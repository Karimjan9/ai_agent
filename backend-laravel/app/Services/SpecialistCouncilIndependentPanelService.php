<?php

namespace App\Services;

use App\Models\ModelVersion;
use App\Models\LabEvaluationRun;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Strict scientific-policy validator and canonical reservation adapter.
 * Legacy caller-shaped references remain refused. Only the server producer
 * may reserve original cohorts; no context label grants result or authority.
 */
class SpecialistCouncilIndependentPanelService
{
    public const PROTOCOL = 'specialist_council_independent_panel_v1';
    public const TYPES = ['specialist_council_independent_validation', 'specialist_council_descendant_transfer'];
    public const MAX_WINDOWS = 3;
    public const MAX_ARMS = 36;
    private const MAX_EXPOSURE_OWNERS = 256;

    public function __construct(
        private ResearchPaperEpochContractService $epochs,
        private SpecialistCouncilLifecycleService $lifecycle,
        private SpecialistCouncilContractService $contracts,
        private LabImmutableEvidenceService $evidence,
        private ResearchReleaseSealService $releases,
        private InstrumentResearchWindowService $windows,
    ) {}

    /** Validate original references; never accept executable flags or caller results. */
    public function register(ResearchExperimentWorkItem $work, array $input, ?string $actor,
        SpecialistCouncilVersion $parent, array $original): array
    {
        if (($input['protocol'] ?? null) === SpecialistCouncilPanelReservationService::PROTOCOL) {
            return app(SpecialistCouncilPanelReservationService::class)->register($work, $input, $actor, $parent, $original);
        }
        if (($input['protocol'] ?? null) !== self::PROTOCOL
            || array_diff(array_keys($input), ['protocol', 'target_version_id', 'window_generation_ids', 'arm_agent_ids', 'descendant_trait']) !== []
            || ! is_int($input['target_version_id'] ?? null) || $input['target_version_id'] <= 0
            || ! in_array($work->work_type, self::TYPES, true)) {
            throw new LogicException('COUNCIL_PANEL_REFERENCE_CONTRACT_INVALID');
        }
        $target = SpecialistCouncilVersion::findOrFail($input['target_version_id']);
        $owner = $this->owner($target);
        $this->assertParent($work, $parent, $target, $owner['plan'], (array) ($input['descendant_trait'] ?? []));
        // A caller-shaped draft is not the server-owned window reservation.
        $this->assertCanonicalWindowProducer();
    }

    /** Pure, original-owner readiness; a payload flag never wakes work. */
    public function inspect(ResearchExperimentWorkItem $work, SpecialistCouncilVersion $parent): array
    {
        try {
            if (data_get($work->payload, 'pending_panel_intent.reservation_protocol') === SpecialistCouncilPanelReservationService::PROTOCOL) {
                return app(SpecialistCouncilPanelReservationService::class)->inspect($work, $parent);
            }
            $body = data_get($work->payload, 'followup_resolution');
            if (! is_array($body)) {
                return $this->blocked($this->windows->readiness()['eligible_windows'] === []
                    ? 'NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW' : 'CANONICAL_AUTHORIZED_WINDOW_COHORT_PRODUCER_REQUIRED');
            }
            if (($body['protocol'] ?? '') !== SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL
                || ($body['executor_protocol'] ?? '') !== self::PROTOCOL
                || ($body['resolution_hash'] ?? '') !== $this->epochs->parameterHash(array_diff_key($body, ['resolution_hash' => true, 'server_seal' => true]))
                || ! is_string($body['server_seal'] ?? null) || ! hash_equals($body['server_seal'], $this->seal(array_diff_key($body, ['server_seal' => true])))
                || $body['work_item_id'] !== $work->id || $body['work_key'] !== $work->work_key || $body['work_type'] !== $work->work_type
                || $body['source_receipt_id'] !== $work->research_experiment_receipt_id || $body['source_version_id'] !== $parent->id
                || $body['source_manifest_hash'] !== $parent->manifest_hash || $body['source_assessment_hash'] !== $parent->assessment_hash
                || ($body['authority'] ?? '') !== 'research_only' || ($body['promotion_evidence'] ?? null) !== false
                || ($body['independent_evidence_claimed'] ?? null) !== false || ($body['max_experiments'] ?? null) !== 1) {
                throw new LogicException('COUNCIL_PANEL_SERVER_PREREGISTRATION_DRIFT');
            }
            if ($body['current_source_hash'] !== $this->evidence->codeHash()
                || $body['current_python_source_hash'] !== $this->releases->pythonHash()) throw new LogicException('COUNCIL_PANEL_PREREGISTERED_SOURCE_CHANGED');
            $target = SpecialistCouncilVersion::findOrFail($body['target_version_id']); $owner = $this->owner($target);
            if ($target->manifest_hash !== $body['target_manifest_hash'] || $owner['hash'] !== $body['target_plan_hash']
                || $owner['evaluator_id'] !== $body['evaluator_id']) throw new LogicException('COUNCIL_PANEL_ORIGINAL_PLAN_DRIFT');
            $this->assertParent($work, $parent, $target, $owner['plan'], $body['descendant_trait']);
            $this->assertCanonicalWindowProducer();
        } catch (Throwable $error) {
            return $this->blocked($error instanceof LogicException ? $error->getMessage() : 'COUNCIL_PANEL_ORIGINAL_OWNER_UNAVAILABLE');
        }
    }

    /** A caller cannot bypass the missing canonical owner through direct execution. */
    public function execute(ResearchExperimentWorkItem $item): array
    {
        try {
            $this->assertLease($item);
            if (data_get($item->payload, 'pending_panel_intent.reservation_protocol') === SpecialistCouncilPanelReservationService::PROTOCOL) {
                return app(SpecialistCouncilPanelReservationService::class)->execute($item);
            }
            $this->assertCanonicalWindowProducer();
        } catch (Throwable $error) {
            // A fenced, unavailable adapter has no operational write authority,
            // especially after the caller's original work lease has expired.
            return $this->blocked($error instanceof LogicException ? $error->getMessage() : 'COUNCIL_PANEL_EXECUTION_DEPENDENCY');
        }
    }

    public function assertConstructorIntent(array $intent): array
    {
        return app(SpecialistCouncilPanelReservationService::class)->assertConstructorIntent($intent);
    }

    public function assertExecutionUnit(ResearchExperimentWorkItem $work, \App\Models\LabGeneration $cohort, array $unit): void
    {
        app(SpecialistCouncilPanelReservationService::class)->assertExecutionUnit($work, $cohort, $unit);
    }

    /** Fresh independent cases only; sealed continuation/outcome verification has its own original owner. */
    public function assertUnobservedOriginalPhysicalQuestion(SpecialistCouncilVersion $version, array $plan, string $windowKey): void
    {
        $owner = $this->owner($version);
        $window = $plan['windows'][$windowKey] ?? null;
        if ($this->epochs->parameterHash($plan) !== $owner['hash'] || ! is_array($window)) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_EXPOSURE_OWNER_REQUIRED');
        }
        $scope = $window['evaluation_scope'] ?? $window;
        $start = CarbonImmutable::parse($scope['start_inclusive'], 'UTC')->utc();
        $end = CarbonImmutable::parse($scope['end_exclusive'], 'UTC')->utc();
        if (! $end->greaterThan($start)) throw new LogicException('ORIGINAL_NATIVE_POLICY_EXPOSURE_SCOPE_INVALID');
        $symbols = array_values(array_unique(array_map('strtoupper', array_merge(...array_column(
            array_column($version->manifest['members'], 'scope'), 'symbols')))));
        $candidates = $this->physicalExposureOwners($symbols, $start, $end);
        if ($candidates->count() > self::MAX_EXPOSURE_OWNERS) {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_EXPOSURE_LOOKUP_BUDGET_EXCEEDED');
        }
        foreach ($candidates as $candidate) {
            $prior = SpecialistCouncilVersion::findOrFail($candidate->specialist_council_version_id);
            $priorOwner = $this->owner($prior);
            $modelIds = array_column($priorOwner['plan']['arms'], 'model_version_id');
            $runs = LabEvaluationRun::whereIn('model_version_id', $modelIds)->where('status', 'completed')
                ->whereNotNull('request_hash')->whereNotNull('response_hash')->orderBy('id')->limit(self::MAX_ARMS + 1)->get();
            if ($runs->count() > self::MAX_ARMS) throw new LogicException('ORIGINAL_NATIVE_POLICY_EXPOSURE_RUN_BUDGET_EXCEEDED');
            foreach ($runs as $run) {
                $proof = $this->lifecycle->originalIndependentRunObservation($prior, $run);
                if (! is_array($proof)) throw new LogicException('ORIGINAL_NATIVE_POLICY_EXPOSURE_PROOF_UNAVAILABLE');
                if (! in_array(strtoupper((string) ($proof['symbol'] ?? '')), $symbols, true)) continue;
                $from = CarbonImmutable::parse($proof['start_inclusive'], 'UTC')->utc();
                $until = CarbonImmutable::parse($proof['end_exclusive'], 'UTC')->utc();
                if ($from->lessThan($end) && $until->greaterThan($start)) {
                    throw new LogicException('OBSERVED_NATIVE_POLICY_WINDOW_CANNOT_BE_PREREGISTERED');
                }
            }
        }
    }

    /** Bounded immutable-plan lookup; mutable generation/request projections cannot hide old exposure. */
    private function physicalExposureOwners(array $symbols, CarbonImmutable $start, CarbonImmutable $end)
    {
        $query = DB::table('specialist_council_evaluation_plans as plans')
            ->join('specialist_council_versions as versions', 'versions.id', '=', 'plans.specialist_council_version_id')
            ->select('plans.*')
            ->where('plans.plan->purpose', 'independent');
        $symbolSlots = implode(',', array_fill(0, count($symbols), '?'));
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $query->whereRaw("EXISTS (SELECT 1 FROM json_each(plans.plan, '$.arms') AS arm_rows
                JOIN lab_evaluation_runs AS runs ON runs.model_version_id = json_extract(arm_rows.value, '$.model_version_id')
                WHERE runs.status = 'completed' AND runs.request_hash IS NOT NULL AND runs.response_hash IS NOT NULL)");
            $query->whereRaw("EXISTS (SELECT 1 FROM json_tree(versions.manifest, '$.members') AS scope_rows
                WHERE scope_rows.fullkey LIKE '$.members[%].scope.symbols[%]' AND UPPER(scope_rows.value) IN ({$symbolSlots}))", $symbols);
            $query->whereRaw("EXISTS (SELECT 1 FROM json_each(plans.plan, '$.windows') AS window_rows
                WHERE substr(json_extract(window_rows.value, '$.start_inclusive'), 1, 19) < ?
                AND substr(json_extract(window_rows.value, '$.end_exclusive'), 1, 19) > ?)",
                [$end->format('Y-m-d\TH:i:s'), $start->format('Y-m-d\TH:i:s')]);
        } elseif ($driver === 'mysql') {
            // MariaDB 10.4 has no JSON_TABLE. Original panel owners have at
            // most three windows; extra slots are a dependency, never a tail
            // silently omitted from freshness checks.
            $query->whereRaw("EXISTS (SELECT 1 FROM lab_evaluation_runs AS runs
                WHERE JSON_CONTAINS(JSON_EXTRACT(plans.plan, '$.arms.*.model_version_id'), CAST(runs.model_version_id AS CHAR))
                AND runs.status = 'completed' AND runs.request_hash IS NOT NULL AND runs.response_hash IS NOT NULL)");
            $query->where(function ($scope) use ($symbols): void {
                foreach ($symbols as $symbol) $scope->orWhereRaw(
                    "JSON_SEARCH(LOWER(JSON_EXTRACT(versions.manifest, '$.members[*].scope.symbols')), 'one', ?) IS NOT NULL", [strtolower($symbol)]);
            });
            $query->where(function ($overlap) use ($start, $end): void {
                $overlap->whereRaw("JSON_LENGTH(JSON_EXTRACT(plans.plan, '$.windows')) > ?", [self::MAX_WINDOWS]);
                for ($index = 0; $index < self::MAX_WINDOWS; $index++) {
                    $key = "JSON_UNQUOTE(JSON_EXTRACT(JSON_KEYS(JSON_EXTRACT(plans.plan, '$.windows')), '$[{$index}]'))";
                    $from = "JSON_UNQUOTE(JSON_EXTRACT(plans.plan, CONCAT('$.windows.\"', {$key}, '\".start_inclusive')))";
                    $until = "JSON_UNQUOTE(JSON_EXTRACT(plans.plan, CONCAT('$.windows.\"', {$key}, '\".end_exclusive')))";
                    $overlap->orWhereRaw("LEFT({$from}, 19) < ? AND LEFT({$until}, 19) > ?",
                        [$end->format('Y-m-d\TH:i:s'), $start->format('Y-m-d\TH:i:s')]);
                }
            });
        } else {
            throw new LogicException('ORIGINAL_NATIVE_POLICY_EXPOSURE_DATABASE_UNSUPPORTED');
        }
        return $query->orderBy('plans.id')->limit(self::MAX_EXPOSURE_OWNERS + 1)->get();
    }

    private function owner(SpecialistCouncilVersion $version): array
    {
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $plan = $row ? json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! $this->contracts->manifestValid($version->manifest) || ! is_array($plan)
            || $this->epochs->parameterHash($plan) !== $row->plan_hash || $plan['manifest_hash'] !== $version->manifest_hash
            || $plan['purpose'] !== 'independent' || $row->evaluator_id === $version->creator_id
            || count($plan['windows']) < $version->manifest['evaluation_policy']['minimum_independent_windows']
            || count($plan['windows']) > self::MAX_WINDOWS || count($plan['arms']) > self::MAX_ARMS) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_INDEPENDENT_PLAN_REQUIRED');
        }
        return ['plan' => $plan, 'hash' => $row->plan_hash, 'sealed_at' => $row->sealed_at, 'evaluator_id' => $row->evaluator_id];
    }

    public function assertParent(ResearchExperimentWorkItem $work, SpecialistCouncilVersion $parent,
        SpecialistCouncilVersion $target, array $plan, array $trait): void
    {
        if (! in_array($work->work_type, self::TYPES, true) || $target->id === $parent->id || $target->council_id !== $parent->council_id) {
            throw new LogicException('COUNCIL_PANEL_FRESH_ORIGINAL_SUCCESSOR_REQUIRED');
        }
        $old = $this->memberVectors($parent->manifest); $new = $this->memberVectors($target->manifest);
        foreach (['execution', 'allocation', 'routing', 'risk'] as $field) {
            if (! $this->evidence->equivalentJsonValue($parent->manifest[$field] ?? null, $target->manifest[$field] ?? null)) {
                throw new LogicException('COUNCIL_PANEL_FROZEN_EXTERNAL_POLICY_CHANGED');
            }
        }
        // Native IDs and their ID-bound hashes can differ for an exact clone;
        // physical program equality is independently checked below. Every
        // objective/power/risk/non-regression criterion remains frozen.
        $identities = ['champion_model_version_id' => true, 'solo_model_version_id' => true,
            'champion_model_version_id_hash' => true, 'solo_model_version_id_hash' => true];
        $oldPolicy = array_diff_key($parent->manifest['evaluation_policy'], $identities);
        $newPolicy = array_diff_key($target->manifest['evaluation_policy'], $identities);
        if (! $this->evidence->equivalentJsonValue($oldPolicy, $newPolicy)) throw new LogicException('COUNCIL_PANEL_ORIGINAL_SCIENTIFIC_POLICY_CHANGED');
        if ($work->work_type === 'specialist_council_independent_validation') {
            if ($trait !== [] || ! $this->evidence->equivalentJsonValue($old, $new)
                || ! $this->evidence->equivalentJsonValue($parent->manifest['components'], $target->manifest['components'])) {
                throw new LogicException('COUNCIL_PANEL_VALIDATION_CANNOT_RETUNE_SELECTED_PROGRAM');
            }
            $this->assertOriginalComparators($parent, $plan);
            return;
        }
        $qualified = $this->lifecycle->qualifiedOriginalResearchProof($parent);
        if (($qualified['allowed'] ?? false) !== true) throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_QUALIFIED_PARENT_REQUIRED');
        // Only an original executable component has an exact runtime removal.
        // A scalar gene cannot be called an ablation by relabeling a carrier.
        if (array_diff(array_keys($trait), ['component_id', 'parent_candidate_model_version_id', 'parent_ablation_model_version_id',
                'contrast_parent_version_id', 'contrast_trait_version_id'])
            || ! is_string($trait['component_id'] ?? null)
            || ! in_array($trait['component_id'], $parent->manifest['evaluation_policy']['required_ablations'], true)
            || ! collect($parent->manifest['components'])->contains('id', $trait['component_id'])) {
            throw new LogicException('COUNCIL_DESCENDANT_EXACT_QUALIFIED_EXECUTABLE_TRAIT_REQUIRED');
        }
        $original = $this->owner($parent)['plan'];
        $originalCandidate = collect($original['arms'])->firstWhere('kind', 'candidate');
        $originalAblation = collect($original['arms'])->first(fn ($arm) => $arm['kind'] === 'ablation' && ($arm['removed_id'] ?? '') === $trait['component_id']);
        if (! $originalCandidate || ! $originalAblation
            || (int) ($trait['parent_candidate_model_version_id'] ?? 0) !== $originalCandidate['model_version_id']
            || (int) ($trait['parent_ablation_model_version_id'] ?? 0) !== $originalAblation['model_version_id']) {
            throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_PARENT_AND_TRAIT_ABLATION_REQUIRED');
        }
        $parentContrast = SpecialistCouncilVersion::find($trait['contrast_parent_version_id'] ?? 0);
        $traitContrast = SpecialistCouncilVersion::find($trait['contrast_trait_version_id'] ?? 0);
        if (! $parentContrast || ! $traitContrast || $parentContrast->id === $traitContrast->id
            || in_array($parentContrast->id, [$parent->id, $target->id], true) || in_array($traitContrast->id, [$parent->id, $target->id], true)
            || ! $this->contracts->manifestValid($parentContrast->manifest) || ! $this->contracts->manifestValid($traitContrast->manifest)) {
            throw new LogicException('COUNCIL_DESCENDANT_TWO_FROZEN_ORIGINAL_CONTRAST_VERSIONS_REQUIRED');
        }
        $p = $this->memberVectors($parentContrast->manifest); $pt = $this->memberVectors($traitContrast->manifest);
        $expectedP = $old;
        foreach ($expectedP as &$member) if (data_get($member, 'operator_contract.component_id') === $trait['component_id']) unset($member['operator_contract']);
        unset($member);
        $expectedComponents = array_values(array_filter($parent->manifest['components'], fn ($component) => $component['id'] !== $trait['component_id']));
        if (! $this->evidence->equivalentJsonValue($p, $expectedP) || ! $this->evidence->equivalentJsonValue($pt, $old)
            || ! $this->evidence->equivalentJsonValue($parentContrast->manifest['components'], $expectedComponents)
            || ! $this->evidence->equivalentJsonValue($traitContrast->manifest['components'], $parent->manifest['components'])
            || ! $this->evidence->equivalentJsonValue($target->manifest['components'], $parent->manifest['components'])) {
            throw new LogicException('COUNCIL_DESCENDANT_P_AND_P_PLUS_T_MUST_DIFFER_ONLY_BY_ORIGINAL_TRAIT');
        }
        foreach ([$parentContrast, $traitContrast] as $contrast) foreach (['routing', 'risk', 'allocation', 'execution'] as $field) {
            if (! $this->evidence->equivalentJsonValue($parent->manifest[$field], $contrast->manifest[$field])) {
                throw new LogicException('COUNCIL_DESCENDANT_CONTRAST_EXTERNAL_POLICY_CHANGED');
            }
        }
        $diffs = 0; $roles = [];
        if (array_keys($old) !== array_keys($new)) throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_ROSTER_CHANGED');
        foreach ($new as $id => $member) {
            $sourceMember = collect($parent->manifest['members'])->firstWhere('specialist_id', $id);
            $source = ModelVersion::findOrFail($sourceMember['model_version_id']);
            $oldParameters = $old[$id]['parameters']; $newParameters = $member['parameters'];
            if (array_diff(array_keys($oldParameters), array_keys($newParameters)) || array_diff(array_keys($newParameters), array_keys($oldParameters))) {
                throw new LogicException('COUNCIL_DESCENDANT_U_MUST_USE_EXISTING_LEGAL_NATIVE_GENES');
            }
            $schema = app(StrategyParameterSchemaService::class);
            $validated = $schema->validate($source->strategy, $newParameters);
            if ($this->epochs->parameterHash($validated) !== $this->epochs->parameterHash($newParameters)
                || $this->epochs->parameterHash($schema->normalizeForGeneration($source->strategy, $validated)) !== $this->epochs->parameterHash($newParameters)) {
                throw new LogicException('COUNCIL_DESCENDANT_U_REQUIRES_UNDECLARED_VECTOR_NORMALIZATION');
            }
            $delta = [];
            foreach ($newParameters as $gene => $value) {
                if ($this->evidence->equivalentJsonValue($value, $oldParameters[$gene])) continue;
                $admission = app(DependencyAwareEdgeGenesisFoundryService::class)->mutationAdmission($source, $gene);
                if (($admission['allowed'] ?? false) !== true) throw new LogicException('COUNCIL_DESCENDANT_U_MUTATION_NOT_ADMITTED:'.$gene);
                $delta[$gene] = ['old' => $oldParameters[$gene], 'new' => $value];
                ++$diffs; $roles[$id] = true;
            }
            if (app(InstrumentPolicyConsumptionService::class)->forbiddenDelta((array) data_get($source->metadata, 'instrument_learning_policy', []), $delta, $newParameters)) {
                throw new LogicException('INSTRUMENT_EXACT_DELTA_FORBIDDEN');
            }
            $member['parameters'] = $oldParameters;
            if (! $this->evidence->equivalentJsonValue($member, $old[$id])) throw new LogicException('COUNCIL_DESCENDANT_U_CHANGED_NON_PARAMETER_CONTRACT');
        }
        if ($diffs < 1 || $diffs > 4 || count($roles) > 2) throw new LogicException('COUNCIL_DESCENDANT_U_REQUIRES_ONE_BOUNDED_LEGAL_INTERVENTION');
        foreach ($plan['arms'] as $arm) {
            if (! in_array($arm['kind'], ['champion', 'solo'], true)) continue;
            $model = ModelVersion::findOrFail($arm['model_version_id']);
            $bound = $this->lifecycle->researchVersionForModel($model);
            $expected = $arm['kind'] === 'champion' ? $traitContrast : $parentContrast;
            if (! $bound || $bound->id !== $expected->id) throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_FOUR_PROGRAM_BINDINGS_REQUIRED');
        }
    }

    /** Pure legal projection; registration separately requires the original whole-council exam. */
    public function projectDescendantSources(SpecialistCouncilVersion $parent, string $componentId, array $deltas): array
    {
        if (! $this->contracts->manifestValid($parent->manifest)
            || ! in_array($componentId, $parent->manifest['evaluation_policy']['required_ablations'], true)
            || ! collect($parent->manifest['components'])->contains('id', $componentId)
            || ! collect($parent->manifest['members'])->contains(fn ($member) => data_get($member, 'operator_contract.component_id') === $componentId)) {
            throw new LogicException('COUNCIL_DESCENDANT_EXACT_QUALIFIED_EXECUTABLE_TRAIT_REQUIRED');
        }
        $ids = array_column($parent->manifest['members'], 'model_version_id', 'role');
        $specs = app(SpecialistCouncilResearchFeedbackService::class)->projectOriginalNativeSources($parent, $deltas);
        if (array_sum(array_map(fn ($spec) => count($spec['parameter_deltas']), $specs)) < 1) {
            throw new LogicException('COUNCIL_DESCENDANT_U_REQUIRES_ONE_BOUNDED_LEGAL_INTERVENTION');
        }
        $targets = [...array_column($parent->manifest['members'], 'specialist_id'), $componentId];
        $required = $parent->manifest['evaluation_policy']['required_ablations']; sort($targets); sort($required);
        if (count($ids) !== 4 || $targets !== $required) throw new LogicException('COUNCIL_DESCENDANT_EXACT_FOUR_MEMBER_AND_TRAIT_ABLATIONS_REQUIRED');
        return $specs;
    }

    /** A support-role exam or a caller's qualified flag cannot be a genetic parent. */
    public function assertDescendantOriginalParent(SpecialistCouncilVersion $parent, string $componentId): array
    {
        $proof = $this->lifecycle->qualifiedOriginalResearchProof($parent);
        if (($proof['allowed'] ?? false) !== true) throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_QUALIFIED_PARENT_REQUIRED');
        $plan = $this->owner($parent)['plan'];
        foreach ($plan['windows'] as $windowKey => $window) {
            $arms = array_filter($plan['arms'], fn ($arm) => $arm['window_key'] === $windowKey);
            if (! collect($arms)->contains(fn ($arm) => $arm['kind'] === 'ablation' && ($arm['removed_id'] ?? '') === $componentId)) {
                throw new LogicException('COUNCIL_DESCENDANT_ORIGINAL_PARENT_AND_TRAIT_ABLATION_REQUIRED');
            }
        }
        return [...$proof, 'executed_trait_proof' => $this->lifecycle->executedOriginalTraitProof($parent, $componentId)];
    }

    private function assertOriginalComparators(SpecialistCouncilVersion $parent, array $plan): void
    {
        foreach (['solo', 'champion'] as $kind) {
            $sourceId = (int) ($parent->manifest['evaluation_policy'][$kind.'_model_version_id'] ?? 0);
            // A founding council has no earned champion. Its unchanged solo
            // is the explicitly frozen founding control, not a caller's newly
            // weaker or outcome-selected "champion".
            if ($sourceId === 0 && $kind === 'champion') $sourceId = (int) ($parent->manifest['evaluation_policy']['solo_model_version_id'] ?? 0);
            $source = ModelVersion::find($sourceId);
            if (! $source) throw new LogicException('COUNCIL_PANEL_ORIGINAL_COMPARATOR_PROVENANCE_REQUIRED');
            $basis = $this->physicalProgram($source);
            foreach ($plan['arms'] as $arm) {
                if ($arm['kind'] !== $kind) continue;
                $actual = ModelVersion::findOrFail($arm['model_version_id']);
                if (! $this->evidence->equivalentJsonValue($basis, $this->physicalProgram($actual))) {
                    throw new LogicException('COUNCIL_PANEL_FROZEN_COMPARATOR_PROGRAM_CHANGED:'.$kind);
                }
            }
        }
    }

    private function physicalProgram(ModelVersion $model): array
    {
        return ['parameters' => (array) $model->parameters, 'runtime_basis' => $this->evidence->modelRuntimeBasis($model),
            'contextual_cell' => data_get($model->metadata, 'specialist_council_membership.contextual_cell'),
            'prospective_owner' => data_get($model->metadata, 'causal_learning_cohort'),
            'composition_owner' => data_get($model->metadata, 'smart_composition.composition_passport'),
            'instrument_owner' => data_get($model->metadata, 'instrument_research_assignment')];
    }

    private function memberVectors(array $manifest): array
    {
        $out = [];
        foreach ($manifest['members'] as $member) {
            $model = ModelVersion::findOrFail($member['model_version_id']);
            if (($member['source_model_hash'] ?? null) !== $this->contracts->modelHash($model)) {
                throw new LogicException('COUNCIL_PANEL_ORIGINAL_NATIVE_MEMBER_MODEL_DRIFT');
            }
            $out[$member['specialist_id']] = [...array_diff_key($member, array_flip([
                'model_version_id', 'source_model_hash', 'passport_hash', 'version', 'strategy',
                'strategy_version', 'tactic_version', 'management_version', 'qualified', 'qualified_evidence'])),
                'native_base_strategy' => data_get($model->metadata, 'base_strategy'),
                'native_architecture' => data_get($model->metadata, 'strategy_architecture')];
        }
        ksort($out); return $out;
    }

    private function assertLease(ResearchExperimentWorkItem $item, ?ResearchExperimentWorkItem $current = null): void
    {
        $current ??= $item->fresh();
        if ($current->status !== 'leased' || ! filled($item->lease_token) || $current->lease_token !== $item->lease_token
            || (int) $current->fence_version !== (int) $item->fence_version || ! $current->lease_expires_at?->isFuture()) {
            throw new LogicException('COUNCIL_PANEL_WORK_LEASE_NOT_CURRENT');
        }
    }

    private function seal(array $body): string
    {
        $key = (string) config('services.internal_api.token');
        if (strlen($key) < 32) throw new LogicException('COUNCIL_PANEL_SERVER_KEY_UNAVAILABLE');
        return hash_hmac('sha256', self::PROTOCOL."\n".$this->epochs->parameterHash($body), $key);
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'executable' => false,
            'reason' => $reason, 'paper_authority_granted' => false, 'promotion_evidence' => false];
    }

    /**
     * Original full transport is available, but today's canonical constructor
     * cannot reserve >=3 independent window cohorts while retaining the
     * single-active-generation invariant, nor close those native lifecycle
     * projections through a panel owner. An audit-shaped context or a caller
     * supplied generation ID is NOT that producer. Keep this adapter fenced
     * until a real public canonical reservation/terminal owner is integrated
     * and tested; do not silently rename this code prerequisite as missing data.
     */
    private function assertCanonicalWindowProducer(): void
    {
        throw new LogicException('CANONICAL_AUTHORIZED_WINDOW_COHORT_PRODUCER_REQUIRED');
    }
}
