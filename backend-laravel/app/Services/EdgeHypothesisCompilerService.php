<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compiles one bounded, non-duplicate structural experiment from terminal
 * Edge evidence.  It never invents a new runtime strategy and never grants
 * promotion authority: professional libraries are proposal priors, while the
 * frozen local XAUUSD evidence chooses the diagnostic axis.
 */
class EdgeHypothesisCompilerService
{
    public const PROTOCOL = 'edge_hypothesis_compiler_v1';

    public function __construct(
        private EdgeCohortIdentityService $identity,
        private FailureDojoService $dojo,
        private StrategyLibraryCompilerService $strategies,
        private StrategyResearchCatalogueService $researchCatalogue,
        private StrategyParameterSchemaService $schemas,
        private CausalStageMasteryDirectorService $stageMastery,
        private CausalProgressRatchetGovernorService $ratchetGovernor,
    ) {}

    /** @return array<string,mixed> */
    public function compile(string $symbol, string $timeframe): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        if (! Schema::hasTable('edge_hypothesis_packets') || ! Schema::hasTable('edge_genesis_passports')) {
            return $this->blocked('EDGE_HYPOTHESIS_REGISTRY_UNAVAILABLE');
        }

        $scope = DB::table('edge_genesis_passports')->where('symbol', $symbol)->where('timeframe', $timeframe);
        if ((clone $scope)->whereIn('phase', ['EDGE_ATTRIBUTION', 'RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'])->exists()) {
            return $this->blocked('EDGE_ALREADY_ESTABLISHED');
        }

        $budget = max(1, (int) config('services.edge_director.compiled_hypothesis_budget', 10));
        $registeredAll = DB::table('edge_hypothesis_packets')->where('symbol', $symbol)
            ->where('timeframe', $timeframe)->get(['definition']);
        $islands = (clone $scope)->get()->groupBy(function ($passport): string {
            $packet = $this->packetFromPassport($passport);
            return $this->rootPacketIdentity($packet)['key'];
        })->map(function (Collection $rows, string $rootKey) use ($registeredAll): array {
            $latestGenerationId = (int) $rows->max('lab_generation_id');
            $latest = $rows->where('lab_generation_id', $latestGenerationId)->values();
            $packet = $this->packetFromPassport($latest->first());
            $registered = $this->registeredForPacket($registeredAll, $packet);
            return [
                'root_key' => $rootKey,
                'latest_generation_id' => $latestGenerationId,
                'passports' => $latest,
                'packet' => $packet,
                'registered' => $registered,
                'compiled_count' => $registered->count(),
            ];
        })->filter(fn (array $island): bool => $island['latest_generation_id'] > 0
            && $island['packet'] !== []
            && ! $island['passports']->contains(fn ($passport): bool =>
                in_array((string) $passport->status, ['queued', 'running'], true)))
            // Breadth first: every professional strategy/tactic island earns
            // causal questions before one threshold family consumes all
            // research. Latest generation is only a deterministic tie-break.
            ->sortBy(fn (array $island): string => sprintf('%06d|%010d|%s',
                $island['compiled_count'], PHP_INT_MAX - $island['latest_generation_id'], $island['root_key']))
            ->values();
        $sourceIsland = $islands->first(fn (array $island): bool => $island['compiled_count'] < $budget);
        if (! is_array($sourceIsland)) return $this->blocked('COMPILED_HYPOTHESIS_ISLANDS_EXHAUSTED', [
            'budget_per_island' => $budget,
            'islands' => $islands->map(fn (array $island): array => [
                'root_key' => $island['root_key'], 'compiled_count' => $island['compiled_count'],
                'latest_generation_id' => $island['latest_generation_id'],
            ])->all(),
        ]);

        $sourceGenerationId = (int) $sourceIsland['latest_generation_id'];
        $passports = $sourceIsland['passports'];
        $trials = DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $passports->pluck('id'))->get();
        $terminal = ['edge_not_found', 'edge_not_confirmed', 'control_settled', 'replication_control_settled'];
        if ($passports->isEmpty() || $trials->isEmpty()
            || $passports->contains(fn ($passport): bool => in_array((string) $passport->status, ['queued', 'running'], true))
            || $trials->contains(fn ($trial): bool => ! in_array((string) $trial->status, $terminal, true))) {
            return $this->blocked('SOURCE_EDGE_COHORT_NOT_TERMINAL');
        }

        $sourceGeneration = LabGeneration::query()->find($sourceGenerationId);
        if (! $sourceGeneration || in_array((string) $sourceGeneration->status, ['technical_quarantine', 'quarantined'], true)) {
            return $this->blocked('TECHNICAL_SOURCE_CANNOT_COMPILE_HYPOTHESIS');
        }
        $identity = $this->sourceIdentity($passports, $sourceGeneration);
        if (! $identity['valid']) return $this->blocked('SOURCE_COHORT_FROZEN_IDENTITY_NOT_UNIQUE');

        $observations = $this->observations($trials);
        if ($observations->isEmpty()) return $this->blocked('VALID_ECONOMIC_SOURCE_EVIDENCE_REQUIRED');
        $source = $this->rankSourceObservations($observations)->first();
        $sourceModel = ModelVersion::query()->find((int) $source['model_version_id']);
        $sourceTrial = $trials->firstWhere('model_version_id', (int) $source['model_version_id']);
        $sourcePassport = $sourceTrial ? $passports->firstWhere('id', (int) $sourceTrial->edge_genesis_passport_id) : null;
        if (! $sourceModel || ! $sourceTrial || ! $sourcePassport) return $this->blocked('CAUSAL_SOURCE_COMPOSITION_MISSING');

        $sourcePacket = (array) data_get(json_decode((string) $sourcePassport->evidence, true), 'packet', []);
        if ($sourcePacket === []) return $this->blocked('SOURCE_PROFESSIONAL_PACKET_MISSING');

        $diagnosis = $this->diagnose($observations, $source);
        $blueprints = array_values(array_filter($this->blueprints((array) $sourceModel->parameters, $diagnosis),
            fn (array $blueprint): bool => (bool) data_get($this->stageMastery->owner((string) $blueprint['axis']), 'declared', false)));
        if ($blueprints === []) return $this->blocked('NO_DECLARED_CAUSAL_STAGE_OWNER_FOR_DIAGNOSIS', [
            'diagnosis' => $diagnosis['code'], 'promotion_evidence' => false,
        ]);
        $baselineBudget = max(1, (int) config('services.edge_director.compiled_hypothesis_budget_per_baseline', 3));
        $axisEpochBudget = max(1, (int) config('services.edge_director.compiled_hypothesis_budget_per_axis_epoch', 2));
        $baselineEpochHash = $this->identity->hash((array) $sourceModel->parameters);
        /** @var Collection<int,object> $registered */
        $registered = $sourceIsland['registered'];

        // A new source generation or a reclassified failure diagnosis must
        // not erase the causal debt of an axis that already failed for the
        // same professional composition.
        // Exact definition hashes include source identity by design, so using
        // only that hash would endlessly retry the same structural question.
        $usedSemanticAxes = $registered->map(function ($row): ?string {
            $definition = is_string($row->definition) ? json_decode($row->definition, true) : $row->definition;
            if (! is_array($definition)) return null;
            return $this->semanticAxisKey((array) ($definition['source_packet'] ?? []),
                (string) ($definition['diagnosis'] ?? ''), (string) ($definition['structural_axis'] ?? ''),
                (string) ($definition['source_parameter_hash'] ?? ''));
        })->filter()->unique()->flip();
        $used = $usedSemanticAxes->count();
        if ($used >= $budget) return $this->blocked('COMPILED_HYPOTHESIS_BUDGET_EXHAUSTED', [
            'budget' => $budget, 'used' => $used, 'registered_rows' => $registered->count(),
        ]);
        $usedInBaseline = $registered->map(function ($row) use ($baselineEpochHash): ?string {
            $definition = is_string($row->definition) ? json_decode($row->definition, true) : $row->definition;
            if (! is_array($definition) || (string) ($definition['source_parameter_hash'] ?? '') !== $baselineEpochHash) return null;
            return $this->semanticAxisKey((array) ($definition['source_packet'] ?? []),
                (string) ($definition['diagnosis'] ?? ''), (string) ($definition['structural_axis'] ?? ''),
                $baselineEpochHash);
        })->filter()->unique()->count();
        if ($usedInBaseline >= $baselineBudget) return $this->blocked('COMPILED_BASELINE_EPOCH_BUDGET_EXHAUSTED', [
            'baseline_epoch_hash' => $baselineEpochHash, 'budget' => $baselineBudget,
            'used' => $usedInBaseline, 'global_budget' => $budget, 'global_used' => $used,
        ]);
        $axisEpochUse = $registered->map(function ($row): ?string {
            $definition = is_string($row->definition) ? json_decode($row->definition, true) : $row->definition;
            if (! is_array($definition)) return null;
            return $this->axisEpochKey((array) ($definition['source_packet'] ?? []),
                (string) ($definition['diagnosis'] ?? ''), (string) ($definition['structural_axis'] ?? ''));
        })->filter()->countBy();

        $selected = null;
        foreach ($blueprints as $blueprint) {
            $escalation = $this->ratchetGovernor->escalation([
                'symbol' => $symbol, 'timeframe' => $timeframe, 'composition_key' => $sourceIsland['root_key'],
                'baseline_epoch_hash' => $baselineEpochHash, 'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
            ], (string) $blueprint['axis']);
            if (($escalation['scalar_reentry_forbidden'] ?? false) && ! str_contains((string) $blueprint['axis'], 'policy')) continue;
            $axisEpochKey = $this->axisEpochKey($sourcePacket, (string) $diagnosis['code'],
                (string) $blueprint['axis']);
            if ((int) ($axisEpochUse[$axisEpochKey] ?? 0) >= $axisEpochBudget) continue;
            $semanticAxisKey = $this->semanticAxisKey($sourcePacket, (string) $diagnosis['code'],
                (string) $blueprint['axis'], $baselineEpochHash);
            if ($usedSemanticAxes->has($semanticAxisKey)) continue;
            $definition = $this->definition($sourcePacket, $sourceModel, $sourceGenerationId, $diagnosis, $blueprint);
            $hash = $this->identity->hash($definition);
            if (! DB::table('edge_hypothesis_packets')->where('packet_definition_hash', $hash)->exists()) {
                $selected = [...$blueprint, 'definition' => $definition, 'packet_definition_hash' => $hash,
                    'semantic_axis_key' => $semanticAxisKey];
                break;
            }
        }
        if (! $selected) return $this->blocked('NON_DUPLICATE_COMPILED_HYPOTHESIS_UNAVAILABLE', [
            'budget' => $budget, 'used' => $used, 'registered_rows' => $registered->count(),
            'axis_epoch_budget' => $axisEpochBudget, 'axis_epoch_use' => $axisEpochUse->all(),
        ]);
        $governorAdmission = $this->ratchetGovernor->admitExpensivePacket([
            'structural_axis' => $selected['axis'], 'decisive_outcome_probability' => .5, 'causal_depth_gain' => 1,
            'transfer_potential' => .5, 'novelty' => .5, 'compute_cost' => count($selected['arm_values']), 'duplicate_penalty' => 0,
        ], $symbol, $timeframe);
        if (! ($governorAdmission['allowed'] ?? false)) return $this->blocked((string) $governorAdmission['reason'], ['governor' => $governorAdmission]);

        $rootPacket = $this->rootPacketIdentity($sourcePacket);
        $packet = [...$sourcePacket,
            'root_key' => $rootPacket['key'],
            'root_label' => $rootPacket['label'],
            'key' => $rootPacket['key'].'_compiled_'.substr($selected['packet_definition_hash'], 0, 10),
            'label' => $rootPacket['label'].' Compiled '.$selected['axis'],
            'emitter' => 'evidence_compiler',
            'compiled_axis' => $selected['axis'],
            'compiled_arm_values' => $selected['arm_values'],
            'compiled_diagnosis' => $diagnosis['code'],
            'one_structural_axis_only' => true,
        ];
        $snapshots = $this->reusableSnapshots((array) data_get($sourceGeneration->trigger_context, 'canonical_dataset_snapshots', []), $sourceGenerationId);
        if ($snapshots === []) return $this->blocked('SOURCE_CANONICAL_COVERAGE_SNAPSHOT_MISSING');

        $hypothesisKey = hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe,
            $sourceGenerationId, $selected['packet_definition_hash']]));
        $dojo = $this->dojo->summary($symbol, $timeframe);
        $prior = $this->professionalPrior($sourcePacket);

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'compiled',
            'admitted' => true,
            'hypothesis_key' => $hypothesisKey,
            'architecture_revision' => DependencyAwareEdgeGenesisFoundryService::EVIDENCE_COMPILED_REVISION,
            'source_generation_id' => $sourceGenerationId,
            'source_model_version_id' => (int) $sourceModel->id,
            'source_trial_id' => (int) $sourceTrial->id,
            'research_island' => [
                'root_key' => $sourceIsland['root_key'],
                'compiled_count_before' => $sourceIsland['compiled_count'],
                'budget' => $budget,
                'selection' => 'least_compiled_professional_island_first',
            ],
            'diagnosis' => $diagnosis,
            'structural_axis' => $selected['axis'],
            'semantic_axis_key' => $selected['semantic_axis_key'],
            'baseline_epoch_hash' => $baselineEpochHash,
            'packet_definition_hash' => $selected['packet_definition_hash'],
            'packet' => $packet,
            'arms' => array_keys($selected['arm_values']),
            'source_parameters' => (array) $sourceModel->parameters,
            'data_hash' => $identity['data_hash'],
            'execution_hash' => $identity['execution_hash'],
            'mtf_bundle' => $identity['mtf_bundle'],
            'canonical_dataset_snapshots' => $snapshots,
            'professional_prior' => $prior,
            'failure_dojo' => [
                'available' => (bool) ($dojo['available'] ?? false),
                'actionable_pending' => (int) ($dojo['actionable_pending'] ?? 0),
                'blocks_compilation' => false,
            ],
            'governor' => ['admission' => $governorAdmission, 'allocation' => $this->ratchetGovernor->allocate($symbol, $timeframe, true),
                'escalation' => $this->ratchetGovernor->escalation([
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'composition_key' => $sourceIsland['root_key'],
                    'baseline_epoch_hash' => $baselineEpochHash, 'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                ], (string) $selected['axis'])],
            'authority_contract' => [
                'research_only' => true,
                'risk_governor_frozen' => true,
                'one_structural_axis_only' => true,
                'exact_control_required' => true,
                'negative_control_required' => true,
                'two_three_nine_disjoint_required' => true,
                'parent_authority' => false,
                'promotion_evidence' => false,
            ],
            'definition' => $selected['definition'],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function initialGenesisReadiness(AiLaboratory $lab): array
    {
        $required = max(3, (int) config('services.edge_director.comparable_scalar_generations', 3));
        $generations = $lab->generations()->with('agents.modelVersion.marketPerformances')
            ->whereNotNull('completed_at')->latest('id')->limit(30)->get()
            ->filter(fn (LabGeneration $generation): bool => ! in_array((string) $generation->status,
                ['technical_quarantine', 'quarantined'], true));
        if ($generations->isEmpty()) {
            return ['protocol' => self::PROTOCOL, 'admitted' => true, 'reason' => 'COLD_START_EDGE_BOOTSTRAP',
                'comparable_generations' => 0, 'required' => $required, 'promotion_evidence' => false];
        }

        $comparable = $generations->filter(fn (LabGeneration $generation): bool => $this->isComparableScalarRepair($generation));
        $group = $comparable->groupBy(fn (LabGeneration $generation): string => implode('|', [
            (string) ($generation->data_fingerprint ?? ''),
            (string) data_get($generation->trigger_context, 'execution_hash', ''),
            (string) data_get($generation->trigger_context, 'base_composition_hash',
                data_get($generation->trigger_context, 'control_hash', 'unknown')),
        ]))->sortByDesc(fn (Collection $items): int => $items->count())->first() ?? collect();
        $latest = collect($group)->take($required);
        $nonViable = $latest->count() === $required && $latest->every(function (LabGeneration $generation): bool {
            $metrics = $generation->agents->flatMap(fn ($agent) => $agent->modelVersion?->marketPerformances ?? collect())
                ->where('evidence_status', 'valid')->pluck('metrics')->filter(fn ($value): bool => is_array($value));
            return $metrics->isNotEmpty() && $metrics->every(fn (array $row): bool => $this->afterCost($row) <= 0
                && data_get($row, 'edge_genesis.phase') !== 'EDGE_ATTRIBUTION');
        });

        return [
            'protocol' => self::PROTOCOL,
            'admitted' => $nonViable,
            'reason' => $nonViable ? 'THREE_COMPARABLE_SCALAR_REPAIRS_NON_VIABLE' : 'COMPARABLE_SCALAR_PLATEAU_NOT_PROVEN',
            'comparable_generations' => $latest->count(),
            'required' => $required,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function sourceIdentity(Collection $passports, LabGeneration $generation): array
    {
        $data = $passports->pluck('data_hash')->filter()->unique()->values();
        $execution = $passports->pluck('execution_hash')->filter()->unique()->values();
        $mtf = $passports->map(fn ($passport): string => (string) data_get(json_decode((string) $passport->evidence, true), 'mtf_bundle_hash', ''))
            ->filter()->unique()->values();
        $manifest = (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []);
        return [
            'valid' => $data->count() === 1 && $execution->count() === 1 && $mtf->count() === 1
                && hash_equals((string) $mtf->first(), (string) data_get($manifest, 'bundle_hash', '')),
            'data_hash' => (string) $data->first(),
            'execution_hash' => (string) $execution->first(),
            'mtf_bundle' => ['bundle_hash' => (string) $mtf->first(), 'manifest' => $manifest],
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function observations(Collection $trials): Collection
    {
        $controlArms = DependencyAwareEdgeGenesisFoundryService::TRAVELING_CONTROL_ARMS;
        return $trials->map(function ($trial) use ($controlArms): ?array {
            $row = DB::table('model_market_performance')->where('model_version_id', $trial->model_version_id)
                ->where('evidence_status', 'valid')->latest('id')->first(['metrics', 'sample_count', 'rolling_windows_count']);
            if (! $row) return null;
            $metrics = is_string($row->metrics) ? json_decode($row->metrics, true) : $row->metrics;
            if (! is_array($metrics)) return null;
            $observation = [
                'trial_id' => (int) $trial->id,
                'model_version_id' => (int) $trial->model_version_id,
                'arm' => (string) $trial->arm,
                'control' => in_array((string) $trial->arm, $controlArms, true) || (string) $trial->arm === 'frozen_control',
                'metrics' => $metrics,
                'trades' => (int) data_get($metrics, 'total_trades', $row->sample_count ?? 0),
                'expectancy_r' => $this->afterCost($metrics),
                'opportunities' => (int) data_get($metrics, 'edge_observability.opportunity_detected.count', 0),
                'setups' => (int) data_get($metrics, 'edge_observability.setup_location_valid.setup_count', 0),
                'entries' => (int) data_get($metrics, 'edge_observability.entry.count', 0),
                'funnel_setup' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.setup', 0),
                'funnel_confirmation' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.confirmation', 0),
                'funnel_trigger' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.trigger', 0),
                'funnel_entry_ready' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.entry_ready', 0),
                'trigger_diagnosis' => (string) data_get($metrics, 'entry_contract_funnel.trigger_topology.diagnosis', ''),
                'trigger_modes' => (array) data_get($metrics, 'entry_contract_funnel.trigger_topology.counterfactual_mode_counts', []),
                'no_trade_reasons' => (array) data_get($metrics, 'entry_contract_funnel.no_trade_reasons', []),
                'mfe_capture_ratio' => (float) data_get($metrics, 'management_audit.mfe_capture_ratio',
                    data_get($metrics, 'trade_excursion_audit.mfe_capture_ratio', 0)),
                'powered_folds' => (int) data_get($metrics, 'forward_window_protocol.powered_windows', 0),
                'positive_folds' => (int) data_get($metrics, 'forward_window_protocol.positive_windows', 0),
            ];
            $observation['causal_depth'] = $this->causalDepth($observation);
            return $observation;
        })->filter()->values();
    }

    /** @param Collection<int,array<string,mixed>> $observations @return Collection<int,array<string,mixed>> */
    private function rankSourceObservations(Collection $observations): Collection
    {
        return $observations->map(function (array $row): array {
            $row['causal_depth'] = $this->causalDepth($row);
            // A treatment becomes the next frozen baseline only when it
            // reaches a deeper executable stage or improves economics. Raw
            // diagnostic signal-count growth alone must not displace the
            // exact professional control and create parameter drift.
            $row['control_anchor'] = ($row['control'] ?? false) ? 1 : 0;
            return $row;
        })->sort(function (array $left, array $right): int {
            // A losing composition that opened a real decision path is a
            // safer causal stepping stone than a zero-trade abstainer with a
            // superficially better zero expectancy. This grants research
            // baseline authority only; parent/promotion gates remain closed.
            return [$right['causal_depth'], $right['funnel_entry_ready'] ?? 0, $right['expectancy_r'],
                $right['positive_folds'] ?? 0, $right['powered_folds'] ?? 0, $right['trades'],
                $right['control_anchor'], $right['entries'], -$right['model_version_id']]
                <=> [$left['causal_depth'], $left['funnel_entry_ready'] ?? 0, $left['expectancy_r'],
                    $left['positive_folds'] ?? 0, $left['powered_folds'] ?? 0, $left['trades'],
                    $left['control_anchor'], $left['entries'], -$left['model_version_id']];
        })->values();
    }

    /** @param array<string,mixed> $row */
    private function causalDepth(array $row): int
    {
        return match (true) {
            (int) ($row['trades'] ?? 0) > 0 || (int) ($row['funnel_entry_ready'] ?? $row['entries'] ?? 0) > 0 => 4,
            (int) ($row['funnel_trigger'] ?? 0) > 0 => 3,
            (int) ($row['funnel_confirmation'] ?? 0) > 0 => 2,
            (int) ($row['funnel_setup'] ?? $row['setups'] ?? 0) > 0 => 1,
            default => 0,
        };
    }

    /** @param array<string,mixed> $best @return array<string,mixed> */
    private function diagnose(Collection $rows, array $best): array
    {
        $treatments = $rows->where('control', false);
        $opportunities = (int) $treatments->sum('opportunities');
        $setups = (int) $treatments->sum('setups');
        $entries = (int) $treatments->sum('entries');
        $funnelSetups = (int) $treatments->sum('funnel_setup');
        $funnelConfirmations = (int) $treatments->sum('funnel_confirmation');
        $funnelTriggers = (int) $treatments->sum('funnel_trigger');
        $funnelEntryReady = (int) $treatments->sum('funnel_entry_ready');
        $triggerDiagnosis = (string) ($treatments->pluck('trigger_diagnosis')->filter()->countBy()
            ->sortDesc()->keys()->first() ?? '');
        $trades = (int) $treatments->sum('trades');
        $meanCapture = (float) ($treatments->avg('mfe_capture_ratio') ?? 0);
        $code = match (true) {
            $opportunities > 0 && $setups === 0 => 'location_starvation',
            $funnelSetups > 0 && $funnelConfirmations === 0 => 'confirmation_family_starvation',
            $funnelConfirmations > 0 && $funnelTriggers === 0 => 'trigger_topology_starvation',
            $funnelTriggers > 0 && $funnelEntryReady === 0 => 'entry_admission_starvation',
            $setups > 0 && $entries === 0 => 'confirmation_entry_starvation',
            $trades > 0 && $best['expectancy_r'] <= 0 && $meanCapture > 0 && $meanCapture < .45 => 'latent_edge_realization_failure',
            $best['expectancy_r'] > 0 && ((int) $best['powered_folds'] < 6 || (int) $best['positive_folds'] < 4) => 'sparse_fold_power',
            $trades > 0 && $best['expectancy_r'] <= 0 => 'negative_entry_quality',
            default => 'temporal_structure_mismatch',
        };
        return compact('code', 'opportunities', 'setups', 'entries', 'trades', 'meanCapture',
            'funnelSetups', 'funnelConfirmations', 'funnelTriggers', 'funnelEntryReady', 'triggerDiagnosis') + [
            'best_expectancy_r' => $best['expectancy_r'],
            'best_powered_folds' => $best['powered_folds'],
            'best_positive_folds' => $best['positive_folds'],
            'best_causal_depth' => $best['causal_depth'] ?? $this->causalDepth($best),
            'source_selection' => 'deepest_observed_decision_path_before_scalar_expectancy',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function blueprints(array $parameters, array $diagnosis): array
    {
        $preferred = match ($diagnosis['code']) {
            'location_starvation' => ['setup_topology_policy', 'max_chase_atr', 'm5_retest_expiry_minutes', 'swing_lookback'],
            'confirmation_family_starvation' => ['confirmation_family_policy', 'rejection_wick_ratio', 'swing_lookback', 'entry_model'],
            'trigger_topology_starvation' => ['trigger_topology_policy', 'rejection_wick_ratio', 'entry_model'],
            'entry_admission_starvation' => ['minimum_reward_space_r', 'max_chase_atr', 'invalidation_buffer_atr'],
            'confirmation_entry_starvation', 'sparse_fold_power' => ['m5_retest_expiry_minutes', 'm5_minimum_displacement_atr', 'swing_lookback'],
            'latent_edge_realization_failure' => ['atr_target_multiplier', 'time_stop_candles', 'm5_retest_expiry_minutes'],
            'negative_entry_quality' => ($parameters['entry_model'] ?? null) === 'breakout_retest'
                ? ['max_chase_atr', 'minimum_reward_space_r', 'breakout_minimum_expansion_atr', 'm5_retest_expiry_minutes', 'swing_lookback']
                : ['max_chase_atr', 'm5_minimum_displacement_atr', 'rejection_wick_ratio'],
            default => ['swing_lookback', 'm5_retest_expiry_minutes', 'rejection_wick_ratio'],
        };
        $preferred = array_values(array_unique([...$preferred, 'minimum_reward_space_r', 'rejection_wick_ratio', 'atr_target_multiplier']));
        return array_map(fn (string $axis): array => [
            'axis' => $axis,
            'arm_values' => $this->armValues($axis, $parameters[$axis] ?? null),
        ], $preferred);
    }

    /** @return array<string,int|float|string> */
    private function armValues(string $axis, mixed $base): array
    {
        $values = match ($axis) {
            'confirmation_family_policy' => [(string) ($base ?: 'all_three_simultaneous'), 'structure_plus_reaction',
                'structure_plus_participation', 'sequential_three', 'state_adaptive_two_of_three'],
            'trigger_topology_policy' => [(string) ($base ?: 'balanced_retest_reaction'), 'aggressive_structure_close',
                'conservative_continuation', 'session_adaptive', 'volatility_adaptive'],
            'setup_topology_policy' => [(string) ($base ?: 'pullback_rejection'), 'breakout_and_retest',
                'liquidity_sweep_reclaim', 'range_reentry', 'compression_expansion'],
            'entry_model' => [(string) ($base ?: 'trend_continuation'), 'breakout_retest',
                'false_break_reversal', 'range_sweep', 'htf_reversal'],
            'm5_retest_expiry_minutes' => [(int) ($base ?: 20), 40, 60, 80, 10],
            'swing_lookback' => [(int) ($base ?: 40), 30, 20, 60, 80],
            'time_stop_candles' => [(int) ($base ?: 240), 180, 120, 300, 360],
            'm5_minimum_displacement_atr' => [(float) ($base ?: .5), .4, .3, .65, .8],
            'breakout_minimum_expansion_atr' => [(float) ($base ?: .35), .25, .15, .5, .75],
            'max_chase_atr' => [(float) ($base ?: 1.25), 1.0, .75, 1.5, 2.0],
            'minimum_reward_space_r' => [(float) ($base ?: 1.5), 1.75, 2.0, 1.25, 1.0],
            'rejection_wick_ratio' => [(float) ($base ?: .35), .4, .45, .3, .2],
            'invalidation_buffer_atr' => [(float) ($base ?? .05), .025, .1, .15, .2],
            'atr_target_multiplier' => [(float) ($base ?: 2.5), 2.0, 1.5, 3.0, 4.0],
            default => [(float) ($base ?: 1), .9, .8, 1.1, 1.2],
        };
        $values = $this->fiveDistinctWithinSchema($axis, $values);
        return array_combine([
            'compiled_control', 'compiled_primary', 'compiled_refinement',
            'compiled_counterfactual', 'compiled_negative_control',
        ], $values);
    }

    /** @param array<int,int|float|string> $values @return array<int,int|float|string> */
    private function fiveDistinctWithinSchema(string $axis, array $values): array
    {
        $schema = (array) data_get($this->schemas->schema('confirmation_entry_mtf'), $axis, []);
        [$type, $minimum, $maximum] = array_pad($schema, 3, null);
        if ($type === 'string' && is_array($minimum)) {
            $out = [];
            foreach ([...$values, ...$minimum] as $value) {
                $value = (string) $value;
                if (! in_array($value, $minimum, true) || in_array($value, $out, true)) continue;
                $out[] = $value;
                if (count($out) === 5) break;
            }
            if (count($out) !== 5) {
                throw new \InvalidArgumentException("Compiled Edge axis {$axis} cannot provide five distinct schema-valid arms.");
            }
            return $out;
        }
        if (! in_array($type, ['integer', 'numeric'], true)
            || ! is_numeric($minimum) || ! is_numeric($maximum)) {
            throw new \InvalidArgumentException("Compiled Edge axis {$axis} has no bounded numeric runtime schema.");
        }
        $minimum = (float) $minimum;
        $maximum = (float) $maximum;
        $normalize = static function (mixed $value) use ($type, $minimum, $maximum): int|float {
            $bounded = max($minimum, min($maximum, (float) $value));
            return $type === 'integer' ? (int) round($bounded) : round($bounded, 8);
        };

        // Preserve the declared role order first, then fill any collision
        // (for example a source already at the proposed boundary) from the
        // same legal schema interval. Control remains the first exact value.
        $candidates = array_map($normalize, $values);
        for ($step = 0; $step <= 20; $step++) {
            $candidates[] = $normalize($minimum + (($maximum - $minimum) * ($step / 20)));
        }
        $out = [];
        foreach ($candidates as $value) {
            $key = is_float($value) ? number_format($value, 8, '.', '') : (string) $value;
            if (! array_key_exists($key, $out)) $out[$key] = $value;
            if (count($out) === 5) break;
        }
        if (count($out) !== 5) {
            throw new \InvalidArgumentException("Compiled Edge axis {$axis} cannot provide five distinct schema-valid arms.");
        }

        return array_values($out);
    }

    /** @return array<string,mixed> */
    private function definition(array $packet, ModelVersion $source, int $sourceGenerationId, array $diagnosis, array $blueprint): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'source_generation_id' => $sourceGenerationId,
            'source_model_version_id' => $source->id,
            'source_parameter_hash' => $this->identity->hash((array) $source->parameters),
            'source_packet' => [
                'key' => $packet['key'] ?? null,
                'strategy_id' => $packet['strategy_id'] ?? null,
                'tactic_id' => $packet['tactic_id'] ?? null,
                'management_id' => $packet['management_id'] ?? null,
                'context' => $packet['context'] ?? [],
            ],
            'diagnosis' => $diagnosis['code'],
            'structural_axis' => $blueprint['axis'],
            'semantic_axis_key' => $this->semanticAxisKey($packet, (string) $diagnosis['code'],
                (string) $blueprint['axis'], $this->identity->hash((array) $source->parameters)),
            'arm_values' => $blueprint['arm_values'],
            'one_structural_axis_only' => true,
            'exact_control_required' => true,
            'negative_control_required' => true,
            'risk_governor_frozen' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array{key:string,label:string} */
    private function rootPacketIdentity(array $packet): array
    {
        $key = (string) ($packet['root_key'] ?? $packet['key'] ?? 'edge');
        $key = preg_replace('/(?:_compiled_[a-f0-9]{10})+$/i', '', $key) ?: 'edge';
        $label = trim((string) ($packet['root_label'] ?? $packet['label'] ?? 'Edge'));
        $label = preg_replace('/\s+Compiled\s+.*$/i', '', $label) ?: 'Edge';
        return ['key' => $key, 'label' => $label];
    }

    /** @return array<string,mixed> */
    private function packetFromPassport(?object $passport): array
    {
        if (! $passport) return [];
        $evidence = is_string($passport->evidence ?? null)
            ? json_decode((string) $passport->evidence, true)
            : ($passport->evidence ?? []);
        return is_array($evidence) ? (array) data_get($evidence, 'packet', []) : [];
    }

    /** @param Collection<int,object> $registered @return Collection<int,object> */
    private function registeredForPacket(Collection $registered, array $packet): Collection
    {
        $target = $this->rootPacketIdentity($packet)['key'];
        return $registered->filter(function ($row) use ($packet, $target): bool {
            $definition = is_string($row->definition ?? null)
                ? json_decode((string) $row->definition, true)
                : ($row->definition ?? []);
            if (! is_array($definition)) return false;
            $source = (array) ($definition['source_packet'] ?? []);
            $sourceRoot = $this->rootPacketIdentity($source)['key'];
            if ($sourceRoot !== $target) return false;
            foreach (['strategy_id', 'tactic_id', 'management_id'] as $key) {
                $expected = (string) ($packet[$key] ?? '');
                $actual = (string) ($source[$key] ?? '');
                if ($expected !== '' && $actual !== '' && $expected !== $actual) return false;
            }
            return true;
        })->values();
    }

    private function semanticAxisKey(array $packet, string $diagnosis, string $axis, string $baselineEpochHash): string
    {
        return $this->identity->hash([
            'protocol' => 'edge_semantic_axis_debt_v3',
            'strategy_id' => (string) ($packet['strategy_id'] ?? ''),
            'tactic_id' => (string) ($packet['tactic_id'] ?? ''),
            'management_id' => (string) ($packet['management_id'] ?? ''),
            'baseline_epoch_hash' => $baselineEpochHash,
            'structural_axis' => $axis,
        ]);
    }

    private function axisEpochKey(array $packet, string $diagnosis, string $axis): string
    {
        return $this->identity->hash([
            'protocol' => 'edge_axis_epoch_budget_v1',
            'strategy_id' => (string) ($packet['strategy_id'] ?? ''),
            'tactic_id' => (string) ($packet['tactic_id'] ?? ''),
            'management_id' => (string) ($packet['management_id'] ?? ''),
            'diagnosis' => $diagnosis,
            'structural_axis' => $axis,
        ]);
    }

    /** @return array<string,mixed> */
    private function professionalPrior(array $packet): array
    {
        $strategyId = (string) ($packet['strategy_id'] ?? '');
        try {
            $strategy = $strategyId !== '' ? $this->strategies->compile($strategyId) : [];
        } catch (\Throwable) {
            $strategy = [];
        }
        $catalogue = $this->researchCatalogue->catalogue();
        $playbook = collect((array) ($catalogue['models'] ?? []))->first(function (array $model) use ($packet): bool {
            $needle = strtolower((string) ($packet['tactic_id'] ?? $packet['key'] ?? ''));
            return $needle !== '' && (str_contains(strtolower((string) ($model['id'] ?? '')), str_replace('_retest', '', $needle))
                || str_contains(strtolower((string) ($model['label'] ?? '')), str_replace('_', ' ', $needle)));
        });
        return [
            'strategy_contract' => $strategy,
            'research_playbook' => $playbook,
            'proposal_bias_only' => true,
            'local_evidence_is_authority' => true,
            'promotion_evidence' => false,
        ];
    }

    private function isComparableScalarRepair(LabGeneration $generation): bool
    {
        if (! in_array((string) $generation->trigger_type, ['learning_confirmation', 'causal_learning', 'evolution'], true)) return false;
        $diffKeys = $generation->agents->flatMap(fn ($agent) => array_keys((array) $agent->parameter_diff))->unique()->values();
        if ($diffKeys->isEmpty()) return false;
        $allowed = [...DependencyAwareEdgeGenesisFoundryService::RISK_GENES,
            ...DependencyAwareEdgeGenesisFoundryService::MANAGEMENT_GENES];
        return $diffKeys->every(fn (string $key): bool => in_array($key, $allowed, true));
    }

    /** @return array<string,mixed> */
    private function reusableSnapshots(array $snapshots, int $sourceGenerationId): array
    {
        if ($snapshots === []) return [];
        return collect($snapshots)->mapWithKeys(fn ($snapshot, $key): array => [$key => [...((array) $snapshot),
            'causal_reuse' => [
                'protocol' => 'edge_generation_coverage_snapshot_reuse_v1',
                'source_generation_id' => $sourceGenerationId,
                'target_generation_id' => null,
                'immutable_file_reused' => true,
                'promotion_evidence' => false,
            ],
        ]])->all();
    }

    private function afterCost(array $metrics): float
    {
        foreach (['causal_edge_accounting.after_cost_expectancy_r', 'after_cost_expectancy_r',
            'expectancy_r', 'fitness_breakdown.expectancy_r'] as $path) {
            $value = data_get($metrics, $path);
            if (is_numeric($value)) return (float) $value;
        }
        $trades = max(1, (int) data_get($metrics, 'total_trades', 0));
        return round((float) data_get($metrics, 'net_profit_percent', 0) / $trades, 6);
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason, array $context = []): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'admitted' => false,
            'reason' => $reason, ...$context, 'promotion_evidence' => false];
    }
}
