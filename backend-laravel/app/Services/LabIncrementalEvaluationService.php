<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LabIncrementalEvaluationService
{
    public function __construct(
        private CandlePayloadService $candles,
        private StrategyParameterSchemaService $schemas,
        private RuntimeEnsemblePolicyService $runtimeEnsembles,
        private LabQueueJobInspector $queue,
    ) {}

    /**
     * Re-checks the current champion on recent candles only. This is health
     * evidence, not a promotion evaluation: promotion remains full rolling
     * walk-forward + paper trading + sealed holdout.
     *
     * @return array{checked:int,degraded:int,skipped:int,deferred?:bool,deferred_reason?:string}
     */
    public function evaluateChampions(): array
    {
        $summary = ['checked' => 0, 'degraded' => 0, 'skipped' => 0];

        $champions = ModelMarketPerformance::with('modelVersion')
            ->where('status', 'champion')
            ->orderBy('symbol');
        $championCount = (clone $champions)->count();

        // Full validation owns the serialized AI replay lane. A direct
        // hourly health check must yield while a full job is pending or
        // reserved; otherwise it can occupy the AI worker for several
        // minutes and starve the evidence-bearing job that is already in the
        // queue. Unknown queue state is also a defer: health evidence is
        // optional, while replay ordering is a safety invariant.
        $deferredReason = $this->incrementalAdmissionBlockReason();
        if ($championCount > 0 && $deferredReason !== null) {
            return [
                ...$summary,
                'skipped' => $championCount,
                'deferred' => true,
                'deferred_reason' => $deferredReason,
            ];
        }

        $champions->each(function (ModelMarketPerformance $performance) use (&$summary): void {
                $outcome = $this->evaluate($performance);
                $summary['checked'] += $outcome['checked'] ? 1 : 0;
                $summary['degraded'] += $outcome['degraded'] ? 1 : 0;
                $summary['skipped'] += $outcome['checked'] ? 0 : 1;
            });

        return $summary;
    }

    private function incrementalAdmissionBlockReason(): ?string
    {
        try {
            return $this->queue->fullValidationIsWaiting()
                ? 'full_validation_lane_waiting'
                : null;
        } catch (\Throwable $exception) {
            Log::warning('Incremental lab health check deferred because queue state is unknown.', [
                'exception' => $exception::class,
            ]);

            return 'queue_state_unknown_fail_closed';
        }
    }

    /** @return array{checked:bool,degraded:bool} */
    public function evaluate(ModelMarketPerformance $performance): array
    {
        $model = $performance->modelVersion;
        if (! $model) {
            return ['checked' => false, 'degraded' => false];
        }

        // Incremental health checks intentionally use only the recent sample.
        // Full selection always receives the complete exported history.
        $rows = $this->candles->candlesForBacktest($performance->symbol, $performance->timeframe, 2500);
        if (count($rows) < 200) {
            return ['checked' => false, 'degraded' => false];
        }

        $runtime = $this->runtimeEnsembles->requestPayload($performance);
        $portfolioMembers = (array) data_get($runtime, 'portfolio_members', []);
        $isPortfolio = count($portfolioMembers) >= 2;
        $response = Http::timeout(300)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(
            rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all',
            [
                'symbol' => $performance->symbol,
                'timeframe' => $performance->timeframe,
                'strategy' => $isPortfolio ? 'portfolio_v1' : $model->strategy,
                'base_strategy' => $isPortfolio ? 'portfolio' : $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $performance->strategy_family),
                'evaluation_mode' => 'incremental',
                'candles' => $rows,
                'regime_candles' => strtoupper((string) $performance->timeframe) === 'M15'
                    ? $this->candles->candlesForBacktest($performance->symbol, 'H1', 2000)
                    : [],
                'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                    $performance->symbol,
                    $performance->timeframe,
                    $model->strategy,
                ),
                'parameters' => $isPortfolio ? (array) data_get($runtime, 'parameters', []) : ($model->parameters ?? []),
                'portfolio_members' => $portfolioMembers,
                'runtime_ensemble_policy' => (array) data_get($runtime, 'runtime_ensemble_policy', []),
                'strategies' => $isPortfolio ? [] : [[
                    'strategy' => $model->strategy,
                    'base_strategy' => $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $performance->strategy_family),
                    'version' => $model->version,
                    'parameters' => $model->parameters ?? [],
                ]],
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException("Incremental evaluation failed for {$performance->symbol}: {$response->body()}");
        }

        $item = data_get($response->json(), 'leaderboard.0');
        if (! $item) {
            throw new RuntimeException("Incremental evaluation returned no result for {$performance->symbol}.");
        }

        $result = $item['result'] ?? [];
        $score = (float) ($item['score'] ?? 0);
        $degraded = $score < ((float) $performance->forward_score - 5)
            || (float) ($result['profit_factor'] ?? 0) < 1.0
            || (float) ($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 100) > 15;

        $metrics = $performance->metrics ?? [];
        $metrics['incremental'] = [
            'checked_at' => now()->toIso8601String(),
            'score' => $score,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'max_drawdown_percent' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
            'total_trades' => $result['total_trades'] ?? 0,
            'degraded' => $degraded,
        ];

        $performance->update([
            'metrics' => $metrics,
            'consecutive_no_improvement' => $degraded ? $performance->consecutive_no_improvement + 1 : 0,
        ]);

        return ['checked' => true, 'degraded' => $degraded];
    }
}
