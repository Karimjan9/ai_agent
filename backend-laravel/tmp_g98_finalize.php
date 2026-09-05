<?php

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\CanonicalLearningOutbox;
use App\Models\EvolutionLearningReceipt;
use App\Models\LabLearningLanePair;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\MutationMemory;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\SystemEvent;
use App\Services\CanonicalLearningOutboxService;
use App\Services\LearningProtocolSafetyService;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$reprojection = [];
$cacheRetirement = null;
$projectionCompaction = null;
$researchDisposition = null;
if (in_array('--retire-guided-cache', $argv, true)) {
    $cacheRetirement = Illuminate\Support\Facades\DB::transaction(function (): array {
        $model = ModelVersion::query()->lockForUpdate()->findOrFail(1864);
        $pair = LabLearningLanePair::query()->lockForUpdate()->findOrFail(1086);
        $outbox = CanonicalLearningOutbox::query()->lockForUpdate()->findOrFail(7);
        $run = LabEvaluationRun::query()->lockForUpdate()->findOrFail(4761);
        $metadata = (array) $model->metadata;
        $cache = (array) data_get($metadata, 'full_validation_batch', []);
        $runId = (string) $run->run_id;
        $cacheRunId = (string) data_get($cache, 'item.result.evidence_run_id', '');
        if ((string) data_get($cache, 'protocol') !== 'sealed_replay_cache_v2'
            || ($cacheRunId !== '' && $cacheRunId !== $runId)
            || (string) data_get($outbox->payload, 'result.evidence_run_id') !== $runId
            || (string) $run->status !== 'completed'
            || (string) $pair->status !== 'canonical_episode_settled'
            || (string) $outbox->status !== 'completed'
            || (int) $outbox->attempts !== 1
            || ! hash_equals(
                (string) data_get($cache, 'item.result.trade_ledger_hash', ''),
                (string) data_get($outbox->payload, 'result.trade_ledger_hash', ''),
            )) {
            throw new RuntimeException('G98 guided cache authority changed; retirement aborted.');
        }
        $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $bytes = strlen((string) $encoded);
        $hash = hash('sha256', (string) $encoded);
        unset($metadata['full_validation_batch']);
        $metadata['causal_replay_cache_retirement'] = [
            'protocol' => 'terminal_causal_cache_retirement_v1',
            'reason' => 'Terminal G98 replay is immutable in evaluation run and canonical outbox; remove duplicate model cache payload.',
            'retired_cache_protocol' => 'sealed_replay_cache_v2',
            'retired_cache_sha256' => $hash,
            'retired_cache_bytes' => $bytes,
            'evaluation_run_id' => $run->id,
            'evidence_run_id' => $runId,
            'canonical_outbox_id' => $outbox->id,
            'canonical_pair_id' => $pair->id,
            'exactly_once_settlement_preserved' => true,
            'promotion_evidence' => false,
            'retired_at' => now()->utc()->toIso8601String(),
        ];
        $model->update(['metadata' => $metadata]);

        return ['model_id' => $model->id, 'retired_bytes' => $bytes, 'retired_sha256' => $hash];
    });
}
if (in_array('--seal-research-only-status', $argv, true)) {
    $researchDisposition = Illuminate\Support\Facades\DB::transaction(function (): array {
        $generation = LabGeneration::query()->with('agents')->lockForUpdate()->findOrFail(139);
        $experiment = AgentLearningCausalExperiment::query()->lockForUpdate()->findOrFail(3);
        $agentIds = $generation->agents->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $blockers = array_values((array) data_get($experiment->evidence, 'confirmation_blockers', []));
        sort($blockers);
        $expectedBlockers = ['GUIDED_ABSOLUTE_VIABILITY_FAILED', 'NON_TARGET_REGRESSION_UNSAFE'];
        sort($expectedBlockers);
        $performances = ModelMarketPerformance::query()->whereIn('model_version_id', [1864, 1865, 1866])->lockForUpdate()->get();
        if ((string) $generation->trigger_type !== 'learning_confirmation'
            || (string) $generation->status !== 'completed'
            || $agentIds !== [1827, 1828, 1829]
            || (string) $experiment->status !== 'provisional'
            || $blockers !== $expectedBlockers
            || $performances->count() !== 3
            || $performances->contains(fn (ModelMarketPerformance $row): bool => filled($row->champion_slot))
            || MutationMemory::query()->whereIn('lab_agent_id', $agentIds)->exists()
            || EvolutionLearningReceipt::query()->where('lab_generation_id', $generation->id)->where('status', 'confirmed')->exists()) {
            throw new RuntimeException('G98 research-only authority changed; status seal aborted.');
        }
        foreach ($performances as $performance) {
            $metrics = (array) $performance->metrics;
            $metrics['causal_research_disposition'] = [
                'protocol' => 'causal_research_only_disposition_v1',
                'status' => 'research_observed_promotion_rejected',
                'experiment_id' => $experiment->id,
                'confirmation_blockers' => $blockers,
                'parent_eligible' => false,
                'paper_eligible' => false,
                'champion_eligible' => false,
                'promotion_evidence' => false,
            ];
            $performance->update(['status' => 'rejected', 'champion_slot' => null, 'metrics' => $metrics]);
        }
        foreach ($generation->agents as $agent) {
            $agent->update([
                'lifecycle_status' => 'rejected',
                'decision_reason' => 'Research-only G98 causal observation settled; promotion/parent authority rejected because absolute viability and non-target safety failed.',
            ]);
        }

        return ['generation_id' => $generation->id, 'agent_ids' => $agentIds, 'performance_ids' => $performances->pluck('id')->all()];
    });
}
if (in_array('--compact-guided-projection', $argv, true)) {
    $projectionCompaction = Illuminate\Support\Facades\DB::transaction(function (): array {
        $model = ModelVersion::query()->lockForUpdate()->findOrFail(1864);
        $pair = LabLearningLanePair::query()->lockForUpdate()->findOrFail(1086);
        $outbox = CanonicalLearningOutbox::query()->lockForUpdate()->findOrFail(7);
        $run = LabEvaluationRun::query()->lockForUpdate()->findOrFail(4761);
        $metadata = (array) $model->metadata;
        $result = (array) data_get($metadata, 'last_result', []);
        $walk = (array) data_get($result, 'walk_forward', []);
        $windows = (array) data_get($walk, 'windows', []);
        $protocol = (array) data_get($walk, 'forward_window_protocol', []);
        if ((string) data_get($result, 'evidence_run_id') !== (string) $run->run_id
            || count($windows) !== 9
            || (int) data_get($protocol, 'observed_windows', 0) !== 9
            || (string) $run->status !== 'completed'
            || (string) $pair->status !== 'canonical_episode_settled'
            || (string) $outbox->status !== 'completed'
            || (int) $outbox->attempts !== 1) {
            throw new RuntimeException('G98 guided projection authority changed; compaction aborted.');
        }
        $encoded = json_encode($windows, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $bytes = strlen((string) $encoded);
        $hash = hash('sha256', (string) $encoded);
        $result['walk_forward'] = array_filter([
            'mode' => data_get($walk, 'mode'),
            'forward_window_protocol' => $protocol,
            'final_holdout' => data_get($walk, 'final_holdout'),
            'window_details_retired' => [
                'protocol' => 'terminal_causal_window_projection_compaction_v1',
                'window_count' => count($windows),
                'sha256' => $hash,
                'bytes' => $bytes,
                'authoritative_evaluation_run_id' => $run->id,
                'authoritative_evidence_run_id' => $run->run_id,
                'promotion_evidence' => false,
            ],
        ], fn (mixed $value): bool => $value !== null);
        $metadata['last_result'] = $result;
        $metadata['causal_projection_compaction'] = [
            'protocol' => 'terminal_causal_projection_compaction_v1',
            'retired_window_sha256' => $hash,
            'retired_window_bytes' => $bytes,
            'window_count' => count($windows),
            'evaluation_run_id' => $run->id,
            'canonical_outbox_id' => $outbox->id,
            'canonical_pair_id' => $pair->id,
            'promotion_evidence' => false,
            'compacted_at' => now()->utc()->toIso8601String(),
        ];
        $model->update(['metadata' => $metadata]);

        return ['model_id' => $model->id, 'retired_window_bytes' => $bytes, 'retired_window_sha256' => $hash];
    });
}
if (in_array('--reproject', $argv, true)) {
    foreach ([7 => 1086, 8 => 1087] as $outboxId => $pairId) {
        $row = CanonicalLearningOutbox::query()->findOrFail($outboxId);
        if ((int) $row->pair_id !== $pairId || (string) $row->status !== 'completed' || (int) $row->attempts !== 1) {
            throw new RuntimeException("G98 outbox {$outboxId} authority changed; reprojection aborted.");
        }
        $reprojection[] = app(CanonicalLearningOutboxService::class)->reproject($row);
    }
}

$pairs = LabLearningLanePair::query()
    ->with('candidateResponseMap')
    ->whereIn('id', [1086, 1087])
    ->get()
    ->map(fn (LabLearningLanePair $pair): array => [
        'id' => $pair->id,
        'status' => $pair->status,
        'gene' => $pair->candidateResponseMap?->parameter_key,
        'failure_signature' => $pair->failure_signature,
        'target_delta' => $pair->target_delta,
        'non_target_regression' => $pair->non_target_regression,
    ]);

$outboxes = CanonicalLearningOutbox::query()->whereIn('id', [7, 8])->get()->map(fn (CanonicalLearningOutbox $row): array => [
    ...$row->only(['id', 'pair_id', 'status', 'attempts', 'evidence_run_id', 'processed_at']),
    'payload_evidence_run_id' => data_get($row->payload, 'result.evidence_run_id'),
    'payload_trade_ledger_hash' => data_get($row->payload, 'result.trade_ledger_hash'),
]);
$payloadShapes = CanonicalLearningOutbox::query()->whereIn('id', [7, 8])->get()->map(function (CanonicalLearningOutbox $row): array {
    $result = (array) data_get($row->payload, 'result', []);
    return [
        'id' => $row->id,
        'keys' => array_keys($result),
        'selected' => [
            'total_trades' => data_get($result, 'total_trades'),
            'profit_factor' => data_get($result, 'profit_factor'),
            'net_profit_percent' => data_get($result, 'net_profit_percent'),
            'max_drawdown_percent' => data_get($result, 'max_drawdown_percent'),
            'robustness_score' => data_get($result, 'robustness_score'),
            'fitness_score' => data_get($result, 'fitness_score'),
            'fitness_breakdown' => data_get($result, 'fitness_breakdown'),
            'selection_validation' => data_get($result, 'selection_validation'),
            'confidence_calibration' => data_get($result, 'confidence_calibration'),
            'temporal_survival' => data_get($result, 'temporal_survival'),
            'opportunity_metrics' => data_get($result, 'opportunity_metrics'),
            'entry_funnel' => data_get($result, 'entry_funnel'),
            'statistical_evidence_keys' => array_keys((array) data_get($result, 'statistical_evidence', [])),
            'statistical_edge_quality' => data_get($result, 'statistical_evidence.edge_quality'),
            'deflated_sharpe' => data_get($result, 'statistical_evidence.deflated_sharpe'),
            'pf_attribution' => data_get($result, 'pf_attribution'),
            'window_survival' => data_get($result, 'window_survival'),
            'certified_coverage_passport' => data_get($result, 'certified_coverage_passport'),
            'opportunity_recall' => data_get($result, 'opportunity_recall'),
            'forward_protocol' => data_get($result, 'walk_forward.forward_window_protocol'),
        ],
    ];
});
$settlements = AgentLearningSettlement::query()
    ->where('source_type', LabLearningLanePair::class)
    ->whereIn('source_id', [1086, 1087])
    ->get()
    ->map->only(['id', 'source_id', 'evidence_state', 'hard_failure', 'reward_components']);
$receipts = EvolutionLearningReceipt::query()
    ->whereIn('lab_agent_id', [1827, 1829])
    ->get()
    ->map(fn (EvolutionLearningReceipt $receipt): array => [
        'id' => $receipt->id,
        'agent_id' => $receipt->lab_agent_id,
        'claim_key' => $receipt->claim_key,
        'component' => $receipt->component,
        'action' => $receipt->action,
        'status' => $receipt->status,
        'scope' => $receipt->scope,
        'input_gene' => data_get($receipt->evidence, 'input.parameter_key'),
        'observed_windows' => data_get($receipt->evidence, 'input.observed_independent_windows'),
        'observed_positive_windows' => data_get($receipt->evidence, 'input.observed_positive_windows'),
        'authoritative_windows' => data_get($receipt->evidence, 'input.independent_windows'),
        'absolute_viability_failed' => data_get($receipt->evidence, 'input.absolute_viability_failed'),
        'component_credit' => data_get($receipt->evidence, 'input.component_credit'),
        'accounting_protocol' => data_get($receipt->evidence, 'input.causal_edge_accounting.protocol'),
    ]);
$experiment = AgentLearningCausalExperiment::query()->find(3);
$generation = LabGeneration::query()->with('agents')->find(139);
$models = ModelVersion::query()->whereIn('id', [1864, 1865, 1866])->get()->map(fn (ModelVersion $model): array => [
    'id' => $model->id,
    'metadata_bytes' => strlen(json_encode((array) $model->metadata, JSON_UNESCAPED_SLASHES)),
    'metadata_keys' => array_keys((array) $model->metadata),
    'has_full_validation_batch' => array_key_exists('full_validation_batch', (array) $model->metadata),
    'cache_run_id' => data_get($model->metadata, 'full_validation_batch.item.result.evidence_run_id'),
    'cache_trade_ledger_hash' => data_get($model->metadata, 'full_validation_batch.item.result.trade_ledger_hash'),
    'cache_protocol' => data_get($model->metadata, 'full_validation_batch.protocol'),
    'cache_keys' => array_keys((array) data_get($model->metadata, 'full_validation_batch', [])),
    'cache_item_keys' => array_keys((array) data_get($model->metadata, 'full_validation_batch.item', [])),
    'largest_metadata_keys' => collect((array) $model->metadata)
        ->map(fn (mixed $value, string $key): array => ['key' => $key, 'bytes' => strlen(json_encode($value, JSON_UNESCAPED_SLASHES))])
        ->sortByDesc('bytes')->take(10)->values()->all(),
]);
$runs = LabEvaluationRun::query()->whereIn('id', [4761, 4764, 4765])->get()->map(fn (LabEvaluationRun $run): array => [
    'id' => $run->id,
    'run_id' => $run->run_id,
    'agent_id' => $run->lab_agent_id,
    'status' => $run->status,
    'phase' => $run->phase,
    'metrics_bytes' => strlen(json_encode((array) $run->metrics, JSON_UNESCAPED_SLASHES)),
]);
$queueCounts = Illuminate\Support\Facades\DB::table('jobs')
    ->selectRaw('queue, COUNT(*) as aggregate')->groupBy('queue')->pluck('aggregate', 'queue');
$safetyEvent = SystemEvent::query()->where('event_key', 'learning_protocol:generation_creation_paused')->first();
$safety = app(LearningProtocolSafetyService::class);

echo json_encode([
    'reprojection' => $reprojection,
    'cache_retirement' => $cacheRetirement,
    'projection_compaction' => $projectionCompaction,
    'research_disposition' => $researchDisposition,
    'pairs' => $pairs,
    'outboxes' => $outboxes,
    'payload_shapes' => $payloadShapes,
    'settlements' => $settlements,
    'receipts' => $receipts,
    'experiment' => $experiment ? [
        ...$experiment->only(['id', 'status', 'independent_window_count', 'guided_beats_blinded', 'guided_beats_control']),
        'evidence' => $experiment->evidence,
    ] : null,
    'generation' => $generation ? [
        ...$generation->only(['id', 'generation', 'trigger_type', 'status', 'population_size', 'completed_at']),
        'agents' => $generation->agents->map->only([
            'id', 'model_version_id', 'origin', 'lifecycle_status', 'parent_a_model_version_id',
            'parent_b_model_version_id', 'profit_factor', 'max_drawdown', 'risk_of_ruin', 'decision_reason',
        ]),
        'ordinary_mutation_memory_count' => MutationMemory::query()->whereIn('lab_agent_id', $generation->agents->pluck('id'))->count(),
        'receipt_status_counts' => EvolutionLearningReceipt::query()->where('lab_generation_id', $generation->id)
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
    ] : null,
    'runtime_database' => [
        'queue_counts' => $queueCounts,
        'failed_jobs' => Illuminate\Support\Facades\DB::table('failed_jobs')->count(),
        'generation_creation_paused' => $safety->generationCreationPaused(),
        'pause_payload' => $safetyEvent?->payload,
        'resume_readiness' => $safety->resumeReadiness(),
    ],
    'models' => $models,
    'runs' => $runs,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
