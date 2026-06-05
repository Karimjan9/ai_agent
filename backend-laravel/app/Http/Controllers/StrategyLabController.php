<?php

namespace App\Http\Controllers;

use App\Models\ModelVersion;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class StrategyLabController extends Controller
{
    public function index(): View
    {
        $scores = StrategyScore::query()
            ->orderByDesc('score')
            ->orderByDesc('net_profit_percent')
            ->paginate(20);

        return view('strategy-lab.index', compact('scores'));
    }

    public function runAll(Request $request): RedirectResponse
    {
        $payload = [
            'symbol' => $request->input('symbol', 'XAUUSD'),
            'timeframe' => $request->input('timeframe', 'H1'),
            'strategy' => 'all',
            'initial_balance' => (float) $request->input('initial_balance', 10000),
            'risk_per_trade' => (float) $request->input('risk_per_trade', 1),
        ];

        try {
            $response = Http::timeout(300)
                ->acceptJson()
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $payload);
        } catch (ConnectionException) {
            return back()->with('error', "Python AI service bilan bog'lanib bo'lmadi.");
        }

        if ($response->failed()) {
            return back()->with('error', 'AI service xatolik berdi.');
        }

        $data = $response->json();
        $leaderboard = $data['leaderboard'] ?? [];

        if (empty($leaderboard)) {
            return back()->with('error', "Leaderboard bo'sh qaytdi.");
        }

        DB::transaction(function () use ($data, $payload, $leaderboard): void {
            $best = $leaderboard[0];
            $worst = $leaderboard[count($leaderboard) - 1];

            $totalTrades = collect($leaderboard)->sum(fn (array $item): int => $item['result']['total_trades'] ?? 0);
            $avgWinrate = round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['winrate'] ?? 0), 2);
            $avgProfit = round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['net_profit_percent'] ?? 0), 2);

            $session = TrainingSession::create($this->onlyExistingColumns('training_sessions', [
                'title' => 'Training Session '.now()->format('Y-m-d H:i'),
                'symbol' => $data['symbol'] ?? $payload['symbol'],
                'timeframe' => $data['timeframe'] ?? $payload['timeframe'],
                'agents_count' => count($leaderboard),
                'best_strategy' => $best['strategy'] ?? null,
                'best_score' => $best['score'] ?? 0,
                'worst_strategy' => $worst['strategy'] ?? null,
                'worst_score' => $worst['score'] ?? 0,
                'total_trades' => $totalTrades,
                'average_winrate' => $avgWinrate,
                'average_profit' => $avgProfit,
                'ai_conclusion' => $this->makeSessionConclusion($best, $worst, $avgWinrate, $avgProfit),
                'next_training_plan' => $this->makeNextTrainingPlan($worst),
                'raw_leaderboard' => $leaderboard,
                'status' => 'completed',
                'metrics' => [
                    'agents_count' => count($leaderboard),
                    'best_strategy' => $best['strategy'] ?? null,
                    'best_score' => $best['score'] ?? 0,
                    'worst_strategy' => $worst['strategy'] ?? null,
                    'worst_score' => $worst['score'] ?? 0,
                    'total_trades' => $totalTrades,
                    'average_winrate' => $avgWinrate,
                    'average_profit' => $avgProfit,
                ],
            ]));

            foreach ($leaderboard as $item) {
                $result = $item['result'] ?? [];

                StrategyScore::create([
                    'training_session_id' => $session->id,
                    'symbol' => $data['symbol'] ?? $payload['symbol'],
                    'timeframe' => $data['timeframe'] ?? $payload['timeframe'],
                    'strategy' => $item['strategy'],
                    'score' => $item['score'],
                    'total_trades' => $result['total_trades'] ?? 0,
                    'wins' => $result['wins'] ?? 0,
                    'losses' => $result['losses'] ?? 0,
                    'winrate' => $result['winrate'] ?? 0,
                    'net_profit_percent' => $result['net_profit_percent'] ?? 0,
                    'max_drawdown_percent' => $result['max_drawdown'] ?? 0,
                    'profit_factor' => $result['profit_factor'] ?? 0,
                    'raw_result' => $result,
                ]);

                $this->updateModelVersionFromResult($item['strategy'], $item['score'], $result);
            }
        });

        return redirect()
            ->route('training-sessions.index')
            ->with('success', 'Yangi training session yaratildi.');
    }

    private function makeSessionConclusion(array $best, array $worst, float $avgWinrate, float $avgProfit): string
    {
        $bestStrategy = strtoupper($best['strategy'] ?? 'unknown');
        $worstStrategy = strtoupper($worst['strategy'] ?? 'unknown');
        $bestScore = $best['score'] ?? 0;
        $worstScore = $worst['score'] ?? 0;

        $text = "Bugungi training session natijasi: o'rtacha winrate {$avgWinrate}%, o'rtacha profit {$avgProfit}%. ";
        $text .= "Eng kuchli agent {$bestStrategy} bo'ldi, score: {$bestScore}. ";
        $text .= "Eng zaif agent {$worstStrategy} bo'ldi, score: {$worstScore}. ";

        if ($avgWinrate >= 55 && $avgProfit > 0) {
            $text .= 'Umumiy natija yaxshi, keyingi bosqichda risk va drawdown chuqurroq tekshiriladi.';
        } elseif ($avgWinrate < 45 || $avgProfit < 0) {
            $text .= 'Umumiy natija zaif, strategiya filterlari kuchaytirilishi kerak.';
        } else {
            $text .= "Natija o'rtacha, agentlar turli market holatlarida alohida test qilinishi kerak.";
        }

        return $text;
    }

    private function makeNextTrainingPlan(array $worst): string
    {
        return match ($worst['strategy'] ?? 'unknown') {
            'breakout_v1' => 'Keyingi treningda Breakout agent uchun ATR filter kuchaytiriladi va false breakout holatlari alohida tahlil qilinadi.',
            'fibonacci_v1' => "Keyingi treningda Fibonacci agent uchun trend direction va swing confirmation qo'shiladi.",
            'ema_rsi_v1' => 'Keyingi treningda EMA/RSI agent uchun RSI chegaralari va trend filter qayta sozlanadi.',
            'macd_trend_v1' => 'Keyingi treningda MACD agent uchun signal kechikishi va entry timing optimizatsiya qilinadi.',
            default => "Keyingi treningda eng zaif agent bo'yicha xato turlari chuqur tahlil qilinadi.",
        };
    }

    private function updateModelVersionFromResult(string $strategy, int $score, array $result): void
    {
        $version = $this->extractVersion($strategy);
        $modelVersion = ModelVersion::query()
            ->where('strategy', $strategy)
            ->where('version', $version)
            ->firstOrNew([
                'strategy' => $strategy,
                'version' => $version,
            ]);

        if (! $modelVersion->exists) {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'name' => "{$strategy}_{$version}",
                'strategy' => $strategy,
                'version' => $version,
                'generation' => (int) str_replace('v', '', $version),
                'status' => 'testing',
                'description' => strtoupper($strategy)." boshlang'ich versiyasi.",
                'parameters' => [],
                'metadata' => [],
            ]));
        }

        if (! $modelVersion->exists || $score > (float) $modelVersion->best_score) {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'best_score' => $score,
                'best_winrate' => $result['winrate'] ?? 0,
                'best_profit' => $result['net_profit_percent'] ?? 0,
                'best_drawdown' => $result['max_drawdown'] ?? 0,
                'change_log' => 'Yangi eng yaxshi natija training session vaqtida topildi.',
                'parameters' => $result,
                'metadata' => $result,
            ]));
        }

        if ($score >= 75 && $modelVersion->status !== 'active') {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'status' => 'active',
                'promoted_at' => now(),
                'change_log' => "Model score 75 dan oshgani uchun active statusga o'tkazildi.",
            ]));
        }

        if ($score < 30 && $modelVersion->status === 'testing') {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'status' => 'rejected',
                'change_log' => 'Model juda past score olgani uchun rejected qilindi.',
            ]));
        }

        $modelVersion->save();
    }

    private function extractVersion(string $strategy): string
    {
        if (preg_match('/_v(\d+)$/', $strategy, $matches)) {
            return 'v'.$matches[1];
        }

        return 'v1';
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
