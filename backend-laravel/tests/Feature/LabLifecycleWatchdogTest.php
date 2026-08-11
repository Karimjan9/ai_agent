<?php

namespace Tests\Feature;

use App\Models\EliteAgentPortfolio;
use App\Services\LabPopulationService;
use App\Services\LabLifecycleWatchdogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LabLifecycleWatchdogTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchdog_surfaces_active_portfolio_contract_drift_without_rewriting_evidence(): void
    {
        $portfolio = EliteAgentPortfolio::create([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'portfolio_key' => 'watchdog-test-portfolio',
            'status' => 'forward_validated',
            'gate_status' => 'passed',
            'member_count' => 2,
            'gate_reasons' => [],
            'evidence' => ['gate' => ['status' => 'passed']],
        ]);

        $events = app(LabLifecycleWatchdogService::class)->inspect(false);

        $finding = collect($events)->firstWhere('code', 'ELITE_PORTFOLIO_CONTRACT_DRIFT');
        $this->assertNotNull($finding);
        $this->assertContains('PORTFOLIO_MEMBER_COUNT_MISMATCH', $finding['context']['issues']);
        $this->assertFalse($finding['context']['promotion_evidence']);
        $this->assertSame('forward_validated', $portfolio->fresh()->status);
        $this->assertSame('passed', $portfolio->fresh()->gate_status);
    }

    public function test_watchdog_finalizes_a_terminal_full_boundary_without_waiting_an_hour(): void
    {
        Http::fake([
            '*' => Http::response([
                'active_requests' => 0,
                'protocol' => 'replay_liveness_v2_bounded_worker',
            ], 200),
        ]);

        $generation = app(LabPopulationService::class)->build('XAUUSD', 'watchdog_terminal_full_boundary', true);
        $generation->agents()->update(['lifecycle_status' => 'screened']);
        $generation->update(['status' => 'full_validation', 'completed_at' => null]);

        $events = app(LabLifecycleWatchdogService::class)->inspect(true);

        $this->assertSame('completed', $generation->fresh()->status);
        $this->assertNotNull(collect($events)->firstWhere('code', 'FULL_VALIDATION_TERMINAL_FINALIZED'));
        $this->assertFalse((bool) data_get(collect($events)->firstWhere('code', 'FULL_VALIDATION_TERMINAL_FINALIZED'), 'context.promotion_evidence', false));
    }
}
