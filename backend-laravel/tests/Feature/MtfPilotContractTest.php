<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperMtfShadowObservation;
use App\Models\PaperSignal;
use App\Models\PaperSignalPassport;
use App\Services\MultiTimeframePilotService;
use App\Services\PaperMtfLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MtfPilotContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_is_enabled_only_for_xauusd_m15_and_never_transfers_genetic_parenthood(): void
    {
        $service = app(MultiTimeframePilotService::class);
        $contract = $service->requestPayload('XAUUSD', 'M15', 'pullback_entry_v1', 'b'.str_repeat('1', 63));

        $this->assertTrue($contract['enabled']);
        $this->assertSame('H1', $contract['regime_timeframe']);
        $this->assertSame('M15', $contract['entry_timeframe']);
        $this->assertFalse($contract['genetic_parent_transfer']);
        $this->assertSame('xauusd_h1_m15_v1', $contract['pilot_id']);
        $this->assertNotSame('', $contract['contract_hash']);
        $this->assertFalse(app(MultiTimeframePilotService::class)->requestPayload('XAUUSD', 'H1')['enabled']);
        $this->assertFalse(app(MultiTimeframePilotService::class)->requestPayload('EURUSD', 'M15')['enabled']);
    }

    public function test_missing_python_mtf_context_is_blocked_at_laravel_paper_boundary(): void
    {
        $model = ModelVersion::create([
            'name' => 'mtf_guard', 'strategy' => 'pullback_entry_v1', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'strategy_family' => 'entry', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);

        $guarded = app(MultiTimeframePilotService::class)->enforcePaperResponse($candidate, [
            'signal' => 'BUY', 'confidence' => .8,
        ]);

        $this->assertSame('WAIT', $guarded['signal']);
        $this->assertSame('LARAVEL_MTF_CONTEXT_GUARD', $guarded['mtf_pilot']['reason']);
    }

    public function test_passport_and_shadow_twin_are_idempotent_and_immutable(): void
    {
        $model = ModelVersion::create([
            'name' => 'mtf_ledger', 'strategy' => 'pullback_entry_v1', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'strategy_family' => 'entry', 'status' => 'forward_validated', 'paper_status' => 'pending',
        ]);
        $payload = [
            'signal' => 'BUY', 'signal_time' => '2026-08-11T10:15:00+00:00', 'price' => 3400,
            'confidence' => .72,
            'mtf_pilot' => [
                'protocol' => MultiTimeframePilotService::PROTOCOL, 'pilot_id' => 'xauusd_h1_m15_v1',
                'decision' => 'BUY', 'risk_multiplier' => 1.0,
                'context' => [
                    'status' => 'ready', 'permission' => 'ALLOW', 'h1_direction' => 'BUY',
                    'h1_regime' => 'trend_up', 'h1_closed_at' => '2026-08-11T10:00:00+00:00',
                    'h1_context_hash' => str_repeat('a', 64),
                ],
            ],
            'meta_agent' => ['decision' => 'BUY', 'reason' => 'H1_PERMISSION_GRANTED'],
            'counterfactuals' => [
                'm15_only' => ['decision' => 'BUY'],
                'h1_m15_official' => ['decision' => 'BUY'],
                'm15_without_h1_veto' => ['decision' => 'BUY'],
                'h1_only_context' => ['decision' => 'BUY'],
            ],
            'execution_contract_preview' => ['execution_hash' => str_repeat('b', 64)],
        ];
        $signal = PaperSignal::create([
            'model_market_performance_id' => $candidate->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'candle_time' => '2026-08-11 10:15:00',
            'decision' => 'BUY', 'price' => 3400, 'confidence' => 72,
            'payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload)),
        ]);

        $ledger = app(PaperMtfLedgerService::class);
        $passport = $ledger->recordOfficial($signal, $payload);
        $ledger->recordShadow($candidate, $signal, $payload);
        $ledger->recordShadow($candidate, $signal, $payload);

        $this->assertInstanceOf(PaperSignalPassport::class, $passport);
        $this->assertDatabaseCount('paper_signal_passports', 1);
        $this->assertSame(2, PaperMtfShadowObservation::count());
        $this->assertDatabaseHas('paper_mtf_shadow_observations', ['promotion_evidence' => false, 'scenario_key' => 'm15_only']);

        $this->expectException(LogicException::class);
        $passport->update(['entry_reason' => 'tampered']);
    }
}
