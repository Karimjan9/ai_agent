<?php

namespace App\Services;

use App\Models\DualTrackEvidenceWorkItem;
use App\Models\DualTrackOutcome;
use App\Models\DualTrackRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable queue for evidence-producing work; every result remains fail-closed. */
class DualTrackEvidenceWorkItemService
{
    public const PROTOCOL = 'dual_track_evidence_work_queue_v1';
    private const LEASE_SECONDS = 900;

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
            // A dead worker never owns work forever. Its token is discarded
            // before a new attempt is leased, so stale completion is fenced.
            DualTrackEvidenceWorkItem::query()->where('status', 'processing')
                ->where('lease_expires_at', '<=', now())
                ->update(['status' => 'retry', 'last_error' => 'LEASE_EXPIRED', 'available_at' => now(),
                    'leased_at' => null, 'lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => null,
                    'updated_at' => now()]);
            $rows = DualTrackEvidenceWorkItem::query()->whereIn('status', ['queued', 'retry'])
                ->where(function ($query): void { $query->whereNull('available_at')->orWhere('available_at', '<=', now()); })
                ->orderByDesc('priority')->orderBy('id')->lockForUpdate()->limit(max(1, min(50, $limit)))->get();
            foreach ($rows as $row) {
                $token = (string) Str::uuid();
                $lease = now();
                $values = ['status' => 'processing', 'attempts' => (int) $row->attempts + 1,
                    'leased_at' => $lease, 'lease_token' => $token,
                    'fence_version' => (int) $row->fence_version + 1,
                    'lease_expires_at' => $lease->copy()->addSeconds(self::LEASE_SECONDS),
                    'heartbeat_at' => $lease];
                $row->update($values);
                $row->forceFill($values);
            }
            return $rows->all();
        });
    }

    /** Returns false when the worker's lease has been superseded. */
    public function complete(DualTrackEvidenceWorkItem $item, array $result): bool
    {
        return $this->transition($item, ['status' => 'completed', 'result' => ['protocol' => self::PROTOCOL, ...$result], 'completed_at' => now(), 'leased_at' => null]);
    }

    public function retry(DualTrackEvidenceWorkItem $item, string $error): bool
    {
        $attempts = (int) $item->attempts;
        if ($attempts >= 3) {
            return $this->transition($item, ['status' => 'blocked', 'last_error' => $error, 'leased_at' => null]);
        }
        return $this->transition($item, ['status' => 'retry', 'last_error' => $error, 'available_at' => now()->addMinutes(max(1, $attempts)), 'leased_at' => null]);
    }

    public function defer(DualTrackEvidenceWorkItem $item, string $reason): bool
    {
        // Readiness deferral is not a failed replay and must not consume the
        // bounded technical retry budget.
        return $this->transition($item, ['status' => 'queued', 'last_error' => $reason, 'available_at' => now()->addMinutes(30), 'leased_at' => null]);
    }

    public function heartbeat(DualTrackEvidenceWorkItem $item): bool
    {
        return $this->transition($item, ['heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS)], false);
    }

    private function transition(DualTrackEvidenceWorkItem $item, array $values, bool $release = true): bool
    {
        $query = DualTrackEvidenceWorkItem::query()->whereKey($item->id)->where('status', 'processing')
            ->where('lease_token', $item->lease_token)->where('fence_version', (int) $item->fence_version);
        if ($release) {
            $values = [...$values, 'lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => null];
        }
        $updated = $query->update($values) === 1;
        if ($updated) $item->forceFill($values);
        return $updated;
    }
}
