<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Explicit cold-source preparation only; the existing component executor owns all later replay. */
class FiniteOriginalComponentPreparationService
{
    public const WORK_TYPE = 'finite_component_prepare';
    public const PROTOCOL = 'finite_original_component_preparation_v1';
    public const MARKER = 'finite_original_component_preparation';

    public function register(string $questionKey, array $design): array
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,96}$/D', $questionKey)) throw new LogicException('FINITE_COMPONENT_QUESTION_KEY_REQUIRED');
        $lab = AiLaboratory::where('symbol', 'XAUUSD')->where('timeframe', 'H1')->firstOrFail();
        if (! in_array('hybrid', (array) $lab->strategy_families, true)) throw new LogicException('FINITE_COMPONENT_HYBRID_LAB_REQUIRED');
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        // There is one fixed hypothesis, not a memory-guided ranking claim.
        $design['evaluator_hash'] = app(LabImmutableEvidenceService::class)->codeHash();
        $design['owner_source_hash'] = hash_file('sha256', __FILE__);
        $design['execution_hash'] = $execution['execution_hash'];
        $context = data_get($design, 'subject.context');
        $this->need(is_array($context) && $context !== [], 'FINITE_COMPONENT_EXPLICIT_CONTEXT_REQUIRED');
        $design['context_hash'] = app(ContextContractV2Service::class)->project($context)['identity_hash'];
        $design['subject'] = ['candidate_role' => 'guided', 'context' => $context,
            'trait_delta' => ['gene' => 'max_loss_streak_before_wait', 'old' => 4, 'new' => 3],
            'trait_gene' => 'max_loss_streak_before_wait',
            'fixed_recipe' => ['control' => 4, 'guided' => 3, 'blinded' => 5], 'memory_selector_evidence' => false];
        $design['registration_time_policy'] = ScopedResearchCertificateService::FIRST_EXPOSURE_TIME_POLICY;
        $design['authority_policy'] = ScopedResearchCertificateService::AUTHORITY_POLICY;
        $design = app(ScopedResearchCertificateService::class)->normalizeProspectiveDesign('component', $design);
        $this->need(isset($design['native_execution']) && ! isset($design['native_execution']['protocol']),
            'FINITE_COMPONENT_RAW_NATIVE_EXECUTION_REQUIRED');
        $schema = app(StrategyParameterSchemaService::class);
        $spec = ['protocol' => self::PROTOCOL, 'question_key' => $questionKey,
            'lab_id' => (int) $lab->id, 'strategy' => 'hybrid', 'design' => $design,
            'php_source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'schema_hash' => $this->hash($schema->schema('hybrid')),
            'defaults_hash' => $this->hash($schema->defaults('hybrid')),
            'source_only_birth' => true, 'memory_selector_evidence' => false, 'promotion_evidence' => false];
        // The work-item JSON column is the prospective question's wire owner.
        // Normalize before hashing/sealing, never repair a persisted seal later.
        $spec = json_decode(json_encode($spec, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $specHash = $this->hash($spec);
        $existing = ResearchExperimentWorkItem::where('work_type', self::WORK_TYPE)
            ->where('payload->question_key', $questionKey)->first();
        if ($existing) {
            $this->need(data_get($existing->payload, 'spec_hash') === $specHash, 'FINITE_COMPONENT_QUESTION_IMMUTABLE');
            return ['status' => 'already_registered', 'work_id' => (int) $existing->id, 'dispatched' => false];
        }
        return app(ResearchExperimentConversionKernelService::class)->record($this->contract($lab, $specHash, $design),
            ['prospective_spec' => $spec, 'spec_hash' => $specHash, 'spec_seal' => $this->seal($spec)], 'INCONCLUSIVE',
            ['type' => self::WORK_TYPE, 'identity' => $questionKey, 'question_key' => $questionKey,
                'spec' => $spec, 'spec_hash' => $specHash, 'spec_seal' => $this->seal($spec),
                'executable' => false, 'priority' => 8,
                'retry_condition' => ['code' => 'FINITE_COMPONENT_ORIGINAL_SERVER_DATA_REQUIRED',
                    'max_experiments' => 1, 'same_evidence_replay_forbidden' => true]], []);
    }

    public function inspectWork(ResearchExperimentWorkItem $work): array
    {
        try {
            $spec = $this->sealedSpec($work);
            $lab = AiLaboratory::find($spec['lab_id']);
            $this->need($lab && $lab->symbol === $work->symbol && $lab->timeframe === $work->timeframe
                && $work->symbol === 'XAUUSD' && $work->timeframe === 'H1'
                && in_array('hybrid', (array) $lab->strategy_families, true), 'FINITE_COMPONENT_ORIGINAL_LAB_REQUIRED');
            $this->need(app(AutonomousModeService::class)->enabled($work->symbol, $work->timeframe), 'AUTONOMOUS_MODE_STOPPED');
            $this->need(! $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists(),
                'FINITE_COMPONENT_ANOTHER_GENERATION_OWNS_STREAM');
            $windows = $this->windows($spec['design']);
            return ['executable' => true, 'spec' => $spec, 'windows' => $windows,
                'spec_hash' => $this->hash($spec), 'promotion_evidence' => false];
        } catch (Throwable $error) {
            return ['executable' => false, 'reason_code' => $this->reason($error), 'promotion_evidence' => false];
        }
    }

    public function assertLease(ResearchExperimentWorkItem $work): void
    {
        $current = ResearchExperimentWorkItem::find($work->id);
        $this->need($current && $current->status === 'leased' && $current->lease_token === $work->lease_token
            && (int) $current->fence_version === (int) $work->fence_version
            && $current->symbol === $work->symbol && $current->timeframe === $work->timeframe
            && $current->work_type === self::WORK_TYPE && $work->work_type === self::WORK_TYPE
            && $current->lease_expires_at?->isFuture(), 'FINITE_COMPONENT_WORK_LEASE_NOT_CURRENT');
        $mode = app(AutonomousModeService::class);
        $allowed = DB::transactionLevel() > 0 ? $mode->enabledForCommit($work->symbol, $work->timeframe)
            : $mode->enabled($work->symbol, $work->timeframe);
        $this->need($allowed, 'AUTONOMOUS_MODE_STOPPED');
    }

    public function execute(ResearchExperimentWorkItem $work): array
    {
        try {
            $this->assertLease($work);
            $proof = $this->inspectWork($work->fresh('receipt'));
            $this->need(($proof['executable'] ?? false) === true, $proof['reason_code'] ?? 'FINITE_COMPONENT_NOT_READY');
            $generation = app(LabPopulationService::class)->buildFiniteOriginalComponent($work, $proof);
            return ['status' => 'completed', 'source_generation_id' => (int) $generation->id,
                'source_only' => true, 'replay_dispatched' => false, 'promotion_evidence' => false];
        } catch (Throwable $error) {
            app(ResearchExperimentConversionKernelService::class)->defer($work, $this->reason($error), false);
            return ['status' => 'blocked', 'reason' => $this->reason($error), 'promotion_evidence' => false];
        }
    }

    /** Called within the canonical constructor transaction; failures roll the birth back. */
    public function completeBirth(ResearchExperimentWorkItem $work, LabGeneration $generation, array $proof): void
    {
        $this->need(DB::transactionLevel() > 0, 'FINITE_COMPONENT_OUTER_CONSTRUCTOR_TRANSACTION_REQUIRED');
        $this->assertLease($work);
        $spec = $this->sealedSpec($work->fresh('receipt'));
        $generation = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
        $marker = data_get($generation->trigger_context, self::MARKER);
        $specHash = $this->hash($spec);
        $this->need(is_array($marker) && ($marker['protocol'] ?? null) === self::PROTOCOL
            && ($marker['work_id'] ?? null) === (int) $work->id && ($marker['spec_hash'] ?? null) === $specHash
            && ($proof['spec_hash'] ?? null) === $specHash && ($marker['source_only'] ?? null) === true
            && $generation->trigger_type === self::WORK_TYPE && $generation->status === 'draft'
            && (int) $generation->ai_laboratory_id === $spec['lab_id']
            && data_get($generation->trigger_context, 'generation_admission_decision.allowed') === true,
            'FINITE_COMPONENT_CANONICAL_BIRTH_PURPOSE_REQUIRED');
        $origin = app(ResearchWindowExposureInventoryService::class)->createOriginalUntrainedTriplet((int) $generation->id);
        $source = AgentLearningCausalExperiment::findOrFail($origin['experiment_id']);
        $certificate = app(ScopedResearchCertificateService::class)->register('component', $source, $spec['design']);
        $this->need(($certificate['valid'] ?? false) === true, 'FINITE_COMPONENT_ORIGINAL_CAPTURE_REQUIRED');
        $certificateId = (int) $certificate['certificate_id'];
        // The native owner reopens each registry stream and capture inventory.
        // bindOriginalData is a single-window diagnostic API, not a roster seal.
        $readiness = app(ScopedResearchCertificateService::class)->independentWindowReadiness($certificateId);
        $this->need(($readiness['ready'] ?? false) === true,
            (string) ($readiness['reason_codes'][0] ?? 'FINITE_COMPONENT_ORIGINAL_INPUT_INVENTORY_REQUIRED'));
        $next = app(DescendantScopedExecutionService::class)->registerComponentWork($certificateId);
        $this->need(($next['status'] ?? null) === 'recorded', 'FINITE_COMPONENT_EXISTING_COMPONENT_WORK_REQUIRED');
        $disposition = ['protocol' => self::PROTOCOL, 'work_id' => (int) $work->id,
            'spec_hash' => $specHash, 'origin_id' => $origin['origin_id'],
            'experiment_id' => (int) $source->id, 'certificate_id' => $certificateId,
            'component_work_id' => $next['work_id'], 'source_only_reference' => true,
            'replay_attempted' => false, 'scientific_outcome' => false, 'authority' => false];
        // A birth source is not an ordinary causal fold candidate. Status is
        // excluded from the immutable choice seal; no choice/evidence is changed.
        $source->update(['status' => 'scoped_source_reference']);
        $generation->agents()->update(['lifecycle_status' => 'abandoned',
            'decision_reason' => 'Unused source-only reference; no replay, failure verdict, credit or qualification.']);
        $generation->update(['status' => 'abandoned', 'completed_at' => now(),
            'trigger_context' => [...(array) $generation->trigger_context, 'source_only_disposition' => $disposition]]);
        $closure = app(ResearchExperimentConversionKernelService::class)->record(
            $this->contract($generation->laboratory, $specHash, $spec['design'], (int) $generation->id),
            $disposition, 'INCONCLUSIVE', [], ['code' => 'SOURCE_ONLY_BIRTH_PREPARED_NO_SCIENTIFIC_OUTCOME']);
        $this->need(($closure['status'] ?? null) === 'recorded', 'FINITE_COMPONENT_NEUTRAL_CLOSURE_REQUIRED');
        $this->assertLease($work);
        $this->need(app(ResearchExperimentConversionKernelService::class)->complete($work,
            [...$disposition, 'neutral_receipt_id' => $closure['receipt_id']]), 'FINITE_COMPONENT_WORK_COMPLETION_FENCE_REQUIRED');
    }

    /** Purpose identity only; neither this nor the birth grants replay/credit authority. */
    public function isOriginalSourceReference(AgentLearningCausalExperiment $experiment): bool
    {
        try {
            $generation = LabGeneration::findOrFail($experiment->lab_generation_id);
            $marker = data_get($generation->trigger_context, self::MARKER);
            $disposition = data_get($generation->trigger_context, 'source_only_disposition');
            $this->need(is_array($marker) && is_array($disposition), 'FINITE_COMPONENT_SOURCE_PURPOSE_MISSING');
            $work = ResearchExperimentWorkItem::with('receipt')->findOrFail($marker['work_id'] ?? null);
            $spec = $this->sealedSpec($work, false);
            $this->need($generation->trigger_type === self::WORK_TYPE && $generation->status === 'abandoned'
                && (int) $generation->ai_laboratory_id === $spec['lab_id']
                && ($marker['protocol'] ?? null) === self::PROTOCOL && ($marker['source_only'] ?? null) === true
                && ($marker['spec_hash'] ?? null) === $this->hash($spec)
                && ($disposition['work_id'] ?? null) === (int) $work->id
                && ($disposition['spec_hash'] ?? null) === $this->hash($spec)
                && ($disposition['experiment_id'] ?? null) === (int) $experiment->id
                && ($disposition['source_only_reference'] ?? null) === true,
                'FINITE_COMPONENT_ORIGINAL_SOURCE_PURPOSE_DRIFT');
            $origin = app(ResearchWindowExposureInventoryService::class)->originalFiniteOriginIdentity(
                (int) $generation->id, (int) $experiment->id);
            $this->need(($origin['origin_id'] ?? null) === ($disposition['origin_id'] ?? null),
                'FINITE_COMPONENT_ORIGINAL_SOURCE_PURPOSE_DRIFT');
            return true;
        } catch (Throwable) { return false; }
    }

    private function sealedSpec(ResearchExperimentWorkItem $work, bool $requireCurrentSource = true): array
    {
        $spec = data_get($work->payload, 'spec');
        $this->need($work->work_type === self::WORK_TYPE
            && data_get($work->payload, 'owner') === ResearchLoopArbiterService::class
            && data_get($work->payload, 'executor') === ResearchExperimentWorkConsumerService::class
            && is_array($spec) && ($spec['protocol'] ?? null) === self::PROTOCOL
            && $this->hash($spec) === data_get($work->payload, 'spec_hash')
            && hash_equals($this->seal($spec), (string) data_get($work->payload, 'spec_seal'))
            && data_get($work->receipt?->payload, 'evidence.prospective_spec') === $spec
            && data_get($work->receipt?->payload, 'evidence.spec_seal') === data_get($work->payload, 'spec_seal'),
            'FINITE_COMPONENT_ORIGINAL_SPEC_DRIFT');
        if ($requireCurrentSource) $this->need($spec['php_source_hash'] === app(LabImmutableEvidenceService::class)->codeHash()
            && $spec['design']['owner_source_hash'] === hash_file('sha256', __FILE__)
            && $spec['schema_hash'] === $this->hash(app(StrategyParameterSchemaService::class)->schema('hybrid'))
            && $spec['defaults_hash'] === $this->hash(app(StrategyParameterSchemaService::class)->defaults('hybrid')),
            'FINITE_COMPONENT_REGISTERED_SOURCE_CHANGED');
        return $spec;
    }

    private function windows(array $design): array
    {
        $ids = data_get($design, 'native_execution.authorization_ids', []);
        $periods = $design['validation_windows'] ?? [];
        $minimum = max(6, (int) config('services.learning_lane.causal_minimum_powered_windows', 6));
        $this->need(is_array($ids) && array_is_list($ids) && count($ids) >= $minimum && count($ids) <= 9
            && count(array_unique($ids)) === count($ids) && count($periods) === count($ids), 'FINITE_COMPONENT_SIX_ORIGINAL_WINDOWS_REQUIRED');
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $this->need($execution['execution_hash'] === $design['execution_hash'], 'FINITE_COMPONENT_COST_POLICY_CHANGED');
        $entries = []; $hashes = [];
        foreach ($ids as $index => $id) {
            $records = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
                fn ($record): bool => is_array($record) && ($record['authorization_id'] ?? null) === $id));
            $this->need(count($records) === 1, 'FINITE_COMPONENT_ORIGINAL_SERVER_DATA_REQUIRED');
            $record = $records[0];
            $window = app(InstrumentResearchWindowService::class)->seal($id, (string) ($record['dataset_sha256'] ?? ''));
            $this->need($window !== null
                && \Carbon\CarbonImmutable::parse($window['start_inclusive'])->eq(\Carbon\CarbonImmutable::parse($periods[$index]['start_inclusive']))
                && \Carbon\CarbonImmutable::parse($window['end_exclusive'])->eq(\Carbon\CarbonImmutable::parse($periods[$index]['end_exclusive'])),
                'FINITE_COMPONENT_ORIGINAL_SERVER_WINDOW_DRIFT');
            $manifest = (array) ($record['mtf_bundle_manifest'] ?? []);
            app(InstrumentResearchWindowService::class)->verifySealedReplayWindow($window, $manifest);
            $hashes[] = $window['dataset_sha256'];
            $entries[] = ['window' => $window, 'manifest' => $manifest];
        }
        $this->need(count(array_unique($hashes)) === count($hashes), 'FINITE_COMPONENT_ORIGINAL_DATASET_REUSED');
        return $entries;
    }

    private function contract(AiLaboratory $lab, string $hash, array $design, ?int $generationId = null): array
    {
        return ['contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => $generationId ? LabGeneration::class : AiLaboratory::class, 'id' => $generationId ?? (int) $lab->id],
            'scope' => ['symbol' => $lab->symbol, 'laboratory_timeframe' => $lab->timeframe, 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => $hash, 'data_and_mtf_hash' => $hash,
                'runtime_and_contract_hash' => $design['execution_hash'], 'intervention_hash' => $hash,
                'window_plan_hash' => $hash, 'evaluator_version' => $design['evaluator_hash']],
            'arms' => [['role' => 'control'], ['role' => 'guided'], ['role' => 'blinded']]];
    }

    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
    private function seal(array $value): string
    {
        $key = (string) config('services.internal_api.token');
        $this->need(strlen($key) >= 32, 'FINITE_COMPONENT_INTERNAL_SEAL_KEY_REQUIRED');
        return hash_hmac('sha256', self::PROTOCOL."\n".$this->hash($value), $key);
    }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
    private function reason(Throwable $error): string { return $error instanceof LogicException ? $error->getMessage() : 'FINITE_COMPONENT_ORIGINAL_OWNER_UNAVAILABLE'; }
}
