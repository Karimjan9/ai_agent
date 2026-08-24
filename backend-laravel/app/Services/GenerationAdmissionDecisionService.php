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
            || in_array($trigger, ['candidate_handoff', 'data_edge_audit', 'coverage_rescue'], true);
        $decision = self::OPEN_NORMAL_GENERATION;
        $allowed = true;
        $reasons = [];
        $safetyPaused = app(LearningProtocolSafetyService::class)->generationCreationPaused();

        if (! $terminal) {
            $decision = self::WAIT_ACTIVE_WORK;
            $allowed = false;
            $reasons[] = 'LATEST_GENERATION_OR_AGENT_WORK_ACTIVE';
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
            $decision = self::BLOCK_HARD;
            $allowed = false;
            $reasons[] = 'ZERO_PASS_THRESHOLD_NOT_YET_STRUCTURAL_ESCAPE_ELIGIBLE';
        }

        $capabilityAgents = collect((array) data_get($velocity, 'observations', []))
            ->sum(fn (array $row): int => (int) data_get($row, 'capability_quarantined_agents', 0));
        if ($allowed && $capabilityAgents > 0 && $decision === self::OPEN_NORMAL_GENERATION) {
            $decision = self::QUARANTINE_CAPABILITY_LANE;
            $reasons[] = 'DATA_CAPABILITY_LANE_ISOLATED';
        }
        if ($special && $terminal && ! $allowed && $decision === self::BLOCK_HARD) {
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
            'learning_velocity' => $velocity,
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
                'context' => ['input' => $input, 'learning_velocity' => $velocity, 'promotion_evidence' => false],
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
