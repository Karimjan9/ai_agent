<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\ContextualSpecialistCapsule;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Selects a research question, never a trading or component-credit authority. */
class ProofFrontierService
{
    public const PROTOCOL = 'proof_frontier_activation_v1';
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
                || ! in_array($sourcePhase, $this->calendar->researchPhases(), true)
                || $sourcePhase === 'comex_maintenance'
                || (int) data_get($trace, 'component_execution.tactic.accepted_count', -1) !== 0) {
                continue;
            }
            $runId = (string) data_get($decision?->metrics, 'evidence_run_id', '');
            $run = $runId !== '' ? LabEvaluationRun::query()->where('lab_agent_id', $source->id)
                ->where('run_id', $runId)->where('phase', 'screening')->where('status', 'completed')->first() : null;
            if (! $run || ! filled($run->data_hash) || ! filled($run->response_hash)
                || ! $this->evidence->learningEligibility($run)['complete']) {
                continue;
            }
            $immutableTrace = data_get($this->evidence->latestArtifactPayload($run) ?? [], 'composition_runtime_trace');
            if (! is_array($immutableTrace) || $immutableTrace !== $trace) {
                continue;
            }
            $identity = (array) data_get($passport, 'components', []);
            $sourceParameters = (array) $source->modelVersion?->parameters;
            $family = (string) $source->strategy_family;
            $defaults = $this->schemas->defaults($family);
            if ($sourceParameters === [] || $this->schemas->schema($family) === []
                || array_diff_key($defaults, $sourceParameters) !== []) {
                continue;
            }
            $immutableRequest = $this->evidence->latestArtifactPayload($run, 'evaluation_request');
            if (! is_array($immutableRequest)
                || $immutableRequest !== data_get($run->request_meta, 'payload')) {
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
            $matching = collect($plan)->filter(function (array $slot) use ($source, $identity): bool {
                return (string) data_get($slot, 'family') === (string) $source->strategy_family
                    && (array) data_get($slot, 'niche.composition_passport.components', []) === $identity
                    && ! filled(data_get($slot, 'niche.causal_learning_cohort.role'));
            });
            foreach ($matching as $baseIndex => $base) {
                $a = $this->legalIntervention($base, $sourceParameters);
                if ($a === null) {
                    continue;
                }
                foreach ($plan as $bIndex => $other) {
                    if ($bIndex === $baseIndex || (string) data_get($other, 'family') !== (string) $source->strategy_family) {
                        continue;
                    }
                    $b = $this->legalIntervention($other, $sourceParameters);
                    if ($b === null || $a['gene'] === $b['gene']) {
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
        }
        if ($proposals === []) {
            return $this->unavailable('no_compatible_source_and_legal_two_axis_probe');
        }
        // Stage progress is the research objective. Capped signal volume is
        // only a tie-breaker, never an economic score or parent authority.
        usort($proposals, static fn (array $a, array $b): int =>
            [min(100, $b['strategy_signals']), $b['source_generation_id'], -$b['base_index']]
            <=> [min(100, $a['strategy_signals']), $a['source_generation_id'], -$a['base_index']]);
        $selected = collect($proposals)->first(fn (array $proposal): bool =>
            ! LabGeneration::query()->where('ai_laboratory_id', $lab->id)
                ->where('trigger_context->specialist_council_contract->contextual_allocator->proof_frontier->proposal->hypothesis_key',
                    $proposal['hypothesis_key'])->exists()
        );
        if ($selected === null) {
            return $this->unavailable('discovery_trial_budget_exhausted_on_frozen_data');
        }

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
                'selection_rule' => 'bounded_stage_gap_then_capped_signal_tie_breaker',
                'economic_axes_not_collapsed_to_one_score' => true,
            ],
            'heuristic_priority_is_not_calibrated_information_gain' => true,
            'authority_ceiling' => 'research_hypothesis_only',
            'economic_credit_allowed' => false,
            'component_credit_allowed' => false,
            'promotion_evidence' => false,
        ];
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
        try {
            $candidate = [...$this->schemas->defaults($family), $gene => $value];
            $validated = $this->schemas->validate($family, $this->schemas->normalizeForGeneration($family, $candidate));
            $baseValue = $sourceParameters[$gene] ?? data_get($this->schemas->defaults($family), $gene);
            if (json_encode($validated[$gene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                === json_encode($baseValue, JSON_PRESERVE_ZERO_FRACTION)) {
                return null;
            }

            return ['gene' => $gene, 'value' => $validated[$gene]];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'not_proposed', 'reason' => $reason,
            'promotion_evidence' => false];
    }
}
