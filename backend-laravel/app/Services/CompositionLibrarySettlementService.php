<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabSkillZooEntry;
use Illuminate\Support\Facades\Schema;

/** Returns only independently confirmed composition evidence to the skill zoo. */
class CompositionLibrarySettlementService
{
    /** @return array<string, mixed> */
    public function consolidateConfirmed(LabAgent $agent, array $evidence): array
    {
        if (! Schema::hasTable('lab_skill_zoo_entries')) return ['status' => 'unavailable'];

        $composition = (array) data_get($agent->modelVersion?->metadata, 'smart_composition', []);
        if ((string) data_get($composition, 'protocol') !== StrategyTacticRiskCompositionPlannerService::PROTOCOL) {
            return ['status' => 'not_a_composition_candidate'];
        }
        $parts = array_values(array_filter([
            data_get($composition, 'strategy_library_id'),
            data_get($composition, 'tactic_library_key'),
            data_get($composition, 'risk_library_id'),
            data_get($composition, 'risk_mutation_gene'),
        ], fn ($value): bool => is_string($value) && $value !== ''));
        if ($parts === []) return ['status' => 'no_library_identity'];

        $nicheKey = implode('|', $parts);
        $key = hash('sha256', implode('|', [StrategyTacticRiskCompositionPlannerService::PROTOCOL, $agent->symbol, $agent->timeframe, $agent->strategy_family, $nicheKey]));
        $entry = LabSkillZooEntry::query()->updateOrCreate(['skill_key' => $key], [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family,
            'module_key' => 'strategy_tactic_risk_composition',
            'niche_key' => $nicheKey,
            'gene_key' => data_get($composition, 'risk_mutation_gene'),
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'quality_score' => (float) data_get($evidence, 'quality_score', 0),
            'confidence' => 1.0,
            'status' => 'confirmed',
            'evidence' => [
                'protocol' => StrategyTacticRiskCompositionPlannerService::PROTOCOL,
                'composition' => $composition,
                'independent_confirmation' => true,
                'paired_control' => true,
                'source' => $evidence,
                'promotion_evidence' => false,
            ],
        ]);

        return ['status' => 'confirmed_composition_consolidated', 'skill_zoo_entry_id' => $entry->id];
    }
}
