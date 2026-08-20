<?php

namespace App\Services;

use App\Models\DualTrackOutcome;
use App\Models\DualTrackRun;
use App\Models\DualTrackSettlementState;
use App\Models\PaperSignalOutcome;
use Illuminate\Support\Facades\Schema;

/** Resumable state machine for settlement side effects. */
class DualTrackSettlementStateService
{
    public const PROTOCOL = 'dual_track_settlement_state_machine_v1';

    /** @return array{state:?DualTrackSettlementState, already_completed:bool} */
    public function begin(DualTrackRun $run, PaperSignalOutcome $outcome, DualTrackOutcome $anchor): array
    {
        if (! Schema::hasTable('dual_track_settlement_states')) return ['state' => null, 'already_completed' => false];
        $key = hash('sha256', self::PROTOCOL.'|'.$run->run_key.'|'.$outcome->id);
        $state = DualTrackSettlementState::query()->firstOrCreate(
            ['state_key' => $key],
            ['dual_track_run_id' => $run->id, 'paper_signal_outcome_id' => $outcome->id,
                'symbol' => $anchor->symbol, 'timeframe' => $anchor->timeframe, 'stage' => 'received',
                'attempts' => 0, 'completed_stages' => [], 'payload' => ['protocol' => self::PROTOCOL]],
        );
        if ($state->completed_at) return ['state' => $state, 'already_completed' => true];
        $state->update(['stage' => 'materializing', 'attempts' => (int) $state->attempts + 1, 'last_error' => null, 'last_attempted_at' => now()]);
        return ['state' => $state->fresh(), 'already_completed' => false];
    }

    public function completeStage(?DualTrackSettlementState $state, string $stage, array $payload = []): void
    {
        if (! $state) return;
        $completed = array_values(array_unique([...((array) $state->completed_stages), $stage]));
        $state->update(['stage' => $stage, 'completed_stages' => $completed, 'payload' => [...((array) $state->payload), $stage => $payload], 'last_error' => null]);
    }

    public function complete(?DualTrackSettlementState $state, array $payload = []): void
    {
        if (! $state) return;
        $this->completeStage($state, 'completed', $payload);
        $state->update(['completed_at' => now()]);
    }

    public function fail(?DualTrackSettlementState $state, \Throwable $error): void
    {
        if (! $state) return;
        $state->update(['stage' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 2000), 'last_attempted_at' => now()]);
    }
}
