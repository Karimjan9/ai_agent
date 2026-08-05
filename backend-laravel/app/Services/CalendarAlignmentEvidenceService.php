<?php

namespace App\Services;

use App\Models\EconomicEvent;
use Carbon\Carbon;
/**
 * Attaches official economic-calendar evidence to a sealed replay result.
 *
 * The Python replay remains market-only and deterministic. This service joins
 * its immutable trade ledger to the locally stored official event ledger; it
 * never invents historical events and never turns a missing provider history
 * into a pass.
 */
class CalendarAlignmentEvidenceService
{
    public function enrich(string $symbol, string $timeframe, array $result): array
    {
        $start = data_get($result, 'market_adaptive_replay.rolling_evolution.start');
        $end = data_get($result, 'market_adaptive_replay.rolling_evolution.end');
        if (! $start || ! $end) {
            return $this->attach($result, [
                'protocol' => 'official_calendar_alignment_v1',
                'status' => 'not_assessed',
                'pass' => false,
                'reason' => 'replay_period_missing',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ]);
        }

        try {
            $from = Carbon::parse($start)->utc();
            $to = Carbon::parse($end)->utc();
        } catch (\Throwable) {
            return $this->attach($result, [
                'protocol' => 'official_calendar_alignment_v1',
                'status' => 'not_assessed',
                'pass' => false,
                'reason' => 'replay_period_invalid',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ]);
        }

        $currencies = $this->currencies($symbol);
        $events = EconomicEvent::query()
            ->whereIn('currency', $currencies)
            ->where('impact', 'high')
            ->whereBetween('scheduled_at', [$from, $to])
            // Headline/news rows are not scheduled economic-calendar proof.
            ->whereNotIn('source', ['alpha_vantage_news', 'currents_api_news'])
            ->orderBy('scheduled_at')
            ->get();

        if ($events->isEmpty()) {
            return $this->attach($result, [
                'protocol' => 'official_calendar_alignment_v1',
                'status' => 'not_assessed',
                'pass' => false,
                'reason' => 'official_calendar_history_unavailable',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'replay_period' => ['start' => $from->toIso8601String(), 'end' => $to->toIso8601String()],
                'currencies' => $currencies,
                'event_count' => 0,
            ]);
        }

        $preMinutes = (int) config('services.economic_calendar.pre_event_minutes', 30);
        $postMinutes = (int) config('services.economic_calendar.post_event_minutes', 30);
        $trades = collect((array) data_get($result, 'trades', []));
        $eventWindows = $events->map(function (EconomicEvent $event) use ($trades, $preMinutes, $postMinutes): array {
            $at = $event->scheduled_at->utc();
            $windowStart = $at->copy()->subMinutes($preMinutes);
            $windowEnd = $at->copy()->addMinutes($postMinutes);
            $windowTrades = $trades->filter(function ($trade) use ($windowStart, $windowEnd): bool {
                try {
                    $entry = Carbon::parse(data_get($trade, 'entry_time'))->utc();
                    return $entry->betweenIncluded($windowStart, $windowEnd);
                } catch (\Throwable) {
                    return false;
                }
            })->values();
            $profits = $windowTrades->map(fn ($trade): float => (float) data_get($trade, 'profit_percent', 0));
            $wins = $profits->filter(fn (float $profit): bool => $profit > 0)->sum();
            $losses = abs($profits->filter(fn (float $profit): bool => $profit < 0)->sum());
            return [
                'event_id' => $event->id,
                'source' => $event->source,
                'currency' => $event->currency,
                'scheduled_at' => $at->toIso8601String(),
                'impact' => $event->impact,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
                'trades' => $windowTrades->count(),
                'wins' => $wins,
                'losses' => $losses,
                'profit_factor' => $losses > 0 ? round($wins / $losses, 6) : ($wins > 0 ? 99.0 : null),
            ];
        })->values();

        $eventTrades = $eventWindows->sum('trades');
        $eventWins = (float) $eventWindows->sum('wins');
        $eventLosses = (float) $eventWindows->sum('losses');
        $eventPf = $eventLosses > 0 ? round($eventWins / $eventLosses, 6) : ($eventWins > 0 ? 99.0 : null);
        // Zero trades in official high-impact windows is an explicit safe
        // abstention. With any activity, require at least three observations;
        // tiny samples are an evidence gap, not a pass.
        $pass = $eventTrades === 0 || ($eventTrades >= 3 && $eventPf !== null && $eventPf >= 1.0);
        $status = $eventTrades > 0 && $eventTrades < 3 ? 'insufficient_sample' : 'assessed';

        return $this->attach($result, [
            'protocol' => 'official_calendar_alignment_v1',
            'status' => $status,
            'pass' => $pass && $status === 'assessed',
            'reason' => $status === 'assessed' ? ($pass ? 'calendar_window_survival_passed' : 'calendar_window_survival_failed') : 'too_few_event_window_trades',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'currencies' => $currencies,
            'replay_period' => ['start' => $from->toIso8601String(), 'end' => $to->toIso8601String()],
            'event_count' => $events->count(),
            'event_sources' => $events->pluck('source')->unique()->values()->all(),
            'event_ledger_hash' => hash('sha256', json_encode($eventWindows->all(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
            'event_window_trades' => $eventTrades,
            'event_window_profit_factor' => $eventPf,
            'windows' => $eventWindows->all(),
            'window_policy' => ['pre_event_minutes' => $preMinutes, 'post_event_minutes' => $postMinutes],
        ]);
    }

    /** @return array<int, string> */
    private function currencies(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        if (str_starts_with($symbol, 'XAU')) return ['USD'];
        return array_values(array_unique([substr($symbol, 0, 3), 'USD']));
    }

    private function attach(array $result, array $evidence): array
    {
        $redTeam = (array) ($result['red_team'] ?? []);
        $scenarios = (array) ($redTeam['scenarios'] ?? []);
        $scenarios['news_window'] = $evidence;
        $redTeam['scenarios'] = $scenarios;
        $result['red_team'] = $redTeam;
        $result['calendar_alignment'] = $evidence;
        return $result;
    }
}
