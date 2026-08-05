<?php

namespace Tests\Feature;

use App\Models\EconomicEvent;
use App\Services\EconomicCalendarService;
use App\Services\CalendarAlignmentEvidenceService;
use App\Services\OfficialUsdCalendarBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EconomicCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fmp_economic_calendar_is_parsed_and_uses_fmp_authorization(): void
    {
        config([
            'services.economic_calendar.enabled' => true,
            'services.economic_calendar.provider' => 'financial_modeling_prep',
            'services.economic_calendar.endpoint' => 'https://financialmodelingprep.com/stable/economic-calendar',
            'services.economic_calendar.api_key' => 'test-fmp-key',
        ]);
        Http::fake([
            'financialmodelingprep.com/stable/economic-calendar*' => Http::response([[
                'date' => '2026-07-25 12:30:00', 'event' => 'US CPI', 'country' => 'US',
                'currency' => 'USD', 'impact' => 'High', 'estimate' => '0.3%', 'actual' => null, 'previous' => '0.2%',
            ]]),
        ]);

        $result = app(EconomicCalendarService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['synced']);
        $this->assertDatabaseHas('economic_events', ['title' => 'US CPI', 'currency' => 'USD', 'impact' => 'high', 'forecast' => '0.3%']);
        Http::assertSent(fn ($request) => $request['apikey'] === 'test-fmp-key' && filled($request['from']) && filled($request['to']));
    }

    public function test_alpha_vantage_macro_headline_becomes_short_lived_usd_execution_veto(): void
    {
        config([
            'services.economic_calendar.enabled' => true,
            'services.economic_calendar.provider' => 'alpha_vantage_news',
            'services.alpha_vantage.api_key' => 'test-alpha-key',
            'services.alpha_vantage.endpoint' => 'https://www.alphavantage.co/query',
        ]);
        Http::fake([
            'www.alphavantage.co/query*' => Http::response(['feed' => [[
                'title' => 'Federal Reserve signals CPI and interest rate decision',
                'summary' => 'US dollar markets await FOMC commentary.',
                'time_published' => now('UTC')->subMinutes(10)->format('Ymd\\THis'),
                'url' => 'https://example.test/fed-cpi',
            ]]]),
        ]);

        $result = app(EconomicCalendarService::class)->sync();
        $veto = app(EconomicCalendarService::class)->veto('XAUUSD');

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseHas('economic_events', ['source' => 'alpha_vantage_news', 'currency' => 'USD', 'impact' => 'high']);
        $this->assertTrue($veto['active']);
        Http::assertSent(fn ($request) => $request['function'] === 'NEWS_SENTIMENT' && $request['apikey'] === 'test-alpha-key');
    }

    public function test_currents_macro_headline_becomes_short_lived_execution_veto(): void
    {
        config([
            'services.currents_api.api_key' => 'test-currents-key',
            'services.currents_api.endpoint' => 'https://api.currentsapi.services/v1/latest-news',
        ]);
        Http::fake([
            'api.currentsapi.services/v1/latest-news*' => Http::response(['news' => [[
                'id' => 'currents-123',
                'title' => 'Bank of England signals interest-rate decision',
                'description' => 'Sterling traders await the central-bank statement.',
                'published' => now('UTC')->subMinutes(5)->toIso8601String(),
                'url' => 'https://example.test/boe-rates',
            ]]]),
        ]);

        $result = app(EconomicCalendarService::class)->sync('currents_api_news');
        $veto = app(EconomicCalendarService::class)->veto('GBPUSD');

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseHas('economic_events', ['source' => 'currents_api_news', 'external_id' => 'currents-123', 'currency' => 'GBP', 'impact' => 'high']);
        $this->assertTrue($veto['active']);
        Http::assertSent(fn ($request) => $request['apiKey'] === 'test-currents-key' && $request['language'] === 'en');
    }

    public function test_replay_calendar_alignment_fails_closed_without_historical_official_events(): void
    {
        $result = app(CalendarAlignmentEvidenceService::class)->enrich('XAUUSD', 'H1', [
            'market_adaptive_replay' => [
                'rolling_evolution' => ['start' => '2026-01-01T00:00:00Z', 'end' => '2026-06-30T00:00:00Z'],
            ],
            'red_team' => ['scenarios' => []],
            'trades' => [],
        ]);

        $this->assertSame('not_assessed', data_get($result, 'calendar_alignment.status'));
        $this->assertFalse((bool) data_get($result, 'calendar_alignment.pass'));
        $this->assertSame('official_calendar_history_unavailable', data_get($result, 'red_team.scenarios.news_window.reason'));
    }

    public function test_replay_calendar_alignment_accepts_an_official_event_when_the_strategy_abstains(): void
    {
        EconomicEvent::create([
            'source' => 'financial_modeling_prep', 'external_id' => 'historical-cpi-1',
            'title' => 'US CPI', 'country' => 'US', 'currency' => 'USD', 'impact' => 'high',
            'scheduled_at' => '2026-04-10 12:30:00', 'payload' => [],
        ]);

        $result = app(CalendarAlignmentEvidenceService::class)->enrich('XAUUSD', 'H1', [
            'market_adaptive_replay' => [
                'rolling_evolution' => ['start' => '2026-04-01T00:00:00Z', 'end' => '2026-04-30T23:59:59Z'],
            ],
            'red_team' => ['scenarios' => []],
            'trades' => [],
        ]);

        $this->assertSame('assessed', data_get($result, 'calendar_alignment.status'));
        $this->assertTrue((bool) data_get($result, 'calendar_alignment.pass'));
        $this->assertSame(1, data_get($result, 'calendar_alignment.event_count'));
    }

    public function test_official_usd_backfill_is_idempotent_and_preserves_release_timezone_provenance(): void
    {
        $service = app(OfficialUsdCalendarBackfillService::class);

        $first = $service->backfill(
            now('UTC')->setDate(2026, 1, 1)->startOfDay(),
            now('UTC')->setDate(2026, 7, 31)->endOfDay(),
            2026,
        );
        $second = $service->backfill(
            now('UTC')->setDate(2026, 1, 1)->startOfDay(),
            now('UTC')->setDate(2026, 7, 31)->endOfDay(),
            2026,
        );

        $this->assertSame(14, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(14, EconomicEvent::where('source', 'official_bls')->count());
        $this->assertSame('official_release_date_backfill_v1', EconomicEvent::where('source', 'official_bls')->first()->payload['protocol']);
        $this->assertSame('America/New_York', EconomicEvent::where('source', 'official_bls')->first()->payload['source_timezone']);
    }
}
