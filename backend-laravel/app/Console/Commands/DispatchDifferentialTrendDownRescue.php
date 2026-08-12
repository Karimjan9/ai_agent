<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Creates a frozen parent control plus one-variable worst-regime router experiments. */
class DispatchDifferentialTrendDownRescue extends Command
{
    private const EXECUTION_CONTRACT = 'differential_paired_lane_v3_context_scoped_cooldown_v1';

    protected $signature = 'trading:dispatch-differential-trend-down-rescue {sourceAgent : Screened hybrid parent id} {--resume : Dispatch already-created queued differential experiments only}';

    protected $description = 'Queue a frozen parent control and isolated differential worst-regime variants for screening only.';

    public function handle(
        StrategyParameterSchemaService $schemas,
        LabDatasetExportService $datasets,
        StrategySemanticGroupService $semanticGroups,
        LearningProtocolSafetyService $protocolSafety,
    ): int {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: differential trend-down rescue deferred.');

            return self::SUCCESS;
        }
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $parent = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $parent || ! $lab || $source->lifecycle_status !== 'screened' || data_get($parent->metadata, 'base_strategy') !== 'hybrid_v1') {
            $this->error('Source must be a screened hybrid parent.');

            return self::FAILURE;
        }
        if ((string) $lab->lifecycle_mode !== 'lighthouse') {
            $this->info('Source laboratory shadow rejimida; differential rescue dispatch qilinmadi.');

            return self::SUCCESS;
        }
        $target = $this->worstRegimeTarget($source);
        if (! $target) {
            $this->error('Target regime uchun kamida 15 ta trade bilan regime PF evidence topilmadi.');

            return self::FAILURE;
        }
        $differentialNiche = [
            'role' => 'differential_router',
            'regime' => $target['regime'],
        ];
        $sameFamilyParent = LabAgent::query()
            ->with('modelVersion')
            ->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)
            ->where('strategy_family', 'differential_router')
            ->whereIn('lifecycle_status', ['screened', 'challenger', 'forward_validated', 'paper'])
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->latest('id')
            ->get()
            ->first(fn (LabAgent $candidate): bool => $candidate->modelVersion !== null
                && $semanticGroups->exactParentCompatible(
                    $candidate->modelVersion,
                    $lab->symbol,
                    $lab->timeframe,
                    'differential_router',
                    $differentialNiche,
                ));
        $sameFamilyParentModel = $sameFamilyParent?->modelVersion;
        if ($this->option('resume')) {
            $pending = LabAgent::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->whereIn('lifecycle_status', ['draft', 'queued', 'screening', 'evaluation_error'])
                ->whereHas('generation', fn ($query) => $query
                    ->where(function ($query) {
                        $query->where('trigger_type', 'differential_parent_control')
                            ->orWhere('trigger_type', 'like', 'differential_%_single_gene');
                    }))
                ->orderBy('id')->get();
            if ($pending->isEmpty()) {
                $this->info('No pending differential experiments to resume.');

                return self::SUCCESS;
            }
            $pending->each(function (LabAgent $agent): void {
                $agent->update(['lifecycle_status' => 'queued', 'decision_reason' => 'Transport/replay recovery: requeued after evaluator contention; strategy verdict withheld until a clean replay.']);
                $agent->generation()->update(['status' => 'screening', 'completed_at' => null]);
            });
            $batch = Bus::batch($pending->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen'))->all())
                ->name("{$lab->symbol} resumed differential trend-down cohort")
                ->allowFailures()
                ->onConnection((string) config('queue.default', 'redis'))->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))->dispatch();
            $this->info("{$lab->symbol}: {$batch->id}; {$pending->count()} corrected differential experiments resumed.");

            return self::SUCCESS;
        }
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi differential generation locklandi.");

            return self::SUCCESS;
        }

        [$control, $children] = DB::transaction(function () use (
            $source,
            $parent,
            $sameFamilyParentModel,
            $lab,
            $schemas,
            $semanticGroups,
            $target,
            $differentialNiche,
        ): array {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) {
                return [null, collect()];
            }
            // The hybrid source is failure context only.  Its parameters are
            // not genetic material for this differential family.  If a
            // compatible differential parent exists, inherit only that
            // same-group parameter set; otherwise start from the family root.
            $differentialBase = [...$schemas->defaults('differential_router'),
                ...array_intersect_key($sameFamilyParentModel?->parameters ?? [], $schemas->schema('differential_router')),
                'differential_target_regime' => $target['regime'], 'differential_replay_mode' => 'paired_isolated'];
            $differentialBase = $schemas->validate('differential_router', $differentialBase);
            $variants = $this->variantsFor($target['regime'], $differentialBase);
            $controlGeneration = $lockedLab->generations()->create([
                'generation' => (int) ($lockedLab->generations()->latest('generation')->value('generation') ?? 0) + 1,
                'trigger_type' => 'differential_parent_control',
                'trigger_context' => [
                    'source_agent_id' => $source->id,
                    'target_regime' => $target['regime'],
                    'cohort_protocol' => 'single_generation_parent_control_and_children_v1',
                    'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                    'promotion_evidence' => false,
                ],
                'data_fingerprint' => $source->generation->data_fingerprint,
                'population_size' => count($variants) + 1,
                'status' => 'screening',
                'started_at' => now(),
            ]);
            $controlArchitecture = 'frozen_parent_differential_'.$target['regime'].'_v2';
            $controlSemanticGroup = $semanticGroups->descriptor(
                $lab->symbol,
                $lab->timeframe,
                'differential_router',
                $differentialNiche,
                $controlArchitecture,
            );
            $controlModel = ModelVersion::create([
                'name' => strtolower($lab->symbol).'_differential_router_g'.$controlGeneration->generation.'_parent',
                'strategy' => strtolower($lab->symbol).'_differential_router_g'.$controlGeneration->generation.'_parent',
                'version' => 'v'.$controlGeneration->generation.'-differential-parent', 'generation' => $controlGeneration->generation,
                'status' => 'testing', 'parameters' => $differentialBase,
                'description' => "Frozen differential-group parent control for {$target['regime']} rescue; hybrid source {$source->id} is diagnostic only.",
                'metadata' => [
                    'base_strategy' => 'differential_router_v1',
                    'strategy_architecture' => $controlArchitecture,
                    'lab_symbol' => $lab->symbol,
                    'lab_timeframe' => $lab->timeframe,
                    'origin' => 'causal_isolation',
                    'semantic_group' => $controlSemanticGroup,
                    'source_model_version_id' => $parent->id,
                    'source_strategy_family' => $source->strategy_family,
                    'genetic_parent_model_version_id' => $sameFamilyParentModel?->id,
                    'foreign_source_not_inherited' => true,
                    'differential_execution_contract' => self::EXECUTION_CONTRACT,
                    'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                    'parameter_fingerprint' => $this->parameterFingerprint($differentialBase, self::EXECUTION_CONTRACT),
                    'differential_parent_control' => [
                        'source_agent_id' => $source->id,
                        'source_model_version_id' => $parent->id,
                        'target_regime' => $target['regime'],
                        'same_family_parent_model_version_id' => $sameFamilyParentModel?->id,
                        'promotion_evidence' => false,
                    ],
                    'parent_inheritance_protocol' => [
                        'protocol' => 'exact_semantic_parent_or_group_root_v1',
                        'parent_selection' => $sameFamilyParentModel ? 'exact_semantic_parent' : 'exact_group_root_default',
                        'cross_cell_parent_forbidden' => true,
                        'legacy_parent_genetic_material' => false,
                        'promotion_evidence' => false,
                    ],
                    'semantic_lineage' => [
                        'protocol' => 'strict_semantic_lineage_v2',
                        'mode' => $sameFamilyParentModel ? 'exact_semantic_parent' : 'semantic_group_root_default_seed',
                        'child_group_key' => $controlSemanticGroup['key'],
                        'genetic_parent_model_version_id' => $sameFamilyParentModel?->id,
                        'diagnostic_source_model_version_id' => $parent->id,
                        'promotion_evidence' => false,
                    ],
                    'mutation_constructor_invariant' => [
                        'protocol' => 'agent_constructor_invariant_v1',
                        'status' => 'passed',
                        'single_gene_required' => false,
                        'changed_parameter_keys' => [],
                        'parameter_diff_count' => 0,
                        'parent_model_version_id' => $sameFamilyParentModel?->id,
                        'control_only' => true,
                        'promotion_evidence' => false,
                    ],
                ],
            ]);
            $control = $controlGeneration->agents()->create([
                'model_version_id' => $controlModel->id, 'parent_a_model_version_id' => $sameFamilyParentModel?->id,
                'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => 'differential_router',
                'origin' => 'causal_isolation', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
                'decision_reason' => $sameFamilyParentModel
                    ? 'Frozen same-semantic-group differential parent replay; screening evidence only.'
                    : 'Differential semantic-group root control; no compatible parent existed, so hybrid source was kept diagnostic only.',
            ]);
            $knownFingerprints = LabAgent::query()->with('modelVersion')
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->where('strategy_family', 'differential_router')->get()
                ->map(fn (LabAgent $agent) => $this->parameterFingerprint(
                    (array) ($agent->modelVersion?->parameters ?? []),
                    (string) data_get($agent->modelVersion?->metadata, 'differential_execution_contract', 'legacy')
                ))
                ->filter()->unique()->flip()->all();
            $children = collect();
            foreach ($variants as $variant) {
                $parameters = $schemas->validate('differential_router', [...$differentialBase, ...$variant['changes']]);
                // A replay-policy fix is a new evidence context.  Reusing the
                // old parameter fingerprint here would silently treat results
                // produced by the previous risk-state machine as current.
                $fingerprint = $this->parameterFingerprint($parameters, self::EXECUTION_CONTRACT);
                if (isset($knownFingerprints[$fingerprint])) {
                    continue;
                }
                $knownFingerprints[$fingerprint] = true;
                $diff = [];
                foreach ($variant['changes'] as $key => $newValue) {
                    $diff[$key] = ['old' => $differentialBase[$key] ?? null, 'new' => $newValue];
                }
                $changedParameterKeys = array_keys($diff);
                $model = ModelVersion::create([
                    'name' => strtolower($lab->symbol).'_differential_router_g'.$controlGeneration->generation.'_'.str_replace('_', '-', $variant['key']),
                    'strategy' => strtolower($lab->symbol).'_differential_router_g'.$controlGeneration->generation.'_'.str_replace('_', '-', $variant['key']),
                    'version' => 'v'.$controlGeneration->generation.'-'.$variant['key'], 'generation' => $controlGeneration->generation,
                    'status' => 'testing', 'parameters' => $parameters,
                    'description' => "Single-variable differential {$target['regime']} {$variant['key']} rescue from {$source->id}.",
                    'metadata' => [
                        'base_strategy' => 'differential_router_v1', 'strategy_architecture' => $controlArchitecture,
                        'lab_symbol' => $lab->symbol, 'lab_timeframe' => $lab->timeframe,
                        'origin' => 'causal_isolation', 'generation_target' => $target['regime'].'_regime_coverage',
                        'semantic_group' => $controlSemanticGroup,
                        'differential_router_contract' => ['parent_model_version_id' => $controlModel->id, 'source_model_version_id' => $parent->id,
                            'target_regime' => $target['regime'], 'non_target_parent_freeze' => true,
                            'replay_mode' => 'paired_isolated', 'promotion_evidence' => false],
                        'differential_execution_contract' => self::EXECUTION_CONTRACT,
                        'execution_contract' => app(ExecutionContractService::class)->for($lab->symbol, $lab->timeframe),
                        'parameter_fingerprint' => $fingerprint,
                        'causal_experiment_lane' => ['status' => 'single_gene', 'parameter_key' => $variant['key'],
                            'target_regime' => $target['regime'], 'rule' => 'Parent signal/confidence are immutable outside the target regime; one target-lane variable changes.'],
                        'parent_inheritance_protocol' => [
                            'protocol' => 'exact_semantic_parent_or_group_root_v1',
                            'parent_selection' => 'exact_semantic_parent',
                            'cross_cell_parent_forbidden' => true,
                            'legacy_parent_genetic_material' => false,
                            'promotion_evidence' => false,
                        ],
                        'semantic_lineage' => [
                            'protocol' => 'strict_semantic_lineage_v2',
                            'mode' => 'exact_semantic_parent',
                            'child_group_key' => $controlSemanticGroup['key'],
                            'genetic_parent_model_version_id' => $controlModel->id,
                            'diagnostic_source_model_version_id' => $parent->id,
                            'promotion_evidence' => false,
                        ],
                        'mutation_constructor_invariant' => [
                            'protocol' => 'agent_constructor_invariant_v1',
                            'status' => 'passed',
                            'single_gene_required' => true,
                            'changed_parameter_keys' => $changedParameterKeys,
                            'parameter_diff_count' => count($diff),
                            'parent_model_version_id' => $controlModel->id,
                            'promotion_evidence' => false,
                        ],
                    ],
                ]);
                $child = $controlGeneration->agents()->create([
                    'model_version_id' => $model->id, 'parent_a_model_version_id' => $controlModel->id,
                    'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => 'differential_router',
                    'origin' => 'causal_isolation', 'lifecycle_status' => 'queued',
                    'parameter_diff' => $diff,
                    'decision_reason' => 'Differential '.$target['regime'].' single-gene rescue; paired non-target ledger contract required.',
                ]);
                $children->push($child);
            }

            return [$control, $children];
        });
        if (! $control) {
            $this->warn("{$lab->symbol}: active generation lock prevented differential generation creation.");

            return self::SUCCESS;
        }

        $datasets->export($lab->symbol, $lab->timeframe);
        $agents = collect([$control])->merge($children)->values();
        $batch = Bus::batch($agents->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen'))->all())
            ->name("{$lab->symbol} differential {$target['regime']} parent + single-gene cohort")
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))->dispatch();
        $this->info("{$lab->symbol}: {$batch->id}; target {$target['regime']} (PF {$target['profit_factor']}, {$target['trades']} trades), frozen parent and {$children->count()} variants queued. No full-validation/paper jobs were dispatched.");

        return self::SUCCESS;
    }

    private function worstRegimeTarget(LabAgent $source): ?array
    {
        $result = (array) data_get($source->modelVersion?->metadata, 'last_screen_result', []);
        $rows = (array) data_get($result, 'pf_attribution.breakdown.by_regime', []);

        return collect($rows)->map(fn ($metrics, $regime) => [
            'regime' => (string) $regime, 'trades' => (int) data_get($metrics, 'trades', 0),
            'profit_factor' => (float) data_get($metrics, 'net_pf', 0),
        ])->filter(fn ($row) => in_array($row['regime'], ['trend_up', 'range', 'trend_down'], true) && $row['trades'] >= 15)
            ->sortBy('profit_factor')->first();
    }

    private function variantsFor(string $target, array $base): array
    {
        if ($target === 'range') {
            return [
                ['key' => 'range_deviation_low', 'changes' => ['range_deviation' => 1.6]],
                ['key' => 'range_deviation_high', 'changes' => ['range_deviation' => 2.4]],
                ['key' => 'range_inverse_extreme', 'changes' => ['range_signal_mode' => 'inverse_extreme']],
                ['key' => 'range_reentry_off', 'changes' => ['range_reentry_required' => false]],
            ];
        }
        $prefix = $target === 'trend_up' ? 'trend_up' : 'trend_down';

        return [
            ['key' => $prefix.'_strength_low', 'changes' => [$prefix.'_strength_min' => 18.0]],
            ['key' => $prefix.'_strength_high', 'changes' => [$prefix.'_strength_min' => 26.0]],
            ['key' => $prefix.'_pullback_tight', 'changes' => [$prefix.'_pullback_atr_fraction' => .60]],
            ['key' => $prefix.'_pullback_wide', 'changes' => [$prefix.'_pullback_atr_fraction' => 1.0]],
        ];
    }

    private function parameterFingerprint(array $parameters, string $executionContract = 'legacy'): string
    {
        ksort($parameters);

        return hash('sha256', json_encode([
            'execution_contract' => $executionContract,
            'parameters' => $parameters,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }
}
