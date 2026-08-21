<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Services\CandidateHandoffService;
use App\Services\LabAgentPreflightService;
use App\Services\LabDatasetExportService;
use App\Services\LabGenerationContextService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningTechnicalCircuitBreakerService;
use App\Services\LearningEvidenceGate;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DispatchLabGeneration extends Command
{
    protected $signature = 'trading:dispatch-lab {symbol?} {--timeframe=H1} {--force-generation} {--controlled-rescue : Dispatch an already-approved XAUUSD H1 controlled rescue only} {--shadow-research : Dispatch only an already-approved shadow-research generation} {--audited-data-edge : Dispatch only an explicitly audited data-edge generation while normal creation remains paused} {--resume-draft-agents : Continue stranded draft agents after a complete constructor has already opened the generation}';

    protected $description = 'Dispatch pair-local incremental screening for each draft laboratory agent';

    public function handle(LabPopulationService $populations, LabDatasetExportService $datasets, MarketDataContinuityService $continuity, LabImmutableEvidenceService $evidence, CandidateHandoffService $handoffs, LabAgentPreflightService $preflight, LearningProtocolSafetyService $protocolSafety, LearningTechnicalCircuitBreakerService $technicalBreaker, LearningEvidenceGate $evidenceGate, LabQueueJobInspector $queueState, StrategyParameterSchemaService $schemas, LabGenerationContextService $generationContext): int
    {
        $populations->ensureLaboratories();
        $controlledRescue = (bool) $this->option('controlled-rescue');
        $shadowResearch = (bool) $this->option('shadow-research');
        $auditedDataEdge = (bool) $this->option('audited-data-edge');
        $resumeDraftAgents = (bool) $this->option('resume-draft-agents');
        if ($shadowResearch
            && (strtoupper((string) ($this->argument('symbol') ?: '')) !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || strtoupper((string) $this->option('timeframe')) !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Shadow research dispatch faqat XAUUSD H1 lighthouse uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        if ($controlledRescue
            && (strtoupper((string) ($this->argument('symbol') ?: '')) !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || strtoupper((string) $this->option('timeframe')) !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Controlled rescue dispatch faqat XAUUSD H1 uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        if ($auditedDataEdge
            && (strtoupper((string) ($this->argument('symbol') ?: '')) !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || strtoupper((string) $this->option('timeframe')) !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Audited data-edge dispatch faqat XAUUSD H1 lighthouse uchun ruxsat etiladi.');

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
            $this->warn('Lab queue state unavailable; generation dispatch deferred fail-closed.');

            return self::SUCCESS;
        }
        if (($queueSnapshot['total'] ?? 0) !== null) {
            $pending = (int) ($queueSnapshot['total'] ?? 0);
            $limit = max(1, (int) config('services.lab_selection.max_screening_jobs', 40));
            if ($pending >= $limit) {
                $this->warn("Lab screening backlog {$pending} >= {$limit}; dispatch deferred.");

                return self::SUCCESS;
            }
        }

        $timeframe = strtoupper((string) $this->option('timeframe'));
        foreach ($symbols as $symbol) {
            if ($technicalBreaker->blocked($symbol, $timeframe) && ! $resumeDraftAgents) {
                $this->warn("{$symbol} {$timeframe}: repeated technical failure circuit breaker active; new generation blocked pending technical repair.");
                continue;
            }
            if (! $resumeDraftAgents && ! $controlledRescue && ! $shadowResearch && ! $auditedDataEdge) {
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
                $this->error('Audited data-edge dispatch faqat XAUUSD H1 lighthouse uchun ruxsat etiladi.');

                return self::FAILURE;
            }
            if ((string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} {$timeframe}: shadow lab; normal screening dispatch skipped.");

                continue;
            }
            $generation = $lab->generations()->with('agents')->latest('generation')->first();
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
                || ($this->option('force-generation')
                    && ! in_array((string) $generation->status, $activeStatuses, true));
            if ($shouldBuildGeneration) {
                $generation = $shadowResearch
                    ? $populations->build($symbol, 'shadow_research', false, $timeframe, [], false, false, (int) config('services.lab_selection.population_size', 20))
                    : ($auditedDataEdge
                        ? $populations->build($symbol, 'data_edge_audit', true, $timeframe)
                        : $populations->build($symbol, 'new_data', (bool) $this->option('force-generation'), $timeframe));
            }

            if (! $generation) {
                $this->warn("{$symbol}: new learning evidence is not available.");

                continue;
            }
            if ($shadowResearch && (string) $generation->trigger_type !== 'shadow_research') {
                $this->error("{$symbol}: shadow flag bilan normal generation dispatch qilinmaydi.");

                continue;
            }
            if ($controlledRescue
                && data_get($generation->trigger_context, 'controlled_rescue_admission.protocol')
                    !== LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL) {
                $this->error("{$symbol}: target generation controlled-rescue admission contracti topilmadi; dispatch bloklandi.");

                continue;
            }
            if (! $controlledRescue && ! $shadowResearch && ! $auditedDataEdge && ! $resumeExistingGeneration) {
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
            $draftAgents = $generation->agents->where('lifecycle_status', 'draft');
            $strandedQueuedAgents = ($resumeDraftAgents
                && in_array((string) $generation->status, ['queued', 'screening'], true)
                && $this->constructorCompleteForDraftContinuation($generation))
                ? $this->strandedQueuedAgents($generation, $queueState)
                : collect();
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
            $strandedQueuedAgents = ($resumeDraftAgents
                && in_array((string) $generation->status, ['queued', 'screening'], true)
                && $this->constructorCompleteForDraftContinuation($generation))
                ? $this->strandedQueuedAgents($generation, $queueState)
                : collect();
            $draftIntegrityQuarantines = [];
            foreach ($generation->agents->where('lifecycle_status', 'draft') as $agent) {
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
            $agentIds = $dispatchAgents->pluck('id');
            if ($agentIds->isEmpty()) {
                if ($draftIntegrityQuarantines !== []) {
                    $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
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
            $rollingSnapshot = $datasets->ensureGenerationSnapshot($generation, $includeVolume);
            // Verify the frozen split before changing any child to queued.
            // A failed check leaves the generation draft/blocked instead of
            // allowing a paper candle to influence evolutionary screening.
            $datasets->assertGenerationDataPartition($generation, $foundationSnapshot, $rollingSnapshot);
            if ($timeframe === 'M15') {
                // M15 entries are evaluated against one immutable H1 regime
                // snapshot whose last candle is already closed. This keeps
                // screening reproducible and prevents a later open H1 candle
                // from changing the meaning of an earlier M15 candidate.
                $datasets->ensureGenerationRegimeSnapshot($generation);
            }
            $generation->agents()->whereIn('id', $agentIds)->update(['lifecycle_status' => 'queued']);
            foreach ($generation->agents->whereIn('id', $draftAgents->pluck('id')) as $agent) {
                $agent->lifecycle_status = 'queued';
                $evidence->recordAgentStatusChanged($agent, 'draft', 'queued', 'DispatchLabGeneration.bulk_dispatch');
            }
            $generation->update(['status' => 'screening']);
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
            $batchSize = $heavyScreeningBatch
                ? min($configuredBatchSize, 2)
                : $configuredBatchSize;
            $orderedIds = $agentIds->map(fn ($id): int => (int) $id)->all();
            $controlIds = $draftAgents
                ->filter(fn (LabAgent $agent): bool => $this->isFrozenRepairControl($agent))
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $remainingIds = array_values(array_diff($orderedIds, $controlIds));
            $chunks = [];
            foreach ($controlIds as $controlId) $chunks[] = [$controlId];
            // A bounded batch shares one dataset path.  Never mix price and
            // volume contracts in one request: the old global `contains`
            // check made a price control inherit the volume snapshot merely
            // because a sibling in the same chunk used volume.
            $remainingAgents = $generation->agents
                ->whereIn('id', $remainingIds)
                ->sortBy(fn (LabAgent $agent): int => array_search((int) $agent->id, $remainingIds, true))
                ->groupBy(fn (LabAgent $agent): string => $this->screeningDatasetContract($agent));
            foreach ($remainingAgents as $contractAgents) {
                foreach (array_chunk($contractAgents->pluck('id')->map(fn ($id): int => (int) $id)->all(), $batchSize) as $chunk) {
                    $chunks[] = $chunk;
                }
            }
            $jobs = collect($chunks)
                ->values()
                ->map(fn (array $ids, int $index) => new EvaluateLabScreeningBatchJob($ids, $symbol, $index % 2))
                ->all();

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

            $this->info(sprintf(
                '%s: %s, %d agents in %d bounded screening batches dispatched (batch_size=%d, heavy_lane=%s).',
                $symbol,
                $batch->id,
                count($agentIds),
                count($jobs),
                $batchSize,
                $heavyScreeningBatch ? 'yes' : 'no',
            ));
            } finally {
                $dispatchLease->release();
            }
        }

        return self::SUCCESS;
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
        if ((string) data_get($pairing, 'protocol', '') !== 'frozen_control_pair_v1') {
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

        return (bool) data_get($metadata, 'repair_anchor.control_only', false)
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
                && (string) data_get($metadata, 'hypothesis_contract.changed_gene', '') === '__architecture';
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
