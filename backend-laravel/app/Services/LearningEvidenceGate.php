<?php

namespace App\Services;

use App\Models\AgentLearningSettlement;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;

/** The sole authority for state transitions in the learning-evidence lane. */
class LearningEvidenceGate
{
    public const PROTOCOL = 'learning_evidence_gate_v1';

    /** @return array{allowed:bool,status:string,reasons:list<string>,promotion_evidence:bool} */
    public function allow(?LabLearningLanePair $pair, LabEvaluationRun|array|string|null $run, string $stage): array
    {
        $reasons = [];
        if (! $pair) $reasons[] = 'PAIR_MISSING';
        elseif (! $pair->loadMissing('controlResponseMap')->isVerifiedControlPair()) {
            $reasons[] = ! $pair->control_agent_id || ! $pair->control_response_map_id ? 'CONTROL_MISSING' : 'PAIR_UNVERIFIED';
        }
        if ($pair && (array) $pair->candidate_metrics === []) $reasons[] = 'CANDIDATE_METRICS_EMPTY';
        if ($pair && (array) $pair->control_metrics === []) $reasons[] = 'CONTROL_METRICS_EMPTY';

        $candidateRun = $this->run($run ?: ($pair?->candidate_evidence_run_id));
        $controlRun = $this->run($pair?->control_evidence_run_id);
        if (in_array($stage, ['replay_completed', 'canonical_pending', 'canonical_settled', 'lesson_compiled', 'skill_confirmed'], true)) {
            if (! $candidateRun || (string) $candidateRun->status !== 'completed') $reasons[] = 'CANDIDATE_EVIDENCE_INCOMPLETE';
            if (! $controlRun || (string) $controlRun->status !== 'completed') $reasons[] = 'CONTROL_EVIDENCE_INCOMPLETE';
        }
        if (in_array($stage, ['canonical_settled', 'lesson_compiled', 'skill_confirmed'], true) && $pair) {
            $settled = AgentLearningSettlement::query()->where('source_type', LabLearningLanePair::class)->where('source_id', $pair->id)->exists();
            if (! $settled) $reasons[] = 'CANONICAL_SETTLEMENT_MISSING';
        }

        $status = $reasons === [] ? $stage : (in_array('CONTROL_MISSING', $reasons, true) ? 'control_missing' : (in_array('PAIR_UNVERIFIED', $reasons, true) ? 'pair_unverified' : 'diagnostic_only'));
        return ['allowed' => $reasons === [], 'status' => $status, 'reasons' => array_values(array_unique($reasons)), 'promotion_evidence' => false];
    }

    /** @return array{allowed:bool,reason:string} */
    public function allowsNextGeneration(string $symbol, string $timeframe): array
    {
        $settled = AgentLearningSettlement::query()->whereHas('episode', fn ($q) => $q->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe)))->exists();
        $recovered = \App\Models\LearningRecoveryEvent::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->whereIn('status', ['reconciled', 'technical_recovery_completed'])->exists();
        return ['allowed' => $settled || $recovered, 'reason' => $settled ? 'CANONICAL_SETTLEMENT_EXISTS' : ($recovered ? 'TECHNICAL_RECOVERY_COMPLETED' : 'LEARNING_EVIDENCE_REQUIRED')];
    }

    private function run(LabEvaluationRun|array|string|null $run): ?LabEvaluationRun
    {
        if ($run instanceof LabEvaluationRun) return $run;
        $id = is_array($run) ? (string) ($run['evidence_run_id'] ?? $run['run_id'] ?? '') : (string) $run;
        return $id !== '' ? LabEvaluationRun::query()->where('run_id', $id)->first() : null;
    }
}
