<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Creates exactly two no-promotion, single-gene cooldown experiments. */
class DispatchCooldownCausalRescue extends Command
{
    protected $signature = 'trading:dispatch-cooldown-causal-rescue {sourceAgent : Screened agent id with loss_cooldown_candles=4}';

    protected $description = 'Create and screen only 4→2 and 4→3 loss-cooldown causal rescue variants';

    public function handle(
        StrategyParameterSchemaService $schemas,
        LabDatasetExportService $datasets,
        StrategySemanticGroupService $semanticGroups,
    ): int {
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $model = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $model || ! $lab || $source->lifecycle_status !== 'screened'
            || (int) data_get($model->parameters, 'loss_cooldown_candles') !== 4) {
            $this->error('Source must be a screened laboratory agent with loss_cooldown_candles = 4.');

            return self::FAILURE;
        }
        $sourceGroup = $semanticGroups->fromModel($model, $source->strategy_family);
        $sourceNiche = [
            'role' => data_get($sourceGroup, 'role'),
            'regime' => data_get($sourceGroup, 'regime'),
            'volatility' => data_get($sourceGroup, 'volatility'),
            'direction' => data_get($sourceGroup, 'direction'),
        ];
        $geneticParent = $semanticGroups->exactParentCompatible(
            $model,
            $lab->symbol,
            $lab->timeframe,
            $source->strategy_family,
            $sourceNiche,
        ) ? $model : null;
        $parentGroup = $geneticParent
            ? $semanticGroups->descriptor($lab->symbol, $lab->timeframe, $source->strategy_family, $sourceNiche)
            : $semanticGroups->descriptor($lab->symbol, $lab->timeframe, $source->strategy_family, ['role' => 'general']);
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi rescue generation locklandi.");

            return self::SUCCESS;
        }

        $generation = DB::transaction(function () use ($source, $model, $geneticParent, $parentGroup, $lab, $schemas): ?LabGeneration {
            $lockedLab = $lab->newQuery()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) {
                return null;
            }
            $number = (int) ($lockedLab->generations()->latest('generation')->lockForUpdate()->value('generation') ?? 0) + 1;
            $generation = $lockedLab->generations()->create([
                'generation' => $number,
                'trigger_type' => 'causal_cooldown_rescue',
                'trigger_context' => [
                    'source_agent_id' => $source->id,
                    'source_model_version_id' => $model->id,
                    'rescue_protocol' => 'loss_cooldown_single_gene_v1',
                    'variants' => [2, 3],
                    'promotion_evidence' => false,
                    'required_screen_contract' => [
                        'minimum_trades' => 10, 'minimum_profit_factor' => 1.30,
                        'minimum_stress_profit_factor' => 1.05, 'minimum_worst_regime_profit_factor' => 1.00,
                        'minimum_temporal_chunk_profit_factor' => 1.00, 'maximum_train_forward_gap' => 25.0,
                        'minimum_parameter_stability' => .80,
                    ],
                ],
                'data_fingerprint' => $source->generation?->data_fingerprint,
                'population_size' => 2, 'status' => 'draft', 'started_at' => now(),
            ]);

            foreach ([2, 3] as $slot => $cooldown) {
                $baseParameters = [...$schemas->defaults($source->strategy_family), ...($geneticParent?->parameters ?? [])];
                $parameters = $schemas->validate($source->strategy_family, [
                    ...$baseParameters, 'loss_cooldown_candles' => $cooldown,
                ]);
                $metadata = $geneticParent?->metadata ?? [];
                Arr::forget($metadata, ['last_screen_result', 'last_result', 'full_validation_batch', 'screening_history']);
                $metadata = [
                    ...$metadata,
                    'origin' => 'causal_cooldown_rescue',
                    'semantic_group' => $parentGroup,
                    'lab_symbol' => $lab->symbol, 'lab_timeframe' => $lab->timeframe,
                    'semantic_lineage' => [
                        'protocol' => 'strict_semantic_lineage_v2',
                        'mode' => $geneticParent ? 'exact_semantic_parent' : 'semantic_group_root_default_seed',
                        'genetic_parent_model_version_id' => $geneticParent?->id,
                        'diagnostic_source_model_version_id' => $model->id,
                        'promotion_evidence' => false,
                    ],
                    'parent_inheritance_protocol' => [
                        'protocol' => 'exact_semantic_parent_or_group_root_v1',
                        'parent_selection' => $geneticParent ? 'exact_semantic_parent' : 'exact_group_root_default',
                        'cross_cell_parent_forbidden' => true,
                        'legacy_parent_genetic_material' => false,
                        'promotion_evidence' => false,
                    ],
                    'mutation_constructor_invariant' => [
                        'protocol' => 'agent_constructor_invariant_v1',
                        'status' => 'passed',
                        'single_gene_required' => true,
                        'changed_parameter_keys' => ['loss_cooldown_candles'],
                        'parameter_diff_count' => 1,
                        'parent_model_version_id' => $geneticParent?->id,
                        'promotion_evidence' => false,
                    ],
                    'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                    'generation_target' => 'shadow_veto_loss_cooldown',
                    'causal_experiment_lane' => [
                        'status' => 'single_gene', 'source_agent_id' => $source->id,
                        'rule' => 'Only loss_cooldown_candles differs from the frozen source.',
                    ],
                    'causal_rescue_contract' => [
                        'kind' => 'loss_cooldown_single_gene', 'source_agent_id' => $source->id,
                        'variant' => ['loss_cooldown_candles' => $cooldown],
                        'promotion_evidence' => false,
                    ],
                ];
                $strategy = strtolower($lab->symbol).'_'.strtolower($source->strategy_family).'_g'.$number.'_cooldown_'.$cooldown;
                $variant = ModelVersion::create([
                    'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$number.'-cooldown-'.$cooldown,
                    'generation' => $number, 'status' => 'testing', 'parameters' => $parameters,
                    'description' => "Causal cooldown rescue from agent {$source->id}: 4→{$cooldown} only.",
                    'metadata' => $metadata,
                ]);
                $generation->agents()->create([
                    'model_version_id' => $variant->id, 'parent_a_model_version_id' => $geneticParent?->id,
                    'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe,
                    'strategy_family' => $source->strategy_family, 'origin' => 'causal_isolation',
                    'lifecycle_status' => 'draft',
                    'parameter_diff' => ['loss_cooldown_candles' => ['old' => data_get($baseParameters, 'loss_cooldown_candles'), 'new' => $cooldown]],
                    'decision_reason' => "Causal cooldown rescue: 4→{$cooldown}; strict screen contract required.",
                ]);
            }

            return $generation->load('agents');
        });
        if (! $generation) {
            $this->warn("{$lab->symbol}: active generation lock prevented cooldown rescue creation.");

            return self::SUCCESS;
        }

        $datasets->export($lab->symbol, $lab->timeframe);
        $generation->agents()->where('lifecycle_status', 'draft')->get()->each(
            fn (LabAgent $agent) => $agent->update(['lifecycle_status' => 'queued'])
        );
        $generation->update(['status' => 'screening']);
        $jobs = $generation->fresh('agents')->agents->map(
            fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen')
        )->all();
        $batch = Bus::batch($jobs)->name("{$lab->symbol} cooldown causal rescue G{$generation->generation}")
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))->dispatch();
        $this->info("{$lab->symbol} G{$generation->generation}: {$batch->id}; cooldown variants 4→2 and 4→3 dispatched.");

        return self::SUCCESS;
    }
}
