<?php

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\ReplayLivenessProbeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$agentIds = [2012, 2013];
$generationId = 186;
$liveness = app(ReplayLivenessProbeService::class)->probe();
$backlog = app(LabQueueJobInspector::class)->labQueueBacklog();

if (($liveness['status'] ?? null) !== 'ok') {
    throw new RuntimeException('Recovery refused: replay lane is not proven idle.');
}
if (($backlog['total'] ?? null) !== 0) {
    throw new RuntimeException('Recovery refused: canonical lab queue is not empty.');
}

$agents = LabAgent::query()->with(['modelVersion', 'generation'])->whereIn('id', $agentIds)->get()->keyBy('id');
if ($agents->count() !== count($agentIds)) {
    throw new RuntimeException('Recovery refused: exact G144 agent scope is incomplete.');
}

foreach ($agentIds as $agentId) {
    $agent = $agents->get($agentId);
    $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $agentId)->first();
    if ((int) $agent->lab_generation_id !== $generationId
        || ! $trial
        || (string) $trial->stage !== 'nine_fold_authority'
        || (string) $trial->status !== 'queued'
        || app(LabQueueJobInspector::class)->hasAgentJob($agentId, ['lab-full-validation'])) {
        throw new RuntimeException('Recovery refused: G144 authority ownership changed for agent '.$agentId.'.');
    }
}

$openRun = LabEvaluationRun::query()->where('id', 5095)->where('lab_agent_id', 2012)->firstOrFail();
if ((string) $openRun->status !== 'started' || ! $openRun->started_at || $openRun->started_at->gt(now()->subMinutes(80))) {
    throw new RuntimeException('Recovery refused: G144 control run is not a proven stale open attempt.');
}
$partialRunAfterAuthorityQueue = LabEvaluationRun::query()->where('lab_agent_id', 2013)
    ->where('phase', 'full_validation')->where('started_at', '>=', DB::table('edge_genesis_trials')->where('lab_agent_id', 2013)->value('updated_at'))
    ->exists();
if ($partialRunAfterAuthorityQueue) {
    throw new RuntimeException('Recovery refused: partial-harvest authority already opened an evaluator run.');
}

$evidence = app(LabImmutableEvidenceService::class);
DB::transaction(function () use ($agentIds, $agents, $generationId, $openRun, $evidence): void {
    $evidence->finishIfOpen($openRun, 'retry_released', null, [], [
        'reason_code' => 'PM2_REDIS_RUNTIME_INTERRUPTION',
        'recovery_protocol' => 'g144_exact_authority_runtime_recovery_v1',
        'prior_partial_result_authority' => false,
        'promotion_evidence' => false,
    ]);

    foreach ($agentIds as $agentId) {
        $agent = $agents->get($agentId);
        $from = (string) $agent->lifecycle_status;
        $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $agentId)->lockForUpdate()->first();
        $trialEvidence = (array) json_decode((string) $trial->evidence, true);
        $trialEvidence['runtime_recovery'] = [
            'protocol' => 'g144_exact_authority_runtime_recovery_v1',
            'reason_code' => 'PM2_REDIS_RUNTIME_INTERRUPTION',
            'parameters_unchanged' => true,
            'dataset_execution_mtf_hashes_unchanged' => true,
            'prior_partial_result_authority' => false,
            'requeued_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
            'status' => 'queued',
            'settled_at' => null,
            'evidence' => json_encode($trialEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'updated_at' => now(),
        ]);
        $agent->update([
            'lifecycle_status' => 'full_queued',
            'decision_reason' => 'Nine-fold Edge authority replay restored after proven PM2/Redis interruption; prior incomplete attempt has no authority.',
        ]);
        $evidence->recordLifecycle($agent->fresh(), 'edge_authority_runtime_requeued', [
            'protocol' => 'g144_exact_authority_runtime_recovery_v1',
            'reason_code' => 'PM2_REDIS_RUNTIME_INTERRUPTION',
            'parameters_unchanged' => true,
            'passport_hashes_unchanged' => true,
            'prior_partial_result_authority' => false,
            'promotion_evidence' => false,
        ], 'full_validation', null, null, basename(__FILE__), null, $from, 'full_queued');
    }

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
    'protocol' => 'g144_exact_authority_runtime_recovery_v1',
    'status' => 'queued',
    'generation_id' => $generationId,
    'agent_ids' => $agentIds,
    'jobs_dispatched' => count($agentIds),
    'prior_partial_result_authority' => false,
    'parameters_unchanged' => true,
    'promotion_evidence' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
