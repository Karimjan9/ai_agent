<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExposureCaptureRecord;
use App\Models\ResearchOriginalModelOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** A deliberately finite original producer, not a retroactive model-history declaration. */
trait ResearchOriginalExposureHistoryTrait
{
    public const ORIGINAL_ORIGIN_PROTOCOL = 'finite_untrained_component_origin_v1';

    /** Cheap immutable purpose identity; mutable runtime/status cannot erase a source-only birth. */
    public function originalFiniteOriginIdentity(int $generationId, int $experimentId): array
    {
        if (! Schema::hasTable('research_original_model_origins')) {
            throw new LogicException('ORIGINAL_MODEL_ORIGIN_MIGRATION_REQUIRED');
        }
        $origin = ResearchOriginalModelOrigin::where('lab_generation_id', $generationId)->first();
        if (! $origin) throw new LogicException('ORIGINAL_MODEL_BIRTH_AND_SELECTION_HISTORY_UNATTESTED');
        $body = ['origin_key' => $origin->origin_key, 'lab_generation_id' => $generationId,
            'payload_hash' => $origin->payload_hash, 'payload' => $origin->payload,
            'recorded_at' => $this->originalWholeSecondTimestampRepresentation($origin->getRawOriginal('recorded_at'))];
        if ($this->hash($origin->payload) !== $origin->payload_hash
            || ! hash_equals($origin->server_seal, $this->seal(['domain' => self::ORIGINAL_ORIGIN_PROTOCOL, ...$body]))) {
            throw new LogicException('ORIGINAL_MODEL_ORIGIN_SEAL_DRIFT');
        }
        if (($origin->payload['protocol'] ?? null) !== self::ORIGINAL_ORIGIN_PROTOCOL
            || ($origin->payload['lab_generation_id'] ?? null) !== $generationId
            || ($origin->payload['experiment_id'] ?? null) !== $experimentId) {
            throw new LogicException('ORIGINAL_MODEL_ORIGIN_SCOPE_MISMATCH');
        }
        return ['origin_id' => (int) $origin->id, 'origin_key' => $origin->origin_key,
            'payload_hash' => $origin->payload_hash, 'protocol' => self::ORIGINAL_ORIGIN_PROTOCOL,
            'model_ids' => array_column($origin->payload['models'], 'model_version_id'),
            'agent_ids' => array_column($origin->payload['models'], 'agent_id')];
    }

    /**
     * A constructor may fill one already-authorized, empty draft. No generation,
     * job or authority is created here; PAUSE/STOP deny the transactional commit.
     * The fixed alternatives are not guided/blinded learning-performance proof.
     */
    public function createOriginalUntrainedTriplet(int $generationId, string $strategy = 'hybrid'): array
    {
        if (! Schema::hasTable('research_original_model_origins')) {
            throw new LogicException('ORIGINAL_MODEL_ORIGIN_MIGRATION_REQUIRED');
        }
        return DB::transaction(function () use ($generationId, $strategy): array {
            $generation = LabGeneration::whereKey($generationId)->lockForUpdate()->firstOrFail();
            $lab = $generation->laboratory;
            if (! $lab || ! app(AutonomousModeService::class)->enabledForCommit($lab->symbol, $lab->timeframe)) {
                throw new LogicException('ORIGINAL_COMPONENT_AUTONOMY_COMMIT_DENIED');
            }
            if (ResearchOriginalModelOrigin::where('lab_generation_id', $generationId)->exists()) {
                throw new LogicException('ORIGINAL_COMPONENT_ORIGIN_ALREADY_CREATED');
            }
            if ($generation->status !== 'draft' || (int) $generation->population_size !== 3
                || $generation->started_at !== null || $generation->data_fingerprint !== null
                || $generation->agents()->exists()
                || LabEvaluationRun::where('lab_generation_id', $generationId)->exists()
                || AgentLearningCausalExperiment::where('lab_generation_id', $generationId)->exists()
                || data_get($generation->trigger_context, 'research_release') !== null
                || $lab->symbol !== 'XAUUSD' || ! in_array($strategy, (array) $lab->strategy_families, true)) {
                throw new LogicException('ORIGINAL_COMPONENT_UNUSED_THREE_SLOT_DRAFT_REQUIRED');
            }
            $schema = app(StrategyParameterSchemaService::class);
            $defaults = $schema->defaults($strategy);
            if ($defaults === [] || ($defaults['max_loss_streak_before_wait'] ?? null) !== 4) {
                throw new LogicException('ORIGINAL_COMPONENT_FIXED_DEFAULT_RECIPE_UNAVAILABLE');
            }
            $nonce = (string) Str::uuid();
            $started = CarbonImmutable::now('UTC')->toIso8601String();
            $execution = app(ExecutionContractService::class)->for($lab->symbol, 'M5');
            $agents = [];
            foreach (['control' => 4, 'guided' => 3, 'blinded' => 5] as $role => $limit) {
                $parameters = $schema->validate($strategy, [...$defaults, 'max_loss_streak_before_wait' => $limit]);
                $model = ModelVersion::create(['name' => 'finite untrained '.$nonce.' '.$role,
                    'strategy' => $strategy, 'version' => 'original-'.$nonce.'-'.$role,
                    'status' => 'testing', 'parameters' => $parameters,
                    'metadata' => ['execution_contract' => $execution]]);
                $agents[$role] = LabAgent::create(['lab_generation_id' => $generationId,
                    'model_version_id' => $model->id, 'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe,
                    'strategy_family' => $strategy, 'origin' => 'finite_untrained_v1',
                    'lifecycle_status' => 'draft', 'parameter_diff' => $role === 'control' ? []
                        : ['max_loss_streak_before_wait' => ['old' => 4, 'new' => $limit]]]);
            }
            $experiment = AgentLearningCausalExperiment::create(['experiment_key' => 'original-'.$nonce,
                'lab_generation_id' => $generationId, 'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe,
                'strategy_family' => $strategy, 'target' => 'drawdown', 'gene_key' => 'max_loss_streak_before_wait',
                'guided_agent_id' => $agents['guided']->id, 'blinded_agent_id' => $agents['blinded']->id,
                'control_agent_id' => $agents['control']->id, 'status' => 'ready_for_replay',
                'evidence' => ['experiment_kind' => self::ORIGINAL_ORIGIN_PROTOCOL,
                    'selection_owner' => self::class.'::createOriginalUntrainedTriplet',
                    'memory_selector_evidence' => false, 'training_inputs' => [], 'parent_inputs' => [],
                    'recipe' => ['control' => 4, 'guided' => 3, 'blinded' => 5]]]);
            $payload = ['protocol' => self::ORIGINAL_ORIGIN_PROTOCOL,
                'lab_generation_id' => $generationId, 'experiment_id' => (int) $experiment->id,
                'created_before_first_exposure_at' => $started,
                'evaluator_release_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                'schema_hash' => $this->hash($schema->schema($strategy)),
                'defaults_hash' => $this->hash($defaults),
                'parameter_choice' => 'canonical_defaults_plus_fixed_4_3_5_loss_streak_recipe',
                'external_training_inputs' => [], 'parent_inputs' => [], 'memory_selector_evidence' => false,
                'run_high_water' => (int) LabEvaluationRun::max('id'),
                'fold_high_water' => (int) CausalFoldReceipt::max('id'),
                'prior_run_roster' => $this->originalBirthRunRoster((int) LabEvaluationRun::max('id')),
                'prior_fold_roster' => $this->originalBirthFoldRoster((int) CausalFoldReceipt::max('id')),
                'models' => $this->originalOriginModels($generationId),
                'experiment' => $this->originalOriginExperiment($experiment)];
            $key = $this->hash([self::ORIGINAL_ORIGIN_PROTOCOL, $generationId, $nonce]);
            $body = ['origin_key' => $key, 'lab_generation_id' => $generationId,
                'payload_hash' => $this->hash($payload), 'payload' => $payload,
                'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')];
            $origin = ResearchOriginalModelOrigin::create([...$body, 'server_seal' => $this->seal([
                'domain' => self::ORIGINAL_ORIGIN_PROTOCOL, ...$body])]);
            if (! app(AutonomousModeService::class)->enabledForCommit($lab->symbol, $lab->timeframe)) {
                throw new LogicException('ORIGINAL_COMPONENT_AUTONOMY_COMMIT_DENIED');
            }
            return ['origin_id' => (int) $origin->id, 'experiment_id' => (int) $experiment->id,
                'agent_ids' => array_map(fn ($agent) => (int) $agent->id, $agents),
                'model_ids' => array_map(fn ($agent) => (int) $agent->model_version_id, $agents),
                'authority' => false, 'memory_selector_evidence' => false, 'dispatched' => false];
        });
    }

    /** Reopens a finite server origin and every known original input, including failed attempts. */
    public function originalHistoryAttestation(int $certificateId, string $start, string $end,
        array $originalRunIds = [], array $originalFoldIds = []): array
    {
        try {
            $registration = $this->registration($certificateId);
            $capture = ResearchExposureCaptureRecord::where('certificate_id', $certificateId)
                ->where('record_type', 'capture_start')->sole();
            $this->assertRecord($capture);
            $this->assertRegistrationMatches($registration, $capture->payload);
            if (($registration['scope'] ?? null) === 'inheritance') {
                $context = $this->originalDescendantHistoryContext($registration, $capture);
            } else {
                $context = $this->coldOriginalHistoryContext($registration, $capture);
            }
            $ownedModels = $context['owned_model_ids'];
            $originProof = $context['origin_proof'];
            if ($ownedModels !== $capture->payload['model_ids']) {
                throw new LogicException('ORIGINAL_MODEL_ORIGIN_SCOPE_MISMATCH');
            }
            $from = CarbonImmutable::parse($start)->utc(); $until = CarbonImmutable::parse($end)->utc();
            if (! $until->greaterThan($from) || ! $this->epochs->researchIntervalDisjointFromPaper($start, $end)) {
                throw new LogicException('ORIGINAL_HISTORY_RESEARCH_INTERVAL_REQUIRED');
            }
            $planned = array_values(array_filter($registration['design']['validation_windows'],
                fn ($window) => CarbonImmutable::parse($window['start_inclusive'])->equalTo($from)
                    && CarbonImmutable::parse($window['end_exclusive'])->equalTo($until)));
            if (count($planned) !== 1) throw new LogicException('ORIGINAL_HISTORY_EXACT_REGISTERED_WINDOW_REQUIRED');
            foreach ([$originalRunIds, $originalFoldIds] as $ids) {
                if (! array_is_list($ids) || count($ids) > 128 || count(array_unique($ids)) !== count($ids)
                    || count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) !== count($ids)) {
                    throw new LogicException('ORIGINAL_HISTORY_PRODUCT_ROSTER_INVALID');
                }
            }
            // Source, choice and windows are frozen at capture. Reinspection
            // uses that original global prefix plus the owned product prefix;
            // unrelated later work cannot rewrite a prior exam's proof hash.
            $priorRunHigh = (int) $capture->payload['run_high_water'];
            $priorFoldHigh = (int) $capture->payload['fold_high_water'];
            $priorRecordHigh = $capture->payload['capture_record_high_water'] ?? null;
            if (! is_int($priorRecordHigh)) throw new LogicException('ORIGINAL_CAPTURE_HISTORY_PREFIX_UNATTESTED');
            if (($capture->payload['history_run_roster'] ?? null) !== $this->originalBirthRunRoster($priorRunHigh)
                || ($capture->payload['history_fold_roster'] ?? null) !== $this->originalBirthFoldRoster($priorFoldHigh)) {
                throw new LogicException('ORIGINAL_CAPTURE_HISTORY_PREFIX_DRIFT');
            }
            $runHigh = max($priorRunHigh, (int) LabEvaluationRun::whereIn('model_version_id', $ownedModels)->max('id'));
            $experiments = (array) $capture->payload['source_experiment_ids'];
            $foldHigh = max($priorFoldHigh,
                (int) CausalFoldReceipt::whereIn('agent_learning_causal_experiment_id', $experiments)->max('id'));
            $sealed = app(ScopedResearchCertificateService::class)->verifiedIndependentAssessment($certificateId);
            if ($sealed !== null) {
                $matched = [];
                foreach ((array) data_get($sealed, 'original_assessment.original_windows', []) as $proof) {
                    if (! CarbonImmutable::parse($proof['window']['start_inclusive'])->equalTo($from)
                        || ! CarbonImmutable::parse($proof['window']['end_exclusive'])->equalTo($until)) continue;
                    $group = collect($sealed['original_products'])->firstWhere('window_key', $proof['window']['window_key']);
                    $expected = array_values((array) ($group['run_ids'] ?? [])); sort($expected);
                    $actual = $originalRunIds; sort($actual);
                    $prefix = $proof['original_history_prefix'] ?? null;
                    if (! is_array($prefix) || $expected !== $actual || $originalFoldIds !== []
                        || $prefix['certificate_id'] !== $certificateId
                        || $prefix['source_hash'] !== $registration['source_hash'] || $prefix['design_hash'] !== $registration['design_hash']
                        || $prefix['global_prior_run_high_water'] !== $priorRunHigh
                        || $prefix['global_prior_fold_high_water'] !== $priorFoldHigh
                        || ! is_int($prefix['run_high_water']) || $prefix['run_high_water'] < max([$priorRunHigh, ...$actual])
                        || $prefix['run_high_water'] > $runHigh || ! is_int($prefix['fold_high_water'])
                        || $prefix['fold_high_water'] < $priorFoldHigh || $prefix['fold_high_water'] > $foldHigh) {
                        throw new LogicException('ORIGINAL_HISTORY_SEALED_ASSESSMENT_PREFIX_DRIFT');
                    }
                    $matched[] = $prefix;
                }
                if (count($matched) !== 1) throw new LogicException('ORIGINAL_HISTORY_SEALED_WINDOW_PREFIX_REQUIRED');
                $runHigh = $matched[0]['run_high_water']; $foldHigh = $matched[0]['fold_high_water'];
            }
            $runs = LabEvaluationRun::where('id', '<=', $runHigh)->where(fn ($query) => $query
                ->where('id', '<=', $priorRunHigh)->orWhereIn('model_version_id', $ownedModels))
                ->orderBy('id');
            $folds = CausalFoldReceipt::where('id', '<=', $foldHigh)->where(fn ($query) => $query
                ->where('id', '<=', $priorFoldHigh)->orWhereIn('agent_learning_causal_experiment_id', $experiments))
                ->orderBy('id');
            $proofs = []; $foundRuns = []; $foundFolds = [];
            $runDigest = hash_init('sha256'); $foldDigest = hash_init('sha256'); $inputDigest = hash_init('sha256');
            $runCount = 0; $foldCount = 0; $inputCount = 0;
            $appendProof = function (array $proof) use (&$proofs, &$inputCount, $inputDigest): void {
                hash_update($inputDigest, $this->hash($proof)."\n");
                $inputCount++;
                if (count($proofs) < 32) $proofs[] = $proof;
            };
            if (ResearchExposureCaptureRecord::where('record_type', 'request_ingress')
                ->where(fn ($query) => $query->where('id', '<=', $priorRecordHigh)
                    ->orWhere(fn ($owned) => $owned->where('certificate_id', $certificateId)
                        ->where('evaluation_run_id', '<=', $runHigh)))
                ->where(fn ($query) => $query->whereNull('evaluation_run_id')
                    ->orWhereNotExists(fn ($exists) => $exists
                        ->selectRaw('1')->from('lab_evaluation_runs')
                        ->whereColumn('lab_evaluation_runs.id', 'research_exposure_capture_records.evaluation_run_id')))->exists()) {
                throw new LogicException('ORIGINAL_GLOBAL_INGRESS_ORPHANED');
            }
            foreach ($runs->lazyById(32) as $run) {
                hash_update($runDigest, $this->hash([(int) $run->id, $run->run_id])."\n"); $runCount++;
                $records = ResearchExposureCaptureRecord::where('record_type', 'request_ingress')
                    ->where('evaluation_run_id', $run->id)->get();
                if ($records->isEmpty()) throw new LogicException('ORIGINAL_GLOBAL_RUN_INPUT_HISTORY_UNATTESTED');
                $artifacts = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->get();
                if ($artifacts->count() !== 1) throw new LogicException('ORIGINAL_GLOBAL_REQUEST_BYTES_UNATTESTED');
                $artifact = $artifacts->sole();
                $request = app(LabImmutableEvidenceService::class)->readArtifactPayload($artifact);
                if (! is_array($request) || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2') {
                    throw new LogicException('ORIGINAL_GLOBAL_REQUEST_BYTES_UNATTESTED');
                }
                foreach ($records as $record) {
                    $this->assertRecord($record);
                    $ingress = $record->payload;
                    $parent = ResearchExposureCaptureRecord::findOrFail($ingress['capture_record_id']);
                    $this->assertRecord($parent);
                    if ($ingress['run_id'] !== $run->run_id || $ingress['request_hash'] !== $run->request_hash
                        || $ingress['model_version_id'] !== (int) $run->model_version_id
                        || $ingress['request_artifact_id'] !== (int) $artifact->id
                        || $ingress['request_artifact_hash'] !== $artifact->sha256
                        || $ingress['request_payload_hash'] !== data_get($artifact->metadata, 'raw_payload_hash')
                        || $ingress['request_payload_hash'] !== data_get($run->request_meta, 'payload_hash')
                        // The artifact is the original safe manifest, not raw
                        // wire JSON. Its empty-candle reference has another hash.
                        || data_get($artifact->metadata, 'request_hash') !== $run->request_hash
                        || ! app(LabImmutableEvidenceService::class)->verifiedModelRuntimeIdentity($run)
                        || ! $run->started_at || CarbonImmutable::parse($parent->payload['capture_started_at'])->greaterThan($run->started_at)
                        || $run->code_hash !== $parent->payload['evaluator_release_hash']) {
                        throw new LogicException('ORIGINAL_GLOBAL_RUN_INGRESS_OWNER_DRIFT');
                    }
                    if (($request['symbol'] ?? null) !== $parent->payload['symbol']) {
                        throw new LogicException('ORIGINAL_GLOBAL_PHYSICAL_MARKET_IDENTITY_UNATTESTED');
                    }
                    $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals(
                        $request, (int) $parent->payload['policy']['holding_fence_seconds']);
                    $this->assertRequestHolding($request, $parent->payload['policy']);
                    if ($this->hash($inventory) !== $this->hash($ingress['inventory'])) {
                        throw new LogicException('ORIGINAL_GLOBAL_REQUEST_SOURCE_BYTES_DRIFT');
                    }
                    $isOriginal = in_array((int) $run->id, $originalRunIds, true)
                        && in_array((int) $run->model_version_id, $ownedModels, true)
                        && (int) $record->certificate_id === $certificateId && $run->phase === 'full_validation';
                    if ($isOriginal) {
                        if (! in_array($run->mode, ['full', 'replay'], true)) {
                            throw new LogicException('ORIGINAL_HISTORY_EXACT_VALIDATION_PRODUCT_REQUIRED');
                        }
                        $this->assertHistoricalOriginalWindow($registration, $request, $inventory, $from, $until);
                        $foundRuns[] = (int) $run->id;
                    }
                    $this->assertOriginalHistoryDisjoint($inventory, $from, $until, $isOriginal);
                    $appendProof(['run_id' => (int) $run->id, 'record_id' => (int) $record->id,
                        'request_artifact_hash' => $artifact->sha256, 'inventory_hash' => $this->hash($inventory)]);
                }
            }
            foreach ($folds->lazyById(32) as $fold) {
                hash_update($foldDigest, $this->hash([(int) $fold->id, (int) $fold->attempt_count])."\n"); $foldCount++;
                $records = ResearchExposureCaptureRecord::where('record_type', 'causal_fold_ingress')
                    ->where('payload->causal_fold_receipt_id', (int) $fold->id)->orderBy('id')->get();
                if ($fold->status === 'planned' && (int) $fold->attempt_count === 0 && $records->isEmpty()) continue;
                if ($records->count() !== (int) $fold->attempt_count || $records->isEmpty()) {
                    throw new LogicException('ORIGINAL_GLOBAL_FOLD_ATTEMPT_HISTORY_UNATTESTED');
                }
                foreach ($records as $record) {
                    $this->assertRecord($record); $ingress = $record->payload;
                    $parent = ResearchExposureCaptureRecord::findOrFail($ingress['capture_record_id']);
                    $this->assertRecord($parent);
                    if ($this->hash($ingress['request']) !== $ingress['request_hash']
                        || $ingress['experiment_id'] !== (int) $fold->agent_learning_causal_experiment_id
                        || $ingress['fold_index'] !== (int) $fold->fold_index
                        || $ingress['attempt_count'] < 1 || $ingress['attempt_count'] > (int) $fold->attempt_count
                        || (($fold->status === 'completed') && ($this->hash((array) $fold->request_payload) !== $ingress['request_hash']
                            || $fold->request_hash !== $ingress['request_hash']))
                        || ($ingress['captured_before_evaluator_delivery'] ?? false) !== true) {
                        throw new LogicException('ORIGINAL_GLOBAL_FOLD_INGRESS_OWNER_DRIFT');
                    }
                    if (($ingress['request']['symbol'] ?? null) !== $parent->payload['symbol']) {
                        throw new LogicException('ORIGINAL_GLOBAL_PHYSICAL_MARKET_IDENTITY_UNATTESTED');
                    }
                    $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals(
                        $ingress['request'], (int) $parent->payload['policy']['holding_fence_seconds']);
                    $this->assertRequestHolding($ingress['request'], $parent->payload['policy']);
                    if ($this->hash($inventory) !== $this->hash($ingress['inventory'])) {
                        throw new LogicException('ORIGINAL_GLOBAL_FOLD_SOURCE_BYTES_DRIFT');
                    }
                    $isOriginal = in_array((int) $fold->id, $originalFoldIds, true)
                        && (int) $record->certificate_id === $certificateId;
                    if ($isOriginal) {
                        if ((int) $fold->attempt_count !== 1
                            || ! in_array((int) $fold->agent_learning_causal_experiment_id, $experiments, true)) {
                            throw new LogicException('ORIGINAL_HISTORY_EXACT_VALIDATION_PRODUCT_REQUIRED');
                        }
                        $this->assertHistoricalOriginalWindow($registration, $ingress['request'], $inventory, $from, $until);
                        $foundFolds[] = (int) $fold->id;
                    }
                    $this->assertOriginalHistoryDisjoint($inventory, $from, $until, $isOriginal);
                    $appendProof(['fold_id' => (int) $fold->id, 'record_id' => (int) $record->id,
                        'attempt' => $ingress['attempt_count'], 'inventory_hash' => $this->hash($inventory)]);
                }
            }
            if (array_diff($originalRunIds, $foundRuns) !== [] || array_diff($originalFoldIds, $foundFolds) !== []) {
                throw new LogicException('ORIGINAL_HISTORY_PRODUCT_ROSTER_NOT_ORIGINAL');
            }
            if (ResearchExposureCaptureRecord::where('record_type', 'causal_fold_ingress')
                ->where(fn ($query) => $query->where('id', '<=', $priorRecordHigh)
                    ->orWhere(fn ($owned) => $owned->where('certificate_id', $certificateId)
                        ->where('payload->causal_fold_receipt_id', '<=', $foldHigh)))
                ->whereNotExists(fn ($exists) => $exists->selectRaw('1')->from('causal_fold_receipts')
                    ->whereColumn('causal_fold_receipts.id', 'research_exposure_capture_records.payload->causal_fold_receipt_id'))->exists()) {
                throw new LogicException('ORIGINAL_GLOBAL_FOLD_INGRESS_ORPHANED');
            }
            if (DB::table('specialist_council_data_uses as uses')
                ->join('specialist_council_data_events as events', 'events.id', '=', 'uses.event_id')
                ->whereIn('uses.use', ['training', 'selection'])->where('events.symbol', $capture->payload['symbol'])
                ->where('events.event_start', '<', $until)->where('events.event_end', '>', $from)->exists()) {
                throw new LogicException('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE');
            }
            $identity = ['protocol' => self::ORIGINAL_ORIGIN_PROTOCOL, 'certificate_id' => $certificateId,
                'origin_proof' => $originProof, 'ancestry_proof' => $context['ancestry_proof'] ?? null,
                'source_hash' => $registration['source_hash'], 'design_hash' => $registration['design_hash'],
                'candidate_start' => $from->toIso8601String(), 'candidate_end' => $until->toIso8601String(),
                'run_high_water' => $runHigh, 'fold_high_water' => $foldHigh,
                'global_prior_run_high_water' => $priorRunHigh, 'global_prior_fold_high_water' => $priorFoldHigh,
                'capture_artifact_high_water' => $capture->payload['artifact_high_water'],
                'capture_record_high_water' => $priorRecordHigh,
                'run_roster_hash' => hash_final($runDigest), 'run_roster_count' => $runCount,
                'fold_roster_hash' => hash_final($foldDigest), 'fold_roster_count' => $foldCount,
                'original_input_proofs' => $proofs, 'original_input_proof_count' => $inputCount,
                'original_input_proofs_hash' => hash_final($inputDigest),
                'proof_details_limit' => 32, 'all_prefix_rows_revalidated' => true,
                'candidate_unused_demonstrated' => true, 'historical_training_selection_inventory_complete' => true,
                'completeness_scope' => 'original_programme_choice_and_input_prefix_frozen_before_validation',
                'absence_of_legacy_records_proves_unused' => false, 'global_archive_unused' => false,
                'independent_evidence' => false, 'authority' => false, 'ready' => true,
                'reason_code' => 'FINITE_ORIGINAL_HISTORY_AND_INPUT_BYTES_ATTESTED'];
            return [...$identity, 'attestation_hash' => $this->hash($identity)];
        } catch (Throwable $error) {
            return ['protocol' => self::ORIGINAL_ORIGIN_PROTOCOL, 'certificate_id' => $certificateId,
                'ready' => false, 'candidate_unused_demonstrated' => false,
                'historical_training_selection_inventory_complete' => false,
                'reason_code' => $error instanceof LogicException ? $error->getMessage() : 'ORIGINAL_HISTORY_OWNER_UNAVAILABLE',
                'independent_evidence' => false, 'authority' => false];
        }
    }

    private function coldOriginalHistoryContext(array $registration, ResearchExposureCaptureRecord $capture): array
    {
        $generationId = (int) data_get($registration, 'source_snapshot.lab_generation_id');
        $identity = $this->originalFiniteOriginIdentity($generationId, (int) $registration['source_id']);
        $origin = ResearchOriginalModelOrigin::findOrFail($identity['origin_id']);
        $payload = $origin->payload;
        $experiment = AgentLearningCausalExperiment::findOrFail($payload['experiment_id']);
        if (($payload['protocol'] ?? null) !== self::ORIGINAL_ORIGIN_PROTOCOL
            || $payload['experiment_id'] !== $registration['source_id']
            || $this->hash($payload['models']) !== $this->hash($this->originalOriginModels($generationId))
            || $this->hash($payload['experiment']) !== $this->hash($this->originalOriginExperiment($experiment))
            || $payload['schema_hash'] !== $this->hash(app(StrategyParameterSchemaService::class)->schema($experiment->strategy_family))
            || $payload['defaults_hash'] !== $this->hash(app(StrategyParameterSchemaService::class)->defaults($experiment->strategy_family))
            || $payload['prior_run_roster'] !== $this->originalBirthRunRoster($payload['run_high_water'])
            || $payload['prior_fold_roster'] !== $this->originalBirthFoldRoster($payload['fold_high_water'])
            || CarbonImmutable::parse($origin->recorded_at)->greaterThan($capture->recorded_at)
            || $this->modelExperiments($capture->payload['model_ids']) !== [(int) $experiment->id]
            || $payload['external_training_inputs'] !== [] || $payload['parent_inputs'] !== []) {
            throw new LogicException('ORIGINAL_MODEL_ORIGIN_OR_SELECTION_ROSTER_DRIFT');
        }
        if ($payload['evaluator_release_hash'] !== app(LabImmutableEvidenceService::class)->codeHash()) {
            throw new LogicException('ORIGINAL_MODEL_ORIGIN_EVALUATOR_RELEASE_DRIFT');
        }
        $ownedModels = array_column($payload['models'], 'model_version_id'); sort($ownedModels);
        return ['owned_model_ids' => $ownedModels, 'origin_proof' => ['origin_id' => (int) $origin->id,
            'origin_payload_hash' => $origin->payload_hash, 'protocol' => self::ORIGINAL_ORIGIN_PROTOCOL],
            'ancestry_proof' => null];
    }

    private function originalOriginModels(int $generationId): array
    {
        $agents = LabAgent::where('lab_generation_id', $generationId)->orderBy('id')->get();
        if ($agents->count() !== 3) throw new LogicException('ORIGINAL_ORIGIN_EXACT_THREE_AGENTS_REQUIRED');
        $result = [];
        foreach ($agents as $agent) {
            if ($agent->parent_a_model_version_id || $agent->parent_b_model_version_id
                || $agent->parentLinks()->exists() || $agent->parentSelectionDecisions()->exists()) {
                throw new LogicException('ORIGINAL_ORIGIN_ANCESTRAL_SELECTION_FORBIDDEN');
            }
            $model = $agent->modelVersion;
            $result[] = ['agent_id' => (int) $agent->id, 'model_version_id' => (int) $model->id,
                'strategy' => $model->strategy, 'version' => $model->version,
                'parameter_hash' => $this->hash((array) $model->parameters),
                // This owner writes only the lifecycle/status read projection;
                // no learned programme, policy or parameter field is exempted.
                'metadata_hash' => $this->hash(array_diff_key((array) $model->metadata, ['lifecycle_sync' => true])),
                'runtime_hash' => $this->hash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model)),
                'parameter_diff' => (array) $agent->parameter_diff, 'origin' => $agent->origin];
        }
        return $result;
    }

    private function originalOriginExperiment(AgentLearningCausalExperiment $experiment): array
    {
        $fields = ['id', 'experiment_key',
            'lab_generation_id', 'symbol', 'timeframe', 'strategy_family', 'target', 'gene_key',
            'source_lesson_id', 'guided_agent_id', 'blinded_agent_id', 'control_agent_id'];
        $choice = ['experiment_kind', 'selection_owner', 'memory_selector_evidence', 'training_inputs', 'parent_inputs', 'recipe'];
        $outcomes = ['outcomes', 'fold_execution', 'confirmation_evidence_protocol', 'confirmation_protocol',
            'component_effect', 'selector_effect', 'protocol_epoch', 'capability_lattice', 'confirmation_blockers',
            'economic_authority_blockers', 'causal_power', 'absolute_viability', 'confirmed_guided_mentor', 'promotion_evidence'];
        $evidence = (array) $experiment->evidence;
        if (array_diff(array_keys($evidence), [...$choice, ...$outcomes]) !== []) {
            throw new LogicException('ORIGINAL_ORIGIN_UNRECOGNIZED_SELECTION_INPUT');
        }
        $result = [];
        foreach ($fields as $field) $result[$field] = $experiment->getAttribute($field);
        return [...$result, 'original_selection_evidence' => array_intersect_key($evidence, array_flip($choice))];
    }

    private function originalBirthRunRoster(int $highWater): array
    {
        $digest = hash_init('sha256'); $count = 0;
        foreach (LabEvaluationRun::where('id', '<=', $highWater)->select(['id', 'run_id', 'model_version_id', 'lab_agent_id'])
            ->orderBy('id')->lazyById(32) as $run) {
            hash_update($digest, $this->hash([(int) $run->id, $run->run_id, $run->model_version_id, $run->lab_agent_id])."\n"); $count++;
        }
        return ['count' => $count, 'sha256' => hash_final($digest)];
    }

    private function originalBirthFoldRoster(int $highWater): array
    {
        $digest = hash_init('sha256'); $count = 0;
        foreach (CausalFoldReceipt::where('id', '<=', $highWater)->select(['id', 'agent_learning_causal_experiment_id', 'fold_index'])
            ->orderBy('id')->lazyById(32) as $fold) {
            hash_update($digest, $this->hash([(int) $fold->id, (int) $fold->agent_learning_causal_experiment_id,
                (int) $fold->fold_index])."\n"); $count++;
        }
        return ['count' => $count, 'sha256' => hash_final($digest)];
    }

    private function assertHistoricalOriginalWindow(array $registration, array $request, array $inventory,
        CarbonImmutable $from, CarbonImmutable $until): void
    {
        $authorizations = array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
            fn ($row) => is_array($row) && ($row['purpose'] ?? null) === 'instrument_independent_validation'
                && ($row['dataset_sha256'] ?? null) === ($request['replay_dataset_hash'] ?? null)
                && CarbonImmutable::parse((string) ($row['start_inclusive'] ?? ''))->equalTo($from)
                && CarbonImmutable::parse((string) ($row['end_exclusive'] ?? ''))->equalTo($until));
        if (count($authorizations) !== 1 || $inventory['dataset_hash'] !== ($request['replay_dataset_hash'] ?? null)) {
            throw new LogicException('ORIGINAL_HISTORY_EXACT_AUTHORIZED_WINDOW_REQUIRED');
        }
        foreach ($inventory['intervals'] as $interval) {
            if (CarbonImmutable::parse($interval['start_inclusive'])->lessThan($from)
                || CarbonImmutable::parse($interval['end_exclusive'])->greaterThan($until)) {
                throw new LogicException('ORIGINAL_HISTORY_VALIDATION_CONTEXT_OR_HOLDING_OUTSIDE_WINDOW');
            }
        }
    }

    private function assertOriginalHistoryDisjoint(array $inventory, CarbonImmutable $from,
        CarbonImmutable $until, bool $original): void
    {
        foreach ($inventory['intervals'] as $interval) {
            // A request label is not independent physical market identity.
            // Never silently exclude the same bytes under a different symbol.
            if (($interval['symbol'] ?? null) !== 'XAUUSD') {
                throw new LogicException('ORIGINAL_GLOBAL_PHYSICAL_MARKET_IDENTITY_UNATTESTED');
            }
            if (! $original && CarbonImmutable::parse($interval['start_inclusive'])->lessThan($until)
                && CarbonImmutable::parse($interval['end_exclusive'])->greaterThan($from)) {
                throw new LogicException('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE');
            }
        }
    }
}
