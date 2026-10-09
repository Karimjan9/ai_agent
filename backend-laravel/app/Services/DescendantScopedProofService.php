<?php

namespace App\Services;

use App\Models\DescendantValueTrial;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact four-arm descendant topology; authority is owned by scoped certificates. */
class DescendantScopedProofService
{
    public const PROTOCOL = 'prospective_descendant_four_arm_v1';

    public const ARMS = ['P', 'P+T', 'P+T+U', 'P+U'];

    /** Prospective registry path; it does not construct or dispatch replay work. */
    public function preregister(LabSkillZooEntry $cartridge, array $models, array $scope): array
    {
        if (! Schema::hasTable('descendant_value_trials') || ! Schema::hasTable('skill_cartridge_revisions')
            || ! Schema::hasTable('scoped_research_certificates')) {
            return $this->blocked('DESCENDANT_SCOPED_REGISTRY_UNAVAILABLE');
        }
        if (count($models) !== 4 || array_diff(array_keys($models), self::ARMS) !== []) {
            return $this->blocked('DESCENDANT_EXACT_FOUR_ARM_ROSTER_REQUIRED');
        }
        $vectors = [];
        $subjects = [];
        foreach (self::ARMS as $arm) {
            $model = $models[$arm];
            if (! $model instanceof ModelVersion || ! $model->exists || ! ($model = $model->fresh())) {
                return $this->blocked('DESCENDANT_PERSISTED_ARM_MODELS_REQUIRED');
            }
            $vectors[$arm] = (array) $model->parameters;
            $subjects[$arm] = ['model_version_id' => (int) $model->id,
                'parameter_hash' => $this->hash($vectors[$arm]),
                'runtime_hash' => $this->hash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model))];
        }
        if (count(array_unique(array_column($subjects, 'model_version_id'))) !== 4) {
            return $this->blocked('DESCENDANT_FOUR_DISTINCT_MODEL_IDENTITIES_REQUIRED');
        }
        $cartridge = $cartridge->fresh();
        $runtimePrograms = [];
        foreach ($models as $model) {
            $model = $model->fresh();
            $basis = app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model);
            $runtimePrograms[] = $this->hash([
                'family' => app(StrategyParameterSchemaService::class)->runtimeBaseStrategy(
                    $model->strategy, data_get($model->metadata, 'base_strategy'), $cartridge?->strategy_family),
                'components' => $basis['components'],
            ]);
        }
        if (count(array_unique($runtimePrograms)) !== 1) {
            return $this->blocked('DESCENDANT_SHARED_RUNTIME_PROGRAM_REQUIRED');
        }
        $topology = $this->topology($vectors, (string) $cartridge?->gene_key);
        $intervention = (array) data_get($cartridge?->evidence, 'intervention', []);
        if ($topology['status'] !== 'topology_valid') return $topology;
        if (! $cartridge || $cartridge->status !== 'confirmed' || $cartridge->component_status !== 'component_confirmed'
            || ! array_key_exists('old_value', $intervention) || ! array_key_exists('tested_value', $intervention)
            || ! $this->same($topology['trait_delta']['old'], $intervention['old_value'] ?? null)
            || ! $this->same($topology['trait_delta']['new'], $intervention['tested_value'] ?? null)) {
            return $this->blocked('DESCENDANT_SOURCE_CARTRIDGE_EXACT_TRAIT_REQUIRED');
        }
        $revision = DB::table('skill_cartridge_revisions')->where('lab_skill_zoo_entry_id', $cartridge->id)
            ->where('revision', $cartridge->revision)->first();
        if (! $revision) return $this->blocked('DESCENDANT_IMMUTABLE_SOURCE_REVISION_REQUIRED');
        $revisionPayload = json_decode($revision->payload, true);
        if (! is_array($revisionPayload)
            || ! $this->same(data_get($revisionPayload, 'intervention.old_value'), $topology['trait_delta']['old'])
            || ! $this->same(data_get($revisionPayload, 'intervention.tested_value'), $topology['trait_delta']['new'])) {
            return $this->blocked('DESCENDANT_IMMUTABLE_SOURCE_REVISION_MISMATCH');
        }
        $context = app(ContextContractV2Service::class)->project((array) ($scope['context'] ?? []));
        $capsule = app(ContextualCausalTraitCapsuleService::class)->assess(
            (array) data_get($cartridge->evidence, 'trait_capsule', []), $cartridge->gene_key, (array) ($scope['context'] ?? []),
        );
        if ($context['status'] !== 'valid' || ! ($capsule['valid'] ?? false)) {
            return $this->blocked('DESCENDANT_EXACT_SOURCE_CONTEXT_REQUIRED');
        }
        try {
            $start = CarbonImmutable::parse((string) ($scope['validation_start'] ?? ''), 'UTC')->utc();
            $end = CarbonImmutable::parse((string) ($scope['validation_end'] ?? ''), 'UTC')->utc();
        } catch (\Throwable) {
            return $this->blocked('DESCENDANT_PROSPECTIVE_VALIDATION_INTERVAL_REQUIRED');
        }
        if (empty($scope['validation_start']) || empty($scope['validation_end']) || ! $start->greaterThan(now())
            || $start->lessThan('2027-01-01T00:00:00Z') || ! $end->greaterThan($start)
            || ! app(ResearchPaperEpochContractService::class)->researchIntervalDisjointFromPaper(
                $start->toIso8601String(), $end->toIso8601String())) {
            return $this->blocked('DESCENDANT_2027_PROSPECTIVE_PAPER_DISJOINT_INTERVAL_REQUIRED');
        }
        foreach (['evaluator_hash', 'execution_hash'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', (string) ($scope[$field] ?? ''))) {
                return $this->blocked('DESCENDANT_FROZEN_SCOPE_HASH_REQUIRED');
            }
        }
        $rule = (array) ($scope['stopping_rule'] ?? []);
        if (! is_int($rule['minimum_trades_per_arm'] ?? null) || $rule['minimum_trades_per_arm'] < 3
            || ! is_numeric($rule['minimum_effect'] ?? null) || ! is_finite((float) $rule['minimum_effect']) || $rule['minimum_effect'] < 0
            || ! in_array($scope['metric'] ?? null, ['profit_factor', 'net_profit', 'total_return_percent'], true)) {
            return $this->blocked('DESCENDANT_PREREGISTERED_METRIC_AND_POWER_REQUIRED');
        }
        $design = [
            'protocol' => self::PROTOCOL,
            'validation_start' => $start->toIso8601String(), 'validation_end' => $end->toIso8601String(),
            'evaluator_hash' => $scope['evaluator_hash'], 'execution_hash' => $scope['execution_hash'],
            'owner_source_hash' => hash_file('sha256', __FILE__), 'context_hash' => $context['identity_hash'],
            'data_manifest_hash' => $scope['data_manifest_hash'] ?? null,
            'metric' => $scope['metric'], 'stopping_rule' => $rule,
            'subject' => ['arm_models' => $subjects, 'arm_parameters' => $vectors,
                'source_cartridge' => ['id' => (int) $cartridge->id, 'key' => $cartridge->cartridge_key,
                    'revision' => (int) $cartridge->revision, 'revision_payload_hash' => $this->hash($revisionPayload)],
                'trait_delta' => $topology['trait_delta'], 'other_delta' => $topology['other_delta'],
                'context' => $context['extended_axes']],
            'research_only' => true, 'component_credit' => false, 'inheritance_credit' => false, 'promotion_evidence' => false,
        ];
        $designHash = $this->hash($design);
        $trialKey = $this->hash([self::PROTOCOL, $designHash]);

        try {
        return DB::transaction(function () use ($cartridge, $subjects, $design, $designHash, $trialKey): array {
            // Lock the original trait owner. A second label cannot reseal an
            // observed model or overwrite a preregistered question.
            LabSkillZooEntry::query()->whereKey($cartridge->id)->lockForUpdate()->firstOrFail();
            $trial = DescendantValueTrial::query()->where('trial_key', $trialKey)->first();
            if (! $trial) {
                $windowKey = $this->hash([$design['validation_start'], $design['validation_end']]);
                if (DescendantValueTrial::query()->where('mentor_model_version_id', $subjects['P+T']['model_version_id'])
                    ->where('child_model_version_id', $subjects['P+T+U']['model_version_id'])->where('window_key', $windowKey)->exists()) {
                    return $this->blocked('DESCENDANT_ORIGINAL_PHYSICAL_QUESTION_ALREADY_REGISTERED');
                }
                if (LabEvaluationRun::query()->whereIn('model_version_id', array_column($subjects, 'model_version_id'))->exists()) {
                    return $this->blocked('DESCENDANT_OBSERVED_ARM_CANNOT_BE_PREREGISTERED');
                }
                $trial = DescendantValueTrial::create([
                    'trial_key' => $trialKey, 'mentor_model_version_id' => $subjects['P+T']['model_version_id'],
                    'child_model_version_id' => $subjects['P+T+U']['model_version_id'],
                    'symbol' => strtoupper((string) $cartridge->symbol), 'timeframe' => strtoupper((string) $cartridge->timeframe),
                    'strategy_family' => $cartridge->strategy_family, 'window_key' => $windowKey,
                    'status' => 'scoped_preregistered', 'evidence' => ['scoped_proof' => [
                        'protocol' => self::PROTOCOL, 'design' => $design, 'design_hash' => $designHash,
                        'preregistered_at' => now()->utc()->toIso8601String(),
                    ]],
                ]);
            }
            $certificate = app(ScopedResearchCertificateService::class)->register('inheritance', $trial, $design);
            if (($certificate['valid'] ?? false) !== true) {
                throw new \LogicException('DESCENDANT_SCOPED_CERTIFICATE_REFUSED');
            }

            return ['protocol' => self::PROTOCOL, 'status' => 'scoped_preregistered', 'trial_id' => (int) $trial->id,
                'certificate' => $certificate, 'executor_status' => 'requires_original_owner_bound_four_arm_products',
                'research_only' => true, 'inheritance_credit' => false, 'promotion_evidence' => false];
        });
        } catch (\LogicException $exception) {
            return $this->blocked($exception->getMessage());
        }
    }

    /** Revalidate the original parameter topology before exposing its certificate. */
    public function inspect(int $trialId, int $certificateId): array
    {
        $trial = DescendantValueTrial::query()->find($trialId);
        $design = (array) data_get($trial?->evidence, 'scoped_proof.design', []);
        if (! $trial || data_get($trial->evidence, 'scoped_proof.protocol') !== self::PROTOCOL
            || $this->hash($design) !== data_get($trial->evidence, 'scoped_proof.design_hash')
            || ($design['owner_source_hash'] ?? null) !== hash_file('sha256', __FILE__)) {
            return $this->blocked('DESCENDANT_ORIGINAL_SEALED_TRIAL_REQUIRED');
        }
        $identities = (array) data_get($design, 'subject.arm_models', []);
        if (count($identities) !== 4 || array_diff(array_keys($identities), self::ARMS) !== []) {
            return $this->blocked('DESCENDANT_EXACT_FOUR_ARM_ROSTER_REQUIRED');
        }
        foreach ($identities as $identity) {
            $model = ModelVersion::find((int) ($identity['model_version_id'] ?? 0));
            if (! $model || $this->hash((array) $model->parameters) !== ($identity['parameter_hash'] ?? null)
                || $this->hash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model)) !== ($identity['runtime_hash'] ?? null)) {
                return $this->blocked('DESCENDANT_FROZEN_ARM_DRIFT');
            }
        }
        $source = (array) data_get($design, 'subject.source_cartridge', []);
        $cartridge = LabSkillZooEntry::find((int) ($source['id'] ?? 0));
        $revision = DB::table('skill_cartridge_revisions')->where('lab_skill_zoo_entry_id', $source['id'] ?? 0)
            ->where('revision', $source['revision'] ?? 0)->first();
        $payload = $revision ? json_decode($revision->payload, true) : null;
        if (! $cartridge || $cartridge->status !== 'confirmed' || $cartridge->component_status !== 'component_confirmed'
            || $cartridge->cartridge_key !== ($source['key'] ?? null) || ! is_array($payload)
            || $this->hash($payload) !== ($source['revision_payload_hash'] ?? null)) {
            return $this->blocked('DESCENDANT_ORIGINAL_SOURCE_REVISION_DRIFT');
        }
        $certificate = app(ScopedResearchCertificateService::class)->inspect($certificateId);
        if (($certificate['valid'] ?? false) !== true || ($certificate['scope'] ?? null) !== 'inheritance'
            || ($certificate['source_id'] ?? null) !== $trialId
            || ($certificate['design_hash'] ?? null) !== $this->hash($design)) {
            return $this->blocked('DESCENDANT_EXACT_SCOPED_CERTIFICATE_REQUIRED');
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'scoped_proof_inspected', 'trial_id' => $trialId,
            'certificate' => $certificate,
            'executor_status' => 'requires_original_owner_bound_four_arm_products',
            'research_only' => true, 'inheritance_credit' => false, 'promotion_evidence' => false];
    }

    /**
     * Only original immutable products are accepted. A complete comparison
     * remains a diagnostic; the registry exposes no economic authority here.
     */
    public function settle(int $trialId, int $certificateId, array $runIds): array
    {
        $inspection = $this->inspect($trialId, $certificateId);
        if ($inspection['status'] === 'blocked') return $inspection;
        if (count($runIds) !== 4 || array_diff(array_keys($runIds), self::ARMS) !== []
            || count(array_unique($runIds)) !== 4) {
            return $this->blocked('DESCENDANT_FOUR_ORIGINAL_RUN_IDENTITIES_REQUIRED');
        }
        $trial = DescendantValueTrial::findOrFail($trialId);
        $design = (array) data_get($trial->evidence, 'scoped_proof.design');
        if (CarbonImmutable::parse($design['validation_end'])->greaterThan(now())) {
            return $this->blocked('DESCENDANT_VALIDATION_INTERVAL_NOT_MATURE');
        }
        $immutable = app(LabImmutableEvidenceService::class);
        $values = [];
        $witnesses = [];
        $windows = [];
        $generations = [];
        foreach (self::ARMS as $arm) {
            $run = LabEvaluationRun::find($runIds[$arm]);
            $subject = $design['subject']['arm_models'][$arm];
            $runtime = $run ? $immutable->verifiedModelRuntimeIdentity($run) : null;
            if (! $run || (int) $run->model_version_id !== $subject['model_version_id']
                || ! $run->started_at || ! $run->started_at->greaterThan(CarbonImmutable::parse(
                    data_get($trial->evidence, 'scoped_proof.preregistered_at')))
                || $run->code_hash !== $design['evaluator_hash']
                || $runtime === null || ($runtime['parameter_hash'] ?? null) !== $subject['parameter_hash']
                || $this->hash((array) ($runtime['runtime_basis'] ?? [])) !== $subject['runtime_hash']
                || ! ($immutable->learningEligibility($run)['complete'] ?? false)) {
                return $this->blocked('DESCENDANT_COMPLETE_ORIGINAL_ARM_EVIDENCE_REQUIRED');
            }
            try {
                $request = $this->originalArtifact($run, 'evaluation_request', (string) $runtime['request_artifact_hash']);
                $response = $this->originalArtifact($run, 'evaluation_response', (string) $run->response_hash);
            } catch (\Throwable) {
                return $this->blocked('DESCENDANT_ORIGINAL_ARTIFACT_HASH_INVALID');
            }
            $strategies = array_values((array) ($request['strategies'] ?? []));
            $parameters = count($strategies) === 1 ? ($strategies[0]['parameters'] ?? null) : null;
            if ($request === null || $response === null || ! is_array($parameters)
                || ! $this->same($parameters, $design['subject']['arm_parameters'][$arm])
                || (int) ($strategies[0]['lab_agent_id'] ?? 0) !== (int) $run->lab_agent_id
                || $this->hash($parameters) !== $subject['parameter_hash']
                || ($request['replay_dataset_hash'] ?? null) !== $run->data_hash
                || data_get($request, 'execution_contract.execution_hash', data_get($request, 'execution_hash')) !== $design['execution_hash']) {
                return $this->blocked('DESCENDANT_ORIGINAL_ARM_REQUEST_SCOPE_MISMATCH');
            }
            $manifest = (array) ($response['replay_manifest'] ?? []);
            $window = app(InstrumentResearchWindowService::class)->sealForDataset((string) $run->data_hash, $manifest);
            if ($window === null || CarbonImmutable::parse($window['start_inclusive'])->lessThan(CarbonImmutable::parse($design['validation_start']))
                || CarbonImmutable::parse($window['end_exclusive'])->greaterThan(CarbonImmutable::parse($design['validation_end']))
                || (($design['data_manifest_hash'] ?? null) !== null && $design['data_manifest_hash'] !== $run->data_hash)) {
                return $this->blocked('DESCENDANT_ORIGINAL_AUTHORIZED_VALIDATION_WINDOW_REQUIRED');
            }
            $slice = $this->contextSlice($response, $design);
            if ($slice === null) return $this->blocked('DESCENDANT_POWERED_EXACT_CONTEXT_MEASUREMENT_REQUIRED');
            $values[$arm] = $slice[$design['metric']];
            $windows[$arm] = $window;
            $generations[] = (int) $run->lab_generation_id;
            $witnesses[$arm] = ['run_id' => (int) $run->id, 'run_key' => $run->run_id,
                'model_version_id' => (int) $run->model_version_id, 'request_hash' => $run->request_hash,
                'response_hash' => $run->response_hash, 'data_hash' => $run->data_hash,
                'context_measurement' => $slice];
        }
        if (count(array_unique($generations)) !== 1 || $generations[0] <= 0) {
            return $this->blocked('DESCENDANT_FOUR_ARMS_REQUIRE_ONE_ORIGINAL_COHORT');
        }
        foreach ($windows as $window) {
            if (! $this->same($window, $windows['P'])) {
                return $this->blocked('DESCENDANT_FOUR_ARMS_REQUIRE_ONE_PHYSICAL_WINDOW');
            }
        }
        $effects = $this->effects($values, (float) $design['stopping_rule']['minimum_effect']);
        $assessment = [...$effects, 'original_arm_witnesses' => $witnesses,
            'authorized_window' => $windows['P'], 'unused_market_exposure' => 'requires_separate_original_provenance',
            'authority' => false, 'scope_authority_confirmed' => false];

        return DB::transaction(function () use ($trialId, $certificateId, $assessment): array {
            $trial = DescendantValueTrial::query()->whereKey($trialId)->lockForUpdate()->firstOrFail();
            $existing = data_get($trial->evidence, 'scoped_assessment');
            if ($existing !== null && ! $this->same($existing, $assessment)) {
                return $this->blocked('DESCENDANT_ORIGINAL_ASSESSMENT_IMMUTABLE');
            }
            $certificate = app(ScopedResearchCertificateService::class)->recordAssessment($certificateId, $assessment);
            if (($certificate['valid'] ?? false) !== true) {
                throw new \LogicException('DESCENDANT_SCOPED_ASSESSMENT_REFUSED:'.($certificate['reason_code'] ?? 'UNKNOWN'));
            }
            $trial->update(['status' => 'scoped_diagnostic', 'settled_at' => $trial->settled_at ?? now(),
                'evidence' => [...(array) $trial->evidence, 'scoped_assessment' => $assessment]]);

            return ['protocol' => self::PROTOCOL, 'status' => 'scoped_diagnostic', 'trial_id' => $trialId,
                'certificate' => $certificate, 'assessment' => $assessment,
                'research_only' => true, 'inheritance_credit' => false, 'promotion_evidence' => false];
        });
    }

    private function originalArtifact(LabEvaluationRun $run, string $type, string $hash): ?array
    {
        $artifact = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', $type)->latest('id')->first();
        if (! $artifact || $artifact->sha256 !== $hash) return null;
        $payload = app(LabImmutableEvidenceService::class)->readArtifactPayload($artifact);
        if (! $artifact->storage_path && app(LabImmutableEvidenceService::class)->hash($payload) !== $hash) return null;

        return $payload;
    }

    private function contextSlice(array $response, array $design): ?array
    {
        $trace = (array) ($response['instrument_research_trace'] ?? []);
        if (($trace['context_source'] ?? null) !== 'decision_time_trade_ledger'
            || ($trace['context_slice_protocol'] ?? null) !== 'venue_phase_v1') return null;
        $matches = [];
        foreach ((array) ($trace['exact_context_slices'] ?? []) as $slice) {
            if (! is_array($slice)) continue;
            $context = app(ContextContractV2Service::class)->project((array) ($slice['context'] ?? []));
            $metrics = (array) ($slice['metrics'] ?? []);
            $value = $metrics[$design['metric']] ?? null;
            if ($context['identity_hash'] === $design['context_hash']
                && is_int($metrics['trades'] ?? null) && $metrics['trades'] >= $design['stopping_rule']['minimum_trades_per_arm']
                && is_numeric($value) && is_finite((float) $value)) $matches[] = $metrics;
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Every arm is a full parameter vector. No trait-preserved or ablation
     * boolean can substitute for the two actual, disjoint parameter deltas.
     */
    public function topology(array $arms, string $traitGene): array
    {
        if (count($arms) !== 4 || array_diff(array_keys($arms), self::ARMS) !== [] || $traitGene === '') {
            return $this->blocked('DESCENDANT_EXACT_FOUR_ARM_ROSTER_REQUIRED');
        }
        foreach ($arms as $parameters) {
            if (! is_array($parameters) || $parameters === [] || array_is_list($parameters)) {
                return $this->blocked('DESCENDANT_FULL_PARAMETER_VECTORS_REQUIRED');
            }
        }
        $trait = $this->diff($arms['P'], $arms['P+T']);
        $other = $this->diff($arms['P'], $arms['P+U']);
        $retainedTrait = $this->diff($arms['P+U'], $arms['P+T+U']);
        $retainedOther = $this->diff($arms['P+T'], $arms['P+T+U']);
        if (count($trait) !== 1 || array_key_first($trait) !== $traitGene
            || count($other) !== 1 || array_key_first($other) === $traitGene
            || ! array_key_exists('old', $trait[$traitGene]) || ! array_key_exists('new', $trait[$traitGene])
            || ! array_key_exists('old', reset($other)) || ! array_key_exists('new', reset($other))
            || ! $this->same($trait, $retainedTrait)
            || ! $this->same($other, $retainedOther)) {
            return $this->blocked('DESCENDANT_MATCHED_T_AND_U_ABLATION_REQUIRED');
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'topology_valid',
            'trait_delta' => ['gene' => $traitGene, ...$trait[$traitGene]],
            'other_delta' => ['gene' => array_key_first($other), ...reset($other)],
            'arms' => array_replace(array_fill_keys(self::ARMS, []), $arms),
            'research_only' => true,
            'component_credit' => false,
            'inheritance_credit' => false,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Caller metrics are useful only as arithmetic inputs. The certificate
     * owner must independently verify the original four products before any
     * of these effects can constitute an observed certificate.
     */
    public function effects(array $values, float $minimumEffect = 0.0): array
    {
        if (count($values) !== 4 || array_diff(array_keys($values), self::ARMS) !== []
            || ! is_finite($minimumEffect) || $minimumEffect < 0) {
            return $this->blocked('DESCENDANT_COMPLETE_FINITE_VALUES_REQUIRED');
        }
        foreach ($values as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return $this->blocked('DESCENDANT_COMPLETE_FINITE_VALUES_REQUIRED');
            }
        }
        $p = (float) $values['P'];
        $pt = (float) $values['P+T'];
        $ptu = (float) $values['P+T+U'];
        $pu = (float) $values['P+U'];
        $t = $pt - $p;
        $u = $pu - $p;
        $retainedT = $ptu - $pu;
        $retainedU = $ptu - $pt;
        $interaction = $ptu - $pt - $pu + $p;
        $bundle = $ptu - $p;
        foreach ([$t, $u, $retainedT, $retainedU, $interaction, $bundle] as $effect) {
            if (! is_finite($effect)) return $this->blocked('DESCENDANT_COMPLETE_FINITE_VALUES_REQUIRED');
        }
        // A successful organism can lose either marginal contribution. That
        // result proves only the bundle comparison until its ablations agree.
        $traitObserved = $t > $minimumEffect && $retainedT > $minimumEffect;
        $otherObserved = $u > $minimumEffect && $retainedU > $minimumEffect;

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'four_arm_effects_computed',
            'effects' => [
                'T_over_P' => $t,
                'U_over_P' => $u,
                'T_retained_under_U' => $retainedT,
                'U_incremental_under_T' => $retainedU,
                'interaction' => $interaction,
                'bundle_over_P' => $bundle,
            ],
            'attribution' => $bundle > $minimumEffect && ! ($traitObserved && $otherObserved)
                ? 'bundle_only' : ($traitObserved && $otherObserved ? 'individual_ablation_supported' : 'not_positive'),
            'trait_retained' => $traitObserved,
            'other_incremental' => $otherObserved,
            'research_only' => true,
            'component_credit' => false,
            'inheritance_credit' => false,
            'independent_market_evidence' => false,
            'promotion_evidence' => false,
        ];
    }

    private function diff(array $old, array $new): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $gene) {
            if (! array_key_exists($gene, $old) || ! array_key_exists($gene, $new)) {
                $diff[$gene] = ['missing_parameter' => true];
            } elseif (! $this->same($old[$gene], $new[$gene])) {
                $diff[$gene] = ['old' => $old[$gene], 'new' => $new[$gene]];
            }
        }

        return $diff;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return app(LabImmutableEvidenceService::class)->equivalentJsonValue($left, $right);
    }

    private function hash(mixed $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => $reason,
            'research_only' => true, 'component_credit' => false, 'inheritance_credit' => false,
            'promotion_evidence' => false];
    }
}
