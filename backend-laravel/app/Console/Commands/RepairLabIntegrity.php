<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\LabAgent;
use App\Models\LabTrialLedger;
use App\Models\ModelVersion;
use App\Services\LabAgentPreflightService;
use App\Services\ControlRootCatalogueService;
use App\Services\ControlRootInheritanceService;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabReplayRecoveryService;
use App\Services\LabQueueJobInspector;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Repair legacy lab records without deleting their immutable history. */
class RepairLabIntegrity extends Command
{
    protected $signature = 'trading:repair-lab-integrity
        {symbol? : Market symbol, for example XAUUSD}
        {--timeframe=H1}
        {--generation=* : Generation number(s) to audit; defaults to active generations}
        {--apply : Persist quarantine and lifecycle repairs}
        {--repair-missing-screen-evidence : Requeue screened agents that have no completed immutable screen run}
        {--repair-architecture-escape-contract : Repair a draft-only scalar leak from a topology-only architecture hypothesis}
        {--repair-stranded-screening-agents : Return queued/screening agents with no live or completed screen run to same-generation draft recovery}
        {--quarantine-contract-drift : Quarantine an active generation whose immutable population/constructor contract is already invalid}
        {--quarantine-invalid-normal-contract : Quarantine a draft normal cohort whose exact causal control/structural contract is incomplete}
        {--rebuild-root : After applying, create one clean next generation from exact parents or group roots}';

    protected $description = 'Quarantine legacy lineage/evidence and optionally rebuild a clean semantic lab generation.';

    public function handle(
        LabAgentPreflightService $preflight,
        StrategyParameterSchemaService $schemas,
        StrategySemanticGroupService $semanticGroups,
        ExecutionContractService $executionContracts,
        ControlRootCatalogueService $controlRoots,
        ControlRootInheritanceService $rootInheritance,
        LabImmutableEvidenceService $evidence,
        LabReplayRecoveryService $replayRecovery,
        LearningProtocolSafetyService $protocolSafety,
    ): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        if (! $lab) {
            $this->error("{$symbol} {$timeframe}: laboratory topilmadi.");
            return self::FAILURE;
        }

        $requested = collect((array) $this->option('generation'))
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): int => (int) $value)
            ->values();
        $generations = $lab->generations()
            ->when($requested->isNotEmpty(), fn ($query) => $query->whereIn('generation', $requested->all()))
            ->when($requested->isEmpty(), fn ($query) => $query->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES))
            ->with(['agents.modelVersion', 'agents.parentA', 'agents.parentB'])
            ->orderBy('generation')
            ->get();

        if ($generations->isEmpty()) {
            $this->info("{$symbol} {$timeframe}: repair talab qiladigan active generation yo'q.");
            return self::SUCCESS;
        }

        $invalid = 0;
        $architectureRepairs = 0;
        $strandedRepairs = 0;
        $contractQuarantined = 0;
        $normalContractQuarantined = 0;
        $queueInspector = app(LabQueueJobInspector::class);
        foreach ($generations as $generation) {
            $generationInvalid = 0;
            $normalContract = $this->invalidNormalContract($generation);
            if ($this->option('apply')
                && $this->option('quarantine-invalid-normal-contract')
                && $normalContract['issues'] !== []
                && in_array((string) $generation->status, ['draft', 'queued'], true)
                && $this->generationHasNoEvidenceOrQueueJobs($generation, $queueInspector)) {
                $this->warn("G{$generation->generation}: normal causal contract incomplete; draft cohort technical quarantine qilinmoqda.");
                foreach ($generation->fresh(['agents.modelVersion'])->agents as $agent) {
                    if (! in_array((string) $agent->lifecycle_status, ['draft', 'queued'], true)) continue;
                    $fromStatus = (string) $agent->lifecycle_status;
                    $agent->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => 'Normal causal contract incomplete; no strategy verdict or promotion evidence was created.',
                    ]);
                    $freshAgent = $agent->fresh(['modelVersion']);
                    $evidence->recordLifecycle($freshAgent, 'normal_causal_contract_quarantine', [
                        'reason_code' => 'NORMAL_CAUSAL_CONTRACT_INCOMPLETE',
                        'issues' => $normalContract['issues'],
                        'quality_verdict' => 'withheld',
                        'evidence_preserved' => true,
                        'promotion_evidence' => false,
                    ], 'screening', null, null, self::class, null, $fromStatus, 'technical_quarantine');
                    $normalContractQuarantined++;
                }
                $fresh = $generation->fresh(['agents']);
                $context = (array) ($fresh->trigger_context ?? []);
                $context['integrity_repair']['normal_causal_contract'] = [
                    'protocol' => 'normal_causal_contract_quarantine_v1',
                    'issues' => $normalContract['issues'],
                    'metrics' => $normalContract['metrics'],
                    'quarantined_at' => now()->utc()->toIso8601String(),
                    'evidence_preserved' => true,
                    'promotion_evidence' => false,
                ];
                $fresh->update([
                    'trigger_context' => $context,
                    'status' => 'technical_quarantine',
                    'completed_at' => now(),
                ]);
                continue;
            }
            foreach ($generation->agents as $agent) {
                if ($this->option('apply') && $this->option('repair-architecture-escape-contract')) {
                    $repair = $this->repairArchitectureEscapeContract($agent, $schemas);
                    if ($repair !== null) {
                        $architectureRepairs++;
                        $fromStatus = (string) $agent->lifecycle_status;
                        $agent->refresh();
                        $agent->update([
                            'parameter_diff' => [],
                            'lifecycle_status' => 'draft',
                            'decision_reason' => 'Architecture-only hypothesis contract repaired before screening; all promotion gates remain unchanged.',
                        ]);
                        $freshAgent = $agent->fresh(['modelVersion']);
                        $evidence->recordLifecycle($freshAgent, 'architecture_escape_contract_repair', [
                            'reason_code' => 'ARCHITECTURE_ESCAPE_SCALAR_LEAK_REMOVED',
                            ...$repair,
                            'promotion_evidence' => false,
                        ], 'screening', null, null, self::class, null, $fromStatus, 'draft');
                        $this->line("G{$generation->generation} A{$freshAgent->id}: architecture escape contract repaired; returned to draft.");
                        $agent = $freshAgent;
                    }
                }
                if ($this->option('apply') && $this->option('repair-stranded-screening-agents')) {
                    $repair = $this->repairStrandedScreeningAgent($agent, $queueInspector);
                    if ($repair !== null) {
                        $strandedRepairs++;
                        $fromStatus = (string) $agent->lifecycle_status;
                        $agent->refresh();
                        $agent->update([
                            'lifecycle_status' => 'draft',
                            'decision_reason' => 'Stranded screening admission repaired; no immutable screen run or live queue job existed.',
                        ]);
                        $freshAgent = $agent->fresh(['modelVersion']);
                        $evidence->recordLifecycle($freshAgent, 'stranded_screening_admission_repair', [
                            'reason_code' => 'STRANDED_SCREENING_AGENT_NO_RUN_OR_QUEUE_JOB',
                            ...$repair,
                            'promotion_evidence' => false,
                        ], 'screening', null, null, self::class, null, $fromStatus, 'draft');
                        $this->line("G{$generation->generation} A{$freshAgent->id}: stranded screening admission repaired; returned to draft.");
                        $agent = $freshAgent;
                    }
                }
                if ($agent->lifecycle_status === 'technical_quarantine'
                    && data_get($agent->modelVersion?->metadata, 'preflight_quarantine.protocol') === LabAgentPreflightService::PROTOCOL) {
                    continue;
                }
                $inspection = $preflight->inspect($agent, 'screening');
                if ($inspection['passed']) continue;
                $invalid++;
                $generationInvalid++;
                $this->line("G{$generation->generation} A{$agent->id}: ".implode(', ', $inspection['errors']));
                if ($this->option('apply')) {
                    $preflight->quarantine($agent, $inspection, 'database_integrity_repair');
                }
            }

            // A generation created before the balanced five-by-four
            // constructor fix can pass individual preflight while still
            // being an invalid cohort (for example 16 persisted slots with
            // a declared 20-seat contract).  It is diagnostic history, not a
            // strategy verdict.  Quarantine is explicit and opt-in so a
            // normal integrity audit cannot unexpectedly stop live work.
            $contractDrift = $this->contractDrift($generation);
            if ($this->option('apply')
                && $this->option('quarantine-contract-drift')
                && $contractDrift['issues'] !== []
                && in_array((string) $generation->status, [
                    ...LabPopulationService::ACTIVE_GENERATION_STATUSES,
                    'screened',
                    // A timed-out constructor can be quarantined while its
                    // orphaned PHP process is still unwinding. Keep this
                    // repair idempotent so agents appended during that race
                    // are quarantined on the next audit as well.
                    'technical_quarantine',
                ], true)) {
                $this->warn("G{$generation->generation}: immutable contract drift detected; queued agents will be consumed as technical quarantine.");
                foreach ($generation->fresh(['agents.modelVersion'])->agents as $agent) {
                    if (! in_array((string) $agent->lifecycle_status, [
                        'draft', 'queued', 'screening', 'screened', 'full_queued', 'training', 'evaluation_error',
                    ], true)) {
                        continue;
                    }
                    $preflight->quarantine($agent, [
                        'protocol' => LabAgentPreflightService::PROTOCOL,
                        'passed' => false,
                        'errors' => ['POPULATION_CONTRACT_DRIFT'],
                        'stage' => 'screening',
                        'agent_id' => $agent->id,
                        'generation_id' => $generation->id,
                        'promotion_evidence' => false,
                    ], 'population_contract_drift');
                    $contractQuarantined++;
                }
                $fresh = $generation->fresh(['agents']);
                $context = (array) ($fresh->trigger_context ?? []);
                $context['integrity_repair']['contract_drift'] = [
                    'protocol' => 'population_contract_quarantine_v1',
                    'issues' => $contractDrift['issues'],
                    'metrics' => $contractDrift['metrics'],
                    'applied_at' => now()->utc()->toIso8601String(),
                    'evidence_preserved' => true,
                    'promotion_evidence' => false,
                ];
                // Older constructors overwrote `population_size` with the
                // number actually created, which hid a 20->19 partial build.
                // Prefer the immutable contract/audit plan when deciding
                // whether the cohort was incomplete.
                $plannedPopulation = max(
                    (int) $fresh->population_size,
                    (int) data_get($fresh->trigger_context, 'population_group_contract.planned_population', 0),
                    (int) data_get($fresh->trigger_context, 'constructor_audit.planned_slots', 0),
                );
                $actualPopulation = (int) $fresh->agents->count();
                if ($plannedPopulation > 0 && $actualPopulation < $plannedPopulation) {
                    // A command timeout can interrupt the constructor before
                    // it writes its own audit. Mark the immutable partial
                    // cohort as constructor-aborted so no lane can treat it
                    // as a smaller valid population.
                    $abortKey = (string) $fresh->trigger_type === 'shadow_research'
                        ? 'shadow_research_constructor_abort'
                        : ((string) $fresh->trigger_type === 'controlled_rescue'
                            ? 'controlled_rescue_constructor_abort'
                            : 'constructor_contract_abort');
                    $context[$abortKey] = [
                        'protocol' => (string) $fresh->trigger_type === 'shadow_research'
                            ? 'shadow_research_constructor_v1'
                            : ((string) $fresh->trigger_type === 'controlled_rescue'
                                ? LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL
                                : 'agent_constructor_invariant_v1'),
                        'reason_code' => (string) $fresh->trigger_type === 'shadow_research'
                            ? 'INCOMPLETE_SHADOW_RESEARCH_POPULATION'
                            : ((string) $fresh->trigger_type === 'controlled_rescue'
                                ? 'INCOMPLETE_CONTROLLED_RESCUE_POPULATION'
                                : 'INCOMPLETE_GENERATION_POPULATION'),
                        'planned_slots' => $plannedPopulation,
                        'created_agents' => $actualPopulation,
                        'aborted_at' => now()->utc()->toIso8601String(),
                        'evidence_preserved' => true,
                        'promotion_evidence' => false,
                    ];
                }
                $fresh->update([
                    'trigger_context' => $context,
                    'status' => 'technical_quarantine',
                    'completed_at' => now(),
                ]);
            }

            if ($this->option('apply') && $generationInvalid > 0) {
                $fresh = $generation->fresh(['agents']);
                $open = $fresh->agents->contains(fn ($agent): bool => in_array(
                    $agent->lifecycle_status,
                    ['draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation'],
                    true,
                ));
                $context = (array) ($fresh->trigger_context ?? []);
                $context['integrity_repair'] = [
                    'protocol' => LabAgentPreflightService::PROTOCOL,
                    'invalid_agent_count' => $generationInvalid,
                    'applied_at' => now()->utc()->toIso8601String(),
                    'promotion_evidence' => false,
                ];
                $fresh->update([
                    'trigger_context' => $context,
                    ...(! $open ? ['status' => 'technical_quarantine', 'completed_at' => now()] : []),
                ]);
            }
        }

        $missingScreenEvidence = 0;
        $missingScreenRequeued = 0;
        $missingScreenSkipped = 0;
        if ($this->option('repair-missing-screen-evidence')) {
            foreach ($generations as $generation) {
                $result = $this->repairMissingScreenEvidence(
                    $generation->fresh(['agents.modelVersion']),
                    $preflight,
                    $evidence,
                    $replayRecovery,
                    app(LabQueueJobInspector::class),
                );
                $missingScreenEvidence += $result['missing'];
                $missingScreenRequeued += $result['requeued'];
                $missingScreenSkipped += $result['skipped'];
            }
        }

        $terminalBackfilled = 0;
        $invalidExecutionEvidence = 0;
        if ($this->option('apply')) {
            $terminalCandidates = $lab->generations()
                ->where('status', 'screened')
                ->whereNull('completed_at')
                ->with('agents')
                ->get();
            foreach ($terminalCandidates as $screenedGeneration) {
                $open = $screenedGeneration->agents->contains(fn ($agent): bool => in_array(
                    $agent->lifecycle_status,
                    ['draft', 'queued', 'training', 'screening', 'evaluation_error', 'full_queued', 'full_validation'],
                    true,
                ));
                if ($open) continue;
                $context = (array) ($screenedGeneration->trigger_context ?? []);
                $context['screening_terminal'] = [
                    'protocol' => 'generation_terminal_boundary_v1',
                    'status' => 'screened',
                    'backfilled_at' => now()->utc()->toIso8601String(),
                    'all_agents_terminal' => true,
                    'promotion_evidence' => false,
                ];
                $screenedGeneration->update(['completed_at' => now(), 'trigger_context' => $context]);
                $terminalBackfilled++;
            }

            // Never invent an execution hash for historical evidence. Rows
            // that reached full replay without the canonical contract remain
            // immutable audit history, but are explicitly excluded from all
            // promotion paths.
            $invalidExecutionRows = LabTrialLedger::query()
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->whereIn('stage', ['full', 'full_replay', 'full_validation', 'paper', 'holdout'])
                ->whereNull('execution_hash')
                ->get();
            foreach ($invalidExecutionRows as $ledger) {
                $metrics = (array) ($ledger->metrics ?? []);
                $metrics['execution_contract_valid'] = false;
                $metrics['promotion_evidence'] = false;
                $metrics['integrity_repair'] = [
                    'protocol' => 'historical_execution_contract_quarantine_v1',
                    'reason_code' => 'EXECUTION_CONTRACT_HASH_MISSING',
                    'repaired_at' => now()->utc()->toIso8601String(),
                    'evidence_preserved' => true,
                    'promotion_evidence' => false,
                ];
                $ledger->update([
                    'status' => 'invalid_execution_contract',
                    'metrics' => $metrics,
                ]);
                $invalidExecutionEvidence++;
            }
        }

        $mode = $this->option('apply') ? 'APPLIED' : 'DRY_RUN';
        $this->info("{$symbol} {$timeframe}: {$invalid} invalid lineage/preflight record(s), {$architectureRepairs} architecture escape contract repair(s), {$strandedRepairs} stranded screening admission repair(s), {$contractQuarantined} contract-drift agent(s) quarantined, {$normalContractQuarantined} invalid-normal-contract agent(s) quarantined, {$terminalBackfilled} screened terminal boundary backfill(s), {$invalidExecutionEvidence} invalid execution-evidence row(s), {$missingScreenEvidence} missing-screen-evidence agent(s), {$missingScreenRequeued} requeued, {$missingScreenSkipped} skipped [{$mode}].");

        if ($this->option('rebuild-root')) {
            if ($protocolSafety->generationCreationPaused()) {
                $this->warn('Learning protocol paused: --rebuild-root blocked; integrity audit/repair above remained within its requested scope.');

                return $invalid > 0 ? self::FAILURE : self::SUCCESS;
            }
            if ((string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->warn("{$symbol} {$timeframe}: shadow lab; --rebuild-root blocked.");

                return $invalid > 0 ? self::FAILURE : self::SUCCESS;
            }
            if (! $this->option('apply')) {
                $this->warn('--rebuild-root ishlashi uchun --apply ham kerak.');
                return $invalid > 0 ? self::FAILURE : self::SUCCESS;
            }
            // The failure curriculum is already persisted in the generation;
            // do not run the unbounded historical refresh while holding a
            // repair command open. The normal scheduler refreshes it later.
            // Four bounded root specialists are enough to restart quality
            // research; the full twenty-slot screen remains available to the
            // normal scheduler after this clean cohort proves its contract.
            $rebuilt = $this->createBoundedRootCohort($lab, $schemas, $semanticGroups, $executionContracts, $controlRoots, $rootInheritance, $protocolSafety);
            if ($rebuilt) {
                $rootFailures = 0;
                foreach ($rebuilt->load('agents.modelVersion')->agents as $agent) {
                    $inspection = $preflight->inspect($agent, 'screening');
                    if ($inspection['passed']) continue;
                    $rootFailures++;
                    $preflight->quarantine($agent, $inspection, 'root_constructor_postflight');
                }
                $this->info("{$symbol}: clean generation G{$rebuilt->generation} yaratildi; {$rootFailures} root preflight failure(s); queue hali dispatch qilinmadi.");
            } else {
                $this->warn("{$symbol}: clean root rebuild hozircha safety/data gate sabab yaratilmadi.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Repair only the known constructor defect where a topology-only escape
     * inherited one stale scalar from the failed council attempt.  The
     * immutable scalar diff itself identifies the exact value to restore; a
     * multi-key or inconsistent row remains fail-closed and is not touched.
     */
    private function repairArchitectureEscapeContract(LabAgent $agent, StrategyParameterSchemaService $schemas): ?array
    {
        $model = $agent->modelVersion;
        if (! $model || $agent->origin !== 'g98_council') {
            return null;
        }

        $metadata = (array) $model->metadata;
        $hypothesisGene = (string) data_get($metadata, 'hypothesis_contract.changed_gene', '');
        $architectureChanged = (bool) data_get($metadata, 'mutation_constructor_invariant.architecture_changed', false);
        $architectureVariant = (string) data_get(
            $metadata,
            'mutation_constructor_invariant.architecture_variant',
            data_get($metadata, 'strategy_architecture', ''),
        );
        $strategyArchitecture = (string) data_get($metadata, 'strategy_architecture', '');
        if ($hypothesisGene !== '__architecture'
            || ! $architectureChanged
            || $architectureVariant === ''
            || $strategyArchitecture === ''
            || $architectureVariant !== $strategyArchitecture) {
            return null;
        }

        $diff = (array) $agent->parameter_diff;
        if (count($diff) !== 1) {
            return null;
        }
        $changedKey = (string) array_key_first($diff);
        $change = (array) ($diff[$changedKey] ?? []);
        $parameters = (array) $model->parameters;
        if ($changedKey === ''
            || ! array_key_exists($changedKey, $parameters)
            || ! array_key_exists('old', $change)
            || ! array_key_exists('new', $change)
            || json_encode($parameters[$changedKey], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
                !== json_encode($change['new'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)) {
            return null;
        }

        $parameters[$changedKey] = $change['old'];
        try {
            $parameters = $schemas->normalizeForGeneration($agent->strategy_family, $parameters);
            $parameters = $schemas->validate($agent->strategy_family, $parameters);
        } catch (\Throwable) {
            return null;
        }
        if (json_encode($parameters[$changedKey] ?? null, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
            !== json_encode($change['old'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)) {
            return null;
        }

        $canonical = $schemas->canonicalizeForIdentity($agent->strategy_family, $parameters);
        $metadata['parameter_fingerprint'] = hash(
            'sha256',
            $agent->strategy_family.'|'.json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION),
        );
        data_set(
            $metadata,
            'universal_genome.local_adapter.parameters_hash',
            hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION)),
        );
        $metadata['mutation_constructor_invariant'] = [
            ...(array) data_get($metadata, 'mutation_constructor_invariant', []),
            'changed_parameter_keys' => [],
            'parameter_diff_count' => 0,
            'architecture_changed' => true,
            'architecture_variant' => $strategyArchitecture,
            'promotion_evidence' => false,
        ];
        $metadata['specialist_council_membership'] = [
            ...(array) data_get($metadata, 'specialist_council_membership', []),
            'parameter_specialties' => [],
            'promotion_evidence' => false,
        ];
        $alignment = [
            'status' => 'passed',
            'target' => data_get($metadata, 'hypothesis_contract.target_lane'),
            'changed_gene' => '__architecture',
            'gene_allowed' => true,
            'reason' => 'declared_structural_architecture_hypothesis',
        ];
        $metadata['tactic_alignment'] = $alignment;
        $metadata['hypothesis_contract'] = [
            ...(array) data_get($metadata, 'hypothesis_contract', []),
            'changed_gene' => '__architecture',
            'architecture_changed' => true,
            'architecture_variant' => $strategyArchitecture,
            'tactic_alignment' => $alignment,
            'promotion_evidence' => false,
        ];
        $metadata['integrity_repair'] = [
            ...(array) data_get($metadata, 'integrity_repair', []),
            'protocol' => 'architecture_escape_contract_repair_v1',
            'reason_code' => 'ARCHITECTURE_ESCAPE_SCALAR_LEAK_REMOVED',
            'removed_gene' => $changedKey,
            'restored_value' => $change['old'],
            'repaired_at' => now()->utc()->toIso8601String(),
            'evidence_preserved' => true,
            'promotion_evidence' => false,
        ];
        $model->update(['parameters' => $parameters, 'metadata' => $metadata]);

        return [
            'removed_scalar_gene' => $changedKey,
            'restored_value' => $change['old'],
            'architecture' => $strategyArchitecture,
            'parameter_vector_restored_to_topology_base' => true,
        ];
    }

    /**
     * A cancelled/expired continuation batch may leave its agent projection
     * in `queued` even though no immutable screen run was ever created. Only
     * that empty-evidence state is recoverable; any existing run remains the
     * source of truth and is never silently retried here.
     */
    private function repairStrandedScreeningAgent(LabAgent $agent, LabQueueJobInspector $queue): ?array
    {
        if (! in_array((string) $agent->lifecycle_status, ['queued', 'screening'], true)) {
            return null;
        }

        $hasScreenRun = DB::table('lab_evaluation_runs')
            ->where('lab_agent_id', $agent->id)
            ->where('phase', 'screening')
            ->exists();
        if ($hasScreenRun || $queue->hasAgentJob((int) $agent->id, $queue->labQueues())) {
            return null;
        }

        return [
            'agent_id' => (int) $agent->id,
            'previous_status' => (string) $agent->lifecycle_status,
            'screening_run_count' => 0,
            'live_queue_job' => false,
            'same_generation_recovery_only' => true,
        ];
    }

    /**
     * A worker can persist the screened projection before a stale reservation
     * recovery closes the immutable run.  That projection is not a quality
     * verdict: without a completed run it must be returned to the same
     * generation's queue and evaluated again against the frozen snapshots.
     * Historical runs remain untouched and no promotion evidence is created.
     *
     * @return array{missing:int,requeued:int,skipped:int}
     */
    private function repairMissingScreenEvidence(
        LabGeneration $generation,
        LabAgentPreflightService $preflight,
        LabImmutableEvidenceService $evidence,
        LabReplayRecoveryService $replayRecovery,
        LabQueueJobInspector $queueJobs,
    ): array {
        $completedAgentIds = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'screening')
            ->where('status', 'completed')
            ->whereIn('lab_agent_id', $generation->agents->pluck('id'))
            ->pluck('lab_agent_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $missing = $generation->agents
            ->where('lifecycle_status', 'screened')
            ->reject(fn ($agent): bool => in_array((int) $agent->id, $completedAgentIds, true));
        if ($missing->isEmpty()) {
            return ['missing' => 0, 'requeued' => 0, 'skipped' => 0];
        }

        $queues = array_values(array_unique(array_merge(
            [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
            [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
            (array) config('services.lab_queue.legacy_screening_queues', []),
        )));
        $requeued = 0;
        $skipped = 0;
        foreach ($missing as $agent) {
            $hasOpenRun = LabEvaluationRun::query()
                ->where('lab_agent_id', $agent->id)
                ->where('phase', 'screening')
                ->where('status', 'started')
                ->exists();
            $hasQueuedJob = $queueJobs->hasAgentJob((int) $agent->id, $queues);

            $this->line("G{$generation->generation} A{$agent->id}: screened projection has no completed immutable screen run.");
            if ($hasOpenRun || $hasQueuedJob) {
                $skipped++;
                $this->line("G{$generation->generation} A{$agent->id}: recovery skipped; open run or queue job already exists.");
                continue;
            }

            $inspection = $preflight->inspect($agent, 'screening');
            if (! $inspection['passed']) {
                $skipped++;
                $this->warn("G{$generation->generation} A{$agent->id}: recovery skipped; preflight failed.");
                continue;
            }

            if (! $this->option('apply')) {
                $requeued++;
                continue;
            }

            try {
                $recoveryContract = $replayRecovery->prepare($agent, 'screen');
                $fromStatus = (string) $agent->lifecycle_status;
                $oldProjection = [
                    'sample_count' => $agent->sample_count,
                    'profit_factor' => $agent->profit_factor,
                    'max_drawdown' => $agent->max_drawdown,
                    'train_score' => $agent->train_score,
                    'validation_score' => $agent->validation_score,
                    'forward_score' => $agent->forward_score,
                ];
                DB::transaction(function () use ($agent, $evidence, $recoveryContract, $fromStatus, $oldProjection): void {
                    $agent->update([
                        'lifecycle_status' => 'queued',
                        'sample_count' => 0,
                        'profit_factor' => null,
                        'max_drawdown' => null,
                        'risk_of_ruin' => null,
                        'train_score' => null,
                        'validation_score' => null,
                        'forward_score' => null,
                        'champion_improvement' => null,
                        'rolling_wins' => 0,
                        'decision_reason' => 'Screen evidence was incomplete; same-generation replay requeued against the frozen dataset. Quality verdict withheld.',
                    ]);
                    $fresh = $agent->fresh();
                    $evidence->recordLifecycle($fresh, 'screen_evidence_repair_queued', [
                        'reason_code' => 'SCREENED_WITHOUT_COMPLETED_SCREEN_RUN',
                        'old_projection' => $oldProjection,
                        'recovery_protocol' => LabReplayRecoveryService::PROTOCOL,
                        'promotion_evidence' => false,
                    ], 'screening', null, null, self::class, null, $fromStatus, 'queued');
                    Bus::dispatch(new EvaluateLabAgentJob($fresh->id, $fresh->symbol, 'screen', $recoveryContract));
                });
                $requeued++;
            } catch (\Throwable $exception) {
                $skipped++;
                $this->warn("G{$generation->generation} A{$agent->id}: recovery refused — ".substr($exception->getMessage(), 0, 240));
            }
        }

        return ['missing' => $missing->count(), 'requeued' => $requeued, 'skipped' => $skipped];
    }

    /** @return array{issues: array<int, string>, metrics: array<string, mixed>} */
    private function contractDrift(LabGeneration $generation): array
    {
        $context = (array) ($generation->trigger_context ?? []);
        $contract = (array) data_get($context, 'population_group_contract', []);
        $agents = $generation->agents()->with('modelVersion')->get();
        $actual = $agents->count();
        $expected = (int) $generation->population_size;
        $contractExpected = (int) data_get($contract, 'planned_population', 0);
        $groupCounts = $agents->groupBy(function ($agent): string {
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);

            return (string) (data_get($metadata, 'population_group.key')
                ?: data_get($metadata, 'specialist_council_membership.group_key')
                ?: data_get($metadata, 'generation_target')
                ?: 'unassigned');
        })->map->count()->all();
        $plannedGroups = [];
        foreach ((array) data_get($contract, 'groups', []) as $group => $definition) {
            $plannedGroups[(string) $group] = (int) data_get($definition, 'planned_seats', 0);
        }
        $groupMismatches = [];
        foreach ($plannedGroups as $group => $seats) {
            if ((int) ($groupCounts[$group] ?? 0) !== $seats) {
                $groupMismatches[$group] = [
                    'expected' => $seats,
                    'actual' => (int) ($groupCounts[$group] ?? 0),
                ];
            }
        }
        $skipped = (array) data_get($context, 'constructor_audit.skipped_zero_diff_slots', []);
        $issues = [];
        if ($contractExpected > 0 && $contractExpected !== $actual) $issues[] = 'POPULATION_COUNT_MISMATCH';
        if ($actual !== $expected) $issues[] = 'GENERATION_POPULATION_SIZE_MISMATCH';
        if ((bool) data_get($contract, 'balanced_core', false) && $groupMismatches !== []) $issues[] = 'COUNCIL_GROUP_SEAT_MISMATCH';
        if ($skipped !== []) $issues[] = 'CONSTRUCTOR_SKIPPED_ZERO_DIFF_SLOTS';

        return [
            'issues' => array_values(array_unique($issues)),
            'metrics' => [
                'generation_id' => $generation->id,
                'planned_population' => $expected,
                'actual_population' => $actual,
                'contract_planned_population' => $contractExpected ?: null,
                'group_counts' => $groupCounts,
                'planned_groups' => $plannedGroups,
                'group_mismatches' => $groupMismatches,
                'skipped_zero_diff_slots' => $skipped,
                'promotion_evidence' => false,
            ],
        ];
    }

    /** @return array{issues: array<int, string>, metrics: array<string, mixed>} */
    private function invalidNormalContract(LabGeneration $generation): array
    {
        $context = (array) ($generation->trigger_context ?? []);
        $mode = (string) data_get(
            $context,
            'research_allocation_budget.mode',
            data_get($context, 'control_pairing_contract.mode', ''),
        );
        if ($mode !== 'normal_research') {
            return ['issues' => [], 'metrics' => []];
        }

        $pairing = (array) data_get($context, 'control_pairing_contract', []);
        $structural = (array) data_get($context, 'structural_research_contract', []);
        $issues = [];
        if ((string) data_get($pairing, 'protocol', '') !== 'frozen_control_pair_v1') {
            $issues[] = 'NORMAL_CONTROL_PAIR_PROTOCOL_MISSING';
        }
        if (! (bool) data_get($pairing, 'allowed', false)) {
            $issues[] = 'NORMAL_CONTROL_CANDIDATE_PAIR_INCOMPLETE';
        }
        if ((array) data_get($pairing, 'missing_execution_lanes', []) !== []) {
            $issues[] = 'NORMAL_CONTROL_LANE_MISSING';
        }
        if ((array) data_get($pairing, 'missing_candidate_pairs', []) !== []) {
            $issues[] = 'NORMAL_CANDIDATE_PAIR_MISSING';
        }
        $structuralExpected = (bool) data_get($context, 'normal_structural_research_expected', true);
        if ($structuralExpected && (string) data_get($structural, 'protocol', '') !== 'normal_structural_research_v1') {
            $issues[] = 'NORMAL_STRUCTURAL_CONTRACT_MISSING';
        }
        if ($structuralExpected && (int) data_get($structural, 'structural_candidate_count', 0) < 1) {
            $issues[] = 'NORMAL_STRUCTURAL_CANDIDATE_MISSING';
        }

        return [
            'issues' => array_values(array_unique($issues)),
            'metrics' => [
                'generation_id' => $generation->id,
                'generation' => $generation->generation,
                'pairing_allowed' => (bool) data_get($pairing, 'allowed', false),
                'missing_candidate_pairs' => (array) data_get($pairing, 'missing_candidate_pairs', []),
                'structural_expected' => $structuralExpected,
                'structural_candidate_count' => (int) data_get($structural, 'structural_candidate_count', 0),
                'promotion_evidence' => false,
            ],
        ];
    }

    private function generationHasNoEvidenceOrQueueJobs(LabGeneration $generation, LabQueueJobInspector $queueInspector): bool
    {
        foreach ($generation->agents as $agent) {
            if (LabEvaluationRun::query()->where('lab_generation_id', $generation->id)->exists()) {
                return false;
            }
            if ($queueInspector->hasAgentJob((int) $agent->id, $queueInspector->labQueues())) {
                return false;
            }
        }

        return true;
    }

    private function createBoundedRootCohort(
        AiLaboratory $lab,
        StrategyParameterSchemaService $schemas,
        StrategySemanticGroupService $semanticGroups,
        ExecutionContractService $executionContracts,
        ControlRootCatalogueService $controlRoots,
        ControlRootInheritanceService $rootInheritance,
        LearningProtocolSafetyService $protocolSafety,
    ): ?LabGeneration {
        return DB::transaction(function () use ($lab, $schemas, $semanticGroups, $executionContracts, $controlRoots, $rootInheritance, $protocolSafety): ?LabGeneration {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($protocolSafety->generationCreationPaused()) {
                return null;
            }
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) return null;
            $latest = $lockedLab->generations()->latest('generation')->lockForUpdate()->first();
            $number = (int) ($latest?->generation ?? 0) + 1;
            $canonical = $semanticGroups->canonicalSpecialistGroups();
            $rootNiches = [
                [...$canonical['trend_up_specialist'], 'gene' => 'trend_up_ema_period', 'value' => 55],
                [...$canonical['trend_down_specialist'], 'gene' => 'trend_down_roc_period', 'value' => 14],
                [...$canonical['range_specialist'], 'gene' => 'range_deviation', 'value' => 2.2],
                [...$canonical['transition_risk_router'], 'gene' => 'transition_wait_candles', 'value' => 3],
            ];
            $execution = $executionContracts->for($lockedLab->symbol, $lockedLab->timeframe);
            $generation = $lockedLab->generations()->create([
                'generation' => $number,
                'trigger_type' => 'lineage_root_rebuild',
                'trigger_context' => [
                    'previous_generation' => $latest?->generation,
                    'protocol' => 'bounded_root_recovery_v1',
                    'semantic_parent_rule' => 'exact semantic parent only; otherwise explicit no-parent root seed',
                    'execution_contract' => $execution,
                    'population_size' => count($rootNiches),
                    'promotion_evidence' => false,
                ],
                'data_fingerprint' => $latest?->data_fingerprint,
                'population_size' => count($rootNiches),
                'status' => 'draft',
                'started_at' => now(),
            ]);

            foreach ($rootNiches as $slot => $niche) {
                $family = (string) $niche['family'];
                $base = $schemas->defaults($family);
                $parameters = $schemas->validate($family, [...$base, $niche['gene'] => $niche['value']]);
                $semanticGroup = $semanticGroups->descriptor(
                    $lockedLab->symbol,
                    $lockedLab->timeframe,
                    $family,
                    [
                        'role' => $niche['role'],
                        'regime' => $niche['regime'],
                        'volatility' => $niche['volatility'],
                        'direction' => null,
                    ],
                    'bounded_root_control_v1',
                );
                $strategy = strtolower($lockedLab->symbol).'_'.strtolower($family).'_g'.$number.'_root_'.str_pad((string) ($slot + 1), 2, '0', STR_PAD_LEFT);
                $metadata = [
                    'base_strategy' => $schemas->runtimeBaseStrategy($strategy, $family.'_v1', $family),
                    'strategy_architecture' => 'bounded_root_control_v1',
                    'lab_symbol' => $lockedLab->symbol,
                    'lab_timeframe' => $lockedLab->timeframe,
                    'origin' => 'lineage_root_rebuild',
                    'semantic_group' => $semanticGroup,
                    'control_root' => $controlRoots->for($family, 'bounded_root_control_v1'),
                    'generation_target' => $niche['target'],
                    'statistical_gate_version' => 3,
                    'robustness_gate_version' => 1,
                    'parent_inheritance_protocol' => [
                        'protocol' => 'exact_semantic_parent_or_group_root_v1',
                        'parent_selection' => 'no_parent_available',
                        'parent_status' => 'not_available',
                        'cross_cell_parent_forbidden' => true,
                        'legacy_parent_genetic_material' => false,
                        'promotion_evidence' => false,
                    ],
                    'semantic_lineage' => [
                        'protocol' => 'strict_semantic_lineage_v2',
                        'mode' => 'no_parent_available',
                        'parent_status' => 'not_available',
                        'child_group_key' => $semanticGroup['key'],
                        'genetic_parent_model_version_id' => null,
                        'root_model_version_id' => null,
                        'promotion_evidence' => false,
                    ],
                    'progressive_inheritance' => [
                        'protocol' => 'progressive_frontier_inheritance_v1',
                        'status' => 'first_generation_seed',
                        'parent_model_version_id' => null,
                        'root_model_version_id' => null,
                        'confirmed_beneficial_traits' => [],
                        'changed_parameter_keys' => [$niche['gene']],
                        'reset_reason' => 'no_parent_available',
                        'promotion_evidence' => false,
                    ],
                    'mutation_constructor_invariant' => [
                        'protocol' => 'agent_constructor_invariant_v1',
                        'status' => 'passed',
                        'single_gene_required' => true,
                        'changed_parameter_keys' => [$niche['gene']],
                        'parameter_diff_count' => 1,
                        'parent_model_version_id' => null,
                        'parent_rule' => 'No exact parent was available; group root/default seed used.',
                        'promotion_evidence' => false,
                    ],
                    'execution_contract' => $execution,
                    'control_root_seed' => $rootInheritance->seedDeclaration(
                        $lockedLab->symbol,
                        $lockedLab->timeframe,
                        $family,
                        $niche,
                        $semanticGroup,
                        $controlRoots->for($family, 'bounded_root_control_v1'),
                        'bounded_root_control_v1',
                        $parameters,
                    ),
                    'recovery_protocol' => [
                        'protocol' => 'bounded_root_recovery_v1',
                        'failure_source_diagnostic_only' => true,
                        'target_regime' => $niche['regime'],
                        'role' => $niche['role'],
                        'promotion_evidence' => false,
                    ],
                    'root_recovery_replay_contract' => [
                        'protocol' => 'bounded_root_recovery_v1',
                        'status' => 'screening_seed',
                        'standalone_full_replay_required' => true,
                        'forward_score_created_by_full_replay' => true,
                        'promotion_evidence' => false,
                    ],
                    'parameter_fingerprint' => hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
                ];
                $model = ModelVersion::create([
                    'name' => $strategy,
                    'strategy' => $strategy,
                    'version' => 'v'.$number.'-root-'.($slot + 1),
                    'generation' => $number,
                    'status' => 'testing',
                    'parameters' => $parameters,
                    'description' => 'Clean semantic group-root recovery control after legacy lineage quarantine.',
                    'metadata' => $metadata,
                ]);
                $agent = $generation->agents()->create([
                    'model_version_id' => $model->id,
                    'parent_a_model_version_id' => null,
                    'parent_b_model_version_id' => null,
                    'symbol' => $lockedLab->symbol,
                    'timeframe' => $lockedLab->timeframe,
                    'strategy_family' => $family,
                    'origin' => 'lineage_root_rebuild',
                    'lifecycle_status' => 'draft',
                    'parameter_diff' => [$niche['gene'] => ['old' => $base[$niche['gene']], 'new' => $niche['value']]],
                    'decision_reason' => 'Clean root recovery; exact parent absent, all elite/forward/paper gates remain required.',
                ]);
                $agent->setRelation('modelVersion', $model);
                $rootInheritance->finalizeSeed($model, $agent);
            }
            return $generation->fresh(['agents.modelVersion']);
        });
    }
}
