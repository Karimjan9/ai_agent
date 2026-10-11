<?php

// Read-only launch-environment maintenance; application source is unchanged.
$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function (string $sql): void {
    if (! preg_match('/^SELECT\b/i', trim($sql))) {
        throw new RuntimeException('DISPLAY_ENV_READ_ONLY_QUERY_REQUIRED');
    }
});
$queues = ['lab-screening', 'lab-frontier', 'lab-full-validation', 'lab-xauusd',
    'lab-eurusd', 'lab-gbpusd', 'lab-learning', 'market-maintenance',
    'scheduler-critical', 'scheduler-ops', 'scheduler-constructor',
    'scheduler-research', 'strategy-lab', 'backtests'];
$backlogs = [];
foreach ($queues as $queue) {
    $backlogs[$queue] = Illuminate\Support\Facades\Queue::connection('redis')->size($queue);
}
echo json_encode([
    'source_hash' => app(App\Services\LabImmutableEvidenceService::class)->codeHash(),
    'control' => app(App\Services\AutonomousModeService::class)->status(),
    'queues' => $backlogs,
    'active_replays' => App\Models\LabEvaluationRun::whereIn('status', ['started', 'running', 'processing'])->count(),
], JSON_THROW_ON_ERROR).PHP_EOL;
