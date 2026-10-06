<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

/** A prospective, original-owner reservation. It grants no scientific result. */
class SpecialistCouncilPanelReservationService
{
    public const PROTOCOL = 'specialist_council_panel_reservation_v1';
    public const COHORT_PROTOCOL = 'specialist_council_authorized_panel_v1';
    public const BUNDLE_PROTOCOL = 'authorized_original_council_window_bundle_v1';

    public function __construct(
        private ResearchPaperEpochContractService $epochs,
        private InstrumentResearchWindowService $windows,
        private LabImmutableEvidenceService $evidence,
        private ResearchReleaseSealService $releases,
        private SpecialistCouncilContractService $contracts,
    ) {}

    /** Only server window IDs, never caller-shaped generation/arm/result references. */
    public function register(ResearchExperimentWorkItem $work, array $input, ?string $actor,
        SpecialistCouncilVersion $parent, array $original): array
    {
        if (($input['protocol'] ?? null) !== self::PROTOCOL
            || array_diff(array_keys($input), ['protocol', 'authorization_ids', 'creator_id', 'evaluator_id', 'research_question']) !== []
            || $work->work_type !== 'specialist_council_independent_validation'
            || ! is_array($input['authorization_ids'] ?? null) || ! array_is_list($input['authorization_ids'])
            || count($input['authorization_ids']) !== SpecialistCouncilIndependentPanelService::MAX_WINDOWS
            || count(array_unique($input['authorization_ids'], SORT_STRING)) !== count($input['authorization_ids'])) {
            throw new LogicException('COUNCIL_PANEL_SERVER_WINDOW_RESERVATION_REQUIRED');
        }
        foreach (['creator_id', 'evaluator_id'] as $field) {
            if (! is_string($input[$field] ?? null) || preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $input[$field]) !== 1) {
                throw new LogicException('COUNCIL_PANEL_ATTRIBUTABLE_ACTOR_REQUIRED');
            }
        }
        if ($input['creator_id'] === $input['evaluator_id'] || $parent->creator_id === $input['evaluator_id']
            || ! is_string($input['research_question'] ?? null) || trim($input['research_question']) !== $input['research_question']
            || $input['research_question'] === '' || strlen($input['research_question']) > 500) {
            throw new LogicException('COUNCIL_PANEL_INDEPENDENT_EVALUATOR_AND_QUESTION_REQUIRED');
        }
        if (data_get($work->payload, 'pending_panel_intent') !== null
            || data_get($work->payload, 'followup_resolution') !== null
            || in_array($work->status, ['leased', 'settled'], true)) {
            throw new LogicException('COUNCIL_PANEL_PREREGISTRATION_IS_IMMUTABLE');
        }
        if (! $this->contracts->manifestValid($parent->manifest) || $parent->assessment_hash === null
            || $original === [] || ($original['manifest_hash'] ?? null) !== $parent->manifest_hash) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_ASSESSED_SOURCE_REQUIRED');
        }
        $this->assertOriginalComparisonPolicy($work, $parent, $original);
        $records = [];
        foreach ($input['authorization_ids'] as $id) {
            if (! is_string($id) || $id === '') throw new LogicException('COUNCIL_PANEL_SERVER_WINDOW_RESERVATION_REQUIRED');
            $matches = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
                fn ($m): bool => is_array($m) && ($m['authorization_id'] ?? null) === $id));
            if (count($matches) !== 1) throw new LogicException('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW');
            $registry = $matches[0];
            $window = $this->windows->seal($id, (string) ($registry['dataset_sha256'] ?? ''));
            if ($window === null) throw new LogicException('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW');
            $manifest = (array) ($registry['mtf_bundle_manifest'] ?? []);
            $this->assertWindowBundleContract($manifest);
            $proof = $this->windows->verifySealedReplayWindow($window, $manifest);
            $records[] = ['window' => $window, 'mtf_bundle_manifest' => $manifest, 'transport_proof' => $proof];
        }
        usort($records, fn ($a, $b): int => strcmp($a['window']['start_inclusive'], $b['window']['start_inclusive']));
        $hashes = [];
        foreach ($records as $i => $record) {
            $window = $record['window'];
            if (isset($hashes[$window['dataset_sha256']]) || ($i > 0
                && $records[$i - 1]['window']['end_exclusive'] > $window['start_inclusive'])) {
                throw new LogicException('COUNCIL_PANEL_WINDOWS_MUST_BE_ORIGINAL_DISJOINT_EVENTS');
            }
            $hashes[$window['dataset_sha256']] = true;
            foreach (array_unique(array_merge(...array_column(array_column($parent->manifest['members'], 'scope'), 'symbols'))) as $symbol) {
                if (app(SpecialistCouncilDataUseService::class)->intervalExposed($parent, $symbol,
                    $window['start_inclusive'], $window['end_exclusive'])) {
                    throw new LogicException('EVALUATION_EVENTS_ALREADY_USED_FOR_TRAINING_OR_SELECTION');
                }
            }
        }
        $sourceArms = array_values((array) ($original['arms'] ?? []));
        $source = [];
        foreach (['candidate', 'champion', 'solo'] as $kind) {
            $matches = array_values(array_filter($sourceArms, fn ($arm): bool => ($arm['kind'] ?? null) === $kind));
            if ($matches === []) throw new LogicException('COUNCIL_PANEL_ORIGINAL_COMPARATORS_REQUIRED');
            $model = ModelVersion::findOrFail($matches[0]['model_version_id']);
            if (($matches[0]['model_hash'] ?? null) !== $this->contracts->modelHash($model)) {
                throw new LogicException('COUNCIL_PANEL_ORIGINAL_ARM_MODEL_DRIFT');
            }
            $source[$kind] = $this->sourceModel($model);
        }
        $roots = [];
        foreach (['candidate', 'champion', 'solo', 'retention'] as $kind) {
            $roots[] = ['arm_key' => $kind, 'kind' => $kind, ...$source[$kind === 'retention' ? 'candidate' : $kind]];
        }
        foreach ($parent->manifest['evaluation_policy']['required_ablations'] as $removed) {
            $roots[] = ['arm_key' => 'ablation:'.$removed, 'kind' => 'ablation', 'removed_id' => $removed, ...$source['candidate']];
        }
        if (count($roots) < 5 || count($roots) > 12
            || count($roots) * count($records) > SpecialistCouncilIndependentPanelService::MAX_ARMS) {
            throw new LogicException('COUNCIL_PANEL_ARM_BUDGET_EXCEEDED');
        }
        $body = ['protocol' => SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL,
            'executor_protocol' => SpecialistCouncilIndependentPanelService::PROTOCOL,
            'reservation_protocol' => self::PROTOCOL, 'work_item_id' => (int) $work->id, 'work_key' => $work->work_key,
            'work_type' => $work->work_type, 'source_receipt_id' => $work->research_experiment_receipt_id,
            'source_version_id' => (int) $parent->id, 'source_manifest_hash' => $parent->manifest_hash,
            'source_assessment_hash' => $parent->assessment_hash, 'source_plan_hash' => $this->epochs->parameterHash($original),
            'input_hash' => $this->epochs->parameterHash($input), 'manifest_template' => $parent->manifest,
            'original_plan' => $original, 'windows' => $records, 'arm_roots' => $roots,
            'creator_id' => $input['creator_id'], 'evaluator_id' => $input['evaluator_id'],
            'research_question' => $input['research_question'], 'registered_by' => $actor ?? $input['creator_id'],
            'current_source_hash' => $this->evidence->codeHash(), 'current_python_source_hash' => $this->releases->pythonHash(),
            'registered_at' => now()->utc()->toIso8601String(), 'authority' => 'research_only', 'max_experiments' => 1,
            'max_window_cohorts' => 3, 'max_lease_deliveries' => count($roots) * count($records) + count($records) + 2 + 8,
            'independent_evidence_claimed' => false, 'promotion_evidence' => false];
        // The model's JSON cast persists 1.0 as 1. Seal the exact persisted
        // representation, not an ephemeral PHP float representation.
        $body = $this->persistedJsonValue($body);
        $body['resolution_hash'] = $this->epochs->parameterHash($body);
        $body['reservation_hash'] = $body['resolution_hash'];
        $body['server_seal'] = $this->seal($body);
        $work->update(['payload' => [...(array) $work->payload, 'executable' => false,
            'pending_panel_intent' => $body, 'followup_resolution' => $body]]);
        return $this->inspect($work->fresh(), $parent);
    }

    /** Pure original-owner verification; no stored flag substitutes for any proof. */
    public function inspect(ResearchExperimentWorkItem $work, SpecialistCouncilVersion $parent): array
    {
        $body = $this->body($work);
        if ((int) $work->attempts >= $body['max_lease_deliveries']) {
            throw new LogicException('COUNCIL_PANEL_OPERATIONAL_DELIVERY_BUDGET_EXHAUSTED');
        }
        if ($body['source_version_id'] !== (int) $parent->id || $body['source_manifest_hash'] !== $parent->manifest_hash
            || $body['source_assessment_hash'] !== $parent->assessment_hash || ! $this->contracts->manifestValid($parent->manifest)) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_PARENT_CHANGED');
        }
        $this->assertOriginalComparisonPolicy($work, $parent, $body['original_plan']);
        foreach ($body['windows'] as $record) {
            $this->assertWindowBundleContract($record['mtf_bundle_manifest']);
            $proof = $this->windows->verifySealedReplayWindow($record['window'], $record['mtf_bundle_manifest']);
            if ($this->epochs->parameterHash($this->persistedJsonValue($proof)) !== $this->epochs->parameterHash($record['transport_proof'])) {
                throw new LogicException('COUNCIL_PANEL_AUTHORIZED_STREAMS_CHANGED');
            }
            foreach (array_unique(array_merge(...array_column(array_column($parent->manifest['members'], 'scope'), 'symbols'))) as $symbol) {
                if (app(SpecialistCouncilDataUseService::class)->intervalExposed($parent, $symbol,
                    $record['window']['start_inclusive'], $record['window']['end_exclusive'])) {
                    throw new LogicException('EVALUATION_EVENTS_ALREADY_USED_FOR_TRAINING_OR_SELECTION');
                }
            }
        }
        foreach ($body['arm_roots'] as $root) {
            $model = ModelVersion::findOrFail($root['source_model_version_id']);
            if ($this->epochs->parameterHash($this->sourceModel($model)) !== $this->epochs->parameterHash(
                array_diff_key($root, array_flip(['arm_key', 'kind', 'removed_id'])))) {
                throw new LogicException('COUNCIL_PANEL_ORIGINAL_ARM_MODEL_DRIFT');
            }
        }
        return ['protocol' => self::PROTOCOL, 'status' => 'ready', 'executable' => true,
            'executor_protocol' => SpecialistCouncilIndependentPanelService::PROTOCOL,
            'resolution_hash' => $body['resolution_hash'], 'reservation' => $body,
            'paper_authority_granted' => false, 'promotion_evidence' => false];
    }

    /** Called before AND inside the canonical constructor's lock. */
    public function assertConstructorIntent(array $intent): array
    {
        $work = ResearchExperimentWorkItem::findOrFail($intent['work_item_id'] ?? 0);
        $this->assertLease($work);
        $body = $this->body($work);
        $parent = SpecialistCouncilVersion::findOrFail($body['source_version_id']);
        $this->inspect($work, $parent);
        $index = (int) ($intent['window_ordinal'] ?? 0) - 1;
        $record = $body['windows'][$index] ?? null;
        if ($record === null) throw new LogicException('COUNCIL_PANEL_WINDOW_ORDINAL_INVALID');
        $expected = $this->constructorIntent($work, $body, $index);
        if (! $this->evidence->equivalentJsonValue(array_diff_key($intent, ['intent_hash' => true]), $expected)) {
            throw new LogicException('COUNCIL_PANEL_CANONICAL_CONSTRUCTOR_INTENT_DRIFT');
        }
        $owned = LabGeneration::where('trigger_context->specialist_council_authorized_panel->work_item_id', $work->id)->get();
        if ($owned->count() > 3) throw new LogicException('COUNCIL_PANEL_COHORT_BUDGET_EXCEEDED');
        foreach ($owned as $cohort) {
            $marker = (array) data_get($cohort->trigger_context, 'specialist_council_authorized_panel', []);
            if (($marker['reservation_hash'] ?? null) !== $body['reservation_hash']
                || LabEvaluationRun::where('lab_generation_id', $cohort->id)->exists()) {
                throw new LogicException('COUNCIL_PANEL_ALL_COHORTS_MUST_PRECEDE_OUTCOMES');
            }
        }
        return $expected;
    }

    public function constructorIntent(ResearchExperimentWorkItem $work, array $body, int $index): array
    {
        $record = $body['windows'][$index];
        return ['protocol' => LabPopulationService::AUTHORIZED_COUNCIL_PANEL_INTENT_PROTOCOL,
            'symbol' => $work->symbol, 'storage_timeframe' => $work->timeframe,
            'work_item_id' => (int) $work->id, 'work_key' => $work->work_key,
            'reservation_hash' => $body['reservation_hash'], 'window_key' => $record['window']['window_key'],
            'window_ordinal' => $index + 1, 'source_version_id' => $body['source_version_id'], 'panel_version_id' => null,
            'evaluator_id' => $body['evaluator_id'], 'research_question' => $body['research_question'],
            'current_source_hash' => $body['current_source_hash'], 'current_python_source_hash' => $body['current_python_source_hash'],
            'authorized_window' => $record['window'], 'arm_roots' => $body['arm_roots'],
            'authority' => 'research_only', 'promotion_evidence' => false, 'independent_evidence_claimed' => false];
    }

    /** One bounded original operation per arbiter delivery, never another scheduler. */
    public function execute(ResearchExperimentWorkItem $item): array
    {
        $lock = Cache::lock('specialist-council-authorized-panel:'.$item->id, LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS);
        if (! $lock->get()) return $this->defer($item, 'COUNCIL_PANEL_OWNER_BUSY', true);
        try {
            $this->assertCurrentLease($item);
            $body = $this->body($item->fresh());
            $this->inspect($item->fresh(), SpecialistCouncilVersion::findOrFail($body['source_version_id']));
            $cohorts = LabGeneration::where('trigger_context->specialist_council_authorized_panel->work_item_id', $item->id)
                ->orderBy('id')->get();
            if ($cohorts->count() > 3) throw new LogicException('COUNCIL_PANEL_COHORT_BUDGET_EXCEEDED');
            foreach ($body['windows'] as $index => $record) {
                $cohort = $cohorts->first(fn ($g): bool => data_get($g->trigger_context,
                    'specialist_council_authorized_panel.window_key') === $record['window']['window_key']);
                if ($cohort === null) {
                    $this->assertCurrentLease($item);
                    $population = app(LabPopulationService::class);
                    $cohort = $population->build((string) $item->symbol, 'specialist_council_independent_panel', false,
                        (string) $item->timeframe, [], false, false, count($body['arm_roots']), null, false, null, null,
                        $this->constructorIntent($item, $body, $index));
                    if (! $cohort) return $this->defer($item,
                        (string) ($population->lastBuildOutcome()['reason_code'] ?? 'COUNCIL_PANEL_CANONICAL_RESERVATION_DEFERRED'), false);
                    $this->checkpoint($item, ['phase' => 'reserving', 'last_reserved_generation_id' => $cohort->id]);
                    return $this->defer($item, 'COUNCIL_PANEL_NEXT_PREREGISTERED_RESERVATION', true);
                }
                if (LabPopulationService::constructionIncomplete($cohort)) {
                    app(LabPopulationService::class)->continueInterruptedConstruction((int) $cohort->id, count($body['arm_roots']));
                    $this->assertCurrentLease($item);
                    return $this->defer($item, 'COUNCIL_PANEL_ORIGINAL_CONSTRUCTION_CONTINUATION', true);
                }
            }
            $alreadyPrepared = is_array(data_get($item->fresh()->result, 'panel_preparation'));
            $prepared = $this->prepareAll($item, $body);
            $this->assertCurrentLease($item);
            if (! $alreadyPrepared) {
                // Global original sealing is its own bounded delivery. The
                // HTTP arm needs a fresh genuine lease, not the preparation
                // lease's remaining time or an implicit lease renewal.
                $this->checkpoint($item, ['phase' => 'original_preparation_sealed']);
                return $this->defer($item, 'COUNCIL_PANEL_ORIGINAL_PREPARATION_SEALED', true);
            }
            foreach ($prepared['units'] as $unit) {
                $cohort = LabGeneration::findOrFail($unit['generation_id']);
                $unit = array_diff_key($unit, ['generation_id' => true]);
                $result = app(SpecialistCouncilAuthorizedArmExecutionService::class)->execute(
                    $cohort, $unit, $item, 'research_loop_arbiter', (string) $item->lease_token, (int) $item->fence_version);
                if (($result['status'] ?? null) === 'blocked') return $this->defer($item,
                    (string) ($result['reason'] ?? 'COUNCIL_PANEL_ORIGINAL_UNIT_DEPENDENCY'), false);
                if (($result['already_terminal'] ?? false) !== true) {
                    return $this->defer($item, 'COUNCIL_PANEL_NEXT_ORIGINAL_ARM_UNIT', true);
                }
            }
            $version = SpecialistCouncilVersion::findOrFail($prepared['panel_version_id']);
            $runIds = LabEvaluationRun::whereIn('lab_generation_id', $cohorts->pluck('id'))
                ->where('phase', 'full_validation')->orderBy('id')->pluck('run_id')->all();
            $assessment = app(SpecialistCouncilLifecycleService::class)->evaluateOriginalRuns($version, $body['evaluator_id'], $runIds);
            $terminal = $this->canonicalTerminalProjection($item, $cohorts);
            $this->checkpoint($item, ['phase' => 'canonical_terminal_projection', 'panel_terminal_projection' => $terminal]);
            if (($terminal['complete'] ?? false) !== true) {
                return $this->defer($item, 'COUNCIL_PANEL_CANONICAL_TERMINAL_BOUNDARY_PENDING', true);
            }
            $this->assertCurrentLease($item);
            $result = [...(array) $item->fresh()->result,
                'protocol' => self::PROTOCOL, 'status' => 'original_panel_settled',
                'panel_version_id' => $version->id, 'assessment_hash' => $version->fresh()->assessment_hash,
                'assessment' => $assessment, 'generation_ids' => $cohorts->pluck('id')->all(),
                'reservation_hash' => $body['reservation_hash'], 'promotion_evidence' => false];
            if (! app(ResearchExperimentConversionKernelService::class)->complete($item, $result)) {
                throw new LogicException('COUNCIL_PANEL_COMPLETION_FENCE_REJECTED');
            }
            return $result;
        } catch (Throwable $error) {
            return $this->defer($item, $error->getMessage(), false);
        } finally {
            $lock->release();
        }
    }

    /** A sealed scientific exam does not substitute for canonical operational closure. */
    private function canonicalTerminalProjection(ResearchExperimentWorkItem $item, iterable $cohorts): array
    {
        $receipts = [];
        foreach ($cohorts as $cohort) {
            $this->assertCurrentLease($item);
            $boundary = app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($cohort);
            $current = $cohort->fresh();
            // A resumed delivery may see a cohort already closed by an earlier
            // real arm. Re-read its durable status/time; never trust only the
            // closer's boolean or discard a genuine pending boundary.
            $verified = $current !== null && $current->completed_at !== null
                && in_array((string) $current->status, ['completed', 'technical_quarantine'], true);
            $receipts[(string) $cohort->id] = [...$boundary, 'canonical_terminal_verified' => $verified,
                'status' => $current?->status, 'completed_at' => $current?->completed_at?->utc()->toIso8601String()];
        }
        return ['protocol' => 'authorized_original_council_terminal_projection_v1',
            'complete' => count($receipts) === 3
                && count(array_filter($receipts, fn (array $receipt): bool => ! $receipt['canonical_terminal_verified'])) === 0,
            'generation_receipts' => $receipts, 'promotion_evidence' => false];
    }

    /** Seal every cohort, carrier, original arm and window before the first HTTP. */
    private function prepareAll(ResearchExperimentWorkItem $item, array $body): array
    {
        return DB::transaction(function () use ($item, $body): array {
            $work = ResearchExperimentWorkItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentLease($item, $work);
            $cohorts = LabGeneration::where('trigger_context->specialist_council_authorized_panel->work_item_id', $item->id)
                ->orderBy('id')->lockForUpdate()->get();
            $saved = data_get($work->result, 'panel_preparation');
            if ($saved !== null) {
                if (($saved['reservation_hash'] ?? null) !== $body['reservation_hash']) {
                    throw new LogicException('COUNCIL_PANEL_PREPARATION_CHECKPOINT_DRIFT');
                }
                foreach ($saved['units'] as $unit) $this->assertExecutionUnit($work, LabGeneration::findOrFail($unit['generation_id']),
                    array_diff_key($unit, ['generation_id' => true]));
                return $saved;
            }
            if ($cohorts->count() !== count($body['windows'])) throw new LogicException('COUNCIL_PANEL_ALL_ORIGINAL_COHORTS_REQUIRED');
            $agentsByWindow = [];
            foreach ($cohorts as $cohort) {
                $marker = (array) data_get($cohort->trigger_context, 'specialist_council_authorized_panel');
                $agents = $cohort->agents()->with('modelVersion')->orderBy('id')->get();
                $windowIndex = array_search($marker['window_key'] ?? null,
                    array_column(array_column($body['windows'], 'window'), 'window_key'), true);
                if ($cohort->status !== 'research_reserved' || $marker['reservation_hash'] !== $body['reservation_hash']
                    || $windowIndex === false
                    || ! LabPopulationService::reservedAuthorizedPanelOwnerMatches($cohort,
                        $this->constructorIntent($item, $body, $windowIndex))
                    || $agents->count() !== count($body['arm_roots'])
                    || $agents->contains(fn ($a): bool => $a->lifecycle_status !== 'draft')
                    || LabEvaluationRun::where('lab_generation_id', $cohort->id)->exists()) {
                    throw new LogicException('COUNCIL_PANEL_ORIGINAL_UNUSED_RESERVATION_REQUIRED');
                }
                foreach ($agents as $agent) {
                    $key = data_get($agent->modelVersion->metadata, 'authorized_specialist_council_panel_seed.arm_key');
                    if (! is_string($key) || isset($agentsByWindow[$marker['window_key']][$key])) {
                        throw new LogicException('COUNCIL_PANEL_CANONICAL_ARM_SLOT_AMBIGUOUS');
                    }
                    $agentsByWindow[$marker['window_key']][$key] = $agent;
                }
            }
            $first = $agentsByWindow[$body['windows'][0]['window']['window_key']];
            $manifest = array_diff_key($body['manifest_template'], array_flip(['manifest_hash', 'epoch_contract', 'promotion_evidence']));
            $manifest['version'] = 'panel-'.substr($body['reservation_hash'], 0, 20);
            $manifest['evaluation_policy']['champion_model_version_id'] = $first['champion']->model_version_id;
            $manifest['evaluation_policy']['solo_model_version_id'] = $first['solo']->model_version_id;
            foreach (['champion_model_version_id_hash', 'solo_model_version_id_hash'] as $key) unset($manifest['evaluation_policy'][$key]);
            $lifecycle = app(SpecialistCouncilLifecycleService::class);
            $version = $lifecycle->registerDraft($manifest, $body['creator_id']);
            $units = []; $arms = []; $windowRecords = [];
            $fullPolicy = ['protocol' => 'specialist_council_original_full_source_v1', 'evaluation_mode' => 'full',
                'selection' => 'entire_authorized_source', 'maximum_source_rows' => 200000,
                'maximum_runtime_seconds' => 600, 'warmup_rows' => 0, 'no_walk_forward_selection' => true,
                'promotion_evidence' => false];
            foreach ($body['windows'] as $index => $record) {
                $window = $record['window']; $windowKey = $window['window_key'];
                $cohort = $cohorts->firstWhere('trigger_context.specialist_council_authorized_panel.window_key', $windowKey);
                if (! $cohort) $cohort = $cohorts->first(fn ($g): bool => data_get($g->trigger_context,
                    'specialist_council_authorized_panel.window_key') === $windowKey);
                $sourceFile = $record['transport_proof']['files']['M5'];
                if ($sourceFile['rows'] < 2 || $sourceFile['rows'] > $fullPolicy['maximum_source_rows']) {
                    throw new LogicException('COUNCIL_PANEL_AUTHORIZED_FULL_SOURCE_BUDGET_EXCEEDED');
                }
                $scope = ['start_inclusive' => $sourceFile['start_inclusive'],
                    'end_exclusive' => CarbonImmutable::parse($sourceFile['last_candle_at'])->addMinutes(5)->toIso8601String(),
                    'rows' => $sourceFile['rows'], 'decision_rows' => $sourceFile['rows'] - 1, 'warmup_rows' => 0,
                    'policy_hash' => $this->epochs->parameterHash($fullPolicy)];
                $windowRecords[] = [...$window, 'evaluation_scope' => $scope];
                foreach ($body['arm_roots'] as $root) {
                    $agent = $agentsByWindow[$windowKey][$root['arm_key']] ?? null;
                    if (! $agent) throw new LogicException('COUNCIL_PANEL_ORIGINAL_ARM_SLOT_MISSING');
                    $model = $agent->modelVersion;
                    $source = ModelVersion::findOrFail($root['source_model_version_id']);
                    if ($this->contracts->modelHash($source) !== $root['source_model_hash']
                        || ! $this->evidence->equivalentJsonValue($this->evidence->modelRuntimeBasis($source), $this->evidence->modelRuntimeBasis($model))
                        || ! $this->evidence->equivalentJsonValue((array) $source->parameters, (array) $model->parameters)) {
                        throw new LogicException('COUNCIL_PANEL_PHYSICAL_CARRIER_CHANGED_DURING_CONSTRUCTION');
                    }
                    // This fresh, never-observed carrier is an exact clone of
                    // the frozen source. Rebind only the aggregate council
                    // reference to the same frozen native program, before the
                    // new model/plan/release seal. Old sources are untouched.
                    if (in_array($root['kind'], ['candidate', 'retention', 'ablation'], true)
                        && data_get($source->metadata, 'specialist_council') !== null) {
                        $metadata = (array) $model->metadata; unset($metadata['specialist_council']);
                        $model->forceFill(['metadata' => $metadata])->save();
                        $model = $lifecycle->attachResearchModel($version, $model->fresh());
                    }
                    $armKey = 'w'.($index + 1).':'.$root['arm_key'];
                    $arm = ['arm_key' => $armKey, 'kind' => $root['kind'], 'window_key' => $windowKey,
                        'model_version_id' => (int) $model->id, 'evaluation_phase' => 'full_validation',
                        'expected_start_inclusive' => $scope['start_inclusive'], 'expected_end_exclusive' => $scope['end_exclusive'],
                        'evaluation_scope' => $scope];
                    if (isset($root['removed_id'])) $arm['removed_id'] = $root['removed_id'];
                    $arms[] = $arm;
                    $units[] = ['generation_id' => (int) $cohort->id, ...$arm, 'lab_agent_id' => (int) $agent->id,
                        'model_hash' => $this->contracts->modelHash($model), 'panel_version_id' => (int) $version->id];
                }
            }
            $plan = array_diff_key($body['original_plan'], array_flip(['protocol', 'version_id', 'manifest_hash', 'objective',
                'windows', 'arms', 'plan_hash', 'preparation_source_hash', 'discovery_bundle_manifest', 'discovery_manifest_hash']));
            $plan = [...$plan, 'purpose' => 'independent', 'evaluation_phase' => 'full_validation', 'windows' => $windowRecords,
                'arms' => $arms, 'preparation_source_hash' => $body['current_source_hash'],
                'full_replay_runtime_policy' => $fullPolicy,
                'panel_reservation_hash' => $body['reservation_hash'], 'panel_work_item_id' => (int) $work->id];
            $sealed = $lifecycle->sealEvaluationPlan($version, $body['evaluator_id'], $plan);
            foreach ($units as &$unit) {
                $model = ModelVersion::findOrFail($unit['model_version_id']);
                $lifecycle->attachEvaluationArm($version->fresh(), $unit['arm_key'], $model);
                $unit['plan_hash'] = $sealed['plan_hash'];
            }
            unset($unit);
            foreach ($cohorts as $cohort) {
                $context = (array) $cohort->trigger_context; $marker = $context['specialist_council_authorized_panel'];
                $record = collect($body['windows'])->first(fn ($r): bool => $r['window']['window_key'] === $marker['window_key']);
                $context['mtf_bundle_manifest'] = $record['mtf_bundle_manifest'];
                $context['mtf_bundle_hash'] = $record['window']['dataset_sha256'];
                $context['specialist_council_authorized_panel'] = [...$marker, 'protocol' => self::COHORT_PROTOCOL,
                    'panel_version_id' => (int) $version->id, 'plan_hash' => $sealed['plan_hash'],
                    'arm_units' => array_values(array_map(fn ($u): array => array_diff_key($u, ['generation_id' => true]),
                        array_filter($units, fn ($u): bool => $u['generation_id'] === (int) $cohort->id)))];
                $cohort->update(['trigger_context' => $context]);
                $this->releases->seal($cohort->fresh());
                $cohort->refresh();
                $cohort->update(['status' => 'full_validation']);
                $cohort->agents()->update(['lifecycle_status' => 'full_queued']);
            }
            foreach ($units as &$unit) {
                $cohort = LabGeneration::findOrFail($unit['generation_id']);
                $request = app(SpecialistCouncilAuthorizedArmExecutionService::class)->compileRequest($cohort,
                    array_diff_key($unit, ['generation_id' => true]), $work);
                $unit['request_hash'] = $this->evidence->hash($request);
            }
            unset($unit);
            foreach ($cohorts as $cohort) {
                $cohort->refresh(); $context = (array) $cohort->trigger_context;
                $context['specialist_council_authorized_panel']['arm_units'] = array_values(array_map(
                    fn ($u): array => array_diff_key($u, ['generation_id' => true]),
                    array_filter($units, fn ($u): bool => $u['generation_id'] === (int) $cohort->id)));
                $cohort->update(['trigger_context' => $context]);
            }
            $saved = ['reservation_hash' => $body['reservation_hash'], 'panel_version_id' => (int) $version->id,
                'plan_hash' => $sealed['plan_hash'], 'units' => $units];
            $work->update(['result' => [...(array) $work->result, 'panel_preparation' => $saved]]);
            $this->assertCurrentLease($item, $work->fresh());
            return $saved;
        });
    }

    private function checkpoint(ResearchExperimentWorkItem $item, array $data): void
    {
        DB::transaction(function () use ($item, $data): void {
            $current = ResearchExperimentWorkItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentLease($item, $current);
            $current->update(['result' => [...(array) $current->result, 'protocol' => self::PROTOCOL, ...$data], 'heartbeat_at' => now()]);
        });
    }

    private function defer(ResearchExperimentWorkItem $item, string $reason, bool $retryable): array
    {
        app(ResearchExperimentConversionKernelService::class)->defer($item, $reason, $retryable);
        return ['protocol' => self::PROTOCOL, 'status' => 'deferred', 'reason' => $reason,
            'executable' => false, 'promotion_evidence' => false];
    }

    private function assertCurrentLease(ResearchExperimentWorkItem $item, ?ResearchExperimentWorkItem $current = null): void
    {
        $current ??= $item->fresh(); $this->assertLease($current);
        if ($current->lease_token !== $item->lease_token || (int) $current->fence_version !== (int) $item->fence_version) {
            throw new LogicException('COUNCIL_PANEL_WORK_LEASE_NOT_CURRENT');
        }
    }

    /** Every original arm unit is derived from the global original plan, not a context label. */
    public function assertExecutionUnit(ResearchExperimentWorkItem $work, LabGeneration $cohort, array $unit): void
    {
        $this->assertLease($work);
        $body = $this->body($work);
        $marker = (array) data_get($cohort->trigger_context, 'specialist_council_authorized_panel', []);
        if (($marker['protocol'] ?? null) !== self::COHORT_PROTOCOL || ($marker['work_item_id'] ?? null) !== (int) $work->id
            || ($marker['reservation_hash'] ?? null) !== $body['reservation_hash']
            || ! in_array($unit, (array) ($marker['arm_units'] ?? []), true)
            || ($unit['window_key'] ?? null) !== ($marker['window_key'] ?? null)) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_EXECUTION_UNIT_NOT_OWNED');
        }
        $plan = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $unit['panel_version_id'] ?? 0)->first();
        $global = $plan ? json_decode($plan->plan, true, 512, JSON_THROW_ON_ERROR) : [];
        $arm = $global['arms'][$unit['arm_key'] ?? ''] ?? [];
        $agent = LabAgent::find($unit['lab_agent_id'] ?? 0);
        if (! $plan || $plan->plan_hash !== ($unit['plan_hash'] ?? null) || $plan->plan_hash !== $this->epochs->parameterHash($global)
            || $global['purpose'] !== 'independent' || ($global['panel_reservation_hash'] ?? null) !== $body['reservation_hash']
            || ! $agent || $agent->lab_generation_id !== $cohort->id || $agent->model_version_id !== ($unit['model_version_id'] ?? null)
            || ($arm['model_version_id'] ?? null) !== $agent->model_version_id || ($arm['model_hash'] ?? null) !== ($unit['model_hash'] ?? null)
            || ($arm['window_key'] ?? null) !== $unit['window_key']) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_GLOBAL_PLAN_OR_ARM_DRIFT');
        }
        app(ResearchReleaseSealService::class)->assertCurrent($cohort);
    }

    public function body(ResearchExperimentWorkItem $work): array
    {
        $body = data_get($work->payload, 'pending_panel_intent');
        if (! is_array($body) || ($body['reservation_protocol'] ?? null) !== self::PROTOCOL
            || ($body['work_item_id'] ?? null) !== (int) $work->id || ($body['work_key'] ?? null) !== $work->work_key
            || ($body['work_type'] ?? null) !== $work->work_type || $body !== data_get($work->payload, 'followup_resolution')
            || ($body['max_experiments'] ?? null) !== 1 || ($body['max_window_cohorts'] ?? null) !== 3
            || ($body['max_lease_deliveries'] ?? null) !== count($body['arm_roots'] ?? []) * count($body['windows'] ?? [])
                + count($body['windows'] ?? []) + 2 + 8
            || ($body['authority'] ?? null) !== 'research_only' || ($body['promotion_evidence'] ?? null) !== false
            || ($body['independent_evidence_claimed'] ?? null) !== false
            || ($body['resolution_hash'] ?? null) !== $this->epochs->parameterHash(array_diff_key($body,
                array_flip(['resolution_hash', 'reservation_hash', 'server_seal'])))
            || ($body['reservation_hash'] ?? null) !== $body['resolution_hash']
            || ! is_string($body['server_seal'] ?? null)
            || ! hash_equals($body['server_seal'], $this->seal(array_diff_key($body, ['server_seal' => true])))) {
            throw new LogicException('COUNCIL_PANEL_SERVER_RESERVATION_SEAL_INVALID');
        }
        if (($body['current_source_hash'] ?? null) !== $this->evidence->codeHash()
            || ($body['current_python_source_hash'] ?? null) !== $this->releases->pythonHash()) {
            throw new LogicException('COUNCIL_PANEL_PREREGISTERED_SOURCE_CHANGED');
        }
        return $body;
    }

    private function sourceModel(ModelVersion $model): array
    {
        $schemas = app(StrategyParameterSchemaService::class);
        $parameters = (array) $model->parameters;
        if (! $this->evidence->equivalentJsonValue($schemas->validate($model->strategy, $parameters), $parameters)) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_ARM_PARAMETER_RENORMALIZATION_REFUSED');
        }
        return $this->persistedJsonValue(['source_model_version_id' => (int) $model->id, 'source_model_hash' => $this->contracts->modelHash($model),
            'strategy' => $model->strategy, 'family' => $schemas->family($model->strategy),
            'parameters' => $parameters, 'runtime_basis_hash' => $this->epochs->parameterHash($this->evidence->modelRuntimeBasis($model))]);
    }

    /** A reservation cannot promise execution of an incompatible frozen account. */
    private function assertOriginalComparisonPolicy(ResearchExperimentWorkItem $work, SpecialistCouncilVersion $parent, array $original): void
    {
        $execution = app(ExecutionContractService::class)->for((string) $work->symbol,
            (string) ($original['execution_timeframe'] ?? ''));
        if (($original['execution_hash'] ?? null) !== $execution['execution_hash']
            || ! $this->evidence->equivalentJsonValue($original['cost_model'] ?? null, $execution['parameters'])) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_CANONICAL_COST_POLICY_CHANGED');
        }
        $risk = (array) ($original['risk_policy'] ?? []);
        $legacyRisk = $risk['risk_per_trade_percent'] ?? null;
        if (! is_numeric($legacyRisk) || ! is_finite((float) $legacyRisk) || $legacyRisk <= 0 || $legacyRisk > 2) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_BOUNDED_RISK_PER_TRADE_REQUIRED');
        }
        foreach (['broker_position_mode', 'opposite_position_policy', 'max_open_positions', 'max_reserved_capital_percent',
            'max_gross_exposure_percent', 'max_total_risk_percent', 'max_drawdown_percent', 'max_daily_loss_percent',
            'max_expected_cost_percent'] as $key) {
            if (! array_key_exists($key, $risk)
                || ! $this->evidence->equivalentJsonValue($risk[$key], $parent->manifest['execution'][$key] ?? null)) {
                throw new LogicException('COUNCIL_PANEL_ORIGINAL_SHARED_ACCOUNT_POLICY_INVALID:'.$key);
            }
        }
    }

    private function persistedJsonValue(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertWindowBundleContract(array $manifest): void
    {
        if (($manifest['protocol'] ?? null) !== MultiTimeframeSnapshotService::PROTOCOL
            || ($manifest['validation_bundle_protocol'] ?? null) !== self::BUNDLE_PROTOCOL
            || ($manifest['symbol'] ?? null) !== 'XAUUSD') {
            throw new LogicException('COUNCIL_PANEL_AUTHORIZED_WINDOW_BUNDLE_PROTOCOL_REQUIRED');
        }
    }

    /** Exact window clones must retain the original frozen comparator program. */
    public function assertWindowComparator(array $plan, array $arm, ModelVersion $model): void
    {
        $work = ResearchExperimentWorkItem::findOrFail($plan['panel_work_item_id'] ?? 0);
        $this->assertCurrentLease($work); $body = $this->body($work);
        if (($plan['panel_reservation_hash'] ?? null) !== $body['reservation_hash']
            || ! in_array($arm['kind'] ?? null, ['solo', 'champion'], true)) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_COMPARATOR_RESERVATION_REQUIRED');
        }
        $root = collect($body['arm_roots'])->firstWhere('kind', $arm['kind']);
        $source = $root ? ModelVersion::find($root['source_model_version_id']) : null;
        $agent = LabAgent::where('model_version_id', $model->id)->sole();
        $marker = (array) data_get($agent->generation?->trigger_context, 'specialist_council_authorized_panel', []);
        if (! $source || $this->contracts->modelHash($source) !== $root['source_model_hash']
            || ($marker['reservation_hash'] ?? null) !== $body['reservation_hash']
            || ($marker['window_key'] ?? null) !== ($arm['window_key'] ?? null)
            || data_get($model->metadata, 'authorized_specialist_council_panel_seed.arm_key') !== $root['arm_key']
            || ! $this->evidence->equivalentJsonValue((array) $source->parameters, (array) $model->parameters)
            || ! $this->evidence->equivalentJsonValue($this->evidence->modelRuntimeBasis($source), $this->evidence->modelRuntimeBasis($model))) {
            throw new LogicException('COUNCIL_PANEL_ORIGINAL_COMPARATOR_PHYSICAL_PROGRAM_CHANGED');
        }
    }

    private function assertLease(ResearchExperimentWorkItem $work): void
    {
        if ($work->status !== 'leased' || ! filled($work->lease_token) || ! $work->lease_expires_at?->isFuture()
            || data_get($work->payload, 'owner') !== ResearchLoopArbiterService::class) {
            throw new LogicException('COUNCIL_PANEL_WORK_LEASE_NOT_CURRENT');
        }
    }

    private function seal(array $body): string
    {
        $key = (string) config('services.internal_api.token');
        if (strlen($key) < 32) throw new LogicException('COUNCIL_PANEL_SERVER_KEY_UNAVAILABLE');
        return hash_hmac('sha256', self::PROTOCOL."\n".$this->epochs->parameterHash($body), $key);
    }
}
