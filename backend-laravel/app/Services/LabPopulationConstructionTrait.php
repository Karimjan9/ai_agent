<?php

namespace App\Services;

use App\Models\AgentDiagnosis;
use App\Models\AgentFailureCase;
use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Internal method group composed exclusively into LabPopulationService. */
trait LabPopulationConstructionTrait
{
    /** Exact four persisted models, under the same canonical constructor mutex. */
    public function buildScopedDescendant(\App\Models\ResearchExperimentWorkItem $work, array $proof): LabGeneration
    {
        $owner = app(DescendantScopedExecutionService::class);
        $roles = $owner->armRoles($proof);
        $owner->assertLease($work);
        $fresh = $owner->inspectWork($work->fresh('receipt'));
        $matchingWindow = collect($fresh['windows'] ?? [])->contains(fn ($entry): bool => ($entry['window'] ?? null) === ($proof['window'] ?? null));
        if (($fresh['executable'] ?? false) !== true || ($fresh['design_hash'] ?? null) !== ($proof['design_hash'] ?? null)
            || ! $matchingWindow) {
            throw new \LogicException('DESCENDANT_ORIGINAL_CONSTRUCTOR_READINESS_REQUIRED');
        }
        $lock = Cache::lock($this->constructorLockKey($work->symbol, $work->timeframe), self::CONSTRUCTOR_LOCK_TTL_SECONDS);
        if (! $lock->get()) throw new \LogicException('DESCENDANT_CANONICAL_CONSTRUCTOR_BUSY');
        try {
            return DB::transaction(function () use ($work, $proof, $owner, $roles): LabGeneration {
                $owner->assertLease($work);
                $lab = AiLaboratory::where('symbol', $work->symbol)->where('timeframe', $work->timeframe)->lockForUpdate()->first();
                if (! $lab) throw new \LogicException('DESCENDANT_EXISTING_LABORATORY_REQUIRED');
                $owned = $lab->generations()->where('trigger_context->scoped_descendant_execution->work_item_id', $work->id)
                    ->where('trigger_context->scoped_descendant_execution->window_key', $proof['window']['window_key'])
                    ->orderBy('id')->limit(2)->get();
                if ($owned->count() > 1) throw new \LogicException('DESCENDANT_ONE_ORIGINAL_COHORT_REQUIRED');
                $existing = $owned->first();
                if ($lab->generations()->whereIn('status', self::ACTIVE_GENERATION_STATUSES)
                    ->where(fn ($query) => $query->whereNull('trigger_context->scoped_descendant_execution->work_item_id')
                        ->orWhere('trigger_context->scoped_descendant_execution->work_item_id', '!=', $work->id))->exists()) {
                    throw new \LogicException('DESCENDANT_ANOTHER_GENERATION_OWNS_STREAM');
                }
                if ($existing) {
                    $marker = data_get($existing->trigger_context, 'scoped_descendant_execution');
                    if (($marker['protocol'] ?? null) !== DescendantScopedExecutionService::PROTOCOL
                        || ($marker['certificate_id'] ?? null) !== $proof['certificate_id']
                        || ($marker['design_hash'] ?? null) !== $proof['design_hash']
                        || ($marker['window_key'] ?? null) !== $proof['window']['window_key']
                        || $existing->agents()->count() !== count($roles)
                        || ! (app(ImmutableGenerationContractService::class)->validate($existing)['valid'] ?? false)) {
                        throw new \LogicException('DESCENDANT_ORIGINAL_COHORT_CONTRACT_DRIFT');
                    }
                    app(ResearchReleaseSealService::class)->assertCurrent($existing);
                    return $existing->fresh(['agents.modelVersion']);
                }
                $design = $proof['design']; $plan = [];
                foreach ($roles as $arm) $plan[] = [
                    'family' => $lab->strategy_families[0] ?? 'hybrid', 'origin' => 'scoped_descendant_original',
                    'target' => 'scoped_inheritance', 'arm' => $arm,
                    'model_version_id' => $design['subject']['arm_models'][$arm]['model_version_id'],
                    'parameter_hash' => $design['subject']['arm_models'][$arm]['parameter_hash'],
                    'runtime_hash' => $design['subject']['arm_models'][$arm]['runtime_hash']];
                $marker = ['protocol' => DescendantScopedExecutionService::PROTOCOL,
                    'purpose' => $design['native_execution']['purpose'], 'work_type' => $work->work_type, 'work_item_id' => (int) $work->id,
                    'work_key' => $work->work_key, 'trial_id' => $proof['trial_id'], 'certificate_id' => $proof['certificate_id'],
                    'design_hash' => $proof['design_hash'], 'window_key' => $proof['window']['window_key'],
                    'source_receipt_id' => (int) $work->research_experiment_receipt_id,
                    'research_only' => true, 'projection_withheld' => true, 'promotion_evidence' => false];
                $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
                    'generation' => ((int) $lab->generations()->max('generation')) + 1,
                    'trigger_type' => $work->work_type,
                    'trigger_context' => ['scoped_descendant_execution' => $marker, 'generation_plan' => $plan,
                        'mtf_bundle_manifest' => $proof['manifest'], 'mtf_bundle_hash' => $proof['window']['dataset_sha256'],
                        'research_only' => true, 'promotion_evidence' => false],
                    'data_fingerprint' => $proof['window']['dataset_sha256'], 'population_size' => count($roles), 'status' => 'research_reserved']);
                $execution = app(ExecutionContractService::class)->for($work->symbol, 'M5');
                foreach ($plan as $seat) {
                    $model = ModelVersion::whereKey($seat['model_version_id'])->lockForUpdate()->firstOrFail();
                    $sourceAgentId = data_get($design, 'subject.arm_models.'.$seat['arm'].'.source_agent_id');
                    if (\App\Models\LabEvaluationRun::where('model_version_id', $model->id)
                            ->when($sourceAgentId, fn ($query) => $query->where('lab_agent_id', '!=', $sourceAgentId))->exists()
                        || LabAgent::where('model_version_id', $model->id)->when($sourceAgentId, fn ($query) => $query->where('id', '!=', $sourceAgentId))
                            ->whereHas('generation', fn ($query) => $query
                            ->whereNull('trigger_context->scoped_descendant_execution->work_item_id')
                            ->orWhere('trigger_context->scoped_descendant_execution->work_item_id', '!=', $work->id))->exists()) {
                        throw new \LogicException('DESCENDANT_ORIGINAL_ARM_ALREADY_ENROLLED_OR_OBSERVED');
                    }
                    // Costs are already prospective model identity. Never
                    // modify a frozen model to make release admission fit.
                    if (! app(LabImmutableEvidenceService::class)->equivalentJsonValue(
                        data_get($model->metadata, 'execution_contract.parameters'), $execution['parameters'])) {
                        throw new \LogicException('DESCENDANT_PERSISTED_ARM_EXECUTION_CONTRACT_REQUIRED');
                    }
                    LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                        'parent_a_model_version_id' => null, 'parent_b_model_version_id' => null,
                        'symbol' => $work->symbol, 'timeframe' => $work->timeframe,
                        'strategy_family' => app(StrategyParameterSchemaService::class)->family($model->strategy),
                        'origin' => 'scoped_descendant_original', 'lifecycle_status' => 'draft',
                        'decision_reason' => 'Prospective exact four-arm research; generic projections withheld.']);
                }
                $context = (array) $generation->trigger_context;
                $context['immutable_generation_contract'] = app(ImmutableGenerationContractService::class)->compile($generation, $plan,
                    ['data_hash' => $proof['window']['dataset_sha256'], 'execution_hash' => $design['execution_hash'],
                    'normal_population' => count($roles), 'new_work_owner' => ResearchLoopArbiterService::class]);
                $generation->update(['trigger_context' => $context]);
                app(LearningProtocolEpochService::class)->openForNewGeneration($generation, $work->symbol, $work->timeframe);
                return app(ResearchReleaseSealService::class)->seal($generation)->fresh(['agents.modelVersion']);
            });
        } finally { $lock->release(); }
    }

    public function build(string $symbol, string $trigger = 'new_data', bool $force = false, string $timeframe = 'H1', array $coverageRescue = [], bool $roleComplete = false, bool $refreshHistoricalLearning = true, ?int $populationLimit = null, ?array $targetedFailureProfile = null, bool $allowControlledRescue = false, ?array $prospectiveExpectation = null, ?array $nativeCouncilIntent = null, ?array $authorizedCouncilPanelIntent = null): ?LabGeneration
    {
        $this->parentPerformanceSnapshots = [];
        $this->archiveFrontierSnapshots = [];
        $this->archiveMigrationSnapshots = [];
        $symbol = strtoupper($symbol);
        $requestedTimeframe = strtoupper($timeframe);
        if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            $timeframe = (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1');
        }
        $timeframe = strtoupper($timeframe);
        $this->lastBuildOutcome = [
            'status' => 'blocked',
            'reason_code' => 'POPULATION_BUILD_UNSPECIFIED',
            'retryable' => false,
            'context' => ['symbol' => $symbol, 'timeframe' => $timeframe, 'trigger' => $trigger],
        ];
        if ($authorizedCouncilPanelIntent !== null || $trigger === self::AUTHORIZED_COUNCIL_PANEL_TRIGGER) {
            try {
                if ($authorizedCouncilPanelIntent === null || $nativeCouncilIntent !== null || $symbol !== 'XAUUSD'
                    || $requestedTimeframe !== 'H1' || $trigger !== self::AUTHORIZED_COUNCIL_PANEL_TRIGGER
                    || $force || $coverageRescue !== [] || $roleComplete || $targetedFailureProfile !== null
                    || $allowControlledRescue || $prospectiveExpectation !== null) {
                    throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_REQUIRES_EXACT_UNFORCED_OWNER');
                }
                $authorizedCouncilPanelIntent = $this->authorizedCouncilPanelProof($authorizedCouncilPanelIntent);
                $size = count(self::authorizedPanelConstructionRoots($authorizedCouncilPanelIntent));
                if ($populationLimit !== null && $populationLimit !== $size) {
                    throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_EXACT_ARM_BUDGET_REQUIRED');
                }
                $populationLimit = $size;
                $refreshHistoricalLearning = false;
            } catch (\Throwable $error) {
                return $this->blocked('AUTHORIZED_COUNCIL_PANEL_CONSTRUCTOR_INTENT_INVALID', false,
                    ['dependency' => $error instanceof \LogicException ? $error->getMessage() : 'AUTHORIZED_COUNCIL_PANEL_ORIGINAL_OWNER_UNAVAILABLE']);
            }
        }
        if ($nativeCouncilIntent !== null) {
            try {
                $nativeCouncilIntent = $this->sealNativeCouncilIntent($nativeCouncilIntent, $symbol, $requestedTimeframe,
                    $trigger, $force, $coverageRescue, $roleComplete, $populationLimit, $targetedFailureProfile,
                    $allowControlledRescue, $prospectiveExpectation);
                $populationLimit = 6;
            } catch (\InvalidArgumentException $error) {
                return $this->blocked('NATIVE_COUNCIL_CONSTRUCTOR_INTENT_INVALID', false,
                    ['dependency' => $error->getMessage()]);
            }
        }
        // STOP is checked before taking the long-lived constructor lock or
        // compiling historical/parent state. The canonical admission service
        // repeats this check at commit time, but a fail-fast boundary prevents
        // a scheduled special lane from spending minutes on a population that
        // the operator has explicitly disabled.
        if (! app(AutonomousModeService::class)->enabled($symbol, $timeframe)) {
            return $this->blocked('GENERATION_ADMISSION_BLOCK_HARD', false, [
                'reason_codes' => ['AUTONOMOUS_MODE_STOPPED'],
                'autonomous_mode' => 'stopped',
            ]);
        }
        // Every population entry point (lifecycle, targeted handoff, operator
        // command and recovery) converges here. Command-specific mutexes do
        // not protect these paths from each other, so own one symbol-scoped
        // constructor lease for the entire expensive 20-seat compilation.
        $this->recoverStaleConstructorLease($symbol, $timeframe);
        $constructorLock = Cache::lock(
            $this->constructorLockKey($symbol, $timeframe),
            self::CONSTRUCTOR_LOCK_TTL_SECONDS,
        );
        if (! $constructorLock->get()) {
            return $this->blocked('GENERATION_CONSTRUCTOR_ACTIVE', true, [
                'lock_owner' => Cache::get($this->constructorOwnerKey($symbol, $timeframe)),
            ]);
        }
        $this->nativeFollowupConstructorInvocation = true;
        $this->nativeFollowupInvocationProof = null;
        try {
            if (isset($nativeCouncilIntent['followup_work_item_id'])) {
                $this->nativeFollowupFeedbackOwner = app(SpecialistCouncilResearchFeedbackService::class);
                $this->nativeFollowupSourceFenceScope = $this->nativeFollowupFeedbackOwner->beginConstructorSourceFenceScope();
            }
            Cache::put($this->constructorOwnerKey($symbol, $timeframe), $this->constructorOwner('build', $trigger), now()->addSeconds(self::CONSTRUCTOR_LOCK_TTL_SECONDS));
            // Existing queued jobs stay intact.  This only prevents creation of a
            // new population while an execution-contract rollout is being audited.
            $controlledRescue = $allowControlledRescue
                && $this->protocolSafety->controlledRescueAllowed($trigger, $populationLimit, $targetedFailureProfile);
            if ($allowControlledRescue && ! $controlledRescue) {
                return $this->blocked('CONTROLLED_RESCUE_NOT_ADMITTED');
            }
            $this->ensureLaboratories();
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
            if ($authorizedCouncilPanelIntent !== null) {
                $owned = $lab->generations()->where('trigger_context->authorized_specialist_council_panel_intent->reservation_hash', $authorizedCouncilPanelIntent['reservation_hash'])
                    ->where('trigger_context->authorized_specialist_council_panel_intent->window_key', $authorizedCouncilPanelIntent['window_key'])
                    ->orderBy('id')->limit(2)->get();
                if ($owned->count() > 1) return $this->blocked('AUTHORIZED_COUNCIL_PANEL_MULTIPLE_WINDOW_OWNERS');
                if ($existing = $owned->first()) {
                    $stored = (array) data_get($existing->trigger_context, 'authorized_specialist_council_panel_intent', []);
                    if (app(ResearchPaperEpochContractService::class)->parameterHash($stored)
                        !== app(ResearchPaperEpochContractService::class)->parameterHash($authorizedCouncilPanelIntent)) {
                        return $this->blocked('AUTHORIZED_COUNCIL_PANEL_EXISTING_WINDOW_OWNER_DRIFT');
                    }
                    $existing->load('agents.modelVersion');
                    $slots = [];
                    foreach ($existing->agents as $agent) {
                        $slot = $this->constructorSlot($existing, $agent, '');
                        if ($slot === null || isset($slots[$slot])) return $this->blocked('AUTHORIZED_COUNCIL_PANEL_DUPLICATE_OR_MISSING_SLOT');
                        $slots[$slot] = true;
                    }
                    $this->lastBuildOutcome = ['status' => 'existing', 'reason_code' => 'AUTHORIZED_COUNCIL_PANEL_SAME_WINDOW_OWNER',
                        'retryable' => false, 'context' => ['generation_id' => $existing->id, 'window_key' => $stored['window_key']]];
                    return $existing->fresh(['agents.modelVersion']);
                }
            }
            $lineageHead = $lab->generations()->latest('generation')->first();
            $historicalPolicy = app(GenerationAdmissionDecisionService::class)->historicalResearchPolicy($symbol, $timeframe);
            if ($trigger === GenerationAdmissionDecisionService::HISTORICAL_TRIGGER && ! $historicalPolicy['eligible']) {
                return $this->blocked('HISTORICAL_RESEARCH_POLICY_NOT_ADMITTED');
            }
            $historicalResearch = $historicalPolicy['eligible'] && in_array($trigger, [
                GenerationAdmissionDecisionService::HISTORICAL_TRIGGER, 'learning_confirmation',
            ], true);
            $historicalAdmission = null;
            // A bounded continuation temporarily projects an incomplete cohort as
            // technical_quarantine so none of its seats can enter replay. That is
            // still active construction ownership, not a terminal strategy result.
            // Every population entry point must therefore defer to the same newest
            // incomplete row; otherwise the targeted scheduler can open G(n+1)
            // between continuation chunks and strand G(n) forever.
            if (self::constructionIncomplete($lineageHead)) {
                return $this->blocked('LATEST_GENERATION_CONSTRUCTION_INCOMPLETE', true, [
                    'generation_id' => (int) $lineageHead->id,
                    'generation' => (int) $lineageHead->generation,
                    'planned_slots' => count((array) data_get($lineageHead->trigger_context, 'generation_plan', [])),
                    'created_slots' => $lineageHead->agents()->count(),
                ]);
            }
            // Normal generation creation remains fail-closed during a protocol
            // pause. Two explicitly auditable lanes are re-authorized here:
            // shadow research (validated by its governor below) and a data-edge
            // handoff whose latest screened generation carries durable audit
            // evidence. The latter is intentionally narrow so a stale scheduler
            // cannot turn the audit exception into a generic population stream.
            $auditGeneration = $trigger === 'data_edge_audit'
                ? $lab->generations()->latest('generation')->first()
                : null;
            $auditedDataEdge = $auditGeneration !== null
                && in_array((string) $auditGeneration->status, ['screened', 'technical_quarantine'], true)
                && $this->dataEdgeAudits->opensSuccessor($auditGeneration);
            $operatorSuccessor = $trigger === 'operator_successor';
            $learningConfirmation = $trigger === 'learning_confirmation';
            $qualityEvolutionSynthesis = $trigger === 'quality_evolution_synthesis';
            if ($this->protocolSafety->generationCreationPaused()
                && ! $controlledRescue
                && ! $operatorSuccessor
                && ! $learningConfirmation
                && ! $qualityEvolutionSynthesis
                && $trigger !== 'shadow_research'
                && ! $auditedDataEdge) {
                return $this->blocked('LEARNING_GATE_PAUSED', false);
            }
            $confirmationLesson = $learningConfirmation
                ? app(CausalLearningCohortPlannerService::class)->eligibleLesson($lab->symbol, $lab->timeframe)
                : null;
            // A failed causal experiment has priority over unrelated positive
            // memory. Its frontier is a bounded falsification debt: repair one
            // target-owning gene from the exact frozen control before spending
            // compute on another confirmation.
            $causalRepairFrontier = $learningConfirmation
                ? app(CausalRepairFrontierService::class)->eligible($lab->symbol, $lab->timeframe)
                : null;
            $prospectiveRepair = $learningConfirmation && ! $causalRepairFrontier && ! $confirmationLesson
                ? app(ProspectiveRepairExperimentService::class)->eligible($lab->symbol, $lab->timeframe,
                    $prospectiveExpectation === null ? null : (int) ($prospectiveExpectation['source_pair_id'] ?? 0)) : null;
            if ($prospectiveExpectation !== null && (! $learningConfirmation || ! $prospectiveRepair
                || strlen((string) ($prospectiveExpectation['source_hash'] ?? '')) !== 64
                || ! hash_equals((string) ($prospectiveExpectation['source_hash'] ?? ''),
                    (string) $prospectiveRepair['source_hash']))) {
                return $this->blocked('PROSPECTIVE_REPAIR_SELECTED_SOURCE_CHANGED', true);
            }
            if ($learningConfirmation && ! $causalRepairFrontier && ! $confirmationLesson && ! $prospectiveRepair) {
                return $this->blocked('NO_ELIGIBLE_CAUSAL_LEARNING_CONFIRMATION', true);
            }
            if ($learningConfirmation && $prospectiveRepair) {
                // This experiment's indivisible unit is its exact three-arm
                // comparison. Unrelated discovery seats may not turn an
                // otherwise valid causal triplet into a partial generation.
                if ($populationLimit !== null && $populationLimit !== 3) {
                    return $this->blocked('PROSPECTIVE_REPAIR_REQUIRES_EXACT_TRIPLET', true);
                }
                $populationLimit = 3;
            }
            if (! $controlledRescue && (string) $lab->lifecycle_mode !== 'lighthouse') {
                // Non-lighthouse labs remain research/shadow streams. They may be
                // monitored and studied, but they cannot create an evolution
                // generation or become a parent ecosystem.
                return $this->blocked('LAB_NOT_IN_LIGHTHOUSE_MODE');
            }
            // Recompute the append-only historical conclusion before planning a
            // new population. Snapshot history may choose the failure target;
            // exact causal credits are handled separately by mutate().
            // The role-complete builder is an explicit child handoff and may be
            // invoked after a data/edge audit. Its curriculum already consumes
            // the latest append-only insight; do not rescan the million-row
            // candle-event plane while holding the population creation path open.
            // The normal scheduler and sync-agent-knowledge command continue to
            // refresh historical learning independently.
            if (! $roleComplete && $refreshHistoricalLearning) {
                $this->historicalLearning->refreshForLab($lab->symbol, $lab->timeframe);
            }
            $provider = (string) config('services.market_data.provider', 'csv');
            if ($authorizedCouncilPanelIntent === null && ! $historicalResearch && ! $force && $provider !== 'csv' && ! $this->continuity->isReady($provider, $lab->symbol, $lab->timeframe)) {
                return $this->blocked('MARKET_DATA_CONTINUITY_NOT_READY', true);
            }
            // A forced protocol activation is an explicit operator action after a
            // successful market-data audit.  Normal scheduled populations remain
            // blocked by both continuity and historical-data readiness gates.
            if ($authorizedCouncilPanelIntent === null && ! $historicalResearch && ! $force && ! app()->environment('testing') && ! $this->historicalData->ready($lab->symbol, $lab->timeframe)) {
                return $this->blocked('HISTORICAL_DATA_NOT_READY', true);
            }
            if ($historicalResearch) {
                // Replace live readiness with actual archive validation, not
                // with --force. Missing/corrupt research data still blocks.
                try {
                    $archive = app(LabDatasetExportService::class)->ensureFoundationDataset($symbol, $timeframe);
                    $historicalAdmission = [...$historicalPolicy,
                        'archive_sha256' => (string) $archive['sha256'],
                        'archive_manifest' => (array) $archive['manifest'],
                        'archive_path' => (string) $archive['path'],
                    ];
                } catch (\RuntimeException $error) {
                    return $this->blocked('HISTORICAL_RESEARCH_ARCHIVE_NOT_READY', true,
                        ['dependency' => $error->getMessage()]);
                }
            }
            if ($roleComplete && ! app()->environment('testing')) {
                $dataIntegrity = app(MarketDriftDetectionService::class)->canonicalDataContract($lab->symbol, $lab->timeframe);
                if ($dataIntegrity['status'] !== 'ready' || $dataIntegrity['is_canonical'] !== true) {
                    return $this->blocked('CANONICAL_DATA_CONTRACT_NOT_READY', true);
                }
            }
            $snapshot = $authorizedCouncilPanelIntent === null ? $this->dataSnapshot($lab) : [
                'fingerprint' => $authorizedCouncilPanelIntent['authorized_window']['dataset_sha256'],
                'count' => 0, 'latest' => $authorizedCouncilPanelIntent['authorized_window']['end_exclusive'],
            ];
            $fingerprint = $snapshot['fingerprint'];
            $latest = $lab->generations()->latest('generation')->first();
            // A durable audit owns the very next root population regardless of
            // whether the caller is the lifecycle orchestrator or an older
            // generic new-data/candidate-handoff scheduler entrypoint. Resolve it
            // before shadow routing so a race cannot consume the successor as an
            // ordinary shadow generation.
            if (in_array($trigger, ['new_data', 'candidate_handoff', GenerationAdmissionDecisionService::HISTORICAL_TRIGGER], true)
                && $this->dataEdgeAudits->opensSuccessor($latest)) {
                $trigger = 'data_edge_audit';
            }
            $targetedRescueBlocked = $this->rescueCircuitBreaker->blockedForLab($lab);
            // A complete control failure is not automatically a strategy verdict.
            // When the evidence contract is healthy, route the next bounded search
            // into an explicitly shadow-only cohort. Technical/incomplete control
            // evidence remains fail-closed and can only enter recovery.
            $shadowResearch = false;
            $shadowResearchPosture = [
                'protocol' => ShadowResearchGovernorService::PROTOCOL,
                'allowed' => false,
                'shadow_only' => true,
                'promotion_evidence' => false,
            ];
            if ((bool) config('services.lab_selection.shadow_research_enabled', true)
                && in_array($trigger, ['new_data', 'shadow_research'], true)) {
                $shadowResearchPosture = $this->shadowResearch->assess($lab, $targetedRescueBlocked);
                $shadowResearch = (bool) data_get($shadowResearchPosture, 'allowed', false);
                if ($trigger === 'shadow_research' && ! $shadowResearch) {
                    return $this->blocked('SHADOW_RESEARCH_NOT_ADMITTED');
                }
                if ($trigger === 'new_data' && $shadowResearch) {
                    $trigger = 'shadow_research';
                }
            }
            // Do not look only at the numerically latest row. A stale scheduler
            // can leave an older generation in screening while a later terminal
            // row exists; that older stream still owns the laboratory lock.
            if ($lab->generations()->latest('id')->first()?->status !== null
                && in_array((string) $lab->generations()->latest('id')->value('status'), self::ACTIVE_GENERATION_STATUSES, true)
                && ! $this->sameReservedCouncilPanel($lab->generations()->latest('id')->first(), $authorizedCouncilPanelIntent)) {
                return $this->blocked('LATEST_GENERATION_ACTIVE', true);
            }
            // A long-lived scheduler can submit the generic candidate-handoff
            // command just after an operator records the required data/edge
            // audit. Route that request through the explicit audit protocol so a
            // stale scheduler cannot create a generic generation and silently
            // bypass the council plan.
            if (in_array($trigger, ['new_data', 'candidate_handoff'], true)
                && $this->dataEdgeAudits->opensSuccessor($latest)) {
                $trigger = 'data_edge_audit';
            }
            if ($nativeCouncilIntent !== null && $trigger !== GenerationAdmissionDecisionService::HISTORICAL_TRIGGER) {
                return $this->blocked('NATIVE_COUNCIL_CONSTRUCTOR_INTENT_SUPERSEDED', true,
                    ['selected_trigger' => $trigger]);
            }
            if ($trigger === 'new_data' && ModelMarketPerformance::where('symbol', $lab->symbol)
                ->where('timeframe', $lab->timeframe)->where('status', 'champion')->where('evidence_status', 'valid')
                ->where('consecutive_no_improvement', '>=', 3)->exists()) {
                $trigger = 'degradation';
            }
            $generationAdmission = app(GenerationAdmissionDecisionService::class)->decide($lab, $latest, [
                'trigger' => $trigger,
                'historical_research' => $historicalResearch,
                'controlled_rescue' => $controlledRescue,
                'operator_approved_successor' => $operatorSuccessor || $qualityEvolutionSynthesis,
                'learning_confirmation' => $learningConfirmation,
                'role_complete' => $roleComplete,
                'shadow_research' => $shadowResearch,
                'coverage_rescue' => (bool) data_get($coverageRescue, 'eligible', false),
                'force' => $force,
                'authorized_specialist_council_panel_intent' => $authorizedCouncilPanelIntent,
            ]);
            $learningVelocity = (array) data_get($generationAdmission, 'learning_velocity', []);
            if (! (bool) data_get($generationAdmission, 'allowed', false)) {
                return $this->blocked(
                    'GENERATION_ADMISSION_'.(string) data_get($generationAdmission, 'decision', 'BLOCK_HARD'),
                    in_array((string) data_get($generationAdmission, 'decision'), [
                        GenerationAdmissionDecisionService::WAIT_ACTIVE_WORK,
                        GenerationAdmissionDecisionService::DISPATCH_LEARNING,
                        GenerationAdmissionDecisionService::RECOVER_TECHNICAL,
                    ], true),
                    ['generation_admission' => $generationAdmission],
                );
            }
            // A completed screening with no eligible full-replay candidate is an
            // intentional handoff boundary.  The targeted-generation builder
            // must be able to consume that immutable failure curriculum without
            // opening a second population during live screening or full replay.
            $screenedCandidateHandoff = $trigger === 'candidate_handoff'
                && $latest?->status === 'screened';
            $screenedDataEdgeAudit = $trigger === 'data_edge_audit'
                && $latest?->status === 'screened'
                && $this->dataEdgeAudits->opensSuccessor($latest);
            $screenedCoverageRescue = $trigger === 'coverage_rescue'
                && $latest?->status === 'screened'
                && (bool) data_get($coverageRescue, 'eligible')
                && data_get($coverageRescue, 'protocol') === CoverageRescueAuditService::PROTOCOL;
            if ($latest && in_array($latest->status, self::ACTIVE_GENERATION_STATUSES, true)
                && ! $screenedCandidateHandoff && ! $screenedDataEdgeAudit && ! $screenedCoverageRescue
                && ! $this->sameReservedCouncilPanel($latest, $authorizedCouncilPanelIntent)) {
                return $this->blocked('LATEST_GENERATION_ACTIVE', true);
            }
            $latestRequiresAudit = $latest
                && data_get($latest->trigger_context, 'latest_generation_report.report_state', 'FINAL') !== 'EVIDENCE_IN_PROGRESS'
                && data_get($latest->trigger_context, 'latest_generation_report.next_action') === 'data_edge_audit_required';
            if ($trigger === 'coverage_rescue' && (! (bool) data_get($coverageRescue, 'eligible') || data_get($coverageRescue, 'failure') !== 'operating_envelope_coverage_sparse')) {
                return $this->blocked('COVERAGE_RESCUE_NOT_ELIGIBLE');
            }
            if (($latestRequiresAudit && ! $controlledRescue && ! $operatorSuccessor && ! $learningConfirmation && ! in_array($trigger, ['data_edge_audit', 'coverage_rescue', 'shadow_research'], true))
                || ($trigger === 'data_edge_audit' && ! $this->dataEdgeAudits->opensSuccessor($latest))) {
                return $this->blocked('DATA_EDGE_AUDIT_REQUIRED');
            }
            $newCandles = $snapshot['count'] - (int) data_get($latest?->trigger_context, 'data_count', 0);
            $rescueAdmission = null;
            $independentEvidenceAdmission = null;
            if ($this->rescueCircuitBreaker->isRescueProfile($targetedFailureProfile, $trigger)) {
                $rescueSnapshot = [
                    ...$snapshot,
                    'data_fingerprint' => $fingerprint,
                    'data_count' => (int) $snapshot['count'],
                    'latest_candle' => $snapshot['latest'],
                    'new_candles' => max(0, $newCandles),
                ];
                $rescueAdmission = $this->rescueCircuitBreaker->admission(
                    $lab,
                    $targetedFailureProfile,
                    $latest,
                    $rescueSnapshot,
                );
                if (! (bool) data_get($rescueAdmission, 'allowed', false)) {
                    $this->rescueCircuitBreaker->recordBlocked($lab, $rescueAdmission, $latest);

                    return $this->blocked('RESCUE_CIRCUIT_BREAKER_BLOCKED');
                }
                if (app(StructuralResearchCohortService::class)->isProfile($targetedFailureProfile)) {
                    $independentEvidenceAdmission = $this->rescueCircuitBreaker->independentEvidenceAdmission(
                        $lab,
                        $latest,
                        $targetedFailureProfile,
                        $rescueSnapshot,
                    );
                    if (! (bool) data_get($independentEvidenceAdmission, 'allowed', false)) {
                        $this->rescueCircuitBreaker->recordBlocked($lab, $independentEvidenceAdmission, $latest);

                        return $this->blocked('INDEPENDENT_EVIDENCE_NOT_READY');
                    }
                }
            }
            // A fresh population needs roughly one day of new evidence on its
            // own stream: 24 H1 bars or 96 M15 bars. Degradation and explicit
            // handoff/audit protocols remain immediate safety exceptions. This
            // prevents the faster M15 feed from creating noisy six-hour
            // generations while preserving its independent entry research lane.
            $minimumFreshCandles = $timeframe === 'M15' ? 96 : 24;
            // Shadow research is still a generation and therefore cannot recycle
            // a sealed snapshot. It may remain diagnostic-only, but it must wait
            // for a genuinely new candle identity or an explicitly audited
            // independent holdout just like the normal lane.
            $structuralEscapeAdmission = (string) data_get($generationAdmission, 'decision')
                === GenerationAdmissionDecisionService::OPEN_STRUCTURAL_ESCAPE;
            if ($authorizedCouncilPanelIntent === null && $latest && $newCandles < $minimumFreshCandles && ! $historicalResearch && ! $force && ! $structuralEscapeAdmission
                && ! in_array($trigger, ['degradation', 'candidate_handoff', 'data_edge_audit', 'shadow_research', 'learning_confirmation', 'quality_evolution_synthesis'], true)) {
                return $this->blocked('INSUFFICIENT_FRESH_CANDLES', true, ['new_candles' => $newCandles, 'minimum_fresh_candles' => $minimumFreshCandles]);
            }

            // Reserve the generation number and immutable plan atomically, but do
            // not keep that transaction open while compiling every child. Parent
            // frontier/capability work is CPU-heavy and can take minutes; a long
            // transaction blocks scheduler reads and makes cancellation look like
            // a database hang.
            $targetedFailureTargets = array_values(array_unique(array_filter(array_map(
                static fn (mixed $target): string => (string) $target,
                (array) data_get($targetedFailureProfile, 'targets', []),
            ))));
            $buildState = DB::transaction(function () use ($lab, $trigger, $fingerprint, $snapshot, $newCandles, $coverageRescue, $roleComplete, $populationLimit, $targetedFailureProfile, $targetedFailureTargets, $controlledRescue, $operatorSuccessor, $learningConfirmation, $qualityEvolutionSynthesis, $confirmationLesson, $causalRepairFrontier, $prospectiveRepair, $learningVelocity, $generationAdmission, $shadowResearch, $shadowResearchPosture, $rescueAdmission, $independentEvidenceAdmission, $targetedRescueBlocked, $historicalResearch, $historicalAdmission, $nativeCouncilIntent, $authorizedCouncilPanelIntent): ?array {
                // Scheduler and manual/operator requests may arrive together. Lock
                // the laboratory row before assigning the next generation number;
                // otherwise two workers can build the same G and one can leave a
                // partially recorded handoff behind.
                $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
                if ($authorizedCouncilPanelIntent !== null) $authorizedCouncilPanelIntent = $this->authorizedCouncilPanelProof($authorizedCouncilPanelIntent);
                $latestInTransaction = $lockedLab->generations()->latest('generation')->lockForUpdate()->first();
                // Repeat the check after acquiring the row lock.  The preflight
                // check prevents normal duplicates; this one closes the race
                // between a scheduler tick and a manual/targeted invocation.
                if (self::constructionIncomplete($latestInTransaction)) {
                    return $this->blocked('LATEST_GENERATION_CONSTRUCTION_INCOMPLETE', true, [
                        'generation_id' => (int) $latestInTransaction->id,
                        'generation' => (int) $latestInTransaction->generation,
                        'planned_slots' => count((array) data_get($latestInTransaction->trigger_context, 'generation_plan', [])),
                        'created_slots' => $latestInTransaction->agents()->count(),
                    ]);
                }
                if (in_array($trigger, ['new_data', 'candidate_handoff', GenerationAdmissionDecisionService::HISTORICAL_TRIGGER], true)
                    && $this->dataEdgeAudits->opensSuccessor($latestInTransaction)) {
                    $trigger = 'data_edge_audit';
                }
                $screenedCandidateHandoff = $trigger === 'candidate_handoff'
                    && $latestInTransaction?->status === 'screened';
                $screenedDataEdgeAudit = $trigger === 'data_edge_audit'
                    && $latestInTransaction?->status === 'screened'
                    && $this->dataEdgeAudits->opensSuccessor($latestInTransaction);
                $screenedCoverageRescue = $trigger === 'coverage_rescue'
                    && $latestInTransaction?->status === 'screened'
                    && (bool) data_get($coverageRescue, 'eligible')
                    && data_get($coverageRescue, 'protocol') === CoverageRescueAuditService::PROTOCOL;
                if ($latestInTransaction !== null && in_array((string) $latestInTransaction->status, self::ACTIVE_GENERATION_STATUSES, true)
                    && ! $this->sameReservedCouncilPanel($latestInTransaction, $authorizedCouncilPanelIntent)) {
                    return $this->blocked('LATEST_GENERATION_ACTIVE', true);
                }
                $lockedGenerationAdmission = app(GenerationAdmissionDecisionService::class)->decide($lockedLab, $latestInTransaction, [
                    'trigger' => $trigger,
                    'historical_research' => $historicalResearch,
                    'controlled_rescue' => $controlledRescue,
                    'operator_approved_successor' => $operatorSuccessor || $qualityEvolutionSynthesis,
                    'learning_confirmation' => $learningConfirmation,
                    'role_complete' => $roleComplete,
                    'shadow_research' => $shadowResearch,
                    'coverage_rescue' => (bool) data_get($coverageRescue, 'eligible', false),
                    'force' => (bool) data_get($generationAdmission, 'input.force', false),
                    'authorized_specialist_council_panel_intent' => $authorizedCouncilPanelIntent,
                ]);
                if (! (bool) data_get($lockedGenerationAdmission, 'allowed', false)) {
                    return $this->blocked(
                        'GENERATION_ADMISSION_'.(string) data_get($lockedGenerationAdmission, 'decision', 'BLOCK_HARD'),
                        true,
                        ['generation_admission' => $lockedGenerationAdmission],
                    );
                }
                $latestRequiresAudit = $latestInTransaction
                    && data_get($latestInTransaction->trigger_context, 'latest_generation_report.report_state', 'FINAL') !== 'EVIDENCE_IN_PROGRESS'
                    && data_get($latestInTransaction->trigger_context, 'latest_generation_report.next_action') === 'data_edge_audit_required';
                if ($trigger === 'coverage_rescue' && (! (bool) data_get($coverageRescue, 'eligible') || data_get($coverageRescue, 'failure') !== 'operating_envelope_coverage_sparse')) {
                    return $this->blocked('COVERAGE_RESCUE_NOT_ELIGIBLE');
                }
                if (($latestRequiresAudit && ! $controlledRescue && ! $operatorSuccessor && ! $learningConfirmation && ! in_array($trigger, ['data_edge_audit', 'coverage_rescue', 'shadow_research'], true))
                    || ($trigger === 'data_edge_audit' && ! $this->dataEdgeAudits->opensSuccessor($latestInTransaction))) {
                    return $this->blocked('DATA_EDGE_AUDIT_REQUIRED');
                }
                if ($this->rescueCircuitBreaker->isRescueProfile($targetedFailureProfile, $trigger)) {
                    $lockedSnapshot = [
                        ...$snapshot,
                        'data_fingerprint' => $fingerprint,
                        'data_count' => (int) $snapshot['count'],
                        'latest_candle' => $snapshot['latest'],
                        'new_candles' => max(0, (int) $snapshot['count'] - (int) data_get($latestInTransaction?->trigger_context, 'data_count', 0)),
                    ];
                    $lockedAdmission = $this->rescueCircuitBreaker->admission(
                        $lockedLab,
                        $targetedFailureProfile,
                        $latestInTransaction,
                        $lockedSnapshot,
                    );
                    if (! (bool) data_get($lockedAdmission, 'allowed', false)) {
                        return $this->blocked('RESCUE_CIRCUIT_BREAKER_BLOCKED');
                    }
                    $rescueAdmission = $lockedAdmission;
                    if (app(StructuralResearchCohortService::class)->isProfile($targetedFailureProfile)) {
                        $lockedIndependentEvidence = $this->rescueCircuitBreaker->independentEvidenceAdmission(
                            $lockedLab,
                            $latestInTransaction,
                            $targetedFailureProfile,
                            $lockedSnapshot,
                        );
                        if (! (bool) data_get($lockedIndependentEvidence, 'allowed', false)) {
                            return $this->blocked('INDEPENDENT_EVIDENCE_NOT_READY');
                        }
                        $independentEvidenceAdmission = $lockedIndependentEvidence;
                    }
                }
                $number = (int) ($latestInTransaction?->generation ?? 0) + 1;
                $plannedPopulationSize = $roleComplete
                    ? max(4, $populationLimit !== null ? (int) $populationLimit : $this->configuredPopulationSize())
                    : ($populationLimit !== null
                        ? max(1, (int) $populationLimit)
                        : $this->configuredPopulationSize());
                // Warm the original full proof while the mutex/transaction is
                // held, before this new row can appear as an owned cohort.
                if (isset($nativeCouncilIntent['followup_work_item_id'])) {
                    $this->nativeCouncilFollowupResolution($nativeCouncilIntent, true);
                }
                $generation = $lockedLab->generations()->create([
                    'generation' => $number, 'trigger_type' => $trigger,
                    'trigger_context' => ['previous_generation' => $latestInTransaction?->generation, 'created_by' => 'learning_trigger',
                        'data_count' => $snapshot['count'], 'latest_candle' => $snapshot['latest'], 'new_candles' => $newCandles,
                        'generation_protocol' => self::GENERATION_PROTOCOL,
                        'council_protocol' => $roleComplete ? self::ROLE_COMPLETE_COUNCIL_PROTOCOL : null,
                        'role_complete_council' => $roleComplete,
                        'native_specialist_council_intent' => $nativeCouncilIntent,
                        'authorized_specialist_council_panel_intent' => $authorizedCouncilPanelIntent,
                        'specialist_council_authorized_panel' => $authorizedCouncilPanelIntent === null ? null
                            : $this->authorizedCouncilPanelMarker($authorizedCouncilPanelIntent),
                        'canonical_data_contract' => $roleComplete
                            ? app(MarketDriftDetectionService::class)->canonicalDataContract($lockedLab->symbol, $lockedLab->timeframe)
                            : null,
                        'data_edge_audit' => $trigger === 'data_edge_audit'
                            ? data_get($latestInTransaction?->trigger_context, 'data_edge_audit')
                            : null,
                        'coverage_rescue_audit' => $trigger === 'coverage_rescue' ? $coverageRescue : null,
                        'targeted_failure_profile' => $targetedFailureProfile,
                        'learning_velocity_gate' => $learningVelocity,
                        'generation_admission_decision' => $generationAdmission,
                        'historical_research_admission' => $historicalAdmission,
                        'freshness_admission' => (string) data_get($generationAdmission, 'decision')
                            === GenerationAdmissionDecisionService::OPEN_STRUCTURAL_ESCAPE ? [
                                'status' => 'bounded_structural_escape_on_existing_snapshot',
                                'new_candles' => $newCandles,
                                'minimum_fresh_candles' => strtoupper((string) $lockedLab->timeframe) === 'M15' ? 96 : 24,
                                'independent_confirmation_still_required' => true,
                                'promotion_evidence' => false,
                            ] : null,
                        'shadow_research_lane' => $shadowResearch ? $shadowResearchPosture : null,
                        'portfolio_failure_curriculum' => $roleComplete ? [] : $this->portfolioFailureCurriculum($lockedLab),
                        'portfolio_council_curriculum' => $roleComplete
                            ? $this->roleCouncilCurriculumSnapshot($lockedLab)
                            : $this->portfolioCouncilCurriculum($lockedLab)],
                    'data_fingerprint' => $fingerprint, 'population_size' => $plannedPopulationSize,
                    'status' => 'draft', 'started_at' => now(),
                ]);

                // Fixed, auditable experiment budget.  A slot is assigned for the
                // gate it is meant to move; it is not an undifferentiated "more
                // agents" budget.
                $plan = $authorizedCouncilPanelIntent !== null ? $this->authorizedCouncilPanelSeedPlan($authorizedCouncilPanelIntent) : ($nativeCouncilIntent !== null
                    ? $this->nativeCouncilSeedPlan($lockedLab, $nativeCouncilIntent)
                    : $this->generationPlan($lockedLab, $coverageRescue, $roleComplete, $populationLimit, $targetedFailureTargets, $targetedFailureProfile));
                if ($learningConfirmation && $causalRepairFrontier) {
                    $lockedFrontier = app(CausalRepairFrontierService::class)->eligible(
                        $lockedLab->symbol,
                        $lockedLab->timeframe,
                        (int) data_get($causalRepairFrontier, 'source_experiment_id'),
                    );
                    if (! $lockedFrontier) {
                        $generation->delete();

                        return $this->blocked('CAUSAL_REPAIR_FRONTIER_SOURCE_CHANGED', true);
                    }
                    $causalSeed = app(CausalRepairFrontierService::class)->seedPlan($lockedFrontier);
                    $plan = array_values([
                        ...$causalSeed,
                        ...array_slice($plan, count($causalSeed)),
                    ]);
                } elseif ($learningConfirmation && $confirmationLesson) {
                    $lockedLesson = app(CausalLearningCohortPlannerService::class)->eligibleLesson(
                        $lockedLab->symbol,
                        $lockedLab->timeframe,
                        (string) $confirmationLesson->strategy_family,
                        (int) $confirmationLesson->id,
                    );
                    if (! $lockedLesson) {
                        $generation->delete();

                        return $this->blocked('CAUSAL_LEARNING_CONFIRMATION_SOURCE_CHANGED', true);
                    }
                    $causalSeed = app(CausalLearningCohortPlannerService::class)->seedPlan($lockedLesson);
                    // A causal confirmation is no longer reported as a tiny
                    // generation. Reserve its exact three roles at the front of
                    // the normal population and retain the remaining discovery
                    // seats. Only those three roles may enter expensive replay.
                    $plan = array_values([
                        ...$causalSeed,
                        ...array_slice($plan, count($causalSeed)),
                    ]);
                }
                if ($learningConfirmation && $prospectiveRepair) {
                    $lockedSource = app(ProspectiveRepairExperimentService::class)->eligible(
                        $lockedLab->symbol, $lockedLab->timeframe, (int) $prospectiveRepair['source_pair_id']);
                    if ($lockedSource === null || $lockedSource['source_hash'] !== $prospectiveRepair['source_hash']) {
                        $generation->delete();
                        return $this->blocked('PROSPECTIVE_REPAIR_SOURCE_CHANGED', true);
                    }
                    $causalSeed = app(ProspectiveRepairExperimentService::class)->seedPlan($lockedSource);
                    $plan = array_values([...$causalSeed, ...array_slice($plan, 3)]);
                }
                if ($populationLimit !== null) {
                    $limit = $roleComplete ? max(4, (int) $populationLimit) : max(1, (int) $populationLimit);
                    $plan = array_slice($plan, 0, $limit);
                }
                $baseGenerationPlan = $plan;
                $preAdaptivePolicy = $this->evolutionGovernor->generationSnapshot($lockedLab, $plan);
                if ($populationLimit === null && ! $roleComplete && ! data_get($coverageRescue, 'eligible')) {
                    $plan = $this->evolutionGovernor->adaptPlan($plan, $preAdaptivePolicy);
                }
                // Recompute only the planned-origin projection after adaptation;
                // the observed metrics must remain tied to the same lookback
                // history and are retained in the policy for auditability.
                $adaptiveEvolutionPolicy = $this->evolutionGovernor->generationSnapshot($lockedLab, $plan);
                $adaptiveEvolutionPolicy['base_generation_plan'] = $baseGenerationPlan;
                $adaptiveEvolutionPolicy['adaptive_plan_changed'] = $baseGenerationPlan !== $plan;
                $adaptiveEvolutionPolicy['adaptive_plan_protocol'] = 'champion_guided_adaptive_budget_v1';
                $adaptiveEvolutionPolicy['plan_change_rule'] = 'protect causal floor; allocate remaining seats to robust, architecture and curiosity lanes under stagnation, concentration or drift pressure';
                $adaptiveEvolutionPolicy['quality_diversity_operating_system'] = app(EvolutionOperatingSystemService::class)->blueprint($lockedLab);
                // Directors are persisted only as part of an already-authorized
                // generation build. They never run from a monitor/snapshot path.
                $adaptiveEvolutionPolicy['meta_evolution_directors'] = app(EvolutionPortfolioService::class)->directorPlan($lockedLab);
                $shadowAllocation = $shadowResearch
                    ? $this->shadowResearch->allocation(count($plan), $targetedRescueBlocked)
                    : null;
                $adaptiveEvolutionPolicy['research_allocation_budget'] = $shadowResearch
                    ? $this->researchAllocation->shadowContract($targetedRescueBlocked, count($plan))
                    : $this->researchAllocation->contract($targetedRescueBlocked, count($plan));
                if (! $roleComplete && $populationLimit === null && ! data_get($coverageRescue, 'eligible')) {
                    // The governor may reorder or replace reserve lanes. Reapply
                    // the executable five-target core after that decision so an
                    // adaptive plan cannot reintroduce an invalid filler target.
                    $plan = $this->fillNormalCouncilCore($plan, $lockedLab, $plannedPopulationSize);
                }
                if ($shadowResearch) {
                    $plan = $this->shadowResearch->applyAllocation(
                        $plan,
                        $shadowResearchPosture,
                        (int) $generation->id,
                        $targetedRescueBlocked,
                    );
                    $adaptiveEvolutionPolicy['shadow_research_lane'] = $shadowResearchPosture;
                    $shadowAllocation = $this->shadowResearch->allocation(count($plan), $targetedRescueBlocked);
                    $adaptiveEvolutionPolicy['smart_courage_allocation'] = $shadowAllocation;
                    $adaptiveEvolutionPolicy['research_allocation_budget'] = $this->researchAllocation->shadowContract(
                        $targetedRescueBlocked,
                        count($plan),
                    );
                }
                $contextualCouncilAllocation = null;
                // A controlled rescue is recompiled from its sealed research
                // curriculum after generic planning. The current structural
                // mode then allocates those templates into dynamic causal
                // blocks; five-by-four survives only as an explicit legacy
                // fallback and can never silently retake the active path.
                if ($controlledRescue
                    && (string) data_get($targetedFailureProfile, 'protocol') === self::TARGETED_RESCUE_PROFILE_PROTOCOL) {
                    $plan = match ((string) data_get($targetedFailureProfile, 'cohort_mode')) {
                        'four_siblings_plus_control_v1' => $this->anchorSiblingPlan($lockedLab, $targetedFailureProfile ?? [], $plannedPopulationSize),
                        StructuralResearchCohortService::COHORT_MODE => (function () use ($lockedLab, $generation, $targetedFailureProfile, &$contextualCouncilAllocation, &$adaptiveEvolutionPolicy): array {
                            $seedPlan = app(StructuralResearchCohortService::class)->plan($lockedLab, $targetedFailureProfile ?? []);
                            $contextualCouncilAllocation = app(ContextualCouncilAllocatorService::class)->allocate(
                                $seedPlan,
                                $lockedLab,
                                $targetedFailureProfile ?? [],
                                (int) $generation->generation,
                            );
                            $adaptiveEvolutionPolicy['contextual_council_allocation'] = data_get($contextualCouncilAllocation, 'contract');

                            return (array) data_get($contextualCouncilAllocation, 'plan', $seedPlan);
                        })(),
                        StructuralResearchCohortService::LEGACY_COHORT_MODE => app(StructuralResearchCohortService::class)->plan($lockedLab, $targetedFailureProfile ?? []),
                        default => $this->fiveByFourTargetedFailurePlan($lockedLab, $targetedFailureProfile ?? []),
                    };
                }
                if (! $controlledRescue && (string) data_get($targetedFailureProfile, 'cohort_mode') === 'four_siblings_plus_control_v1') {
                    $plan = $this->anchorSiblingPlan($lockedLab, $targetedFailureProfile ?? [], $plannedPopulationSize);
                }
                // Five-by-four remains a sealed rescue/fallback scaffold only.
                // An ordinary twenty-seat generation reaches the cooperative
                // allocator without being rebalanced into permanent quotas.
                $specialPurposeAllocation = $controlledRescue
                    || (bool) data_get($coverageRescue, 'eligible', false)
                    || $roleComplete
                    || $populationLimit !== null
                    || count($plan) !== 20;
                $cooperativeExperimentPlan = collect($plan)->contains(fn (array $slot): bool => data_get($slot, 'niche.cooperative_experiment_block.protocol') === CooperativeContextualEvolutionCouncilService::PROTOCOL
                );
                $plan = (string) data_get($targetedFailureProfile, 'cohort_mode') === 'four_siblings_plus_control_v1'
                    ? $this->assignAnchorCohortSeats($plan)
                    : ($specialPurposeAllocation && ! $cooperativeExperimentPlan
                        ? $this->assignPopulationGroupSeats($plan)
                        : array_values($plan));
                $rootExperimentPortfolio = null;
                if ($trigger === 'data_edge_audit'
                    && ! $shadowResearch
                    && ! $controlledRescue
                    && ! $roleComplete
                    && $populationLimit === null
                    && count($plan) >= 20) {
                    $rootExperimentPortfolio = $this->rootExperimentPortfolioPlan($plan, $lockedLab);
                    $plan = (array) data_get($rootExperimentPortfolio, 'plan', $plan);
                    $adaptiveEvolutionPolicy['root_experiment_portfolio'] = data_get($rootExperimentPortfolio, 'contract');
                }
                $normalStructuralResearch = null;
                if (! $shadowResearch
                    && ! $controlledRescue
                    && ! (bool) data_get($coverageRescue, 'eligible', false)
                    && ! $roleComplete
                    && $populationLimit === null
                    && count($plan) >= 4
                    && $rootExperimentPortfolio === null) {
                    $normalStructuralResearch = $this->normalStructuralResearchPlan($plan, $lockedLab);
                    $plan = (array) data_get($normalStructuralResearch, 'plan', $plan);
                    $adaptiveEvolutionPolicy['normal_structural_research'] = data_get($normalStructuralResearch, 'contract');
                }
                // Select immutable strategy/tactic/risk compositions before the
                // paired-control planner seals its baseline/candidate mapping.
                // Small recovery cohorts keep their separately approved protocol.
                if (! $shadowResearch
                    && ! $controlledRescue
                    && ! (bool) data_get($coverageRescue, 'eligible', false)
                    && ! $roleComplete
                    && $populationLimit === null
                    // The root audit is already a sealed strategy/tactic/risk
                    // portfolio. Re-composing it would rewrite declared genes and
                    // make its own post-pairing integrity contract fail.
                    && $rootExperimentPortfolio === null) {
                    // Receipts affect the NEXT population only through this
                    // bounded director. It labels an exploit/repair seat only
                    // when a scoped receipt exists; otherwise it degrades to an
                    // explicit explorer rather than pretending a raw PnL is
                    // inheritable knowledge.
                    $composition = $this->compositionPlanner->materialize(
                        $plan,
                        (int) $generation->generation,
                        (array) $lockedLab->strategy_families,
                        $this->lineageContinuationFamilies($lockedLab),
                    );
                    $plan = $composition['plan'];
                    $adaptiveEvolutionPolicy['smart_composition_cohort'] = $composition['contract'];
                    if ((string) data_get($composition, 'contract.status') === 'not_admitted') {
                        $generation->delete();

                        return $this->blocked('SMART_COMPOSITION_COHORT_NOT_ADMITTED', false, [
                            'smart_composition_cohort' => $composition['contract'],
                        ]);
                    }
                    // Composition owns the final family/runtime identity. Apply
                    // contextual learning afterwards so a library rotation
                    // cannot move a receipt onto an incompatible family.
                    $learningDirected = app(EvolutionDirectorService::class)->materialize(
                        $plan,
                        $lockedLab->symbol,
                        $lockedLab->timeframe,
                        (int) $generation->generation,
                    );
                    $plan = array_map(function (array $slot): array {
                        $directive = (array) data_get($slot, 'niche.learning_evolution', []);
                        $passport = (array) data_get($slot, 'niche.composition_passport', []);
                        if ($directive !== [] && $passport !== []) {
                            data_set($slot, 'niche.composition_passport', app(CompositionAuthorityKernelService::class)
                                ->bindLearningDirective($passport, $directive));
                        }

                        return $slot;
                    }, $learningDirected['plan']);
                    $adaptiveEvolutionPolicy['learning_driven_evolution'] = $learningDirected['contract'];
                }
                $causalLearningCohort = null;
                if ($learningConfirmation && $prospectiveRepair) {
                    // Generic portfolio/governor transforms may discover new
                    // lanes, but cannot own the three preregistered treatments.
                    $plan = array_values([...app(ProspectiveRepairExperimentService::class)->seedPlan($prospectiveRepair),
                        ...array_slice($plan, 3)]);
                }
                if (! $shadowResearch
                    && ! $controlledRescue
                    && ! (bool) data_get($coverageRescue, 'eligible', false)
                    && ! $roleComplete
                    && $nativeCouncilIntent === null
                    && $authorizedCouncilPanelIntent === null
                    && count($plan) >= 3) {
                    $causalLearningCohort = app(CausalLearningCohortPlannerService::class)->materialize(
                        $plan,
                        $lockedLab->symbol,
                        $lockedLab->timeframe,
                        (int) $generation->id,
                    );
                    $plan = (array) data_get($causalLearningCohort, 'plan', $plan);
                    $plan = array_map(function (array $slot): array {
                        $experiment = (array) data_get($slot, 'niche.causal_learning_cohort', []);
                        $passport = (array) data_get($slot, 'niche.composition_passport', []);
                        if ($experiment !== [] && $passport !== []) {
                            data_set($slot, 'niche.composition_passport', app(CompositionAuthorityKernelService::class)
                                ->bindLearningExperiment($passport, $experiment));
                        }

                        return $slot;
                    }, $plan);
                    $adaptiveEvolutionPolicy['causal_learning_counterfactual_cohort'] = data_get($causalLearningCohort, 'contract');
                    if ($learningConfirmation && (string) data_get($causalLearningCohort, 'contract.status') !== 'materialized') {
                        $generation->delete();

                        return $this->blocked('CAUSAL_LEARNING_CONFIRMATION_NOT_MATERIALIZED', false, [
                            'causal_learning_cohort' => data_get($causalLearningCohort, 'contract'),
                        ]);
                    }
                }
                if ($contextualCouncilAllocation === null
                    && ! $controlledRescue
                    && $rootExperimentPortfolio === null
                    && (string) data_get($targetedFailureProfile, 'cohort_mode') !== 'four_siblings_plus_control_v1'
                    && ! (bool) data_get($coverageRescue, 'eligible', false)
                    && ! $roleComplete
                    && $populationLimit === null
                    // The cooperative allocator preserves a pre-registered
                    // causal trio verbatim, compiles sixteen of the remaining
                    // seats into eight contextual blocks and turns the final
                    // odd seat into explicit uncertainty abstention. Causal
                    // proof and contextual discovery therefore coexist in one
                    // twenty-seat organism instead of falling back to generic
                    // non-contextual pairing.
                    && count($plan) === 20) {
                    $contextualCouncilAllocation = app(ContextualCouncilAllocatorService::class)->allocate(
                        $plan,
                        $lockedLab,
                        $adaptiveEvolutionPolicy,
                        (int) $generation->generation,
                    );
                    $plan = (array) data_get($contextualCouncilAllocation, 'plan', $plan);
                    $adaptiveEvolutionPolicy['contextual_council_allocation'] = data_get($contextualCouncilAllocation, 'contract');
                }
                // Bind before control pairing so pair hashes and family
                // contracts are compiled from the passport-owned runtime,
                // not from a contextual host that merely copied the seat.
                $compositionConstructorBinding = $this->compositionPlanner->bindRuntimeOwnership($plan);
                $plan = $compositionConstructorBinding['plan'];
                $adaptiveEvolutionPolicy['composition_constructor_binding'] = $compositionConstructorBinding['contract'];
                if ((string) data_get($compositionConstructorBinding, 'contract.status') === 'not_admitted') {
                    $generation->delete();

                    return $this->blocked('COMPOSITION_CONSTRUCTOR_BINDING_NOT_ADMITTED', false, [
                        'composition_constructor_binding' => $compositionConstructorBinding['contract'],
                    ]);
                }
                $normalControlPairing = null;
                if (! ($learningConfirmation && $prospectiveRepair)
                    && (! $controlledRescue || $cooperativeExperimentPlan)
                    && ! (bool) data_get($coverageRescue, 'eligible', false)
                    && ! $roleComplete
                    && $nativeCouncilIntent === null
                    && $authorizedCouncilPanelIntent === null
                    && count($plan) >= 2) {
                    $normalControlPairing = $this->researchAllocation->materializeNormalControlPairing(
                        $plan,
                        $lockedLab->symbol,
                        $lockedLab->timeframe,
                        (int) $generation->id,
                    );
                    $plan = (array) data_get($normalControlPairing, 'plan', $plan);
                    $adaptiveEvolutionPolicy['normal_control_pairing'] = data_get($normalControlPairing, 'contract');

                    // A normal generation is not allowed to exist with a
                    // control-only execution lane or without its exact paired
                    // candidate. The generation ID was reserved for pair keys,
                    // so explicitly remove this still-empty row before returning.
                    // A plain closure return commits the transaction and would
                    // otherwise leave a zero-agent draft that blocks the lab.
                    if (! (bool) data_get($normalControlPairing, 'contract.allowed', false)) {
                        $generation->delete();

                        return $this->blocked('NORMAL_CONTROL_PAIRING_NOT_ADMITTED', false, [
                            'control_pairing_contract' => data_get($normalControlPairing, 'contract'),
                        ]);
                    }
                }
                // Both exact 1:1 control pairing and causal triplet
                // materialization run after structural planning and may
                // consume/replace structural seats. Re-check the FINAL
                // mutation-eligible plan here. Proof seats are excluded from
                // the paired-discovery budget; an odd remainder is an explicit
                // uncertainty abstention, never an unpaired mutation.
                if ($normalStructuralResearch !== null) {
                    $finalStructuralRepair = $this->ensureFinalStructuralFloor($plan);
                    $plan = $finalStructuralRepair['plan'];
                    if ($finalStructuralRepair['repaired']) {
                        $adaptiveEvolutionPolicy['normal_structural_research']['final_structural_floor_repair'] = $finalStructuralRepair['audit'];
                    }
                }
                $rootPortfolioIntegrity = null;
                if ($rootExperimentPortfolio !== null) {
                    // Pairing is allowed to attach an execution contract, but it
                    // must never rewrite a sealed root seat. Validate the final,
                    // paired plan rather than trusting the pre-pairing intent.
                    $rootPortfolioIntegrity = $this->rootPortfolioIntegrityContract($plan);
                    $adaptiveEvolutionPolicy['root_portfolio_integrity'] = $rootPortfolioIntegrity;
                    if (! (bool) data_get($rootPortfolioIntegrity, 'allowed', false)) {
                        $generation->delete();

                        return $this->blocked('ROOT_PORTFOLIO_INTEGRITY_FAILED');
                    }
                }
                if ($normalStructuralResearch !== null || $rootExperimentPortfolio !== null) {
                    $mutationDiversity = $this->normalMutationDiversityContract($plan);
                    $adaptiveEvolutionPolicy['mutation_diversity_contract'] = $mutationDiversity;
                    // A normal cohort with a scalar-heavy or incomplete structural
                    // plan is not an evolution cohort. Abort before model/queue
                    // persistence so it cannot inflate generation counts while
                    // testing the same wait/threshold family again.
                    if (! (bool) data_get($mutationDiversity, 'allowed', false)) {
                        $generation->delete();

                        return $this->blocked('MUTATION_DIVERSITY_CONTRACT_FAILED', false, [
                            'mutation_diversity' => $mutationDiversity,
                        ]);
                    }
                }
                $plan = $this->researchAllocation->annotatePlan($plan, $targetedRescueBlocked, $shadowAllocation);
                $plan = $this->reallocateBlockedTargetedPrerequisites($plan, $targetedRescueBlocked);
                // Contextual allocation and exact-control pairing may copy a
                // composition seat into a different host slot. Re-bind the
                // FINAL constructor inputs to the frozen passport so family,
                // strategy architecture and tactic cannot drift afterwards.
                $compositionRuntimeOwnership = $this->compositionPlanner->bindRuntimeOwnership($plan);
                $plan = $compositionRuntimeOwnership['plan'];
                $adaptiveEvolutionPolicy['composition_runtime_ownership'] = $compositionRuntimeOwnership['contract'];
                if ((string) data_get($compositionRuntimeOwnership, 'contract.status') === 'not_admitted') {
                    $generation->delete();

                    return $this->blocked('COMPOSITION_RUNTIME_OWNERSHIP_NOT_ADMITTED', false, [
                        'composition_runtime_ownership' => $compositionRuntimeOwnership['contract'],
                    ]);
                }
                $structuralValidation = null;
                if ((string) data_get($targetedFailureProfile, 'cohort_mode') === StructuralResearchCohortService::COHORT_MODE) {
                    $structuralValidation = app(StructuralResearchCohortService::class)->validatePlan($plan);
                    if (! (bool) data_get($structuralValidation, 'allowed', false)) {
                        $generation->delete();

                        return $this->blocked('STRUCTURAL_PLAN_VALIDATION_FAILED');
                    }
                }
                $populationGroupContract = $this->populationGroupContract(
                    $plan,
                    (array) data_get($contextualCouncilAllocation, 'contract', []),
                );
                // This timestamped protocol boundary is written only for new
                // generations. Historical rows are intentionally not
                // backfilled, so old evidence can seed a hypothesis but can
                // never inflate the post-v2 verified denominator.
                $learningProtocolEpoch = app(LearningProtocolEpochService::class)->generationContract();
                $priorGroupCheckpoints = $this->latestGroupCheckpoints($lockedLab);
                $generation->update(['trigger_context' => [
                    ...($generation->trigger_context ?? []),
                    'generation_plan' => $plan,
                    // The plan remains a construction draft until every seat
                    // has been created. A legal zero-diff replacement may
                    // still change one spec during that phase. The immutable
                    // contract is therefore compiled only at the terminal
                    // constructor boundary below, never prematurely.
                    'immutable_generation_contract' => null,
                    'adaptive_evolution_policy' => $adaptiveEvolutionPolicy,
                    'research_allocation_budget' => $this->researchAllocation->audit($plan, $targetedRescueBlocked, $shadowAllocation),
                    'control_pairing_contract' => data_get($normalControlPairing, 'contract'),
                    'structural_research_contract' => data_get($normalStructuralResearch, 'contract'),
                    'normal_structural_research_expected' => $normalStructuralResearch !== null,
                    'shadow_research_lane' => $shadowResearch ? [
                        ...$shadowResearchPosture,
                        'generation_id' => (int) $generation->id,
                        'trigger' => 'shadow_research',
                    ] : null,
                    'population_group_contract' => $populationGroupContract,
                    'learning_protocol_epoch' => $learningProtocolEpoch,
                    'group_checkpoint_inputs' => $priorGroupCheckpoints,
                    'specialist_council_contract' => [
                        'protocol' => self::SPECIALIST_COUNCIL_PROTOCOL,
                        'contextual_allocator' => data_get($contextualCouncilAllocation, 'contract'),
                        'global_champion_forbidden' => true,
                        'member_model' => 'complementary_specialists_by_group_and_semantic_cell',
                        'parameter_specialist_rule' => 'Each member may own a bounded parameter/skill niche; group progress is measured as a frontier, not a singleton score.',
                        'combined_activation' => 'individual_passports_then_council_quorum',
                        'promotion_evidence' => false,
                    ],
                    'controlled_rescue_admission' => $controlledRescue ? [
                        'protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
                        'profile_protocol' => data_get($targetedFailureProfile, 'protocol'),
                        'temporary' => true,
                        'normal_generation_creation_still_paused' => $this->protocolSafety->generationCreationPaused(),
                        'promotion_evidence' => false,
                    ] : null,
                    'rescue_circuit_breaker' => $rescueAdmission,
                    'independent_evidence_admission' => $independentEvidenceAdmission,
                    // Structural cohort validation is a separate rescue
                    // projection.  For normal research it must not overwrite the
                    // executable structural-research contract with null.
                    'structural_research_contract' => $structuralValidation
                        ?? data_get($normalStructuralResearch, 'contract'),
                    'group_checkpoint_rule' => [
                        'protocol' => self::POPULATION_GROUP_PROTOCOL,
                        'checkpoint_advances_only_from' => ['challenger', 'forward_validated', 'paper', 'champion'],
                        'screening_and_quarantine_are_diagnostic_only' => true,
                        'exact_semantic_parent_rule_unchanged' => true,
                        'promotion_evidence' => false,
                    ],
                ]]);
                app(LearningProtocolEpochService::class)->registerGeneration(
                    $generation->fresh(),
                    $lockedLab->symbol,
                    $lockedLab->timeframe,
                );
                $this->historicalLearning->recordGenerationConsumption($generation, $plan);

                return [
                    'generation_id' => $generation->id,
                    'plan' => $plan,
                ];
            });

            if ($buildState === null) {
                return null;
            }

            $generation = LabGeneration::query()->with('laboratory')->findOrFail((int) $buildState['generation_id']);
            $plan = (array) ($buildState['plan'] ?? []);
            $createdAgents = 0;
            $initialSeatBudget = min(
                count($plan),
                max(1, (int) config('services.lab_selection.constructor_initial_seat_budget', 3)),
            );
            $this->publishConstructorProgress(
                $symbol,
                $timeframe,
                'build',
                $trigger,
                (int) $generation->id,
                count($plan),
                0,
                $plan === [] ? null : 1,
            );
            $constructionFailures = [];
            $constructionReplacements = array_values((array) data_get(
                $generation->trigger_context,
                'constructor_continuation.replacements',
                [],
            ));
            foreach ($plan as $index => $spec) {
                if ($index >= $initialSeatBudget) {
                    break;
                }
                // Each child is atomic on its own. Completed siblings remain
                // visible as durable construction progress, while a cancelled
                // child cannot leave half of its model/link/archive writes.
                $attemptSpec = $spec;
                $failureReason = null;
                $this->publishConstructorProgress(
                    $symbol,
                    $timeframe,
                    'build',
                    $trigger,
                    (int) $generation->id,
                    count($plan),
                    $createdAgents,
                    $index + 1,
                );
                try {
                    $created = DB::transaction(function () use ($generation, $attemptSpec, $index, &$failureReason): bool {
                        $currentGeneration = LabGeneration::query()->with('laboratory')->findOrFail($generation->id);

                        return $this->createAgent(
                            $currentGeneration,
                            $attemptSpec['family'],
                            $attemptSpec['origin'],
                            $index + 1,
                            $attemptSpec['target'],
                            $attemptSpec['niche'] ?? null,
                            $attemptSpec['history'] ?? null,
                            $attemptSpec['research_group'] ?? null,
                            (int) ($attemptSpec['group_seat'] ?? 0),
                            $failureReason,
                        );
                    });
                } catch (\Throwable $exception) {
                    report($exception);
                    $created = false;
                    $failureReason = 'CONSTRUCTOR_EXCEPTION: '.substr($exception->getMessage(), 0, 500);
                }
                if (! $created && $this->canReplaceMutationConstruction($attemptSpec, $failureReason)) {
                    foreach ($this->zeroDiffReplacementSpecs($plan, $index, $attemptSpec, $failureReason) as $replacementSpec) {
                        $replacementFailure = null;
                        try {
                            $replacementCreated = DB::transaction(function () use ($generation, $replacementSpec, $index, &$replacementFailure): bool {
                                $currentGeneration = LabGeneration::query()->with('laboratory')->findOrFail($generation->id);

                                return $this->createAgent(
                                    $currentGeneration,
                                    $replacementSpec['family'],
                                    $replacementSpec['origin'],
                                    $index + 1,
                                    $replacementSpec['target'],
                                    $replacementSpec['niche'] ?? null,
                                    $replacementSpec['history'] ?? null,
                                    $replacementSpec['research_group'] ?? null,
                                    (int) ($replacementSpec['group_seat'] ?? 0),
                                    $replacementFailure,
                                );
                            });
                        } catch (\Throwable $exception) {
                            report($exception);
                            $replacementCreated = false;
                            $replacementFailure = 'CONSTRUCTOR_EXCEPTION: '.substr($exception->getMessage(), 0, 500);
                        }
                        if (! $replacementCreated) {
                            $failureReason = $replacementFailure ?: $failureReason;

                            continue;
                        }

                        $created = true;
                        $plan[$index] = $replacementSpec;
                        $constructionReplacements[] = [
                            'slot' => $index + 1,
                            'from_gene' => data_get($attemptSpec, 'niche.declared_gene'),
                            'to_gene' => data_get($replacementSpec, 'niche.declared_gene'),
                            'kind' => data_get($replacementSpec, 'niche.sibling_kind'),
                            'reason' => 'zero_diff_or_exhausted_gene_replaced_before_persistence',
                            'promotion_evidence' => false,
                        ];
                        break;
                    }
                }
                if ($created) {
                    $createdAgents++;
                } else {
                    $constructionFailures[] = [
                        'slot' => $index + 1,
                        'family' => $attemptSpec['family'],
                        'origin' => $attemptSpec['origin'],
                        'target' => $attemptSpec['target'],
                        'reason' => $failureReason ?: 'no_legal_nonzero_mutation',
                        'promotion_evidence' => false,
                    ];
                }
                $this->publishConstructorProgress(
                    $symbol,
                    $timeframe,
                    'build',
                    $trigger,
                    (int) $generation->id,
                    count($plan),
                    $createdAgents,
                    $index + 1 < $initialSeatBudget ? $index + 2 : null,
                );
            }
            $generation->update([
                'population_size' => $createdAgents,
                'trigger_context' => [
                    ...((array) $generation->fresh()->trigger_context),
                    'constructor_audit' => [
                        'protocol' => 'agent_constructor_invariant_v1',
                        'planned_slots' => count($plan),
                        'created_agents' => $createdAgents,
                        'skipped_zero_diff_slots' => $constructionFailures,
                        'replacements' => $constructionReplacements,
                        'rule' => 'No zero-diff child is persisted; a blocked experiment is skipped and remains diagnostic only.',
                        'promotion_evidence' => false,
                    ],
                    'generation_plan' => $plan,
                ],
            ]);

            // Never let a partially constructed cohort enter the replay queue.
            // Its incomplete slots are technical construction evidence, not
            // strategy failures and not a smaller valid population. This applies
            // to ordinary audited cohorts as well as rescue/shadow lanes.
            if ($createdAgents !== count($plan)) {
                $context = (array) $generation->fresh()->trigger_context;
                $abortKey = $controlledRescue
                    ? 'controlled_rescue_constructor_abort'
                    : ((string) $generation->trigger_type === 'shadow_research'
                        ? 'shadow_research_constructor_abort'
                        : 'constructor_contract_abort');
                $context[$abortKey] = [
                    'protocol' => $controlledRescue
                        ? LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL
                        : ((string) $generation->trigger_type === 'shadow_research'
                            ? 'shadow_research_constructor_v1'
                            : 'agent_constructor_invariant_v1'),
                    'planned_slots' => count($plan),
                    'created_agents' => $createdAgents,
                    'reason_code' => $controlledRescue
                        ? 'INCOMPLETE_CONTROLLED_RESCUE_POPULATION'
                        : ((string) $generation->trigger_type === 'shadow_research'
                            ? 'INCOMPLETE_SHADOW_RESEARCH_POPULATION'
                            : 'INCOMPLETE_GENERATION_POPULATION'),
                    'lane' => $controlledRescue
                        ? 'controlled_rescue'
                        : ((string) $generation->trigger_type === 'shadow_research' ? 'shadow_research' : 'audited_generation'),
                    'promotion_evidence' => false,
                ];
                $generation->update([
                    'status' => 'technical_quarantine',
                    'completed_at' => now(),
                    'trigger_context' => $context,
                ]);
                $generation->agents()
                    ->whereIn('lifecycle_status', ['draft', 'queued'])
                    ->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => 'Generation construction incomplete; candidate quarantined before replay and strategy verdict withheld.',
                    ]);
            }

            $freshGeneration = $this->finalizeLineageContinuationContract($generation);
            if ($authorizedCouncilPanelIntent !== null && $createdAgents === count($plan)
                && (bool) data_get($freshGeneration->trigger_context, 'lineage_continuation_contract.allowed', false)) {
                $freshGeneration->update(['status' => 'research_reserved', 'completed_at' => null]);
                $freshGeneration = $freshGeneration->fresh(['agents.modelVersion']);
            }
            $this->lastBuildOutcome = [
                'status' => $freshGeneration->status === 'technical_quarantine' ? 'blocked' : 'created',
                'reason_code' => $freshGeneration->status === 'technical_quarantine'
                    ? 'INCOMPLETE_GENERATION_CONSTRUCTION'
                    : 'GENERATION_CREATED',
                'retryable' => $freshGeneration->status === 'technical_quarantine',
                'context' => [
                    'generation_id' => (int) $freshGeneration->id,
                    'generation' => (int) $freshGeneration->generation,
                    'status' => (string) $freshGeneration->status,
                    'planned_agents' => count($plan),
                    'created_agents' => $createdAgents,
                ],
            ];

            return $freshGeneration;
        } finally {
            try {
                if ($this->nativeFollowupSourceFenceScope !== null) {
                    $this->nativeFollowupFeedbackOwner->endConstructorSourceFenceScope($this->nativeFollowupSourceFenceScope);
                }
            } finally {
                $this->nativeFollowupSourceFenceScope = null;
                $this->nativeFollowupFeedbackOwner = null;
                $this->nativeFollowupInvocationProof = null;
                $this->nativeFollowupConstructorInvocation = false;
                optional($constructorLock)->release();
                Cache::forget($this->constructorOwnerKey($symbol, $timeframe));
            }
        }
    }

    /**
     * Finish an interrupted constructor in bounded, idempotent chunks.
     *
     * Production has a deliberately short CLI supervision budget while an
     * individual candidate still performs lineage/archive checks.  A serial
     * twenty-seat constructor could therefore time out after several valid
     * children.  Those children must never be dispatched as a smaller cohort,
     * but rebuilding them from scratch both wastes the immutable work and can
     * recreate the same experiment.  This continuation creates only missing
     * planned slots and re-opens the generation only after the exact plan is
     * complete.
     *
     * @return array<string, mixed>
     */
    public function continueInterruptedConstruction(int $generationId, int $maxSeats = 3): array
    {
        $this->parentPerformanceSnapshots = [];
        $this->archiveFrontierSnapshots = [];
        $this->archiveMigrationSnapshots = [];
        $generation = LabGeneration::query()->with(['laboratory', 'agents.modelVersion'])->findOrFail($generationId);
        $latestGenerationId = (int) $generation->laboratory->generations()->latest('generation')->value('id');
        if ($latestGenerationId !== (int) $generation->id) {
            return [
                'status' => 'superseded_by_newer_generation',
                'generation' => $generation,
                'created_slots' => [],
                'completed_slots' => [],
                'failures' => [],
            ];
        }
        $this->recoverStaleConstructorLease(
            (string) $generation->laboratory->symbol,
            (string) $generation->laboratory->timeframe,
        );
        $constructorLock = Cache::lock(
            $this->constructorLockKey((string) $generation->laboratory->symbol, (string) $generation->laboratory->timeframe),
            self::CONSTRUCTOR_LOCK_TTL_SECONDS,
        );
        if (! $constructorLock->get()) {
            return [
                'status' => 'constructor_active',
                'generation' => $generation,
                'created_slots' => [],
                'completed_slots' => [],
                'failures' => [],
            ];
        }
        $this->nativeFollowupConstructorInvocation = true;
        $this->nativeFollowupInvocationProof = null;
        $constructorSymbol = (string) $generation->laboratory->symbol;
        $constructorTimeframe = (string) $generation->laboratory->timeframe;
        try {
            if (data_get($generation->trigger_context, 'native_specialist_council_intent.followup_work_item_id')) {
                $this->nativeFollowupFeedbackOwner = app(SpecialistCouncilResearchFeedbackService::class);
                $this->nativeFollowupSourceFenceScope = $this->nativeFollowupFeedbackOwner->beginConstructorSourceFenceScope();
            }
            Cache::put(
                $this->constructorOwnerKey($constructorSymbol, $constructorTimeframe),
                $this->constructorOwner('continuation', (string) $generation->trigger_type, (int) $generation->id),
                now()->addSeconds(self::CONSTRUCTOR_LOCK_TTL_SECONDS),
            );
            $plan = array_values((array) data_get($generation->trigger_context, 'generation_plan', []));
            // Repair the one pre-deployment reservation created with a verbose
            // origin label that exceeds the production VARCHAR(24). Provenance is
            // unchanged: the full protocol/experiment remains in the niche.
            $plan = array_map(function (array $spec): array {
                if ((string) ($spec['origin'] ?? '') === 'causal_learning_confirmation') {
                    $spec['origin'] = 'causal_confirm';
                }
                if (filled(data_get($spec, 'niche.causal_learning_cohort.role'))) {
                    $spec['niche'] = app(CausalLearningCohortPlannerService::class)
                        ->isolateCausalNiche((array) data_get($spec, 'niche', []));
                }

                return $spec;
            }, $plan);
            $this->publishConstructorProgress(
                $constructorSymbol,
                $constructorTimeframe,
                'continuation',
                (string) $generation->trigger_type,
                (int) $generation->id,
                count($plan),
                $generation->agents->count(),
                null,
            );
            $maxSeats = max(1, min(4, $maxSeats));
            if ($plan === []) {
                return ['status' => 'no_generation_plan', 'generation' => $generation, 'created_slots' => []];
            }
            $selectorProtocol = (string) data_get(
                $generation->trigger_context,
                'adaptive_evolution_policy.causal_learning_counterfactual_cohort.blinded_selector.protocol',
                '',
            );
            if ($selectorProtocol !== '' && $selectorProtocol !== CausalBlindedMutationSelectorService::PROTOCOL) {
                return $this->quarantineSupersededCausalConstruction($generation, $selectorProtocol);
            }
            if (! in_array((string) $generation->status, ['draft', 'technical_quarantine'], true)) {
                return ['status' => 'not_resumable', 'generation' => $generation, 'created_slots' => []];
            }

            $slotPattern = '/_g'.preg_quote((string) $generation->generation, '/').'_a(\d+)$/';
            $existingSlots = $generation->agents
                ->map(fn (LabAgent $agent): ?int => $this->constructorSlot($generation, $agent, $slotPattern))
                ->filter(fn (?int $slot): bool => $slot !== null)
                ->values()->all();
            $createdSlots = [];
            $failures = [];
            $constructionReplacements = [];
            $previousFailures = collect((array) data_get(
                $generation->trigger_context,
                'constructor_continuation.failures',
                [],
            ));
            foreach ($plan as $index => $spec) {
                $slot = $index + 1;
                if (in_array($slot, $existingSlots, true) || count($createdSlots) >= $maxSeats) {
                    continue;
                }
                $this->publishConstructorProgress(
                    $constructorSymbol,
                    $constructorTimeframe,
                    'continuation',
                    (string) $generation->trigger_type,
                    (int) $generation->id,
                    count($plan),
                    count(array_unique($existingSlots)),
                    $slot,
                );
                // A previous bounded continuation already proved that this exact
                // dependency-gated seat cannot legally mutate yet. Re-running
                // the original targeted compiler repeats the same archive and
                // lineage searches for several minutes before reaching the same
                // gate. On retry, proceed directly to the explicit frozen
                // diagnostic control compiled by the replacement policy.
                $attemptSpec = $this->repeatedFailureReplacementSpec(
                    $plan,
                    $index,
                    $spec,
                    $previousFailures->all(),
                ) ?? $spec;
                $preemptiveReplacement = $attemptSpec !== $spec;
                $failureReason = null;
                try {
                    $created = DB::transaction(function () use ($generation, $attemptSpec, $index, &$failureReason): bool {
                        $current = LabGeneration::query()->with('laboratory')->findOrFail($generation->id);

                        return $this->createAgent(
                            $current,
                            (string) $attemptSpec['family'],
                            (string) $attemptSpec['origin'],
                            $index + 1,
                            (string) $attemptSpec['target'],
                            (array) ($attemptSpec['niche'] ?? []),
                            isset($attemptSpec['history']) ? (array) $attemptSpec['history'] : null,
                            isset($attemptSpec['research_group']) ? (string) $attemptSpec['research_group'] : null,
                            (int) ($attemptSpec['group_seat'] ?? 0),
                            $failureReason,
                        );
                    });
                } catch (\Throwable $exception) {
                    report($exception);
                    $created = false;
                    $failureReason = 'CONSTRUCTOR_EXCEPTION: '.$exception->getMessage();
                }
                if ($created && $preemptiveReplacement) {
                    $plan[$index] = $attemptSpec;
                    $constructionReplacements[] = [
                        'slot' => $slot,
                        'from_gene' => data_get($spec, 'niche.declared_gene'),
                        'to_gene' => data_get($attemptSpec, 'niche.declared_gene'),
                        'kind' => data_get($attemptSpec, 'niche.sibling_kind'),
                        'reason' => data_get(
                            $attemptSpec,
                            'niche.replacement_contract.reason',
                            'constructor_replacement_before_persistence',
                        ),
                        'promotion_evidence' => false,
                    ];
                }
                if (! $created && $this->canReplaceMutationConstruction($spec, $failureReason)) {
                    $repeatedFailure = $previousFailures->contains(
                        fn (mixed $failure): bool => is_array($failure)
                            && (int) ($failure['slot'] ?? 0) === $slot
                            && (string) ($failure['reason'] ?? '') === (string) $failureReason,
                    );
                    foreach ($this->zeroDiffReplacementSpecs($plan, $index, $spec, $failureReason, $repeatedFailure) as $replacementSpec) {
                        $replacementFailure = null;
                        try {
                            $replacementCreated = DB::transaction(function () use ($generation, $replacementSpec, $index, &$replacementFailure): bool {
                                $current = LabGeneration::query()->with('laboratory')->findOrFail($generation->id);

                                return $this->createAgent(
                                    $current,
                                    (string) $replacementSpec['family'],
                                    (string) $replacementSpec['origin'],
                                    $index + 1,
                                    (string) $replacementSpec['target'],
                                    (array) ($replacementSpec['niche'] ?? []),
                                    isset($replacementSpec['history']) ? (array) $replacementSpec['history'] : null,
                                    isset($replacementSpec['research_group']) ? (string) $replacementSpec['research_group'] : null,
                                    (int) ($replacementSpec['group_seat'] ?? 0),
                                    $replacementFailure,
                                );
                            });
                        } catch (\Throwable $exception) {
                            report($exception);
                            $replacementCreated = false;
                            $replacementFailure = 'CONSTRUCTOR_EXCEPTION: '.$exception->getMessage();
                        }
                        if (! $replacementCreated) {
                            $failureReason = $replacementFailure ?: $failureReason;

                            continue;
                        }
                        $created = true;
                        $plan[$index] = $replacementSpec;
                        $constructionReplacements[] = [
                            'slot' => $slot,
                            'from_gene' => data_get($spec, 'niche.declared_gene'),
                            'to_gene' => data_get($replacementSpec, 'niche.declared_gene'),
                            'kind' => data_get($replacementSpec, 'niche.sibling_kind'),
                            'reason' => data_get(
                                $replacementSpec,
                                'niche.replacement_contract.reason',
                                'constructor_replacement_before_persistence',
                            ),
                            'promotion_evidence' => false,
                        ];
                        $existingSlots[] = $slot;
                        break;
                    }
                }
                if ($created) {
                    $createdSlots[] = $slot;
                    $existingSlots[] = $slot;
                } else {
                    $failures[] = ['slot' => $slot, 'reason' => $failureReason ?: 'no_legal_nonzero_mutation'];
                }
                $this->publishConstructorProgress(
                    $constructorSymbol,
                    $constructorTimeframe,
                    'continuation',
                    (string) $generation->trigger_type,
                    (int) $generation->id,
                    count($plan),
                    count(array_unique($existingSlots)),
                    null,
                );
            }

            $fresh = $generation->fresh(['agents.modelVersion']);
            $completedSlots = $fresh->agents
                ->map(fn (LabAgent $agent): ?int => $this->constructorSlot($fresh, $agent, $slotPattern))
                ->filter(fn (?int $slot): bool => $slot !== null)->unique()->values()->all();
            $complete = count($completedSlots) === count($plan);
            $context = (array) ($fresh->trigger_context ?? []);
            $context['generation_plan'] = $plan;
            $shadowAllocation = (string) data_get($context, 'research_allocation_budget.mode') === 'shadow_research'
                ? (array) data_get($context, 'adaptive_evolution_policy.smart_courage_allocation', [])
                : null;
            $context['research_allocation_budget'] = $this->researchAllocation->audit(
                $plan,
                (bool) data_get($context, 'research_allocation_budget.targeted_rescue_blocked', false),
                $shadowAllocation,
            );
            $context['constructor_continuation'] = [
                'protocol' => 'bounded_resumable_constructor_v1',
                'created_slots_this_run' => $createdSlots,
                'completed_slots' => $completedSlots,
                'planned_slots' => count($plan),
                'failures' => $failures,
                'replacements' => $constructionReplacements,
                'complete' => $complete,
                'promotion_evidence' => false,
            ];
            if ($complete) {
                unset(
                    $context['constructor_contract_abort'],
                    $context['shadow_research_constructor_abort'],
                    $context['controlled_rescue_constructor_abort'],
                );
                $context['constructor_audit'] = [
                    'protocol' => 'agent_constructor_invariant_v1',
                    'planned_slots' => count($plan), 'created_agents' => count($plan),
                    'skipped_zero_diff_slots' => [], 'replacements' => $constructionReplacements,
                    'rule' => 'Resumed construction completed every immutable planned slot before queue admission.',
                    'promotion_evidence' => false,
                ];
            }
            $fresh->update([
                'population_size' => count($completedSlots),
                // Keep the resumable cohort non-dispatchable until its mandatory
                // lineage projection has been written and admitted below.
                'status' => 'technical_quarantine',
                'completed_at' => now(),
                'trigger_context' => $context,
            ]);
            if (! $complete) {
                $fresh->agents()
                    ->whereIn('lifecycle_status', ['draft', 'queued'])
                    ->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => 'Generation construction incomplete; candidate quarantined before replay and strategy verdict withheld.',
                    ]);
            }
            $fresh = $complete
                ? $this->finalizeLineageContinuationContract($fresh)
                : $fresh->fresh(['agents.modelVersion']);
            $lineageAllowed = (bool) data_get($fresh->trigger_context, 'lineage_continuation_contract.allowed', false);
            if ($complete && $lineageAllowed) {
                // These seats never entered replay and were quarantined solely
                // because construction was partial. Once every immutable slot is
                // present they can safely return to draft; unrelated technical
                // quarantines are identified by a different reason and remain
                // untouched.
                $fresh->agents()
                    ->where('lifecycle_status', 'technical_quarantine')
                    ->where('decision_reason', 'Generation construction incomplete; candidate quarantined before replay and strategy verdict withheld.')
                    ->update(['lifecycle_status' => 'draft', 'decision_reason' => null]);
                $fresh->update(['status' => data_get($fresh->trigger_context, 'authorized_specialist_council_panel_intent') !== null
                    ? 'research_reserved' : 'draft', 'completed_at' => null]);
                $fresh = $fresh->fresh(['agents.modelVersion']);
            }

            return [
                'status' => $complete && $lineageAllowed ? 'complete' : ($complete ? 'lineage_blocked' : 'partial'),
                'generation' => $fresh,
                'created_slots' => $createdSlots,
                'completed_slots' => $completedSlots,
                'failures' => $failures,
            ];
        } finally {
            try {
                if ($this->nativeFollowupSourceFenceScope !== null) {
                    $this->nativeFollowupFeedbackOwner->endConstructorSourceFenceScope($this->nativeFollowupSourceFenceScope);
                }
            } finally {
                $this->nativeFollowupSourceFenceScope = null;
                $this->nativeFollowupFeedbackOwner = null;
                $this->nativeFollowupInvocationProof = null;
                $this->nativeFollowupConstructorInvocation = false;
                optional($constructorLock)->release();
                Cache::forget($this->constructorOwnerKey($constructorSymbol, $constructorTimeframe));
            }
        }
    }

    /** @return array<string, mixed> */
    private function quarantineSupersededCausalConstruction(LabGeneration $generation, string $selectorProtocol): array
    {
        $reasonCodes = ['CAUSAL_SELECTOR_PROTOCOL_SUPERSEDED'];
        foreach ((array) data_get($generation->trigger_context, 'queue_batches.screening', []) as $batchId) {
            try {
                Bus::findBatch((string) $batchId)?->cancel();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $experiment = AgentLearningCausalExperiment::query()
            ->where('lab_generation_id', $generation->id)
            ->latest('id')
            ->first();

        DB::transaction(function () use ($generation, $experiment, $selectorProtocol, $reasonCodes): void {
            $locked = LabGeneration::query()->lockForUpdate()->findOrFail($generation->id);
            $context = (array) $locked->trigger_context;
            $context['constructor_contract_abort'] = [
                'protocol' => 'superseded_causal_selector_construction_v1',
                'reason_codes' => $reasonCodes,
                'stored_selector_protocol' => $selectorProtocol,
                'required_selector_protocol' => CausalBlindedMutationSelectorService::PROTOCOL,
                'immutable_existing_agents_preserved' => true,
                'strategy_verdict' => 'withheld',
                'learning_evidence' => false,
                'promotion_evidence' => false,
                'closed_at' => now()->utc()->toIso8601String(),
            ];
            $locked->update([
                'status' => 'technical_quarantine',
                'completed_at' => now(),
                'trigger_context' => $context,
            ]);
            $locked->agents()->whereIn('lifecycle_status', ['draft', 'queued', 'screening', 'screened', 'evaluation_error'])->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Causal constructor selector protocol was superseded before screening; strategy verdict withheld.',
            ]);
            if ($experiment) {
                $evidence = (array) $experiment->evidence;
                $evidence['construction_validation'] = [
                    'status' => 'invalid_counterfactual_contract',
                    'reason_codes' => $reasonCodes,
                    'stored_selector_protocol' => $selectorProtocol,
                    'required_selector_protocol' => CausalBlindedMutationSelectorService::PROTOCOL,
                    'promotion_evidence' => false,
                ];
                $experiment->update([
                    'status' => 'invalid_counterfactual_contract',
                    'evidence' => $evidence,
                ]);
            }
        });

        if ($experiment) {
            app(CausalRepairFrontierService::class)->releaseInvalidChild($experiment->fresh(), $reasonCodes);
        }

        return [
            'status' => 'superseded_causal_protocol',
            'generation' => $generation->fresh(['agents.modelVersion']),
            'created_slots' => [],
            'completed_slots' => [],
            'failures' => [[
                'reason' => 'CAUSAL_SELECTOR_PROTOCOL_SUPERSEDED',
                'stored_protocol' => $selectorProtocol,
                'required_protocol' => CausalBlindedMutationSelectorService::PROTOCOL,
            ]],
        ];
    }

    /** Native clones retain original strategy strings; only this verified owner supplies their slot. */
    private function constructorSlot(LabGeneration $generation, LabAgent $agent, string $ordinaryPattern): ?int
    {
        $intent = data_get($generation->trigger_context, 'authorized_specialist_council_panel_intent');
        if ($intent === null) return preg_match($ordinaryPattern, (string) $agent->modelVersion?->strategy, $match) === 1 ? (int) $match[1] : null;
        $seed = data_get($agent->modelVersion?->metadata, 'authorized_specialist_council_panel_seed');
        if (! is_array($intent) || ! is_array($seed) || ($seed['lab_generation_id'] ?? null) !== $generation->id
            || ($seed['work_item_id'] ?? null) !== $intent['work_item_id'] || ($seed['reservation_hash'] ?? null) !== $intent['reservation_hash']
            || ($seed['intent_hash'] ?? null) !== $intent['intent_hash'] || ! is_int($seed['construction_slot'] ?? null)) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_PERSISTED_SLOT_OWNER_INVALID');
        }
        $slot = $seed['construction_slot']; $arm = self::authorizedPanelConstructionRoots($intent)[$slot - 1] ?? null;
        if (! is_array($arm) || ($seed['arm_key'] ?? null) !== $arm['arm_key'] || ($seed['source_model_hash'] ?? null) !== $arm['source_model_hash']
            || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($agent->modelVersion->parameters, $arm['parameters'])) {
            throw new \LogicException('AUTHORIZED_COUNCIL_PANEL_PERSISTED_SLOT_VECTOR_DRIFT');
        }
        $source = $this->authorizedCouncilPanelSource($arm);
        $this->assertAuthorizedCouncilPanelPhysicalClone($agent->modelVersion, $source, $arm['parameters']);
        return $slot;
    }

    private function finalizeLineageContinuationContract(LabGeneration $generation): LabGeneration
    {
        $fresh = $generation->fresh(['agents.modelVersion']);
        $requiredFamilies = collect((array) data_get(
            $fresh->trigger_context,
            'adaptive_evolution_policy.smart_composition_cohort.lineage_continuation_families',
            [],
        ))->map(fn ($family): string => (string) $family)->filter()->unique()->values();
        $coveredFamilies = $fresh->agents
            ->filter(fn (LabAgent $agent): bool => $agent->parent_a_model_version_id !== null)
            ->pluck('strategy_family')->unique()->values();
        $missingFamilies = $requiredFamilies->diff($coveredFamilies)->values();
        $parentedAgents = $fresh->agents->whereNotNull('parent_a_model_version_id')->count();
        $inheritanceActive = $requiredFamilies->isNotEmpty()
            && $parentedAgents > 0
            && $missingFamilies->isEmpty();
        $rootResearchOnly = $requiredFamilies->isEmpty();
        $context = (array) $fresh->trigger_context;
        $context['lineage_continuation_contract'] = [
            'protocol' => 'mandatory_generation_lineage_v1',
            'status' => $rootResearchOnly
                ? 'allowed_for_root_research'
                : ($inheritanceActive ? 'inheritance_active' : 'lineage_blocked'),
            'required_families' => $requiredFamilies->all(),
            'covered_families' => $coveredFamilies->all(),
            'missing_families' => $missingFamilies->all(),
            'parented_agents' => $parentedAgents,
            'population_size' => $fresh->agents->count(),
            'coverage_percent' => $fresh->agents->isNotEmpty()
                ? round(($parentedAgents / $fresh->agents->count()) * 100, 2)
                : 0.0,
            'allowed' => $rootResearchOnly || $inheritanceActive,
            'inheritance_active' => $inheritanceActive,
            'root_research_only' => $rootResearchOnly,
            'eligible_parent_supply_present' => $requiredFamilies->isNotEmpty(),
            'rule' => 'An empty eligible-parent frontier may open root research, but can never be reported as inheritance.',
            'promotion_evidence' => false,
        ];
        $plan = array_values((array) data_get($context, 'generation_plan', []));
        $constructionComplete = $plan !== [] && $fresh->agents->count() === count($plan);
        if ($constructionComplete) {
            $priorRequirements = (array) data_get($context, 'immutable_generation_contract.requirements', []);
            $context['immutable_generation_contract'] = app(ImmutableGenerationContractService::class)->compile(
                $fresh,
                $plan,
                [
                    'data_hash' => (string) ($fresh->data_fingerprint ?: data_get($context, 'data_hash', '')),
                    'execution_hash' => (string) data_get(
                        app(ExecutionContractService::class)->for(
                            (string) $fresh->laboratory?->symbol,
                            (string) config('services.xauusd_organism.execution_timeframe', 'M5'),
                        ),
                        'execution_hash',
                        '',
                    ),
                    'normal_population' => (int) ($priorRequirements['normal_population'] ?? $this->configuredPopulationSize()),
                    'control_pairing_required' => (bool) ($priorRequirements['control_pairing_required']
                        ?? is_array(data_get($context, 'control_pairing_contract'))),
                    'causal_proof_required' => (bool) ($priorRequirements['causal_proof_required']
                        ?? is_array(data_get($context, 'adaptive_evolution_policy.causal_learning_counterfactual_cohort'))),
                    'mtf_roles' => (array) ($priorRequirements['mtf_roles']
                        ?? array_keys((array) config('services.xauusd_organism.timeframe_roles', []))),
                    'new_work_owner' => ResearchLoopArbiterService::class,
                ],
            );
        } else {
            // A partial constructor is intentionally unsealed and therefore
            // cannot pass screening admission.
            $context['immutable_generation_contract'] = null;
        }
        $updates = ['trigger_context' => $context];
        if ($missingFamilies->isNotEmpty() && $fresh->status !== 'technical_quarantine') {
            $updates['status'] = 'technical_quarantine';
            $updates['completed_at'] = now();
        }
        $fresh->update($updates);

        return $fresh->fresh(['agents.modelVersion']);
    }

    private function configuredPopulationSize(): int
    {
        $minimum = max(1, (int) config('services.lab_selection.population_min_size', 1));
        $configuredMaximum = (int) config('services.lab_selection.population_max_size', 0);
        $requested = max($minimum, (int) config('services.lab_selection.population_size', 20));

        return $configuredMaximum > 0
            ? min(max($minimum, $configuredMaximum), $requested)
            : $requested;
    }

    private function constructorLockKey(string $symbol, string $timeframe): string
    {
        return 'lab-population-constructor:'.strtoupper($symbol).':'.strtoupper($timeframe).':v1';
    }

    private function constructorOwnerKey(string $symbol, string $timeframe): string
    {
        return $this->constructorLockKey($symbol, $timeframe).':owner';
    }

    /** @return array<string, mixed> */
    private function constructorOwner(string $operation, string $trigger, ?int $generationId = null): array
    {
        return [
            'protocol' => 'lab_population_constructor_owner_v1',
            'operation' => $operation,
            'trigger' => $trigger,
            'generation_id' => $generationId,
            'command' => app()->runningInConsole() ? implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 4)) : 'non_console',
            'pid' => getmypid(),
            'hostname' => $this->constructorHostname(),
            'acquired_at' => now()->utc()->toIso8601String(),
            'heartbeat_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
    }

    private function constructorHostname(): string
    {
        return (string) (gethostname() ?: php_uname('n'));
    }

    /** Null means the platform could not prove either process state. */
    private function localConstructorProcessIsRunning(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 0);
            }

            return is_dir('/proc/'.$pid) ? true : null;
        }

        $process = new Process(['tasklist', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH']);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }
        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (preg_match('/^"[^"]+","'.preg_quote((string) $pid, '/').'",/i', trim((string) $line)) === 1) {
                return true;
            }
        }

        return false;
    }

    private function publishConstructorProgress(
        string $symbol,
        string $timeframe,
        string $operation,
        string $trigger,
        int $generationId,
        int $plannedSlots,
        int $completedSlots,
        ?int $currentSlot,
    ): void {
        $key = $this->constructorOwnerKey($symbol, $timeframe);
        $owner = Cache::get($key);
        if (! is_array($owner)) {
            $owner = $this->constructorOwner($operation, $trigger, $generationId);
        }
        Cache::put($key, [
            ...$owner,
            'operation' => $operation,
            'trigger' => $trigger,
            'generation_id' => $generationId,
            'planned_slots' => max(0, $plannedSlots),
            'completed_slots' => max(0, $completedSlots),
            'current_slot' => $currentSlot,
            'heartbeat_at' => now()->utc()->toIso8601String(),
        ], now()->addSeconds(self::CONSTRUCTOR_LOCK_TTL_SECONDS));
    }

    private function publishConstructorStage(LabGeneration $generation, int $slot, string $stage): void
    {
        $lab = $generation->laboratory;
        if (! $lab) {
            return;
        }
        $key = $this->constructorOwnerKey((string) $lab->symbol, (string) $lab->timeframe);
        $owner = Cache::get($key);
        // createAgent() is also used by bounded diagnostics and tests outside
        // a population constructor. Never manufacture a false active owner.
        if (! is_array($owner)) {
            return;
        }
        Cache::put($key, [
            ...$owner,
            'generation_id' => (int) $generation->id,
            'current_slot' => $slot,
            'stage' => $stage,
            'heartbeat_at' => now()->utc()->toIso8601String(),
        ], now()->addSeconds(self::CONSTRUCTOR_LOCK_TTL_SECONDS));
    }
}
