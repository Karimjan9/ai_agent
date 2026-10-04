<?php

namespace Tests\Feature;

use App\Services\ProspectiveRepairProbeWindowService;
use RuntimeException;
use Tests\TestCase;

class ProspectiveRepairProbeWindowTest extends TestCase
{
    private function rows(): array
    {
        return array_map(fn (int $day): array => [
            'time' => sprintf('2025-11-%02dT00:00:00Z', $day),
        ], range(1, 6));
    }

    public function test_sealed_window_attests_only_exact_complete_receipt(): void
    {
        $service = app(ProspectiveRepairProbeWindowService::class);
        $contract = $service->seal($this->rows(), str_repeat('a', 64), str_repeat('b', 64), 'experiment-1', 4, 2);
        $this->assertSame(6, $contract['loaded_rows']);
        $this->assertSame('2025-11-03T00:00:00Z', $contract['evaluated_start']);
        $this->assertSame(['2025-11' => 4], $contract['evaluated_month_counts']);
        $this->assertTrue($service->attests($contract, [...$contract, 'complete' => true]));
        $this->assertFalse($service->attests($contract, [...$contract, 'evaluated_rows' => 2, 'complete' => true]));
        $this->assertFalse($service->attests($contract, [...$contract, 'complete' => false]));
    }

    public function test_paper_and_short_windows_fail_before_replay(): void
    {
        $service = app(ProspectiveRepairProbeWindowService::class);
        try {
            $service->seal($this->rows(), str_repeat('a', 64), str_repeat('b', 64), 'experiment-1', 5, 2);
            $this->fail('A short window was admitted.');
        } catch (RuntimeException $e) {
            $this->assertSame('PROSPECTIVE_PROBE_WINDOW_DATA_OR_IDENTITY_MISSING', $e->getMessage());
        }
        $rows = $this->rows();
        $rows[5]['time'] = '2026-01-01T00:00:00Z';
        $this->expectExceptionMessage('PROSPECTIVE_PROBE_WINDOW_NOT_CHRONOLOGICAL_PRE_PAPER');
        $service->seal($rows, str_repeat('a', 64), str_repeat('b', 64), 'experiment-1', 4, 2);
    }
}
