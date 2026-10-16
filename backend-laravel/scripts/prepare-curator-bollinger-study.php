<?php
/** Declares one offline Bollinger question without application boot or outcomes. */
declare(strict_types=1);
function bollingerNeed(bool $ok, string $reason): void { if (! $ok) { throw new LogicException($reason); } }
function bollingerEncode(array $data): string {
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}
bollingerNeed(count($argv) === 5 && $argv[1] === '--windows' && $argv[3] === '--windows-sha256', 'EXACT_WINDOWS_IDENTITY_ARGUMENTS_REQUIRED');
$manifestPath = realpath($argv[2]); $manifestSha = $argv[4];
bollingerNeed($manifestPath !== false && hash_file('sha256', $manifestPath) === $manifestSha, 'EXACT_PROSPECTIVE_WINDOWS_REQUIRED');
$manifest = json_decode(file_get_contents($manifestPath), true, 256, JSON_THROW_ON_ERROR);
$windowNames = array_keys($manifest['windows']); sort($windowNames);
bollingerNeed($manifest['protocol'] === 'curator_instrument_calendar_windows_v1'
    && $windowNames === ['august2025', 'may2025'], 'EXACT_NEW_MONTHS_REQUIRED');
$root = dirname(__DIR__, 2);
$sourceReviewPath = $root.'/docs/research/instruments/range_reentry/events/2026-10-16-source-review.json';
$sourceReviewSha = 'bb5e378b4ca700bbcacf85594bd4d8f00112b160fcb0ebd106e21893bc18fdc8';
bollingerNeed(hash_file('sha256', $sourceReviewPath) === $sourceReviewSha, 'ORIGINAL_CURATOR_SOURCE_REVIEW_REQUIRED');
$controlPath = dirname($root).'/curator-context-research-20261015/.runtime/context-practice-completion-20261015/before-outcome-spec.json';
$controlSha = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4';
bollingerNeed(hash_file('sha256', $controlPath) === $controlSha, 'ORIGINAL_FROZEN_ACCOUNT_COST_CONTROL_REQUIRED');
$control = json_decode(file_get_contents($controlPath), true, 256, JSON_THROW_ON_ERROR)['baselines']['october_pre_dst'];
require $root.'/backend-laravel/app/Services/StrategyParameterSchemaService.php';
require $root.'/backend-laravel/app/Services/ExecutionContractService.php';
require $root.'/backend-laravel/app/Services/ResearchPaperEpochContractService.php';
$schema = new App\Services\StrategyParameterSchemaService();
$execution = new App\Services\ExecutionContractService(); $hashes = new App\Services\ResearchPaperEpochContractService();
$arms = ['all_scalp6' => ['none', 6], 'range_low_scalp6' => ['range_low_v1', 6],
    'all_day24' => ['none', 24], 'range_low_day24' => ['range_low_v1', 24]];
$question = 'bollinger-range-context-20261016-v1'; $baselines = $requests = [];
foreach (['may2025', 'august2025'] as $windowKey) {
    $window = $manifest['windows'][$windowKey];
    bollingerNeed(hash_file('sha256', $window['path']) === $window['sha256'] && $window['warmup_rows'] === 200
        && $window['evaluation_last_close_utc'] < '2026-01-01T00:00:00Z', 'ORIGINAL_PRE2026_WINDOW_BYTES_REQUIRED');
    $base = $control; $base['strategy'] = 'session_mean_reversion_v1'; $base['base_strategy'] = 'session_mean_reversion';
    $base['dataset_path'] = $window['path']; $base['replay_dataset_hash'] = $window['sha256'];
    $base['execution']['allowed_sessions_utc'] = ['7-14'];
    $base['execution_contract']['parameters'] = $base['execution'];
    $base['execution_contract']['execution_hash'] = $execution->hashParameters($base['execution']);
    $base['parameters'] = array_replace($schema->defaults($base['strategy']), ['session_start' => 7, 'session_end' => 14,
        'lookback' => 20, 'volume_lane' => 'none', 'atr_stop_multiplier' => 1.5, 'atr_target_multiplier' => 2.5,
        'trailing_atr_multiplier' => 0.0, 'partial_take_profit_fraction' => 0.0, 'minimum_signal_confidence' => 0.35,
        'time_stop_candles' => 6, 'session_context_gate' => 'none']);
    bollingerNeed($schema->validate($base['strategy'], $base['parameters'], $base['base_strategy']) === $base['parameters'], 'ACTUAL_PHP_REENTRY_SCHEMA_REQUIRED');
    $baselines[$windowKey] = $base;
    foreach ($arms as $arm => [$gate, $bars]) {
        $request = $base; $request['parameters']['session_context_gate'] = $gate; $request['parameters']['time_stop_candles'] = $bars;
        bollingerNeed($schema->validate($request['strategy'], $request['parameters'], $request['base_strategy']) === $request['parameters'], 'ACTUAL_PHP_ARM_SCHEMA_REQUIRED');
        $restored = $request; $restored['parameters']['session_context_gate'] = 'none'; $restored['parameters']['time_stop_candles'] = 6;
        bollingerNeed($restored === $base, 'ONLY_PREDECLARED_CONTEXT_AND_HOLDING_COORDINATES_ALLOWED');
        $wire = bollingerEncode($request);
        $requests[$windowKey.'__'.$arm] = ['window' => $windowKey, 'arm' => $arm, 'baseline_key' => $windowKey.'__all_scalp6',
            'question_id' => $question, 'request' => $request, 'request_json' => $wire, 'request_sha256' => hash('sha256', $wire),
            'request_semantic_hash' => $hashes->parameterHash($request), 'dataset_sha256' => $window['sha256'],
            'cost_sha256' => $request['execution_contract']['execution_hash']];
    }
}
echo bollingerEncode(['protocol' => 'unfrozen_curator_instrument_panel_v1', 'study_owner' => 'bollinger_context_v1',
    'question_id' => $question, 'instrument' => 'bollinger_bands', 'instrument_key' => 'range_reentry',
    'question' => 'closed_band_recross_entry_context_none_vs_range_low_crossed_with_six_vs_twenty_four_row_holding',
    'research_date' => '2026-10-16', 'windows' => $manifest['windows'], 'baselines' => $baselines, 'requests' => $requests,
    'upstream' => ['windows' => ['path' => $manifestPath, 'sha256' => $manifestSha],
        'account_control' => ['path' => $controlPath, 'sha256' => $controlSha],
        'source_review' => ['path' => $sourceReviewPath, 'sha256' => $sourceReviewSha]],
    'predeclared_arms' => 8, 'per_original_timeout_seconds' => 600, 'global_timeout_seconds' => 5400,
    'max_attempts_per_arm' => 1, 'serial_execution' => true, 'worker_threads' => 1, 'process_priority' => 'below_normal_hidden',
    'exposure' => 'original_archive_discovery_no_complete_training_exposure_inventory_no_independence_claim',
    'source_boundary' => 'curator_context_hypothesis_not_author_Method_I_II_III_IV_replication; generic_ATR_exit_not_band_midline_exit',
    'research_only' => true, 'own_agent_outcome' => false, 'independent_validation' => false, 'credit' => false,
    'mastery' => false, 'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false]).PHP_EOL;
