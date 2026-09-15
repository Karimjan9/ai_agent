<?php

namespace App\Console\Commands;

use App\Models\CandidateHandoffEvent;
use App\Services\AutonomousModeService;
use App\Services\CandidateHandoffService;
use App\Services\FailureRepairAnchorService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningProtocolSafetyService;
use App\Services\TargetedRescueProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessTargetedGenerationRequests extends Command
{
    protected $signature = 'trading:process-targeted-generations';

    protected $description = 'Create one bounded targeted generation for each no-eligible-candidate handoff request';

    public function handle(LabPopulationService $populations, CandidateHandoffService $handoffs, TargetedRescueProfileService $profiles): int
    {
        if (! app(AutonomousModeService::class)->enabled('XAUUSD', 'H1')) {
            $this->info('Targeted generation builder deferred: autonomous mode is stopped; monitoring only.');

            return self::SUCCESS;
        }
        // Scheduler-level withoutOverlapping() does not protect a manual
        // invocation from racing the scheduler.  Both paths can otherwise
        // consume the same immutable handoff and create duplicate targeted
        // populations (G93/G94), wasting the bounded mutation budget.
        // The same 20-seat causal/history compiler used by normal generations
        // can legitimately run for tens of minutes. Keep manual and scheduled
        // invocations mutually exclusive for the full constructor budget.
        $lock = Cache::lock('trading:targeted-generation-builder:v1', 3000);
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
        // An archived/shadow laboratory may retain immutable handoffs, but it
        // has no authority to open a new population. This matters for XAUUSD:
        // its former M15 lab is now evidence-only and all live evolution is
        // owned by the single H1-storage lighthouse organism.
        foreach ($requests as $request) {
            $source = $request->generation;
            $lab = $source?->laboratory;
            if (! $source || ! $lab) {
                // A broken projection cannot be written to the immutable
                // generation evidence plane because its owner is gone, but it
                // also must not remain an immortal scheduler work item.
                $request->update([
                    'status' => 'blocked',
                    'terminal_reason' => 'SOURCE_GENERATION_MISSING',
                    'payload' => [
                        ...((array) $request->payload),
                        'closed_at' => now()->utc()->toIso8601String(),
                        'next_action' => 'technical_evidence_reconciliation',
                        'promotion_evidence' => false,
                    ],
                    'recorded_at' => now(),
                ]);

                continue;
            }
            if ($lab->is_active && (string) $lab->lifecycle_mode === 'lighthouse') {
                continue;
            }
            $this->closeRequest($handoffs, $request, 'superseded', 'SOURCE_LAB_ARCHIVED', [
                'next_action' => 'retain_as_historical_evidence_only',
            ]);
        }
        // A handoff is a single-consumer work projection, not a permanent
        // memory queue. Failure cases/repair anchors retain old lessons. Keep
        // only the newest live request for each laboratory and close every
        // older projection with an immutable supersession receipt. Previously
        // those rows stayed `waiting` forever and made the arbiter cycle over
        // 167 historical requests.
        $liveRequests = $requests
            ->filter(fn (CandidateHandoffEvent $request): bool => $request->generation?->laboratory?->is_active === true
                && (string) $request->generation?->laboratory?->lifecycle_mode === 'lighthouse')
            ->values();
        $requests = collect();
        foreach ($liveRequests->groupBy(fn (CandidateHandoffEvent $request): int => (int) $request->generation->ai_laboratory_id) as $labRequests) {
            /** @var CandidateHandoffEvent|null $newest */
            $newest = $labRequests->first();
            if (! $newest) {
                continue;
            }
            foreach ($labRequests->skip(1) as $stale) {
                $this->closeRequest($handoffs, $stale, 'superseded', 'NEWER_TARGETED_HANDOFF_SUPERSEDES_REQUEST', [
                    'superseded_by_event_id' => (int) $newest->id,
                    'superseded_by_source_generation_id' => (int) $newest->lab_generation_id,
                    'next_action' => 'lesson_retained_in_failure_memory',
                ]);
            }
            if ($newest->targetedGenerationRetryDue()) {
                $requests->push($newest);
            } else {
                $this->info("{$newest->generation->laboratory->symbol}: targeted handoff retry is deferred until ".data_get($newest->payload, 'targeted_retry.next_retry_at').'.');
            }
        }
        foreach ($requests as $request) {
            $source = $request->generation;
            $lab = $source?->laboratory;
            if (! $source || ! $lab) {
                continue;
            }
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
                && (in_array($latest->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)
                    || LabPopulationService::constructionIncomplete($latest))) {
                // A newer active generation still owns the laboratory stream.
                // Incomplete technical_quarantine is the dispatch-safe state
                // between constructor chunks and owns the stream as well.
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

                $this->closeRequest($handoffs, $request, 'blocked', 'TARGETED_GENERATION_BUDGET_EXHAUSTED', [
                    'baseline_generation' => $baseline,
                    'targeted_attempts' => $targetedAttempts,
                    'targeted_attempt_limit' => $targetedAttemptLimit,
                    'next_action' => 'data_edge_audit_required',
                ]);

                continue;
            }
            // Rebuild the profile from current immutable evidence instead of
            // trusting an old handoff projection. This is where gate-margin
            // ranking selects one dominant failure and its five-seat control
            // cohort; legacy profiles still resolve to the five-by-four path.
            $currentProfile = $profiles->forGeneration($source);
            if (! $this->profileIsActionable($currentProfile)) {
                $this->closeRequest($handoffs, $request, 'blocked', 'TARGETED_FAILURE_PROFILE_NOT_ACTIONABLE', [
                    'profile_hash' => data_get($currentProfile, 'profile_hash'),
                    'actionable_failure_count' => (int) data_get($currentProfile, 'actionable_failure_count', 0),
                    'technical_excluded_agent_ids' => (array) data_get($currentProfile, 'technical_excluded_agent_ids', []),
                    'next_action' => 'continue_causal_director_or_data_edge_audit',
                ]);
                $this->warn("{$lab->symbol}: targeted handoff closed because no evidence-backed repair target exists.");

                continue;
            }
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
                $this->closeRequest($handoffs, $request, 'completed', 'TARGETED_GENERATION_REQUEST_CONSUMED', [
                    'target_generation_id' => (int) $created->id,
                    'target_generation' => (int) $created->generation,
                    'consumption_receipt' => hash('sha256', implode('|', [
                        'targeted_generation_request_consumed_v1',
                        (int) $request->id,
                        (int) $source->id,
                        (int) $created->id,
                        (string) data_get($targetProfile, 'profile_hash', ''),
                    ])),
                    'next_action' => 'settle_target_generation',
                ]);
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $created->id, 'generation' => $created->generation,
                    'targeted_failure_profile' => $targetProfile,
                    'rule' => data_get($targetProfile, 'cohort_mode') === 'four_siblings_plus_control_v1'
                        ? 'Four one-gene siblings plus one freshly replayed frozen control are created from the nearest failure margin; no old screened candidate was force-replayed.'
                        : 'Five four-seat one-gene rescue groups are created from the immutable failure profile; no old screened candidate was force-replayed.']);
                $this->info("{$lab->symbol}: targeted G{$created->generation} created.");
            } else {
                $outcome = $populations->lastBuildOutcome();
                if ((bool) data_get($outcome, 'retryable', false)) {
                    $this->deferRetry($handoffs, $request, $outcome);
                    $this->warn("{$lab->symbol}: targeted generation retry deferred after ".data_get($outcome, 'reason_code', 'UNKNOWN_BUILD_BLOCK').'.');
                } else {
                    $reason = (string) data_get($outcome, 'reason_code', 'TARGETED_GENERATION_BUILD_BLOCKED');
                    $this->closeRequest($handoffs, $request, 'blocked', $reason, [
                        'build_outcome' => $outcome,
                        'next_action' => 'continue_causal_director_or_data_edge_audit',
                    ]);
                    $this->warn("{$lab->symbol}: targeted handoff terminally blocked by {$reason}; arbiter may select the next legal work.");
                }
            }
        }

        return self::SUCCESS;
    }

    private function profileIsActionable(array $profile): bool
    {
        if ((int) data_get($profile, 'actionable_failure_count', 0) > 0) {
            return true;
        }
        if (collect((array) data_get($profile, 'target_counts', []))->sum() > 0) {
            return true;
        }

        return filled(data_get($profile, 'selected_near_miss.agent_id'))
            && count((array) data_get($profile, 'targets', [])) > 0;
    }

    /** @param array<string, mixed> $extra */
    private function closeRequest(CandidateHandoffService $handoffs, CandidateHandoffEvent $request, string $status, string $reason, array $extra = []): void
    {
        $source = $request->generation;
        if (! $source) {
            return;
        }
        $handoffs->record($source, null, 'waiting_for_targeted_generation', $status, $reason, [
            ...((array) $request->payload),
            'source_terminal_reason' => (string) ($request->terminal_reason ?: data_get($request->payload, 'source_terminal_reason', '')),
            'request_lifecycle' => 'terminal',
            'closed_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
            ...$extra,
        ]);
    }

    /** @param array<string, mixed> $outcome */
    private function deferRetry(CandidateHandoffService $handoffs, CandidateHandoffEvent $request, array $outcome): void
    {
        $attempt = (int) data_get($request->payload, 'targeted_retry.attempt_count', 0) + 1;
        $reason = (string) data_get($outcome, 'reason_code', 'TARGETED_GENERATION_RETRYABLE_BLOCK');
        $minutes = match ($reason) {
            'GENERATION_CONSTRUCTOR_ACTIVE', 'LATEST_GENERATION_ACTIVE', 'LATEST_GENERATION_CONSTRUCTION_INCOMPLETE' => 5,
            'CAUSAL_REPAIR_FRONTIER_SOURCE_CHANGED', 'CAUSAL_LEARNING_CONFIRMATION_SOURCE_CHANGED' => 10,
            'MARKET_DATA_CONTINUITY_NOT_READY', 'HISTORICAL_DATA_NOT_READY', 'CANONICAL_DATA_CONTRACT_NOT_READY', 'INSUFFICIENT_FRESH_CANDLES' => 60,
            default => min(360, 15 * (2 ** min(4, max(0, $attempt - 1)))),
        };
        $handoffs->record($request->generation, null, 'waiting_for_targeted_generation', 'waiting', 'TARGETED_GENERATION_RETRY_DEFERRED', [
            ...((array) $request->payload),
            'source_terminal_reason' => (string) ($request->terminal_reason ?: data_get($request->payload, 'source_terminal_reason', '')),
            'targeted_retry' => [
                'protocol' => 'targeted_generation_retry_backoff_v1',
                'attempt_count' => $attempt,
                'last_attempt_at' => now()->utc()->toIso8601String(),
                'next_retry_at' => now()->utc()->addMinutes($minutes)->toIso8601String(),
                'backoff_minutes' => $minutes,
                'build_outcome' => $outcome,
                'promotion_evidence' => false,
            ],
            'next_action' => 'arbiter_may_run_other_work_until_retry_due',
            'promotion_evidence' => false,
        ]);
    }

    private function screeningBacklogIsHigh(): bool
    {
        $snapshot = app(LabQueueJobInspector::class)->queueSnapshot();
        if (($snapshot['available'] ?? true) === false) {
            return true;
        }
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
            if (! in_array($target, $canonical, true)) {
                continue;
            }
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
            'repair_anchor_protocol' => (string) data_get($profile, 'repair_anchor_protocol', FailureRepairAnchorService::PROTOCOL),
            'observed_profile' => $profile,
            'promotion_evidence' => false,
            'rule' => 'Five four-seat groups: PF/stress, temporal/calendar, regime specialist, non-target regression and architecture/control. Full/forward/paper gates remain unchanged.',
            'promotion_evidence' => false,
        ];
    }
}
