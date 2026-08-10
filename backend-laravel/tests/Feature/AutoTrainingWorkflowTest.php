<?php

namespace Tests\Feature;

use App\Models\MarketSymbol;
use App\Models\TrainingLog;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AutoTrainingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_logs_index_and_show_pages_are_visible(): void
    {
        $session = TrainingSession::create([
            'title' => 'Historical session',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'best_strategy' => 'ema_rsi_v1',
            'best_score' => 80,
            'worst_strategy' => 'ema_rsi_v1',
            'worst_score' => 80,
            'total_trades' => 120,
            'average_winrate' => 58.4,
            'average_profit' => 10.2,
            'ai_conclusion' => 'Historical session.',
            'next_training_plan' => 'Canonical Lab.',
            'raw_leaderboard' => [],
        ]);
        $log = TrainingLog::create([
            'type' => 'historical_snapshot',
            'status' => 'success',
            'training_session_id' => $session->id,
            'message' => 'Historical snapshot.',
            'context' => ['best_strategy' => 'ema_rsi_v1'],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->get('/training-logs')
            ->assertOk()
            ->assertSee('Auto Training Logs')
            ->assertSee('historical_snapshot')
            ->assertSee('Session');
        $this->get(route('training-logs.show', $log))
            ->assertOk()
            ->assertSee('Training Log #'.$log->id)
            ->assertSee('Historical snapshot.');
    }

    public function test_auto_train_is_only_a_canonical_dispatch_alias(): void
    {
        $this->artisan('trading:auto-train --symbol=INVALID')
            ->expectsOutput("Canonical Lab uchun symbol/timeframe noto'g'ri.")
            ->assertExitCode(1);

        $this->assertDatabaseCount('training_sessions', 0);
        $this->assertDatabaseCount('strategy_scores', 0);
        $this->assertDatabaseCount('evolution_proposals', 0);
        $this->assertDatabaseCount('strategy_genomes', 0);
    }

    public function test_market_data_update_command_stores_csv_candles_without_duplicates(): void
    {
        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $this->writeMarketDataCsv();

        $this->artisan('market-data:update --symbol=XAUUSD --timeframe=H1 --limit=2')
            ->expectsOutput('XAUUSD H1: 2 candle updated.')
            ->assertOk();
        $this->assertDatabaseHas('symbols', ['code' => 'XAUUSD', 'display_name' => 'Gold / US Dollar']);
        $this->assertDatabaseCount('candles', 2);
    }

    public function test_daily_workflow_calls_canonical_lab_and_not_legacy_training(): void
    {
        MarketSymbol::create([
            'symbol' => 'XAUUSD',
            'provider_symbol' => 'XAU_USD',
            'name' => 'Gold / US Dollar',
            'market_type' => 'forex',
            'is_active' => true,
        ]);
        $this->writeMarketDataCsv();

        $this->artisan('trading:daily-workflow')
            ->expectsOutput('1/3 Market data yangilanmoqda...')
            ->expectsOutput('2/3 Canonical Lab incremental tekshiruvi boshlanmoqda...')
            ->expectsOutput('3/3 Daily report yaratilmoqda...')
            ->expectsOutput('Daily AI workflow yakunlandi.')
            ->assertOk();

        $this->assertDatabaseHas('training_logs', [
            'type' => 'daily_workflow', 'status' => 'success',
        ]);
        $this->assertDatabaseCount('training_sessions', 0);
        $this->assertDatabaseCount('strategy_scores', 0);
        $this->assertDatabaseCount('evolution_proposals', 0);
    }

    private function writeMarketDataCsv(): void
    {
        $directory = storage_path('app/market-data');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/XAUUSD_H1.csv', implode(PHP_EOL, [
            'time,open,high,low,close,volume',
            '2024-01-01 00:00:00,2062.12,2065.40,2059.10,2063.50,0',
            '2024-01-01 01:00:00,2063.50,2066.00,2060.00,2064.10,0',
            '2024-01-01 02:00:00,2064.10,2068.00,2062.00,2067.25,0',
        ]).PHP_EOL);
    }
}
