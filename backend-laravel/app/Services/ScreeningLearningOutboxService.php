<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LearningRecoveryEvent;
use App\Models\ScreeningLearningOutbox;

/** Screening facts and gate decisions are durable before optional learning writes run. */
class ScreeningLearningOutboxService
{
    /** A terminal incomplete projection cannot become a retryable lesson.
     * Keep the dependency separate from original replay/outbox facts. */
    public function recordEvidenceDependency(LabAgent $agent, array $result, array $eligibility): void
    {
        $runId = data_get($result, 'evidence_run_id');
        LearningRecoveryEvent::query()->firstOrCreate([
            'event_key' => 'screening-evidence:'.$agent->id.':'.(is_string($runId) ? $runId : 'missing'),
        ], [
            'source_type' => self::class, 'source_key' => (string) $agent->id,
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
            'status' => 'blocked_dependency', 'action' => 'await_authorized_complete_evidence',
            'reason' => 'IMMUTABLE_LEARNING_EVIDENCE_INCOMPLETE',
            'metadata' => ['protocol' => 'immutable_learning_evidence_dependency_v1',
                'kind' => 'immutable_evidence_dependency', 'evidence' => $eligibility,
                'same_run_settlement_retry' => false, 'may_dispatch_replay' => false, 'promotion_evidence' => false],
        ]);
    }

    public function enqueue(LabAgent $agent, array $result, float $forwardScore): void
    {
        $existing = ScreeningLearningOutbox::query()->where('lab_agent_id', $agent->id)->first();
        if ($existing && in_array($existing->status, ['completed', 'blocked_dependency'], true)
            && data_get($existing->screen_result, 'evidence_run_id') === data_get($result, 'evidence_run_id')) {
            return;
        }
        ScreeningLearningOutbox::updateOrCreate(['lab_agent_id' => $agent->id], [
            'model_version_id' => $agent->model_version_id, 'screen_result' => $result, 'forward_score' => $forwardScore,
            'status' => 'pending', 'available_at' => now(), 'last_error' => null,
        ]);
    }

    public function process(int $limit = 100): int
    {
        $processed = 0;
        ScreeningLearningOutbox::query()->whereIn('status', ['pending', 'retry'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get()->each(function (ScreeningLearningOutbox $outbox) use (&$processed): void {
                $agent = LabAgent::with('modelVersion')->find($outbox->lab_agent_id);
                if (! $agent || ! $agent->modelVersion) { $outbox->update(['status' => 'discarded', 'processed_at' => now()]); return; }
                try {
                    $evidence = app(LabImmutableEvidenceService::class);
                    $runId = data_get($outbox->screen_result, 'evidence_run_id');
                    $run = $evidence->findRun(is_string($runId) ? $runId : null);
                    $eligibility = $evidence->learningEligibility($run);
                    if ($run && ((int) $run->lab_agent_id !== (int) $agent->id
                        || (int) $run->model_version_id !== (int) $agent->model_version_id
                        || (int) $run->lab_generation_id !== (int) $agent->lab_generation_id)) {
                        $eligibility['complete'] = false;
                        $eligibility['reason_codes'][] = 'SCREENING_EVIDENCE_OWNER_MISMATCH';
                    }
                    if (! $eligibility['complete']) {
                        $waiting = ! in_array('SCREENING_EVIDENCE_OWNER_MISMATCH', $eligibility['reason_codes'], true)
                            && $run !== null && ! $evidence->isTerminalRun($run);
                        $outbox->update(['status' => $waiting ? 'retry' : 'blocked_dependency',
                            'attempts' => $outbox->attempts + ($waiting ? 0 : 1),
                            'processed_at' => $waiting ? null : now(),
                            'available_at' => $waiting ? now()->addMinute() : null,
                            'last_error' => json_encode(['protocol' => 'immutable_learning_evidence_dependency_v1',
                                'kind' => 'immutable_evidence_dependency', 'evidence' => $eligibility,
                                'same_run_settlement_retry' => $waiting, 'promotion_evidence' => false], JSON_THROW_ON_ERROR)]);

                        return;
                    }
                    $recorded = app(ScreeningLearningService::class)->record($agent, $agent->modelVersion, $outbox->screen_result, (float) $outbox->forward_score);
                    if (! $recorded) {
                        $outbox->update([
                            'status' => 'blocked',
                            'attempts' => $outbox->attempts + 1,
                            'processed_at' => now(),
                            'last_error' => 'LEARNING_EVIDENCE_INCOMPLETE: request, response, decision trace and complete trade ledger are required.',
                        ]);

                        return;
                    }
                    $outbox->update(['status' => 'completed', 'attempts' => $outbox->attempts + 1, 'processed_at' => now(), 'last_error' => null]);
                    $processed++;
                } catch (\Throwable $exception) {
                    $attempts = $outbox->attempts + 1;
                    $outbox->update(['status' => 'retry', 'attempts' => $attempts, 'last_error' => substr($exception->getMessage(), 0, 1000),
                        'available_at' => now()->addMinutes(min(60, max(1, $attempts * 2)))]);
                }
            });
        return $processed;
    }
}
