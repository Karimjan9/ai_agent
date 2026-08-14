<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$jobs = Illuminate\Support\Facades\DB::table('jobs')
        ->whereIn('queue', ['lab-screening', 'lab-frontier', 'lab-full-validation'])
        ->get(['id', 'queue', 'reserved_at']);
$agents = Illuminate\Support\Facades\DB::table('lab_agents')
        ->where('lab_generation_id', 45)
        ->select('id', 'lifecycle_status', 'sample_count', 'profit_factor', 'max_drawdown', 'decision_reason')->get();
$runs = Illuminate\Support\Facades\DB::table('lab_evaluation_runs')
        ->where('lab_generation_id', 45)
        ->select('lab_agent_id', 'phase', 'status', 'started_at', 'duration_ms', 'error_message')->get();
$artifactCounts = Illuminate\Support\Facades\DB::table('lab_evidence_artifacts')
        ->where('lab_generation_id', 45)
        ->select('lab_agent_id', 'artifact_type')
        ->get()
        ->groupBy('lab_agent_id')
        ->map(fn ($items) => $items->pluck('artifact_type')->unique()->values());
$gateEvents = Illuminate\Support\Facades\DB::table('lab_gate_decision_events')
        ->where('lab_generation_id', 45)
        ->where('stage', 'screening')
        ->orderBy('id')
        ->get(['lab_agent_id', 'decision', 'reason_codes', 'metrics'])
        ->groupBy('lab_agent_id')
        ->map(fn ($items) => $items->last());
echo json_encode([
    'jobs' => ['total' => $jobs->count(), 'reserved' => $jobs->whereNotNull('reserved_at')->count(), 'queued' => $jobs->whereNull('reserved_at')->count()],
    'agents' => $agents->groupBy('lifecycle_status')->map->count(),
    'runs' => ['total' => $runs->count(), 'completed' => $runs->where('status', 'completed')->count(), 'started' => $runs->where('status', 'started')->count(), 'technical' => $runs->where('status', 'technical_error')->count()],
    'active_agents' => $agents->whereIn('lifecycle_status', ['screening', 'full_validation'])->values()->map(fn ($agent) => ['id' => $agent->id, 'status' => $agent->lifecycle_status]),
    'active_runs' => $runs->where('status', 'started')->values()->map(fn ($run) => ['agent_id' => $run->lab_agent_id, 'started_at' => $run->started_at]),
    'completed_summary' => $agents->where('lifecycle_status', 'screened')->values()->map(fn ($agent) => ['id' => $agent->id, 'sample_count' => $agent->sample_count, 'pf' => $agent->profit_factor, 'max_drawdown' => $agent->max_drawdown, 'gate' => optional($gateEvents->get($agent->id))->decision, 'reason_codes' => json_decode((string) optional($gateEvents->get($agent->id))->reason_codes, true), 'artifacts' => $artifactCounts->get($agent->id, collect())->values(), 'reason' => $agent->decision_reason]),
    'last_completed' => optional($runs->where('status', 'completed')->sortByDesc('lab_agent_id')->first())->lab_agent_id,
    'technical_errors' => $runs->where('status', 'technical_error')->map(fn ($run) => ['agent_id' => $run->lab_agent_id, 'error' => $run->error_message])->values(),
], JSON_UNESCAPED_SLASHES);
