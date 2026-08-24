<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Collection;

class GenerationLearningConsumptionReconciliationService
{
    public const PROTOCOL = 'canonical_retrieval_provenance_reconciliation_v1';

    public function __construct(private LearningKernelService $kernel) {}

    /** @return array<string, mixed> */
    public function reconcile(LabGeneration $generation): array
    {
        if (! in_array((string) $generation->status, ['draft', 'queued', 'screening', 'screened'], true)) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'blocked',
                'reason' => 'GENERATION_NOT_IN_PRE_OUTCOME_RECONCILIATION_WINDOW',
                'generation_id' => (int) $generation->id,
                'promotion_evidence' => false,
            ];
        }

        $rows = [];
        foreach ($generation->agents()->with('modelVersion')->orderBy('id')->get() as $agent) {
            $selectedGene = (string) data_get($agent->modelVersion?->metadata, 'learning_decision.selected_gene', '');
            if ($selectedGene === '') {
                continue;
            }

            $canonicalLessonIds = $this->eligibleCanonicalLessonIds($agent, $selectedGene);
            if ($canonicalLessonIds->isEmpty()) {
                continue;
            }
            $alreadyConsumed = AgentLearningRetrieval::query()
                ->where('lab_agent_id', $agent->id)
                ->where('retrieval_state', 'consumed')
                ->where('metadata->provenance', 'canonical_settled')
                ->whereIn('agent_learning_lesson_id', $canonicalLessonIds)
                ->exists();
            if ($alreadyConsumed) {
                $rows[] = ['agent_id' => (int) $agent->id, 'selected_gene' => $selectedGene, 'status' => 'already_consumed'];

                continue;
            }

            $priorRetrieval = AgentLearningRetrieval::query()->where('lab_agent_id', $agent->id)->oldest('id')->first();
            $episodeId = (int) data_get($agent->modelVersion?->metadata, 'learning_decision.episode_id', 0);
            $episode = $episodeId > 0 ? AgentLearningEpisode::query()->find($episodeId) : null;
            $context = (array) ($priorRetrieval?->context ?: data_get($episode?->decision_context, 'context', []));
            $packet = $this->kernel->retrieveForGeneration(
                $agent->symbol,
                $agent->timeframe,
                $agent->strategy_family,
                $context,
                $agent,
                $episode?->id,
            );
            $retrieved = collect([
                ...((array) data_get($packet, 'positive_lessons', [])),
                ...((array) data_get($packet, 'harmful_lessons', [])),
                ...((array) data_get($packet, 'uncertainty_lessons', [])),
            ]);
            $matchedLessonIds = $retrieved
                ->filter(fn (array $lesson): bool => (string) data_get($lesson, 'provenance') === 'canonical_settled'
                    && (string) data_get($lesson, 'parameter_key') === $selectedGene
                    && $canonicalLessonIds->contains((int) data_get($lesson, 'lesson_id'))
                )
                ->pluck('lesson_id')->map(fn ($id): int => (int) $id)->unique()->values();
            // This pass runs after the immutable mutation already exists. It
            // may repair provenance visibility, but it must never write a
            // consumed row or upgrade the mutation to memory-guided.
            AgentLearningRetrieval::query()
                ->where('packet_id', data_get($packet, 'packet_id'))
                ->whereIn('agent_learning_lesson_id', $matchedLessonIds->all() ?: [0])
                ->get()
                ->each(function (AgentLearningRetrieval $retrieval) use ($agent, $episode): void {
                    $retrieval->update([
                        'lab_agent_id' => $agent->id,
                        'episode_id' => $episode?->id ?? $retrieval->episode_id,
                        'retrieval_state' => 'reconciled_post_hoc',
                        'reason_code' => 'POST_HOC_PROVENANCE_ONLY',
                        'consumed_at' => null,
                        'metadata' => [
                            ...((array) $retrieval->metadata),
                            'post_hoc_provenance' => true,
                            'causal_application' => false,
                            'promotion_evidence' => false,
                        ],
                    ]);
                });

            $metadata = (array) $agent->modelVersion->fresh()->metadata;
            $learningDecision = (array) data_get($metadata, 'learning_decision', []);
            $learningDecision['canonical_provenance_reconciliation'] = [
                'protocol' => self::PROTOCOL,
                'status' => $matchedLessonIds->isNotEmpty() ? 'provenance_reconciled_not_consumed' : 'no_compatible_canonical_lesson',
                'selected_gene' => $selectedGene,
                'canonical_lesson_ids' => $matchedLessonIds->all(),
                'reason' => 'STRUCTURED_CONTEXT_NORMALIZATION_REPAIR',
                'mutation_was_changed' => false,
                'reconciled_at' => now()->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $metadata['learning_decision'] = $learningDecision;
            $agent->modelVersion->update(['metadata' => $metadata]);
            $rows[] = [
                'agent_id' => (int) $agent->id,
                'selected_gene' => $selectedGene,
                'status' => $matchedLessonIds->isNotEmpty() ? 'provenance_only' : 'not_matched',
                'canonical_lesson_ids' => $matchedLessonIds->all(),
            ];
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'completed',
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'inspected_agents' => $generation->agents()->count(),
            'eligible_agents' => count($rows),
            'consumed_agents' => 0,
            'provenance_only_agents' => collect($rows)->where('status', 'provenance_only')->count(),
            'rows' => $rows,
            'mutation_was_changed' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return Collection<int, int> */
    private function eligibleCanonicalLessonIds(LabAgent $agent, string $selectedGene): Collection
    {
        return AgentLearningLesson::query()
            ->where('symbol', strtoupper($agent->symbol))
            ->where('timeframe', strtoupper($agent->timeframe))
            ->where('strategy_family', $agent->strategy_family)
            ->where('lesson_type', 'skill_lesson')
            ->whereIn('status', ['provisional', 'confirmed'])
            ->where('outcome', 'beneficial')
            ->where('parameter_key', $selectedGene)
            ->get()
            ->filter(function (AgentLearningLesson $lesson): bool {
                $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
                if ($pairId <= 0) {
                    return false;
                }
                $pair = LabLearningLanePair::query()->with('controlResponseMap')->find($pairId);
                if (! $pair || ! in_array((string) $pair->status, ['canonical_episode_settled', 'lesson_compiled', 'skill_confirmed'], true)) {
                    return false;
                }
                if (! $pair->isVerifiedControlPair()) {
                    return false;
                }

                return AgentLearningSettlement::query()
                    ->where('source_type', LabLearningLanePair::class)
                    ->where('source_id', $pairId)
                    ->where('evidence_state', 'positive')
                    ->where('hard_failure', false)
                    ->exists();
            })
            ->pluck('id')->map(fn ($id): int => (int) $id)->values();
    }
}
