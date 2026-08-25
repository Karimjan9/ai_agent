<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Check all lab queues
$queues = ['lab-frontier', 'lab-screening', 'lab-full-validation'];

foreach ($queues as $queue) {
    $count = DB::table('jobs')->where('queue', $queue)->count();
    echo "Queue '{$queue}': {$count} jobs\n";
}

// Check failed jobs more thoroughly
$failedCount = DB::table('failed_jobs')
    ->where('queue', 'lab-frontier')
    ->orWhere('queue', 'lab-screening')
    ->orWhere('queue', 'lab-full-validation')
    ->count();
echo "Failed lab jobs: {$failedCount}\n";
