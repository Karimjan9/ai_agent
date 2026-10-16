<?php
/** Offline original request declaration. No application boot, DB, queue or replay. */
declare(strict_types=1);

function intradayNeed(bool $ok, string $reason): void
{
    if (! $ok) { throw new LogicException($reason); }
}
function intradayRead(string $path, string $identity): array
{
    intradayNeed(is_file($path) && hash_file('sha256', $path) === $identity, 'EXACT_ORIGINAL_INPUT_REQUIRED:'.$path);
    return json_decode(file_get_contents($path), true, 256, JSON_THROW_ON_ERROR);
}
function intradayEncode(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

intradayNeed(array_slice($argv, 1) === ['--export-draft'], 'ONLY_READ_ONLY_EXPORT_DRAFT_SUPPORTED');
$root = dirname(__DIR__, 2);
$priorRoot = dirname($root).'/curator-context-research-20261015';
$priorPath = $priorRoot.'/.runtime/context-practice-completion-20261015/before-outcome-spec.json';
$priorSha = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4';
$manifestPath = $priorRoot.'/backend-laravel/storage/app/lab-datasets/curator-context-20261015/provenance-manifest.json';
$manifestSha = 'b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92';
$calendarPath = $priorRoot.'/.runtime/curator-activation-calendar-20261015/selected-calendar-audit.json';
$calendarSha = '56939bbdb421f9ad810a3caebe3a07b15cc73112275df013d7fb92477ed41b05';
$prior = intradayRead($priorPath, $priorSha);
$manifest = intradayRead($manifestPath, $manifestSha);
$calendar = intradayRead($calendarPath, $calendarSha);
foreach (['source_path' => 'source_sha256', 'source_manifest_path' => 'source_manifest_sha256', 'producer_path' => 'producer_sha256'] as $path => $identity) {
    intradayNeed(hash_file('sha256', $manifest[$path]) === $manifest[$identity], 'UNCHANGED_RAW_CUSTODY_REQUIRED:'.$path);
}
foreach ($calendar['source_hashes'] as $path => $identity) {
    intradayNeed(hash_file('sha256', $path) === $identity, 'UNCHANGED_RETAINED_CALENDAR_OWNER_REQUIRED:'.$path);
}
require $root.'/backend-laravel/app/Services/StrategyParameterSchemaService.php';
require $root.'/backend-laravel/app/Services/ResearchPaperEpochContractService.php';
require $root.'/backend-laravel/app/Services/ExecutionContractService.php';
$schema = new App\Services\StrategyParameterSchemaService();
$hashes = new App\Services\ResearchPaperEpochContractService();
$executionOwner = new App\Services\ExecutionContractService();
$question = 'curator-intraday-session-20261016-v1';
$arms = ['breakout_scalp6' => ['session_v1', 'session', 6],
    'reentry_scalp6' => ['session_mean_reversion_v1', 'session_mean_reversion', 6],
    'breakout_day24' => ['session_v1', 'session', 24],
    'reentry_day24' => ['session_mean_reversion_v1', 'session_mean_reversion', 24]];
$windows = $baselines = $requests = [];
foreach (['october_pre_dst', 'december_standard'] as $windowKey) {
    $window = $manifest['windows'][$windowKey];
    intradayNeed(hash_file('sha256', $window['path']) === $window['sha256'] && $window['warmup_rows'] === 200
        && $window['evaluated_rows'] === $window['loaded_rows'] - 200
        && $window['evaluation_last_close_utc'] < '2026-01-01T00:00:00Z'
        && $calendar['windows'][$windowKey]['selected_unexpected_market_candles'] === 0,
        'EXACT_SELECTED_PRE2026_ROWS_AND_CALENDAR_REQUIRED');
    $windows[$windowKey] = $window;
    $old = $prior['baselines'][$windowKey];
    intradayNeed(! isset($old['policy_context']) && $old['initial_balance'] === 10000.0 && $old['risk_per_trade'] === 1.0
        && $old['execution']['allowed_sessions_utc'] === ['1-22']
        && $old['execution_contract']['protocol'] === App\Services\ExecutionContractService::PROTOCOL
        && $old['execution_contract']['version'] === App\Services\ExecutionContractService::VERSION
        && $old['execution_contract']['parameters'] === $old['execution']
        && $executionOwner->hashParameters($old['execution']) === $old['execution_contract']['execution_hash'],
        'ORIGINAL_ACCOUNT_RISK_AND_EXECUTION_OWNER_REQUIRED');
    $base = $old;
    $base['strategy'] = 'session_v1'; $base['base_strategy'] = 'session';
    $parameters = array_replace($schema->defaults($base['strategy']), ['session_start' => 7, 'session_end' => 14,
        'lookback' => 20, 'volume_lane' => 'none', 'atr_stop_multiplier' => 1.5, 'atr_target_multiplier' => 2.5,
        'trailing_atr_multiplier' => 0.0, 'partial_take_profit_fraction' => 0.0,
        'minimum_signal_confidence' => 0.35, 'time_stop_candles' => 6]);
    foreach ($parameters as $name => $value) {
        if ($name !== 'time_stop_candles' && array_key_exists($name, $old['parameters'])) {
            intradayNeed($value === $old['parameters'][$name], 'UNCHANGED_COMMON_FROZEN_CONTROLS_REQUIRED:'.$name);
        }
    }
    $base['parameters'] = $schema->validate($base['strategy'], $parameters);
    intradayNeed($base['parameters'] === $parameters, 'NO_HIDDEN_PARAMETER_REPAIR_ALLOWED');
    // The only common execution intervention is the prospective UTC schedule.
    // The actual execution owner hashes that complete changed physical map.
    $base['execution']['allowed_sessions_utc'] = ['7-14'];
    $base['execution_contract']['parameters'] = $base['execution'];
    $base['execution_contract']['execution_hash'] = $executionOwner->hashParameters($base['execution']);
    intradayNeed($base['execution_contract']['execution_hash'] !== $old['execution_contract']['execution_hash'],
        'CHANGED_SESSION_SCHEDULE_MUST_NOT_BORROW_OLD_EXECUTION_HASH');
    $restored = $base; unset($restored['parameters']);
    $oldNonParameters = $old; unset($oldNonParameters['parameters']);
    $restored['strategy'] = $old['strategy']; $restored['base_strategy'] = $old['base_strategy'];
    $restored['execution']['allowed_sessions_utc'] = $old['execution']['allowed_sessions_utc'];
    $restored['execution_contract']['parameters'] = $old['execution_contract']['parameters'];
    $restored['execution_contract']['execution_hash'] = $old['execution_contract']['execution_hash'];
    intradayNeed($restored === $oldNonParameters, 'ONLY_DECLARED_COMMON_NONPARAMETER_CHANGES_ALLOWED');
    $baselines[$windowKey] = $base;
    foreach ($arms as $arm => [$strategy, $baseStrategy, $bars]) {
        $request = $base; $request['strategy'] = $strategy; $request['base_strategy'] = $baseStrategy;
        $request['parameters']['time_stop_candles'] = $bars;
        intradayNeed($schema->family($strategy) === 'session'
            && $schema->runtimeBaseStrategy($strategy, $baseStrategy) === $baseStrategy
            && $schema->validate($request['strategy'], $request['parameters']) === $request['parameters'],
            'ACTUAL_DECLARED_STRATEGY_SCHEMA_AND_RUNTIME_IDENTITY_REQUIRED');
        $restored = $request; $restored['strategy'] = $base['strategy']; $restored['base_strategy'] = $base['base_strategy'];
        $restored['parameters']['time_stop_candles'] = 6;
        intradayNeed($restored === $base, 'ONLY_ENTRY_PROGRAMME_AND_TIME_STOP_COORDINATES_ALLOWED');
        $wire = intradayEncode($request);
        $requests[$windowKey.'__'.$arm] = ['arm' => $arm, 'window' => $windowKey,
            'baseline_key' => $windowKey.'__breakout_scalp6', 'question_id' => $question,
            'request' => $request, 'request_json' => $wire, 'request_sha256' => hash('sha256', $wire),
            'request_semantic_hash' => $hashes->parameterHash($request), 'dataset_sha256' => $window['sha256'],
            'cost_sha256' => $request['execution_contract']['execution_hash']];
    }
}
intradayNeed(count($requests) === 8, 'EXACT_EIGHT_DECLARED_ORIGINALS_REQUIRED');
$draft = ['protocol' => 'unfrozen_curator_intraday_panel_v1', 'question_id' => $question,
    'question' => 'existing_session_breakout_vs_closed_band_recross_crossed_with_six_vs_twenty_four_observed_row_holding',
    'client_research_date' => '2026-10-16', 'requests' => $requests, 'windows' => $windows, 'baselines' => $baselines,
    'upstream' => ['prior' => ['path' => $priorPath, 'sha256' => $priorSha],
        'manifest' => ['path' => $manifestPath, 'sha256' => $manifestSha],
        'calendar' => ['path' => $calendarPath, 'sha256' => $calendarSha]],
    'predeclared_arms' => 8, 'per_original_timeout_seconds' => 600, 'global_timeout_seconds' => 5400,
    'max_attempts_per_arm' => 1, 'serial_execution' => true, 'worker_threads' => 1, 'process_priority' => 'below_normal_hidden',
    'exposure' => 'previously_seen_pre2026_discovery_not_untouched_validation',
    'session_semantics' => 'prospective_UTC_07_14_laboratory_schedule_not_London_DST_equivalence; closed_signal_and_execution_entry_filters',
    'holding_semantics' => 'six_or_twenty_four_observed_row_transitions_nominal_30_or_120_minutes; observed_same_UTC_date_not_general_session_flat_guarantee',
    'execution_limitation' => 'existing_time_stop_open_precedes_gap_SL_TP_check; ordinary_EOF_open_exposure_preserved; no_independent_intrabar_or_broker_fill_proof',
    'source_transfer' => 'existing_price_channel_breakout_and_closed_band_recross_adaptations_not_full_source_masterclass_replications',
    'source_references' => [
        'https://www.bollingerbands.com/bollinger-band-rules',
        'https://www.fidelity.com/learning-center/trading-investing/technical-analysis/technical-indicator-guide/bollinger-bands',
        'https://chartschool.stockcharts.com/table-of-contents/technical-indicators-and-overlays/technical-overlays/price-channels',
    ],
    'source_claim_boundary' => 'reviewed_textual_concepts_inform_existing_laboratory_implementations_not_author_exact_rules_or_profitability_claims; UTC_07_14_ATR_1.5_2.5_holding_6_24_confidence_0.35_are_laboratory_hypotheses',
    'research_only' => true, 'own_agent_outcome' => false, 'independent_validation' => false, 'credit' => false,
    'mastery' => false, 'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false];
echo intradayEncode($draft).PHP_EOL;
