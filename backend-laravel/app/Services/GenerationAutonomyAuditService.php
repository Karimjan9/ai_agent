<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\AgentLearningMutationIntent;
use App\Models\CandidateGateDecision;
use App\Models\ContextualInstrumentBundleEffect;
use App\Models\ContextualSpecialistCapsule;
use App\Models\CooperativeExperimentSettlement;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabLifecycleCycle;
use App\Models\LabLifecycleEvent;
use App\Models\ResearchLoopDecision;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, generation-scoped acceptance for unattended operation.
 *
 * Scientific rejection is a valid result.  Missing wiring, technical
 * quarantine, incomplete counterfactuals and false attribution are not.
 */
class GenerationAutonomyAuditService
{
    public const PROTOCOL = 'generation_cross_feature_acceptance_v1';

    private const CAUSAL_COHORT_PROTOCOL = 'causal_learning_counterfactual_cohort_v1';

    private const TERMINAL_GENERATIONS = ['screened', 'completed'];

    private const TERMINAL_AGENTS = ['screened', 'completed'];

    /** @return array<string,mixed> */
    public function audit(LabGeneration $generation): array
    {
        $generation->loadMissing('laboratory', 'agents.modelVersion');
        $terminal = in_array((string) $generation->status, self::TERMINAL_GENERATIONS, true);
        $checks = collect([
            $this->populationAndTerminal($generation, $terminal),
            $this->technicalIntegrity($generation),
            $this->immutableEvidence($generation, $terminal),
            $this->dynamicCouncil($generation, $terminal),
            $this->sessionCalendar($generation, $terminal),
            $this->cooperativeSettlement($generation, $terminal),
            $this->instrumentAttribution($generation),
            $this->causalLearningClosure($generation, $terminal),
            $this->terminalLearningOrder($generation, $terminal),
            $this->authorityContainment($generation),
            $this->researchLoopClosure($generation, $terminal),
        ]);

        $failed = $checks->where('status', 'failed')->values();
        $running = $checks->where('status', 'running')->values();
        $state = $failed->isNotEmpty()
            ? 'failed'
            : ($running->isNotEmpty() || ! $terminal ? 'running' : 'passed');
        $screenDecisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $generation->agents->pluck('id'))
            ->where('stage', 'screening')->get();
        if ($state === 'passed' && $screenDecisions->isNotEmpty()
            && $screenDecisions->where('decision', 'passed')->isEmpty()) {
            $state = 'passed_with_scientific_abstention';
        }

        return [
            'protocol' => self::PROTOCOL,
            'observed_at' => now()->utc()->toIso8601String(),
            'generation' => [
                'id' => (int) $generation->id,
                'number' => (int) $generation->generation,
                'symbol' => (string) $generation->laboratory?->symbol,
                'timeframe' => (string) $generation->laboratory?->timeframe,
                'trigger_type' => (string) $generation->trigger_type,
                'status' => (string) $generation->status,
            ],
            'state' => $state,
            'unattended_ready' => in_array($state, ['passed', 'passed_with_scientific_abstention'], true),
            'manual_intervention_required' => $failed->isNotEmpty(),
            'failed_checks' => $failed->pluck('name')->values()->all(),
            'running_checks' => $running->pluck('name')->values()->all(),
            'checks' => $checks->values()->all(),
            'scientific_outcome' => [
                'screening_decisions' => $screenDecisions->count(),
                'passed' => $screenDecisions->where('decision', 'passed')->count(),
                'failed' => $screenDecisions->where('decision', 'failed')->count(),
                'zero_pass_is_valid' => true,
            ],
            'pass_rule' => 'terminal_20_of_20_zero_technical_failures_complete_evidence_dynamic_blocks_session_safe_attribution_closed_learning_and_no_false_authority',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function populationAndTerminal(LabGeneration $generation, bool $terminal): array
    {
        $expected = $this->plannedPopulation($generation);
        $agents = $generation->agents;
        $nonTerminal = $agents->reject(fn ($agent): bool => in_array((string) $agent->lifecycle_status, self::TERMINAL_AGENTS, true));
        $reasons = [];
        if ($expected !== $agents->count()) {
            $reasons[] = 'POPULATION_NOT_COMPLETE';
        }
        if ($terminal && $nonTerminal->isNotEmpty()) {
            $reasons[] = 'AGENTS_NOT_CLEANLY_TERMINAL';
        }
        if ($terminal && ! in_array((string) $generation->status, self::TERMINAL_GENERATIONS, true)) {
            $reasons[] = 'GENERATION_NOT_CLEANLY_TERMINAL';
        }

        return $this->check('population_terminal', $reasons === [] ? ($terminal ? 'passed' : 'running') : 'failed', $reasons, [
            'planned' => $expected,
            'actual' => $agents->count(),
            'terminal_agents' => $agents->count() - $nonTerminal->count(),
            'non_terminal_or_technical_agent_ids' => $nonTerminal->pluck('id')->values()->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function technicalIntegrity(LabGeneration $generation): array
    {
        $runs = LabEvaluationRun::query()->where('lab_generation_id', $generation->id)->get();
        $events = LabLifecycleEvent::query()->where('lab_generation_id', $generation->id)->get();
        $technicalRuns = $runs->where('status', 'technical_error');
        $technicalEvents = $events->filter(fn (LabLifecycleEvent $event): bool => (string) $event->event_type === 'evaluation_technical_error'
            || str_contains((string) $event->event_type, 'technical_quarantine')
            || str_contains((string) $event->event_type, 'integrity_quarantine')
        );
        $blockedCycles = Schema::hasTable('lab_lifecycle_cycles')
            ? LabLifecycleCycle::query()
                ->where('symbol', (string) $generation->laboratory?->symbol)
                ->where('timeframe', (string) $generation->laboratory?->timeframe)
                ->where('started_at', '>=', $generation->created_at)
                ->where('started_at', '<=', $generation->completed_at ?: $generation->updated_at)
                ->where('status', 'blocked')->get()
            : collect();
        $recoveredBlockedCycles = $blockedCycles->filter(fn (LabLifecycleCycle $blocked): bool => LabLifecycleCycle::query()
            ->where('symbol', (string) $blocked->symbol)
            ->where('timeframe', (string) $blocked->timeframe)
            ->where('started_at', '>', $blocked->started_at)
            ->where('started_at', '<=', $generation->completed_at ?: $generation->updated_at)
            ->where('status', 'completed')
            ->exists()
        );
        $unrecoveredBlockedCycles = $blockedCycles->whereNotIn('id', $recoveredBlockedCycles->pluck('id'));
        $technicalAgents = $generation->agents->filter(fn ($agent): bool => in_array(
            (string) $agent->lifecycle_status,
            ['evaluation_error', 'technical_quarantine', 'quarantined', 'legacy_quarantine', 'abandoned', 'failed'],
            true,
        ));
        $reasons = [];
        if ($technicalRuns->isNotEmpty()) {
            $reasons[] = 'TECHNICAL_EVALUATION_RUN_RECORDED';
        }
        if ($technicalEvents->isNotEmpty()) {
            $reasons[] = 'TECHNICAL_LIFECYCLE_EVENT_RECORDED';
        }
        if ($technicalAgents->isNotEmpty()) {
            $reasons[] = 'TECHNICAL_AGENT_RECORDED';
        }
        if ($unrecoveredBlockedCycles->isNotEmpty()) {
            $reasons[] = 'BLOCKED_LIFECYCLE_CYCLE_RECORDED';
        }

        return $this->check('technical_integrity', $reasons === [] ? 'passed' : 'failed', $reasons, [
            'technical_run_ids' => $technicalRuns->pluck('run_id')->values()->all(),
            'technical_event_ids' => $technicalEvents->pluck('id')->values()->all(),
            'technical_agent_ids' => $technicalAgents->pluck('id')->values()->all(),
            'blocked_cycle_ids' => $blockedCycles->pluck('cycle_id')->values()->all(),
            'recovered_blocked_cycle_ids' => $recoveredBlockedCycles->pluck('cycle_id')->values()->all(),
            'unrecovered_blocked_cycle_ids' => $unrecoveredBlockedCycles->pluck('cycle_id')->values()->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function immutableEvidence(LabGeneration $generation, bool $terminal): array
    {
        $runs = LabEvaluationRun::query()->where('lab_generation_id', $generation->id)->orderBy('id')->get();
        $artifacts = LabEvidenceArtifact::query()->where('lab_generation_id', $generation->id)->get();
        $missing = [];
        $incomplete = [];
        foreach ($generation->agents as $agent) {
            $agentRuns = $runs->where('lab_agent_id', $agent->id)->reject(fn (LabEvaluationRun $run): bool => $run->status === 'retry_released'
                || ($run->status === 'skipped' && data_get($run->metadata, 'projection_only') === true)
            );
            /** @var LabEvaluationRun|null $run */
            $run = $agentRuns->last();
            if (! $run) {
                $missing[] = (int) $agent->id;

                continue;
            }
            if ((string) $run->status !== 'completed') {
                $incomplete[] = (int) $agent->id;

                continue;
            }
            $localGuard = data_get($run->metadata, 'correctly_abstained') === true
                && data_get($run->metadata, 'replay_performed') === false;
            $agentArtifacts = $artifacts->where('run_id', $run->run_id);
            $hasRequest = $agentArtifacts->contains('artifact_type', 'evaluation_request');
            if ($localGuard) {
                if (! $hasRequest) {
                    $incomplete[] = (int) $agent->id;
                }

                continue;
            }
            $trace = $agentArtifacts->where('artifact_type', 'decision_trace_manifest')
                ->contains(fn (LabEvidenceArtifact $artifact): bool => (bool) data_get($artifact->metadata, 'complete', data_get($artifact->payload, 'complete', false)));
            $ledger = $agentArtifacts->filter(fn (LabEvidenceArtifact $artifact): bool => in_array(
                (string) $artifact->artifact_type,
                ['trade_ledger', 'agent_trade_ledger'],
                true,
            ))->contains(fn (LabEvidenceArtifact $artifact): bool => (bool) data_get($artifact->metadata, 'complete', false));
            if (! $run->request_hash || ! $run->response_hash || ! $hasRequest || ! $trace || ! $ledger) {
                $incomplete[] = (int) $agent->id;
            }
        }
        $reasons = [];
        if ($missing !== []) {
            $reasons[] = 'AGENT_EVIDENCE_RUN_MISSING';
        }
        if ($incomplete !== []) {
            $reasons[] = 'AGENT_IMMUTABLE_EVIDENCE_INCOMPLETE';
        }
        $status = $reasons === [] ? 'passed' : ($terminal ? 'failed' : 'running');

        return $this->check('immutable_evidence', $status, $reasons, [
            'run_count' => $runs->count(),
            'artifact_count' => $artifacts->count(),
            'missing_agent_ids' => $missing,
            'incomplete_agent_ids' => array_values(array_unique($incomplete)),
        ]);
    }

    /** @return array<string,mixed> */
    private function dynamicCouncil(LabGeneration $generation, bool $terminal): array
    {
        $contract = (array) data_get($generation->trigger_context, 'specialist_council_contract.contextual_allocator', []);
        if (data_get($contract, 'protocol') !== CooperativeContextualEvolutionCouncilService::PROTOCOL) {
            return $this->check('dynamic_council', 'not_applicable', [], ['reason' => 'generation_has_no_cooperative_council_contract']);
        }
        $agents = $generation->agents;
        $cooperative = $agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.protocol') === CooperativeContextualEvolutionCouncilService::PROTOCOL);
        $causal = $agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.protocol') === self::CAUSAL_COHORT_PROTOCOL);
        $guards = $agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'uncertainty_abstain_contract.protocol') === CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL);
        $unowned = $agents->reject(fn ($agent): bool => $cooperative->contains('id', $agent->id)
            || $causal->contains('id', $agent->id) || $guards->contains('id', $agent->id));
        $invalidBlocks = [];
        foreach ($cooperative->groupBy(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.block_key')) as $key => $members) {
            $required = collect((array) data_get($members->first()->modelVersion?->metadata, 'cooperative_experiment_block.required_arms'))->map('strval')->sort()->values();
            $actual = $members->map(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.arm'))->sort()->values();
            $instances = $members->map(fn ($agent) => data_get(
                $agent->modelVersion?->metadata,
                'specialist_council_membership.contextual_cell.session_ownership.session_instance_id',
                data_get($agent->modelVersion?->metadata, 'specialist_council_membership.contextual_cell.session_instance_id'),
            ))->filter()->unique();
            if ($key === '' || $required->all() !== $actual->all() || $instances->count() !== 1) {
                $invalidBlocks[] = (string) $key;
            }
        }
        $reasons = [];
        if ($cooperative->count() !== (int) data_get($contract, 'cooperative_seats', $cooperative->count())) {
            $reasons[] = 'COOPERATIVE_SEAT_COUNT_MISMATCH';
        }
        if ($causal->count() !== (int) data_get($contract, 'protected_causal_proof_seats', $causal->count())) {
            $reasons[] = 'CAUSAL_PROOF_SEAT_COUNT_MISMATCH';
        }
        if ($guards->count() !== count((array) data_get($contract, 'uncertainty_abstain_slots', []))) {
            $reasons[] = 'UNCERTAINTY_GUARD_SEAT_COUNT_MISMATCH';
        }
        if ($unowned->isNotEmpty()) {
            $reasons[] = 'COUNCIL_SEAT_WITHOUT_EXPERIMENT_LANE';
        }
        if ($invalidBlocks !== []) {
            $reasons[] = 'CANDIDATE_CONTROL_BLOCK_PARITY_FAILED';
        }
        $status = $reasons === [] ? 'passed' : ($terminal ? 'failed' : 'running');

        return $this->check('dynamic_council', $status, $reasons, [
            'cooperative_seats' => $cooperative->count(),
            'causal_proof_seats' => $causal->count(),
            'uncertainty_guard_seats' => $guards->count(),
            'unowned_agent_ids' => $unowned->pluck('id')->values()->all(),
            'invalid_block_keys' => $invalidBlocks,
            'permanent_semantic_group_quotas' => (bool) data_get($contract, 'permanent_semantic_group_quotas', true),
        ]);
    }

    /** @return array<string,mixed> */
    private function sessionCalendar(LabGeneration $generation, bool $terminal): array
    {
        $guardIds = $generation->agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'uncertainty_abstain_contract.protocol') === CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL)->pluck('id');
        $required = $generation->agents->reject(fn ($agent): bool => $guardIds->contains($agent->id));
        $missing = [];
        $invalid = [];
        $quarantined = 0;
        foreach ($required as $agent) {
            $coverage = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result.market_session_calendar_coverage', []);
            if ($coverage === []) {
                $missing[] = (int) $agent->id;

                continue;
            }
            $total = (int) data_get($coverage, 'total_candles', 0);
            $classified = (int) data_get($coverage, 'classified_count', 0);
            $quarantine = (int) data_get($coverage, 'quarantined_count', 0);
            $quarantined += $quarantine;
            if (data_get($coverage, 'protocol') !== 'market_session_calendar_coverage_v1'
                || ! filled(data_get($coverage, 'calendar_version'))
                || $total <= 0
                || $classified + $quarantine !== $total
                || (float) data_get($coverage, 'classification_coverage', 0) < 1
                || (int) data_get($coverage, 'unknown_count', -1) !== 0
                || (int) data_get($coverage, 'outside_scope_activation_count', -1) !== 0) {
                $invalid[] = (int) $agent->id;
            }
        }
        $reasons = [];
        if ($missing !== []) {
            $reasons[] = 'SESSION_CALENDAR_COVERAGE_MISSING';
        }
        if ($invalid !== []) {
            $reasons[] = 'SESSION_CLASSIFICATION_OR_SCOPE_INVARIANT_FAILED';
        }
        $status = $reasons === [] ? 'passed' : ($terminal ? 'failed' : 'running');

        return $this->check('market_session_calendar', $status, $reasons, [
            'required_agent_count' => $required->count(),
            'missing_agent_ids' => $missing,
            'invalid_agent_ids' => $invalid,
            'explicit_quarantined_candle_observations' => $quarantined,
            'unknown_sessions_allowed' => 0,
        ]);
    }

    /** @return array<string,mixed> */
    private function cooperativeSettlement(LabGeneration $generation, bool $terminal): array
    {
        $members = $generation->agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.protocol') === CooperativeContextualEvolutionCouncilService::PROTOCOL);
        if ($members->isEmpty()) {
            return $this->check('cooperative_settlement', 'not_applicable', [], []);
        }
        $keys = $members->map(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.block_key'))->filter()->unique();
        $settlements = CooperativeExperimentSettlement::query()->where('lab_generation_id', $generation->id)->whereIn('block_key', $keys)->get();
        $missing = $keys->diff($settlements->pluck('block_key'));
        $invalid = $settlements->filter(fn (CooperativeExperimentSettlement $row): bool => ! $row->evidence_complete || $row->outcome_status === 'invalid_arm_evidence');
        $badCredits = CooperativeModuleSpeciesMember::query()->whereIn('lab_agent_id', $members->pluck('id'))
            ->where('authority_level', '!=', 'hypothesis')->get()
            ->filter(function (CooperativeModuleSpeciesMember $member): bool {
                $agent = $member->lab_agent_id ? LabAgent::with('modelVersion')->find($member->lab_agent_id) : null;

                return ! $agent || (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.changed_species') !== (string) $member->species;
            });
        $reasons = [];
        if ($missing->isNotEmpty()) {
            $reasons[] = 'COOPERATIVE_BLOCK_SETTLEMENT_MISSING';
        }
        if ($invalid->isNotEmpty()) {
            $reasons[] = 'COOPERATIVE_BLOCK_EVIDENCE_INVALID';
        }
        if ($badCredits->isNotEmpty()) {
            $reasons[] = 'MODULE_CREDIT_NOT_BOUND_TO_CHANGED_SPECIES';
        }
        $status = $reasons === [] ? 'passed' : ($terminal ? 'failed' : 'running');

        return $this->check('cooperative_settlement', $status, $reasons, [
            'expected_blocks' => $keys->count(),
            'settled_blocks' => $settlements->count(),
            'positive_blocks' => $settlements->where('outcome_status', 'settled_positive_signal')->count(),
            'negative_or_null_blocks' => $settlements->where('outcome_status', 'settled_negative_or_null')->count(),
            'missing_block_keys' => $missing->values()->all(),
            'invalid_settlement_ids' => $invalid->pluck('id')->values()->all(),
            'misattributed_species_member_ids' => $badCredits->pluck('id')->values()->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function instrumentAttribution(LabGeneration $generation): array
    {
        $ledgers = InstrumentInvocationLedger::query()->where('lab_generation_id', $generation->id)->whereNull('paper_signal_id')->get();
        $activated = $ledgers->filter(fn (InstrumentInvocationLedger $row): bool => $row->used_in_decision
            && data_get($row->metadata, 'declaration.causal_candidate') === true
            && data_get($row->metadata, 'runtime_trace.decision_path_activated') === true
            && (string) data_get($row->metadata, 'runtime_trace.status') === 'consumed');
        $effects = ContextualInstrumentBundleEffect::query()->where('lab_generation_id', $generation->id)->get();
        $settlements = CooperativeExperimentSettlement::query()
            ->whereIn('id', $effects->pluck('cooperative_experiment_settlement_id')->filter())
            ->get()->keyBy('id');
        $falseEffects = $effects->filter(function (ContextualInstrumentBundleEffect $effect) use ($activated, $generation, $settlements): bool {
            $settlement = $settlements->get($effect->cooperative_experiment_settlement_id);
            if (! $settlement) {
                return true;
            }
            $blockAgentIds = $generation->agents->filter(fn ($agent): bool => (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.block_key') === (string) $settlement->block_key
            )->pluck('id');
            $blockActivations = $activated->whereIn('lab_agent_id', $blockAgentIds);
            $needed = collect([(string) $effect->instrument_a, (string) $effect->instrument_b])->filter()->unique();

            return $needed->isEmpty()
                || $needed->contains(fn (string $instrument): bool => ! $blockActivations->contains('instrument_key', $instrument));
        });
        $toolboxCreditsWithoutActivation = CooperativeModuleSpeciesMember::query()
            ->whereIn('lab_agent_id', $generation->agents->pluck('id'))
            ->where('species', 'toolbox_instrument')
            ->where('authority_level', '!=', 'hypothesis')->get()
            ->reject(fn (CooperativeModuleSpeciesMember $member): bool => $activated->contains('lab_agent_id', $member->lab_agent_id));
        $reasons = [];
        if ($falseEffects->isNotEmpty()) {
            $reasons[] = 'BUNDLE_EFFECT_WITHOUT_RUNTIME_ACTIVATION';
        }
        if ($toolboxCreditsWithoutActivation->isNotEmpty()) {
            $reasons[] = 'TOOLBOX_CREDIT_WITHOUT_RUNTIME_ACTIVATION';
        }

        return $this->check('instrument_runtime_attribution', $reasons === [] ? 'passed' : 'failed', $reasons, [
            'assignment_count' => $generation->agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'instrument_research_assignment.protocol') === 'lab_instrument_research_assignment_v2')->count(),
            'ledger_count' => $ledgers->count(),
            'activated_causal_invocation_count' => $activated->count(),
            'bundle_effect_count' => $effects->count(),
            'false_effect_ids' => $falseEffects->pluck('id')->values()->all(),
            'false_toolbox_credit_member_ids' => $toolboxCreditsWithoutActivation->pluck('id')->values()->all(),
            'used_in_execution_means_paper_or_live_candidate_only' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function causalLearningClosure(LabGeneration $generation, bool $terminal): array
    {
        $causalAgents = $generation->agents->filter(fn ($agent): bool => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.protocol') === self::CAUSAL_COHORT_PROTOCOL);
        if ($causalAgents->isEmpty()) {
            return $this->check('causal_learning_closure', 'not_applicable', [], []);
        }
        $experiments = AgentLearningCausalExperiment::query()->where('lab_generation_id', $generation->id)->get();
        $terminalStatuses = ['provisional', 'confirmed', 'technical_quarantine'];
        $open = $experiments->reject(fn (AgentLearningCausalExperiment $row): bool => in_array((string) $row->status, $terminalStatuses, true));
        $ids = $causalAgents->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $badIdentity = $experiments->filter(function (AgentLearningCausalExperiment $row) use ($ids): bool {
            $armIds = collect([$row->guided_agent_id, $row->blinded_agent_id, $row->control_agent_id])->map(fn ($id): int => (int) $id)->sort()->values()->all();

            return $armIds !== $ids || data_get($row->evidence, 'construction_validation.status') !== 'ready_for_replay';
        });
        $intents = AgentLearningMutationIntent::query()->where('lab_generation_id', $generation->id)->whereIn('lab_agent_id', $causalAgents->pluck('id'))->get();
        $unsealed = $intents->filter(fn (AgentLearningMutationIntent $intent): bool => ! $intent->sealed_at || ! $intent->bound_at || $intent->invalidated_at !== null);
        $reasons = [];
        if ($experiments->count() !== 1) {
            $reasons[] = 'EXACTLY_ONE_CAUSAL_EXPERIMENT_REQUIRED';
        }
        if ($badIdentity->isNotEmpty()) {
            $reasons[] = 'CAUSAL_TRIPLET_IDENTITY_OR_BASELINE_INVALID';
        }
        if ($open->isNotEmpty()) {
            $reasons[] = 'CAUSAL_EXPERIMENT_NOT_SETTLED';
        }
        if ($intents->count() < 2 || $unsealed->isNotEmpty()) {
            $reasons[] = 'CAUSAL_MUTATION_INTENT_NOT_SEALED_AND_BOUND';
        }
        $status = $reasons === [] ? 'passed' : ($terminal ? 'failed' : 'running');

        return $this->check('causal_learning_closure', $status, $reasons, [
            'causal_agent_ids' => $ids,
            'experiment_ids' => $experiments->pluck('id')->values()->all(),
            'experiment_statuses' => $experiments->pluck('status', 'id')->all(),
            'intent_count' => $intents->count(),
            'unsealed_or_invalid_intent_ids' => $unsealed->pluck('id')->values()->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function authorityContainment(LabGeneration $generation): array
    {
        $capsules = ContextualSpecialistCapsule::query()->whereIn('lab_agent_id', $generation->agents->pluck('id'))->get();
        $falseAuthority = $capsules->filter(fn (ContextualSpecialistCapsule $capsule): bool => (in_array((string) $capsule->status, ['elite', 'contextually_confirmed_specialist'], true)
                || in_array((string) $capsule->authority_level, ['contextually_confirmed', 'economic_parent'], true))
            && data_get($capsule->evidence, 'promotion_evidence') !== true
        );
        $scopeLeaks = $capsules->where('outside_scope_activation_count', '>', 0);
        $reasons = [];
        if ($falseAuthority->isNotEmpty()) {
            $reasons[] = 'AUTHORITY_WITHOUT_PROMOTION_EVIDENCE';
        }
        if ($scopeLeaks->isNotEmpty()) {
            $reasons[] = 'OUT_OF_SCOPE_SPECIALIST_ACTIVATION';
        }

        return $this->check('authority_containment', $reasons === [] ? 'passed' : 'failed', $reasons, [
            'capsule_count' => $capsules->count(),
            'confirmed_capsule_count' => $capsules->whereIn('status', ['elite', 'contextually_confirmed_specialist'])->count(),
            'false_authority_capsule_ids' => $falseAuthority->pluck('id')->values()->all(),
            'scope_leak_capsule_ids' => $scopeLeaks->pluck('id')->values()->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function terminalLearningOrder(LabGeneration $generation, bool $terminal): array
    {
        $agentIds = $generation->agents->pluck('id');
        $episodes = AgentLearningEpisode::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->with('settlement')
            ->get();
        $openEpisodes = $episodes->filter(fn (AgentLearningEpisode $episode): bool => ! $episode->settlement
            && ! in_array((string) $episode->status, ['technical_quarantine'], true));
        $episodeIds = $episodes->pluck('id');
        $latestLearningSettlement = $episodeIds->isEmpty()
            ? null
            : AgentLearningSettlement::query()->whereIn('episode_id', $episodeIds)->max('settled_at');
        $latestCooperativeSettlement = CooperativeExperimentSettlement::query()
            ->where('lab_generation_id', $generation->id)->max('updated_at');
        $latestCausalSettlement = AgentLearningCausalExperiment::query()
            ->where('lab_generation_id', $generation->id)->max('updated_at');

        $completedAt = $generation->completed_at;
        $settlementTimes = collect([
            'learning' => $latestLearningSettlement,
            'cooperative' => $latestCooperativeSettlement,
            'causal' => $latestCausalSettlement,
        ])->filter();
        $lateKinds = $completedAt
            ? $settlementTimes->filter(fn (mixed $at): bool => \Illuminate\Support\Carbon::parse($at)->gt($completedAt))->keys()->values()
            : collect();
        $reasons = [];
        if ($episodes->isNotEmpty() && $openEpisodes->isNotEmpty()) {
            $reasons[] = 'GENERATION_LEARNING_EPISODES_NOT_TERMINAL';
        }
        if ($terminal && ! $completedAt) {
            $reasons[] = 'GENERATION_COMPLETED_AT_MISSING';
        }
        if ($lateKinds->isNotEmpty()) {
            $reasons[] = 'GENERATION_CLOSED_BEFORE_LEARNING_SETTLEMENT';
        }
        $status = $reasons === []
            ? ($terminal ? 'passed' : 'running')
            : ($terminal ? 'failed' : 'running');

        return $this->check('terminal_learning_order', $status, $reasons, [
            'episode_count' => $episodes->count(),
            'terminal_episode_count' => $episodes->count() - $openEpisodes->count(),
            'open_episode_ids' => $openEpisodes->pluck('id')->values()->all(),
            'generation_completed_at' => $completedAt?->toIso8601String(),
            'latest_learning_settlement_at' => $latestLearningSettlement,
            'latest_cooperative_settlement_at' => $latestCooperativeSettlement,
            'latest_causal_settlement_at' => $latestCausalSettlement,
            'settlements_after_generation_close' => $lateKinds->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function researchLoopClosure(LabGeneration $generation, bool $terminal): array
    {
        if (! Schema::hasTable('research_loop_decisions')) {
            return $this->check('research_loop_children', 'not_applicable', [], []);
        }
        $decisions = ResearchLoopDecision::query()
            ->where('symbol', (string) $generation->laboratory?->symbol)
            ->where('timeframe', (string) $generation->laboratory?->timeframe)
            ->where('created_at', '>=', $generation->created_at)
            ->where('created_at', '<=', $generation->completed_at ?: $generation->updated_at)
            ->get();
        $open = $decisions->whereIn('status', ['selected', 'dispatched', 'running']);
        $reasons = $open->isEmpty() ? [] : ['RESEARCH_LOOP_CHILD_DECISION_NOT_TERMINAL'];
        $status = $reasons === [] ? 'passed' : ($terminal ? 'failed' : 'running');

        return $this->check('research_loop_children', $status, $reasons, [
            'decision_count' => $decisions->count(),
            'status_counts' => $decisions->groupBy('status')->map->count()->all(),
            'open_decision_ids' => $open->pluck('id')->values()->all(),
        ]);
    }

    private function plannedPopulation(LabGeneration $generation): int
    {
        $plan = (array) data_get($generation->trigger_context, 'generation_plan', []);

        return $plan !== [] ? count($plan) : (int) $generation->population_size;
    }

    /** @param array<int,string> $reasonCodes @param array<string,mixed> $metrics @return array<string,mixed> */
    private function check(string $name, string $status, array $reasonCodes, array $metrics): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'metrics' => $metrics,
            'promotion_evidence' => false,
        ];
    }
}
