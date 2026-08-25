<?php

namespace App\Services;

use App\Models\AgentInheritanceManifest;
use App\Models\GenerationClosedLoopAudit;
use App\Models\LabGeneration;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use Illuminate\Support\Facades\Schema;

/** A generation is complete only when every constructed child closes its learning loop. */
class ClosedLoopGenerationAuditService
{
    public const PROTOCOL = 'closed_loop_generation_audit_v1';

    /** @return array<string,mixed> */
    public function assess(LabGeneration $generation): array
    {
        $agents = LabAgent::query()->where('lab_generation_id', $generation->id)->with('modelVersion')->get();
        $total = max(1, $agents->count());
        $completedRuns = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'full_validation')
            ->where('status', 'completed')
            ->get(['lab_agent_id', 'run_id']);
        $eligibleIds = $completedRuns->pluck('lab_agent_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        $eligible = $eligibleIds->count();
        $terminalStatuses = ['screened', 'rejected', 'stagnated', 'technical_quarantine', 'evaluation_error', 'challenger', 'forward_validated', 'paper', 'promoted', 'champion', 'active', 'elite'];
        $terminal = $agents->filter(fn (LabAgent $agent): bool => in_array((string) $agent->lifecycle_status, $terminalStatuses, true))->count();
        $eligibleAgents = $agents->whereIn('id', $eligibleIds->all());
        $terminalReceiptStates = ['provisional', 'harmful', 'no_effect', 'context_mismatch', 'invalid_intent', 'technical_incomplete'];
        $replay = $eligibleAgents->filter(function (LabAgent $agent) use ($completedRuns): bool {
            $runId = (string) data_get($agent->modelVersion?->metadata, 'learning_receipt.settlement.evidence_run_id', '');

            return $runId !== '' && $completedRuns->contains(fn (LabEvaluationRun $run): bool =>
                (int) $run->lab_agent_id === (int) $agent->id && (string) $run->run_id === $runId);
        })->count();
        $settled = $eligibleAgents->filter(fn (LabAgent $agent): bool => in_array((string) data_get($agent->modelVersion?->metadata, 'learning_receipt.status'), $terminalReceiptStates, true))->count();
        $attributed = $eligibleAgents->filter(function (LabAgent $agent): bool {
            $settlement = (array) data_get($agent->modelVersion?->metadata, 'learning_receipt.settlement', []);

            return $settlement !== []
                && array_key_exists('target_delta', $settlement)
                && filled($settlement['declared_target'] ?? null)
                && filled($settlement['observed_target'] ?? null);
        })->count();
        $learning = $settled;
        $manifestCount = Schema::hasTable('agent_inheritance_manifests') ? AgentInheritanceManifest::query()->where('lab_generation_id', $generation->id)->where('status', 'sealed')->count() : 0;
        $coverage = fn (int $count): float => round($count / $total, 6);
        $eligibleCoverage = fn (int $count): float => $eligible === 0 ? 0.0 : round($count / $eligible, 6);
        $terminalCoverage = $coverage($terminal);
        $manifestCoverage = $coverage($manifestCount);
        $learningComplete = $eligible > 0
            && $eligibleCoverage($replay) >= 1
            && $eligibleCoverage($settled) >= 1
            && $eligibleCoverage($attributed) >= 1
            && $eligibleCoverage($learning) >= 1;
        $operationalComplete = $agents->isNotEmpty()
            && $terminalCoverage >= 1
            && $manifestCoverage >= 1
            && ($eligible === 0 || $learningComplete);
        $status = $operationalComplete
            ? ($learningComplete ? 'closed_loop_complete' : 'closed_no_learning_evidence')
            : 'incomplete';
        $report = ['protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'required_questions' => ['confirmed', 'rejected', 'uncertain', 'memory_updated', 'evolution_action', 'next_generation_experiment'], 'counts' => compact('total', 'eligible', 'terminal', 'replay', 'settled', 'attributed', 'learning', 'manifestCount'), 'terminal_disposition_coverage' => $terminalCoverage, 'learning_progress' => $learningComplete, 'zero_full_replay_is_not_learning_progress' => true];
        $result = ['protocol' => self::PROTOCOL, 'status' => $status, 'replay_coverage' => $eligibleCoverage($replay), 'settlement_coverage' => $eligibleCoverage($settled), 'attribution_coverage' => $eligibleCoverage($attributed), 'learning_decision_coverage' => $eligibleCoverage($learning), 'inheritance_manifest_coverage' => $manifestCoverage, 'generation_may_close' => $operationalComplete, 'learning_progress' => $learningComplete, 'report' => $report, 'promotion_evidence' => false];
        if (Schema::hasTable('generation_closed_loop_audits')) GenerationClosedLoopAudit::query()->updateOrCreate(['lab_generation_id' => $generation->id], ['audit_key' => hash('sha256', self::PROTOCOL.'|'.$generation->id), 'status' => $result['status'], 'replay_coverage' => $result['replay_coverage'], 'settlement_coverage' => $result['settlement_coverage'], 'attribution_coverage' => $result['attribution_coverage'], 'learning_decision_coverage' => $result['learning_decision_coverage'], 'inheritance_manifest_coverage' => $result['inheritance_manifest_coverage'], 'report' => $report, 'audited_at' => now()]);
        return $result;
    }
}
