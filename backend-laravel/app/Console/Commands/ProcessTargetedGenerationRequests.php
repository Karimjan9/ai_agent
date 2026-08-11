<?php

namespace App\Console\Commands;

use App\Models\CandidateHandoffEvent;
use App\Services\CandidateHandoffService;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProcessTargetedGenerationRequests extends Command
{
    protected $signature = 'trading:process-targeted-generations';
    protected $description = 'Create one bounded targeted generation for each no-eligible-candidate handoff request';

    public function handle(LabPopulationService $populations, CandidateHandoffService $handoffs): int
    {
        // Scheduler-level withoutOverlapping() does not protect a manual
        // invocation from racing the scheduler.  Both paths can otherwise
        // consume the same immutable handoff and create duplicate targeted
        // populations (G93/G94), wasting the bounded mutation budget.
        $lock = Cache::lock('trading:targeted-generation-builder:v1', 300);
        if (! $lock->get()) {
            $this->info('Targeted generation builder already active; this invocation is safely deferred.');
            return self::SUCCESS;
        }

        try {
            return $this->buildTargetedGenerations($populations, $handoffs);
        } finally {
            optional($lock)->release();
        }
    }

    private function buildTargetedGenerations(LabPopulationService $populations, CandidateHandoffService $handoffs): int
    {
        $requests = CandidateHandoffEvent::query()->with('generation.laboratory')
            ->where('stage', 'waiting_for_targeted_generation')->where('status', 'waiting')->get();
        foreach ($requests as $request) {
            $source = $request->generation; $lab = $source?->laboratory;
            if (! $source || ! $lab) continue;
            if ($this->screeningBacklogIsHigh()) {
                $this->warn("{$lab->symbol}: lab queue backlog is high; targeted generation creation deferred.");
                continue;
            }
            $latest = $lab->generations()->latest('generation')->first();
            $latestIsAbandonedStaleProtocol = $latest
                && $latest->status === 'abandoned'
                && $latest->trigger_type === 'candidate_handoff'
                && data_get($latest->trigger_context, 'generation_protocol') !== LabPopulationService::GENERATION_PROTOCOL;
            if ($latest && $latest->id !== $source->id
                && ! $latestIsAbandonedStaleProtocol
                && in_array($latest->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)) {
                // A newer active generation still owns the laboratory stream.
                // Keep the original failure profile waiting instead of
                // marking it consumed; it can seed the next legal targeted
                // cohort after the active frontier reaches a terminal state.
                $this->info("{$lab->symbol}: active G{$latest->generation} owns the lab; targeted handoff remains waiting.");
                continue;
            }
            $baseline = $lab->generations()->where('trigger_type', '!=', 'candidate_handoff')->max('generation');
            $targeted = $lab->generations()->where('trigger_type', 'candidate_handoff')
                // Abandoned populations produced no screening evidence and
                // must not consume the bounded targeted-generation budget.
                // This includes a duplicate population quarantined after a
                // manual/scheduler race.
                ->where('status', '!=', 'abandoned')
                ->when($baseline !== null, fn ($query) => $query->where('generation', '>', $baseline))->get();
            $targetedAttempts = $targeted->filter(fn ($generation) => data_get($generation->trigger_context, 'generation_protocol') === LabPopulationService::GENERATION_PROTOCOL)->count();
            $budgetAlreadyClosed = CandidateHandoffEvent::query()->where('lab_generation_id', $source->id)
                ->where('stage', 'targeted_generation_budget_exhausted')->get()
                ->contains(fn ($event) => data_get($event->payload, 'protocol_version') === LabPopulationService::GENERATION_PROTOCOL)
                && $targetedAttempts >= 2;
            if ($targetedAttempts >= 2 || $budgetAlreadyClosed) {
                if (! $budgetAlreadyClosed) {
                    $handoffs->record($source, null, 'targeted_generation_budget_exhausted', 'blocked', 'TARGETED_GENERATION_BUDGET_EXHAUSTED', [
                        'baseline_generation' => $baseline, 'targeted_attempts' => $targetedAttempts,
                        'protocol_version' => LabPopulationService::GENERATION_PROTOCOL,
                        'rule' => 'At most two targeted populations may follow one baseline without a new validated signal; require a data/edge audit before any further mutation.',
                        'next_action' => 'data_edge_audit_required',
                    ]);
                    $this->warn("{$lab->symbol}: targeted generation budget exhausted; data/edge audit required before further mutation.");
                }
                continue;
            }
            $profile = (array) data_get($request->payload, 'screening_failure_profile', data_get($request->payload, 'forward_failure_profile', []));
            $targetProfile = $this->targetProfile($source, $request, $profile);
            $created = $populations->build(
                $lab->symbol,
                'candidate_handoff',
                false,
                $lab->timeframe,
                [],
                false,
                true,
                4,
                $targetProfile,
            );
            if ($created) {
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $created->id, 'generation' => $created->generation,
                    'targeted_failure_profile' => $targetProfile,
                    'rule' => 'Four one-gene failure targets are created from the immutable Gen3/forward failure profile; no old screened candidate was force-replayed.']);
                $this->info("{$lab->symbol}: targeted G{$created->generation} created.");
            } else $this->warn("{$lab->symbol}: targeted generation remains waiting for market-data readiness.");
        }
        return self::SUCCESS;
    }

    private function screeningBacklogIsHigh(): bool
    {
        if (! Schema::hasTable('jobs')) return false;

        $queues = array_values(array_unique(array_merge(
            [
                (string) config('services.lab_queue.screening_queue', 'lab-screening'),
                (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
                'lab-full-validation',
            ],
            (array) config('services.lab_queue.legacy_screening_queues', []),
        )));
        $pending = (int) DB::table('jobs')->whereIn('queue', $queues)->count();

        return $pending >= max(1, (int) config('services.lab_selection.max_screening_jobs', 40));
    }

    /** @return array<string, mixed> */
    private function targetProfile($source, CandidateHandoffEvent $request, array $profile): array
    {
        $canonical = ['profit_factor', 'stress_cost', 'temporal_stability', 'regime_coverage'];
        $targetCounts = [];
        foreach ((array) data_get($profile, 'targets', []) as $reason => $row) {
            $target = is_array($row) ? (string) data_get($row, 'target', '') : (string) $row;
            if (! in_array($target, $canonical, true)) continue;
            $targetCounts[$target] = ($targetCounts[$target] ?? 0) + max(1, (int) (is_array($row) ? data_get($row, 'count', 1) : 1));
        }
        $targets = collect($canonical)
            ->sortByDesc(fn (string $target): array => [
                (int) ($targetCounts[$target] ?? 0),
                -array_search($target, $canonical, true),
            ])
            ->values()->all();

        return [
            'protocol' => 'targeted_failure_profile_v1',
            'source_generation_id' => $source->id,
            'source_generation' => $source->generation,
            'profile_hash' => (string) data_get($request->payload, 'handoff_profile_hash', hash('sha256', json_encode($profile))),
            'target_counts' => $targetCounts,
            'targets' => $targets,
            'observed_profile' => $profile,
            'promotion_evidence' => false,
            'rule' => 'One bounded mutation target per seat: profit factor, stress cost, temporal stability and regime coverage. Full/forward/paper gates remain unchanged.',
        ];
    }
}
