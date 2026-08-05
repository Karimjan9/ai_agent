<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Creates a frozen parent control plus one-variable worst-regime router experiments. */
class DispatchDifferentialTrendDownRescue extends Command
{
    private const EXECUTION_CONTRACT = 'differential_paired_lane_v3_context_scoped_cooldown_v1';

    protected $signature = 'trading:dispatch-differential-trend-down-rescue {sourceAgent : Screened hybrid parent id} {--resume : Dispatch already-created queued differential experiments only}';
    protected $description = 'Queue a frozen parent control and isolated differential worst-regime variants for screening only.';

    public function handle(StrategyParameterSchemaService $schemas, LabDatasetExportService $datasets): int
    {
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        $parent = $source->modelVersion;
        $lab = $source->generation?->laboratory;
        if (! $parent || ! $lab || $source->lifecycle_status !== 'screened' || data_get($parent->metadata, 'base_strategy') !== 'hybrid_v1') {
            $this->error('Source must be a screened hybrid parent.');
            return self::FAILURE;
        }
        $target = $this->worstRegimeTarget($source);
        if (! $target) {
            $this->error('Target regime uchun kamida 15 ta trade bilan regime PF evidence topilmadi.');
            return self::FAILURE;
        }
        if ($this->option('resume')) {
            $pending = LabAgent::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
                ->whereIn('lifecycle_status', ['draft', 'queued', 'screening', 'evaluation_error'])
                ->whereHas('generation', fn ($query) => $query
                    ->where(function ($query) {
                        $query->where('trigger_type', 'differential_parent_control')
                            ->orWhere('trigger_type', 'like', 'differential_%_single_gene');
                    }))
                ->orderBy('id')->get();
            if ($pending->isEmpty()) { $this->info('No pending differential experiments to resume.'); return self::SUCCESS; }
            $pending->each(function (LabAgent $agent): void {
                $agent->update(['lifecycle_status' => 'queued', 'decision_reason' => 'Transport/replay recovery: requeued after evaluator contention; strategy verdict withheld until a clean replay.']);
                $agent->generation()->update(['status' => 'screening', 'completed_at' => null]);
            });
            $batch = Bus::batch($pending->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen'))->all())
                ->name("{$lab->symbol} resumed differential trend-down cohort")
                ->allowFailures()
                ->onConnection('database')->onQueue('lab-'.strtolower($lab->symbol))->dispatch();
            $this->info("{$lab->symbol}: {$batch->id}; {$pending->count()} corrected differential experiments resumed.");
            return self::SUCCESS;
        }
        $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
        if ($active) {
            $this->warn("{$lab->symbol}: G{$active->generation} hali {$active->status}; yangi differential generation locklandi.");
            return self::SUCCESS;
        }

        [$control, $children] = DB::transaction(function () use ($source, $parent, $lab, $schemas, $target): array {
            $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
            if ($lockedLab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->exists()) return [null, collect()];
            $base = $schemas->validate('hybrid', $parent->parameters ?? []);
            $differentialBase = [...$schemas->defaults('differential_router'), ...$base,
                'differential_target_regime' => $target['regime'], 'differential_replay_mode' => 'paired_isolated'];
            $controlGeneration = $this->generation($lockedLab, $source, 'differential_parent_control');
            $controlModel = ModelVersion::create([
                'name' => strtolower($lab->symbol).'_hybrid_g'.$controlGeneration->generation.'_differential_parent',
                'strategy' => strtolower($lab->symbol).'_hybrid_g'.$controlGeneration->generation.'_differential_parent',
                'version' => 'v'.$controlGeneration->generation.'-differential-parent', 'generation' => $controlGeneration->generation,
                'status' => 'testing', 'parameters' => $base,
                'description' => "Frozen same-contract parent control for differential {$target['regime']} rescue from {$source->id}.",
                'metadata' => [...($parent->metadata ?? []), 'base_strategy' => 'hybrid_v1',
                    'differential_parent_control' => ['source_agent_id' => $source->id, 'target_regime' => $target['regime'], 'promotion_evidence' => false]],
            ]);
            $control = $controlGeneration->agents()->create([
                'model_version_id' => $controlModel->id, 'parent_a_model_version_id' => $parent->id,
                'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => 'hybrid',
                'origin' => 'causal_isolation', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
                'decision_reason' => 'Frozen parent replay for differential no-regression comparison; screening evidence only.',
            ]);
            $controlGeneration->update(['status' => 'screening']);

            $variants = $this->variantsFor($target['regime'], $differentialBase);
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
                if (isset($knownFingerprints[$fingerprint])) continue;
                $knownFingerprints[$fingerprint] = true;
                $generation = $this->generation($lockedLab, $source, 'differential_'.$target['regime'].'_single_gene');
                $diff = [];
                foreach ($variant['changes'] as $key => $newValue) {
                    $diff[$key] = ['old' => $differentialBase[$key] ?? null, 'new' => $newValue];
                }
                $model = ModelVersion::create([
                    'name' => strtolower($lab->symbol).'_differential_router_g'.$generation->generation.'_'.str_replace('_', '-', $variant['key']),
                    'strategy' => strtolower($lab->symbol).'_differential_router_g'.$generation->generation.'_'.str_replace('_', '-', $variant['key']),
                    'version' => 'v'.$generation->generation.'-'.$variant['key'], 'generation' => $generation->generation,
                    'status' => 'testing', 'parameters' => $parameters,
                    'description' => "Single-variable differential {$target['regime']} {$variant['key']} rescue from {$source->id}.",
                    'metadata' => [
                        'base_strategy' => 'differential_router_v1', 'strategy_architecture' => 'frozen_parent_differential_'.$target['regime'].'_v2',
                        'lab_symbol' => $lab->symbol, 'origin' => 'causal_isolation', 'generation_target' => $target['regime'].'_regime_coverage',
                        'differential_router_contract' => ['parent_model_version_id' => $controlModel->id, 'source_model_version_id' => $parent->id,
                            'target_regime' => $target['regime'], 'non_target_parent_freeze' => true,
                            'replay_mode' => 'paired_isolated', 'promotion_evidence' => false],
                        'differential_execution_contract' => self::EXECUTION_CONTRACT,
                        'parameter_fingerprint' => $fingerprint,
                        'causal_experiment_lane' => ['status' => 'single_gene', 'parameter_key' => $variant['key'],
                            'target_regime' => $target['regime'], 'rule' => 'Parent signal/confidence are immutable outside the target regime; one target-lane variable changes.'],
                    ],
                ]);
                $child = $generation->agents()->create([
                    'model_version_id' => $model->id, 'parent_a_model_version_id' => $controlModel->id,
                    'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => 'differential_router',
                    'origin' => 'causal_isolation', 'lifecycle_status' => 'queued',
                    'parameter_diff' => $diff,
                    'decision_reason' => 'Differential '.$target['regime'].' single-gene rescue; paired non-target ledger contract required.',
                ]);
                $generation->update(['status' => 'screening']);
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
            ->onConnection('database')->onQueue('lab-'.strtolower($lab->symbol))->dispatch();
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

    private function generation(AiLaboratory $lab, LabAgent $source, string $trigger): LabGeneration
    {
        $number = (int) ($lab->generations()->latest('generation')->lockForUpdate()->value('generation') ?? 0) + 1;
        return $lab->generations()->create([
            'generation' => $number, 'trigger_type' => $trigger,
            'trigger_context' => ['source_agent_id' => $source->id, 'promotion_evidence' => false,
                'execution_contract' => 'same parent data/seed/execution required'],
            'data_fingerprint' => $source->generation->data_fingerprint, 'population_size' => 1,
            'status' => 'draft', 'started_at' => now(),
        ]);
    }
}
