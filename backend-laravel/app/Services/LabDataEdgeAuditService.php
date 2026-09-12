<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;

/**
 * Converts a terminal, technically complete generation report into a durable
 * research-only data/edge audit. It never invents a pass or relaxes a gate.
 */
class LabDataEdgeAuditService
{
    public const PROTOCOL = 'data_edge_audit_v1';

    /** @return array<string, mixed> */
    public function recordFromFinalReport(LabGeneration $generation): array
    {
        $generation = $generation->fresh() ?? $generation;
        $existing = (array) data_get($generation->trigger_context, 'data_edge_audit', []);
        if ((string) data_get($existing, 'protocol') === self::PROTOCOL) {
            return ['status' => 'already_recorded', 'reason_code' => 'AUDIT_ALREADY_RECORDED', 'audit' => $existing];
        }
        $report = (array) data_get($generation->trigger_context, 'latest_generation_report', []);
        if ((string) data_get($report, 'protocol') !== LabGenerationReportService::PROTOCOL
            || (string) data_get($report, 'report_state') !== 'FINAL'
            || (string) data_get($report, 'next_action') !== 'data_edge_audit_required') {
            return ['status' => 'blocked', 'reason_code' => 'FINAL_DATA_EDGE_REPORT_REQUIRED'];
        }

        $technicalCompletion = (float) data_get($report, 'kpis.technical_completion_rate', 0);
        $pipelineFailures = (int) data_get($report, 'kpis.pipeline_failure_count', 0);
        if ($technicalCompletion < 100 || $pipelineFailures > 0) {
            return [
                'status' => 'blocked',
                'reason_code' => 'TECHNICAL_EVIDENCE_MUST_BE_RECOVERED_FIRST',
                'technical_completion_rate' => $technicalCompletion,
                'pipeline_failure_count' => $pipelineFailures,
            ];
        }

        $gateFailures = (array) data_get($report, 'gate_failures', []);
        arsort($gateFailures);
        $dominantFailures = array_slice($gateFailures, 0, 6, true);
        $screenPassRate = (float) data_get($report, 'kpis.screen_pass_rate', 0);
        $finding = sprintf(
            'G%d completed with %.2f%% technical evidence and %.2f%% screen pass rate; the current search portfolio was falsified by %s. Open a fresh root data-edge portfolio without relaxing any gate.',
            (int) $generation->generation,
            $technicalCompletion,
            $screenPassRate,
            implode(', ', array_keys($dominantFailures)) ?: 'NO_RECORDED_DOMINANT_GATE',
        );

        return $this->record($generation, $finding, [
            'source' => 'autonomous_final_generation_report',
            'report_recorded_at' => data_get($report, 'recorded_at'),
            'technical_completion_rate' => $technicalCompletion,
            'pipeline_failure_count' => $pipelineFailures,
            'screen_pass_rate' => $screenPassRate,
            'screening_pass_count' => (int) data_get($report, 'screening.passed', 0),
            'dominant_gate_failures' => $dominantFailures,
            'observable_mutation_count' => (int) data_get($report, 'kpis.observable_mutation_count', 0),
            'control_relative_improvement_count' => (int) data_get($report, 'kpis.control_relative_improvement_count', 0),
            'instrument_value_evidence_is_advisory_only' => true,
            'disposition' => 'open_fresh_root_data_edge_portfolio',
        ], 'autonomous_final_report');
    }

    /** @return array<string, mixed> */
    public function record(LabGeneration $generation, string $finding, array $evidence = [], string $source = 'operator'): array
    {
        $finding = trim($finding);
        if ($finding === '') {
            return ['status' => 'blocked', 'reason_code' => 'AUDIT_FINDING_REQUIRED'];
        }

        return DB::transaction(function () use ($generation, $finding, $evidence, $source): array {
            $locked = LabGeneration::query()->lockForUpdate()->find($generation->id);
            if (! $locked) {
                return ['status' => 'blocked', 'reason_code' => 'GENERATION_NOT_FOUND'];
            }
            $context = (array) $locked->trigger_context;
            $existing = (array) data_get($context, 'data_edge_audit', []);
            if ((string) data_get($existing, 'protocol') === self::PROTOCOL) {
                return ['status' => 'already_recorded', 'reason_code' => 'AUDIT_ALREADY_RECORDED', 'audit' => $existing];
            }

            $openAgent = $locked->agents()->whereIn('lifecycle_status', [
                'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
            ])->exists();
            $openRun = LabEvaluationRun::query()->where('lab_generation_id', $locked->id)
                ->whereNull('finished_at')->exists();
            $screenedBoundary = (string) $locked->status === 'screened' && ! $openAgent && ! $openRun;
            if (in_array((string) $locked->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)
                && ! $screenedBoundary) {
                return ['status' => 'deferred', 'reason_code' => 'GENERATION_EVIDENCE_IN_PROGRESS'];
            }

            $audit = [
                'protocol' => self::PROTOCOL,
                'source' => $source,
                'recorded_at' => now()->utc()->toIso8601String(),
                'finding' => $finding,
                'generation' => (int) $locked->generation,
                'evidence' => $evidence,
                'promotion_evidence' => false,
                'rule' => 'Audit unlocks research creation only; all screening/full/forward/paper gates remain unchanged.',
            ];
            $context['data_edge_audit'] = $audit;
            $report = (array) data_get($context, 'latest_generation_report', []);
            $report['next_action'] = 'data_edge_audit_completed';
            $report['data_edge_audit'] = $audit;
            $context['latest_generation_report'] = $report;
            $locked->update(['trigger_context' => $context]);

            return ['status' => 'recorded', 'reason_code' => 'AUTONOMOUS_DATA_EDGE_AUDIT_RECORDED', 'audit' => $audit];
        });
    }
}
