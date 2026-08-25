<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Get all orphaned jobs and their agents
$jobs = DB::table('jobs')
    ->where('queue', 'lab-frontier')
    ->get();

echo "Total orphaned jobs: " . $jobs->count() . "\n\n";

foreach ($jobs as $job) {
    $payload = json_decode($job->payload, true);
    $command = data_get($payload, 'data.command');
    $decoded = @unserialize((string) $command);
    
    $agentId = $decoded->labAgentId ?? null;
    $mode = $decoded->mode ?? null;
    $retryDeadline = $decoded->retryDeadline ?? null;
    $screenQueuedAt = $decoded->screenQueuedAt ?? null;
    
    // Format the dates
    $retryDeadlineFormatted = $retryDeadline ? (is_string($retryDeadline) ? date('Y-m-d H:i:s', strtotime($retryDeadline)) : $retryDeadline->format('Y-m-d H:i:s')) : 'null';
    $screenQueuedAtFormatted = $screenQueuedAt ? (is_string($screenQueuedAt) ? date('Y-m-d H:i:s', strtotime($screenQueuedAt)) : $screenQueuedAt->format('Y-m-d H:i:s')) : 'null';
    
    $agent = $agentId ? DB::table('lab_agents')->where('id', $agentId)->first(['id', 'lifecycle_status', 'symbol', 'timeframe']) : null;
    
    echo "Job ID: {$job->id} | Agent: {$agentId} | Mode: {$mode} | Retry Deadline: {$retryDeadlineFormatted} | Screen Queued: {$screenQueuedAtFormatted} | Agent Status: " . ($agent->lifecycle_status ?? 'not found') . "\n";
}
