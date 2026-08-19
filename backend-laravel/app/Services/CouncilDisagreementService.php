<?php

namespace App\Services;

use App\Models\LabCouncilDisagreement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Converts council/router disagreement traces into research memory. */
class CouncilDisagreementService
{
    public const PROTOCOL = 'council_disagreement_memory_v1';

    public function available(): bool
    {
        return Schema::hasTable('lab_council_disagreements');
    }

    /** @return array{recorded:int, skipped:int} */
    public function recordResult(array $result, array $context = []): array
    {
        if (! $this->available()) return ['recorded' => 0, 'skipped' => 0];
        $trace = data_get($result, 'decision_trace', data_get($result, 'router_decision_trace', []));
        if (! is_array($trace) || $trace === []) {
            $trace = [$this->summaryEvent($result)];
        }

        $recorded = 0;
        $skipped = 0;
        foreach ($trace as $index => $event) {
            if (! is_array($event)) { $skipped++; continue; }
            $votes = $event['votes'] ?? $event['specialist_votes'] ?? $event['specialists'] ?? [];
            if (! is_array($votes)) $votes = [];
            if ($votes === []) {
                $features = (array) ($event['features'] ?? $event['feature_snapshot'] ?? []);
                $state = (array) ($event['state'] ?? $event['state_snapshot'] ?? []);
                $specialist = (string) ($features['specialist_strategy'] ?? $event['specialist_strategy'] ?? 'm15_specialist');
                $raw = $features['m15_raw_decision'] ?? $state['m15_raw_decision'] ?? null;
                $risk = $features['risk_decision'] ?? $state['risk_decision'] ?? null;
                $council = $features['council_decision'] ?? $state['council_decision'] ?? $event['decision'] ?? null;
                $votes = array_filter([
                    'specialist' => [$specialist => $raw],
                    'risk_sentinel' => $risk,
                    'council' => $council,
                ], static fn ($value): bool => $value !== null && $value !== '');
            }
            $riskVeto = in_array(strtoupper((string) ($event['risk_decision'] ?? $votes['risk_sentinel'] ?? '')), ['WAIT', 'VETO'], true);
            $rawDecision = strtoupper((string) ($event['m15_raw_decision'] ?? data_get($votes, 'specialist.'.array_key_first((array) ($votes['specialist'] ?? [])), '')));
            $councilDecision = strtoupper((string) ($event['council_decision'] ?? $votes['council'] ?? $event['decision'] ?? ''));
            $disagreement = $this->disagreement([
                'specialist' => $rawDecision,
                'council' => $councilDecision,
            ], $event);
            if ($riskVeto && in_array($rawDecision, ['BUY', 'SELL'], true)) {
                $disagreement['risk_veto'] = true;
            }
            if ($disagreement === [] && ! $riskVeto && ($rawDecision === '' || $councilDecision === '' || $rawDecision === $councilDecision) && count($trace) > 1) {
                $skipped++;
                continue;
            }
            $eventKey = hash('sha256', json_encode([
                self::PROTOCOL, $context['evidence_run_id'] ?? data_get($result, 'evidence_run_id'),
                $event['timestamp'] ?? $event['time'] ?? $event['decision_at'] ?? $index, $votes,
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            LabCouncilDisagreement::query()->firstOrCreate(
                ['event_key' => $eventKey],
                [
                    'symbol' => strtoupper((string) ($context['symbol'] ?? data_get($result, 'symbol', 'XAUUSD'))),
                    'timeframe' => strtoupper((string) ($context['timeframe'] ?? data_get($result, 'timeframe', 'H1'))),
                    'family' => $context['family'] ?? data_get($event, 'family'),
                    'h1_context_hash' => $event['h1_context_hash'] ?? data_get($event, 'h1_context.hash', data_get($event, 'state.h1_context.h1_context_hash', data_get($event, 'features.h1_context_hash'))),
                    'decision_at' => $this->timestamp(
                        data_get($event, 'timestamp', data_get($event, 'time', data_get($event, 'decision_at')))
                    ),
                    'regime' => $event['regime'] ?? data_get($event, 'h1_context.regime'),
                    'specialist_votes' => $votes,
                    'risk_decision' => $event['risk_decision'] ?? data_get($event, 'risk.decision', $votes['risk_sentinel'] ?? null),
                    'council_decision' => $event['council_decision'] ?? $event['decision'] ?? $event['signal'] ?? ($votes['council'] ?? null),
                    'disagreement' => $disagreement,
                    'outcome_status' => $event['outcome_status'] ?? 'unresolved',
                    'outcome_score' => is_numeric($event['outcome_score'] ?? null) ? (float) $event['outcome_score'] : null,
                    'evidence' => ['protocol' => self::PROTOCOL, 'promotion_evidence' => false, 'raw_keys' => array_keys($event)],
                    'promotion_evidence' => false,
                ],
            );
            $recorded++;
        }
        return compact('recorded', 'skipped');
    }

    /** @return array<string, mixed> */
    public function progress(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return ['available' => false];
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        return Cache::remember("council-disagreement:progress:{$symbol}:{$timeframe}", now()->addSeconds(15), function () use ($symbol, $timeframe): array {
            $row = LabCouncilDisagreement::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw("SUM(CASE WHEN outcome_status = 'unresolved' THEN 1 ELSE 0 END) AS unresolved")
                ->selectRaw("SUM(CASE WHEN outcome_status <> 'unresolved' THEN 1 ELSE 0 END) AS resolved")
                ->first();
            return [
                'available' => true,
                'total' => (int) ($row->total ?? 0),
                'unresolved' => (int) ($row->unresolved ?? 0),
                'resolved' => (int) ($row->resolved ?? 0),
                'cached_for_seconds' => 15,
            ];
        });
    }

    private function summaryEvent(array $result): array
    {
        return [
            'decision' => data_get($result, 'decision'),
            'signal' => data_get($result, 'signal'),
            'risk_decision' => data_get($result, 'risk_decision'),
            'votes' => data_get($result, 'specialist_votes', data_get($result, 'council_votes', [])),
        ];
    }

    /** @return array<string, mixed> */
    private function disagreement(array $votes, array $event): array
    {
        if ($votes === []) return [];
        $normalized = [];
        foreach ($votes as $name => $vote) {
            $normalized[(string) $name] = is_array($vote)
                ? ($vote['decision'] ?? $vote['signal'] ?? $vote['action'] ?? null)
                : $vote;
        }
        $values = array_values(array_filter($normalized, fn ($value) => $value !== null && $value !== ''));
        return count(array_unique(array_map('strval', $values))) > 1
            ? ['votes' => $normalized, 'selected' => $event['decision'] ?? $event['signal'] ?? null]
            : [];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! $value) return null;
        try { return \Illuminate\Support\Carbon::parse($value)->utc()->toDateTimeString(); } catch (\Throwable) { return null; }
    }
}
