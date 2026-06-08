<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Models\Mistake;
use App\Models\Trade;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class BacktestController extends Controller
{
    public function __construct(
        private CandlePayloadService $candlePayloadService,
    ) {}

    public function index(): View
    {
        return view('backtests.index');
    }

    public function run(Request $request): View|RedirectResponse
    {
        $payload = [
            'symbol' => $request->input('symbol', 'XAUUSD'),
            'timeframe' => $request->input('timeframe', 'H1'),
            'strategy' => $request->input('strategy', 'ema_rsi_v1'),
            'initial_balance' => (float) $request->input('initial_balance', 10000),
            'risk_per_trade' => (float) $request->input('risk_per_trade', 1),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
        ];
        $payload['candles'] = $this->candlePayloadService->candlesForBacktest($payload['symbol'], $payload['timeframe']);

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run', $payload);
        } catch (ConnectionException) {
            return back()->with('error', "Python AI service bilan bog'lanib bo'lmadi.");
        }

        if ($response->failed()) {
            return back()->with('error', 'Backtest service xatolik berdi.');
        }

        $result = $response->json();
        $backtestRun = DB::transaction(function () use ($result, $payload): BacktestRun {
            $runData = [
                'symbol' => $payload['symbol'],
                'timeframe' => $payload['timeframe'],
                'strategy' => $payload['strategy'],
                'date_from' => $payload['from_date'] ?? null,
                'date_to' => $payload['to_date'] ?? null,
                'initial_balance' => $result['initial_balance'] ?? 10000,
                'final_balance' => $result['final_balance'] ?? 0,
                'total_trades' => $result['total_trades'] ?? 0,
                'wins' => $result['wins'] ?? 0,
                'losses' => $result['losses'] ?? 0,
                'winrate' => $result['winrate'] ?? 0,
                'net_profit_percent' => $result['net_profit_percent'] ?? 0,
                'max_drawdown_percent' => $result['max_drawdown'] ?? 0,
                'profit_factor' => $result['profit_factor'] ?? 0,
                'conclusion' => $result['conclusion'] ?? null,
                'raw_result' => $result,
            ];

            if (Schema::hasColumn('backtest_runs', 'symbol_id')) {
                $runData['symbol_id'] = $this->symbolId($payload['symbol']);
            }

            if (Schema::hasColumn('backtest_runs', 'from_date')) {
                $runData['from_date'] = $payload['from_date'] ?? now()->toDateString();
            }

            if (Schema::hasColumn('backtest_runs', 'to_date')) {
                $runData['to_date'] = $payload['to_date'] ?? now()->toDateString();
            }

            if (Schema::hasColumn('backtest_runs', 'status')) {
                $runData['status'] = 'completed';
            }

            if (Schema::hasColumn('backtest_runs', 'request_payload')) {
                $runData['request_payload'] = $payload;
            }

            if (Schema::hasColumn('backtest_runs', 'metrics')) {
                $runData['metrics'] = [
                    'total_trades' => $result['total_trades'] ?? 0,
                    'wins' => $result['wins'] ?? 0,
                    'losses' => $result['losses'] ?? 0,
                    'winrate' => $result['winrate'] ?? 0,
                    'net_profit_percent' => $result['net_profit_percent'] ?? 0,
                    'profit_factor' => $result['profit_factor'] ?? 0,
                    'max_drawdown' => $result['max_drawdown'] ?? 0,
                ];
            }

            if (Schema::hasColumn('backtest_runs', 'started_at')) {
                $runData['started_at'] = now();
            }

            if (Schema::hasColumn('backtest_runs', 'finished_at')) {
                $runData['finished_at'] = now();
            }

            $runData = $this->onlyExistingColumns('backtest_runs', $runData);
            $run = BacktestRun::create($runData);

            foreach (($result['trades'] ?? []) as $tradeData) {
                $trade = Trade::create($this->onlyExistingColumns('trades', [
                    'backtest_run_id' => $run->id,
                    'symbol' => $payload['symbol'],
                    'timeframe' => $payload['timeframe'],
                    'strategy' => $payload['strategy'],
                    'direction' => $tradeData['direction'],
                    'entry_time' => $tradeData['entry_time'],
                    'exit_time' => $tradeData['exit_time'] ?? null,
                    'entry_price' => $tradeData['entry_price'],
                    'exit_price' => $tradeData['exit_price'] ?? null,
                    'stop_loss' => $tradeData['stop_loss'] ?? null,
                    'take_profit' => $tradeData['take_profit'] ?? null,
                    'result' => $tradeData['result'],
                    'market_regime' => $tradeData['market_regime'] ?? null,
                    'volatility_regime' => $tradeData['volatility_regime'] ?? null,
                    'profit_percent' => $tradeData['profit_percent'] ?? 0,
                    'balance_after_trade' => $tradeData['balance'] ?? null,
                    'mistake_type' => $tradeData['mistake_type'] ?? null,
                    'reason' => $tradeData['reason'] ?? null,
                ]));

                if (($tradeData['result'] ?? null) === 'LOSS' && !empty($tradeData['mistake_type'])) {
                    $mistakeData = [
                        'backtest_run_id' => $run->id,
                        'trade_id' => $trade->id,
                        'mistake_type' => $tradeData['mistake_type'],
                        'reason' => $tradeData['reason'] ?? null,
                        'description' => $tradeData['reason'] ?? null,
                        'suggestion' => $tradeData['suggestion'] ?? null,
                        'context' => $tradeData,
                    ];

                    Mistake::create($this->onlyExistingColumns('mistakes', $mistakeData));
                }
            }

            return $run;
        });

        return view('backtests.result', [
            'result' => $result,
            'payload' => $payload,
            'backtestRun' => $backtestRun,
        ]);
    }

    private function symbolId(string $symbol): int
    {
        $code = str_replace('/', '', $symbol);
        $existing = DB::table('symbols')->where('code', $code)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('symbols')->insertGetId([
            'code' => $code,
            'display_name' => str_contains($symbol, '/') ? $symbol : 'XAU/USD',
            'asset_class' => 'metal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        return array_filter(
            $data,
            fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
