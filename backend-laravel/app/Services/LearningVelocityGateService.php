<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabMutationResponseMap;
use Illuminate\Support\Facades\Cache;
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
            $fullStatuses = ['challenger', 'forward_validated', 'paper', 'champion'];
            $observations = $generations->map(function ($generation) use ($agents, $screenPasses, $fullStatuses): array {
                $rows = $agents->where('lab_generation_id', $generation->id);
                $passed = $rows->filter(fn ($agent): bool => $screenPasses->has($agent->id))->count();
                $full = $rows->whereIn('lifecycle_status', $fullStatuses)->count();
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
            $allowed = $active === 0 && $unresolved < max(1, (int) config('services.lab_selection.learning_velocity_max_unresolved_screen_generations', 1));

            return [
                ...$base,
                'allowed' => $allowed,
                'status' => $active > 0 ? 'learning_in_progress' : ($unresolved > 0 ? 'blocked_learning_backlog' : ($technical > 0 ? 'technical_history_quarantined' : 'healthy')),
                'technical_quarantine_generations' => $technical,
                'unresolved_screen_generations' => $unresolved,
                'active_learning_agents' => $active,
                'lookback_generations' => $lookback,
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
            $fullProgress = $this->fullProgressCount($agentIds);
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
                'screen_decisions' => $screen->count(),
                'screen_passes' => $screenPasses,
                'technical_agents' => $technical,
                'full_replay_or_forward_progress' => $fullProgress,
                'active_learning_agents' => $active,
                'unresolved_screen_pass' => $isUnresolved,
            ];
        }

        $maxUnresolved = max(1, (int) config('services.lab_selection.learning_velocity_max_unresolved_screen_generations', 1));
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
}
