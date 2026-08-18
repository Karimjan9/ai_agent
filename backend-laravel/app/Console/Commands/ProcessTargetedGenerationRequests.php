<?php

namespace App\Console\Commands;

use App\Models\CandidateHandoffEvent;
use App\Services\CandidateHandoffService;
use App\Services\LearningProtocolSafetyService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\TargetedRescueProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProcessTargetedGenerationRequests extends Command
{
    protected $signature = 'trading:process-targeted-generations';
    protected $description = 'Create one bounded targeted generation for each no-eligible-candidate handoff request';

    public function handle(LabPopulationService $populations, CandidateHandoffService $handoffs, TargetedRescueProfileService $profiles): int
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
            // A protocol freeze admits only the explicit controlled-rescue
            // command. Do not let the five-minute scheduler create a normal
            // targeted generation during the short interval between an
            // operator resume and the safety monitor restoring the freeze.
            // This keeps every v2 cohort tied to its auditable admission
            // event and prevents another partial draft from being produced.
            if (app(LearningProtocolSafetyService::class)->generationCreationPaused()) {
                $this->info('Targeted generation builder deferred: learning protocol is paused; controlled rescue admission is required.');
                return self::SUCCESS;
            }

            return $this->buildTargetedGenerations($populations, $handoffs, $profiles);
        } finally {
            optional($lock)->release();
        }
    }

    private function buildTargetedGenerations(LabPopulationService $populations, CandidateHandoffService $handoffs, TargetedRescueProfileService $profiles): int
    {
        $requests = CandidateHandoffEvent::query()->with('generation.laboratory')
            ->where('stage', 'waiting_for_targeted_generation')->where('status', 'waiting')
            // Several historical failure profiles can wait for the same lab
            // while a newer data-edge generation is being screened. Consume
            // the newest frontier first; otherwise an old G1/G2 profile can
            // claim the next targeted budget before the current G3/G4 edge
            // curriculum is even considered.
            ->orderByDesc('recorded_at')->orderByDesc('id')->get();
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
            $profile = (array) data_get($request->payload, 'screening_failure_profile', data_get($request->payload, 'forward_failure_profile', []));
            $repairAnchorCount = count((array) data_get($profile, 'repair_anchors', []));
            // Ordinary no-candidate rescues retain the strict two-generation
            // budget. A failure-anchor curriculum gets one additional clean
            // attempt so its three-cohort escape rule can be observed, but it
            // still cannot open an unbounded mutation stream.
            $targetedAttemptLimit = $repairAnchorCount > 0 ? 3 : 2;
            $budgetAlreadyClosed = CandidateHandoffEvent::query()->where('lab_generation_id', $source->id)
                ->where('stage', 'targeted_generation_budget_exhausted')->get()
                ->contains(fn ($event) => data_get($event->payload, 'protocol_version') === LabPopulationService::GENERATION_PROTOCOL)
                && $targetedAttempts >= $targetedAttemptLimit;
            if ($targetedAttempts >= $targetedAttemptLimit || $budgetAlreadyClosed) {
                if (! $budgetAlreadyClosed) {
                    $handoffs->record($source, null, 'targeted_generation_budget_exhausted', 'blocked', 'TARGETED_GENERATION_BUDGET_EXHAUSTED', [
                        'baseline_generation' => $baseline, 'targeted_attempts' => $targetedAttempts,
                        'protocol_version' => LabPopulationService::GENERATION_PROTOCOL,
                        'rule' => "At most {$targetedAttemptLimit} targeted populations may follow one baseline without a new validated signal; require a data/edge audit before any further mutation.",
                        'next_action' => 'data_edge_audit_required',
                    ]);
                    $this->warn("{$lab->symbol}: targeted generation budget exhausted; data/edge audit required before further mutation.");
                }
                continue;
            }
            // Rebuild the profile from current immutable evidence instead of
            // trusting an old handoff projection. This is where gate-margin
            // ranking selects one dominant failure and its five-seat control
            // cohort; legacy profiles still resolve to the five-by-four path.
            $currentProfile = $profiles->forGeneration($source);
            $targetProfile = $this->targetProfile($source, $request, $currentProfile);
            $populationSize = max(1, (int) data_get($targetProfile, 'population_size', 20));
            $created = $populations->build(
                $lab->symbol,
                'candidate_handoff',
                false,
                $lab->timeframe,
                [],
                false,
                true,
                $populationSize,
                $targetProfile,
            );
            if ($created) {
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $created->id, 'generation' => $created->generation,
                    'targeted_failure_profile' => $targetProfile,
                    'rule' => data_get($targetProfile, 'cohort_mode') === 'four_siblings_plus_control_v1'
                        ? 'Four one-gene siblings plus one freshly replayed frozen control are created from the nearest failure margin; no old screened candidate was force-replayed.'
                        : 'Five four-seat one-gene rescue groups are created from the immutable failure profile; no old screened candidate was force-replayed.']);
                $this->info("{$lab->symbol}: targeted G{$created->generation} created.");
            } else $this->warn("{$lab->symbol}: targeted generation remains waiting for market-data readiness.");
        }
        return self::SUCCESS;
    }

    private function screeningBacklogIsHigh(): bool
    {
        $snapshot = app(LabQueueJobInspector::class)->queueSnapshot();
        if (($snapshot['available'] ?? true) === false) return true;
        $pending = (int) ($snapshot['total'] ?? 0);

        return $pending >= max(1, (int) config('services.lab_selection.max_screening_jobs', 40));
    }

    /** @return array<string, mixed> */
    private function targetProfile($source, CandidateHandoffEvent $request, array $profile): array
    {
        $canonical = ['profit_factor', 'stress_cost', 'temporal_stability', 'regime_coverage'];
        $special = data_get($profile, 'cohort_mode') === 'four_siblings_plus_control_v1';
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

        if ($special) {
            return [
                ...$profile,
                'profile_hash' => (string) data_get($profile, 'profile_hash', data_get($request->payload, 'handoff_profile_hash', '')),
                'source_generation_id' => $source->id,
                'source_generation' => $source->generation,
                'promotion_evidence' => false,
            ];
        }

        return [
            'protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'population_size' => count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS,
            'group_plan' => LabPopulationService::TARGETED_RESCUE_GROUP_PLAN,
            'source_generation_id' => $source->id,
            'source_generation' => $source->generation,
            'profile_hash' => (string) data_get($request->payload, 'handoff_profile_hash', hash('sha256', json_encode($profile))),
            'target_counts' => $targetCounts,
            'targets' => $targets,
            'repair_anchors' => collect((array) data_get($profile, 'repair_anchors', []))
                ->filter(fn (mixed $anchor): bool => is_array($anchor) && filled(data_get($anchor, 'id')))
                ->values()->all(),
            'repair_anchor_protocol' => (string) data_get($profile, 'repair_anchor_protocol', \App\Services\FailureRepairAnchorService::PROTOCOL),
            'observed_profile' => $profile,
            'promotion_evidence' => false,
            'rule' => 'Five four-seat groups: PF/stress, temporal/calendar, regime specialist, non-target regression and architecture/control. Full/forward/paper gates remain unchanged.',
            'promotion_evidence' => false,
        ];
    }
}
