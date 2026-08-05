<?php

namespace App\Console\Commands;

use App\Models\ModelVersion;
use App\Models\StrategyScore;
use App\Models\TrainingLog;
use App\Models\TrainingSession;
use App\Services\AgentEvolutionService;
use App\Services\AgentMindService;
use App\Services\EvolutionGenomeService;
use App\Services\FutureSimulationService;
use App\Services\MarketRealityService;
use App\Services\MarketChampionService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\OverfitDetectorService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TradingScientistService;
use App\Services\UniversalKnowledgeGraphService;
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
                            {--risk=1}
                            {--evaluation=full}
                            {--include-lab-agents : Include laboratory agent models in this full training run}
                            {--max-strategies=1 : Maximum models to evaluate per run}';

    protected $description = 'Run automatic AI training session';

    public function handle(
        AgentEvolutionService $evolutionService,
        CandlePayloadService $candlePayloadService,
        OverfitDetectorService $overfitDetector,
        TradingScientistService $tradingScientist,
        AgentMindService $agentMind,
        EvolutionGenomeService $evolutionGenome,
        MarketRealityService $marketReality,
        UniversalKnowledgeGraphService $knowledgeGraph,
        FutureSimulationService $futureSimulation,
        MarketChampionService $marketChampion,
        StrategyParameterSchemaService $schemas,
    ): int
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
            $payload = $this->trainingPayload($candlePayloadService, $schemas);

            $response = Http::timeout(600)
                ->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $payload);

            if ($response->failed()) {
                throw new RuntimeException('AI service xatolik berdi: '.$response->body());
            }

            $data = $response->json();
            $leaderboard = $data['leaderboard'] ?? [];

            if (empty($leaderboard)) {
                throw new RuntimeException("Leaderboard bo'sh qaytdi.");
            }

            $session = $this->storeTrainingSession(
                $data,
                $payload,
                $leaderboard,
                $evolutionService,
                $overfitDetector,
                $tradingScientist,
                $agentMind,
                $evolutionGenome,
                $marketReality,
                $knowledgeGraph,
                $futureSimulation,
                $marketChampion,
            );

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

    private function trainingPayload(CandlePayloadService $candlePayloadService, StrategyParameterSchemaService $schemas): array
    {
        $symbol = (string) $this->option('symbol');
        $timeframe = (string) $this->option('timeframe');
        $strategies = $this->strategyRuntimePayloads($symbol, $timeframe, $schemas);

        if (empty($strategies)) {
            throw new RuntimeException('Bu market uchun testing yoki champion model version topilmadi.');
        }

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'strategy' => 'all',
            'strategies' => $strategies,
            'initial_balance' => (float) $this->option('balance'),
            'risk_per_trade' => (float) $this->option('risk'),
            'evaluation_mode' => (string) $this->option('evaluation'),
            'execution' => $this->executionAssumptions($symbol),
            'candles' => $candlePayloadService->candlesForBacktest($symbol, $timeframe),
        ];
    }

    private function storeTrainingSession(
        array $data,
        array $payload,
        array $leaderboard,
        AgentEvolutionService $evolutionService,
        OverfitDetectorService $overfitDetector,
        TradingScientistService $tradingScientist,
        AgentMindService $agentMind,
        EvolutionGenomeService $evolutionGenome,
        MarketRealityService $marketReality,
        UniversalKnowledgeGraphService $knowledgeGraph,
        FutureSimulationService $futureSimulation,
        MarketChampionService $marketChampion,
    ): TrainingSession {
        return DB::transaction(function () use ($data, $payload, $leaderboard, $evolutionService, $overfitDetector, $tradingScientist, $agentMind, $evolutionGenome, $marketReality, $knowledgeGraph, $futureSimulation, $marketChampion): TrainingSession {
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
                $trainScore = $item['train_score'] ?? $result['train_score'] ?? null;
                $validationScore = $item['validation_score'] ?? $result['validation_score'] ?? null;
                $forwardScore = $item['forward_score'] ?? $result['forward_score'] ?? null;
                $robustnessScore = $item['robustness_score'] ?? $result['robustness_score'] ?? null;
                $isOverfit = (bool) ($item['is_overfit']
                    ?? $result['is_overfit']
                    ?? $overfitDetector->isOverfit($trainScore, $forwardScore));
                $monteCarlo = data_get($item, 'result.monte_carlo', []);

                $strategyScore = StrategyScore::create($this->onlyExistingColumns('strategy_scores', [
                    'training_session_id' => $session->id,
                    'symbol' => $data['symbol'] ?? $payload['symbol'],
                    'timeframe' => $data['timeframe'] ?? $payload['timeframe'],
                    'strategy' => $item['strategy'],
                    'parameters' => $item['parameters'] ?? $result['parameters'] ?? [],
                    'score' => $item['score'],
                    'train_score' => $trainScore,
                    'validation_score' => $validationScore,
                    'forward_score' => $forwardScore,
                    'robustness_score' => $robustnessScore,
                    'is_overfit' => $isOverfit,
                    'mc_worst_profit_percent' => data_get($monteCarlo, 'worst_profit_percent'),
                    'mc_avg_profit_percent' => data_get($monteCarlo, 'avg_profit_percent'),
                    'mc_best_profit_percent' => data_get($monteCarlo, 'best_profit_percent'),
                    'mc_worst_drawdown_percent' => data_get($monteCarlo, 'worst_drawdown_percent'),
                    'mc_avg_drawdown_percent' => data_get($monteCarlo, 'avg_drawdown_percent'),
                    'mc_risk_of_ruin_percent' => data_get($monteCarlo, 'risk_of_ruin_percent'),
                    'mc_worst_equity_curve' => data_get($monteCarlo, 'worst_equity_curve', []),
                    'mc_best_equity_curve' => data_get($monteCarlo, 'best_equity_curve', []),
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

                if (Schema::hasTable('strategy_dna_profiles')) {
                    $strategyDna = data_get($item, 'result.strategy_dna', []);
                    if (! empty($strategyDna)) {
                        $strategyScore->dnaProfile()->create($this->strategyDnaAttributes($strategyDna));
                    }
                }

                $result['train_score'] = $trainScore;
                $result['validation_score'] = $validationScore;
                $result['forward_score'] = $forwardScore;
                $result['forward_window_scores'] = $item['forward_window_scores'] ?? $result['forward_window_scores'] ?? [];
                $result['rolling_windows_count'] = $item['rolling_windows_count'] ?? $result['rolling_windows_count'] ?? 0;
                $result['robustness_score'] = $robustnessScore;
                $result['is_overfit'] = $isOverfit;

                $this->updateModelVersionFromResult($item['strategy'], $item['score'], $result);
                if (($payload['evaluation_mode'] ?? 'full') === 'full') {
                    $marketChampion->evaluate(
                        $item['strategy'],
                        $data['symbol'] ?? $payload['symbol'],
                        $data['timeframe'] ?? $payload['timeframe'],
                        (int) $item['score'],
                        $result,
                    );
                }
            }

            if (config('services.secondary_intelligence.enabled', false) || app()->environment('testing')) {
                $tradingScientist->recordTrainingSession($session);
                $agentMind->recordTrainingSession($session);
                $evolutionGenome->recordTrainingSession($session);
                $marketReality->recordStrategyPerformance($session);
                $knowledgeGraph->recordTrainingSession($session);
                $futureSimulation->simulate($session->symbol, $session->timeframe);
            }
            if (($payload['evaluation_mode'] ?? 'full') === 'full') {
                $evolutionService->createProposalFromSession($session);
            }

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

        $modelVersion->save();
    }

    private function strategyRuntimePayloads(string $symbol, string $timeframe, StrategyParameterSchemaService $schemas): array
    {
        if (! Schema::hasTable('model_versions') || ! Schema::hasColumn('model_versions', 'parameters')) {
            return [];
        }

        return ModelVersion::query()
            ->where('evidence_status', 'valid')
            ->whereIn('status', ['testing', 'active'])
            ->where(fn ($query) => $query->whereDoesntHave('labAgents')
                ->orWhereHas('labAgents', fn ($agent) => $agent->where('symbol', $symbol)->where('timeframe', $timeframe)))
            ->whereDoesntHave('marketPerformances', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->whereIn('status', ['archived', 'rejected', 'overfit', 'stagnated']))
            ->orderBy('strategy')
            ->orderBy('generation')
            ->get()
            // Laboratory agents are evaluated independently through
            // trading:dispatch-lab. Including every agent here turns one
            // daily training run into hundreds of full walk-forward runs and
            // can exceed the HTTP timeout before any evolution is recorded.
            ->unless(
                (bool) $this->option('include-lab-agents'),
                fn ($versions) => $versions->reject(fn (ModelVersion $version): bool => (bool) preg_match('/_g\d+_a\d+$/i', $version->strategy ?? $version->name ?? '')),
            )
            ->take(max(1, (int) $this->option('max-strategies')))
            ->map(fn (ModelVersion $version): array => [
                'strategy' => $version->strategy ?? $version->name,
                'base_strategy' => $schemas->runtimeBaseStrategy($version->strategy ?? $version->name, data_get($version->metadata, 'base_strategy')),
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

    private function executionAssumptions(string $symbol): array
    {
        $isMetal = str_starts_with(strtoupper(str_replace('/', '', $symbol)), 'XAU');

        return [
            'spread_points' => $isMetal ? 20 : 12,
            'point_size' => $isMetal ? 0.01 : 0.00001,
            'commission_percent' => 0.01,
            'slippage_points' => 2,
            'swap_per_day_percent' => 0.002,
            'allowed_sessions_utc' => ['1-22'],
            'intrabar_policy' => 'conservative',
            'max_gap_multiple' => 96,
            'reject_unexpected_gaps' => true,
            'stop_loss_percent' => 0.5,
            'take_profit_percent' => 1.0,
            'max_leverage' => 5,
        ];
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

    private function strategyDnaAttributes(array $strategyDna): array
    {
        return [
            'aggression_score' => data_get($strategyDna, 'aggression_score'),
            'trend_dependency' => data_get($strategyDna, 'trend_dependency'),
            'range_dependency' => data_get($strategyDna, 'range_dependency'),
            'volatility_sensitivity' => data_get($strategyDna, 'volatility_sensitivity'),
            'adaptability_score' => data_get($strategyDna, 'adaptability_score'),
            'recovery_score' => data_get($strategyDna, 'recovery_score'),
            'survival_score' => data_get($strategyDna, 'survival_score'),
            'dna_summary' => data_get($strategyDna, 'dna_summary'),
        ];
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
