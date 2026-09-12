<?php

namespace App\Console\Commands;

use App\Models\MtfAblationRun;
use App\Services\AutonomousModeService;
use App\Services\MtfResearchCohortService;
use App\Services\MtfResearchSnapshotService;
use App\Services\MultiTimeframePilotService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RunMtfAblation extends Command
{
    protected $signature = 'trading:mtf-ablation
        {--candidate= : ModelMarketPerformance id; defaults to the newest valid XAUUSD M15 candidate}
        {--symbol=XAUUSD : Pilot symbol}
        {--json : Print the immutable research result as JSON}';

    protected $description = 'Run the four controlled XAUUSD H1/M15 ablation lanes without promotion side effects';

    public function handle(
        MtfResearchCohortService $cohorts,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
        MtfResearchSnapshotService $snapshots,
        AutonomousModeService $autonomy,
    ): int {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) $this->option('symbol')));
        if (! $autonomy->enabled($symbol, 'H1')) {
            $this->info('MTF ablation deferred: autonomous mode is stopped; monitoring remains available.');

            return self::SUCCESS;
        }
        $cohort = $cohorts->current(
            $symbol,
            filled($this->option('candidate')) ? (int) $this->option('candidate') : null,
        );
        if (($cohort['status'] ?? null) !== 'ready') {
            $this->error('MTF cohort unavailable: '.(string) ($cohort['reason_code'] ?? 'UNKNOWN'));

            return self::FAILURE;
        }
        $candidate = $cohort['candidate'];
        $m15 = (array) $cohort['m15_candles'];
        $h1 = (array) $cohort['h1_candles'];
        $model = $candidate->modelVersion;
        $execution = (array) $cohort['execution'];
        $volumeContext = (array) $cohort['volume_context'];
        $dataHash = (string) $cohort['data_hash'];
        $executionHash = (string) $cohort['execution_hash'];
        $payload = [
            'symbol' => $symbol,
            'timeframe' => 'M15',
            'strategy' => $model->strategy,
            'base_strategy' => $schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $candidate->strategy_family),
            'parameters' => [...((array) ($model->parameters ?? [])), 'volume_lane' => 'none'],
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'execution' => $execution['parameters'],
            'execution_contract' => $execution,
            'volume_context' => $volumeContext,
            'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', $model->strategy),
        ];
        $runKey = $pilot->hash([
            'candidate_id' => $candidate->id,
            'pilot_id' => data_get($payload, 'mtf_pilot.pilot_id'),
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'contract_hash' => data_get($payload, 'mtf_pilot.contract_hash'),
        ]);
        $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
            ->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
            ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-ablation', [
                'base_request' => $payload,
                'h1_candles' => $h1,
                'm15_candles' => $m15,
                'lightweight' => true,
            ]);
        if ($response->failed()) {
            $this->error('MTF ablation AI service xatosi: '.substr((string) $response->body(), 0, 1000));

            return self::FAILURE;
        }

        $result = (array) $response->json();
        $result['candidate_id'] = $candidate->id;
        $result['candidate_model_version_id'] = $model->id;
        $result['promotion_evidence'] = false;
        $snapshotReference = $snapshots->store(
            $runKey,
            $symbol,
            $h1,
            $m15,
            $volumeContext,
            $execution['parameters'],
            $dataHash,
            $executionHash,
            $payload['mtf_pilot'],
        );
        $ablationRun = MtfAblationRun::firstOrCreate(
            ['run_key' => $runKey],
            [
                'model_market_performance_id' => $candidate->id,
                'pilot_id' => (string) data_get($payload, 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                'symbol' => $symbol,
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'run_key' => $runKey,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'status' => 'completed',
                'variants' => (array) ($result['variants'] ?? []),
                'snapshot_reference' => $snapshotReference,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ],
        );
        $result['ablation_run_id'] = $ablationRun->id;
        $result['run_key'] = $runKey;
        $result['data_hash'] = $dataHash;
        $result['execution_hash'] = $executionHash;
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("XAUUSD H1/M15 ablation candidate #{$candidate->id} (research-only)");
        $this->table(
            ['Lane', 'Timeframe', 'Trades', 'PF', 'Net %', 'Max DD %', 'MTF vetoes'],
            collect((array) ($result['variants'] ?? []))->map(function (array $variant, string $name): array {
                return [
                    $name,
                    $variant['timeframe'] ?? '',
                    $variant['total_trades'] ?? 0,
                    $variant['profit_factor'] ?? 0,
                    $variant['net_profit_percent'] ?? 0,
                    $variant['max_drawdown_percent'] ?? 0,
                    data_get($variant, 'mtf_pilot.veto_count', 0),
                ];
            })->values()->all(),
        );
        $this->comment('Natija promotion evidence emas; frozen control = m15_only.');

        return self::SUCCESS;
    }
}
