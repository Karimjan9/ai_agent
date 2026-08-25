<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$out = [];

$out['ai_labs'] = DB::table('ai_laboratories')->get(['id','name','created_at'])->toArray();
$out['current_lab_gen'] = DB::table('lab_generations')->orderBy('id','desc')->first(['id','generation','status','trigger_type','started_at','completed_at']);
$out['gen_125_agents'] = DB::table('lab_agents')
    ->where('lab_generation_id', 125)
    ->select('id','lifecycle_status','symbol','timeframe','strategy_family','decision_reason','created_at')
    ->orderBy('id')
    ->get()
    ->toArray();

$out['recent_lab_generations'] = DB::table('lab_generations')
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get(['id','generation','status','trigger_type','created_at','started_at','completed_at'])
    ->toArray();

$out['failed_jobs_recent'] = DB::table('failed_jobs')
    ->select('queue', 'connection', 'failed_at')
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get()
    ->toArray();

$out['orphaned_jobs'] = DB::table('jobs')
    ->where('queue', 'lab-frontier')
    ->get()
    ->map(function($j) {
        $p = json_decode($j->payload, true);
        $cmd = data_get($p, 'data.command');
        $decoded = @unserialize((string) $cmd);
        return [
            'id' => $j->id,
            'queue' => $j->queue,
            'agent_id' => is_object($decoded) ? ($decoded->labAgentId ?? null) : null,
            'mode' => is_object($decoded) ? ($decoded->mode ?? null) : null,
            'symbol' => is_object($decoded) ? ($decoded->symbol ?? null) : null,
            'connection_in_payload' => is_object($decoded) ? ($decoded->connection ?? 'not_set') : 'not_set',
            'attempts' => $j->attempts,
            'reserved_at' => $j->reserved_at,
            'created_at' => $j->created_at,
            'age_hours' => round((now()->timestamp - $j->created_at) / 3600, 1),
        ];
    })
    ->toArray();

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
