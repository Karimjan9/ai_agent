<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$failedJobs = DB::table('failed_jobs')
    ->where('queue', 'lab-frontier')
    ->where('connection', 'database')
    ->latest('failed_at')
    ->first();

echo "Latest failed job:\n";
echo "ID: {$failedJobs->id}\n";
echo "Failed At: {$failedJobs->failed_at}\n";
echo "Exception: " . $failedJobs->exception . "\n";
