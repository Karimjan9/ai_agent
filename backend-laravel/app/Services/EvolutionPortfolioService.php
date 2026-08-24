<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabEvolutionDirector;
use App\Models\LabEvolutionExtinctionPlan;
use App\Models\LabMutationResponseMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Pareto, multi-fidelity, surrogate, director and extinction policy layer. */
class EvolutionPortfolioService
{
    public const PROTOCOL = 'quality_diversity_evolution_operating_system_v1';
    public const DIRECTORS = ['exploitation', 'novelty', 'repair', 'architecture', 'adversarial'];

    /** @param array<int, array<string, mixed>> $candidates */
    public function paretoFront(array $candidates): array
    {
        return array_values(array_filter($candidates, function (array $candidate, int $i) use ($candidates): bool {
            foreach ($candidates as $j => $other) if ($i !== $j && $this->dominates($other, $candidate)) return false;
            return true;
        }, ARRAY_FILTER_USE_BOTH));
    }

    /** @return array<string, mixed> */
    public function minimalCriterion(array $candidate): array
    {
        $valid = (bool) data_get($candidate, 'data_valid', true);
        $trades = (int) data_get($candidate, 'total_trades', 0);
        $drawdown = (float) data_get($candidate, 'max_drawdown_percent', 0);
        $fatal = (bool) data_get($candidate, 'fatal_instability', false);
        $passed = $valid && $trades >= 3 && $drawdown <= 35 && ! $fatal;
        return ['protocol' => self::PROTOCOL, 'passed' => $passed, 'minimum_trades' => 3, 'maximum_drawdown_percent' => 35,
            'reason' => $passed ? 'stepping_stone_eligible' : 'minimum_safety_or_data_criterion_failed',
            'rule' => 'Passing protects a novel niche hypothesis for research only; it does not grant full replay, parent eligibility or promotion.', 'promotion_evidence' => false];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    public function multiFidelityAllocate(array $candidates, int $budget = 20): array
    {
        $ranked = collect($candidates)->map(function (array $candidate): array {
            $expected = max(0.0, (float) data_get($candidate, 'expected_improvement', 0));
            $uncertainty = max(.01, (float) data_get($candidate, 'uncertainty', 1));
            $novelty = max(0.0, (float) data_get($candidate, 'novelty', 0));
            $cost = max(.01, (float) data_get($candidate, 'compute_cost', 1));
            return [...$candidate, 'information_value' => round(($expected * $uncertainty * max(.05, $novelty)) / $cost, 6)];
        })->sortByDesc('information_value')->take(max(0, $budget))->values();
        return ['protocol' => self::PROTOCOL, 'stage_order' => ['static_validity', 'behavior_fingerprint', 'tiny_replay', 'representative_slices', 'full_replay', 'stress_replay', 'forward', 'paper'],
            'selected' => $ranked->all(), 'rule' => 'Allocation ranks only already-admissible candidates; frozen-control and all normal gates remain mandatory.', 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function surrogate(AiLaboratory $lab, array $virtualActions = []): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $rows = LabMutationResponseMap::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->get();
        $usable = $rows->filter(fn ($row): bool => in_array((string) $row->status, ['confirmed', 'independently_confirmed', 'validated', 'positive', 'harmful', 'behavioral_duplicate'], true));
        if ($usable->count() < 10) return ['protocol' => self::PROTOCOL, 'status' => 'insufficient_sealed_observations', 'training_rows' => $usable->count(), 'promotion_evidence' => false];
        $predictions = collect($virtualActions)->map(function (array $action) use ($usable): array {
            $matches = $usable->where('parameter_key', data_get($action, 'gene_key'))->where('target', data_get($action, 'target'));
            $sample = $matches->isNotEmpty() ? $matches : $usable;
            $deltas = $sample->map(fn ($row): float => (float) data_get($row->target_delta, 'delta', 0));
            return [...$action, 'predicted_delta' => round((float) $deltas->avg(), 6), 'uncertainty' => round(1 / sqrt(max(1, $sample->count())), 6), 'sample_size' => $sample->count()];
        })->sortByDesc('uncertainty')->take(500)->values()->all();
        return ['protocol' => self::PROTOCOL, 'status' => 'research_ranker_ready', 'training_rows' => $usable->count(), 'virtual_proposals' => $predictions,
            'rule' => 'The surrogate ranks bounded proposals only. It cannot replace a replay, fabricate evidence, or directly create a generation.', 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function directorPlan(AiLaboratory $lab): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $directors = collect(self::DIRECTORS)->map(function (string $type) use ($lab): array {
            $key = hash('sha256', implode('|', [self::PROTOCOL, $lab->id, $type]));
            $row = LabEvolutionDirector::query()->firstOrCreate(['director_key' => $key], ['symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'director_type' => $type,
                'policy' => ['protocol' => self::PROTOCOL, 'director' => $type, 'promotion_evidence' => false], 'budget_share' => .2, 'status' => 'active']);
            return ['id' => $row->id, 'director' => $type, 'reward_score' => $row->reward_score, 'budget_share' => $row->budget_share];
        });
        return ['protocol' => self::PROTOCOL, 'status' => 'five_directors_ready', 'directors' => $directors->all(),
            'reward' => 'settled_knowledge_per_compute + archive_coverage_growth + robust_improvement + descendant_success - duplicates - repeated_failures', 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function extinctionPlan(AiLaboratory $lab, string $islandKey, array $metrics): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $stalled = (float) data_get($metrics, 'archive_growth', 0) <= 0 && (float) data_get($metrics, 'novelty', 0) <= 0
            && (float) data_get($metrics, 'causal_improvements', 0) <= 0 && (float) data_get($metrics, 'duplicate_rate', 0) >= .5;
        $key = hash('sha256', json_encode([self::PROTOCOL, $lab->id, $islandKey, $metrics], JSON_UNESCAPED_SLASHES));
        $plan = LabEvolutionExtinctionPlan::query()->firstOrCreate(['plan_key' => $key], ['ai_laboratory_id' => $lab->id, 'island_key' => $islandKey,
            'status' => $stalled ? 'approval_required' : 'not_needed', 'retirement_fraction' => $stalled ? .8 : 0,
            'trigger_metrics' => $metrics, 'preservation_contract' => ['keep' => ['confirmed_skills', 'stepping_stone_genes', 'rare_behavioral_elites', 'frozen_control_roots', 'genealogy'], 'promotion_evidence' => false],
            'replacement_contract' => ['different_architecture_or_distant_genetic_region' => true, 'operator_approval_required' => true]]);
        return ['protocol' => self::PROTOCOL, 'plan_id' => $plan->id, 'status' => $plan->status, 'promotion_evidence' => false];
    }

    private function dominates(array $a, array $b): bool
    {
        $maximize = ['return_quality', 'temporal_robustness', 'regime_coverage', 'stress_survival', 'novelty', 'sample_confidence'];
        $minimize = ['drawdown', 'cost_sensitivity', 'complexity', 'behavioral_duplication']; $better = false;
        foreach ($maximize as $key) { if ((float) data_get($a, $key, 0) < (float) data_get($b, $key, 0)) return false; $better = $better || (float) data_get($a, $key, 0) > (float) data_get($b, $key, 0); }
        foreach ($minimize as $key) { if ((float) data_get($a, $key, 0) > (float) data_get($b, $key, 0)) return false; $better = $better || (float) data_get($a, $key, 0) < (float) data_get($b, $key, 0); }
        return $better;
    }
    private function available(): bool { try { return Schema::hasTable('lab_evolution_directors') && Schema::hasTable('lab_evolution_extinction_plans') && Schema::hasTable('lab_mutation_response_maps'); } catch (\Throwable) { return false; } }
}
