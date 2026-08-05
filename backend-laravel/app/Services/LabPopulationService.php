<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\AgentDiagnosis;
use App\Models\CandidateGateDecision;
use App\Models\AgentFailureCase;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\Symbol;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Support\Facades\DB;

class LabPopulationService
{
    /**
     * One laboratory has one evidence stream.  A new generation may not be
     * created while the previous stream is still producing screening or full
     * validation evidence, even when an operator supplies --force.
     */
    public const ACTIVE_GENERATION_STATUSES = [
        'draft', 'queued', 'training', 'screening', 'screened',
        'full_queued', 'full_validation',
    ];

    public const GENERATION_PROTOCOL = 'g98_failure_eliminator_v1';

    private const LABS = [
        'XAUUSD' => ['name' => 'XAUUSD Lab', 'families' => ['trend', 'breakout', 'volatility', 'hybrid', 'regime_ensemble', 'differential_router']],
        'EURUSD' => ['name' => 'EURUSD Lab', 'families' => ['trend', 'mean_reversion', 'session', 'hybrid', 'regime_ensemble']],
        'GBPUSD' => ['name' => 'GBPUSD Lab', 'families' => ['breakout', 'momentum', 'volatility', 'hybrid', 'regime_ensemble']],
    ];

    // Architecture is a first-class gene.  Parameters tune an implementation;
    // this gene chooses the decision topology that is allowed to compete.
    private const ARCHITECTURES = [
        'trend' => ['trend_pullback', 'trend_breakout_retest'],
        'breakout' => ['breakout_retest', 'breakout_continuation'],
        'volatility' => ['volatility_compression_expansion', 'volatility_breakout'],
        'mean_reversion' => ['range_mean_reversion', 'range_rsi_reversion'],
        'session' => ['session_breakout', 'session_mean_reversion'],
        'momentum' => ['momentum_continuation', 'momentum_pullback'],
        'hybrid' => ['regime_router', 'regime_consensus'],
        'regime_ensemble' => ['frozen_regime_specialist_ensemble'],
        'differential_router' => ['frozen_parent_differential_router'],
    ];

    /** Cached historical topologies used by the bounded novelty contract. */
    private array $historicalParameterFingerprints = [];

    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private MarketDataContinuityService $continuity,
        private HistoricalDataQualityService $historicalData,
        private DecisionLearningService $decisionLearning,
        private BayesianMutationLaboratoryService $bayesianMutations,
        private AgentEvolutionQualityService $evolutionQuality,
        private AgentConstitutionService $constitutions,
        private UniversalAgentCapabilityService $universalCapabilities,
        private VetoPolicyLaboratoryService $vetoPolicies,
        private RegimeReservoirService $regimeReservoir,
        private LearningProtocolSafetyService $protocolSafety,
        private LabHistoricalLearningService $historicalLearning,
    ) {}

    public function ensureLaboratories(): void
    {
        foreach (self::LABS as $symbol => $config) {
            AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => 'H1'], [
                'name' => $config['name'], 'timeframe' => 'H1',
                'strategy_families' => $config['families'], 'is_active' => true,
            ]);
        }

        // EURUSD uses a closed H1 regime as context and M15 only for entries.
        // It is a separate evidence stream, never a replacement for the H1 lab.
        AiLaboratory::updateOrCreate(['symbol' => 'EURUSD', 'timeframe' => 'M15'], [
            'name' => 'EURUSD M15 Specialist Lab', 'timeframe' => 'M15',
            'strategy_families' => ['trend', 'mean_reversion', 'session', 'breakout', 'regime_ensemble'], 'is_active' => true,
        ]);
    }

    public function build(string $symbol, string $trigger = 'new_data', bool $force = false, string $timeframe = 'H1', array $coverageRescue = []): ?LabGeneration
    {
        // Existing queued jobs stay intact.  This only prevents creation of a
        // new population while an execution-contract rollout is being audited.
        if ($this->protocolSafety->generationCreationPaused()) return null;
        $this->ensureLaboratories();
        $timeframe = strtoupper($timeframe);
        $lab = AiLaboratory::where('symbol', strtoupper($symbol))->where('timeframe', $timeframe)->firstOrFail();
        // Recompute the append-only historical conclusion before planning a
        // new population. Snapshot history may choose the failure target;
        // exact causal credits are handled separately by mutate().
        $this->historicalLearning->refreshForLab($lab->symbol, $lab->timeframe);
        $provider = (string) config('services.market_data.provider', 'csv');
        if (! $force && $provider !== 'csv' && ! $this->continuity->isReady($provider, $lab->symbol, $lab->timeframe)) {
            return null;
        }
        // A forced protocol activation is an explicit operator action after a
        // successful market-data audit.  Normal scheduled populations remain
        // blocked by both continuity and historical-data readiness gates.
        if (! $force && ! app()->environment('testing') && ! $this->historicalData->ready($lab->symbol, $lab->timeframe)) {
            return null;
        }
        $snapshot = $this->dataSnapshot($lab);
        $fingerprint = $snapshot['fingerprint'];
        $latest = $lab->generations()->latest('generation')->first();
        // A long-lived scheduler can submit the generic candidate-handoff
        // command just after an operator records the required data/edge
        // audit. Route that request through the explicit audit protocol so a
        // stale scheduler cannot create a generic generation and silently
        // bypass the council plan.
        if ($trigger === 'candidate_handoff'
            && is_array(data_get($latest?->trigger_context, 'data_edge_audit'))) {
            $trigger = 'data_edge_audit';
        }
        if ($trigger === 'new_data' && ModelMarketPerformance::where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->where('status', 'champion')->where('evidence_status', 'valid')
            ->where('consecutive_no_improvement', '>=', 3)->exists()) {
            $trigger = 'degradation';
        }
        // A completed screening with no eligible full-replay candidate is an
        // intentional handoff boundary.  The targeted-generation builder
        // must be able to consume that immutable failure curriculum without
        // opening a second population during live screening or full replay.
        $screenedCandidateHandoff = $trigger === 'candidate_handoff'
            && $latest?->status === 'screened';
        $screenedDataEdgeAudit = $trigger === 'data_edge_audit'
            && $latest?->status === 'screened'
            && is_array(data_get($latest?->trigger_context, 'data_edge_audit'));
        if ($latest && in_array($latest->status, self::ACTIVE_GENERATION_STATUSES, true)
            && ! $screenedCandidateHandoff && ! $screenedDataEdgeAudit) {
            return null;
        }
        $latestRequiresAudit = $latest
            && data_get($latest->trigger_context, 'latest_generation_report.next_action') === 'data_edge_audit_required';
        $auditEvidence = data_get($latest?->trigger_context, 'data_edge_audit');
        if ($trigger === 'coverage_rescue' && (! (bool) data_get($coverageRescue, 'eligible') || data_get($coverageRescue, 'failure') !== 'operating_envelope_coverage_sparse')) return null;
        if (($latestRequiresAudit && ! in_array($trigger, ['data_edge_audit', 'coverage_rescue'], true))
            || ($trigger === 'data_edge_audit' && ! is_array($auditEvidence))) {
            return null;
        }
        $newCandles = $snapshot['count'] - (int) data_get($latest?->trigger_context, 'data_count', 0);
        // H1 learning cadence: a new population needs a full day of fresh
        // evidence.  Degradation is an immediate safety exception; market
        // drift is confirmed separately and still needs new candles so the
        // hourly detector cannot create a 20-agent generation storm.
        if (! $force && $latest && $newCandles < 24 && ! in_array($trigger, ['degradation', 'candidate_handoff', 'data_edge_audit'], true)) {
            return null;
        }

        return DB::transaction(function () use ($lab, $trigger, $fingerprint, $snapshot, $newCandles, $coverageRescue): ?LabGeneration {
            // Scheduler and manual/operator requests may arrive together. Lock
            // the laboratory row before assigning the next generation number;
            // otherwise two workers can build the same G and one can leave a
            // partially recorded handoff behind.
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $latestInTransaction = $lockedLab->generations()->latest('generation')->lockForUpdate()->first();
            // Repeat the check after acquiring the row lock.  The preflight
            // check prevents normal duplicates; this one closes the race
            // between a scheduler tick and a manual/targeted invocation.
            if ($trigger === 'candidate_handoff'
                && is_array(data_get($latestInTransaction?->trigger_context, 'data_edge_audit'))) {
                $trigger = 'data_edge_audit';
            }
            $screenedCandidateHandoff = $trigger === 'candidate_handoff'
                && $latestInTransaction?->status === 'screened';
            $screenedDataEdgeAudit = $trigger === 'data_edge_audit'
                && $latestInTransaction?->status === 'screened'
                && is_array(data_get($latestInTransaction?->trigger_context, 'data_edge_audit'));
            if ($latestInTransaction
                && in_array((string) $latestInTransaction->status, self::ACTIVE_GENERATION_STATUSES, true)
                && ! $screenedCandidateHandoff && ! $screenedDataEdgeAudit) {
                return null;
            }
            $latestRequiresAudit = $latestInTransaction
                && data_get($latestInTransaction->trigger_context, 'latest_generation_report.next_action') === 'data_edge_audit_required';
            $auditEvidence = data_get($latestInTransaction?->trigger_context, 'data_edge_audit');
            if ($trigger === 'coverage_rescue' && (! (bool) data_get($coverageRescue, 'eligible') || data_get($coverageRescue, 'failure') !== 'operating_envelope_coverage_sparse')) return null;
            if (($latestRequiresAudit && ! in_array($trigger, ['data_edge_audit', 'coverage_rescue'], true))
                || ($trigger === 'data_edge_audit' && ! is_array($auditEvidence))) {
                return null;
            }
            $number = (int) ($latestInTransaction?->generation ?? 0) + 1;
            $generation = $lockedLab->generations()->create([
                'generation' => $number, 'trigger_type' => $trigger,
                'trigger_context' => ['previous_generation' => $latestInTransaction?->generation, 'created_by' => 'learning_trigger',
                    'data_count' => $snapshot['count'], 'latest_candle' => $snapshot['latest'], 'new_candles' => $newCandles,
                    'generation_protocol' => self::GENERATION_PROTOCOL,
                    'coverage_rescue_audit' => $trigger === 'coverage_rescue' ? $coverageRescue : null,
                    'portfolio_failure_curriculum' => $this->portfolioFailureCurriculum($lockedLab),
                    'portfolio_council_curriculum' => $this->portfolioCouncilCurriculum($lockedLab)],
                'data_fingerprint' => $fingerprint, 'population_size' => 20,
                'status' => 'draft', 'started_at' => now(),
            ]);

            // Fixed, auditable experiment budget.  A slot is assigned for the
            // gate it is meant to move; it is not an undifferentiated "more
            // agents" budget.
            $plan = $this->generationPlan($lockedLab, $coverageRescue);
            $generation->update(['trigger_context' => [...($generation->trigger_context ?? []), 'generation_plan' => $plan]]);
            $this->historicalLearning->recordGenerationConsumption($generation, $plan);
            foreach ($plan as $index => $spec) {
                $this->createAgent(
                    $generation,
                    $spec['family'],
                    $spec['origin'],
                    $index + 1,
                    $spec['target'],
                    $spec['niche'] ?? null,
                    $spec['history'] ?? null,
                );
            }
            return $generation->load('agents.modelVersion');
        });
    }

    /**
     * 20 slots are intentionally stable across generations so observed gate
     * transitions can be compared.  Family selection is deficit-weighted:
     * the greatest unsatisfied forward gate gets the earliest experiments.
     */
    private function generationPlan(AiLaboratory $lab, array $coverageRescue = []): array
    {
        if ((bool) data_get($coverageRescue, 'eligible')) return $this->coverageRescuePlan($coverageRescue);
        $families = $this->prioritizedFamilies($lab);
        $matrixFrontier = $this->robustnessMatrixFrontier($lab);
        $explorationOnly = $this->explorationOnlyFamilies($lab);
        $nonExploratoryFamilies = array_values(array_filter(
            $families,
            fn (array $evidence) => ! in_array($evidence['family'], $explorationOnly, true),
        ));
        // G98 is a failure-eliminator, not a PF maximizer.  The budget is
        // fully partitioned into five causal layers; there is deliberately no
        // random-explorer slot and no lane may change more than one gene.
        $slots = [];
        foreach (['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'portfolio_router'] as $lane) {
            foreach (range(1, 4) as $_) $slots[] = ['origin' => 'g98_council', 'target' => $lane];
        }

        // A failed sealed portfolio is an ensemble-level problem.  Its next
        // population must therefore contain several independent attempts for
        // each weak regime x volatility niche, instead of letting a global
        // slot index accidentally turn the rescue into a generic PF/stress
        // mutation.  These are still screening agents; no member can bypass
        // the unchanged full/forward/paper gates.
        $council = $this->portfolioCouncilCurriculum($lab);
        $councilPlan = [];
        foreach ((array) data_get($council, 'niches', []) as $nicheIndex => $niche) {
            // The third lane is deliberately a context-rescue experiment. It
            // uses regime/volatility-local entry topology, never a calendar
            // month label, so the council can repair recurring conditions
            // without learning "November" or "July" as a hidden feature.
            // Council lanes include an explicit transition/risk experiment.
            // The ordinary five-lane G98 population remains unchanged when no
            // council curriculum is active, preserving its fixed budget and
            // historical comparability.
            foreach (['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router'] as $laneIndex => $target) {
                $councilPlan[] = [
                    'origin' => 'g98_council',
                    // The range adapter already lives inside hybrid. Keep
                    // its trend/breakout lanes frozen and mutate only the
                    // range gene; a differential child would replace the
                    // parent range flow and can manufacture zero activity.
                    'family' => data_get($niche, 'regime') === 'range' ? 'hybrid' : 'differential_router',
                    'target' => $target,
                    'niche' => [
                        'protocol' => 'portfolio_council_v1',
                        'role' => 'niche_rescue',
                        'regime' => (string) data_get($niche, 'regime', 'trend_down'),
                        'volatility' => (string) data_get($niche, 'volatility', 'normal_volatility'),
                        'direction' => filled(data_get($niche, 'direction')) ? strtoupper((string) data_get($niche, 'direction')) : null,
                        'objective' => $target,
                        'source_performance_id' => data_get($niche, 'source_performance_id'),
                        'research_reason' => data_get($niche, 'reason'),
                        'opposite_profit_factor' => data_get($niche, 'opposite_profit_factor'),
                        'non_target_parent_freeze' => true,
                        'promotion_rule' => 'combined_portfolio_only',
                    ],
                ];
            }
        }
        // Keep the fixed 20-agent budget. The standard lanes fill the rest;
        // the council lanes always get first claim on the gate-targeted slots.
        $councilPlan = array_slice($councilPlan, 0, count($slots));
        $standardSlots = array_slice($slots, count($councilPlan));
        // Opportunity recall is a forward passport failure, not merely a
        // reporting metric.  Reserve one of the remaining fixed-budget
        // slots for a bounded recall experiment when the latest council
        // evidence says recall is the deficit.  The six regime-owner lanes
        // remain intact; the separate slot keeps router ownership and recall
        // repair from being conflated.
        $recallNiche = collect((array) data_get($council, 'niches', []))
            ->first(fn (array $niche): bool => in_array('FAILED_PASSPORT_OPPORTUNITY_RECALL', (array) data_get($niche, 'failed_gate_reasons', []), true));
        $recallLaneActive = is_array($recallNiche);

        $standardPlan = collect($standardSlots)->map(function (array $slot, int $localIndex) use ($families, $explorationOnly, $nonExploratoryFamilies, $councilPlan, $lab, $matrixFrontier, $recallLaneActive, $recallNiche): array {
            $index = $localIndex + count($councilPlan);
            $familyEvidence = $families[$index % count($families)];
            $origin = $slot['origin'];
            if (in_array($familyEvidence['family'], $explorationOnly, true) && $nonExploratoryFamilies !== []) {
                $familyEvidence = $nonExploratoryFamilies[$index % count($nonExploratoryFamilies)];
            }
            $targets = array_values(array_filter((array) ($familyEvidence['screening_targets'] ?? [$familyEvidence['target'] ?? 'profit_factor'])));
            $targets = $targets ?: [$familyEvidence['target'] ?? 'profit_factor'];
            $experimentTarget = $slot['target'] ?? match ($origin) {
                // The gate-targeted slot must test the family's primary
                // deficit. Previously the global slot index selected the
                // third/fourth reason for a family (for example PF instead of
                // XAU hybrid's calendar failure), so the new curriculum was
                // recorded but never actually executed. Secondary deficits
                // belong to causal/architecture lanes below.
                'gate_targeted' => ! empty($familyEvidence['portfolio_curriculum'])
                    ? $targets[$localIndex % count($targets)]
                    : $targets[0],
                // Causal lanes test a second deficit in the same family,
                // producing a useful counterfactual instead of four copies of
                // the primary mutation.
                'causal_isolation' => $targets[1] ?? $targets[0],
                default => match ($origin) {
                    'risk_exit' => 'risk_exit', 'architecture' => 'architecture',
                    'robust_crossover' => 'robustness', default => 'regime_coverage',
                },
            };
            if ($recallLaneActive && $experimentTarget === 'portfolio_router' && $localIndex === 0) {
                $experimentTarget = 'opportunity_recall';
            }
            $matrixNiche = $matrixFrontier === [] ? null : $matrixFrontier[$index % count($matrixFrontier)];
            $niche = $matrixNiche;
            if ($experimentTarget === 'opportunity_recall') {
                $niche = [
                    'protocol' => 'g98_opportunity_recall_lane_v1',
                    'role' => 'opportunity_recall_specialist',
                    'regime' => data_get($recallNiche, 'regime', data_get($matrixNiche, 'regime', 'trend_down')),
                    'volatility' => data_get($recallNiche, 'volatility', data_get($matrixNiche, 'volatility', 'normal_volatility')),
                    'direction' => data_get($recallNiche, 'direction', data_get($matrixNiche, 'direction')),
                    'objective' => 'opportunity_recall',
                    'source_performance_id' => data_get($recallNiche, 'source_performance_id'),
                    'research_reason' => 'forward_recall_below_twenty_percent',
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => 'unchanged_forward_recall_and_abstention_gates',
                ];
            }
            return [
                'origin' => $origin,
                // The router owns its own layer. It never mutates a signal
                // specialist under a misleading portfolio-router label.
                'family' => in_array($experimentTarget, ['portfolio_router', 'opportunity_recall'], true) && in_array('differential_router', $lab->strategy_families, true)
                    ? 'differential_router' : $familyEvidence['family'],
                'target' => $experimentTarget,
                // The matrix is causal attribution. Month stays attached as
                // recurrence evidence, while regime/volatility/session/side
                // identifies the only envelope the lane may try to repair.
                'niche' => $niche,
            ];
        })->all();

        return [...$councilPlan, ...$standardPlan];
    }

    /** Only uncertified operating-envelope cells receive a G102 child slot. */
    private function coverageRescuePlan(array $audit): array
    {
        $cells = array_values((array) data_get($audit, 'uncertified_cells', []));
        $parents = array_values((array) data_get($audit, 'parent_model_version_ids', []));
        if ($cells === [] || $parents === []) return [];
        return collect(range(0, 19))->map(function (int $index) use ($cells, $parents): array {
            $cell = $cells[$index % count($cells)];
            return [
                'origin' => 'coverage_rescue', 'family' => 'differential_router', 'target' => 'regime_coverage',
                'niche' => [
                    'protocol' => CoverageRescueAuditService::PROTOCOL, 'role' => 'uncertified_cell_only', ...$cell,
                    'frozen_parent_model_version_id' => (int) $parents[$index % count($parents)],
                    'entry_logic_frozen' => true, 'exit_logic_frozen' => true, 'non_target_parent_freeze' => true,
                    'differential_invariant' => 'non_target signal, confidence and trade-ledger identities must match parent; breach quarantines child.',
                ],
            ];
        })->all();
    }

    /** Recent full-replay failures become G98 context contracts, not month genes. */
    private function robustnessMatrixFrontier(AiLaboratory $lab): array
    {
        $rows = ModelMarketPerformance::query()->with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')->latest('updated_at')->take(30)->get();
        $frontier = [];
        foreach ($rows as $performance) {
            foreach ((array) data_get($performance->metrics, 'robustness_matrix.weakest_envelopes', []) as $cell) {
                $regime = (string) data_get($cell, 'regime');
                $volatility = (string) data_get($cell, 'volatility');
                $direction = strtoupper((string) data_get($cell, 'direction'));
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)
                    || ! in_array($direction, ['BUY', 'SELL'], true)) continue;
                $frontier[] = [
                    'protocol' => 'robustness_matrix_v1', 'role' => 'failure_context',
                    'regime' => $regime, 'volatility' => $volatility, 'direction' => $direction,
                    'session_utc_hour' => (string) data_get($cell, 'session', 'unknown'),
                    'calendar_role' => 'diagnostic_recurrence_only',
                    'source_performance_id' => $performance->id,
                    'trades' => (int) data_get($cell, 'trades', 0),
                    'profit_factor' => (float) data_get($cell, 'net_pf', 0),
                    'reason' => 'robustness_matrix_weak_envelope',
                ];
            }
        }
        return collect($frontier)->filter(fn (array $cell): bool => $cell['trades'] >= 3)
            ->sortBy(fn (array $cell): array => [$cell['profit_factor'], -$cell['trades']])
            ->unique(fn (array $cell): string => implode('|', [$cell['regime'], $cell['volatility'], $cell['session_utc_hour'], $cell['direction']]))
            ->take(5)->values()->all();
    }

    /** A zero-activity family may explore, but it cannot consume rescue or
     * validation budget until it demonstrates an executable opportunity. */
    private function explorationOnlyFamilies(AiLaboratory $lab): array
    {
        if ($lab->symbol !== 'XAUUSD' || $lab->timeframe !== 'H1') return [];
        $latestPopulation = $lab->generations()->where('population_size', 20)->latest('generation')->first();
        if (! $latestPopulation) return [];
        $volatility = $latestPopulation->agents()->where('strategy_family', 'volatility')->get(['sample_count']);
        return $volatility->count() >= 3 && $volatility->every(fn ($agent) => (int) $agent->sample_count === 0)
            ? ['volatility']
            : [];
    }

    private function prioritizedFamilies(AiLaboratory $lab): array
    {
        $evidence = collect($lab->strategy_families)->reject(fn (string $family) => $this->familyPaused($lab, $family))->map(function (string $family) use ($lab): array {
            $diagnosis = AgentDiagnosis::query()->whereHas('modelMarketPerformance', fn ($query) => $query
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('strategy_family', $family))
                ->latest()->first();
            $rescue = CandidateGateDecision::query()->where('stage', 'diagnostic_rescue_replay')
                ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('strategy_family', $family))
                ->latest('evaluated_at')->first();
            $vetoTarget = $this->shadowVetoTarget($lab->symbol, $lab->timeframe, $family);
            $deficits = (array) data_get($diagnosis?->evidence, 'deficits', []);
            $latest = ModelMarketPerformance::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('strategy_family', $family)->latest()->first();
            $curriculum = $latest ? $this->evolutionQuality->curriculum((array) $latest->metrics) : null;
            $history = $this->historicalLearning->latestForFamily($lab->symbol, $lab->timeframe, $family);
            $historyTarget = (string) data_get($history?->recommended_mutations, 'primary_target', '');
            $historyConfidence = (float) ($history?->confidence ?? 0);
            $doctorBundle = data_get($diagnosis?->evidence, 'gate_doctor.recommended_bundle');
            $target = $vetoTarget ?: ($doctorBundle ? $this->targetFromBundle($doctorBundle) : ($diagnosis ? $this->dominantTarget($diagnosis->primary_failure, $deficits) : ($curriculum['primary_target'] ?? $this->rescueTarget($rescue?->reason_codes ?? []))));
            $severity = (float) ($deficits['trade_deficit'] ?? 0) / 30
                + (float) ($deficits['pf_deficit'] ?? 0) / 1.3
                + (float) ($deficits['rolling_deficit'] ?? 0) / 3
                + (float) ($deficits['drawdown_excess'] ?? 0) / 15
                + (float) ($deficits['ruin_excess'] ?? 0) / 10
                + ($rescue ? 1.0 : 0.0);
            $failureCases = AgentFailureCase::query()
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('regression_status', 'open')->latest()->take(3)->get();
            // A fresh screening profile outranks stale red-team/cost cases for
            // the next population. Otherwise an old stress finding can make a
            // new generation optimize drawdown while every current candidate
            // is actually failing PF or activity.
            $screeningCase = AgentFailureCase::query()
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('discovered_by', 'CandidateHandoffProtocol')->where('regression_status', 'open')
                ->latest('updated_at')->first();
            $screeningReasons = (array) data_get($screeningCase?->evidence, "screening_failure_profile.family_reasons.{$family}", []);
            // A short screen may fail several gates at once. Preserve the
            // causal diagnosis, but retain the ranked alternatives so the
            // fixed experiment budget can test more than one bottleneck.
            $screeningTargets = $this->screeningTargetsForReasons($screeningReasons);
            $screeningTarget = $screeningTargets[0] ?? null;
            // A failed sealed portfolio is an ensemble-level failure signal.
            // Let its feature-envelope curriculum outrank stale member
            // diagnoses, while keeping month labels diagnostic only.
            $portfolioTargets = (array) data_get(
                $this->portfolioFailureCurriculum($lab),
                "targets.{$family}",
                []
            );
            if ($portfolioTargets !== []) {
                $screeningTargets = array_values(array_unique([
                    ...$portfolioTargets,
                    ...$screeningTargets,
                ]));
                $screeningTarget = $portfolioTargets[0];
            }
            // Immutable history outranks stale diagnosis when no current
            // screening profile exists. It supplies a failure target only;
            // it never grants a promotion or causal mutation direction.
            if ($historyTarget !== '') {
                $screeningTargets = array_values(array_unique([$historyTarget, ...$screeningTargets]));
                if ($screeningTarget === null && $historyConfidence >= 35) $screeningTarget = $historyTarget;
            }
            $failureTarget = $screeningTarget ?: $screeningCase?->expected_action;
            if (! $failureTarget) {
                $failureTarget = match (true) {
                    $failureCases->contains('failure_type', 'cost_fragility') => 'drawdown_risk',
                    $failureCases->contains('failure_type', 'regime_coverage_quality') || $failureCases->contains('failure_type', 'transition_failure') => 'rolling_regime',
                    $failureCases->contains('failure_type', 'overfit_structure') => 'architecture',
                    $failureCases->contains('failure_type', 'edge_pf_signal_quality') => 'profit_factor',
                    $failureCases->contains('failure_type', 'trade_viability_signal_frequency') => 'trade_frequency',
                    default => null,
                };
            }
            $primaryTarget = $failureTarget ?: $target;
            if ($screeningTargets === [] && $primaryTarget) {
                $screeningTargets = [$primaryTarget];
            } elseif ($primaryTarget && ! in_array($primaryTarget, $screeningTargets, true)) {
                array_unshift($screeningTargets, $primaryTarget);
            }
            return ['family' => $family, 'target' => $primaryTarget,
                'screening_targets' => array_values(array_unique($screeningTargets)),
                'portfolio_curriculum' => $portfolioTargets !== [],
                'severity' => $severity + ($failureCases->count() * .35)
                    + ($historyTarget !== '' ? ($historyConfidence / 100) * ($history?->evidence_quality === 'exact' ? 1.5 : .35) : 0),
                'curriculum' => $curriculum,
                'failure_curriculum_case_ids' => $failureCases->pluck('id')->all(),
                'history' => $history ? [
                    'insight_id' => $history->insight_id,
                    'evidence_quality' => $history->evidence_quality,
                    'causal_prior_allowed' => (bool) $history->causal_prior_allowed,
                    'confidence' => (float) $history->confidence,
                    'primary_target' => $historyTarget ?: null,
                    'recommended_keys' => (array) data_get($history->recommended_mutations, 'keys', []),
                    'blocked_mutations' => (array) $history->blocked_mutations,
                    'failure_signature' => (array) $history->failure_signature,
                ] : null];
        })->sortByDesc('severity')->values();

        // No prior evidence is an exploration case, not an implicit claim of
        // quality. Keep the configured family order deterministic on G1.
        return $evidence->isEmpty() ? collect($lab->strategy_families)->map(fn ($family) => ['family' => $family, 'target' => 'trade_frequency', 'severity' => 0])->all() : $evidence->all();
    }

    /**
     * Convert a failed sealed portfolio into a bounded next-generation
     * curriculum. Month names remain observability evidence; the mutation
     * target is always a generalizable execution/regime feature envelope.
     */
    private function portfolioFailureCurriculum(AiLaboratory $lab): array
    {
        $portfolio = EliteAgentPortfolio::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('gate_status', 'failed')->latest('updated_at')->first();
        if (! $portfolio) return [];

        $reasons = array_values(array_unique((array) $portfolio->gate_reasons));
        $targetMap = [
            'FAILED_PORTFOLIO_CALENDAR_SURVIVAL' => 'monthly_survival',
            'FAILED_PORTFOLIO_TEMPORAL_SURVIVAL' => 'temporal_stability',
            'FAILED_PORTFOLIO_REGIME_COVERAGE' => 'regime_coverage',
            'FAILED_PORTFOLIO_STRESS_COST' => 'stress_cost',
            'FAILED_PORTFOLIO_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_PORTFOLIO_TRADE_COUNT' => 'trade_frequency',
        ];
        $targets = collect($reasons)->map(fn (string $reason) => $targetMap[$reason] ?? null)
            ->filter()->unique()->values()->all();
        if ($targets === []) return [];

        $result = (array) data_get($portfolio->evidence, 'result', []);
        $failedWindows = collect((array) data_get($result, 'window_survival.windows', []))
            ->filter(fn (array $window): bool => data_get($window, 'status') === 'edge_failure' || (bool) data_get($window, 'catastrophic', false))
            ->map(fn (array $window): array => [
                'window' => data_get($window, 'month'),
                'regime_context' => array_keys((array) data_get($window, 'regime_performance', [])),
                'trades' => (int) data_get($window, 'trades', 0),
                'profit_factor' => (float) data_get($window, 'profit_factor', 0),
            ])->values()->all();
        $breakdown = (array) data_get($result, 'portfolio_evidence.member_breakdown', []);
        $memberFailures = collect($breakdown)->map(function (array $member, string $memberKey): ?array {
            $failedMonths = collect((array) data_get($member, 'monthly', []))
                ->filter(fn (array $month): bool => (float) data_get($month, 'profit_factor', 0) < 1.0)
                ->keys()->values()->all();
            return $failedMonths === [] ? null : ['member_key' => $memberKey, 'failed_windows' => $failedMonths];
        })->filter()->values()->all();

        return [
            'protocol' => 'portfolio_feature_envelope_curriculum_v1',
            'source_portfolio_id' => $portfolio->id,
            'targets' => array_fill_keys($lab->strategy_families, $targets),
            'failed_windows' => $failedWindows,
            'member_failure_map' => $memberFailures,
            'month_labels_are_diagnostic_only' => true,
            'repair_rule' => 'freeze unaffected lanes; replace only the failing sealed niche; require control-window non-regression and all unchanged gates',
        ];
    }

    /**
     * Turn observed sealed context failures into a small specialist council.
     * The target is a regime x volatility envelope, optionally split by a
     * direction that has independent evidence; never a calendar month.
     * This method deliberately returns only research instructions; admission
     * and promotion remain the responsibility of the normal gate services.
     */
    private function portfolioCouncilCurriculum(AiLaboratory $lab): array
    {
        $portfolio = EliteAgentPortfolio::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->latest('updated_at')->first();
        // A market with no individually forward-valid member normally has a
        // portfolio in `waiting`, not `failed`: the combined replay cannot
        // start until its members exist.  Requiring `failed` here created a
        // deadlock where the council curriculum could only be created after
        // the council had already replayed.  Seed the council from the
        // immutable failed-forward evidence in that state; this is research
        // routing only and never changes any promotion gate.
        if (! $portfolio || $portfolio->gate_status !== 'failed') {
            return $this->forwardFailureCouncilCurriculum($lab);
        }

        $candidates = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->whereJsonContains('metadata->portfolio_research_contract->protocol', 'portfolio_member_research_v1'))
            ->latest('updated_at')->take(30)->get();

        $observed = [];
        foreach ($candidates as $candidate) {
            foreach ((array) data_get($candidate->metrics, 'pf_attribution.breakdown.by_regime_volatility', []) as $key => $row) {
                [$regime, $volatility] = array_pad(explode('|', (string) $key, 2), 2, null);
                $trades = (int) data_get($row, 'trades', 0);
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)
                    || $trades < 5) continue;
                $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
                $nicheKey = "{$regime}|{$volatility}";
                if (! isset($observed[$nicheKey]) || $pf < $observed[$nicheKey]['profit_factor']) {
                    $observed[$nicheKey] = [
                        'regime' => $regime, 'volatility' => $volatility,
                        'profit_factor' => $pf, 'trades' => $trades,
                        'source_performance_id' => $candidate->id,
                    ];
                }
            }
        }

        // Derive the next council from member x context evidence.  The old
        // hard-coded pair (trend_up|normal and range|low) could miss the
        // actual failing lanes of the sealed portfolio.  Calendar labels are
        // used only to prove that a context failed in a frozen window; they
        // never enter the niche contract or parameter mutation.
        $portfolioBreakdown = (array) data_get(
            $portfolio->evidence,
            'result.portfolio_evidence.member_breakdown',
            []
        );
        $niches = [];
        foreach ($portfolioBreakdown as $memberKey => $member) {
            $sourcePerformanceId = str_starts_with((string) $memberKey, 'performance:')
                ? (int) substr((string) $memberKey, strlen('performance:'))
                : null;
            foreach ((array) data_get($member, 'context_breakdown', []) as $contextKey => $context) {
                [$regime, $volatility] = array_pad(explode('|', (string) $contextKey, 2), 2, null);
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)) {
                    continue;
                }
                $trades = (int) data_get($context, 'trades', 0);
                $profitFactor = (float) data_get($context, 'profit_factor', 0);
                $failedFrozenWindow = collect((array) data_get($context, 'monthly', []))
                    ->contains(fn (array $month): bool => (int) data_get($month, 'trades', 0) >= 2
                        && (float) data_get($month, 'profit_factor', 0) < 1.0);
                $directionalNicheAdded = false;
                // The canonical portfolio ledger stores direction evidence at
                // member level, keyed by regime|volatility. Older/alternate
                // ledgers may nest it under the context row. Read both shapes
                // so a sealed BUY/SELL rescue is never silently skipped.
                $memberDirectionBreakdown = (array) data_get($member, 'direction_breakdown', []);
                $directionBreakdown = (array) data_get($context, 'direction_breakdown', []);
                if ($directionBreakdown === [] && array_key_exists((string) $contextKey, $memberDirectionBreakdown)) {
                    $directionBreakdown = (array) $memberDirectionBreakdown[(string) $contextKey];
                }
                $healthyDirectionComplements = [];
                foreach ($directionBreakdown as $direction => $directionEvidence) {
                    $direction = strtoupper((string) $direction);
                    if (! in_array($direction, ['BUY', 'SELL'], true)) continue;
                    $directionTrades = (int) data_get($directionEvidence, 'trades', 0);
                    $directionPf = (float) data_get($directionEvidence, 'profit_factor', 0);
                    $directionWindowFailure = collect((array) data_get($directionEvidence, 'monthly', []))
                        ->contains(fn (array $month): bool => (int) data_get($month, 'trades', 0) >= 2
                            && (float) data_get($month, 'profit_factor', 0) < 1.0);
                    // A side-specific rescue needs enough independent side
                    // observations. It is a research instruction only; the
                    // combined portfolio still faces every unchanged gate.
                    if (! $directionWindowFailure && $directionTrades >= 8 && $directionPf >= 1.3) {
                        // Preserve a healthy opposite side as a separate
                        // research hypothesis when the context itself has a
                        // frozen-window failure.  This is not a claim that
                        // the side will pass; it gives the council a chance
                        // to replace a losing directional lane without
                        // learning a calendar label.
                        $healthyDirectionComplements[] = [
                            'regime' => $regime,
                            'volatility' => $volatility,
                            'direction' => $direction,
                            'profit_factor' => $directionPf,
                            'trades' => $directionTrades,
                            'source_performance_id' => $sourcePerformanceId,
                            'reason' => 'healthy_directional_complement',
                        ];
                        continue;
                    }
                    if ($directionTrades < 8) continue;
                    $niches[] = [
                        'regime' => $regime,
                        'volatility' => $volatility,
                        'direction' => $direction,
                        'profit_factor' => $directionPf,
                        'trades' => $directionTrades,
                        'source_performance_id' => $sourcePerformanceId,
                        'reason' => $directionWindowFailure
                            ? 'sealed_direction_frozen_window_failure'
                            : 'sealed_direction_below_research_floor',
                    ];
                    $directionalNicheAdded = true;
                }
                if ($directionalNicheAdded) {
                    foreach ($healthyDirectionComplements as $complement) {
                        $alreadyAdded = collect($niches)->contains(
                            fn (array $existing): bool => data_get($existing, 'regime') === $complement['regime']
                                && data_get($existing, 'volatility') === $complement['volatility']
                                && data_get($existing, 'direction') === $complement['direction']
                                && data_get($existing, 'source_performance_id') === $complement['source_performance_id'],
                        );
                        if (! $alreadyAdded) $niches[] = $complement;
                    }
                }
                if ($directionalNicheAdded) continue;
                // Eight total observations plus either a weak aggregate or a
                // repeated frozen-window failure is enough to fund research.
                // It is not a promotion threshold and never bypasses replay.
                if ($trades < 8 || (! $failedFrozenWindow && $profitFactor >= 1.3)) continue;
                $niches[] = [
                    'regime' => $regime,
                    'volatility' => $volatility,
                    'profit_factor' => $profitFactor,
                    'trades' => $trades,
                    'source_performance_id' => $sourcePerformanceId,
                    'reason' => $failedFrozenWindow
                        ? 'sealed_context_frozen_window_failure'
                        : 'sealed_context_below_research_floor',
                ];
            }
        }

        // Legacy evidence may not contain the richer context breakdown. Keep
        // a conservative fallback so a portfolio can still create research
        // work after an old replay, but remove it automatically once a fresh
        // replay writes context_breakdown.
        if ($niches === []) {
            foreach ([
                ['regime' => 'trend_up', 'volatility' => 'normal_volatility'],
                ['regime' => 'range', 'volatility' => 'low_volatility'],
            ] as $requiredNiche) {
                $key = $requiredNiche['regime'].'|'.$requiredNiche['volatility'];
                $row = $observed[$key] ?? null;
                if ($row === null || (float) $row['profit_factor'] < 1.3 || (int) $row['trades'] < 8) {
                    $niches[] = [
                        ...$requiredNiche,
                        'profit_factor' => (float) ($row['profit_factor'] ?? 0),
                        'trades' => (int) ($row['trades'] ?? 0),
                        'source_performance_id' => $row['source_performance_id'] ?? null,
                        'reason' => 'legacy_context_evidence_missing',
                    ];
                }
            }
        }

        // The sealed portfolio ledger is intentionally conservative, but it
        // can lag behind a newer full replay.  Recover asymmetric directional
        // evidence from the current valid performance frontier as a research
        // hypothesis.  Example: a context can have a healthy SELL lane while
        // BUY is weak; that is a real complementary specialist opportunity,
        // whereas copying the aggregate context PF would hide the asymmetry.
        // This never promotes the source and never relaxes a combined gate.
        foreach ($this->evidenceDirectionalComplements($lab, $niches) as $complement) {
            $alreadyAdded = collect($niches)->contains(
                fn (array $existing): bool => data_get($existing, 'regime') === $complement['regime']
                    && data_get($existing, 'volatility') === $complement['volatility']
                    && strtoupper((string) data_get($existing, 'direction', '')) === strtoupper((string) $complement['direction'])
                    && (int) data_get($existing, 'source_performance_id', 0) === (int) $complement['source_performance_id'],
            );
            if (! $alreadyAdded) $niches[] = $complement;
        }

        $niches = collect($niches)
            ->unique(fn (array $niche): string => (string) $niche['regime'].'|'.(string) $niche['volatility'].'|'.(string) ($niche['direction'] ?? 'any').'|'.(string) ($niche['source_performance_id'] ?? ''))
            // Give an evidence-derived asymmetric lane a seat before legacy
            // weak niches.  The later member selector still requires its own
            // niche sample, stress edge and unchanged full/portfolio gates.
            ->sortBy(fn (array $niche): array => [
                in_array(data_get($niche, 'reason'), [
                    'evidence_directional_complement',
                    'evidence_directional_temporal_rescue',
                ], true) ? 0 : 1,
                (int) data_get($niche, 'trades', 0) >= 10 ? 0 : 1,
                (float) data_get($niche, 'profit_factor', 0),
            ])
            ->take(4)->values()->all();

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source_portfolio_id' => $portfolio->id,
            'niches' => $niches,
            'rule' => 'repair only weak regime x volatility x direction envelopes; preserve all unaffected lanes and require combined replay gates',
        ];
    }

    /**
     * Activate the council before a combined portfolio exists.
     *
     * Individual forward failures are already immutable, context-level
     * evidence.  Use them to create one bounded owner for each regime so the
     * next generation actually tests trend-up, trend-down and range lanes.
     * The resulting children remain screening/research candidates and are
     * still subject to full replay, standalone gates and the combined gate.
     */
    private function forwardFailureCouncilCurriculum(AiLaboratory $lab): array
    {
        $performances = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            // Overfit members are still valid failure evidence for research
            // routing. They can expose which regime owner failed, but they
            // can never become a council member or promotion evidence.
            ->whereIn('status', ['challenger', 'stagnated', 'rejected', 'overfit'])
            ->latest('updated_at')->take(60)->get();
        if ($performances->isEmpty()) return [];

        $decisions = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->where('decision', 'failed')
            ->whereIn('model_market_performance_id', $performances->pluck('id')->all())
            ->latest('evaluated_at')->get()->groupBy('model_market_performance_id');
        $failed = $performances->filter(fn (ModelMarketPerformance $performance): bool =>
            $decisions->has($performance->id));
        if ($failed->isEmpty()) return [];

        $defaults = [
            'trend_up' => 'normal_volatility',
            'trend_down' => 'normal_volatility',
            'range' => 'low_volatility',
        ];
        $niches = [];

        foreach ($defaults as $regime => $defaultVolatility) {
            $best = null;
            foreach ($failed as $performance) {
                $contexts = (array) data_get($performance->metrics, 'pf_attribution.breakdown.by_regime_volatility', []);
                foreach ($contexts as $key => $row) {
                    [$contextRegime, $volatility] = array_pad(explode('|', (string) $key, 2), 2, null);
                    if ($contextRegime !== $regime || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)) continue;
                    $trades = (int) data_get($row, 'trades', 0);
                    if ($trades < 3) continue;
                    $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
                    $candidate = [
                        'regime' => $regime, 'volatility' => $volatility,
                        'profit_factor' => $pf, 'trades' => $trades,
                        'source_performance_id' => $performance->id,
                        'failed_gate_reasons' => array_values(array_unique((array) data_get($decisions->get($performance->id)->first(), 'reason_codes', []))),
                    ];
                    $isPreferredFamily = in_array($performance->strategy_family, ['hybrid', 'differential_router'], true);
                    $bestPreferred = $best && in_array($best['_family'] ?? null, ['hybrid', 'differential_router'], true);
                    if ($best === null
                        || ($isPreferredFamily && ! $bestPreferred)
                        || (($isPreferredFamily === $bestPreferred) && $trades > (int) ($best['trades'] ?? 0))) {
                        $candidate['_family'] = $performance->strategy_family;
                        $best = $candidate;
                    }
                }
            }

            $niches[] = [
                'protocol' => 'portfolio_council_v1',
                'role' => $regime.'_specialist',
                'regime' => $regime,
                'volatility' => (string) ($best['volatility'] ?? $defaultVolatility),
                'direction' => null,
                'objective' => 'regime_coverage',
                'source_performance_id' => $best['source_performance_id'] ?? null,
                'observed_trades' => (int) ($best['trades'] ?? 0),
                'observed_profit_factor' => (float) ($best['profit_factor'] ?? 0),
                'failed_gate_reasons' => $best['failed_gate_reasons'] ?? [],
                'research_reason' => 'forward_failure_without_combined_portfolio',
                'non_target_parent_freeze' => true,
                'promotion_rule' => 'combined_portfolio_only',
            ];
        }

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source_portfolio_id' => null,
            'activation' => 'forward_failure_deadlock_breaker_v1',
            'niches' => $niches,
            'rule' => 'Individual forward failures seed explicit regime owners; no member or combined portfolio receives promotion evidence from this curriculum.',
        ];
    }

    /**
     * Find a bounded BUY/SELL complement from already-valid full evidence.
     * The source must have current statistical metadata, positive stress-cost
     * edge, at least ten observations on the declared side, and a weak or
     * absent opposite side.  A temporal failure is allowed only as an
     * explicitly labelled rescue hypothesis; it can never become a member
     * without passing the unchanged temporal gate. Calendar labels are
     * deliberately ignored.
     */
    private function evidenceDirectionalComplements(AiLaboratory $lab, array $existingNiches = []): array
    {
        $coveredContexts = collect($existingNiches)
            ->map(fn (array $niche): string => (string) data_get($niche, 'regime').'|'.(string) data_get($niche, 'volatility'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $performances = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->latest('updated_at')
            ->take(120)
            ->get();

        $complements = [];
        foreach ($performances as $performance) {
            if ($performance->status === 'overfit') continue;
            if ((int) data_get($performance->modelVersion?->metadata, 'statistical_gate_version', 0) < 3) continue;

            $metrics = (array) $performance->metrics;
            $stressPf = (float) data_get(
                $metrics,
                'pf_attribution.stress_cost.profit_factor',
                data_get($metrics, 'screening_survival.stress_cost_pf', 0),
            );
            if ($stressPf < 1.05) continue;

            $temporalFailure = collect((array) data_get($metrics, 'pf_attribution.breakdown.by_temporal_chunk', []))
                ->contains(fn (array $chunk): bool => (int) data_get($chunk, 'trades', 0) >= 5
                    && (float) data_get($chunk, 'net_pf', data_get($chunk, 'profit_factor', 0)) < 1.0);

            foreach ((array) data_get($metrics, 'pf_attribution.breakdown.by_regime_volatility_direction', []) as $contextKey => $directions) {
                [$regime, $volatility] = array_pad(explode('|', (string) $contextKey, 2), 2, null);
                if (! in_array($regime, ['trend_up', 'trend_down', 'range'], true)
                    || ! in_array($volatility, ['low_volatility', 'normal_volatility', 'high_volatility'], true)) continue;
                // The sealed council already owns this context.  Only add a
                // new evidence-derived lane when it expands envelope
                // coverage; otherwise the next generation would spend its
                // whole budget cloning the same regime.
                if (in_array("{$regime}|{$volatility}", $coveredContexts, true)) continue;

                foreach ((array) $directions as $direction => $row) {
                    $direction = strtoupper((string) $direction);
                    if (! in_array($direction, ['BUY', 'SELL'], true) || ! is_array($row)) continue;
                    $trades = (int) data_get($row, 'trades', 0);
                    $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
                    if ($trades < 10 || $pf < 1.3) continue;

                    $opposite = $direction === 'BUY' ? 'SELL' : 'BUY';
                    $oppositePf = (float) data_get(
                        $directions,
                        "{$opposite}.net_pf",
                        data_get($directions, "{$opposite}.profit_factor", 0),
                    );
                    if ($oppositePf >= 1.1) continue;

                    $complements[] = [
                        'regime' => $regime,
                        'volatility' => $volatility,
                        'direction' => $direction,
                        'profit_factor' => $pf,
                        'trades' => $trades,
                        'source_performance_id' => $performance->id,
                        // A locally strong side with a weak temporal chunk is
                        // a repair experiment, not a healthy member.  Keep it
                        // available so evolution can attack the exact
                        // temporal weakness, but let the normal member
                        // selector reject the child unless every unchanged
                        // survival gate is later satisfied.
                        'reason' => $temporalFailure
                            ? 'evidence_directional_temporal_rescue'
                            : 'evidence_directional_complement',
                        'opposite_profit_factor' => $oppositePf,
                    ];
                }
            }
        }

        return collect($complements)
            ->sortByDesc(fn (array $row): array => [
                (float) data_get($row, 'profit_factor', 0),
                (int) data_get($row, 'trades', 0),
            ])
            ->unique(fn (array $row): string => (string) $row['regime'].'|'.(string) $row['volatility'].'|'.(string) $row['direction'])
            ->take(2)
            ->values()
            ->all();
    }

    private function familyPaused(AiLaboratory $lab, string $family): bool
    {
        $records = MutationMemory::with('labAgent.generation')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('strategy_family', $family)->whereNotNull('gate_transition')->get();
        return $this->noGateProgressAcrossThreeGenerations($records);
    }

    private function pausedArchitectures(string $symbol, string $timeframe, string $family): array
    {
        return MutationMemory::with('labAgent.generation')->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)->where('parameter_key', '__architecture')->whereNotNull('gate_transition')->get()
            ->groupBy(fn (MutationMemory $memory) => (string) data_get($memory->new_value, 'value'))
            ->filter(fn ($records) => $this->noGateProgressAcrossThreeGenerations($records))
            ->keys()->filter()->values()->all();
    }

    private function noGateProgressAcrossThreeGenerations($records): bool
    {
        $generations = collect($records)->filter(fn (MutationMemory $memory) => $memory->labAgent?->generation?->status === 'completed')
            ->groupBy(fn (MutationMemory $memory) => $memory->labAgent?->lab_generation_id)
            ->sortByDesc(fn ($items) => (int) $items->first()?->labAgent?->generation?->generation)
            ->take(3);
        if ($generations->count() < 3) return false;
        return $generations->every(fn ($items) => $items->every(fn (MutationMemory $memory) => empty(data_get($memory->gate_transition, 'improved', []))));
    }

    private function dominantTarget(?string $failure, array $deficits): string
    {
        return match ($failure) {
            'signal_starvation', 'over_filtering', 'insufficient_trades', 'no_trade' => 'trade_frequency',
            'negative_edge', 'weak_profit_factor', 'stop_too_tight' => 'profit_factor',
            'excessive_drawdown', 'ruin_risk' => 'drawdown_risk',
            'overfit' => 'architecture',
            default => ((int) ($deficits['rolling_deficit'] ?? 0) > 0 ? 'rolling_regime' : 'profit_factor'),
        };
    }

    private function rescueTarget(array $reasons): string
    {
        if (in_array('FAILED_TRADE_COUNT', $reasons, true)) return 'trade_frequency';
        if (in_array('FAILED_DRAWDOWN', $reasons, true) || in_array('FAILED_RUIN_RISK', $reasons, true)) return 'drawdown_risk';
        if (in_array('FAILED_PROFIT_FACTOR', $reasons, true) || in_array('FAILED_STRESS_COST', $reasons, true)) return 'profit_factor';
        return 'rolling_regime';
    }

    /** A shadow result may relax exactly one veto; it never weakens promotion gates. */
    private function shadowVetoTarget(string $symbol, string $timeframe, string $family): ?string
    {
        if ($target = $this->vetoPolicies->recommendedTarget($symbol, $timeframe, $family)) return $target;
        $decisions = CandidateGateDecision::query()->where('stage', 'screening')
            ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))
            ->latest('evaluated_at')->take(12)->get();
        foreach ($decisions as $decision) {
            foreach ((array) data_get($decision->metrics, 'veto_regret.by_veto_reason', []) as $reason => $metrics) {
                // Legacy aggregates remain diagnostic only. New policy-lab
                // records require 30 context samples, three months and a
                // positive lower bound before a bounded experiment.
                if ((int) data_get($metrics, 'shadow_trades', 0) < 30 || data_get($metrics, 'recommended_action') !== 'relax_bounded_veto') continue;
                return match ($reason) {
                    'loss_cooldown' => 'shadow_veto_loss_cooldown',
                    'minimum_confidence' => 'shadow_veto_confidence',
                    'high_volatility_veto' => 'shadow_veto_volatility',
                    default => null,
                };
            }
        }
        return null;
    }

    private function screeningTargetsForReasons(array $reasons): array
    {
        $mapping = [
            'FAILED_TRAIN_FORWARD_GAP' => 'temporal_stability',
            'FAILED_PARAMETER_STABILITY' => 'temporal_stability',
            'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
            'FAILED_TEMPORAL_CHUNK_SURVIVAL' => 'temporal_stability',
            'FAILED_CALENDAR_MONTH_SURVIVAL' => 'monthly_survival',
            // Compatibility for historical G34/G35 ledger rows.
            'FAILED_MONTHLY_SURVIVAL' => 'monthly_survival',
            'FAILED_REGIME_COVERAGE' => 'regime_coverage',
            'FAILED_TRANSITION' => 'regime_coverage',
            'FAILED_STRESS_COST' => 'stress_cost',
            'FAILED_TRADE_COUNT' => 'trade_frequency',
            'FAILED_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_DRAWDOWN' => 'drawdown_risk',
            'FAILED_RUIN' => 'drawdown_risk',
            'FAILED_OVERFIT' => 'architecture',
            'FAILED_STATISTICAL' => 'architecture',
            // Calendar alignment is a data-contract defect, not a strategy
            // parameter defect. It must be fixed in the event pipeline.
            'FAILED_CALENDAR_ALIGNMENT' => null,
        ];
        $weighted = [];
        foreach ($reasons as $key => $value) {
            $reason = is_string($key) ? $key : (string) $value;
            $count = is_string($key) && is_numeric($value) ? (int) $value : 1;
            if (! array_key_exists($reason, $mapping) || $mapping[$reason] === null) continue;
            $target = $mapping[$reason];
            $weighted[$target] = ($weighted[$target] ?? 0) + max(1, $count);
        }
        arsort($weighted);
        return array_keys($weighted);
    }

    private function screeningTargetForReasons(array $reasons): ?string
    {
        return $this->screeningTargetsForReasons($reasons)[0] ?? null;
    }

    private function targetFromBundle(string $bundle): string
    {
        return match ($bundle) {
            'trade_frequency_bundle' => 'trade_frequency', 'profit_factor_bundle' => 'profit_factor',
            'drawdown_bundle' => 'drawdown_risk', 'architecture_bundle' => 'architecture',
            default => 'rolling_regime',
        };
    }

    private function createAgent(LabGeneration $generation, string $family, string $origin, int $slot, string $target, ?array $niche = null, ?array $history = null): void
    {
        $lab = $generation->laboratory;
        $history ??= ($this->historicalLearning->latestForFamily($lab->symbol, $lab->timeframe, $family)?->toArray());
        $historyKeys = array_values((array) data_get($history, 'recommended_keys', data_get($history, 'recommended_mutations.keys', [])));
        $historyInsightId = data_get($history, 'insight_id');
        $g98Target = in_array($target, ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router', 'opportunity_recall'], true);
        // Forward score alone is insufficient evidence for a reusable parent.
        // Weak-PF or high-ruin candidates remain explorers, not gene sources.
        $parentTier = 'validated_frontier';
        $parents = $this->qualityParents($lab->symbol, $lab->timeframe, $family);
        if ($parents->isEmpty()) {
            // A valid near-miss may seed a bounded screening experiment. It
            // is never a promotion parent: it lacks the full passport by
            // definition and is explicitly labelled in child metadata.
            $parents = $this->screeningSeedParents($lab->symbol, $lab->timeframe, $family);
            $parentTier = $parents->isEmpty() ? 'no_parent' : 'screening_seed';
            if ($parents->contains(fn (ModelVersion $parent) => $parent->evidence_status === 'legacy_invalid')) {
                $parentTier = 'legacy_hypothesis';
            }
        }
        if ($family === 'differential_router' && $parents->isEmpty()) {
            $parents = $this->qualityParents($lab->symbol, $lab->timeframe, 'hybrid');
            if ($parents->isEmpty()) {
                $parents = $this->screeningSeedParents($lab->symbol, $lab->timeframe, 'hybrid');
                $parentTier = $parents->isEmpty() ? 'no_parent' : 'screening_seed';
                if ($parents->contains(fn (ModelVersion $parent) => $parent->evidence_status === 'legacy_invalid')) {
                    $parentTier = 'legacy_hypothesis';
                }
            } else {
                $parentTier = 'validated_frontier';
            }
        }
        // A portfolio council lane carries the exact sealed member that
        // failed in that context.  Use it as the bounded research parent;
        // otherwise the generic frontier could mutate a stronger but
        // unrelated clone and never repair the observed niche.
        $sourcePerformance = data_get($niche, 'source_performance_id')
            ? ModelMarketPerformance::with('modelVersion')->find((int) data_get($niche, 'source_performance_id'))
            : null;
        $sourceParent = $sourcePerformance?->modelVersion;
        if ($sourceParent && in_array($family, ['hybrid', 'differential_router'], true)
            && in_array((string) $sourcePerformance->strategy_family, ['hybrid', 'differential_router'], true)) {
            $parents = collect([$sourceParent]);
            $parentTier = 'portfolio_failure_context_parent';
        }
        // A coverage-rescue child has no freedom to substitute a stronger but
        // unrelated frontier parent.  Its sole research parent is sealed by
        // the audit, making the non-target replay comparison meaningful.
        $frozenParent = ModelVersion::find((int) data_get($niche, 'frozen_parent_model_version_id', 0));
        if ($frozenParent && data_get($niche, 'protocol') === CoverageRescueAuditService::PROTOCOL) {
            $parents = collect([$frozenParent]);
            $parentTier = 'coverage_rescue_frozen_parent';
        }
        // A dynamic parent frontier must actually be used. Rotate through all
        // proven parents instead of permanently cloning the first two ranked
        // records into every new generation.
        $parentCount = $parents->count();
        $parentA = $parentCount ? $parents->get(($slot - 1) % $parentCount) : null;
        $parentB = $parentCount > 1 ? $parents->get($slot % $parentCount) : null;
        // Merge defaults with the parent instead of replacing the defaults
        // with a legacy parameter map. New specialist genes must be present
        // before the next bounded mutation is selected.
        $base = [...$this->schemas->defaults($family), ...($parentA?->parameters ?? [])];
        // A sealed coverage parent may come from a specialist family (for
        // example breakout) while the child is the differential router that
        // enforces non-target identity. Never smuggle that family's entry or
        // exit genes into the router schema; the parent remains immutable and
        // only the declared envelope adapter may be researched.
        $base = array_intersect_key($base, $this->schemas->schema($family));
        $mutationScope = ($g98Target || in_array($origin, ['gate_targeted', 'risk_exit', 'causal_isolation', 'architecture', 'g98_council'], true))
            ? $this->mutationScope($lab->symbol, $lab->timeframe, $family, $slot)
            : null;
        $councilRegime = data_get($niche, 'regime');
        // Slots are interleaved by family (1, 5, 9...).  Use a family-local
        // experiment index so every architecture receives representation;
        // using the raw slot would give a family the same modulo forever.
        $architectureSeed = intdiv($slot - 1, max(1, count($lab->strategy_families))) + 1;
        $architecture = $g98Target && $parentA
            ? (string) data_get($parentA->metadata, 'strategy_architecture', $this->architectureBaseStrategy($family))
            : $this->selectArchitecture($lab->symbol, $lab->timeframe, $family, $origin, $architectureSeed, $mutationScope, $parentA);

        $skillCrossoverSources = [];
        if ($niche && data_get($niche, 'regime') === 'range' && $family === 'hybrid') {
            $parameters = $this->rangeCouncilSingleGene($base, $slot, $niche ? (string) data_get($niche, 'objective', '') : null);
        } elseif ($family === 'differential_router') {
            // General generations may use the differential architecture, but
            // exactly one target-lane gene is allowed to move.  Parent
            // parameters remain frozen outside that declared lane.
            $parameters = $this->differentialSingleGene($base, $slot, $councilRegime ?: $mutationScope, $niche ? (string) data_get($niche, 'objective', $target) : $target);
        } elseif ($origin === 'robust_crossover' && ! $g98Target) {
            [$parameters, $skillCrossoverSources] = $this->skillCrossover($family, $parents, $base, $slot);
        } else {
            $parameters = $g98Target
                ? $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $target, true, $historyKeys)
                : match ($origin) {
                'gate_targeted', 'risk_exit', 'architecture' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $target, false, $historyKeys),
                'causal_isolation', 'g98_council' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $target, true, $historyKeys),
                default => $this->randomParameters($family, $slot),
            };
        }
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        // A frozen parent/default may already hold the proposed value (for
        // example router v2 on a fresh lab). A G98 seat must still be a real
        // one-gene experiment, never a zero-diff clone labelled as causal.
        if ($g98Target && $this->diff($base, $parameters) === []) {
            $parameters = $this->forceSingleGeneNudge($family, $parameters, $slot, $target);
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }
        // A generation is an experiment set, not a collection of parameter
        // clones.  Reject exact fingerprints and near neighbours before they
        // consume an expensive full validation slot.
        $isolatedKey = ($g98Target || in_array($origin, ['causal_isolation', 'g98_council'], true)) ? array_key_first($this->diff($base, $parameters)) : null;
        $parameters = $this->ensureNovelParameters($generation, $family, $parameters, $slot, $g98Target || in_array($origin, ['gate_targeted', 'causal_isolation', 'g98_council'], true), $isolatedKey);
        // The generation-local check above cannot see the same failed
        // topology from G83/G84/G85/G86.  Avoiding only current-generation
        // duplicates allowed the council to rediscover an already falsified
        // member every cycle, after which portfolio admission correctly
        // rejected it as a known-failed fingerprint.  Historical novelty is
        // applied only to the declared lane gene, preserving differential and
        // non-target invariants.
        $parameters = $this->ensureHistoricalNovelParameters(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $parameters,
            $slot,
            $target,
            $niche,
        );
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        $parentMetrics = $parentA?->marketPerformances()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->latest()->first()?->metrics ?? [];
        $constitution = $this->constitutions->draft($lab->symbol, $lab->timeframe, $family, $architecture, $parameters);
        $universalGenome = $this->universalCapabilities->genome($lab->symbol, $lab->timeframe, $family, $architecture, $parameters, $parentA);
        $reservoirRecall = $this->regimeReservoir->recall($lab->symbol, $lab->timeframe, $mutationScope);
        // Model versions are globally unique. H1 keeps its established name;
        // additional execution timeframes receive an explicit namespace so an
        // EURUSD M15 G1 specialist cannot collide with EURUSD H1 G1.
        $timeframePrefix = strtoupper($lab->timeframe) === 'H1' ? '' : '_'.strtolower($lab->timeframe);
        $strategy = strtolower($lab->symbol).$timeframePrefix.'_'.$family.'_g'.$generation->generation.'_a'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT);
        $model = ModelVersion::create([
            'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$generation->generation,
            'generation' => $generation->generation, 'status' => 'testing', 'parameters' => $parameters,
            'description' => "{$lab->name} generation {$generation->generation} {$origin} agent",
            'metadata' => array_filter([
                'base_strategy' => $this->schemas->runtimeBaseStrategy($strategy, $this->architectureBaseStrategy($architecture), $family), 'strategy_architecture' => $architecture,
                'lab_symbol' => $lab->symbol, 'origin' => $origin,
                'generation_target' => $target,
                'historical_learning' => $history ? [
                    'protocol' => LabHistoricalLearningService::PROTOCOL,
                    'insight_id' => $historyInsightId,
                    'evidence_quality' => data_get($history, 'evidence_quality'),
                    'causal_prior_allowed' => (bool) data_get($history, 'causal_prior_allowed', false),
                    'confidence' => (float) data_get($history, 'confidence', 0),
                    'primary_target' => data_get($history, 'primary_target', data_get($history, 'recommended_mutations.primary_target')),
                    'recommended_keys' => $historyKeys,
                    'blocked_mutations' => (array) data_get($history, 'blocked_mutations', []),
                    'failure_signature' => (array) data_get($history, 'failure_signature', []),
                    'direction_rule' => 'Only independently confirmed exact replay credits can influence mutation direction.',
                ] : null,
                'g98_council_lane' => $g98Target ? [
                    'protocol' => self::GENERATION_PROTOCOL,
                    'lane' => $target,
                    'mutation_layers' => 1,
                    'parent_lane_freeze' => true,
                    'acceptance_rule' => 'causal_blame_proven_and_unchanged_gates_pass',
                ] : null,
                'coverage_rescue_contract' => data_get($niche, 'protocol') === CoverageRescueAuditService::PROTOCOL ? [
                    'protocol' => CoverageRescueAuditService::PROTOCOL,
                    'parent_model_version_id' => $parentA?->id,
                    'target_envelope' => [
                        'regime' => data_get($niche, 'regime'), 'volatility' => data_get($niche, 'volatility'),
                        'session_utc_hour' => data_get($niche, 'session_utc_hour'), 'direction' => data_get($niche, 'direction'),
                    ],
                    'entry_logic_frozen' => true, 'exit_logic_frozen' => true,
                    'non_target_replay_required' => ['signal_identity', 'confidence_identity', 'trade_ledger_identity'],
                    'breach_action' => 'technical_quarantine',
                ] : null,
                'portfolio_council_lane' => $niche,
                'portfolio_council_source_performance_id' => data_get($niche, 'source_performance_id'),
                // Council members enter a research-only lane first. Keep the
                // routing declaration and the admission contract under one
                // canonical metadata name so a strong niche child can be
                // selected for combined replay after full validation.
                'portfolio_research_contract' => data_get($niche, 'protocol') === 'portfolio_council_v1' ? [
                    'protocol' => 'portfolio_member_research_v1',
                    'status' => 'screening_seed',
                    'target_regime' => data_get($niche, 'regime'),
                    'target_volatility' => data_get($niche, 'volatility'),
                    'target_direction' => data_get($niche, 'direction'),
                    'screening_agent_id' => null,
                    'promotion_rule' => 'member_never_promotes; only_combined_portfolio_can_pass',
                ] : null,
                'parent_provenance' => $parentTier,
                'screening_seed_only' => $parentTier === 'screening_seed',
                'causal_experiment_lane' => ($g98Target || $origin === 'causal_isolation' || $origin === 'g98_council' || $family === 'differential_router') ? [
                    'status' => 'isolated_single_gene',
                    'rule' => 'One changed parameter only; requires parent and same-generation alternative before causal credit.',
                ] : null,
                'hypothesis_contract' => ($g98Target || $origin === 'coverage_rescue' || $family === 'differential_router') ? [
                    'protocol' => 'hypothesis_laboratory_v1',
                    'hypothesis' => 'Improve only the declared operating-envelope failure without changing any protected lane.',
                    'target_lane' => $target,
                    'target_context' => $niche ? [
                        'regime' => data_get($niche, 'regime'), 'volatility' => data_get($niche, 'volatility'),
                        'session_utc_hour' => data_get($niche, 'session_utc_hour'), 'direction' => data_get($niche, 'direction'),
                    ] : null,
                    'changed_gene' => $isolatedKey,
                    'unchanged_lane_invariant' => $family === 'differential_router'
                        ? 'Non-target signal, confidence and trade-ledger identities must equal the frozen parent.'
                        : 'Only the declared single gene may differ from the frozen parent/default.',
                    'independent_reconfirmations_required' => 2,
                    'retire_family_after_failed_independent_replays' => 2,
                    'parent_rule' => 'Only independently confirmed beneficial credits may become reusable mutation priors.',
                ] : null,
                'mutation_bundle' => $this->evolutionQuality->curriculum($parentMetrics)['bounded_bundle'] ?? null,
                'mutation_scope' => $mutationScope,
                'skill_crossover_sources' => $skillCrossoverSources ?: null,
                // A frozen near-forward parent is never edited in place.
                // This child is the only allowed research fork from it.
                'elite_candidate_fork' => data_get($parentA?->metadata, 'elite_agent_passport.freeze.status') === 'frozen'
                    ? ['parent_model_version_id' => $parentA->id, 'parent_parameter_hash' => data_get($parentA->metadata, 'elite_agent_passport.freeze.parameter_hash')]
                    : null,
                'parameter_fingerprint' => $this->parameterFingerprint($family, $parameters),
                // New generations must produce actual CSCV/PBO, DSR and
                // bootstrap evidence before paper promotion. Legacy records
                // remain auditable but cannot silently define this protocol.
                'statistical_gate_version' => 3,
                'agent_constitution' => $constitution,
                'universal_genome' => $universalGenome,
                'regime_reservoir_recall' => $reservoirRecall,
                'execution_contract' => $family === 'differential_router' ? LearningProtocolSafetyService::EXECUTION_CONTRACT : null,
                'differential_router_contract' => $family === 'differential_router' && $parentA ? [
                    'parent_model_version_id' => $parentA->id,
                    'parent_frozen_hash' => hash('sha256', json_encode(data_get($parentA->metadata, 'last_screen_result', []), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                    'target_regime' => $parameters['differential_target_regime'],
                    'target_parameter' => array_key_first($this->diff($base, $parameters)),
                    'execution_contract' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
                    'non_target_parent_freeze' => true,
                ] : null,
            ]),
        ]);
        $generation->agents()->create([
            'model_version_id' => $model->id, 'parent_a_model_version_id' => $parentA?->id,
            'parent_b_model_version_id' => $origin === 'crossover' ? $parentB?->id : null,
            'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
            'origin' => $origin, 'lifecycle_status' => 'draft',
            'parameter_diff' => $this->diff($base, $parameters),
        ]);
    }

    private function differentialSingleGene(array $base, int $slot, ?string $scope, ?string $objective = null): array
    {
        $target = in_array($scope, ['trend_up', 'range', 'trend_down'], true) ? $scope : ['trend_down', 'range', 'trend_up'][$slot % 3];
        $parameters = [...$this->schemas->defaults('differential_router'), ...$base,
            'differential_target_regime' => $target, 'differential_replay_mode' => 'paired_isolated'];
        if ($objective === 'opportunity_recall') {
            // Recall is a controlled entry-funnel experiment. Lower one
            // bounded filter at a time and let the unchanged opportunity and
            // abstention gates decide whether the extra entries were useful.
            // The 20% recall target is never bought by weakening the 50%
            // abstention-precision floor.
            foreach (['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'] as $key) {
                if (! array_key_exists($key, $parameters)) continue;
                if ($key === 'minimum_confidence') {
                    $current = (float) $parameters[$key];
                    if ($current > .1) { $parameters[$key] = round(max(.1, $current - .1), 4); return $parameters; }
                } elseif ($key === 'minimum_signal_confidence') {
                    $current = (float) $parameters[$key];
                    if ($current > 0) { $parameters[$key] = round(max(0.0, $current - .05), 4); return $parameters; }
                } else {
                    $current = (int) $parameters[$key];
                    if ($current > 1) { $parameters[$key] = max(1, $current - 1); return $parameters; }
                }
            }
            return $parameters;
        }
        if ($objective === 'transition_firewall') {
            // April's portfolio loss was shared by otherwise different
            // members, which is a classic transition-boundary hypothesis.
            // Test the firewall as one isolated control gene; do not hide it
            // inside a calendar/month mutation bundle.
            $parameters['transition_firewall_enabled'] = ! (bool) ($parameters['transition_firewall_enabled'] ?? false);
            return $parameters;
        }
        if ($objective === 'stress_cost') {
            // Stress is an execution-topology problem, not a reason to
            // rewrite the target regime's entry identity.  Test one bounded
            // exit gene per child and let the unchanged non-target and stress
            // gates decide whether the experiment earned its place.
            $key = ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'][$slot % 4];
            [$type, $min, $max] = array_pad($this->schemas->schema('differential_router')[$key] ?? ['numeric', 0.5, 4.0], 3, null);
            $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
            $step = match ($key) {
                'atr_stop_multiplier' => .15,
                'atr_target_multiplier' => .25,
                'trailing_atr_multiplier' => .2,
                default => 8,
            };
            $direction = ($slot % 2 === 0) ? 1 : -1;
            $value = max((float) $min, min((float) $max, $current + ($direction * $step)));
            $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
            return $parameters;
        }
        $contextV2 = in_array($objective, ['monthly_survival', 'temporal_stability', 'calendar_context_rescue'], true);
        if ($contextV2) $parameters['differential_router_version'] = 'v2';
        if ($target === 'range') {
            // Three range children test three different mechanisms. Each
            // child changes one gene only, so a later improvement can receive
            // causal credit instead of being attributed to a hidden bundle.
            $variant = $slot % 3;
            if ($variant === 1) {
                $parameters['range_signal_mode'] = 'inverse_extreme';
            } elseif ($variant === 2) {
                $parameters['range_low_volatility_only'] = ! (bool) ($parameters['range_low_volatility_only'] ?? false);
            } else {
                $parameters['range_deviation'] = (float) ($parameters['range_deviation'] ?? 2.0) + (($slot % 2) ? -.2 : .2);
            }
        } elseif ($target === 'trend_up') {
            if ($contextV2) {
                // v2 reuses the parent hybrid momentum branch.  Each council
                // objective changes one meaningful target-lane gene, keeping
                // the opportunity count comparable to the frozen parent.
                if ($objective === 'monthly_survival') {
                    $parameters['trend_up_roc_threshold'] = round(max(.01, (float) ($parameters['trend_up_roc_threshold'] ?? .2) - .05), 4);
                } elseif ($objective === 'temporal_stability') {
                    $parameters['trend_up_roc_period'] = max(4, min(60, (int) ($parameters['trend_up_roc_period'] ?? 12) + 2));
                } else {
                    $parameters['trend_up_ema_period'] = max(10, min(300, (int) ($parameters['trend_up_ema_period'] ?? 50) + 10));
                }
                return $parameters;
            }
            // The parent deficit is not lack of global PF; it is a thin,
            // negative trend-up|normal lane.  The old +/-2 pair produced
            // 18/22 from the default 20 and collapsed the child to 3/1
            // target trades.  Explore the direction that can restore
            // opportunity coverage, one gene at a time, while leaving every
            // other specialist and execution parameter frozen.
            $variant = $slot % 3;
            $key = 'trend_up_strength_min';
            $baseStrength = (float) ($parameters[$key] ?? 20.0);
            if ($objective === 'calendar_context_rescue') {
                // A context rescue must not repeat the two screen-only
                // strength variants. Pullback depth is the only changed
                // target-lane gene; all non-target specialist branches stay
                // frozen by the differential replay contract.
                $basePullback = (float) ($parameters['trend_up_pullback_atr_fraction'] ?? 0.75);
                $parameters['trend_up_pullback_atr_fraction'] = round(min(2.0, $basePullback + 0.15), 2);
            } elseif ($variant === 1) {
                $parameters[$key] = max(8.0, $baseStrength - 6.0);
            } elseif ($variant === 2) {
                $parameters[$key] = max(8.0, $baseStrength - 4.0);
            } else {
                $basePullback = (float) ($parameters['trend_up_pullback_atr_fraction'] ?? 0.75);
                $parameters['trend_up_pullback_atr_fraction'] = round(max(0.25, $basePullback - 0.20), 2);
            }
        } else {
            if ($contextV2) {
                if ($objective === 'monthly_survival') {
                    $parameters['trend_down_roc_threshold'] = round(max(.01, (float) ($parameters['trend_down_roc_threshold'] ?? .2) - .05), 4);
                } elseif ($objective === 'temporal_stability') {
                    $parameters['trend_down_roc_period'] = max(4, min(60, (int) ($parameters['trend_down_roc_period'] ?? 12) + 2));
                } else {
                    $parameters['trend_down_ema_period'] = max(10, min(300, (int) ($parameters['trend_down_ema_period'] ?? 50) + 10));
                }
                return $parameters;
            }
            $key = $target.'_strength_min';
            $delta = (($slot % 2) ? -2 : 2);
            $parameters[$key] = (float) ($parameters[$key] ?? 20.0) + $delta;
        }
        return $parameters;
    }

    /** Deterministic last-resort for a G98 lane whose proposed gene is already set. */
    private function forceSingleGeneNudge(string $family, array $parameters, int $slot, string $target): array
    {
        $preferred = match ($target) {
            'monthly_survival' => ['transition_firewall_enabled', 'session_filter_enabled', 'minimum_signal_confidence', 'lookback'],
            'regime_coverage' => ['trend_strength_min', 'minimum_signal_confidence', 'lookback'],
            'volatility_session_stability' => ['high_volatility_risk_multiplier', 'session_start', 'minimum_signal_confidence'],
            'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles'],
            'transition_firewall' => ['transition_firewall_enabled', 'transition_wait_candles'],
            'portfolio_router' => ['differential_target_regime', 'minimum_signal_confidence', 'lookback'],
            'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'],
            default => [],
        };
        $schema = $this->schemas->schema($family);
        $keys = [...array_values(array_intersect($preferred, array_keys($schema))), ...array_keys($schema)];
        foreach (array_unique($keys) as $key) {
            [$type, $min, $max] = array_pad($schema[$key] ?? [], 3, null);
            if ($type === 'boolean') {
                $parameters[$key] = ! (bool) ($parameters[$key] ?? false);
                return $parameters;
            }
            if ($type === 'string') continue;
            if (! is_numeric($min) || ! is_numeric($max)) continue;
            $current = (float) ($parameters[$key] ?? (((float) $min + (float) $max) / 2));
            $step = max($type === 'integer' ? 1 : .0001, (((float) $max - (float) $min) * .05));
            $next = $current + (($slot % 2 === 0) ? $step : -$step);
            if ($next < (float) $min || $next > (float) $max) $next = $current - (($slot % 2 === 0) ? $step : -$step);
            $parameters[$key] = $type === 'integer' ? (int) round($next) : round($next, 4);
            return $parameters;
        }
        throw new \RuntimeException("G98 {$family} {$target} has no mutable one-gene schema field.");
    }

    /** Hybrid range rescue: preserve trend/breakout and change one range gene. */
    private function rangeCouncilSingleGene(array $base, int $slot, ?string $objective = null): array
    {
        $parameters = [...$this->schemas->defaults('hybrid'), ...$base];
        if ($objective === 'transition_firewall') {
            // Same experiment for a range specialist: preserve its entry
            // topology and test only transition protection.
            return [...$parameters, 'transition_firewall_enabled' => ! (bool) ($parameters['transition_firewall_enabled'] ?? false)];
        }
        if ($objective === 'opportunity_recall') {
            $current = (float) ($parameters['minimum_confidence'] ?? 1.0);
            return [...$parameters, 'minimum_confidence' => round(max(.1, $current - .1), 4)];
        }
        if ($objective === 'stress_cost') {
            return $this->stressExitSingleGene('hybrid', $parameters, $slot);
        }
        if ($objective === 'monthly_survival') {
            // inverse_extreme was a screen winner in G80/G81 but collapsed
            // under sealed full replay. Use a different entry topology for
            // the next monthly experiment instead of reproducing that
            // known-overfit fingerprint.
            return [...$parameters, 'range_signal_mode' => 'mean_reversion'];
        }
        if ($objective === 'temporal_stability') {
            return [...$parameters, 'range_deviation' => round(max(0.5, (float) ($parameters['range_deviation'] ?? 2.0) - .2), 4)];
        }
        if ($objective === 'calendar_context_rescue') {
            // This tests re-entry hysteresis in the declared range envelope;
            // it does not alter trend or breakout behavior.
            return [...$parameters, 'range_reentry_required' => false];
        }
        return match ($slot % 3) {
            1 => [...$parameters, 'range_signal_mode' => 'inverse_extreme'],
            2 => [...$parameters, 'range_low_volatility_only' => ! (bool) ($parameters['range_low_volatility_only'] ?? false)],
            default => [...$parameters, 'range_deviation' => round((float) ($parameters['range_deviation'] ?? 2.0) + .2, 4)],
        };
    }

    /** Change one schema-bounded execution gene for a cost-resilience probe. */
    private function stressExitSingleGene(string $family, array $parameters, int $slot): array
    {
        $key = ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'][$slot % 4];
        [$type, $min, $max] = array_pad($this->schemas->schema($family)[$key] ?? ['numeric', 0.5, 4.0], 3, null);
        $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
        $step = match ($key) {
            'atr_stop_multiplier' => .15,
            'atr_target_multiplier' => .25,
            'trailing_atr_multiplier' => .2,
            default => 8,
        };
        $direction = ($slot % 2 === 0) ? 1 : -1;
        $value = max((float) $min, min((float) $max, $current + ($direction * $step)));
        $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        return $parameters;
    }

    private function ensureNovelParameters(LabGeneration $generation, string $family, array $parameters, int $seed, bool $preserveDirectedMutation = false, ?string $singleGeneKey = null): array
    {
        $existing = $generation->agents()->with('modelVersion')->get()
            ->filter(fn ($agent) => $agent->strategy_family === $family)
            ->map(fn ($agent) => $agent->modelVersion?->parameters ?? [])
            ->values();

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $duplicate = $existing->contains(fn (array $other) =>
                $this->parameterFingerprint($family, $other) === $this->parameterFingerprint($family, $parameters)
                || (! $preserveDirectedMutation && $this->parameterDistance($family, $other, $parameters) < 0.08)
            );
            if (! $duplicate) return $parameters;
            if ($singleGeneKey && isset($this->schemas->schema($family)[$singleGeneKey])) {
                [$type, $min, $max] = array_pad($this->schemas->schema($family)[$singleGeneKey], 3, null);
                if ($type === 'boolean') {
                    $parameters[$singleGeneKey] = ! (bool) ($parameters[$singleGeneKey] ?? false);
                } elseif ($type === 'string') {
                    $choices = (array) $min;
                    $current = (string) ($parameters[$singleGeneKey] ?? ($choices[0] ?? ''));
                    $position = array_search($current, $choices, true);
                    $parameters[$singleGeneKey] = $choices[(($position === false ? 0 : $position) + $attempt + 1) % max(1, count($choices))];
                } else {
                    $current = (float) ($parameters[$singleGeneKey] ?? (($min + $max) / 2));
                    $direction = (($seed + $attempt) % 2 === 0) ? 1 : -1;
                    $value = max($min, min($max, $current + $direction * (($max - $min) * .07)));
                    $parameters[$singleGeneKey] = $type === 'integer' ? (int) round($value) : round($value, 4);
                }
            } else {
                $parameters = $this->randomParameters($family, $seed + (($attempt + 1) * 37));
            }
        }
        // The final fallback remains schema-valid and deterministic.  Its
        // fingerprint records that the family search space was exhausted.
        return $parameters;
    }

    /**
     * Keep evolution from replaying an exact historical topology forever.
     *
     * This is a search-space hygiene rule, not a quality shortcut: a novel
     * child must still pass every unchanged screening/full/forward gate.  For
     * a declared council/differential lane we mutate one contextual gene only;
     * an unrelated specialist branch is never changed to manufacture novelty.
     */
    private function ensureHistoricalNovelParameters(
        string $symbol,
        string $timeframe,
        string $family,
        array $parameters,
        int $seed,
        string $target,
        ?array $niche,
    ): array {
        $cacheKey = strtoupper($symbol).'|'.strtoupper($timeframe).'|'.$family;
        if (! array_key_exists($cacheKey, $this->historicalParameterFingerprints)) {
            // Historical novelty is a safety boundary for evolution, not a
            // recent-memory heuristic.  The old 160-agent window allowed a
            // failed portfolio member from an older generation to re-enter
            // the next council with the same topology, which then consumed a
            // full replay only to rediscover the same failure.  Load the
            // complete market/family history (the result is cached for this
            // generation build) so a new child is genuinely new before it
            // reaches screening.  This never converts a failure to a pass;
            // it only prevents redundant experiments.
            $this->historicalParameterFingerprints[$cacheKey] = LabAgent::query()
                ->with('modelVersion')
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', strtoupper($timeframe))
                ->where('strategy_family', $family)
                ->get()
                ->flatMap(function (LabAgent $agent) use ($family): array {
                    $parameters = (array) ($agent->modelVersion?->parameters ?? []);
                    $computed = $parameters === [] ? null : $this->parameterFingerprint($family, $parameters);
                    // Legacy model rows can carry a fingerprint generated
                    // before the parameter JSON was normalized.  Keep both
                    // identities in the historical blacklist so a stale
                    // metadata hash cannot let an already-failed topology
                    // back into the next council generation.
                    $recorded = data_get($agent->modelVersion?->metadata, 'parameter_fingerprint');

                    return array_values(array_filter([$computed, is_string($recorded) ? $recorded : null]));
                })
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $historical = $this->historicalParameterFingerprints[$cacheKey];
        if (! in_array($this->parameterFingerprint($family, $parameters), $historical, true)) {
            return $parameters;
        }

        $keys = $this->historicalNoveltyKeys($family, $parameters, $target, $niche);
        foreach (range(0, 24) as $attempt) {
            $key = $keys[$attempt % max(1, count($keys))] ?? null;
            if (! $key || ! isset($this->schemas->schema($family)[$key])) continue;
            $candidate = $this->nudgeNoveltyParameter($family, $parameters, $key, $attempt, $seed);
            if (! in_array($this->parameterFingerprint($family, $candidate), $historical, true)) {
                return $candidate;
            }
        }

        // A bounded schema can genuinely exhaust one lane. Keep the original
        // candidate in that rare case and let the normal evidence gates report
        // the result; no historical failure is converted into a pass.
        return $parameters;
    }

    private function historicalNoveltyKeys(string $family, array $parameters, string $target, ?array $niche): array
    {
        $objective = (string) data_get($niche, 'objective', '');
        $regime = (string) data_get($niche, 'regime', '');
        if ($family === 'hybrid' && $regime === 'range') {
            return match ($objective) {
                'monthly_survival' => ['range_signal_mode', 'range_deviation'],
                'temporal_stability' => ['range_deviation', 'range_lookback'],
                'calendar_context_rescue' => ['range_reentry_required', 'range_adx_max'],
                'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
                'opportunity_recall' => ['minimum_confidence'],
                default => ['range_signal_mode', 'range_deviation', 'range_reentry_required'],
            };
        }
        if ($family === 'differential_router') {
            $prefix = in_array($regime, ['trend_up', 'trend_down', 'range'], true) ? $regime : 'trend_down';
            if ($prefix === 'range') {
                return ['range_signal_mode', 'range_deviation', 'range_reentry_required'];
            }
            return match ($objective) {
                'monthly_survival' => ["{$prefix}_roc_threshold", "{$prefix}_strength_min"],
                'temporal_stability' => ["{$prefix}_roc_period", "{$prefix}_pullback_atr_fraction"],
                'calendar_context_rescue' => ["{$prefix}_ema_period", "{$prefix}_pullback_atr_fraction"],
                'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
                'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'],
                default => ["{$prefix}_strength_min", "{$prefix}_pullback_atr_fraction", "{$prefix}_risk_multiplier"],
            };
        }

        $preferred = match ($target) {
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence'],
            'profit_factor', 'risk_exit' => ['atr_target_multiplier', 'atr_stop_multiplier', 'time_stop_candles'],
            'temporal_stability', 'monthly_survival', 'rolling_regime' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            default => [],
        };
        $available = array_keys($this->schemas->schema($family));
        return array_values(array_filter([...$preferred, ...$available], fn (string $key): bool => array_key_exists($key, $parameters)));
    }

    private function nudgeNoveltyParameter(string $family, array $parameters, string $key, int $attempt, int $seed): array
    {
        $schema = $this->schemas->schema($family);
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        $candidate = $parameters;
        if ($type === 'boolean') {
            $candidate[$key] = ! (bool) ($parameters[$key] ?? false);
            return $candidate;
        }
        if ($type === 'string') {
            $choices = array_values((array) $min);
            if ($choices === []) return $candidate;
            $current = (string) ($parameters[$key] ?? $choices[0]);
            $position = array_search($current, $choices, true);
            $offset = (($position === false ? 0 : $position) + $attempt + $seed + 1) % count($choices);
            $candidate[$key] = $choices[$offset];
            return $candidate;
        }
        $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
        $span = max(0.0001, (float) $max - (float) $min);
        $direction = (($attempt + $seed) % 2 === 0) ? 1 : -1;
        $step = $span * (.035 + (.01 * intdiv($attempt, 4)));
        $value = $current + ($direction * $step);
        if ($value > (float) $max) $value = (float) $min + $step;
        if ($value < (float) $min) $value = (float) $max - $step;
        $candidate[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        return $candidate;
    }

    private function selectArchitecture(string $symbol, string $timeframe, string $family, string $origin, int $seed, ?string $scope, ?ModelVersion $parent): string
    {
        $architectures = self::ARCHITECTURES[$family] ?? [$family];
        $memory = MutationMemory::query()->where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->where('parameter_key', '__architecture')
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('confidence', '>=', 60)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope));
        $harmful = (clone $memory)->where('outcome', 'harmful')->where('confidence', '>=', 70)->where('forward_delta', '<', 0)
            ->pluck('new_value')->map(fn ($value) => data_get($value, 'value'))->filter()->unique()->all();
        $paused = $this->pausedArchitectures($symbol, $timeframe, $family);
        $available = array_values(array_diff($architectures, $harmful, $paused));
        if ($available === []) $available = $architectures; // search space is exhausted; re-test rather than silently stop learning.
        $parentArchitecture = data_get($parent?->metadata, 'strategy_architecture');
        if ($origin === 'elite' && in_array($parentArchitecture, $available, true)) return $parentArchitecture;
        $beneficial = (clone $memory)->where('outcome', 'beneficial')->where('forward_delta', '>', 0)->orderByDesc('confidence')->first();
        $preferred = data_get($beneficial?->new_value, 'value');
        // Mutation exploits an evidenced topology on two thirds of slots and
        // keeps one third exploratory so a formerly weak architecture can
        // recover under a different regime rather than becoming permanently extinct.
        if ($origin === 'mutation' && in_array($preferred, $available, true) && $seed % 3 !== 0) return $preferred;
        return $available[$seed % count($available)];
    }

    private function architectureBaseStrategy(string $architecture): string
    {
        return match ($architecture) {
            'trend_pullback' => 'trend_v1', 'trend_breakout_retest' => 'trend_retest_v1',
            'breakout_retest', 'breakout_continuation' => 'breakout_v1',
            'volatility_compression_expansion', 'volatility_breakout' => 'volatility_v1',
            'range_mean_reversion', 'range_rsi_reversion' => 'mean_reversion_v1',
            'session_breakout', 'session_mean_reversion' => 'session_v1',
            'momentum_continuation', 'momentum_pullback' => 'momentum_v1',
            'regime_router', 'regime_consensus' => 'hybrid_v1',
            'frozen_regime_specialist_ensemble' => 'regime_ensemble_v1',
            'frozen_parent_differential_router' => 'differential_router_v1',
            default => $architecture.'_v1',
        };
    }

    private function parameterFingerprint(string $family, array $parameters): string
    {
        ksort($parameters);
        return hash('sha256', $family.'|'.json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function parameterDistance(string $family, array $left, array $right): float
    {
        $schema = $this->schemas->schema($family);
        $distance = 0.0;
        $count = 0;
        foreach ($schema as $key => $definition) {
            [$type, $min, $max] = array_pad($definition, 3, null);
            if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) continue;
            $count++;
            if ($type === 'boolean') {
                $distance += (bool) $left[$key] === (bool) $right[$key] ? 0.0 : 1.0;
                continue;
            }
            if ($type === 'string') {
                $distance += (string) $left[$key] === (string) $right[$key] ? 0.0 : 1.0;
                continue;
            }
            $distance += abs((float) $left[$key] - (float) $right[$key]) / max(0.000001, (float) $max - (float) $min);
        }
        return $count ? $distance / $count : 1.0;
    }

    private function mutate(string $symbol, string $timeframe, string $family, array $base, int $seed, ?string $scope, string $target = 'profit_factor', bool $isolated = false, array $historyKeys = []): array
    {
        $schema = $this->schemas->schema($family);
        $signatureBound = in_array($target, ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router', 'opportunity_recall'], true);
        $historicalPrior = $this->historicalLearning->confirmedMutationPrior($symbol, $timeframe, $family, $scope);
        $beneficial = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->when($signatureBound && $scope, fn ($query) => $query->whereJsonContains('behavioral_effect->failure_signature->regime', $scope))
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('outcome', 'beneficial')->where('confidence', '>=', 60)->where('forward_delta', '>', 0)
            ->whereNotNull('execution_contract_hash')->where('independent_confirmation_count', '>=', 2)
            ->where('non_target_regression_status', 'passed')
            ->orderByDesc('confidence')->first();
        $harmful = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->when($signatureBound && $scope, fn ($query) => $query->whereJsonContains('behavioral_effect->failure_signature->regime', $scope))
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('outcome', 'harmful')->where('confidence', '>=', 60)->where('forward_delta', '<', 0)
            ->orderByDesc('confidence')->first();
        $keys = array_keys($schema);
        $targetKeys = match ($target) {
            // Trade deficit: relax only the filter proven to be preventing
            // entries. Do not solve starvation by silently increasing risk.
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold', 'compression_ratio'],
            // Shadow regret pointed to this exact guard. Keep the rescue
            // bounded to the guard and its online evidence threshold.
            'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
            'shadow_veto_confidence' => ['minimum_signal_confidence'],
            'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
            // PF is an exit-topology experiment: stop/target/trailing/time
            // exits and partials are changed as a coherent family.
            'profit_factor', 'risk_exit', 'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier', 'avoid_high_volatility'],
            // These are deliberately separate targets. A temporal collapse,
            // a bad regime, and a poor month are different failures and must
            // not all mutate the same undifferentiated \"rolling\" bundle.
            'temporal_stability' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            'monthly_survival' => [
                'session_filter_enabled', 'session_start', 'session_end',
                'transition_firewall_enabled', 'transition_wait_candles',
                'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                'breakout_lookback', 'breakout_atr_threshold',
                'range_lookback', 'range_deviation', 'range_adx_max',
            ],
            'regime_coverage' => ['trend_strength_min', 'high_volatility_risk_multiplier', 'minimum_signal_confidence', 'lookback'],
            // G98 council lanes are isolated.  Their lists describe a
            // possible single causal gene, never a bundle to optimise PF.
            'volatility_session_stability' => ['session_filter_enabled', 'session_start', 'session_end', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
            'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'],
            'portfolio_router' => ['differential_target_regime', 'differential_target_volatility', 'differential_target_direction', 'minimum_signal_confidence'],
            'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'],
            'rolling_regime', 'architecture' => ['lookback', 'session_start', 'session_end', 'trend_strength_min', 'minimum_signal_confidence', 'high_volatility_risk_multiplier'],
            default => $keys,
        };
        // Hybrid calendar/temporal failures must mutate the real specialist
        // adapters. A generic lookback mutation used to change nothing in the
        // hybrid runtime because its trend/breakout/range lookbacks were
        // hard-coded constants.
        if ($family === 'hybrid') {
            $targetKeys = match ($target) {
                'temporal_stability', 'monthly_survival' => [
                    'session_filter_enabled', 'session_start', 'session_end',
                    'transition_firewall_enabled', 'transition_wait_candles',
                    'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                    'breakout_lookback', 'breakout_atr_threshold',
                    'range_lookback', 'range_deviation', 'range_adx_max',
                ],
                'regime_coverage' => [
                    'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                    'range_lookback', 'range_deviation', 'range_adx_max',
                    'range_low_volatility_only', 'high_volatility_wait',
                ],
                'volatility_session_stability' => [
                    'session_filter_enabled', 'session_start', 'session_end',
                    'high_volatility_wait', 'high_volatility_risk_multiplier',
                ],
                'exit_topology' => [
                    'trend_atr_stop_multiplier', 'trend_atr_target_multiplier',
                    'breakout_atr_stop_multiplier', 'breakout_atr_target_multiplier',
                    'range_atr_stop_multiplier', 'range_atr_target_multiplier',
                ],
                'trade_frequency' => [
                    'trend_weight', 'breakout_weight', 'mean_reversion_weight',
                    'minimum_confidence', 'session_filter_enabled',
                ],
                default => $targetKeys,
            };
        }
        if ($family === 'differential_router' && in_array($target, ['temporal_stability', 'monthly_survival', 'volatility_session_stability'], true)) {
            $targetKeys = [
                'session_filter_enabled', 'session_start', 'session_end',
                'transition_firewall_enabled', 'transition_wait_candles',
                'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                'breakout_lookback', 'breakout_atr_threshold',
                'range_lookback', 'range_deviation', 'range_adx_max',
            ];
        }
        // Immutable failure profiles narrow the experiment to genes that can
        // affect the diagnosed failure. This is a search prior, never a gate
        // shortcut; a snapshot-only insight is still marked non-causal.
        $historyTargetKeys = array_values(array_intersect($targetKeys, $historyKeys));
        if ($historyTargetKeys !== []) $targetKeys = $historyTargetKeys;
        $targetKeys = array_values(array_intersect($keys, $targetKeys));
        if ($targetKeys !== []) $keys = $targetKeys;
        $latestDiagnosis = AgentDiagnosis::query()->whereHas('modelMarketPerformance', fn ($query) => $query
            ->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))->latest()->first();
        $latestDeficits = (array) data_get($latestDiagnosis?->evidence, 'deficits', []);
        // A high-confidence harmful mutation is evidence, not a tie-breaker.
        // Keep it out of the next search unless every available gene has been
        // falsified; otherwise a single historic beneficial result could keep
        // resurrecting a known-bad direction.
        $harmfulKeys = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('outcome', 'harmful')->where('confidence', '>=', 70)->where('forward_delta', '<', 0)
            ->pluck('parameter_key')->unique()->all();
        $safeKeys = array_values(array_diff($keys, $harmfulKeys));
        if ($safeKeys !== []) $keys = $safeKeys;
        // Three repeated changes with no observable behavioural movement are
        // not treated as an optimisation signal. Temporarily park the gene so
        // the generation budget can test a materially different mechanism.
        $ineffectiveKeys = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->whereIn('parameter_key', $keys)->get()
            ->filter(fn (MutationMemory $memory) => data_get($memory->behavioral_effect, 'causal_experiment.parameter_effective') === false)
            ->pluck('parameter_key')->unique()->all();
        $effectiveKeys = array_values(array_diff($keys, $ineffectiveKeys));
        if ($effectiveKeys !== []) $keys = $effectiveKeys;
        $diagnosedKey = AgentDiagnosis::whereHas('modelMarketPerformance', fn($q)=>$q->where('symbol',$symbol)->where('timeframe',$timeframe)->where('strategy_family',$family))
            ->latest()->get()->flatMap(fn($item)=>$item->recommended_mutations??[])->first(fn($key)=>isset($schema[$key]) && in_array($key, $keys, true));
        $decisionAdvice = $this->decisionLearning->advice($symbol, $timeframe, $family, $scope);
        $decisionKey = collect($decisionAdvice['prioritize'])->first(fn ($key) => isset($schema[$key]) && in_array($key, $keys, true));
        if (! $beneficial && $harmful && count($keys) > 1) {
            $keys = array_values(array_diff($keys, [$harmful->parameter_key]));
        }
        $bayesian = $this->bayesianMutations->recommend($symbol, $timeframe, $family, $keys, $latestDeficits, $scope);
        $bayesianKey = data_get($bayesian, 'evidence_status') === 'confirmed_prior'
            && isset($schema[data_get($bayesian, 'parameter_key')])
            && in_array(data_get($bayesian, 'parameter_key'), $keys, true)
            ? data_get($bayesian, 'parameter_key') : null;
        $historicalKey = $historicalPrior && isset($schema[$historicalPrior['parameter_key']])
            && in_array($historicalPrior['parameter_key'], $keys, true) ? $historicalPrior['parameter_key'] : null;
        $key = $beneficial?->parameter_key && isset($schema[$beneficial->parameter_key]) && in_array($beneficial->parameter_key, $keys, true)
            ? $beneficial->parameter_key : ($historicalKey ?: ($bayesianKey ?: ($decisionKey ?: ($diagnosedKey ?: $keys[$seed % count($keys)]))));
        // The first hybrid monthly-survival experiment must actually test the
        // missing calendar mechanism. Selecting an execution gene here would
        // reproduce G71's PF rescue and leave the month failure untouched.
        if (in_array($family, ['hybrid', 'differential_router'], true)
            && $target === 'monthly_survival'
            && isset($schema['transition_firewall_enabled'])) {
            $key = 'transition_firewall_enabled';
        }
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        $learnedDirection = static fn (MutationMemory $memory): int => data_get($memory->new_value, 'value') >= data_get($memory->old_value, 'value') ? 1 : -1;
        $direction = $beneficial && is_numeric(data_get($beneficial->new_value, 'value')) && is_numeric(data_get($beneficial->old_value, 'value'))
            ? $learnedDirection($beneficial)
            : ($harmful && is_numeric(data_get($harmful->new_value, 'value')) && is_numeric(data_get($harmful->old_value, 'value'))
                ? -$learnedDirection($harmful)
                : ($historicalPrior && is_numeric($historicalPrior['direction'] ?? null)
                    ? (int) $historicalPrior['direction'] * ($historicalPrior['outcome'] === 'harmful' ? -1 : 1)
                    : ($seed % 2 === 0 ? 1 : -1)));
        if ($target === 'trade_frequency' && in_array($key, ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold'], true)) $direction = -1;
        if ($target === 'shadow_veto_loss_cooldown' && in_array($key, ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'], true)) $direction = -1;
        if ($target === 'shadow_veto_confidence') $direction = -1;
        if ($target === 'shadow_veto_volatility' && $key === 'high_volatility_risk_multiplier') $direction = 1;
        if ($target === 'opportunity_recall' && in_array($key, ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'], true)) $direction = -1;
        if ($target === 'drawdown_risk' && in_array($key, ['high_volatility_risk_multiplier'], true)) $direction = -1;
        if ($target === 'drawdown_risk' && in_array($key, ['max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier'], true)) $direction = 1;
        if ($type === 'boolean') {
            $base[$key] = ! (bool) ($base[$key] ?? false);
            return $isolated ? $base : $this->applyBoundedBundle($schema, $base, $key, $target, $direction);
        }
        $current = (float) ($base[$key] ?? (($min + $max) / 2));
        $step = ($beneficial || $harmful) ? 0.05 : 0.1;
        $value = max($min, min($max, $current + $direction * (($max - $min) * $step)));
        $base[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        return $isolated ? $base : $this->applyBoundedBundle($schema, $base, $key, $target, $direction);
    }

    /** A mutation is a small coherent experiment, never an unbounded re-fit. */
    private function applyBoundedBundle(array $schema, array $parameters, string $primary, string $target, int $direction): array
    {
        $bundle = match ($target) {
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min'],
            'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
            'shadow_veto_confidence' => ['minimum_signal_confidence'],
            'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
            'profit_factor', 'risk_exit' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'],
            'temporal_stability' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            'monthly_survival' => [
                'session_filter_enabled', 'session_start', 'session_end',
                'transition_firewall_enabled', 'transition_wait_candles',
                'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                'breakout_lookback', 'breakout_atr_threshold',
                'range_lookback', 'range_deviation', 'range_adx_max',
            ],
            'regime_coverage' => ['trend_strength_min', 'high_volatility_risk_multiplier', 'minimum_signal_confidence'],
            'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'],
            'rolling_regime', 'architecture' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            default => [],
        };
        // Primary plus two related controls is enough to test topology while
        // retaining a causal attribution path for each bundle.
        foreach (array_slice(array_values(array_diff($bundle, [$primary])), 0, 2) as $key) {
            if (! isset($schema[$key])) continue;
            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            if ($type === 'boolean') {
                if ($target === 'drawdown_risk') $parameters[$key] = true;
                continue;
            }
            $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
            $secondaryDirection = $target === 'trade_frequency' ? -1 : $direction;
            if ($target === 'drawdown_risk' && $key === 'high_volatility_risk_multiplier') $secondaryDirection = -1;
            $value = max($min, min($max, $current + $secondaryDirection * (($max - $min) * .03)));
            $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        }
        return $parameters;
    }

    private function qualityParents(string $symbol, string $timeframe, string $family)
    {
        return ModelMarketPerformance::with('modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->where('strategy_family', $family)
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->get()
            ->filter(fn (ModelMarketPerformance $performance) => $this->parentEligible($performance))
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance))
            ->pluck('modelVersion')
            ->filter()
            ->values();
    }

    /**
     * Screening-only seed frontier. These models have enough reproducible
     * edge to be useful as mutation anchors, but intentionally do not satisfy
     * the stricter parentEligible() passport. The metadata on every child
     * makes this distinction auditable and the normal gate still decides all
     * later promotion stages.
     */
    private function screeningSeedParents(string $symbol, string $timeframe, string $family)
    {
        $valid = ModelMarketPerformance::with('modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->where('strategy_family', $family)
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->get()
            ->filter(function (ModelMarketPerformance $performance): bool {
                $metrics = (array) ($performance->metrics ?? []);
                return (float) data_get($metrics, 'profit_factor', 0) >= 1.25
                    && (int) $performance->sample_count >= 25
                    && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
                    && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
                    && ! (bool) data_get($metrics, 'is_overfit', true);
            })
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance))
            ->pluck('modelVersion')
            ->filter()
            ->values();

        if ($valid->isNotEmpty()) return $valid;

        // Some early runs used a non-canonical historical slice and are
        // explicitly marked legacy_invalid. They may re-enter only as a
        // hypothesis seed when no canonical valid frontier exists; their old
        // score is never copied into a gate, forward ledger, or paper state.
        return ModelMarketPerformance::with('modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('evidence_status', 'legacy_invalid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'legacy_invalid'))
            ->where('strategy_family', $family)
            ->whereIn('status', ['challenger', 'paper', 'archived'])
            ->get()
            ->filter(function (ModelMarketPerformance $performance): bool {
                $metrics = (array) ($performance->metrics ?? []);
                return (float) data_get($metrics, 'profit_factor', 0) >= 1.25
                    && (int) $performance->sample_count >= 25
                    && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
                    && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
                    && ! (bool) data_get($metrics, 'is_overfit', true);
            })
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance))
            ->pluck('modelVersion')
            ->filter()
            ->values();
    }

    private function parentEligible(ModelMarketPerformance $performance): bool
    {
        $metrics = $performance->metrics ?? [];
        $bootstrap = data_get($metrics, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        $bootstrapPasses = data_get($bootstrap, 'status') !== 'assessed'
            || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1;
        $worstRegime = data_get($metrics, 'statistical_evidence.edge_quality');
        $regimePasses = ! data_get($worstRegime, 'worst_regime_sampled', false)
            || (float) data_get($worstRegime, 'worst_regime_pf', 0) >= 1.0;

        return (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPasses && $regimePasses
            && data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
    }

    private function parentQualityScore(ModelMarketPerformance $performance): float
    {
        $metrics = $performance->metrics ?? [];

        return ((float) $performance->forward_score * 2)
            + ((float) data_get($metrics, 'profit_factor', 0) * 25)
            - ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) * 2)
            - (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100));
    }

    private function mutationScope(string $symbol, string $timeframe, string $family, int $seed): string
    {
        $scopes = [];
        ModelMarketPerformance::query()
            ->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)
            ->where('evidence_status', 'valid')
            ->latest('updated_at')
            ->take(20)
            ->get()
            ->each(function (ModelMarketPerformance $performance) use (&$scopes): void {
                foreach (['market' => 'regime_performance', 'volatility' => 'volatility_performance'] as $prefix => $key) {
                    foreach (data_get($performance->metrics, $key, []) as $name => $metric) {
                        if ((int) data_get($metric, 'trades', 0) < 20) {
                            continue;
                        }
                        $scope = "{$prefix}:{$name}";
                        $score = (float) data_get($metric, 'profit_percent', 0) / max(1, (int) data_get($metric, 'trades', 1));
                        $scopes[$scope] = min($scopes[$scope] ?? INF, $score);
                    }
                }
            });

        if ($scopes !== []) {
            asort($scopes);
            return (string) array_key_first($scopes);
        }

        $fallbacks = ['market:trend_up', 'market:trend_down', 'market:range', 'volatility:high_volatility', 'volatility:normal_volatility'];
        return $fallbacks[$seed % count($fallbacks)];
    }

    private function crossover(string $family, array $a, array $b): array
    {
        $child = [];
        foreach (array_keys($this->schemas->schema($family)) as $index => $key) $child[$key] = $index % 2 === 0 ? ($a[$key] ?? $b[$key]) : ($b[$key] ?? $a[$key]);
        return $child;
    }

    /** Blend entry, exit and risk genes from the parent with demonstrated skill in that domain. */
    private function skillCrossover(string $family, $parents, array $fallback, int $seed): array
    {
        $parents = collect($parents)->filter()->values();
        if ($parents->isEmpty()) return [$this->randomParameters($family, $seed), []];
        $child = $fallback;
        $sources = [];
        foreach (array_keys($this->schemas->schema($family)) as $key) {
            $skill = match (true) {
                in_array($key, ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'], true) => 'exit_skill',
                in_array($key, ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'], true) => 'risk_skill',
                in_array($key, ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'pullback_atr_fraction', 'roc_threshold', 'deviation'], true) => 'entry_timing_skill',
                default => 'cost_robustness_skill',
            };
            $parent = $parents->sortByDesc(fn (ModelVersion $model) => (float) data_get($model->metadata, "skill_tree.{$skill}", 0))->first();
            if ($parent && array_key_exists($key, $parent->parameters ?? [])) {
                $child[$key] = $parent->parameters[$key];
                $sources[$skill] = $parent->id;
            }
        }
        return [$child, $sources];
    }

    private function randomParameters(string $family, int $seed): array
    {
        $values = [];
        $index = 0;
        foreach ($this->schemas->schema($family) as $key => $rule) {
            [$type, $min, $max] = array_pad($rule, 3, null);
            if ($type === 'boolean') { $values[$key] = ($seed + $index) % 2 === 0; $index++; continue; }
            if ($type === 'string') {
                $choices = (array) $min;
                $values[$key] = $choices[($seed + $index) % max(1, count($choices))] ?? '';
                $index++;
                continue;
            }
            $ratio = (($seed * 37 + $index * 17) % 101) / 100;
            $value = $min + ($max - $min) * $ratio;
            $values[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
            $index++;
        }
        return $values;
    }

    private function dataSnapshot(AiLaboratory $lab): array
    {
        $symbolId = Symbol::where('code', $lab->symbol)->value('id');
        if (! $symbolId) return ['fingerprint' => 'no-data', 'count' => 0, 'latest' => null];
        $query = Candle::where('symbol_id', $symbolId)->where('timeframe', $lab->timeframe);
        $count = $query->count();
        $latest = $query->max('time');
        return ['fingerprint' => sha1($count.'|'.($latest ?? 'none')), 'count' => $count, 'latest' => $latest];
    }

    private function diff(array $old, array $new): array
    {
        return collect($new)->filter(fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value)
            ->map(fn ($value, $key) => ['old' => $old[$key] ?? null, 'new' => $value])->all();
    }
}
