<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Get the first orphaned job
$job = DB::table('jobs')
    ->where('queue', 'lab-frontier')
    ->orderBy('id')
    ->first();

if (!$job) {
    echo "No orphaned jobs found\n";
    exit;
}

$payload = json_decode($job->payload, true);
$command = data_get($payload, 'data.command');
$decoded = @unserialize((string) $command);

echo "Job ID: {$job->id}\n";
echo "Queue: {$job->queue}\n";
echo "Attempts: {$job->attempts}\n";
echo "Lab Agent ID: " . ($decoded->labAgentId ?? 'null') . "\n";
echo "Mode: " . ($decoded->mode ?? 'null') . "\n";
echo "Symbol: " . ($decoded->symbol ?? 'null') . "\n";
echo "Connection in payload: " . ($decoded->connection ?? 'not_set') . "\n";
echo "Queue in payload: " . ($decoded->queue ?? 'not_set') . "\n";
echo "Retry Deadline: " . ($decoded->retryDeadline ?? 'null') . "\n";
echo "Screen Queued At: " . ($decoded->screenQueuedAt ?? 'null') . "\n";

// Check the agent
$agent = DB::table('lab_agents')->where('id', $decoded->labAgentId)->first(['id', 'lifecycle_status', 'symbol', 'timeframe']);
echo "Agent Lifecycle: " . ($agent->lifecycle_status ?? 'not found') . "\n";
