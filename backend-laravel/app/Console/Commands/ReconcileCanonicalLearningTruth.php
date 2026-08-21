<?php

namespace App\Console\Commands;

use App\Models\LabEvaluationRun;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Services\CanonicalLearningOutboxService;
use Illuminate\Console\Command;

/** Replays only sealed, exact-control historical evidence into the truth ledger. */
class ReconcileCanonicalLearningTruth extends Command
{
    protected $signature = 'trading:reconcile-canonical-learning {symbol?} {--timeframe=H1} {--limit=4} {--dry-run}';
    protected $description = 'Reconcile valid historical full replays into canonical settlements; legacy invalid rows remain diagnostic-only';

    public function handle(CanonicalLearningOutboxService $outbox): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $limit = max(1, min(100, (int) $this->option('limit')));
        $pairs = LabLearningLanePair::query()->with(['candidateAgent', 'controlResponseMap'])
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->whereIn('status', ['learning_observed', 'provisional', 'confirmed', 'canonical_failed'])
            ->oldest('id')->get();
        $valid = $pairs->filter(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair())->take($limit);
        $invalid = $pairs->reject(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair());
        if (! $this->option('dry-run')) {
            foreach ($invalid as $pair) {
                $pair->update(['status' => 'diagnostic_only', 'metadata' => [...((array) $pair->metadata), 'diagnostic_reason' => 'LEGACY_CONTROL_CONTRACT_MISSING', 'promotion_evidence' => false]]);
                LabLearningLaneDispatch::query()->where('pair_id', $pair->id)->where('status', 'completed')->update(['status' => 'diagnostic_only', 'completed_at' => null]);
            }
            // Historical dispatches may predate the pair ledger entirely.
            // They remain visible, but cannot retain a completed-learning
            // status without the frozen-control proof.
            LabLearningLaneDispatch::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('status', 'completed')->with('pair.controlResponseMap')->get()
                ->filter(fn (LabLearningLaneDispatch $dispatch): bool => ! $dispatch->pair || ! $dispatch->pair->isVerifiedControlPair())
                ->each(fn (LabLearningLaneDispatch $dispatch) => $dispatch->update(['status' => 'diagnostic_only', 'completed_at' => null, 'metadata' => [...((array) $dispatch->metadata), 'diagnostic_reason' => 'HISTORICAL_CONTROL_PROOF_MISSING', 'promotion_evidence' => false]]));
        }
        $reconciled = 0;
        foreach ($valid as $pair) {
            $run = LabEvaluationRun::query()->where('lab_agent_id', $pair->candidate_agent_id)
                ->where('phase', 'full_validation')->where('status', 'completed')->latest('id')->first();
            if (! $run) continue;
            if (! $this->option('dry-run')) {
                $result = [...((array) $run->metrics), 'evidence_run_id' => $run->run_id];
                $outbox->record($pair->candidateAgent, $pair, $result, count((array) $pair->candidateAgent?->parameter_diff) === 1, (array) $pair->target_delta);
            }
            $reconciled++;
        }
        $this->table(['valid_replayed', 'legacy_diagnostic_only', 'dry_run'], [[$reconciled, $invalid->count(), $this->option('dry-run') ? 'yes' : 'no']]);
        return self::SUCCESS;
    }
}
