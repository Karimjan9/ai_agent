<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$out = [];

// 1. Scheduler lease
$lease = Cache::store('redis')->get('trading:headless-scheduler:v1');
$out['scheduler_lease'] = $lease;

// 2. Failed jobs
$failedJobs = DB::table('failed_jobs')->orderBy('id', 'desc')->limit(10);
$out['failed_jobs'] = [
    'count' => DB::table('failed_jobs')->count(),
    'recent' => $failedJobs->get(['id', 'uuid', 'queue', 'connection', 'failed_at', 'exception'])->map(function($j) {
        return [
            'id' => $j->id,
            'uuid' => $j->uuid,
            'queue' => $j->queue,
            'connection' => $j->connection,
            'failed_at' => $j->failed_at,
            'exception' => substr($j->exception, 0, 800),
        ];
    })->toArray(),
];

// 3. Jobs table (database queue)
$out['jobs_table'] = [
    'total' => DB::table('jobs')->count(),
    'by_queue' => DB::table('jobs')->select('queue', DB::raw('count(*) as c'))->groupBy('queue')->get()->keyBy('queue')->map(fn($r) => $r->c)->toArray(),
    'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
    'queued' => DB::table('jobs')->whereNull('reserved_at')->count(),
    'recent' => DB::table('jobs')->orderBy('id', 'desc')->limit(5)->get(['id','queue','reserved_at','available_at','created_at'])->map(fn($j) => [
        'id' => $j->id,
        'queue' => $j->queue,
        'reserved_at' => $j->reserved_at,
        'available_at' => $j->available_at,
        'created_at' => $j->created_at,
        'age_seconds' => now()->timestamp - $j->created_at,
    ])->toArray(),
];

// 4. Current lab generation
$generations = DB::table('lab_generations')->orderBy('id', 'desc')->limit(5)->get();
$out['lab_generations'] = $generations->map(fn($g) => [
    'id' => $g->id,
    'generation' => $g->generation ?? $g->name ?? null,
    'status' => $g->status ?? null,
    'created_at' => $g->created_at,
]);

// 5. Current generation agents
$currentGen = DB::table('lab_generations')->orderBy('id', 'desc')->first();
if ($currentGen) {
    $genId = $currentGen->id;
    $out['lab_agents_current_gen'] = [
        'generation_id' => $genId,
        'total' => DB::table('lab_agents')->where('lab_generation_id', $genId)->count(),
        'by_status' => DB::table('lab_agents')->where('lab_generation_id', $genId)
            ->select('lifecycle_status', DB::raw('count(*) as c'))
            ->groupBy('lifecycle_status')
            ->get()
            ->keyBy('lifecycle_status')
            ->map(fn($r) => $r->c)
            ->toArray(),
    ];
}

// 6. Lab evaluation runs - started but not finished (stuck)
$out['stuck_runs'] = DB::table('lab_evaluation_runs')
    ->where('status', 'started')
    ->where('started_at', '<', now()->subMinutes(30))
    ->select('id','lab_agent_id','phase','status','started_at','run_id')
    ->limit(20)
    ->get()
    ->toArray();

// 7. System health checks
$out['health_checks'] = DB::table('service_health_checks')
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get(['id','service_key','status','last_checked_at','message'])
    ->toArray();

// 8. System logs recent
$out['system_logs'] = DB::table('system_logs')
    ->orderBy('id', 'desc')
    ->limit(15)
    ->get(['id','log_type','level','component','action','status','message','occurred_at'])
    ->toArray();

// 9. Redis config
$out['redis_prefix'] = config('database.redis.default.prefix');
$out['queue_connection'] = config('queue.default');

// 10. Failed jobs by queue
$out['failed_jobs_by_queue'] = DB::table('failed_jobs')
    ->select('queue', DB::raw('count(*) as c'))
    ->groupBy('queue')
    ->get()
    ->keyBy('queue')
    ->map(fn($r) => $r->c)
    ->toArray();

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
