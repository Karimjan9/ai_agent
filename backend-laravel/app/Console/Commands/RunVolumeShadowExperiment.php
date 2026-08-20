<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\VolumeShadowExperiment;
use App\Services\LabPopulationService;
use App\Services\LabDatasetExportService;
use App\Services\ExecutionContractService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runs an observational no-volume control beside the canonical-volume
 * dataset. Neither replay can create promotion or paper evidence.
 */
class RunVolumeShadowExperiment extends Command
{
    private const PROTOCOL = 'volume_shadow_control_v1';
    private const SOURCE_CONTRACT = 'dukascopy_jetta_bid_tick_volume_millions_v1';

    protected $signature = 'trading:volume-shadow {agent : Screened/challenger parent agent id}';

    protected $description = 'Compare no-volume control with canonical-volume shadow evidence without changing gates';

    public function handle(
        LabDatasetExportService $datasets,
        StrategyParameterSchemaService $schemas,
    ): int {
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->find((int) $this->argument('agent'));
        if (! $source || ! $source->modelVersion || ! $source->generation?->laboratory) {
            $this->error('Source agent/model/laboratory topilmadi.');
            return self::FAILURE;
        }
        if (! in_array($source->lifecycle_status, ['screened', 'challenger', 'stagnated', 'rejected'], true)) {
            $this->error('Volume shadow faqat standalone screen/challenger candidate uchun ishlaydi.');
            return self::FAILURE;
        }
        $latest = $source->generation->laboratory->generations()->latest('generation')->first();
        $active = $latest && in_array($latest->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true);
        if ($active) {
            $this->warn('Faol generation mavjud. G109/frozen forward tugamaguncha volume shadow o‘zgartirilmadi.');
            return self::SUCCESS;
        }

        $symbol = strtoupper((string) $source->symbol);
        $timeframe = strtoupper((string) $source->timeframe);
        try {
            $controlDataset = $datasets->export($symbol, $timeframe, false);
            $volumeDataset = $datasets->export($symbol, $timeframe, true);
            $manifestPath = $volumeDataset.'.manifest.json';
            $manifest = is_file($manifestPath)
                ? (array) json_decode(File::get($manifestPath), true)
                : [];
            $quality = (array) data_get($manifest, 'volume_quality', []);
            if (data_get($quality, 'status') !== 'passed') {
                throw new RuntimeException('Canonical volume quality gate pass emas; shadow experiment bloklandi.');
            }

            $model = $source->modelVersion;
            $baseStrategy = $schemas->runtimeBaseStrategy(
                $model->strategy,
                data_get($model->metadata, 'base_strategy'),
                $source->strategy_family,
            );
            $parameters = (array) ($model->parameters ?? []);
            // The Python contract is an object, not a JSON list.  Keep the
            // no-volume control explicit so a missing column is recorded as
            // volume_unavailable and can never be interpreted as low volume.
            $controlVolumeContext = [
                'status' => 'volume_unavailable',
                'reason' => 'no_volume_control',
                'provider' => 'dukascopy',
                'unit' => 'millions',
                'session' => 'UTC',
                'source_contract' => self::SOURCE_CONTRACT,
                'protocol' => 'relative_volume_session_v2',
                'promotion_evidence' => false,
            ];
            $controlParameters = [...$parameters, 'volume_lane' => 'none'];
            $control = $this->replay(
                $source,
                $model->strategy,
                $baseStrategy,
                $controlParameters,
                $controlDataset,
                $controlVolumeContext,
                'volume-shadow-control-'.$source->id,
            );
            $volumeParameters = [...$parameters, 'volume_lane' => 'none'];
            $volume = $this->replay(
                $source,
                $model->strategy,
                $baseStrategy,
                $volumeParameters,
                $volumeDataset,
                $quality,
                'volume-shadow-observation-'.$source->id,
            );

            $controlResult = (array) data_get($control, 'result', []);
            $volumeResult = (array) data_get($volume, 'result', []);
            $metrics = [
                'protocol' => self::PROTOCOL,
                'promotion_evidence' => false,
                'quality' => $quality,
                'control' => $this->metricSnapshot($controlResult),
                'volume_control' => $this->metricSnapshot($volumeResult),
                'delta_volume_control_minus_control' => [
                    'profit_factor' => round((float) data_get($volumeResult, 'profit_factor', 0) - (float) data_get($controlResult, 'profit_factor', 0), 6),
                    'net_profit_percent' => round((float) data_get($volumeResult, 'net_profit_percent', 0) - (float) data_get($controlResult, 'net_profit_percent', 0), 6),
                    'max_drawdown_percent' => round((float) data_get($volumeResult, 'max_drawdown_percent', data_get($volumeResult, 'max_drawdown', 0)) - (float) data_get($controlResult, 'max_drawdown_percent', data_get($controlResult, 'max_drawdown', 0)), 6),
                ],
                'volume_shadow' => data_get($volumeResult, 'volume_shadow', []),
            ];
            $experiment = VolumeShadowExperiment::create([
                'lab_agent_id' => $source->id,
                'model_version_id' => $model->id,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => 'assessed',
                'protocol' => self::PROTOCOL,
                'source_contract' => self::SOURCE_CONTRACT,
                'data_hash' => (string) data_get($manifest, 'sha256', ''),
                'metrics' => $metrics,
                'promotion_evidence' => false,
            ]);
            $this->info("Volume shadow #{$experiment->id} assessed; promotion_evidence=false.");
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            VolumeShadowExperiment::create([
                'lab_agent_id' => $source->id,
                'model_version_id' => $source->modelVersion->id,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => 'failed',
                'protocol' => self::PROTOCOL,
                'source_contract' => self::SOURCE_CONTRACT,
                'data_hash' => null,
                'metrics' => ['error' => $exception->getMessage(), 'promotion_evidence' => false],
                'promotion_evidence' => false,
            ]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function replay(
        LabAgent $source,
        string $strategy,
        string $baseStrategy,
        array $parameters,
        string $dataset,
        array $volumeContext,
        string $requestId,
    ): array {
        $response = Http::connectTimeout(15)->timeout(2280)->acceptJson()->withHeaders([
            'X-Internal-Token' => (string) config('services.internal_api.token'),
            'X-Lab-Request-Id' => $requestId,
        ])->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run-all', [
            'symbol' => $source->symbol,
            'timeframe' => $source->timeframe,
            'strategy' => $strategy,
            'base_strategy' => $baseStrategy,
            'evaluation_mode' => 'replay',
            'dataset_path' => $dataset,
            'strategies' => [[
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'version' => $source->modelVersion->version,
                'parameters' => $parameters,
            ]],
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'volume_context' => $volumeContext,
            'policy_context' => [
                'data_boundary' => [
                    'protocol' => 'pre_2026_training_paper_only_v1',
                    'training_end_exclusive' => '2026-01-01T00:00:00Z',
                    'paper_allowed_for_replay' => false,
                    'paper_allowed_for_mutation' => false,
                    'promotion_evidence' => false,
                ],
            ],
            'execution' => $this->executionAssumptions((string) $source->symbol),
            'execution_contract' => app(ExecutionContractService::class)->for((string) $source->symbol, (string) $source->timeframe),
            'emit_decision_trace' => false,
        ]);
        if ($response->failed()) {
            throw new RuntimeException("Volume shadow replay failed: {$response->body()}");
        }
        $item = (array) data_get($response->json(), 'leaderboard.0', []);
        if ($item === []) {
            throw new RuntimeException('Volume shadow replay empty result returned.');
        }
        return $item;
    }

    private function metricSnapshot(array $result): array
    {
        return [
            'profit_factor' => data_get($result, 'profit_factor', 0),
            'total_trades' => data_get($result, 'total_trades', 0),
            'net_profit_percent' => data_get($result, 'net_profit_percent', 0),
            'max_drawdown_percent' => data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0)),
            'opportunity_recall' => data_get($result, 'opportunity_recall', []),
        ];
    }

    private function executionAssumptions(string $symbol): array
    {
        return app(ExecutionContractService::class)->parameters($symbol);
    }
}
