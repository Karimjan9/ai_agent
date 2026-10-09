<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Services\CandidateHandoffService;
use App\Services\CausalLearningCohortService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\GenerationConstructionAdmissionService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabAgentPreflightService;
use App\Services\LabDatasetExportService;
use App\Services\LabGenerationContextService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningEvidenceGate;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningTechnicalCircuitBreakerService;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\NativeReachabilityDepthAuditService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchAllocationPolicyService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class DispatchLabGeneration extends Command
{
    protected $signature = 'trading:dispatch-lab {symbol?} {--timeframe=H1 : Internal storage key; XAUUSD aliases route to one organism} {--force-generation} {--controlled-rescue : Dispatch an already-approved XAUUSD organism rescue only} {--shadow-research : Dispatch only an already-approved shadow-research generation} {--audited-data-edge : Dispatch only an explicitly audited data-edge generation while normal creation remains paused} {--learning-confirmation : Build/dispatch one bounded guided-vs-blinded-vs-frozen-control confirmation triplet} {--resume-draft-agents : Continue stranded draft agents after a complete constructor has already opened the generation} {--expected-generation-id= : Internal fence for one original native depth diagnostic phase}';

    protected $description = 'Dispatch pair-local incremental screening for each draft laboratory agent';

    private ?int $expectedNativeGenerationId = null;
    private ?array $nativeDepthFailureDiagnostic = null;

    private array $nativeDepthExpectedAgentIds = [];

    private array $nativeDepthQueuedAgentIds = [];

    private string $nativeDepthRefusalReason = 'NATIVE_DEPTH_CANONICAL_DISPATCH_DEFERRED';

    public function handle(LabPopulationService $populations, LabDatasetExportService $datasets, MultiTimeframeSnapshotService $mtfSnapshots, MarketDataContinuityService $continuity, LabImmutableEvidenceService $evidence, CandidateHandoffService $handoffs, LabAgentPreflightService $preflight, LearningProtocolSafetyService $protocolSafety, LearningTechnicalCircuitBreakerService $technicalBreaker, LearningEvidenceGate $evidenceGate, LabQueueJobInspector $queueState, StrategyParameterSchemaService $schemas, LabGenerationContextService $generationContext, GenerationSnapshotAdmissionService $snapshotAdmission, GenerationConstructionAdmissionService $constructionAdmission): int
    {
        $expected = $this->option('expected-generation-id');
        $this->expectedNativeGenerationId = null;
        $this->nativeDepthFailureDiagnostic = null;
        $this->nativeDepthExpectedAgentIds = $this->nativeDepthQueuedAgentIds = [];
        $this->nativeDepthRefusalReason = 'NATIVE_DEPTH_CANONICAL_DISPATCH_DEFERRED';
        if ($expected === null) {
            return $this->handleCanonical($populations, $datasets, $mtfSnapshots, $continuity, $evidence, $handoffs,
                $preflight, $protocolSafety, $technicalBreaker, $evidenceGate, $queueState, $schemas, $generationContext,
                $snapshotAdmission, $constructionAdmission);
        }
        $exit = self::FAILURE;
        try {
            $expectedText = is_int($expected) || is_string($expected) ? (string) $expected : '';
            if (! preg_match('/^[1-9][0-9]*$/D', $expectedText)
                || strlen($expectedText) > strlen((string) PHP_INT_MAX)
                || (strlen($expectedText) === strlen((string) PHP_INT_MAX) && strcmp($expectedText, (string) PHP_INT_MAX) > 0)) {
                throw new \LogicException('NATIVE_DEPTH_EXPECTED_GENERATION_ID_INVALID');
            }
            $this->expectedNativeGenerationId = (int) $expectedText;
            $this->assertExpectedNativeDepthGeneration(null, $queueState);
            $exit = $this->handleCanonical($populations, $datasets, $mtfSnapshots, $continuity, $evidence, $handoffs,
                $preflight, $protocolSafety, $technicalBreaker, $evidenceGate, $queueState, $schemas, $generationContext,
                $snapshotAdmission, $constructionAdmission);
        } catch (\Throwable $error) {
            $this->nativeDepthFailureDiagnostic = ['error_class' => $error::class,
                'file' => basename($error->getFile()), 'line' => $error->getLine(),
                'message_sha256' => hash('sha256', $error->getMessage()),
                'frames' => array_map(static fn ($frame) => array_filter([
                    'class' => $frame['class'] ?? null, 'function' => $frame['function'] ?? null,
                    'file' => isset($frame['file']) ? basename($frame['file']) : null, 'line' => $frame['line'] ?? null,
                ], static fn ($value) => $value !== null), array_slice($error->getTrace(), 0, 8))];
            $reason = $error->getMessage();
            $this->nativeDepthRefusalReason = preg_match('/^[A-Z][A-Z0-9_]{0,159}$/D', $reason)
                ? $reason : 'NATIVE_DEPTH_CANONICAL_DISPATCH_FAILED';
        }
        $admitted = $exit === self::SUCCESS && count($this->nativeDepthQueuedAgentIds) === 1
            && $this->nativeDepthQueuedAgentIds === $this->nativeDepthExpectedAgentIds;
        $this->line(json_encode(['protocol' => 'native_depth_audit_dispatch_v1',
            'status' => $admitted ? 'admitted' : 'refused', 'generation_id' => $this->expectedNativeGenerationId ?? 0,
            'reason_code' => $admitted ? 'NATIVE_DEPTH_ORIGINAL_PHASE_ADMITTED' : $this->nativeDepthRefusalReason,
            'agent_ids' => $admitted ? $this->nativeDepthQueuedAgentIds : []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $admitted ? self::SUCCESS : self::FAILURE;
    }

    private function handleCanonical(LabPopulationService $populations, LabDatasetExportService $datasets, MultiTimeframeSnapshotService $mtfSnapshots, MarketDataContinuityService $continuity, LabImmutableEvidenceService $evidence, CandidateHandoffService $handoffs, LabAgentPreflightService $preflight, LearningProtocolSafetyService $protocolSafety, LearningTechnicalCircuitBreakerService $technicalBreaker, LearningEvidenceGate $evidenceGate, LabQueueJobInspector $queueState, StrategyParameterSchemaService $schemas, LabGenerationContextService $generationContext, GenerationSnapshotAdmissionService $snapshotAdmission, GenerationConstructionAdmissionService $constructionAdmission): int
    {
        if ($this->expectedNativeGenerationId === null) $populations->ensureLaboratories();
        $controlledRescue = (bool) $this->option('controlled-rescue');
        $shadowResearch = (bool) $this->option('shadow-research');
        $auditedDataEdge = (bool) $this->option('audited-data-edge');
        $learningConfirmation = (bool) $this->option('learning-confirmation');
        $resumeDraftAgents = (bool) $this->option('resume-draft-agents');
        $requestedTimeframe = strtoupper((string) $this->option('timeframe'));
        $requestedSymbol = strtoupper((string) ($this->argument('symbol') ?: ''));
        $scopeTimeframe = $requestedSymbol === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
            ? strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'))
            : $requestedTimeframe;
        if ($learningConfirmation
            && ($requestedSymbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || $scopeTimeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Learning confirmation faqat yagona XAUUSD organizmi uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        if ($shadowResearch
            && ($requestedSymbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || $scopeTimeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Shadow research dispatch faqat yagona XAUUSD organizmi uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        if ($controlledRescue
            && ($requestedSymbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || $scopeTimeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Controlled rescue dispatch faqat yagona XAUUSD organizmi uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        if ($auditedDataEdge
            && ($requestedSymbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || $scopeTimeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Audited data-edge dispatch faqat yagona XAUUSD organizmi uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        // A protocol pause blocks new screening populations, but it must not
        // strand a constructor-complete generation whose draft agents never
        // received their first queue job. `--resume-draft-agents` is a
        // same-generation recovery path; it creates no new generation and
        // keeps all promotion gates unchanged.
        if ($protocolSafety->generationCreationPaused()
            && ! $controlledRescue
            && ! $shadowResearch
            && ! $auditedDataEdge
            && ! $learningConfirmation
            && ! $resumeDraftAgents) {
            $this->info('Learning protocol paused: normal screening dispatch deferred; existing recovery jobs remain untouched.');

            return self::SUCCESS;
        }
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];

        // The lab queue shares the worker pool with screening learning and
        // evidence jobs.  Do not create another population while the pool is
        // already saturated: a large backlog makes every candidate stale and
        // turns the scheduler into a source of noisy, duplicated experiments.
        $queueSnapshot = $queueState->queueSnapshot();
        if (($queueSnapshot['available'] ?? true) === false) {
            $this->nativeDepthRefusalReason = 'NATIVE_DEPTH_QUEUE_STATE_UNAVAILABLE';
            $this->warn('Lab queue state unavailable; generation dispatch deferred fail-closed.');

            return self::SUCCESS;
        }
        if (($queueSnapshot['total'] ?? 0) !== null) {
            $pending = (int) ($queueSnapshot['total'] ?? 0);
            $limit = max(1, (int) config('services.lab_selection.max_screening_jobs', 40));
            if ($pending >= $limit) {
                $this->nativeDepthRefusalReason = 'NATIVE_DEPTH_SCREENING_BACKLOG';
                $this->warn("Lab screening backlog {$pending} >= {$limit}; dispatch deferred.");

                return self::SUCCESS;
            }
        }

        foreach ($symbols as $symbol) {
            $timeframe = $symbol === LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                ? strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'))
                : $requestedTimeframe;
            // Idempotency is evaluated before admission gates. An already
            // queued/screening/full-validation generation needs neither new
            // evidence nor a technical-breaker decision; it simply remains
            // the owner of this laboratory stream.
            $existingLab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
            $existingGeneration = $existingLab->generations()->latest('generation')->first();
            $resumeLearningConfirmationDraft = $learningConfirmation
                && $existingGeneration
                && (string) $existingGeneration->status === 'draft'
                && (string) $existingGeneration->trigger_type === 'learning_confirmation';
            if ($resumeLearningConfirmationDraft) {
                $technicalBreaker->adoptHalfOpenProbe($symbol, $timeframe);
            }
            if (! $resumeDraftAgents
                && $existingGeneration
                && in_array((string) $existingGeneration->status, ['queued', 'training', 'screening', 'full_queued', 'full_validation'], true)) {
                $this->info("{$symbol}: generation is already dispatched or evaluated.");

                continue;
            }
            if ($technicalBreaker->blocked($symbol, $timeframe)
                && ! $resumeDraftAgents
                && ! $resumeLearningConfirmationDraft) {
                $this->warn("{$symbol} {$timeframe}: repeated technical failure circuit breaker active; new generation blocked pending technical repair.");

                continue;
            }
            if (! $resumeDraftAgents && ! $controlledRescue && ! $shadowResearch && ! $auditedDataEdge && ! $learningConfirmation) {
                $generationGate = $evidenceGate->allowsNextGeneration($symbol, $timeframe);
                if (! $generationGate['allowed']) {
                    $this->warn("{$symbol} {$timeframe}: new generation blocked by Learning Evidence Gate ({$generationGate['reason']}).");

                    continue;
                }
            }
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
            if ($auditedDataEdge
                && ($symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                    || $timeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
                $this->error('Audited data-edge dispatch faqat yagona XAUUSD organizmi uchun ruxsat etiladi.');

                return self::FAILURE;
            }
            if ((string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} {$timeframe}: shadow lab; normal screening dispatch skipped.");

                continue;
            }
            $generation = $lab->generations()->with('agents')->latest('generation')->first();
            if ($this->expectedNativeGenerationId !== null) $this->assertExpectedNativeDepthGeneration($generation, $queueState);
            $nativePreparation = app(\App\Services\SpecialistCouncilPreparationService::class);
            if ($generation && $nativePreparation->hasNativeConstructorIntent($generation)) {
                try { $nativePreparation->isResearchGeneration($generation); }
                catch (\LogicException $error) {
                    $this->warn("{$symbol}: native council draft awaits its original atomic preparation ({$error->getMessage()}); snapshots and dispatch withheld.");
                    continue;
                }
            }
            $resumeExistingGeneration = $resumeDraftAgents
                && $generation
                && in_array((string) $generation->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true);
            if ($resumeDraftAgents && ! $resumeExistingGeneration) {
                $this->info("{$symbol}: no active constructor-complete generation available for draft recovery; no new generation created.");

                continue;
            }
            if ($shadowResearch && $generation && (string) $generation->trigger_type !== 'shadow_research') {
                $this->info("{$symbol} {$timeframe}: latest generation shadow-research emas; shadow dispatch skipped.");

                continue;
            }
            if ($auditedDataEdge && (! $generation
                || (string) $generation->trigger_type !== 'data_edge_audit'
                || ! is_array(data_get($generation->trigger_context, 'data_edge_audit')))) {
                $this->info("{$symbol} {$timeframe}: durable data-edge audit generation topilmadi; audited dispatch skipped.");

                continue;
            }
            if ($learningConfirmation && $generation
                && in_array((string) $generation->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)
                && (string) $generation->trigger_type !== 'learning_confirmation') {
                $this->info("{$symbol} {$timeframe}: boshqa active generation mavjud; learning confirmation dispatch deferred.");

                continue;
            }
            $activeGeneration = $lab->generations()
                ->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)
                ->latest('generation')
                ->first();
            if ($activeGeneration && (! $generation || (int) $activeGeneration->id !== (int) $generation->id)) {
                $this->warn("{$symbol}: eski active generation G{$activeGeneration->generation} hali {$activeGeneration->status}; yangi generation locklandi.");

                continue;
            }
            $replayActivation = $generation?->trigger_type === 'protocol_activation';
            if ((string) config('services.market_data.provider', 'csv') !== 'csv'
                && ! $replayActivation
                && ! app(\App\Services\GenerationAdmissionDecisionService::class)->isHistoricalGeneration($generation)
                && ! $continuity->isReady((string) config('services.market_data.provider'), $symbol, $lab->timeframe)) {
                $this->warn("{$symbol}: feed healthy bo'lmaguncha lab dispatch bloklandi.");

                continue;
            }
            if ($replayActivation) {
                $this->info("{$symbol}: sealed historical replay dispatch; live-feed continuity paper trading uchun alohida gate bo'lib qoladi.");
            }
            // `--force-generation` means "create a new generation when the
            // latest one is terminal".  It must never duplicate an active
            // draft/screening/full-validation generation: a manual retry of
            // the dispatcher should continue that generation instead of
            // orphaning it and moving the work to G+1.
            $activeStatuses = [
                'draft', 'queued', 'training', 'screening',
                'full_queued', 'full_validation',
            ];
            $shouldBuildGeneration = ! $generation
                || ($learningConfirmation && ! in_array((string) $generation->status, $activeStatuses, true))
                || ($this->option('force-generation')
                    && ! in_array((string) $generation->status, $activeStatuses, true));
            if ($shouldBuildGeneration) {
                if ($this->expectedNativeGenerationId !== null) throw new \LogicException('NATIVE_DEPTH_NEW_GENERATION_FORBIDDEN');
                $generation = $learningConfirmation
                    // Learning is a reserved triplet inside the ordinary
                    // twenty-seat organism generation. The other seventeen
                    // seats continue bounded discovery; full validation still
                    // admits only the exact guided/blinded/control triplet.
                    ? $populations->build($symbol, 'learning_confirmation', false, $timeframe, [], false, false)
                    : ($shadowResearch
                    ? $populations->build($symbol, 'shadow_research', false, $timeframe, [], false, false, (int) config('services.lab_selection.population_size', 20))
                    : ($auditedDataEdge
                        ? $populations->build($symbol, 'data_edge_audit', true, $timeframe)
                        : $populations->build($symbol, 'new_data', (bool) $this->option('force-generation'), $timeframe)));
            }

            if (! $generation) {
                $outcome = $populations->lastBuildOutcome();
                $reason = (string) data_get($outcome, 'reason_code', 'POPULATION_BUILD_UNAVAILABLE');
                $diagnostic = match ($reason) {
                    'MUTATION_DIVERSITY_CONTRACT_FAILED' => (array) data_get($outcome, 'context.mutation_diversity', []),
                    'GENERATION_CONSTRUCTOR_ACTIVE' => [
                        'lock_owner' => data_get($outcome, 'context.lock_owner'),
                    ],
                    default => [],
                };
                $diagnosticSuffix = $diagnostic !== []
                    ? ' '.json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                    : '';
                $this->warn("{$symbol}: generation build deferred [{$reason}].{$diagnosticSuffix}");
                if ($learningConfirmation) {
                    $technicalBreaker->releaseAcquiredProbe($symbol, $timeframe, $reason);
                }

                continue;
            }
            $construction = $constructionAdmission->inspect($generation);
            if (! (bool) data_get($construction, 'allowed', false)) {
                $this->warn(sprintf(
                    '%s: G%s constructor/lineage contract incomplete; screening dispatch bloklandi (%s).',
                    $symbol,
                    $generation->generation,
                    implode(',', (array) data_get($construction, 'reason_codes', ['GENERATION_CONSTRUCTION_NOT_ADMITTED'])),
                ));

                continue;
            }
            if ($shadowResearch && (string) $generation->trigger_type !== 'shadow_research') {
                $this->error("{$symbol}: shadow flag bilan normal generation dispatch qilinmaydi.");

                continue;
            }
            if ($learningConfirmation && (string) $generation->trigger_type !== 'learning_confirmation') {
                $this->error("{$symbol}: learning-confirmation flag boshqa generationni dispatch qilmaydi.");

                continue;
            }
            if ($controlledRescue
                && data_get($generation->trigger_context, 'controlled_rescue_admission.protocol')
                    !== LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL) {
                $this->error("{$symbol}: target generation controlled-rescue admission contracti topilmadi; dispatch bloklandi.");

                continue;
            }
            if (! $controlledRescue && ! $shadowResearch && ! $auditedDataEdge && ! $learningConfirmation && ! $resumeExistingGeneration) {
                $normalAdmission = $this->normalCausalAdmission($generation);
                if (! (bool) data_get($normalAdmission, 'allowed', true)) {
                    $this->warn(sprintf(
                        '%s: G%s normal causal contract incomplete; screening dispatch bloklandi (%s).',
                        $symbol,
                        $generation->generation,
                        implode(',', (array) data_get($normalAdmission, 'reasons', ['NORMAL_CAUSAL_CONTRACT_INVALID'])),
                    ));

                    continue;
                }
            } elseif ($resumeExistingGeneration) {
                $this->info("{$symbol}: resuming existing G{$generation->generation} only; protocol pause remains active and promotion gates are unchanged.");
            }

            // Two scheduler instances can observe the same draft generation
            // before either one has written the queued projection. Serialize
            // only this dispatch critical section; the queue workers remain
            // independently bounded. This prevents duplicate batches without
            // changing the immutable snapshot, execution contract or gates.
            $dispatchLease = Cache::lock(
                "lab-generation-dispatch:{$lab->id}:{$timeframe}:{$generation->id}",
                max(300, (int) config('services.lab_queue.dispatch_lease_seconds', 3600)),
            );
            if (! $dispatchLease->get()) {
                $this->warn("{$symbol}: G{$generation->generation} dispatch lease boshqa workerda; duplicate batch ochilmadi.");

                continue;
            }

            try {

                // A second scheduler/manual invocation may observe the same
                // generation while its screening jobs are already running. Do
                // not re-export the frozen dataset in that case: the export lock
                // belongs to the active evaluator and re-exporting can turn a
                // harmless duplicate dispatch into a false operational failure.
                $generation = $generation->fresh(['agents.modelVersion']);
                if ($this->expectedNativeGenerationId !== null) $this->assertExpectedNativeDepthGeneration($generation, $queueState);
                $draftAgents = $generation->agents->where('lifecycle_status', 'draft');
                if ($this->expectedNativeGenerationId !== null) $draftAgents = $draftAgents->whereIn('id', $this->nativeDepthExpectedAgentIds);
                $strandedQueuedAgents = ($resumeDraftAgents
                    && in_array((string) $generation->status, ['queued', 'screening'], true)
                    && $this->constructorCompleteForDraftContinuation($generation))
                    ? $this->strandedQueuedAgents($generation, $queueState)
                    : collect();
                if ($this->expectedNativeGenerationId !== null) $strandedQueuedAgents = $strandedQueuedAgents->whereIn('id', $this->nativeDepthExpectedAgentIds);
                $continuation = $resumeDraftAgents
                    && in_array((string) $generation->status, ['queued', 'screening'], true)
                    && $this->constructorCompleteForDraftContinuation($generation)
                    && ($draftAgents->isNotEmpty() || $strandedQueuedAgents->isNotEmpty());
                if (($draftAgents->isEmpty() && $strandedQueuedAgents->isEmpty())
                    || ((string) $generation->status !== 'draft' && ! $continuation)) {
                    $this->info("{$symbol}: generation is already dispatched or evaluated.");

                    continue;
                }
                if ($continuation) {
                    $this->info("{$symbol}: continuing complete generation G{$generation->generation}; stranded draft/queued agents only.");
                }
                // A queued agent recovered after an integrity repair already has
                // the generation's frozen snapshot. Re-export only when there
                // are still draft agents to admit; replacing the live export for
                // a queued-only recovery cannot repair its missing queue job.
                if ($draftAgents->isNotEmpty()) {
                    $datasets->export($symbol, $lab->timeframe);
                }
                // The export is frozen before the first queue job starts. Re-read
                // after export so no concurrent dispatcher can queue the same
                // draft agents twice.
                $generation = $generation->fresh(['agents.modelVersion']);
                if ($this->expectedNativeGenerationId !== null) $this->assertExpectedNativeDepthGeneration($generation, $queueState);
                $strandedQueuedAgents = ($resumeDraftAgents
                    && in_array((string) $generation->status, ['queued', 'screening'], true)
                    && $this->constructorCompleteForDraftContinuation($generation))
                    ? $this->strandedQueuedAgents($generation, $queueState)
                    : collect();
                if ($this->expectedNativeGenerationId !== null) $strandedQueuedAgents = $strandedQueuedAgents->whereIn('id', $this->nativeDepthExpectedAgentIds);
                $draftIntegrityQuarantines = [];
                $preflightAgents = $generation->agents->where('lifecycle_status', 'draft');
                if ($this->expectedNativeGenerationId !== null) $preflightAgents = $preflightAgents->whereIn('id', $this->nativeDepthExpectedAgentIds);
                foreach ($preflightAgents as $agent) {
                    $contractRepair = $this->repairDifferentialContractCoordinate($agent);
                    if ($contractRepair !== []) {
                        $agent = $agent->fresh(['modelVersion']);
                        $evidence->recordLifecycle($agent, 'draft_integrity_repair', [
                            'reason_code' => 'DIFFERENTIAL_TARGET_REGIME_IS_EXECUTION_CONTRACT',
                            ...$contractRepair,
                            'parameters_unchanged' => true,
                            'promotion_evidence' => false,
                        ], 'screening', null, null, self::class, null, 'draft', 'draft');
                        $handoffs->record($generation, $agent, 'integrity_repair', 'passed', 'DERIVED_DIFF_CONTRACT_REPAIRED', [
                            ...$contractRepair,
                            'parameters_unchanged' => true,
                            'promotion_evidence' => false,
                        ]);
                        $this->info("{$symbol}: agent {$agent->id} derived differential contract repaired before screening.");
                    }
                    $preflightInspection = $preflight->inspect($agent, 'screening');
                    if (! $preflightInspection['passed']) {
                        $preflight->quarantine($agent, $preflightInspection, 'draft_queue_admission');
                        $draftIntegrityQuarantines[] = [
                            'agent_id' => $agent->id,
                            'violations' => $preflightInspection['errors'],
                            'promotion_evidence' => false,
                        ];

                        continue;
                    }
                    $violations = $this->draftIntegrityViolations($agent, $schemas);
                    if ($violations === []) {
                        continue;
                    }

                    $reason = 'Draft identity/integrity contract failed; child quarantined before screening. Strategy verdict withheld.';
                    $agent->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => $reason,
                    ]);
                    $draftIntegrityQuarantines[] = [
                        'agent_id' => $agent->id,
                        'violations' => $violations,
                        'promotion_evidence' => false,
                    ];
                    $evidence->recordLifecycle($agent->fresh(), 'draft_integrity_quarantine', [
                        'reason_code' => 'DRAFT_IDENTITY_INTEGRITY_BREACH',
                        'violations' => $violations,
                        'quality_verdict' => 'withheld',
                        'promotion_evidence' => false,
                    ], 'screening', null, null, self::class, null, 'draft', 'technical_quarantine');
                    $handoffs->record($generation, $agent->fresh(), 'integrity_quarantine', 'failed', 'DRAFT_IDENTITY_INTEGRITY_BREACH', [
                        'violations' => $violations,
                        'next_action' => 'repair_in_draft_or_open_bounded_child',
                        'promotion_evidence' => false,
                    ]);
                }
                $admittedStrandedQueuedAgents = collect();
                foreach ($strandedQueuedAgents as $agent) {
                    $preflightInspection = $preflight->inspect($agent, 'screening');
                    if (! $preflightInspection['passed']) {
                        $preflight->quarantine($agent, $preflightInspection, 'stranded_queue_recovery');
                        $draftIntegrityQuarantines[] = [
                            'agent_id' => $agent->id,
                            'violations' => $preflightInspection['errors'],
                            'promotion_evidence' => false,
                        ];

                        continue;
                    }
                    $violations = $this->draftIntegrityViolations($agent, $schemas);
                    if ($violations !== []) {
                        $reason = 'Queued recovery identity/integrity contract failed; child quarantined before screening. Strategy verdict withheld.';
                        $agent->update([
                            'lifecycle_status' => 'technical_quarantine',
                            'decision_reason' => $reason,
                        ]);
                        $draftIntegrityQuarantines[] = [
                            'agent_id' => $agent->id,
                            'violations' => $violations,
                            'promotion_evidence' => false,
                        ];
                        $evidence->recordLifecycle($agent->fresh(), 'queued_recovery_integrity_quarantine', [
                            'reason_code' => 'QUEUED_RECOVERY_IDENTITY_INTEGRITY_BREACH',
                            'violations' => $violations,
                            'quality_verdict' => 'withheld',
                            'promotion_evidence' => false,
                        ], 'screening', null, null, self::class, null, 'queued', 'technical_quarantine');
                        $handoffs->record($generation, $agent->fresh(), 'integrity_quarantine', 'failed', 'QUEUED_RECOVERY_IDENTITY_INTEGRITY_BREACH', [
                            'violations' => $violations,
                            'next_action' => 'repair_in_draft_or_open_bounded_child',
                            'promotion_evidence' => false,
                        ]);

                        continue;
                    }
                    $admittedStrandedQueuedAgents->push($agent->fresh(['modelVersion']));
                    $evidence->recordLifecycle($agent->fresh(), 'screening_queue_recovery_admitted', [
                        'reason_code' => 'MISSING_SCREENING_JOB_RECOVERED',
                        'queue' => (string) config('services.lab_queue.screening_queue', 'lab-screening'),
                        'promotion_evidence' => false,
                    ], 'screening', null, null, self::class, null, 'queued', 'queued');
                }
                $strandedQueuedAgents = $admittedStrandedQueuedAgents;
                if ($draftIntegrityQuarantines !== []) {
                    $context = (array) $generation->trigger_context;
                    $context['draft_integrity_quarantines'] = array_merge(
                        (array) ($context['draft_integrity_quarantines'] ?? []),
                        $draftIntegrityQuarantines,
                    );
                    $generation->update(['trigger_context' => $context]);
                }
                // Draft preflight may repair a derived execution coordinate
                // and refresh the model row. Reload the complete population
                // before deciding which dataset contracts must be frozen;
                // otherwise a newly materialized volume specialist can be
                // queued with only the price snapshot.
                $generation = $generation->fresh(['agents.modelVersion']);
                $draftAgents = $generation->agents
                    ->where('lifecycle_status', 'draft')
                    // A fresh repair control is the first observation for the
                    // snapshot. Put it in the first bounded job without changing
                    // the sibling order or any candidate parameters.
                    ->sortBy(fn (LabAgent $agent): array => [
                        $this->isFrozenRepairControl($agent) ? 0 : 1,
                        (int) $agent->id,
                    ])
                    ->values();
                $dispatchAgents = $draftAgents->concat($strandedQueuedAgents)->values();
                $studyCarrierIds = app(\App\Services\SpecialistCouncilPreparationService::class)->nativeDiagnosticDispatchAgentIds($generation);
                if ($studyCarrierIds !== null) {
                    $draftAgents = $draftAgents->whereIn('id', $studyCarrierIds)->values();
                    $dispatchAgents = $dispatchAgents->whereIn('id', $studyCarrierIds)->values();
                }
                $agentIds = $dispatchAgents->pluck('id');
                if ($this->expectedNativeGenerationId !== null) {
                    $this->assertExpectedNativeDepthGeneration($generation, $queueState);
                    if ($agentIds->map(fn ($id): int => (int) $id)->all() !== $this->nativeDepthExpectedAgentIds) {
                        throw new \LogicException('NATIVE_DEPTH_PHASE_AGENT_SET_DRIFT');
                    }
                }
                if ($agentIds->isEmpty()) {
                    if ($draftIntegrityQuarantines !== []) {
                        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
                        if ($learningConfirmation) {
                            $reasons = collect($draftIntegrityQuarantines)
                                ->flatMap(fn (array $row): array => (array) ($row['violations'] ?? []))
                                ->map('strval')->unique()->values()->all();
                            app(CausalLearningCohortService::class)
                                ->invalidateGeneration($generation->fresh(), $reasons);
                            $technicalBreaker->releaseAcquiredProbe(
                                $symbol,
                                $timeframe,
                                'LEARNING_CONFIRMATION_PREFLIGHT_REJECTED',
                            );
                        }
                        $this->warn("{$symbol}: all recoverable children failed identity integrity; generation quarantined without screening evidence.");

                        continue;
                    }
                    $this->info("{$symbol}: generation is already dispatched or evaluated.");

                    continue;
                }
                $includeVolume = $generation->agents->contains(fn ($agent): bool => $this->screeningDatasetContract($agent) === 'volume');
                // Freeze the exact price/volume snapshot before the first queue
                // job starts. Evaluator workers may drain over several new
                // candles; every child in this generation must see one dataset.
                // Keep the independent pre-2026 foundation contract beside the
                // rolling snapshot from the beginning. Screening may proceed
                // with the rolling tail, but full replay must never discover a
                // missing foundation only after queue admission.
                $foundationSnapshot = $datasets->ensureGenerationFoundationSnapshot($generation);
                // A volume lane adds evidence; it never replaces the mandatory
                // immutable price snapshot used by generation admission.
                $priceSnapshot = $datasets->ensureGenerationSnapshot($generation, false);
                $rollingSnapshot = $includeVolume
                    ? $datasets->ensureGenerationSnapshot($generation, true)
                    : $priceSnapshot;
                // Verify the frozen split before changing any child to queued.
                // A failed check leaves the generation draft/blocked instead of
                // allowing a paper candle to influence evolutionary screening.
                $datasets->assertGenerationDataPartition($generation, $foundationSnapshot, $rollingSnapshot);
                if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
                    && $timeframe === strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'))) {
                    $generation = $this->sealAutonomousMtfRuntime(
                        $generation,
                        $symbol,
                        $mtfSnapshots,
                        $generationContext,
                    );
                } elseif ($timeframe === 'M15') {
                    // M15 entries are evaluated against one immutable H1 regime
                    // snapshot whose last candle is already closed. This keeps
                    // screening reproducible and prevents a later open H1 candle
                    // from changing the meaning of an earlier M15 candidate.
                    $datasets->ensureGenerationRegimeSnapshot($generation);
                }
                $generation = $generation->fresh(['agents.modelVersion']);
                if ($this->expectedNativeGenerationId !== null) $this->assertExpectedNativeDepthGeneration($generation, $queueState);
                $generation = app(\App\Services\ResearchReleaseSealService::class)->seal($generation);
                $snapshotCheck = $snapshotAdmission->inspect($generation);
                if (! $snapshotCheck['allowed']) {
                    $this->nativeDepthRefusalReason = 'NATIVE_DEPTH_SNAPSHOT_ADMISSION_REFUSED';
                    $this->warn(sprintf(
                        '%s: G%s immutable snapshot/execution admission failed; screening was not queued (%s).',
                        $symbol,
                        $generation->generation,
                        implode(',', $snapshotCheck['reasons']),
                    ));

                    continue;
                }
                $configuredBatchSize = max(1, min(6, (int) config('services.lab_queue.screening_batch_size', 4)));
                // Differential/volume/portfolio lanes have materially more
                // stateful diagnostic work than a plain specialist. Keep those
                // cohorts smaller so one HTTP deadline cannot strand four
                // otherwise valid candidates. This is a scheduling budget only;
                // every agent keeps the same snapshot, trace, ledger and gates.
                $heavyScreeningBatch = $generation->agents
                    ->whereIn('id', $agentIds)
                    ->contains(function (LabAgent $agent): bool {
                        $metadata = (array) ($agent->modelVersion?->metadata ?? []);

                        return $agent->strategy_family === 'differential_router'
                            || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
                            || data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
                            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
                            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
                            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
                            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
                            || data_get($metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1';
                    });
                // A causal triplet must run its two counterfactuals concurrently
                // after the frozen control exists. Their stateful replay dominates
                // the few seconds saved by sharing feature construction; putting
                // both arms in one HTTP batch serialises them and roughly doubles
                // wall-clock learning latency. Single-agent jobs alternate the two
                // mutex slots: the control owns slot 0 first, while both candidates
                // later use slot 1/0 and keep independent immutable runs.
                $batchSize = $learningConfirmation
                    ? 1
                    : ($heavyScreeningBatch
                        ? min($configuredBatchSize, 2)
                        : $configuredBatchSize);
                $orderedIds = $agentIds->map(fn ($id): int => (int) $id)->all();
                if ($this->expectedNativeGenerationId !== null && $orderedIds !== $this->nativeDepthExpectedAgentIds) {
                    throw new \LogicException('NATIVE_DEPTH_PHASE_AGENT_SET_DRIFT');
                }
                $jobs = $this->screeningJobs($generation, $dispatchAgents, $orderedIds, $batchSize, $symbol, $timeframe);
                // Resolve the original diagnostic phase before a queued write
                // makes its owner correctly report that phase as in flight.
                if ($this->expectedNativeGenerationId !== null) {
                    $this->assertExpectedNativeDepthGeneration($generation, $queueState);
                    if (count($jobs) !== 1 || $jobs[0]->labAgentIds !== $this->nativeDepthExpectedAgentIds) {
                        throw new \LogicException('NATIVE_DEPTH_SINGLE_PHASE_JOB_REQUIRED');
                    }
                }
                $generation->agents()->whereIn('id', $agentIds)->update(['lifecycle_status' => 'queued']);
                foreach ($generation->agents->whereIn('id', $draftAgents->pluck('id')) as $agent) {
                    $agent->lifecycle_status = 'queued';
                    $evidence->recordAgentStatusChanged($agent, 'draft', 'queued', 'DispatchLabGeneration.bulk_dispatch');
                }
                $generation->update(['status' => 'screening']);

                $batch = Bus::batch($jobs)
                    ->name("{$symbol} {$timeframe} Lab G{$generation->generation} screening")
                    ->allowFailures()
                    ->onConnection((string) config('queue.default', 'redis'))
                    ->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))
                    ->dispatch();
                // The evaluator can start immediately after dispatch and append
                // its own report/context projection.  Merge the batch id under a
                // short row lock so that the queue-batch identity cannot be lost
                // to a stale model instance or a concurrent worker write.
                $generationContext->update($generation, function (array $context) use ($batch): array {
                    $queueBatches = (array) ($context['queue_batches'] ?? []);
                    $queueBatches['screening'] = array_values(array_unique([
                        ...((array) ($queueBatches['screening'] ?? [])),
                        (string) $batch->id,
                    ]));
                    $context['queue_batches'] = $queueBatches;

                    return $context;
                });
                if ($this->expectedNativeGenerationId !== null) $this->nativeDepthQueuedAgentIds = $orderedIds;

                $this->info(sprintf(
                    '%s: %s, %d agents in %d bounded screening batches dispatched (batch_size=%d, heavy_lane=%s).',
                    $symbol,
                    $batch->id,
                    count($agentIds),
                    count($jobs),
                    max(array_map(fn (EvaluateLabScreeningBatchJob $job): int => count($job->labAgentIds), $jobs)),
                    $heavyScreeningBatch ? 'yes' : 'no',
                ));
            } finally {
                $dispatchLease->release();
            }
        }

        return self::SUCCESS;
    }

    /** Read-only same-generation fence; admission still uses every canonical gate. */
    private function assertExpectedNativeDepthGeneration(?LabGeneration $selected, LabQueueJobInspector $queueState): LabGeneration
    {
        if (strtoupper((string) $this->argument('symbol')) !== 'XAUUSD'
            || strtoupper((string) $this->option('timeframe')) !== 'H1'
            || strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1')) !== 'H1'
            || ! $this->option('resume-draft-agents')) {
            throw new \LogicException('NATIVE_DEPTH_EXACT_SCOPE_AND_RESUME_REQUIRED');
        }
        foreach (['force-generation', 'controlled-rescue', 'shadow-research', 'audited-data-edge', 'learning-confirmation'] as $flag) {
            if ($this->option($flag)) throw new \LogicException('NATIVE_DEPTH_ALTERNATIVE_CREATION_FLAG_FORBIDDEN');
        }
        $lab = AiLaboratory::where('symbol', 'XAUUSD')->where('timeframe', 'H1')->first();
        $latest = $lab?->generations()->with('agents')->latest('generation')->first();
        if (! $latest || (int) $latest->id !== $this->expectedNativeGenerationId
            || ($selected !== null && (int) $selected->id !== (int) $latest->id)) {
            throw new \LogicException('NATIVE_DEPTH_EXPECTED_GENERATION_NOT_LATEST');
        }
        if (! in_array((string) $latest->status, ['draft', 'queued', 'screening'], true)
            || data_get($latest->trigger_context, 'native_specialist_council_intent.research_purpose') !== NativeReachabilityDepthAuditService::PURPOSE) {
            throw new \LogicException('NATIVE_DEPTH_ORIGINAL_PURPOSE_REQUIRED');
        }
        $preparation = app(\App\Services\SpecialistCouncilPreparationService::class);
        if (! $preparation->hasNativeConstructorIntent($latest) || ! $preparation->isResearchGeneration($latest)) {
            throw new \LogicException('NATIVE_DEPTH_PREPARED_OWNER_REQUIRED');
        }
        $state = app(NativeReachabilityDepthAuditService::class)->inspectContinuation($latest);
        if (! in_array($state['status'] ?? null, ['cheap_pending', 'deeper_ready'], true)) {
            throw new \LogicException('NATIVE_DEPTH_ORIGINAL_PHASE_NOT_READY');
        }
        if ($state['status'] === 'deeper_ready' && (($state['generation_id'] ?? null) !== (int) $latest->id
            || ($state['same_original_question'] ?? false) !== true)) {
            throw new \LogicException('NATIVE_DEPTH_ORIGINAL_SELECTION_REQUIRED');
        }
        $ids = $preparation->nativeDiagnosticDispatchAgentIds($latest);
        if (! is_array($ids) || count($ids) !== 1 || ! is_int($ids[0] ?? null) || $ids[0] <= 0
            || ($this->nativeDepthExpectedAgentIds !== [] && $ids !== $this->nativeDepthExpectedAgentIds)) {
            throw new \LogicException('NATIVE_DEPTH_SINGLE_ORIGINAL_PHASE_REQUIRED');
        }
        $agent = $latest->agents->firstWhere('id', $ids[0]);
        if (! $agent || (string) $agent->lifecycle_status !== 'draft'
            || LabEvaluationRun::where('lab_agent_id', $ids[0])->exists()
            || $queueState->hasAgentJob($ids[0], [(string) config('services.lab_queue.screening_queue', 'lab-screening'),
                (string) config('services.lab_queue.full_queue', 'lab-full-validation')])) {
            throw new \LogicException('NATIVE_DEPTH_ORIGINAL_PHASE_ALREADY_ADMITTED');
        }
        $this->nativeDepthExpectedAgentIds = $ids;

        return $latest;
    }

    /**
     * H1 is only the laboratory identity. Freeze the executable organism
     * before the first queue job so screening and full replay consume the
     * same M5 stream and the same closed H4/H1/M15 context files.
     */
    private function sealAutonomousMtfRuntime(
        LabGeneration $generation,
        string $symbol,
        MultiTimeframeSnapshotService $mtfSnapshots,
        LabGenerationContextService $generationContext,
    ): LabGeneration {
        $existingManifest = (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []);
        $discovery = ($existingManifest['validation_bundle_protocol'] ?? null) === MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL;
        $academyDiscoveryOwner = $generation->trigger_type === 'academy_experiment'
            && data_get($generation->trigger_context, 'prospective_source_identity.data_role') === 'pre_2026_discovery_only';
        if ($discovery && ! $academyDiscoveryOwner
            && ! app(\App\Services\SpecialistCouncilPreparationService::class)->inspectDiscoveryOwner($generation, $existingManifest)['allowed']) {
            throw new \RuntimeException('GENERATION_DISCOVERY_BUNDLE_OWNER_INVALID');
        }
        $bundle = $existingManifest !== []
            ? ($discovery ? $mtfSnapshots->restoreAgentOwnedConfirmationValidationBundle($existingManifest, true)
                : $mtfSnapshots->restoreAgentOwnedConfirmationValidationBundle($existingManifest))
            : $mtfSnapshots->forAgentOwnedConfirmationValidation($symbol);

        return $generationContext->update(
            $generation,
            function (array $context) use ($bundle): array {
                $existingHash = (string) data_get($context, 'mtf_bundle_hash', '');
                if ($existingHash !== '' && ! hash_equals($existingHash, (string) $bundle['bundle_hash'])) {
                    throw new \RuntimeException('Generation MTF bundle identity changed before dispatch.');
                }

                $context['mtf_bundle_hash'] = (string) $bundle['bundle_hash'];
                $context['mtf_bundle_manifest'] = (array) $bundle['manifest'];
                $context['mtf_runtime_contract'] = [
                    'protocol' => 'autonomous_generation_closed_mtf_v1',
                    'laboratory_storage_timeframe' => strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1')),
                    'execution_timeframe' => strtoupper((string) config('services.xauusd_organism.execution_timeframe', 'M5')),
                    'context_timeframes' => ['H4', 'H1', 'M15'],
                    'bundle_hash' => (string) $bundle['bundle_hash'],
                    'status' => 'sealed',
                    'promotion_evidence' => false,
                ];

                return $context;
            },
        );
    }

    private function screeningDatasetContract(LabAgent $agent): string
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $parameters = (array) ($agent->modelVersion?->parameters ?? []);
        $volume = data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
            || data_get($parameters, 'volume_lane', 'none') !== 'none';

        return $volume ? 'volume' : 'price';
    }

    /** Each typed 15k replay gets its own unchanged HTTP/Python and queue lease. */
    private function screeningJobs(LabGeneration $generation, \Illuminate\Support\Collection $dispatchAgents,
        array $orderedIds, int $ordinaryBatchSize, string $symbol, string $timeframe): array
    {
        $studyIds = app(\App\Services\SpecialistCouncilPreparationService::class)->nativeDiagnosticDispatchAgentIds($generation);
        if ($studyIds !== null) {
            $dispatchAgents = $dispatchAgents->whereIn('id', $studyIds)->values();
            $orderedIds = array_values(array_intersect($orderedIds, $studyIds));
        }
        // Include resumed queued agents, not only newly constructed draft seats.
        $controlIds = $dispatchAgents->whereIn('id', $orderedIds)
            ->filter(fn (LabAgent $agent): bool => $this->isFrozenRepairControl($agent))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $remainingIds = array_values(array_diff($orderedIds, $controlIds));
        $chunks = array_map(fn (int $id): array => [$id], $controlIds);
        $windowOwner = app(ProspectiveRepairProbeWindowService::class);
        $nativeCouncilResearch = app(\App\Services\SpecialistCouncilPreparationService::class)->isResearchGeneration($generation);
        $singleCandidate = fn (LabAgent $agent): bool => $nativeCouncilResearch || $windowOwner->requiresSingleCandidateScreening(
            (array) ($agent->modelVersion?->metadata ?? []), (string) $generation->trigger_type,
            (array) ($generation->trigger_context ?? []));
        // Keep both physical dataset and row-budget contracts separate. Never
        // run several typed windows recursively inside one 2400s queue job.
        $groups = $dispatchAgents->whereIn('id', $remainingIds)
            ->sortBy(fn (LabAgent $agent): int => array_search((int) $agent->id, $remainingIds, true))
            ->groupBy(fn (LabAgent $agent): string => $this->screeningDatasetContract($agent)
                .($singleCandidate($agent) ? ':single_15k' : ':ordinary'));
        foreach ($groups as $agents) {
            $size = $singleCandidate($agents->first()) ? 1 : $ordinaryBatchSize;
            foreach (array_chunk($agents->pluck('id')->map(fn ($id): int => (int) $id)->all(), $size) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return collect($chunks)->values()->map(fn (array $ids, int $index) =>
            new EvaluateLabScreeningBatchJob($ids, $symbol, $index % 2, $generation->id, $timeframe))->all();
    }

    /**
     * Normal research may enter the queue only when every execution lane has
     * an exact same-generation frozen control/candidate pair and the
     * structural research contract survived generation-context projection.
     * Rescue, shadow and explicitly audited data-edge cohorts use their own
     * admission protocols and are intentionally checked elsewhere.
     *
     * @return array{allowed: bool, reasons: array<int, string>}
     */
    private function normalCausalAdmission($generation): array
    {
        $native = app(\App\Services\SpecialistCouncilPreparationService::class);
        if ($generation instanceof \App\Models\LabGeneration && $native->hasNativeConstructorIntent($generation)) {
            try {
                $native->isResearchGeneration($generation);
                return ['allowed' => true, 'reasons' => [], 'owner' =>
                    data_get($generation->trigger_context, 'native_specialist_council_intent.research_purpose') === 'spread_context_study'
                        ? 'original_native_spread_context_study' : 'original_native_council_research_plan'];
            } catch (\LogicException $error) {
                return ['allowed' => false, 'reasons' => [$error->getMessage()]];
            }
        }
        $context = (array) ($generation->trigger_context ?? []);
        $mode = (string) data_get(
            $context,
            'research_allocation_budget.mode',
            data_get($context, 'control_pairing_contract.mode', ''),
        );
        if ($mode !== 'normal_research') {
            return ['allowed' => true, 'reasons' => []];
        }

        $reasons = [];
        $pairing = (array) data_get($context, 'control_pairing_contract', []);
        if ((string) data_get($pairing, 'protocol', '') !== ResearchAllocationPolicyService::CONTROL_PAIR_PROTOCOL) {
            $reasons[] = 'NORMAL_CONTROL_PAIR_PROTOCOL_MISSING';
        }
        if (! (bool) data_get($pairing, 'allowed', false)) {
            $reasons[] = 'NORMAL_CONTROL_CANDIDATE_PAIR_INCOMPLETE';
        }
        if ((array) data_get($pairing, 'missing_execution_lanes', []) !== []) {
            $reasons[] = 'NORMAL_CONTROL_LANE_MISSING';
        }
        if ((array) data_get($pairing, 'missing_candidate_pairs', []) !== []) {
            $reasons[] = 'NORMAL_CANDIDATE_PAIR_MISSING';
        }

        if ((bool) data_get($context, 'normal_structural_research_expected', true)) {
            $structural = (array) data_get($context, 'structural_research_contract', []);
            if ((string) data_get($structural, 'protocol', '') !== 'normal_structural_research_v1') {
                $reasons[] = 'NORMAL_STRUCTURAL_CONTRACT_MISSING';
            }
            if ((int) data_get($structural, 'structural_candidate_count', 0) < 1) {
                $reasons[] = 'NORMAL_STRUCTURAL_CANDIDATE_MISSING';
            }
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * A differential router's target regime is an execution-contract
     * coordinate, not the causal gene being researched.  Older drafts built
     * from a hybrid parent could therefore report two parameter changes:
     * target-regime selection plus the declared lane mutation.  Repair only
     * this derived diff/metadata shape before screening; model parameters and
     * their hashes remain untouched. Any other multi-gene shape stays a hard
     * quarantine.
     */
    private function repairDifferentialContractCoordinate($agent): array
    {
        if ($agent->origin !== 'g98_council' || $agent->strategy_family !== 'differential_router') {
            return [];
        }

        $diff = (array) $agent->parameter_diff;
        if (! array_key_exists('differential_target_regime', $diff) || count($diff) !== 2) {
            return [];
        }

        $changedKeys = array_values(array_diff(array_keys($diff), ['differential_target_regime']));
        if (count($changedKeys) !== 1) {
            return [];
        }

        $model = $agent->modelVersion;
        $parameters = (array) $model?->parameters;
        $metadata = (array) $model?->metadata;
        $router = (array) data_get($metadata, 'differential_router_contract', []);
        $targetRegime = (string) data_get($router, 'target_regime', '');
        $contractValue = (string) data_get($diff['differential_target_regime'], 'new', '');
        $changedKey = (string) $changedKeys[0];

        if ($model === null
            || $targetRegime === ''
            || $contractValue === ''
            || $targetRegime !== $contractValue
            || ! array_key_exists($changedKey, $parameters)
            || ! array_key_exists('new', (array) ($diff[$changedKey] ?? []))) {
            return [];
        }

        $cleanDiff = [$changedKey => $diff[$changedKey]];
        $hypothesis = (array) data_get($metadata, 'hypothesis_contract', []);
        $hypothesis['changed_gene'] = $changedKey;
        $metadata['hypothesis_contract'] = $hypothesis;
        $router['target_parameter'] = $changedKey;
        $metadata['differential_router_contract'] = $router;

        $agent->update(['parameter_diff' => $cleanDiff]);
        $model->update(['metadata' => $metadata]);

        return [
            'agent_id' => $agent->id,
            'removed_derived_diff_key' => 'differential_target_regime',
            'changed_gene' => $changedKey,
            'target_regime' => $targetRegime,
            'contract_value' => $contractValue,
        ];
    }

    private function isFrozenRepairControl(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $siblingKind = (string) data_get(
            $metadata,
            'repair_anchor.sibling_kind',
            data_get($metadata, 'repair_anchor_sibling.kind', ''),
        );

        $canonicalControl = app(FrozenControlScreeningAdmissionService::class)->isControl($agent);

        return $canonicalControl
            || (bool) data_get($metadata, 'repair_anchor.control_only', false)
            || in_array($siblingKind, ['frozen_control', 'control'], true);
    }

    /**
     * Find queued children that have neither a screening run nor a live queue
     * payload. This is the narrow recovery case created when an integrity
     * repair re-queued an agent after the original batch had already been
     * dispatched. Unknown queue state stays fail-closed.
     */
    private function strandedQueuedAgents($generation, LabQueueJobInspector $queueState)
    {
        $screeningQueue = (string) config('services.lab_queue.screening_queue', 'lab-screening');
        $snapshot = $queueState->queueSnapshot([$screeningQueue]);
        if (($snapshot['available'] ?? false) !== true) {
            return collect();
        }

        return $generation->agents
            ->where('lifecycle_status', 'queued')
            ->filter(function (LabAgent $agent) use ($generation, $queueState, $screeningQueue): bool {
                $hasScreenRun = LabEvaluationRun::query()
                    ->where('lab_generation_id', $generation->id)
                    ->where('lab_agent_id', $agent->id)
                    ->where('phase', 'screening')
                    ->exists();

                return ! $hasScreenRun && ! $queueState->hasAgentJob((int) $agent->id, [$screeningQueue]);
            })
            ->values();
    }

    /**
     * A timed-out constructor may leave a generation row with fewer agents
     * than its immutable population budget. Draft continuation is allowed
     * only after the complete population is present; otherwise the partial
     * cohort remains fail-closed and must go through integrity repair.
     */
    private function constructorCompleteForDraftContinuation($generation): bool
    {
        $agents = $generation->agents;
        $planned = (int) $generation->population_size;
        if ($planned < 1
            || $agents->count() < $planned
            || $agents->contains(fn (LabAgent $agent): bool => ! $agent->model_version_id)) {
            return false;
        }

        $context = (array) $generation->trigger_context;
        if ((string) data_get($context, 'shadow_research_constructor_abort.reason_code', '')
            === 'INCOMPLETE_SHADOW_RESEARCH_POPULATION') {
            return false;
        }

        $audit = (array) data_get($context, 'constructor_audit', []);
        $plannedSlots = (int) data_get($audit, 'planned_slots', 0);
        $createdAgents = (int) data_get($audit, 'created_agents', 0);
        if ($plannedSlots > 0 && ($plannedSlots > $agents->count() || $createdAgents < $plannedSlots)) {
            return false;
        }

        return true;
    }

    /**
     * Validate a draft before it can create any screening evidence.  New G98
     * council children are isolated one-gene experiments; a stale model hash,
     * zero-diff clone, or changed-gene mismatch makes the experiment
     * uninterpretable and must never be mistaken for a strategy verdict.
     */
    private function draftIntegrityViolations($agent, StrategyParameterSchemaService $schemas): array
    {
        $model = $agent->modelVersion;
        if (! $model) {
            return ['MODEL_VERSION_MISSING'];
        }

        $parameters = (array) $model->parameters;
        $fingerprintParameters = $schemas->canonicalizeForIdentity($agent->strategy_family, $parameters);
        $expectedFingerprint = hash('sha256', $agent->strategy_family.'|'.json_encode($fingerprintParameters, JSON_PRESERVE_ZERO_FRACTION));
        $expectedUniversalHash = hash('sha256', json_encode($fingerprintParameters, JSON_PRESERVE_ZERO_FRACTION));
        $violations = [];

        $boundedRootRecovery = data_get($model->metadata, 'recovery_protocol.protocol') === 'bounded_root_recovery_v1';
        if (! $boundedRootRecovery && data_get($model->metadata, 'parameter_fingerprint') !== $expectedFingerprint) {
            $violations[] = 'PARAMETER_FINGERPRINT_MISMATCH';
        }
        if (! $boundedRootRecovery && data_get($model->metadata, 'universal_genome.local_adapter.parameters_hash') !== $expectedUniversalHash) {
            $violations[] = 'UNIVERSAL_PARAMETERS_HASH_MISMATCH';
        }

        $metadata = (array) $model->metadata;
        $violations = array_merge($violations, $this->councilRoleIntegrityViolations($metadata, $parameters));
        $isolated = $agent->origin === 'g98_council'
            || data_get($metadata, 'causal_experiment_lane.status') === 'isolated_single_gene'
            || filled(data_get($metadata, 'hypothesis_contract.changed_gene'));
        if (! $isolated) {
            return $violations;
        }

        $diff = (array) $agent->parameter_diff;
        $hybridMultiGene = (bool) data_get($metadata, 'hybrid_evolution.multi_gene', false);
        if ($hybridMultiGene) {
            $declaredGenes = array_values(array_filter(array_map('strval', (array) data_get(
                $metadata,
                'structural_research_contract.declared_genes',
                data_get($metadata, 'hypothesis_contract.changed_genes', []),
            ))));
            $changedGenes = array_values(array_map('strval', array_keys($diff)));
            sort($declaredGenes);
            sort($changedGenes);
            if (count($diff) < 2 || count($diff) > 3 || $declaredGenes === [] || $changedGenes !== $declaredGenes) {
                $violations[] = 'HYBRID_MULTI_GENE_DECLARATION_MISMATCH';

                return array_values(array_unique($violations));
            }
            foreach ($diff as $gene => $change) {
                if (! array_key_exists((string) $gene, $parameters)
                    || ! is_array($change)
                    || ! array_key_exists('new', $change)
                    || $parameters[$gene] != $change['new']) {
                    $violations[] = 'HYBRID_MULTI_GENE_VALUE_MISMATCH';
                    break;
                }
            }

            return array_values(array_unique($violations));
        }
        if (count($diff) !== 1) {
            // A role may exhaust every legal owner mutation after the
            // direction firewall has learned several harmful directions. The
            // resulting no-change control is still useful replay evidence,
            // but it is explicitly barred from specialist/passport promotion.
            $roleControl = (bool) data_get($metadata, 'mutation_constructor_invariant.control_only', false)
                || (bool) data_get($metadata, 'g98_council_lane.control_only', false)
                || data_get($metadata, 'role_complete_council.role_control.type') === 'no_change_control';
            // Architecture rescue is a first-class causal mutation. Its
            // executable parameter vector is intentionally frozen; the
            // changed gene is the sealed strategy topology, not a scalar in
            // parameter_diff. Keep this admission rule identical to
            // LabAgentPreflightService so a valid topology variant cannot be
            // quarantined merely because the legacy checker expected one
            // numeric parameter.
            $architectureVariant = (string) data_get(
                $metadata,
                'mutation_constructor_invariant.architecture_variant',
                data_get($metadata, 'portfolio_council_lane.architecture_variant', ''),
            );
            $architectureChanged = (bool) data_get(
                $metadata,
                'mutation_constructor_invariant.architecture_changed',
                false,
            )
                && $architectureVariant !== ''
                && (string) data_get($metadata, 'strategy_architecture', '') === $architectureVariant
                && (
                    (bool) data_get($metadata, 'portfolio_council_lane.architecture_experiment', false)
                    || (string) data_get($metadata, 'hypothesis_contract.planner_declared_gene', '') === '__architecture'
                    || (string) data_get($metadata, 'hypothesis_contract.changed_gene', '') === '__architecture'
                    || (string) data_get($metadata, 'g98_council_lane.lane', '') === 'architecture'
                );
            if (count($diff) === 0 && ($roleControl || $architectureChanged)) {
                return $violations;
            }
            $violations[] = count($diff) === 0 ? 'ISOLATED_ZERO_PARAMETER_DIFF' : 'ISOLATED_MULTI_PARAMETER_DIFF';

            return $violations;
        }

        $changedKey = (string) array_key_first($diff);
        $declaredKey = data_get($metadata, 'hypothesis_contract.changed_gene')
            ?: data_get($metadata, 'differential_router_contract.target_parameter');
        if ($declaredKey !== null && (string) $declaredKey !== $changedKey) {
            $violations[] = 'DECLARED_GENE_DIFF_MISMATCH';
        }
        $change = (array) ($diff[$changedKey] ?? []);
        if (! array_key_exists($changedKey, $parameters) || ! array_key_exists('new', $change)) {
            $violations[] = 'DECLARED_GENE_PARAMETER_MISSING';
        } elseif ($parameters[$changedKey] != $change['new']) {
            $violations[] = 'DECLARED_GENE_VALUE_MISMATCH';
        }

        return array_values(array_unique($violations));
    }

    /**
     * Validate the role contract before screening evidence exists. A draft
     * that disables its own transition firewall, breaks range ownership, or
     * omits the bounded policy is a construction defect; quarantine it without
     * producing a misleading strategy verdict.
     */
    private function councilRoleIntegrityViolations(array $metadata, array $parameters): array
    {
        if (data_get($metadata, 'role_complete_council.protocol') !== 'role_complete_council_v1') {
            return [];
        }

        $violations = [];
        $policy = (array) data_get($metadata, 'role_complete_council.policy', []);
        if (data_get($policy, 'protocol') !== 'council_role_policy_v1') {
            $violations[] = 'ROLE_POLICY_MISSING_OR_INVALID';
        }

        $role = (string) data_get($metadata, 'role_complete_council.role', '');
        $allowed = (array) data_get($policy, 'mutation_allowlist', []);
        $changedGene = data_get($policy, 'changed_gene');
        if ($changedGene !== null && ! in_array((string) $changedGene, $allowed, true)) {
            $violations[] = 'ROLE_POLICY_CHANGED_GENE_OUTSIDE_ALLOWLIST';
        }

        if (in_array($role, ['trend_up_specialist', 'trend_down_specialist', 'range_specialist', 'transition_risk_router'], true)
            && ($parameters['transition_firewall_enabled'] ?? null) !== true) {
            $violations[] = 'ROLE_POLICY_TRANSITION_FIREWALL_MUST_REMAIN_ENABLED';
        }
        if ($role === 'range_specialist') {
            if (($parameters['range_low_volatility_only'] ?? null) !== true) {
                $violations[] = 'ROLE_POLICY_RANGE_VOLATILITY_OWNERSHIP_BREACH';
            }
            if (($parameters['range_reentry_required'] ?? null) !== true) {
                $violations[] = 'ROLE_POLICY_RANGE_REENTRY_INVARIANT_BREACH';
            }
        }
        if ($role === 'transition_risk_router' && data_get($policy, 'routing_only') !== true) {
            $violations[] = 'ROLE_POLICY_ROUTER_NOT_ROUTING_ONLY';
        }

        return array_values(array_unique($violations));
    }
}
