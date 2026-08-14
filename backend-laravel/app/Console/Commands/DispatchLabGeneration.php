<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Services\CandidateHandoffService;
use App\Services\LabAgentPreflightService;
use App\Services\LabDatasetExportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningProtocolSafetyService;
use App\Services\MarketData\MarketDataContinuityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DispatchLabGeneration extends Command
{
    protected $signature = 'trading:dispatch-lab {symbol?} {--timeframe=H1} {--force-generation} {--controlled-rescue : Dispatch an already-approved XAUUSD H1 controlled rescue only}';

    protected $description = 'Dispatch pair-local incremental screening for each draft laboratory agent';

    public function handle(LabPopulationService $populations, LabDatasetExportService $datasets, MarketDataContinuityService $continuity, LabImmutableEvidenceService $evidence, CandidateHandoffService $handoffs, LabAgentPreflightService $preflight, LearningProtocolSafetyService $protocolSafety, LabQueueJobInspector $queueState): int
    {
        $populations->ensureLaboratories();
        $controlledRescue = (bool) $this->option('controlled-rescue');
        if ($controlledRescue
            && (strtoupper((string) ($this->argument('symbol') ?: '')) !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
                || strtoupper((string) $this->option('timeframe')) !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME)) {
            $this->error('Controlled rescue dispatch faqat XAUUSD H1 uchun ruxsat etiladi.');

            return self::FAILURE;
        }
        if ($protocolSafety->generationCreationPaused() && ! $controlledRescue) {
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
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
            if ((string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} {$timeframe}: shadow lab; normal screening dispatch skipped.");

                continue;
            }
            $generation = $lab->generations()->with('agents')->latest('generation')->first();
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
                $generation = $populations->build($symbol, 'new_data', (bool) $this->option('force-generation'), $timeframe);
            }

            if (! $generation) {
                $this->warn("{$symbol}: new learning evidence is not available.");

                continue;
            }
            if ($controlledRescue
                && data_get($generation->trigger_context, 'controlled_rescue_admission.protocol')
                    !== LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL) {
                $this->error("{$symbol}: target generation controlled-rescue admission contracti topilmadi; dispatch bloklandi.");

                continue;
            }

            // A second scheduler/manual invocation may observe the same
            // generation while its screening jobs are already running. Do
            // not re-export the frozen dataset in that case: the export lock
            // belongs to the active evaluator and re-exporting can turn a
            // harmless duplicate dispatch into a false operational failure.
            $generation = $generation->fresh(['agents.modelVersion']);
            $draftAgents = $generation->agents->where('lifecycle_status', 'draft');
            if ($draftAgents->isEmpty() || (string) $generation->status !== 'draft') {
                $this->info("{$symbol}: generation is already dispatched or evaluated.");

                continue;
            }
            $datasets->export($symbol, $lab->timeframe);
            // The export is frozen before the first queue job starts. Re-read
            // after export so no concurrent dispatcher can queue the same
            // draft agents twice.
            $generation = $generation->fresh(['agents.modelVersion']);
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
                $violations = $this->draftIntegrityViolations($agent);
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
            if ($draftIntegrityQuarantines !== []) {
                $context = (array) $generation->trigger_context;
                $context['draft_integrity_quarantines'] = array_merge(
                    (array) ($context['draft_integrity_quarantines'] ?? []),
                    $draftIntegrityQuarantines,
                );
                $generation->update(['trigger_context' => $context]);
            }
            $agentIds = $generation->agents->where('lifecycle_status', 'draft')->pluck('id');
            if ($agentIds->isEmpty()) {
                if ($draftIntegrityQuarantines !== []) {
                    $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
                    $this->warn("{$symbol}: all draft children failed identity integrity; generation quarantined without screening evidence.");

                    continue;
                }
                $this->info("{$symbol}: generation is already dispatched or evaluated.");

                continue;
            }
            $includeVolume = $generation->agents->contains(fn ($agent): bool => (bool) data_get($agent->modelVersion?->metadata, 'volume_research_contract.enabled', false)
                || data_get($agent->modelVersion?->parameters, 'volume_lane', 'none') !== 'none'
            );
            // Freeze the exact price/volume snapshot before the first queue
            // job starts. Evaluator workers may drain over several new
            // candles; every child in this generation must see one dataset.
            // Keep the independent pre-2026 foundation contract beside the
            // rolling snapshot from the beginning. Screening may proceed
            // with the rolling tail, but full replay must never discover a
            // missing foundation only after queue admission.
            $datasets->ensureGenerationFoundationSnapshot($generation);
            $datasets->ensureGenerationSnapshot($generation, $includeVolume);
            if ($timeframe === 'M15') {
                // M15 entries are evaluated against one immutable H1 regime
                // snapshot whose last candle is already closed. This keeps
                // screening reproducible and prevents a later open H1 candle
                // from changing the meaning of an earlier M15 candidate.
                $datasets->ensureGenerationRegimeSnapshot($generation);
            }
            $generation->agents()->whereIn('id', $agentIds)->update(['lifecycle_status' => 'queued']);
            foreach ($generation->agents->whereIn('id', $agentIds) as $agent) {
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
                        || data_get($metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1';
                });
            $batchSize = $heavyScreeningBatch
                ? min($configuredBatchSize, 2)
                : $configuredBatchSize;
            $jobs = collect(array_chunk($agentIds->map(fn ($id): int => (int) $id)->all(), $batchSize))
                ->values()
                ->map(fn (array $ids, int $index) => new EvaluateLabScreeningBatchJob($ids, $symbol, $index % 2))
                ->all();

            $batch = Bus::batch($jobs)
                ->name("{$symbol} {$timeframe} Lab G{$generation->generation} screening")
                ->allowFailures()
                ->onConnection((string) config('queue.default', 'redis'))
                ->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))
                ->dispatch();

            $this->info(sprintf(
                '%s: %s, %d agents in %d bounded screening batches dispatched (batch_size=%d, heavy_lane=%s).',
                $symbol,
                $batch->id,
                count($agentIds),
                count($jobs),
                $batchSize,
                $heavyScreeningBatch ? 'yes' : 'no',
            ));
        }

        return self::SUCCESS;
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

    /**
     * Validate a draft before it can create any screening evidence.  New G98
     * council children are isolated one-gene experiments; a stale model hash,
     * zero-diff clone, or changed-gene mismatch makes the experiment
     * uninterpretable and must never be mistaken for a strategy verdict.
     */
    private function draftIntegrityViolations($agent): array
    {
        $model = $agent->modelVersion;
        if (! $model) {
            return ['MODEL_VERSION_MISSING'];
        }

        $parameters = (array) $model->parameters;
        $fingerprintParameters = $parameters;
        ksort($fingerprintParameters);
        $expectedFingerprint = hash('sha256', $agent->strategy_family.'|'.json_encode($fingerprintParameters, JSON_PRESERVE_ZERO_FRACTION));
        $expectedUniversalHash = hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
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
