<?php

namespace Tests\Feature;

use App\Models\EliteAgentPortfolio;
use App\Services\LabLifecycleWatchdogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
