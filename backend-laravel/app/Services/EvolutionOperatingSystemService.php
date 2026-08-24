<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabSkillZooEntry;
use Illuminate\Support\Facades\Schema;

/** Read-only architecture contract exposed to planning and monitoring. */
class EvolutionOperatingSystemService
{
    public const PROTOCOL = 'quality_diversity_operating_system_v1';

    /** @return array<string, mixed> */
    public function blueprint(AiLaboratory $lab): array
    {
        $skillCount = $this->table('lab_skill_zoo_entries')
            ? LabSkillZooEntry::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->whereIn('status', ['provisional', 'confirmed'])->count() : 0;
        $behaviorCells = $this->table('lab_evolution_archive_entries')
            ? LabEvolutionArchiveEntry::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('archive_type', 'behavioral_map_elites')->count() : 0;
        return [
            'protocol' => self::PROTOCOL,
            'status' => $this->table('lab_skill_zoo_entries') ? 'available' : 'migration_pending',
            'skill_zoo' => ['protocol' => SkillZooService::PROTOCOL, 'local_skill_entries' => $skillCount, 'behavioral_cells' => $behaviorCells,
                'modules' => ['regime_detector', 'setup_detector', 'entry_trigger', 'confirmation', 'initial_risk', 'exit', 'cost_filter', 'position_sizing']],
            'mutation_brain' => ['protocol' => MutationBrainService::PROTOCOL, 'action' => ['gene', 'direction', 'magnitude', 'context', 'parent_state'],
                'reward' => ['causal_improvement', '-regressions', '-duplicates', '-compute_cost', '+novelty', '+descendant_value']],
            'adversarial_market' => ['protocol' => AdversarialCoEvolutionService::PROTOCOL, 'historical_bounds_required' => true, 'synthetic_outside_history_forbidden' => true],
            'pareto' => ['maximize' => ['return_quality', 'temporal_robustness', 'regime_coverage', 'stress_survival', 'novelty', 'sample_confidence'],
                'minimize' => ['drawdown', 'cost_sensitivity', 'complexity', 'behavioral_duplication']],
            'islands' => EvolutionPortfolioService::DIRECTORS,
            'minimal_criterion' => ['minimum_trades' => 3, 'max_drawdown_percent' => 35, 'fatal_instability_forbidden' => true],
            'multi_fidelity' => ['static_validity', 'behavior_fingerprint', 'tiny_replay', 'representative_slices', 'full_replay', 'stress_replay', 'forward', 'paper'],
            'surrogate' => ['research_only' => true, 'never_replaces_replay' => true, 'virtual_proposals_cap' => 500],
            'meta_evolution' => ['directors' => EvolutionPortfolioService::DIRECTORS, 'director_count' => 5],
            'extinction' => ['operator_approval_required' => true, 'preserve' => ['confirmed_skills', 'stepping_stone_genes', 'rare_behavioral_elites', 'frozen_control_roots', 'genealogy']],
            'promotion_evidence' => false,
        ];
    }

    private function table(string $table): bool { try { return Schema::hasTable($table); } catch (\Throwable) { return false; } }
}
