<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\GenerationAdmissionDecision;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\Schema;

/** The single typed authority for every new-generation admission. */
class GenerationAdmissionDecisionService
{
    public const WAIT_RUNTIME = 'WAIT_RUNTIME';

    public const WAIT_ACTIVE_WORK = 'WAIT_ACTIVE_WORK';

    public const DISPATCH_LEARNING = 'DISPATCH_LEARNING';

    public const RECOVER_TECHNICAL = 'RECOVER_TECHNICAL';

    public const QUARANTINE_CAPABILITY_LANE = 'QUARANTINE_CAPABILITY_LANE';

    public const OPEN_NORMAL_GENERATION = 'OPEN_NORMAL_GENERATION';

    public const OPEN_STRUCTURAL_ESCAPE = 'OPEN_STRUCTURAL_ESCAPE';

    public const BLOCK_HARD = 'BLOCK_HARD';

    /** @return array<string, mixed> */
    public function decide(AiLaboratory $lab, ?LabGeneration $latest, array $input = [], bool $persist = true): array
    {
        $velocity = app(LearningVelocityGateService::class)->inspect($lab);
        $trigger = (string) data_get($input, 'trigger');
        $learningConfirmation = (bool) data_get($input, 'learning_confirmation');
        $screenedHandoff = $latest?->status === 'screened'
            && in_array($trigger, ['candidate_handoff', 'data_edge_audit', 'coverage_rescue'], true);
        $terminalStatus = ! $latest || in_array((string) $latest->status, ['screened', 'completed', 'technical_quarantine', 'abandoned', 'failed'], true);
        $terminal = ! $latest || (
            $terminalStatus
            && (
                (bool) data_get($input, 'force')
                || ($screenedHandoff && ! $latest->agents()->whereIn('lifecycle_status', [
                    'queued', 'screening', 'training', 'full_queued', 'full_validation',
                ])->exists())
                || (! $screenedHandoff && ! $latest->agents()->whereIn('lifecycle_status', [
                    'draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation',
                ])->exists())
            )
        );
        $special = (bool) data_get($input, 'controlled_rescue')
            || (bool) data_get($input, 'operator_approved_successor')
            || (bool) data_get($input, 'role_complete')
            || (bool) data_get($input, 'shadow_research')
            || (bool) data_get($input, 'coverage_rescue')
            || $learningConfirmation
            || in_array($trigger, ['candidate_handoff', 'data_edge_audit', 'coverage_rescue'], true);
        $autonomy = app(AutonomousModeService::class)->status((string) $lab->symbol, (string) $lab->timeframe);
        $decision = self::OPEN_NORMAL_GENERATION;
        $allowed = true;
        $reasons = [];
        $safetyPaused = app(LearningProtocolSafetyService::class)->generationCreationPaused();
        $edgeOwnership = app(CanonicalResearchLanePriorityService::class)->edgeGenesisOwnership(
            (string) $lab->symbol,
            (string) $lab->timeframe,
        );
        // A canonical beneficial lesson awaiting the current target-aligned
        // protocol is the next generation curriculum. Generic, targeted and
        // structural-escape builders must not consume the only lineage head
        // first; the dedicated learning-confirmation trigger below is the
        // sole consumer and still passes every ordinary safety boundary.
        $causalLesson = null;
        if (! $learningConfirmation
            && strtoupper((string) $lab->symbol) === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
            && strtoupper((string) $lab->timeframe) === LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME) {
            $causalLesson = app(CausalLearningCohortPlannerService::class)->eligibleLesson(
                (string) $lab->symbol,
                (string) $lab->timeframe,
            );
        }
        $priorityLearningPair = $terminal
            ? app(LearningLaneService::class)->priorityResearchPair(
                (string) $lab->symbol,
                (string) $lab->timeframe,
            )
            : null;

        if (($edgeOwnership['owned'] ?? false) === true) {
            // Edge Genesis is itself the current learning/evolution state
            // machine. Opening a parallel learning-confirmation generation
            // here lets a partial constructor consume the same replay lane
            // and can strand an admitted Edge repair indefinitely.
            $decision = self::WAIT_ACTIVE_WORK;
            $allowed = false;
            $reasons[] = 'CANONICAL_EDGE_STATE_MACHINE_OWNS_EVOLUTION_LANE';
        } elseif (! $terminal) {
            $decision = self::WAIT_ACTIVE_WORK;
            $allowed = false;
            $reasons[] = 'LATEST_GENERATION_OR_AGENT_WORK_ACTIVE';
        } elseif (! (bool) data_get($autonomy, 'enabled', false)) {
            $decision = self::BLOCK_HARD;
            $allowed = false;
            $reasons[] = 'AUTONOMOUS_MODE_STOPPED';
        } elseif ($priorityLearningPair !== null) {
            $decision = self::DISPATCH_LEARNING;
            $allowed = false;
            $reasons[] = 'VERIFIED_POSITIVE_LEARNING_PAIR_HAS_REPLAY_PRIORITY';
        } elseif ($causalLesson !== null) {
            $decision = self::DISPATCH_LEARNING;
            $allowed = false;
            $reasons[] = 'TARGET_ALIGNED_CAUSAL_LESSON_HAS_GENERATION_PRIORITY';
        } elseif (! (bool) data_get($velocity, 'allowed', true)) {
            $status = (string) data_get($velocity, 'status');
            $actionable = (int) data_get($velocity, 'learning_starvation.actionable_pending_dojo', 0);
            $activeDispatches = (int) data_get($velocity, 'learning_starvation.active_dispatches', 0);
            if ($actionable > 0 && $activeDispatches === 0) {
                $decision = self::DISPATCH_LEARNING;
                $reasons[] = 'ACTIONABLE_LEARNING_MUST_BE_DISPATCHED_FIRST';
            } elseif ($status === 'blocked_technical_recovery') {
                $decision = self::RECOVER_TECHNICAL;
                $reasons[] = 'TRANSIENT_TECHNICAL_RECOVERY_REQUIRED';
            } elseif (in_array($status, ['blocked_learning_backlog', 'live_learning_backlog'], true)) {
                $decision = self::DISPATCH_LEARNING;
                $reasons[] = 'ACTIONABLE_LEARNING_MUST_BE_DISPATCHED_FIRST';
            } elseif ($status === 'strategy_deadlock') {
                $decision = self::OPEN_STRUCTURAL_ESCAPE;
                $allowed = true;
                $reasons[] = 'ZERO_PASS_DEADLOCK_REQUIRES_BOUNDED_STRUCTURAL_ESCAPE';
            } else {
                $decision = self::BLOCK_HARD;
                $reasons[] = 'LEARNING_VELOCITY_GATE_BLOCKED';
            }
            if ($decision !== self::OPEN_STRUCTURAL_ESCAPE) {
                $allowed = false;
            }
        } elseif ($safetyPaused && ! $special) {
            $decision = self::BLOCK_HARD;
            $allowed = false;
            $reasons[] = 'GENERATION_CREATION_SAFETY_PAUSED';
        } elseif ($latest && $this->screenDecisions($latest) > 0 && $this->screenPasses($latest) === 0) {
            if ($trigger === 'new_data') {
                // An enabled autonomous loop must be able to accumulate the
                // configured number of independent zero-pass observations.
                // LabPopulationService still requires a fresh data window;
                // this is not permission to recycle the sealed snapshot.
                $reasons[] = 'AUTONOMOUS_ZERO_PASS_ACCUMULATION_REQUIRES_FRESH_DATA';
            } else {
                $decision = self::BLOCK_HARD;
                $allowed = false;
                $reasons[] = 'ZERO_PASS_THRESHOLD_NOT_YET_STRUCTURAL_ESCAPE_ELIGIBLE';
            }
        }

        $capabilityAgents = collect((array) data_get($velocity, 'observations', []))
            ->sum(fn (array $row): int => (int) data_get($row, 'capability_quarantined_agents', 0));
        if ($allowed && $capabilityAgents > 0 && $decision === self::OPEN_NORMAL_GENERATION) {
            $decision = self::QUARANTINE_CAPABILITY_LANE;
            $reasons[] = 'DATA_CAPABILITY_LANE_ISOLATED';
        }
        if ($learningConfirmation
            && $terminal
            && $priorityLearningPair === null
            && ! $allowed
            && $decision === self::DISPATCH_LEARNING) {
            // This bounded triplet is the action requested by the velocity
            // gate: it consumes one canonical provisional lesson through
            // guided, memory-blinded and frozen-control replay. Blocking it
            // because learning is waiting creates a circular admission
            // deadlock. Active work and technical recovery remain untouched.
            $allowed = true;
            $reasons[] = 'CAUSAL_CONFIRMATION_SATISFIES_LEARNING_DISPATCH';
        }
        if ($special
            && $terminal
            && ! $allowed
            && $decision === self::BLOCK_HARD
            && ! in_array('AUTONOMOUS_MODE_STOPPED', $reasons, true)) {
            // Operator input is not a bypass. It is an audited input to this
            // authority and can only open a bounded structural/recovery path.
            $decision = self::OPEN_STRUCTURAL_ESCAPE;
            $allowed = true;
            $reasons[] = 'AUDITED_BOUNDED_SPECIAL_ADMISSION';
        }
        $result = [
            'protocol' => 'generation_admission_decision_v1',
            'decision' => $decision,
            'allowed' => $allowed,
            'reason_codes' => array_values(array_unique($reasons)),
            'latest_generation_id' => $latest?->id,
            'input' => $input,
            'generation_creation_safety_paused' => $safetyPaused,
            'autonomous_mode' => $autonomy,
            'learning_velocity' => $velocity,
            'causal_confirmation_priority' => $causalLesson === null ? null : [
                'lesson_id' => (int) $causalLesson->id,
                'target' => (string) data_get(
                    $causalLesson->evidence,
                    'failure_signature.failure_target',
                    $causalLesson->failure_class ?: 'causal_learning',
                ),
                'gene_key' => (string) $causalLesson->parameter_key,
                'promotion_evidence' => false,
            ],
            'learning_pair_priority' => $priorityLearningPair === null ? null : [
                'pair_id' => (int) $priorityLearningPair->id,
                'candidate_agent_id' => (int) $priorityLearningPair->candidate_agent_id,
                'target' => (string) $priorityLearningPair->target,
                'gene_key' => (string) $priorityLearningPair->candidateResponseMap?->parameter_key,
                'target_delta' => (array) $priorityLearningPair->target_delta,
                'research_only' => true,
                'promotion_evidence' => false,
            ],
            'edge_research_lane' => $edgeOwnership,
            'promotion_evidence' => false,
        ];
        if ($persist && Schema::hasTable('generation_admission_decisions')) {
            $key = hash('sha512', json_encode([
                'generation_admission_decision_v1', $lab->id, $latest?->id, $decision,
                $result['reason_codes'], data_get($input, 'trigger'),
            ], JSON_UNESCAPED_SLASHES));
            $row = GenerationAdmissionDecision::query()->firstOrCreate(['decision_key' => $key], [
                'ai_laboratory_id' => $lab->id,
                'latest_generation_id' => $latest?->id,
                'decision' => $decision,
                'allowed' => $allowed,
                'reason_codes' => $result['reason_codes'],
                'context' => ['input' => $input, 'learning_velocity' => $velocity,
                    'causal_confirmation_priority' => $result['causal_confirmation_priority'],
                    'learning_pair_priority' => $result['learning_pair_priority'],
                    'autonomous_mode' => $autonomy, 'edge_research_lane' => $edgeOwnership, 'promotion_evidence' => false],
                'decided_at' => now(),
            ]);
            $result['decision_id'] = (int) $row->id;
        }

        return $result;
    }

    private function screenPasses(LabGeneration $generation): int
    {
        $ids = $generation->agents()->pluck('id');
        if ($ids->isEmpty() || ! Schema::hasTable('candidate_gate_decisions')) {
            return 0;
        }

        return CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $ids)
            ->where('stage', 'screening')
            ->where('decision', 'passed')
            ->count();
    }

    private function screenDecisions(LabGeneration $generation): int
    {
        $ids = $generation->agents()->pluck('id');
        if ($ids->isEmpty() || ! Schema::hasTable('candidate_gate_decisions')) {
            return 0;
        }

        return CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $ids)
            ->where('stage', 'screening')
            ->count();
    }
}
