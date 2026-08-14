<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Services\CandidateHandoffService;
use App\Services\LabGenerationReportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\TechnicalGenerationRecoveryService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Close an exhausted evaluator retry as technical quarantine.
 *
 * This command never retries a replay and never manufactures a strategy
 * decision. It only closes a bounded technical error after queue, replay
 * liveness and immutable evidence checks have all passed.
 */
class FinalizeLabTechnicalQuarantine extends Command
{
    protected $signature = 'trading:finalize-lab-technical-quarantine
        {symbol : Market symbol, for example XAUUSD}
        {--timeframe=H1}
        {--generation= : Restrict finalization to one generation number}
        {--agent=* : Restrict finalization to one or more agent ids}
        {--apply : Persist terminal technical quarantine after operator approval}
        {--approved-by=}
        {--approval-reason=}
        {--json}';

    protected $description = 'Finalize bounded evaluator failures as technical quarantine without replay or quality evidence';

    public function handle(
        LabQueueJobInspector $queue,
        TechnicalGenerationRecoveryService $liveness,
        LabImmutableEvidenceService $evidence,
        OperatorApprovalService $approvals,
        CandidateHandoffService $handoffs,
        LabGenerationReportService $reports,
    ): int {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $generationNumber = $this->option('generation') !== null
            ? (int) $this->option('generation')
            : null;
        $requestedAgents = collect((array) $this->option('agent'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        $backlog = $queue->labQueueBacklog();
        if ($backlog['total'] > 0) {
            return $this->report([
                'action' => 'deferred_queue_backlog',
                'queue_backlog' => $backlog,
                'rows' => [],
            ]);
        }

        $probe = $liveness->readiness();
        if (! $probe['ready'] || ! $probe['idle']) {
            return $this->report([
                'action' => 'deferred_replay_not_idle',
                'replay_liveness' => $probe,
                'rows' => [],
            ]);
        }

        $agents = LabAgent::query()
            ->with(['generation', 'modelVersion'])
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('lifecycle_status', 'evaluation_error')
            ->when($generationNumber !== null, fn ($query) => $query->whereHas(
                'generation',
                fn ($generation) => $generation->where('generation', $generationNumber),
            ))
            ->when($requestedAgents->isNotEmpty(), fn ($query) => $query->whereIn('id', $requestedAgents->all()))
            ->orderBy('id')
            ->get();

        $rows = $agents->map(function (LabAgent $agent) use ($queue, $evidence): array {
            $latestRun = LabEvaluationRun::query()
                ->where('lab_agent_id', $agent->id)
                ->where('phase', 'screening')
                ->latest('id')
                ->first();
            $eligibility = $latestRun
                ? $evidence->learningEligibility($latestRun)
                : ['complete' => false, 'reason_codes' => ['NO_SCREENING_RUN']];
            $hasJob = $queue->hasAgentJob($agent->id, $this->labQueues());
            $recoveryAttempts = (int) data_get($agent->modelVersion?->metadata, 'evaluator_recovery_attempts', 0);
            $technicalRun = $latestRun
                && $latestRun->status === 'technical_error'
                && (string) data_get($latestRun->metadata, 'strategy_verdict', '') === 'withheld';
            $eligible = $recoveryAttempts >= 1
                && ! $hasJob
                && $technicalRun
                && ! (bool) ($eligibility['complete'] ?? false)
                && str_contains(strtolower((string) $agent->decision_reason), 'strategy verdict withheld');

            return [
                'agent_id' => (int) $agent->id,
                'generation_id' => (int) $agent->lab_generation_id,
                'generation' => (int) $agent->generation?->generation,
                'lifecycle_status' => $agent->lifecycle_status,
                'latest_run_id' => $latestRun?->run_id,
                'latest_run_status' => $latestRun?->status,
                'recovery_attempts' => $recoveryAttempts,
                'queue_job_present' => $hasJob,
                'evidence_complete' => (bool) ($eligibility['complete'] ?? false),
                'eligible' => $eligible,
                'reason_codes' => (array) ($eligibility['reason_codes'] ?? []),
            ];
        })->values()->all();

        $eligibleRows = collect($rows)->where('eligible', true)->values();
        if (! $this->option('apply')) {
            return $this->report([
                'action' => 'dry_run',
                'replay_liveness' => $probe,
                'rows' => $rows,
                'eligible_count' => $eligibleRows->count(),
            ]);
        }

        if ($eligibleRows->isEmpty()) {
            return $this->report([
                'action' => 'nothing_to_finalize',
                'replay_liveness' => $probe,
                'rows' => $rows,
                'eligible_count' => 0,
            ]);
        }

        try {
            $approval = $approvals->requireForApply(
                'finalize-lab-technical-quarantine',
                $this->option('approved-by'),
                $this->option('approval-reason'),
                [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'generation' => $generationNumber,
                    'agent_ids' => $eligibleRows->pluck('agent_id')->all(),
                    'replay_liveness' => $probe,
                    'promotion_evidence' => false,
                ],
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $finalized = [];
        foreach ($eligibleRows as $row) {
            $agent = LabAgent::query()->with(['generation', 'modelVersion'])->find((int) $row['agent_id']);
            if (! $agent || $agent->lifecycle_status !== 'evaluation_error') continue;

            $metadata = (array) ($agent->modelVersion?->metadata ?? []);
            data_set($metadata, 'technical_quarantine_finalization', [
                'protocol' => 'bounded_evaluator_recovery_v1',
                'finalized_at' => now()->utc()->toIso8601String(),
                'reason_code' => 'EVALUATOR_RECOVERY_EXHAUSTED',
                'recovery_attempts' => (int) data_get($metadata, 'evaluator_recovery_attempts', 0),
                'promotion_evidence' => false,
            ]);
            $agent->modelVersion?->update(['metadata' => $metadata]);
            $agent->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Technical quarantine finalized after bounded evaluator recovery; strategy verdict remains withheld.',
            ]);
            $evidence->recordLifecycle($agent->fresh(), 'technical_quarantine_finalized', [
                'protocol' => 'bounded_evaluator_recovery_v1',
                'reason_code' => 'EVALUATOR_RECOVERY_EXHAUSTED',
                'latest_run_id' => $row['latest_run_id'],
                'promotion_evidence' => false,
            ], 'screening', $row['latest_run_id'], (int) $row['recovery_attempts'], self::class);
            $handoffs->record(
                $agent->generation()->first(),
                $agent,
                'evaluation_error_quarantined',
                'completed',
                'EVALUATOR_RECOVERY_EXHAUSTED',
                ['recovery_attempts' => (int) $row['recovery_attempts'], 'promotion_evidence' => false],
            );
            $finalized[] = (int) $agent->id;
        }

        $generationIds = LabAgent::query()->whereIn('id', $finalized)->pluck('lab_generation_id')->unique();
        foreach ($generationIds as $generationId) {
            $generation = LabGeneration::query()->with('agents')->find((int) $generationId);
            if (! $generation) continue;
            $open = $generation->agents->contains(fn (LabAgent $agent): bool => in_array(
                (string) $agent->lifecycle_status,
                ['draft', 'queued', 'screening', 'training', 'evaluation_error', 'full_queued', 'full_validation'],
                true,
            ));
            if (! $open) {
                $context = (array) ($generation->trigger_context ?? []);
                data_set($context, 'evaluation_error_quarantine.protocol', 'bounded_evaluator_recovery_v1');
                data_set($context, 'evaluation_error_quarantine.finalized_at', now()->utc()->toIso8601String());
                data_set($context, 'evaluation_error_quarantine.agent_ids', $generation->agents
                    ->where('lifecycle_status', 'technical_quarantine')->pluck('id')->values()->all());
                data_set($context, 'evaluation_error_quarantine.promotion_evidence', false);
                $generation->update([
                    'status' => 'technical_quarantine',
                    'completed_at' => now(),
                    'trigger_context' => $context,
                ]);
                $reports->record($generation->fresh(), 'screening_technical_quarantine_finalized');
            }
        }

        return $this->report([
            'action' => 'applied',
            'replay_liveness' => $probe,
            'operator_approval' => $approval,
            'finalized_agent_ids' => $finalized,
            'promotion_evidence' => false,
        ]);
    }

    /** @return array<int, string> */
    private function labQueues(): array
    {
        return array_values(array_unique([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function report(array $payload): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($payload['action'] ?? 'unknown').': '.count((array) ($payload['rows'] ?? $payload['finalized_agent_ids'] ?? [])).' row(s).');
        }

        return self::SUCCESS;
    }
}
