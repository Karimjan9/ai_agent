<?php

namespace Tests\Feature;

use App\Models\DualTrackRun;
use App\Services\DualTrackEvidenceWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DualTrackEvidenceWorkItemServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_lease_is_reclaimed_and_stale_worker_is_fenced(): void
    {
        $run = DualTrackRun::query()->create([
            'run_key' => 'lease-fence-run', 'protocol' => 'test', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'task_type' => 'test', 'cell_key' => 'lease-fence-cell', 'input_hash' => 'input', 'output_hash' => 'output',
        ]);
        $service = app(DualTrackEvidenceWorkItemService::class);
        $service->enqueue('red_team', 'lease-fence-work', $run);
        $first = $service->claim(1)[0];
        $first->update(['lease_expires_at' => now()->subSecond()]);

        $second = $service->claim(1)[0];

        $this->assertNotSame($first->lease_token, $second->lease_token);
        $this->assertFalse($service->complete($first, ['status' => 'stale']));
        $this->assertTrue($service->complete($second, ['status' => 'completed']));
        $this->assertDatabaseHas('dual_track_evidence_work_items', ['id' => $second->id, 'status' => 'completed']);
    }
}
