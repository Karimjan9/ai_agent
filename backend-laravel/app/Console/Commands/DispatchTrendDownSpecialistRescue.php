<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AdaptiveParentFrontierService;
use App\Services\AgentConstitutionService;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use App\Services\UniversalAgentCapabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** A bounded architecture rescue for a demonstrated trend-down regime deficit. */
class DispatchTrendDownSpecialistRescue extends Command
{
    protected $signature = 'trading:dispatch-trend-down-specialist-rescue {sourceAgent : Screened parent whose trend-down regime failed}';

    protected $description = 'Create one frozen trend-down specialist/router candidate for screening only; never bypasses Forward or Paper gates.';

    public function handle(
        StrategyParameterSchemaService $schemas,
        LabDatasetExportService $datasets,
        AgentConstitutionService $constitutions,
        UniversalAgentCapabilityService $universalCapabilities,
        StrategySemanticGroupService $semanticGroups,
        AdaptiveParentFrontierService $adaptiveParents,
    ): int {
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $sourceModel = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $sourceModel || ! $lab || $source->lifecycle_status !== 'screened') {
            $this->error('Source must be a completed screened laboratory agent.');

            return self::FAILURE;
        }
        if ((float) data_get($sourceModel->metadata, 'last_screen_result.screening_survival.worst_regime_pf', 99) >= 1.0) {
            $this->error('This rescue is allowed only for an evidenced regime failure (worst regime PF < 1).');

            return self::FAILURE;
        }
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi specialist rescue generation locklandi.");

            return self::SUCCESS;
        }

        // The failed source is diagnostic context, never a genetic parent.
        // Resolve a reusable exact-cell frontier first, then deliberately take
        // one parent because this command is a single-gene rescue lane.
        $specialistNiche = ['role' => 'trend_down_specialist', 'regime' => 'trend_down'];
        $candidateParents = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('strategy_family', 'regime_ensemble')
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->where('evidence_status', 'valid')
            ->latest('id')
            ->get()
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && $semanticGroups->exactParentCompatible(
                    $performance->modelVersion,
                    $lab->symbol,
                    $lab->timeframe,
                    'regime_ensemble',
                    $specialistNiche,
                ))
            ->pluck('modelVersion')
            ->unique('id')
            ->values();
        $parentSelection = $adaptiveParents->select(
            $candidateParents,
            $lab->symbol,
            $lab->timeframe,
            'regime_ensemble',
            'gate_targeted',
            'trend_down_regime_coverage',
            $specialistNiche,
            1,
        );
        $sameFamilyParent = $parentSelection['parents']->first();
        $generation = DB::transaction(function () use ($source, $sourceModel, $sameFamilyParent, $parentSelection, $specialistNiche, $lab, $schemas, $constitutions, $universalCapabilities, $semanticGroups) {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) {
                return null;
            }
            $number = (int) ($lockedLab->generations()->latest('generation')->lockForUpdate()->value('generation') ?? 0) + 1;
            $generation = $lockedLab->generations()->create([
                'generation' => $number, 'trigger_type' => 'targeted_trend_down_specialist_rescue',
                'trigger_context' => [
                    'source_agent_id' => $source->id, 'source_model_version_id' => $sourceModel->id,
                    'source_is_diagnostic_only' => true,
                    'parent_selection' => $parentSelection['contract'] ?? null,
                    'failure_target' => 'trend_down_regime_coverage', 'promotion_evidence' => false,
                    'router_policy' => 'frozen_regime_specialist_ensemble_v2',
                    'required_screen_contract' => [
                        'minimum_trades' => 10, 'minimum_profit_factor' => 1.30,
                        'minimum_stress_profit_factor' => 1.05, 'minimum_worst_regime_profit_factor' => 1.00,
                        'minimum_temporal_chunk_profit_factor' => 1.00, 'maximum_train_forward_gap' => 25.0,
                        'minimum_parameter_stability' => .80,
                    ],
                ],
                'data_fingerprint' => $source->generation->data_fingerprint,
                'population_size' => 1, 'status' => 'draft', 'started_at' => now(),
            ]);

            // Preserve the parent's execution controls, but make the topology
            // explicit: trend_down gets a separate signal filter and a bounded
            // local risk adapter.  Trend-up/range/breakout controls come from
            // the frozen ensemble defaults and cannot be post-hoc selected.
            $parameters = [
                ...$schemas->defaults('regime_ensemble'),
                ...array_intersect_key($sameFamilyParent?->parameters ?? [], $schemas->schema('regime_ensemble')),
                'trend_down_strength_min' => 28.0,
            ];
            $parameters = $schemas->validate('regime_ensemble', $parameters);
            $architecture = 'frozen_regime_specialist_ensemble_v2';
            $constitution = $constitutions->draft($lab->symbol, $lab->timeframe, 'regime_ensemble', $architecture, $parameters);
            $genome = $universalCapabilities->genome($lab->symbol, $lab->timeframe, 'regime_ensemble', $architecture, $parameters, $sameFamilyParent);
            $strategy = strtolower($lab->symbol).'_regime_ensemble_g'.$number.'_trend_down_rescue';
            $model = ModelVersion::create([
                'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$number.'-trend-down-rescue',
                'generation' => $number, 'status' => 'testing', 'parameters' => $parameters,
                'description' => "Trend-down specialist/router rescue from screened agent {$source->id}; screening evidence only.",
                'metadata' => [
                    'base_strategy' => 'regime_ensemble_v1', 'strategy_architecture' => $architecture,
                    'lab_symbol' => $lab->symbol, 'lab_timeframe' => $lab->timeframe, 'origin' => 'architecture',
                    'semantic_group' => $semanticGroups->descriptor($lab->symbol, $lab->timeframe, 'regime_ensemble', $specialistNiche, $architecture),
                    'source_model_version_id' => $sourceModel->id,
                    'source_is_diagnostic_only' => true,
                    'adaptive_parent_ecosystem' => $parentSelection['contract'] ?? null,
                    'capability_genome' => $parentSelection['capability_genome'] ?? null,
                    'generation_target' => 'trend_down_regime_coverage', 'statistical_gate_version' => 3,
                    'trend_down_specialist_contract' => [
                        'source_agent_id' => $source->id,
                        'policy' => 'trend_down uses its own strength/pullback/risk adapter; one router signal per candle.',
                        'promotion_evidence' => false,
                    ],
                    'parent_inheritance_protocol' => [
                        'protocol' => 'exact_semantic_parent_or_group_root_v1',
                        'parent_selection' => $sameFamilyParent ? 'exact_semantic_parent' : 'exact_group_root_default',
                        'parent_graph_protocol' => 'lab_agent_parent_graph_v1',
                        'cross_cell_parent_forbidden' => true,
                        'legacy_parent_genetic_material' => false,
                        'promotion_evidence' => false,
                    ],
                    'semantic_lineage' => [
                        'protocol' => 'strict_semantic_lineage_v2',
                        'mode' => $sameFamilyParent ? 'exact_semantic_parent' : 'semantic_group_root_default_seed',
                        'genetic_parent_model_version_id' => $sameFamilyParent?->id,
                        'diagnostic_source_model_version_id' => $sourceModel->id,
                        'promotion_evidence' => false,
                    ],
                    'mutation_constructor_invariant' => [
                        'protocol' => 'agent_constructor_invariant_v1',
                        'status' => 'passed',
                        'single_gene_required' => true,
                        'changed_parameter_keys' => ['trend_down_strength_min'],
                        'parameter_diff_count' => 1,
                        'parent_model_version_id' => $sameFamilyParent?->id,
                        'promotion_evidence' => false,
                    ],
                    'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                    'agent_constitution' => $constitution, 'universal_genome' => $genome,
                    'parent_contribution_graph' => [
                        'protocol' => 'lab_agent_parent_graph_v1',
                        'all_parent_model_version_ids' => (array) ($parentSelection['selected_parent_ids'] ?? []),
                        'primary_parent_a_model_version_id' => $sameFamilyParent?->id,
                        'adaptive_selected_parent_model_version_ids' => (array) ($parentSelection['selected_parent_ids'] ?? []),
                        'promotion_evidence' => false,
                    ],
                ],
            ]);
            $agent = $generation->agents()->create([
                'model_version_id' => $model->id, 'parent_a_model_version_id' => $sameFamilyParent?->id,
                'parent_b_model_version_id' => null,
                'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe,
                'strategy_family' => 'regime_ensemble', 'origin' => 'architecture', 'lifecycle_status' => 'queued',
                'parameter_diff' => ['trend_down_strength_min' => [
                    'old' => data_get($sameFamilyParent?->parameters, 'trend_down_strength_min', data_get($schemas->defaults('regime_ensemble'), 'trend_down_strength_min')),
                    'new' => 28.0,
                ]],
                'decision_reason' => 'Targeted trend-down specialist/router rescue; strict screen gate required and no promotion was dispatched.',
            ]);
            if ($sameFamilyParent?->id) {
                $agent->parentLinks()->create([
                    'parent_model_version_id' => $sameFamilyParent->id,
                    'relation_type' => 'causal_parent',
                    'contribution_key' => 'parent_a',
                    'metadata' => [
                        'source' => 'validated_exact_cell_frontier',
                        'selection_protocol' => data_get($parentSelection, 'contract.protocol'),
                        'promotion_evidence' => false,
                    ],
                ]);
            }

            return $generation->load('agents');
        });
        if (! $generation) {
            $this->warn("{$lab->symbol}: active generation lock prevented specialist rescue creation.");

            return self::SUCCESS;
        }

        $datasets->export($lab->symbol, $lab->timeframe);
        $generation->update(['status' => 'screening']);
        $agent = $generation->agents->firstOrFail();
        $batch = Bus::batch([new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen')])
            ->name("{$lab->symbol} trend-down specialist rescue G{$generation->generation}")
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))->onQueue('lab-'.strtolower($lab->symbol))->dispatch();
        $this->info("{$lab->symbol} G{$generation->generation}: {$batch->id}; trend-down specialist/router queued for screening only. No full-validation or paper job was dispatched.");

        return self::SUCCESS;
    }
}
