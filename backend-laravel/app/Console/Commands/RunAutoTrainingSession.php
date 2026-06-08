<?php

namespace App\Console\Commands;

use App\Models\ModelVersion;
use App\Models\StrategyScore;
use App\Models\TrainingLog;
use App\Models\TrainingSession;
use App\Services\AgentEvolutionService;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class RunAutoTrainingSession extends Command
{
    protected $signature = 'trading:auto-train
                            {--symbol=XAUUSD}
                            {--timeframe=H1}
                            {--balance=10000}
                            {--risk=1}';

    protected $description = 'Run automatic AI training session';

    public function handle(AgentEvolutionService $evolutionService, CandlePayloadService $candlePayloadService): int
    {
        $log = TrainingLog::create([
            'type' => 'auto_training',
            'status' => 'running',
            'message' => 'Auto training boshlandi.',
            'started_at' => now(),
            'context' => [
                'symbol' => $this->option('symbol'),
                'timeframe' => $this->option('timeframe'),
                'balance' => $this->option('balance'),
                'risk' => $this->option('risk'),
            ],
        ]);

        try {
            $payload = $this->trainingPayload($candlePayloadService);

            $response = Http::timeout(600)
                ->acceptJson()
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $payload);

            if ($response->failed()) {
                throw new RuntimeException('AI service xatolik berdi: '.$response->body());
            }

            $data = $response->json();
            $leaderboard = $data['leaderboard'] ?? [];

            if (empty($leaderboard)) {
                throw new RuntimeException("Leaderboard bo'sh qaytdi.");
            }

            $session = $this->storeTrainingSession($data, $payload, $leaderboard, $evolutionService);

            $log->update([
                'status' => 'success',
                'training_session_id' => $session->id,
                'message' => 'Auto training muvaffaqiyatli yakunlandi.',
                'finished_at' => now(),
                'context' => [
                    'training_session_id' => $session->id,
                    'best_strategy' => $session->best_strategy,
                    'best_score' => $session->best_score,
                    'worst_strategy' => $session->worst_strategy,
                    'worst_score' => $session->worst_score,
                ],
            ]);

            $this->info('Auto training session yaratildi: #'.$session->id);

            return self::SUCCESS;
        } catch (ConnectionException) {
            return $this->failLog($log, "Python AI service bilan bog'lanib bo'lmadi.");
        } catch (Throwable $e) {
            return $this->failLog($log, $e->getMessage());
        }
    }

    private function trainingPayload(CandlePayloadService $candlePayloadService): array
    {
        $strategies = $this->strategyRuntimePayloads();

        if (empty($strategies)) {
            throw new RuntimeException('Testing yoki active model version topilmadi.');
        }

        $symbol = (string) $this->option('symbol');
        $timeframe = (string) $this->option('timeframe');

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'strategy' => 'all',
            'strategies' => $strategies,
            'initial_balance' => (float) $this->option('balance'),
            'risk_per_trade' => (float) $this->option('risk'),
            'candles' => $candlePayloadService->candlesForBacktest($symbol, $timeframe),
        ];
    }

    private function storeTrainingSession(
        array $data,
        array $payload,
        array $leaderboard,
        AgentEvolutionService $evolutionService,
    ): TrainingSession {
        return DB::transaction(function () use ($data, $payload, $leaderboard, $evolutionService): TrainingSession {
            $best = $leaderboard[0];
            $worst = $leaderboard[count($leaderboard) - 1];

            $totalTrades = collect($leaderboard)->sum(fn (array $item): int => $item['result']['total_trades'] ?? 0);
            $avgWinrate = round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['winrate'] ?? 0), 2);
            $avgProfit = round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['net_profit_percent'] ?? 0), 2);
            $avgDrawdown = round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['max_drawdown_percent'] ?? $item['result']['max_drawdown'] ?? 0), 2);
            $avgProfitFactor = round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['profit_factor'] ?? 0), 2);
            $avgStabilityScore = (int) round(collect($leaderboard)->avg(fn (array $item): float|int => $item['result']['stability_score'] ?? 0));

            $session = TrainingSession::create($this->onlyExistingColumns('training_sessions', [
                'title' => 'Auto Training Session '.now()->format('Y-m-d H:i'),
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
                'average_drawdown' => $avgDrawdown,
                'average_profit_factor' => $avgProfitFactor,
                'average_stability_score' => $avgStabilityScore,
                'ai_conclusion' => $this->makeSessionConclusion(
                    $best,
                    $worst,
                    $avgWinrate,
                    $avgProfit,
                    $avgDrawdown,
                    $avgProfitFactor,
                    $avgStabilityScore,
                ),
                'next_training_plan' => $this->makeNextTrainingPlan($worst),
                'raw_leaderboard' => $leaderboard,
                'status' => 'completed',
                'metrics' => [
                    'workflow' => 'auto_training',
                    'agents_count' => count($leaderboard),
                    'best_strategy' => $best['strategy'] ?? null,
                    'best_score' => $best['score'] ?? 0,
                    'worst_strategy' => $worst['strategy'] ?? null,
                    'worst_score' => $worst['score'] ?? 0,
                    'total_trades' => $totalTrades,
                    'average_winrate' => $avgWinrate,
                    'average_profit' => $avgProfit,
                    'average_drawdown' => $avgDrawdown,
                    'average_profit_factor' => $avgProfitFactor,
                    'average_stability_score' => $avgStabilityScore,
                ],
            ]));

            foreach ($leaderboard as $item) {
                $result = $item['result'] ?? [];

                StrategyScore::create($this->onlyExistingColumns('strategy_scores', [
                    'training_session_id' => $session->id,
                    'symbol' => $data['symbol'] ?? $payload['symbol'],
                    'timeframe' => $data['timeframe'] ?? $payload['timeframe'],
                    'strategy' => $item['strategy'],
                    'parameters' => $item['parameters'] ?? $result['parameters'] ?? [],
                    'score' => $item['score'],
                    'total_trades' => $result['total_trades'] ?? 0,
                    'wins' => $result['wins'] ?? 0,
                    'losses' => $result['losses'] ?? 0,
                    'winrate' => $result['winrate'] ?? 0,
                    'net_profit_percent' => $result['net_profit_percent'] ?? 0,
                    'max_drawdown_percent' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
                    'profit_factor' => $result['profit_factor'] ?? 0,
                    'average_win_percent' => $result['average_win_percent'] ?? 0,
                    'average_loss_percent' => $result['average_loss_percent'] ?? 0,
                    'risk_reward_ratio' => $result['risk_reward_ratio'] ?? 0,
                    'max_consecutive_losses' => $result['max_consecutive_losses'] ?? 0,
                    'stability_score' => $result['stability_score'] ?? 0,
                    'equity_curve' => $result['equity_curve'] ?? [],
                    'regime_performance' => $result['regime_performance'] ?? [],
                    'volatility_performance' => $result['volatility_performance'] ?? [],
                    'raw_result' => $result,
                ]));

                $this->updateModelVersionFromResult($item['strategy'], $item['score'], $result);
            }

            $evolutionService->createProposalFromSession($session);

            return $session;
        });
    }

    private function makeSessionConclusion(
        array $best,
        array $worst,
        float $avgWinrate,
        float $avgProfit,
        float $avgDrawdown = 0,
        float $avgProfitFactor = 0,
        int $avgStabilityScore = 0,
    ): string {
        $bestStrategy = strtoupper($best['strategy'] ?? 'unknown');
        $worstStrategy = strtoupper($worst['strategy'] ?? 'unknown');
        $bestScore = $best['score'] ?? 0;
        $worstScore = $worst['score'] ?? 0;

        $text = 'Avtomatik training session natijasi: ';
        $text .= "o'rtacha winrate {$avgWinrate}%, ";
        $text .= "o'rtacha profit {$avgProfit}%, ";
        $text .= "o'rtacha drawdown {$avgDrawdown}%, ";
        $text .= "o'rtacha profit factor {$avgProfitFactor}, ";
        $text .= "o'rtacha stability score {$avgStabilityScore}. ";
        $text .= "Eng kuchli agent {$bestStrategy}, score: {$bestScore}. ";
        $text .= "Eng zaif agent {$worstStrategy}, score: {$worstScore}. ";

        if ($avgProfit > 0 && $avgProfitFactor >= 1.3 && $avgDrawdown <= 10) {
            $text .= "Umumiy natija risk/profit bo'yicha yaxshi.";
        } elseif ($avgProfit > 0 && $avgDrawdown > 15) {
            $text .= 'Profit bor, lekin drawdown yuqori. Riskni kamaytirish kerak.';
        } elseif ($avgProfit < 0 || $avgProfitFactor < 1) {
            $text .= 'Umumiy natija zaif, strategiyalar zararli yoki risk/reward past.';
        } else {
            $text .= "Natija o'rtacha, agentlar market regime bo'yicha alohida test qilinishi kerak.";
        }

        return $text;
    }

    private function makeNextTrainingPlan(array $worst): string
    {
        $strategy = $worst['strategy'] ?? 'unknown';

        return match (true) {
            str_contains($strategy, 'breakout') => 'Keyingi treningda Breakout agent uchun ATR filter va false breakout himoyasi kuchaytiriladi.',
            str_contains($strategy, 'fibonacci') => "Keyingi treningda Fibonacci agent uchun trend confirmation va swing logic yaxshilanadi.",
            str_contains($strategy, 'ema_rsi') => 'Keyingi treningda EMA/RSI agent uchun RSI chegaralari va volatility filter tekshiriladi.',
            str_contains($strategy, 'macd_trend') => 'Keyingi treningda MACD agent uchun entry timing va confirmation candle tekshiriladi.',
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
                'name' => $strategy,
                'strategy' => $strategy,
                'version' => $version,
                'generation' => (int) str_replace('v', '', $version),
                'status' => 'testing',
                'description' => strtoupper($strategy).' auto training dynamic version.',
                'parameters' => $result['parameters'] ?? [],
                'metadata' => [],
            ]));
        }

        if (! $modelVersion->exists || $score > (float) $modelVersion->best_score) {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'best_score' => $score,
                'best_winrate' => $result['winrate'] ?? 0,
                'best_profit' => $result['net_profit_percent'] ?? 0,
                'best_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
                'change_log' => 'Yangi eng yaxshi risk-adjusted natija auto training vaqtida topildi.',
                'metadata' => $result,
            ]));
        }

        if (
            $score >= 75
            && ($result['profit_factor'] ?? 0) >= 1.3
            && ($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 100) <= 15
            && $modelVersion->status !== 'active'
        ) {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'status' => 'active',
                'promoted_at' => now(),
                'change_log' => "Model auto training orqali active statusga o'tkazildi.",
            ]));
        }

        if (($score < 30 || ($result['profit_factor'] ?? 0) < 0.8) && $modelVersion->status === 'testing') {
            $modelVersion->fill($this->onlyExistingColumns('model_versions', [
                'status' => 'rejected',
                'change_log' => 'Model auto training risk-adjusted baholashda past natija olgani uchun rejected qilindi.',
            ]));
        }

        $modelVersion->save();
    }

    private function strategyRuntimePayloads(): array
    {
        if (! Schema::hasTable('model_versions') || ! Schema::hasColumn('model_versions', 'parameters')) {
            return [];
        }

        return ModelVersion::query()
            ->whereIn('status', ['testing', 'active'])
            ->orderBy('strategy')
            ->orderBy('generation')
            ->get()
            ->map(fn (ModelVersion $version): array => [
                'strategy' => $version->strategy ?? $version->name,
                'base_strategy' => $this->baseStrategyName($version->strategy ?? $version->name),
                'version' => $version->version ?? $this->extractVersion($version->strategy ?? $version->name),
                'parameters' => $version->parameters ?? [],
            ])
            ->values()
            ->all();
    }

    private function extractVersion(string $strategy): string
    {
        if (preg_match('/_v(\d+)$/', $strategy, $matches)) {
            return 'v'.$matches[1];
        }

        return 'v1';
    }

    private function baseStrategyName(string $strategy): string
    {
        if (preg_match('/_v\d+$/', $strategy)) {
            return preg_replace('/_v\d+$/', '_v1', $strategy);
        }

        return $strategy;
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        return array_filter(
            $data,
            fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function failLog(TrainingLog $log, string $message): int
    {
        $log->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $this->error($message);

        return self::FAILURE;
    }
}
