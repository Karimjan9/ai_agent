<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Check failed jobs
$failedJobs = DB::table('failed_jobs')
    ->whereIn('queue', ['lab-frontier', 'lab-screening', 'lab-full-validation'])
    ->get(['id', 'queue', 'connection', 'failed_at', 'exception']);

echo "Failed lab jobs:\n";
foreach ($failedJobs as $job) {
    $errorLine = '';
    if (preg_match('/in (.*\.php:\d+)/', $job->exception, $matches)) {
        $errorLine = $matches[1];
    }
    echo "  ID: {$job->id} | Queue: {$job->queue} | Connection: {$job->connection} | Failed: {$job->failed_at} | Error: {$errorLine}\n";
}
