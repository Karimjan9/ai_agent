<?php
/** Offline curator request preparation. No Laravel boot, DB, queue or network. */
declare(strict_types=1);

function scalpNeed(bool $ok, string $reason): void
{
    if (! $ok) { throw new LogicException($reason); }
}
function scalpRead(string $path, string $expected): array
{
    scalpNeed(is_file($path) && hash_file('sha256', $path) === $expected, 'ORIGINAL_INPUT_HASH_REQUIRED:'.$path);
    return json_decode(file_get_contents($path), true, 256, JSON_THROW_ON_ERROR);
}
function scalpEncode(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}
scalpNeed(array_slice($argv, 1) === ['--export-draft'], 'ONLY_READ_ONLY_EXPORT_DRAFT_SUPPORTED');
$root = dirname(__DIR__, 2);
$priorRoot = dirname($root).'/curator-context-research-20261015';
$priorPath = $priorRoot.'/.runtime/context-practice-completion-20261015/before-outcome-spec.json';
$priorSha = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4';
$manifestPath = $priorRoot.'/backend-laravel/storage/app/lab-datasets/curator-context-20261015/provenance-manifest.json';
$manifestSha = 'b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92';
$calendarPath = $priorRoot.'/.runtime/curator-activation-calendar-20261015/selected-calendar-audit.json';
$calendarSha = '56939bbdb421f9ad810a3caebe3a07b15cc73112275df013d7fb92477ed41b05';
$prior = scalpRead($priorPath, $priorSha);
$manifest = scalpRead($manifestPath, $manifestSha);
$calendar = scalpRead($calendarPath, $calendarSha);
require $root.'/backend-laravel/app/Services/StrategyParameterSchemaService.php';
require $root.'/backend-laravel/app/Services/ResearchPaperEpochContractService.php';
$schema = new App\Services\StrategyParameterSchemaService();
$hashes = new App\Services\ResearchPaperEpochContractService();
$question = 'curator-scalp-ema-20261016-v1';
$windows = $baselines = $requests = [];
$arms = ['proximity_unbounded' => ['frozen', 0], 'proximity_6bars' => ['frozen', 6],
    'pretrend_unbounded' => ['source_ema_pretrend_pullback_v1', 0],
    'pretrend_6bars' => ['source_ema_pretrend_pullback_v1', 6]];
foreach (['october_pre_dst', 'december_standard'] as $key) {
    $window = $manifest['windows'][$key];
    scalpNeed(hash_file('sha256', $window['path']) === $window['sha256']
        && $window['warmup_rows'] === 200 && $window['evaluation_last_close_utc'] < '2026-01-01T00:00:00Z'
        && $calendar['windows'][$key]['selected_unexpected_market_candles'] === 0,
        'EXACT_PRE2026_SELECTED_ROWS_AND_CALENDAR_REQUIRED');
    $windows[$key] = $window;
    $base = $prior['baselines'][$key];
    $base['strategy'] = 'trend_v1'; $base['base_strategy'] = 'trend';
    $parameters = $schema->defaults('trend_v1');
    // Defaults are schema-owned, not tuned from this question's outcomes.
    $parameters = array_replace($parameters, ['ema_fast' => 20, 'ema_slow' => 50, 'rsi_period' => 14,
        'trend_strength_min' => 20.0, 'pullback_atr_fraction' => 0.75,
        'volume_lane' => 'none', 'atr_stop_multiplier' => 1.5, 'atr_target_multiplier' => 2.5,
        'trailing_atr_multiplier' => 0.0, 'time_stop_candles' => 0,
        'minimum_signal_confidence' => 0.35, 'partial_take_profit_fraction' => 0.0,
        'entry_topology_variant' => 'frozen']);
    $base['parameters'] = $parameters;
    scalpNeed($schema->validate('trend_v1', $parameters) === $parameters, 'ACTUAL_PHP_BASELINE_SCHEMA_REQUIRED');
    scalpNeed(! isset($base['policy_context']) && $base['execution'] === $prior['baselines'][$key]['execution']
        && $base['initial_balance'] === 10000.0 && $base['risk_per_trade'] === 1.0,
        'UNCHANGED_ACCOUNT_RISK_EXECUTION_REQUIRED');
    $baselines[$key] = $base;
    foreach ($arms as $arm => [$topology, $bars]) {
        $request = $base;
        $request['parameters']['entry_topology_variant'] = $topology;
        $request['parameters']['time_stop_candles'] = $bars;
        scalpNeed($schema->validate('trend_v1', $request['parameters']) === $request['parameters'], 'ACTUAL_PHP_ARM_SCHEMA_REQUIRED');
        $restored = $request;
        $restored['parameters']['entry_topology_variant'] = 'frozen'; $restored['parameters']['time_stop_candles'] = 0;
        scalpNeed($restored === $base, 'ONLY_TWO_DECLARED_COORDINATES_ALLOWED');
        $wire = scalpEncode($request);
        $requests[$key.'__'.$arm] = ['arm' => $arm, 'window' => $key,
            'baseline_key' => $key.'__proximity_unbounded', 'question_id' => $question,
            'request' => $request, 'request_json' => $wire, 'request_sha256' => hash('sha256', $wire),
            'request_semantic_hash' => $hashes->parameterHash($request),
            'dataset_sha256' => $window['sha256'], 'cost_sha256' => $request['execution_contract']['execution_hash']];
    }
}
$draft = ['protocol' => 'unfrozen_curator_scalp_panel_v1', 'question_id' => $question,
    'question' => 'EMA20_50_proximity_vs_closed_pretrend_touch_reclaim_crossed_with_no_time_exit_vs_six_row_time_exit',
    'client_research_date' => '2026-10-16', 'requests' => $requests, 'windows' => $windows, 'baselines' => $baselines,
    'upstream' => ['prior' => ['path' => $priorPath, 'sha256' => $priorSha],
        'manifest' => ['path' => $manifestPath, 'sha256' => $manifestSha],
        'calendar' => ['path' => $calendarPath, 'sha256' => $calendarSha]],
    'predeclared_arms' => 8, 'per_original_timeout_seconds' => 600, 'global_timeout_seconds' => 5400,
    'max_attempts_per_arm' => 1, 'serial_execution' => true, 'worker_threads' => 1, 'process_priority' => 'below_normal_hidden',
    'exposure' => 'previously_seen_pre2026_discovery_not_untouched_validation',
    'holding_semantics' => 'six_observed_row_transitions_nominal_30_minutes_not_hard_wall_clock_or_subbar_fill_proof',
    'execution_limitation' => 'existing_time_stop_open_precedes_gap_SL_TP_check; quote_sidecar_is_not_fill_path; modeled_costs_unchanged',
    'source_transfer' => 'EMA_pullback_concept_adaptation_not_CME_377tick_ES_limit_strategy_replication',
    'source_references' => [
        ['url' => 'https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/ema', 'reviewed' => 'calculation_and_how_it_works'],
        ['url' => 'https://www.cmegroup.com/content/dam/cmegroup/education/files/education_team_booklet.pdf', 'reviewed' => 'extracted_text_printed_pages_4_to_20; chart_pixels_and_companion_videos_not_reviewed'],
        ['url' => 'https://chartschool.stockcharts.com/table-of-contents/technical-indicators-and-overlays/technical-overlays/keltner-channels', 'reviewed' => 'calculation_interpretation_and_regime_examples; Keltner_not_executed_in_this_question']],
    'research_only' => true, 'own_agent_outcome' => false, 'independent_validation' => false, 'credit' => false,
    'mastery' => false, 'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false];
echo scalpEncode($draft).PHP_EOL;
