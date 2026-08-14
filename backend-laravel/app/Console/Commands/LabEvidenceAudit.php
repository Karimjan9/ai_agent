<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGateDecisionEvent;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\LabLearningConsumptionEvent;
use App\Models\LabLearningInsight;
use App\Models\LabMutationCreditEvent;
use Illuminate\Console\Command;

class LabEvidenceAudit extends Command
{
    protected $signature = 'trading:lab-evidence-audit {symbol?} {--timeframe=} {--generation=} {--json}';
    protected $description = 'Audit immutable laboratory history completeness without changing gates or dispatching work';

    public function handle(): int
    {
        $query = LabGeneration::query()->with('laboratory', 'agents');
        if ($symbol = $this->argument('symbol')) {
            $query->whereHas('laboratory', fn ($q) => $q->where('symbol', strtoupper((string) $symbol)));
        }
        if ($timeframe = $this->option('timeframe')) {
            $query->whereHas('laboratory', fn ($q) => $q->where('timeframe', strtoupper((string) $timeframe)));
        }
        if ($generation = $this->option('generation')) $query->where('generation', (int) $generation);

        $rows = $query->orderByDesc('id')->get()->map(fn (LabGeneration $generation): array => $this->auditGeneration($generation))->values()->all();
        if ($this->option('json')) {
            $this->line(json_encode(['protocol' => 'lab_immutable_evidence_audit_v1', 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        if ($rows === []) { $this->warn('Audit uchun generation topilmadi.'); return self::SUCCESS; }
        $this->table(['Market', 'G', 'Status', 'Agents', 'Exact create %', 'Any event %', 'Runs', 'Terminal %', 'Gate events', 'Trace complete', 'Verdict'], array_map(fn (array $row): array => [
            $row['symbol'], $row['generation'], $row['status'], $row['agent_count'],
            $row['exact_creation_coverage_percent'], $row['agent_event_coverage_percent'],
            $row['evaluation_run_count'], $row['terminal_run_coverage_percent'],
            $row['gate_decision_event_count'], $row['decision_trace_complete_runs'].'/'.$row['response_runs'],
            $row['history_verdict'],
        ], $rows));
        return self::SUCCESS;
    }

    private function auditGeneration(LabGeneration $generation): array
    {
        $agents = $generation->agents;
        $ids = $agents->pluck('id')->all();
        $events = LabLifecycleEvent::query()->where('lab_generation_id', $generation->id)->get();
        $runs = LabEvaluationRun::query()->where('lab_generation_id', $generation->id)->get();
        $artifacts = LabEvidenceArtifact::query()->where('lab_generation_id', $generation->id)->get();
        $createdAgents = $events->where('event_type', 'agent_created')->pluck('lab_agent_id')->filter()->unique()->count();
        $seenAgents = $events->pluck('lab_agent_id')->filter()->unique()->count();
        $terminalRuns = $runs->whereIn('status', ['completed', 'technical_error', 'retry_released', 'skipped', 'legacy_snapshot'])->count();
        // A middleware release is a queue deferral, not an evaluator replay.
        // A projection-only skip is also not a replay: it records an
        // idempotent duplicate recovery attempt after a valid screen result
        // was already persisted. Newer jobs do not create these rows, but
        // older immutable rows must not be mistaken for response evidence
        // merely because their operational envelope has a response hash.
        $queueDeferredRunIds = $runs->filter(fn (LabEvaluationRun $run): bool => $run->status === 'retry_released'
            && data_get($run->metadata, 'reason_code') === 'QUEUE_MIDDLEWARE_RELEASE')
            ->pluck('run_id')->filter()->unique();
        $projectionOnlyRunIds = $runs->filter(fn (LabEvaluationRun $run): bool => $run->status === 'skipped'
            && data_get($run->metadata, 'projection_only') === true
            && data_get($run->metadata, 'reason_code') === 'SCREEN_RESULT_ALREADY_PERSISTED')
            ->pluck('run_id')->filter()->unique();
        $replayRuns = $runs->reject(fn (LabEvaluationRun $run): bool => $queueDeferredRunIds->contains($run->run_id)
            || $projectionOnlyRunIds->contains($run->run_id));

        // A same-generation recovery appends a new immutable run. The old
        // technical/incomplete attempt remains valuable audit history, but it
        // must not poison the current evidence boundary after its agent has a
        // later complete replay. Promotion and learning consumers already use
        // the latest eligible run; make this audit report the same boundary.
        $currentReplayRuns = $replayRuns
            ->sortBy('id')
            ->groupBy(fn (LabEvaluationRun $run): string => $run->lab_agent_id !== null
                ? 'agent:'.$run->lab_agent_id
                : 'run:'.$run->run_id)
            ->map(fn ($agentRuns) => $agentRuns->last())
            ->values();
        $currentRunIds = $currentReplayRuns->pluck('run_id')->filter()->unique();
        $replayTerminalRuns = $currentReplayRuns->whereIn('status', ['completed', 'technical_error', 'retry_released', 'skipped', 'legacy_snapshot']);
        $completedReplayRuns = $currentReplayRuns->where('status', 'completed');
        $responseRunIds = $completedReplayRuns->filter(fn (LabEvaluationRun $run): bool => $run->response_hash !== null)->pluck('run_id')->filter()->unique();
        $responseRuns = $responseRunIds->count();
        $traceCompleteRunIds = $artifacts->where('artifact_type', 'decision_trace_manifest')
            ->whereIn('run_id', $currentRunIds->all())
            ->filter(fn (LabEvidenceArtifact $artifact): bool => (bool) data_get($artifact->metadata, 'complete', data_get($artifact->payload, 'complete', false)))
            ->pluck('run_id')->filter()->unique();
        $traceComplete = $traceCompleteRunIds->count();
        $ledgerCompleteRunIds = $artifacts->filter(fn (LabEvidenceArtifact $artifact): bool => in_array($artifact->artifact_type, ['trade_ledger', 'agent_trade_ledger'], true))
            ->whereIn('run_id', $currentRunIds->all())
            ->filter(fn (LabEvidenceArtifact $artifact): bool => (bool) data_get($artifact->metadata, 'complete', false))
            ->pluck('run_id')->filter()->unique();
        $requestRunIds = $artifacts->where('artifact_type', 'evaluation_request')
            ->whereIn('run_id', $currentRunIds->all())
            ->pluck('run_id')->filter()->unique();
        $runIdsWithLifecycle = $events->whereIn('run_id', $currentRunIds->all())->pluck('run_id')->filter()->unique();
        $orphanArtifactCount = $artifacts->filter(fn (LabEvidenceArtifact $artifact): bool => $artifact->run_id !== null && ! $runs->pluck('run_id')->contains($artifact->run_id))->count();
        $queueAttempts = $events->where('event_type', 'queue_attempt_started')->count();
        $releasedAttempts = $runs->where('status', 'retry_released')->count();
        $legacy = $runs->where('status', 'legacy_snapshot')->count();
        $agentCount = max(1, $agents->count());
        $terminalCoverage = $runs->count() === 0 ? 0 : round(($terminalRuns / $runs->count()) * 100, 1);
        $exactCoverage = round(($createdAgents / $agentCount) * 100, 1);
        $eventCoverage = round(($seenAgents / $agentCount) * 100, 1);
        $strictTraceRequired = $responseRuns > 0;
        $strictResponseEvidence = $responseRunIds->diff($requestRunIds)->isEmpty()
            && $responseRunIds->diff($traceCompleteRunIds)->isEmpty()
            && $responseRunIds->diff($ledgerCompleteRunIds)->isEmpty();
        $replayAgentIds = $replayRuns->pluck('lab_agent_id')->filter()->unique();
        $complete = $agents->count() > 0 && $createdAgents === $agents->count()
            && $currentReplayRuns->count() > 0 && $replayAgentIds->count() >= $agents->count()
            && $replayTerminalRuns->count() === $currentReplayRuns->count()
            && $completedReplayRuns->count() > 0
            && $runIdsWithLifecycle->count() >= $currentReplayRuns->count()
            && (! $strictTraceRequired || ($traceComplete >= $responseRuns && $strictResponseEvidence))
            && $orphanArtifactCount === 0
            && $legacy === 0;
        return [
            'generation_id' => $generation->id, 'symbol' => $generation->laboratory?->symbol,
            'timeframe' => $generation->laboratory?->timeframe, 'generation' => $generation->generation,
            'status' => $generation->status, 'agent_count' => $agents->count(),
            'exact_creation_coverage_percent' => $exactCoverage,
            'agent_event_coverage_percent' => $eventCoverage,
            'missing_agent_ids' => $agents->reject(fn ($agent) => $events->where('lab_agent_id', $agent->id)->isNotEmpty())->pluck('id')->values()->all(),
            'evaluation_run_count' => $runs->count(), 'replay_run_count' => $replayRuns->count(),
            'current_evidence_run_count' => $currentReplayRuns->count(),
            'superseded_replay_run_count' => max(0, $replayRuns->count() - $currentReplayRuns->count()),
            'projection_only_run_count' => $projectionOnlyRunIds->count(),
            'completed_replay_count' => $completedReplayRuns->count(),
            'queue_deferred_run_count' => $queueDeferredRunIds->count(),
            'terminal_run_count' => $terminalRuns,
            'terminal_run_coverage_percent' => $terminalCoverage,
            'response_runs' => $responseRuns, 'decision_trace_complete_runs' => $traceComplete,
            // Lifecycle coverage is a current evidence-boundary metric. The
            // denominator must exclude superseded immutable attempts, or a
            // successful same-generation recovery will appear incomplete
            // merely because its old technical run has no closing event.
            'run_lifecycle_coverage_percent' => $currentReplayRuns->count() === 0 ? 0 : round(($runIdsWithLifecycle->count() / $currentReplayRuns->count()) * 100, 1),
            'request_artifact_runs' => $requestRunIds->count(),
            'ledger_complete_runs' => $ledgerCompleteRunIds->count(),
            'queue_attempt_count' => $queueAttempts,
            'retry_released_run_count' => $releasedAttempts,
            'orphan_artifact_count' => $orphanArtifactCount,
            'gate_decision_event_count' => LabGateDecisionEvent::where('lab_generation_id', $generation->id)->count(),
            'mutation_credit_event_count' => LabMutationCreditEvent::where('lab_generation_id', $generation->id)->count(),
            'learning_insight_count' => LabLearningInsight::query()
                ->where('symbol', $generation->laboratory?->symbol)->where('timeframe', $generation->laboratory?->timeframe)->count(),
            'learning_consumption_event_count' => LabLearningConsumptionEvent::where('lab_generation_id', $generation->id)->count(),
            'artifact_count' => $artifacts->count(), 'legacy_snapshot_runs' => $legacy,
            'history_verdict' => $complete ? 'COMPLETE_FOR_NEW_RUNS' : ($legacy > 0 ? 'LEGACY_SNAPSHOT_ONLY_OR_INCOMPLETE' : 'INCOMPLETE'),
            'evolution_safe' => $complete,
        ];
    }
}
