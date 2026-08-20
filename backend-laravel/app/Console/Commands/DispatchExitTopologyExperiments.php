<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Bounded one-gene exit replays with the entry topology frozen. */
class DispatchExitTopologyExperiments extends Command
{
    protected $signature = 'trading:dispatch-exit-topology-experiments {sourceAgent : Screened source agent id} {--resume : Dispatch already-created queued exit experiments without creating duplicates}';

    protected $description = 'Create isolated stop/target/trailing/time-stop screening experiments; never dispatches full validation or paper.';

    public function handle(
        StrategyParameterSchemaService $schemas,
        LabDatasetExportService $datasets,
        StrategySemanticGroupService $semanticGroups,
        LearningProtocolSafetyService $protocolSafety,
    ): int {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: exit-topology experiments deferred.');

            return self::SUCCESS;
        }
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $parent = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $parent || ! $lab || $source->lifecycle_status !== 'screened') {
            $this->error('Source must be a screened laboratory agent.');

            return self::FAILURE;
        }
        if ((string) $lab->lifecycle_mode !== 'lighthouse') {
            $this->info('Source laboratory shadow rejimida; exit-topology dispatch qilinmadi.');

            return self::SUCCESS;
        }
        if ($this->option('resume')) {
            $pending = LabAgent::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('lifecycle_status', 'queued')->whereHas('generation', fn ($query) => $query->where('trigger_type', 'counterfactual_exit_single_gene'))
                ->orderBy('id')->get();
            if ($pending->isEmpty()) {
                $this->info('No pending exit experiments to resume.');

                return self::SUCCESS;
            }
            $batch = Bus::batch($pending->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen'))->all())
                ->name("{$lab->symbol} resumed frozen-entry exit topology experiments")
                ->allowFailures()
                ->onConnection((string) config('queue.default', 'redis'))->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))->dispatch();
            $this->info("{$lab->symbol}: {$batch->id}; {$pending->count()} pending exit experiments resumed.");

            return self::SUCCESS;
        }
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi exit generation locklandi.");

            return self::SUCCESS;
        }
        $family = $source->strategy_family;
        $sourceGroup = $semanticGroups->fromModel($parent, $family);
        $sourceNiche = [
            'role' => data_get($sourceGroup, 'role'),
            'regime' => data_get($sourceGroup, 'regime'),
            'volatility' => data_get($sourceGroup, 'volatility'),
            'direction' => data_get($sourceGroup, 'direction'),
        ];
        // A declared group is not enough: the source must be the exact
        // canonical parent for the child cell. Legacy/unscoped records are
        // diagnostic controls only and force a fresh group-root seed.
        $geneticParent = $semanticGroups->exactParentCompatible(
            $parent,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $sourceNiche,
        ) ? $parent : null;
        $parentGroup = $geneticParent
            ? $semanticGroups->descriptor($lab->symbol, $lab->timeframe, $family, $sourceNiche)
            : $semanticGroups->descriptor($lab->symbol, $lab->timeframe, $family, ['role' => 'general']);
        // G83 already falsified wider fixed stops, shorter targets and a
        // 24-candle stop.  This is the next sequential race: genuinely
        // different exit lifecycle questions, each with one changed gene.
        $variants = [
            ['atr_stop_multiplier' => 1.25],
            ['atr_target_multiplier' => 3.0],
            ['trailing_atr_multiplier' => 1.0],
            ['time_stop_candles' => 12],
            ['partial_take_profit_fraction' => .25],
            ['max_spread_atr_ratio' => .15],
        ];
        $cohort = DB::transaction(function () use ($source, $parent, $parentGroup, $lab, $family, $variants, $schemas, $protocolSafety) {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($protocolSafety->generationCreationPaused()) {
                return [];
            }
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) {
                return [];
            }
            $number = (int) ($lockedLab->generations()->latest('generation')->lockForUpdate()->value('generation') ?? 0) + 1;
            $generation = $lockedLab->generations()->create([
                'generation' => $number, 'trigger_type' => 'counterfactual_exit_single_gene',
                'trigger_context' => [
                    'source_agent_id' => $source->id,
                    'interventions' => $variants,
                    'entry_topology' => 'frozen',
                    'semantic_parent_protocol' => 'sealed_source_then_same_generation_control_v1',
                    'research_allocation_budget' => ['mode' => 'normal_research'],
                    'control_pairing_contract' => [
                        'protocol' => 'control_first_exit_topology_pairing_v1',
                        'allowed' => true,
                        'control_must_screen_before_candidates' => true,
                        'exact_same_generation_snapshot_required' => true,
                    ],
                    'promotion_evidence' => false,
                ],
                'data_fingerprint' => $source->generation->data_fingerprint,
                'population_size' => count($variants) + 1,
                'status' => 'screening',
                'started_at' => now(),
            ]);
            $baseParameters = $schemas->validate($family, [
                ...$schemas->defaults($family),
                ...((array) $parent->parameters),
            ]);
            $pairKey = "exit-topology-g{$number}-source-{$source->id}";
            $controlStrategy = strtolower($lab->symbol).'_'.$family.'_g'.$number.'_exit_control';
            $controlModel = ModelVersion::create([
                'name' => $controlStrategy, 'strategy' => $controlStrategy, 'version' => 'v'.$number.'-exit-control',
                'generation' => $number, 'status' => 'testing', 'parameters' => $baseParameters,
                'description' => "Frozen same-generation control for exit experiments from {$source->id}.",
                'metadata' => [
                    ...((array) $parent->metadata),
                    'semantic_group' => $parentGroup,
                    'lab_symbol' => $lab->symbol, 'lab_timeframe' => $lab->timeframe,
                    'base_strategy' => data_get($parent->metadata, 'base_strategy') ?: $family.'_v1',
                    'counterfactual_exit_contract' => [
                        'protocol' => 'control_first_exit_topology_pairing_v1', 'source_agent_id' => $source->id,
                        'entry_topology' => 'frozen', 'role' => 'frozen_control', 'promotion_evidence' => false,
                    ],
                    'control_pair_contract' => ['protocol' => 'control_first_exit_topology_pairing_v1', 'pair_key' => $pairKey],
                    'mutation_constructor_invariant' => [
                        'protocol' => 'agent_constructor_invariant_v1', 'status' => 'passed', 'control_only' => true,
                        'single_gene_required' => false, 'changed_parameter_keys' => [], 'parameter_diff_count' => 0,
                        'parent_model_version_id' => $parent->id, 'promotion_evidence' => false,
                    ],
                    'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                ],
            ]);
            $control = $generation->agents()->create([
                'model_version_id' => $controlModel->id, 'parent_a_model_version_id' => $parent->id,
                'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
                'origin' => 'causal_isolation_control', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
                'decision_reason' => 'Frozen exact same-generation control; candidates remain chained until its screen evidence persists.',
            ]);
            $created = collect();
            foreach ($variants as $variant) {
                $key = array_key_first($variant);
                $parameters = $schemas->validate($family, [...$baseParameters, ...$variant]);
                $strategy = strtolower($lab->symbol).'_'.$family.'_g'.$number.'_exit_'.$key;
                $model = ModelVersion::create([
                    'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$number.'-exit-'.$key,
                    'generation' => $number, 'status' => 'testing', 'parameters' => $parameters,
                    'description' => "Frozen-entry one-gene exit experiment {$key} from {$source->id}.",
                    'metadata' => [
                        ...((array) $parent->metadata),
                        'semantic_group' => $parentGroup,
                        'lab_symbol' => $lab->symbol, 'lab_timeframe' => $lab->timeframe,
                        'base_strategy' => data_get($parent->metadata, 'base_strategy') ?: $family.'_v1',
                        'declared_gene' => $key,
                        'counterfactual_exit_contract' => ['protocol' => 'control_first_exit_topology_pairing_v1', 'source_agent_id' => $source->id, 'entry_topology' => 'frozen',
                            'single_gene' => $key, 'promotion_evidence' => false],
                        'control_pair_contract' => [
                            'protocol' => 'control_first_exit_topology_pairing_v1', 'pair_key' => $pairKey,
                            'required_for_candidate' => true, 'control_agent_id' => $control->id,
                        ],
                        'shadow_mutation_contract' => [
                            'protocol' => 'control_first_exit_topology_pairing_v1', 'gene' => $key,
                            'behavioral_change_required' => true, 'trade_ledger_delta_required' => true,
                            'control_pair_required' => true,
                            'behavioral_falsification' => ['expected_change' => $this->expectedBehavioralChangeForGene($key)],
                            'promotion_evidence' => false,
                        ],
                        'semantic_lineage' => [
                            'protocol' => 'strict_semantic_lineage_v2',
                            'mode' => 'sealed_screening_source',
                            'genetic_parent_model_version_id' => $controlModel->id,
                            'diagnostic_source_model_version_id' => $parent->id,
                            'promotion_evidence' => false,
                        ],
                        'parent_inheritance_protocol' => [
                            'protocol' => 'exact_semantic_parent_or_group_root_v1',
                            'parent_selection' => 'same_generation_frozen_control',
                            'cross_cell_parent_forbidden' => true,
                            'legacy_parent_genetic_material' => false,
                            'promotion_evidence' => false,
                        ],
                        'mutation_constructor_invariant' => [
                            'protocol' => 'agent_constructor_invariant_v1',
                            'status' => 'passed',
                            'single_gene_required' => true,
                            'changed_parameter_keys' => [$key],
                            'parameter_diff_count' => 1,
                            'parent_model_version_id' => $controlModel->id,
                            'promotion_evidence' => false,
                        ],
                        'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                    ],
                ]);
                $created->push($generation->agents()->create([
                    'model_version_id' => $model->id, 'parent_a_model_version_id' => $controlModel->id,
                    'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
                    'origin' => 'causal_isolation', 'lifecycle_status' => 'queued',
                    'parameter_diff' => [$key => ['old' => data_get($baseParameters, $key), 'new' => $variant[$key]]],
                    'decision_reason' => 'Frozen-entry counterfactual exit experiment; screening evidence only.',
                ]));
            }

            return ['control' => $control, 'candidates' => $created];
        });
        if ($cohort === []) {
            $this->warn("{$lab->symbol}: active generation lock prevented exit experiment creation.");

            return self::SUCCESS;
        }
        $datasets->export($lab->symbol, $lab->timeframe);
        $control = $cohort['control'];
        $candidates = $cohort['candidates'];
        Bus::chain([
            new EvaluateLabScreeningBatchJob([$control->id], $lab->symbol, 0),
            new EvaluateLabScreeningBatchJob($candidates->pluck('id')->map(fn ($id): int => (int) $id)->all(), $lab->symbol, 1),
        ])->onConnection((string) config('queue.default', 'redis'))
            ->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))
            ->dispatch();
        $this->info("{$lab->symbol}: control {$control->id} queued first, then {$candidates->count()} exact-paired exit experiments. No full-validation/paper jobs were dispatched.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function expectedBehavioralChangeForGene(string $gene): array
    {
        return match ($gene) {
            'max_spread_atr_ratio' => ['accepted_entries' => 'change', 'accepted_trade_set' => 'change'],
            default => ['accepted_entries' => 'unchanged_or_near', 'exit_state' => 'change',
                'holding_time_distribution' => 'change', 'stress_cost' => 'change'],
        };
    }
}
