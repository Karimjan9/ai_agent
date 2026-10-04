<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ContextualSpecialistCapsule;
use App\Models\CooperativeExperimentSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Selects a research question, never a trading or component-credit authority. */
class ProofFrontierService
{
    public const PROTOCOL = 'proof_frontier_activation_v1';
    public const PHASE_PROBE_PROTOCOL = 'prospective_phase_scope_probe_v1';

    public const PHASE_PROBE_REFREEZE_PROTOCOL = 'prospective_phase_scope_probe_refreeze_v2';
    public const MIN_PAIRED_OPPORTUNITIES = 20;
    public const MIN_SIGNAL_OPPORTUNITIES = 20;

    private const UPSTREAM_GENES = [
        'regime_classifier_variant', 'entry_topology_variant',
        'differential_target_regime', 'minimum_signal_confidence',
        'trend_strength_min', 'lookback', 'location_tolerance_atr',
        'h1_context_max_age_bars', 'm15_context_max_age_bars',
    ];

    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private LabImmutableEvidenceService $evidence,
        private MarketSessionCalendarService $calendar,
    ) {}

    /** @return array<string,mixed> */
    public function propose(AiLaboratory $lab, array $plan): array
    {
        if (! Schema::hasTable('lab_agents') || ! Schema::hasTable('lab_evaluation_runs')) {
            return $this->unavailable('evidence_tables_missing');
        }
        $generationIds = $lab->generations()->whereIn('status', ['screened', 'completed'])
            ->orderByDesc('generation')->limit(8)->pluck('id');
        if ($generationIds->isEmpty()) {
            return $this->unavailable('no_terminal_source_generation');
        }
        $sources = LabAgent::query()->with('modelVersion')
            ->whereIn('lab_generation_id', $generationIds)
            ->whereNotIn('lifecycle_status', ['technical_quarantine', 'quarantined', 'evaluation_error'])
            ->orderByDesc('id')->limit(160)->get();
        $proposals = [];
        $funnel = [
            'source_agents_considered' => $sources->count(),
            'runtime_signal_gap_sources' => 0,
            'phase_bound_gap_sources' => 0,
            'immutable_run_sources' => 0,
            'exact_runtime_request_sources' => 0,
            'same_passport_plan_sources' => 0,
            'phase_probe_owned_sources' => 0,
            'two_legal_axis_sources' => 0,
            'joint_delta_proposals' => 0,
            'missing_observed_liquidity_sources' => 0,
            'unphased_signal_gap_agent_ids' => [],
            'forbidden_phase_signal_gap_agent_ids' => [],
        ];
        foreach ($sources as $source) {
            $passport = (array) data_get($source->modelVersion?->metadata, 'smart_composition.composition_passport', []);
            $decision = CandidateGateDecision::query()->where('lab_agent_id', $source->id)
                ->where('stage', 'screening')->latest('id')->first();
            $trace = (array) data_get($decision?->metrics, 'composition_runtime_trace', []);
            $signals = (int) data_get($trace, 'observations.strategy_signals_before_tactic', 0);
            $sourcePhase = (string) data_get($source->modelVersion?->metadata,
                'specialist_council_membership.contextual_cell.venue_phase', '');
            if ((string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL
                || data_get($trace, 'execution_receipt_valid') !== true
                || data_get($trace, 'component_bindings_valid') !== true
                || data_get($trace, 'authority_bindings_valid') !== true
                || (string) data_get($trace, 'composition_id') !== (string) data_get($passport, 'composition_id')
                || $signals < self::MIN_SIGNAL_OPPORTUNITIES
                || (int) data_get($trace, 'observations.rows', 0) < self::MIN_PAIRED_OPPORTUNITIES
                || (int) data_get($trace, 'component_execution.tactic.accepted_count', -1) !== 0) {
                continue;
            }
            $funnel['runtime_signal_gap_sources']++;
            // Missing measured inputs are a data dependency, not a legal
            // strategy/tactic mutation opportunity. Do not spend a factorial
            // block trying to optimize away the observation contract.
            $scope = (array) data_get($decision?->metrics, 'data_quality.specialist_signal_scope', []);
            if ((int) data_get($scope, 'signal_predicate_failure_counts.liquidity_observation_missing', 0) > 0
                && (int) data_get($scope, 'accepted_signal_count', 0) === 0) {
                $funnel['missing_observed_liquidity_sources']++;
                continue;
            }
            if (! in_array($sourcePhase, $this->calendar->researchPhases(), true)) {
                if (count($funnel['unphased_signal_gap_agent_ids']) < 5) {
                    $funnel['unphased_signal_gap_agent_ids'][] = (int) $source->id;
                }
                continue;
            }
            if ($sourcePhase === 'comex_maintenance') {
                if (count($funnel['forbidden_phase_signal_gap_agent_ids']) < 5) {
                    $funnel['forbidden_phase_signal_gap_agent_ids'][] = (int) $source->id;
                }
                continue;
            }
            $funnel['phase_bound_gap_sources']++;
            $runId = (string) data_get($decision?->metrics, 'evidence_run_id', '');
            $run = $runId !== '' ? LabEvaluationRun::query()->where('lab_agent_id', $source->id)
                ->where('run_id', $runId)->where('phase', 'screening')->where('status', 'completed')->first() : null;
            if (! $run || ! filled($run->data_hash) || ! filled($run->response_hash)) {
                continue;
            }
            try {
                if (! $this->evidence->learningEligibility($run)['complete']) {
                    continue;
                }
                $immutableTrace = data_get($this->evidence->latestArtifactPayload($run) ?? [], 'composition_runtime_trace');
                $immutableRequest = $this->evidence->latestArtifactPayload($run, 'evaluation_request');
            } catch (Throwable) {
                // Corrupt historical artifacts are not prospective controls.
                continue;
            }
            if (! is_array($immutableTrace) || ! $this->evidence->equivalentJsonValue($immutableTrace, $trace)) {
                continue;
            }
            $funnel['immutable_run_sources']++;
            $identity = (array) data_get($passport, 'components', []);
            $sourceParameters = (array) $source->modelVersion?->parameters;
            $family = (string) $source->strategy_family;
            if ($sourceParameters === [] || $this->schemas->schema($family) === []) {
                continue;
            }
            try {
                $this->schemas->validate($family, $sourceParameters);
            } catch (Throwable) {
                continue;
            }
            if (! is_array($immutableRequest)
                || ! $this->evidence->equivalentJsonValue($immutableRequest, data_get($run->request_meta, 'payload'))
                || strlen((string) data_get($immutableRequest, 'execution_contract.execution_hash', '')) !== 64
                || strlen((string) data_get($run->request_meta, 'dataset_manifest.mtf_bundle_hash', '')) !== 64) {
                continue;
            }
            $requestStrategy = collect((array) data_get($immutableRequest, 'strategies', []))
                ->first(fn (mixed $row): bool => is_array($row)
                    && (int) data_get($row, 'lab_agent_id', 0) === (int) $source->id);
            $requestedParameters = is_array($requestStrategy)
                ? data_get($requestStrategy, 'parameters') : null;
            if (! is_array($requestedParameters)
                || (string) data_get($requestStrategy, 'composition_runtime_contract.composition_id', '')
                    !== (string) data_get($passport, 'composition_id', '')
                || (string) data_get($requestStrategy, 'specialist_context_contract.venue_phase', '')
                    !== $sourcePhase
                || $this->schemas->canonicalizeForIdentity($family, $requestedParameters)
                    !== $this->schemas->canonicalizeForIdentity($family, $sourceParameters)) {
                continue;
            }
            $funnel['exact_runtime_request_sources']++;
            $matching = collect($plan)->filter(function (array $slot) use ($source, $identity): bool {
                return (string) data_get($slot, 'family') === (string) $source->strategy_family
                    && (array) data_get($slot, 'niche.composition_passport.components', []) === $identity
                    && ! filled(data_get($slot, 'niche.causal_learning_cohort.role'));
            });
            $sourceProposalCount = count($proposals);
            if ($matching->isNotEmpty()) {
                $funnel['same_passport_plan_sources']++;
            }
            $legalGenes = [];
            foreach ($matching as $baseIndex => $base) {
                $a = $this->legalIntervention($base, $sourceParameters);
                if ($a === null) {
                    continue;
                }
                $legalGenes[$a['gene']] = true;
                foreach ($plan as $bIndex => $other) {
                    if ($bIndex === $baseIndex
                        || (string) data_get($other, 'family') !== (string) $source->strategy_family
                        || (array) data_get($other, 'niche.composition_passport.components', []) !== $identity
                        || filled(data_get($other, 'niche.causal_learning_cohort.role'))) {
                        continue;
                    }
                    $b = $this->legalIntervention($other, $sourceParameters);
                    if ($b === null || $a['gene'] === $b['gene']
                        || $this->exactIntervention($family, $sourceParameters, [
                            $a['gene'] => $a['value'], $b['gene'] => $b['value'],
                        ]) === null) {
                        continue;
                    }
                    $hypothesisKey = hash('sha256', json_encode([
                        self::PROTOCOL, $run->data_hash, $sourcePhase, $identity,
                        collect([$a, $b])->sortBy('gene')->values()->all(),
                    ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                    $proposals[] = [
                        'hypothesis_key' => $hypothesisKey,
                        'max_discovery_trials' => 1,
                        'source_agent_id' => (int) $source->id,
                        'source_model_version_id' => (int) $source->model_version_id,
                        'source_parameter_hash' => hash('sha256', json_encode(
                            $this->schemas->canonicalizeForIdentity((string) $source->strategy_family,
                                $sourceParameters), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
                        )),
                        'source_run_id' => (string) $run->run_id,
                        'source_response_hash' => (string) $run->response_hash,
                        'source_data_hash' => (string) $run->data_hash,
                        'source_execution_hash' => (string) data_get($immutableRequest, 'execution_contract.execution_hash', ''),
                        'source_mtf_bundle_hash' => (string) data_get($run->request_meta, 'dataset_manifest.mtf_bundle_hash', ''),
                        'source_generation_id' => (int) $source->lab_generation_id,
                        'source_venue_phase' => $sourcePhase,
                        'base_index' => (int) $baseIndex,
                        'other_index' => (int) $bIndex,
                        'components' => $identity,
                        'strategy_signals' => $signals,
                        'a' => $a,
                        'b' => $b,
                        'selection_tier' => 'promising_unconfirmed',
                        'evidence_axes' => [
                            'after_cost_expectancy_r' => data_get($decision->metrics, 'after_cost_expectancy_r'),
                            'max_drawdown_percent' => data_get($decision->metrics, 'max_drawdown_percent'),
                            'trades' => data_get($decision->metrics, 'total_trades'),
                            'causal_confirmation' => false,
                        ],
                    ];
                }
            }
            if (count($legalGenes) >= 2) {
                $funnel['two_legal_axis_sources']++;
            }
            if (count($proposals) === $sourceProposalCount) {
                $axes = $this->verifiedPhaseProbeAxes($source, $decision, $sourceParameters);
                if ($axes !== null) {
                    [$a, $b] = $axes;
                    $funnel['phase_probe_owned_sources']++;
                    $funnel['two_legal_axis_sources']++;
                    $hypothesisKey = hash('sha256', json_encode([
                        self::PROTOCOL, $run->data_hash, $sourcePhase, $identity,
                        collect([$a, $b])->sortBy('gene')->values()->all(),
                    ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                    $proposals[] = [
                        'hypothesis_key' => $hypothesisKey, 'max_discovery_trials' => 1,
                        'source_agent_id' => (int) $source->id,
                        'source_model_version_id' => (int) $source->model_version_id,
                        'source_parameter_hash' => hash('sha256', json_encode(
                            $this->schemas->canonicalizeForIdentity($family, $sourceParameters),
                            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                        'source_run_id' => (string) $run->run_id,
                        'source_response_hash' => (string) $run->response_hash,
                        'source_data_hash' => (string) $run->data_hash,
                        'source_execution_hash' => (string) data_get($immutableRequest, 'execution_contract.execution_hash'),
                        'source_mtf_bundle_hash' => (string) data_get($run->request_meta, 'dataset_manifest.mtf_bundle_hash'),
                        'source_generation_id' => (int) $source->lab_generation_id,
                        'source_venue_phase' => $sourcePhase,
                        'source_composition_id' => (string) data_get($passport, 'composition_id'),
                        'source_owned_probe' => true,
                        'base_index' => -1, 'other_index' => -1,
                        'components' => $identity, 'strategy_signals' => $signals,
                        'a' => $a, 'b' => $b, 'selection_tier' => 'promising_unconfirmed',
                        'evidence_axes' => [
                            'after_cost_expectancy_r' => data_get($decision->metrics, 'after_cost_expectancy_r'),
                            'max_drawdown_percent' => data_get($decision->metrics, 'max_drawdown_percent'),
                            'trades' => data_get($decision->metrics, 'total_trades'),
                            'causal_confirmation' => false,
                        ],
                    ];
                }
            }
        }
        $funnel['joint_delta_proposals'] = count($proposals);
        if ($proposals === []) {
            return $this->unavailable($funnel['missing_observed_liquidity_sources'] > 0
                ? 'observed_liquidity_data_required_before_activation_probe'
                : 'no_compatible_source_and_legal_two_axis_probe', $funnel);
        }
        foreach ($proposals as &$proposal) {
            $proposal['instrument_learnability'] = $this->instrumentLearnability($proposal);
        }
        unset($proposal);
        $funnel['learnable_axis_pairs'] = collect($proposals)->filter(fn (array $proposal): bool =>
            (int) data_get($proposal, 'instrument_learnability.priority', 0) >= 3)->count();
        if ($funnel['learnable_axis_pairs'] === 0) {
            return $this->unavailable('sealed_source_not_learnable_for_two_axes', $funnel);
        }
        $proposals = array_values(array_filter($proposals, static fn (array $proposal): bool =>
            (int) data_get($proposal, 'instrument_learnability.priority', 0) >= 3));
        // Prefer observable, powered questions before signal volume. Facts
        // come only from the sealed discovery run; no present-market leakage
        // or validation profitability is consulted when choosing a probe.
        usort($proposals, static fn (array $a, array $b): int =>
            [$b['instrument_learnability']['priority'], $b['instrument_learnability']['uncertainty'] ?? -1,
                min(100, $b['strategy_signals']), $b['source_generation_id'], -$b['base_index']]
            <=> [$a['instrument_learnability']['priority'], $a['instrument_learnability']['uncertainty'] ?? -1,
                min(100, $a['strategy_signals']), $a['source_generation_id'], -$a['base_index']]);
        $selected = collect($proposals)->first(fn (array $proposal): bool =>
            ! LabGeneration::query()->where('ai_laboratory_id', $lab->id)
                ->where('trigger_context->specialist_council_contract->contextual_allocator->proof_frontier->proposal->hypothesis_key',
                    $proposal['hypothesis_key'])->exists()
        );
        if ($selected === null) {
            return $this->unavailable('discovery_trial_budget_exhausted_on_frozen_data', $funnel);
        }
        $selected['validation_plan'] = app(ActivationValidationPlanService::class)->reserve($selected);

        $incumbents = Schema::hasTable('contextual_specialist_capsules')
            ? ContextualSpecialistCapsule::query()->where('symbol', $lab->symbol)
                ->where('timeframe', $lab->timeframe)->where('status', 'elite')
                ->latest('id')->limit(3)->get()->map(fn (ContextualSpecialistCapsule $row): array => [
                    'capsule_id' => (int) $row->id,
                    'agent_id' => (int) $row->lab_agent_id,
                    'context_cell_key' => (string) $row->context_cell_key,
                    'evidence_axes' => (array) $row->pareto_vector,
                    'authority_level' => (string) $row->authority_level,
                ])->all() : [];

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'proposed',
            'question' => 'Can two legal upstream changes jointly open the strategy-to-tactic-to-entry path?',
            'frontier_stage' => 'strategy_to_tactic_activation',
            'eligibility_funnel' => $funnel,
            'proposal' => $selected,
            'shortlist_count' => count($proposals),
            'portfolio' => [
                'incumbents_preserved' => $incumbents,
                'unconfirmed_challengers' => collect($proposals)->take(3)->map(fn (array $row): array => [
                    'source_agent_id' => $row['source_agent_id'],
                    'hypothesis_key' => $row['hypothesis_key'],
                    'evidence_axes' => $row['evidence_axes'],
                    'frontier_stage' => 'strategy_to_tactic_activation',
                ])->all(),
                'selection_rule' => 'sealed_data_stage_control_learnability_then_source_exploration_uncertainty_then_capped_signal_tie_breaker',
                'economic_axes_not_collapsed_to_one_score' => true,
            ],
            'heuristic_priority_is_not_calibrated_information_gain' => true,
            'authority_ceiling' => 'research_hypothesis_only',
            'economic_credit_allowed' => false,
            'component_credit_allowed' => false,
            'promotion_evidence' => false,
        ];
    }

    private function instrumentLearnability(array $proposal): array
    {
        $source = LabAgent::with('modelVersion')->find((int) $proposal['source_agent_id']);
        $run = LabEvaluationRun::where('run_id', $proposal['source_run_id'])->first();
        $result = $run ? (array) $this->evidence->latestArtifactPayload($run) : [];
        $scope = (array) data_get($result, 'data_quality.specialist_signal_scope', []);
        $facts = ['sealed_source' => $run !== null, 'dataset_hash' => $proposal['source_data_hash'],
            'context_key' => $proposal['source_venue_phase'],
            'context_opportunities' => array_key_exists('accepted_signal_count', $scope)
                ? (int) $scope['accepted_signal_count']
                : (int) $proposal['strategy_signals'],
            'exact_control_available' => false, // It will be prospectively frozen, not backfilled.
            'prospective_control_feasible' => true, // Proposal identity was verified; admission must still freeze it.
            'data_available' => ['closed_ohlc' => $run !== null,
                'canonical_volume' => data_get($result, 'data_quality.volume_features.volume_available') === true,
                'observed_bid_ask' => data_get($result, 'data_quality.spread_quality.provider_observed') === true
                    && (float) data_get($result, 'data_quality.spread_quality.coverage', 0) >= .95],
            'stages' => ['pre_entry_decision' => (int) $proposal['strategy_signals'] > 0,
                'risk_sizing' => (int) data_get($result, 'total_trades', 0) > 0,
                'position_history' => (int) data_get($result, 'total_trades', 0) > 0,
                'position_management' => (int) data_get($result, 'composition_runtime_trace.observations.management_events', 0) > 0],
            'evidence_run_id' => $proposal['source_run_id'], 'response_hash' => $proposal['source_response_hash']];
        $axes = [];
        foreach (['a', 'b'] as $axis) {
            $key = app(LabInstrumentResearchService::class)->instrumentForGene(
                $proposal[$axis]['gene'], (string) $source?->strategy_family);
            $observation = collect((array) data_get($result, 'instrument_research_trace.instruments', []))
                ->first(fn ($row): bool => is_array($row)
                    && (string) ($row['instrument_key'] ?? '') === $key
                    && ($row['runtime_observation_valid'] ?? false) === true);
            $axes[$axis] = ['instrument_key' => $key,
                ...app(TradingInstrumentOperatingSystemService::class)->researchReadiness($key,
                    (array) $source?->modelVersion?->parameters, [
                        ...$facts,
                        'source_instrument_evaluations' => $observation === null ? null
                            : data_get($observation, 'evaluation_count'),
                    ])];
        }
        $uncertainty = collect($axes)->pluck('uncertainty')->filter(fn ($value): bool => is_numeric($value));
        return ['protocol' => TradingInstrumentOperatingSystemService::RESEARCH_READINESS_PROTOCOL, 'axes' => $axes,
            'priority' => min($axes['a']['priority'], $axes['b']['priority']),
            'uncertainty' => $uncertainty->count() === 2 ? min($uncertainty->all()) : null,
            'purpose' => 'discovery_compute_priority_only', 'control_freeze_required' => true,
            'economic_credit_allowed' => false, 'promotion_evidence' => false];
    }

    /**
     * A historical unphased signal is only a hypothesis. Re-run its exact
     * executable vector in a phase chosen before the new replay; never infer
     * a phase from the historical signal timestamps or grant causal credit.
     *
     * @return array<string,mixed>
     */
    public function proposePhaseScope(AiLaboratory $lab, array $plan): array
    {
        $unavailable = static fn (string $reason): array => [
            'protocol' => self::PHASE_PROBE_PROTOCOL, 'status' => 'not_proposed',
            'reason' => $reason, 'promotion_evidence' => false,
        ];
        if (! Schema::hasTable('lab_agents') || ! Schema::hasTable('lab_evaluation_runs')) {
            return $unavailable('evidence_tables_missing');
        }
        // The phase is a fixed, prospective research question. Changing this
        // constant is a new protocol, not a retrospective label on G234.
        $phase = 'london_comex_overlap';
        if (! in_array($phase, $this->calendar->researchPhases(), true)) {
            return $unavailable('phase_not_in_research_calendar');
        }
        $generationIds = $lab->generations()->whereIn('status', ['screened', 'completed'])
            ->orderByDesc('generation')->limit(8)->pluck('id');
        $sources = LabAgent::query()->with('modelVersion')
            ->whereIn('lab_generation_id', $generationIds)
            ->whereNotIn('lifecycle_status', ['technical_quarantine', 'quarantined', 'evaluation_error'])
            ->orderByDesc('id')->limit(160)->get();
        foreach ($sources as $source) {
            $metadata = (array) $source->modelVersion?->metadata;
            $passport = (array) data_get($metadata, 'smart_composition.composition_passport', []);
            if ((string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL
                || filled(data_get($metadata, 'specialist_council_membership.contextual_cell.venue_phase'))) {
                continue;
            }
            $decision = CandidateGateDecision::query()->where('lab_agent_id', $source->id)
                ->where('stage', 'screening')->latest('id')->first();
            $trace = (array) data_get($decision?->metrics, 'composition_runtime_trace', []);
            $signals = (int) data_get($trace, 'observations.strategy_signals_before_tactic', 0);
            if (data_get($trace, 'execution_receipt_valid') !== true
                || data_get($trace, 'component_bindings_valid') !== true
                || data_get($trace, 'authority_bindings_valid') !== true
                || (string) data_get($trace, 'composition_id') !== (string) data_get($passport, 'composition_id')
                || $signals < self::MIN_SIGNAL_OPPORTUNITIES
                || (int) data_get($trace, 'observations.rows', 0) < self::MIN_PAIRED_OPPORTUNITIES
                || (int) data_get($trace, 'component_execution.tactic.accepted_count', -1) !== 0) {
                continue;
            }
            $runId = (string) data_get($decision?->metrics, 'evidence_run_id', '');
            $run = $runId !== '' ? LabEvaluationRun::query()->where('lab_agent_id', $source->id)
                ->where('run_id', $runId)->where('phase', 'screening')->where('status', 'completed')->first() : null;
            if (! $run || ! filled($run->data_hash) || ! filled($run->response_hash)) {
                continue;
            }
            try {
                if (! $this->evidence->learningEligibility($run)['complete']
                    || ! $this->evidence->equivalentJsonValue(
                        data_get($this->evidence->latestArtifactPayload($run) ?? [], 'composition_runtime_trace'), $trace)) {
                    continue;
                }
                $request = $this->evidence->latestArtifactPayload($run, 'evaluation_request');
            } catch (Throwable) {
                continue;
            }
            $strategy = is_array($request) ? collect((array) data_get($request, 'strategies', []))
                ->first(fn (mixed $row): bool => is_array($row)
                    && (int) data_get($row, 'lab_agent_id', 0) === (int) $source->id) : null;
            $family = (string) $source->strategy_family;
            $parameters = (array) $source->modelVersion?->parameters;
            if (! is_array($request)
                || ! $this->evidence->equivalentJsonValue($request, data_get($run->request_meta, 'payload'))
                || ! is_array($strategy)
                || filled(data_get($strategy, 'specialist_context_contract.venue_phase'))
                || (string) data_get($strategy, 'composition_runtime_contract.composition_id')
                    !== (string) data_get($passport, 'composition_id')
                || strlen((string) data_get($request, 'execution_contract.execution_hash', '')) !== 64
                || strlen((string) data_get($run->request_meta, 'dataset_manifest.mtf_bundle_hash', '')) !== 64
                || $parameters === [] || $this->schemas->schema($family) === []
                || $this->schemas->canonicalizeForIdentity($family, (array) data_get($strategy, 'parameters', []))
                    !== $this->schemas->canonicalizeForIdentity($family, $parameters)) {
                continue;
            }
            try {
                $this->schemas->validate($family, $parameters);
            } catch (Throwable) {
                continue;
            }
            $actualBase = $this->schemas->runtimeBaseStrategy(
                (string) $source->modelVersion?->strategy,
                data_get($metadata, 'base_strategy'), $family,
            );
            $frozenScope = (array) data_get($passport, 'strategy_signal_scope', []);
            $actualScope = app(StrategyLibraryCompilerService::class)->signalScope($actualBase);
            $prospectivePassport = null;
            if ($frozenScope === []) {
                try {
                    $prospectivePassport = app(CompositionAuthorityKernelService::class)
                        ->refreezeHistoricalHypothesis($passport, $actualBase);
                } catch (Throwable) {
                    continue;
                }
            } elseif ($frozenScope !== $actualScope) {
                continue;
            }
            $probeProtocol = $prospectivePassport === null
                ? self::PHASE_PROBE_PROTOCOL : self::PHASE_PROBE_REFREEZE_PROTOCOL;
            $prospectiveIdentity = $prospectivePassport === null ? [] : [
                'prospective_composition_id' => (string) $prospectivePassport['composition_id'],
                'prospective_passport_hash' => hash('sha256', json_encode(
                    $prospectivePassport, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            ];
            $intervention = $this->phaseDiagnosticIntervention($family, $parameters, $plan);
            if ($intervention === null) {
                continue;
            }
            $key = hash('sha256', json_encode([$probeProtocol, (int) $source->id,
                (string) $run->data_hash, (string) $run->response_hash, $phase,
                (array) data_get($passport, 'components', []), $intervention,
                ...($prospectivePassport === null ? [] : array_values($prospectiveIdentity))],
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            if (LabGeneration::query()->where('ai_laboratory_id', $lab->id)
                ->where('trigger_context->specialist_council_contract->contextual_allocator->phase_scope_probe->proposal->probe_key', $key)
                ->exists()) {
                continue;
            }

            return [
                'protocol' => $probeProtocol, 'status' => 'proposed',
                'question' => 'Does the exact historical source activate in a prospectively declared venue phase?',
                'proposal' => [
                    ...($prospectivePassport === null ? [] : ['protocol' => $probeProtocol]),
                    'probe_key' => $key, 'source_agent_id' => (int) $source->id,
                    'source_model_version_id' => (int) $source->model_version_id,
                    'source_generation_id' => (int) $source->lab_generation_id,
                    'source_run_id' => (string) $run->run_id,
                    'source_data_hash' => (string) $run->data_hash,
                    'source_response_hash' => (string) $run->response_hash,
                    'source_execution_hash' => (string) data_get($request, 'execution_contract.execution_hash'),
                    'source_mtf_bundle_hash' => (string) data_get($run->request_meta, 'dataset_manifest.mtf_bundle_hash'),
                    'source_parameter_hash' => hash('sha256', json_encode(
                        $this->schemas->canonicalizeForIdentity($family, $parameters),
                        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                    'source_composition_id' => (string) data_get($passport, 'composition_id'),
                    ...$prospectiveIdentity,
                    'components' => (array) data_get($passport, 'components', []),
                    'venue_phase' => $phase, 'phase_selection_policy' => 'fixed_before_replay_v1',
                    'diagnostic_intervention' => $intervention,
                    'source_signal_count' => $signals,
                ],
                'authority_ceiling' => 'research_hypothesis_only',
                'economic_credit_allowed' => false, 'component_credit_allowed' => false,
                'promotion_evidence' => false,
            ];
        }

        return $unavailable('no_immutable_unphased_signal_source_with_legal_diagnostic_axis');
    }

    /** @return array{gene:string,value:mixed}|null */
    private function phaseDiagnosticIntervention(string $family, array $parameters, array $plan): ?array
    {
        foreach ($plan as $slot) {
            if ((string) data_get($slot, 'family') !== $family) {
                continue;
            }
            $intervention = $this->legalIntervention($slot, $parameters);
            if ($intervention !== null && in_array($intervention['gene'], [
                'entry_topology_variant', 'regime_classifier_variant', 'lookback', 'trend_strength_min',
            ], true)) {
                return $intervention;
            }
        }
        foreach (['regime_classifier_variant', 'entry_topology_variant'] as $gene) {
            $definition = (array) ($this->schemas->schema($family)[$gene] ?? []);
            foreach ((array) ($definition[1] ?? []) as $value) {
                if (($parameters[$gene] ?? null) === $value) {
                    continue;
                }
                $candidate = $this->exactIntervention($family, $parameters, [$gene => $value]);
                if ($candidate !== null) {
                    return ['gene' => $gene, 'value' => $candidate[$gene]];
                }
            }
        }

        return null;
    }

    /** A completed phase probe may own two fresh axes under its own passport. */
    private function verifiedPhaseProbeAxes(LabAgent $source, CandidateGateDecision $decision, array $parameters): ?array
    {
        $probe = (array) data_get($source->modelVersion?->metadata, 'phase_scope_probe', []);
        $blockKey = (string) data_get($source->modelVersion?->metadata,
            'cooperative_experiment_block.block_key', '');
        if ((string) data_get($probe, 'arm') !== 'phase_control' || $blockKey === ''
            || data_get($probe, 'credit_allowed') !== false) {
            return null;
        }
        $settlement = CooperativeExperimentSettlement::query()
            ->where('lab_generation_id', $source->lab_generation_id)
            ->where('block_key', $blockKey)->where('block_type', 'phase_scope_probe')
            ->where('outcome_status', 'phase_scope_tactic_veto_reproduced')
            ->where('evidence_complete', true)->first();
        if (! $settlement
            || (int) data_get($settlement->arm_results, 'phase_control.lab_agent_id', 0) !== (int) $source->id
            || (int) data_get($settlement->arm_results, 'phase_control.decision_id', 0) !== (int) $decision->id) {
            return null;
        }
        $family = (string) $source->strategy_family;
        $axes = [];
        foreach (['regime_classifier_variant', 'entry_topology_variant'] as $gene) {
            $definition = (array) ($this->schemas->schema($family)[$gene] ?? []);
            foreach ((array) ($definition[1] ?? []) as $value) {
                if (($parameters[$gene] ?? null) === $value) {
                    continue;
                }
                $candidate = $this->exactIntervention($family, $parameters, [$gene => $value]);
                if ($candidate !== null) {
                    $axes[] = ['gene' => $gene, 'value' => $candidate[$gene]];
                    break;
                }
            }
        }
        if (count($axes) !== 2 || $this->exactIntervention($family, $parameters, [
            $axes[0]['gene'] => $axes[0]['value'], $axes[1]['gene'] => $axes[1]['value'],
        ]) === null) {
            return null;
        }

        return $axes;
    }

    /** @return array{gene:string,value:mixed}|null */
    private function legalIntervention(array $slot, array $sourceParameters): ?array
    {
        if ((bool) data_get($slot, 'niche.control_only', false)
            || count((array) data_get($slot, 'niche.declared_values', [])) > 0
            || ! array_key_exists('declared_value', (array) data_get($slot, 'niche', []))) {
            return null;
        }
        $family = (string) data_get($slot, 'family', '');
        $gene = (string) data_get($slot, 'niche.declared_gene', '');
        $value = data_get($slot, 'niche.declared_value');
        if (! in_array($gene, self::UPSTREAM_GENES, true)
            || ! array_key_exists($gene, $this->schemas->schema($family))
            || ! is_scalar($value)) {
            return null;
        }
        $validated = $this->exactIntervention($family, $sourceParameters, [$gene => $value]);

        return $validated === null ? null : ['gene' => $gene, 'value' => $validated[$gene]];
    }

    /** A candidate may change only the declared genes of the exact source vector. */
    private function exactIntervention(string $family, array $sourceParameters, array $changes): ?array
    {
        try {
            $source = $this->schemas->canonicalizeForIdentity($family, $sourceParameters);
            $candidate = $this->schemas->validate($family, $this->schemas->normalizeForGeneration(
                $family, [...$sourceParameters, ...$changes],
            ));
            $canonical = $this->schemas->canonicalizeForIdentity($family, $candidate);
            $changed = [];
            foreach ($canonical as $gene => $value) {
                if (! array_key_exists($gene, $source) || $source[$gene] !== $value) {
                    $changed[] = $gene;
                }
            }
            $expected = array_keys($changes);
            sort($changed);
            sort($expected);
            if ($changed !== $expected) {
                return null;
            }
            foreach ($changes as $gene => $value) {
                $requested = $this->schemas->canonicalizeForIdentity($family, [$gene => $value]);
                if ($canonical[$gene] !== $requested[$gene]) {
                    return null;
                }
            }

            return $candidate;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason, array $funnel = []): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'not_proposed', 'reason' => $reason,
            'eligibility_funnel' => $funnel,
            'first_missing_proof' => $this->firstMissingProof($funnel),
            'promotion_evidence' => false];
    }

    /** @param array<string,mixed> $funnel */
    private function firstMissingProof(array $funnel): ?string
    {
        if ((int) ($funnel['missing_observed_liquidity_sources'] ?? 0) > 0
            && (int) ($funnel['phase_bound_gap_sources'] ?? 0) === 0) return 'observed_liquidity_data';
        foreach ([
            'runtime_signal_gap_sources' => 'runtime_strategy_signal_and_tactic_veto',
            'phase_bound_gap_sources' => 'eligible_source_venue_phase',
            'immutable_run_sources' => 'immutable_source_run',
            'exact_runtime_request_sources' => 'exact_runtime_request_identity',
            'same_passport_plan_sources' => 'same_passport_plan_slot',
            'two_legal_axis_sources' => 'two_distinct_legal_upstream_axes',
            'joint_delta_proposals' => 'joint_delta_without_hidden_gene',
            'learnable_axis_pairs' => 'source_data_stage_and_control_path_for_both_axes',
        ] as $countKey => $proof) {
            if (array_key_exists($countKey, $funnel) && (int) $funnel[$countKey] === 0) {
                return $proof;
            }
        }

        return null;
    }
}
