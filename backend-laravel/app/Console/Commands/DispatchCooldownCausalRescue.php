<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Creates exactly two no-promotion, single-gene cooldown experiments. */
class DispatchCooldownCausalRescue extends Command
{
    protected $signature = 'trading:dispatch-cooldown-causal-rescue {sourceAgent : Screened agent id with loss_cooldown_candles=4}';
    protected $description = 'Create and screen only 4→2 and 4→3 loss-cooldown causal rescue variants';

    public function handle(StrategyParameterSchemaService $schemas, LabDatasetExportService $datasets): int
    {
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $model = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $model || ! $lab || $source->lifecycle_status !== 'screened'
            || (int) data_get($model->parameters, 'loss_cooldown_candles') !== 4) {
            $this->error('Source must be a screened laboratory agent with loss_cooldown_candles = 4.');
            return self::FAILURE;
        }
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi rescue generation locklandi.");
            return self::SUCCESS;
        }

        $generation = DB::transaction(function () use ($source, $model, $lab, $schemas): ?LabGeneration {
            $lockedLab = $lab->newQuery()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) return null;
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
                $parameters = $schemas->validate($source->strategy_family, [
                    ...($model->parameters ?? []), 'loss_cooldown_candles' => $cooldown,
                ]);
                $metadata = $model->metadata ?? [];
                Arr::forget($metadata, ['last_screen_result', 'last_result', 'full_validation_batch', 'screening_history']);
                $metadata = [
                    ...$metadata,
                    'origin' => 'causal_cooldown_rescue',
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
                    'model_version_id' => $variant->id, 'parent_a_model_version_id' => $model->id,
                    'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe,
                    'strategy_family' => $source->strategy_family, 'origin' => 'causal_isolation',
                    'lifecycle_status' => 'draft',
                    'parameter_diff' => ['loss_cooldown_candles' => ['old' => 4, 'new' => $cooldown]],
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
            ->onConnection('database')->onQueue('lab-'.strtolower($lab->symbol))->dispatch();
        $this->info("{$lab->symbol} G{$generation->generation}: {$batch->id}; cooldown variants 4→2 and 4→3 dispatched.");
        return self::SUCCESS;
    }
}
