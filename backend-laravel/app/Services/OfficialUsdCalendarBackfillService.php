<?php

namespace App\Services;

use App\Models\EconomicEvent;
use Carbon\Carbon;

/**
 * Curated fallback for historical USD release dates when the paid calendar
 * provider is unavailable.  These rows are not alpha data: they are only
 * execution-risk evidence for a sealed replay.  Every row carries the
 * official BLS source URL and the original Eastern-time release timestamp.
 *
 * The list is deliberately small and immutable: Employment Situation and CPI
 * are the high-impact BLS releases used by the XAU/USD calendar-alignment
 * passport.  A missing year is returned as not_available rather than being
 * guessed or filled from headlines.
 */
class OfficialUsdCalendarBackfillService
{
    private const BLS_SCHEDULE_URL = 'https://www.bls.gov/schedule/2026/home.htm';
    private const BLS_CPI_URL = 'https://www.bls.gov/schedule/news_release/cpi.htm';

    /** @return array{status:string, inserted:int, updated:int, skipped:int, source?:string} */
    public function backfill(?Carbon $from = null, ?Carbon $to = null, int $year = 2026): array
    {
        if ($year !== 2026) {
            return ['status' => 'not_available', 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $fromUtc = ($from ?: Carbon::create(2026, 1, 1, 0, 0, 0, 'UTC'))->copy()->utc();
        $toUtc = ($to ?: Carbon::create(2026, 12, 31, 23, 59, 59, 'UTC'))->copy()->utc();
        $rows = $this->releaseRows();
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $scheduledAt = Carbon::createFromFormat(
                'Y-m-d H:i',
                $row['date'].' '.$row['time'],
                'America/New_York',
            )->utc();
            if ($scheduledAt->lt($fromUtc) || $scheduledAt->gt($toUtc)) {
                $skipped++;
                continue;
            }

            $payload = [
                'protocol' => 'official_release_date_backfill_v1',
                'source_url' => self::BLS_SCHEDULE_URL,
                'secondary_source_url' => self::BLS_CPI_URL,
                'source_timezone' => 'America/New_York',
                'scheduled_at_precision' => 'official_release_time',
                'curated_release' => true,
                'curated_at' => '2026-08-03T00:00:00Z',
                'reference_period' => $row['reference_period'],
            ];
            $event = EconomicEvent::query()->updateOrCreate(
                ['source' => 'official_bls', 'external_id' => $row['external_id']],
                [
                    'title' => $row['title'],
                    'country' => 'United States',
                    'currency' => 'USD',
                    'impact' => 'high',
                    'scheduled_at' => $scheduledAt,
                    'payload' => $payload,
                ],
            );
            if ($event->wasRecentlyCreated) $inserted++;
            else $updated++;
        }

        return [
            'status' => 'completed',
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'source' => self::BLS_SCHEDULE_URL,
        ];
    }

    /** @return list<array{external_id:string,title:string,reference_period:string,date:string,time:string}> */
    private function releaseRows(): array
    {
        return [
            ['external_id' => 'employment-situation-2026-01-09', 'title' => 'Employment Situation', 'reference_period' => 'December 2025', 'date' => '2026-01-09', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-01-13', 'title' => 'Consumer Price Index', 'reference_period' => 'December 2025', 'date' => '2026-01-13', 'time' => '08:30'],
            ['external_id' => 'employment-situation-2026-02-11', 'title' => 'Employment Situation', 'reference_period' => 'January 2026', 'date' => '2026-02-11', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-02-13', 'title' => 'Consumer Price Index', 'reference_period' => 'January 2026', 'date' => '2026-02-13', 'time' => '08:30'],
            ['external_id' => 'employment-situation-2026-03-06', 'title' => 'Employment Situation', 'reference_period' => 'February 2026', 'date' => '2026-03-06', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-03-11', 'title' => 'Consumer Price Index', 'reference_period' => 'February 2026', 'date' => '2026-03-11', 'time' => '08:30'],
            ['external_id' => 'employment-situation-2026-04-03', 'title' => 'Employment Situation', 'reference_period' => 'March 2026', 'date' => '2026-04-03', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-04-10', 'title' => 'Consumer Price Index', 'reference_period' => 'March 2026', 'date' => '2026-04-10', 'time' => '08:30'],
            ['external_id' => 'employment-situation-2026-05-08', 'title' => 'Employment Situation', 'reference_period' => 'April 2026', 'date' => '2026-05-08', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-05-12', 'title' => 'Consumer Price Index', 'reference_period' => 'April 2026', 'date' => '2026-05-12', 'time' => '08:30'],
            ['external_id' => 'employment-situation-2026-06-05', 'title' => 'Employment Situation', 'reference_period' => 'May 2026', 'date' => '2026-06-05', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-06-10', 'title' => 'Consumer Price Index', 'reference_period' => 'May 2026', 'date' => '2026-06-10', 'time' => '08:30'],
            ['external_id' => 'employment-situation-2026-07-02', 'title' => 'Employment Situation', 'reference_period' => 'June 2026', 'date' => '2026-07-02', 'time' => '08:30'],
            ['external_id' => 'cpi-2026-07-14', 'title' => 'Consumer Price Index', 'reference_period' => 'June 2026', 'date' => '2026-07-14', 'time' => '08:30'],
        ];
    }
}
