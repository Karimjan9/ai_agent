<?php

namespace App\Jobs;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Services\AgentKnowledgeService;
use App\Services\AgentProgressCardService;
use App\Services\FailureRepairAnchorService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LearningLaneService;
use App\Services\MutationResponseMapService;
use App\Services\ParentAwareCreditService;
use App\Services\ProvisionalSkillCartridgeService;
use App\Services\SkillMentorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Projects secondary learning cards after the immutable screening run closes.
 *
 * Gate/evidence/lifecycle writes deliberately stay synchronous in
 * LabAgentEvaluationService. This job only moves the expensive, retryable
 * projections off the replay HTTP critical path; it cannot promote an agent
 * and it is idempotent per (agent, evidence run).
 */
class ProcessLabScreeningLearningProjection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 3;
    public int $timeout = 300;
    public int $uniqueFor = 86400;

    public function __construct(
        public int $labAgentId,
        public string $runId,
        public int $decisionId,
        public array $screenProjection,
    ) {
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue((string) config('services.lab_queue.learning_queue', 'lab-learning'));
    }

    public function uniqueId(): string
    {
        return "lab-screen-learning:{$this->labAgentId}:{$this->runId}";
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(600),
        ];
    }

    public function handle(
        LabImmutableEvidenceService $evidence,
        FailureRepairAnchorService $repairAnchors,
        SkillMentorService $mentors,
        MutationResponseMapService $responseMap,
        LearningLaneService $learningLane,
        ParentAwareCreditService $parentCredit,
        ProvisionalSkillCartridgeService $cartridges,
        AgentProgressCardService $progressCards,
        AgentKnowledgeService $knowledge,
    ): void {
        $agent = LabAgent::with('modelVersion', 'generation')->find($this->labAgentId);
        $decision = CandidateGateDecision::find($this->decisionId);
        if (! $agent || ! $agent->modelVersion || ! $decision) {
            return;
        }

        $run = $evidence->findRun($this->runId);
        if (! $run || (string) $run->status !== 'completed') {
            // A technical/incomplete run is not allowed to teach the
            // mutation/compiler lane. It remains recoverable evidence only.
            return;
        }

        $result = [...$this->screenProjection, 'evidence_run_id' => $this->runId];
        if ((int) data_get($agent->modelVersion->metadata, 'repair_anchor.id', 0) > 0) {
            $repairAnchors->recordRepairScreeningOutcome($agent, $result);
        } elseif ((string) $decision->decision === 'failed') {
            $repairAnchors->recordFromScreeningDecision($agent, $decision, $result);
        }

        $mentors->markScreenValidatedSeed(
            $agent->fresh(['modelVersion']),
            (string) $decision->decision === 'passed',
            $result,
        );
        $screeningResponseMap = $responseMap->recordScreening(
            $agent->fresh(['modelVersion']),
            $result,
        );
        $cartridge = $cartridges->record(
            $agent->fresh(['modelVersion']),
            $result,
            (array) data_get($result, 'mutation_observability', data_get($agent->modelVersion->metadata, 'mutation_observability', [])),
            (array) data_get($result, 'mutation_observability.control_relative', []),
        );
        if ($cartridge !== null) {
            $result['provisional_skill_cartridge'] = $cartridge;
        }
        $learningLane->pairScreeningObservation(
            $agent->fresh(['modelVersion', 'generation']),
            $result,
            $screeningResponseMap,
        );
        $credit = $parentCredit->recordScreening(
            $agent->fresh(['modelVersion']),
            $result,
            (string) $decision->decision,
        );
        $screenModel = $agent->fresh(['modelVersion'])->modelVersion;
        if ($screenModel) {
            $metadata = (array) $screenModel->metadata;
            data_set($metadata, 'parent_aware_evolution.last_screening_credit', $credit);
            $screenModel->update(['metadata' => $metadata]);
        }

        $progressCards->sync(
            $agent->fresh(['modelVersion', 'generation']),
            null,
            $result,
            $decision,
        );
        $knowledge->recordScreening(
            $agent->fresh(['modelVersion', 'generation']),
            $result,
            $this->runId,
        );
    }
}
