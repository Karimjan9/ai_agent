<?php

namespace App\Services;

use App\Models\ModelVersion;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Freezes an E3 candidate before the 2026 stream; paper results cannot rewrite its passport. */
class PaperAuthorityAdmissionService
{
    public const PROTOCOL = 'paper_authority_admission_v1';
    public const IDENTITY_PROTOCOL = 'frozen_paper_candidate_identity_v1';
    public const TRAIT_PROVENANCE_PROTOCOL = 'archive_baseline_confirmed_single_trait_paper_v1';

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    /** @return array<string,mixed> */
    public function admit(ModelVersion $model, string $symbol, string $timeframe, array $passport): array
    {
        if (! Schema::hasTable('paper_authority_admissions')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $model = $model->fresh() ?? $model;
        $prior = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->whereIn('status', ['e3_paper_candidate', 'e4_evidence_ready'])->latest('id')->first();
        if ($prior) {
            $verification = $this->verifyFrozenCandidate($model, $symbol, $timeframe, $passport, (int) $prior->id);
            return ['protocol' => self::PROTOCOL, 'status' => $verification['allowed'] ? $prior->status : 'withheld',
                'reason_code' => $verification['reason_code'], 'frozen_at' => $prior->frozen_at,
                'admission_id' => $prior->id, 'identity_hash' => $verification['identity_hash'] ?? null,
                'idempotent' => true, 'promotion_evidence' => false];
        }
        $authority = app(EvolutionaryAuthorityFoundryService::class)->authorityFor($model);
        $pre2026 = (bool) ($passport['training_pre_2026'] ?? false);
        $windowKey = ! array_key_exists('paper_window_key', $passport) ? ResearchPaperEpochContractService::PAPER_WINDOW_KEY
            : (is_string($passport['paper_window_key']) ? $passport['paper_window_key'] : '');
        $frozenAt = now()->utc()->toIso8601String();
        $epochContract = $this->epochs->paperContractForCandidate($windowKey, $frozenAt);
        // A future trait-only fork has a narrow server-derived path; a new
        // caller label cannot make arbitrary post-paper training admissible.
        $futureEpoch = $windowKey !== ResearchPaperEpochContractService::PAPER_WINDOW_KEY;
        $traitProof = $futureEpoch && $epochContract !== null
            ? (filled(data_get($model->metadata, 'instrument_learning_consumption'))
                ? $this->postPaperTraitProvenance($model, $symbol, $frozenAt, $epochContract)
                : $this->archivePaperProvenance($model, $symbol, $frozenAt, $epochContract)) : null;
        $trainingProven = $futureEpoch ? ($traitProof['allowed'] ?? false) === true : $pre2026;
        $hashes = ['confirmation_entry_hash', 'risk_governor_hash', 'trade_management_hash', 'execution_hash'];
        $complete = collect($hashes)->every(fn (string $key): bool => filled($passport[$key] ?? null));
        // Paper is the missing prospective rung, so requiring an Economic
        // Parent here creates a circle: parent requires paper while paper
        // requires parent. Admit only a fully incubated pre-paper economic
        // candidate; the paper outcome itself remains unable to mutate it.
        $economicChecks = (array) data_get($authority, 'evidence.economic_parent_authority.checks', []);
        $prePaperChecks = collect($economicChecks)->except([
            'forward_or_paper_evidence',
            'performance_credit_earned',
        ]);
        $prePaperEconomicCandidate = $prePaperChecks->isNotEmpty()
            && $prePaperChecks->every(fn (mixed $passed): bool => $passed === true)
            && data_get($authority, 'evidence.incubation_passed') === true
            && data_get($authority, 'evidence.passport.passed') === true;
        $status = $prePaperEconomicCandidate && $trainingProven && $complete && $epochContract !== null
            ? 'e3_paper_candidate'
            : 'withheld';
        // The old 2026 identity is unchanged. A new paper period is an explicit
        // prospective seal, not authority to relabel a candidate's old results.
        $keyParts = [self::PROTOCOL, $model->id, strtoupper($symbol), strtoupper($timeframe), (string) ($passport['passport_hash'] ?? '')];
        if ($windowKey !== ResearchPaperEpochContractService::PAPER_WINDOW_KEY) $keyParts[] = $windowKey;
        $key = hash('sha256', implode('|', $keyParts));
        $existing = DB::table('paper_authority_admissions')->where('admission_key', $key)->first();
        $frozenParameters = $this->epochs->parameterHash((array) $model->parameters);
        $evidence = [
            'protocol' => self::PROTOCOL,
            'authority' => $authority,
            'pre_paper_economic_candidate' => $prePaperEconomicCandidate,
            'pre_paper_checks' => $prePaperChecks->all(),
            'training_pre_2026' => $pre2026,
            ...($traitProof !== null ? ['post_paper_trait_provenance' => $traitProof] : []),
            'parameter_hash' => $frozenParameters,
            'frozen_candidate_identity' => $this->candidateIdentity($model, $passport),
            'epoch_contract' => $epochContract,
            'paper_window_key' => $windowKey,
            'dependency_status' => $epochContract !== null && $trainingProven ? null : 'BLOCKED_DEPENDENCY',
            'dependency_reason_code' => $epochContract === null ? 'PAPER_EPOCH_UNKNOWN_UNAUTHORIZED_OR_NOT_PROSPECTIVE'
                : (! $trainingProven ? ($traitProof['reason_code'] ?? 'RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED') : null),
            'parameter_changes_forbidden_in_block' => true,
            'paper_is_prospective_only' => true,
            'hashes' => array_intersect_key($passport, array_flip([...$hashes, 'passport_hash'])),
            'promotion_evidence' => false,
        ];
        DB::table('paper_authority_admissions')->updateOrInsert(['admission_key' => $key], [
            'model_version_id' => $model->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'status' => $status,
            'passport_hash' => $passport['passport_hash'] ?? null, 'execution_hash' => $passport['execution_hash'] ?? null,
            'evidence' => json_encode($evidence),
            'frozen_at' => $status === 'e3_paper_candidate' ? now() : null,
            'updated_at' => now(),
            'created_at' => $existing?->created_at ?? now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $status,
            'reason_code' => $status === 'withheld'
                ? ($evidence['dependency_reason_code'] ?? 'PRE_PAPER_ECONOMIC_CANDIDATE_INCOMPLETE') : null,
            'dependency_status' => $evidence['dependency_status'],
            'promotion_evidence' => false];
    }

    /** A persisted admission is not authority for a changed or merely cached model. */
    public function verifyFrozenCandidate(ModelVersion $model, string $symbol, string $timeframe, array $passport = [], ?int $admissionId = null): array
    {
        $blocked = fn (string $reason): array => ['allowed' => false, 'reason_code' => $reason, 'promotion_evidence' => false];
        if (! Schema::hasTable('paper_authority_admissions')) return $blocked('E3_ADMISSION_MISSING');
        $query = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->whereIn('status', ['e3_paper_candidate', 'e4_evidence_ready']);
        if ($admissionId !== null) $query->where('id', $admissionId);
        $row = $query->latest('id')->first();
        if (! $row || ! $row->frozen_at) return $blocked('E3_ADMISSION_MISSING');
        $evidence = (array) json_decode((string) $row->evidence, true);
        $identity = data_get($evidence, 'frozen_candidate_identity', []);
        if (data_get($identity, 'protocol') !== self::IDENTITY_PROTOCOL) return $blocked('PAPER_FROZEN_IDENTITY_MISSING');
        $epoch = (array) data_get($evidence, 'epoch_contract', []);
        if (data_get($epoch, 'paper_epoch.window_key', ResearchPaperEpochContractService::PAPER_WINDOW_KEY)
                !== ResearchPaperEpochContractService::PAPER_WINDOW_KEY
            && ! $this->epochs->paperContractAuthorized($epoch, $this->isoFreeze($row->frozen_at))) {
            return $blocked('PAPER_EPOCH_AUTHORIZATION_OR_FREEZE_DRIFT');
        }
        $current = $model->fresh();
        if (! $current) return $blocked('PAPER_MODEL_MISSING');
        if (data_get($evidence, 'post_paper_trait_provenance.allowed') === true) {
            $proof = data_get($evidence, 'post_paper_trait_provenance.provenance_kind') === 'unchanged_archive_baseline'
                ? $this->archivePaperProvenance($current, $symbol, $this->isoFreeze($row->frozen_at), $epoch)
                : $this->postPaperTraitProvenance($current, $symbol, $this->isoFreeze($row->frozen_at), $epoch);
            if (($proof['allowed'] ?? false) !== true || ! hash_equals(
                (string) data_get($evidence, 'post_paper_trait_provenance.provenance_hash', ''), (string) ($proof['provenance_hash'] ?? ''))) {
                return $blocked('PAPER_CONFIRMED_TRAIT_PROVENANCE_DRIFT');
            }
        }
        // Reconstruct with the original passport when no new transport is supplied.
        $actual = $this->candidateIdentity($current, $passport === [] ? (array) data_get($identity, 'passport') : $passport);
        if (! hash_equals((string) data_get($identity, 'identity_hash', ''), $actual['identity_hash'])) {
            return $blocked('PAPER_FROZEN_CANDIDATE_DRIFT');
        }
        return ['allowed' => true, 'reason_code' => null, 'admission_id' => (int) $row->id,
            'identity_hash' => $actual['identity_hash'], 'promotion_evidence' => false];
    }

    private function candidateIdentity(ModelVersion $model, array $passport): array
    {
        $identity = ['protocol' => self::IDENTITY_PROTOCOL, 'model_version_id' => $model->id,
            'parameter_hash' => $this->epochs->parameterHash((array) $model->parameters),
            'runtime' => ['strategy' => $model->strategy, 'version' => $model->version,
                'components' => collect(['architecture', 'base_strategy', 'tactic', 'composition_passport',
                    'composition_runtime_contract', 'confirmation_entry', 'risk_governor', 'trade_management',
                    'elite_agent_passport', 'execution_contract', 'runtime_ensemble'])
                    ->mapWithKeys(fn (string $key): array => [$key => data_get($model->metadata, $key)])->all()],
            'passport' => array_intersect_key($passport, array_flip(['passport_hash', 'execution_hash',
                'confirmation_entry_hash', 'risk_governor_hash', 'trade_management_hash', 'training_pre_2026', 'paper_window_key'])),
        ];
        $identity['identity_hash'] = $this->epochs->parameterHash($identity);
        return $identity;
    }

    /** Paper evidence is E4-eligible only when it is chronologically after the frozen E3 passport. */
    public function recordProspectiveOutcome(ModelVersion $model, string $symbol, string $timeframe, array $outcome): array
    {
        if (! Schema::hasTable('paper_authority_admissions')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $row = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'e3_paper_candidate')->latest('id')->first();
        if (! $row || ! $row->frozen_at) return ['protocol' => self::PROTOCOL, 'status' => 'withheld', 'reason_code' => 'E3_ADMISSION_MISSING', 'promotion_evidence' => false];
        $prospective = (bool) ($outcome['prospective_after_freeze'] ?? false);
        $parameterUnchanged = (bool) ($outcome['parameter_hash_matches_passport'] ?? false)
            && $this->verifyFrozenCandidate($model, $symbol, $timeframe, [], (int) $row->id)['allowed'];
        $disciplinePassed = (bool) ($outcome['discipline_audit_passed'] ?? false);
        $evidence = (array) json_decode((string) $row->evidence, true);
        $sealedEpoch = (array) data_get($evidence, 'epoch_contract', []);
        $sealedKey = (string) data_get($sealedEpoch, 'paper_epoch.window_key', ResearchPaperEpochContractService::PAPER_WINDOW_KEY);
        $future = $sealedKey !== ResearchPaperEpochContractService::PAPER_WINDOW_KEY;
        $epochValid = data_get($outcome, 'epoch_contract.protocol') === ResearchPaperEpochContractService::PROTOCOL
            && data_get($outcome, 'paper_window_key') === $sealedKey
            && (! $future || hash_equals($this->epochs->parameterHash($sealedEpoch),
                $this->epochs->parameterHash((array) data_get($outcome, 'epoch_contract', []))))
            && $this->epochs->paperWindowValid(
                (array) data_get($outcome, 'paper_observation_times', []),
                (string) data_get($outcome, 'paper_window_key', ''),
                $future ? $sealedEpoch : null,
                $future ? $this->isoFreeze($row->frozen_at) : null,
            )
            && data_get($outcome, 'paper_used_for_screening') === false
            && data_get($outcome, 'paper_used_for_mutation') === false
            && data_get($outcome, 'paper_used_for_selection') === false
            && data_get($outcome, 'paper_used_for_posterior_update') === false;
        $passed = $prospective && $parameterUnchanged && $disciplinePassed && $epochValid
            && (bool) ($outcome['paper_gate_passed'] ?? false);
        DB::table('paper_authority_admissions')->where('id', $row->id)->update([
            'status' => $passed ? 'e4_evidence_ready' : 'e3_paper_candidate',
            'evidence' => json_encode([...((array) json_decode($row->evidence, true)), 'latest_paper_outcome' => $outcome,
                'e4_conditions' => compact('prospective', 'parameterUnchanged', 'disciplinePassed', 'epochValid'), 'promotion_evidence' => false]),
            'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $passed ? 'e4_evidence_ready' : 'e3_paper_candidate', 'promotion_evidence' => false];
    }

    /** @param array<int,\App\Models\PaperOrder> $orders */
    public function prospectiveOutcomeContract(ModelVersion $model, string $symbol, string $timeframe, iterable $orders): array
    {
        $row = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'e3_paper_candidate')->latest('id')->first();
        if (! $row || ! $row->frozen_at) {
            return ['prospective_after_freeze' => false, 'parameter_hash_matches_passport' => false,
                'discipline_audit_passed' => false, 'epoch_contract' => $this->epochs->contract(),
                'promotion_evidence' => false];
        }
        $orders = collect($orders);
        $evidence = (array) json_decode((string) $row->evidence, true);
        $observationTimes = $orders->map(fn ($order): ?string => $order->opened_at?->copy()->utc()->toIso8601String())
            ->filter()->values()->all();
        if (data_get($evidence, 'epoch_contract.paper_epoch.window_key', ResearchPaperEpochContractService::PAPER_WINDOW_KEY)
            !== ResearchPaperEpochContractService::PAPER_WINDOW_KEY) {
            $observationTimes = $orders->flatMap(fn ($order): array => array_filter([
                $order->opened_at?->copy()->utc()->toIso8601String(),
                $order->closed_at?->copy()->utc()->toIso8601String(),
            ]))->values()->all();
        }
        $prospective = $orders->isNotEmpty() && $orders->every(
            fn ($order): bool => $order->created_at !== null && $order->created_at->greaterThanOrEqualTo($row->frozen_at),
        );
        $parameterUnchanged = filled(data_get($evidence, 'parameter_hash'))
            && hash_equals(
                (string) data_get($evidence, 'parameter_hash'),
                $this->epochs->parameterHash((array) ($model->fresh()?->parameters ?? [])),
            ) && $this->verifyFrozenCandidate($model, $symbol, $timeframe, [], (int) $row->id)['allowed'];
        $disciplinePassed = $orders->isNotEmpty() && $orders->every(
            fn ($order): bool => data_get($order->signal_context, 'smart_discipline.approved') === true,
        );

        return [
            'paper_window_key' => data_get($evidence, 'epoch_contract.paper_epoch.window_key', ResearchPaperEpochContractService::PAPER_WINDOW_KEY),
            'paper_observation_times' => $observationTimes,
            'prospective_after_freeze' => $prospective,
            'parameter_hash_matches_passport' => $parameterUnchanged,
            'discipline_audit_passed' => $disciplinePassed,
            'paper_used_for_screening' => false,
            'paper_used_for_mutation' => false,
            'paper_used_for_selection' => false,
            'paper_used_for_posterior_update' => false,
            'epoch_contract' => data_get($evidence, 'epoch_contract', $this->epochs->contract()),
            'promotion_evidence' => false,
        ];
    }

    public function championEligible(ModelVersion $model, string $symbol, string $timeframe): bool
    {
        if (! Schema::hasTable('paper_authority_admissions')) return false;
        return $this->verifyFrozenCandidate($model, $symbol, $timeframe)['allowed']
            && DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'e4_evidence_ready')->exists();
    }

    /** Identity may remain valid before/after a period, but new paper orders may not. */
    public function observationReadiness(ModelVersion $model, string $symbol, string $timeframe, ?int $admissionId = null): array
    {
        $blocked = fn (string $reason): array => ['allowed' => false, 'reason_code' => $reason, 'promotion_evidence' => false];
        if (! Schema::hasTable('paper_authority_admissions')) return $blocked('E3_ADMISSION_MISSING');
        $query = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->whereIn('status', ['e3_paper_candidate', 'e4_evidence_ready']);
        if ($admissionId !== null) $query->where('id', $admissionId);
        $row = $query->latest('id')->first();
        if (! $row || ! $row->frozen_at) return $blocked('E3_ADMISSION_MISSING');
        $epoch = (array) data_get(json_decode((string) $row->evidence, true), 'epoch_contract', []);
        if (data_get($epoch, 'paper_epoch.window_key', ResearchPaperEpochContractService::PAPER_WINDOW_KEY)
            === ResearchPaperEpochContractService::PAPER_WINDOW_KEY) {
            return ['allowed' => true, 'reason_code' => null, 'promotion_evidence' => false];
        }
        if (! $this->epochs->paperContractAuthorized($epoch, $this->isoFreeze($row->frozen_at))) {
            return $blocked('PAPER_EPOCH_AUTHORIZATION_OR_FREEZE_DRIFT');
        }
        if (! $this->epochs->paperWindowValid([now()->utc()->toIso8601String()],
            (string) data_get($epoch, 'paper_epoch.window_key'), $epoch, $this->isoFreeze($row->frozen_at))) {
            return $blocked('PAPER_EPOCH_NOT_OPEN_FOR_OBSERVATION');
        }
        return ['allowed' => true, 'reason_code' => null, 'promotion_evidence' => false];
    }

    private function isoFreeze(mixed $value): string
    {
        try { return \Carbon\CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601String(); }
        catch (\Throwable) { return ''; }
    }

    /**
     * This deliberately does not support arbitrary future-trained/tuned models.
     * Only an unchanged attested archive baseline plus one independently
     * confirmed parameter intervention can enter a later, untouched paper epoch.
     * Every call re-resolves original sources; this method performs no writes.
     */
    public function postPaperTraitProvenance(ModelVersion $model, string $symbol, string $frozenAt, array $epoch): array
    {
        $blocked = static fn (string $reason): array => ['protocol' => self::TRAIT_PROVENANCE_PROTOCOL,
            'allowed' => false, 'reason_code' => $reason, 'promotion_evidence' => false];
        try {
            $current = $model->fresh();
            $freeze = $this->strictTime($frozenAt);
            $paperStart = $this->strictTime(data_get($epoch, 'paper_epoch.start_inclusive'));
            $paperEnd = $this->strictTime(data_get($epoch, 'paper_epoch.end_exclusive'));
            if (! $current || $freeze === null || $paperStart === null || $paperEnd === null
                || data_get($epoch, 'paper_epoch.window_key') === ResearchPaperEpochContractService::PAPER_WINDOW_KEY
                || ! $this->epochs->paperContractAuthorized($epoch, $frozenAt)) return $blocked('RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED');
            $receipt = (array) data_get($current->metadata, 'instrument_learning_consumption', []);
            $policy = (array) data_get($current->metadata, 'instrument_learning_policy', []);
            $deltas = (array) ($receipt['tested_interventions'] ?? []);
            if (($receipt['protocol'] ?? null) !== 'instrument_successor_consumption_v2'
                || count($deltas) !== 1 || count((array) ($receipt['applied_genes'] ?? [])) !== 1
                || count((array) ($receipt['isolated_sources'] ?? [])) !== 1) return $blocked('RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED');
            $delta = $deltas[0];
            $currentSource = app(LabImmutableEvidenceService::class)->codeHash();
            if (($delta['evaluator_hash'] ?? null) !== $currentSource) return $blocked('RELEASE_TRAIT_REVALIDATION_REQUIRED');
            $gene = (string) ($delta['gene'] ?? '');
            $parent = ModelVersion::find((int) data_get($current->metadata, 'mutation_constructor_invariant.parent_model_version_id', 0));
            $family = (string) ($policy['strategy_family'] ?? '');
            if (! $parent || $parent->id === $current->id || $family === ''
                || $this->epochs->parameterHash((array) $parent->parameters) !== ($delta['baseline_parameter_hash'] ?? null)) return $blocked('PAPER_TRAIT_ARCHIVE_BASELINE_MISSING_OR_CHANGED');
            $parameters = (array) $current->parameters;
            $baseline = (array) $parent->parameters;
            $diff = [];
            foreach (array_unique([...array_keys($baseline), ...array_keys($parameters)]) as $key) {
                if (! array_key_exists($key, $baseline) || ! array_key_exists($key, $parameters)) return $blocked('PAPER_TRAIT_TOTAL_PARAMETER_DELTA_MISMATCH');
                if ($this->epochs->parameterHash(['value' => $baseline[$key]]) !== $this->epochs->parameterHash(['value' => $parameters[$key]])) {
                    $diff[$key] = ['old' => $baseline[$key], 'new' => $parameters[$key]];
                }
            }
            if (count($diff) !== 1 || ! isset($diff[$gene])
                || $this->epochs->parameterHash($diff) !== $this->epochs->parameterHash((array) ($receipt['parameter_diff'] ?? []))) return $blocked('PAPER_TRAIT_TOTAL_PARAMETER_DELTA_MISMATCH');
            $reverified = app(InstrumentPolicyConsumptionService::class)->receipt($policy, $diff, $parameters);
            if (($reverified['status'] ?? null) !== 'policy_aligned_mutation_observed'
                || ! hash_equals($this->epochs->parameterHash($reverified), $this->epochs->parameterHash($receipt))) return $blocked('PAPER_TRAIT_CONFIRMED_CONSUMPTION_INVALID');
            $canonical = app(StrategyParameterSchemaService::class)->canonicalizeForIdentity($family, $parameters);
            if (data_get($current->metadata, 'parameter_fingerprint') !== hash('sha256', $family.'|'.json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION))
                || data_get($current->metadata, 'universal_genome.local_adapter.parameters_hash') !== hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION))) return $blocked('PAPER_TRAIT_CURRENT_PARAMETER_SEAL_INVALID');
            $basis = $this->traitRuntimeBasis($parent);
            if ($this->epochs->parameterHash($basis) !== $this->epochs->parameterHash($this->traitRuntimeBasis($current))) return $blocked('PAPER_TRAIT_UNOWNED_RUNTIME_COMPONENT_CHANGE');
            $archive = $this->archiveBaselineSource($parent, $symbol, $freeze, $basis);
            if ($archive === null) return $blocked('PAPER_TRAIT_ARCHIVE_BASELINE_PROVENANCE_UNVERIFIED');
            $sources = (array) data_get($receipt, 'isolated_sources.0.source_receipts', []);
            if (count($sources) < 3 || count($sources) > 9) return $blocked('PAPER_TRAIT_INDEPENDENT_SOURCE_WINDOWS_INCOMPLETE');
            $windows = []; $attested = []; $pairs = []; $instrumentBasis = null;
            foreach ($sources as $source) {
                $pair = \App\Models\LabLearningLanePair::where('pair_key', $source['pair_key'] ?? '')->first();
                $window = (array) data_get($pair?->metadata, 'instrument_research_window_receipt', []);
                $start = $this->strictTime($window['start_inclusive'] ?? null);
                $end = $this->strictTime($window['end_exclusive'] ?? null);
                if (! $pair || isset($pairs[$pair->id]) || $start === null || $end === null || ! $end->greaterThan($start)
                    || ! $start->greaterThanOrEqualTo('2027-01-01T00:00:00Z') || $end->greaterThan($freeze)
                    || ($start->lessThan($paperEnd) && $end->greaterThan($paperStart))) return $blocked('PAPER_TRAIT_ORIGINAL_WINDOW_CHRONOLOGY_INVALID');
                $pairs[$pair->id] = true;
                if (isset($windows[$window['window_key'] ?? ''])) return $blocked('PAPER_TRAIT_DUPLICATE_SOURCE_WINDOW');
                $windows[$window['window_key']] = $window;
                foreach (['control', 'candidate'] as $arm) {
                    $run = LabEvaluationRun::find($source[$arm.'_evidence_run_id'] ?? 0);
                    $sourceModel = ModelVersion::find($source[$arm.'_model_version_id'] ?? 0);
                    $finished = $this->strictTime($run?->finished_at?->toIso8601String());
                    if (! $run || ! $sourceModel || $finished === null || $finished->greaterThan($freeze) || $finished->lessThan($end)
                        || $this->epochs->parameterHash($this->traitRuntimeBasis($sourceModel)) !== $this->epochs->parameterHash($basis)
                        || $this->epochs->parameterHash((array) $sourceModel->parameters) !== $this->epochs->parameterHash($arm === 'control' ? $baseline : $parameters)) return $blocked('PAPER_TRAIT_ORIGINAL_SOURCE_MODEL_OR_TIME_INVALID');
                    $artifacts = $this->immutableSource($run, $sourceModel, $symbol);
                    if ($artifacts === null || ! $this->sourceFilesInside($artifacts['request'], $artifacts['response'], $start, $end)) return $blocked('PAPER_TRAIT_ORIGINAL_SOURCE_ARTIFACT_UNVERIFIED');
                    if (! $this->originalResearchTransportMatches($artifacts['request'], $window, $run)) return $blocked('PAPER_TRAIT_ORIGINAL_RESEARCH_AUTHORIZATION_UNVERIFIED');
                    $treatment = $this->instrumentTreatment((array) ($artifacts['request']['instrument_research_assignment'] ?? []), $gene);
                    if ($treatment === null || ($instrumentBasis !== null && $treatment !== $instrumentBasis)) return $blocked('PAPER_TRAIT_ORIGINAL_INSTRUMENT_TREATMENT_MISMATCH');
                    $instrumentBasis ??= $treatment;
                    $attested[] = ['pair_id' => $pair->id, 'run_id' => $run->run_id,
                        'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                        'request_artifact_hash' => $artifacts['request_artifact_hash'], 'response_artifact_hash' => $artifacts['response_artifact_hash'],
                        'window_key' => $window['window_key']];
                }
            }
            if (count($windows) < 3) return $blocked('PAPER_TRAIT_INDEPENDENT_SOURCE_WINDOWS_INCOMPLETE');
            $ordered = array_values($windows); usort($ordered, static fn (array $a, array $b): int => strcmp($a['start_inclusive'], $b['start_inclusive']));
            for ($i = 1; $i < count($ordered); $i++) {
                if ($ordered[$i - 1]['end_exclusive'] > $ordered[$i]['start_inclusive']) return $blocked('PAPER_TRAIT_ORIGINAL_WINDOW_CHRONOLOGY_INVALID');
            }
            usort($attested, static fn (array $a, array $b): int => strcmp($a['run_id'], $b['run_id']));
            $identity = ['protocol' => self::TRAIT_PROVENANCE_PROTOCOL, 'allowed' => true, 'reason_code' => null,
                'provenance_kind' => 'archive_baseline_confirmed_runtime_seal_preserving_trait',
                'archive_baseline_model_version_id' => $parent->id, 'archive_source' => $archive,
                'baseline_parameter_hash' => $delta['baseline_parameter_hash'], 'runtime_basis_hash' => $this->epochs->parameterHash($basis),
                'current_parameter_hash' => $this->epochs->parameterHash($parameters), 'current_parameter_fingerprint' => data_get($current->metadata, 'parameter_fingerprint'),
                'full_runtime_source_hash_at_freeze' => $currentSource,
                'consumption_receipt_hash' => $receipt['receipt_hash'], 'tested_intervention' => $delta,
                'source_windows' => $ordered, 'immutable_sources' => $attested, 'candidate_frozen_at' => $freeze->toIso8601String(),
                'paper_window_key' => data_get($epoch, 'paper_epoch.window_key'), 'arbitrary_post_paper_training_authorized' => false,
                'promotion_evidence' => false];
            return [...$identity, 'provenance_hash' => $this->epochs->parameterHash($identity)];
        } catch (\Throwable) {
            return $blocked('PAPER_TRAIT_ORIGINAL_PROVENANCE_UNASSESSABLE');
        }
    }

    /** Future archive candidates also require original server-owned evidence, never a caller flag. */
    public function archivePaperProvenance(ModelVersion $model, string $symbol, string $frozenAt, array $epoch): array
    {
        $blocked = ['protocol' => self::TRAIT_PROVENANCE_PROTOCOL, 'allowed' => false,
            'reason_code' => 'RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED', 'promotion_evidence' => false];
        try {
            $current = $model->fresh(); $freeze = $this->strictTime($frozenAt);
            if (! $current || $freeze === null || ! $this->epochs->paperContractAuthorized($epoch, $frozenAt)
                || data_get($epoch, 'paper_epoch.window_key') === ResearchPaperEpochContractService::PAPER_WINDOW_KEY) return $blocked;
            $archive = $this->archiveBaselineSource($current, $symbol, $freeze, $this->traitRuntimeBasis($current));
            if ($archive === null) return $blocked;
            $identity = ['protocol' => self::TRAIT_PROVENANCE_PROTOCOL, 'allowed' => true, 'reason_code' => null,
                'provenance_kind' => 'unchanged_archive_baseline', 'archive_baseline_model_version_id' => $current->id,
                'archive_source' => $archive, 'current_parameter_hash' => $this->epochs->parameterHash((array) $current->parameters),
                'runtime_basis_hash' => $this->epochs->parameterHash($this->traitRuntimeBasis($current)),
                'candidate_frozen_at' => $freeze->toIso8601String(), 'paper_window_key' => data_get($epoch, 'paper_epoch.window_key'),
                'arbitrary_post_paper_training_authorized' => false, 'promotion_evidence' => false];
            return [...$identity, 'provenance_hash' => $this->epochs->parameterHash($identity)];
        } catch (\Throwable) { return $blocked; }
    }

    private function traitRuntimeBasis(ModelVersion $model): array
    {
        return app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model);
    }

    /** Main validated this signed transport before cache/child execution. It is not independent credit. */
    private function originalResearchTransportMatches(array $request, array $window, LabEvaluationRun $run): bool
    {
        $signed = (array) data_get($request, 'policy_context.authorized_research_transport', []);
        $identity = array_diff_key($signed, array_flip(['contract_hash', 'hmac_sha256']));
        return ($signed['protocol'] ?? null) === InstrumentResearchWindowService::TRANSPORT_PROTOCOL
            && ($signed['purpose'] ?? null) === 'server_authorized_research_execution'
            && ($signed['independent_evidence'] ?? null) === false && ($signed['promotion_evidence'] ?? null) === false
            && ($signed['evaluation_mode'] ?? null) === 'full' && ($request['evaluation_mode'] ?? null) === 'full'
            && ($signed['generation_id'] ?? null) === (int) $run->lab_generation_id
            && ($signed['symbol'] ?? null) === ($request['symbol'] ?? null) && ($signed['timeframe'] ?? null) === ($request['timeframe'] ?? null)
            && ($signed['dataset_hash'] ?? null) === $run->data_hash
            && ($signed['release_hash'] ?? null) === data_get($request, 'research_release.release_hash')
            && $this->epochs->parameterHash((array) ($signed['window'] ?? [])) === $this->epochs->parameterHash($window)
            && preg_match('/^[a-f0-9]{64}$/', (string) ($signed['hmac_sha256'] ?? '')) === 1
            && ($signed['contract_hash'] ?? null) === $this->epochs->parameterHash($identity);
    }

    /** Per-arm IDs are transport identity; only the confirmed raw gene may differ in runtime treatment. */
    private function instrumentTreatment(array $assignment, string $gene): ?string
    {
        if (($assignment['protocol'] ?? null) !== LabInstrumentResearchService::PROTOCOL
            || ($assignment['status'] ?? null) !== 'assigned' || (array) ($assignment['selected'] ?? []) === []) return null;
        $selected = [];
        foreach ((array) $assignment['selected'] as $row) {
            if (! is_array($row) || ! filled($row['instrument_key'] ?? null)) return null;
            $selected[] = \Illuminate\Support\Arr::only($row, ['instrument_key', 'role', 'tactic_id', 'runtime_capability', 'activation_contract'])
                + ['parameter_bindings' => array_diff_key((array) ($row['parameter_bindings'] ?? []), [$gene => true])];
        }
        usort($selected, static fn (array $a, array $b): int => strcmp($a['instrument_key'], $b['instrument_key']));
        return $this->epochs->parameterHash(['protocol' => $assignment['protocol'], 'temporal_roles' => $assignment['temporal_roles'] ?? [],
            'activation_policy' => $assignment['activation_policy'] ?? [], 'selected' => $selected,
            'decision_rules' => data_get($assignment, 'decision_doctrine.rules', [])]);
    }

    private function archiveBaselineSource(ModelVersion $model, string $symbol, CarbonImmutable $freeze, array $basis): ?array
    {
        foreach (LabEvaluationRun::where('model_version_id', $model->id)->where('status', 'completed')->orderBy('id')->limit(50)->get() as $run) {
            $finished = $this->strictTime($run->finished_at?->toIso8601String());
            if ($finished === null || $finished->greaterThan($freeze) || ! $finished->lessThan('2027-01-01T00:00:00Z')) continue;
            $source = $this->immutableSource($run, $model, $symbol);
            if ($source === null || ! $this->sourceFilesInside($source['request'], $source['response'], null, CarbonImmutable::parse('2026-01-01T00:00:00Z'))) continue;
            return ['run_id' => $run->run_id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                'request_artifact_hash' => $source['request_artifact_hash'], 'response_artifact_hash' => $source['response_artifact_hash'],
                'data_hash' => $run->data_hash, 'source_hash' => $run->code_hash, 'model_runtime_identity_hash' => $source['model_runtime_identity_hash']];
        }
        return null;
    }

    private function immutableSource(LabEvaluationRun $run, ModelVersion $model, string $symbol): ?array
    {
        if ($run->status !== 'completed' || (int) $run->model_version_id !== (int) $model->id) return null;
        $evidence = app(LabImmutableEvidenceService::class);
        $payloads = []; $hashes = [];
        foreach (['request', 'response'] as $plane) {
            $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_'.$plane)->latest('id')->first();
            if (! $artifact || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2' || ! $artifact->storage_path) return null;
            $payload = $evidence->readArtifactPayload($artifact);
            if (! is_array($payload) || ($plane === 'response' && ! hash_equals((string) $run->response_hash, (string) $artifact->sha256))
                || ($plane === 'request' && data_get($artifact->metadata, 'request_hash') !== $run->request_hash)) return null;
            $payloads[$plane] = $payload; $hashes[$plane] = $artifact->sha256;
        }
        $request = $payloads['request']; $response = $payloads['response'];
        $modelIdentity = $evidence->verifiedModelRuntimeIdentity($run);
        if ($modelIdentity === null || ($modelIdentity['evidence_parameter_hash'] ?? null) !== $run->parameter_hash
            || ($modelIdentity['request_artifact_hash'] ?? null) !== $hashes['request']
            || $this->epochs->parameterHash((array) ($modelIdentity['runtime_basis'] ?? [])) !== $this->epochs->parameterHash($this->traitRuntimeBasis($model))) return null;
        if (strtoupper((string) ($request['symbol'] ?? '')) !== strtoupper($symbol)
            || (string) ($request['strategy'] ?? '') !== (string) $model->strategy
            || $this->epochs->parameterHash((array) ($request['parameters'] ?? [])) !== $this->epochs->parameterHash((array) $model->parameters)
            || (filled(data_get($modelIdentity, 'runtime_basis.components.execution_contract.execution_hash'))
                && data_get($modelIdentity, 'runtime_basis.components.execution_contract.execution_hash') !== data_get($request, 'execution_contract.execution_hash'))) return null;
        // Recompile only against the ORIGINAL request dependencies, not live
        // data or a caller's current treatment aliases. This owner reads the
        // real nested smart_composition passport and per-arm raw parameters.
        $originalContract = app(LabAgentEvaluationService::class)->compositionRuntimeContract($run->agent()->firstOrFail(),
            (array) ($request['instrument_research_assignment'] ?? []), (string) ($request['timeframe'] ?? ''),
            ['bundle_hash' => $request['replay_dataset_hash'] ?? '', 'manifest' => (array) ($request['mtf_snapshot_manifest'] ?? [])],
            (string) ($request['replay_dataset_hash'] ?? ''));
        if ($this->epochs->parameterHash((array) $originalContract) !== $this->epochs->parameterHash((array) ($request['composition_runtime_contract'] ?? []))) return null;
        $identity = (array) data_get($response, 'data_quality.decision_identity_receipt', []);
        if (! app(CausalStageMasteryDirectorService::class)->decisionIdentityValid($identity)) return null;
        $bindings = (array) ($identity['bindings'] ?? []);
        $release = (array) ($request['research_release'] ?? []);
        $worker = (array) data_get($response, 'data_quality.research_release_receipt', []);
        if (($release['protocol'] ?? null) !== ResearchReleaseSealService::PROTOCOL
            || ($release['source_hash'] ?? null) !== $run->code_hash || ($bindings['full_runtime_source_hash'] ?? null) !== $run->code_hash
            || ($release['python_source_hash'] ?? null) !== ($bindings['python_source_hash'] ?? null)
            || ($worker['loaded_code_attested'] ?? null) !== true || ($worker['source_hash'] ?? null) !== ($release['python_source_hash'] ?? null)
            || ($worker['boot_source_hash'] ?? null) !== ($release['python_source_hash'] ?? null)
            || ($worker['release_hash'] ?? null) !== ($release['release_hash'] ?? null)
            || ($release['dataset_hash'] ?? null) !== $run->data_hash || ($bindings['dataset_identity'] ?? null) !== $run->data_hash
            || ($request['replay_dataset_hash'] ?? null) !== $run->data_hash
            || ($bindings['execution_hash'] ?? null) !== data_get($request, 'execution_contract.execution_hash')) return null;
        $releaseIdentity = array_diff_key($release, array_flip(['release_hash', 'sealed_at', 'promotion_evidence']));
        if (! hash_equals((string) ($release['release_hash'] ?? ''), app(ExecutionContractService::class)->hashParameters($releaseIdentity))) return null;
        return [...$payloads, 'request_artifact_hash' => $hashes['request'], 'response_artifact_hash' => $hashes['response'],
            'model_runtime_identity_hash' => $modelIdentity['artifact_hash']];
    }

    /** Hash and parse the same original bytes, including non-evaluated warmup. */
    private function sourceFilesInside(array $request, array $response, ?CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $manifest = (array) ($request['mtf_snapshot_manifest'] ?? []);
        $records = (array) ($manifest['streams'] ?? []);
        $streams = (array) data_get($response, 'data_quality.decision_identity_receipt.dependency_identity.streams', []);
        if (array_diff(['M5', 'M15', 'H1', 'H4'], array_keys($records)) !== [] || ($manifest['bundle_hash'] ?? null) !== ($request['replay_dataset_hash'] ?? null)
            || strtoupper((string) ($request['timeframe'] ?? '')) !== 'M5'
            || realpath((string) ($request['dataset_path'] ?? '')) !== realpath((string) data_get($records, 'M5.path', ''))
            || ! $this->emptyOriginalInlinePlane($request['candles'] ?? null) || ! $this->emptyOriginalInlinePlane($request['regime_candles'] ?? null)
            || (array) ($request['related_mtf_dataset_paths'] ?? []) !== [] || (array) ($request['mtf_streams'] ?? []) !== []
            || (array) ($request['related_mtf_streams'] ?? []) !== [] || filled($request['foundation_dataset_path'] ?? null)) return false;
        foreach ((array) ($request['mtf_dataset_paths'] ?? []) as $key => $path) {
            if (! isset($records[$key]) || realpath((string) $path) !== realpath((string) ($records[$key]['path'] ?? ''))) return false;
        }
        if (array_diff(['M15', 'H1', 'H4'], array_keys((array) ($request['mtf_dataset_paths'] ?? []))) !== []) return false;
        if (filled($request['regime_dataset_path'] ?? null)
            && realpath((string) $request['regime_dataset_path']) !== realpath((string) data_get($records, 'H1.path', ''))) return false;
        foreach ($records as $key => $record) {
            if (($streams[$key]['status'] ?? null) !== 'verified' || ($record['sha256'] ?? null) !== ($streams[$key]['actual_source_sha256'] ?? null)) return false;
            $path = realpath((string) ($record['path'] ?? ''));
            if ($path === false || ! is_file($path) || filesize($path) > 67108864) return false;
            $bytes = file_get_contents($path);
            if ($bytes === false || ! hash_equals((string) $record['sha256'], hash('sha256', $bytes))) return false;
            $handle = fopen('php://temp', 'w+'); fwrite($handle, $bytes); rewind($handle); unset($bytes);
            try {
                $header = fgetcsv($handle, escape: '');
                $column = is_array($header) ? array_search('time', $header, true) : false;
                if ($column === false) return false;
                $prior = null; $rows = 0;
                while (($row = fgetcsv($handle, escape: '')) !== false) {
                    $time = $this->strictTime(is_string($row[$column] ?? null) ? str_replace(' ', 'T', $row[$column]) : null);
                    if ($time === null || ! $time->lessThan($end) || ($start !== null && $time->lessThan($start))
                        || ($prior !== null && ! $time->greaterThan($prior)) || ++$rows > 2000000) return false;
                    $prior = $time;
                }
                if ($rows < 2) return false;
            } finally { fclose($handle); }
        }
        return true;
    }

    private function emptyOriginalInlinePlane(mixed $plane): bool
    {
        return $plane === null || $plane === [] || (is_array($plane)
            && ($plane['__canonical_dataset_reference'] ?? null) === true && ($plane['row_count'] ?? null) === 0
            && ($plane['first_row'] ?? null) === null && ($plane['last_row'] ?? null) === null
            && ($plane['sha256'] ?? null) === app(LabImmutableEvidenceService::class)->hash([]));
    }

    private function strictTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) return null;
        try { return CarbonImmutable::parse($value)->utc(); } catch (\Throwable) { return null; }
    }
}
