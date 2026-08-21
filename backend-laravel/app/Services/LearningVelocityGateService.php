<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabFailureDojoRun;
use App\Models\LearningRecoveryEvent;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabMutationResponseMap;
use App\Models\LabLearningLanePair;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only backpressure for the research generator.
 *
 * A screen pass is a learning promise, not a promotion result. This gate
 * prevents generic cohorts from multiplying while that promise has no full
 * replay/forward observation. Explicit recovery, audit, rescue and council
 * handoff commands remain available because they repair or consume the
 * backlog rather than hiding it.
 */
class LearningVelocityGateService
{
    public const PROTOCOL = 'learning_velocity_gate_v1';

    /**
     * Bounded monitor projection. It intentionally avoids modelVersion
     * hydration, per-agent joins and PHP-side recovery analysis. The detailed
     * inspect() method remains available for forensic audits via --full.
     *
     * @return array<string, mixed>
     */
    public function summary(string|AiLaboratory $labOrSymbol, ?string $timeframe = null): array
    {
        $lab = $labOrSymbol instanceof AiLaboratory
            ? $labOrSymbol
            : AiLaboratory::query()->where('symbol', strtoupper($labOrSymbol))
                ->where('timeframe', strtoupper((string) $timeframe))->first();
        $symbol = strtoupper((string) ($lab?->symbol ?: ($labOrSymbol instanceof AiLaboratory ? '' : $labOrSymbol)));
        $tf = strtoupper((string) ($lab?->timeframe ?: $timeframe));
        $key = "learning-velocity:summary:{$symbol}:{$tf}";

        return Cache::remember($key, now()->addSeconds(15), function () use ($lab, $symbol, $tf): array {
            $base = [
                'protocol' => self::PROTOCOL,
                'symbol' => $symbol,
                'timeframe' => $tf,
                'monitor_mode' => 'lightweight_summary',
                'enabled' => (bool) config('services.lab_selection.learning_velocity_enabled', true),
                'promotion_evidence' => false,
            ];
            if (! $lab || ! Schema::hasTable('lab_generations')) {
                return [...$base, 'status' => 'no_history', 'allowed' => true, 'observations' => [], 'cached_for_seconds' => 15];
            }

            $lookback = max(1, (int) config('services.lab_selection.learning_velocity_lookback_generations', 3));
            $generations = $lab->generations()->select(['id', 'generation', 'status'])
                ->whereIn('status', ['screened', 'completed', 'technical_quarantine', 'abandoned', 'failed'])
                ->latest('generation')->limit($lookback)->get();
            $generationIds = $generations->pluck('id')->all();
            $agents = $generationIds === [] ? collect() : LabAgent::query()
                ->select(['id', 'lab_generation_id', 'lifecycle_status'])
                ->whereIn('lab_generation_id', $generationIds)->get();
            $agentIds = $agents->pluck('id')->all();
            $screenPasses = collect();
            if ($agentIds !== [] && Schema::hasTable('candidate_gate_decisions')) {
                $screenPasses = CandidateGateDecision::query()->select(['lab_agent_id', 'decision'])
                    ->whereIn('lab_agent_id', $agentIds)->where('stage', 'screening')
                    ->where('decision', 'passed')->get()->groupBy('lab_agent_id');
            }
            $observations = $generations->map(function ($generation) use ($agents, $screenPasses): array {
                $rows = $agents->where('lab_generation_id', $generation->id);
                $passed = $rows->filter(fn ($agent): bool => $screenPasses->has($agent->id))->count();
                $full = $this->canonicalProgressCount($rows->pluck('id'));
                $active = $rows->whereIn('lifecycle_status', ['queued', 'screening', 'training', 'full_queued', 'full_validation'])->count();
                return [
                    'generation' => (int) $generation->generation,
                    'generation_id' => (int) $generation->id,
                    'status' => (string) $generation->status,
                    'agent_count' => $rows->count(),
                    'screen_passes' => $passed,
                    'full_replay_or_forward_progress' => $full,
                    'active_learning_agents' => $active,
                    'unresolved_screen_pass' => $passed > 0 && $full === 0,
                ];
            })->values()->all();
            $unresolved = collect($observations)->where('unresolved_screen_pass', true)->count();
            $active = collect($observations)->sum('active_learning_agents');
            $technical = collect($observations)->where('status', 'technical_quarantine')->count();
            $starvation = $this->starvationSummary($symbol, $tf);
            $noEvidenceGenerations = collect($observations)->filter(fn (array $row): bool => (int) ($row['agent_count'] ?? 0) > 0
                && (string) ($row['status'] ?? '') !== 'technical_quarantine'
                && (int) ($row['screen_passes'] ?? 0) === 0
                && (int) ($row['full_replay_or_forward_progress'] ?? 0) === 0)->count();
            if ($noEvidenceGenerations > 0) {
                $starvation['starved'] = true;
                $starvation['no_learning_evidence_generations'] = $noEvidenceGenerations;
                $starvation['recovery_required'] = true;
                $starvation['reason'] = 'NO_LEARNING_EVIDENCE_IN_RECENT_GENERATIONS';
            }
            $allowed = $active === 0
                && ! (bool) data_get($starvation, 'starved', false)
                && $unresolved < max(1, (int) config('services.lab_selection.learning_velocity_max_unresolved_screen_generations', 1));
            $truth = $this->truthScoreboard($symbol, $tf);
            $broken = (int) ($truth['false_green_count'] ?? 0) > 0
                || ((int) ($truth['verified_pair_count'] ?? 0) > 0 && (int) ($truth['settlement_count'] ?? 0) === 0)
                || ((int) ($truth['evaluation_completed'] ?? 0) > 0 && (int) ($truth['canonical_episode_count'] ?? 0) === 0);

            return [
                ...$base,
                'allowed' => $allowed,
                'status' => $broken ? 'learning_broken' : ((bool) data_get($starvation, 'starved', false)
                    ? 'learning_starvation'
                    : ($active > 0 ? 'learning_in_progress' : ($unresolved > 0 ? 'blocked_learning_backlog' : ($technical > 0 ? 'technical_history_quarantined' : 'healthy')))),
                'technical_quarantine_generations' => $technical,
                'unresolved_screen_generations' => $unresolved,
                'active_learning_agents' => $active,
                'lookback_generations' => $lookback,
                'learning_starvation' => $starvation,
                'learning_truth' => $truth,
                'observations' => $observations,
                'cached_for_seconds' => 15,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function inspect(string|AiLaboratory $labOrSymbol, ?string $timeframe = null): array
    {
        $lab = $labOrSymbol instanceof AiLaboratory
            ? $labOrSymbol
            : AiLaboratory::query()
                ->where('symbol', strtoupper($labOrSymbol))
                ->where('timeframe', strtoupper((string) $timeframe))
                ->first();

        $symbol = strtoupper((string) ($lab?->symbol ?: ($labOrSymbol instanceof AiLaboratory ? '' : $labOrSymbol)));
        $tf = strtoupper((string) ($lab?->timeframe ?: $timeframe));
        $base = [
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            'timeframe' => $tf,
            'enabled' => (bool) config('services.lab_selection.learning_velocity_enabled', true),
            'safe_sandbox' => [
                'protocol' => 'evidence_quarantine_sandbox_v1',
                'enabled' => (bool) config('services.lab_selection.evidence_quarantine_sandbox_enabled', true),
                'allowed_activities' => ['architecture_probe', 'volume_probe', 'parent_interaction_probe', 'ablation_planning'],
                'cached_snapshot_only' => true,
                'mutation_credit' => false,
                'parent_promotion' => false,
                'official_evidence' => false,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];

        if (! (bool) data_get($base, 'enabled', true)) {
            return [
                ...$base,
                'allowed' => true,
                'status' => 'disabled',
                'evolution_mode' => 'uncertainty',
                'reason_codes' => [],
                'next_action' => 'normal_generation_policy',
                'unresolved_screen_generations' => 0,
                'observations' => [],
            ];
        }

        if (! $lab || ! Schema::hasTable('lab_generations')) {
            return [
                ...$base,
                'allowed' => true,
                'status' => 'no_history',
                'evolution_mode' => 'uncertainty',
                'reason_codes' => [],
                'next_action' => 'collect_first_screening_evidence',
                'unresolved_screen_generations' => 0,
                'observations' => [],
            ];
        }

        $lookback = max(1, (int) config('services.lab_selection.learning_velocity_lookback_generations', 3));
        $generations = $lab->generations()
            ->whereIn('status', ['screened', 'completed', 'technical_quarantine', 'abandoned', 'failed'])
            ->latest('generation')
            ->limit($lookback)
            ->get();
        $observations = [];
        $unresolved = 0;
        $technicalRecovery = 0;
        $activeLearning = 0;

        foreach ($generations as $generation) {
            $agents = $generation->agents()->with(['modelVersion', 'generation'])->get();
            $agentIds = $agents->pluck('id')->filter()->values();
            $screen = $agentIds->isEmpty() || ! Schema::hasTable('candidate_gate_decisions')
                ? collect()
                : CandidateGateDecision::query()
                    ->whereIn('lab_agent_id', $agentIds)
                    ->where('stage', 'screening')
                    ->get();
            $screenPasses = $screen->where('decision', 'passed')->count();
            $technical = $agents
                ->filter(fn (LabAgent $agent): bool => $this->requiresTechnicalRecovery($agent))
                ->count();
            $fullProgress = $this->canonicalProgressCount($agentIds);
            $active = $agents->whereIn('lifecycle_status', [
                'queued', 'screening', 'training', 'full_queued', 'full_validation',
            ])->count();
            if ($active > 0) $activeLearning += $active;
            if ($technical > 0 && $screenPasses === 0 && $fullProgress === 0) $technicalRecovery += $technical;
            $isUnresolved = $screenPasses > 0 && $fullProgress === 0;
            if ($isUnresolved) $unresolved++;
            $observations[] = [
                'generation' => (int) $generation->generation,
                'generation_id' => (int) $generation->id,
                'status' => (string) $generation->status,
                'agent_count' => $agents->count(),
                'screen_decisions' => $screen->count(),
                'screen_passes' => $screenPasses,
                'technical_agents' => $technical,
                'full_replay_or_forward_progress' => $fullProgress,
                'active_learning_agents' => $active,
                'unresolved_screen_pass' => $isUnresolved,
            ];
        }

        $maxUnresolved = max(1, (int) config('services.lab_selection.learning_velocity_max_unresolved_screen_generations', 1));
        $starvation = $this->starvationSummary($symbol, $tf);
        $noEvidenceGenerations = collect($observations)->filter(fn (array $row): bool => (int) ($row['agent_count'] ?? 0) > 0
            && (string) ($row['status'] ?? '') !== 'technical_quarantine'
            && (int) ($row['screen_passes'] ?? 0) === 0
            && (int) ($row['full_replay_or_forward_progress'] ?? 0) === 0)->count();
        if ($noEvidenceGenerations > 0) {
            $starvation['starved'] = true;
            $starvation['no_learning_evidence_generations'] = $noEvidenceGenerations;
            $starvation['recovery_required'] = true;
            $starvation['reason'] = 'NO_LEARNING_EVIDENCE_IN_RECENT_GENERATIONS';
        }
        $reasons = [];
        $allowed = true;
        $status = 'healthy';
        $nextAction = 'normal_generation_policy';
        $evolutionMode = 'uncertainty';
        if ($technicalRecovery > 0) {
            $allowed = false;
            $status = 'blocked_technical_recovery';
            $evolutionMode = 'technical_error';
            $reasons[] = 'technical_recovery_required_before_strategy_learning';
            $nextAction = 'recover_or_reconcile_technical_evidence';
        } elseif ($activeLearning > 0) {
            $allowed = false;
            $status = 'learning_in_progress';
            $reasons[] = 'existing_learning_work_must_finish_before_new_cohort';
            $nextAction = 'wait_for_screen_or_full_replay_completion';
        } elseif ($unresolved >= $maxUnresolved) {
            $allowed = false;
            $status = 'blocked_learning_backlog';
            $evolutionMode = 'screen_pass';
            $reasons[] = 'screen_pass_without_full_replay';
            $nextAction = 'dispatch_learning_lane_or_full_replay_before_new_generation';
        } elseif ($generations->isEmpty()) {
            $status = 'no_history';
            $nextAction = 'collect_first_screening_evidence';
        } elseif ($unresolved > 0) {
            $status = 'learning_throughput_warning';
            $evolutionMode = 'screen_pass';
            $reasons[] = 'unresolved_screen_pass_below_backpressure_limit';
            $nextAction = 'prioritize_learning_lane';
        } elseif (collect($observations)->contains(fn (array $row): bool => (int) $row['screen_decisions'] > 0)) {
            $evolutionMode = 'strategy_failure';
        }
        if ((bool) data_get($starvation, 'starved', false)) {
            $allowed = false;
            $status = 'learning_starvation';
            $evolutionMode = 'uncertainty';
            $nextAction = 'run_learning_reconciliation_recovery';
            $reasons[] = 'LEARNING_STARVATION_DETECTED';
        }

        return [
            ...$base,
            'allowed' => $allowed,
            'status' => $status,
            'evolution_mode' => $evolutionMode,
            'reason_codes' => array_values(array_unique($reasons)),
            'next_action' => $nextAction,
            'lookback_generations' => $lookback,
            'max_unresolved_screen_generations' => $maxUnresolved,
            'unresolved_screen_generations' => $unresolved,
            'technical_recovery_agents' => $technicalRecovery,
            'active_learning_agents' => $activeLearning,
            'learning_starvation' => $starvation,
            'learning_truth' => $this->truthScoreboard($symbol, $tf),
            'observations' => $observations,
        ];
    }

    /**
     * Count only technical states that still have an actionable recovery.
     *
     * A constructor may legitimately be unable to produce a non-zero
     * mutation for a fully exhausted gene.  That candidate remains in
     * technical_quarantine and remains excluded from every quality path, but
     * replaying it cannot repair the invariant.  Treat only that exact,
     * immutable zero-diff preflight shape as reconciled; every other
     * technical quarantine continues to block learning fail-closed.
     */
    private function requiresTechnicalRecovery(LabAgent $agent): bool
    {
        if ((string) $agent->lifecycle_status === 'evaluation_error') {
            return true;
        }

        if ((string) $agent->lifecycle_status !== 'technical_quarantine') {
            return false;
        }

        // A closed generation-level constructor contract breach has no
        // executable evidence to recover.  Replaying its surviving agents
        // would create an incomplete cohort and would turn infrastructure
        // history into a false strategy observation.  Keep the generation
        // permanently quarantined and excluded from quality/learning, but do
        // not let it block a fresh valid cohort forever.
        $generationContext = (array) data_get($agent->generation?->trigger_context, 'integrity_repair', []);
        $contractDrift = (array) data_get($generationContext, 'contract_drift', []);
        $constructorAbort = data_get($agent->generation?->trigger_context, 'constructor_contract_abort.reason_code')
            ?? data_get($agent->generation?->trigger_context, 'shadow_research_constructor_abort.reason_code')
            ?? data_get($agent->generation?->trigger_context, 'controlled_rescue_constructor_abort.reason_code');
        if ($contractDrift !== [] && $constructorAbort !== null) {
            return false;
        }

        $errors = array_values(array_unique(array_map(
            static fn (mixed $error): string => (string) $error,
            (array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', []),
        )));
        if ($errors !== ['ZERO_DIFF_INVARIANT_FAILED']) {
            return true;
        }

        $diff = (array) $agent->parameter_diff;
        if ($diff === []) {
            return true;
        }

        foreach ($diff as $change) {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)) {
                return true;
            }

            $old = $change['old'];
            $new = $change['new'];
            $same = is_numeric($old) && is_numeric($new)
                ? (float) $old === (float) $new
                : json_encode($old, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
                    === json_encode($new, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
            if (! $same) {
                return true;
            }
        }

        return false;
    }

    /** @param \Illuminate\Support\Collection<int, mixed> $agentIds */
    private function fullProgressCount($agentIds): int
    {
        if ($agentIds->isEmpty()) return 0;

        $count = LabAgent::query()
            ->whereIn('id', $agentIds)
            ->whereIn('lifecycle_status', [
                'challenger', 'forward_validated', 'paper', 'champion',
            ])
            ->count();
        if (Schema::hasTable('lab_learning_lane_dispatches') && Schema::hasColumn('lab_learning_lane_dispatches', 'stage')) {
            $count += LabLearningLaneDispatch::query()
                ->whereIn('lab_agent_id', $agentIds)
                ->where(function ($query): void {
                    $query->whereIn('stage', ['full_replay', 'full_validation', 'forward'])
                        ->orWhereIn('status', ['full_replay_completed', 'forward_validated']);
                })
                ->count();
        }
        if (Schema::hasTable('lab_mutation_response_maps')) {
            $count += LabMutationResponseMap::query()
                ->whereIn('lab_agent_id', $agentIds)
                ->whereIn('stage', ['full_replay', 'forward'])
                ->count();
        }

        return $count;
    }

    /** Only canonical, settled, exact-control pairs are learning progress. */
    private function canonicalProgressCount($agentIds): int
    {
        if ($agentIds->isEmpty() || ! Schema::hasTable('agent_learning_settlements')) return 0;
        $pairs = LabLearningLanePair::query()->with('controlResponseMap')
            ->whereIn('candidate_agent_id', $agentIds)
            ->where('status', 'canonical_episode_settled')->get()
            ->filter(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair());
        if ($pairs->isEmpty()) return 0;

        return AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->whereIn('source_id', $pairs->pluck('id'))
            ->count();
    }

    /** @return array<string,int|float> */
    private function truthScoreboard(string $symbol, string $timeframe): array
    {
        $scope = fn ($query) => $query->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe));
        $pairs = Schema::hasTable('lab_learning_lane_pairs') ? $scope(LabLearningLanePair::query()->with('controlResponseMap'))->get() : collect();
        $verified = $pairs->filter(fn (LabLearningLanePair $pair): bool => $pair->isVerifiedControlPair());
        $settlements = Schema::hasTable('agent_learning_settlements') ? AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)->whereIn('source_id', $verified->pluck('id'))->count() : 0;
        $lessons = Schema::hasTable('agent_learning_lessons') ? $scope(AgentLearningLesson::query())->get() : collect();
        $dispatches = Schema::hasTable('lab_learning_lane_dispatches') ? $scope(LabLearningLaneDispatch::query())->get() : collect();
        $falseGreen = $dispatches->where('status', 'completed')->filter(fn ($row): bool => ! $verified->contains('id', $row->pair_id))->count()
            + $pairs->where('status', 'canonical_failed')->count();
        $technical = Schema::hasTable('lab_generations') ? LabGeneration::query()->where('status', 'technical_quarantine')->whereHas('laboratory', fn ($q) => $q->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe)))->count() : 0;
        $episodes = Schema::hasTable('agent_learning_episodes') ? AgentLearningEpisode::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->count() : 0;
        $evaluations = Schema::hasTable('lab_evaluation_runs') ? DB::table('lab_evaluation_runs')->where('status', 'completed')->whereIn('lab_agent_id', LabAgent::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->pluck('id'))->count() : 0;
        $usableLessons = $lessons->filter(function (AgentLearningLesson $lesson) use ($verified): bool {
            $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
            return $pairId > 0 && $verified->contains('id', $pairId);
        });
        $confirmed = $usableLessons->where('status', 'confirmed')->count();
        $provisional = $usableLessons->where('status', 'provisional')->count();
        $real = $settlements + $confirmed - $pairs->where('status', 'canonical_failed')->count() - $falseGreen;

        return ['generation_activity' => Schema::hasTable('lab_generations') ? LabGeneration::query()->whereHas('laboratory', fn ($q) => $q->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe)))->count() : 0, 'evaluation_completed' => $evaluations, 'verified_pair_count' => $verified->count(), 'canonical_episode_count' => $episodes, 'settlement_count' => $settlements, 'provisional_lesson_count' => $provisional, 'confirmed_skill_count' => $confirmed, 'anti_skill_count' => 0, 'canonical_failure_count' => $pairs->where('status', 'canonical_failed')->count(), 'false_green_count' => $falseGreen, 'learning_starvation' => $settlements === 0 ? 1 : 0, 'technical_quarantine' => $technical, 'insufficient_activity' => Schema::hasTable('agent_learning_settlements') ? AgentLearningSettlement::query()->where('evidence_state', 'insufficient_evidence')->count() : 0, 'real_progress' => $real];
    }

    /**
     * A pending curriculum is not progress. If dojo work, stale dispatches or
     * failed lab jobs exist without an active recovery path, admission stays
     * blocked and the monitor cannot report a false green.
     *
     * @return array<string, mixed>
     */
    private function starvationSummary(string $symbol, string $timeframe): array
    {
        $pendingDojo = Schema::hasTable('lab_failure_dojo_runs')
            ? LabFailureDojoRun::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'pending')->count()
            : 0;
        $dispatches = Schema::hasTable('lab_learning_lane_dispatches')
            ? LabLearningLaneDispatch::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->get(['status', 'queued_at', 'queue_batch_id'])
            : collect();
        $activeDispatches = $dispatches->whereIn('status', ['selected', 'queued', 'running'])->count();
        $staleAfter = max(60, (int) config('services.lab_selection.learning_starvation_stale_seconds', 1800));
        $staleDispatches = $dispatches->whereIn('status', ['selected', 'queued', 'running'])
            ->filter(fn ($row): bool => $row->queued_at !== null && now()->utc()->diffInSeconds($row->queued_at) > $staleAfter)
            ->count();
        $failedJobs = 0;
        if (Schema::hasTable('failed_jobs')) {
            $queues = app(LabQueueJobInspector::class)->labQueues();
            $failedJobs = DB::table('failed_jobs')->whereIn('queue', $queues)->get(['uuid', 'payload'])->filter(function ($job) use ($symbol, $timeframe): bool {
                if (Schema::hasTable('learning_recovery_events')
                    && LearningRecoveryEvent::query()->where('source_key', (string) $job->uuid)->whereIn('status', ['requeued', 'reconciled'])->exists()) {
                    return false;
                }
                $command = (string) data_get(json_decode((string) $job->payload, true), 'data.command', $job->payload);
                if (! preg_match('/labAgentId.*?i:(\d+);/s', $command, $match)) return true;
                $agent = DB::table('lab_agents')->where('id', (int) $match[1])->first();
                if (! $agent || strtoupper((string) $agent->symbol) !== strtoupper($symbol) || strtoupper((string) $agent->timeframe) !== strtoupper($timeframe)) return false;
                return true;
            })->count();
            if (Schema::hasTable('learning_recovery_events')) {
                $failedJobs += LearningRecoveryEvent::query()
                    ->where('symbol', strtoupper($symbol))
                    ->where('timeframe', strtoupper($timeframe))
                    ->where('status', 'manual_review')
                    ->count();
            }
        }
        $minPending = max(1, (int) config('services.lab_selection.learning_starvation_min_pending_dojo', 1));
        $starved = $staleDispatches > 0
            || $failedJobs > 0
            || ($pendingDojo >= $minPending && $activeDispatches === 0);

        return [
            'protocol' => 'learning_starvation_v1',
            'starved' => $starved,
            'pending_dojo' => $pendingDojo,
            'active_dispatches' => $activeDispatches,
            'stale_dispatches' => $staleDispatches,
            'failed_lab_jobs' => $failedJobs,
            'stale_after_seconds' => $staleAfter,
            'minimum_pending_dojo' => $minPending,
            'recovery_required' => $starved,
            'promotion_evidence' => false,
        ];
    }
}
