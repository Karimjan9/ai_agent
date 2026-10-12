<?php

namespace App\Services;

use App\Models\AgentDiagnosis;
use App\Models\AgentFailureCase;
use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class LabPopulationService
{
    use LabPopulationConstructionTrait;
    use LabPopulationReplacementTrait;
    use LabPopulationPlanningTrait;
    use LabPopulationCouncilPlanningTrait;
    use LabPopulationAgentFactoryTrait;
    use LabPopulationMutationTrait;
    use LabPopulationLineageTrait;

    public const CONSTRUCTOR_LOCK_TTL_SECONDS = 3000;

    /** Read-only proof reuse is confined to one invocation holding this constructor's mutex. */
    private bool $nativeFollowupConstructorInvocation = false;
    private ?array $nativeFollowupInvocationProof = null;
    private ?SpecialistCouncilResearchFeedbackService $nativeFollowupFeedbackOwner = null;
    private ?object $nativeFollowupSourceFenceScope = null;

    /** @var array{status: string, reason_code: string, retryable: bool, context: array<string, mixed>} */
    private array $lastBuildOutcome = [
        'status' => 'blocked',
        'reason_code' => 'POPULATION_BUILD_NOT_STARTED',
        'retryable' => false,
        'context' => [],
    ];

    private function rootExperimentPortfolioPlan(array $plan, AiLaboratory $lab): array
    {
        return app(RootExperimentPortfolioPlannerService::class)->plan($plan, $lab);
    }

    public const ACTIVE_GENERATION_STATUSES = [
        'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation', 'research_reserved',
    ];

    public const AUTHORIZED_COUNCIL_PANEL_TRIGGER = 'specialist_council_independent_panel';

    public const AUTHORIZED_COUNCIL_PANEL_INTENT_PROTOCOL = 'authorized_specialist_council_panel_intent_v1';

    /** Screening is terminal only after every agent has a terminal outcome. */
    public const TERMINAL_GENERATION_STATUSES = [
        'screened', 'completed', 'technical_quarantine', 'abandoned', 'failed',
    ];

    public const GENERATION_PROTOCOL = 'g98_failure_eliminator_v1';

    public const TARGETED_RESCUE_PROFILE_PROTOCOL = 'targeted_failure_profile_v2';

    public const TARGETED_RESCUE_CURRICULUM = 'temporal_edge_audit_v4_state_timing';

    public const GATE_MARGIN_RESCUE_CURRICULUM = 'gate_margin_bounded_anchor_v1';

    public const TEMPORAL_STATE_PERSISTENCE_HYPOTHESIS = 'temporal_state_persistence_v1';

    public const ROLE_COMPLETE_COUNCIL_PROTOCOL = 'role_complete_council_v1';

    public const POPULATION_GROUP_PROTOCOL = 'population_group_checkpoint_v1';

    public const SPECIALIST_COUNCIL_PROTOCOL = 'specialist_council_v1';

    public const NATIVE_COUNCIL_INTENT_PROTOCOL = 'native_specialist_council_intent_v1';

    public const POPULATION_GROUP_SEATS = 4;

    public const ANCHOR_SIBLING_PROTOCOL = 'failure_repair_anchor_siblings_v1';

    public const ROOT_EXPERIMENT_PORTFOLIO_PROTOCOL = 'root_experiment_portfolio_v2';

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
     *
     * This v4 curriculum is driven by the immutable G12 cell audit. The
     * failure labels identify a weak month/chunk/session, but those labels
     * remain diagnostic coordinates and never become executable selectors.
     * The temporal seats therefore use runtime-active state/persistence genes
     * and keep one declared causal cell per child; they do not change a gate
     * or combine multiple causal cells in one child.
     */
    public const TARGETED_RESCUE_GROUP_PLAN = [
        'volatility_session_stability' => [
            'rescue_objective' => 'pf_stress_cost',
            'specialist_role' => 'pf_stress_specialist',
            'targets' => ['profit_factor', 'stress_cost', 'profit_factor', 'stress_cost'],
            // Keep the semantic PF/stress lane on the active hybrid topology,
            // but test the observed stop/target asymmetry independently.
            'family_preference' => ['hybrid', 'volatility'],
            'gene_plan' => ['atr_stop_multiplier', 'atr_target_multiplier', 'max_spread_atr_ratio', 'trailing_atr_multiplier'],
        ],
        'monthly_survival' => [
            'rescue_objective' => 'temporal_calendar_stability',
            'specialist_role' => 'temporal_calendar_specialist',
            'targets' => ['temporal_stability', 'temporal_stability', 'temporal_stability', 'temporal_stability'],
            'family_preference' => ['hybrid', 'session'],
            // G12's weak cell was concentrated in a late UTC session and the
            // final chronological chunk. Session labels are diagnostic only;
            // these four genes test active state/persistence controls without
            // changing entry indicators or using a month as a feature.
            'gene_plan' => ['transition_firewall_enabled', 'dynamic_cooldown_enabled', 'loss_cooldown_candles', 'time_stop_candles'],
        ],
        'regime_coverage' => [
            'rescue_objective' => 'regime_specialist',
            'specialist_role' => 'regime_coverage_specialist',
            'targets' => ['regime_coverage', 'regime_coverage', 'regime_coverage', 'regime_coverage'],
            // Differential routing is active on the lighthouse and exposes
            // regime-specific genes without importing the restrictive
            // regime_ensemble signal envelope.
            'family_preference' => ['differential_router', 'regime_ensemble', 'hybrid'],
            // Directional performance was asymmetric in the audit; keep the
            // regime lane paired/isolated and give both trend directions an
            // independent timing sensitivity seat.
            'gene_plan' => ['trend_up_strength_min', 'trend_down_strength_min', 'trend_up_roc_period', 'trend_down_roc_period'],
            'regime_plan' => ['trend_up', 'trend_down', 'trend_up', 'trend_down'],
        ],
        'exit_topology' => [
            'rescue_objective' => 'non_target_regression',
            'specialist_role' => 'non_target_regression_specialist',
            'targets' => ['drawdown_risk', 'drawdown_risk', 'drawdown_risk', 'drawdown_risk'],
            'family_preference' => ['hybrid', 'differential_router'],
            'gene_plan' => ['time_stop_candles', 'partial_target_atr_multiplier', 'partial_take_profit_fraction', 'max_loss_streak_before_wait'],
        ],
        'portfolio_router' => [
            'rescue_objective' => 'architecture_control',
            'specialist_role' => 'architecture_control_specialist',
            'targets' => ['architecture', 'architecture', 'architecture', 'architecture'],
            'family_preference' => ['hybrid', 'differential_router'],
            // Hybrid has two real runtime topologies. Two frozen controls and
            // two repeated topology probes make the architecture contrast
            // auditable without fabricating a fifth architecture.
            'architecture_plan' => [
                ['variant' => null, 'control_only' => true],
                ['variant' => 'regime_consensus', 'control_only' => false],
                ['variant' => null, 'control_only' => true],
                ['variant' => 'regime_consensus', 'control_only' => false],
            ],
        ],
    ];

    private const LABS = [
        // Every normal lab exposes the five executable tactic families. The
        // composition planner can therefore test each library member without
        // silently substituting a differential-router placeholder. Extra
        // specialist families remain available as complementary research.
        'XAUUSD' => ['name' => 'XAUUSD Unified MTF Organism', 'families' => ['trend', 'breakout', 'volatility', 'mean_reversion', 'session', 'hybrid', 'regime_ensemble', 'differential_router']],
        'EURUSD' => ['name' => 'EURUSD Lab', 'families' => ['trend', 'breakout', 'volatility', 'mean_reversion', 'session', 'hybrid', 'regime_ensemble']],
        'GBPUSD' => ['name' => 'GBPUSD Lab', 'families' => ['trend', 'breakout', 'volatility', 'mean_reversion', 'session', 'momentum', 'hybrid', 'regime_ensemble']],
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

    /** Cached historical topologies used by the bounded novelty contract, scoped per generation build. */
    private array $historicalParameterFingerprints = [];

    /** Cached parent candidates; large immutable evidence documents are hydrated once per constructor run. */
    private array $parentPerformanceSnapshots = [];

    /** Immutable archive reads reused by replacement attempts in one constructor run. */
    private array $archiveFrontierSnapshots = [];

    /** Diagnostic migration reads reused by the same semantic cell in one constructor run. */
    private array $archiveMigrationSnapshots = [];

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
        private LearningMemoryService $learningMemory,
        private LearningVelocityGateService $learningVelocity,
        private ParentMentorBrokerService $parentMentorBroker,
        private ParentAwareCreditService $parentAwareCredit,
        private ShadowResearchGovernorService $shadowResearch,
        private RescueCircuitBreakerService $rescueCircuitBreaker,
        private ResearchAllocationPolicyService $researchAllocation,
        private StrategyTacticRiskCompositionPlannerService $compositionPlanner,
        private LabDataEdgeAuditService $dataEdgeAudits,
    ) {}

    public function ensureLaboratories(): void
    {
        $organismSymbol = strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'));
        $organismStorageTimeframe = strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        foreach (self::LABS as $symbol => $config) {
            $storageTimeframe = $symbol === $organismSymbol ? $organismStorageTimeframe : 'H1';
            AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => $storageTimeframe], [
                'name' => $config['name'], 'timeframe' => $storageTimeframe,
                'strategy_families' => $config['families'], 'is_active' => true,
                'lifecycle_mode' => $symbol === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL ? 'lighthouse' : 'shadow',
            ]);
        }

        // Non-XAUUSD symbols retain their legacy M15 shadow workspaces. XAUUSD
        // has one active symbol-scoped population only; its historical M15 row
        // remains an inactive evidence workspace so immutable records and old
        // foreign keys are preserved without granting generation ownership.
        foreach (self::LABS as $symbol => $config) {
            if ($symbol === $organismSymbol) {
                AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => 'M15'], [
                    'name' => 'XAUUSD MTF Organism - M15 Evidence Archive',
                    'timeframe' => 'M15',
                    'strategy_families' => array_values(array_unique([...$config['families'], 'regime_ensemble'])),
                    'is_active' => false,
                    'lifecycle_mode' => 'shadow',
                ]);

                continue;
            }
            AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => 'M15'], [
                'name' => "{$symbol} M15 Specialist Lab", 'timeframe' => 'M15',
                'strategy_families' => array_values(array_unique([...$config['families'], 'regime_ensemble'])),
                'is_active' => true,
                'lifecycle_mode' => 'shadow',
            ]);
        }
    }

    /** @return array{status: string, reason_code: string, retryable: bool, context: array<string, mixed>} */
    public function lastBuildOutcome(): array
    {
        return $this->lastBuildOutcome;
    }

    public function constructorIsActive(string $symbol, string $timeframe = 'H1'): bool
    {
        $symbol = strtoupper($symbol);
        if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            $timeframe = (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1');
        }
        $probe = Cache::lock($this->constructorLockKey($symbol, strtoupper($timeframe)), 5);
        if (! $probe->get()) {
            return true;
        }
        $probe->release();

        return false;
    }

    /** @return array<string, mixed> */
    public function constructorStatus(string $symbol, string $timeframe = 'H1'): array
    {
        $symbol = strtoupper($symbol);
        if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            $timeframe = (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1');
        }
        $timeframe = strtoupper($timeframe);
        $owner = Cache::get($this->constructorOwnerKey($symbol, $timeframe));

        return [
            'protocol' => 'lab_population_constructor_status_v1',
            'active' => $this->constructorIsActive($symbol, $timeframe),
            'owner' => is_array($owner) ? $owner : null,
            'observed_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
    }

    /**
     * Recover only a stale constructor whose recorded process is provably
     * dead on this host. A live or unverifiable owner is never pre-empted.
     *
     * @return array<string,mixed>
     */
    public function recoverStaleConstructorLease(string $symbol, string $timeframe = 'H1'): array
    {
        $symbol = strtoupper($symbol);
        if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            $timeframe = (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1');
        }
        $timeframe = strtoupper($timeframe);
        $lockKey = $this->constructorLockKey($symbol, $timeframe);
        $ownerKey = $this->constructorOwnerKey($symbol, $timeframe);
        $lock = Cache::lock($lockKey, 5);
        if ($lock->get()) {
            $lock->release();

            return ['status' => 'not_active', 'recovered' => false, 'promotion_evidence' => false];
        }

        $owner = Cache::get($ownerKey);
        if (! is_array($owner)) {
            return ['status' => 'owner_unknown', 'recovered' => false, 'promotion_evidence' => false];
        }
        $heartbeatAt = (string) ($owner['heartbeat_at'] ?? $owner['acquired_at'] ?? '');
        try {
            $ageSeconds = $heartbeatAt === '' ? 0 : max(
                0,
                now()->utc()->timestamp - CarbonImmutable::parse($heartbeatAt)->utc()->timestamp,
            );
        } catch (\Throwable) {
            return ['status' => 'heartbeat_invalid', 'recovered' => false, 'promotion_evidence' => false];
        }
        $staleAfter = max(120, (int) config(
            'services.lifecycle_orchestrator.constructor_stale_heartbeat_seconds',
            180,
        ));
        $pid = (int) ($owner['pid'] ?? 0);
        $ownerHost = trim((string) ($owner['hostname'] ?? ''));
        $localHost = $this->constructorHostname();
        $command = str_replace('/', DIRECTORY_SEPARATOR, (string) ($owner['command'] ?? ''));
        $legacyLocalCommand = $ownerHost === ''
            && str_contains(strtolower($command), strtolower(str_replace('/', DIRECTORY_SEPARATOR, base_path('artisan'))));
        $localOwner = ($ownerHost !== '' && hash_equals(strtolower($localHost), strtolower($ownerHost)))
            || $legacyLocalCommand;
        if ($ageSeconds < $staleAfter || $pid <= 0 || ! $localOwner) {
            return [
                'status' => 'active_or_unverifiable', 'recovered' => false,
                'stale_age_seconds' => $ageSeconds, 'owner' => $owner,
                'promotion_evidence' => false,
            ];
        }
        $running = $this->localConstructorProcessIsRunning($pid);
        if ($running !== false) {
            return [
                'status' => $running ? 'owner_process_running' : 'owner_process_unknown',
                'recovered' => false, 'stale_age_seconds' => $ageSeconds,
                'owner' => $owner, 'promotion_evidence' => false,
            ];
        }

        // Fence against a heartbeat/process-owner change between the proof
        // above and forceRelease().
        $currentOwner = Cache::get($ownerKey);
        if (! is_array($currentOwner)
            || (int) ($currentOwner['pid'] ?? 0) !== $pid
            || (string) ($currentOwner['heartbeat_at'] ?? '') !== (string) ($owner['heartbeat_at'] ?? '')) {
            return ['status' => 'owner_changed', 'recovered' => false, 'promotion_evidence' => false];
        }

        $lock->forceRelease();
        Cache::forget($ownerKey);
        Log::warning('Recovered stale population constructor lease from dead local process.', [
            'symbol' => $symbol, 'timeframe' => $timeframe,
            'stale_pid' => $pid, 'stale_age_seconds' => $ageSeconds,
            'replacement_pid' => getmypid(), 'owner' => $owner,
        ]);

        return [
            'status' => 'recovered_dead_local_owner', 'recovered' => true,
            'stale_age_seconds' => $ageSeconds, 'owner' => $owner,
            'promotion_evidence' => false,
        ];
    }

    /**
     * An incomplete lineage head owns generation authority even while its
     * dispatch-safe projection is technical_quarantine between recovery runs.
     */
    public static function constructionIncomplete(?LabGeneration $generation): bool
    {
        if ($generation === null
            || ! in_array((string) $generation->status, ['draft', 'technical_quarantine'], true)) {
            return false;
        }

        $plan = (array) data_get($generation->trigger_context, 'generation_plan', []);
        if ($plan === []) {
            return false;
        }

        $created = $generation->agents()->count();
        if ($created >= count($plan)) {
            return false;
        }

        // A missing seat is resumable until its original construction and a
        // bounded continuation both prove the same deterministic no-mutation
        // failure. After that the cohort remains technical history; treating
        // it as active work makes the arbiter settle the same 19/20 head forever.
        if ((string) $generation->status === 'technical_quarantine'
            && self::constructionExhaustedAfterRetry($generation, count($plan), $created)) {
            return false;
        }

        return true;
    }

    private static function constructionExhaustedAfterRetry(LabGeneration $generation, int $planned, int $created): bool
    {
        $context = (array) $generation->trigger_context;
        $continuation = (array) data_get($context, 'constructor_continuation', []);
        $abortMatches = (string) data_get($context, 'constructor_contract_abort.reason_code') === 'INCOMPLETE_GENERATION_POPULATION'
            || (string) data_get($context, 'shadow_research_constructor_abort.reason_code') === 'INCOMPLETE_SHADOW_RESEARCH_POPULATION'
            || (string) data_get($context, 'controlled_rescue_constructor_abort.reason_code') === 'INCOMPLETE_CONTROLLED_RESCUE_POPULATION';
        if (! $abortMatches
            || (bool) ($continuation['complete'] ?? true)
            || (array) ($continuation['created_slots_this_run'] ?? []) !== []
            || (int) ($continuation['planned_slots'] ?? 0) !== $planned
            || (int) data_get($context, 'constructor_audit.created_agents', -1) !== $created) {
            return false;
        }

        $completed = array_values(array_unique(array_map('intval', (array) ($continuation['completed_slots'] ?? []))));
        if (count($completed) !== $created || array_diff($completed, range(1, $planned)) !== []) {
            return false;
        }
        $missing = array_values(array_diff(range(1, $planned), $completed));
        $initial = (array) data_get($context, 'constructor_audit.skipped_zero_diff_slots', []);
        $repeated = (array) ($continuation['failures'] ?? []);
        if ($missing === [] || count($initial) !== count($missing) || count($repeated) !== count($missing)) {
            return false;
        }

        foreach ($missing as $slot) {
            $first = collect($initial)->firstWhere('slot', $slot);
            $second = collect($repeated)->firstWhere('slot', $slot);
            $reason = (string) data_get($first, 'reason', '');
            if (! in_array($reason, [
                'CONSTRUCTOR_MUTATION_INVARIANT_FAILED',
                'NO_LEGAL_NONZERO_MUTATION',
                'HISTORICAL_GENE_EXHAUSTED',
            ], true) || $reason !== (string) data_get($second, 'reason', '')) {
                return false;
            }
        }

        return true;
    }

    private function blocked(string $reasonCode, bool $retryable = false, array $context = []): ?LabGeneration
    {
        $this->lastBuildOutcome = [
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'retryable' => $retryable,
            'context' => [...$this->lastBuildOutcome['context'], ...$context],
        ];

        return null;
    }

}
