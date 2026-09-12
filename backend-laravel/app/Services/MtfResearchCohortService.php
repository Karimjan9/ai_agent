<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\ModelMarketPerformance;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketVolumeService;
use Carbon\CarbonImmutable;

/**
 * Builds the one canonical immutable H1-context/M15-decision research cohort.
 *
 * H1 on ModelMarketPerformance is only the XAUUSD organism's laboratory
 * storage key.  It is not a separate trading organ.  The actual replay still
 * receives independent H1 context and M15 decision streams and executes under
 * the canonical M5-aware strategy contract.
 */
class MtfResearchCohortService
{
    public const PROTOCOL = 'xauusd_mtf_research_cohort_v2';

    public function __construct(
        private CandlePayloadService $candles,
        private MarketVolumeService $volumes,
        private ExecutionContractService $execution,
        private MultiTimeframePilotService $pilot,
        private MtfStrategyResearchService $research,
    ) {}

    public function candidate(string $symbol = 'XAUUSD', ?int $candidateId = null): ?ModelMarketPerformance
    {
        $symbol = $this->symbol($symbol);
        $storageTimeframe = $symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
            ? strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'))
            : 'M15';
        $query = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $symbol)
            ->where('timeframe', $storageTimeframe)
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($rows) => $rows->where('evidence_status', 'valid'))
            ->whereIn('status', ['forward_validated', 'paper', 'rejected'])
            ->latest('id');
        if ($candidateId !== null && $candidateId > 0) {
            $query->whereKey($candidateId);
        }

        return $query->first();
    }

    /** @return array<string,mixed> */
    public function current(string $symbol = 'XAUUSD', ?int $candidateId = null): array
    {
        $symbol = $this->symbol($symbol);
        $candidate = $this->candidate($symbol, $candidateId);
        if (! $candidate || ! $candidate->modelVersion) {
            return $this->unavailable($symbol, 'CANONICAL_ORGANISM_CANDIDATE_MISSING');
        }

        $m15 = $this->candles->candlesForTraining($symbol, 'M15', limit: 5000, includeVolume: true);
        $h1 = $this->candles->candlesForTraining($symbol, 'H1', limit: 2000, includeVolume: true);
        if (count($m15) < 200 || count($h1) < 200) {
            return $this->unavailable($symbol, 'INDEPENDENT_MTF_TRAINING_STREAMS_INSUFFICIENT', [
                'candidate_id' => $candidate->id,
                'h1_count' => count($h1),
                'm15_count' => count($m15),
            ]);
        }

        $volume = $this->volumes->mtfContext($symbol);
        $execution = $this->execution->for($symbol, 'M15');
        // Hash the complete ordered replay payload.  Count/first/last cannot
        // detect an OHLCV repair in the middle of a dataset.  Live volume
        // freshness is deliberately not part of this historical identity;
        // its actual per-candle values already are.
        $dataHash = $this->pilot->hash([
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            'training_end_exclusive' => (string) config('services.lab_selection.training_end_exclusive', '2026-01-01 00:00:00'),
            'h1_payload_hash' => $this->pilot->hash($h1),
            'm15_payload_hash' => $this->pilot->hash($m15),
        ]);

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'ready',
            'reason_code' => null,
            'symbol' => $symbol,
            'candidate' => $candidate,
            'candidate_id' => (int) $candidate->id,
            'candidate_model_version_id' => (int) $candidate->model_version_id,
            'laboratory_storage_timeframe' => (string) $candidate->timeframe,
            'temporal_roles' => ['context' => 'H1', 'decision' => 'M15', 'execution' => 'M5'],
            'h1_candles' => $h1,
            'm15_candles' => $m15,
            'volume_context' => $volume,
            'volume_research_freshness' => $this->research->volumeResearchFreshness($volume),
            'execution' => $execution,
            'data_hash' => $dataHash,
            'execution_hash' => (string) data_get($execution, 'execution_hash', ''),
            'live_feed' => $this->liveFeedReadiness($symbol),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function liveFeedReadiness(string $symbol): array
    {
        $now = CarbonImmutable::now('UTC');
        $limits = [
            'H1' => max(3600, (int) config('services.mtf_pilot.max_h1_staleness_seconds', 7200)),
            'M15' => max(900, (int) config('services.mtf_pilot.monitor_max_m15_staleness_seconds', 1800)),
        ];
        $streams = [];
        foreach ($limits as $timeframe => $maximumAge) {
            $minutes = $timeframe === 'H1' ? 60 : 15;
            $row = Candle::query()
                ->whereHas('symbol', fn ($query) => $query->where('code', $symbol))
                ->where('timeframe', $timeframe)
                ->where('time', '<=', $now->subMinutes($minutes))
                ->latest('time')
                ->first();
            $closedAt = $row?->time
                ? CarbonImmutable::parse($row->time, 'UTC')->addMinutes($minutes)
                : null;
            $age = $closedAt ? max(0, $closedAt->diffInSeconds($now, false)) : null;
            $streams[$timeframe] = [
                'closed_at' => $closedAt?->toIso8601String(),
                'age_seconds' => $age,
                'maximum_age_seconds' => $maximumAge,
                'ready' => $age !== null && $age <= $maximumAge,
            ];
        }

        return [
            'ready' => collect($streams)->every(fn (array $stream): bool => $stream['ready']),
            'streams' => $streams,
            // Feed health blocks the autonomous dispatcher, but is never
            // mixed into the immutable pre-2026 evidence identity.
            'authority' => 'operational_admission_only',
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $symbol, string $reason, array $extra = []): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'unavailable',
            'reason_code' => $reason,
            'symbol' => $symbol,
            ...$extra,
            'promotion_evidence' => false,
        ];
    }

    private function symbol(string $symbol): string
    {
        return strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
    }
}
