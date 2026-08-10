<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabTrialLedger;

/**
 * Single admission boundary for every lab queue lane.
 *
 * A model can be useful diagnostic history and still be forbidden from a
 * genetic or replay lane. Keeping this check in one service prevents direct
 * rescue commands from silently bypassing the population builder's lineage
 * rules.
 */
class LabAgentPreflightService
{
    public const PROTOCOL = 'strict_lab_agent_preflight_v1';

    public function __construct(
        private StrategySemanticGroupService $semanticGroups,
        private StrategyParameterSchemaService $schemas,
        private ExecutionContractService $executionContracts,
        private ControlRootInheritanceService $controlRootInheritance,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(LabAgent $agent, string $stage = 'screening'): array
    {
        $agent->loadMissing(['modelVersion', 'generation', 'parentA', 'parentB', 'parentLinks.parentModel', 'inheritanceAudits']);
        $model = $agent->modelVersion;
        $errors = [];
        $family = (string) $agent->strategy_family;
        $group = $model ? $this->semanticGroups->fromModel($model, $family) : [];
        $declared = $model !== null && $this->semanticGroups->hasDeclaredGroup($model, $family);

        if (! $model) {
            $errors[] = 'MODEL_VERSION_MISSING';
        }
        if (! $declared) {
            $errors[] = 'SEMANTIC_GROUP_NOT_DECLARED';
        }

        $lineage = (array) data_get($model?->metadata, 'semantic_lineage', []);
        $inheritance = (array) data_get($model?->metadata, 'parent_inheritance_protocol', []);
        $parentIds = array_values(array_unique(array_filter([
            $agent->parent_a_model_version_id,
            $agent->parent_b_model_version_id,
        ], static fn ($id): bool => filled($id))));
        $parents = collect([$agent->parentA, $agent->parentB])->filter();
        $graphLinks = $agent->parentLinks ?? collect();
        $graphParentIds = $graphLinks
            ->pluck('parent_model_version_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $declaredSkillSources = array_values(array_filter(array_map(
            'intval',
            array_values((array) data_get($model?->metadata, 'skill_crossover_sources', [])),
        ), static fn (int $id): bool => $id > 0));
        $adaptiveSources = array_values(array_filter(array_map(
            'intval',
            array_values((array) data_get($model?->metadata, 'adaptive_parent_ecosystem.selected_parent_model_version_ids', [])),
        ), static fn (int $id): bool => $id > 0));
        $capabilitySources = collect((array) data_get($model?->metadata, 'capability_gene_provenance', []))
            ->map(fn ($provenance): int => (int) data_get($provenance, 'source_parent_id', 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()->values()->all();
        $expectedGraphParentIds = array_values(array_unique([
            ...$parentIds,
            ...$declaredSkillSources,
            ...$adaptiveSources,
            ...$capabilitySources,
        ]));
        $graphRequired = data_get($model?->metadata, 'parent_contribution_graph.protocol') === 'lab_agent_parent_graph_v1'
            || $agent->origin === 'robust_crossover';
        if ($graphRequired && $expectedGraphParentIds !== [] && array_diff($expectedGraphParentIds, $graphParentIds) !== []) {
            $errors[] = 'PARENT_GRAPH_INCOMPLETE';
        }
        $geneProvenance = (array) data_get($model?->metadata, 'capability_gene_provenance', []);
        foreach ($geneProvenance as $gene => $provenance) {
            if (! is_array($provenance)
                || ! filled(data_get($provenance, 'source_parent_id'))
                || ! filled(data_get($provenance, 'source_module'))
                || ! filled(data_get($provenance, 'parameter_hash'))) {
                $errors[] = 'CAPABILITY_GENE_PROVENANCE_INCOMPLETE';
                break;
            }
        }
        $expectedNiche = [
            'role' => data_get($group, 'role'),
            'regime' => data_get($group, 'regime'),
            'volatility' => data_get($group, 'volatility'),
            'direction' => data_get($group, 'direction'),
        ];
        $controlRootHandoff = data_get($inheritance, 'parent_selection') === 'control_root_seed_inheritance';
        $canonicalSpecialist = $this->controlRootInheritance->specialistDefinition($family, $expectedNiche);
        $controlRootContract = (array) data_get($model?->metadata, 'control_root_specialist_inheritance', []);
        $controlRootAudit = $agent->inheritanceAudits?->first(
            fn ($audit): bool => $audit->protocol === ControlRootInheritanceService::PROTOCOL
                && $audit->transition === 'control_root_to_specialist'
                && $audit->decision === 'accepted',
        );

        if ($parents->isEmpty()) {
            $rootMode = data_get($lineage, 'mode') === 'semantic_group_root_default_seed'
                && data_get($inheritance, 'parent_selection') === 'exact_group_root_default'
                && $agent->parent_a_model_version_id === null
                && $agent->parent_b_model_version_id === null;
            if (! $rootMode) $errors[] = 'ROOT_SEED_PROTOCOL_MISSING';
            if ($rootMode && $canonicalSpecialist !== null) {
                $seedInspection = $this->controlRootInheritance->inspectSeed($agent, $family, $expectedNiche);
                if (! ($seedInspection['passed'] ?? false)) $errors[] = 'CONTROL_ROOT_SEED_PROTOCOL_INVALID';
                $seedAudit = $agent->inheritanceAudits?->first(
                    fn ($audit): bool => $audit->protocol === ControlRootInheritanceService::PROTOCOL
                        && in_array($audit->transition, ['control_root_seed_issued', 'control_root_seed_backfill'], true)
                        && $audit->decision === 'accepted',
                );
                if (! $seedAudit) $errors[] = 'CONTROL_ROOT_SEED_AUDIT_MISSING';
            }
        } else {
            foreach ($parents as $parent) {
                $exact = $declared && $this->semanticGroups->exactParentCompatible(
                    $parent,
                    $agent->symbol,
                    $agent->timeframe,
                    $family,
                    $expectedNiche,
                );
                if (! $exact) $errors[] = 'NON_EXACT_SEMANTIC_PARENT';
            }
            foreach ($graphLinks as $link) {
                $linkedParent = $link->parentModel;
                if (! $linkedParent || ! $declared || ! $this->semanticGroups->exactParentCompatible(
                    $linkedParent,
                    $agent->symbol,
                    $agent->timeframe,
                    $family,
                    $expectedNiche,
                )) {
                    $errors[] = 'NON_EXACT_SEMANTIC_PARENT_GRAPH_LINK';
                }
            }
            foreach ($capabilitySources as $sourceId) {
                $source = $graphLinks->first(fn ($link): bool => (int) $link->parent_model_version_id === (int) $sourceId)?->parentModel;
                if (! $source || ! $declared || ! $this->semanticGroups->exactParentCompatible(
                    $source,
                    $agent->symbol,
                    $agent->timeframe,
                    $family,
                    $expectedNiche,
                )) {
                    $errors[] = 'CAPABILITY_SOURCE_NOT_EXACT_PARENT';
                }
            }
            $lineageParent = (int) data_get($lineage, 'genetic_parent_model_version_id', 0);
            if ($lineageParent !== (int) ($agent->parent_a_model_version_id ?: $agent->parent_b_model_version_id)) {
                $errors[] = 'LINEAGE_PARENT_ID_MISMATCH';
            }
            if ($controlRootHandoff) {
                $rootAgent = LabAgent::query()
                    ->where('model_version_id', $agent->parent_a_model_version_id)
                    ->where('symbol', $agent->symbol)
                    ->where('timeframe', $agent->timeframe)
                    ->latest('id')
                    ->first();
                if (! $rootAgent
                    || data_get($controlRootContract, 'protocol') !== ControlRootInheritanceService::PROTOCOL
                    || data_get($controlRootContract, 'status') !== 'accepted'
                    || (int) data_get($controlRootContract, 'root_model_version_id', 0) !== (int) $agent->parent_a_model_version_id
                    || ! ($this->controlRootInheritance->inspectSeed($rootAgent, $family, $expectedNiche)['passed'] ?? false)) {
                    $errors[] = 'CONTROL_ROOT_INHERITANCE_INVALID';
                }
                if (! $controlRootAudit) $errors[] = 'CONTROL_ROOT_INHERITANCE_AUDIT_MISSING';
                if (data_get($lineage, 'mode') !== 'control_root_seed_inheritance') {
                    $errors[] = 'CONTROL_ROOT_LINEAGE_MODE_MISSING';
                }
            } elseif (data_get($lineage, 'mode') !== 'exact_semantic_parent'
                || ! in_array((string) data_get($inheritance, 'parent_selection'), [
                    'exact_semantic_parent',
                    'exact_semantic_group_parent',
                    'exact_semantic_group_screening_seed',
                    'exact_eligible_failure_context_parent',
                    'sealed_coverage_rescue_parent',
                    'validated_frontier_fallback_from_failure_context',
                ], true)) {
                $errors[] = 'EXACT_PARENT_PROTOCOL_MISSING';
            }
        }

        try {
            if ($model) $this->schemas->validate($family, (array) $model->parameters);
        } catch (\Throwable) {
            $errors[] = 'PARAMETER_SCHEMA_INVALID';
        }

        $parameterDiff = (array) $agent->parameter_diff;
        $isControl = (bool) data_get($model?->metadata, 'mutation_constructor_invariant.control_only', false)
            || (data_get($model?->metadata, 'role_complete_council.role_control.type') === 'no_change_control');
        $roleContract = (array) data_get($model?->metadata, 'role_complete_council', []);
        $rolePolicy = (array) data_get($roleContract, 'policy', []);
        if ($roleContract !== [] && data_get($roleContract, 'protocol') === 'role_complete_council_v1') {
            foreach ((array) data_get($rolePolicy, 'protected_invariants', []) as $key => $expected) {
                $observed = data_get((array) ($model?->parameters ?? []), $key);
                if (json_encode($observed, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
                    !== json_encode($expected, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)) {
                    $errors[] = 'ROLE_PROTECTED_INVARIANT_FAILED';
                }
            }
            if (! $isControl) {
                $allowed = array_values((array) data_get($rolePolicy, 'mutation_allowlist', []));
                foreach (array_keys($parameterDiff) as $changedKey) {
                    if (! in_array((string) $changedKey, $allowed, true)) {
                        $errors[] = 'ROLE_MUTATION_OUTSIDE_ALLOWLIST';
                    }
                }
            }
        }
        $singleGeneRequired = (bool) data_get($model?->metadata, 'mutation_constructor_invariant.single_gene_required', false);
        if ($singleGeneRequired && ! $isControl && count($parameterDiff) !== 1) $errors[] = 'ONE_GENE_INVARIANT_FAILED';
        if (! $isControl && $parameterDiff !== [] && collect($parameterDiff)->every(function ($change): bool {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)) return false;
            return json_encode($change['old'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
                === json_encode($change['new'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        })) {
            $errors[] = 'ZERO_DIFF_INVARIANT_FAILED';
        }
        if ((int) data_get($model?->metadata, 'mutation_constructor_invariant.parameter_diff_count', count($parameterDiff))
            !== count($parameterDiff)) {
            $errors[] = 'PARAMETER_INVARIANT_METADATA_MISMATCH';
        }
        if (data_get($model?->metadata, 'parent_inheritance_protocol.legacy_parent_genetic_material') === true) {
            $errors[] = 'LEGACY_PARENT_GENETIC_MATERIAL_FORBIDDEN';
        }

        $expectedContract = $this->executionContracts->for($agent->symbol, $agent->timeframe);
        $observedContract = $this->latestExecutionContract($agent);
        if ($observedContract !== null && ! $this->executionContracts->matches($observedContract, $agent->symbol, $agent->timeframe)) {
            $errors[] = 'EXECUTION_CONTRACT_MISMATCH';
        }
        if (in_array(strtolower($stage), ['full', 'full_validation', 'promotion'], true)) {
            if ($observedContract === null || ! $this->executionContracts->matches($observedContract, $agent->symbol, $agent->timeframe)) {
                $errors[] = 'FULL_REPLAY_EXECUTION_HASH_MISSING_OR_INVALID';
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'passed' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'stage' => $stage,
            'agent_id' => $agent->id,
            'model_version_id' => $model?->id,
            'generation_id' => $agent->lab_generation_id,
            'semantic_group_key' => data_get($group, 'key'),
            'parent_model_version_ids' => $expectedGraphParentIds,
            'parent_graph_link_count' => $graphLinks->count(),
            'parent_mode' => $parents->isEmpty()
                ? 'semantic_group_root_default_seed'
                : (data_get($lineage, 'mode') ?: 'exact_semantic_parent'),
            'control_root_inheritance' => $controlRootHandoff ? [
                'protocol' => ControlRootInheritanceService::PROTOCOL,
                'audit_id' => $controlRootAudit?->id,
                'contract_hash' => data_get($controlRootContract, 'contract_hash'),
                'root_model_version_id' => data_get($controlRootContract, 'root_model_version_id'),
                'promotion_evidence' => false,
            ] : null,
            'parameter_diff_count' => count($parameterDiff),
            'execution_hash' => data_get($observedContract, 'execution_hash', $expectedContract['execution_hash']),
            'execution_contract_observed' => $observedContract !== null,
            'promotion_evidence' => false,
        ];
    }

    public function assertQueueable(LabAgent $agent, string $stage = 'screening'): void
    {
        $inspection = $this->inspect($agent, $stage);
        if (! $inspection['passed']) {
            throw new \RuntimeException('Lab agent preflight failed: '.implode(', ', $inspection['errors']));
        }
    }

    /** Mark invalid legacy/technical records without deleting evidence. */
    public function quarantine(LabAgent $agent, array $inspection, string $reason = 'preflight_failed'): void
    {
        $agent->loadMissing(['modelVersion', 'generation']);
        $message = 'Technical quarantine: strict lab preflight failed ('.implode(', ', (array) ($inspection['errors'] ?? [])).').';
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => $message,
        ]);
        if ($agent->modelVersion) {
            $metadata = (array) ($agent->modelVersion->metadata ?? []);
            $metadata['preflight_quarantine'] = [
                'protocol' => self::PROTOCOL,
                'reason' => $reason,
                'errors' => array_values((array) ($inspection['errors'] ?? [])),
                'recorded_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $agent->modelVersion->update([
                'metadata' => $metadata,
                'evidence_status' => 'stale_quarantine',
                'invalidated_at' => now(),
                'invalidation_reason' => 'strict_lab_agent_preflight_failed',
            ]);
        }
        $generation = $agent->generation;
        if ($generation) {
            $context = (array) ($generation->trigger_context ?? []);
            $context['preflight_quarantine'] = [
                'protocol' => self::PROTOCOL,
                'agent_id' => $agent->id,
                'errors' => array_values((array) ($inspection['errors'] ?? [])),
                'recorded_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $freshAgents = $generation->agents()->get(['lifecycle_status']);
            $open = $freshAgents->contains(fn ($candidate): bool => in_array(
                $candidate->lifecycle_status,
                ['draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation'],
                true,
            ));
            $generation->update([
                'trigger_context' => $context,
                ...(! $open ? ['status' => 'technical_quarantine', 'completed_at' => now()] : []),
            ]);
        }
        app(LabImmutableEvidenceService::class)->recordLifecycle($agent, 'preflight_quarantine', [
            'reason_code' => 'LAB_AGENT_PREFLIGHT_FAILED',
            'preflight' => $inspection,
            'quality_verdict' => 'withheld',
        ], 'preflight', null, null, self::class);
    }

    /** @return array<string, mixed>|null */
    private function latestExecutionContract(LabAgent $agent): ?array
    {
        $model = $agent->modelVersion;
        foreach ([
            data_get($model?->metadata, 'last_screen_result.execution_contract'),
            data_get($model?->metadata, 'last_result.execution_contract'),
            data_get($model?->metadata, 'execution_contract'),
        ] as $candidate) {
            if (is_array($candidate) && filled(data_get($candidate, 'execution_hash'))) return $candidate;
        }
        $ledger = LabTrialLedger::query()
            ->where('lab_agent_id', $agent->id)
            ->whereNotNull('execution_hash')
            ->latest('id')
            ->first();
        if ($ledger?->execution_hash) {
            return [
                'protocol' => ExecutionContractService::PROTOCOL,
                'version' => ExecutionContractService::VERSION,
                'parameters' => $this->executionContracts->parameters($agent->symbol),
                'execution_hash' => (string) $ledger->execution_hash,
            ];
        }
        return null;
    }
}
