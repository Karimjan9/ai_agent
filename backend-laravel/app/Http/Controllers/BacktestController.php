<?php

namespace App\Http\Controllers;

use App\Models\LabEvaluationRun;
use App\Services\CanonicalManualBacktestService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BacktestController extends Controller
{
    public function index(): View
    {
        return view('backtests.index');
    }

    public function run(Request $request, CanonicalManualBacktestService $manualBacktests): View|RedirectResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'regex:/^(XAU|EUR|GBP)(?:[_\/]?USD)$/i'],
            'timeframe' => ['required', 'string', 'in:M15,H1'],
            'strategy' => ['required', 'string', 'regex:/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/i', 'max:96'],
            'initial_balance' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'risk_per_trade' => ['nullable', 'numeric', 'gt:0', 'max:5'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $payload = [
            'symbol' => $this->normalizeSymbolCode($validated['symbol']),
            'timeframe' => strtoupper((string) $validated['timeframe']),
            'strategy' => strtolower((string) $validated['strategy']),
            'initial_balance' => (float) ($validated['initial_balance'] ?? 10000),
            'risk_per_trade' => (float) ($validated['risk_per_trade'] ?? 1),
            'from_date' => $validated['from_date'] ?? null,
            'to_date' => $validated['to_date'] ?? null,
        ];
        $requestHash = $manualBacktests->requestHash($payload);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        $idempotencyKeyHash = $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : null;

        if ($idempotencyKeyHash !== null) {
            $existing = $manualBacktests->findByIdempotencyKey($idempotencyKeyHash);
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    return back()->with('error', 'Idempotency-Key boshqa backtest payload bilan allaqachon ishlatilgan.');
                }

                return $this->respondForRun($existing->fresh(), $payload, $manualBacktests);
            }
        }

        $lockKey = 'web-canonical-backtest:'.($idempotencyKey !== ''
            ? hash('sha256', $idempotencyKey)
            : $requestHash);
        $lock = Cache::lock($lockKey, 1800);

        if (! $lock->get()) {
            return back()->with('error', 'Ayni canonical backtest allaqachon queue\'da bajarilmoqda.');
        }

        try {
            $run = $manualBacktests->submit($payload, $requestHash, [
                'idempotency_key_hash' => $idempotencyKeyHash,
            ]);

            return $this->respondForRun($run, $payload, $manualBacktests);
        } finally {
            $lock->release();
        }
    }

    public function status(LabEvaluationRun $backtestRun): JsonResponse
    {
        $backtestRun->refresh();
        $terminalError = in_array($backtestRun->status, ['technical_error', 'skipped'], true);

        return response()->json([
            'id' => $backtestRun->id,
            'run_id' => $backtestRun->run_id,
            'source' => 'lab_evaluation_runs',
            'status' => $backtestRun->status,
            'metrics' => $backtestRun->metrics,
            'result_url' => $backtestRun->status === 'completed'
                ? route('backtests.result', ['backtestRun' => $backtestRun->id])
                : null,
            'error' => $terminalError ? $backtestRun->error_message : null,
        ]);
    }

    public function result(LabEvaluationRun $backtestRun, CanonicalManualBacktestService $manualBacktests): View|RedirectResponse
    {
        $backtestRun->refresh();

        if ($backtestRun->status !== 'completed') {
            return redirect()->route('backtests.status-page', ['backtestRun' => $backtestRun->id]);
        }

        return view('backtests.result', [
            'result' => $manualBacktests->responseFor($backtestRun),
            'payload' => $manualBacktests->payloadFor($backtestRun),
            'backtestRun' => $backtestRun,
        ]);
    }

    private function respondForRun(
        LabEvaluationRun $run,
        array $payload,
        CanonicalManualBacktestService $manualBacktests,
    ): View|RedirectResponse {
        $run->refresh();
        if ($run->status === 'completed') {
            return view('backtests.result', [
                'result' => $manualBacktests->responseFor($run),
                'payload' => $manualBacktests->payloadFor($run) ?: $payload,
                'backtestRun' => $run,
            ]);
        }

        if (in_array($run->status, ['technical_error', 'skipped'], true)) {
            return redirect()
                ->route('backtests.status-page', ['backtestRun' => $run->id])
                ->with('error', $run->error_message ?: 'Canonical backtest technical error.');
        }

        return redirect()
            ->route('backtests.status-page', ['backtestRun' => $run->id])
            ->with('success', 'Canonical Lab backtest queue\'ga yuborildi. Natija evidence polling orqali ko\'rinadi.');
    }

    private function normalizeSymbolCode(string $symbol): string
    {
        return strtoupper(str_replace(['_', '/'], '', trim($symbol)));
    }
}
