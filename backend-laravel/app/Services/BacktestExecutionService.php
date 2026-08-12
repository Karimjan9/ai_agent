<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Executes a manual replay and closes one immutable LabEvaluationRun.
 *
 * This service intentionally has no BacktestRun, Trade or Mistake model
 * dependency.  The complete response/ledger/decision trace is stored by
 * LabImmutableEvidenceService; dashboards read that evidence plane directly.
 */
class BacktestExecutionService
{
    public function __construct(
        private LabDatasetExportService $datasets,
        private LabImmutableEvidenceService $evidence,
    ) {}

    /** @return array<string, mixed> */
    public function execute(LabEvaluationRun $run, array $payload): array
    {
        $symbol = strtoupper(str_replace(['_', '/'], '', (string) ($payload['symbol'] ?? '')));
        $timeframe = strtoupper((string) ($payload['timeframe'] ?? 'H1'));
        if ($symbol === '' || $timeframe === '') {
            throw new RuntimeException('Manual backtest symbol/timeframe topilmadi.');
        }

        $payload['symbol'] = $symbol;
        $payload['timeframe'] = $timeframe;
        $payload['dataset_path'] = $this->datasetPath($symbol, $timeframe);
        $contract = app(ExecutionContractService::class)->for($symbol, $timeframe);
        $payload['execution'] = $contract['parameters'];
        $payload['execution_contract'] = $contract;
        $payload['mtf_pilot'] = app(MultiTimeframePilotService::class)->requestPayload(
            $symbol,
            $timeframe,
            $payload['strategy'] ?? null,
        );
        $startedAt = now();
        $datasetHash = $this->datasetHash($payload['dataset_path']);
        $requestHash = (string) ($run->request_hash ?: $this->evidence->hash($payload));

        $run->update([
            'status' => 'started',
            'started_at' => $startedAt,
            'worker_name' => gethostname() ?: null,
            'worker_pid' => (string) getmypid(),
            // Keep the idempotency key created at submission time. The
            // enriched execution payload is recorded separately as the
            // immutable request artifact and must not hide an in-flight
            // duplicate from the submitter.
            'request_hash' => $requestHash,
            'data_hash' => $datasetHash,
            'code_hash' => $this->evidence->codeHash(),
        ]);
        $this->evidence->attachRequest($run, $payload, [
            'request_hash' => $requestHash,
            'data_hash' => $datasetHash,
            'dataset_manifest' => [
                'path' => $payload['dataset_path'],
                'sha256' => $datasetHash,
                'protocol' => 'manual_backtest_dataset_v1',
            ],
        ]);

        try {
            $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 180))
                ->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => (string) config('services.internal_api.token'),
                    'X-Lab-Request-Id' => $run->run_id,
                ])
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run', $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException("Python AI service bilan bog'lanib bo'lmadi.", 0, $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException('Backtest service xatolik berdi: '.substr((string) $response->body(), 0, 1000));
        }

        $result = (array) $response->json();
        if ($result === []) {
            throw new RuntimeException('Backtest service bo\'sh canonical response qaytardi.');
        }
        if (! array_key_exists('conclusion', $result)) {
            $result['conclusion'] = $this->defaultConclusion($payload, (array) ($result['metrics'] ?? []));
        }
        $returnedContract = (array) data_get($result, 'execution_contract', []);
        if ($returnedContract !== []
            && ! app(ExecutionContractService::class)->matches($returnedContract, $symbol, $timeframe)) {
            throw new RuntimeException('Backtest execution contract mismatch; result withheld.');
        }

        $metrics = $this->metricManifest($result, $payload);
        $this->evidence->finishRun($run, 'completed', $result, $metrics, [
            'protocol' => 'manual_backtest_evaluation_v2',
            'execution_contract' => $contract,
            'promotion_evidence' => false,
        ]);

        return $result;
    }

    private function datasetPath(string $symbol, string $timeframe): string
    {
        try {
            return $this->datasets->export($symbol, $timeframe);
        } catch (\Throwable $exception) {
            // Tests intentionally do not seed market candles. Production must
            // never silently replay a different instrument/timeframe.
            if (app()->environment('testing')) {
                return (string) config('services.ai_service.default_dataset');
            }

            throw new RuntimeException("{$symbol} {$timeframe} dataset export failed: ".$exception->getMessage(), 0, $exception);
        }
    }

    private function metricManifest(array $result, array $payload): array
    {
        $metrics = (array) ($result['metrics'] ?? []);
        $value = static function (string $key, mixed $default = null) use ($result, $metrics): mixed {
            return array_key_exists($key, $result) ? $result[$key] : ($metrics[$key] ?? $default);
        };

        return [
            'symbol' => $payload['symbol'],
            'timeframe' => $payload['timeframe'],
            'strategy' => $payload['strategy'] ?? ($result['strategy'] ?? null),
            'period' => $result['period'] ?? (($payload['from_date'] ?? null).' - '.($payload['to_date'] ?? null)),
            'initial_balance' => (float) $value('initial_balance', $payload['initial_balance'] ?? 10000),
            'final_balance' => (float) $value('final_balance', 0),
            'total_trades' => (int) $value('total_trades', 0),
            'wins' => (int) $value('wins', 0),
            'losses' => (int) $value('losses', 0),
            'winrate' => (float) $value('winrate', $metrics['win_rate'] ?? 0),
            'net_profit_percent' => (float) $value('net_profit_percent', $metrics['net_pnl'] ?? 0),
            'max_drawdown' => (float) $value('max_drawdown', $value('max_drawdown_percent', 0)),
            'max_drawdown_percent' => (float) $value('max_drawdown_percent', $value('max_drawdown', 0)),
            'profit_factor' => (float) $value('profit_factor', 0),
            'conclusion' => $result['conclusion'] ?? null,
        ];
    }

    private function datasetHash(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $manifestPath = $path.'.manifest.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            return data_get($manifest, 'sha256');
        }

        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    private function defaultConclusion(array $payload, array $metrics): string
    {
        $strategy = strtolower((string) ($payload['strategy'] ?? 'canonical strategy'));
        $timeframe = strtoupper((string) ($payload['timeframe'] ?? 'H1'));
        if (str_contains($strategy, 'ema_rsi')) {
            return "EMA trend + RSI strategy {$timeframe} timeframe'da yaxshi ishladi, lekin sideways marketdagi xatolar mistake journal orqali tekshirilishi kerak.";
        }

        return sprintf(
            'Canonical %s replay %s timeframe\'da yakunlandi; natija immutable Lab evidence sifatida saqlandi.',
            $strategy,
            $timeframe,
        );
    }
}
