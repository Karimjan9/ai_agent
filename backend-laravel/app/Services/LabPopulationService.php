<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\AgentKnowledgeCard;
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
        'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
    ];

    /** Screening is terminal only after every agent has a terminal outcome. */
    public const TERMINAL_GENERATION_STATUSES = [
        'screened', 'completed', 'technical_quarantine', 'abandoned', 'failed',
    ];

    public const GENERATION_PROTOCOL = 'g98_failure_eliminator_v1';
    public const TARGETED_RESCUE_PROFILE_PROTOCOL = 'targeted_failure_profile_v2';
    public const ROLE_COMPLETE_COUNCIL_PROTOCOL = 'role_complete_council_v1';
    public const POPULATION_GROUP_PROTOCOL = 'population_group_checkpoint_v1';
    public const SPECIALIST_COUNCIL_PROTOCOL = 'specialist_council_v1';
    public const POPULATION_GROUP_SEATS = 4;

    /**
     * The normal twenty-seat population is five stable research groups.  A
     * group is a research objective, not a strategy family: the family and
     * semantic cell still decide which parents are legal.
     *
     * @var array<string, array{label: string, axis: string}>
     */
    public const POPULATION_GROUPS = [
        'monthly_survival' => ['label' => 'Monthly survival', 'axis' => 'temporal_robustness'],
        'regime_coverage' => ['label' => 'Regime coverage', 'axis' => 'regime_coverage'],
        'volatility_session_stability' => ['label' => 'Volatility/session stability', 'axis' => 'cost_stability'],
        'exit_topology' => ['label' => 'Exit topology', 'axis' => 'risk_exit'],
        'portfolio_router' => ['label' => 'Portfolio/router integrity', 'axis' => 'portfolio_integrity'],
    ];

    /**
     * Temporary rescue curriculum: five named objectives, four seats each.
     * The legacy population-group keys remain stable for audit compatibility;
     * rescue_objective explains what each group is trying to repair.
     */
    public const TARGETED_RESCUE_GROUP_PLAN = [
        'volatility_session_stability' => [
            'rescue_objective' => 'pf_stress_cost',
            'specialist_role' => 'pf_stress_specialist',
            'targets' => ['profit_factor', 'stress_cost', 'profit_factor', 'stress_cost'],
        ],
        'monthly_survival' => [
            'rescue_objective' => 'temporal_calendar_stability',
            'specialist_role' => 'temporal_calendar_specialist',
            'targets' => ['temporal_stability', 'temporal_stability', 'temporal_stability', 'temporal_stability'],
        ],
        'regime_coverage' => [
            'rescue_objective' => 'regime_specialist',
            'specialist_role' => 'regime_coverage_specialist',
            'targets' => ['regime_coverage', 'regime_coverage', 'regime_coverage', 'regime_coverage'],
        ],
        'exit_topology' => [
            'rescue_objective' => 'non_target_regression',
            'specialist_role' => 'non_target_regression_specialist',
            'targets' => ['drawdown_risk', 'drawdown_risk', 'drawdown_risk', 'drawdown_risk'],
        ],
        'portfolio_router' => [
            'rescue_objective' => 'architecture_control',
            'specialist_role' => 'architecture_control_specialist',
            'targets' => ['architecture', 'architecture', 'architecture', 'architecture'],
        ],
    ];

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
        private AgentKnowledgeService $knowledge,
        private TacticCatalogueService $tactics,
        private StrategySemanticGroupService $semanticGroups,
        private ControlRootInheritanceService $controlRootInheritance,
        private EvolutionGovernorService $evolutionGovernor,
        private EvolutionArchiveService $evolutionArchive,
        private AdaptiveParentFrontierService $adaptiveParentFrontier,
        private ExecutionContractService $executionContracts,
    ) {}

    public function ensureLaboratories(): void
    {
        foreach (self::LABS as $symbol => $config) {
            AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => 'H1'], [
                'name' => $config['name'], 'timeframe' => 'H1',
                'strategy_families' => $config['families'], 'is_active' => true,
                'lifecycle_mode' => $symbol === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL ? 'lighthouse' : 'shadow',
            ]);
        }

        // Every symbol now has a separate M15 entry lab. M15 is an evidence
        // stream for entries only; its regime context is the last CLOSED H1
        // candle, and its execution costs remain the same contract as H1.
        foreach (self::LABS as $symbol => $config) {
            AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => 'M15'], [
                'name' => "{$symbol} M15 Specialist Lab", 'timeframe' => 'M15',
                'strategy_families' => array_values(array_unique([...$config['families'], 'regime_ensemble'])),
                'is_active' => true,
                'lifecycle_mode' => 'shadow',
            ]);
        }
    }

    public function build(string $symbol, string $trigger = 'new_data', bool $force = false, string $timeframe = 'H1', array $coverageRescue = [], bool $roleComplete = false, bool $refreshHistoricalLearning = true, ?int $populationLimit = null, ?array $targetedFailureProfile = null, bool $allowControlledRescue = false): ?LabGeneration
    {
        // Existing queued jobs stay intact.  This only prevents creation of a
        // new population while an execution-contract rollout is being audited.
        $controlledRescue = $allowControlledRescue
            && $this->protocolSafety->controlledRescueAllowed($trigger, $populationLimit, $targetedFailureProfile);
        if ($allowControlledRescue && ! $controlledRescue) return null;
        if ($this->protocolSafety->generationCreationPaused() && ! $controlledRescue) return null;
        $this->ensureLaboratories();
        $timeframe = strtoupper($timeframe);
        $lab = AiLaboratory::where('symbol', strtoupper($symbol))->where('timeframe', $timeframe)->firstOrFail();
        if (! $controlledRescue && (string) $lab->lifecycle_mode !== 'lighthouse') {
            // Non-lighthouse labs remain research/shadow streams. They may be
            // monitored and studied, but they cannot create an evolution
            // generation or become a parent ecosystem.
            return null;
        }
        // Recompute the append-only historical conclusion before planning a
        // new population. Snapshot history may choose the failure target;
        // exact causal credits are handled separately by mutate().
        // The role-complete builder is an explicit child handoff and may be
        // invoked after a data/edge audit. Its curriculum already consumes
        // the latest append-only insight; do not rescan the million-row
        // candle-event plane while holding the population creation path open.
        // The normal scheduler and sync-agent-knowledge command continue to
        // refresh historical learning independently.
        if (! $roleComplete && $refreshHistoricalLearning) {
            $this->historicalLearning->refreshForLab($lab->symbol, $lab->timeframe);
        }
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
        if ($roleComplete && ! app()->environment('testing')) {
            $dataIntegrity = app(MarketDriftDetectionService::class)->canonicalDataContract($lab->symbol, $lab->timeframe);
            if ($dataIntegrity['status'] !== 'ready' || $dataIntegrity['is_canonical'] !== true) return null;
        }
        $snapshot = $this->dataSnapshot($lab);
        $fingerprint = $snapshot['fingerprint'];
        $latest = $lab->generations()->latest('generation')->first();
        // Do not look only at the numerically latest row. A stale scheduler
        // can leave an older generation in screening while a later terminal
        // row exists; that older stream still owns the laboratory lock.
        if ($lab->generations()->whereIn('status', self::ACTIVE_GENERATION_STATUSES)->exists()) {
            return null;
        }
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
        $screenedCoverageRescue = $trigger === 'coverage_rescue'
            && $latest?->status === 'screened'
            && (bool) data_get($coverageRescue, 'eligible')
            && data_get($coverageRescue, 'protocol') === CoverageRescueAuditService::PROTOCOL;
        if ($latest && in_array($latest->status, self::ACTIVE_GENERATION_STATUSES, true)
            && ! $screenedCandidateHandoff && ! $screenedDataEdgeAudit && ! $screenedCoverageRescue) {
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
        // A fresh population needs roughly one day of new evidence on its
        // own stream: 24 H1 bars or 96 M15 bars. Degradation and explicit
        // handoff/audit protocols remain immediate safety exceptions. This
        // prevents the faster M15 feed from creating noisy six-hour
        // generations while preserving its independent entry research lane.
        $minimumFreshCandles = $timeframe === 'M15' ? 96 : 24;
        if (! $force && $latest && $newCandles < $minimumFreshCandles && ! in_array($trigger, ['degradation', 'candidate_handoff', 'data_edge_audit'], true)) {
            return null;
        }

        // Reserve the generation number and immutable plan atomically, but do
        // not keep that transaction open while compiling every child. Parent
        // frontier/capability work is CPU-heavy and can take minutes; a long
        // transaction blocks scheduler reads and makes cancellation look like
        // a database hang.
        $targetedFailureTargets = array_values(array_unique(array_filter(array_map(
            static fn (mixed $target): string => (string) $target,
            (array) data_get($targetedFailureProfile, 'targets', []),
        ))));
        $buildState = DB::transaction(function () use ($lab, $trigger, $fingerprint, $snapshot, $newCandles, $coverageRescue, $roleComplete, $populationLimit, $targetedFailureProfile, $targetedFailureTargets, $controlledRescue): ?array {
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
            $screenedCoverageRescue = $trigger === 'coverage_rescue'
                && $latestInTransaction?->status === 'screened'
                && (bool) data_get($coverageRescue, 'eligible')
                && data_get($coverageRescue, 'protocol') === CoverageRescueAuditService::PROTOCOL;
            if ($lockedLab->generations()->whereIn('status', self::ACTIVE_GENERATION_STATUSES)->exists()) {
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
            $plannedPopulationSize = $roleComplete
                ? max(4, $populationLimit !== null ? (int) $populationLimit : $this->configuredPopulationSize())
                : ($populationLimit !== null
                    ? max(1, (int) $populationLimit)
                    : $this->configuredPopulationSize());
            $generation = $lockedLab->generations()->create([
                'generation' => $number, 'trigger_type' => $trigger,
                'trigger_context' => ['previous_generation' => $latestInTransaction?->generation, 'created_by' => 'learning_trigger',
                    'data_count' => $snapshot['count'], 'latest_candle' => $snapshot['latest'], 'new_candles' => $newCandles,
                    'generation_protocol' => self::GENERATION_PROTOCOL,
                    'council_protocol' => $roleComplete ? self::ROLE_COMPLETE_COUNCIL_PROTOCOL : null,
                    'role_complete_council' => $roleComplete,
                    'canonical_data_contract' => $roleComplete
                        ? app(MarketDriftDetectionService::class)->canonicalDataContract($lockedLab->symbol, $lockedLab->timeframe)
                        : null,
                    'coverage_rescue_audit' => $trigger === 'coverage_rescue' ? $coverageRescue : null,
                    'targeted_failure_profile' => $targetedFailureProfile,
                    'portfolio_failure_curriculum' => $roleComplete ? [] : $this->portfolioFailureCurriculum($lockedLab),
                    'portfolio_council_curriculum' => $roleComplete
                        ? $this->roleCouncilCurriculumSnapshot($lockedLab)
                        : $this->portfolioCouncilCurriculum($lockedLab)],
                'data_fingerprint' => $fingerprint, 'population_size' => $plannedPopulationSize,
                'status' => 'draft', 'started_at' => now(),
            ]);

            // Fixed, auditable experiment budget.  A slot is assigned for the
            // gate it is meant to move; it is not an undifferentiated "more
            // agents" budget.
            $plan = $this->generationPlan($lockedLab, $coverageRescue, $roleComplete, $populationLimit, $targetedFailureTargets, $targetedFailureProfile);
            if ($populationLimit !== null) {
                $limit = $roleComplete ? max(4, (int) $populationLimit) : max(1, (int) $populationLimit);
                $plan = array_slice($plan, 0, $limit);
            }
            $baseGenerationPlan = $plan;
            $preAdaptivePolicy = $this->evolutionGovernor->generationSnapshot($lockedLab, $plan);
            if ($populationLimit === null && ! $roleComplete && ! data_get($coverageRescue, 'eligible')) {
                $plan = $this->evolutionGovernor->adaptPlan($plan, $preAdaptivePolicy);
            }
            // Recompute only the planned-origin projection after adaptation;
            // the observed metrics must remain tied to the same lookback
            // history and are retained in the policy for auditability.
            $adaptiveEvolutionPolicy = $this->evolutionGovernor->generationSnapshot($lockedLab, $plan);
            $adaptiveEvolutionPolicy['base_generation_plan'] = $baseGenerationPlan;
            $adaptiveEvolutionPolicy['adaptive_plan_changed'] = $baseGenerationPlan !== $plan;
            $adaptiveEvolutionPolicy['adaptive_plan_protocol'] = 'champion_guided_adaptive_budget_v1';
            $adaptiveEvolutionPolicy['plan_change_rule'] = 'protect causal floor; allocate remaining seats to robust, architecture and curiosity lanes under stagnation, concentration or drift pressure';
            if (! $roleComplete && $populationLimit === null && ! data_get($coverageRescue, 'eligible')) {
                // The governor may reorder or replace reserve lanes. Reapply
                // the executable five-target core after that decision so an
                // adaptive plan cannot reintroduce an invalid filler target.
                $plan = $this->fillNormalCouncilCore($plan, $lockedLab, $plannedPopulationSize);
            }
            // Group membership is an explicit council contract. Recompute it
            // after adaptive planning so a governor cannot turn a balanced
            // five-by-four core into an accidental target-count imbalance.
            $plan = $this->assignPopulationGroupSeats($plan);
            $populationGroupContract = $this->populationGroupContract($plan);
            $priorGroupCheckpoints = $this->latestGroupCheckpoints($lockedLab);
            $generation->update(['trigger_context' => [
                ...($generation->trigger_context ?? []),
                'generation_plan' => $plan,
                'adaptive_evolution_policy' => $adaptiveEvolutionPolicy,
                'population_group_contract' => $populationGroupContract,
                'group_checkpoint_inputs' => $priorGroupCheckpoints,
                'specialist_council_contract' => [
                    'protocol' => self::SPECIALIST_COUNCIL_PROTOCOL,
                    'global_champion_forbidden' => true,
                    'member_model' => 'complementary_specialists_by_group_and_semantic_cell',
                    'parameter_specialist_rule' => 'Each member may own a bounded parameter/skill niche; group progress is measured as a frontier, not a singleton score.',
                    'combined_activation' => 'individual_passports_then_council_quorum',
                    'promotion_evidence' => false,
                ],
                'controlled_rescue_admission' => $controlledRescue ? [
                    'protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
                    'profile_protocol' => data_get($targetedFailureProfile, 'protocol'),
                    'temporary' => true,
                    'normal_generation_creation_still_paused' => $this->protocolSafety->generationCreationPaused(),
                    'promotion_evidence' => false,
                ] : null,
                'group_checkpoint_rule' => [
                    'protocol' => self::POPULATION_GROUP_PROTOCOL,
                    'checkpoint_advances_only_from' => ['challenger', 'forward_validated', 'paper', 'champion'],
                    'screening_and_quarantine_are_diagnostic_only' => true,
                    'exact_semantic_parent_rule_unchanged' => true,
                    'promotion_evidence' => false,
                ],
            ]]);
            $this->historicalLearning->recordGenerationConsumption($generation, $plan);
            return [
                'generation_id' => $generation->id,
                'plan' => $plan,
            ];
        });

        if ($buildState === null) return null;

        $generation = LabGeneration::query()->with('laboratory')->findOrFail((int) $buildState['generation_id']);
        $plan = (array) ($buildState['plan'] ?? []);
        $createdAgents = 0;
        $constructionFailures = [];
        foreach ($plan as $index => $spec) {
            // Each child is atomic on its own. Completed siblings remain
            // visible as durable construction progress, while a cancelled
            // child cannot leave half of its model/link/archive writes.
            $created = DB::transaction(function () use ($generation, $spec, $index): bool {
                $currentGeneration = LabGeneration::query()->with('laboratory')->findOrFail($generation->id);

                return $this->createAgent(
                    $currentGeneration,
                    $spec['family'],
                    $spec['origin'],
                    $index + 1,
                    $spec['target'],
                    $spec['niche'] ?? null,
                    $spec['history'] ?? null,
                    $spec['research_group'] ?? null,
                    (int) ($spec['group_seat'] ?? 0),
                );
            });
            if ($created) {
                $createdAgents++;
            } else {
                $constructionFailures[] = [
                    'slot' => $index + 1,
                    'family' => $spec['family'],
                    'origin' => $spec['origin'],
                    'target' => $spec['target'],
                    'reason' => 'no_legal_nonzero_mutation',
                    'promotion_evidence' => false,
                ];
            }
        }
        $generation->update([
            'population_size' => $createdAgents,
            'trigger_context' => [
                ...((array) $generation->fresh()->trigger_context),
                'constructor_audit' => [
                    'protocol' => 'agent_constructor_invariant_v1',
                    'planned_slots' => count($plan),
                    'created_agents' => $createdAgents,
                    'skipped_zero_diff_slots' => $constructionFailures,
                    'rule' => 'No zero-diff child is persisted; a blocked experiment is skipped and remains diagnostic only.',
                    'promotion_evidence' => false,
                ],
            ],
        ]);

        return $generation->fresh(['agents.modelVersion']);
    }

    private function configuredPopulationSize(): int
    {
        $minimum = max(1, (int) config('services.lab_selection.population_min_size', 1));
        $configuredMaximum = (int) config('services.lab_selection.population_max_size', 0);
        $requested = max($minimum, (int) config('services.lab_selection.population_size', 20));
        return $configuredMaximum > 0
            ? min(max($minimum, $configuredMaximum), $requested)
            : $requested;
    }

    /** @return array<string, mixed> */
    private function populationGroupSeatContract(string $group, int $seat): array
    {
        $definition = self::POPULATION_GROUPS[$group] ?? [
            'label' => $group,
            'axis' => 'diagnostic_reserve',
        ];

        return [
            'protocol' => self::POPULATION_GROUP_PROTOCOL,
            'key' => $group,
            'label' => $definition['label'],
            'axis' => $definition['axis'],
            'seat' => $seat,
            'cohort_size' => self::POPULATION_GROUP_SEATS,
            'search_mode' => $seat <= 2 ? 'depth' : 'breadth',
            'search_role' => match ($seat) {
                1 => 'checkpoint_continuation',
                2 => 'deepening_mutation',
                3 => 'architecture_widening',
                default => 'curiosity_widening',
            },
        ];
    }

    private function researchGroupForTarget(string $target, int $slot): string
    {
        if (array_key_exists($target, self::POPULATION_GROUPS)) return $target;

        $groups = array_keys(self::POPULATION_GROUPS);
        return $groups[max(0, ($slot - 1) % count($groups))];
    }

    /** @param array<int, array<string, mixed>> $plan */
    private function populationGroupContract(array $plan): array
    {
        $groups = collect($plan)
            ->map(function (array $spec, int $index): array {
                $key = (string) ($spec['research_group'] ?? $this->researchGroupForTarget((string) ($spec['target'] ?? ''), $index + 1));
                return [
                    'key' => $key,
                    'seat' => (int) ($spec['group_seat'] ?? 0),
                    'axis' => (string) ($spec['group_axis'] ?? data_get(self::POPULATION_GROUPS, $key.'.axis', 'diagnostic_reserve')),
                    'search_mode' => (string) ($spec['group_search_mode'] ?? 'adaptive_reserve'),
                    'search_role' => (string) ($spec['group_search_role'] ?? 'adaptive_reserve'),
                    'target' => (string) ($spec['target'] ?? ''),
                    'origin' => (string) ($spec['origin'] ?? ''),
                    'rescue_objective' => (string) data_get($spec, 'niche.rescue_lane', ''),
                ];
            })
            ->groupBy('key');

        $groupRows = [];
        foreach (array_keys(self::POPULATION_GROUPS) as $key) {
            $rows = $groups->get($key, collect());
            $groupRows[$key] = [
                ...self::POPULATION_GROUPS[$key],
                'planned_seats' => $rows->count(),
                'seat_numbers' => $rows->pluck('seat')->filter()->values()->all(),
                'search_modes' => $rows->pluck('search_mode')->unique()->values()->all(),
                'targets' => $rows->pluck('target')->filter()->unique()->values()->all(),
                'origins' => $rows->pluck('origin')->filter()->unique()->values()->all(),
                'rescue_objectives' => $rows->pluck('rescue_objective')->filter()->unique()->values()->all(),
                'checkpoint_inheritance' => true,
                'promotion_evidence' => false,
            ];
        }

        $plannedCore = array_sum(array_map(fn (array $row): int => (int) $row['planned_seats'], $groupRows));

        return [
            'protocol' => self::POPULATION_GROUP_PROTOCOL,
            'planned_population' => count($plan),
            'core_group_count' => count(self::POPULATION_GROUPS),
            'core_seats_per_group' => self::POPULATION_GROUP_SEATS,
            'core_population' => count(self::POPULATION_GROUPS) * self::POPULATION_GROUP_SEATS,
            'balanced_core' => $plannedCore === count(self::POPULATION_GROUPS) * self::POPULATION_GROUP_SEATS
                && collect($groupRows)->every(fn (array $row): bool => (int) $row['planned_seats'] === self::POPULATION_GROUP_SEATS),
            'groups' => $groupRows,
            'overflow_seats' => max(0, count($plan) - $plannedCore),
            'rule' => 'Five stable research groups receive four seats in the normal twenty-agent population; overflow is an explicit adaptive reserve and cannot replace a core group seat.',
            'promotion_evidence' => false,
        ];
    }

    /**
     * Keep the normal council budget physically balanced as well as
     * conceptually balanced.  A target such as transition_firewall can be a
     * useful experiment inside a group, but it is not itself a sixth group.
     * Prefer the declared target group while it has a free core seat, then
     * place the remaining diagnostic lanes into the least-filled group.  This
     * makes the five-by-four contract deterministic without changing the
     * experiment target or manufacturing a parent.
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    private function assignPopulationGroupSeats(array $plan): array
    {
        $groupKeys = array_keys(self::POPULATION_GROUPS);
        $counts = array_fill_keys($groupKeys, 0);
        $assigned = [];

        foreach ($plan as $index => $spec) {
            // Targeted rescue seats already declare the research group that
            // owns the failure lane. Preserve that declaration before the
            // normal target-based balancing pass; otherwise abstract targets
            // such as `profit_factor` and `architecture` are redistributed
            // into the wrong groups and the five-by-four cohort loses its
            // intended causal ownership.
            $declared = (string) ($spec['research_group'] ?? '');
            if (($spec['origin'] ?? null) === 'targeted_failure_profile'
                && in_array($declared, $groupKeys, true)
                && $counts[$declared] < self::POPULATION_GROUP_SEATS) {
                $assigned[$index] = $declared;
                $counts[$declared]++;
                continue;
            }
            $target = (string) ($spec['target'] ?? '');
            if (! in_array($target, $groupKeys, true) || $counts[$target] >= self::POPULATION_GROUP_SEATS) {
                continue;
            }
            $assigned[$index] = $target;
            $counts[$target]++;
        }

        foreach ($plan as $index => $spec) {
            if (array_key_exists($index, $assigned)) continue;
            $available = array_values(array_filter(
                $groupKeys,
                fn (string $group): bool => $counts[$group] < self::POPULATION_GROUP_SEATS,
            ));
            if ($available === []) {
                // Seats after the twenty-agent core are explicit overflow;
                // retain a declared group when one exists and otherwise use
                // a stable round-robin reserve. The core remains untouched.
                $declared = (string) ($spec['research_group'] ?? '');
                $assigned[$index] = in_array($declared, $groupKeys, true)
                    ? $declared
                    : $groupKeys[$index % count($groupKeys)];
                continue;
            }
            usort($available, function (string $left, string $right) use ($counts, $groupKeys): int {
                $countOrder = $counts[$left] <=> $counts[$right];
                if ($countOrder !== 0) return $countOrder;
                return array_search($left, $groupKeys, true) <=> array_search($right, $groupKeys, true);
            });
            $assigned[$index] = $available[0];
            $counts[$available[0]]++;
        }

        $seatCounts = array_fill_keys($groupKeys, 0);
        foreach ($plan as $index => &$spec) {
            $group = $assigned[$index] ?? $groupKeys[$index % count($groupKeys)];
            $seat = ++$seatCounts[$group];
            $spec['research_group'] = $group;
            $spec['group_seat'] = $seat;
            $spec['group_axis'] = self::POPULATION_GROUPS[$group]['axis'];
            $spec['group_search_mode'] = $seat <= 2 ? 'depth' : 'breadth';
            $spec['group_search_role'] = match ($seat) {
                1 => 'checkpoint_continuation',
                2 => 'deepening_mutation',
                3 => 'architecture_widening',
                default => 'curiosity_widening',
            };
        }
        unset($spec);

        return $plan;
    }

    /**
     * Fill the normal core with real council experiments when the failure
     * curriculum contains fewer than twenty lanes.  The old implementation
     * filled the remainder with generic `robustness`/`architecture` labels;
     * those labels are not executable mutation targets for every family and
     * could silently reduce a pair's population to eighteen. Reusing an
     * existing council niche (or a deterministic family fallback) preserves
     * the semantic contract and lets the normal one-gene compiler choose a
     * lawful bounded change.
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    private function fillNormalCouncilCore(array $plan, AiLaboratory $lab, int $targetPopulation): array
    {
        $groupKeys = array_keys(self::POPULATION_GROUPS);
        $required = min($targetPopulation, count($groupKeys) * self::POPULATION_GROUP_SEATS);
        if ($required <= 0) return [];

        $family = in_array('differential_router', $lab->strategy_families, true)
            ? 'differential_router'
            : (in_array('hybrid', $lab->strategy_families, true) ? 'hybrid' : ($lab->strategy_families[0] ?? 'hybrid'));
        $fallback = collect($plan)->first(fn (array $spec): bool => ($spec['target'] ?? null) === 'portfolio_router')
            ?? collect($plan)->first(fn (array $spec): bool => ($spec['family'] ?? null) === 'hybrid')
            ?? ($plan[0] ?? [
                'origin' => 'g98_council',
                'family' => $family,
                'target' => 'portfolio_router',
                'niche' => [],
            ]);

        $counts = array_fill_keys($groupKeys, 0);
        $kept = [];
        $replaceable = [];
        foreach (array_slice($plan, 0, $required) as $spec) {
            $target = (string) ($spec['target'] ?? '');
            if (in_array($target, $groupKeys, true) && $counts[$target] < self::POPULATION_GROUP_SEATS) {
                $kept[] = $spec;
                $counts[$target]++;
            } else {
                // Unknown/overflow targets are still useful research context,
                // but they cannot consume one of the five core target seats.
                $replaceable[] = $spec;
            }
        }

        $missingTargets = [];
        foreach ($groupKeys as $target) {
            for ($seat = $counts[$target] + 1; $seat <= self::POPULATION_GROUP_SEATS; $seat++) {
                $missingTargets[] = $target;
            }
        }

        foreach ($missingTargets as $index => $target) {
            $targetTemplate = collect($plan)->first(fn (array $spec): bool => ($spec['target'] ?? null) === $target);
            $template = $targetTemplate ?? ($replaceable[$index] ?? $fallback);
            $families = array_values($lab->strategy_families);
            $familyForSeat = $targetTemplate['family'] ?? ($families[$index % max(1, count($families))] ?? $family);
            $niche = (array) ($template['niche'] ?? []);
            $kept[] = [
                ...$template,
                'origin' => 'g98_council',
                'family' => $familyForSeat,
                'target' => $target,
                'niche' => [
                    ...$niche,
                    'protocol' => 'portfolio_council_v1',
                    'objective' => $target,
                    'mutation_target' => $target,
                    'research_reason' => 'balanced_core_group_seat_fill',
                    'balanced_core_reserve_variant' => $index + 1,
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => $target === 'portfolio_router'
                        ? 'combined_portfolio_only'
                        : 'standalone_forward_passport_required',
                ],
            ];
        }

        // If a curriculum was unusually sparse, keep filling from the
        // deterministic fallback until every core target has four seats.
        while (count($kept) < $required) {
            $target = $groupKeys[count($kept) % count($groupKeys)];
            $families = array_values($lab->strategy_families);
            $familyForSeat = $families[count($kept) % max(1, count($families))] ?? $family;
            $kept[] = [
                ...$fallback,
                'origin' => 'g98_council',
                'family' => $familyForSeat,
                'target' => $target,
                'niche' => [
                    ...(array) ($fallback['niche'] ?? []),
                    'protocol' => 'portfolio_council_v1',
                    'objective' => $target,
                    'mutation_target' => $target,
                    'research_reason' => 'balanced_core_group_seat_fill',
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => $target === 'portfolio_router'
                        ? 'combined_portfolio_only'
                        : 'standalone_forward_passport_required',
                ],
            ];
        }

        $overflow = count($plan) > $required ? array_slice($plan, $required) : [];
        return [...array_slice($kept, 0, $required), ...$overflow];
    }

    /**
     * Read the last durable group report as context for the next generation.
     * It never manufactures a parent: strict semantic parent eligibility is
     * still evaluated independently by createAgent().
     *
     * @return array<string, array<string, mixed>>
     */
    private function latestGroupCheckpoints(AiLaboratory $lab): array
    {
        $generations = $lab->generations()
            ->whereIn('status', self::TERMINAL_GENERATION_STATUSES)
            ->latest('generation')
            ->take(5)
            ->get(['trigger_context']);

        foreach ($generations as $generation) {
            $checkpoints = (array) data_get(
                $generation->trigger_context,
                'latest_generation_report.population_group_checkpoints',
                [],
            );
            if ($checkpoints !== []) return $checkpoints;
        }

        return [];
    }

    /**
     * The default 20-slot budget remains stable across generations so observed
     * gate transitions can be compared. The budget is configurable when a
     * laboratory needs a wider search. Family selection is deficit-weighted:
     * the greatest unsatisfied forward gate gets the earliest experiments.
     */
    private function generationPlan(AiLaboratory $lab, array $coverageRescue = [], bool $roleComplete = false, ?int $populationLimit = null, array $targetedFailureTargets = [], ?array $targetedFailureProfile = null): array
    {
        $targetPopulation = $roleComplete
            ? max(4, $populationLimit !== null ? (int) $populationLimit : $this->configuredPopulationSize())
            : ($populationLimit !== null ? max(1, (int) $populationLimit) : $this->configuredPopulationSize());
        if ((bool) data_get($coverageRescue, 'eligible')) return $this->coverageRescuePlan($coverageRescue, $targetPopulation);
        if ($targetedFailureTargets !== []) {
            if ((string) data_get($targetedFailureProfile, 'protocol') === self::TARGETED_RESCUE_PROFILE_PROTOCOL
                && $targetPopulation >= count(self::POPULATION_GROUPS) * self::POPULATION_GROUP_SEATS) {
                return $this->fiveByFourTargetedFailurePlan($lab, $targetedFailureProfile ?? []);
            }
            return $this->targetedFailurePlan($lab, $targetedFailureTargets, $targetPopulation, $targetedFailureProfile ?? []);
        }
        // A role-complete build may be intentionally bounded to the four
        // mandatory seats while its constructor/lineage contract is being
        // proven.  Do not route that request through the generic root-recovery
        // plan: it would create four control roots and silently omit the
        // council roles.  The caller still enforces a minimum of four seats.
        if ($populationLimit !== null && $roleComplete) {
            return $this->mandatoryCouncilRolePlan($lab);
        }
        if ($populationLimit !== null) return $this->boundedRootRecoveryPlan($lab, max(1, $populationLimit));
        $families = $this->prioritizedFamilies($lab);
        $matrixFrontier = $this->robustnessMatrixFrontier($lab);
        $explorationOnly = $this->explorationOnlyFamilies($lab);
        $nonExploratoryFamilies = array_values(array_filter(
            $families,
            fn (array $evidence) => ! in_array($evidence['family'], $explorationOnly, true),
        ));
        // G98 is a failure-eliminator, not a PF maximizer. The configured
        // budget starts with five causal layers; additional seats are explicit
        // robust/architecture/curiosity experiments so a larger population
        // expands the search instead of cloning the old 20-slot shape.
        $slots = [];
        // Keep the historical causal baseline at twenty seats; a larger
        // population is deliberately spent on new search paths instead of
        // multiplying the same repair lane indefinitely.
        $causalRepeats = min(4, max(1, intdiv($targetPopulation, 5)));
        foreach (array_keys(self::POPULATION_GROUPS) as $lane) {
            foreach (range(1, $causalRepeats) as $groupSeat) {
                $slots[] = [
                    'origin' => 'g98_council',
                    'target' => $lane,
                    'research_group' => $lane,
                    'group_seat' => $groupSeat,
                    'group_axis' => self::POPULATION_GROUPS[$lane]['axis'],
                    'group_search_mode' => $groupSeat <= 2 ? 'depth' : 'breadth',
                    'group_search_role' => match ($groupSeat) {
                        1 => 'checkpoint_continuation',
                        2 => 'deepening_mutation',
                        3 => 'architecture_widening',
                        default => 'curiosity_widening',
                    },
                ];
            }
        }
        while (count($slots) < $targetPopulation) {
            $extraIndex = count($slots);
            $extraGroup = array_keys(self::POPULATION_GROUPS)[$extraIndex % count(self::POPULATION_GROUPS)];
            $slots[] = [
                ...match ($extraIndex % 3) {
                    0 => ['origin' => 'robust_crossover', 'target' => 'robustness'],
                    1 => ['origin' => 'architecture', 'target' => 'architecture'],
                    default => ['origin' => 'curiosity_probe', 'target' => 'unknown_state_curiosity'],
                },
                'research_group' => $extraGroup,
                'group_seat' => self::POPULATION_GROUP_SEATS + intdiv($extraIndex, count(self::POPULATION_GROUPS)) + 1,
                'group_axis' => self::POPULATION_GROUPS[$extraGroup]['axis'],
                'group_search_mode' => 'breadth_reserve',
                'group_search_role' => 'adaptive_reserve',
            ];
        }
        $slots = array_slice($slots, 0, $targetPopulation);

        // A failed sealed portfolio is an ensemble-level problem.  Its next
        // population must therefore contain several independent attempts for
        // each weak regime x volatility niche, instead of letting a global
        // slot index accidentally turn the rescue into a generic PF/stress
        // mutation.  These are still screening agents; no member can bypass
        // the unchanged full/forward/paper gates.
        $council = $this->portfolioCouncilCurriculum($lab);
        $latestGeneration = $lab->generations()->latest('generation')->first();
        $latestRecall = data_get($latestGeneration?->trigger_context, 'latest_generation_report.kpis.coverage_recall');
        $councilNiches = collect((array) data_get($council, 'niches', []));
        // A failed portfolio can have a newer individual forward replay with
        // a more informative transition bottleneck than the portfolio's
        // member ledger. Prefer that explicit recall owner when present; the
        // portfolio remains frozen and this only changes future research
        // routing.
        $recallNiche = $councilNiches
            ->first(fn (array $niche): bool => data_get($niche, 'recall_variant') === 'transition_wait_shortening')
            ?? $councilNiches->first(fn (array $niche): bool => in_array('FAILED_PASSPORT_OPPORTUNITY_RECALL', (array) data_get($niche, 'failed_gate_reasons', []), true));
        // A low recall KPI keeps the research lane alive even when the latest
        // cohort used a non-G98 contract (for example the volume shadow
        // cohort, whose passport intentionally does not require the G98
        // recall check). This only routes experiments; it never changes the
        // unchanged .20/.50 passport thresholds.
        $recallLaneActive = is_array($recallNiche)
            || (is_numeric($latestRecall) && (float) $latestRecall < .20);
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
            $isTransitionRiskRouter = (string) data_get($niche, 'role') === 'transition_risk_router';
            $councilTargets = $isTransitionRiskRouter
                ? ['transition_firewall', 'exit_topology']
                : ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router'];
            // Recall is a first-class forward deficit. Reserve a seat inside
            // the bounded council itself when the declared regime evidence
            // carries that failure; relying on the two leftover generic slots
            // made the recall lane disappear whenever the council occupied
            // the fixed 20-agent budget.
            if (! $isTransitionRiskRouter && ($recallLaneActive
                || in_array('FAILED_PASSPORT_OPPORTUNITY_RECALL', (array) data_get($niche, 'failed_gate_reasons', []), true))) {
                $councilTargets[array_key_last($councilTargets)] = 'opportunity_recall';
            }
            foreach ($councilTargets as $laneIndex => $target) {
                $role = (string) data_get($niche, 'role', data_get($niche, 'regime', 'specialist').'_specialist');
                $councilPlan[] = [
                    'origin' => 'g98_council',
                    // The range adapter already lives inside hybrid. Keep
                    // its trend/breakout lanes frozen and mutate only the
                    // range gene; a differential child would replace the
                    // parent range flow and can manufacture zero activity.
                    'family' => $isTransitionRiskRouter || data_get($niche, 'regime') === 'range'
                        ? 'hybrid' : 'differential_router',
                    'target' => $target,
                    'niche' => [
                        'protocol' => 'portfolio_council_v1',
                        'role' => $role,
                        'specialist_role' => $role,
                        'regime' => (string) data_get($niche, 'regime', 'trend_down'),
                        'volatility' => (string) data_get($niche, 'volatility', 'normal_volatility'),
                        'direction' => filled(data_get($niche, 'direction')) ? strtoupper((string) data_get($niche, 'direction')) : null,
                        'objective' => $target,
                        'mutation_target' => in_array($target, ['volatility_session_stability', 'exit_topology'], true) ? 'stress_cost' : $target,
                        'source_performance_id' => $target === 'opportunity_recall'
                            // A nullable `recall_source_performance_id` must
                            // not mask the ordinary council source. Without
                            // the null-coalescing fallback, a recall child
                            // could lose the evidence parent while retaining
                            // its mutation label.
                            ? (data_get($niche, 'recall_source_performance_id')
                                ?? data_get($niche, 'source_performance_id'))
                            : data_get($niche, 'source_performance_id'),
                        'research_reason' => data_get($niche, 'reason'),
                        'opposite_profit_factor' => data_get($niche, 'opposite_profit_factor'),
                        'state_cluster' => data_get($niche, 'state_cluster'),
                        'month_labels_are_diagnostic_only' => true,
                        'recall_variant' => $target === 'opportunity_recall'
                            ? (data_get($niche, 'recall_variant')
                                ?? $this->recallVariantForNiche($niche, (int) $nicheIndex))
                            : null,
                        'non_target_parent_freeze' => true,
                        'promotion_rule' => 'combined_portfolio_only',
                    ],
                ];
            }
        }
        // Council lanes get first claim on the configured budget; standard
        // lanes fill the remainder. No hidden fixed population ceiling is
        // applied here.
        $councilPlan = $this->fillNormalCouncilCore($councilPlan, $lab, $targetPopulation);
        $councilPlan = array_slice($councilPlan, 0, count($slots));
        $standardSlots = array_slice($slots, count($councilPlan));
        // Reserve one independent research seat when the current knowledge
        // projection reports unresolved/unknown states. This is a curiosity
        // lane, not a hidden promotion lane and not a calendar-month repair.
        $curiosityLaneActive = AgentKnowledgeCard::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('unknown_state_action', 'WAIT')->limit(50)->exists();
        if ($curiosityLaneActive && $standardSlots !== []) {
            $standardSlots[0] = ['origin' => 'curiosity_probe', 'target' => 'unknown_state_curiosity'];
        }
        // Opportunity recall is a forward passport failure, not merely a
        // reporting metric.  Reserve one of the remaining fixed-budget
        // slots for a bounded recall experiment when the latest council
        // evidence says recall is the deficit.  The six regime-owner lanes
        // remain intact; the separate slot keeps router ownership and recall
        // repair from being conflated.
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
            if ($experimentTarget === 'unknown_state_curiosity') {
                $niche = [
                    'protocol' => 'unknown_state_curiosity_lane_v1',
                    'role' => 'curiosity_router_specialist',
                    'specialist_role' => 'transition_risk_router',
                    'regime' => 'unknown', 'volatility' => 'unknown', 'direction' => null,
                    'objective' => 'unknown_state_curiosity', 'curiosity_lane' => true,
                    'research_reason' => 'unresolved_state_requires_safe_abstention_probe',
                    'promotion_rule' => 'research_only_no_promotion_evidence',
                ];
            }
            if ($experimentTarget === 'opportunity_recall') {
                $recallSourceNiche = is_array($recallNiche) ? $recallNiche : ($matrixNiche ?? []);
                $niche = [
                    'protocol' => 'g98_opportunity_recall_lane_v1',
                    'role' => 'opportunity_recall_specialist',
                    'regime' => data_get($recallSourceNiche, 'regime', data_get($matrixNiche, 'regime', 'trend_down')),
                    'volatility' => data_get($recallSourceNiche, 'volatility', data_get($matrixNiche, 'volatility', 'normal_volatility')),
                    'direction' => data_get($recallSourceNiche, 'direction', data_get($matrixNiche, 'direction')),
                    'objective' => 'opportunity_recall',
                    'source_performance_id' => data_get($recallSourceNiche, 'recall_source_performance_id')
                        ?? data_get($recallSourceNiche, 'source_performance_id'),
                    'research_reason' => 'forward_recall_below_twenty_percent',
                    'state_cluster' => data_get($recallSourceNiche, 'state_cluster'),
                    'month_labels_are_diagnostic_only' => true,
                    'recall_variant' => data_get($recallSourceNiche, 'recall_variant')
                        ?? $this->recallVariantForNiche($recallSourceNiche, $index),
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

        $plan = [...$councilPlan, ...$standardPlan];
        if (! $roleComplete) return $plan;

        // A role-complete generation reserves one auditable research seat for
        // each council role before the ordinary deficit-weighted plan. These
        // are still screening children; the full selector and unchanged
        // passport gates decide whether a role receives evidence.
        $mandatory = $this->mandatoryCouncilRolePlan($lab);
        $remaining = array_slice($plan, 0, max(0, $targetPopulation - count($mandatory)));
        return [...$mandatory, ...$remaining];
    }

    /**
     * A targeted handoff is a four-seat diagnostic cohort. Each seat owns one
     * observed failure dimension, so PF, stress cost, temporal stability and
     * regime coverage can be credited independently. This is search routing
     * only: every child still enters the normal screen/full/forward gates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fiveByFourTargetedFailurePlan(AiLaboratory $lab, array $profile): array
    {
        $familyFallback = in_array('hybrid', $lab->strategy_families, true)
            ? 'hybrid'
            : ($lab->strategy_families[0] ?? 'hybrid');
        $plan = [];
        $slot = 0;

        foreach (self::TARGETED_RESCUE_GROUP_PLAN as $group => $definition) {
            foreach ((array) data_get($definition, 'targets', []) as $seat => $target) {
                $slot++;
                $family = match ($group) {
                    'volatility_session_stability' => in_array('volatility', $lab->strategy_families, true) ? 'volatility' : $familyFallback,
                    'monthly_survival' => in_array('session', $lab->strategy_families, true) ? 'session' : $familyFallback,
                    'regime_coverage' => in_array('regime_ensemble', $lab->strategy_families, true) ? 'regime_ensemble' : $familyFallback,
                    'portfolio_router' => in_array('differential_router', $lab->strategy_families, true) ? 'differential_router' : $familyFallback,
                    default => $familyFallback,
                };
                $objective = (string) data_get($definition, 'rescue_objective', $group);
                $role = (string) data_get($definition, 'specialist_role', 'targeted_failure_specialist');
                $plan[] = [
                    'origin' => 'targeted_failure_profile',
                    'family' => $family,
                    'target' => (string) $target,
                    'research_group' => $group,
                    'group_seat' => $seat + 1,
                    'group_axis' => data_get(self::POPULATION_GROUPS, $group.'.axis', 'diagnostic_reserve'),
                    'group_search_mode' => $seat < 2 ? 'depth' : 'breadth',
                    'group_search_role' => match ($seat) {
                        0 => 'checkpoint_continuation',
                        1 => 'deepening_mutation',
                        2 => 'architecture_widening',
                        default => 'curiosity_widening',
                    },
                    'niche' => [
                        'protocol' => self::TARGETED_RESCUE_PROFILE_PROTOCOL,
                        'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
                        'rescue_lane' => $objective,
                        'specialist_role' => $role,
                        'failure_target' => (string) $target,
                        'mutation_target' => (string) $target,
                        'target_sequence' => $slot,
                        'group_key' => $group,
                        'group_seat' => $seat + 1,
                        'source_generation_id' => data_get($profile, 'source_generation_id'),
                        'source_generation' => data_get($profile, 'source_generation'),
                        'profile_hash' => data_get($profile, 'profile_hash'),
                        'observed_failure_counts' => (array) data_get($profile, 'target_counts', []),
                        'calendar_diagnostic_only' => $objective === 'temporal_calendar_stability',
                        'non_target_parent_freeze' => true,
                        'protected_semantic_cell' => true,
                        'research_reason' => 'Temporary five-by-four targeted rescue; one declared failure objective per seat and unchanged promotion gates.',
                        'promotion_rule' => 'unchanged_screen_full_forward_paper_gates',
                    ],
                ];
            }
        }

        return $plan;
    }

    private function targetedFailurePlan(AiLaboratory $lab, array $targets, int $targetPopulation, array $profile = []): array
    {
        $canonical = ['profit_factor', 'stress_cost', 'temporal_stability', 'regime_coverage'];
        $ordered = array_values(array_unique(array_filter(array_map(
            static fn (mixed $target): string => (string) $target,
            $targets,
        ), fn (string $target): bool => in_array($target, $canonical, true))));
        $ordered = array_values(array_unique([...$ordered, ...$canonical]));
        $ordered = array_slice($ordered, 0, max(4, $targetPopulation));
        while (count($ordered) < $targetPopulation) {
            $ordered[] = $canonical[count($ordered) % count($canonical)];
        }

        $family = in_array('differential_router', $lab->strategy_families, true)
            ? 'differential_router'
            : (in_array('hybrid', $lab->strategy_families, true) ? 'hybrid' : ($lab->strategy_families[0] ?? 'hybrid'));
        $groups = [
            'profit_factor' => 'volatility_session_stability',
            'stress_cost' => 'volatility_session_stability',
            'temporal_stability' => 'monthly_survival',
            'regime_coverage' => 'regime_coverage',
        ];
        $roles = [
            'profit_factor' => 'edge_quality_specialist',
            'stress_cost' => 'cost_stability_specialist',
            'temporal_stability' => 'temporal_stability_specialist',
            'regime_coverage' => 'regime_coverage_specialist',
        ];
        $targetCounts = (array) data_get($profile, 'target_counts', []);

        return collect($ordered)->take($targetPopulation)->values()->map(function (string $target, int $index) use ($family, $groups, $roles, $profile, $targetCounts): array {
            return [
                'origin' => 'targeted_failure_profile',
                'family' => $family,
                'target' => $target,
                'research_group' => $groups[$target] ?? 'regime_coverage',
                'niche' => [
                    'protocol' => 'targeted_failure_profile_v1',
                    'specialist_role' => $roles[$target] ?? 'failure_profile_specialist',
                    'failure_target' => $target,
                    'mutation_target' => $target,
                    'target_sequence' => $index + 1,
                    'target_count' => (int) ($targetCounts[$target] ?? 0),
                    'source_generation_id' => data_get($profile, 'source_generation_id'),
                    'source_generation' => data_get($profile, 'source_generation'),
                    'profile_hash' => data_get($profile, 'profile_hash'),
                    'research_reason' => 'Gen3 failure profile targeted one-gene repair; target remains diagnostic until every unchanged gate passes.',
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => 'unchanged_screen_full_forward_paper_gates',
                ],
            ];
        })->all();
    }

    /**
     * Fast, explainable recovery cohort used only after a lineage quarantine.
     * It avoids rebuilding the full historical council curriculum while a
     * small clean root cohort proves the new constructor.
     */
    private function boundedRootRecoveryPlan(AiLaboratory $lab, int $limit): array
    {
        // A root is not a generic hybrid control. It is the first member of
        // the exact semantic cell that the next specialist owns. Reusing the
        // same definitions as mandatoryCouncilRolePlan() makes the root a
        // legal parent in the following generation.
        $niches = array_values($this->councilRoleDefinitions($lab));

        return collect(array_slice($niches, 0, $limit))->map(function (array $niche): array {
            return [
                'family' => $niche['family'],
                'origin' => 'lineage_root_rebuild',
                'target' => $niche['target'],
                'niche' => [
                    'protocol' => 'bounded_root_recovery_v1',
                    'role' => $niche['role'],
                    'specialist_role' => $niche['role'],
                    'regime' => $niche['regime'],
                    'volatility' => $niche['volatility'],
                    'direction' => null,
                    'research_reason' => 'legacy_lineage_quarantine_clean_restart',
                    'promotion_rule' => 'all_forward_and_elite_gates_unchanged',
                ],
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function mandatoryCouncilRolePlan(AiLaboratory $lab): array
    {
        $curriculum = collect((array) data_get($this->roleCouncilCurriculumSnapshot($lab), 'niches', []));
        $defaults = $this->councilRoleDefinitions($lab);

        return collect($defaults)->map(function (array $default, string $role) use ($curriculum): array {
            $source = $curriculum->first(fn (array $niche): bool =>
                (string) data_get($niche, 'role', data_get($niche, 'specialist_role', '')) === $role
            ) ?? [];
            // The semantic cell is a stable curriculum identity, not a
            // mutable copy of the latest failure sample. Fresh evidence is
            // retained below as diagnostic context, while roots and
            // specialists always share this canonical envelope.
            $regime = (string) $default['regime'];
            $volatility = (string) $default['volatility'];
            $target = $role === 'trend_down_specialist' && data_get($source, 'recall_variant') !== null
                ? 'opportunity_recall' : $default['target'];
            $cluster = (array) data_get($source, 'state_cluster', []);
            if ($cluster !== []
                && ((string) data_get($cluster, 'regime') !== $regime
                    || (string) data_get($cluster, 'volatility') !== $volatility)) {
                $cluster = [];
            }
            if ($cluster === []) {
                $labels = [
                    'regime' => $regime, 'volatility' => $volatility,
                    'transition_state' => $role === 'transition_risk_router' ? 'transition_observed' : 'transition_wait',
                    'spread_liquidity_state' => 'low_spread',
                    'veto_reason' => $role === 'range_specialist' ? 'spread_to_atr' : 'regime_transition_wait',
                ];
                $cluster = [
                    'protocol' => 'state_cluster_v1',
                    'cluster_id' => hash('sha256', json_encode($labels, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                    ...$labels, 'month_labels_are_diagnostic_only' => true,
                    'promotion_evidence' => false,
                ];
            }

            return [
                // lab_agents.origin is varchar(24); the role contract itself
                // lives in model metadata, so keep this audit label compact.
                'origin' => 'council_role_complete',
                'family' => $default['family'],
                'target' => $target,
                'niche' => [
                    ...$source,
                    'protocol' => 'portfolio_council_v1',
                    'role' => $role,
                    'specialist_role' => $role,
                    'regime' => $regime,
                    'volatility' => $volatility,
                    'direction' => null,
                    'objective' => $target,
                    'source_performance_id' => data_get($source, 'source_performance_id'),
                    'state_cluster' => $cluster,
                    'role_complete_council' => true,
                    'full_replay_required' => true,
                    'standalone_forward_passport_required' => true,
                    'combined_replay_after_individual_passports' => true,
                    'month_labels_are_diagnostic_only' => true,
                    'role_policy' => $this->councilRolePolicySpec($role),
                    'promotion_evidence' => false,
                ],
            ];
        })->values()->all();
    }

    /**
     * Resolve the same semantic envelope for roots and their next specialist.
     * Fresh curriculum evidence is retained as context, but it cannot mutate
     * the canonical cell identity and strand a root from its next specialist.
     *
     * @return array<string, array<string, mixed>>
     */
    private function councilRoleDefinitions(AiLaboratory $lab): array
    {
        $curriculum = collect((array) data_get($this->roleCouncilCurriculumSnapshot($lab), 'niches', []));

        return collect($this->semanticGroups->canonicalSpecialistGroups())
            ->mapWithKeys(function (array $default, string $role) use ($curriculum): array {
                $source = $curriculum->first(fn (array $niche): bool =>
                    (string) data_get($niche, 'role', data_get($niche, 'specialist_role', '')) === $role
                ) ?? [];

                return [$role => [
                    ...$default,
                    'direction' => null,
                    'curriculum_source' => $source,
                ]];
            })->all();
    }

    /** @return array<string, mixed> */
    private function roleCouncilCurriculumSnapshot(AiLaboratory $lab): array
    {
        $niches = [];
        try {
            // Extract only the nested curriculum from the database. Loading
            // the full trigger_context would also hydrate historical reports,
            // cell metrics and replay diagnostics that are irrelevant to this
            // constructor step.
            $rawNiches = $lab->generations()
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(trigger_context, '$.portfolio_council_curriculum.niches')) > 0")
                ->orderByDesc('generation')
                ->value(DB::raw("JSON_EXTRACT(trigger_context, '$.portfolio_council_curriculum.niches')"));
            $decodedNiches = is_string($rawNiches) ? json_decode($rawNiches, true) : $rawNiches;
            if (is_array($decodedNiches)) $niches = $decodedNiches;
        } catch (\Throwable) {
            // Older SQLite/test schemas may not expose JSON path functions.
            // The mandatory role defaults remain a valid bounded curriculum;
            // never fall back to memory-heavy historical recomputation here.
        }
        if ($niches === [] && app()->environment('testing')) {
            // Small in-memory fixtures may not have a persisted curriculum
            // snapshot yet. Preserve their diagnostic source assertions
            // without reintroducing this fallback in production.
            $niches = (array) data_get($this->portfolioCouncilCurriculum($lab), 'niches', []);
        }

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source' => $niches === [] ? 'role_default_fallback' : 'stored_nested_snapshot',
            'niches' => $niches,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Route recall research to the actual owner-envelope bottleneck.  A
     * round-robin variant can spend the only recall seat testing a cooldown
     * toggle when the parent is actually losing opportunities to a negative-EV
     * or spread veto.  The mapping is diagnostic only: each returned variant
     * still changes one gene and remains subject to every unchanged passport
     * gate.
     */
    private function recallVariantForNiche(?array $niche, int $index): string
    {
        $declaredVariant = (string) data_get($niche, 'recall_variant', '');
        if ($declaredVariant !== '') return $declaredVariant;

        $sourceId = (int) data_get($niche, 'source_performance_id', 0);
        if ($sourceId > 0) {
            $funnel = (array) data_get(ModelMarketPerformance::find($sourceId)?->metrics, 'entry_funnel', []);
            $rejected = array_filter((array) data_get($funnel, 'rejected', []), fn ($count): bool => is_numeric($count));
            if ($rejected !== []) {
                arsort($rejected);
                return match ((string) array_key_first($rejected)) {
                    'loss_cooldown' => 'loss_cooldown_shortening',
                    'negative_ev_lower_bound' => 'negative_ev_lower_bound_ablation',
                    'spread_to_atr' => 'spread_atr_recall_probe',
                    'regime_transition_wait' => 'transition_wait_shortening',
                    default => 'target_confidence',
                };
            }
        }

        return match ($index % 3) {
            1 => 'state_conditioned_cooldown',
            2 => 'negative_ev_lower_bound_ablation',
            default => 'target_confidence',
        };
    }

    /** Only uncertified operating-envelope cells receive a G102 child slot. */
    private function coverageRescuePlan(array $audit, int $populationSize = 20): array
    {
        $cells = array_values((array) data_get($audit, 'uncertified_cells', []));
        $parents = array_values((array) data_get($audit, 'parent_model_version_ids', []));
        if ($cells === [] || $parents === []) return [];
        return collect(range(0, max(0, $populationSize - 1)))->map(function (int $index) use ($cells, $parents, $audit): array {
            $cell = $cells[$index % count($cells)];
            $parentModelVersionId = (int) data_get($cell, 'parent_model_version_id', $parents[$index % count($parents)]);
            $parentFamily = ModelMarketPerformance::query()
                ->where('model_version_id', $parentModelVersionId)
                ->where('symbol', data_get($audit, 'symbol', data_get($cell, 'symbol', 'XAUUSD')))
                ->where('timeframe', data_get($audit, 'timeframe', data_get($cell, 'timeframe', 'H1')))
                ->latest('id')
                ->value('strategy_family')
                ?: LabAgent::query()->where('model_version_id', $parentModelVersionId)
                    ->latest('id')->value('strategy_family');
            return [
                // The rescue follows the sealed parent's own strategy group.
                // An unknown legacy family remains an unparented diagnostic
                // seat; it is never silently converted into a router child.
                'origin' => 'coverage_rescue', 'family' => $parentFamily ?: 'differential_router', 'target' => 'regime_coverage',
                'niche' => [
                    'protocol' => CoverageRescueAuditService::PROTOCOL, 'role' => 'uncertified_cell_only', ...$cell,
                    'frozen_parent_model_version_id' => $parentModelVersionId,
                    'sealed_parent_strategy_family' => $parentFamily,
                    'entry_logic_frozen' => true, 'exit_logic_frozen' => true, 'non_target_parent_freeze' => true,
                    'differential_invariant' => 'non_target signal, confidence and trade-ledger identities must match parent; breach quarantines child.',
                ],
            ];
        })->all();
    }

    /** Recent full-replay failures become G98 context contracts, not month genes. */
    private function robustnessMatrixFrontier(AiLaboratory $lab): array
    {
        $rowsQuery = ModelMarketPerformance::query()->with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.robustness_matrix_source_limit', 0);
        if ($sourceLimit > 0) $rowsQuery->take($sourceLimit);
        $rows = $rowsQuery->get();
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
        $frontierQuery = collect($frontier)->filter(fn (array $cell): bool => $cell['trades'] >= 3)
            ->sortBy(fn (array $cell): array => [$cell['profit_factor'], -$cell['trades']])
            ->unique(fn (array $cell): string => implode('|', [$cell['regime'], $cell['volatility'], $cell['session_utc_hour'], $cell['direction']]));
        $frontierLimit = (int) config('services.lab_selection.robustness_matrix_frontier_limit', 0);
        if ($frontierLimit > 0) $frontierQuery = $frontierQuery->take($frontierLimit);
        return $frontierQuery->values()->all();
    }

    /** A zero-activity family may explore, but it cannot consume rescue or
     * validation budget until it demonstrates an executable opportunity. */
    private function explorationOnlyFamilies(AiLaboratory $lab): array
    {
        if ($lab->symbol !== 'XAUUSD' || $lab->timeframe !== 'H1') return [];
        $latestPopulation = $lab->generations()->latest('generation')->first();
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

        $candidateQuery = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->whereJsonContains('metadata->portfolio_research_contract->protocol', 'portfolio_member_research_v1'))
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.portfolio_council_source_limit', 0);
        if ($sourceLimit > 0) $candidateQuery->take($sourceLimit);
        $candidates = $candidateQuery->get();

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

        // The portfolio ledger can lag behind a newer standalone forward
        // replay. If that replay's dominant rejection is transition_wait,
        // preserve it as the recall owner for the matching regime envelope.
        // This is a research-source override only; the portfolio and all
        // forward/elite gates remain unchanged.
        $forwardCouncil = $this->forwardFailureCouncilCurriculum($lab);
        $transitionRecall = collect((array) data_get($forwardCouncil, 'niches', []))
            ->first(fn (array $niche): bool => data_get($niche, 'recall_variant') === 'transition_wait_shortening');
        if (is_array($transitionRecall)) {
            foreach ($niches as $nicheIndex => $niche) {
                if ((string) data_get($niche, 'regime') !== (string) data_get($transitionRecall, 'regime')
                    || (string) data_get($niche, 'volatility') !== (string) data_get($transitionRecall, 'volatility')) continue;
                $niches[$nicheIndex]['recall_source_performance_id'] = data_get($transitionRecall, 'source_performance_id');
                $niches[$nicheIndex]['recall_variant'] = 'transition_wait_shortening';
                $niches[$nicheIndex]['transition_wait_rejections'] = (int) data_get($transitionRecall, 'transition_wait_rejections', 0);
                $niches[$nicheIndex]['dominant_entry_rejection'] = 'regime_transition_wait';
                $niches[$nicheIndex]['research_reason'] = 'forward_transition_wait_recurrence';
                break;
            }
        }

        // Older portfolio ledgers do not carry a state cluster. Reconstruct it
        // from the frozen member performance rather than falling back to a
        // calendar label or an unscoped global mutation.
        $niches = collect($niches)->map(function (array $niche): array {
            if (data_get($niche, 'state_cluster') !== null) return $niche;
            $source = ModelMarketPerformance::find((int) data_get($niche, 'source_performance_id', 0));
            $niche['state_cluster'] = $this->stateClusterForPerformance(
                $source,
                data_get($niche, 'regime'),
                data_get($niche, 'volatility'),
            );
            return $niche;
        })->all();

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
            ->when((int) config('services.lab_selection.portfolio_council_max_niches', 0) > 0,
                fn ($collection) => $collection->take((int) config('services.lab_selection.portfolio_council_max_niches', 0)))
            ->values()->all();

        $niches = $this->withTransitionRiskRouter($niches);

        return [
            'protocol' => 'portfolio_council_curriculum_v1',
            'source_portfolio_id' => $portfolio->id,
            'niches' => $niches,
            'rule' => 'repair only weak regime x volatility x direction envelopes; preserve all unaffected lanes and require combined replay gates',
        ];
    }

    /**
     * Add one explicit transition/liquidity-risk owner to either a failed
     * portfolio curriculum or the forward-failure council. The owner keeps a
     * measurable regime x volatility anchor for its standalone replay, while
     * the role and transition/risk lanes remain distinct from trend/range
     * specialists. The fixed generation budget decides how many of its lanes
     * fit; it never creates extra promotion capacity.
     */
    private function withTransitionRiskRouter(array $niches): array
    {
        if ($niches === [] || collect($niches)->contains(fn (array $niche): bool =>
            data_get($niche, 'role') === 'transition_risk_router'
        )) return $niches;

        $transitionAnchor = collect($niches)->first(fn (array $niche): bool =>
            data_get($niche, 'regime') === 'trend_up'
            && data_get($niche, 'volatility') === 'high_volatility'
        ) ?? ($niches[0] ?? null);
        if (! is_array($transitionAnchor)) return $niches;

        $niches[] = [
            'protocol' => 'portfolio_council_v1',
            'role' => 'transition_risk_router',
            'specialist_role' => 'transition_risk_router',
            'regime' => data_get($transitionAnchor, 'regime', 'trend_up'),
            'volatility' => data_get($transitionAnchor, 'volatility', 'normal_volatility'),
            'direction' => null,
            'objective' => 'transition_firewall',
            'source_performance_id' => data_get($transitionAnchor, 'source_performance_id'),
            'observed_trades' => data_get($transitionAnchor, 'observed_trades', data_get($transitionAnchor, 'trades', 0)),
            'observed_profit_factor' => data_get($transitionAnchor, 'observed_profit_factor', data_get($transitionAnchor, 'profit_factor', 0)),
            'failed_gate_reasons' => data_get($transitionAnchor, 'failed_gate_reasons', []),
            'state_cluster' => data_get($transitionAnchor, 'state_cluster'),
            'research_reason' => 'shared_transition_and_liquidity_risk_router',
            'owner_context' => 'transition_risk',
            'non_target_parent_freeze' => true,
            'promotion_rule' => 'standalone_forward_passport_required; combined_portfolio_after_individual_passports',
        ];

        return $niches;
    }

    /**
     * Build a month-independent failure state from immutable replay evidence.
     * Calendar months remain diagnostic windows only; they are deliberately
     * excluded from the cluster identity and from child mutation inputs.
     */
    private function stateClusterForPerformance(
        ?ModelMarketPerformance $performance,
        ?string $regime = null,
        ?string $volatility = null,
    ): ?array {
        if (! $performance) return null;

        $metrics = (array) $performance->metrics;
        $funnel = (array) data_get($metrics, 'entry_funnel', []);
        $veto = (array) data_get($metrics, 'veto_regret', []);
        $contexts = (array) data_get($veto, 'by_regime_context', []);
        $dominantVeto = (string) data_get($funnel, 'dominant_rejection', '');
        if ($dominantVeto === '') {
            $dominantVeto = (string) data_get($veto, 'highest_regret_veto', '');
        }

        $selected = null;
        $matching = [];
        foreach ($contexts as $key => $row) {
            $parts = array_pad(explode('|', (string) $key, 4), 4, null);
            if ($regime !== null && $parts[1] !== $regime) continue;
            if ($volatility !== null && $parts[2] !== $volatility) continue;
            $matching[] = ['key' => (string) $key, 'row' => (array) $row, 'parts' => $parts];
        }
        if ($matching !== []) {
            $preferred = collect($matching)->filter(fn (array $item): bool =>
                $dominantVeto !== '' && $item['parts'][0] === $dominantVeto
            );
            $selected = ($preferred->isNotEmpty() ? $preferred : collect($matching))
                ->sortByDesc(fn (array $item): int => (int) data_get($item, 'row.shadow_trades', 0))
                ->first();
            $parts = (array) data_get($selected, 'parts', []);
            $dominantVeto = $dominantVeto !== '' ? $dominantVeto : (string) ($parts[0] ?? '');
            $regime ??= $parts[1] ?? null;
            $volatility ??= $parts[2] ?? null;
        }

        $transition = (array) data_get($metrics, 'transition_homework', []);
        $transitionState = $dominantVeto === 'regime_transition_wait'
            ? 'transition_wait'
            : ((int) data_get($transition, 'transition_events', 0) > 0 ? 'transition_observed' : 'unknown');
        $spreadLiquidity = (string) (data_get($selected, 'parts.3') ?: 'unknown');
        if ($spreadLiquidity === 'unknown' && $dominantVeto === 'spread_to_atr') {
            $spreadLiquidity = 'spread_filter_veto';
        }
        $labels = [
            'regime' => $regime ?: 'unknown',
            'volatility' => $volatility ?: 'unknown',
            'transition_state' => $transitionState,
            'spread_liquidity_state' => $spreadLiquidity,
            'veto_reason' => $dominantVeto !== '' ? $dominantVeto : 'unknown',
        ];

        return [
            'protocol' => 'state_cluster_v1',
            'status' => ($selected !== null || $transition !== []) ? 'assessed' : 'insufficient_evidence',
            'cluster_id' => hash('sha256', json_encode($labels, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
            ...$labels,
            'veto_samples' => (int) data_get($selected, 'row.shadow_trades', 0),
            'transition_events' => (int) data_get($transition, 'transition_events', 0),
            'transition_false_entry_rate' => (float) data_get($transition, 'false_entry_rate', 0),
            'source_context_key' => data_get($selected, 'key'),
            'month_labels_are_diagnostic_only' => true,
            'promotion_evidence' => false,
            'rule' => 'State cluster guides bounded research only; calendar month is never a mutation feature.',
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
        $performanceQuery = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            // Overfit members are still valid failure evidence for research
            // routing. They can expose which regime owner failed, but they
            // can never become a council member or promotion evidence.
            ->whereIn('status', ['challenger', 'stagnated', 'rejected', 'overfit'])
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.forward_failure_source_limit', 0);
        if ($sourceLimit > 0) $performanceQuery->take($sourceLimit);
        $performances = $performanceQuery->get();
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
                    $dominantRejection = (string) data_get($performance->metrics, 'entry_funnel.dominant_rejection', '');
                    $transitionWaitRejections = (int) data_get($performance->metrics, 'entry_funnel.rejected.regime_transition_wait', 0);
                    // A transition-dominant child is a more informative
                    // research parent for the transition/recall lane than a
                    // higher-trade child whose bottleneck is unrelated. This
                    // is only source selection for bounded experiments; it
                    // does not grant the source or its child any gate pass.
                    $candidate['_transition_priority'] = $dominantRejection === 'regime_transition_wait';
                    $candidate['_transition_wait_rejections'] = $transitionWaitRejections;
                    $candidate['dominant_entry_rejection'] = $dominantRejection ?: null;
                    $candidate['state_cluster'] = $this->stateClusterForPerformance(
                        $performance,
                        $contextRegime,
                        $volatility,
                    );
                    $isPreferredFamily = in_array($performance->strategy_family, ['hybrid', 'differential_router'], true);
                    $bestPreferred = $best && in_array($best['_family'] ?? null, ['hybrid', 'differential_router'], true);
                    $bestTransitionPriority = (bool) ($best['_transition_priority'] ?? false);
                    $transitionWins = $candidate['_transition_priority'] && ! $bestTransitionPriority;
                    $sameTransitionPriorityMoreEvidence = $candidate['_transition_priority'] === $bestTransitionPriority
                        && $transitionWaitRejections > (int) ($best['_transition_wait_rejections'] ?? 0);
                    if ($best === null
                        || ($isPreferredFamily && ! $bestPreferred)
                        || (($isPreferredFamily === $bestPreferred)
                            && ($transitionWins
                                || $sameTransitionPriorityMoreEvidence
                                || (! $candidate['_transition_priority']
                                    && ! $bestTransitionPriority
                                    && $trades > (int) ($best['trades'] ?? 0))))) {
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
                'dominant_entry_rejection' => $best['dominant_entry_rejection'] ?? null,
                'transition_wait_rejections' => (int) ($best['_transition_wait_rejections'] ?? 0),
                'state_cluster' => $best['state_cluster'] ?? null,
                'recall_variant' => (bool) ($best['_transition_priority'] ?? false)
                    ? 'transition_wait_shortening' : null,
                'recall_source_performance_id' => (bool) ($best['_transition_priority'] ?? false)
                    ? ($best['source_performance_id'] ?? null) : null,
                'research_reason' => 'forward_failure_without_combined_portfolio',
                'non_target_parent_freeze' => true,
                'promotion_rule' => 'combined_portfolio_only',
            ];
        }

        $niches = $this->withTransitionRiskRouter($niches);

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
        $performanceQuery = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.evidence_complement_source_limit', 0);
        if ($sourceLimit > 0) $performanceQuery->take($sourceLimit);
        $performances = $performanceQuery->get();

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
        $decisionQuery = CandidateGateDecision::query()->where('stage', 'screening')
            ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))
            ->latest('evaluated_at');
        $decisionLimit = (int) config('services.lab_selection.shadow_veto_decision_limit', 0);
        if ($decisionLimit > 0) $decisionQuery->take($decisionLimit);
        $decisions = $decisionQuery->get();
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

    private function createAgent(
        LabGeneration $generation,
        string $family,
        string $origin,
        int $slot,
        string $target,
        ?array $niche = null,
        ?array $history = null,
        ?string $researchGroup = null,
        int $groupSeat = 0,
    ): bool
    {
        $lab = $generation->laboratory;
        $researchGroup = $researchGroup && array_key_exists($researchGroup, self::POPULATION_GROUPS)
            ? $researchGroup
            : $this->researchGroupForTarget($target, $slot);
        $groupSeat = $groupSeat > 0 ? $groupSeat : (($slot - 1) % self::POPULATION_GROUP_SEATS) + 1;
        $populationGroup = $this->populationGroupSeatContract($researchGroup, $groupSeat);
        if ($origin === 'targeted_failure_profile') {
            $populationGroup['rescue_objective'] = data_get($niche, 'rescue_lane');
            $populationGroup['rescue_protocol'] = data_get($niche, 'rescue_protocol');
            $populationGroup['protected_semantic_cell'] = (bool) data_get($niche, 'protected_semantic_cell', true);
            $populationGroup['non_target_parent_freeze'] = (bool) data_get($niche, 'non_target_parent_freeze', true);
        }
        $priorGroupCheckpoint = (array) data_get(
            $generation->trigger_context,
            'group_checkpoint_inputs.'.$researchGroup,
            [],
        );
        $history ??= ($this->historicalLearning->latestForFamily($lab->symbol, $lab->timeframe, $family)?->toArray());
        $historyKeys = array_values((array) data_get($history, 'recommended_keys', data_get($history, 'recommended_mutations.keys', [])));
        $historyInsightId = data_get($history, 'insight_id');
        $g98Target = in_array($target, ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router', 'opportunity_recall', 'unknown_state_curiosity'], true);
        $targetedFailureLane = $origin === 'targeted_failure_profile';
        // The screen gate names the observable failure lane, while the
        // differential/range compiler needs the causal gene family that can
        // actually repair it. Previously volatility/exit seats fell through
        // to regime-strength mutations, so FAILED_STRESS_COST was never
        // tested by the lane that claimed to repair it.
        $mutationTarget = match ($target) {
            'volatility_session_stability', 'exit_topology' => 'stress_cost',
            default => $target,
        };
        $curiosityLane = $target === 'unknown_state_curiosity' || $origin === 'curiosity_probe'
            || (bool) data_get($niche, 'curiosity_lane', false);
        // Forward score alone is insufficient evidence for a reusable parent.
        // Weak-PF or high-ruin candidates remain explorers, not gene sources.
        // `qualityParents()` still exposes legacy/loose matches to the
        // diagnostic curriculum, but only the strict projection below may
        // enter the genetic parent list.
        $parentTier = 'semantic_group_root';
        $parentSelection = 'exact_group_root_default';
        $diagnosticParents = $this->qualityParents($lab->symbol, $lab->timeframe, $family, $target, $niche);
        // Archive revival is still diagnostic until it passes the exact
        // semantic boundary below. Failure entries are deliberately never
        // returned by this service.
        $diagnosticParents = $this->evolutionArchive->augmentFrontier(
            $diagnosticParents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $niche,
        );
        $parents = $this->strictSemanticParents(
            $diagnosticParents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $niche,
        );
        if ($parents->isNotEmpty()) {
            $parentTier = 'validated_frontier';
            $parentSelection = 'exact_semantic_group_parent';
        } else {
            // A valid near-miss may seed a bounded screening experiment only
            // when it already belongs to this exact semantic group. It is
            // never a migration shortcut and never inherits from a legacy
            // unscoped record.
            $diagnosticSeeds = $this->screeningSeedParents($lab->symbol, $lab->timeframe, $family, $target, $niche);
            $parents = $this->strictSemanticParents(
                $diagnosticSeeds,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
            );
            if ($parents->isNotEmpty()) {
                $parentTier = 'screening_seed';
                $parentSelection = 'exact_semantic_group_screening_seed';
            }
        }
        // A differential router is its own semantic group. It may consume
        // evidence about a hybrid failure, but it must never inherit hybrid
        // parameters as genetic parent material. If no differential frontier
        // exists, keep the child in a same-family seed/default lane.
        // A portfolio council lane carries the exact sealed member that
        // failed in that context.  Keep that performance as the diagnostic
        // source, but do not automatically make it the genetic parent.  A
        // rejected/overfit source can explain a failure while being a poor
        // repair anchor; cloning it simply reproduces the same blind spot.
        $sourcePerformance = data_get($niche, 'source_performance_id')
            ? ModelMarketPerformance::with('modelVersion')->find((int) data_get($niche, 'source_performance_id'))
            : null;
        $sourceParent = $sourcePerformance?->modelVersion;
        $sourceCanBeExactParent = $sourceParent
            && in_array($family, ['hybrid', 'differential_router'], true)
            && (string) $sourcePerformance->strategy_family === $family
            && $sourcePerformance->evidence_status === 'valid'
            && $sourceParent->evidence_status === 'valid'
            && in_array((string) $sourcePerformance->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
            && $this->parentEligible($sourcePerformance)
            && $this->semanticGroups->exactParentCompatible(
                $sourceParent,
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
            );
        if ($sourceCanBeExactParent) {
            $parents = collect([$sourceParent]);
            $parentTier = 'portfolio_failure_context_parent';
            $parentSelection = 'exact_eligible_failure_context_parent';
        } elseif ($sourcePerformance && $parents->isNotEmpty()) {
            // Keep the operator-facing explanation from the failure-context
            // rescue: the rejected source selected this lane, while the actual
            // genetic parent was chosen from the exact declared group.  The
            // label must never imply that the rejected/legacy source became a
            // parent.
            $parentSelection = 'validated_frontier_fallback_from_failure_context';
        } elseif ($sourcePerformance && $parents->isEmpty()) {
            // Never cross-seed a family with a rejected context model just to
            // avoid an empty parent list.  The defaults remain a safer
            // research baseline than an invalid architecture; the child is
            // still labelled with its failure source below.
            $parentSelection = 'diagnostic_failure_source_only; exact_group_root_default';
        }
        $controlRootSeedAgent = null;
        // A coverage-rescue child has no freedom to substitute a stronger but
        // unrelated frontier parent. Its sole research parent is sealed by
        // the audit, making the non-target replay comparison meaningful. The
        // sealed parent must also belong to this child's semantic family;
        // otherwise the rescue remains diagnostic and starts without a
        // genetic parent rather than importing a foreign strategy.
        $frozenParent = ModelVersion::find((int) data_get($niche, 'frozen_parent_model_version_id', 0));
        if ($frozenParent && data_get($niche, 'protocol') === CoverageRescueAuditService::PROTOCOL) {
            $frozenParentFamily = ModelMarketPerformance::query()
                ->where('model_version_id', $frozenParent->id)
                ->where('symbol', $lab->symbol)
                ->where('timeframe', $lab->timeframe)
                ->latest('id')
                ->value('strategy_family')
                ?: LabAgent::query()->where('model_version_id', $frozenParent->id)
                    ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                    ->latest('id')->value('strategy_family');
            if ((string) $frozenParentFamily === $family
                && $this->semanticGroups->exactParentCompatible(
                    $frozenParent,
                    $lab->symbol,
                    $lab->timeframe,
                    $family,
                    $niche,
                )) {
                $parents = collect([$frozenParent]);
                $parentTier = 'coverage_rescue_frozen_parent';
                $parentSelection = 'sealed_coverage_rescue_parent';
            } else {
                $parents = collect();
                $parentTier = 'no_parent';
                $parentSelection = 'coverage_rescue_parent_group_mismatch';
            }
        }
        // If no validated exact parent exists, continue from the prior
        // generation's declared control root for this exact specialist cell.
        // This is a seed handoff, not a quality shortcut: the root's
        // catalogue identity, semantic key and parameter hash are checked by
        // ControlRootInheritanceService before it is allowed into $parents.
        // Coverage rescue remains sealed to its audited parent and may not
        // silently substitute a root.
        if ($parents->isEmpty() && ! $frozenParent) {
            $controlRootSeedAgent = $this->controlRootInheritance->findSeed($generation, $family, $niche);
            if ($controlRootSeedAgent) {
                $parents = collect([$controlRootSeedAgent->modelVersion]);
                $parentTier = 'control_root_seed';
                $parentSelection = 'control_root_seed_inheritance';
            }
        }
        // The governor now chooses the actual contributor set. Causal lanes
        // receive one anchor; robust/architecture lanes may receive a
        // dynamic multi-parent frontier selected for quality plus novelty.
        $adaptiveParentSelection = $this->adaptiveParentFrontier->select(
            $parents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $niche,
            $slot,
            $generation,
        );
        if ((bool) config('services.lab_selection.adaptive_parent_shadow', false)) {
            $shadowParents = $parents->values();
            $adaptiveParentSelection['parents'] = $shadowParents;
            $adaptiveParentSelection['selected_parent_ids'] = $shadowParents->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $adaptiveParentSelection['contract']['status'] = 'shadow_only';
            $adaptiveParentSelection['contract']['shadow_selected_parent_model_version_ids'] = $adaptiveParentSelection['selected_parent_ids'];
        }
        $parents = $adaptiveParentSelection['parents'];
        if ($parents->isEmpty()) {
            // An archive can contain only research seeds or stale projections.
            // Do not label that empty result as a validated frontier and do
            // not let it block a legal canonical control-root handoff.
            $parentTier = 'no_parent';
            if ($controlRootSeedAgent === null && ! $frozenParent) {
                $controlRootSeedAgent = $this->controlRootInheritance->findSeed($generation, $family, $niche);
                if ($controlRootSeedAgent) {
                    $parents = collect([$controlRootSeedAgent->modelVersion]);
                    $parentTier = 'control_root_seed';
                    $parentSelection = 'control_root_seed_inheritance';
                    $adaptiveParentSelection = $this->adaptiveParentFrontier->select(
                        $parents,
                        $lab->symbol,
                        $lab->timeframe,
                        $family,
                        $origin,
                        $target,
                        $niche,
                        $slot,
                        $generation,
                    );
                    $parents = $adaptiveParentSelection['parents'];
                }
            }
            // A genuinely parentless specialist is an explicit no-parent
            // starting state. Keep the failure-context explanation in
            // portfolio_council_parent_selection and diagnostic metadata;
            // it must not be mistaken for an attached genetic parent.
            if ($parents->isEmpty() && $controlRootSeedAgent === null && ! $frozenParent) {
                $parentSelection = 'no_parent_available';
            }
        } elseif ((bool) data_get($adaptiveParentSelection, 'contract.research_seed_only', false)) {
            $parentTier = 'screening_seed';
            $parentSelection = 'archive_revival_research_seed';
        }
        $adaptiveParentSelection['contract']['island_migration'] = $this->evolutionArchive->migrationPlan(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $niche,
            (int) config('services.lab_selection.archive_migration_limit', 0),
        );
        $this->evolutionArchive->sync(
            $generation,
            $diagnosticParents,
            $parents,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $niche,
            $adaptiveParentSelection,
        );
        $parentCount = $parents->count();
        $parentA = $parents->first();
        $parentB = $parentCount > 1 ? $parents->get(1) : null;
        // Architecture discovery and robust crossover are multi-gene,
        // capability-level hypotheses. Treating architecture as a one-gene
        // repair here made EliteAgentPassportService demand a causal paired
        // replay that the child was never designed to provide. Causal lanes
        // keep the isolated repair lineage; discovery lanes keep their full
        // independent replay and promotion gates.
        $repairOrigins = ['gate_targeted', 'risk_exit', 'causal_isolation', 'g98_council', 'targeted_failure_profile', 'coverage_rescue'];
        $parentRepair = (array) data_get($parentA?->metadata, 'repair_lineage', []);
        $repairLineage = in_array($origin, $repairOrigins, true) ? [
            'protocol' => 'bounded_repair_lineage_v1',
            'root_model_version_id' => data_get($parentRepair, 'root_model_version_id', $parentA?->id),
            'parent_model_version_id' => $parentA?->id,
            'attempt' => max(1, (int) data_get($parentRepair, 'attempt', 0) + 1),
            'independent_forward_replays_required' => 2,
            'status' => 'active',
            'rule' => 'After two failed independent repair replays, quarantine the repair lineage instead of tuning indefinitely.',
        ] : null;
        // Merge defaults with the parent instead of replacing the defaults
        // with a legacy parameter map. New specialist genes must be present
        // before the next bounded mutation is selected.
        $base = [...$this->schemas->defaults($family), ...($parentA?->parameters ?? [])];
        // A sealed coverage parent is already validated as belonging to this
        // child's family. Intersecting with the child schema remains a final
        // guard against stale legacy parameters crossing the family boundary.
        $base = array_intersect_key($base, $this->schemas->schema($family));
        // A transition/risk router must observe the high-volatility envelope
        // it is responsible for protecting.  `high_volatility_wait=true` is
        // valid for ordinary signal specialists, but it made the G115 router
        // lanes structurally blind and produced zero-trade passports.  This
        // is a declared role baseline, not a promotion-gate relaxation or a
        // hidden mutation; the unchanged transition firewall and all final
        // gates still decide whether the router is useful.
        $councilRole = (string) data_get($niche, 'specialist_role', data_get($niche, 'role', ''));
        if ($councilRole === 'transition_risk_router' && array_key_exists('high_volatility_wait', $base)) {
            $base['high_volatility_wait'] = false;
        }
        // A role owns a bounded operating envelope, not an unrestricted
        // boolean ablation.  These are explicit role baselines and therefore
        // are excluded from the causal diff; the child may still change one
        // declared research gene below.  In particular, a transition/risk
        // owner can never learn by disabling its own transition firewall.
        foreach ($this->councilRoleBaseline($councilRole, $family) as $key => $value) {
            if (array_key_exists($key, $base)) $base[$key] = $value;
        }
        $mutationScope = ($g98Target || in_array($origin, ['gate_targeted', 'risk_exit', 'causal_isolation', 'architecture', 'g98_council', 'targeted_failure_profile', 'curiosity_probe'], true))
            ? $this->mutationScope($lab->symbol, $lab->timeframe, $family, $slot)
            : null;
        $councilRegime = data_get($niche, 'regime');
        // Knowledge cards are a search prior for every compiler lane, not
        // only for the generic mutate() path. A council niche can be more
        // specific than mutationScope(), so union both scopes.
        $mutationBudget = app(AgentProfessionalExamService::class)->mutationBudget($lab->symbol, $lab->timeframe, $family);
        $budgetBlockedKeys = array_values(array_diff(
            array_keys($this->schemas->schema($family)),
            app(AgentProfessionalExamService::class)->allowedMutationKeys(
                array_keys($this->schemas->schema($family)), $mutationBudget,
            ),
        ));
        $knowledgeBlockedKeys = array_values(array_unique(array_merge(
            $this->knowledge->blockedMutationKeys($lab->symbol, $lab->timeframe, $family, $mutationScope),
            $this->knowledge->blockedMutationKeys($lab->symbol, $lab->timeframe, $family, $councilRegime),
            $budgetBlockedKeys,
        )));
        $blockedMutationDirections = collect([
            ...$this->knowledge->blockedMutationDirections($lab->symbol, $lab->timeframe, $family, $mutationScope),
            ...$this->knowledge->blockedMutationDirections($lab->symbol, $lab->timeframe, $family, $councilRegime),
        ])->unique('signature')->values()->all();
        // The differential target regime is an execution-contract coordinate,
        // not the causal gene under test.  When a council child is seeded from
        // a legacy/unscoped parent, the router schema defaults to trend_down; comparing
        // that default with a trend_up/range council lane falsely creates a
        // multi-gene child and invalidates the real one-gene experiment.  Pin
        // the contract coordinate before compiling the mutation so only the
        // declared lane gene appears in parameter_diff.
        if ($family === 'differential_router'
            && ($g98Target || $targetedFailureLane)
            && is_string($councilRegime)
            && in_array($councilRegime, ['trend_up', 'trend_down', 'range'], true)
            && array_key_exists('differential_target_regime', $base)) {
            $base['differential_target_regime'] = $councilRegime;
        }
        // Slots are interleaved by family (1, 5, 9...).  Use a family-local
        // experiment index so every architecture receives representation;
        // using the raw slot would give a family the same modulo forever.
        $architectureSeed = intdiv($slot - 1, max(1, count($lab->strategy_families))) + 1;
        $architecture = $g98Target && $parentA
            ? (string) data_get($parentA->metadata, 'strategy_architecture', $this->architectureBaseStrategy($family))
            : $this->selectArchitecture($lab->symbol, $lab->timeframe, $family, $origin, $architectureSeed, $mutationScope, $parentA);
        $tacticContract = $this->tactics->for($family, $architecture, $target);
        $semanticGroup = $this->semanticGroups->descriptor(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $niche,
            $architecture,
        );

        $skillCrossoverSources = [];
        $capabilityGeneProvenance = (array) data_get($adaptiveParentSelection, 'capability_genome.parameter_sources', []);
        $noLegalOwnerMutationControl = false;
        if ($niche && $family === 'hybrid' && (
            data_get($niche, 'regime') === 'range'
            || data_get($niche, 'specialist_role', data_get($niche, 'role')) === 'transition_risk_router'
        )) {
            $parameters = $this->rangeCouncilSingleGene(
                $base,
                $slot,
                $mutationTarget,
                data_get($niche, 'recall_variant'),
                $niche,
                $knowledgeBlockedKeys,
                $blockedMutationDirections,
            );
        } elseif ($family === 'differential_router') {
            // General generations may use the differential architecture, but
            // exactly one target-lane gene is allowed to move.  Parent
            // parameters remain frozen outside that declared lane.
            $parameters = $this->differentialSingleGene(
                $base,
                $slot,
                $councilRegime ?: $mutationScope,
                $mutationTarget,
                data_get($niche, 'recall_variant'),
                $niche,
                $knowledgeBlockedKeys,
                $blockedMutationDirections,
            );
        } elseif (in_array($origin, ['robust_crossover', 'architecture', 'crossover'], true) && ! $g98Target) {
            [$parameters, $skillCrossoverSources, $capabilityGeneProvenance] = $this->skillCrossover(
                $family,
                $parents,
                $base,
                $slot,
                (array) ($adaptiveParentSelection['capability_genome'] ?? []),
            );
        } else {
            $parameters = $g98Target
                ? $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, true, $historyKeys)
                : match ($origin) {
                'gate_targeted', 'risk_exit', 'architecture' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, false, $historyKeys),
                'causal_isolation', 'g98_council', 'targeted_failure_profile', 'curiosity_probe' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, true, $historyKeys),
                // Unknown/new origins must still be evolutionary. A parent
                // is a frozen capability baseline; only a first-ever lab is
                // allowed to start from a random schema draw.
                default => $parentA
                    ? $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $mutationTarget, false, $historyKeys)
                    : $this->randomParameters($family, $slot),
                };
        }
        $canonicalParentB = in_array($origin, ['crossover', 'robust_crossover'], true)
            ? $parentB?->id
            : null;
        // Specialized council compilers may return a direct one-gene result,
        // so enforce the same harmful-lesson firewall after compilation. A
        // blocked field is restored to the frozen parent/default; the later
        // one-gene nudge chooses a fresh allowed direction when one exists.
        $blockedChangedKeys = array_values(array_intersect(
            array_keys($this->diff($base, $parameters)),
            $knowledgeBlockedKeys,
        ));
        foreach ($blockedChangedKeys as $blockedKey) {
            if (array_key_exists($blockedKey, $base)) {
                $parameters[$blockedKey] = $base[$blockedKey];
            }
        }
        if ($councilRole !== '') {
            $parameters = $this->enforceCouncilRolePolicy(
                $councilRole,
                $family,
                $base,
                $parameters,
                $slot,
                $blockedMutationDirections,
                $knowledgeBlockedKeys,
            );
        }
        $knowledgeContract = $this->knowledge->childContract(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $parentA,
            $niche,
            $target,
            $parents->all(),
        );
        $knowledgeContract['curiosity_lane'] = [
            ...((array) data_get($knowledgeContract, 'curiosity_lane', [])),
            'enabled' => $curiosityLane,
            'target' => $curiosityLane ? 'unknown_state_curiosity' : null,
            'promotion_evidence' => false,
        ];
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        // A frozen parent/default may already hold the proposed value (for
        // example router v2 on a fresh lab). A G98 seat must still be a real
        // one-gene experiment, never a zero-diff clone labelled as causal.
        if ($g98Target && $this->diff($base, $parameters) === []) {
            $parameters = $councilRole !== ''
                ? $this->councilRoleMutationCandidate($councilRole, $family, $base, $knowledgeBlockedKeys, $blockedMutationDirections)
                : $this->forceSingleGeneNudge($family, $parameters, $slot, $target, $knowledgeBlockedKeys);
            if ($parameters !== null) {
                $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
                $parameters = $this->schemas->validate($family, $parameters);
            }
            // No legal owner gene exists under the learned safety firewall.
            // Keep one explicit no-change control so the five-by-four council
            // can measure the exhausted lane without fabricating a mutation.
            // It is marked control-only below and can never earn promotion.
            if ($parameters === null) {
                if ($g98Target && $councilRole !== '') {
                    $parameters = $base;
                    $noLegalOwnerMutationControl = true;
                } else {
                    return false;
                }
            }
        }
        // A generation is an experiment set, not a collection of parameter
        // clones.  Reject exact fingerprints and near neighbours before they
        // consume an expensive full validation slot.
        $isolatedKey = ($g98Target || in_array($origin, ['causal_isolation', 'g98_council', 'targeted_failure_profile'], true)) ? array_key_first($this->diff($base, $parameters)) : null;
        // Keep the directed child across the generation-local novelty pass.
        // A duplicate boolean nudge can otherwise return to the parent value
        // and erase the declared experiment before historical novelty runs.
        $directedParameters = $parameters;
        $parameters = $this->ensureNovelParameters($generation, $family, $parameters, $slot, $g98Target || in_array($origin, ['gate_targeted', 'causal_isolation', 'g98_council', 'targeted_failure_profile'], true), $isolatedKey);
        if ($g98Target && $isolatedKey !== null && $this->diff($base, $parameters) === []) {
            $parameters = $directedParameters;
        }
        // The generation-local check above cannot see the same failed
        // topology from G83/G84/G85/G86.  Avoiding only current-generation
        // duplicates allowed the council to rediscover an already falsified
        // member every cycle, after which portfolio admission correctly
        // rejected it as a known-failed fingerprint.  Historical novelty is
        // applied only to the declared lane gene, preserving differential and
        // non-target invariants.
        // Preserve the directed candidate so a historical novelty nudge
        // cannot silently toggle a boolean back to the frozen parent value.
        // The previous behaviour produced zero-diff G98 children (notably
        // transition-firewall lanes), which are not interpretable experiments
        // and must never consume a screening slot.
        $directedParameters = $parameters;
        $parameters = $this->ensureHistoricalNovelParameters(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $parameters,
            $slot,
            $target,
            $niche,
            $isolatedKey,
        );
        if ($g98Target && $isolatedKey !== null && $this->diff($base, $parameters) === []) {
            $parameters = $directedParameters;
        }
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        // Historical novelty is allowed to choose an unseen nearby value, but
        // it must not undo the role contract or resurrect a quarantined
        // direction. Apply the policy once more after that search step; this
        // is still draft-time parameter construction and creates no evidence.
        if ($councilRole !== '') {
            $parameters = $this->enforceCouncilRolePolicy(
                $councilRole,
                $family,
                $base,
                $parameters,
                $slot,
                $blockedMutationDirections,
                $knowledgeBlockedKeys,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }

        // The constructor invariant is checked after every compiler, novelty
        // pass and role policy.  Checking only before persistence was too
        // early: historical novelty could toggle the proposed value back to
        // the parent and create a zero-diff agent.  Repair lanes also require
        // exactly one changed gene; a multi-gene child is not attributable.
        $strictSingleGene = $g98Target
            || in_array($origin, ['gate_targeted', 'risk_exit', 'causal_isolation', 'g98_council', 'targeted_failure_profile', 'coverage_rescue'], true)
            || $family === 'differential_router';
        $parameters = $this->enforceConstructorMutationInvariant(
            $family,
            $base,
            $parameters,
            $slot,
            $target,
            $knowledgeBlockedKeys,
            $strictSingleGene,
        );
        if ($parameters === null) {
            if ($g98Target && $councilRole !== '') {
                $parameters = $base;
                $noLegalOwnerMutationControl = true;
            } else {
                return false;
            }
        }
        $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
        $parameters = $this->schemas->validate($family, $parameters);
        // The generic one-gene nudge above is intentionally family-wide and
        // can select a protected firewall when a role compiler returned a
        // zero-diff control. Re-apply the role contract after that nudge so a
        // router can never be persisted with its transition firewall off.
        if ($councilRole !== '') {
            $parameters = $this->enforceCouncilRolePolicy(
                $councilRole,
                $family,
                $base,
                $parameters,
                $slot,
                $blockedMutationDirections,
                $knowledgeBlockedKeys,
            );
            $parameters = $this->schemas->normalizeForGeneration($family, $parameters);
            $parameters = $this->schemas->validate($family, $parameters);
        }
        $parameterDiff = $this->diff($base, $parameters);
        // A role-complete lane may truthfully persist a no-change control when
        // every legal owner mutation is blocked by learned harmful lessons.
        // It remains research-only and can never satisfy a passport, but it
        // preserves role coverage and records an explicit abstention/control
        // result instead of resurrecting a protected firewall gene.
        $roleControlEligible = $councilRole !== '' && $g98Target;
        if (($parameterDiff === [] && ! $roleControlEligible)
            || ($strictSingleGene && count($parameterDiff) !== 1 && ! ($roleControlEligible && $parameterDiff === []))) {
            return false;
        }

        $roleControl = $roleControlEligible && $parameterDiff === [];
        $rolePolicy = $councilRole !== ''
            ? $this->councilRolePolicyContract($councilRole, $family, $base, $parameters, $blockedMutationDirections)
            : null;
        if ($roleControl && is_array($rolePolicy)) {
            $rolePolicy['role_control'] = [
                'type' => 'no_change_control',
                'status' => 'no_legal_owner_mutation_available',
                'promotion_evidence' => false,
            ];
        }
        $tacticAlignment = $this->tactics->alignment($tacticContract, $target, array_key_first($this->diff($base, $parameters)));
        $controlRoot = app(ControlRootCatalogueService::class)->for($family, $architecture);
        $controlRootSeedDeclaration = $parentA === null
            ? $this->controlRootInheritance->seedDeclaration(
                $lab->symbol,
                $lab->timeframe,
                $family,
                $niche,
                $semanticGroup,
                $controlRoot,
                $architecture,
                $parameters,
            )
            : null;
        $pendingControlRootInheritance = $controlRootSeedAgent
            ? $this->controlRootInheritance->pendingChildDeclaration($controlRootSeedAgent, $semanticGroup, $target)
            : null;
        $parentPerformance = $parentA?->marketPerformances()
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->latest('id')
            ->first();
        $parentMetrics = $parentPerformance?->metrics ?? [];
        // Every child receives an auditable inheritance contract. Parameters
        // are still the executable source of truth, while this snapshot
        // proves which frontier parent, target progress and confirmed traits
        // were carried into the next generation.
        $progressiveInheritance = $this->progressiveInheritanceContract(
            $parentA,
            $parentPerformance,
            $family,
            $target,
            $niche,
            $base,
            $parameters,
            $parentTier,
            $parentSelection,
            $semanticGroup,
            $parents->all(),
        );
        $constitution = $this->constitutions->draft($lab->symbol, $lab->timeframe, $family, $architecture, $parameters);
        $universalGenome = $this->universalCapabilities->genome(
            $lab->symbol,
            $lab->timeframe,
            $family,
            $architecture,
            $parameters,
            $parentA,
            $parents->all(),
        );
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
                'tactic_contract' => $tacticContract,
                'tactic_alignment' => $tacticAlignment,
                'lab_symbol' => $lab->symbol, 'origin' => $origin,
                'lab_timeframe' => $lab->timeframe,
                'population_group' => [
                    ...$populationGroup,
                    'prior_checkpoint' => $priorGroupCheckpoint !== [] ? $priorGroupCheckpoint : null,
                    'checkpoint_inheritance_rule' => 'Use the prior group checkpoint as bounded research context; exact semantic parent and unchanged gates remain authoritative.',
                    'promotion_evidence' => false,
                ],
                'specialist_council_membership' => [
                    'protocol' => self::SPECIALIST_COUNCIL_PROTOCOL,
                    'group_key' => $researchGroup,
                    'member_role' => data_get($populationGroup, 'search_role'),
                    'parameter_specialties' => array_keys($parameterDiff),
                    'global_champion' => false,
                    'council_frontier_only_until_individual_passport' => true,
                    'promotion_evidence' => false,
                ],
                'semantic_group' => $semanticGroup,
                'semantic_map_elites' => app(SemanticEliteArchiveService::class)->contract(
                    // The group descriptor is the immutable cell identity;
                    // this model is the child being placed into the archive.
                    // A temporary model is unnecessary because the cell key
                    // is already persisted in semantic_group below.
                    ModelVersion::make(['metadata' => ['semantic_group' => $semanticGroup]]),
                    $family,
                ),
                'adaptive_parent_ecosystem' => $adaptiveParentSelection['contract'] ?? null,
                'capability_genome' => $adaptiveParentSelection['capability_genome'] ?? null,
                'capability_gene_provenance' => $capabilityGeneProvenance ?: null,
                'runtime_ensemble_policy' => $adaptiveParentSelection['runtime_ensemble_policy'] ?? null,
                'parent_inheritance_protocol' => [
                    'protocol' => 'exact_semantic_parent_or_group_root_v1',
                    'parent_selection' => $parentSelection,
                    'parent_status' => $parentA ? 'attached' : 'not_available',
                    'parent_graph_protocol' => 'lab_agent_parent_graph_v1',
                    'control_root_protocol' => $controlRootSeedAgent ? ControlRootInheritanceService::PROTOCOL : null,
                    'control_root_seed_model_version_id' => $controlRootSeedAgent?->model_version_id,
                    'cross_cell_parent_forbidden' => true,
                    'legacy_parent_genetic_material' => false,
                    'promotion_evidence' => false,
                ],
                'control_root' => $controlRoot,
                'control_root_seed' => $controlRootSeedDeclaration,
                'control_root_specialist_inheritance' => $pendingControlRootInheritance,
                'ai_policy_boundary' => [
                    'protocol' => 'bounded_ai_policy_authority_v1',
                    'signal_generator' => false,
                    'gate_threshold_mutation' => false,
                    'allowed_layers' => ['paper_only_position_sizing', 'paper_only_execution'],
                    'status' => 'enforced',
                    'promotion_evidence' => false,
                ],
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
                'g98_council_lane' => ($g98Target || $targetedFailureLane) ? [
                    'protocol' => self::GENERATION_PROTOCOL,
                    'lane' => $target,
                    'mutation_target' => $mutationTarget,
                    'mutation_layers' => 1,
                    'research_variant' => data_get($niche, 'recall_variant'),
                    'targeted_failure_profile' => $targetedFailureLane,
                    'parent_lane_freeze' => true,
                    'control_only' => $roleControl,
                    'control_reason' => $roleControl
                        ? ($noLegalOwnerMutationControl ? 'all_legal_owner_mutations_blocked' : 'role_policy_no_change')
                        : null,
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
                'professional_learning_lane' => [
                    'protocol' => 'professional_learning_lane_v1',
                    'curiosity_lane' => $curiosityLane,
                    'selection_lane' => $curiosityLane ? 'curiosity_research' : 'standard_research',
                    'promotion_evidence' => false,
                ],
                'mutation_budget' => data_get($knowledgeContract, 'mutation_budget', []),
                'portfolio_council_source_performance_id' => data_get($niche, 'source_performance_id'),
                'portfolio_council_parent_selection' => [
                    'protocol' => 'failure_context_frontier_parent_v1',
                    'requested_failure_source_performance_id' => $sourcePerformance?->id,
                    'selected_parent_model_version_id' => $parentA?->id,
                    'selection' => $parentSelection,
                    'source_was_eligible_exact_parent' => (bool) $sourceCanBeExactParent,
                    'failure_context_remains_diagnostic_only' => (bool) $sourcePerformance && ! $sourceCanBeExactParent,
                    'promotion_evidence' => false,
                ],
                'state_cluster_contract' => $g98Target && data_get($niche, 'state_cluster') ? [
                    'protocol' => 'state_cluster_v1',
                    'cluster' => data_get($niche, 'state_cluster'),
                    'month_labels_are_diagnostic_only' => true,
                    'mutation_feature_allowlist' => [
                        'regime', 'volatility', 'transition_state',
                        'spread_liquidity_state', 'veto_reason',
                    ],
                    'promotion_evidence' => false,
                ] : null,
                'council_specialist_contract' => data_get($niche, 'protocol') === 'portfolio_council_v1' ? [
                    'protocol' => 'agent_council_v1',
                    'role' => data_get($niche, 'specialist_role', data_get($niche, 'role')),
                    'owner_regime' => data_get($niche, 'regime'),
                    'owner_volatility' => data_get($niche, 'volatility'),
                    'owner_direction' => data_get($niche, 'direction'),
                    'owner_context' => data_get($niche, 'owner_context'),
                    'lane' => $target,
                    'mutation_target' => $mutationTarget,
                    'standalone_forward_passport_required' => true,
                    'combined_replay_after_individual_passports' => true,
                    'promotion_evidence' => false,
                ] : null,
                'role_complete_council' => (bool) data_get($niche, 'role_complete_council', false) ? [
                    'protocol' => self::ROLE_COMPLETE_COUNCIL_PROTOCOL,
                    'role' => data_get($niche, 'specialist_role', data_get($niche, 'role')),
                    'full_replay_required' => true,
                    'standalone_forward_passport_required' => true,
                    'router_before_combined_replay' => true,
                    'policy' => $rolePolicy,
                    'role_control' => $roleControl ? [
                        'type' => 'no_change_control',
                        'status' => 'no_legal_owner_mutation_available',
                        'promotion_evidence' => false,
                    ] : null,
                    'promotion_evidence' => false,
                ] : null,
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
                    'promotion_rule' => 'standalone_forward_passport_required; member_never_promotes_as_champion; combined_portfolio_after_passports',
                    'standalone_forward_required' => true,
                    'combined_replay_only_after_individual_passports' => true,
                ] : null,
                'parent_provenance' => $parentTier,
                'screening_seed_only' => $parentTier === 'screening_seed'
                    || ($parentTier === 'no_parent' && $parentSelection === 'archive_revival_research_seed'),
                'archive_revival' => [
                    'status' => $parentSelection === 'archive_revival_research_seed' ? 'research_seed' : 'not_used',
                    'parent_tier' => $parentTier,
                    'independent_replay_required' => true,
                    'old_score_not_inherited' => true,
                    'promotion_evidence' => false,
                ],
                'semantic_lineage' => [
                    'protocol' => 'strict_semantic_lineage_v2',
                    'parent_status' => $parentA ? 'attached' : 'not_available',
                    'child_group_key' => data_get($semanticGroup, 'key'),
                    'genetic_parent_model_version_id' => $parentA?->id,
                    'genetic_parent_model_version_ids' => array_values(array_unique(array_filter([
                        $parentA?->id,
                        $canonicalParentB,
                        ...array_values((array) ($adaptiveParentSelection['selected_parent_ids'] ?? [])),
                        ...array_values($skillCrossoverSources),
                    ], static fn ($id): bool => is_numeric($id) && (int) $id > 0))),
                    'genetic_parent_group_key' => $parentA
                        ? data_get($this->semanticGroups->fromModel($parentA, $family), 'key')
                        : null,
                    'mode' => $controlRootSeedAgent
                        ? 'control_root_seed_inheritance'
                        : ($parentA ? 'exact_semantic_parent' : 'no_parent_available'),
                    'legacy_parent_ids_diagnostic_only' => $diagnosticParents
                        ->filter(fn (ModelVersion $candidate): bool => (bool) data_get($this->semanticGroups->fromModel($candidate, $family), 'legacy_unscoped', false))
                        ->pluck('id')->values()->all(),
                    'diagnostic_candidate_count' => $diagnosticParents->count(),
                    'adaptive_selected_parent_count' => count((array) ($adaptiveParentSelection['selected_parent_ids'] ?? [])),
                    'promotion_evidence' => false,
                    'rule' => 'Exact semantic key only for genetic inheritance; all other candidates remain diagnostic controls.',
                ],
                'progressive_inheritance' => $progressiveInheritance,
                'causal_experiment_lane' => ($g98Target || $targetedFailureLane || $origin === 'causal_isolation' || $origin === 'g98_council' || $origin === 'curiosity_probe' || $family === 'differential_router') ? [
                    'status' => $roleControl ? 'no_change_control' : 'isolated_single_gene',
                    'rule' => 'One changed parameter only; requires parent and same-generation alternative before causal credit.',
                    'control_only' => $roleControl,
                ] : null,
                'hypothesis_contract' => ($g98Target || $targetedFailureLane || $origin === 'coverage_rescue' || $family === 'differential_router') ? [
                    'protocol' => 'hypothesis_laboratory_v1',
                    'hypothesis' => 'Improve only the declared operating-envelope failure without changing any protected lane.',
                    'target_lane' => $target,
                    'target_context' => $niche ? [
                        'regime' => data_get($niche, 'regime'), 'volatility' => data_get($niche, 'volatility'),
                        'session_utc_hour' => data_get($niche, 'session_utc_hour'), 'direction' => data_get($niche, 'direction'),
                        'state_cluster' => data_get($niche, 'state_cluster'),
                    ] : null,
                    'changed_gene' => $isolatedKey,
                    'unchanged_lane_invariant' => $family === 'differential_router'
                        ? 'Non-target signal, confidence and trade-ledger identities must equal the frozen parent.'
                        : 'Only the declared single gene may differ from the frozen parent/default.',
                    'independent_reconfirmations_required' => 2,
                    'retire_family_after_failed_independent_replays' => 2,
                    'parent_rule' => 'Only independently confirmed beneficial credits may become reusable mutation priors.',
                    'tactic_alignment' => $tacticAlignment,
                ] : null,
                'mutation_bundle' => $this->evolutionQuality->curriculum($parentMetrics)['bounded_bundle'] ?? null,
                'mutation_scope' => $mutationScope,
                'skill_crossover_sources' => $skillCrossoverSources ?: null,
                'parent_contribution_graph' => [
                    'protocol' => 'lab_agent_parent_graph_v1',
                    'all_parent_model_version_ids' => array_values(array_unique(array_filter([
                        ...array_values((array) ($adaptiveParentSelection['selected_parent_ids'] ?? [])),
                        $parentA?->id,
                        $canonicalParentB,
                        ...array_values($skillCrossoverSources),
                    ], static fn ($id): bool => is_numeric($id) && (int) $id > 0))),
                    'primary_parent_a_model_version_id' => $parentA?->id,
                    'primary_parent_b_model_version_id' => $canonicalParentB,
                    'control_root_seed_model_version_id' => $controlRootSeedAgent?->model_version_id,
                    'skill_source_model_version_ids' => $skillCrossoverSources,
                    'capability_gene_provenance' => $capabilityGeneProvenance,
                    'adaptive_selected_parent_model_version_ids' => (array) ($adaptiveParentSelection['selected_parent_ids'] ?? []),
                    'adaptive_selection_contract' => $adaptiveParentSelection['contract'] ?? null,
                    'promotion_evidence' => false,
                ],
                'agent_knowledge_contract' => $knowledgeContract,
                // A frozen near-forward parent is never edited in place.
                // This child is the only allowed research fork from it.
                'elite_candidate_fork' => data_get($parentA?->metadata, 'elite_agent_passport.freeze.status') === 'frozen'
                    ? ['parent_model_version_id' => $parentA->id, 'parent_parameter_hash' => data_get($parentA->metadata, 'elite_agent_passport.freeze.parameter_hash')]
                    : null,
                'parameter_fingerprint' => $this->parameterFingerprint($family, $parameters),
                'mutation_constructor_invariant' => [
                    'protocol' => 'agent_constructor_invariant_v1',
                    'status' => 'passed',
                    'control_only' => $roleControl,
                    'single_gene_required' => $strictSingleGene,
                    'changed_parameter_keys' => array_keys($parameterDiff),
                    'parameter_diff_count' => count($parameterDiff),
                    'parent_model_version_id' => $parentA?->id,
                    'parent_rule' => $parentA
                        ? 'Child inherits only from its exact declared semantic parent.'
                        : 'No exact parent is available; child starts from the semantic group root/default seed.',
                    'promotion_evidence' => false,
                ],
                // New generations must produce actual CSCV/PBO, DSR and
                // bootstrap evidence before paper promotion. Legacy records
                // remain auditable but cannot silently define this protocol.
                'statistical_gate_version' => 3,
                'robustness_gate_version' => 1,
                'repair_lineage' => $repairLineage,
                'agent_constitution' => $constitution,
                'universal_genome' => $universalGenome,
                'regime_reservoir_recall' => $reservoirRecall,
                // Store the exact symbol-aware contract used by every replay
                // lane. The old differential protocol string was not an
                // execution hash and made an otherwise valid FX cohort fail
                // the later full-validation preflight.
                'execution_contract' => $this->executionContracts->for($lab->symbol, $lab->timeframe),
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
        // Reconcile the persisted model identity before it can enter any
        // queue.  Parameters are the source of truth; a stale fingerprint or
        // universal-genome hash makes a child impossible to audit even when
        // its diff has one gene.  This is a draft-time metadata repair only;
        // it never changes a parameter or creates promotion evidence.
        $this->sealParameterIntegrity($model, $family);
        $agent = $generation->agents()->create([
            'model_version_id' => $model->id, 'parent_a_model_version_id' => $parentA?->id,
            'parent_b_model_version_id' => $canonicalParentB,
            'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
            'origin' => $origin, 'lifecycle_status' => 'draft',
            'parameter_diff' => $parameterDiff,
            'decision_reason' => $parentA
                ? null
                : 'Parent currently unavailable; agent starts without a parent and may use an exact parent in a later generation.',
        ]);
        $agent->setRelation('modelVersion', $model);
        if ($controlRootSeedDeclaration !== null) {
            $this->controlRootInheritance->finalizeSeed($model, $agent);
        }
        if ($controlRootSeedAgent !== null) {
            $this->controlRootInheritance->finalizeSpecialist(
                $agent,
                $controlRootSeedAgent,
                $family,
                $niche,
                $semanticGroup,
                $base,
                $parameters,
                $parameterDiff,
                $knowledgeContract,
                $progressiveInheritance,
                $history,
                $target,
            );
        }
        $this->persistParentContributionGraph(
            $agent,
            $parentA,
            $canonicalParentB,
            $skillCrossoverSources,
            $controlRootSeedAgent !== null ? 'control_root_seed' : null,
            (array) ($adaptiveParentSelection['selected_parent_ids'] ?? []),
            (array) ($adaptiveParentSelection['contract'] ?? []),
            $capabilityGeneProvenance,
        );
        $this->evolutionArchive->recordParentSelectionDecision(
            $generation,
            $agent,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $origin,
            $target,
            $adaptiveParentSelection,
        );
        return true;
    }

    /**
     * Persist every actual crossover contributor. The two parent columns are
     * retained as the primary compatibility projection, while skill-level
     * links make a robust crossover auditable when different genes came from
     * different parents.
     */
    private function persistParentContributionGraph(
        LabAgent $agent,
        ?ModelVersion $parentA,
        ?int $parentBId,
        array $skillCrossoverSources,
        ?string $parentARelationType = null,
        array $adaptiveParentIds = [],
        array $selectionContract = [],
        array $geneProvenance = [],
    ): void {
        $links = [];
        $linkedIds = [];
        if ($parentA?->id) {
            $links[] = [
                'parent_model_version_id' => $parentA->id,
                'relation_type' => $parentARelationType ?: 'parent_a',
                'contribution_key' => 'parent_a',
                'metadata' => ['source' => $parentARelationType ?: 'primary_parent_column'],
            ];
            $linkedIds[] = (int) $parentA->id;
        }
        if ($parentBId) {
            $links[] = [
                'parent_model_version_id' => $parentBId,
                'relation_type' => 'parent_b',
                'contribution_key' => 'parent_b',
                'metadata' => ['source' => 'primary_parent_column'],
            ];
            $linkedIds[] = (int) $parentBId;
        }
        foreach ($skillCrossoverSources as $skill => $parentId) {
            if (! is_numeric($parentId) || (int) $parentId <= 0) continue;
            $skillGenes = collect($geneProvenance)
                ->filter(fn (array $provenance): bool => (string) data_get($provenance, 'source_module', '') !== ''
                    && (int) data_get($provenance, 'source_parent_id', 0) === (int) $parentId)
                ->keys()->values()->all();
            $links[] = [
                'parent_model_version_id' => (int) $parentId,
                'relation_type' => 'skill_crossover',
                'contribution_key' => (string) $skill,
                'metadata' => [
                    'source' => 'skill_crossover',
                    'skill' => (string) $skill,
                    'gene_keys' => $skillGenes,
                    'gene_provenance' => collect($geneProvenance)->only($skillGenes)->all(),
                    'promotion_evidence' => false,
                ],
            ];
            $linkedIds[] = (int) $parentId;
        }
        foreach (array_values(array_unique(array_filter($adaptiveParentIds))) as $index => $parentId) {
            if (! is_numeric($parentId) || (int) $parentId <= 0 || in_array((int) $parentId, $linkedIds, true)) continue;
            $links[] = [
                'parent_model_version_id' => (int) $parentId,
                'relation_type' => 'adaptive_contributor',
                'contribution_key' => 'adaptive_parent_'.($index + 1),
                'metadata' => [
                    'source' => 'adaptive_parent_frontier',
                    'selection_protocol' => data_get($selectionContract, 'protocol'),
                    'selection_mode' => data_get($selectionContract, 'mode'),
                    'island_key' => data_get($selectionContract, 'island_key'),
                    'promotion_evidence' => false,
                ],
            ];
            $linkedIds[] = (int) $parentId;
        }
        if ($links !== []) {
            $agent->parentLinks()->createMany($links);
        }
    }

    private function differentialSingleGene(
        array $base,
        int $slot,
        ?string $scope,
        ?string $objective = null,
        ?string $variant = null,
        ?array $niche = null,
        array $blockedKeys = [],
        array $blockedDirections = [],
    ): array
    {
        $blockedKeys = array_values(array_unique($blockedKeys));
        $role = (string) data_get($niche, 'specialist_role', data_get($niche, 'role', ''));
        $target = in_array($scope, ['trend_up', 'range', 'trend_down'], true) ? $scope : ['trend_down', 'range', 'trend_up'][$slot % 3];
        $parameters = [...$this->schemas->defaults('differential_router'), ...$base,
            'differential_target_regime' => $target, 'differential_replay_mode' => 'paired_isolated'];
        $schema = $this->schemas->schema('differential_router');
        // Monthly survival is a state-recurrence experiment.  If immutable
        // evidence identifies a transition/cooldown/spread veto, direct the
        // child to that existing control gene.  The state cluster is never a
        // calendar feature and the helper returns at most one changed gene.
        if ($objective === 'monthly_survival') {
            $stateMutation = $this->stateClusterMonthlyMutation(
                $parameters,
                $schema,
                data_get($niche, 'state_cluster'),
                $blockedKeys,
            );
            if ($stateMutation !== null) return $stateMutation;
        }
        if ($objective === 'opportunity_recall') {
            if ($role === 'trend_down_specialist') {
                return $this->trendDownCouncilRecallMutation($parameters, $schema, $blockedDirections);
            }
            // Recall is a controlled entry-funnel experiment, but a global
            // confidence relaxation lets a trend-up child borrow false
            // positives from every branch.  That is exactly how G113's
            // recall child reached 26 trades while PF collapsed to 0.22.
            // Keep the non-target router frozen and mutate the owner regime's
            // entry gene first; the unchanged PF, monthly and abstention
            // gates decide whether the added opportunities were useful.
            $ownerKeys = match ($target) {
                'trend_up' => ['differential_target_min_signal_confidence', 'trend_up_strength_min', 'trend_up_roc_threshold', 'trend_up_pullback_atr_fraction', 'trend_up_ema_period'],
                'trend_down' => ['differential_target_min_signal_confidence', 'trend_down_strength_min', 'trend_down_roc_threshold', 'trend_down_pullback_atr_fraction', 'trend_down_ema_period'],
                'range' => ['range_deviation', 'range_low_volatility_only', 'range_reentry_required', 'range_adx_max'],
                default => [],
            };
            // G120/G121 showed that loss_cooldown was the dominant rejection
            // source. Test the existing state-conditioned policy as a single
            // boolean ablation: trend/normal waits are shorter, while
            // range/high-risk waits are longer. It is never a global
            // confidence relaxation and still faces every unchanged gate.
            // When the parent already enables it, the child is the explicit
            // fixed-cooldown control for the same causal comparison.
            if ($variant === 'state_conditioned_cooldown'
                && array_key_exists('dynamic_cooldown_enabled', $parameters)
                && isset($schema['dynamic_cooldown_enabled'])) {
                $parameters['dynamic_cooldown_enabled'] = ! (bool) $parameters['dynamic_cooldown_enabled'];
                return $parameters;
            }
            // A cooldown-dominant owner gets a small, explicit shortening
            // probe.  The parent policy remains frozen; this child tests only
            // whether the rejected opportunities are worth the added risk.
            if ($variant === 'loss_cooldown_shortening'
                && array_key_exists('loss_cooldown_candles', $parameters)
                && isset($schema['loss_cooldown_candles'])) {
                [$type, $min, $max] = array_pad($schema['loss_cooldown_candles'], 3, null);
                $current = (int) $parameters['loss_cooldown_candles'];
                $step = max(1, min(4, (int) ceil(((float) $max - (float) $min) * .08)));
                $parameters['loss_cooldown_candles'] = max((int) $min, $current - $step);
                return $parameters;
            }
            // G128 exposed a different recall bottleneck from the earlier
            // cooldown cohort: regime_transition_wait rejected 111 of 284
            // opportunities.  Shorten only the transition wait by one candle
            // so the child tests whether a transition can be re-entered
            // sooner.  Firewall semantics, entry logic, costs and every
            // unchanged passport gate remain frozen.
            if ($variant === 'transition_wait_shortening'
                && array_key_exists('transition_wait_candles', $parameters)
                && isset($schema['transition_wait_candles'])) {
                [$type, $min, $max] = array_pad($schema['transition_wait_candles'], 3, null);
                $current = (int) $parameters['transition_wait_candles'];
                $parameters['transition_wait_candles'] = max((int) $min, $current - 1);
                return $parameters;
            }
            // G122's cooldown child exposed the next real bottleneck: the
            // negative-EV lower-bound veto rejected 172 of 257 opportunities.
            // Test only the existing guard as a shadow ablation. This is not
            // a promotion relaxation: recall, abstention precision, monthly
            // survival, stress, adversarial and every elite gate remain
            // unchanged and decide whether the extra opportunities are safe.
            if ($variant === 'negative_ev_lower_bound_ablation'
                && array_key_exists('confidence_ev_lower_bound_enabled', $parameters)
                && isset($schema['confidence_ev_lower_bound_enabled'])) {
                $parameters['confidence_ev_lower_bound_enabled'] = ! (bool) $parameters['confidence_ev_lower_bound_enabled'];
                return $parameters;
            }
            // A spread-dominant range owner gets a minimal recall probe.  It
            // is deliberately tiny and remains exposed to stress, drawdown,
            // monthly and worst-regime gates; this is not a spread-gate
            // relaxation for promotion.
            if ($variant === 'spread_atr_recall_probe'
                && array_key_exists('max_spread_atr_ratio', $parameters)
                && isset($schema['max_spread_atr_ratio'])) {
                [, , $max] = array_pad($schema['max_spread_atr_ratio'], 3, null);
                $parameters['max_spread_atr_ratio'] = round(min((float) $max, (float) $parameters['max_spread_atr_ratio'] + .005), 4);
                return $parameters;
            }
            foreach ($ownerKeys as $key) {
                if (! array_key_exists($key, $parameters) || ! isset($schema[$key])) continue;
                [$type, $min, $max] = array_pad($schema[$key], 3, null);
                if ($type === 'boolean') {
                    $parameters[$key] = ! (bool) ($parameters[$key] ?? false);
                    return $parameters;
                }
                if (! is_numeric($min) || ! is_numeric($max)) continue;
                $current = (float) $parameters[$key];
                $step = match (true) {
                    str_ends_with($key, '_strength_min') => 2.0,
                    str_ends_with($key, '_roc_threshold') => .05,
                    str_ends_with($key, '_pullback_atr_fraction') => .15,
                    str_ends_with($key, '_ema_period') => 5.0,
                    'range_deviation' => .2,
                    'range_adx_max' => 2.0,
                    default => max(.0001, ((float) $max - (float) $min) * .05),
                };
                // A recall lane must actually expand the target opportunity
                // funnel.  Alternating the target-only confidence threshold
                // upward on even seats silently creates a precision-control
                // arm instead of a recall arm; keep that gene directional.
                // Other owner genes still alternate bounded expansion and
                // contraction so the lane retains causal contrast.
                $direction = $key === 'differential_target_min_signal_confidence'
                    ? -1
                    : (($slot % 2 === 0) ? 1 : -1);
                $value = max((float) $min, min((float) $max, $current + ($direction * $step)));
                $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
                return $parameters;
            }
            // Legacy families without a regime-local field retain the old
            // bounded fallback; it is still subject to the same gates.
            foreach (['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'dynamic_cooldown_enabled'] as $key) {
                if (! array_key_exists($key, $parameters)) continue;
                if ($key === 'dynamic_cooldown_enabled') {
                    $parameters[$key] = ! (bool) $parameters[$key];
                    return $parameters;
                } elseif ($key === 'minimum_confidence') {
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
            if ($role === 'trend_up_specialist') {
                return $this->transitionCouncilMutation($parameters, $schema, $blockedDirections);
            }
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
            [$strengthType, $strengthMin, $strengthMax] = array_pad($schema[$key] ?? ['numeric', 10, 50], 3, null);
            $baseStrength = max((float) $strengthMin, min((float) $strengthMax, (float) ($parameters[$key] ?? 20.0)));
            if ($objective === 'calendar_context_rescue') {
                // A context rescue must not repeat the two screen-only
                // strength variants. Pullback depth is the only changed
                // target-lane gene; all non-target specialist branches stay
                // frozen by the differential replay contract.
                $basePullback = (float) ($parameters['trend_up_pullback_atr_fraction'] ?? 0.75);
                $parameters['trend_up_pullback_atr_fraction'] = round(min(2.0, $basePullback + 0.15), 2);
            } elseif ($variant === 1) {
                $parameters[$key] = max((float) $strengthMin, min((float) $strengthMax, $baseStrength - 6.0));
            } elseif ($variant === 2) {
                $parameters[$key] = max((float) $strengthMin, min((float) $strengthMax, $baseStrength - 4.0));
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
            [$strengthType, $strengthMin, $strengthMax] = array_pad($this->schemas->schema('differential_router')[$key] ?? ['numeric', 10, 50], 3, null);
            $current = max((float) $strengthMin, min((float) $strengthMax, (float) ($parameters[$key] ?? 20.0)));
            $value = max((float) $strengthMin, min((float) $strengthMax, $current + $delta));
            $parameters[$key] = $strengthType === 'integer' ? (int) round($value) : round($value, 4);
        }
        return $parameters;
    }

    /**
     * Enforce the draft-time mutation invariant before a model row exists.
     *
     * A compiler may legitimately propose a bundle for an ordinary research
     * lane, but repair/causal lanes must be attributable to one gene.  When a
     * compiler returns multiple changes we retain the first legal changed
     * field and restore every other field to the frozen base.  If a safety
     * firewall blocks every legal field, returning null makes the caller skip
     * the slot instead of persisting a zero-diff or silently resurrected child.
     */
    private function enforceConstructorMutationInvariant(
        string $family,
        array $base,
        array $parameters,
        int $slot,
        string $target,
        array $blockedKeys = [],
        bool $singleGene = false,
    ): ?array {
        $diff = $this->diff($base, $parameters);
        if ($diff === []) {
            return $this->forceSingleGeneNudge($family, $base, $slot, $target, $blockedKeys);
        }
        if (! $singleGene || count($diff) === 1) return $parameters;

        foreach (array_keys($diff) as $key) {
            if (in_array($key, $blockedKeys, true)) continue;
            $candidate = $base;
            $candidate[$key] = $parameters[$key] ?? $base[$key] ?? null;
            $candidate = $this->schemas->normalizeForGeneration($family, $candidate);
            $candidate = $this->schemas->validate($family, $candidate);
            if (count($this->diff($base, $candidate)) === 1) return $candidate;
        }

        return $this->forceSingleGeneNudge($family, $base, $slot, $target, $blockedKeys);
    }

    /** Deterministic last-resort for a G98 lane whose proposed gene is already set. */
    private function forceSingleGeneNudge(string $family, array $parameters, int $slot, string $target, array $blockedKeys = []): ?array
    {
        $preferred = match ($target) {
            'monthly_survival' => ['transition_firewall_enabled', 'session_filter_enabled', 'minimum_signal_confidence', 'lookback'],
            'regime_coverage' => ['trend_strength_min', 'minimum_signal_confidence', 'lookback'],
            'volatility_session_stability' => ['high_volatility_risk_multiplier', 'session_start', 'minimum_signal_confidence'],
            'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles'],
            'transition_firewall' => ['transition_firewall_enabled', 'transition_wait_candles'],
            'portfolio_router' => ['differential_target_regime', 'minimum_signal_confidence', 'lookback'],
            'opportunity_recall' => ['differential_target_min_signal_confidence', 'minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles', 'dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled'],
            'unknown_state_curiosity' => ['minimum_signal_confidence', 'minimum_confidence', 'transition_firewall_enabled', 'transition_wait_candles', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
            default => [],
        };
        $schema = $this->schemas->schema($family);
        $keys = [...array_values(array_intersect($preferred, array_keys($schema))), ...array_keys($schema)];
        foreach (array_unique($keys) as $key) {
            if (in_array($key, $blockedKeys, true)) continue;
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
        // If every legal gene is blocked by independently confirmed harmful
        // evidence, skip the slot. A no-change/retest child is not an agent:
        // it cannot teach the lab which mutation caused an outcome.
        return null;
    }

    /** Hybrid range rescue: preserve trend/breakout and change one range gene. */
    private function rangeCouncilSingleGene(
        array $base,
        int $slot,
        ?string $objective = null,
        ?string $variant = null,
        ?array $niche = null,
        array $blockedKeys = [],
        array $blockedDirections = [],
    ): array
    {
        $blockedKeys = array_values(array_unique($blockedKeys));
        $parameters = [...$this->schemas->defaults('hybrid'), ...$base];
        $role = (string) data_get($niche, 'specialist_role', data_get($niche, 'role', ''));
        if ($role === 'range_specialist') {
            return $this->rangeCouncilRoleMutation($parameters, $blockedDirections);
        }
        if ($role === 'transition_risk_router' && $objective === 'transition_firewall') {
            return $this->transitionCouncilMutation($parameters, $this->schemas->schema('hybrid'), $blockedDirections);
        }
        if ($objective === 'transition_firewall') {
            // Same experiment for a range specialist: preserve its entry
            // topology and test only transition protection.
            return [...$parameters, 'transition_firewall_enabled' => ! (bool) ($parameters['transition_firewall_enabled'] ?? false)];
        }
        if ($objective === 'opportunity_recall') {
            if ($variant === 'state_conditioned_cooldown' && array_key_exists('dynamic_cooldown_enabled', $parameters)) {
                return [...$parameters, 'dynamic_cooldown_enabled' => ! (bool) $parameters['dynamic_cooldown_enabled']];
            }
            if ($variant === 'loss_cooldown_shortening' && array_key_exists('loss_cooldown_candles', $parameters)) {
                $parameters['loss_cooldown_candles'] = max(1, (int) $parameters['loss_cooldown_candles'] - 1);
                return $parameters;
            }
            if ($variant === 'transition_wait_shortening' && array_key_exists('transition_wait_candles', $parameters)) {
                $parameters['transition_wait_candles'] = max(1, (int) $parameters['transition_wait_candles'] - 1);
                return $parameters;
            }
            if ($variant === 'negative_ev_lower_bound_ablation' && array_key_exists('confidence_ev_lower_bound_enabled', $parameters)) {
                return [...$parameters, 'confidence_ev_lower_bound_enabled' => ! (bool) $parameters['confidence_ev_lower_bound_enabled']];
            }
            if ($variant === 'spread_atr_recall_probe' && array_key_exists('max_spread_atr_ratio', $parameters)) {
                return [...$parameters, 'max_spread_atr_ratio' => round(min(.5, (float) $parameters['max_spread_atr_ratio'] + .005), 4)];
            }
            $current = (float) ($parameters['minimum_confidence'] ?? 1.0);
            return [...$parameters, 'minimum_confidence' => round(max(.1, $current - .1), 4)];
        }
        if ($objective === 'stress_cost') {
            return $this->stressExitSingleGene('hybrid', $parameters, $slot);
        }
        if ($objective === 'monthly_survival') {
            $stateMutation = $this->stateClusterMonthlyMutation(
                $parameters,
                $this->schemas->schema('hybrid'),
                data_get($niche, 'state_cluster'),
                $blockedKeys,
            );
            if ($stateMutation !== null) return $stateMutation;
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

    /**
     * Role baselines are safety invariants, not promotion shortcuts.  They
     * make the council curriculum express ownership explicitly: a specialist
     * may research its envelope, but it cannot remove the safety mechanism
     * that defines that envelope.
     */
    private function councilRoleBaseline(string $role, string $family): array
    {
        $baseline = match ($role) {
            'trend_up_specialist', 'trend_down_specialist' => [
                'transition_firewall_enabled' => true,
            ],
            'range_specialist' => [
                'transition_firewall_enabled' => true,
                'range_low_volatility_only' => true,
                'range_reentry_required' => true,
            ],
            'transition_risk_router' => [
                'transition_firewall_enabled' => true,
                'high_volatility_wait' => false,
            ],
            default => [],
        };

        return array_intersect_key($baseline, $this->schemas->schema($family));
    }

    /** @return array<string, mixed> */
    private function councilRolePolicySpec(string $role): array
    {
        return match ($role) {
            'trend_up_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'trend_up|high_volatility',
                'mutation_allowlist' => ['transition_wait_candles', 'high_volatility_risk_multiplier'],
                'protected_invariants' => ['transition_firewall_enabled' => true],
                'unknown_state_action' => 'WAIT',
                'transition_action' => 'WAIT_OR_REDUCE_RISK',
            ],
            'trend_down_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'trend_down|normal_volatility',
                'mutation_allowlist' => [
                    'transition_wait_candles', 'loss_cooldown_candles',
                    'confidence_ev_lower_bound_enabled', 'differential_target_min_signal_confidence',
                ],
                'protected_invariants' => ['transition_firewall_enabled' => true],
                'unknown_state_action' => 'WAIT',
                'recall_rule' => 'Expand recall only through a state-conditioned bounded probe; preserve abstention and cost gates.',
            ],
            'range_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'range|low_volatility',
                'mutation_allowlist' => ['range_signal_mode', 'range_reentry_required', 'range_deviation', 'range_adx_max'],
                'protected_invariants' => [
                    'transition_firewall_enabled' => true,
                    'range_low_volatility_only' => true,
                    'range_reentry_required' => true,
                ],
                'unknown_state_action' => 'WAIT',
                'edge_absent_action' => 'ABSTAIN_AND_SHADOW',
            ],
            'transition_risk_router' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'transition|risk',
                'routing_only' => true,
                'mutation_allowlist' => ['transition_wait_candles', 'high_volatility_risk_multiplier'],
                'protected_invariants' => ['transition_firewall_enabled' => true],
                'disagreement_action' => 'WAIT',
                'unknown_state_action' => 'WAIT',
            ],
            // Targeted failure-profile cohorts use observable gate failures
            // as role names. Keep those roles inside the same one-gene
            // constructor contract as the specialist council; an unknown
            // role used to restore every proposed change and silently shrink
            // a planned 20-seat rescue to only the few control-only seats
            // that happened to match a generic G98 target.
            'pf_stress_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'profit_factor|stress_cost',
                'mutation_allowlist' => [
                    'atr_stop_multiplier', 'atr_target_multiplier',
                    'trailing_atr_multiplier', 'time_stop_candles',
                    'partial_take_profit_fraction', 'max_spread_atr_ratio',
                    'high_volatility_risk_multiplier', 'avoid_high_volatility',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair PF/stress cost through one bounded cost or exit gene; keep all non-target lanes frozen.',
            ],
            'temporal_calendar_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'temporal_stability|calendar_survival',
                'mutation_allowlist' => [
                    'lookback', 'session_start', 'session_end',
                    'minimum_signal_confidence', 'transition_firewall_enabled',
                    'transition_wait_candles',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair temporal/calendar stability through one bounded timing or persistence gene; calendar labels remain diagnostic only.',
            ],
            'regime_coverage_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'regime_coverage',
                'mutation_allowlist' => [
                    'trend_strength_min', 'lookback',
                    'high_volatility_risk_multiplier', 'minimum_signal_confidence',
                    'minimum_confidence',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Repair regime coverage through one bounded regime sensitivity gene; no regime gate is relaxed.',
            ],
            'non_target_regression_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'drawdown_risk|non_target_regression',
                'mutation_allowlist' => [
                    'high_volatility_risk_multiplier', 'max_loss_streak_before_wait',
                    'loss_cooldown_candles', 'atr_stop_multiplier',
                    'time_stop_candles', 'avoid_high_volatility',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Protect non-target behavior through one bounded risk/exit gene; the target gate remains unchanged.',
            ],
            'architecture_control_specialist' => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'owner' => 'architecture_control',
                'mutation_allowlist' => [
                    'lookback', 'session_start', 'session_end',
                    'trend_strength_min', 'minimum_signal_confidence',
                    'range_signal_mode', 'range_deviation', 'range_adx_max',
                ],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
                'research_rule' => 'Test architecture behavior through one bounded topology-control gene; family and execution contract remain frozen.',
            ],
            default => [
                'protocol' => 'council_role_policy_v1',
                'role' => $role,
                'mutation_allowlist' => [],
                'protected_invariants' => [],
                'unknown_state_action' => 'WAIT',
            ],
        };
    }

    /**
     * Keep a role child from resurrecting a quarantined mutation direction.
     * If the first proposal is blocked, the role receives a different,
     * still one-gene bounded probe.  If its lane is exhausted, the unchanged
     * gates see a no-change/retest candidate rather than a disguised repeat.
     */
    private function enforceCouncilRolePolicy(
        string $role,
        string $family,
        array $base,
        array $parameters,
        int $slot,
        array $blockedDirections,
        array $blockedKeys = [],
    ): array {
        $spec = $this->councilRolePolicySpec($role);
        $allowed = array_values((array) data_get($spec, 'mutation_allowlist', []));
        foreach ($this->councilRoleBaseline($role, $family) as $key => $value) {
            if (array_key_exists($key, $base)) $parameters[$key] = $value;
        }

        // Historical novelty and generic curriculum fallbacks are allowed to
        // propose a gene outside the role envelope. Restore those proposals
        // before looking for a replacement; otherwise a range child can spend
        // a trend gene simply because every known range direction is blocked.
        foreach (array_keys($this->diff($base, $parameters)) as $changedKey) {
            if (! in_array((string) $changedKey, $allowed, true)
                || in_array((string) $changedKey, $blockedKeys, true)) {
                if (array_key_exists($changedKey, $base)) {
                    $parameters[$changedKey] = $base[$changedKey];
                } else {
                    unset($parameters[$changedKey]);
                }
            }
        }

        $diff = $this->diff($base, $parameters);
        if (count($diff) === 1) {
            $changedKey = (string) array_key_first($diff);
            $changedValue = data_get($diff, $changedKey.'.new');
            if (! $this->mutationDirectionBlocked($changedKey, $changedValue, $blockedDirections)) {
                return $parameters;
            }
            if (array_key_exists($changedKey, $base)) $parameters[$changedKey] = $base[$changedKey];
        }

        $fallback = $this->councilRoleMutationCandidate($role, $family, $base, $blockedKeys, $blockedDirections);
        $fallbackDiff = $this->diff($base, $fallback);
        if (count($fallbackDiff) === 1) return $fallback;

        // No legal owner mutation remains. Keep a truthful no-change control;
        // draft validation may admit it as evidence, while the passport hard
        // gate still prevents it from becoming a specialist or paper member.
        return $base;
    }

    /**
     * Find a fresh one-gene mutation inside the role allowlist. This is the
     * last bounded search before a no-change control, never a global fallback.
     * It prevents harmful-direction memory from leaking into another role.
     */
    private function councilRoleMutationCandidate(
        string $role,
        string $family,
        array $base,
        array $blockedKeys = [],
        array $blockedDirections = [],
    ): array {
        $spec = $this->councilRolePolicySpec($role);
        $allowed = array_values((array) data_get($spec, 'mutation_allowlist', []));
        $protected = (array) data_get($spec, 'protected_invariants', []);
        $schema = $this->schemas->schema($family);

        foreach ($allowed as $key) {
            if (in_array($key, $blockedKeys, true)
                || ! array_key_exists($key, $base)
                || ! isset($schema[$key])) continue;

            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            $current = $base[$key];
            $candidates = [];

            if ($type === 'string') {
                foreach ((array) $min as $value) if ($value !== $current) $candidates[] = $value;
            } elseif ($type === 'boolean') {
                $candidate = ! (bool) $current;
                if (! array_key_exists($key, $protected) || $candidate === $protected[$key]) $candidates[] = $candidate;
            } elseif (in_array($type, ['integer', 'numeric'], true) && is_numeric($min) && is_numeric($max)) {
                $span = (float) $max - (float) $min;
                $step = $type === 'integer' ? 1.0 : max(.0001, $span * .05);
                for ($multiplier = 1; $multiplier <= 8; $multiplier++) {
                    foreach ([1, -1] as $direction) {
                        $value = (float) $current + ($direction * $step * $multiplier);
                        $value = max((float) $min, min((float) $max, $value));
                        $candidates[] = $type === 'integer' ? (int) round($value) : round($value, 4);
                    }
                }
            }

            foreach (array_values(array_unique($candidates, SORT_REGULAR)) as $candidate) {
                if (array_key_exists($key, $protected) && $candidate !== $protected[$key]) continue;
                if ($candidate === $current || $this->mutationDirectionBlocked($key, $candidate, $blockedDirections)) continue;
                $child = $base;
                $child[$key] = $candidate;
                $diff = $this->diff($base, $child);
                if (count($diff) === 1) return $child;
            }
        }

        return $base;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function transitionCouncilMutation(array $parameters, array $schema, array $blockedDirections): array
    {
        if (array_key_exists('transition_wait_candles', $parameters)
            && isset($schema['transition_wait_candles'])) {
            [, $min, $max] = array_pad($schema['transition_wait_candles'], 3, null);
            $current = (int) $parameters['transition_wait_candles'];
            for ($step = 1; $step <= 6; $step++) {
                foreach ([$current + $step, $current - $step] as $candidate) {
                    $candidate = max((int) $min, min((int) $max, $candidate));
                    if ($candidate === $current || $this->mutationDirectionBlocked('transition_wait_candles', $candidate, $blockedDirections)) continue;
                    $parameters['transition_wait_candles'] = $candidate;
                    return $parameters;
                }
            }
        }

        if (array_key_exists('high_volatility_risk_multiplier', $parameters)
            && isset($schema['high_volatility_risk_multiplier'])) {
            [, $min, $max] = array_pad($schema['high_volatility_risk_multiplier'], 3, null);
            $current = (float) $parameters['high_volatility_risk_multiplier'];
            $step = max(.0001, ((float) $max - (float) $min) * .05);
            for ($multiplier = 1; $multiplier <= 8; $multiplier++) {
                foreach ([-1, 1] as $direction) {
                    $candidate = round(max((float) $min, min((float) $max, $current + ($direction * $step * $multiplier))), 4);
                    if ($candidate === $current || $this->mutationDirectionBlocked('high_volatility_risk_multiplier', $candidate, $blockedDirections)) continue;
                    $parameters['high_volatility_risk_multiplier'] = $candidate;
                    return $parameters;
                }
            }
        }

        return $parameters;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function trendDownCouncilRecallMutation(array $parameters, array $schema, array $blockedDirections): array
    {
        if (array_key_exists('transition_wait_candles', $parameters) && isset($schema['transition_wait_candles'])) {
            $current = (int) $parameters['transition_wait_candles'];
            $candidate = max(1, $current - 1);
            if ($candidate !== $current && ! $this->mutationDirectionBlocked('transition_wait_candles', $candidate, $blockedDirections)) {
                $parameters['transition_wait_candles'] = $candidate;
                return $parameters;
            }
        }
        if (array_key_exists('loss_cooldown_candles', $parameters) && isset($schema['loss_cooldown_candles'])) {
            $current = (int) $parameters['loss_cooldown_candles'];
            $candidate = max(1, $current - 1);
            if ($candidate !== $current && ! $this->mutationDirectionBlocked('loss_cooldown_candles', $candidate, $blockedDirections)) {
                $parameters['loss_cooldown_candles'] = $candidate;
                return $parameters;
            }
        }
        if (array_key_exists('confidence_ev_lower_bound_enabled', $parameters)
            && isset($schema['confidence_ev_lower_bound_enabled'])) {
            $candidate = ! (bool) $parameters['confidence_ev_lower_bound_enabled'];
            if (! $this->mutationDirectionBlocked('confidence_ev_lower_bound_enabled', $candidate, $blockedDirections)) {
                $parameters['confidence_ev_lower_bound_enabled'] = $candidate;
                return $parameters;
            }
        }
        if (array_key_exists('differential_target_min_signal_confidence', $parameters)
            && isset($schema['differential_target_min_signal_confidence'])) {
            $current = (float) $parameters['differential_target_min_signal_confidence'];
            $candidate = round(max(0.0, $current - .05), 4);
            if ($candidate !== $current && ! $this->mutationDirectionBlocked('differential_target_min_signal_confidence', $candidate, $blockedDirections)) {
                $parameters['differential_target_min_signal_confidence'] = $candidate;
                return $parameters;
            }
        }

        return $parameters;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function rangeCouncilRoleMutation(array $parameters, array $blockedDirections): array
    {
        $mode = (string) ($parameters['range_signal_mode'] ?? 'reentry');
        foreach (['mean_reversion', 'mid_cross', 'inverse_extreme', 'reentry'] as $candidate) {
            if ($candidate === $mode || $this->mutationDirectionBlocked('range_signal_mode', $candidate, $blockedDirections)) continue;
            $parameters['range_signal_mode'] = $candidate;
            return $parameters;
        }

        if (array_key_exists('range_deviation', $parameters)) {
            $current = (float) $parameters['range_deviation'];
            foreach ([
                round($current + .2, 4), round($current - .2, 4),
                round($current + .4, 4), round($current - .4, 4),
                round($current + .6, 4), round($current - .6, 4),
            ] as $candidate) {
                if ($candidate === $current || $this->mutationDirectionBlocked('range_deviation', $candidate, $blockedDirections)) continue;
                $parameters['range_deviation'] = max(.5, min(4.0, $candidate));
                return $parameters;
            }
        }

        if (array_key_exists('range_adx_max', $parameters)) {
            $current = (float) $parameters['range_adx_max'];
            foreach ([
                round($current + 2, 4), round($current - 2, 4),
                round($current + 4, 4), round($current - 4, 4),
            ] as $candidate) {
                if ($candidate === $current || $this->mutationDirectionBlocked('range_adx_max', $candidate, $blockedDirections)) continue;
                $parameters['range_adx_max'] = max(5, min(35, $candidate));
                return $parameters;
            }
        }

        return $parameters;
    }

    /** @param array<int, array<string, mixed>> $blockedDirections */
    private function mutationDirectionBlocked(string $key, mixed $value, array $blockedDirections): bool
    {
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        foreach ($blockedDirections as $direction) {
            if ((string) data_get($direction, 'parameter_key') !== $key) continue;
            if (json_encode(data_get($direction, 'new_value'), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) === $encoded) return true;
        }
        return false;
    }

    private function councilRolePolicyContract(
        string $role,
        string $family,
        array $base,
        array $parameters,
        array $blockedDirections,
    ): array {
        $spec = $this->councilRolePolicySpec($role);
        $diff = $this->diff($base, $parameters);
        $changedKey = array_key_first($diff);
        $spec['family'] = $family;
        $spec['changed_gene'] = $changedKey;
        $spec['changed_value'] = $changedKey !== null ? data_get($diff, $changedKey.'.new') : null;
        $spec['blocked_direction_firewall'] = [
            'protocol' => 'mutation_direction_firewall_v1',
            'blocked_direction_count' => count($blockedDirections),
            'applied' => true,
            'promotion_evidence' => false,
        ];
        $spec['promotion_evidence'] = false;
        return $spec;
    }

    /**
     * Compile a state-cluster monthly hypothesis into one bounded gene.
     *
     * A month can reveal recurrence, but it is not an executable feature:
     * this method deliberately reads only regime/volatility/transition,
     * liquidity and veto labels.  The returned child still runs through the
     * unchanged PF, monthly, stress, adversarial, recall and passport gates.
     */
    private function stateClusterMonthlyMutation(
        array $parameters,
        array $schema,
        mixed $stateCluster,
        array $blockedKeys = [],
    ): ?array {
        if (! is_array($stateCluster)
            || (string) data_get($stateCluster, 'protocol') !== 'state_cluster_v1'
            || (string) data_get($stateCluster, 'status') !== 'assessed') {
            return null;
        }

        $vetoReason = strtolower(trim((string) data_get($stateCluster, 'veto_reason', '')));
        $transitionState = strtolower(trim((string) data_get($stateCluster, 'transition_state', '')));
        $liquidityState = strtolower(trim((string) data_get($stateCluster, 'spread_liquidity_state', '')));

        // Transition vetoes are tested with the existing firewall.  This is a
        // paired ablation/activation, not a permanent permission to trade in
        // transitions.
        if (($vetoReason === 'regime_transition_wait' || $transitionState === 'transition_wait')
            && array_key_exists('transition_firewall_enabled', $parameters)
            && isset($schema['transition_firewall_enabled'])
            && ! in_array('transition_firewall_enabled', $blockedKeys, true)) {
            $parameters['transition_firewall_enabled'] = ! (bool) $parameters['transition_firewall_enabled'];
            return $parameters;
        }

        // Cooldown evidence gets the state-conditioned policy toggle.  A
        // later child can test the candle length in the dedicated recall lane;
        // the monthly lane first asks whether the policy itself is useful.
        if (in_array($vetoReason, ['loss_cooldown', 'cooldown', 'loss_streak_wait'], true)
            && array_key_exists('dynamic_cooldown_enabled', $parameters)
            && isset($schema['dynamic_cooldown_enabled'])
            && ! in_array('dynamic_cooldown_enabled', $blockedKeys, true)) {
            $parameters['dynamic_cooldown_enabled'] = ! (bool) $parameters['dynamic_cooldown_enabled'];
            return $parameters;
        }

        // Spread vetoes receive a deliberately tiny, bounded envelope probe.
        // In a low-liquidity cluster the direction is conservative (tighter
        // admission); otherwise the child tests whether a small widening
        // recovers genuinely good opportunities.  Either direction remains
        // exposed to cost/stress and recall gates.
        if ($vetoReason === 'spread_to_atr'
            || str_contains($liquidityState, 'spread')) {
            if (array_key_exists('max_spread_atr_ratio', $parameters)
                && isset($schema['max_spread_atr_ratio'])
                && ! in_array('max_spread_atr_ratio', $blockedKeys, true)) {
                [, $min, $max] = array_pad($schema['max_spread_atr_ratio'], 3, null);
                $current = (float) $parameters['max_spread_atr_ratio'];
                $direction = str_contains($liquidityState, 'low') || str_contains($liquidityState, 'illiquid') ? -1 : 1;
                $parameters['max_spread_atr_ratio'] = round(max(
                    (float) $min,
                    min((float) $max, $current + ($direction * .005)),
                ), 4);
                return $parameters;
            }
        }

        // Negative-EV lower-bound vetoes are tested as a shadow ablation;
        // promotion still requires the original abstention-precision and
        // opportunity-recall thresholds.
        if (in_array($vetoReason, ['negative_ev_lower_bound', 'negative_ev', 'ev_lower_bound'], true)
            && array_key_exists('confidence_ev_lower_bound_enabled', $parameters)
            && isset($schema['confidence_ev_lower_bound_enabled'])
            && ! in_array('confidence_ev_lower_bound_enabled', $blockedKeys, true)) {
            $parameters['confidence_ev_lower_bound_enabled'] = ! (bool) $parameters['confidence_ev_lower_bound_enabled'];
            return $parameters;
        }

        return null;
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
        ?string $isolatedKey = null,
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

        // G98/causal-isolation children have a sealed one-gene contract.  A
        // historical collision may require a bounded nudge, but it must never
        // add an unrelated novelty gene after the isolated mutation was
        // created.  If the declared gene is exhausted, preserve the original
        // child and quarantine it at the integrity gate instead of silently
        // turning it into an uninterpretable multi-gene experiment.
        $keys = $isolatedKey !== null && isset($this->schemas->schema($family)[$isolatedKey])
            ? [$isolatedKey]
            : $this->historicalNoveltyKeys($family, $parameters, $target, $niche);
        foreach (range(0, 24) as $attempt) {
            $key = $keys[$attempt % max(1, count($keys))] ?? null;
            if (! $key || ! isset($this->schemas->schema($family)[$key])) continue;
            $candidate = $this->nudgeNoveltyParameter(
                $family,
                $parameters,
                $key,
                $attempt,
                $seed,
                $isolatedKey !== null,
            );
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
        // Recall variants are deliberately single-gene experiments. If a
        // historical fingerprint collision occurs, only the declared gene
        // may be nudged; adding a second gene would invalidate the causal
        // contract and make the child impossible to interpret.
        if ($objective === 'opportunity_recall') {
            $variant = (string) data_get($niche, 'recall_variant', '');
            if ($variant === 'state_conditioned_cooldown') return ['dynamic_cooldown_enabled'];
            if ($variant === 'loss_cooldown_shortening') return ['loss_cooldown_candles'];
            if ($variant === 'transition_wait_shortening') return ['transition_wait_candles'];
            if ($variant === 'negative_ev_lower_bound_ablation') return ['confidence_ev_lower_bound_enabled'];
            if ($variant === 'spread_atr_recall_probe') return ['max_spread_atr_ratio'];
        }
        if ($family === 'hybrid' && $regime === 'range') {
            return match ($objective) {
                'monthly_survival' => ['range_signal_mode', 'range_deviation'],
                'temporal_stability' => ['range_deviation', 'range_lookback'],
                'calendar_context_rescue' => ['range_reentry_required', 'range_adx_max'],
                'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
                'opportunity_recall' => ['dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled', 'minimum_confidence', 'transition_wait_candles'],
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
                'opportunity_recall' => ['dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled', 'minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles'],
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

    private function nudgeNoveltyParameter(
        string $family,
        array $parameters,
        string $key,
        int $attempt,
        int $seed,
        bool $boundedIsolated = false,
    ): array
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
        // Isolated council genes already have a declared experiment step.
        // Historical novelty may search for a nearby unseen value, but it
        // must not turn a small recall probe into a materially different
        // experiment (for example max_spread_atr_ratio .01 -> .0469).
        $stepRate = $boundedIsolated ? .01 : (.035 + (.01 * intdiv($attempt, 4)));
        $step = $span * $stepRate;
        $value = $current + ($direction * $step);
        if ($boundedIsolated) {
            // An isolated child already has a declared bounded mutation. A
            // historical fingerprint collision may search locally, but it
            // must never wrap from the lower bound to the opposite edge of
            // the schema (for example ROC .10 -> 4.77). If the local lane is
            // exhausted, the caller preserves the original child and the
            // normal evidence gates decide its fate.
            $value = max((float) $min, min((float) $max, $value));
        } else {
            if ($value > (float) $max) $value = (float) $min + $step;
            if ($value < (float) $min) $value = (float) $max - $step;
        }
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
            'breakout_retest' => 'breakout_v1', 'breakout_continuation' => 'breakout_continuation_v1',
            'volatility_compression_expansion' => 'volatility_v1', 'volatility_breakout' => 'volatility_breakout_v1',
            'range_mean_reversion' => 'mean_reversion_v1', 'range_rsi_reversion' => 'range_rsi_reversion_v1',
            'session_breakout' => 'session_v1', 'session_mean_reversion' => 'session_mean_reversion_v1',
            'momentum_continuation' => 'momentum_v1', 'momentum_pullback' => 'momentum_pullback_v1',
            'regime_router' => 'hybrid_v1', 'regime_consensus' => 'regime_consensus_v1',
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
        $signatureBound = in_array($target, ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router', 'opportunity_recall', 'unknown_state_curiosity'], true);
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
            'opportunity_recall' => ['differential_target_min_signal_confidence', 'minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles', 'dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled'],
            // Curiosity may inspect uncertainty and risk boundaries only. It
            // never receives the broad PF/exit bundle and its evidence is
            // permanently marked research-only by the child contract.
            'unknown_state_curiosity' => ['minimum_signal_confidence', 'minimum_confidence', 'transition_firewall_enabled', 'transition_wait_candles', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
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
        $knowledgeBlockedKeys = $this->knowledge->blockedMutationKeys($symbol, $timeframe, $family, $scope);
        $safeKeys = array_values(array_diff($safeKeys, $knowledgeBlockedKeys));
        if ($safeKeys !== []) $keys = $safeKeys;
        $budget = app(AgentProfessionalExamService::class)->mutationBudget($symbol, $timeframe, $family);
        $budgetKeys = app(AgentProfessionalExamService::class)->allowedMutationKeys($keys, $budget);
        if ($budgetKeys !== []) $keys = $budgetKeys;
        // A fully exhausted lane is an explicit WAIT/retest outcome. It must
        // not resurrect a confirmed harmful gene merely to fill a slot.
        if ($keys === []) return $base;
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
            'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles', 'dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled'],
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

    private function qualityParents(string $symbol, string $timeframe, string $family, ?string $target = null, ?array $niche = null)
    {
        return ModelMarketPerformance::with('modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->where('strategy_family', $family)
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->get()
            ->filter(fn (ModelMarketPerformance $performance) => $this->parentEligible($performance))
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $this->semanticGroups->parentCompatible($performance->modelVersion, $family, $niche))
            // A raw PF/forward winner is not automatically the best repair
            // parent. Keep the general edge in the score, but let the active
            // target lane prefer the candidate that is already closest to
            // closing that lane's gate.
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance, $target, $niche))
            ->pluck('modelVersion')
            ->filter()
            ->values();
    }

    /**
     * Convert a loose/diagnostic frontier into a genetic frontier.
     *
     * This boundary is deliberately kept at the last moment before parent A
     * is selected.  It protects older reports and selectors that still need
     * to see a legacy hypothesis while making it impossible for that record
     * to leak into `base` or `parent_a_model_version_id`.
     */
    private function strictSemanticParents(
        $parents,
        string $symbol,
        string $timeframe,
        string $family,
        ?array $niche = null,
    ) {
        $eligible = collect($parents)
            ->filter(fn (ModelVersion $parent): bool => $this->semanticGroups->exactParentCompatible(
                $parent,
                $symbol,
                $timeframe,
                $family,
                $niche,
            ))
            ->values();

        // Keep the exact-cell boundary, but retain a bounded same-cell
        // frontier. The local champion anchors convergence; diverse
        // capability parents remain available to robust crossover.
        $frontierCaps = array_values(array_filter([
            (int) config('services.lab_selection.semantic_cell_parent_frontier', 0),
            (int) config('services.lab_selection.parent_candidate_frontier', 0),
        ], static fn (int $limit): bool => $limit > 0));
        // Zero means the complete exact-cell frontier. Positive values are
        // explicit infrastructure caps, never biological/evidence rules.
        $frontierLimit = $frontierCaps === [] ? 0 : max($frontierCaps);
        return app(SemanticEliteArchiveService::class)->frontierPerCell(
            $eligible,
            $frontierLimit,
            fn (ModelVersion $parent): float => (float) ($parent->best_score ?? 0),
        );
    }

    /**
     * Screening-only seed frontier. These models have enough reproducible
     * edge to be useful as mutation anchors, but intentionally do not satisfy
     * the stricter parentEligible() passport. The metadata on every child
     * makes this distinction auditable and the normal gate still decides all
     * later promotion stages.
     */
    private function screeningSeedParents(string $symbol, string $timeframe, string $family, ?string $target = null, ?array $niche = null)
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
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $this->semanticGroups->parentCompatible($performance->modelVersion, $family, $niche))
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance, $target, $niche))
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
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $this->semanticGroups->parentCompatible($performance->modelVersion, $family, $niche))
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance, $target, $niche))
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

        return in_array((string) $performance->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
            && (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPasses && $regimePasses
            && data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
    }

    private function parentQualityScore(ModelMarketPerformance $performance, ?string $target = null, ?array $niche = null): float
    {
        $metrics = $performance->metrics ?? [];
        $progress = $this->parentProgressSnapshot($performance, $target, $niche);
        $statusBonus = match ((string) $performance->status) {
            'champion' => 8.0,
            'forward_validated' => 6.0,
            'challenger' => 4.0,
            'paper' => 3.0,
            default => 0.0,
        };

        return ((float) $performance->forward_score * 2)
            + ((float) data_get($metrics, 'profit_factor', 0) * 25)
            - ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) * 2)
            - (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100))
            + (float) data_get($progress, 'selection_score', 0)
            + $statusBonus;
    }

    /**
     * Rank a parent by progress toward the current repair lane, not just by
     * its headline profit. This is the evolutionary frontier used before the
     * child compiler chooses a one-gene mutation.
     *
     * The score is deliberately a tie-breaker/quality bonus on top of the
     * hard parentEligible() passport. It can never make a rejected, overfit,
     * low-sample or high-ruin model a parent.
     */
    private function parentProgressSnapshot(ModelMarketPerformance $performance, ?string $target = null, ?array $niche = null): array
    {
        $metrics = (array) ($performance->metrics ?? []);
        $monthly = $this->monthlyProgressScore($metrics);
        $regime = $this->regimeProgressScore($metrics);
        $recall = $this->recallProgressScore($metrics);
        $stress = $this->stressProgressScore($metrics);
        $drawdown = $this->drawdownProgressScore($metrics);
        $forward = $this->forwardQuorumProgressScore($performance);
        $elite = $this->eliteProgressScore($metrics);
        $architecture = $this->architectureProgressScore($metrics);
        $gateScores = [
            'monthly_survival' => $monthly,
            'regime_coverage' => $regime,
            'opportunity_recall' => $recall,
            'stress_cost' => $stress,
            'drawdown' => $drawdown,
            'forward_quorum' => $forward,
            'elite_passport' => $elite,
            'architecture' => $architecture,
        ];
        $lane = $this->progressLaneForTarget($target);
        $targetScore = (float) ($gateScores[$lane] ?? $this->genericProgressScore($gateScores));
        $contextScore = $this->contextProgressScore($metrics, $niche);
        $genericScore = $this->genericProgressScore($gateScores);
        $observed = collect($gateScores)->filter(fn (float $score): bool => $score > 0)->keys()->values()->all();
        $passed = collect($gateScores)->filter(fn (float $score): bool => $score >= 70)->keys()->values()->all();
        $selectionScore = min(60.0, round(
            ($targetScore * .55) + ($contextScore * .20) + ($genericScore * .15) + ($forward * .10),
            2,
        ));

        return [
            'protocol' => 'progress_frontier_snapshot_v1',
            'target' => $target,
            'lane' => $lane,
            'target_progress_score' => round($targetScore, 2),
            'context_progress_score' => round($contextScore, 2),
            'generic_gate_score' => round($genericScore, 2),
            'forward_quorum_score' => round($forward, 2),
            'selection_score' => $selectionScore,
            'gate_scores' => array_map(fn (float $score): float => round($score, 2), $gateScores),
            'observed_gates' => $observed,
            'passed_gates' => $passed,
            'promotion_evidence' => false,
        ];
    }

    private function progressLaneForTarget(?string $target): string
    {
        return match ($target) {
            'monthly_survival', 'temporal_stability' => 'monthly_survival',
            'regime_coverage', 'rolling_regime', 'portfolio_router' => 'regime_coverage',
            'opportunity_recall', 'trade_frequency' => 'opportunity_recall',
            'volatility_session_stability', 'exit_topology', 'stress_cost', 'profit_factor', 'risk_exit', 'transition_firewall' => 'stress_cost',
            'drawdown_risk', 'shadow_veto_loss_cooldown', 'shadow_veto_volatility' => 'drawdown',
            'architecture', 'robustness' => 'architecture',
            default => 'forward_quorum',
        };
    }

    private function monthlyProgressScore(array $metrics): float
    {
        $passport = (array) data_get($metrics, 'monthly_passport', []);
        if ($passport === []) return 0.0;
        $wins = (int) data_get($passport, 'rolling_forward_wins', 0);
        $failedMonths = (int) data_get($passport, 'failed_months', 0);
        $winsScore = min(60.0, ($wins / 3) * 60);
        $stabilityScore = $failedMonths === 0 ? 40.0 : max(0.0, 40.0 - ($failedMonths * 20.0));
        $worstMonthPf = data_get($passport, 'worst_month_pf', data_get($passport, 'worst_month.profit_factor'));
        if (is_numeric($worstMonthPf)) {
            $stabilityScore = min($stabilityScore, max(0.0, (float) $worstMonthPf / 1.05 * 40));
        }
        return min(100.0, $winsScore + $stabilityScore);
    }

    private function regimeProgressScore(array $metrics): float
    {
        $edge = (array) data_get($metrics, 'statistical_evidence.edge_quality', []);
        $rows = (array) data_get($metrics, 'regime_performance', []);
        $sampled = collect($rows)->filter(fn ($row): bool => (int) data_get($row, 'trades', 0) > 0)->count();
        $coverageScore = min(40.0, ($sampled / 3) * 40);
        $worstPf = data_get($edge, 'worst_regime_pf');
        if (! is_numeric($worstPf)) {
            $pfValues = collect($rows)->map(fn ($row) => data_get($row, 'net_pf', data_get($row, 'profit_factor')))
                ->filter(fn ($pf): bool => is_numeric($pf))->map(fn ($pf): float => (float) $pf);
            $worstPf = $pfValues->isNotEmpty() ? $pfValues->min() : null;
        }
        $pfScore = is_numeric($worstPf) ? min(60.0, max(0.0, ((float) $worstPf / 1.05) * 60)) : 0.0;
        return min(100.0, $coverageScore + $pfScore);
    }

    private function recallProgressScore(array $metrics): float
    {
        $recall = (array) data_get($metrics, 'opportunity_recall', []);
        if ($recall === []) return 0.0;
        $recallScore = min(70.0, max(0.0, ((float) data_get($recall, 'opportunity_recall', 0) / .20) * 70));
        $precisionScore = min(30.0, max(0.0, ((float) data_get($recall, 'abstention_precision', 0) / .50) * 30));
        return min(100.0, $recallScore + $precisionScore);
    }

    private function stressProgressScore(array $metrics): float
    {
        $pf = data_get($metrics, 'pf_attribution.stress_cost.profit_factor', data_get($metrics, 'stress_test.profit_factor'));
        if (! is_numeric($pf)) return 0.0;
        $score = min(80.0, max(0.0, ((float) $pf / 1.05) * 80));
        $digitalTwin = data_get($metrics, 'execution_digital_twin');
        if (is_array($digitalTwin) && data_get($digitalTwin, 'status') === 'assessed') {
            $score += data_get($digitalTwin, 'pass') ? 20.0 : 0.0;
        } elseif ((array) $digitalTwin === []) {
            $score += 20.0;
        }
        return min(100.0, $score);
    }

    private function drawdownProgressScore(array $metrics): float
    {
        $drawdown = data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown'));
        $ruin = data_get($metrics, 'monte_carlo.risk_of_ruin_percent');
        if (! is_numeric($drawdown) && ! is_numeric($ruin)) return 0.0;
        $ddScore = is_numeric($drawdown) ? max(0.0, min(60.0, (1 - ((float) $drawdown / 15)) * 60)) : 0.0;
        $ruinScore = is_numeric($ruin) ? max(0.0, min(40.0, (1 - ((float) $ruin / 10)) * 40)) : 0.0;
        return min(100.0, $ddScore + $ruinScore);
    }

    private function forwardQuorumProgressScore(ModelMarketPerformance $performance): float
    {
        $windows = (int) $performance->rolling_windows_count;
        $wins = (int) $performance->rolling_forward_wins;
        if ($windows === 0 && $wins === 0) return 0.0;
        return min(100.0, min(($windows / 3) * 100, ($wins / 3) * 100));
    }

    private function eliteProgressScore(array $metrics): float
    {
        if (data_get($metrics, 'elite_agent_passport.status') === 'passed') return 100.0;
        if (data_get($metrics, 'elite_agent_passport.elite_quorum.status') === 'passed') return 85.0;
        $reasons = (array) data_get($metrics, 'elite_agent_passport.reason_codes', []);
        return $reasons === [] ? 0.0 : max(0.0, 100.0 - (count($reasons) * 12.5));
    }

    private function architectureProgressScore(array $metrics): float
    {
        $scores = [];
        if (data_get($metrics, 'parameter_plateau.status') === 'assessed') {
            $scores[] = data_get($metrics, 'parameter_plateau.pass') ? 100.0 : 0.0;
        }
        if (data_get($metrics, 'paired_replay.status', data_get($metrics, 'paired_experiment.status')) !== null) {
            $scores[] = data_get($metrics, 'paired_replay.status', data_get($metrics, 'paired_experiment.status')) === 'confirmed' ? 100.0 : 0.0;
        }
        if (data_get($metrics, 'no_regression_contract.status') !== null) {
            $scores[] = data_get($metrics, 'no_regression_contract.status') === 'passed' ? 100.0 : 0.0;
        }
        return $scores === [] ? 0.0 : (float) collect($scores)->avg();
    }

    private function genericProgressScore(array $gateScores): float
    {
        $observed = array_values(array_filter($gateScores, fn (float $score): bool => $score > 0));
        return $observed === [] ? 0.0 : (float) collect($observed)->avg();
    }

    private function contextProgressScore(array $metrics, ?array $niche): float
    {
        $contexts = [];
        foreach ([
            ['key' => 'regime_performance', 'value' => data_get($niche, 'regime')],
            ['key' => 'volatility_performance', 'value' => data_get($niche, 'volatility')],
        ] as $context) {
            if (! is_string($context['value']) || $context['value'] === '') continue;
            $row = (array) data_get($metrics, $context['key'].'.'.$context['value'], []);
            if ($row === []) continue;
            $pf = data_get($row, 'net_pf', data_get($row, 'profit_factor'));
            $trades = (int) data_get($row, 'trades', data_get($row, 'total_trades', 0));
            if (! is_numeric($pf)) continue;
            $contexts[] = min(100.0, max(0.0, ((float) $pf / 1.05) * 75) + min(25.0, ($trades / 20) * 25));
        }
        return $contexts === [] ? 0.0 : (float) collect($contexts)->avg();
    }

    /** Carry confirmed traits forward without turning them into promotion evidence. */
    private function progressiveInheritanceContract(
        ?ModelVersion $parent,
        ?ModelMarketPerformance $parentPerformance,
        string $family,
        string $target,
        ?array $niche,
        array $base,
        array $parameters,
        string $parentTier,
        string $parentSelection,
        array $semanticGroup,
        array $contributors = [],
    ): array {
        $schema = $this->schemas->schema($family);
        $parentModels = collect([$parent, ...$contributors])
            ->filter(fn ($model): bool => $model instanceof ModelVersion)
            ->unique('id')
            ->values();
        $parentParameters = (array) ($parent?->parameters ?? []);
        $inheritedKeys = $parentModels
            ->flatMap(fn (ModelVersion $model): array => array_keys((array) $model->parameters))
            ->filter(fn (string $key): bool => array_key_exists($key, $schema))
            ->unique()->values()->all();
        $changedKeys = array_keys($this->diff($base, $parameters));
        $parentLineage = (array) data_get($parent?->metadata, 'progressive_inheritance', []);
        $repairLineage = (array) data_get($parent?->metadata, 'repair_lineage', []);
        $rootId = $parent
            ? (int) data_get($parentLineage, 'root_model_version_id', data_get($repairLineage, 'root_model_version_id', $parent->id))
            : null;
        $traitsByParent = $parentModels->mapWithKeys(fn (ModelVersion $model): array => [
            (string) $model->id => $this->confirmedParentTraits($model),
        ])->all();
        $traits = collect([
            ...(array) data_get($parentLineage, 'confirmed_beneficial_traits', []),
            ...collect($traitsByParent)->flatMap(fn (array $parentTraits, string $parentId) => collect($parentTraits)
                ->map(fn (array $trait): array => [
                    ...$trait,
                    // The source id is retained beside the legacy trait
                    // shape so a multi-parent child can audit which parent
                    // supplied a beneficial prior.
                    'source_parent_model_version_id' => (int) $parentId,
                ]))->all(),
        ])->filter(fn ($trait): bool => is_array($trait) && filled(data_get($trait, 'parameter_key')))
            ->unique(fn (array $trait): string => (string) data_get($trait, 'parameter_key').'|'.json_encode(data_get($trait, 'new_value')));
        $traitLimit = (int) config('services.lab_selection.confirmed_parent_traits_limit', 0);
        if ($traitLimit > 0) $traits = $traits->take($traitLimit);
        $traits = $traits->values()->all();
        $progress = $parentPerformance
            ? $this->parentProgressSnapshot($parentPerformance, $target, $niche)
            : [
                'protocol' => 'progress_frontier_snapshot_v1', 'target' => $target,
                'lane' => $this->progressLaneForTarget($target), 'selection_score' => 0,
                'target_progress_score' => 0, 'context_progress_score' => 0,
                'generic_gate_score' => 0, 'forward_quorum_score' => 0,
                'gate_scores' => [], 'observed_gates' => [], 'passed_gates' => [],
                'promotion_evidence' => false,
            ];
        $parentGroup = $parent
            ? $this->semanticGroups->fromModel($parent, (string) data_get($parentPerformance, 'strategy_family', $family))
            : null;

        return [
            'protocol' => 'progressive_frontier_inheritance_v1',
            'status' => $parent ? 'inherited_from_frontier' : 'first_generation_seed',
            'parent_model_version_id' => $parent?->id,
            'parent_model_version_ids' => $parentModels->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'parent_performance_id' => $parentPerformance?->id,
            'root_model_version_id' => $rootId,
            'lineage_depth' => $parent ? ((int) data_get($parentLineage, 'lineage_depth', 0) + 1) : 0,
            'target' => $target,
            'target_context' => $niche ? [
                'regime' => data_get($niche, 'regime'),
                'volatility' => data_get($niche, 'volatility'),
                'direction' => data_get($niche, 'direction'),
                'state_cluster' => data_get($niche, 'state_cluster'),
            ] : null,
            'semantic_group' => $semanticGroup,
            'parent_semantic_group' => $parentGroup,
            'same_semantic_group' => $parent
                ? $this->semanticGroups->exactParentCompatible(
                    $parent,
                    (string) data_get($semanticGroup, 'symbol', '*'),
                    (string) data_get($semanticGroup, 'timeframe', '*'),
                    $family,
                    $niche,
                )
                : false,
            'parent_tier' => $parentTier,
            'parent_selection' => $parentSelection,
            'inherited_parameter_keys' => $inheritedKeys,
            'inherited_parameter_count' => count($inheritedKeys),
            'changed_parameter_keys' => $changedKeys,
            'preserved_parameter_count' => count(array_diff($inheritedKeys, $changedKeys)),
            'confirmed_beneficial_traits' => $traits,
            'confirmed_beneficial_traits_by_parent' => $traitsByParent,
            'parent_progress' => $progress,
            'reset_reason' => $parent ? null : 'no_parent_available',
            'promotion_evidence' => false,
            'rule' => 'Carry only the exact semantic parent parameters and independently confirmed traits; otherwise start from the group root/default seed. Change only the declared bounded experiment and re-earn every gate.',
        ];
    }

    private function confirmedParentTraits(?ModelVersion $parent): array
    {
        if (! $parent) return [];
        $agentIds = LabAgent::query()->where('model_version_id', $parent->id)->pluck('id')->all();
        if ($agentIds === []) return [];
        $query = MutationMemory::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('outcome', 'beneficial')
            ->where('independent_confirmation_count', '>=', 2)
            ->where('non_target_regression_status', 'passed')
            ->latest('updated_at');
        $traitLimit = (int) config('services.lab_selection.confirmed_parent_traits_limit', 0);
        if ($traitLimit > 0) $query->take($traitLimit);
        return $query->get()
            ->map(fn (MutationMemory $memory): array => [
                'parameter_key' => $memory->parameter_key,
                'old_value' => data_get($memory->old_value, 'value'),
                'new_value' => data_get($memory->new_value, 'value'),
                'forward_delta' => (float) $memory->forward_delta,
                'market_regime' => $memory->market_regime,
                'confidence' => (float) $memory->confidence,
                'independent_confirmation_count' => (int) $memory->independent_confirmation_count,
                'promotion_evidence' => false,
            ])->values()->all();
    }

    private function mutationScope(string $symbol, string $timeframe, string $family, int $seed): string
    {
        $scopes = [];
        $performanceQuery = ModelMarketPerformance::query()
            ->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)
            ->where('evidence_status', 'valid')
            ->latest('updated_at');
        $sourceLimit = (int) config('services.lab_selection.mutation_scope_source_limit', 0);
        if ($sourceLimit > 0) $performanceQuery->take($sourceLimit);
        $performanceQuery->get()
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

    /** Blend modules using the adaptive capability provenance contract. */
    private function skillCrossover(string $family, $parents, array $fallback, int $seed, array $capabilityGenome = []): array
    {
        $parents = collect($parents)->filter()->values();
        if ($parents->isEmpty()) return [$this->randomParameters($family, $seed), [], []];
        $child = $fallback;
        $sources = [];
        $geneProvenance = [];
        foreach (array_keys($this->schemas->schema($family)) as $key) {
            $skill = match (true) {
                in_array($key, ['differential_target_regime', 'differential_router_version', 'trend_down_strength_min', 'trend_down_pullback_atr_fraction', 'high_volatility_wait', 'range_signal_mode'], true)
                    => 'router_skill',
                in_array($key, ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'], true) => 'exit_skill',
                in_array($key, ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'], true) => 'risk_skill',
                in_array($key, ['lookback', 'confirmation_candles', 'trend_strength_min', 'pullback_atr_fraction', 'roc_threshold', 'deviation'], true) => 'entry_timing_skill',
                in_array($key, ['minimum_signal_confidence', 'confidence_calibration_enabled', 'confidence_calibration_min_samples', 'confidence_ev_lower_bound_enabled', 'meta_label_enabled', 'meta_label_min_history', 'meta_label_min_pf', 'meta_label_risk_multiplier'], true) => 'confidence_calibration_skill',
                default => 'cost_robustness_skill',
            };
            $module = match ($skill) {
                'router_skill' => 'router',
                'exit_skill' => 'exit',
                'risk_skill' => 'risk',
                'entry_timing_skill' => 'entry',
                'confidence_calibration_skill' => 'confidence_calibration',
                default => 'execution_cost',
            };
            $parameterSource = (array) data_get($capabilityGenome, "parameter_sources.{$key}", []);
            $provenanceParentId = data_get($parameterSource, 'source_parent_id');
            $parent = $provenanceParentId
                ? $parents->firstWhere('id', (int) $provenanceParentId)
                : null;
            if (! $parent) {
                $moduleContributor = collect((array) data_get($capabilityGenome, "modules.{$module}.contributors", []))
                    ->first(fn (array $contributor): bool => in_array($key, (array) data_get($contributor, 'parameter_keys', []), true));
                $parent = $parents->firstWhere('id', (int) data_get($moduleContributor, 'parent_model_version_id', 0));
                if ($parent) {
                    $parameterSource = [
                        ...$parameterSource,
                        'source_parent_id' => $parent->id,
                        'source_module' => $module,
                        'source_evidence_id' => data_get($moduleContributor, 'source_evidence_id'),
                        'source_confidence' => data_get($moduleContributor, 'evidence_confidence', 0),
                        'contribution_weight' => data_get($moduleContributor, 'contribution_weight', 0),
                    ];
                }
            }
            $parent ??= $parents->sortByDesc(fn (ModelVersion $model) => (float) data_get($model->metadata, "skill_tree.{$skill}", 0))->first();
            if ($parent && array_key_exists($key, $parent->parameters ?? [])) {
                $child[$key] = $parent->parameters[$key];
                $sources[$skill] = $parent->id;
                $geneProvenance[$key] = [
                    'source_parent_id' => (int) data_get($parameterSource, 'source_parent_id', $parent->id),
                    'source_module' => (string) data_get($parameterSource, 'source_module', $module),
                    'source_evidence_id' => data_get($parameterSource, 'source_evidence_id'),
                    'source_confidence' => (float) data_get($parameterSource, 'source_confidence', 0),
                    'contribution_weight' => (float) data_get($parameterSource, 'contribution_weight', 0),
                    'scope' => data_get($parameterSource, 'scope'),
                    'parameter_hash' => hash('sha256', json_encode([$key => $parent->parameters[$key]], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                    'inheritance_rule' => 'module_bundle_copy_no_blind_scalar_blend',
                    'child_replay_required' => true,
                ];
            }
        }
        return [$child, $sources, $geneProvenance];
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

    private function sealParameterIntegrity(ModelVersion $model, string $family): void
    {
        $parameters = (array) $model->parameters;
        $fingerprintParameters = $parameters;
        ksort($fingerprintParameters);
        $expectedFingerprint = hash('sha256', $family.'|'.json_encode($fingerprintParameters, JSON_PRESERVE_ZERO_FRACTION));
        $expectedUniversalHash = hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
        $metadata = (array) $model->metadata;
        $repairs = [];

        if (data_get($metadata, 'parameter_fingerprint') !== $expectedFingerprint) {
            $repairs['parameter_fingerprint'] = [
                'old' => data_get($metadata, 'parameter_fingerprint'),
                'new' => $expectedFingerprint,
            ];
            $metadata['parameter_fingerprint'] = $expectedFingerprint;
        }
        if (data_get($metadata, 'universal_genome.local_adapter.parameters_hash') !== $expectedUniversalHash) {
            $repairs['universal_genome.local_adapter.parameters_hash'] = [
                'old' => data_get($metadata, 'universal_genome.local_adapter.parameters_hash'),
                'new' => $expectedUniversalHash,
            ];
            data_set($metadata, 'universal_genome.local_adapter.parameters_hash', $expectedUniversalHash);
        }

        if ($repairs === []) return;

        $metadata['parameter_integrity_repair'] = [
            'protocol' => 'model_identity_reconciliation_v1',
            'checks' => $repairs,
            'parameters_unchanged' => true,
            'promotion_evidence' => false,
            'recorded_at' => now()->toIso8601String(),
        ];
        $model->metadata = $metadata;
        $model->save();
    }

    private function diff(array $old, array $new): array
    {
        return collect($new)->filter(fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value)
            ->map(fn ($value, $key) => ['old' => $old[$key] ?? null, 'new' => $value])->all();
    }
}
