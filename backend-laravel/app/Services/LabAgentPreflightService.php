<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabTrialLedger;
use App\Services\MarketData\HistoricalDataQualityService;

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
        private HistoricalDataQualityService $historicalData,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(LabAgent $agent, string $stage = 'screening'): array
    {
        $agent->loadMissing(['modelVersion', 'generation', 'parentA', 'parentB', 'parentLinks.parentModel', 'inheritanceAudits']);
        // Queue admission can race with dataset preparation/report writes.
        // Never inspect a stale serialized generation relation: the current
        // foundation snapshot is part of the full-replay admission contract.
        $generation = $agent->generation?->fresh();
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
        $graphParents = $graphLinks->map(fn ($link) => $link->parentModel)
            ->filter()
            ->unique('id')
            ->values();
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
        $declaredGraphSources = array_values(array_filter(array_map(
            'intval',
            array_values((array) data_get($model?->metadata, 'parent_contribution_graph.all_parent_model_version_ids', [])),
        ), static fn (int $id): bool => $id > 0));
        $declaredGraphGeneSources = collect((array) data_get($model?->metadata, 'parent_contribution_graph.capability_gene_provenance', []))
            ->map(fn ($provenance): int => (int) data_get($provenance, 'source_parent_id', 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()->values()->all();
        $expectedGraphParentIds = array_values(array_unique([
            ...$parentIds,
            ...$declaredSkillSources,
            ...$adaptiveSources,
            ...$capabilitySources,
            ...$declaredGraphSources,
            ...$declaredGraphGeneSources,
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

        $allParents = $parents->merge($graphParents)->unique('id')->values();
        if ($allParents->isEmpty()) {
            // A parentless draft is a normal starting state. It may be an
            // explicit group root, or it may simply be waiting for an exact
            // parent to appear in a later generation. Do not turn the absence
            // of a parent into a lineage failure or quarantine the candidate.
            // Any declared parent evidence is still checked below: a stale
            // parent id/graph link is an integrity problem, not "no parent".
            $declaredParentEvidence = $expectedGraphParentIds !== [] || $graphLinks->isNotEmpty();
            $declaredParentSelection = (string) data_get($inheritance, 'parent_selection', '');
            if ($declaredParentEvidence || $declaredParentSelection === 'control_root_seed_inheritance') {
                $errors[] = 'PARENT_NOT_ATTACHED';
            }
        } else {
            foreach ($allParents as $parent) {
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
            $primaryParentId = (int) ($agent->parent_a_model_version_id
                ?: $agent->parent_b_model_version_id
                ?: ($graphParentIds[0] ?? 0));
            if ($lineageParent !== $primaryParentId) {
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
            $rollingManifest = data_get($generation?->trigger_context, 'canonical_dataset_snapshots.price.manifest');
            $foundationManifest = data_get($generation?->trigger_context, 'canonical_dataset_snapshots.foundation.manifest');
            $coverage = $this->historicalData->fullReplayCoverage(
                $agent->symbol,
                $agent->timeframe,
                is_array($rollingManifest) ? $rollingManifest : null,
                is_array($foundationManifest) ? $foundationManifest : null,
            );
            if ($coverage['status'] !== 'ready') {
                $coverageReasons = (array) data_get($coverage, 'reasons', []);
                if (array_intersect($coverageReasons, [
                    'FOUNDATION_DATASET_CONTINUITY_PASSPORT_MISSING',
                    'FOUNDATION_DATASET_CONTINUITY_BLOCKED',
                ]) !== []) {
                    $errors[] = 'FOUNDATION_DATASET_CONTINUITY_PASSPORT_INVALID';
                } else {
                    $errors[] = 'FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT';
                }
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
            'parent_mode' => $allParents->isEmpty()
                ? 'no_parent_available'
                : (data_get($lineage, 'mode') ?: 'exact_semantic_parent'),
            'parent_status' => $allParents->isEmpty() ? 'not_available' : 'attached',
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

    /**
     * Normalize a legacy execution-contract hash only when its persisted
     * parameter map already hashes to the current canonical contract. This
     * repairs numeric JSON serialization drift without changing parameters or
     * treating the old screening result as fresh full-validation evidence.
     *
     * @return array<string, mixed>
     */
    public function normalizeExecutionContractMetadata(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion');
        $model = $agent->modelVersion;
        if (! $model) {
            return [];
        }

        $expected = $this->executionContracts->for($agent->symbol, $agent->timeframe);
        $metadata = (array) $model->metadata;
        $paths = [
            'execution_contract',
            'last_screen_result.execution_contract',
            'last_result.execution_contract',
        ];
        $repaired = [];
        foreach ($paths as $path) {
            $observed = data_get($metadata, $path);
            if (! is_array($observed)
                || ! is_array(data_get($observed, 'parameters'))
                || $this->executionContracts->hashParameters((array) data_get($observed, 'parameters')) !== $expected['execution_hash']) {
                continue;
            }
            if ((string) data_get($observed, 'execution_hash') === (string) $expected['execution_hash']
                && (string) data_get($observed, 'protocol') === (string) $expected['protocol']
                && (string) data_get($observed, 'version') === (string) $expected['version']) {
                continue;
            }

            $oldHash = (string) data_get($observed, 'execution_hash', '');
            $normalized = [
                ...$observed,
                'protocol' => $expected['protocol'],
                'version' => $expected['version'],
                'symbol' => $expected['symbol'],
                'timeframe' => $expected['timeframe'],
                'execution_hash' => $expected['execution_hash'],
                'status' => 'sealed',
            ];
            if (array_key_exists('declared_execution_hash', $normalized)) {
                $normalized['declared_execution_hash'] = $expected['execution_hash'];
            }
            data_set($metadata, $path, $normalized);
            $repaired[] = ['path' => $path, 'old_hash' => $oldHash, 'new_hash' => $expected['execution_hash']];
        }

        if ($repaired === []) {
            return [];
        }

        $history = (array) data_get($metadata, 'execution_contract_normalization_history', []);
        $history[] = [
            'protocol' => 'execution_contract_metadata_normalization_v1',
            'recorded_at' => now()->utc()->toIso8601String(),
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'repairs' => $repaired,
            'parameters_unchanged' => true,
            'promotion_evidence' => false,
        ];
        data_set($metadata, 'execution_contract_normalization_history', $history);
        $model->update(['metadata' => $metadata]);

        return [
            'protocol' => 'execution_contract_metadata_normalization_v1',
            'repairs' => $repaired,
            'parameters_unchanged' => true,
            'promotion_evidence' => false,
        ];
    }

    /** Mark invalid legacy/technical records without deleting evidence. */
    public function quarantine(LabAgent $agent, array $inspection, string $reason = 'preflight_failed'): void
    {
        $agent->loadMissing(['modelVersion', 'generation']);
        $preflightErrors = array_values(array_unique((array) ($inspection['errors'] ?? [])));
        $operationalOnly = $this->isOperationalPreflightFailure($preflightErrors);
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
                'errors' => $preflightErrors,
                'recorded_at' => now()->utc()->toIso8601String(),
                'classification' => $operationalOnly ? 'operational' : 'integrity',
                'promotion_evidence' => false,
            ];
            $modelUpdate = ['metadata' => $metadata];
            // Missing foundation/rolling coverage is an infrastructure
            // admission problem. It must not invalidate the model's control
            // root or lineage passport, otherwise a clean retry can never
            // pass its own seed inspection.
            if (! $operationalOnly) {
                $modelUpdate += [
                    'evidence_status' => 'stale_quarantine',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'strict_lab_agent_preflight_failed',
                ];
            }
            $agent->modelVersion->update($modelUpdate);
        }
        // Merge the quarantine audit into the latest generation context. A
        // stale relation here could erase a foundation/rolling snapshot that
        // was sealed immediately before this preflight failure.
        $generation = $agent->generation?->fresh(['agents']);
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

    private function isOperationalPreflightFailure(array $errors): bool
    {
        return $errors !== []
            && array_diff($errors, [
                'FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT',
                'FOUNDATION_DATASET_CONTINUITY_PASSPORT_INVALID',
            ]) === [];
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
