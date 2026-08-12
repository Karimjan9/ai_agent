<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperSignal;
use App\Models\PaperSignalPassport;
use App\Models\ServiceHealthCheck;
use App\Models\Symbol;
use App\Services\MtfPilotMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MtfPilotMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_records_closed_alignment_and_health_snapshot(): void
    {
        $symbol = $this->seedCandles();

        $report = app(MtfPilotMonitoringService::class)->inspect('XAUUSD', 24);

        $alignment = collect($report['checks'])->firstWhere('code', 'CLOSED_CANDLE_ALIGNMENT');
        $this->assertSame('ok', $alignment['status']);
        $this->assertSame('warning', $report['status']);
        $this->assertDatabaseCount('mtf_pilot_monitor_runs', 1);
        $this->assertDatabaseHas('service_health_checks', [
            'service_key' => 'mtf_pilot:XAUUSD',
            'status' => 'warning',
        ]);
        $this->assertSame($symbol->id, Candle::query()->where('timeframe', 'H1')->value('symbol_id'));
    }

    public function test_monitor_fails_closed_for_future_h1_context(): void
    {
        $this->seedCandles();
        $model = ModelVersion::create([
            'name' => 'monitor_guard', 'strategy' => 'pullback_entry_v1', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'strategy_family' => 'entry', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);
        $decisionAt = now()->utc()->subMinutes(15);
        $payload = ['mtf_pilot' => ['protocol' => 'xauusd_h1_m15_mtf_v1']];
        $signal = PaperSignal::create([
            'model_market_performance_id' => $candidate->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'M15',
            'candle_time' => $decisionAt,
            'decision' => 'WAIT',
            'price' => 3400,
            'confidence' => 0,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload)),
        ]);
        PaperSignalPassport::create([
            'paper_signal_id' => $signal->id,
            'model_market_performance_id' => $candidate->id,
            'pilot_id' => 'xauusd_h1_m15_v1',
            'lane' => 'official',
            'symbol' => 'XAUUSD',
            'primary_timeframe' => 'M15',
            'regime_timeframe' => 'H1',
            'entry_timeframe' => 'M15',
            'h1_context_hash' => str_repeat('a', 64),
            'h1_closed_at' => now()->utc(),
            'm15_decision_at' => $decisionAt,
            'm15_strategy' => 'pullback_entry_v1',
            'risk_decision' => 'WAIT',
            'mtf_decision' => 'WAIT',
            'payload' => $payload,
            'passport_hash' => str_repeat('b', 64),
        ]);

        $report = app(MtfPilotMonitoringService::class)->inspect('XAUUSD', 24);
        $context = collect($report['checks'])->firstWhere('code', 'CLOSED_H1_CONTEXT');

        $this->assertSame('critical', $context['status']);
        $this->assertGreaterThan(0, $context['metrics']['future_context_count']);
        $this->assertDatabaseHas('system_logs', [
            'log_type' => 'mtf_pilot_status_changed',
            'component' => 'mtf_pilot',
            'status' => 'critical',
        ]);
    }

    private function seedCandles(): Symbol
    {
        $symbol = Symbol::create([
            'code' => 'XAUUSD',
            'display_name' => 'Gold',
            'asset_class' => 'metal',
            'is_active' => true,
        ]);
        $current = now()->utc();
        $h1 = $current->copy()->subHours(2)->startOfHour();
        $m15 = $current->copy()->startOfHour()->addMinutes(intdiv($current->minute, 15) * 15 - 15);
        foreach ([['H1', $h1], ['M15', $m15]] as [$timeframe, $time]) {
            Candle::create([
                'symbol_id' => $symbol->id,
                'timeframe' => $timeframe,
                'time' => $time,
                'open' => 3345.12,
                'high' => 3346.88,
                'low' => 3344.81,
                'close' => 3346.01,
                'volume' => 1532,
                'provider' => 'mt5',
            ]);
        }

        return $symbol;
    }
}
