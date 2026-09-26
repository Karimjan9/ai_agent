<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\CausalCapabilityEscrow;
use App\Models\LabAgent;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Deterministic acceptance worlds for the complete learning constitution. */
class CausalGoldenWorldHarnessService
{
    public const PROTOCOL = 'causal_golden_world_acceptance_v1';

    public function __construct(
        private CausalCapabilityLatticeService $lattice,
        private EvolutionaryAuthorityLadderService $authority,
    ) {}

    /** @return array<string,mixed> */
    public function run(bool $withPersistenceProof = false): array
    {
        $componentFacts = $this->safeComponentFacts();
        $component = $this->lattice->evaluate([...$componentFacts, 'proof_carried' => false]);
        $composition = $this->lattice->evaluate([...$componentFacts,
            'composition_screening_passed' => true,
            'composition_full_replay_passed' => true,
            'composition_absolute_settlement' => .24,
        ]);
        $mentor = $this->authority->researchMentor([
            'failure_fingerprint' => 'golden:positive:target', 'target' => 'expectancy_margin',
            'exact_frozen_control' => true, 'gene' => 'cost_aware_exit', 'changed_gene_count' => 1,
            'context_hash' => 'london-trend', 'target_gate_improved' => true,
            'non_target_regression' => false, 'independence_verified' => true,
            'independent_windows' => 3, 'positive_windows' => 3, 'causal_skill_credit_count' => 1,
        ]);
        $parent = $this->authority->economicParent([
            'screening_passed' => true, 'full_replay_passed' => true,
            'positive_absolute_settlement' => true, 'forward_or_paper_evidence' => true,
            'performance_credit_count' => 1, 'improving_descendants' => 2,
            'inheritance_credit_count' => 2, 'context_trust_confirmed' => true,
        ], $mentor);
        $strongerChild = $this->lattice->evaluate([...$componentFacts,
            'composition_screening_passed' => true, 'composition_full_replay_passed' => true,
            'composition_absolute_settlement' => .24, 'forward_or_paper_evidence' => true,
            'improving_descendants' => 2, 'context_trust_confirmed' => true,
            'child_beats_parent' => true, 'child_beats_frozen_control' => true,
        ]);
        $positivePass = data_get($component, 'lattice_state') === 'causally_confirmed_component'
            && data_get($component, 'organism_viable') === false
            && data_get($composition, 'organism_viable') === true
            && data_get($mentor, 'eligible') === true
            && data_get($parent, 'eligible') === true
            && data_get($strongerChild, 'stronger_child') === true;

        $null = $this->lattice->evaluate([...$componentFacts,
            'beats_control' => false, 'beats_blinded' => false,
            'declared_target_improved' => false, 'positive_windows' => 0,
        ]);
        $nullPass = data_get($null, 'component_confirmed') === false
            && data_get($null, 'reproductive_authority') === false;

        $poisoned = $this->lattice->evaluate([...$componentFacts,
            'hard_risk_safe' => false, 'non_target_corridor_safe' => false,
            'composition_screening_passed' => true, 'composition_full_replay_passed' => true,
            'composition_absolute_settlement' => .40,
        ]);
        $poisonedPass = data_get($poisoned, 'component_confirmed') === false
            && data_get($poisoned, 'organism_viable') === false
            && data_get($poisoned, 'reproductive_authority') === false;

        $london = $this->lattice->evaluate($componentFacts);
        $asia = $this->lattice->evaluate([...$componentFacts,
            'context_and_gene_scoped' => false, 'hard_risk_safe' => false,
        ]);
        $contextSwitchPass = data_get($london, 'component_confirmed') === true
            && data_get($asia, 'component_confirmed') === false
            && data_get($asia, 'reproductive_authority') === false;

        $worlds = [
            'positive' => ['passed' => $positivePass, 'component' => $component,
                'composition' => $composition, 'mentor' => $mentor, 'economic_parent' => $parent,
                'stronger_child' => $strongerChild,
                'chain' => ['discover', 'confirm_component', 'shadow_capability', 'viable_composition',
                    'research_mentor', 'two_descendants', 'economic_parent', 'stronger_child', 'performance_and_inheritance_credit']],
            'null' => ['passed' => $nullPass, 'result' => $null,
                'false_confirmed' => 0, 'eligible_parent' => 0, 'search_action' => 'bounded_island_close'],
            'poisoned' => ['passed' => $poisonedPass, 'result' => $poisoned,
                'authority_action' => 'reject_and_preserve_as_negative_evidence'],
            'context_switch' => ['passed' => $contextSwitchPass, 'london' => $london, 'asia' => $asia,
                'london_action' => 'research_shadow_enabled', 'asia_action' => 'abstain',
                'global_inheritance_allowed' => false],
        ];
        $logicalPassed = collect($worlds)->every(fn (array $world): bool => $world['passed'] === true);
        $persistence = $withPersistenceProof
            ? $this->persistenceProof()
            : ['status' => 'not_requested', 'passed' => false, 'rolled_back' => true];
        $passed = $logicalPassed && (! $withPersistenceProof || $persistence['passed'] === true);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $passed ? 'passed' : 'failed',
            'passed' => $passed,
            'worlds' => $worlds,
            'persistence_wiring' => $persistence,
            'proof_depth' => $withPersistenceProof
                ? 'service_logic_plus_transactional_persistence'
                : 'constitutional_service_logic_only',
            'profit_guaranteed' => false,
            'wiring_and_false_authority_guards_proven' => $logicalPassed && $persistence['passed'] === true,
            'live_market_replay_proven' => false,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Exercise the real model/pair/experiment/escrow wiring and roll every
     * synthetic row back. This is deliberately separate from live replay:
     * it proves persistence boundaries without manufacturing market edge.
     *
     * @return array<string,mixed>
     */
    private function persistenceProof(): array
    {
        $required = [
            'ai_laboratories', 'lab_generations', 'model_versions', 'lab_agents',
            'lab_mutation_response_maps', 'lab_learning_lane_pairs',
            'agent_learning_causal_experiments', 'causal_capability_escrows',
        ];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            return [
                'status' => 'schema_unavailable', 'passed' => false,
                'missing_tables' => $missing, 'rolled_back' => true,
            ];
        }

        DB::beginTransaction();
        try {
            $token = (string) Str::uuid();
            $symbol = 'GW'.strtoupper(substr(hash('sha256', $token), 0, 10));
            $lab = AiLaboratory::create([
                'symbol' => $symbol, 'name' => 'Golden wiring '.$token, 'timeframe' => 'H1',
                'strategy_families' => ['hybrid'], 'is_active' => false, 'lifecycle_mode' => 'lighthouse',
            ]);
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'golden_world_wiring',
                'trigger_context' => ['learning_protocol_epoch' => app(LearningProtocolEpochService::class)->generationContract()],
                'population_size' => 8, 'status' => 'draft',
            ]);

            $positive = $this->persistedWorld($generation, $symbol, $token, 'positive', true, true, false);
            $null = $this->persistedWorld($generation, $symbol, $token, 'null', false, true, false);
            $poisoned = $this->persistedWorld($generation, $symbol, $token, 'poisoned', true, false, false);
            $contextSwitch = $this->persistedWorld($generation, $symbol, $token, 'context_switch', true, true, true);
            $creditBridge = $this->persistedCreditBridge($positive);

            $checks = [
                'positive_component_persisted' => data_get($positive, 'evaluation.component_confirmed') === true
                    && data_get($positive, 'escrow_state') === 'contextual_shadow_capability',
                'positive_cannot_self_promote' => data_get($positive, 'evaluation.reproductive_authority') === false,
                'null_rejected' => data_get($null, 'evaluation.component_confirmed') === false
                    && data_get($null, 'escrow_state') === 'research_inbox',
                'poisoned_rejected' => data_get($poisoned, 'evaluation.component_confirmed') === false
                    && in_array('non_target_corridor_safe', (array) data_get($poisoned, 'evaluation.failed_component_checks'), true),
                'cross_context_rejected' => data_get($contextSwitch, 'evaluation.component_confirmed') === false
                    && in_array('source_replay_context_match', (array) data_get($contextSwitch, 'evaluation.failed_component_checks'), true),
                'causal_credit_bridge_persisted_once' => data_get($creditBridge, 'first.status') === 'credited'
                    && data_get($creditBridge, 'first.newly_recorded') === true
                    && data_get($creditBridge, 'duplicate.newly_recorded') === false
                    && data_get($creditBridge, 'repair_credit_count') === 1
                    && data_get($creditBridge, 'causal_skill_credit_count') === 1,
            ];
            $passed = ! in_array(false, $checks, true);

            return [
                'status' => $passed ? 'passed' : 'failed',
                'passed' => $passed,
                'checks' => $checks,
                'worlds' => compact('positive', 'null', 'poisoned', 'contextSwitch', 'creditBridge'),
                'rolled_back' => true,
                'promotion_evidence' => false,
            ];
        } catch (\Throwable $error) {
            return [
                'status' => 'technical_failure', 'passed' => false,
                'error_class' => $error::class, 'error' => $error->getMessage(),
                'rolled_back' => true, 'promotion_evidence' => false,
            ];
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<string,mixed> $world
     * @return array<string,mixed>
     */
    private function persistedCreditBridge(array $world): array
    {
        $experiment = AgentLearningCausalExperiment::query()->findOrFail((int) $world['experiment_id']);
        $pair = LabLearningLanePair::query()->findOrFail((int) $world['pair_id']);
        $agent = LabAgent::query()->findOrFail((int) $experiment->guided_agent_id);
        $episode = app(LearningKernelService::class)->openEpisode($agent, [
            'decision_key' => 'golden-credit-'.$experiment->id,
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family, 'context' => [],
        ]);
        $settlement = AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'source_key' => 'golden-credit-'.$experiment->id,
            'source_type' => LabLearningLanePair::class,
            'source_id' => $pair->id,
            'outcome_status' => 'settled',
            'evidence_state' => 'negative',
            'selection_reward' => -0.1,
            'hard_failure' => false,
            'outcome' => [],
            'reward_components' => ['vetoes' => []],
            'settled_at' => now(),
        ]);
        $effect = ['passed' => true, 'common_window_count' => 3,
            'positive_delta_windows' => 2, 'target_effect' => ['passed' => true]];
        $this->lattice->projectExperiment($experiment, $pair, $settlement, $effect, $effect, [], true, true);
        $experiment->update([
            'status' => 'confirmed', 'confirmed_at' => now(),
            'independent_window_count' => 3,
            'guided_beats_control' => true, 'guided_beats_blinded' => true,
            'evidence' => [...((array) $experiment->evidence),
                'component_effect' => $effect, 'selector_effect' => $effect,
                'confirmation_blockers' => [],
                'outcomes' => ['memory_guided' => ['pair_id' => $pair->id]],
            ],
        ]);
        $bridge = app(CausalSkillCreditBridgeService::class);
        $first = $bridge->settle($experiment->fresh());
        $duplicate = $bridge->settle($experiment->fresh());

        return [
            'first' => $first,
            'duplicate' => $duplicate,
            'repair_credit_count' => LabEvolutionCreditEvent::query()
                ->where('lab_agent_id', $agent->id)->where('event_type', 'repair_credit')->count(),
            'causal_skill_credit_count' => LabEvolutionCreditEvent::query()
                ->where('lab_agent_id', $agent->id)->where('event_type', 'causal_skill_credit')->count(),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function persistedWorld(
        LabGeneration $generation,
        string $symbol,
        string $token,
        string $world,
        bool $positiveEffect,
        bool $nonTargetSafe,
        bool $contextMismatch,
    ): array {
        $baseParameters = ['entry_threshold' => 1.0];
        $controlModel = ModelVersion::create([
            'name' => "golden-{$world}-control-{$token}", 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $baseParameters,
            'evidence_status' => 'valid', 'metadata' => [],
        ]);
        $candidateModel = ModelVersion::create([
            'name' => "golden-{$world}-candidate-{$token}", 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['entry_threshold' => 2.0],
            'evidence_status' => 'valid', 'metadata' => [],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => $symbol, 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'golden_wiring', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => $symbol, 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'golden_wiring', 'lifecycle_status' => 'rejected',
            'parameter_diff' => ['entry_threshold' => ['old' => 1.0, 'new' => 2.0]],
        ]);
        $dataHash = hash('sha256', "golden-data|{$token}|{$world}");
        $executionHash = hash('sha256', "golden-execution|{$token}|{$world}");
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', "golden-control-map|{$token}|{$world}"),
            'stage' => 'screening', 'status' => 'control', 'symbol' => $symbol, 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'lab_agent_id' => $control->id,
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
            ]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', "golden-candidate-map|{$token}|{$world}"),
            'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => $symbol, 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'lab_agent_id' => $candidate->id,
        ]);
        $observedContext = [
            'regime' => 'trend_up',
            'session' => $contextMismatch ? 'asia' : 'overlap',
            'venue_phase' => $contextMismatch ? 'asia_sge_day' : 'london_comex_overlap',
        ];
        $sourceContext = ['regime' => 'trend_up', 'session' => 'overlap', 'venue_phase' => 'london_comex_overlap'];
        $pair = LabLearningLanePair::create([
            'pair_key' => hash('sha256', "golden-pair|{$token}|{$world}"),
            'lab_generation_id' => $generation->id, 'candidate_agent_id' => $candidate->id,
            'control_agent_id' => $control->id, 'candidate_response_map_id' => $candidateMap->id,
            'control_response_map_id' => $controlMap->id, 'symbol' => $symbol, 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'baseline_source' => 'control',
            'status' => 'lesson_compiled', 'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'target_delta' => ['delta' => $positiveEffect ? .2 : -.1, 'improved' => $positiveEffect],
            'non_target_regression' => ['status' => $nonTargetSafe ? 'passed' : 'failed', 'safe' => $nonTargetSafe],
            'metadata' => ['context_scope' => $observedContext],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha256', "golden-experiment|{$token}|{$world}"),
            'lab_generation_id' => $generation->id, 'symbol' => $symbol, 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'guided_agent_id' => $candidate->id, 'control_agent_id' => $control->id, 'status' => 'provisional',
            'evidence' => ['source_context_scope' => $sourceContext],
        ]);
        $effect = [
            'passed' => $positiveEffect, 'common_window_count' => 3,
            'positive_delta_windows' => $positiveEffect ? 2 : 0,
            'target_effect' => ['passed' => $positiveEffect],
        ];
        $evaluation = $this->lattice->projectExperiment(
            $experiment, $pair, null, $effect, $effect, [], true, $nonTargetSafe,
        );
        $escrow = CausalCapabilityEscrow::query()
            ->where('agent_learning_causal_experiment_id', $experiment->id)
            ->first();

        return [
            'evaluation' => $evaluation,
            'experiment_id' => (int) $experiment->id,
            'pair_id' => (int) $pair->id,
            'escrow_state' => $escrow?->lattice_state,
            'escrow_persisted' => $escrow !== null,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function safeComponentFacts(): array
    {
        return [
            'post_v2_epoch' => true, 'exact_frozen_control' => true,
            'intent_sealed_before_mutation' => true, 'beats_control' => true,
            'beats_blinded' => true, 'declared_target_improved' => true,
            'independent_windows' => 3, 'positive_windows' => 3,
            'hard_risk_safe' => true, 'non_target_corridor_safe' => true,
            'intent_run_outcome_linked' => true, 'context_and_gene_scoped' => true,
            'proof_carried' => true,
        ];
    }
}
