<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabAgentInheritanceAudit;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use RuntimeException;

/**
 * Safe handoff boundary between a declared control root and its specialist.
 *
 * A control root is a reproducible research seed, not a promotion result. A
 * specialist may inherit its parameters only when the root's semantic cell,
 * control-root catalogue identity and parameter hash all agree. The handoff
 * is then written both into model metadata and into a queryable audit row.
 */
class ControlRootInheritanceService
{
    public const PROTOCOL = 'control_root_specialist_inheritance_v1';

    public function __construct(
        private StrategySemanticGroupService $semanticGroups,
        private ControlRootCatalogueService $controlRoots,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function specialistDefinition(string $family, ?array $niche): ?array
    {
        $role = (string) data_get($niche, 'specialist_role', data_get($niche, 'role', ''));
        $definition = $this->semanticGroups->canonicalSpecialistGroups()[$role] ?? null;
        if (! is_array($definition)) return null;

        // A role name alone is not enough to authorize a seed handoff. The
        // operating envelope must be explicit and must match the canonical
        // map; otherwise a failure-context label could redirect a root.
        if ($family !== (string) data_get($definition, 'family')) return null;
        foreach (['regime', 'volatility'] as $field) {
            if ((string) data_get($niche, $field, '') !== (string) data_get($definition, $field)) return null;
        }
        $direction = strtolower(trim((string) data_get($niche, 'direction', '')));
        if ($direction !== '' && ! in_array($direction, ['*', '-', 'null', 'unknown'], true)) return null;

        return [
            ...$definition,
            'role' => $role,
            'specialist_role' => $role,
            'direction' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function seedDeclaration(
        string $symbol,
        string $timeframe,
        string $family,
        ?array $niche,
        array $semanticGroup,
        array $controlRoot,
        string $architecture,
        array $parameters,
    ): ?array {
        $definition = $this->specialistDefinition($family, $niche);
        if ($definition === null) return null;

        $expectedGroup = $this->semanticGroups->descriptor(
            $symbol,
            $timeframe,
            $family,
            $definition,
            $architecture,
        );
        if ((string) data_get($semanticGroup, 'key') !== (string) data_get($expectedGroup, 'key')) return null;

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'pending_identity',
            'eligible_for_specialist' => true,
            'role' => $definition['role'],
            'specialist_role' => $definition['role'],
            'strategy_family' => $family,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'semantic_group_key' => $expectedGroup['key'],
            'control_root_id' => data_get($controlRoot, 'root_id'),
            'control_root_protocol' => data_get($controlRoot, 'protocol'),
            'architecture' => $architecture,
            'root_model_version_id' => null,
            'root_agent_id' => null,
            'seed_parameter_hash' => $this->parameterHash($family, $parameters),
            'inheritance_scope' => [
                'source' => 'control_root_parameters_only',
                'max_changed_parameters' => 1,
                'semantic_group_frozen' => true,
                'execution_contract_frozen' => true,
                'historical_lessons_are_priors_only' => true,
                'beneficial_mutations_require_independent_confirmation' => true,
                'harmful_mutations_remain_blocked' => true,
                'promotion_requires_fresh_evidence' => true,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    /**
     * Resolve a prior-generation control root for the exact specialist cell.
     * The resolver intentionally does not use score, PF or a loose family
     * match; it accepts only a declared and hash-valid seed.
     */
    public function findSeed(LabGeneration $generation, string $family, ?array $niche): ?LabAgent
    {
        $definition = $this->specialistDefinition($family, $niche);
        if ($definition === null) return null;

        $lab = $generation->laboratory;
        $candidates = LabAgent::query()
            ->with(['modelVersion', 'generation'])
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('strategy_family', $family)
            ->whereNull('parent_a_model_version_id')
            ->whereNull('parent_b_model_version_id')
            ->whereHas('generation', fn ($query) => $query->where('generation', '<', $generation->generation))
            ->latest('id')
            ->take(300)
            ->get();

        foreach ($candidates as $candidate) {
            $inspection = $this->inspectSeed($candidate, $family, $niche);
            if (($inspection['passed'] ?? false) === true) return $candidate;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function inspectSeed(LabAgent $root, string $family, ?array $niche): array
    {
        $root->loadMissing(['modelVersion', 'generation']);
        $model = $root->modelVersion;
        $definition = $this->specialistDefinition($family, $niche);
        $errors = [];
        $seed = (array) data_get($model?->metadata, 'control_root_seed', []);
        $group = $model ? $this->semanticGroups->fromModel($model, $family) : [];
        $expectedGroup = $definition && $root
            ? $this->semanticGroups->descriptor($root->symbol, $root->timeframe, $family, $definition)
            : [];

        if (! $model) $errors[] = 'ROOT_MODEL_MISSING';
        if (! $definition) $errors[] = 'SPECIALIST_CELL_NOT_CANONICAL';
        if ($root->parent_a_model_version_id !== null || $root->parent_b_model_version_id !== null) {
            $errors[] = 'ROOT_HAS_GENETIC_PARENT';
        }
        if (in_array((string) $root->lifecycle_status, ['technical_quarantine', 'quarantined', 'evaluation_error', 'rejected'], true)) {
            $errors[] = 'ROOT_LIFECYCLE_NOT_ELIGIBLE';
        }
        if (in_array((string) ($model?->evidence_status ?? ''), ['legacy_invalid', 'stale_quarantine'], true)
            || $model?->invalidated_at !== null) {
            $errors[] = 'ROOT_EVIDENCE_INVALID';
        }
        if (data_get($seed, 'protocol') !== self::PROTOCOL
            || data_get($seed, 'eligible_for_specialist') !== true
            || data_get($seed, 'status') === 'revoked') {
            $errors[] = 'ROOT_SEED_CONTRACT_MISSING';
        }
        if ($model && ! $this->semanticGroups->exactParentCompatible(
            $model,
            $root->symbol,
            $root->timeframe,
            $family,
            $definition,
        )) {
            $errors[] = 'ROOT_SEMANTIC_GROUP_MISMATCH';
        }
        if ((string) data_get($group, 'key') !== (string) data_get($expectedGroup, 'key')) {
            $errors[] = 'ROOT_GROUP_KEY_MISMATCH';
        }
        if ((string) data_get($seed, 'semantic_group_key') !== (string) data_get($expectedGroup, 'key')) {
            $errors[] = 'ROOT_SEED_GROUP_KEY_MISMATCH';
        }
        if ((int) data_get($seed, 'root_model_version_id', 0) !== (int) ($model?->id ?? 0)
            || (int) data_get($seed, 'root_agent_id', 0) !== (int) $root->id) {
            $errors[] = 'ROOT_SEED_IDENTITY_MISMATCH';
        }

        $controlRoot = (array) data_get($model?->metadata, 'control_root', []);
        $expectedControlRoot = $definition
            ? $this->controlRoots->for($family, (string) data_get($seed, 'architecture', 'control_root_seed_v1'))
            : [];
        if (data_get($controlRoot, 'protocol') !== 'explainable_control_root_v1'
            || (string) data_get($controlRoot, 'family') !== $family
            || (string) data_get($controlRoot, 'root_id') !== (string) data_get($expectedControlRoot, 'root_id')) {
            $errors[] = 'CONTROL_ROOT_CATALOGUE_MISMATCH';
        }
        if ($model && data_get($seed, 'seed_parameter_hash') !== $this->parameterHash($family, (array) $model->parameters)) {
            $errors[] = 'ROOT_SEED_PARAMETER_HASH_MISMATCH';
        }

        return [
            'protocol' => self::PROTOCOL,
            'passed' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'root_agent_id' => $root->id,
            'root_model_version_id' => $model?->id,
            'semantic_group_key' => data_get($expectedGroup, 'key'),
            'seed_hash' => data_get($seed, 'seed_parameter_hash'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function pendingChildDeclaration(LabAgent $root, array $semanticGroup, string $target): array
    {
        $seed = (array) data_get($root->modelVersion?->metadata, 'control_root_seed', []);

        return [
            'protocol' => self::PROTOCOL,
            'transition' => 'control_root_to_specialist',
            'status' => 'pending_persistence',
            'root_model_version_id' => $root->model_version_id,
            'root_agent_id' => $root->id,
            'root_seed_hash' => data_get($seed, 'seed_parameter_hash'),
            'control_root_id' => data_get($seed, 'control_root_id'),
            'semantic_group_key' => data_get($semanticGroup, 'key'),
            'target' => $target,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Seal the root identity after both the model and lab-agent rows exist.
     * This is called for newly-created parentless canonical seeds.
     */
    public function finalizeSeed(ModelVersion $model, LabAgent $agent): array
    {
        $seed = (array) data_get($model->metadata, 'control_root_seed', []);
        if (data_get($seed, 'protocol') !== self::PROTOCOL) return [];

        $seed['status'] = 'issued';
        $seed['root_model_version_id'] = $model->id;
        $seed['root_agent_id'] = $agent->id;
        // Recompute from the persisted model, not only the pre-persistence
        // compiler snapshot. This closes any cast/normalization difference
        // between the constructor array and the JSON value stored by Eloquent.
        $seed['seed_parameter_hash'] = $this->parameterHash((string) data_get($seed, 'strategy_family'), (array) $model->parameters);
        $seed['contract_hash'] = $this->contractHash($seed);
        $metadata = (array) $model->metadata;
        $metadata['control_root_seed'] = $seed;
        if (is_array($metadata['semantic_lineage'] ?? null)) {
            $metadata['semantic_lineage']['root_model_version_id'] = $model->id;
        }
        if (is_array($metadata['progressive_inheritance'] ?? null)) {
            $metadata['progressive_inheritance']['root_model_version_id'] = $model->id;
        }
        $model->update(['metadata' => $metadata]);

        LabAgentInheritanceAudit::query()->firstOrCreate(
            [
                'lab_agent_id' => $agent->id,
                'protocol' => self::PROTOCOL,
                'transition' => 'control_root_seed_issued',
            ],
            [
                'source_model_version_id' => null,
                'source_agent_id' => null,
                'decision' => 'accepted',
                'semantic_group_key' => data_get($seed, 'semantic_group_key'),
                'seed_hash' => data_get($seed, 'seed_parameter_hash'),
                'child_parameter_hash' => data_get($seed, 'seed_parameter_hash'),
                'contract_hash' => data_get($seed, 'contract_hash'),
                'metadata' => [
                    'root_model_version_id' => $model->id,
                    'root_agent_id' => $agent->id,
                    'status' => 'issued',
                    'promotion_evidence' => false,
                ],
            ],
        );

        return $seed;
    }

    /**
     * Seal and audit a specialist that inherited from a control root. Any
     * failed check aborts construction rather than persisting an ambiguous
     * child that could later look like a normal validated-parent mutation.
     */
    public function finalizeSpecialist(
        LabAgent $child,
        LabAgent $root,
        string $family,
        ?array $niche,
        array $semanticGroup,
        array $base,
        array $parameters,
        array $parameterDiff,
        array $knowledgeContract,
        array $progressiveInheritance,
        ?array $history,
        string $target,
    ): array {
        $inspection = $this->inspectSeed($root, $family, $niche);
        if (($inspection['passed'] ?? false) !== true) {
            throw new RuntimeException('Control-root inheritance failed: '.implode(', ', (array) ($inspection['errors'] ?? [])));
        }

        $rootParameters = (array) ($root->modelVersion?->parameters ?? []);
        $inheritedKeys = array_values(array_intersect(array_keys($rootParameters), array_keys($parameters)));
        $changedKeys = array_values(array_unique(array_keys($parameterDiff)));
        $beneficialTraits = array_values((array) data_get($progressiveInheritance, 'confirmed_beneficial_traits', []));
        $blockedMutations = array_values(array_unique([
            ...(array) data_get($knowledgeContract, 'blocked_mutations', []),
            ...(array) data_get($history, 'blocked_mutations', []),
        ]));
        $blockedDirections = array_values((array) data_get($knowledgeContract, 'blocked_mutation_directions', []));

        $contract = [
            'protocol' => self::PROTOCOL,
            'transition' => 'control_root_to_specialist',
            'status' => 'accepted',
            'root_model_version_id' => $root->model_version_id,
            'root_agent_id' => $root->id,
            'child_model_version_id' => $child->model_version_id,
            'child_agent_id' => $child->id,
            'control_root_id' => data_get($root->modelVersion?->metadata, 'control_root_seed.control_root_id'),
            'semantic_group_key' => data_get($semanticGroup, 'key'),
            'root_seed_hash' => data_get($inspection, 'seed_hash'),
            'child_parameter_hash' => $this->parameterHash($family, $parameters),
            'inherited_parameter_keys' => $inheritedKeys,
            'inherited_parameter_count' => count($inheritedKeys),
            'changed_parameter_keys' => $changedKeys,
            'changed_parameter_count' => count($changedKeys),
            'preserved_parameter_count' => count(array_diff($inheritedKeys, $changedKeys)),
            'inheritance_scope' => [
                'source' => 'control_root_parameters_only',
                'max_changed_parameters' => 1,
                'semantic_group_frozen' => true,
                'execution_contract_frozen' => true,
                'historical_lessons_are_priors_only' => true,
                'beneficial_mutations_require_independent_confirmation' => true,
                'harmful_mutations_remain_blocked' => true,
                'promotion_requires_fresh_evidence' => true,
            ],
            'historical_lessons' => [
                'insight_id' => data_get($history, 'insight_id'),
                'recommended_keys' => array_values((array) data_get($history, 'recommended_keys', data_get($history, 'recommended_mutations.keys', []))),
                'evidence_quality' => data_get($history, 'evidence_quality'),
                'promotion_evidence' => false,
            ],
            'mutation_memory' => [
                'confirmed_beneficial_traits' => array_slice($beneficialTraits, 0, 24),
                'blocked_harmful_mutations' => $blockedMutations,
                'blocked_harmful_directions' => array_slice($blockedDirections, 0, 24),
                'promotion_evidence' => false,
            ],
            'root_validation' => $inspection,
            'target' => $target,
            'promotion_evidence' => false,
        ];
        $contract['contract_hash'] = $this->contractHash($contract);

        $metadata = (array) ($child->modelVersion?->metadata ?? []);
        $metadata['control_root_specialist_inheritance'] = $contract;
        $child->modelVersion?->update(['metadata' => $metadata]);

        LabAgentInheritanceAudit::query()->updateOrCreate(
            [
                'lab_agent_id' => $child->id,
                'protocol' => self::PROTOCOL,
                'transition' => 'control_root_to_specialist',
            ],
            [
                'source_model_version_id' => $root->model_version_id,
                'source_agent_id' => $root->id,
                'decision' => 'accepted',
                'semantic_group_key' => data_get($contract, 'semantic_group_key'),
                'seed_hash' => data_get($contract, 'root_seed_hash'),
                'child_parameter_hash' => data_get($contract, 'child_parameter_hash'),
                'contract_hash' => data_get($contract, 'contract_hash'),
                'metadata' => $contract,
            ],
        );

        return $contract;
    }

    public function parameterHash(string $family, array $parameters): string
    {
        ksort($parameters);
        return hash('sha256', $family.'|'.json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    private function contractHash(array $contract): string
    {
        unset($contract['contract_hash']);
        return hash('sha256', json_encode($this->canonicalize($contract), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->canonicalize($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
