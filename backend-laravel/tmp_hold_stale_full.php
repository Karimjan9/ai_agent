<?php

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$targetAgentIds = [560, 511, 614, 597, 605, 602, 554, 606];
$holdQueue = 'lab-full-hold';
$evidence = app(LabImmutableEvidenceService::class);
$result = DB::transaction(function () use ($targetAgentIds, $holdQueue, $evidence): array {
    $allFullJobs = DB::table('jobs')->where('queue', 'lab-full-validation')->lockForUpdate()->get(['id', 'queue', 'reserved_at', 'payload']);
    $jobs = $allFullJobs->filter(function ($job) use ($targetAgentIds): bool {
        $payload = json_decode((string) $job->payload, true);
        $decoded = @unserialize((string) data_get($payload, 'data.command', ''));
        return is_object($decoded) && in_array((int) ($decoded->labAgentId ?? 0), $targetAgentIds, true);
    })->values();
    $jobIds = $jobs->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $foundAgentIds = $jobs->map(function ($job): int {
        $payload = json_decode((string) $job->payload, true);
        $decoded = @unserialize((string) data_get($payload, 'data.command', ''));
        return is_object($decoded) ? (int) ($decoded->labAgentId ?? 0) : 0;
    })->filter()->unique()->values()->all();
    sort($foundAgentIds);
    $expectedAgentIds = $targetAgentIds;
    sort($expectedAgentIds);
    if ($foundAgentIds !== $expectedAgentIds) {
        throw new RuntimeException('Explicit stale full-job target set changed; no rows were modified.');
    }

    $agents = collect();
    foreach ($jobs as $job) {
        $payload = json_decode((string) $job->payload, true);
        $command = (string) data_get($payload, 'data.command', '');
        $decoded = @unserialize($command);
        $agentId = is_object($decoded) ? (int) ($decoded->labAgentId ?? 0) : 0;
        if ($agentId <= 0) throw new RuntimeException('Could not decode explicit full-job agent id for job '.$job->id.'.');
        $agents->put($agentId, LabAgent::query()->with('generation')->lockForUpdate()->findOrFail($agentId));
    }

    $activeRun = LabEvaluationRun::query()->where('run_id', '5edd88ed-8884-4ebd-899a-0ab8daa9a54d')->lockForUpdate()->first();
    $technicalAgent = $agents->get(560);
    if (! $activeRun || ! $technicalAgent) {
        throw new RuntimeException('Expected A560 stale full run/agent is missing; no rows were modified.');
    }
    if ($activeRun->status === 'started') {
        $evidence->finishIfOpen($activeRun, 'technical_error', null, [], [
            'reason_code' => 'OPERATOR_STOP_STALE_FULL_REPLAY',
            'quality_verdict' => 'withheld',
            'promotion_evidence' => false,
            'queue_hold' => $holdQueue,
        ], new RuntimeException('Operator stopped stale full replay before the updated admission guard was loaded.'));
    }
    if ($technicalAgent->lifecycle_status !== 'technical_quarantine') {
        $technicalAgent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine: stale full replay stopped before updated screening-pass admission guard; no strategy verdict recorded.',
        ]);
        $evidence->recordLifecycle($technicalAgent->fresh(), 'stale_full_replay_quarantined', [
            'reason_code' => 'OPERATOR_STOP_STALE_FULL_REPLAY',
            'quality_verdict' => 'withheld',
            'promotion_evidence' => false,
            'queue_hold' => $holdQueue,
        ], 'full_validation', $activeRun->run_id, $activeRun->attempt, 'tmp_hold_stale_full', null, null, 'technical_quarantine');
    }

    $held = [];
    foreach ($agents as $agent) {
        if ($agent->id === 560) continue;
        $screening = CandidateGateDecision::query()
            ->where('lab_agent_id', $agent->id)
            ->where('stage', 'screening')
            ->latest('evaluated_at')->first();
        if (! $screening || $screening->decision !== 'failed') {
            throw new RuntimeException('Full-job agent '.$agent->id.' lacks an immutable failed screening decision; no rows were modified.');
        }
        $from = (string) $agent->lifecycle_status;
        $agent->update([
            'lifecycle_status' => 'screened',
            'decision_reason' => 'Full replay held: screening gate failed; no full-validation or promotion evidence created.',
        ]);
        $evidence->recordLifecycle($agent->fresh(), 'stale_full_replay_held', [
            'reason_code' => 'SCREENING_GATE_FAILED_FULL_REPLAY_HELD',
            'screening_reason_codes' => $screening->reason_codes,
            'quality_verdict' => 'withheld',
            'promotion_evidence' => false,
            'queue_hold' => $holdQueue,
        ], 'full_validation', null, null, 'tmp_hold_stale_full', null, $from, 'screened');
        $held[] = $agent->id;
    }

    DB::table('jobs')->whereIn('id', $jobIds)->update([
        'queue' => $holdQueue,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
    ]);

    return [
        'held_job_ids' => $jobIds,
        'held_agent_ids' => $held,
        'technical_quarantine_agent_id' => 560,
        'active_run_status' => $activeRun->fresh()->status,
        'hold_queue' => $holdQueue,
    ];
});

echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
