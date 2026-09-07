<?php

namespace Tests\Feature;

use App\Models\ResearchExperimentWorkItem;
use App\Services\ResearchExperimentConversionKernelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchExperimentConversionKernelServiceTest extends TestCase
{
    use RefreshDatabase;

    private function contract(): array
    {
        return ['contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'fixture', 'id' => 7],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'baseline', 'data_and_mtf_hash' => 'data', 'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => 'intervention', 'window_plan_hash' => 'window', 'evaluator_version' => 'v1'],
            'arms' => [['role' => 'frozen_control'], ['role' => 'candidate']], 'revisions' => ['subject' => 2, 'evidence' => 3]];
    }

    public function test_terminal_receipt_and_next_work_are_idempotent_and_stale_completion_is_fenced(): void
    {
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $one = $kernel->record($this->contract(), ['settlement_id' => 9], 'INCONCLUSIVE', ['type' => 'academy_repair', 'identity' => 'fixture']);
        $two = $kernel->record($this->contract(), ['settlement_id' => 9], 'INCONCLUSIVE', ['type' => 'academy_repair', 'identity' => 'fixture']);

        $this->assertSame($one['receipt_id'], $two['receipt_id']);
        $this->assertSame($one['work_id'], $two['work_id']);
        $first = $kernel->claim(1)[0];
        $first->update(['lease_expires_at' => now()->subSecond()]);
        $second = $kernel->claim(1)[0];

        $this->assertFalse($kernel->complete($first, ['status' => 'stale']));
        $this->assertTrue($kernel->complete($second, ['status' => 'done']));
        $this->assertSame('settled', ResearchExperimentWorkItem::query()->find($second->id)->status);
    }
}
