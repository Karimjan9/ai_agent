<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\AgentConstitutionService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use App\Services\UniversalAgentCapabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** A bounded architecture rescue for a demonstrated trend-down regime deficit. */
class DispatchTrendDownSpecialistRescue extends Command
{
    protected $signature = 'trading:dispatch-trend-down-specialist-rescue {sourceAgent : Screened parent whose trend-down regime failed}';
    protected $description = 'Create one frozen trend-down specialist/router candidate for screening only; never bypasses Forward or Paper gates.';

    public function handle(
        StrategyParameterSchemaService $schemas,
        LabDatasetExportService $datasets,
        AgentConstitutionService $constitutions,
        UniversalAgentCapabilityService $universalCapabilities,
    ): int {
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $sourceModel = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $sourceModel || ! $lab || $source->lifecycle_status !== 'screened') {
            $this->error('Source must be a completed screened laboratory agent.');
            return self::FAILURE;
        }
        if ((float) data_get($sourceModel->metadata, 'last_screen_result.screening_survival.worst_regime_pf', 99) >= 1.0) {
            $this->error('This rescue is allowed only for an evidenced regime failure (worst regime PF < 1).');
            return self::FAILURE;
        }
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi specialist rescue generation locklandi.");
            return self::SUCCESS;
        }

        $generation = DB::transaction(function () use ($source, $sourceModel, $lab, $schemas, $constitutions, $universalCapabilities) {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) return null;
            $number = (int) ($lockedLab->generations()->latest('generation')->lockForUpdate()->value('generation') ?? 0) + 1;
            $generation = $lockedLab->generations()->create([
                'generation' => $number, 'trigger_type' => 'targeted_trend_down_specialist_rescue',
                'trigger_context' => [
                    'source_agent_id' => $source->id, 'source_model_version_id' => $sourceModel->id,
                    'failure_target' => 'trend_down_regime_coverage', 'promotion_evidence' => false,
                    'router_policy' => 'frozen_regime_specialist_ensemble_v2',
                    'required_screen_contract' => [
                        'minimum_trades' => 10, 'minimum_profit_factor' => 1.30,
                        'minimum_stress_profit_factor' => 1.05, 'minimum_worst_regime_profit_factor' => 1.00,
                        'minimum_temporal_chunk_profit_factor' => 1.00, 'maximum_train_forward_gap' => 25.0,
                        'minimum_parameter_stability' => .80,
                    ],
                ],
                'data_fingerprint' => $source->generation->data_fingerprint,
                'population_size' => 1, 'status' => 'draft', 'started_at' => now(),
            ]);

            // Preserve the parent's execution controls, but make the topology
            // explicit: trend_down gets a separate signal filter and a bounded
            // local risk adapter.  Trend-up/range/breakout controls come from
            // the frozen ensemble defaults and cannot be post-hoc selected.
            $parameters = [
                ...$schemas->defaults('regime_ensemble'),
                ...array_intersect_key($sourceModel->parameters ?? [], $schemas->schema('regime_ensemble')),
                'trend_down_strength_min' => 28.0,
                'trend_down_pullback_atr_fraction' => .60,
                'trend_down_risk_multiplier' => .50,
            ];
            $parameters = $schemas->validate('regime_ensemble', $parameters);
            $architecture = 'frozen_regime_specialist_ensemble_v2';
            $constitution = $constitutions->draft($lab->symbol, $lab->timeframe, 'regime_ensemble', $architecture, $parameters);
            $genome = $universalCapabilities->genome($lab->symbol, $lab->timeframe, 'regime_ensemble', $architecture, $parameters, $sourceModel);
            $strategy = strtolower($lab->symbol).'_regime_ensemble_g'.$number.'_trend_down_rescue';
            $model = ModelVersion::create([
                'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$number.'-trend-down-rescue',
                'generation' => $number, 'status' => 'testing', 'parameters' => $parameters,
                'description' => "Trend-down specialist/router rescue from screened agent {$source->id}; screening evidence only.",
                'metadata' => [
                    'base_strategy' => 'regime_ensemble_v1', 'strategy_architecture' => $architecture,
                    'lab_symbol' => $lab->symbol, 'origin' => 'architecture',
                    'generation_target' => 'trend_down_regime_coverage', 'statistical_gate_version' => 3,
                    'trend_down_specialist_contract' => [
                        'source_agent_id' => $source->id,
                        'policy' => 'trend_down uses its own strength/pullback/risk adapter; one router signal per candle.',
                        'promotion_evidence' => false,
                    ],
                    'agent_constitution' => $constitution, 'universal_genome' => $genome,
                ],
            ]);
            $generation->agents()->create([
                'model_version_id' => $model->id, 'parent_a_model_version_id' => $sourceModel->id,
                'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe,
                'strategy_family' => 'regime_ensemble', 'origin' => 'architecture', 'lifecycle_status' => 'queued',
                'parameter_diff' => [
                    'architecture' => ['old' => data_get($sourceModel->metadata, 'strategy_architecture'), 'new' => $architecture],
                    'trend_down_specialist' => ['strength_min' => 28.0, 'pullback_atr_fraction' => .60, 'risk_multiplier' => .50],
                ],
                'decision_reason' => 'Targeted trend-down specialist/router rescue; strict screen gate required and no promotion was dispatched.',
            ]);
            return $generation->load('agents');
        });
        if (! $generation) {
            $this->warn("{$lab->symbol}: active generation lock prevented specialist rescue creation.");
            return self::SUCCESS;
        }

        $datasets->export($lab->symbol, $lab->timeframe);
        $generation->update(['status' => 'screening']);
        $agent = $generation->agents->firstOrFail();
        $batch = Bus::batch([new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen')])
            ->name("{$lab->symbol} trend-down specialist rescue G{$generation->generation}")
            ->allowFailures()
            ->onConnection('database')->onQueue('lab-'.strtolower($lab->symbol))->dispatch();
        $this->info("{$lab->symbol} G{$generation->generation}: {$batch->id}; trend-down specialist/router queued for screening only. No full-validation or paper job was dispatched.");
        return self::SUCCESS;
    }
}
