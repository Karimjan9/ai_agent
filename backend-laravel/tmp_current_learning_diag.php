<?php

use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningSettlement;
use App\Models\EvolutionLearningReceipt;
use App\Models\GenerationClosedLoopAudit;
use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use App\Models\MtfPlaybookFrozenControlRun;
use App\Models\SystemEvent;
use App\Services\LabQueueJobInspector;
use App\Services\LabQueueStateService;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (getenv('DIAG_MODE') === 'mtf_readiness') {
    $service = app(App\Services\MultiTimeframeSnapshotService::class);
    $readiness = $service->agentValidationReadiness('XAUUSD');
    $generation = LabGeneration::query()->where('generation', 151)->first();
    $manifest = (array) data_get($generation?->trigger_context, 'mtf_bundle_manifest', []);
    echo json_encode([
        'readiness' => $readiness,
        'frozen_manifest' => collect($manifest)->only([
            'validation_bundle_protocol', 'data_role', 'bundle_hash', 'entry_rows',
            'entry_first_candle_at', 'entry_last_candle_at', 'closed_cutoff',
            'bounded_cost_contract', 'streams',
        ])->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'causal_experiment') {
    $id = (int) getenv('EXPERIMENT_ID');
    $experiment = AgentLearningCausalExperiment::query()->find($id);
    echo json_encode([
        'experiment' => $experiment?->only([
            'id', 'lab_generation_id', 'symbol', 'timeframe', 'strategy_family',
            'target', 'gene_key', 'status', 'updated_at',
        ]),
        'repair_frontier' => data_get($experiment?->evidence, 'repair_frontier'),
        'repair_lineage' => data_get($experiment?->evidence, 'repair_lineage'),
        'source_pair_id' => data_get($experiment?->evidence, 'source_pair_id'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'edge_generation') {
    $generationNumber = (int) (getenv('GENERATION_NUMBER') ?: 147);
    $generation = LabGeneration::query()->with(['agents.modelVersion'])->where('generation', $generationNumber)->first();
    echo json_encode([
        'generation' => $generation?->only(['id', 'generation', 'status', 'trigger_type', 'population_size', 'updated_at']),
        'architecture_revision' => data_get($generation?->trigger_context, 'architecture_revision'),
        'trigger_context_keys' => array_keys((array) $generation?->trigger_context),
        'trigger_context' => collect((array) $generation?->trigger_context)->only([
            'protocol',
            'architecture_revision',
            'source_generation_id',
            'source_generation',
            'selected_packet',
            'selection_reason',
            'reason',
            'reason_codes',
            'phase',
            'data_hash',
            'execution_hash',
            'mtf_bundle_hash',
            'construction',
            'constructor_audit',
            'admission',
            'quarantine',
        ])->all(),
        'generation_plan' => collect((array) data_get($generation?->trigger_context, 'generation_plan', []))
            ->map(fn (array $seat, int $index): array => [
                'slot' => $index + 1,
                'family' => data_get($seat, 'family'),
                'origin' => data_get($seat, 'origin'),
                'target' => data_get($seat, 'target'),
                'role' => data_get($seat, 'niche.causal_learning_cohort.role'),
                'gene' => data_get($seat, 'niche.declared_gene'),
                'value' => data_get($seat, 'niche.declared_value'),
                'source_experiment_id' => data_get($seat, 'niche.causal_repair_source_experiment_id')
                    ?? data_get($seat, 'niche.causal_learning_cohort.source_experiment_id'),
            ])->values(),
        'agents' => $generation?->agents->map(function (LabAgent $agent): array {
            $trial = Illuminate\Support\Facades\DB::table('edge_genesis_trials')
                ->where('lab_agent_id', $agent->id)->first();
            $performance = $agent->modelVersion?->marketPerformances()->latest('id')->first();
            $runs = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)
                ->latest('id')->limit(3)->get();
            return [
                'id' => $agent->id,
                'model_version_id' => $agent->model_version_id,
                'lifecycle_status' => $agent->lifecycle_status,
                'decision_reason' => $agent->decision_reason,
                'arm' => data_get($agent->modelVersion?->metadata, 'edge_genesis.arm'),
                'temporal_parameters' => collect((array) $agent->modelVersion?->parameters)->only([
                    'breakout_setup_timeframe', 'swing_lookback',
                    'entry_mode', 'entry_model',
                ])->all(),
                'context' => data_get($agent->modelVersion?->metadata, 'edge_genesis.context'),
                'preflight_quarantine' => data_get($agent->modelVersion?->metadata, 'preflight_quarantine'),
                'trial' => $trial ? [
                    'id' => $trial->id, 'stage' => $trial->stage, 'status' => $trial->status,
                    'authority_selection' => data_get(json_decode((string) $trial->evidence, true), 'authority_selection'),
                    'nine_fold_causal_authority' => data_get(json_decode((string) $trial->evidence, true), 'nine_fold_causal_authority'),
                    'discovery_admission' => data_get(json_decode((string) $trial->evidence, true), 'discovery_admission'),
                ] : null,
                'performance' => $performance ? [
                    'id' => $performance->id,
                    'folds' => $performance->rolling_windows_count,
                    'trades' => data_get($performance->metrics, 'total_trades'),
                    'profit_factor' => data_get($performance->metrics, 'profit_factor'),
                    'expectancy_r' => data_get($performance->metrics, 'after_cost_expectancy_r'),
                    'positive_windows' => data_get($performance->metrics, 'forward_window_protocol.positive_windows'),
                ] : null,
                'runs' => $runs->map(fn (LabEvaluationRun $run): array => [
                    'id' => $run->id,
                    'phase' => $run->phase,
                    'status' => $run->status,
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at,
                    'error' => $run->error,
                ])->values(),
            ];
        })->values(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'edge_model_metrics') {
    $modelIds = collect(explode(',', (string) getenv('MODEL_IDS')))
        ->map(fn (string $id): int => (int) trim($id))->filter()->values();
    echo json_encode(App\Models\ModelVersion::query()->whereIn('id', $modelIds)->get()->map(function ($model): array {
        $performance = $model->marketPerformances()->latest('id')->first();
        $metrics = (array) ($performance?->metrics ?? []);
        return [
            'model_version_id' => $model->id,
            'strategy_family' => $model->strategy_family,
            'parameters' => $model->parameters,
            'metrics_keys' => array_keys($metrics),
            'summary' => [
                'total_trades' => data_get($metrics, 'total_trades'),
                'profit_factor' => data_get($metrics, 'profit_factor'),
                'after_cost_expectancy_r' => data_get($metrics, 'after_cost_expectancy_r'),
                'net_profit_percent' => data_get($metrics, 'net_profit_percent'),
                'max_drawdown_percent' => data_get($metrics, 'max_drawdown_percent'),
                'pf_summary' => data_get($metrics, 'pf_attribution.summary'),
                'by_direction' => data_get($metrics, 'pf_attribution.by_direction'),
                'by_session' => data_get($metrics, 'pf_attribution.by_session'),
                'by_regime' => data_get($metrics, 'pf_attribution.by_regime'),
                'by_volatility' => data_get($metrics, 'pf_attribution.by_volatility'),
                'edge_context_enforcement' => data_get($metrics, 'edge_context_enforcement'),
                'entry_contract_funnel' => data_get($metrics, 'entry_contract_funnel'),
                'forward_windows' => data_get($metrics, 'forward_window_protocol.windows'),
                'positive_windows' => data_get($metrics, 'forward_window_protocol.positive_windows'),
                'powered_windows' => data_get($metrics, 'forward_window_protocol.powered_windows'),
            ],
        ];
    })->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'edge_generation_results') {
    $generationNumber = (int) getenv('GENERATION_NUMBER');
    $generation = LabGeneration::query()->with(['agents.modelVersion'])->where('generation', $generationNumber)->first();
    echo json_encode([
        'generation' => $generation?->only(['id', 'generation', 'status', 'trigger_type', 'updated_at']),
        'revision' => data_get($generation?->trigger_context, 'architecture_revision'),
        'agents' => $generation?->agents->map(function (LabAgent $agent): array {
            $trial = Illuminate\Support\Facades\DB::table('edge_genesis_trials')
                ->where('lab_agent_id', $agent->id)->first();
            $performance = $agent->modelVersion?->marketPerformances()->latest('id')->first();
            $run = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->latest('id')->first();
            return [
                'agent_id' => $agent->id,
                'arm' => data_get($agent->modelVersion?->metadata, 'edge_genesis.arm'),
                'status' => $agent->lifecycle_status,
                'trial_status' => $trial?->status,
                'parameters' => collect((array) $agent->modelVersion?->parameters)->only([
                    'breakout_setup_timeframe', 'swing_lookback', 'entry_mode',
                    'minimum_independent_confirmations',
                ])->all(),
                'trades' => data_get($performance?->metrics, 'total_trades'),
                'profit_factor' => data_get($performance?->metrics, 'profit_factor'),
                'expectancy_r' => data_get($performance?->metrics, 'after_cost_expectancy_r'),
                'positive_windows' => data_get($performance?->metrics, 'forward_window_protocol.positive_windows'),
                'powered_windows' => data_get($performance?->metrics, 'forward_window_protocol.powered_windows'),
                'run' => $run?->only(['id', 'status', 'started_at', 'finished_at']),
            ];
        })->values(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'g127_failure_compact') {
    $agentIds = [1914, 1915, 1916];
    $agents = LabAgent::query()->with('modelVersion')->whereIn('id', $agentIds)->orderBy('id')->get();
    $runs = LabEvaluationRun::query()->whereIn('lab_agent_id', $agentIds)->orderBy('id')->get();
    $events = App\Models\LabLifecycleEvent::query()
        ->whereIn('lab_agent_id', $agentIds)
        ->orderByDesc('id')->limit(30)->get()->reverse()->values();
    $failed = Illuminate\Support\Facades\DB::table('failed_jobs')
        ->where(function ($query) use ($agentIds): void {
            foreach ($agentIds as $agentId) {
                $query->orWhere('payload', 'like', '%i:'.$agentId.';%')
                    ->orWhere('payload', 'like', '%"'.$agentId.'"%');
            }
        })->orderByDesc('id')->limit(10)->get();

    echo json_encode([
        'generation' => LabGeneration::query()->find(168)?->only(['id', 'generation', 'status', 'trigger_type', 'population_size', 'updated_at']),
        'agents' => $agents->map(fn (LabAgent $agent): array => [
            'id' => $agent->id,
            'status' => $agent->lifecycle_status,
            'reason' => $agent->decision_reason,
            'updated_at' => $agent->updated_at?->toIso8601String(),
            'recovery' => [
                'evaluator' => data_get($agent->modelVersion?->metadata, 'evaluator_recovery_attempts'),
                'retry_budget' => data_get($agent->modelVersion?->metadata, 'retry_budget_repair_recovery_attempts'),
                'timeout_budget' => data_get($agent->modelVersion?->metadata, 'timeout_budget_repair_recovery_attempts'),
            ],
            'last_screen_result_keys' => array_keys((array) data_get($agent->modelVersion?->metadata, 'last_screen_result', [])),
        ]),
        'runs' => $runs->map(fn (LabEvaluationRun $run): array => [
            'id' => $run->id,
            'agent' => $run->lab_agent_id,
            'run_id' => $run->run_id,
            'status' => $run->status,
            'attempt' => $run->attempt,
            'queue' => $run->queue,
            'duration_ms' => $run->duration_ms,
            'data_hash' => $run->data_hash,
            'parameter_hash' => $run->parameter_hash,
            'request_hash' => $run->request_hash,
            'response_hash' => $run->response_hash,
            'request_meta' => $run->request_meta,
            'response_meta' => $run->response_meta,
            'error_class' => $run->error_class,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'metadata' => $run->metadata,
        ]),
        'response_maps' => App\Models\LabMutationResponseMap::query()
            ->whereIn('lab_agent_id', $agentIds)->orderBy('id')->get()
            ->map(fn ($map): array => [
                'id' => $map->id,
                'agent' => $map->lab_agent_id,
                'stage' => $map->stage,
                'status' => $map->status,
                'evidence_run_id' => $map->evidence_run_id,
                'control_contract' => data_get($map->metadata, 'control_contract'),
            ]),
        'decisions' => App\Models\CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)->where('stage', 'screening')->orderBy('id')->get()
            ->map(fn ($decision): array => [
                'id' => $decision->id,
                'agent' => $decision->lab_agent_id,
                'decision' => $decision->decision,
                'metric_keys' => array_keys((array) $decision->metrics),
            ]),
        'events' => $events->map(fn ($event): array => [
            'id' => $event->id,
            'agent' => $event->lab_agent_id,
            'phase' => $event->phase,
            'type' => $event->event_type,
            'from' => $event->from_status,
            'to' => $event->to_status,
            'attempt' => $event->attempt,
            'reason_code' => $event->reason_code,
            'error_class' => $event->error_class,
            'error_message' => $event->error_message,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ]),
        'failed_jobs' => collect($failed)->map(fn ($row): array => [
            'id' => $row->id,
            'queue' => $row->queue,
            'exception' => mb_substr((string) $row->exception, 0, 1200),
            'failed_at' => $row->failed_at,
        ]),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'g127_causal_summary') {
    $experiment = AgentLearningCausalExperiment::query()->where('lab_generation_id', 168)->latest('id')->first();
    $assessment = $experiment
        ? app(App\Services\CausalScreeningBehaviorPreflightService::class)->assess($experiment)
        : [];
    echo json_encode([
        'experiment' => $experiment ? [
            'id' => $experiment->id,
            'status' => $experiment->status,
            'gene' => $experiment->gene_key,
            'old' => $experiment->old_value,
            'new' => $experiment->new_value,
            'kind' => data_get($experiment->evidence, 'experiment_kind'),
            'source_experiment_id' => data_get($experiment->evidence, 'source_causal_experiment_id'),
            'construction_validation' => data_get($experiment->evidence, 'construction_validation'),
            'activation_screen' => data_get($experiment->evidence, 'activation_screen'),
            'blinded_selector' => data_get($experiment->evidence, 'blinded_selector'),
            'screening_preflight' => data_get($experiment->evidence, 'screening_behavior_preflight'),
            'repair_frontier' => data_get($experiment->evidence, 'repair_frontier'),
        ] : null,
        'assessment' => [
            'status' => data_get($assessment, 'status'),
            'reason_codes' => data_get($assessment, 'reason_codes'),
            'guided' => data_get($assessment, 'roles.guided'),
            'blinded' => data_get($assessment, 'roles.blinded'),
            'control' => data_get($assessment, 'control'),
        ],
        'source_experiment' => ($source = AgentLearningCausalExperiment::query()->find((int) data_get($experiment?->evidence, 'source_causal_experiment_id', 0))) ? [
            'id' => $source->id,
            'status' => $source->status,
            'repair_frontier' => data_get($source->evidence, 'repair_frontier'),
        ] : null,
        'frontier' => app(App\Services\CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $experiment?->id),
        'source_frontier' => isset($source)
            ? ($sourceFrontier = app(App\Services\CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $source->id))
            : null,
        'next_blinded_selector' => isset($sourceFrontier) && is_array($sourceFrontier)
            ? (function (array $frontier): ?array {
                $model = App\Models\ModelVersion::query()->find((int) data_get($frontier, 'baseline_model_version_id'));
                if (! $model) return null;

                return app(App\Services\CausalBlindedMutationSelectorService::class)->select(
                    (string) data_get($frontier, 'strategy_family'),
                    (string) data_get($frontier, 'target'),
                    (array) $model->parameters,
                    'repair:'.data_get($frontier, 'source_experiment_id'),
                    (string) data_get($frontier, 'gene'),
                    data_get($frontier, 'value'),
                    (array) data_get($frontier, 'activation_manifest', []),
                );
            })($sourceFrontier)
            : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'active_generation_blockers') {
    echo json_encode([
        'active' => LabGeneration::query()->with('laboratory')->whereIn('status', [
            'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
        ])->orderByDesc('id')->get()->map(fn ($generation): array => [
            'id' => $generation->id,
            'generation' => $generation->generation,
            'status' => $generation->status,
            'symbol' => $generation->laboratory?->symbol,
            'timeframe' => $generation->laboratory?->timeframe,
            'updated_at' => $generation->updated_at?->toIso8601String(),
            'agents' => $generation->agents()->selectRaw('lifecycle_status, count(*) total')->groupBy('lifecycle_status')->pluck('total', 'lifecycle_status'),
        ]),
        'fresh_screened' => LabGeneration::query()->with('laboratory')
            ->whereIn('status', ['screened', 'completed'])
            ->where('updated_at', '>=', now()->subHour())
            ->whereHas('agents', fn ($query) => $query->where('lifecycle_status', 'screened'))
            ->orderByDesc('id')->get()->map(fn ($generation): array => [
                'id' => $generation->id,
                'generation' => $generation->generation,
                'status' => $generation->status,
                'symbol' => $generation->laboratory?->symbol,
                'updated_at' => $generation->updated_at?->toIso8601String(),
                'screened_agents' => $generation->agents()->where('lifecycle_status', 'screened')->count(),
            ]),
        'evolution_waiting' => app(App\Services\LabQueueJobInspector::class)->evolutionReplayIsWaiting(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'g128_summary') {
    $generation = LabGeneration::query()->with(['agents.modelVersion'])->find(169);
    echo json_encode([
        'generation' => $generation?->only([
            'id', 'generation', 'status', 'trigger_type', 'population_size',
            'data_fingerprint', 'created_at', 'updated_at',
        ]),
        'plan_count' => count((array) data_get($generation?->trigger_context, 'generation_plan', [])),
        'construction' => data_get($generation?->trigger_context, 'construction'),
        'causal_contract' => data_get($generation?->trigger_context, 'adaptive_evolution_policy.causal_learning_counterfactual_cohort'),
        'agents' => $generation?->agents->map(fn ($agent): array => [
            'id' => $agent->id,
            'status' => $agent->lifecycle_status,
            'role' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.role'),
            'diff' => $agent->parameter_diff,
            'blinded_selector' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.blinded_selector'),
            'constructor' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.construction_protocol'),
            'preflight' => data_get($agent->modelVersion?->metadata, 'preflight_quarantine'),
        ]),
        'experiments' => AgentLearningCausalExperiment::query()->where('lab_generation_id', 169)->get()->map(fn ($experiment): array => [
            'id' => $experiment->id,
            'status' => $experiment->status,
            'gene' => $experiment->gene_key,
            'guided' => $experiment->guided_agent_id,
            'blinded' => $experiment->blinded_agent_id,
            'control' => $experiment->control_agent_id,
            'construction' => data_get($experiment->evidence, 'construction_validation'),
            'blinded_selector' => data_get($experiment->evidence, 'blinded_selector'),
        ]),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'mtf_toolbox_portability') {
    $toolbox = app(App\Services\AgentResearchPlaybookToolboxService::class)->forAgent([], 'XAUUSD');
    $tool = collect((array) data_get($toolbox, 'tools', []))
        ->firstWhere('id', 'confirmation_breakout_retest');
    echo json_encode([
        'tool_id' => data_get($tool, 'id'),
        'learning_status' => data_get($tool, 'frozen_control_prior.learning_value.status'),
        'next_evidence' => data_get($tool, 'frozen_control_prior.learning_value.next_evidence'),
        'canonical_validation_consumed' => data_get($tool, 'frozen_control_prior.learning_value.canonical_agent_owned_validation_consumed'),
        'portability_classification' => data_get($tool, 'frozen_control_prior.agent_owned_validation.portability_diagnosis.classification'),
        'changed_axis' => data_get($tool, 'frozen_control_prior.learning_value.evolution_directive.changed_axis'),
        'parent_authority' => data_get($tool, 'frozen_control_prior.agent_owned_validation.parent_authority'),
        'promotion_evidence' => data_get($tool, 'frozen_control_prior.learning_value.promotion_evidence'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'runtime_leases') {
    echo json_encode([
        'scheduler_lease' => Illuminate\Support\Facades\Cache::get('system:scheduler-lease'),
        'scheduler_heartbeat' => Illuminate\Support\Facades\Cache::get('system:scheduler-heartbeat'),
        'replay_liveness' => app(App\Services\ReplayLivenessProbeService::class)->probe(),
        'queue' => app(App\Services\LabQueueStateService::class)->snapshot([
            'lab-screening', 'lab-frontier', 'lab-full-validation',
            'lab-xauusd', 'lab-eurusd', 'lab-gbpusd', 'lab-learning',
            'market-maintenance', 'scheduler-critical', 'scheduler-ops',
        ]),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'runtime_queue_summary') {
    $snapshot = app(App\Services\LabQueueStateService::class)->snapshot([
        'lab-screening', 'lab-frontier', 'lab-full-validation', 'lab-learning',
        'market-maintenance', 'scheduler-critical', 'scheduler-ops', 'scheduler-research',
    ]);
    echo json_encode([
        'scheduler_lease' => Illuminate\Support\Facades\Cache::get('system:scheduler-lease'),
        'counts' => $snapshot['queues'] ?? [],
        'jobs' => collect((array) ($snapshot['rows'] ?? []))->map(function (array $row): array {
            $payload = (string) ($row['payload'] ?? '');
            $decoded = json_decode($payload, true);
            $serialized = (string) data_get($decoded, 'data.command', '');
            preg_match('/s:7:"command";s:\d+:"([^"]+)"/', $serialized, $match);

            return [
                'id' => $row['id'] ?? null,
                'queue' => $row['queue'] ?? null,
                'state' => $row['redis_state'] ?? null,
                'attempts' => $row['attempts'] ?? null,
                'reserved_at' => $row['reserved_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'command' => $match[1] ?? data_get($decoded, 'displayName'),
            ];
        })->values(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'resume_interrupted_mtf_prior') {
    $jobId = (string) getenv('MTF_JOB_ID');
    $state = app(App\Services\LabQueueStateService::class);
    $snapshot = $state->snapshot(['lab-frontier']);
    $row = collect((array) ($snapshot['rows'] ?? []))->first(
        fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $jobId
    );
    $payload = (string) data_get($row, 'payload', '');
    $probe = app(App\Services\ReplayLivenessProbeService::class)->probe();
    $safe = $jobId !== ''
        && $row
        && (string) data_get($row, 'queue') === 'lab-frontier'
        && (string) data_get($row, 'redis_state') === 'reserved'
        && str_contains($payload, 'EvaluateMtfPlaybookPriorJob')
        && (string) data_get($probe, 'status') === 'ok'
        && (int) data_get($probe, 'active_requests', 0) === 0;
    if (! $safe) {
        fwrite(STDERR, json_encode([
            'status' => 'refused', 'job_id' => $jobId,
            'row' => $row ? collect($row)->only(['id', 'queue', 'redis_state', 'attempts', 'reserved_at'])->all() : null,
            'probe' => $probe,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(2);
    }
    $released = $state->releaseReservedPayload('lab-frontier', $payload);
    echo json_encode([
        'status' => $released ? 'interrupted_reservation_requeued' : 'reservation_release_race_lost',
        'job_id' => $jobId,
        'attempts' => data_get($row, 'attempts'),
        'economic_evidence_changed' => false,
        'job_preserved' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($released ? 0 : 3);
}

if (getenv('DIAG_MODE') === 'historical_learning_profile') {
    $agents = App\Models\LabAgent::query()->where('symbol', 'XAUUSD')->where('timeframe', 'H1');
    $agentIds = (clone $agents)->pluck('id');
    echo json_encode([
        'agents' => $agentIds->count(),
        'families' => (clone $agents)->distinct()->count('strategy_family'),
        'runs' => App\Models\LabEvaluationRun::query()->whereIn('lab_agent_id', $agentIds)->count(),
        'exact_candidate_runs' => App\Models\LabEvaluationRun::query()->whereIn('lab_agent_id', $agentIds)
            ->where('status', 'completed')->whereIn('phase', ['full_validation', 'paper', 'holdout'])->count(),
        'gate_events' => App\Models\LabGateDecisionEvent::query()->whereIn('lab_agent_id', $agentIds)->count(),
        'candle_events' => App\Models\LabCandleDecisionEvent::query()->whereIn('lab_agent_id', $agentIds)->count(),
        'mutation_credits' => App\Models\LabMutationCreditEvent::query()->whereIn('lab_agent_id', $agentIds)->count(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'temporal_partition') {
    $cutoff = Carbon\CarbonImmutable::create(2026, 1, 1, 0, 0, 0, 'UTC');
    $yearEnd = $cutoff->addYear();
    echo json_encode([
        'contract' => [
            'training_end_exclusive' => $cutoff->toIso8601String(),
            'paper_start_inclusive' => $cutoff->toIso8601String(),
            'paper_end_exclusive' => $yearEnd->toIso8601String(),
        ],
        'training_rows_at_or_after_cutoff' => Illuminate\Support\Facades\DB::table('market_training_candles')
            ->where('time', '>=', $cutoff)->count(),
        'training_latest' => Illuminate\Support\Facades\DB::table('market_training_candles')->max('time'),
        'paper_2026_rows' => App\Models\Candle::query()->where('time', '>=', $cutoff)->where('time', '<', $yearEnd)->count(),
        'paper_rows_at_or_after_2027' => App\Models\Candle::query()->where('time', '>=', $yearEnd)->count(),
        'frozen_windows' => App\Models\FrozenPaperWindow::query()->orderBy('id')->get()->map(fn ($window): array => [
            'id' => $window->id,
            'symbol' => $window->symbol,
            'timeframe' => $window->timeframe,
            'key' => $window->window_key,
            'training_end' => $window->training_ends_at?->utc()->toIso8601String(),
            'paper_start' => $window->paper_starts_at?->utc()->toIso8601String(),
            'paper_end' => $window->paper_ends_at?->utc()->toIso8601String(),
            'rows' => $window->row_count,
            'constitutional' => $window->training_ends_at?->utc()->equalTo($cutoff)
                && $window->paper_starts_at?->utc()->equalTo($cutoff)
                && $window->paper_ends_at?->utc()->lessThanOrEqualTo($yearEnd),
        ])->values(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'release_scheduler_lock') {
    $leaseKey = (string) config('services.scheduler.lease_key', 'trading:headless-scheduler:v1');
    Illuminate\Support\Facades\Cache::lock($leaseKey)->forceRelease();
    Illuminate\Support\Facades\Cache::forget('system:scheduler-lease');
    Illuminate\Support\Facades\Cache::forget('system:scheduler-heartbeat');
    echo json_encode(['released' => true, 'lease_key' => $leaseKey]).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'scheduled_command_statuses') {
    $commands = [
        ['trading:pump-learning-lane', ['XAUUSD', '--timeframe' => 'H1', '--limit' => 1, '--autonomous' => true], 'scheduler-critical'],
        ['trading:process-canonical-learning-outbox', ['--limit' => 25], 'scheduler-critical'],
        ['trading:recover-lab-replay-mutex', ['--force-stale' => true, '--stale-after' => 120, '--dry-run' => true, '--scheduled-sweep' => true], 'scheduler-critical'],
        ['trading:promote-lab-frontier', [], 'scheduler-critical'],
        ['market:health', [], 'scheduler-ops'],
        ['trading:lab-learn-from-history', [], 'scheduler-ops'],
    ];
    echo json_encode(collect($commands)->map(function (array $spec): array {
        $job = new App\Jobs\RunScheduledArtisanCommandJob($spec[0], $spec[1], $spec[2]);

        return [
            'command' => $spec[0],
            'lane' => $spec[2],
            'status' => Illuminate\Support\Facades\Cache::get('system:scheduled-command:'.$job->uniqueId()),
        ];
    })->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'mtf_validation_identity') {
    $run = App\Models\MtfAgentValidationRun::query()->with('modelVersion')->latest('id')->first();
    $settlement = $run ? App\Models\CompositionSettlement::query()
        ->where('model_version_id', $run->model_version_id)
        ->latest('id')
        ->first() : null;
    echo json_encode([
        'run_id' => $run?->id,
        'contract_composition_id' => data_get($run?->validation_contract, 'composition_id'),
        'contract_passport_id' => data_get($run?->validation_contract, 'composition_passport.composition_id'),
        'owner_validation_passport_id' => data_get($run?->modelVersion?->metadata, 'mtf_agent_validation.composition_passport.composition_id'),
        'owner_base_passport_id' => data_get($run?->modelVersion?->metadata, 'smart_composition.composition_passport.composition_id'),
        'settlement_composition_id' => $settlement?->composition_id,
        'settlement_validation_run_id' => data_get($settlement?->evidence, 'validation_run_id'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'intraday_maintenance') {
    $archives = App\Models\MarketTrainingArchive::query()
        ->where('dataset_key', 'foundation_intraday_10y')
        ->where('symbol', 'XAUUSD')
        ->whereIn('timeframe', ['M1', 'M5', 'M30'])
        ->orderBy('timeframe')
        ->get()
        ->map(fn ($archive) => [
            'timeframe' => $archive->timeframe,
            'status' => $archive->status,
            'row_count' => $archive->row_count,
            'cursor' => $archive->backfill_cursor_at?->utc()->toIso8601String(),
            'last_success_at' => $archive->last_success_at?->utc()->toIso8601String(),
            'failed_chunks' => $archive->failed_chunks,
            'last_error' => $archive->last_error,
        ])->values();
    echo json_encode([
        'heartbeat' => Illuminate\Support\Facades\Cache::get('system:intraday-training-maintenance-heartbeat'),
        'failure_cooldown' => Illuminate\Support\Facades\Cache::has('market-maintenance:intraday-training:failure-cooldown'),
        'archives' => $archives,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'canonical_v6') {
    $outbox = app(App\Services\CanonicalLearningOutboxService::class);
    $rows = App\Models\CanonicalLearningOutbox::query()
        ->where('status', 'completed')
        ->whereHas('pair', fn ($query) => $query->where('symbol', 'XAUUSD')->where('timeframe', 'H1'))
        ->get();
    $attributions = App\Models\CapabilityCausalAttribution::query()
        ->whereIn('attribution_key', $rows->map(function ($row): string {
            $result = (array) data_get($row->payload, 'result', []);

            return 'learning-settlement:'.$row->pair_id.':'.(string) data_get($result, 'evidence_run_id', 'none');
        }))->get();
    echo json_encode([
        'completed_outboxes' => $rows->count(),
        'requires_reprojection' => $rows->filter(fn ($row): bool => $outbox->requiresReprojection($row))->count(),
        'attributions' => $attributions->count(),
        'projection_protocols' => $attributions->countBy(fn ($row): string => (string) data_get($row->evidence, 'projection_protocol', 'missing'))->all(),
        'process_outcome_protocols' => $attributions->countBy(fn ($row): string => (string) data_get($row->evidence, 'process_outcome_audit.protocol', 'missing'))->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'latest_mtf_agent_validation') {
    $run = App\Models\MtfAgentValidationRun::query()->latest('id')->first();
    echo json_encode($run ? [
        'id' => $run->id,
        'source_run_id' => $run->source_run_id,
        'model_version_id' => $run->model_version_id,
        'status' => $run->status,
        'attempts' => $run->attempts,
        'created_at' => $run->created_at?->toIso8601String(),
        'updated_at' => $run->updated_at?->toIso8601String(),
        'completed_at' => $run->completed_at?->toIso8601String(),
        'duration_seconds' => $run->completed_at && $run->started_at ? $run->started_at->diffInSeconds($run->completed_at) : null,
        'verdict' => data_get($run->paired_summary, 'verdict'),
        'observed_windows' => data_get($run->paired_summary, 'observed_windows'),
        'powered_windows' => data_get($run->paired_summary, 'powered_paired_windows'),
        'positive_windows' => data_get($run->paired_summary, 'positive_paired_windows'),
        'candidate_trades' => data_get($run->paired_summary, 'candidate_total_trades'),
        'next_evidence' => data_get($run->paired_summary, 'next_evidence'),
        'evidence_budget_rows' => data_get($run->validation_contract, 'historical_evidence_budget_rows'),
        'historical_entry_rows' => data_get($run->validation_contract, 'historical_entry_rows'),
        'manifest_entry_rows' => data_get($run->dataset_manifest, 'streams.M5.row_count'),
        'manifest_entry_first' => data_get($run->dataset_manifest, 'streams.M5.first_candle_at'),
        'manifest_entry_last' => data_get($run->dataset_manifest, 'streams.M5.last_candle_at'),
        'reason_codes' => $run->reason_codes,
        'last_error' => $run->last_error,
        'promotion_evidence' => $run->promotion_evidence,
    ] : null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'latest_mtf_agent_validation_detail') {
    $run = App\Models\MtfAgentValidationRun::query()->with('modelVersion')->latest('id')->first();
    $settlement = $run ? App\Models\CompositionSettlement::query()
        ->where('model_version_id', $run->model_version_id)
        ->latest('id')->first() : null;
    $arm = static fn (array $result): array => [
        'trades' => data_get($result, 'total_trades'),
        'profit_factor' => data_get($result, 'profit_factor'),
        'net_profit_percent' => data_get($result, 'net_profit_percent'),
        'max_drawdown_percent' => data_get($result, 'max_drawdown_percent'),
        'entry_funnel_protocol' => data_get($result, 'entry_funnel.protocol'),
        'entry_funnel_semantics' => data_get($result, 'entry_funnel.count_semantics'),
        'stage_counts' => data_get($result, 'entry_funnel.stage_counts'),
        'predicate_counts' => data_get($result, 'entry_funnel.predicate_counts'),
        'abstention_count' => data_get($result, 'abstention_count', data_get($result, 'temporal_survival.abstention_count')),
        'confirmation_status' => data_get($result, 'learning_confirmation.status'),
        'fold_windows' => data_get($result, 'walk_forward.forward_window_protocol.windows'),
        'causal_replay' => data_get($result, 'causal_confirmation_replay'),
    ];
    echo json_encode($run ? [
        'run' => $run->only(['id', 'source_run_id', 'model_version_id', 'status', 'attempts', 'reason_codes', 'completed_at']),
        'candidate' => $arm((array) $run->candidate_result),
        'control' => $arm((array) $run->control_result),
        'paired_summary' => $run->paired_summary,
        'causal_accounting_protocol' => data_get($run->causal_accounting, 'protocol'),
        'process_outcome_protocol' => data_get($run->causal_accounting, 'process_outcome_audit.protocol'),
        'settlement' => $settlement ? [
            'id' => $settlement->id,
            'status' => $settlement->status,
            'evidence_tier' => data_get($settlement->evidence, 'evidence_tier'),
            'validation_run_id' => data_get($settlement->evidence, 'validation_run_id'),
            'promotion_evidence' => data_get($settlement->evidence, 'promotion_evidence'),
        ] : null,
        'owner' => [
            'id' => $run->modelVersion?->id,
            'status' => $run->modelVersion?->status,
            'evidence_status' => $run->modelVersion?->evidence_status,
            'validation' => data_get($run->modelVersion?->metadata, 'mtf_agent_validation'),
            'runtime_trade_authority' => data_get($run->modelVersion?->metadata, 'runtime_trade_authority'),
            'parent_authority' => data_get($run->modelVersion?->metadata, 'parent_authority'),
        ],
    ] : null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'market_training') {
    echo json_encode([
        'archives' => App\Models\MarketTrainingArchive::query()
            ->where('symbol', 'XAUUSD')
            ->orderBy('dataset_key')->orderBy('timeframe')
            ->get(['id', 'dataset_key', 'provider', 'timeframe', 'status', 'row_count', 'first_candle_at', 'last_candle_at', 'backfill_cursor_at', 'last_error'])
            ->toArray(),
        'counts' => App\Models\MarketTrainingCandle::query()
            ->where('symbol', 'XAUUSD')
            ->selectRaw('dataset_key, provider, timeframe, COUNT(*) as row_count, MIN(time) as first_candle_at, MAX(time) as last_candle_at')
            ->groupBy('dataset_key', 'provider', 'timeframe')
            ->orderBy('dataset_key')->orderBy('timeframe')
            ->get()->toArray(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'mtf_tail_span') {
    $query = app(App\Services\MarketData\MarketTrainingDataService::class)
        ->query('foundation_intraday_10y', 'dukascopy', 'XAUUSD', 'M5');
    echo json_encode(collect([50000, 100000, 150000, 200000, 250000])->mapWithKeys(function (int $rows) use ($query): array {
        $first = (clone $query)->orderByDesc('time')->offset($rows - 1)->limit(1)->value('time');
        $last = (clone $query)->orderByDesc('time')->limit(1)->value('time');

        return [(string) $rows => [
            'first' => $first,
            'last' => $last,
            'span_days' => $first && $last ? Carbon\CarbonImmutable::parse($first)->diffInDays(Carbon\CarbonImmutable::parse($last)) : null,
        ]];
    })->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'freeze_mtf_agent_bundle') {
    $started = microtime(true);
    $snapshots = app(App\Services\MultiTimeframeSnapshotService::class);
    $readiness = $snapshots->agentValidationReadiness('XAUUSD');
    $bundle = $snapshots->forAgentOwnedConfirmationValidation('XAUUSD');
    echo json_encode([
        'elapsed_seconds' => round(microtime(true) - $started, 3),
        'readiness' => $readiness,
        'bundle_hash' => $bundle['bundle_hash'],
        'protocol' => data_get($bundle, 'manifest.validation_bundle_protocol'),
        'data_role' => data_get($bundle, 'manifest.data_role'),
        'streams' => collect((array) data_get($bundle, 'manifest.streams', []))->map(fn (array $stream): array => [
            'rows' => $stream['row_count'] ?? null,
            'first' => $stream['first_candle_at'] ?? null,
            'last' => $stream['last_candle_at'] ?? null,
        ])->all(),
        'bounded_cost_contract' => data_get($bundle, 'manifest.bounded_cost_contract'),
        'promotion_evidence' => data_get($bundle, 'manifest.promotion_evidence'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'abort_mtf_validation_retry_storm') {
    $sourceRunId = (int) getenv('MTF_SOURCE_RUN_ID');
    $snapshot = app(App\Services\LabQueueStateService::class)->snapshot(['lab-frontier']);
    $row = collect((array) ($snapshot['rows'] ?? []))->first(function (array $candidate) use ($sourceRunId): bool {
        $payload = (string) ($candidate['payload'] ?? '');

        return (string) ($candidate['redis_state'] ?? '') === 'reserved'
            && (int) ($candidate['attempts'] ?? 0) >= 10
            && str_contains($payload, 'ValidateMtfPoweredPriorJob')
            && str_contains($payload, 'sourceRunId\\";i:'.$sourceRunId.';');
    });
    $runExists = App\Models\MtfAgentValidationRun::query()
        ->where('source_run_id', $sourceRunId)
        ->whereIn('status', ['started', 'completed'])
        ->exists();
    if (! $row || $runExists || $sourceRunId <= 0) {
        fwrite(STDERR, json_encode([
            'status' => 'refused',
            'source_run_id' => $sourceRunId,
            'active_validation_row' => $runExists,
            'row' => $row,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(2);
    }
    $removed = app(App\Services\LabQueueStateService::class)->removeCompletedReservedPayload(
        'lab-frontier',
        (string) $row['payload'],
    );
    Illuminate\Support\Facades\Cache::store('redis')->lock(
        'laravel_unique_job:App\\Jobs\\ValidateMtfPoweredPriorJob:mtf-powered-prior-validation:'.$sourceRunId,
    )->forceRelease();
    echo json_encode([
        'status' => $removed ? 'retry_storm_removed' : 'reserved_payload_not_removed',
        'source_run_id' => $sourceRunId,
        'job_id' => $row['id'],
        'attempts' => $row['attempts'],
        'evidence_persisted' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($removed ? 0 : 3);
}

if (getenv('DIAG_MODE') === 'move_mtf_validation_to_full_lane') {
    $sourceRunId = (int) getenv('MTF_SOURCE_RUN_ID');
    $state = app(App\Services\LabQueueStateService::class);
    $snapshot = $state->snapshot(['lab-frontier', 'lab-full-validation']);
    $row = collect((array) ($snapshot['rows'] ?? []))->first(function (array $candidate) use ($sourceRunId): bool {
        $payload = (string) ($candidate['payload'] ?? '');

        return (string) ($candidate['queue'] ?? '') === 'lab-frontier'
            && (string) ($candidate['redis_state'] ?? '') === 'pending'
            && str_contains($payload, 'ValidateMtfPoweredPriorJob')
            && str_contains($payload, 'sourceRunId\\";i:'.$sourceRunId.';');
    });
    if (! $row) {
        fwrite(STDERR, json_encode(['status' => 'refused', 'source_run_id' => $sourceRunId], JSON_PRETTY_PRINT).PHP_EOL);
        exit(2);
    }
    $moved = $state->movePendingPayload('lab-frontier', 'lab-full-validation', (string) $row['payload']);
    echo json_encode([
        'status' => $moved ? 'moved_to_full_validation' : 'move_race_lost',
        'source_run_id' => $sourceRunId,
        'job_id' => $row['id'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($moved ? 0 : 3);
}

if (getenv('DIAG_MODE') === 'abort_delayed_mtf_validation') {
    $sourceRunId = (int) getenv('MTF_SOURCE_RUN_ID');
    $state = app(App\Services\LabQueueStateService::class);
    $snapshot = $state->snapshot(['lab-full-validation']);
    $row = collect((array) ($snapshot['rows'] ?? []))->first(function (array $candidate) use ($sourceRunId): bool {
        $payload = (string) ($candidate['payload'] ?? '');

        return (string) ($candidate['queue'] ?? '') === 'lab-full-validation'
            && (string) ($candidate['redis_state'] ?? '') === 'delayed'
            && (int) ($candidate['attempts'] ?? 0) >= 8
            && str_contains($payload, 'ValidateMtfPoweredPriorJob')
            && str_contains($payload, 'sourceRunId\\";i:'.$sourceRunId.';');
    });
    $active = App\Models\MtfAgentValidationRun::query()
        ->where('source_run_id', $sourceRunId)
        ->whereIn('status', ['started', 'completed'])
        ->exists();
    if (! $row || $active) {
        fwrite(STDERR, json_encode(['status' => 'refused', 'source_run_id' => $sourceRunId, 'active' => $active], JSON_PRETTY_PRINT).PHP_EOL);
        exit(2);
    }
    $removed = $state->removeDelayedPayload('lab-full-validation', (string) $row['payload']);
    Illuminate\Support\Facades\Cache::store('redis')->lock(
        'laravel_unique_job:App\\Jobs\\ValidateMtfPoweredPriorJob:mtf-powered-prior-validation:'.$sourceRunId,
    )->forceRelease();
    echo json_encode([
        'status' => $removed ? 'delayed_self_deadlock_removed' : 'payload_not_removed',
        'source_run_id' => $sourceRunId,
        'job_id' => $row['id'],
        'attempts' => $row['attempts'],
        'evidence_persisted' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($removed ? 0 : 3);
}

if (getenv('DIAG_MODE') === 'abort_exact_stuck_mtf') {
    $runId = (int) getenv('MTF_RUN_ID');
    $jobId = (string) getenv('MTF_JOB_ID');
    $run = MtfPlaybookFrozenControlRun::query()->find($runId);
    $probe = app(App\Services\ReplayLivenessProbeService::class)->probe();
    $snapshot = app(LabQueueJobInspector::class)->queueSnapshot(['lab-frontier']);
    $row = collect((array) ($snapshot['rows'] ?? []))->first(
        fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $jobId
    );
    $payload = (string) data_get($row, 'payload', '');
    $safe = $run
        && (string) $run->status === 'started'
        && (array) $run->control_result === []
        && (array) $run->candidate_result === []
        && $run->updated_at?->isBefore(now()->subMinutes(15))
        && (string) data_get($probe, 'status') === 'ok'
        && (int) data_get($probe, 'active_requests', 0) === 0
        && $row
        && (string) data_get($row, 'queue') === 'lab-frontier'
        && str_contains($payload, 'EvaluateMtfPlaybookPriorJob');
    if (! $safe) {
        fwrite(STDERR, json_encode(['status' => 'refused', 'run' => $run?->only(['id', 'status', 'updated_at']), 'probe' => $probe, 'row' => $row], JSON_PRETTY_PRINT).PHP_EOL);
        exit(2);
    }
    $run->update([
        'status' => 'technical_error',
        'reason_codes' => ['TRANSPORT_RESPONSE_OVERSIZE_ABORTED'],
        'comparison' => [
            'protocol' => 'mtf_playbook_frozen_control_v1',
            'status' => 'technical_error',
            'message' => 'AI computation completed but the legacy full-ledger response exceeded the bounded PHP handoff; no evidence was persisted.',
            'quality_verdict' => 'withheld',
            'replacement_runner_contract' => App\Services\MtfPlaybookFrozenControlService::RUNNER_CONTRACT_HASH,
            'promotion_evidence' => false,
        ],
        'completed_at' => now(),
        'promotion_evidence' => false,
    ]);
    $removed = app(LabQueueStateService::class)->removeCompletedReservedPayload('lab-frontier', $payload);
    Illuminate\Support\Facades\Cache::store('redis')->lock(
        'laravel_unique_job:App\\Jobs\\EvaluateMtfPlaybookPriorJob:mtf-playbook-prior:XAUUSD'
    )->forceRelease();
    echo json_encode([
        'status' => $removed ? 'terminal_reservation_removed' : 'reservation_remove_failed',
        'run_id' => $runId,
        'job_id' => $jobId,
        'evidence_persisted' => false,
        'quality_verdict' => 'withheld',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($removed ? 0 : 3);
}

if (getenv('DIAG_MODE') === 'screen_hashes') {
    echo json_encode(LabEvaluationRun::query()->whereIn('id', [4882, 4883, 4884])
        ->orderBy('id')->get()->map(function (LabEvaluationRun $run): array {
            $result = (array) data_get($run->metrics, 'agent_result', []);

            return [
                'id' => $run->id,
                'agent_id' => $run->lab_agent_id,
                'metric_keys' => array_keys((array) $run->metrics),
                'result_keys' => array_keys($result),
                'metrics' => $run->metrics,
                'trade_ledger_hash' => data_get($result, 'trade_ledger_hash'),
                'event_ledger_hash' => data_get($result, 'event_ledger_hash', data_get($result, 'event_digest.hash')),
                'signal_decision_hash' => data_get($result, 'signal_decision_hash', data_get($result, 'signal_digest.hash')),
                'total_trades' => data_get($result, 'total_trades'),
                'accepted_entries' => data_get($result, 'entry_funnel.accepted_entries'),
                'accepted_exits' => data_get($result, 'exit_funnel.accepted_exits'),
                'abstention_count' => data_get($result, 'abstention_count', data_get($result, 'temporal_survival.abstention_count')),
                'response_map' => (function () use ($run): ?array {
                    $map = App\Models\LabMutationResponseMap::query()
                        ->where('lab_agent_id', $run->lab_agent_id)->where('stage', 'screening')
                        ->latest('id')->first();
                    if (! $map) return null;

                    return [
                        'id' => $map->id,
                        'status' => $map->status,
                        'causal_observation' => data_get($map->observed_metrics, 'causal_observation'),
                        'observable_effect' => data_get($map->metadata, 'observable_effect'),
                        'progress_ladder' => data_get($map->metadata, 'progress_ladder'),
                    ];
                })(),
            ];
        })->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'quick_generation') {
    $generation = LabGeneration::query()->with('agents.modelVersion')->latest('id')->first();
    $experiment = AgentLearningCausalExperiment::query()
        ->where('lab_generation_id', $generation?->id)->latest('id')->first();
    echo json_encode([
        'generation' => $generation?->only(['id', 'generation', 'status', 'trigger_type', 'population_size', 'updated_at']),
        'experiment' => $experiment ? [
            'id' => $experiment->id,
            'status' => $experiment->status,
            'gene_key' => $experiment->gene_key,
            'old_value' => $experiment->old_value,
            'new_value' => $experiment->new_value,
            'kind' => data_get($experiment->evidence, 'experiment_kind'),
            'construction_protocol' => data_get($experiment->evidence, 'construction_protocol'),
            'construction_validation' => data_get($experiment->evidence, 'construction_validation'),
            'activation_screen' => data_get($experiment->evidence, 'activation_screen'),
            'blinded_selector' => data_get($experiment->evidence, 'blinded_selector'),
            'architecture_lineage' => data_get($experiment->evidence, 'architecture_lineage'),
        ] : null,
        'agents' => $generation?->agents->map(fn ($agent): array => [
            'id' => $agent->id,
            'status' => $agent->lifecycle_status,
            'role' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.role'),
            'diff' => $agent->parameter_diff,
            'construction_protocol' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.construction_protocol'),
            'parameter_count' => count((array) $agent->modelVersion?->parameters),
        ])->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'latest_screen_preflight') {
    $experiment = AgentLearningCausalExperiment::query()->latest('id')->first();
    echo json_encode([
        'experiment_id' => $experiment?->id,
        'assessment' => $experiment
            ? app(App\Services\CausalScreeningBehaviorPreflightService::class)->assess($experiment)
            : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$generations = LabGeneration::query()->with(['agents.modelVersion'])
    ->latest('id')->limit(5)->get()->map(function (LabGeneration $generation): array {
        return [
            'id' => $generation->id,
            'generation' => $generation->generation,
            'status' => $generation->status,
            'population_size' => $generation->population_size,
            'trigger_type' => $generation->trigger_type,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
            'trigger_context' => $generation->trigger_context,
            'agents' => $generation->agents->map(fn ($agent): array => [
                'id' => $agent->id,
                'model_version_id' => $agent->model_version_id,
                'status' => $agent->lifecycle_status,
                'origin' => $agent->origin,
                'parameter_diff' => $agent->parameter_diff,
                'decision_reason' => $agent->decision_reason,
                'model_parameters' => $agent->modelVersion?->parameters,
                'model_metadata' => $agent->modelVersion?->metadata,
            ])->all(),
        ];
    })->all();

$mtf = MtfPlaybookFrozenControlRun::query()->latest('id')->limit(10)->get()->map(fn ($run): array => [
    'id' => $run->id,
    'model' => $run->research_model_id,
    'status' => $run->status,
    'created_at' => $run->created_at?->toIso8601String(),
    'completed_at' => $run->completed_at?->toIso8601String(),
    'reason_codes' => $run->reason_codes,
    'comparison' => $run->comparison,
])->all();

if (getenv('DIAG_MODE') === 'mtf') {
    echo json_encode($mtf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'latest_mtf_detail') {
    echo json_encode(
        MtfPlaybookFrozenControlRun::query()->latest('id')->first()?->toArray(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'latest_mtf_summary') {
    $run = MtfPlaybookFrozenControlRun::query()->latest('id')->first();
    $candidate = (array) $run?->candidate_result;
    $control = (array) $run?->control_result;
    echo json_encode([
        'id' => $run?->id,
        'model' => $run?->research_model_id,
        'status' => $run?->status,
        'duration_seconds' => $run?->completed_at && $run?->created_at ? $run->created_at->diffInSeconds($run->completed_at) : null,
        'runner_contract_hash' => data_get($run?->comparison, 'runner_contract_hash'),
        'candidate_variant' => data_get($run?->comparison, 'candidate_variant'),
        'control' => [
            'trades' => data_get($control, 'total_trades'),
            'pf' => data_get($control, 'profit_factor'),
            'net' => data_get($control, 'net_profit_percent'),
            'ledger_count' => data_get($control, 'trade_ledger_count', data_get($control, 'displayed_trade_count')),
            'ledger_array_count' => count((array) data_get($control, 'trade_ledger', [])),
        ],
        'candidate' => [
            'trades' => data_get($candidate, 'total_trades'),
            'pf' => data_get($candidate, 'profit_factor'),
            'net' => data_get($candidate, 'net_profit_percent'),
            'ledger_count' => data_get($candidate, 'trade_ledger_count', data_get($candidate, 'displayed_trade_count')),
            'ledger_array_count' => count((array) data_get($candidate, 'trade_ledger', [])),
            'funnel_protocol' => data_get($candidate, 'entry_contract_funnel.protocol'),
            'count_semantics' => data_get($candidate, 'entry_contract_funnel.count_semantics'),
            'stage_counts' => data_get($candidate, 'entry_contract_funnel.stage_counts'),
            'predicate_counts' => data_get($candidate, 'entry_contract_funnel.predicate_counts'),
            'conversion' => data_get($candidate, 'entry_contract_funnel.conversion'),
            'no_trade_reasons' => data_get($candidate, 'entry_contract_funnel.no_trade_reasons'),
            'confirmation_cost' => data_get($candidate, 'entry_contract_funnel.confirmation_cost'),
        ],
        'comparison' => [
            'evidence_budget' => data_get($run?->comparison, 'evidence_budget'),
            'power' => data_get($run?->comparison, 'power'),
            'delta' => data_get($run?->comparison, 'delta'),
            'interpretation' => data_get($run?->comparison, 'interpretation'),
            'learning_directive' => data_get($run?->comparison, 'learning_directive'),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'db_processlist') {
    echo json_encode(
        Illuminate\Support\Facades\DB::select('SHOW FULL PROCESSLIST'),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ).PHP_EOL;
    exit(0);
}

$runs = LabEvaluationRun::query()->latest('id')->limit(20)->get()->map(fn ($run): array => [
    'id' => $run->id,
    'run_id' => $run->run_id,
    'agent_id' => $run->lab_agent_id,
    'phase' => $run->phase,
    'status' => $run->status,
    'error' => $run->error_message,
    'metadata' => $run->metadata,
    'created_at' => $run->created_at?->toIso8601String(),
])->all();

$pause = SystemEvent::query()->where('event_key', 'learning_protocol:generation_creation_paused')->latest('id')->first();

if (getenv('DIAG_MODE') === 'status') {
    $latestGeneration = LabGeneration::query()->latest('id')->first();
    echo json_encode([
        'now' => now()->toIso8601String(),
        'generation' => $latestGeneration?->only(['id', 'generation', 'status', 'updated_at', 'completed_at']),
        'agents' => $latestGeneration?->agents()->get()->map(fn ($agent): array => [
            'id' => $agent->id,
            'status' => $agent->lifecycle_status,
            'decision_reason' => $agent->decision_reason,
        ])->all(),
        'runs' => LabEvaluationRun::query()->where('lab_generation_id', $latestGeneration?->id)
            ->orderBy('id')->get()->map(fn ($run): array => [
                'id' => $run->id, 'agent_id' => $run->lab_agent_id,
                'phase' => $run->phase, 'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'error' => $run->error_message,
            ])->all(),
        'queue' => app(LabQueueJobInspector::class)->queueSnapshot(),
        'latest_mtf' => MtfPlaybookFrozenControlRun::query()->latest('id')->first()?->only([
            'id', 'research_model_id', 'status', 'created_at', 'updated_at', 'completed_at', 'reason_codes',
        ]),
        'safety_pause' => (bool) data_get($pause?->payload, 'paused', false),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'generation_contract') {
    $latestGeneration = LabGeneration::query()->with('agents.modelVersion')->latest('id')->first();
    $latestExperiment = AgentLearningCausalExperiment::query()
        ->where('lab_generation_id', $latestGeneration?->id)->latest('id')->first();
    echo json_encode([
        'generation' => $latestGeneration?->only(['id', 'generation', 'status', 'trigger_type', 'population_size']),
        'trigger_context_keys' => array_keys((array) $latestGeneration?->trigger_context),
        'generation_plan_count' => count((array) data_get($latestGeneration?->trigger_context, 'generation_plan', [])),
        'construction_audit' => data_get($latestGeneration?->trigger_context, 'constructor_audit'),
        'contract' => data_get($latestGeneration?->trigger_context, 'causal_learning_cohort'),
        'experiment' => $latestExperiment ? [
            'id' => $latestExperiment->id,
            'status' => $latestExperiment->status,
            'construction_protocol' => data_get($latestExperiment->evidence, 'construction_protocol'),
            'construction_validation' => data_get($latestExperiment->evidence, 'construction_validation'),
            'activation_screen' => data_get($latestExperiment->evidence, 'activation_screen'),
            'blinded_selector' => data_get($latestExperiment->evidence, 'blinded_selector'),
        ] : null,
        'agents' => $latestGeneration?->agents->map(fn ($agent): array => [
            'id' => $agent->id,
            'role' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.role'),
            'parameter_diff' => $agent->parameter_diff,
            'parameter_count' => count((array) $agent->modelVersion?->parameters),
            'repair_activation_screen' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.repair_gene_activation_screen'),
            'construction_protocol' => data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.construction_protocol'),
        ])->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'run') {
    $run = LabEvaluationRun::query()->find((int) getenv('RUN_ID'));
    $result = (array) data_get($run?->metrics, 'agent_result', []);
    echo json_encode([
        'id' => $run?->id,
        'metric_keys' => array_keys((array) $run?->metrics),
        'response_meta_keys' => array_keys((array) $run?->response_meta),
        'metadata_keys' => array_keys((array) $run?->metadata),
        'result_keys' => array_keys($result),
        'headline' => array_intersect_key($result, array_flip([
            'total_trades', 'wins', 'losses', 'profit_factor', 'net_profit_percent',
            'max_drawdown_percent', 'risk_of_ruin_percent', 'max_consecutive_losses',
        ])),
        'causal_confirmation_replay' => data_get($result, 'causal_confirmation_replay'),
        'learning_confirmation' => data_get($result, 'learning_confirmation'),
        'cooldown_policy' => data_get($result, 'cooldown_policy'),
        'risk_policy' => data_get($result, 'risk_policy'),
        'decision_behavior_fingerprint' => data_get($result, 'decision_behavior_fingerprint'),
        'volatility_performance' => data_get($result, 'volatility_performance'),
        'regime_performance' => data_get($result, 'regime_performance'),
        'strategy_dna' => data_get($result, 'strategy_dna'),
        'signal_decision_categories' => data_get($result, 'signal_decision_categories'),
        'parameter_activation_manifest' => data_get($result, 'parameter_activation_manifest'),
        'response_meta' => $run?->response_meta,
        'metadata' => $run?->metadata,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'ledger_profile') {
    $queries = [];
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
        $queries[] = ['time_ms' => $query->time, 'sql' => $query->sql];
    });
    $started = microtime(true);
    $context = app(App\Services\LabTrialLedgerService::class)->selectionContext('XAUUSD', 'H1');
    usort($queries, fn (array $a, array $b): int => $b['time_ms'] <=> $a['time_ms']);
    echo json_encode([
        'elapsed_seconds' => round(microtime(true) - $started, 3),
        'query_count' => count($queries),
        'slowest' => array_slice($queries, 0, 20),
        'counts' => $context['trial_count_by_outcome'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$causalExperiments = AgentLearningCausalExperiment::query()->latest('id')->limit(8)->get()->map(function ($experiment): array {
    $ids = array_filter([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id]);
    $agents = LabAgent::query()->with('modelVersion')->whereIn('id', $ids)->get()->keyBy('id');
    $roles = [];
    foreach (['guided' => $experiment->guided_agent_id, 'blinded' => $experiment->blinded_agent_id, 'control' => $experiment->control_agent_id] as $role => $id) {
        $agent = $agents->get($id);
        $roles[$role] = $agent ? [
            'agent_id' => $agent->id,
            'lifecycle_status' => $agent->lifecycle_status,
            'decision_reason' => $agent->decision_reason,
            'parent_id' => $agent->parent_a_model_version_id,
            'diff_count' => count((array) $agent->parameter_diff),
            'diff' => $agent->parameter_diff,
            'parameter_count' => count((array) $agent->modelVersion?->parameters),
            'runs' => LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->latest('id')->get()->map(fn ($run): array => [
                'id' => $run->id,
                'phase' => $run->phase,
                'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'error' => $run->error_message,
            ])->all(),
        ] : null;
    }
    $baseline = LabAgent::query()->with('modelVersion')->where('model_version_id', data_get($experiment->evidence, 'baseline_model_version_id'))->first();

    $baselineParameters = (array) $baseline?->modelVersion?->parameters;
    $controlParameters = (array) $agents->get($experiment->control_agent_id)?->modelVersion?->parameters;

    return [
        'id' => $experiment->id,
        'generation_id' => $experiment->lab_generation_id,
        'status' => $experiment->status,
        'gene' => $experiment->gene_key,
        'construction_validation' => data_get($experiment->evidence, 'construction_validation'),
        'repair_frontier' => data_get($experiment->evidence, 'repair_frontier'),
        'source_experiment_id' => data_get($experiment->evidence, 'source_causal_experiment_id'),
        'baseline_parameter_count' => count((array) $baseline?->modelVersion?->parameters),
        'control_added_keys' => array_values(array_diff(array_keys($controlParameters), array_keys($baselineParameters))),
        'control_removed_keys' => array_values(array_diff(array_keys($baselineParameters), array_keys($controlParameters))),
        'roles' => $roles,
    ];
})->all();

if (getenv('DIAG_MODE') === 'causal') {
    echo json_encode($causalExperiments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'frontier') {
    echo json_encode(
        app(\App\Services\CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1'),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ).PHP_EOL;
    exit(0);
}

if (getenv('DIAG_MODE') === 'causal_detail') {
    $experiment = AgentLearningCausalExperiment::query()->latest('id')->first();
    $pairIds = LabLearningLanePair::query()
        ->where('lab_generation_id', $experiment?->lab_generation_id)
        ->pluck('id');
    echo json_encode([
        'experiment' => $experiment ? [
            'id' => $experiment->id,
            'status' => $experiment->status,
            'generation_id' => $experiment->lab_generation_id,
            'guided_beats_control' => $experiment->guided_beats_control,
            'guided_beats_blinded' => $experiment->guided_beats_blinded,
            'independent_window_count' => $experiment->independent_window_count,
            'component_effect' => data_get($experiment->evidence, 'component_effect'),
            'selector_effect' => data_get($experiment->evidence, 'selector_effect'),
            'confirmation_blockers' => data_get($experiment->evidence, 'confirmation_blockers'),
            'absolute_viability' => data_get($experiment->evidence, 'absolute_viability'),
            'repair_frontier' => data_get($experiment->evidence, 'repair_frontier'),
            'outcomes' => data_get($experiment->evidence, 'outcomes'),
        ] : null,
        'pairs' => LabLearningLanePair::query()->whereIn('id', $pairIds)->get()->map(fn ($pair): array => [
            'id' => $pair->id,
            'candidate_agent_id' => $pair->candidate_agent_id,
            'control_agent_id' => $pair->control_agent_id,
            'status' => $pair->status,
            'integrity' => $pair->pair_integrity_status,
            'candidate_run_id' => $pair->candidate_evidence_run_id,
            'control_run_id' => $pair->control_evidence_run_id,
            'target_delta' => $pair->target_delta,
            'non_target_regression' => $pair->non_target_regression,
        ])->all(),
        'settlements' => AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->whereIn('source_id', $pairIds)->get()->map(fn ($row): array => [
                'id' => $row->id,
                'source_id' => $row->source_id,
                'evidence_state' => $row->evidence_state,
                'hard_failure' => $row->hard_failure,
                'failure_class' => $row->failure_class,
                'selection_reward' => $row->selection_reward,
                'vetoes' => data_get($row->reward_components, 'vetoes'),
                'reflection' => $row->reflection,
            ])->all(),
        'evolution_receipts' => EvolutionLearningReceipt::query()
            ->where('lab_generation_id', $experiment?->lab_generation_id)->get()->map(fn ($row): array => [
                'id' => $row->id,
                'agent_id' => $row->lab_agent_id,
                'component' => $row->component,
                'action' => $row->action,
                'status' => $row->status,
                'claim' => $row->claim,
                'causal_uplift_r' => $row->causal_uplift_r,
                'canonical_memory_eligible' => data_get($row->evidence, 'contract.canonical_memory_eligible'),
                'absolute_viability_failed' => data_get($row->evidence, 'input.absolute_viability_failed'),
            ])->all(),
        'mutation_intents' => AgentLearningMutationIntent::query()
            ->whereIn('lab_agent_id', array_filter([
                $experiment?->guided_agent_id, $experiment?->blinded_agent_id, $experiment?->control_agent_id,
            ]))->get()->map(fn ($row): array => [
                'id' => $row->id,
                'agent_id' => $row->lab_agent_id,
                'influence_type' => $row->influence_type,
                'parameter_key' => $row->parameter_key,
                'status' => $row->status,
                'settlement_status' => data_get($row->evidence, 'settlement.status'),
            ])->all(),
        'closed_loop_audit' => GenerationClosedLoopAudit::query()
            ->where('lab_generation_id', $experiment?->lab_generation_id)->first()?->toArray(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

echo json_encode([
    'now' => now()->toIso8601String(),
    'queue' => app(LabQueueJobInspector::class)->queueSnapshot(),
    'generations' => $generations,
    'mtf_runs' => $mtf,
    'evaluation_runs' => $runs,
    'causal_experiments' => $causalExperiments,
    'safety_pause' => $pause?->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
