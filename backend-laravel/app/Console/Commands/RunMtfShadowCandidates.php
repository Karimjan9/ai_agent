<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Services\ExecutionContractService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MultiTimeframePilotService;
use App\Services\PaperMtfLedgerService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RunMtfShadowCandidates extends Command
{
    protected $signature = 'trading:mtf-shadow-candidates
        {--symbol=XAUUSD : Shadow symbol}
        {--limit=3 : Number of highest-ranked rejected M15 candidates}
        {--json : Print JSON}';

    protected $description = 'Observe the top rejected XAUUSD M15 near-miss candidates in the non-promotional MTF shadow twin';

    public function handle(
        CandlePayloadService $candles,
        MultiTimeframePilotService $pilot,
        PaperMtfLedgerService $ledger,
        StrategyParameterSchemaService $schemas,
    ): int {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) $this->option('symbol')));
        $candidates = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->where('status', 'rejected')
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->orderByDesc('forward_score')
            ->orderByDesc('fitness')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
        if ($candidates->isEmpty()) {
            $this->warn("{$symbol} M15 uchun valid rejected near-miss candidate topilmadi.");
            return self::SUCCESS;
        }

        $m15 = $candles->candlesForBacktest($symbol, 'M15', 1000);
        $h1 = $candles->candlesForBacktest($symbol, 'H1', 2000);
        if (count($m15) < 200 || count($h1) < 200) {
            $this->error('Shadow candidate kuzatuvi uchun mustaqil M15/H1 candle stream yetarli emas.');
            return self::FAILURE;
        }

        $rows = [];
        foreach ($candidates as $candidate) {
            $model = $candidate->modelVersion;
            if (! $model) {
                continue;
            }
            $execution = app(ExecutionContractService::class)->for($symbol, 'M15');
            $request = [
                'symbol' => $symbol,
                'timeframe' => 'M15',
                'strategy' => $model->strategy,
                'base_strategy' => $schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $candidate->strategy_family),
                'parameters' => $model->parameters ?? [],
                'candles' => $m15,
                'regime_candles' => $h1,
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
                'execution' => $execution['parameters'],
                'execution_contract' => $execution,
                'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', $model->strategy),
            ];
            $response = Http::timeout(120)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/paper/signal', $request);
            if ($response->failed()) {
                $rows[] = ['candidate_id' => $candidate->id, 'status' => 'technical_error', 'shadow_rows' => 0];
                continue;
            }
            $signal = (array) $response->json();
            $signal['shadow_candidate_id'] = $candidate->id;
            $shadowRows = $ledger->recordShadow($candidate, null, $signal);
            $rows[] = ['candidate_id' => $candidate->id, 'status' => 'observed', 'shadow_rows' => $shadowRows];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['protocol' => 'mtf_shadow_twin_v1', 'promotion_evidence' => false, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Candidate', 'Status', 'Shadow rows'], $rows);
            $this->comment('Near-miss shadows never enter official paper readiness or promotion gates.');
        }

        return self::SUCCESS;
    }
}
