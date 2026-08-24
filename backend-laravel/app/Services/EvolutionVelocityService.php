<?php

namespace App\Services;

use App\Models\AdversarialValidatorFinding;
use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabGeneration;
use App\Models\LabMutationAction;
use App\Models\LabMutationResponseMap;
use App\Models\LabSkillZooEntry;
use Illuminate\Support\Facades\Schema;

/**
 * A read-only learning-first scorecard.  It intentionally measures sealed
 * knowledge and behavioural diversity, not raw population throughput.
 */
class EvolutionVelocityService
{
    public const PROTOCOL = 'evolution_velocity_scorecard_v1';

    /** @return array<string, mixed> */
    public function snapshot(AiLaboratory $lab, int $lookback = 20): array
    {
        if (! $this->available()) return $this->empty('migration_pending');
        try {
            $generations = LabGeneration::query()->where('ai_laboratory_id', $lab->id)->latest('generation')->limit(max(1, $lookback))->get();
            $generationIds = $generations->pluck('id');
            $agents = $generationIds->isEmpty() ? collect() : LabAgent::with('modelVersion')
                ->whereIn('lab_generation_id', $generationIds)->get();
            $scope = fn ($query) => $query->where('symbol', strtoupper($lab->symbol))->where('timeframe', strtoupper($lab->timeframe));
            $archive = $scope(LabEvolutionArchiveEntry::query())->where('archive_type', 'behavioral_map_elites')->get();
            $responses = $scope(LabMutationResponseMap::query())->get();
            $lessons = $scope(AgentLearningLesson::query())->get();
            $credits = $scope(LabEvolutionCreditEvent::query())->where('event_type', 'descendant_trait')->get();
            $skillZoo = Schema::hasTable('lab_skill_zoo_entries') ? $scope(LabSkillZooEntry::query())->get() : collect();
            $mutationActions = Schema::hasTable('lab_mutation_actions') ? $scope(LabMutationAction::query())->get() : collect();
            $completed = $agents->filter(fn (LabAgent $agent): bool => in_array((string) $agent->lifecycle_status, ['screened', 'replay_complete', 'forward_validated', 'completed', 'rejected', 'failed', 'overfit', 'stagnated'], true));
            $receipts = $agents->map(fn (LabAgent $agent): array => (array) data_get($agent->modelVersion?->metadata, 'learning_receipt', []))
                ->filter(fn (array $receipt): bool => data_get($receipt, 'protocol') === LearningReceiptService::PROTOCOL);
            $settled = $receipts->filter(fn (array $receipt): bool => in_array((string) data_get($receipt, 'settlement.status'), ['provisional', 'harmful', 'no_effect', 'context_mismatch'], true));
            $confirmedSkills = $lessons->whereIn('status', ['confirmed', 'skill_mentor', 'full_parent'])->count();
            $confirmedResponses = $responses->whereIn('status', ['confirmed', 'independently_confirmed', 'validated'])->count();
            $novel = $archive->where('novelty_score', '>', 0)->count();
            $duplicate = $archive->count() - $novel;
            $failure = $completed->filter(fn (LabAgent $agent): bool => in_array((string) $agent->lifecycle_status, ['rejected', 'failed', 'overfit', 'stagnated'], true))->count();
            $modelIds = $agents->pluck('model_version_id')->filter()->unique();
            $adversarial = Schema::hasTable('adversarial_validator_findings') && $modelIds->isNotEmpty()
                ? AdversarialValidatorFinding::query()->whereIn('model_version_id', $modelIds)->get()
                : collect();
            $confirmedDescendants = $credits->where('status', 'independently_confirmed')->count();
            $confirmedZooSkills = $skillZoo->where('status', 'confirmed')->count();
            $settledActions = $mutationActions->where('status', 'settled');
            $computeHours = (float) $settledActions->sum(fn (LabMutationAction $action): float => (float) data_get($action->reward, 'compute_cost', 0));

            return [
                'protocol' => self::PROTOCOL, 'status' => 'available',
                'scope' => ['symbol' => strtoupper($lab->symbol), 'timeframe' => strtoupper($lab->timeframe), 'lab_id' => $lab->id],
                'lookback_generations' => $generations->count(), 'population_observed' => $agents->count(),
                'archive_coverage_growth' => ['new_behavioral_cells' => $novel, 'behavioral_cells' => $archive->count(), 'growth_per_generation' => round($novel / max(1, $generations->count()), 4)],
                'new_confirmed_skills_per_100_experiments' => round((($confirmedSkills + $confirmedResponses + $confirmedZooSkills) / max(1, $completed->count())) * 100, 4),
                'settled_learning_receipts_per_completed_experiment' => round($settled->count() / max(1, $completed->count()), 4),
                'settled_knowledge_per_compute_hour' => $computeHours > 0 ? round(($settled->count() + $settledActions->count()) / $computeHours, 4) : null,
                'behavioral_duplicate_rate' => round($duplicate / max(1, $archive->count()), 4),
                'repeat_failure_rate' => round($failure / max(1, $completed->count()), 4),
                'descendant_success_rate' => round($confirmedDescendants / max(1, $credits->count()), 4),
                'cross_niche_transfer_rate' => ['status' => 'not_claimed_without_sealed_cross_niche_ablation', 'value' => null],
                'skill_retention_in_inheritance' => ['issued_receipts' => $receipts->count(), 'settled_receipts' => $settled->count(), 'rate' => round($settled->count() / max(1, $receipts->count()), 4)],
                'adversarial_survival_rate' => ['observed' => $adversarial->count(), 'passed' => $adversarial->where('verdict', 'passed')->count(), 'rate' => round($adversarial->where('verdict', 'passed')->count() / max(1, $adversarial->count()), 4)],
                'surrogate_prediction_hit_rate' => ['status' => 'not_claimed_without_sealed_surrogate_predictions', 'value' => null],
                'time_to_first_causal_evidence' => $this->timeToFirstCausalEvidence($generations, $responses),
                'generation_knowledge_rate' => round($generations->filter(fn (LabGeneration $generation): bool => $agents->where('lab_generation_id', $generation->id)->contains(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'learning_receipt.settlement.status') !== null))->count() / max(1, $generations->count()), 4),
                'north_star' => [
                    'name' => 'validated_new_knowledge_artifacts_times_behavioral_diversity',
                    'validated_new_knowledge_artifacts' => $confirmedSkills + $confirmedResponses + $confirmedZooSkills + $confirmedDescendants,
                    'validated_behavioral_diversity' => $novel,
                    'value' => ($confirmedSkills + $confirmedResponses + $confirmedZooSkills + $confirmedDescendants) * $novel,
                    'promotion_evidence' => false,
                ],
                'rule' => 'Throughput is diagnostic only. No scorecard field can select a parent, dispatch a replay, or promote a champion.',
                'promotion_evidence' => false,
            ];
        } catch (\Throwable) {
            return $this->empty('unavailable');
        }
    }

    private function timeToFirstCausalEvidence($generations, $responses): ?int
    {
        $first = $responses->first(fn (LabMutationResponseMap $row): bool => in_array((string) $row->status, ['confirmed', 'independently_confirmed', 'validated'], true));
        if (! $first) return null;
        $generation = $generations->firstWhere('id', $first->lab_generation_id);
        return $generation ? (int) $generation->generation : null;
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('lab_evolution_archive_entries') && Schema::hasTable('lab_mutation_response_maps')
                && Schema::hasTable('agent_learning_lessons') && Schema::hasTable('lab_evolution_credit_events');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function empty(string $status): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'promotion_evidence' => false];
    }
}
