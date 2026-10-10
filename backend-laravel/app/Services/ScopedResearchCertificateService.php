<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\DescendantValueTrial;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\ScopedResearchCertificate;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/**
 * Storage boundary for exact research questions. Original producers own their
 * designs and observations. Caller assessments are diagnostic only. The
 * separately versioned original issuer grants exact research scope, never
 * model promotion, global parent, paper or live permission.
 */
class ScopedResearchCertificateService
{
    public const PROTOCOL = 'scoped_research_certificate_v1';

    public const AUTHORITY_POLICY = 'independent_scoped_research_authority_v1';

    public const SCOPES = ['component', 'selector', 'council', 'inheritance'];

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    /** Shared pre-seal normalization for original producers; grants no authority. */
    public function normalizeProspectiveDesign(string $scope, array $design): array
    {
        return $this->normalizeDesign($scope, $design);
    }

    /** The supplied design is sealed as a question, not accepted as proof. */
    public function register(string $scope, Model $source, array $design): array
    {
        $this->assertSourceScope($scope, $source);
        if (! Schema::hasTable('scoped_research_certificates')) {
            return $this->blocked('SCOPED_CERTIFICATE_MIGRATION_REQUIRED');
        }
        $design = $this->normalizeDesign($scope, $design);
        if ($scope === 'component' && array_key_exists('native_execution', $design)) {
            $design = app(DescendantScopedExecutionService::class)->prepareComponentExecution($design, $source);
        }
        $key = $this->hash([self::PROTOCOL, 'preregistration', $scope, $source::class, (int) $source->getKey()]);

        return DB::transaction(function () use ($key, $scope, $source, $design): array {
            $original = $source->newQuery()->whereKey($source->getKey())->lockForUpdate()->first();
            if (! $original) throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_SOURCE_REQUIRED');
            $snapshot = $this->sourceSnapshot($original, $design);
            $sourceHash = $this->hash($snapshot);
            $designHash = $this->hash($design);
            $existing = ScopedResearchCertificate::where('certificate_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->design_hash, $designHash)
                    || ! hash_equals($existing->source_hash, $sourceHash)) {
                    throw new LogicException('SCOPED_CERTIFICATE_IMMUTABLE_DESIGN_OR_SOURCE_CHANGED');
                }

                return $this->inspect((int) $existing->id);
            }
            $clock = CarbonImmutable::now('UTC');
            if (! $clock->lessThan(CarbonImmutable::parse($design['validation_start']))) {
                throw new LogicException('SCOPED_CERTIFICATE_RETROSPECTIVE_REGISTRATION_FORBIDDEN');
            }
            if (($design['source_hypothesis_only'] ?? false) !== true && $this->alreadyObserved($original, $snapshot)) {
                throw new LogicException('SCOPED_CERTIFICATE_VALIDATION_OUTCOMES_ALREADY_OBSERVED');
            }
            $row = $this->append($key, $scope, 'preregistration', $original, $sourceHash, $designHash, [
                'protocol' => self::PROTOCOL, 'scope' => $scope,
                'source_type' => $original::class, 'source_id' => (int) $original->getKey(),
                'source_snapshot' => $snapshot, 'source_hash' => $sourceHash,
                'design' => $design, 'design_hash' => $designHash,
                'preregistered_at' => $clock->toIso8601String(),
                'authority' => false, 'promotion_evidence' => false,
            ], $clock);
            if (($design['authority_policy'] ?? null) === self::AUTHORITY_POLICY) {
                app(ResearchWindowExposureInventoryService::class)->registerCapture((int) $row->id);
                if ($scope === 'component' && isset($design['native_execution'])) {
                    app(DescendantScopedExecutionService::class)->registerComponentWork((int) $row->id);
                }
            }

            return $this->inspect((int) $row->id);
        });
    }

    /** Original question proof without data/authority recursion (capture ingress). */
    public function verifiedRegistration(int $id): array
    {
        $row = ScopedResearchCertificate::find($id);
        if (! $row || $row->record_type !== 'preregistration') {
            throw new LogicException('SCOPED_CERTIFICATE_PREREGISTRATION_REQUIRED');
        }
        $source = $this->verifiedSource($row);
        return ['certificate_id' => (int) $row->id, 'scope' => $row->scope,
            'source_type' => $source::class, 'source_id' => (int) $source->getKey(),
            'design' => $row->payload['design'], 'source_snapshot' => $row->payload['source_snapshot'],
            'source_hash' => $row->source_hash, 'design_hash' => $row->design_hash,
            'preregistered_at' => $row->preregistered_at->toIso8601String()];
    }

    /** Only named original producer verification, never a caller assessment. */
    public function issueIndependent(int $id, array $originalProducts): array
    {
        return DB::transaction(function () use ($id, $originalProducts): array {
            $row = ScopedResearchCertificate::whereKey($id)->lockForUpdate()->firstOrFail();
            $registration = $this->verifiedRegistration($id);
            $assessment = app(ScopedResearchAuthorityService::class)->assess($registration, $originalProducts);
            if (($assessment['status'] ?? null) === 'blocked_dependency') {
                return [...$this->inspect($id), 'issuance' => $assessment];
            }
            $key = $this->hash([self::AUTHORITY_POLICY, 'independent_assessment', $id]);
            $existing = ScopedResearchCertificate::where('certificate_key', $key)->first();
            if ($existing) {
                $this->assertRecord($existing);
                if ($this->hash($existing->payload['original_assessment']) !== $this->hash($assessment)) {
                    throw new LogicException('SCOPED_CERTIFICATE_INDEPENDENT_ASSESSMENT_IMMUTABLE');
                }
            } else {
                $source = $this->verifiedSource($row);
                $this->append($key, $row->scope, 'independent_assessment', $source, $row->source_hash,
                    $row->design_hash, ['protocol' => self::AUTHORITY_POLICY, 'certificate_id' => $id,
                        'original_products' => $originalProducts, 'original_assessment' => $assessment,
                        'paper_or_live_authority' => false, 'promotion_evidence' => false],
                    $row->preregistered_at, $id);
            }
            return $this->inspect($id);
        });
    }

    /** Whole prospective window roster, derived from original server registries. */
    public function independentWindowReadiness(int $id): array
    {
        $registration = $this->verifiedRegistration($id);
        $design = $registration['design'];
        $expected = (array) ($design['validation_windows'] ?? []);
        $windows = []; $reasons = [];
        if (($design['authority_policy'] ?? null) !== self::AUTHORITY_POLICY
            || count($expected) < max(3, (int) config('services.learning_lane.causal_minimum_powered_windows', 6))
            || count($expected) > 12) {
            return ['ready' => false, 'status' => 'blocked_dependency', 'windows' => [],
                'reason_codes' => ['SCOPED_ORIGINAL_WINDOW_ROSTER_REQUIRED']];
        }
        foreach ($expected as $period) {
            $matches = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
                fn ($record): bool => is_array($record)
                    && $this->utc($record['start_inclusive'] ?? null)?->toIso8601String()
                        === $this->utc($period['start_inclusive'] ?? null)?->toIso8601String()
                    && $this->utc($record['end_exclusive'] ?? null)?->toIso8601String()
                        === $this->utc($period['end_exclusive'] ?? null)?->toIso8601String()));
            if (count($matches) !== 1) { $reasons[] = 'SCOPED_ORIGINAL_WINDOW_NOT_AUTHORIZED'; continue; }
            $record = $matches[0];
            $window = app(InstrumentResearchWindowService::class)->seal(
                (string) ($record['authorization_id'] ?? ''), (string) ($record['dataset_sha256'] ?? ''));
            if (! $window) { $reasons[] = 'SCOPED_ORIGINAL_WINDOW_NOT_MATURE'; continue; }
            $models = array_keys((array) $registration['source_snapshot']['models']);
            $runs = LabEvaluationRun::whereIn('model_version_id', $models)
                ->where('data_hash', $window['dataset_sha256'])->orderBy('id')->limit(201)->pluck('id')->map('intval')->all();
            if (count($runs) > 200) { $reasons[] = 'SCOPED_ORIGINAL_PRODUCT_BOUND_EXCEEDED'; continue; }
            $manifest = (array) ($record['mtf_bundle_manifest'] ?? []);
            $proof = app(ResearchWindowExposureInventoryService::class)->assessForCertificate($id, $window, $manifest, $runs);
            $windows[] = ['window' => $window, 'manifest' => $manifest, 'original_readiness' => $proof];
            if (($proof['ready'] ?? false) !== true) $reasons[] = $proof['reason_code'] ?? 'SCOPED_ORIGINAL_EXPOSURE_INCOMPLETE';
        }
        return ['ready' => $reasons === [] && count($windows) === count($expected),
            'status' => $reasons === [] ? 'ready' : 'blocked_dependency', 'windows' => $windows,
            'reason_codes' => array_values(array_unique($reasons))];
    }

    /** Re-derive integrity against persisted originals; a valid draft is still blocked. */
    public function inspect(int $id): array
    {
        try {
            $row = ScopedResearchCertificate::find($id);
            if (! $row || $row->record_type !== 'preregistration') {
                throw new LogicException('SCOPED_CERTIFICATE_PREREGISTRATION_REQUIRED');
            }
            $source = $this->verifiedSource($row);
            $assessment = ScopedResearchCertificate::where('parent_certificate_id', $row->id)
                ->where('record_type', 'diagnostic_assessment')->first();
            if ($assessment) $this->assertRecord($assessment);
            $binding = $this->inspectDataBinding($row);

            $originalAuthority = null;
            $authorityRow = ScopedResearchCertificate::where('parent_certificate_id', $id)
                ->where('record_type', 'independent_assessment')->first();
            if ($authorityRow) {
                $this->assertRecord($authorityRow);
                $rederived = app(ScopedResearchAuthorityService::class)->assess(
                    $this->verifiedRegistration($id), (array) $authorityRow->payload['original_products']);
                if ($this->hash($rederived) === $this->hash($authorityRow->payload['original_assessment'])) {
                    $originalAuthority = $rederived;
                }
            }

            return [
                ...$this->blocked('NAMED_ORIGINAL_PRODUCER_AND_INDEPENDENT_PROVENANCE_REQUIRED'),
                'certificate_id' => (int) $row->id, 'scope' => $row->scope,
                'source_type' => $source::class, 'source_id' => (int) $source->getKey(),
                'source_hash' => $row->source_hash, 'design_hash' => $row->design_hash,
                'design' => $row->payload['design'],
                'preregistered_at' => $row->payload['preregistered_at'],
                'status' => ($originalAuthority['confirmed'] ?? false) ? 'independently_confirmed_'.$row->scope
                    : ($authorityRow ? 'independent_negative_or_inconclusive' : ($assessment ? 'assessed_diagnostic'
                    : (($binding['valid'] ?? false) ? 'data_bound_diagnostic' : 'preregistered_diagnostic'))),
                'valid' => true, 'diagnostic_assessment' => $assessment?->payload['assessment'],
                'assessment_record_id' => $assessment ? (int) $assessment->id : null,
                'data_binding' => $binding,
                'original_readiness' => $binding['original_readiness'] ?? null,
                'original_authority' => $originalAuthority === null ? [] : [$row->scope => $originalAuthority],
                'scope_authority_confirmed' => ($originalAuthority['confirmed'] ?? false) === true,
                'confirmed_component' => $row->scope === 'component' && ($originalAuthority['confirmed'] ?? false) === true,
                'confirmed_selector' => $row->scope === 'selector' && ($originalAuthority['confirmed'] ?? false) === true,
                'confirmed_council' => $row->scope === 'council' && ($originalAuthority['confirmed'] ?? false) === true,
                'confirmed_inheritance' => $row->scope === 'inheritance' && ($originalAuthority['confirmed'] ?? false) === true,
                'authority_record_id' => $authorityRow?->id,
                'authority' => ($originalAuthority['confirmed'] ?? false) === true,
            ];
        } catch (Throwable $error) {
            return [...$this->blocked($error instanceof LogicException ? $error->getMessage()
                : 'SCOPED_CERTIFICATE_ORIGINAL_OWNER_UNAVAILABLE'), 'certificate_id' => $id];
        }
    }

    /** Append one immutable diagnostic. No public verdict can certify its own scope. */
    public function recordAssessment(int $id, array $assessment): array
    {
        return DB::transaction(function () use ($id, $assessment): array {
            $row = ScopedResearchCertificate::whereKey($id)->lockForUpdate()->first();
            if (! $row || $row->record_type !== 'preregistration') {
                throw new LogicException('SCOPED_CERTIFICATE_PREREGISTRATION_REQUIRED');
            }
            $source = $this->verifiedSource($row);
            $assessment = $this->diagnostic($assessment);
            $key = $this->hash([self::PROTOCOL, 'diagnostic_assessment', (int) $row->id]);
            $existing = ScopedResearchCertificate::where('certificate_key', $key)->first();
            if ($existing) {
                $this->assertRecord($existing);
                if (! hash_equals($this->hash($existing->payload['assessment']), $this->hash($assessment))) {
                    throw new LogicException('SCOPED_CERTIFICATE_COMPLETED_ASSESSMENT_IMMUTABLE');
                }

                return $this->inspect($id);
            }
            $this->append($key, $row->scope, 'diagnostic_assessment', $source,
                $row->source_hash, $row->design_hash, [
                    'protocol' => self::PROTOCOL, 'scope' => $row->scope,
                    'certificate_id' => $id, 'source_hash' => $row->source_hash,
                    'design_hash' => $row->design_hash, 'assessment' => $assessment,
                    'authority' => false, 'promotion_evidence' => false,
                ], $row->preregistered_at, $id);

            return $this->inspect($id);
        });
    }

    /**
     * Append actual closed bytes only after the original four-file owner has
     * reopened them. This does not attest unused exposure or enable execution.
     */
    public function bindOriginalData(int $id, array $sealedWindow, array $manifest): array
    {
        return DB::transaction(function () use ($id, $sealedWindow, $manifest): array {
            $row = ScopedResearchCertificate::whereKey($id)->lockForUpdate()->first();
            if (! $row || $row->record_type !== 'preregistration') {
                throw new LogicException('SCOPED_CERTIFICATE_PREREGISTRATION_REQUIRED');
            }
            $source = $this->verifiedSource($row);
            $design = (array) $row->payload['design'];
            if (($design['source_hypothesis_only'] ?? false) === true) {
                throw new LogicException('SCOPED_CERTIFICATE_HYPOTHESIS_CANNOT_BIND_VALIDATION');
            }
            $readiness = $this->verifyDataBinding($design, $sealedWindow, $manifest);
            $actual = (array) $readiness['actual_input_proof'];
            $key = $this->hash([self::PROTOCOL, 'original_data_binding', (int) $row->id]);
            $payload = [
                'protocol' => self::PROTOCOL, 'scope' => $row->scope, 'certificate_id' => $id,
                'source_hash' => $row->source_hash, 'design_hash' => $row->design_hash,
                'window' => $sealedWindow, 'manifest' => $manifest,
                'manifest_hash' => $this->hash($manifest), 'actual_input_proof' => $actual,
                'actual_input_hash' => $this->hash($actual),
                'authority' => false, 'executable' => false, 'promotion_evidence' => false,
            ];
            $existing = ScopedResearchCertificate::where('certificate_key', $key)->first();
            if ($existing) {
                $this->assertRecord($existing);
                if (! hash_equals($existing->payload_hash, $this->hash($payload))) {
                    throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_DATA_BINDING_IMMUTABLE');
                }

                return $this->inspect($id);
            }
            if ($this->alreadyObserved($source, (array) $row->payload['source_snapshot'])
                || ScopedResearchCertificate::where('parent_certificate_id', $id)
                    ->where('record_type', 'diagnostic_assessment')->exists()) {
                throw new LogicException('SCOPED_CERTIFICATE_DATA_BINDING_MUST_PRECEDE_OUTCOMES');
            }
            $this->append($key, $row->scope, 'original_data_binding', $source,
                $row->source_hash, $row->design_hash, $payload, $row->preregistered_at, $id);

            return $this->inspect($id);
        });
    }

    private function inspectDataBinding(ScopedResearchCertificate $row): ?array
    {
        $binding = ScopedResearchCertificate::where('parent_certificate_id', $row->id)
            ->where('record_type', 'original_data_binding')->first();
        if (! $binding) return null;
        $this->assertRecord($binding);
        $payload = $binding->payload;
        try {
            $readiness = $this->verifyDataBinding((array) $row->payload['design'],
                (array) $payload['window'], (array) $payload['manifest']);
            if (! hash_equals((string) $payload['actual_input_hash'], $this->hash((array) $readiness['actual_input_proof']))) {
                throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_DATA_BYTES_DRIFT');
            }

            return ['record_id' => (int) $binding->id, 'valid' => true,
                'window' => $payload['window'], 'manifest_hash' => $payload['manifest_hash'],
                'actual_input_hash' => $payload['actual_input_hash'], 'original_readiness' => $readiness,
                'authority' => false, 'executable' => false, 'promotion_evidence' => false];
        } catch (Throwable $error) {
            return ['record_id' => (int) $binding->id, 'valid' => false,
                'reason_code' => $error instanceof LogicException ? $error->getMessage()
                    : 'SCOPED_CERTIFICATE_ORIGINAL_DATA_PROOF_UNAVAILABLE',
                'authority' => false, 'executable' => false, 'promotion_evidence' => false];
        }
    }

    private function verifyDataBinding(array $design, array $window, array $manifest): array
    {
        $from = $this->utc($window['start_inclusive'] ?? null);
        $until = $this->utc($window['end_exclusive'] ?? null);
        $hash = $window['dataset_sha256'] ?? null;
        if (! $from || ! $until || ! is_string($hash) || ($manifest['bundle_hash'] ?? null) !== $hash
            || $from->lessThan(CarbonImmutable::parse($design['validation_start']))
            || $until->greaterThan(CarbonImmutable::parse($design['validation_end']))
            || (($design['data_manifest_hash'] ?? null) !== null && $design['data_manifest_hash'] !== $hash)) {
            throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_DATA_SCOPE_MISMATCH');
        }
        $readiness = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest, []);
        if (($readiness['complete_input_proof'] ?? false) !== true
            || ! is_array($readiness['actual_input_proof'] ?? null)
            || count((array) data_get($readiness, 'actual_input_proof.files', [])) !== 4) {
            throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_FOUR_STREAM_PROOF_REQUIRED');
        }

        return $readiness;
    }

    /** Valid, separately sealed questions for this exact persisted owner. */
    public function sourceRegistrations(Model $source): array
    {
        if (! $source->exists || ! Schema::hasTable('scoped_research_certificates')) return [];

        return ScopedResearchCertificate::where('source_type', $source::class)->where('source_id', $source->getKey())
            ->where('record_type', 'preregistration')->orderBy('id')->get()
            ->map(fn (ScopedResearchCertificate $row): array => $this->inspect((int) $row->id))
            ->filter(fn (array $receipt): bool => $receipt['valid'] === true)->values()->all();
    }

    public function hasProspectiveScope(Model $source): bool
    {
        return collect($this->sourceRegistrations($source))->contains(
            fn (array $receipt): bool => data_get($receipt, 'design.source_hypothesis_only') !== true,
        );
    }

    private function normalizeDesign(string $scope, array $design): array
    {
        if (isset($design['scope']) && $design['scope'] !== $scope) {
            throw new LogicException('SCOPED_CERTIFICATE_SCOPE_RELABEL_FORBIDDEN');
        }
        foreach (['authority', 'scope_authority_confirmed', 'confirmed_component', 'confirmed_selector',
            'confirmed_council', 'confirmed_inheritance', 'component_credit', 'selector_credit',
            'inheritance_credit', 'parent_eligible', 'paper_authority', 'economic_authority',
            'independent_market_evidence', 'promotion_evidence'] as $flag) {
            if (isset($design[$flag]) && $design[$flag] !== false) {
                throw new LogicException('SCOPED_CERTIFICATE_CALLER_AUTHORITY_FORBIDDEN');
            }
        }
        foreach (['evaluator_hash', 'context_hash', 'execution_hash', 'owner_source_hash'] as $field) {
            if (! is_string($design[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $design[$field])) {
                throw new LogicException('SCOPED_CERTIFICATE_DESIGN_IDENTITIES_REQUIRED');
            }
        }
        if (isset($design['data_manifest_hash'])
            && (! is_string($design['data_manifest_hash']) || ! preg_match('/^[a-f0-9]{64}$/', $design['data_manifest_hash']))) {
            throw new LogicException('SCOPED_CERTIFICATE_ACTUAL_DATA_HASH_INVALID');
        }
        foreach (['metric', 'stopping_rule'] as $field) {
            if ((! is_string($design[$field] ?? null) && ! is_array($design[$field] ?? null))
                || empty($design[$field])) {
                throw new LogicException('SCOPED_CERTIFICATE_METRIC_AND_STOPPING_RULE_REQUIRED');
            }
        }
        if (! is_array($design['subject'] ?? null) || $design['subject'] === []) {
            throw new LogicException('SCOPED_CERTIFICATE_SUBJECT_MANIFEST_REQUIRED');
        }
        foreach (['outcomes', 'assessment', 'validation_outcomes'] as $field) {
            if (array_key_exists($field, $design)) {
                throw new LogicException('SCOPED_CERTIFICATE_RETROSPECTIVE_DESIGN_FORBIDDEN');
            }
        }
        $start = $this->utc($design['validation_start'] ?? null);
        $end = $this->utc($design['validation_end'] ?? null);
        if (! $start || ! $end || ! $end->greaterThan($start)
            || $start->lessThan(CarbonImmutable::parse('2027-01-01T00:00:00Z'))) {
            throw new LogicException('SCOPED_CERTIFICATE_POST_PAPER_FUTURE_INTERVAL_REQUIRED');
        }
        if (isset($design['authority_policy'])) {
            if ($design['authority_policy'] !== self::AUTHORITY_POLICY || ($design['source_hypothesis_only'] ?? false) === true) {
                throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_AUTHORITY_POLICY_REQUIRED');
            }
            if (in_array($scope, ['component', 'inheritance'], true)
                && (! is_string($design['metric']) || ! in_array($design['metric'],
                    ['profit_factor', 'architecture', 'net_profit', 'total_return_percent', 'drawdown', 'max_drawdown'], true))) {
                throw new LogicException('SCOPED_CERTIFICATE_EXPLICIT_SUPPORTED_CONTEXT_UTILITY_REQUIRED');
            }
            // External account limits are not evolution knobs. Seal the
            // applicable ceiling before the first event; callers can narrow it.
            $external = ['max_drawdown_percent' => min(15.0, (float) config('services.dual_track.max_drawdown_percent', 15)),
                'max_risk_of_ruin_percent' => min(10.0, (float) config('services.dual_track.max_risk_of_ruin_percent', 10))];
            $risk = $design['risk_guard'] ?? $external;
            if (! is_array($risk) || array_diff(array_keys($risk), array_keys($external)) !== []) {
                throw new LogicException('SCOPED_CERTIFICATE_EXTERNAL_RISK_GUARD_REQUIRED');
            }
            foreach ($external as $metric => $ceiling) {
                if (! is_numeric($risk[$metric] ?? null) || ! is_finite((float) $risk[$metric])
                    || $risk[$metric] <= 0 || $risk[$metric] > $ceiling) {
                    throw new LogicException('SCOPED_CERTIFICATE_EXTERNAL_RISK_LIMIT_MAY_NOT_BE_LOOSENED');
                }
            }
            $design['risk_guard'] = $risk;
            $policy = (array) ($design['exposure_policy'] ?? []);
            if (($policy['protocol'] ?? null) !== 'prospective_scoped_exposure_policy_v1'
                || ! is_int($policy['holding_fence_seconds'] ?? null)
                || $policy['holding_fence_seconds'] < 0 || $policy['holding_fence_seconds'] > 31536000
                || ($policy['execution_timeframe'] ?? null) !== 'M5'
                || ($policy['context_timeframes'] ?? null) !== ['H4', 'H1', 'M15']
                || ($policy['warmup_policy'] ?? null) !== 'all_original_closed_source_rows_inside_registered_window'
                || ($policy['selection_policy'] ?? null) !== 'frozen_before_first_event') {
                throw new LogicException('SCOPED_CERTIFICATE_COMPLETE_EXPOSURE_POLICY_REQUIRED');
            }
            $roster = $design['validation_windows'] ?? null;
            if (! is_array($roster) || ! array_is_list($roster) || count($roster) < 1 || count($roster) > 12) {
                throw new LogicException('SCOPED_CERTIFICATE_PROSPECTIVE_WINDOW_ROSTER_REQUIRED');
            }
            $previousEnd = null;
            foreach ($roster as $period) {
                $from = $this->utc($period['start_inclusive'] ?? null);
                $until = $this->utc($period['end_exclusive'] ?? null);
                if (! $from || ! $until || ! $until->greaterThan($from) || $from->lessThan($start)
                    || $until->greaterThan($end) || ($previousEnd && $from->lessThan($previousEnd))
                    || ! $this->epochs->researchIntervalDisjointFromPaper($from->toIso8601String(), $until->toIso8601String())) {
                    throw new LogicException('SCOPED_CERTIFICATE_WINDOW_ROSTER_OVERLAP_OR_SCOPE_INVALID');
                }
                $previousEnd = $until;
            }
        }

        return [...$design, 'validation_start' => $start->toIso8601String(),
            'validation_end' => $end->toIso8601String(), 'data_manifest_hash' => $design['data_manifest_hash'] ?? null];
    }

    private function assertSourceScope(string $scope, Model $source): void
    {
        if (! in_array($scope, self::SCOPES, true) || ! $source->exists || (int) $source->getKey() <= 0) {
            throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_SOURCE_REQUIRED');
        }
        $allowed = match ($scope) {
            'component', 'selector' => $source::class === AgentLearningCausalExperiment::class,
            'inheritance' => $source::class === DescendantValueTrial::class,
            'council' => $source::class === SpecialistCouncilVersion::class,
            default => false,
        };
        if (! $allowed) throw new LogicException('SCOPED_CERTIFICATE_NAMED_SCOPE_OWNER_REQUIRED');
    }

    private function sourceSnapshot(Model $source, array $design): array
    {
        if ($source instanceof SpecialistCouncilVersion) return $this->councilSnapshot($source, $design);
        $fields = $source instanceof DescendantValueTrial
            ? ['trial_key', 'mentor_model_version_id', 'child_model_version_id', 'symbol', 'timeframe', 'strategy_family', 'window_key']
            : ['experiment_key', 'lab_generation_id', 'symbol', 'timeframe', 'strategy_family', 'target', 'gene_key', 'source_lesson_id', 'guided_agent_id', 'blinded_agent_id', 'control_agent_id'];
        $snapshot = ['source_type' => $source::class, 'source_id' => (int) $source->getKey()];
        foreach ($fields as $field) $snapshot[$field] = $source->getAttribute($field);
        $evidence = (array) $source->evidence;
        if ($source instanceof DescendantValueTrial) {
            $snapshot['original_design'] = $evidence['scoped_proof'] ?? null;
            $ids = [$source->mentor_model_version_id, $source->child_model_version_id];
        } else {
            $snapshot['original_design'] = array_intersect_key($evidence, array_flip([
                'experiment_kind', 'roles', 'prospective_validation_plan', 'prospective_source_hash',
                'prospective_source_data_hash', 'prospective_parent_semantic_group', 'prospective_source_runs',
                'prospective_probe_policy', 'source_causal_experiment_id', 'source_pair_id',
                'source_control_agent_id', 'baseline_model_version_id', 'baseline_old_value',
                'source_context_scope', 'source_context_hash', 'construction_protocol',
                'activation_screen', 'blinded_selector', 'scoped_proof',
            ]));
            $agents = LabAgent::whereIn('id', array_filter([
                $source->guided_agent_id, $source->blinded_agent_id, $source->control_agent_id,
            ]))->orderBy('id')->get();
            if ($agents->count() !== 3 || $agents->contains(
                fn (LabAgent $agent): bool => (int) $agent->lab_generation_id !== (int) $source->lab_generation_id,
            )) throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_THREE_ARM_ROSTER_REQUIRED');
            $snapshot['agents'] = $agents->map(fn (LabAgent $agent): array => [
                'agent_id' => (int) $agent->id, 'model_version_id' => (int) $agent->model_version_id,
                'lab_generation_id' => (int) $agent->lab_generation_id,
                'parameter_diff' => (array) $agent->parameter_diff,
            ])->all();
            $ids = $agents->pluck('model_version_id')->all();
        }
        $questions = [];
        foreach ((array) data_get($design, 'subject.experiment_ids', []) as $questionId) {
            if (! is_int($questionId) || $questionId <= 0 || isset($questions[$questionId])) {
                throw new LogicException('SCOPED_SELECTOR_ORIGINAL_QUESTION_ROSTER_REQUIRED');
            }
            $question = AgentLearningCausalExperiment::findOrFail($questionId);
            if ((int) $question->id === (int) $source->id) {
                $questions[$questionId] = $snapshot;
            } else {
                $questions[$questionId] = $this->sourceSnapshot($question, array_diff_key($design, ['subject' => true]));
            }
            $ids = [...$ids, ...array_keys((array) ($questions[$questionId]['models'] ?? []))];
            foreach (['guided_agent_id', 'blinded_agent_id', 'control_agent_id'] as $roleField) {
                $modelId = LabAgent::find($question->{$roleField})?->model_version_id;
                if ($modelId) $ids[] = (int) $modelId;
            }
        }
        return [...$snapshot, 'questions' => $questions, 'models' => $this->modelSnapshots($ids, $design)];
    }

    private function modelSnapshots(array $ids, array $design): array
    {
        foreach ((array) data_get($design, 'subject.arm_models', []) as $arm) {
            if (! is_array($arm) || ! is_int($arm['model_version_id'] ?? null)) {
                throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_MODEL_MANIFEST_INVALID');
            }
            $ids[] = $arm['model_version_id'];
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        sort($ids);
        $models = [];
        foreach ($ids as $id) {
            $model = ModelVersion::find($id);
            if (! $model) throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_MODEL_REQUIRED');
            $models[(string) $id] = [
                'model_version_id' => $id, 'strategy' => $model->strategy,
                'version' => $model->version, 'parameter_hash' => $this->hash((array) $model->parameters),
                'runtime_hash' => $this->hash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model)),
            ];
        }
        foreach ((array) data_get($design, 'subject.arm_models', []) as $arm) {
            $actual = $models[(string) $arm['model_version_id']]['parameter_hash'];
            if (! is_string($arm['parameter_hash'] ?? null) || ! hash_equals($actual, $arm['parameter_hash'])) {
                throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_MODEL_PARAMETER_DRIFT');
            }
        }

        return $models;
    }

    private function councilSnapshot(SpecialistCouncilVersion $source, array $design): array
    {
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $source->id)->first();
        $plan = $row ? json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! $row || ! is_array($plan) || ! hash_equals($row->plan_hash, $this->hash($plan))
            || ! app(SpecialistCouncilContractService::class)->manifestValid((array) $source->manifest)
            || ($plan['manifest_hash'] ?? null) !== $source->manifest_hash
            || ! hash_equals((string) data_get($design, 'subject.manifest_hash', ''), $source->manifest_hash)
            || ! hash_equals((string) data_get($design, 'subject.plan_hash', ''), $row->plan_hash)
            || (string) $row->evaluator_id === (string) $source->creator_id) {
            throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_COUNCIL_PLAN_REQUIRED');
        }

        return [
            'source_type' => $source::class, 'source_id' => (int) $source->id,
            'council_id' => $source->council_id, 'version' => $source->version,
            'creator_id' => $source->creator_id, 'manifest' => (array) $source->manifest,
            'manifest_hash' => $source->manifest_hash, 'plan_id' => (int) $row->id,
            'evaluator_id' => $row->evaluator_id, 'plan' => $plan, 'plan_hash' => $row->plan_hash,
            'models' => $this->modelSnapshots([
                ...array_column((array) ($plan['arms'] ?? []), 'model_version_id'),
                ...array_column((array) data_get($source->manifest, 'members', []), 'model_version_id'),
            ], $design),
        ];
    }

    private function alreadyObserved(Model $source, array $snapshot): bool
    {
        if ($source instanceof SpecialistCouncilVersion) {
            if ($source->assessment_hash !== null || $source->assessment !== null) return true;
        } elseif (data_get($source->evidence, 'scoped_assessment') !== null
            || (array) data_get($source->evidence, 'outcomes', []) !== []
            || ($source instanceof DescendantValueTrial && $source->settled_at !== null)) return true;

        return LabEvaluationRun::whereIn('model_version_id', array_keys($snapshot['models']))->exists();
    }

    private function verifiedSource(ScopedResearchCertificate $row): Model
    {
        $this->assertRecord($row);
        $type = $row->source_type;
        if (! in_array($type, [AgentLearningCausalExperiment::class, DescendantValueTrial::class, SpecialistCouncilVersion::class], true)) {
            throw new LogicException('SCOPED_CERTIFICATE_NAMED_SCOPE_OWNER_REQUIRED');
        }
        $source = $type::find($row->source_id);
        if (! $source) throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_SOURCE_REQUIRED');
        $this->assertSourceScope($row->scope, $source);
        $design = $this->normalizeDesign($row->scope, (array) ($row->payload['design'] ?? []));
        if (! hash_equals($row->design_hash, $this->hash($design))
            || ! hash_equals($row->source_hash, $this->hash($this->sourceSnapshot($source, $design)))
            || ! $row->preregistered_at->lessThan(CarbonImmutable::parse($design['validation_start']))) {
            throw new LogicException('SCOPED_CERTIFICATE_ORIGINAL_SOURCE_OR_DESIGN_DRIFT');
        }

        return $source;
    }

    private function append(string $key, string $scope, string $type, Model $source, string $sourceHash,
        string $designHash, array $payload, CarbonImmutable $preregistered, ?int $parent = null): ScopedResearchCertificate
    {
        $attributes = [
            'certificate_key' => $key, 'protocol' => self::PROTOCOL, 'scope' => $scope,
            'record_type' => $type, 'parent_certificate_id' => $parent,
            'source_type' => $source::class, 'source_id' => (int) $source->getKey(),
            'source_hash' => $sourceHash, 'design_hash' => $designHash,
            'payload_hash' => $this->hash($payload), 'payload' => $payload,
            'preregistered_at' => $preregistered->toIso8601String(),
            'recorded_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
        $attributes['server_seal'] = $this->seal($attributes);

        return ScopedResearchCertificate::create($attributes);
    }

    private function assertRecord(ScopedResearchCertificate $row): void
    {
        $body = $row->only([
            'certificate_key', 'protocol', 'scope', 'record_type', 'parent_certificate_id',
            'source_type', 'source_id', 'source_hash', 'design_hash', 'payload_hash', 'payload',
        ]);
        $body['preregistered_at'] = $row->preregistered_at->toIso8601String();
        $body['recorded_at'] = $row->recorded_at->toIso8601String();
        if ($row->protocol !== self::PROTOCOL || ! hash_equals($row->payload_hash, $this->hash((array) $row->payload))
            || ! hash_equals($row->server_seal, $this->seal($body))) {
            throw new LogicException('SCOPED_CERTIFICATE_SEAL_OR_PAYLOAD_DRIFT');
        }
    }

    private function diagnostic(array $assessment): array
    {
        foreach (['scope', 'certificate_id', 'design_hash', 'source_hash'] as $field) unset($assessment[$field]);
        $assessment = $this->removeAuthorityFlags($assessment);

        return [...$assessment, 'assessment_kind' => 'diagnostic_only', 'authority' => false,
            'scope_authority_confirmed' => false, 'confirmed_component' => false,
            'confirmed_selector' => false, 'confirmed_council' => false, 'confirmed_inheritance' => false,
            'component_credit' => false, 'selector_credit' => false, 'inheritance_credit' => false,
            'parent_eligible' => false, 'paper_authority' => false, 'economic_authority' => false,
            'independent_market_evidence' => false, 'promotion_evidence' => false];
    }

    private function removeAuthorityFlags(array $values): array
    {
        $flags = ['authority', 'scope_authority_confirmed', 'confirmed_component', 'confirmed_selector',
            'confirmed_council', 'confirmed_inheritance', 'component_credit', 'selector_credit',
            'inheritance_credit', 'parent_eligible', 'paper_authority', 'economic_authority',
            'independent_market_evidence', 'promotion_evidence'];
        foreach ($values as $key => $value) {
            if (in_array($key, $flags, true)) $values[$key] = false;
            elseif (is_array($value)) $values[$key] = $this->removeAuthorityFlags($value);
        }

        return $values;
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'valid' => false,
            'blocked_reason' => $reason, 'reason_code' => $reason, 'authority' => false,
            'executable' => false, 'scope_authority_confirmed' => false,
            'confirmed_component' => false, 'confirmed_selector' => false,
            'confirmed_council' => false, 'confirmed_inheritance' => false,
            'component_credit' => false, 'selector_credit' => false, 'inheritance_credit' => false,
            'parent_eligible' => false, 'paper_authority' => false, 'economic_authority' => false,
            'independent_market_evidence' => false, 'promotion_evidence' => false];
    }

    private function hash(array $value): string
    {
        json_encode($value, JSON_THROW_ON_ERROR);

        return $this->epochs->parameterHash($value);
    }

    private function seal(array $value): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: '';
        if (strlen($key) < 32) throw new LogicException('SCOPED_CERTIFICATE_SERVER_KEY_UNAVAILABLE');

        return hash_hmac('sha256', self::PROTOCOL."\n".$this->hash($value), $key);
    }

    private function utc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) return null;
        try { return CarbonImmutable::parse($value)->utc(); } catch (Throwable) { return null; }
    }
}
