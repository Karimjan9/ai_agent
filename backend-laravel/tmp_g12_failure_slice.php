<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$agents = App\Models\LabAgent::query()
    ->where('lab_generation_id', 44)
    ->orderBy('id')
    ->get();
$evidence = app(App\Services\LabImmutableEvidenceService::class);

$minCell = static function (mixed $map, int $minimumTrades = 3): ?array {
    $best = null;
    foreach ((array) $map as $key => $metrics) {
        $trades = (int) data_get($metrics, 'trades', 0);
        $pf = (float) data_get($metrics, 'net_pf', data_get($metrics, 'profit_factor', 0));
        if ($trades < $minimumTrades) {
            continue;
        }
        if ($best === null || $pf < (float) $best['pf']) {
            $best = ['key' => (string) $key, 'pf' => round($pf, 3), 'trades' => $trades];
        }
    }

    return $best;
};

$rows = [];
$frequency = ['month' => [], 'chunk' => [], 'session' => [], 'direction' => [], 'regime' => []];
foreach ($agents as $agent) {
    $run = App\Models\LabEvaluationRun::query()
        ->where('lab_agent_id', $agent->id)
        ->where('phase', 'screening')
        ->where('status', 'completed')
        ->latest('id')
        ->first();
    $payload = $run ? ($evidence->latestArtifactPayload($run) ?? []) : [];
    $breakdown = (array) data_get($payload, 'pf_attribution.breakdown', []);
    $cells = [
        'month' => $minCell(data_get($breakdown, 'by_month', [])),
        'chunk' => $minCell(data_get($breakdown, 'by_temporal_chunk', [])),
        'session' => $minCell(data_get($breakdown, 'by_session', [])),
        'direction' => $minCell(data_get($breakdown, 'by_direction', []), 10),
        'regime' => $minCell(data_get($breakdown, 'by_regime', []), 10),
    ];
    foreach ($cells as $kind => $cell) {
        if ($cell !== null) {
            $frequency[$kind][$cell['key']] = ($frequency[$kind][$cell['key']] ?? 0) + 1;
        }
    }
    $rows[] = [
        'agent' => $agent->id,
        'family' => $agent->strategy_family,
        'parameter_diff_keys' => array_keys(is_array($agent->parameter_diff)
            ? $agent->parameter_diff
            : ((array) json_decode((string) $agent->parameter_diff, true))),
        'cells' => $cells,
        'total_trades' => (int) data_get($payload, 'total_trades', 0),
        'profit_factor' => round((float) data_get($payload, 'profit_factor', 0), 3),
    ];
}

echo json_encode(['rows' => $rows, 'worst_frequency' => $frequency], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
