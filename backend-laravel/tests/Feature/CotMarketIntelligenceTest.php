<?php

namespace Tests\Feature;

use App\Models\CotFeatureSnapshot;
use App\Models\CotReport;
use App\Services\CotMarketIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CotMarketIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_cot_rows_are_immutably_imported_and_featured(): void
    {
        Http::fake([
            'https://publicreporting.cftc.gov/*' => Http::response([
                $this->row('260106088691F', '2026-01-06', 100000, 20000, 15000, 34000),
                $this->row('260113088691F', '2026-01-13', 112000, 18000, 14000, 35000),
                $this->row('260120088691F', '2026-01-20', 125000, 17000, 13000, 36000),
            ]),
        ]);

        $stats = app(CotMarketIntelligenceService::class)->syncGoldReports();

        $this->assertSame(['received' => 3, 'created' => 3, 'features' => 3], $stats);
        $this->assertCount(3, CotReport::all());
        $this->assertCount(3, CotFeatureSnapshot::all());

        $latest = CotFeatureSnapshot::query()->latest('report_date')->firstOrFail();
        $this->assertSame(108000, $latest->managed_money_net);
        $this->assertSame(14000, $latest->managed_money_delta_1w);
        $this->assertSame('bullish', $latest->weekly_bias);
        $this->assertTrue($latest->report->release_time_estimated);
        $this->assertSame('2026-01-23 20:30:00', $latest->available_at->utc()->format('Y-m-d H:i:s'));

        Http::fake([
            'https://publicreporting.cftc.gov/*' => Http::response([
                $this->row('260120088691F', '2026-01-20', 999999, 1, 1, 99999),
            ]),
        ]);
        app(CotMarketIntelligenceService::class)->syncGoldReports();

        $this->assertSame(108000, CotReport::where('source_record_id', '260120088691F')->value('managed_money_net'));
    }

    public function test_cot_dashboard_is_read_only_and_command_can_sync(): void
    {
        Http::fake([
            'https://publicreporting.cftc.gov/*' => Http::response([
                $this->row('260127088691F', '2026-01-27', 120000, 20000, 15000, 36000),
            ]),
        ]);

        $this->artisan('market-intelligence:sync-cot --limit=12')
            ->expectsOutputToContain('COT synced: 1 received, 1 reports created, 1 feature snapshots created.')
            ->assertExitCode(0);

        $this->get(route('market-intelligence.index'))
            ->assertOk()
            ->assertSee('Institutional Positioning')
            ->assertSee('COT Safety Rule');
    }

    private function row(string $id, string $date, int $long, int $short, int $commercialLong, int $commercialShort): array
    {
        return [
            'id' => $id,
            'market_and_exchange_names' => 'GOLD - COMMODITY EXCHANGE INC.',
            'report_date_as_yyyy_mm_dd' => $date.'T00:00:00.000',
            'open_interest_all' => '450000',
            'm_money_positions_long_all' => (string) $long,
            'm_money_positions_short_all' => (string) $short,
            'm_money_positions_spread' => '12000',
            'prod_merc_positions_long' => (string) $commercialLong,
            'prod_merc_positions_short' => (string) $commercialShort,
        ];
    }
}
