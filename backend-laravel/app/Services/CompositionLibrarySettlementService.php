<?php

namespace App\Services;

use App\Models\CompositionSettlement;
use App\Models\LabAgent;
use App\Models\LabSkillZooEntry;
use Illuminate\Support\Facades\Schema;

/** Returns only independently confirmed composition evidence to the skill zoo. */
class CompositionLibrarySettlementService
{
    /** @return array<string, mixed> */
    public function consolidateConfirmed(LabAgent $agent, array $evidence): array
    {
        if (! Schema::hasTable('lab_skill_zoo_entries') || ! Schema::hasTable('composition_settlements')) {
            return ['status' => 'unavailable'];
        }

        $composition = (array) data_get($agent->modelVersion?->metadata, 'smart_composition', []);
        if ((string) data_get($composition, 'protocol') !== StrategyTacticRiskCompositionPlannerService::PROTOCOL) {
            return ['status' => 'not_a_composition_candidate'];
        }
        $passport = (array) data_get($composition, 'composition_passport', []);
        if ((string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL) {
            return ['status' => 'missing_frozen_composition_passport'];
        }
        if (data_get($evidence, 'independent_confirmation') !== true
            || data_get($evidence, 'paired_control') !== true) {
            return ['status' => 'not_independently_confirmed'];
        }
        $runtimeTrace = (array) data_get(
            $evidence,
            'composition_runtime_trace',
            data_get($agent->modelVersion?->metadata, 'last_screen_result.composition_runtime_trace', []),
        );
        $runtimeAttested = (string) data_get($runtimeTrace, 'protocol') === 'xauusd_composition_runtime_trace_v3'
            && (string) data_get($runtimeTrace, 'status') === 'consumed'
            && data_get($runtimeTrace, 'contract_hash_valid') === true
            && data_get($runtimeTrace, 'execution_receipt_valid') === true
            && data_get($runtimeTrace, 'program_compiled') === true
            && data_get($runtimeTrace, 'program_valid') === true
            && data_get($runtimeTrace, 'node_receipts_valid') === true
            && data_get($runtimeTrace, 'decision_receipts_valid') === true
            && data_get($runtimeTrace, 'complete_decision_witness') === true
            && data_get($runtimeTrace, 'component_bindings_valid') === true
            && data_get($runtimeTrace, 'authority_bindings_valid') === true
            && data_get($runtimeTrace, 'required_nodes_observed') === true
            && data_get($runtimeTrace, 'execution_completed') === true
            && hash_equals(
                (string) data_get($passport, 'composition_id'),
                (string) data_get($runtimeTrace, 'composition_id'),
            );
        if (! $runtimeAttested) {
            return [
                'status' => 'runtime_composition_not_attested',
                'unbound_components' => (array) data_get($runtimeTrace, 'unbound_components', []),
            ];
        }
        $parts = array_values(array_filter([
            data_get($composition, 'strategy_library_id'),
            data_get($composition, 'tactic_library_key'),
            data_get($composition, 'risk_library_id'),
            data_get($composition, 'risk_mutation_gene'),
            data_get($passport, 'components.management_id'),
        ], fn ($value): bool => is_string($value) && $value !== ''));
        if ($parts === []) {
            return ['status' => 'no_library_identity'];
        }

        $settlementKey = hash('sha256', implode('|', ['composition-settlement-v1', $agent->id, data_get($passport, 'composition_id'), data_get($evidence, 'pair_id'), data_get($evidence, 'lesson_id')]));
        $settlement = CompositionSettlement::query()->firstOrCreate(['settlement_key' => $settlementKey], [
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'composition_id' => data_get($passport, 'composition_id'),
            'status' => 'independently_confirmed',
            'components' => data_get($passport, 'components', []),
            'evidence' => ['source' => $evidence, 'runtime_trace' => $runtimeTrace, 'paired_control' => true, 'independent_confirmation' => true, 'promotion_evidence' => false],
            'settled_at' => now(),
        ]);
        if (! $settlement->wasRecentlyCreated) {
            return ['status' => 'already_settled', 'composition_settlement_id' => $settlement->id];
        }

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
                'composition_passport' => $passport,
                'independent_confirmation' => true,
                'paired_control' => true,
                'runtime_trace' => $runtimeTrace,
                'source' => $evidence,
                'promotion_evidence' => false,
            ],
        ]);

        return ['status' => 'confirmed_composition_consolidated', 'composition_settlement_id' => $settlement->id, 'skill_zoo_entry_id' => $entry->id];
    }
}
