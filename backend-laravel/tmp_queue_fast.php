<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$jobs = Illuminate\Support\Facades\DB::table('jobs')
    ->whereIn('queue', ['lab-screening', 'lab-frontier', 'lab-full-validation'])
    ->select('id', 'queue', 'reserved_at', 'available_at', 'created_at', 'payload')
    ->get();
$agents = Illuminate\Support\Facades\DB::table('lab_agents')
    ->where('lab_generation_id', 45)
    ->select('id', 'lifecycle_status', 'sample_count', 'profit_factor', 'max_drawdown', 'decision_reason')
    ->get();
$runs = Illuminate\Support\Facades\DB::table('lab_evaluation_runs')
    ->where('lab_generation_id', 45)
    ->where('status', 'started')
    ->select('lab_agent_id', 'started_at')
    ->get();
$jobDetails = $jobs->where('queue', 'lab-full-validation')->values()->map(function ($job) {
    $payload = json_decode((string) $job->payload, true);
    $command = data_get($payload, 'data.command');
    $decoded = is_string($command) ? @unserialize($command) : null;
    return ['id' => $job->id, 'reserved_at' => $job->reserved_at, 'display' => data_get($payload, 'displayName'), 'command' => data_get($payload, 'data.commandName'), 'agent_id' => is_object($decoded) ? ($decoded->labAgentId ?? null) : null, 'mode' => is_object($decoded) ? ($decoded->mode ?? null) : null, 'symbol' => is_object($decoded) ? ($decoded->symbol ?? null) : null];
});
$allJobDetails = $jobs->values()->map(function ($job) {
    $payload = json_decode((string) $job->payload, true);
    $command = data_get($payload, 'data.command');
    $decoded = is_string($command) ? @unserialize($command) : null;
    return ['id' => $job->id, 'queue' => $job->queue, 'reserved_at' => $job->reserved_at, 'available_at' => $job->available_at, 'agent_id' => is_object($decoded) ? ($decoded->labAgentId ?? null) : null, 'mode' => is_object($decoded) ? ($decoded->mode ?? null) : null, 'symbol' => is_object($decoded) ? ($decoded->symbol ?? null) : null];
});
$gates = Illuminate\Support\Facades\DB::table('lab_gate_decision_events')
    ->where('lab_generation_id', 45)
    ->where('stage', 'screening')
    ->orderBy('id')
    ->get(['lab_agent_id', 'decision', 'reason_codes'])
    ->groupBy('lab_agent_id')
    ->map(fn ($items) => $items->last());
$fullJobAgents = $jobDetails->pluck('agent_id')->filter()->values();
$fullJobAgentRows = Illuminate\Support\Facades\DB::table('lab_agents')
    ->whereIn('id', $fullJobAgents->all())
    ->select('id', 'lifecycle_status', 'lab_generation_id', 'decision_reason')
    ->get()
    ->keyBy('id');
$fullJobActiveRuns = Illuminate\Support\Facades\DB::table('lab_evaluation_runs')
    ->whereIn('lab_agent_id', $fullJobAgents->all())
    ->where('status', 'started')
    ->select('lab_agent_id', 'lab_generation_id', 'phase', 'run_id', 'started_at')
    ->get();
$fullJobAgentDetail = Illuminate\Support\Facades\DB::table('lab_agents as a')
    ->leftJoin('lab_generations as g', 'g.id', '=', 'a.lab_generation_id')
    ->whereIn('a.id', $fullJobAgents->all())
    ->select('a.id', 'a.lab_generation_id', 'g.generation', 'a.lifecycle_status', 'a.decision_reason')
    ->get();
$fullJobDecisions = Illuminate\Support\Facades\DB::table('candidate_gate_decisions')
    ->whereIn('lab_agent_id', $fullJobAgents->all())
    ->orderBy('id')
    ->get(['lab_agent_id', 'stage', 'decision', 'reason_codes', 'evaluated_at'])
    ->groupBy('lab_agent_id')
    ->map(fn ($rows) => $rows->values());
$fullJobRuns = Illuminate\Support\Facades\DB::table('lab_evaluation_runs')
    ->whereIn('lab_agent_id', $fullJobAgents->all())
    ->orderBy('id')
    ->get(['lab_agent_id', 'phase', 'status', 'run_id', 'started_at', 'finished_at', 'error_message'])
    ->groupBy('lab_agent_id')
    ->map(fn ($rows) => $rows->values());
$tailAgentRows = Illuminate\Support\Facades\DB::table('lab_agents')
    ->whereIn('id', [621, 622, 623, 624])
    ->select('id', 'lab_generation_id', 'lifecycle_status', 'decision_reason', 'sample_count', 'profit_factor')
    ->get();
$tailRuns = Illuminate\Support\Facades\DB::table('lab_evaluation_runs')
    ->whereIn('lab_agent_id', [621, 622, 623, 624])
    ->orderBy('id')
    ->get(['lab_agent_id', 'phase', 'status', 'run_id', 'started_at', 'finished_at', 'duration_ms', 'error_class', 'error_message'])
    ->groupBy('lab_agent_id')
    ->map(fn ($rows) => $rows->values());
$reasonCounts = collect();
$gateCounts = collect();
foreach ($agents->where('lifecycle_status', 'screened') as $agent) {
    $gate = $gates->get($agent->id);
    $gateCounts->put((string) optional($gate)->decision, (int) $gateCounts->get((string) optional($gate)->decision, 0) + 1);
    foreach ((array) json_decode((string) optional($gate)->reason_codes, true) as $reason) {
        $reasonCounts->put($reason, (int) $reasonCounts->get($reason, 0) + 1);
    }
}
echo json_encode([
    'jobs' => ['total' => $jobs->count(), 'reserved' => $jobs->whereNotNull('reserved_at')->count(), 'queued' => $jobs->whereNull('reserved_at')->count(), 'rows' => $jobs->map(fn ($job) => ['id' => $job->id, 'queue' => $job->queue, 'reserved_at' => $job->reserved_at, 'available_at' => $job->available_at, 'created_at' => $job->created_at]), 'details' => $allJobDetails, 'by_queue' => $jobs->groupBy('queue')->map->count(), 'full_details' => $jobDetails->map(fn ($job) => [...$job, 'agent_status' => optional($fullJobAgentRows->get($job['agent_id']))->lifecycle_status, 'agent_reason' => optional($fullJobAgentRows->get($job['agent_id']))->decision_reason, 'screening_gate' => optional($gates->get($job['agent_id']))->decision, 'screening_reasons' => json_decode((string) optional($gates->get($job['agent_id']))->reason_codes, true)])],
    'agents' => $agents->groupBy('lifecycle_status')->map->count(),
    'active_runs' => $runs,
    'full_job_active_runs' => $fullJobActiveRuns,
    'full_job_agent_detail' => $fullJobAgentDetail,
    'full_job_decisions' => $fullJobDecisions,
    'full_job_runs' => $fullJobRuns,
    'tail_agents' => $tailAgentRows,
    'tail_runs' => $tailRuns,
    'full_candidates' => $agents->where('lifecycle_status', 'full_queued')->values()->map(fn ($agent) => ['id' => $agent->id, 'sample_count' => $agent->sample_count, 'pf' => $agent->profit_factor, 'max_drawdown' => $agent->max_drawdown, 'gate' => optional($gates->get($agent->id))->decision, 'reason_codes' => json_decode((string) optional($gates->get($agent->id))->reason_codes, true), 'reason' => $agent->decision_reason]),
    'a616' => optional($agents->firstWhere('id', 616)) ? ['status' => $agents->firstWhere('id', 616)->lifecycle_status, 'sample_count' => $agents->firstWhere('id', 616)->sample_count, 'pf' => $agents->firstWhere('id', 616)->profit_factor, 'gate' => optional($gates->get(616))->decision, 'reason_codes' => json_decode((string) optional($gates->get(616))->reason_codes, true), 'reason' => $agents->firstWhere('id', 616)->decision_reason] : null,
    'screening_gate_counts' => $gateCounts,
    'screening_reason_counts' => $reasonCounts,
], JSON_UNESCAPED_SLASHES);
