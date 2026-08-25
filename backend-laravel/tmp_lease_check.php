<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

echo "=== System scheduler lease (Redis) ===\n";
$lease = Cache::store('redis')->get('system:scheduler-lease');
echo json_encode($lease, JSON_PRETTY_PRINT) . "\n";

echo "\n=== System scheduler heartbeat ===\n";
$hb = Cache::store('redis')->get('system:scheduler-heartbeat');
echo json_encode($hb, JSON_PRETTY_PRINT) . "\n";

echo "\n=== Headless scheduler lease raw Redis ===\n";
$redis = Illuminate\Support\Facades\Redis::connection();
$prefix = config('database.redis.default.prefix');
echo "Redis prefix: " . json_encode($prefix) . "\n";
echo "Raw lease key value: " . json_encode($redis->get($prefix . 'trading:headless-scheduler:v1')) . "\n";
echo "Raw scheduler-lease key value: " . json_encode($redis->get($prefix . 'system:scheduler-lease')) . "\n";
echo "Raw scheduler-heartbeat key value: " . json_encode($redis->get($prefix . 'system:scheduler-heartbeat')) . "\n";

echo "\n=== Queue connection config ===\n";
echo "queue.default: " . config('queue.default') . "\n";
echo "queue.connections.redis.retry_after: " . config('queue.connections.redis.retry_after') . "\n";
echo "services.lab_queue config: " . json_encode(config('services.lab_queue')) . "\n";

echo "\n=== Frontier jobs in DB - payload analysis ===\n";
$frontierJobs = DB::table('jobs')->where('queue', 'lab-frontier')->limit(3)->get(['id','queue','payload','reserved_at','created_at']);
foreach ($frontierJobs as $job) {
    $payload = json_decode((string) $job->payload, true);
    $cmd = data_get($payload, 'data.command');
    $decoded = is_string($cmd) ? @unserialize($cmd) : null;
    echo "\nJob #{$job->id}:\n";
    echo "  connection: " . (is_object($decoded) ? ($decoded->connection ?? 'default') : 'unknown') . "\n";
    echo "  queue: " . (is_object($decoded) ? ($decoded->queue ?? 'default') : 'unknown') . "\n";
    echo "  labAgentId: " . (is_object($decoded) ? ($decoded->labAgentId ?? 'n/a') : 'n/a') . "\n";
    echo "  mode: " . (is_object($decoded) ? ($decoded->mode ?? 'n/a') : 'n/a') . "\n";
    echo "  created_at: " . $job->created_at . "\n";
    echo "  reserved_at: " . ($job->reserved_at ?? 'NULL') . "\n";
}

echo "\n=== How many jobs total and by connection ===\n";
$allJobs = DB::table('jobs')->get(['id','queue','payload']);
$connCounts = [];
foreach ($allJobs as $job) {
    $payload = json_decode((string) $job->payload, true);
    $cmd = data_get($payload, 'data.command');
    $decoded = is_string($cmd) ? @unserialize($cmd) : null;
    $conn = is_object($decoded) ? ($decoded->connection ?? 'default') : 'default';
    $key = $job->queue . '/' . $conn;
    $connCounts[$key] = ($connCounts[$key] ?? 0) + 1;
}
echo json_encode($connCounts, JSON_PRETTY_PRINT) . "\n";
