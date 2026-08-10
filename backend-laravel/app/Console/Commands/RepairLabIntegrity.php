<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabTrialLedger;
use App\Models\ModelVersion;
use App\Services\LabAgentPreflightService;
use App\Services\ControlRootCatalogueService;
use App\Services\ControlRootInheritanceService;
use App\Services\ExecutionContractService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Repair legacy lab records without deleting their immutable history. */
class RepairLabIntegrity extends Command
{
    protected $signature = 'trading:repair-lab-integrity
        {symbol? : Market symbol, for example XAUUSD}
        {--timeframe=H1}
        {--generation=* : Generation number(s) to audit; defaults to active generations}
        {--apply : Persist quarantine and lifecycle repairs}
        {--rebuild-root : After applying, create one clean next generation from exact parents or group roots}';

    protected $description = 'Quarantine legacy lineage/evidence and optionally rebuild a clean semantic lab generation.';

    public function handle(
        LabAgentPreflightService $preflight,
        StrategyParameterSchemaService $schemas,
        StrategySemanticGroupService $semanticGroups,
        ExecutionContractService $executionContracts,
        ControlRootCatalogueService $controlRoots,
        ControlRootInheritanceService $rootInheritance,
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
        foreach ($generations as $generation) {
            $generationInvalid = 0;
            foreach ($generation->agents as $agent) {
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
        $this->info("{$symbol} {$timeframe}: {$invalid} invalid lineage/preflight record(s), {$terminalBackfilled} screened terminal boundary backfill(s), {$invalidExecutionEvidence} invalid execution-evidence row(s) [{$mode}].");

        if ($this->option('rebuild-root')) {
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
            $rebuilt = $this->createBoundedRootCohort($lab, $schemas, $semanticGroups, $executionContracts, $controlRoots, $rootInheritance);
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

    private function createBoundedRootCohort(
        AiLaboratory $lab,
        StrategyParameterSchemaService $schemas,
        StrategySemanticGroupService $semanticGroups,
        ExecutionContractService $executionContracts,
        ControlRootCatalogueService $controlRoots,
        ControlRootInheritanceService $rootInheritance,
    ): ?LabGeneration {
        return DB::transaction(function () use ($lab, $schemas, $semanticGroups, $executionContracts, $controlRoots, $rootInheritance): ?LabGeneration {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
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
