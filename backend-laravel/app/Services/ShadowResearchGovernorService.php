<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;

/**
 * Opens a bounded research-only lane when a complete frozen control fails.
 *
 * This is deliberately not a promotion service.  It only decides whether a
 * healthy, complete control failure is informative enough to justify another
 * research cohort and labels that cohort.  Parent, paper and champion gates
 * remain owned by their existing services.
 */
class ShadowResearchGovernorService
{
    public const PROTOCOL = 'shadow_research_governor_v1';
    public const ALLOCATION_PROTOCOL = ResearchAllocationPolicyService::SHADOW_ALLOCATION_PROTOCOL;

    /** Exact 20-seat budget requested by the research contract. */
    public const SMART_COURAGE_SHARES = ResearchAllocationPolicyService::SHADOW_SMART_SHARES;

    /** Rescue-blocked 20-seat escape budget: 1/6/5/4/3/1. */
    public const SHADOW_ESCAPE_SHARES = ResearchAllocationPolicyService::SHADOW_ESCAPE_SHARES;

    public function __construct(
        private FrozenControlParityService $parity,
        private ResearchAllocationPolicyService $allocations,
    ) {}

    /** @return array<string, mixed> */
    public function assess(AiLaboratory|LabGeneration $scope, bool $targetedRescueBlocked = false): array
    {
        $lab = $scope instanceof LabGeneration
            ? $scope->loadMissing('laboratory')->laboratory
            : $scope;
        $generation = $scope instanceof LabGeneration
            ? $scope->loadMissing('agents.modelVersion')
            : $lab?->generations()->latest('generation')->first();
        // Constructor-aborted shadow rows are immutable technical history,
        // not control sources. Several bounded attempts may be quarantined in
        // a row, so skip the whole contiguous failed suffix and recover the
        // latest usable terminal cohort rather than stopping on the first
        // earlier partial constructor.
        while ($scope instanceof AiLaboratory && $this->isConstructorAbortedShadow($generation)) {
            $previous = $lab?->generations()
                ->where('generation', '<', (int) $generation->generation)
                ->latest('generation')
                ->first();
            if (! $previous) {
                $generation = null;
                break;
            }
            $generation = $previous;
        }

        $base = [
            'protocol' => self::PROTOCOL,
            'scope' => [
                'symbol' => strtoupper((string) ($lab?->symbol ?? '')),
                'timeframe' => strtoupper((string) ($lab?->timeframe ?? '')),
            ],
            'allowed' => false,
            'shadow_only' => true,
            'promotion_evidence' => false,
            'mutation_credit' => false,
            'parent_promotion' => false,
            'official_paper_eligible' => false,
        ];

        if (! (bool) config('services.lab_selection.shadow_research_enabled', true)) {
            return [
                ...$base,
                'status' => 'disabled',
                'reason_codes' => ['SHADOW_RESEARCH_DISABLED'],
                'next_action' => 'keep_normal_promotion_lane',
            ];
        }

        if (! $lab || ! $generation) {
            return [
                ...$base,
                'status' => 'not_applicable',
                'reason_codes' => ['NO_GENERATION'],
                'next_action' => 'collect_complete_control_evidence',
            ];
        }

        $generation->loadMissing('agents.modelVersion');
        $hasExplicitControl = $generation->agents->contains(
            fn (LabAgent $agent): bool => $this->parity->isControl($agent),
        );
        if (! $hasExplicitControl) {
            return [
                ...$base,
                'generation_id' => (int) $generation->id,
                'generation' => (int) $generation->generation,
                'status' => 'not_applicable',
                'reason_codes' => ['NO_EXPLICIT_FROZEN_CONTROL'],
                'next_action' => 'keep_normal_promotion_lane',
            ];
        }

        $parity = $this->parity->assess($generation);
        $status = (string) data_get($parity, 'status', 'incomplete');
        $result = [
            ...$base,
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'control_parity' => $parity,
            'status' => $status,
            'reason_codes' => [],
            'next_action' => 'keep_promotion_lane_closed_until_control_is_interpretable',
        ];

        if ($status === 'passed') {
            return [
                ...$result,
                'status' => 'control_passed',
                'reason_codes' => ['CONTROL_PASSED'],
                'next_action' => 'use_official_candidate_selection_and_unchanged_promotion_gates',
            ];
        }

        if ($status !== 'control_failed') {
            return [
                ...$result,
                'status' => $status === 'incomplete' ? 'evidence_incomplete' : $status,
                'reason_codes' => ['CONTROL_TECHNICAL_OR_EVIDENCE_INCOMPLETE'],
                'next_action' => 'recover_snapshot_execution_trace_and_control_evidence',
            ];
        }

        $health = $this->evidenceHealth($generation);
        if (! $health['healthy']) {
            return [
                ...$result,
                'status' => 'evidence_incomplete',
                'evidence_health' => $health,
                'reason_codes' => ['CONTROL_FAILURE_NOT_OPERATIONALLY_CLEAN'],
                'next_action' => 'recover_technical_evidence_before_shadow_mutation',
            ];
        }

        $consecutive = $this->consecutiveShadowGenerations($lab, $generation);
        $maximum = max(1, (int) config('services.lab_selection.shadow_research_max_consecutive_generations', 3));
        if ($consecutive >= $maximum) {
            return [
                ...$result,
                'status' => 'shadow_budget_exhausted',
                'evidence_health' => $health,
                'consecutive_shadow_generations' => $consecutive,
                'max_consecutive_shadow_generations' => $maximum,
                'reason_codes' => ['SHADOW_RESEARCH_BUDGET_EXHAUSTED'],
                'next_action' => 'architecture_or_data_edge_audit_before_more_mutation',
            ];
        }

        return [
            ...$result,
            'status' => 'control_failed_shadow_allowed',
            'allowed' => true,
            'evidence_health' => $health,
            'consecutive_shadow_generations' => $consecutive,
            'max_consecutive_shadow_generations' => $maximum,
            'allocation' => $this->allocation($this->targetPopulation(), $targetedRescueBlocked),
            'escape_lane' => $targetedRescueBlocked,
            'reason_codes' => ['COMPLETE_CONTROL_FAILURE_WITH_HEALTHY_EVIDENCE'],
            'next_action' => 'create_bounded_shadow_research_cohort',
            'rule' => 'Shadow evidence may guide paired requalification later; it cannot create mutation credit, parent or paper evidence now.',
        ];
    }

    /** @return array<string, mixed> */
    public function allocation(int $population, bool $targetedRescueBlocked = false): array
    {
        return [
            ...$this->allocations->shadowAllocation($population, $targetedRescueBlocked),
            'rule' => $targetedRescueBlocked
                ? 'Rescue paused: architecture/state, robustness/holdout, volume/session/M15, regime/abstention and bounded adversarial research only; each executable family/data lane receives its own frozen control; no targeted repair or mentor credit.'
                : 'Nominal control share is a budget floor; materialize one frozen control per executable family and price/volume data lane before interpreting any mutation; targeted/proven lanes exploit evidence; architecture/robustness/volume/random lanes explore within schema and snapshot bounds.',
        ];
    }

    /**
     * Label a normal plan with the smart-courage shadow budget. Existing
     * family/niche context is retained so the allocation changes search posture
     * without erasing the council's semantic cell.
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    public function applyAllocation(array $plan, array $assessment, int $generationId = 0, bool $targetedRescueBlocked = false): array
    {
        if (! (bool) data_get($assessment, 'allowed', false) || $plan === []) return $plan;

        $targetedRescueBlocked = $targetedRescueBlocked || (bool) data_get($assessment, 'escape_lane', false);
        $allocation = $this->allocation(count($plan), $targetedRescueBlocked);
        $roles = [];
        foreach ((array) data_get($allocation, 'counts', []) as $role => $count) {
            for ($index = 0; $index < $count; $index++) $roles[] = $role;
        }
        $roles = array_slice($roles, 0, count($plan));
        while (count($roles) < count($plan)) $roles[] = 'targeted_repair';

        // A single global control is not causal when the population mixes
        // executable families or price/volume datasets. Materialize the
        // minimum control seat for every family/data-lane pair while keeping
        // the population size fixed. The converted seat remains a frozen
        // diagnostic control; it never creates promotion evidence.
        $controlSeats = $this->materializeControlPairSeats($plan, $roles);
        $roles = $controlSeats['roles'];
        $controlAssignments = $controlSeats['assignments'];

        $temporalShadowSeat = 0;
        $architectureShadowSeat = 0;
        $robustnessShadowSeat = 0;
        $volumeShadowSeat = 0;
        foreach ($plan as $index => &$slot) {
            $role = $roles[$index] ?? 'targeted_repair';
            $existingNiche = (array) ($slot['niche'] ?? []);
            $target = (string) ($slot['target'] ?? 'profit_factor');
            $family = (string) ($slot['family'] ?? '');
            $controlLane = data_get($controlAssignments, $index.'.lane');
            $executionLane = $controlLane !== null
                ? (string) $controlLane
                : ($role === 'volume_m15_specialist' ? 'volume' : 'price');
            $seed = hash('sha256', implode('|', [
                (string) data_get($assessment, 'scope.symbol', ''),
                (string) data_get($assessment, 'scope.timeframe', ''),
                (string) $generationId,
                (string) ($index + 1),
                self::PROTOCOL,
            ]));

            $slot['shadow_research_lane'] = $this->slotContract($role, $index + 1, $seed);
            $slot['niche'] = [
                ...$existingNiche,
                'shadow_research_lane' => $slot['shadow_research_lane'],
                // The shadow allocation owns the dataset lane.  Clear a
                // stale inherited volume marker before assigning the role so
                // a temporal/regime seat cannot accidentally consume the
                // volume snapshot merely because its source parent used it.
                'volume_shadow' => $executionLane === 'volume',
                // A normal plan can carry a checkpoint/control marker from
                // its previous council seat. Shadow allocation owns the
                // current seat, so stale control flags must not turn the
                // first volume/robustness probe into a silent clone.
                'control_only' => false,
                'architecture_control_only' => false,
                'shadow_only' => true,
                'promotion_evidence' => false,
                'mutation_credit' => false,
                'parent_promotion' => false,
                'official_paper_eligible' => false,
            ];

            // Every non-control shadow seat is paired with the frozen control
            // from the same generation/family.  The pair key is metadata only;
            // it never manufactures a control result or relaxes a gate.
            $controlPairKey = hash('sha256', json_encode([
                'protocol' => 'frozen_control_pair_v1',
                'generation_id' => $generationId,
                'symbol' => data_get($assessment, 'scope.symbol'),
                'timeframe' => data_get($assessment, 'scope.timeframe'),
                'family' => $family,
                'execution_lane' => $executionLane,
            ], JSON_UNESCAPED_SLASHES));
            $slot['niche']['control_pair_contract'] = [
                'protocol' => 'frozen_control_pair_v1',
                'pair_key' => $controlPairKey,
                'required_for_candidate' => $role !== 'frozen_control',
                'same_generation' => true,
                'same_symbol_timeframe' => true,
                'same_strategy_family' => true,
                'execution_lane' => $executionLane,
                'strategy_family' => $family,
                'same_execution_contract' => true,
                'missing_control_action' => 'diagnostic_only_no_learning_credit_no_full_replay',
                'promotion_evidence' => false,
            ];

            switch ($role) {
                case 'frozen_control':
                    $slot['origin'] = 'g98_council';
                    $slot['target'] = 'monthly_survival';
                    $slot['evolution_mode'] = 'frozen_control';
                    $slot['niche']['role'] = 'frozen_control';
                    $slot['niche']['specialist_role'] = 'frozen_control';
                    $slot['niche']['control_only'] = true;
                    break;
                case 'proven_gene_refinement':
                    $slot['origin'] = 'g98_council';
                    $slot['evolution_mode'] = 'proven_gene_refinement';
                    $slot['niche']['role'] = 'proven_gene_refinement';
                    $slot['niche']['specialist_role'] = 'skill_mentor_refinement';
                    $slot['niche']['parent_lane'] = 'mentor_assisted';
                    $slot['niche']['proven_gene_or_provisional_required'] = true;
                    break;
                case 'architecture_explorer':
                    $slot['origin'] = 'architecture';
                    $slot['target'] = 'architecture';
                    $slot['evolution_mode'] = 'architecture_explorer';
                    $slot['niche']['role'] = 'architecture_explorer';
                    $slot['niche']['specialist_role'] = 'architecture_explorer';
                    $slot['niche']['architecture_explorer'] = true;
                    $slot['niche']['exploration_domain'] = 'architecture';
                    // A structural seat must change executable entry
                    // behavior. The old rescue lane gave every architecture
                    // seat the same state-machine enum, which produced
                    // telemetry diversity without signal/ledger diversity.
                    $topologyVariant = [
                        'regime_consensus_v1',
                        'transition_hazard_v1',
                        'breakout_retest_v1',
                        'trend_regime_confirmation_v1',
                        'range_reentry_confirmation_v1',
                        'volatility_persistence_v1',
                    ][$architectureShadowSeat++ % 6];
                    $slot['niche']['entry_topology_variant'] = $topologyVariant;
                    $slot['niche']['shadow_mutation_gene'] = 'entry_topology_variant';
                    $slot['niche']['shadow_mutation_contract'] = [
                        'protocol' => 'shadow_structural_mutation_v1',
                        'gene' => 'entry_topology_variant',
                        'variant' => $topologyVariant,
                        'behavioral_change_required' => true,
                        'trade_ledger_delta_required' => true,
                        'promotion_evidence' => false,
                    ];
                    $slot['niche']['architecture_escape_reason'] = $targetedRescueBlocked
                        ? 'temporal_failure_rescue_blocked'
                        : 'structural_entry_topology_diversity';
                    $slot['niche']['architecture_experiment'] = true;
                    $slot['niche']['architecture_escape'] = true;
                    break;
                case 'robustness_split_specialist':
                    $slot['origin'] = 'robust_crossover';
                    $slot['target'] = 'robustness';
                    $slot['evolution_mode'] = 'robustness_split_specialist';
                    $slot['niche']['role'] = 'robustness_split_specialist';
                    $slot['niche']['specialist_role'] = 'robustness_split_specialist';
                    $slot['niche']['robustness_split_contract'] = 'immutable_same_train_forward_split';
                    $slot['niche']['immutable_evaluation_contract'] = true;
                    $slot['niche']['exploration_domain'] = 'robustness_split';
                    $robustnessGenes = [
                        'confidence_calibration_min_samples',
                        'weak_regime_min_samples',
                        'meta_label_min_history',
                        'cooldown_shadow_min_samples',
                        'weak_regime_wait_candles',
                    ];
                    $robustnessGene = $robustnessGenes[$robustnessShadowSeat++ % count($robustnessGenes)];
                    $slot['niche']['shadow_mutation_gene'] = $robustnessGene;
                    $slot['niche']['shadow_mutation_contract'] = [
                        'protocol' => 'shadow_robustness_mutation_v1',
                        'gene' => $robustnessGene,
                        'same_train_forward_split' => true,
                        'one_gene_only' => true,
                        'behavioral_change_required' => true,
                        'trade_ledger_delta_required' => true,
                        'control_pair_required' => true,
                        'promotion_evidence' => false,
                    ];
                    break;
                case 'volume_m15_specialist':
                    $slot['origin'] = 'curiosity_probe';
                    $slot['target'] = 'regime_coverage';
                    $slot['evolution_mode'] = 'volume_m15_specialist';
                    $slot['niche']['role'] = 'volume_m15_specialist';
                    $slot['niche']['specialist_role'] = 'volume_m15_specialist';
                    $slot['niche']['volume_shadow'] = true;
                    $slot['niche']['exploration_domain'] = 'regime_and_volume_shadow';
                    $volumeProbes = [
                        'volume_lane',
                        'volume_lane',
                        'volume_lane',
                        'max_spread_atr_ratio',
                    ];
                    $volumeGene = $volumeProbes[$volumeShadowSeat++ % count($volumeProbes)];
                    $slot['niche']['shadow_mutation_gene'] = $volumeGene;
                    $slot['niche']['shadow_mutation_index'] = max(0, $volumeShadowSeat - 1);
                    $slot['niche']['shadow_mutation_contract'] = [
                        'protocol' => 'shadow_volume_mutation_v1',
                        'gene' => $volumeGene,
                        'freshness_required' => true,
                        'coverage_required' => true,
                        'no_volume_control_required' => true,
                        'behavioral_change_required' => true,
                        'trade_ledger_delta_required' => true,
                        'control_pair_required' => true,
                        'promotion_evidence' => false,
                    ];
                    break;
                case 'regime_abstention_specialist':
                    $slot['origin'] = 'regime_research';
                    $slot['target'] = $targetedRescueBlocked ? 'temporal_stability' : 'regime_coverage';
                    $slot['evolution_mode'] = 'regime_abstention_specialist';
                    $slot['niche']['role'] = 'regime_abstention_specialist';
                    $slot['niche']['specialist_role'] = 'regime_abstention_specialist';
                    $slot['niche']['abstention_explorer'] = true;
                    $slot['niche']['exploration_domain'] = 'regime_and_abstention';
                    if ($targetedRescueBlocked) {
                        $temporalVariant = match ($temporalShadowSeat++ % 3) {
                            0 => 'expiry_age_probe',
                            1 => 'expiry_half_life_probe',
                            default => 'drift_threshold_probe',
                        };
                        $slot['evolution_mode'] = 'temporal_survival_drift_abstention';
                        $slot['niche']['role'] = 'temporal_survival_drift_abstention_specialist';
                        $slot['niche']['specialist_role'] = 'temporal_survival_drift_abstention_specialist';
                        $slot['niche']['temporal_hypothesis'] = [
                            'protocol' => 'temporal_survival_drift_abstention_shadow_v1',
                            'variant' => $temporalVariant,
                            'independent_evidence_required' => true,
                            'three_window_ablation_required' => true,
                            'same_dataset_rescue_forbidden' => true,
                            'mutation_credit' => false,
                            'promotion_evidence' => false,
                        ];
                        $temporalGenes = [
                            'signal_max_age_candles',
                            'signal_decay_half_life_candles',
                            'temporal_drift_zscore_max',
                        ];
                        $temporalGene = $temporalGenes[($temporalShadowSeat - 1) % count($temporalGenes)];
                        $slot['niche']['shadow_mutation_gene'] = $temporalGene;
                        $slot['niche']['shadow_mutation_contract'] = [
                            'protocol' => 'shadow_temporal_mutation_v1',
                            'gene' => $temporalGene,
                            'abstention_behavior_required' => true,
                            'three_window_confirmation_required' => true,
                            'behavioral_change_required' => true,
                            'trade_ledger_delta_required' => true,
                            'control_pair_required' => true,
                            'promotion_evidence' => false,
                        ];
                    }
                    break;
                case 'bounded_random_adversarial':
                    $slot['origin'] = 'curiosity_probe';
                    $slot['target'] = in_array($target, EvolutionGovernorService::CAUSAL_TARGETS, true) ? $target : 'stress_cost';
                    $slot['evolution_mode'] = 'bounded_random_adversarial';
                    $slot['niche']['role'] = 'bounded_random_adversarial';
                    $slot['niche']['specialist_role'] = 'bounded_random_adversarial';
                    $slot['niche']['bounded_random'] = true;
                    $slot['niche']['adversarial_red_team'] = true;
                    $slot['niche']['schema_bounds_only'] = true;
                    $slot['niche']['fixed_seed'] = $seed;
                    $slot['niche']['exploration_domain'] = 'bounded_random_adversarial';
                    break;
                default:
                    $slot['origin'] = in_array((string) ($slot['origin'] ?? ''), ['g98_council', 'targeted_failure_profile'], true)
                        ? $slot['origin'] : 'g98_council';
                    $slot['evolution_mode'] = 'targeted_repair';
                    $slot['niche']['role'] = 'targeted_repair';
                    $slot['niche']['specialist_role'] = $slot['niche']['specialist_role'] ?? 'failure_specific_targeted_repair';
                    $slot['niche']['parent_lane'] = 'autonomous';
                    break;
            }

            if ($role !== 'frozen_control') {
                $slot['niche']['shadow_mutation_contract'] = [
                    ...(array) data_get($slot, 'niche.shadow_mutation_contract', []),
                    'behavioral_change_required' => true,
                    'trade_ledger_delta_required' => true,
                    'control_pair_required' => true,
                    'promotion_evidence' => false,
                ];
            }

            if ($role === 'frozen_control') {
                unset(
                    $slot['niche']['declared_gene'],
                    $slot['niche']['declared_gene_requested'],
                    $slot['niche']['shadow_mutation_gene'],
                    $slot['niche']['shadow_mutation_index'],
                    $slot['niche']['entry_topology_variant'],
                    $slot['niche']['state_machine_variant'],
                    $slot['niche']['architecture_experiment'],
                    $slot['niche']['architecture_escape'],
                    $slot['niche']['architecture_control_only'],
                    $slot['niche']['abstention_explorer'],
                );
                $slot['niche']['control_only'] = true;
                $slot['niche']['control_lane'] = $executionLane;
                $slot['niche']['control_family'] = $family;
                $slot['niche']['volume_shadow'] = $executionLane === 'volume';
                $slot['niche']['shadow_mutation_contract'] = null;
            }
        }
        unset($slot);

        return array_values($plan);
    }

    /**
     * @param array<int, array<string, mixed>> $plan
     * @param array<int, string> $roles
     * @return array{roles: array<int, string>, assignments: array<int, array{lane: string, family: string, key: string}>}
     */
    private function materializeControlPairSeats(array $plan, array $roles): array
    {
        $required = [];
        $controls = [];
        foreach ($plan as $index => $slot) {
            $role = (string) ($roles[$index] ?? 'targeted_repair');
            $lane = $role === 'volume_m15_specialist' ? 'volume' : 'price';
            $family = (string) ($slot['family'] ?? '');
            $key = $lane.'|'.$family;
            $required[$key] = ['lane' => $lane, 'family' => $family, 'key' => $key];
            if ($role === 'frozen_control') $controls[$key] = true;
        }

        $assignments = [];
        foreach ($required as $key => $contract) {
            if (isset($controls[$key])) continue;

            foreach ($plan as $index => $slot) {
                if (($roles[$index] ?? null) === 'frozen_control') continue;
                $role = (string) ($roles[$index] ?? 'targeted_repair');
                $lane = $role === 'volume_m15_specialist' ? 'volume' : 'price';
                $family = (string) ($slot['family'] ?? '');
                if ($lane !== $contract['lane'] || $family !== $contract['family']) continue;

                $roles[$index] = 'frozen_control';
                $assignments[$index] = $contract;
                $controls[$key] = true;
                break;
            }
        }

        return ['roles' => $roles, 'assignments' => $assignments];
    }

    public function isShadowOnlyModel(?ModelVersion $model): bool
    {
        return $model !== null && (
            ((bool) data_get($model->metadata, 'shadow_research_lane.shadow_only', false)
                || data_get($model->metadata, 'shadow_research_lane.protocol') === self::PROTOCOL)
            && data_get($model->metadata, 'shadow_research_lane.requalified', false) !== true
        );
    }

    /** @return array<string, mixed> */
    public function requalification(LabAgent $agent): array
    {
        $generation = $agent->relationLoaded('generation')
            ? $agent->generation
            : LabGeneration::query()->find((int) $agent->lab_generation_id);
        if (! $generation) {
            return ['allowed' => false, 'status' => 'missing_generation', 'promotion_evidence' => false];
        }

        $assessment = $this->assess($generation);

        return [
            'allowed' => data_get($assessment, 'status') === 'control_passed',
            'status' => data_get($assessment, 'status'),
            'generation_id' => (int) $generation->id,
            'control_parity' => data_get($assessment, 'control_parity'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function slotContract(string $role, int $slot, string $seed): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'allocation_protocol' => self::ALLOCATION_PROTOCOL,
            'role' => $role,
            'slot' => $slot,
            'shadow_only' => true,
            'promotion_evidence' => false,
            'mutation_credit' => false,
            'parent_promotion' => false,
            'official_paper_eligible' => false,
            'fixed_seed' => $seed,
        ];
    }

    /** @return array{healthy: bool, reasons: array<int, string>, control_rows: array<int, array<string, mixed>>} */
    private function evidenceHealth(LabGeneration $generation): array
    {
        $activeStatuses = ['draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation'];
        $reasons = [];
        if (in_array((string) $generation->status, $activeStatuses, true)) $reasons[] = 'GENERATION_NOT_TERMINAL';

        foreach ($generation->agents as $agent) {
            if (in_array((string) $agent->lifecycle_status, $activeStatuses, true)) $reasons[] = 'AGENT_NOT_TERMINAL';
            if ((string) $agent->lifecycle_status === 'evaluation_error') $reasons[] = 'EVALUATION_ERROR';
            if ((string) $agent->lifecycle_status === 'technical_quarantine') {
                // Zero-diff is recoverable, but it is still technical evidence.
                // It must never be converted into a strategy mutation signal.
                $reasons[] = 'TECHNICAL_QUARANTINE';
            }
        }

        $controlRows = (array) data_get($this->parity->assess($generation), 'controls', []);
        foreach ($controlRows as $row) {
            if ((string) data_get($row, 'status') !== 'failed') $reasons[] = 'CONTROL_ROW_NOT_COMPLETE_FAILURE';
            if (trim((string) data_get($row, 'data_hash', '')) === '') $reasons[] = 'CONTROL_DATA_HASH_MISSING';
            if (trim((string) data_get($row, 'execution_hash', '')) === '') $reasons[] = 'CONTROL_EXECUTION_HASH_MISSING';
        }

        return [
            'healthy' => $reasons === [] && $controlRows !== [],
            'reasons' => array_values(array_unique($reasons)),
            'control_rows' => $controlRows,
        ];
    }

    private function consecutiveShadowGenerations(AiLaboratory $lab, LabGeneration $latest): int
    {
        $generations = $lab->generations()->latest('generation')->get();
        $count = 0;
        foreach ($generations as $generation) {
            if ($this->isConstructorAbortedShadow($generation)) continue;
            if ((string) $generation->trigger_type !== 'shadow_research') break;
            $count++;
        }

        return $count;
    }

    private function isConstructorAbortedShadow(?LabGeneration $generation): bool
    {
        if (! $generation || (string) $generation->trigger_type !== 'shadow_research') return false;
        if ((string) data_get($generation->trigger_context, 'shadow_research_constructor_abort.reason_code') === 'INCOMPLETE_SHADOW_RESEARCH_POPULATION') {
            return true;
        }

        $audit = (array) data_get($generation->trigger_context, 'constructor_audit', []);
        $planned = (int) data_get($audit, 'planned_slots', 0);
        $created = (int) data_get($audit, 'created_agents', 0);

        return $generation->status === 'technical_quarantine' && $planned > 0 && $created < $planned;
    }

    private function targetPopulation(): int
    {
        $configured = (int) config('services.lab_selection.population_size', 20);
        $maximum = (int) config('services.lab_selection.population_max_size', 0);

        return $maximum > 0 ? min(max(1, $configured), $maximum) : max(1, $configured);
    }
}
