<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Services\LabPopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiLearningLaboratoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_pair_gets_a_bounded_owned_twenty_agent_population(): void
    {
        $service = app(LabPopulationService::class);
        $xau = $service->build('XAUUSD', 'new_data', true);
        $eur = $service->build('EURUSD', 'new_data', true);

        $this->assertCount(20, $xau->agents);
        $this->assertCount(20, $eur->agents);
        $this->assertSame(3, $xau->agents->where('origin', 'elite')->count());
        $this->assertSame(10, $xau->agents->where('origin', 'mutation')->count());
        $this->assertSame(4, $xau->agents->where('origin', 'crossover')->count());
        $this->assertSame(3, $xau->agents->where('origin', 'random')->count());
        $this->assertTrue($xau->agents->every(fn (LabAgent $agent) => $agent->lifecycle_status === 'draft'));
        $this->assertEqualsCanonicalizing(
            ['breakout', 'trend', 'volatility'],
            $xau->agents->where('origin', 'elite')->pluck('strategy_family')->all(),
        );
        $this->assertTrue($xau->agents->every(fn (LabAgent $agent) => str_starts_with($agent->modelVersion->strategy, 'xauusd_')));
        $this->assertTrue($eur->agents->every(fn (LabAgent $agent) => str_starts_with($agent->modelVersion->strategy, 'eurusd_')));
        $this->assertEqualsCanonicalizing(['breakout', 'trend', 'volatility'], $xau->agents->pluck('strategy_family')->unique()->all());
        $this->assertEqualsCanonicalizing(['mean_reversion', 'session', 'trend'], $eur->agents->pluck('strategy_family')->unique()->all());
    }

    public function test_generation_is_not_repeated_without_enough_new_data(): void
    {
        $service = app(LabPopulationService::class);
        $this->assertNotNull($service->build('GBPUSD', 'new_data', false));
        $this->assertNull($service->build('GBPUSD', 'new_data', false));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_pair_laboratory_dashboard_renders_learning_evidence(): void
    {
        app(LabPopulationService::class)->build('XAUUSD', 'market_drift', true);
        $this->get(route('ai-laboratory.show', 'XAUUSD'))
            ->assertOk()->assertSee('XAUUSD Lab')->assertSee('Generation population')
            ->assertSee('Generation bo‘yicha forward performance')->assertSee('20/20');
    }
}
