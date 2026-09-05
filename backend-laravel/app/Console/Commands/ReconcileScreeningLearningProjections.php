<?php

namespace App\Console\Commands;

use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabMutationResponseMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuilds a lost screening-learning outbox dispatch from canonical evidence.
 *
 * Redis/worker loss must not leave a completed frozen control without its
 * response map: candidates would then wait forever despite a valid replay.
 * This command never replays data and never grants promotion authority. It
 * only re-dispatches the idempotent projection against the already-completed
 * immutable run, exact gate decision and stored screen projection.
 */
class ReconcileScreeningLearningProjections extends Command
{
    protected $signature = 'trading:reconcile-screening-learning-projections
        {--generation= : Restrict to one generation number}
        {--limit=10 : Maximum missing projections to dispatch}
        {--scheduled-sweep : Restrict selection to recent active generations}
        {--apply : Dispatch the idempotent learning projection}
        {--json}';

    protected $description = 'Recover lost screening-learning projection jobs without replaying or creating quality evidence';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, min(50, (int) $this->option('limit')));
        $generation = $this->option('generation') !== null ? (int) $this->option('generation') : null;
        $scheduledSweep = (bool) $this->option('scheduled-sweep');
        $lock = $apply ? Cache::lock('trading:reconcile-screening-learning-projections:v1', 240) : null;
        if ($lock !== null && ! $lock->get()) {
            $this->line('Screening-learning projection reconciliation is already active.');

            return self::SUCCESS;
        }

        try {
            $runs = LabEvaluationRun::query()
                ->with(['agent.modelVersion', 'generation'])
                ->where('phase', 'screening')
                ->where('status', 'completed')
                ->whereNotNull('lab_agent_id')
                ->when($generation !== null, fn ($query) => $query->whereHas(
                    'generation',
                    fn ($generationQuery) => $generationQuery->where('generation', $generation),
                ))
                ->when($scheduledSweep, fn ($query) => $query->whereHas(
                    'generation',
                    fn ($generationQuery) => $generationQuery
                        ->where('created_at', '>=', now()->subDays(2))
                        ->whereIn('status', ['screening', 'screened', 'full_validation']),
                ))
                ->latest('id')
                ->limit($limit * 4)
                ->get()
                ->unique('lab_agent_id');

            $rows = [];
            foreach ($runs as $run) {
                if (count($rows) >= $limit) break;

                $agent = $run->agent;
                $projection = (array) data_get($agent?->modelVersion?->metadata, 'last_screen_result', []);
                $decisionId = (int) data_get($run->metadata, 'screen_decision_id', 0);
                $decision = $decisionId > 0
                    ? CandidateGateDecision::query()->whereKey($decisionId)->where('lab_agent_id', $agent?->id)->first()
                    : CandidateGateDecision::query()->where('lab_agent_id', $agent?->id)
                        ->where('stage', 'screening')->latest('id')->first();

                if (! $agent || ! $agent->modelVersion || $projection === [] || ! $decision) continue;
                if (LabMutationResponseMap::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('stage', 'screening')
                    ->where('evidence_run_id', $run->run_id)
                    ->exists()) continue;

                $row = [
                    'agent_id' => (int) $agent->id,
                    'generation_id' => (int) $agent->lab_generation_id,
                    'run_id' => (string) $run->run_id,
                    'decision_id' => (int) $decision->id,
                    'action' => $apply ? 'projection_dispatched' : 'would_dispatch_projection',
                    'promotion_evidence' => false,
                ];
                if ($apply) {
                    ProcessLabScreeningLearningProjection::dispatch(
                        (int) $agent->id,
                        (string) $run->run_id,
                        (int) $decision->id,
                        [...$projection, 'evidence_run_id' => (string) $run->run_id],
                    );
                }
                $rows[] = $row;
            }

            $result = [
                'protocol' => 'screening_learning_projection_reconciliation_v1',
                'apply' => $apply,
                'selected' => count($rows),
                'rows' => $rows,
                'replay_dispatched' => 0,
                'promotion_evidence' => false,
            ];
            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info(sprintf(
                    '%d missing screening-learning projection(s) %s; no replay or promotion evidence was created.',
                    count($rows),
                    $apply ? 'dispatched' : 'found',
                ));
            }

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }
}
