<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningRetrieval;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;

/** The single canonical contract for observation, learning, retrieval and use. */
class LearningKernelService
{
    public function __construct(private LearningEpisodeService $episodes, private LearningOutcomeSettlementService $settlements, private LearningConsolidationService $consolidation, private LearningRetrievalService $retrieval, private LearningExperimentPlannerService $experiments, private LearningPulseService $pulse) {}
    public function openEpisode(?LabAgent $agent, array $decisionContext): AgentLearningEpisode|array { return $this->episodes->open($agent, $decisionContext); }
    public function recordObservation(AgentLearningEpisode|array $episode, array $observation): AgentLearningEpisode|array { return $this->episodes->recordObservation($episode, $observation); }
    /** @return array<string,mixed> */
    public function settleOutcome(AgentLearningEpisode|array $episode, array $outcome): array { $settlement = $this->settlements->settle($episode, $outcome); $linked = $episode instanceof AgentLearningEpisode ? $this->linkOutcome($episode) : 0; return ['settlement' => $settlement, 'retrievals_linked' => $linked, 'promotion_evidence' => false]; }
    /** @return array<string,mixed> */
    public function consolidate(mixed $settlement): array { return $this->consolidation->consolidate($settlement); }
    /** @return array<string,mixed> */
    public function retrieveForGeneration(string $symbol, string $timeframe, ?string $family, array $context = [], ?LabAgent $agent = null, ?int $episodeId = null): array { return $this->retrieval->retrieve($symbol, $timeframe, $family, $context, $agent, $episodeId); }
    /** @return array<string,mixed> */
    public function proposeExperiment(array $packet, string $target, array $legalGenes = []): array { return $this->experiments->propose($packet, $target, $legalGenes); }
    /** @return array<string,mixed> */
    public function recordConsumption(array $packet, array $experiment, ?LabAgent $agent = null, ?AgentLearningEpisode $episode = null): array
    {
        if (! Schema::hasTable('agent_learning_retrievals')) return ['status' => 'unavailable'];
        $ids = array_merge(array_column((array) ($packet['positive_lessons'] ?? []), 'retrieval_id'), array_column((array) ($packet['harmful_lessons'] ?? []), 'retrieval_id'), array_column((array) ($packet['uncertainty_lessons'] ?? []), 'retrieval_id'));
        $usedGene = $experiment['parameter_key'] ?? null; $updated = 0;
        foreach (AgentLearningRetrieval::query()->whereIn('retrieval_id', array_filter($ids))->get() as $row) {
            $lessonGene = data_get($row->metadata, 'parameter_key');
            $state = $usedGene !== null && $lessonGene === $usedGene ? 'consumed' : 'rejected';
            $row->update(['retrieval_state' => $state, 'reason_code' => $state === 'consumed' ? 'EXPERIMENT_GENE_SELECTED' : 'NOT_SELECTED_OR_BLOCKED', 'lab_agent_id' => $agent?->id ?? $row->lab_agent_id, 'episode_id' => $episode?->id ?? $row->episode_id, 'consumed_at' => $state === 'consumed' ? now() : null]); $updated++;
        }
        return ['status' => 'recorded', 'retrievals_updated' => $updated, 'promotion_evidence' => false];
    }
    /** Link actually used lessons back to the settled decision outcome. */
    public function linkOutcome(AgentLearningEpisode $episode): int { return AgentLearningRetrieval::query()->where('episode_id', $episode->id)->where('retrieval_state', 'consumed')->whereNull('outcome_linked_at')->update(['outcome_linked_at' => now()]); }
    /** @return array<string,mixed> */
    public function pulse(string $symbol, string $timeframe, ?string $family = null): array { return $this->pulse->pulse($symbol, $timeframe, $family); }
}
