<?php

namespace App\Services;

use App\Models\AgentInheritanceManifest;
use App\Models\EvolutionLearningReceipt;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;

/** Seals the director's receipt use into every actual child, fail-closed for invalid exploit/repair intent. */
class InheritanceEnforcerService
{
    public const PROTOCOL = 'inheritance_enforcer_v1';

    /** @return array<string,mixed> */
    public function seal(LabAgent $agent, array $directive, ?int $parentModelId, array $parameterDiff, array $passport = []): array
    {
        $role = (string) ($directive['experiment_role'] ?? 'explore');
        $receiptIds = array_values(array_filter((array) ($directive['consumed_receipt_ids'] ?? []), fn ($id): bool => is_numeric($id) && (int) $id > 0));
        $requiresReceipt = in_array($role, ['exploit', 'repair'], true);
        $receipts = Schema::hasTable('evolution_learning_receipts')
            ? EvolutionLearningReceipt::query()->whereIn('id', $receiptIds)->get()
            : collect();
        $actualGene = count($parameterDiff) === 1 ? (string) array_key_first($parameterDiff) : null;
        $expectedGene = (string) ($directive['required_gene'] ?? '');
        $seedPresent = (array) data_get($passport, 'prior_contract.prior_ids', []) !== []
            || filled(data_get($passport, 'seed_blueprint_id'));
        // A confirmed, compatible causal receipt is a component mentor seed.
        // It does not make its source model a genetic parent, but it is valid
        // provenance for an exact bounded exploit/repair child.
        $receiptSeed = $requiresReceipt && $receiptIds !== [];
        $parentOrSeed = $parentModelId !== null || $seedPresent || $receiptSeed || ! $requiresReceipt;
        $receiptAuthority = ! $requiresReceipt || (
            count($receiptIds) === $receipts->count()
            && $receipts->isNotEmpty()
            && $receipts->every(fn (EvolutionLearningReceipt $receipt): bool =>
                (string) $receipt->status === 'confirmed'
                && strtoupper((string) $receipt->symbol) === strtoupper((string) $agent->symbol)
                && strtoupper((string) $receipt->timeframe) === strtoupper((string) $agent->timeframe)
                && (! filled(data_get($receipt->scope, 'strategy_family'))
                    || (string) data_get($receipt->scope, 'strategy_family') === (string) $agent->strategy_family)
                && (! $receipt->expires_at || $receipt->expires_at->isFuture())
            )
        );
        $geneMatches = ! $requiresReceipt || (
            $actualGene !== null && $expectedGene !== '' && $actualGene === $expectedGene
            && $receipts->every(fn (EvolutionLearningReceipt $receipt): bool =>
                (string) data_get($receipt->evidence, 'input.parameter_key', '') === $actualGene)
        );
        $controlContract = ! in_array($role, ['exploit', 'repair', 'falsification'], true)
            || (bool) ($directive['control_pair_required'] ?? false);
        $valid = (! $requiresReceipt || $receiptIds !== [])
            && $parentOrSeed
            && (! $requiresReceipt || filled($directive['mutation_reason'] ?? null))
            && $receiptAuthority
            && $geneMatches
            && $controlContract;
        $manifest = ['protocol' => self::PROTOCOL, 'child_id' => 'G'.$agent->lab_generation_id.'-A'.$agent->id, 'parent_model_version_id' => $parentModelId, 'mentor_source_model_version_id' => $directive['source_baseline_model_version_id'] ?? null, 'inherited_components' => (array) ($directive['inherited_components'] ?? []), 'consumed_learning_receipts' => $receiptIds, 'consumed_source_lesson_ids' => array_values(array_filter(array_map('intval', (array) ($directive['source_lesson_ids'] ?? [])))), 'mutated_component' => $directive['required_component'] ?? ($actualGene ?: null), 'mutated_gene' => $actualGene, 'mutation_from' => $directive['mutation_from'] ?? null, 'mutation_to' => $directive['mutation_to'] ?? null, 'hypothesis' => $directive['mutation_reason'] ?? 'bounded exploration', 'experiment_role' => $role, 'control_agent_id' => $directive['control_agent_id'] ?? null, 'control_pair_required' => (bool) ($directive['control_pair_required'] ?? false), 'full_replay_required' => (bool) ($directive['full_replay_required'] ?? false), 'settlement_required' => (bool) ($directive['settlement_required'] ?? $requiresReceipt), 'composition_id' => data_get($passport, 'composition_id'), 'promotion_evidence' => false];
        $validation = ['valid' => $valid, 'parent_or_seed_blueprint_present' => $parentOrSeed, 'confirmed_receipt_component_seed' => $receiptSeed && $receiptAuthority, 'receipt_required_for_exploit_repair' => ! $requiresReceipt || $receiptIds !== [], 'receipt_is_confirmed_context_compatible' => $receiptAuthority, 'receipt_gene_matches_parameter_diff' => $geneMatches, 'mutation_reason_required_for_exploit_repair' => ! $requiresReceipt || filled($directive['mutation_reason'] ?? null), 'causal_experiment_requires_control' => $controlContract];
        if (! Schema::hasTable('agent_inheritance_manifests')) return ['status' => $valid ? 'sealed' : 'rejected', 'manifest' => $manifest, 'validation' => $validation];
        $key = hash('sha256', implode('|', [self::PROTOCOL, $agent->id, $agent->model_version_id]));
        $row = AgentInheritanceManifest::query()->updateOrCreate(['manifest_key' => $key], ['lab_agent_id' => $agent->id, 'lab_generation_id' => $agent->lab_generation_id, 'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'experiment_role' => $role, 'status' => $valid ? 'sealed' : 'rejected', 'manifest' => $manifest, 'validation' => $validation, 'sealed_at' => now()]);
        return ['status' => $row->status, 'manifest_id' => $row->id, 'manifest' => $manifest, 'validation' => $validation];
    }
}
