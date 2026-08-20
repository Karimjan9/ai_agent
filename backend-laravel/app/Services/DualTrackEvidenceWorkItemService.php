<?php

namespace App\Services;

use App\Models\DualTrackEvidenceWorkItem;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durable queue for evidence-producing work; every result remains fail-closed. */
class DualTrackEvidenceWorkItemService
{
    public const PROTOCOL = 'dual_track_evidence_work_queue_v1';

    public function enqueue(string $type, string $key, DualTrackRun $run, array $payload = [], int $priority = 5, ?DualTrackOutcome $outcome = null): array
    {
        if (! Schema::hasTable('dual_track_evidence_work_items')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $item = DualTrackEvidenceWorkItem::query()->firstOrCreate(
            ['work_key' => hash('sha256', self::PROTOCOL.'|'.$type.'|'.$key)],
            ['dual_track_run_id' => $run->id, 'dual_track_outcome_id' => $outcome?->id, 'symbol' => $run->symbol, 'timeframe' => $run->timeframe, 'cell_key' => $run->cell_key, 'work_type' => $type, 'status' => 'queued', 'priority' => max(1, min(9, $priority)), 'payload' => ['protocol' => self::PROTOCOL, ...$payload], 'available_at' => now()],
        );
        return ['status' => $item->status, 'work_id' => $item->id, 'work_key' => $item->work_key, 'promotion_evidence' => false];
    }

    /** Claim a bounded batch with row locks so two workers cannot duplicate an item. */
    public function claim(int $limit = 10): array
    {
        if (! Schema::hasTable('dual_track_evidence_work_items')) return [];
        return DB::transaction(function () use ($limit): array {
            $rows = DualTrackEvidenceWorkItem::query()->whereIn('status', ['queued', 'retry'])
                ->where(function ($query): void { $query->whereNull('available_at')->orWhere('available_at', '<=', now()); })
                ->orderByDesc('priority')->orderBy('id')->lockForUpdate()->limit(max(1, min(50, $limit)))->get();
            foreach ($rows as $row) $row->update(['status' => 'processing', 'attempts' => (int) $row->attempts + 1, 'leased_at' => now()]);
            return $rows->all();
        });
    }

    public function complete(DualTrackEvidenceWorkItem $item, array $result): void
    {
        $item->update(['status' => 'completed', 'result' => ['protocol' => self::PROTOCOL, ...$result], 'completed_at' => now(), 'leased_at' => null]);
    }

    public function retry(DualTrackEvidenceWorkItem $item, string $error): void
    {
        $attempts = (int) $item->attempts;
        if ($attempts >= 3) {
            $item->update(['status' => 'blocked', 'last_error' => $error, 'leased_at' => null]);
            return;
        }
        $item->update(['status' => 'retry', 'last_error' => $error, 'available_at' => now()->addMinutes(max(1, $attempts)), 'leased_at' => null]);
    }

    public function defer(DualTrackEvidenceWorkItem $item, string $reason): void
    {
        // Readiness deferral is not a failed replay and must not consume the
        // bounded technical retry budget.
        $item->update(['status' => 'queued', 'last_error' => $reason, 'available_at' => now()->addMinutes(30), 'leased_at' => null]);
    }
}
