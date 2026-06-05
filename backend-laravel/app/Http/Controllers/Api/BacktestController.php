<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BacktestController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'in:XAU_USD,XAU/USD'],
            'timeframe' => ['required', 'string', 'in:M15,H1'],
            'strategy' => ['required', 'string', 'in:ema_rsi_v1,macd_trend_v1,fibonacci_v1,breakout_v1'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'initial_balance' => ['nullable', 'numeric', 'gt:0'],
            'risk_per_trade' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $payload = [
            'symbol' => 'XAUUSD',
            'timeframe' => $validated['timeframe'],
            'strategy' => $validated['strategy'],
            'from_date' => $validated['from'],
            'to_date' => $validated['to'],
            'initial_balance' => (float) ($validated['initial_balance'] ?? 10000),
            'risk_per_trade' => (float) ($validated['risk_per_trade'] ?? 1),
            'dataset_path' => config('services.ai_service.default_dataset'),
        ];

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run', $payload);
        } catch (ConnectionException) {
            return response()->json([
                'message' => "Python AI service bilan bog'lanib bo'lmadi.",
                'service_url' => config('services.ai_service.url'),
            ], 503);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => 'Python AI service backtestni bajara olmadi.',
                'details' => $response->json(),
            ], $response->status());
        }

        $body = $response->json();
        $metrics = $body['metrics'] ?? [];
        $totalTrades = $body['total_trades'] ?? $metrics['total_trades'] ?? 0;
        $winrate = $body['winrate'] ?? $metrics['win_rate'] ?? 0.0;
        $profitFactor = $body['profit_factor'] ?? $metrics['profit_factor'] ?? 0.0;
        $maxDrawdown = $body['max_drawdown'] ?? $metrics['max_drawdown'] ?? 0.0;
        $netProfit = $body['net_profit_percent'] ?? $metrics['net_pnl'] ?? 0.0;

        return response()->json([
            'strategy' => $body['strategy'] ?? 'EMA_RSI_V1',
            'instrument' => $body['instrument'] ?? $this->normalizeSymbol($validated['symbol']),
            'timeframe' => $body['timeframe'] ?? $validated['timeframe'],
            'period' => $body['period'] ?? "{$validated['from']} - {$validated['to']}",
            'trades' => $totalTrades,
            'winrate' => $winrate,
            'profit_factor' => $profitFactor,
            'max_drawdown' => $maxDrawdown,
            'net_profit' => $netProfit,
            'conclusion' => $this->conclusion($body, $validated),
        ]);
    }

    private function normalizeSymbol(string $symbol): string
    {
        return str_replace('_', '/', $symbol);
    }

    private function conclusion(array $body, array $request): string
    {
        if (isset($body['conclusion'])) {
            return $body['conclusion'];
        }

        $metrics = $body['metrics'] ?? [];
        $trades = $metrics['total_trades'] ?? 0;
        $netPnl = $metrics['net_pnl'] ?? 0.0;
        $timeframe = $request['timeframe'];

        if ($trades === 0) {
            return "EMA trend + RSI strategy {$timeframe} timeframe'da yetarli signal topmadi. Kattaroq XAU/USD historical data yuklang.";
        }

        if ($netPnl > 0) {
            return "EMA trend + RSI strategy {$timeframe} timeframe'da yaxshi ishladi, lekin sideways marketdagi xatolar mistake journal orqali tekshirilishi kerak.";
        }

        if ($netPnl < 0) {
            return "EMA trend + RSI strategy {$timeframe} timeframe'da zarar bilan yakunlandi. Asosiy e'tibor sideways market, kech kirish va ATR stop-loss xatolariga qaratiladi.";
        }

        return "EMA trend + RSI strategy {$timeframe} timeframe'da neytral natija berdi. Xulosa uchun ko'proq candle va trade kerak.";
    }
}
