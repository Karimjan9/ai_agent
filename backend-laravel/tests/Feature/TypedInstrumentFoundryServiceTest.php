<?php

namespace Tests\Feature;

use App\Services\TypedInstrumentFoundryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TypedInstrumentFoundryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function ast(): array
    {
        return ['op' => 'CONFIRMED_BY', 'args' => [
            ['op' => 'GREATER_THAN', 'args' => [
                ['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T10:00:00Z'],
                ['op' => 'ATR', 'available_at' => '2025-01-01T10:00:00Z'],
            ]],
            ['op' => 'BOOL', 'available_at' => '2025-01-01T10:00:00Z'],
        ]];
    }

    public function test_foundry_requires_academy_gates_and_rejects_semantic_duplicates(): void
    {
        $service = app(TypedInstrumentFoundryService::class);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'pre_2026_only' => true, 'data_hash' => 'data', 'execution_hash' => 'execution'];
        $blocked = $service->compile($this->ast(), $context, []);
        $compiled = $service->compile($this->ast(), $context, ['confirmed_cartridges' => 1, 'successful_transfers' => 1]);
        $duplicate = $service->compile($this->ast(), $context, ['confirmed_cartridges' => 1, 'successful_transfers' => 1]);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('compiled_research_only', $compiled['status']);
        $this->assertSame('semantic_duplicate', $duplicate['status']);
        $this->assertDatabaseHas('research_instrument_programs', ['program_key' => $compiled['program_key'], 'status' => 'compiled_research_only']);
    }

    public function test_emitter_credit_and_compounding_benchmark_remain_settled_research_contracts(): void
    {
        $service = app(TypedInstrumentFoundryService::class);
        $profile = $service->recordEmitterOutcome('entry_emitter', ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'failure_stage' => 'entry'], [
            'settled' => true, 'cohorts' => 1, 'stage_advanced' => 1, 'reusable_skills' => 1, 'successful_transfers' => 1,
        ]);
        $benchmark = $service->planCompoundingBenchmark([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'confirmed_cartridges' => 1, 'successful_transfers' => 1,
            'sealed_challenge_hash' => 'sealed', 'equal_compute_limit' => 120,
        ]);

        $this->assertSame('profiled', $profile['status']);
        $this->assertSame('planned', $benchmark['status']);
        $this->assertFalse($profile['promotion_evidence']);
        $this->assertDatabaseHas('research_compounding_benchmarks', ['benchmark_key' => $benchmark['benchmark_key'], 'status' => 'planned']);
    }
}
