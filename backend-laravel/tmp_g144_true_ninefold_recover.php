<?php

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\ReplayLivenessProbeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$generationId = 186;
$agentIds = [2012, 2013, 2016];
$expectedPriorRuns = [2012 => 5098, 2013 => 5099, 2016 => 5096];
$expectedPriorFolds = [2012 => 9, 2013 => 2, 2016 => 2];
$liveness = app(ReplayLivenessProbeService::class)->probe();
$queue = app(LabQueueJobInspector::class);

if (($liveness['status'] ?? null) !== 'ok' || ($queue->labQueueBacklog()['total'] ?? null) !== 0) {
    throw new RuntimeException('True nine-fold recovery refused: replay lane or queue is not idle.');
}

$agents = LabAgent::query()->with(['modelVersion', 'generation'])->whereIn('id', $agentIds)->get()->keyBy('id');
if ($agents->count() !== count($agentIds)) {
    throw new RuntimeException('True nine-fold recovery refused: G144 scope is incomplete.');
}

foreach ($expectedPriorRuns as $agentId => $runId) {
    $agent = $agents->get($agentId);
    $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $agentId)->first();
    $run = DB::table('lab_evaluation_runs')->where('id', $runId)->where('lab_agent_id', $agentId)->first();
    $request = (array) data_get(json_decode((string) $run?->request_meta, true), 'payload.policy_context.edge_genesis_contracts', []);
    $observedFolds = collect($request)->pluck('fold_count')->filter()->unique()->values()->all();
    if ((int) $agent->lab_generation_id !== $generationId
        || ! $trial
        || (string) $trial->stage !== 'nine_fold_authority'
        || (string) $trial->status !== 'edge_not_confirmed'
        || ! $run
        || (string) $run->status !== 'completed'
        || $observedFolds !== [$expectedPriorFolds[$agentId]]
        || $queue->hasAgentJob($agentId, ['lab-full-validation'])) {
        throw new RuntimeException('True nine-fold recovery refused: immutable prior state changed for agent '.$agentId.'.');
    }
}

$newCodeHash = app(LabImmutableEvidenceService::class)->codeHash();
$priorCodeHashes = DB::table('lab_evaluation_runs')->whereIn('id', array_values($expectedPriorRuns))
    ->pluck('code_hash')->filter()->unique()->values();
if ($priorCodeHashes->count() !== 1 || hash_equals((string) $priorCodeHashes->first(), $newCodeHash)) {
    throw new RuntimeException('True nine-fold recovery refused: repaired runtime fingerprint was not isolated.');
}

$evidence = app(LabImmutableEvidenceService::class);
DB::transaction(function () use ($agents, $agentIds, $generationId, $expectedPriorRuns, $expectedPriorFolds, $newCodeHash, $evidence): void {
    foreach ($agentIds as $agentId) {
        $agent = $agents->get($agentId);
        $from = (string) $agent->lifecycle_status;
        $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $agentId)->lockForUpdate()->first();
        $trialEvidence = (array) json_decode((string) $trial->evidence, true);
        $trialEvidence['authority_dispatch'] = [
            'protocol' => 'edge_nine_fold_authority_dispatch_v1',
            'requested_folds' => 9,
            'discovery_result_authority' => false,
            'promotion_evidence' => false,
        ];
        $trialEvidence['authority_phase_reconciliation'] = [
            'protocol' => 'edge_authority_phase_reconciliation_v1',
            'prior_run_id' => $expectedPriorRuns[$agentId],
            'prior_requested_folds' => $expectedPriorFolds[$agentId],
            'prior_result_authority' => false,
            'reason_code' => $expectedPriorFolds[$agentId] === 9
                ? 'COHORT_CODE_HASH_PARITY_REPLAY_REQUIRED'
                : 'MODEL_PHASE_LEFT_AT_EDGE_DISCOVERY',
            'required_phase' => 'EDGE_CONFIRMATION',
            'required_folds' => 9,
            'new_code_hash' => $newCodeHash,
            'parameters_unchanged' => true,
            'data_execution_mtf_hashes_unchanged' => true,
            'promotion_evidence' => false,
        ];
        DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
            'stage' => 'nine_fold_authority',
            'status' => 'queued',
            'settled_at' => null,
            'evidence' => json_encode($trialEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'updated_at' => now(),
        ]);

        $metadata = (array) $agent->modelVersion->metadata;
        data_set($metadata, 'edge_genesis.phase', 'EDGE_CONFIRMATION');
        data_set($metadata, 'edge_genesis.authority_contract', [
            'protocol' => 'edge_nine_fold_authority_dispatch_v1',
            'requested_folds' => 9,
            'discovery_result_authority' => false,
            'risk_governor_frozen' => true,
            'promotion_evidence' => false,
        ]);
        data_set($metadata, 'edge_authority_phase_reconciliation', $trialEvidence['authority_phase_reconciliation']);
        unset($metadata['full_validation_batch']);
        $agent->modelVersion->update(['metadata' => $metadata]);

        $latestPerformance = $agent->modelVersion->marketPerformances()->latest('id')->lockForUpdate()->first();
        if ($latestPerformance) {
            $latestPerformance->update([
                'evidence_status' => 'stale_quarantine',
                'invalidated_at' => now(),
                'invalidation_reason' => 'EDGE_NINE_FOLD_AUTHORITY_PHASE_OR_CODE_PARITY_REPLAY_REQUIRED',
            ]);
        }
        $agent->update([
            'lifecycle_status' => 'full_queued',
            'decision_reason' => 'True nine-fold Edge authority queued after phase/fold reconciliation; prior result is audit-only.',
        ]);
        $evidence->recordLifecycle($agent->fresh(), 'edge_authority_phase_reconciled', [
            ...$trialEvidence['authority_phase_reconciliation'],
            'quality_verdict' => 'withheld',
        ], 'full_validation', null, null, basename(__FILE__), null, $from, 'full_queued');
    }

    $passportIds = DB::table('edge_genesis_trials')->whereIn('lab_agent_id', $agentIds)
        ->pluck('edge_genesis_passport_id')->unique();
    DB::table('edge_genesis_passports')->whereIn('id', $passportIds)->update([
        'phase' => 'EDGE_CONFIRMATION',
        'status' => 'running',
        'phase_changed_at' => now(),
        'updated_at' => now(),
    ]);
    LabGeneration::query()->where('id', $generationId)->update([
        'status' => 'queued',
        'completed_at' => null,
        'updated_at' => now(),
    ]);
});

foreach ($agentIds as $agentId) {
    EvaluateLabAgentJob::dispatch($agentId, 'XAUUSD', 'full');
}

echo json_encode([
    'protocol' => 'edge_authority_phase_reconciliation_v1',
    'status' => 'queued',
    'generation_id' => $generationId,
    'agent_ids' => $agentIds,
    'requested_folds' => 9,
    'new_code_hash' => $newCodeHash,
    'parameters_unchanged' => true,
    'prior_result_authority' => false,
    'promotion_evidence' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
