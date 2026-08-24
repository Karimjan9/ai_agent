<?php

namespace App\Jobs;

use App\Models\CandidateGateDecision;
use App\Models\AgentLearningEpisode;
use App\Models\LabAgent;
use App\Services\AgentKnowledgeService;
use App\Services\AdversarialCoEvolutionService;
use App\Services\AgentProgressCardService;
use App\Services\FailureRepairAnchorService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LearningLaneService;
use App\Services\LearningKernelService;
use App\Services\LearningReceiptService;
use App\Services\MutationResponseMapService;
use App\Services\ParentAwareCreditService;
use App\Services\ProvisionalSkillCartridgeService;
use App\Services\SkillMentorService;
use App\Services\SkillZooService;
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
        LearningKernelService $learningKernel,
        LearningReceiptService $learningReceipts,
        SkillZooService $skillZoo,
        AdversarialCoEvolutionService $adversarialMarket,
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
        $skillZooEntry = $skillZoo->record($agent->fresh(['modelVersion']), $result, $screeningResponseMap);
        if ($skillZooEntry !== null) $result['skill_zoo_entry'] = $skillZooEntry;
        // Planning a bounded scenario creates no replay or queue job. The
        // existing sealed red-team worker remains the only execution path.
        $result['adversarial_scenarios'] = $adversarialMarket->plan($agent->fresh(['modelVersion']));
        $cartridge = $cartridges->record(
            $agent->fresh(['modelVersion']),
            $result,
            (array) data_get($result, 'mutation_observability', data_get($agent->modelVersion->metadata, 'mutation_observability', [])),
            (array) data_get($result, 'mutation_observability.control_relative', []),
        );
        if ($cartridge !== null) {
            $result['provisional_skill_cartridge'] = $cartridge;
        }
        $pairProjection = $learningLane->pairScreeningObservation(
            $agent->fresh(['modelVersion', 'generation']),
            $result,
            $screeningResponseMap,
        );
        $pair = is_array($pairProjection) && filled($pairProjection['id'])
            ? \App\Models\LabLearningLanePair::find((int) $pairProjection['id'])
            : null;
        $learningReceipts->settle($agent->fresh(['modelVersion']), $result, $pair);
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

        // The retrieval packet was created before mutation selection and
        // consumed only after the child identity existed.  Settle that exact
        // decision at screening so consumed lessons are connected to an
        // outcome rather than accumulating as orphaned advice.  This remains
        // research evidence; it does not grant a promotion or skill claim.
        $freshModel = $agent->fresh(['modelVersion'])->modelVersion;
        $episodeId = (int) data_get($freshModel?->metadata, 'learning_decision.episode_id', 0);
        $episode = $episodeId > 0 ? AgentLearningEpisode::find($episodeId) : null;
        if ($episode) {
            $learningKernel->settleOutcome($episode, [
                'source_key' => 'screening-decision:'.$agent->id.':'.$this->runId,
                'source_type' => LabAgent::class,
                'source_id' => $agent->id,
                'outcome_status' => 'settled',
                'failure_class' => data_get($result, 'mutation_observability.declared_target', data_get($freshModel?->metadata, 'generation_target', 'profit_factor')),
                'parameter_key' => data_get($freshModel?->metadata, 'learning_decision.selected_gene'),
                'metrics' => $result,
            ]);
            $metadata = (array) $freshModel->metadata;
            $metadata['learning_decision']['outcome_status'] = 'screening_settled';
            $metadata['learning_decision']['settled_evidence_run_id'] = $this->runId;
            $freshModel->update(['metadata' => $metadata]);
        }
    }
}
