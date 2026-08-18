<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabEvaluationRun;
use App\Services\CanonicalManualBacktestService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BacktestController extends Controller
{
    public function strategies(): JsonResponse
    {
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->get(rtrim(config('services.ai_service.url'), '/').'/api/strategies');
        } catch (ConnectionException) {
            return response()->json(['message' => 'AI strategy registry bilan bog\'lanib bo\'lmadi.'], 503);
        }

        return response()->json($response->json(), $response->status());
    }

    public function run(Request $request, CanonicalManualBacktestService $manualBacktests): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'regex:/^(XAU|EUR|GBP)(?:[_\/]?USD)$/i'],
            'timeframe' => ['required', 'string', 'in:M15,H1'],
            'strategy' => ['required', 'string', 'regex:/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/i', 'max:96'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'initial_balance' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'risk_per_trade' => ['nullable', 'numeric', 'gt:0', 'max:5'],
        ]);

        $payload = [
            'symbol' => $this->normalizeSymbolCode($validated['symbol']),
            'timeframe' => strtoupper((string) $validated['timeframe']),
            'strategy' => strtolower((string) $validated['strategy']),
            'from_date' => $validated['from'],
            'to_date' => $validated['to'],
            'initial_balance' => (float) ($validated['initial_balance'] ?? 10000),
            'risk_per_trade' => (float) ($validated['risk_per_trade'] ?? 1),
        ];
        $requestHash = $manualBacktests->requestHash($payload);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        $idempotencyKeyHash = $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : null;
        $cacheKey = $idempotencyKeyHash !== null
            ? 'api-canonical-backtest:'.$idempotencyKeyHash
            : null;

        if ($idempotencyKeyHash !== null) {
            $existing = $manualBacktests->findByIdempotencyKey($idempotencyKeyHash);
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    return response()->json([
                        'message' => 'Idempotency-Key boshqa backtest payload bilan allaqachon ishlatilgan.',
                        'code' => 'IDEMPOTENCY_KEY_REUSED',
                    ], 409);
                }

                $body = $this->bodyForRun($existing->fresh(), $payload, $requestHash);
                $status = $existing->status === 'completed' ? 200 : 202;

                Cache::put($cacheKey, [
                    'body' => $body,
                    'status' => $status,
                    'request_hash' => $requestHash,
                ], now()->addHours(24));

                return response()->json($body, $status);
            }
        }

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                if (! hash_equals((string) ($cached['request_hash'] ?? ''), $requestHash)) {
                    return response()->json([
                        'message' => 'Idempotency-Key boshqa backtest payload bilan allaqachon ishlatilgan.',
                        'code' => 'IDEMPOTENCY_KEY_REUSED',
                    ], 409);
                }
                return response()->json($cached['body'], (int) ($cached['status'] ?? 202));
            }
        }

        $lock = $cacheKey ? Cache::lock($cacheKey.':lock', 600) : null;
        if ($lock && ! $lock->get()) {
            return response()->json(['message' => 'Idempotency-Key bilan ayni canonical backtest queue\'da.'], 409);
        }

        try {
            if (! app()->environment('testing') && config('queue.default') === 'sync') {
                return response()->json([
                    'message' => 'Asynchronous Redis queue sozlanmagan. QUEUE_CONNECTION=redis worker ishga tushiring.',
                ], 503);
            }

            $run = $manualBacktests->submit($payload, $requestHash, [
                'idempotency_key_hash' => $idempotencyKeyHash,
                'api' => true,
            ]);
            $run->refresh();
            $status = $run->status === 'completed' ? 200 : 202;
            $body = $this->bodyForRun($run, $payload, $requestHash);

            if ($cacheKey !== null) {
                Cache::put($cacheKey, [
                    'body' => $body,
                    'status' => $status,
                    'request_hash' => $requestHash,
                ], now()->addHours(24));
            }

            $response = response()->json($body, $status);
            if ($status === 202) {
                $response->header('Location', $body['poll_url']);
            }

            return $response;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Canonical backtest queuega yuborilmadi.'], 503);
        } finally {
            $lock?->release();
        }
    }

    public function status(LabEvaluationRun $backtestRun): JsonResponse
    {
        $backtestRun->refresh();
        $technicalError = in_array($backtestRun->status, ['technical_error', 'skipped'], true);

        return response()->json([
            'id' => $backtestRun->id,
            'run_id' => $backtestRun->run_id,
            'source' => 'lab_evaluation_runs',
            'status' => $backtestRun->status,
            'metrics' => $backtestRun->metrics,
            'result_url' => $backtestRun->status === 'completed'
                ? route('backtests.result', ['backtestRun' => $backtestRun->id])
                : null,
            'error' => $technicalError ? $backtestRun->error_message : null,
        ]);
    }

    private function bodyForRun(LabEvaluationRun $run, array $payload, string $requestHash): array
    {
        $metrics = (array) $run->metrics;
        $body = [
            'id' => $run->id,
            'run_id' => $run->run_id,
            'source' => 'lab_evaluation_runs',
            'status' => $run->status,
            'request_hash' => $requestHash,
            'poll_url' => route('api.backtest.status', ['backtestRun' => $run->id]),
            'result_url' => $run->status === 'completed'
                ? route('backtests.result', ['backtestRun' => $run->id])
                : null,
        ];

        if ($run->status !== 'completed') {
            return $body;
        }

        return array_merge($body, [
            'strategy' => $metrics['strategy'] ?? $payload['strategy'],
            'instrument' => $this->displaySymbol((string) ($metrics['symbol'] ?? $payload['symbol'])),
            'timeframe' => $metrics['timeframe'] ?? $payload['timeframe'],
            'period' => $metrics['period'] ?? "{$payload['from_date']} - {$payload['to_date']}",
            'trades' => (int) ($metrics['total_trades'] ?? 0),
            'winrate' => (float) ($metrics['winrate'] ?? 0),
            'profit_factor' => (float) ($metrics['profit_factor'] ?? 0),
            'max_drawdown' => (float) ($metrics['max_drawdown'] ?? $metrics['max_drawdown_percent'] ?? 0),
            'net_profit' => (float) ($metrics['net_profit_percent'] ?? 0),
            'conclusion' => $metrics['conclusion'] ?? null,
        ]);
    }

    private function displaySymbol(string $symbol): string
    {
        $code = $this->normalizeSymbolCode($symbol);

        return strlen($code) === 6 ? substr($code, 0, 3).'/'.substr($code, 3) : $symbol;
    }

    private function normalizeSymbolCode(string $symbol): string
    {
        return strtoupper(str_replace(['_', '/'], '', trim($symbol)));
    }
}
