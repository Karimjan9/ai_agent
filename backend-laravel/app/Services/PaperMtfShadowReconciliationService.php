<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\PaperMtfShadowObservation;
use App\Models\Symbol;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Support\Facades\Http;

/**
 * Settles non-promotional MTF shadows at the same next-candle and exit
 * contract as paper. It never writes PaperOrder/PaperSignalOutcome evidence.
 */
class PaperMtfShadowReconciliationService
{
    public function __construct(
        private CandlePayloadService $candles,
        private StrategyParameterSchemaService $schemas,
    ) {}

    /** @return array{checked:int,closed:int,pending:int,skipped:int,errors:int} */
    public function reconcile(?string $symbol = null, int $limit = 20): array
    {
        $stats = ['checked' => 0, 'closed' => 0, 'pending' => 0, 'skipped' => 0, 'errors' => 0];
        $query = PaperMtfShadowObservation::query()
            ->with('marketPerformance.modelVersion')
            ->whereNull('outcome')
            ->whereIn('decision', ['BUY', 'SELL'])
            ->oldest('candle_time')
            ->limit(max(1, $limit));
        if ($symbol) {
            $query->where('symbol', strtoupper(str_replace(['/', '_', '-'], '', $symbol)));
        }

        foreach ($query->get() as $observation) {
            $stats['checked']++;
            $candidate = $observation->marketPerformance;
            $model = $candidate?->modelVersion;
            $symbolId = Symbol::query()->where('code', $observation->symbol)->value('id');
            $entryCandle = $symbolId
                ? Candle::query()
                    ->where('symbol_id', $symbolId)
                    ->where('timeframe', $observation->timeframe)
                    ->where('time', '>', $observation->candle_time)
                    ->oldest('time')
                    ->first()
                : null;
            if (! $candidate || ! $model || ! $entryCandle) {
                $stats['skipped']++;
                continue;
            }

            $execution = app(ExecutionContractService::class)->for($observation->symbol, $observation->timeframe);
            $request = [
                'symbol' => $observation->symbol,
                'timeframe' => $observation->timeframe,
                'strategy' => $model->strategy,
                'base_strategy' => $this->schemas->runtimeBaseStrategy(
                    $model->strategy,
                    data_get($model->metadata, 'base_strategy'),
                    $candidate->strategy_family,
                ),
                'parameters' => $model->parameters ?? [],
                'candles' => $this->candles->candlesForBacktest($observation->symbol, $observation->timeframe, 1000),
                'regime_candles' => $observation->timeframe === 'M15'
                    ? $this->candles->candlesForBacktest($observation->symbol, 'H1', 2000)
                    : [],
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
                'execution' => $execution['parameters'],
                'execution_contract' => $execution,
                // Reconcile a shadow with its own declared M15-only lane;
                // this does not grant it official MTF or promotion authority.
                'mtf_pilot' => ['enabled' => false, 'mode' => 'm15_only'],
            ];

            try {
                $contractResponse = Http::timeout(120)->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->post(rtrim(config('services.ai_service.url'), '/').'/api/paper/execution-contract', [
                        'request' => $request,
                        'entry_market_price' => (float) $entryCandle->open,
                        'signal_time' => $observation->candle_time?->toIso8601String(),
                    ]);
                if ($contractResponse->failed()) {
                    $stats['errors']++;
                    continue;
                }
                $contract = (array) $contractResponse->json();
                if (($contract['decision'] ?? 'WAIT') !== $observation->decision) {
                    $stats['skipped']++;
                    continue;
                }

                $advanceResponse = Http::timeout(120)->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->post(rtrim(config('services.ai_service.url'), '/').'/api/paper/advance-contract', [
                        'request' => $request,
                        'contract' => $contract,
                        'entry_time' => $entryCandle->time?->toIso8601String(),
                    ]);
                if ($advanceResponse->failed()) {
                    $stats['errors']++;
                    continue;
                }
                $result = (array) $advanceResponse->json();
                if (! (bool) ($result['closed'] ?? false)) {
                    $stats['pending']++;
                    continue;
                }
                $observation->update([
                    'outcome' => (float) ($result['profit_percent'] ?? 0) > 0
                        ? 'win'
                        : ((float) ($result['profit_percent'] ?? 0) < 0 ? 'loss' : 'flat'),
                    'profit_percent' => (float) ($result['profit_percent'] ?? 0),
                    'exit_reason' => $result['exit_reason'] ?? null,
                    'outcome_payload' => [
                        'protocol' => 'mtf_shadow_twin_v1',
                        'promotion_evidence' => false,
                        'entry_candle_time' => $entryCandle->time?->toIso8601String(),
                        'contract' => $contract,
                        'result' => $result,
                    ],
                ]);
                $stats['closed']++;
            } catch (\Throwable) {
                $stats['errors']++;
            }
        }

        return $stats;
    }
}
