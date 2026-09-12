<?php

$protectedSecret = static function (string $file): ?string {
    $configuredPath = $file === 'internal-api.token'
        ? trim((string) env('INTERNAL_API_TOKEN_FILE', ''))
        : '';
    $path = $configuredPath !== '' ? $configuredPath : storage_path('app/secrets/'.$file);
    if (! is_file($path)) {
        return null;
    }
    $contents = @file_get_contents($path);

    return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Versioned XAUUSD market-session research calendar
    |--------------------------------------------------------------------------
    | Civil-time windows are resolved through IANA zones, so London/New York
    | DST and their changing overlap are never frozen into one UTC-hour label.
    | Venue holidays can be added as local YYYY-MM-DD values without code
    | changes. They affect specialist scope only; observed broker availability
    | and spread/liquidity remain mandatory runtime evidence.
    */
    'market_session_calendar' => [
        'version' => env('MARKET_SESSION_CALENDAR_VERSION', 'xauusd_market_sessions_2026_v1'),
        'phases' => [
            'asia' => ['timezone' => 'Asia/Shanghai', 'start' => '08:00', 'end' => '16:00'],
            'london' => ['timezone' => 'Europe/London', 'start' => '08:00', 'end' => '16:30'],
            'new_york' => ['timezone' => 'America/New_York', 'start' => '08:00', 'end' => '17:00'],
        ],
        'holidays' => [
            'asia' => [],
            'london' => [],
            'new_york' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ai_service' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:9000'),
        'default_dataset' => env('AI_SERVICE_DEFAULT_DATASET', '../datasets/XAUUSD_H1.csv'),
        'backtest_timeout_seconds' => (int) env('AI_SERVICE_BACKTEST_TIMEOUT_SECONDS', 900),
        'strategy_lab_timeout_seconds' => (int) env('AI_SERVICE_STRATEGY_LAB_TIMEOUT_SECONDS', 2400),
        'shadow_micro_probe_max_rows' => max(128, (int) env('AI_SHADOW_MICRO_PROBE_MAX_ROWS', 512)),
        'shadow_micro_probe_max_candidates' => max(1, (int) env('AI_SHADOW_MICRO_PROBE_MAX_CANDIDATES', 6)),
    ],

    // Redis is the primary low-latency transport. The application does not
    // silently change session/queue semantics at runtime; controlled
    // failover is observable, audited and performed with the documented
    // database profile instead.
    'redis_availability' => [
        'cache_failover_store' => env('CACHE_FAILOVER_STORE', 'redis_failover'),
        'queue_failover_connection' => env('QUEUE_FAILOVER_CONNECTION', 'redis_failover'),
        'session_failover_driver' => env('SESSION_FAILOVER_DRIVER', 'database'),
        'alert_cache_store' => env('ALERT_CACHE_STORE', 'database'),
    ],

    'scheduler' => [
        // Node supplies Windows' CREATE_NO_WINDOW + detached process-group
        // combination for scheduled Artisan children. PHP proc_open cannot
        // request both without flashing a visible cmd/conhost window.
        // One renewable process lease prevents PM2/Windows reloads from
        // leaving two headless loops executing the same due callbacks.
        'lease_key' => env('SCHEDULER_LEASE_KEY', 'trading:headless-scheduler:v1'),
        // Scheduled data/backfill callbacks can legitimately exceed 90s on
        // Windows. Keep the lease renewable, but make the stale-owner window
        // longer than one bounded schedule tick.
        'lease_seconds' => max(30, (int) env('SCHEDULER_LEASE_SECONDS', 900)),
        'heartbeat_seconds' => max(5, (int) env('SCHEDULER_HEARTBEAT_SECONDS', 30)),
        'duplicate_wait_seconds' => max(1, (int) env('SCHEDULER_DUPLICATE_WAIT_SECONDS', 5)),
        // Zero keeps one long-lived headless scheduler process. A positive
        // value is an explicit bounded-rotation override for maintenance;
        // restarting after every tick makes Windows flash a console window
        // even when PM2's windowsHide flag is set.
        'max_ticks_per_process' => max(0, (int) env('SCHEDULER_MAX_TICKS_PER_PROCESS', 0)),
        // Windows callbacks run in this process. Rotate only after a complete
        // tick so dataset/audit allocations cannot accumulate indefinitely.
        'memory_limit_mb' => max(256, (int) env('SCHEDULER_MEMORY_LIMIT_MB', 768)),
    ],

    'lab_queue' => [
        // All screening candidates share one FIFO lane. Symbol-specific names
        // remain available only for draining and observing legacy rows; a
        // priority list of three queues starves later symbols whenever the
        // first queue stays non-empty.
        'screening_queue' => env('LAB_SCREENING_QUEUE', 'lab-screening'),
        // Full validation is the single priority replay lane. Keep this
        // explicit so monitors and recovery commands cannot silently drift
        // to a symbol-specific queue.
        'full_validation_queue' => env('LAB_FULL_VALIDATION_QUEUE', 'lab-full-validation'),
        // Evidence-recovery/frontier jobs are promoted into this queue while
        // they remain the only missing boundary for a generation. The queue
        // is still served by the single replay coordinator; priority changes
        // ordering, never concurrency.
        'frontier_queue' => env('LAB_FRONTIER_QUEUE', 'lab-frontier'),
        'frontier_backlog_limit' => (int) env('LAB_FRONTIER_BACKLOG_LIMIT', 4),
        'legacy_screening_queues' => ['lab-xauusd', 'lab-eurusd', 'lab-gbpusd'],
        // One canonical name is shared by Laravel's queue middleware, direct
        // portfolio replay, and stale-lock recovery. Changing it requires a
        // controlled drain because existing cache-lock rows use the old key.
        'replay_mutex_key' => env('LAB_REPLAY_MUTEX_KEY', 'neurotrader-ai-heavy-replay'),
        // Screening has its own bounded semaphore in the Python service. It
        // may use two slots over one immutable snapshot, while full replay
        // remains protected by the single coordinator key above.
        'screening_mutex_key' => env('LAB_SCREENING_MUTEX_KEY', 'neurotrader-ai-screening-replay'),
        'screening_batch_size' => max(1, min(6, (int) env('LAB_SCREENING_BATCH_SIZE', 4))),
        'screening_batch_timeout_seconds' => max(60, min(2400, (int) env('LAB_SCREENING_BATCH_TIMEOUT_SECONDS', 1800))),
        'learning_queue' => env('LAB_LEARNING_QUEUE', 'lab-learning'),
        // Historical provider/download work never runs inside the singleton
        // scheduler or the learning worker. It is rebuildable maintenance,
        // not promotion evidence, and receives its own bounded process.
        'market_maintenance_queue' => env('MARKET_MAINTENANCE_QUEUE', 'market-maintenance'),
        // A screen may yield briefly for a sealed full replay, then it must
        // attempt the lane. This bounds fairness releases instead of burning
        // an unbounded retry stream for the whole full-replay window.
        'fairness_max_deferrals' => (int) env('LAB_QUEUE_FAIRNESS_MAX_DEFERRALS', 2),
        'fairness_release_seconds' => (int) env('LAB_QUEUE_FAIRNESS_RELEASE_SECONDS', 300),
        // The shared lane is CPU-serialized, but a 10-minute release can
        // leave every screen worker asleep after a replay finishes or an
        // orphaned lock is recovered. Retry lifetime is bounded separately,
        // so a one-minute handoff keeps throughput responsive without
        // weakening full-validation priority.
        'mutex_release_seconds' => (int) env('LAB_QUEUE_MUTEX_RELEASE_SECONDS', 60),
        // A research-only learning replay may retry a transport failure once;
        // repeated full-lane failures become terminal technical quarantine so
        // retry_ready cannot turn into an infinite learning loop.
        'learning_lane_transport_failure_limit' => max(1, (int) env('LAB_LEARNING_LANE_TRANSPORT_FAILURE_LIMIT', 2)),
        // A worker restart may legitimately recover one transient training
        // lifecycle. A second stale-training recovery is terminal technical
        // evidence, otherwise a reserved full job can cycle forever without
        // ever reaching the evaluator.
        'stale_training_recovery_limit' => max(1, (int) env('LAB_STALE_TRAINING_RECOVERY_LIMIT', 1)),
        // A technical incident is durable audit history, not a permanent
        // evolution kill switch. After cooldown exactly one half-open probe
        // may run; an immutable successful evaluator run closes the breaker.
        'technical_breaker_cooldown_minutes' => max(1, (int) env('LAB_TECHNICAL_BREAKER_COOLDOWN_MINUTES', 30)),
        'technical_breaker_probe_lease_minutes' => max(1, (int) env('LAB_TECHNICAL_BREAKER_PROBE_LEASE_MINUTES', 360)),
    ],

    'lab_evidence' => [
        'disk' => env('LAB_EVIDENCE_DISK', 'lab_evidence'),
        // Canonical trace artifacts remain complete. The SQL plane stores
        // high-value events plus all low-value WAIT counts in a rollup table.
        'compact_decision_projection' => filter_var(env('LAB_COMPACT_DECISION_PROJECTION', true), FILTER_VALIDATE_BOOL),
    ],

    'market_data' => [
        'provider' => env('MARKET_DATA_PROVIDER', 'csv'),
        // One provider owns promotion evidence. Secondary feeds remain useful
        // for discrepancy observation, but never make a canonical series fail
        // merely by having a different coverage window.
        'canonical_provider' => env('MARKET_DATA_CANONICAL_PROVIDER', 'twelve'),
        'fallback_provider' => env('MARKET_DATA_FALLBACK_PROVIDER'),
    ],

    'drift_evidence' => [
        'algorithm_version' => 'canonical_drift_v2',
        'required_confirmations' => (int) env('DRIFT_REQUIRED_CONFIRMATIONS', 3),
        // A repeated check of the same frozen cutoff is still an independent
        // validation observation. Hash diversity can be required explicitly
        // in an environment with a continuously advancing feed, but it must
        // not make confirmation impossible during a static market window.
        'minimum_distinct_hashes' => (int) env('DRIFT_MINIMUM_DISTINCT_HASHES', 1),
    ],

    // Volume is a separate research feature contract. It must never inherit
    // the price provider or its fallback, because the current TwelveData
    // XAUUSD feed has zero volume while Dukascopy Jetta exposes tick volume.
    'market_volume' => [
        'provider' => env('MARKET_VOLUME_PROVIDER', 'dukascopy'),
        'transport' => env('MARKET_VOLUME_TRANSPORT', 'jetta'),
        // H1 Jetta archive volume is the canonical research unit. Tick
        // backfill remains a separate maintenance path because per-hour
        // requests across a 20-year archive can exhaust the sync budget.
        'tick_fallback_enabled' => env('MARKET_VOLUME_TICK_FALLBACK_ENABLED', false),
        'sync_chunk_months' => (int) env('MARKET_VOLUME_SYNC_CHUNK_MONTHS', 1),
        // M15 legacy files are daily.  A small checkpoint is intentionally
        // slower but lets a 429 resume without losing prior observations.
        'sync_chunk_days' => (int) env('MARKET_VOLUME_SYNC_CHUNK_DAYS', 7),
        'sync_chunk_pause_seconds' => (int) env('MARKET_VOLUME_SYNC_CHUNK_PAUSE_SECONDS', 2),
        'minimum_coverage' => (float) env('MARKET_VOLUME_MINIMUM_COVERAGE', 0.95),
        'minimum_usable_ratio' => (float) env('MARKET_VOLUME_MINIMUM_USABLE_RATIO', 0.95),
        // A complete archive with a stale tail is not live-ready evidence.
        // Keep it available for historical shadow replay, but fail the live
        // context gate until the canonical volume source catches up.
        'max_lag_hours' => (float) env('MARKET_VOLUME_MAX_LAG_HOURS', 24),
    ],

    'historical_data' => [
        'minimum_rows' => (int) env('HISTORICAL_MINIMUM_ROWS', 5000),
        // M1/M5/M30 are an isolated XAUUSD shadow plane. They earn no live
        // execution authority until the separate bid/ask execution contract
        // is complete, but still require enough rows for a meaningful audit.
        'intraday_shadow_minimum_rows' => [
            'M1' => (int) env('INTRADAY_SHADOW_M1_MINIMUM_ROWS', 10000),
            'M5' => (int) env('INTRADAY_SHADOW_M5_MINIMUM_ROWS', 2500),
            'M30' => (int) env('INTRADAY_SHADOW_M30_MINIMUM_ROWS', 500),
        ],
        'allowed_missing_open_hours' => (int) env('HISTORICAL_ALLOWED_MISSING_OPEN_HOURS', 0),
        'gap_repair_limit' => (int) env('HISTORICAL_GAP_REPAIR_LIMIT', 100),
        // A secondary feed may repair a *known* missing M1 bar only after it
        // agrees with the primary source on the surrounding day.  This is a
        // data-integrity guard, not an execution-quality endorsement.
        'intraday_gap_repair_max_median_bps' => (float) env('INTRADAY_GAP_REPAIR_MAX_MEDIAN_BPS', 25),
    ],

    'secondary_intelligence' => [
        'enabled' => env('SECONDARY_INTELLIGENCE_ENABLED', false),
    ],

    'access_control' => [
        'enforce_in_tests' => env('ACCESS_CONTROL_ENFORCE_IN_TESTS', false),
    ],

    'internal_api' => [
        'token' => $protectedSecret('internal-api.token') ?: env('INTERNAL_API_TOKEN'),
    ],

    'twelve_data' => [
        'api_key' => env('TWELVE_DATA_API_KEY'),
        'base_url' => env('TWELVE_DATA_BASE_URL', 'https://api.twelvedata.com'),
        'timeout_seconds' => (int) env('TWELVE_DATA_TIMEOUT_SECONDS', 30),
        'max_output_size' => (int) env('TWELVE_DATA_MAX_OUTPUT_SIZE', 5000),
        'instruments' => [
            'XAUUSD' => env('TWELVE_DATA_XAUUSD_SYMBOL', 'XAU/USD'),
            'EURUSD' => env('TWELVE_DATA_EURUSD_SYMBOL', 'EUR/USD'),
            'GBPUSD' => env('TWELVE_DATA_GBPUSD_SYMBOL', 'GBP/USD'),
        ],
    ],

    'cot' => [
        'endpoint' => env('COT_CFTC_ENDPOINT', 'https://publicreporting.cftc.gov/resource/72hh-3qpy.json'),
        'gold_market_name' => env('COT_GOLD_MARKET_NAME', 'GOLD - COMMODITY EXCHANGE INC.'),
        'timeout_seconds' => (int) env('COT_TIMEOUT_SECONDS', 30),
    ],

    'dukascopy' => [
        'node_binary' => env('DUKASCOPY_NODE_BINARY', 'node'),
        'transport' => env('DUKASCOPY_TRANSPORT', 'jetta'),
        'jetta_base_url' => env('DUKASCOPY_JETTA_BASE_URL', 'https://jetta.dukascopy.com'),
        // Symfony/Process still materializes a transient cmd.exe wrapper on
        // Windows, even when the nested PowerShell and Node processes use
        // WindowStyle Hidden. The PHP Jetta M15 implementation is therefore
        // the Windows default; Unix keeps the faster Node adapter.
        'm15_node_enabled' => PHP_OS_FAMILY !== 'Windows' && env('DUKASCOPY_M15_NODE_ENABLED', true),
        'http_timeout_seconds' => (int) env('DUKASCOPY_HTTP_TIMEOUT_SECONDS', 20),
        'http_retry_attempts' => (int) env('DUKASCOPY_HTTP_RETRY_ATTEMPTS', 3),
        'http_retry_pause_ms' => (int) env('DUKASCOPY_HTTP_RETRY_PAUSE_MS', 2000),
        'tick_fallback_enabled' => env('DUKASCOPY_TICK_FALLBACK_ENABLED', true),
        // Foundation archives use the monthly OHLC resource as a research
        // source. Per-hour tick reconstruction is too slow for a 20-year
        // training archive and remains an explicit opt-in diagnostic path.
        'foundation_tick_fallback_enabled' => env('DUKASCOPY_FOUNDATION_TICK_FALLBACK_ENABLED', false),
        'timeout_seconds' => (int) env('DUKASCOPY_TIMEOUT_SECONDS', 45),
        'batch_size' => (int) env('DUKASCOPY_BATCH_SIZE', 1),
        'pause_ms' => (int) env('DUKASCOPY_PAUSE_MS', 1000),
        'chunk_days' => (int) env('DUKASCOPY_CHUNK_DAYS', 7),
        'live_chunk_hours' => (int) env('DUKASCOPY_LIVE_CHUNK_HOURS', 1),
        'empty_response_grace_minutes' => (int) env('DUKASCOPY_EMPTY_RESPONSE_GRACE_MINUTES', 15),
        'retry_attempts' => (int) env('DUKASCOPY_RETRY_ATTEMPTS', 3),
        'retry_pause_ms' => (int) env('DUKASCOPY_RETRY_PAUSE_MS', 5000),
        'instruments' => [
            'XAUUSD' => env('DUKASCOPY_XAUUSD_INSTRUMENT', 'xauusd'),
            'EURUSD' => env('DUKASCOPY_EURUSD_INSTRUMENT', 'eurusd'),
            'GBPUSD' => env('DUKASCOPY_GBPUSD_INSTRUMENT', 'gbpusd'),
        ],
    ],

    'market_reality' => [
        // Hourly feed updates only need a recent rolling window; full
        // historical rebuilds can invoke MarketRealityService explicitly.
        // Market Reality is a Phase 2 foundation signal and is independent
        // from the separately frozen secondary-intelligence modules.
        'enabled' => env('MARKET_REALITY_ENABLED', true),
        // H1 candles can be 60-120 minutes old while the live feed is still
        // healthy. Feed freshness is checked independently by the feed
        // health service, so this threshold covers the analysis cadence.
        'stale_after_seconds' => (int) env('MARKET_REALITY_STALE_AFTER_SECONDS', 7200),
        'analysis_limit' => (int) env('MARKET_REALITY_ANALYSIS_LIMIT', 60),
    ],

    'telegram' => [
        'enabled' => env('TELEGRAM_ALERTS_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'mt5' => [
        'provider' => env('MT5_PROVIDER', env('MARKET_DATA_PROVIDER', 'mt5')),
        // The sovereign foundry trades one market. Other symbols may exist as
        // explicit related research inputs, but they are not active feed or
        // generation targets unless a deployment deliberately opts in.
        'symbols' => env('MT5_SYMBOLS', 'XAUUSD'),
        'timeframes' => env('MT5_TIMEFRAMES', 'M15,H1'),
        'feed_stale_after_seconds' => (int) env('MT5_FEED_STALE_AFTER_SECONDS', 900),
        'feed_lost_after_seconds' => (int) env('MT5_FEED_LOST_AFTER_SECONDS', 1200),
        'auto_recovery_enabled' => env('MT5_AUTO_RECOVERY_ENABLED', false),
        'restart_script' => env('MT5_RESTART_SCRIPT', ''),
        'restart_timeout_seconds' => (int) env('MT5_RESTART_TIMEOUT_SECONDS', 60),
    ],

    'paper' => [
        // Paper monitor is deliberately shadow-only. There is no broker
        // adapter here that can submit a live order.
        'mode' => env('PAPER_MODE', 'shadow'),
        'broker' => 'simulated',
        'units' => (float) env('PAPER_UNITS', 1),
    ],

    // One governed XAUUSD organism consumes a frozen multi-timeframe bundle.
    // H1 remains only the compatibility key of the existing laboratory rows;
    // it is not a separate organism, execution lane, or genetic population.
    'xauusd_organism' => [
        'symbol' => 'XAUUSD',
        'laboratory_storage_timeframe' => env('XAUUSD_ORGANISM_STORAGE_TIMEFRAME', 'H1'),
        'execution_timeframe' => env('XAUUSD_ORGANISM_EXECUTION_TIMEFRAME', 'M5'),
        'timeframe_roles' => [
            'H4' => 'macro_bias',
            'H1' => 'regime_and_location',
            'M15' => 'setup_and_confirmation',
            'M5' => 'entry_and_execution',
        ],
        'decision_roles' => [
            'macro_bias' => 'H4',
            'bias' => 'H1',
            'regime' => 'H1',
            'location' => 'H1',
            'setup' => 'M15',
            'confirmation' => 'M15',
            'trigger' => 'M5',
            'execution' => 'M5',
            'invalidation' => 'M5',
        ],
        'population_scope' => 'symbol',
    ],

    // One persistent operator switch controls new autonomous work. STOP is a
    // drain-first state: evidence/monitoring continue, but no new generation
    // or autonomous research cohort is admitted until `php artisan ai:start`.
    'autonomous_mode' => [
        'default_enabled' => (bool) env('NEUROTRADER_AUTONOMOUS_MODE_DEFAULT_ENABLED', true),
        // The controller profile changes only the monitoring surface. Both
        // profiles operate the same governed scheduler and neither may open
        // a generation manually or bypass admission/safety gates.
        'default_controller_profile' => env('NEUROTRADER_AUTONOMOUS_CONTROLLER_PROFILE', 'lightweight_monitor'),
        'stop_policy' => 'deny_new_work_and_drain_admitted_work',
    ],

    // XAUUSD's official multi-timeframe pilot. H1 regime context and M15/M5
    // setup/entry logic are independent causal inputs inside one organism,
    // not separate production populations. This contract is copied into
    // screening, replay and paper so execution meaning cannot drift.
    'mtf_pilot' => [
        'enabled' => (bool) env('MTF_PILOT_ENABLED', true),
        'pilot_id' => env('MTF_PILOT_ID', 'xauusd_h1_m15_v1'),
        'symbol' => env('MTF_PILOT_SYMBOL', 'XAUUSD'),
        'regime_timeframe' => env('MTF_PILOT_REGIME_TIMEFRAME', 'H1'),
        'entry_timeframe' => env('MTF_PILOT_ENTRY_TIMEFRAME', 'M15'),
        'mode' => env('MTF_PILOT_MODE', 'h1_veto_m15_risk'),
        'max_h1_staleness_seconds' => (int) env('MTF_PILOT_MAX_H1_STALENESS_SECONDS', 7200),
        'range_risk_multiplier' => (float) env('MTF_PILOT_RANGE_RISK_MULTIPLIER', 0.75),
        'high_volatility_risk_multiplier' => (float) env('MTF_PILOT_HIGH_VOLATILITY_RISK_MULTIPLIER', 0.65),
        'normal_volatility_risk_multiplier' => (float) env('MTF_PILOT_NORMAL_VOLATILITY_RISK_MULTIPLIER', 1.0),
        'low_volatility_risk_multiplier' => (float) env('MTF_PILOT_LOW_VOLATILITY_RISK_MULTIPLIER', 0.85),
        'shadow_enabled' => (bool) env('MTF_PILOT_SHADOW_ENABLED', true),
        'shadow_candidate_limit' => (int) env('MTF_PILOT_SHADOW_CANDIDATE_LIMIT', 3),
        'monitor_lookback_hours' => (int) env('MTF_PILOT_MONITOR_LOOKBACK_HOURS', 24),
        'monitor_max_m15_staleness_seconds' => (int) env('MTF_PILOT_MONITOR_MAX_M15_STALENESS_SECONDS', 1800),
        'monitor_veto_warning_rate' => (float) env('MTF_PILOT_MONITOR_VETO_WARNING_RATE', 0.80),
        'monitor_min_decisions_for_veto_warning' => (int) env('MTF_PILOT_MONITOR_MIN_DECISIONS_FOR_VETO_WARNING', 20),
        'monitor_ablation_stale_hours' => (int) env('MTF_PILOT_MONITOR_ABLATION_STALE_HOURS', 36),
    ],

    'paper_observation' => [
        'min_days' => (int) env('PAPER_OBSERVATION_MIN_DAYS', 90),
        'min_signals' => (int) env('PAPER_OBSERVATION_MIN_SIGNALS', 1000),
        'min_closed_trades' => (int) env('PAPER_OBSERVATION_MIN_CLOSED_TRADES', 200),
        'min_regimes' => (int) env('PAPER_OBSERVATION_MIN_REGIMES', 3),
        'min_trades_per_regime' => (int) env('PAPER_OBSERVATION_MIN_TRADES_PER_REGIME', 20),
        'min_profit_factor' => (float) env('PAPER_OBSERVATION_MIN_PROFIT_FACTOR', 1.3),
        'max_drawdown_percent' => (float) env('PAPER_OBSERVATION_MAX_DRAWDOWN_PERCENT', 15),
        'min_feed_uptime_percent' => (float) env('PAPER_OBSERVATION_MIN_FEED_UPTIME_PERCENT', 99.5),
        // A holdout worker that dies after opening its one-time release must
        // become an auditable failure instead of remaining "running" forever.
        'holdout_stale_minutes' => (int) env('PAPER_HOLDOUT_STALE_MINUTES', 180),
    ],

    // Economic events are an execution veto, never an alpha source. External
    // providers require a credential; official_bls uses the curated,
    // provenance-bound release schedule and never pretends to be live news.
    'economic_calendar' => [
        'enabled' => env('ECONOMIC_CALENDAR_ENABLED', false),
        'provider' => env('ECONOMIC_CALENDAR_PROVIDER', 'financial_modeling_prep'),
        'endpoint' => env('ECONOMIC_CALENDAR_ENDPOINT', 'https://financialmodelingprep.com/stable/economic-calendar'),
        'api_key' => env('FMP_API_KEY', env('ECONOMIC_CALENDAR_API_KEY')),
        // A quota/plan refusal from one FMP credential must not silently
        // disable the XAUUSD execution-news veto when an explicitly supplied
        // secondary credential is available.
        'api_key_secondary' => env('FMP_API_KEY_2'),
        'timeout_seconds' => (int) env('ECONOMIC_CALENDAR_TIMEOUT_SECONDS', 30),
        // The execution veto fails closed when the latest external-provider
        // synchronization is missing or older than the scheduler cadence.
        'max_staleness_minutes' => (int) env('ECONOMIC_CALENDAR_MAX_STALENESS_MINUTES', 420),
        'pre_event_minutes' => (int) env('ECONOMIC_CALENDAR_PRE_EVENT_MINUTES', 30),
        'post_event_minutes' => (int) env('ECONOMIC_CALENDAR_POST_EVENT_MINUTES', 30),
        'minimum_impact' => env('ECONOMIC_CALENDAR_MINIMUM_IMPACT', 'high'),
    ],

    'alpha_vantage' => [
        'api_key' => $protectedSecret('alpha-vantage-api.key') ?: env('ALPHA_VANTAGE_API_KEY'),
        'endpoint' => env('ALPHA_VANTAGE_ENDPOINT', 'https://www.alphavantage.co/query'),
        'headline_window_minutes' => (int) env('ALPHA_VANTAGE_HEADLINE_WINDOW_MINUTES', 60),
    ],

    // Published-news feeds are a short-lived execution-risk veto. They are
    // deliberately kept separate from the scheduled macro calendar: a news
    // headline must never be presented as a known future economic release.
    'currents_api' => [
        // CURRENTSAPI_API_KEY is accepted as a compatibility alias for an
        // earlier local setup; new deployments should use CURRENTS_API_KEY.
        'api_key' => $protectedSecret('currents-api.key') ?: env('CURRENTS_API_KEY', env('CURRENTSAPI_API_KEY')),
        'endpoint' => env('CURRENTS_API_ENDPOINT', 'https://api.currentsapi.services/v1/latest-news'),
        'headline_window_minutes' => (int) env('CURRENTS_API_HEADLINE_WINDOW_MINUTES', 60),
        // CurrentsAPI's free plan accepts at most 20 articles per request.
        'page_size' => (int) env('CURRENTS_API_PAGE_SIZE', 20),
    ],

    'paper_calibration' => [
        'minimum_samples' => (int) env('PAPER_CALIBRATION_MINIMUM_SAMPLES', 20),
        'minimum_calibrated_confidence' => (float) env('PAPER_CALIBRATION_MINIMUM_CONFIDENCE', 0.55),
    ],

    'lab_selection' => [
        // Constitutional data boundary: H1/M15 training, screening, replay and
        // mutation stop before 2026. 2026 is paper-only evidence.
        'training_end_exclusive' => env('LAB_TRAINING_END_EXCLUSIVE', '2026-01-01 00:00:00'),
        // A fast screen is only a hypothesis generator.  Fewer than this many
        // observed trades is too noisy even to spend a full replay on.
        'minimum_screening_trades' => (int) env('LAB_MINIMUM_SCREENING_TRADES', 10),
        'max_screening_jobs' => (int) env('LAB_MAX_SCREENING_JOBS', 40),
        // The Python screening child is hard-bounded at 900 seconds. Keep a
        // 30-second transport margin so a complete evidence response is not
        // converted into an evaluator error by Laravel's HTTP client.
        'screen_timeout_seconds' => (int) env('LAB_SCREEN_TIMEOUT_SECONDS', 930),
        'differential_screen_timeout_seconds' => (int) env('LAB_DIFFERENTIAL_SCREEN_TIMEOUT_SECONDS', 900),
        // The Python screen can return before Laravel persists the immutable
        // trace/ledger and gate projection. Stale reservation recovery must
        // wait through this bounded post-processing window as well.
        'screen_replay_post_processing_grace_seconds' => (int) env('LAB_SCREEN_REPLAY_POST_PROCESSING_GRACE_SECONDS', 300),
        // The Python child is bounded at 3600 seconds; leave a transport
        // margin so a completed evidence response is not cut off by Laravel.
        'full_replay_timeout_seconds' => (int) env('LAB_FULL_REPLAY_TIMEOUT_SECONDS', 3900),
        // Causal learning confirmation runs exactly three atomic forward folds.
        // Its AI child stops at 720s and Laravel stops waiting 60s later, so a
        // broken fold can never occupy the learning lane for an hour.
        'causal_replay_hard_timeout_seconds' => max(90, min(900, (int) env('LAB_CAUSAL_REPLAY_HARD_TIMEOUT_SECONDS', 720))),
        'causal_replay_timeout_seconds' => max(120, min(960, (int) env('LAB_CAUSAL_REPLAY_TIMEOUT_SECONDS', 780))),
        'portfolio_replay_timeout_seconds' => (int) env('LAB_PORTFOLIO_REPLAY_TIMEOUT_SECONDS', 3900),
        // The Python request can finish before Laravel persists the immutable
        // response, forward-gate projection and lifecycle close. Stale replay
        // recovery must wait through this post-processing window.
        'full_replay_post_processing_grace_seconds' => (int) env('LAB_FULL_REPLAY_POST_PROCESSING_GRACE_SECONDS', 900),
        // A 100k+ foundation replay is an explicit infrastructure budget
        // decision. Keep at least two competing candidates so CSCV/PBO cannot
        // become a meaningless singleton result; this never relaxes an
        // evidence gate and is recorded in every replay artifact.
        'full_replay_bounded_cohort_foundation_rows' => (int) env('LAB_FULL_REPLAY_BOUNDED_COHORT_FOUNDATION_ROWS', 100000),
        'full_replay_max_cohort_size' => (int) env('LAB_FULL_REPLAY_MAX_COHORT_SIZE', 2),
        // M15 has its own full pre-2026 foundation archive. It must never
        // borrow H1 history as a price foundation; H1 is supplied separately
        // only as the closed regime context.
        'm15_foundation_minimum_rows' => (int) env('LAB_M15_FOUNDATION_MINIMUM_ROWS', 2000),
        'm15_foundation_start' => env('LAB_M15_FOUNDATION_START', '2016-01-01 00:00:00'),
        'm15_foundation_end' => env('LAB_M15_FOUNDATION_END', '2025-12-31 23:59:59'),
        'm15_foundation_required_end' => env('LAB_M15_FOUNDATION_REQUIRED_END', '2025-12-01 00:00:00'),
        'm15_foundation_require_full_history' => (bool) env('LAB_M15_FOUNDATION_REQUIRE_FULL_HISTORY', true),
        'm15_rolling_start' => env('LAB_M15_ROLLING_START', '2026-01-01 00:00:00'),
        'dataset_export_lock_wait_seconds' => (int) env('LAB_DATASET_EXPORT_LOCK_WAIT_SECONDS', 30),
        // Full replay is operationally expensive, but a fixed finalist count
        // must not become an evolutionary ceiling. Zero means: the selector
        // exposes the complete eligible frontier; the dispatch command still
        // applies the current bootstrap survivor gate before queue admission.
        'max_full_validation_candidates' => (int) env('LAB_MAX_FULL_VALIDATION_CANDIDATES', 0),
        // Adaptive parent ecosystem. These values change search allocation only;
        // they never relax PF, drawdown, ruin, PBO/DSR, holdout or paper gates.
        'adaptive_parent_enabled' => env('LAB_ADAPTIVE_PARENT_ENABLED', true),
        'adaptive_archive_enabled' => env('LAB_ADAPTIVE_ARCHIVE_ENABLED', true),
        'adaptive_parent_shadow' => env('LAB_ADAPTIVE_PARENT_SHADOW', false),
        'adaptive_budget_enabled' => env('LAB_ADAPTIVE_BUDGET_ENABLED', true),
        'adaptive_causal_seat_floor' => (int) env('LAB_ADAPTIVE_CAUSAL_SEAT_FLOOR', 8),
        // Keep the initial parent ecosystem finite while it is still earning
        // its first forward-valid lineage. Zero remains an explicit operator
        // override for a deliberate frontier audit, but is not the bootstrap
        // default: robust crossover is capped at five and architecture
        // discovery at four contributors.
        'parent_max_robust' => (int) env('LAB_PARENT_MAX_ROBUST', 5),
        'parent_max_architecture' => (int) env('LAB_PARENT_MAX_ARCHITECTURE', 4),
        'parent_max_curiosity' => (int) env('LAB_PARENT_MAX_CURIOSITY', 2),
        'parent_max_runtime' => (int) env('LAB_PARENT_MAX_RUNTIME', 8),
        'parent_lineage_cap' => (float) env('LAB_PARENT_LINEAGE_CAP', .50),
        'parent_diversity_weight' => (float) env('LAB_PARENT_DIVERSITY_WEIGHT', 20),
        // Zero means the complete exact-cell frontier. Positive values are
        // explicit infrastructure caps and are recorded as such by the
        // selection contract; they are not evidence or lineage rules.
        'semantic_cell_parent_frontier' => (int) env('LAB_SEMANTIC_CELL_PARENT_FRONTIER', 0),
        'parent_candidate_frontier' => (int) env('LAB_PARENT_CANDIDATE_FRONTIER', 0),
        // Population size is an experiment budget, not an evolutionary law.
        // The historical default remains 20 for comparable runs; operators can
        // raise it without changing parent or promotion contracts.
        'population_size' => (int) env('LAB_POPULATION_SIZE', 20),
        'population_min_size' => (int) env('LAB_POPULATION_MIN_SIZE', 1),
        // A normal generation is admitted and materialized as one complete
        // twenty-seat experiment. Constructor progress is heartbeated and the
        // scheduler job has its own long, hidden process budget; deliberately
        // stopping after three seats mislabeled healthy construction as a
        // technical quarantine. The continuation path remains available only
        // for a genuinely interrupted process.
        'constructor_initial_seat_budget' => max(1, (int) env('LAB_CONSTRUCTOR_INITIAL_SEAT_BUDGET', 20)),
        // Zero means no application-level population ceiling; positive values
        // are explicit infrastructure limits for a particular deployment.
        'population_max_size' => (int) env('LAB_POPULATION_MAX_SIZE', 0),
        'portfolio_council_max_niches' => (int) env('LAB_PORTFOLIO_COUNCIL_MAX_NICHES', 0),
        'council_min_regime_specialists' => (int) env('LAB_COUNCIL_MIN_REGIME_SPECIALISTS', 2),
        'council_max_members' => (int) env('LAB_COUNCIL_MAX_MEMBERS', 6),
        'council_curriculum_enabled' => env('LAB_COUNCIL_CURRICULUM_ENABLED', true),
        'transition_min_shadow_windows' => (int) env('LAB_COUNCIL_TRANSITION_MIN_SHADOW_WINDOWS', 3),
        'transition_min_hybrid_windows' => (int) env('LAB_COUNCIL_TRANSITION_MIN_HYBRID_WINDOWS', 3),
        'transition_min_council_windows' => (int) env('LAB_COUNCIL_TRANSITION_MIN_COUNCIL_WINDOWS', 3),
        'transition_min_anchor_ablation_windows' => (int) env('LAB_COUNCIL_TRANSITION_MIN_ANCHOR_ABLATION_WINDOWS', 2),
        'transition_baseline_tolerance' => (float) env('LAB_COUNCIL_TRANSITION_BASELINE_TOLERANCE', .03),
        'transition_max_worst_window_regression' => (float) env('LAB_COUNCIL_TRANSITION_MAX_WORST_WINDOW_REGRESSION', .05),
        'transition_max_router_switch_rate' => (float) env('LAB_COUNCIL_TRANSITION_MAX_ROUTER_SWITCH_RATE', .25),
        'transition_max_anchor_dependency' => (float) env('LAB_COUNCIL_TRANSITION_MAX_ANCHOR_DEPENDENCY', .20),
        'portfolio_council_source_limit' => (int) env('LAB_PORTFOLIO_COUNCIL_SOURCE_LIMIT', 0),
        'forward_failure_source_limit' => (int) env('LAB_FORWARD_FAILURE_SOURCE_LIMIT', 0),
        'evidence_complement_source_limit' => (int) env('LAB_EVIDENCE_COMPLEMENT_SOURCE_LIMIT', 0),
        'robustness_matrix_frontier_limit' => (int) env('LAB_ROBUSTNESS_MATRIX_FRONTIER_LIMIT', 0),
        'robustness_matrix_source_limit' => (int) env('LAB_ROBUSTNESS_MATRIX_SOURCE_LIMIT', 0),
        'archive_failure_limit' => (int) env('LAB_ARCHIVE_FAILURE_LIMIT', 0),
        // Archive lookup is a ranked diagnostic frontier, not an exhaustive
        // evidence scan. Thousands of historical rows (especially the
        // differential router) made one new seat spend tens of minutes
        // hydrating repeated model metadata. The complete archive remains in
        // storage; only construction-time retrieval is bounded.
        'archive_max_per_island' => max(1, (int) env('LAB_ARCHIVE_MAX_PER_ISLAND', 64)),
        'archive_migration_limit' => max(1, (int) env('LAB_ARCHIVE_MIGRATION_LIMIT', 16)),
        'confirmed_parent_traits_limit' => (int) env('LAB_CONFIRMED_PARENT_TRAITS_LIMIT', 0),
        'mutation_scope_source_limit' => (int) env('LAB_MUTATION_SCOPE_SOURCE_LIMIT', 0),
        'shadow_veto_decision_limit' => (int) env('LAB_SHADOW_VETO_DECISION_LIMIT', 0),
        'governor_lookback_generations' => (int) env('LAB_GOVERNOR_LOOKBACK_GENERATIONS', 3),
        'governor_diversity_collapse_threshold' => (float) env('LAB_GOVERNOR_DIVERSITY_COLLAPSE_THRESHOLD', .35),
        'governor_stagnation_generations' => (int) env('LAB_GOVERNOR_STAGNATION_GENERATIONS', 3),
        // Risk-bounded exploration changes research allocation and mutation
        // amplitude only. It never relaxes screening, replay, forward, paper or
        // promotion gates.
        'risk_bounded_exploration_enabled' => env('LAB_RISK_BOUNDED_EXPLORATION_ENABLED', true),
        'risk_bounded_exploration_seats' => (int) env('LAB_RISK_BOUNDED_EXPLORATION_SEATS', 8),
        'bold_mutation_step_multiplier' => (float) env('LAB_BOLD_MUTATION_STEP_MULTIPLIER', 2.0),
        'proven_gene_step_multiplier' => (float) env('LAB_PROVEN_GENE_STEP_MULTIPLIER', 1.5),
        'screen_pass_step_multiplier' => (float) env('LAB_SCREEN_PASS_STEP_MULTIPLIER', 1.2),
        'uncertainty_step_multiplier' => (float) env('LAB_UNCERTAINTY_STEP_MULTIPLIER', .75),
        // A complete, healthy frozen-control failure may open a research-only
        // shadow cohort. This changes search allocation only; it never opens
        // parent, paper, forward-promotion or champion permissions.
        'shadow_research_enabled' => env('LAB_SHADOW_RESEARCH_ENABLED', true),
        'shadow_research_max_consecutive_generations' => (int) env('LAB_SHADOW_RESEARCH_MAX_CONSECUTIVE_GENERATIONS', 3),
        'shadow_research_max_full_replays_per_generation' => (int) env('LAB_SHADOW_RESEARCH_MAX_FULL_REPLAYS_PER_GENERATION', 2),
        // A screen-positive cohort must produce replay evidence before another
        // generic cohort is allowed to multiply the unresolved learning queue.
        'learning_velocity_enabled' => env('LAB_LEARNING_VELOCITY_ENABLED', true),
        'learning_velocity_lookback_generations' => (int) env('LAB_LEARNING_VELOCITY_LOOKBACK_GENERATIONS', 3),
        'learning_velocity_max_unresolved_screen_generations' => (int) env('LAB_LEARNING_VELOCITY_MAX_UNRESOLVED_SCREEN_GENERATIONS', 1),
        'learning_starvation_stale_seconds' => (int) env('LAB_LEARNING_STARVATION_STALE_SECONDS', 1800),
        'learning_starvation_min_pending_dojo' => (int) env('LAB_LEARNING_STARVATION_MIN_PENDING_DOJO', 1),
        // Three terminal zero-pass cohorts are a strategy deadlock, not a
        // learning-worker outage. While autonomous mode is enabled, fresh-data
        // generations may accumulate this evidence; the threshold then opens
        // only a bounded structural escape and never relaxes promotion gates.
        'zero_pass_circuit_breaker_generations' => (int) env('LAB_ZERO_PASS_CIRCUIT_BREAKER_GENERATIONS', 3),
        // Parent-aware evolution. A parent can propose a bounded skill, but it
        // cannot replace the child's autonomous branch or bypass evidence gates.
        'parent_mentor_broker_enabled' => env('LAB_PARENT_MENTOR_BROKER_ENABLED', true),
        'parent_assisted_seats' => (int) env('LAB_PARENT_ASSISTED_SEATS', 2),
        'parent_autonomous_minimum_share' => (float) env('LAB_PARENT_AUTONOMOUS_MINIMUM_SHARE', .25),
        'parent_trust_decay_days' => (int) env('LAB_PARENT_TRUST_DECAY_DAYS', 30),
        'parent_trust_floor' => (float) env('LAB_PARENT_TRUST_FLOOR', .15),
        'parent_trust_ceiling' => (float) env('LAB_PARENT_TRUST_CEILING', .85),
        'parent_counterfactual_required' => env('LAB_PARENT_COUNTERFACTUAL_REQUIRED', true),
        'parent_counterfactual_branches' => ['autonomous', 'mentored', 'ablated'],
        'parent_credit_min_incremental_value' => (float) env('LAB_PARENT_CREDIT_MIN_INCREMENTAL_VALUE', .0001),
        'evolution_credit_enabled' => env('LAB_EVOLUTION_CREDIT_ENABLED', true),
        'evidence_quarantine_sandbox_enabled' => env('LAB_EVIDENCE_QUARANTINE_SANDBOX_ENABLED', true),
        'council_ablation_required_before_official' => env('LAB_COUNCIL_ABLATION_REQUIRED_BEFORE_OFFICIAL', true),
        'council_ablation_roles' => ['entry', 'risk', 'regime', 'volume_temporal'],
        // Brave research is explicit, deterministic and sandboxed. Percentages
        // apply to experimental seats after frozen controls are reserved.
        'hybrid_evolution_enabled' => env('LAB_HYBRID_EVOLUTION_ENABLED', true),
        'hybrid_directed_repair_share' => (float) env('LAB_HYBRID_DIRECTED_REPAIR_SHARE', .60),
        'hybrid_bold_structural_share' => (float) env('LAB_HYBRID_BOLD_STRUCTURAL_SHARE', .25),
        'hybrid_adversarial_share' => (float) env('LAB_HYBRID_ADVERSARIAL_SHARE', .15),
        'hybrid_control_seats' => (int) env('LAB_HYBRID_CONTROL_SEATS', 2),
        'hybrid_bold_max_changed_genes' => (int) env('LAB_HYBRID_BOLD_MAX_CHANGED_GENES', 3),
        'hybrid_adversarial_max_changed_genes' => (int) env('LAB_HYBRID_ADVERSARIAL_MAX_CHANGED_GENES', 3),
    ],

    // Targeted failure research has its own admission budget. A changed
    // number inside the same temporal failure family is not new evidence.
    'rescue_circuit_breaker' => [
        'enabled' => env('LAB_RESCUE_CIRCUIT_BREAKER_ENABLED', true),
        'consecutive_cohorts' => (int) env('LAB_RESCUE_CIRCUIT_BREAKER_COHORTS', 3),
        'minimum_siblings' => (int) env('LAB_RESCUE_CIRCUIT_BREAKER_SIBLINGS', 12),
        'max_siblings_per_hypothesis' => (int) env('LAB_RESCUE_MAX_SIBLINGS_PER_HYPOTHESIS', 12),
        // H1 requires one closed-day tail; M15 is scaled to the equivalent
        // four 15-minute bars per hour. A sealed independent holdout is an
        // explicit alternative and never inferred from a profile hash.
        'minimum_fresh_candles' => (int) env('LAB_RESCUE_MINIMUM_FRESH_CANDLES', 24),
        'target_margin_threshold' => (float) env('LAB_RESCUE_TARGET_MARGIN_THRESHOLD', 1.0),
        'minimum_margin_progress' => (float) env('LAB_RESCUE_MINIMUM_MARGIN_PROGRESS', .05),
        'ablation_windows' => (int) env('LAB_TEMPORAL_ABLATION_WINDOWS', 3),
        'temporal_threshold' => (float) env('LAB_TEMPORAL_ABLATION_THRESHOLD', 1.0),
        // A clean ablation must start from a materially new foundation
        // snapshot. Hashes alone are not enough: the operator manifest must
        // also attest to coverage, source, timezone and bounded overlap.
        'foundation_minimum_candles' => (int) env('LAB_TEMPORAL_FOUNDATION_MINIMUM_CANDLES', 72),
        'foundation_max_overlap_ratio' => (float) env('LAB_TEMPORAL_FOUNDATION_MAX_OVERLAP_RATIO', 0.0),
        'window_minimum_candles' => (int) env('LAB_TEMPORAL_WINDOW_MINIMUM_CANDLES', 24),
        'window_minimum_trades' => (int) env('LAB_TEMPORAL_WINDOW_MINIMUM_TRADES', 5),
    ],

    // Champion and Council are observed as two independent runtime lanes.
    // Shadow is the only safe default: it records capability-cell evidence
    // without changing the incumbent paper owner.
    'dual_track' => [
        'enabled' => env('DUAL_TRACK_ENABLED', true),
        'mode' => env('DUAL_TRACK_MODE', 'shadow'),
        'default_lane' => env('DUAL_TRACK_DEFAULT_LANE', 'incumbent'),
        'cell_routes' => [],
        'require_independent_outputs' => env('DUAL_TRACK_REQUIRE_INDEPENDENT_OUTPUTS', true),
        'unresolved_route' => env('DUAL_TRACK_UNRESOLVED_ROUTE', 'wait'),
        'activate_certified_cells' => env('DUAL_TRACK_ACTIVATE_CERTIFIED_CELLS', false),
        'cell_minimum_samples' => max(1, (int) env('DUAL_TRACK_CELL_MINIMUM_SAMPLES', 30)),
        'cell_minimum_score_margin' => (float) env('DUAL_TRACK_CELL_MINIMUM_SCORE_MARGIN', 2.0),
        'minimum_confidence_lower_bound' => (float) env('DUAL_TRACK_MINIMUM_CONFIDENCE_LOWER_BOUND', .55),
        'require_calibration_for_active' => env('DUAL_TRACK_REQUIRE_CALIBRATION_FOR_ACTIVE', true),
        'evaluator_minimum_samples' => max(1, (int) env('DUAL_TRACK_EVALUATOR_MINIMUM_SAMPLES', 20)),
        'max_evaluator_calibration_error' => (float) env('DUAL_TRACK_MAX_EVALUATOR_CALIBRATION_ERROR', .20),
        'max_risk_of_ruin_percent' => (float) env('DUAL_TRACK_MAX_RISK_OF_RUIN_PERCENT', 10),
        'max_drawdown_percent' => (float) env('DUAL_TRACK_MAX_DRAWDOWN_PERCENT', 15),
        'transition_size_multiplier' => (float) env('DUAL_TRACK_TRANSITION_SIZE_MULTIPLIER', .5),
        'memory_minimum_confirmations' => max(2, (int) env('DUAL_TRACK_MEMORY_MINIMUM_CONFIRMATIONS', 3)),
    ],

    'twin_intelligence' => [
        'version' => env('TWIN_INTELLIGENCE_VERSION', '1.0.0'),
        'require_independent_inference' => env('TWIN_INTELLIGENCE_REQUIRE_INDEPENDENT_INFERENCE', true),
        'diversity_minimum_samples' => max(1, (int) env('TWIN_INTELLIGENCE_DIVERSITY_MINIMUM_SAMPLES', 20)),
        'max_agreement_rate' => (float) env('TWIN_INTELLIGENCE_MAX_AGREEMENT_RATE', .95),
        'reflection_minimum_confirmations' => max(2, (int) env('TWIN_INTELLIGENCE_REFLECTION_MINIMUM_CONFIRMATIONS', 2)),
        'red_team_damage_threshold' => (float) env('TWIN_INTELLIGENCE_RED_TEAM_DAMAGE_THRESHOLD', .25),
        // WAIT-only runs produce three applicable adversarial trials; an
        // action run may produce four and is still required to pass all four.
        'red_team_minimum_trials' => max(1, (int) env('TWIN_INTELLIGENCE_RED_TEAM_MINIMUM_TRIALS', 3)),
        'promotion_decision_ttl_minutes' => max(1, (int) env('TWIN_INTELLIGENCE_PROMOTION_DECISION_TTL_MINUTES', 15)),
        'require_snapshot_manifest' => env('TWIN_INTELLIGENCE_REQUIRE_SNAPSHOT_MANIFEST', true),
        'drift_cusum_slack' => (float) env('TWIN_INTELLIGENCE_DRIFT_CUSUM_SLACK', .05),
        'drift_cusum_threshold' => (float) env('TWIN_INTELLIGENCE_DRIFT_CUSUM_THRESHOLD', 2.5),
        'gene_bootstrap_pf_floor' => (float) env('TWIN_INTELLIGENCE_GENE_BOOTSTRAP_PF_FLOOR', 1.05),
        'gene_dsr_probability_floor' => (float) env('TWIN_INTELLIGENCE_GENE_DSR_PROBABILITY_FLOOR', .95),
        'gene_pbo_ceiling' => (float) env('TWIN_INTELLIGENCE_GENE_PBO_CEILING', .20),
    ],

    'risk' => [
        'max_open_positions' => (int) env('RISK_MAX_OPEN_POSITIONS', 3),
        'max_positions_per_group' => (int) env('RISK_MAX_POSITIONS_PER_GROUP', 2),
        'daily_loss_limit_percent' => (float) env('RISK_DAILY_LOSS_LIMIT_PERCENT', 2),
        'max_risk_per_trade_percent' => (float) env('RISK_MAX_RISK_PER_TRADE_PERCENT', 1),
        // Risk Sentinel owns executable sizing. It may shrink the fixed
        // ceiling, never raise it; martingale, full Kelly and automatic live
        // geometric compounding are intentionally absent.
        'paper_starting_equity' => (float) env('PAPER_STARTING_EQUITY', 10000),
        'sentinel_capped_fractional_risk_percent' => (float) env('RISK_SENTINEL_CAPPED_FRACTIONAL_RISK_PERCENT', .75),
        'sentinel_max_drawdown_percent' => (float) env('RISK_SENTINEL_MAX_DRAWDOWN_PERCENT', 15),
        'sentinel_max_risk_of_ruin_percent' => (float) env('RISK_SENTINEL_MAX_RISK_OF_RUIN_PERCENT', 10),
        'sentinel_min_reward_risk' => (float) env('RISK_SENTINEL_MIN_REWARD_RISK', 1),
        'fx_spread_points' => (float) env('RISK_FX_SPREAD_POINTS', 12),
        'xau_spread_points' => (float) env('RISK_XAU_SPREAD_POINTS', 35),
        'slippage_points' => (float) env('RISK_SLIPPAGE_POINTS', 2),
    ],

    // Smart Discipline is a process-integrity authority, not a new alpha
    // model. Limits are conservative containment defaults and remain
    // configurable; the engine can veto or shrink, never increase risk.
    'discipline' => [
        'enabled' => env('SMART_DISCIPLINE_ENABLED', true),
        'minimum_reward_risk' => max(0, (float) env('SMART_DISCIPLINE_MIN_REWARD_RISK', 1.0)),
        'late_entry_max_stop_units' => max(0, (float) env('SMART_DISCIPLINE_LATE_ENTRY_MAX_STOP_UNITS', .5)),
        'weekly_loss_limit_percent' => max(0, (float) env('SMART_DISCIPLINE_WEEKLY_LOSS_LIMIT_PERCENT', 5)),
        'max_trades_per_session' => max(1, (int) env('SMART_DISCIPLINE_MAX_TRADES_PER_SESSION', 4)),
        'max_trades_per_day' => max(1, (int) env('SMART_DISCIPLINE_MAX_TRADES_PER_DAY', 8)),
        'max_consecutive_losses' => max(1, (int) env('SMART_DISCIPLINE_MAX_CONSECUTIVE_LOSSES', 4)),
        'loss_cooldown_minutes' => max(0, (int) env('SMART_DISCIPLINE_LOSS_COOLDOWN_MINUTES', 20)),
    ],

    // The learning lane is deliberately separate from promotion selection.
    // It can spend a small, bounded amount of replay capacity on a paired
    // near-miss, but it can never lower a gate or create paper evidence.
    'learning_lane' => [
        'enabled' => env('LAB_LEARNING_LANE_ENABLED', true),
        // Autonomous replay is research-only and serialized through the same
        // heavy evaluator mutex. Keep one seat per scheduler tick so a legacy
        // backlog cannot starve screening or promotion validation.
        'autonomous_dispatch_enabled' => env('LAB_LEARNING_LANE_AUTONOMOUS_ENABLED', true),
        'autonomous_max_dispatch' => max(1, min(2, (int) env('LAB_LEARNING_LANE_AUTONOMOUS_MAX_DISPATCH', 1))),
        'max_per_role' => (int) env('LAB_LEARNING_LANE_MAX_PER_ROLE', 1),
        'max_total_per_generation' => (int) env('LAB_LEARNING_LANE_MAX_TOTAL_PER_GENERATION', 4),
        // Read-only control materialization previews are deliberately capped
        // so a legacy backlog cannot compete with live replay workers.
        'materialization_preview_limit' => max(1, (int) env('LAB_LEARNING_LANE_MATERIALIZATION_PREVIEW_LIMIT', 50)),
        'provisional_skill_ttl_days' => (int) env('LAB_LEARNING_LANE_PROVISIONAL_SKILL_TTL_DAYS', 30),
        'independent_confirmations_required' => max(3, (int) env('LAB_LEARNING_LANE_INDEPENDENT_CONFIRMATIONS', 3)),
        'confirmation_maximum_holding_bars' => max(24, (int) env('LAB_LEARNING_CONFIRMATION_MAXIMUM_HOLDING_BARS', 240)),
        'causal_fold_count' => max(6, min(12, (int) env('LAB_LEARNING_CAUSAL_FOLD_COUNT', 9))),
        'causal_max_rows_per_fold' => max(2048, min(8192, (int) env('LAB_LEARNING_CAUSAL_MAX_ROWS_PER_FOLD', 4096))),
        'causal_audit_trace_rows' => max(128, min(1024, (int) env('LAB_LEARNING_CAUSAL_AUDIT_TRACE_ROWS', 512))),
        'causal_minimum_trades_per_window' => max(1, (int) env('LAB_LEARNING_CAUSAL_MIN_TRADES_PER_WINDOW', 8)),
        'causal_minimum_powered_windows' => max(3, min(9, (int) env('LAB_LEARNING_CAUSAL_MIN_POWERED_WINDOWS', 6))),
        'causal_minimum_positive_windows' => max(2, min(9, (int) env('LAB_LEARNING_CAUSAL_MIN_POSITIVE_WINDOWS', 4))),
        'confirmation_max_attempts' => max(1, min(5, (int) env('LAB_LEARNING_CONFIRMATION_MAX_ATTEMPTS', 3))),
        'micro_windows_required' => (int) env('LAB_LEARNING_LANE_MICRO_WINDOWS_REQUIRED', 3),
        'micro_positive_windows_required' => (int) env('LAB_LEARNING_LANE_MICRO_POSITIVE_WINDOWS_REQUIRED', 2),
        // A 2-of-3 causal near-pass is not a global skill. It may spend one
        // serialized full-replay seat so the winning contexts can be separated
        // from the hard-failure context instead of discarding both signals.
        'autonomous_contextual_near_pass_enabled' => env('LAB_LEARNING_LANE_CONTEXTUAL_NEAR_PASS_ENABLED', true),
        'negative_downrank_after' => (int) env('LAB_LEARNING_LANE_NEGATIVE_DOWNRANK_AFTER', 3),
        'negative_quarantine_after' => (int) env('LAB_LEARNING_LANE_NEGATIVE_QUARANTINE_AFTER', 5),
    ],

    // Sparse activation prevents a state router from silently turning every
    // available indicator into one untestable mega-strategy.
    'instrument_policy' => [
        'minimum_active_instruments' => (int) env('INSTRUMENT_POLICY_MIN_ACTIVE', 3),
        'maximum_active_instruments' => (int) env('INSTRUMENT_POLICY_MAX_ACTIVE', 6),
        'minimum_posterior_observations' => max(3, (int) env('INSTRUMENT_POLICY_MIN_POSTERIOR_OBSERVATIONS', 3)),
        'minimum_independent_windows' => max(3, (int) env('INSTRUMENT_POLICY_MIN_INDEPENDENT_WINDOWS', 3)),
        'minimum_confirmed_net_utility' => max(.00001, (float) env('INSTRUMENT_POLICY_MIN_CONFIRMED_NET_UTILITY', .001)),
        'minimum_forbidden_net_utility' => min(-.00001, (float) env('INSTRUMENT_POLICY_MIN_FORBIDDEN_NET_UTILITY', -.001)),
    ],

    // Versioned parameters consumed by lab, full replay, paper and holdout.
    // Keep the risk spread as the single source of truth; a lane must not
    // quietly substitute a cheaper backtest assumption.
    'execution_contract' => [
        'protocol' => 'canonical_market_execution_v1',
        'version' => 'canonical_market_execution_v1',
        'fx_spread_points' => (float) env('EXECUTION_FX_SPREAD_POINTS', env('RISK_FX_SPREAD_POINTS', 12)),
        'xau_spread_points' => (float) env('EXECUTION_XAU_SPREAD_POINTS', env('RISK_XAU_SPREAD_POINTS', 35)),
        'xau_point_size' => (float) env('EXECUTION_XAU_POINT_SIZE', 0.01),
        'fx_point_size' => (float) env('EXECUTION_FX_POINT_SIZE', 0.00001),
        'commission_percent' => (float) env('EXECUTION_COMMISSION_PERCENT', 0.01),
        'slippage_points' => (float) env('EXECUTION_SLIPPAGE_POINTS', env('RISK_SLIPPAGE_POINTS', 2)),
        'swap_per_day_percent' => (float) env('EXECUTION_SWAP_PER_DAY_PERCENT', 0.002),
        'allowed_sessions_utc' => ['1-22'],
        'intrabar_policy' => 'conservative',
        'max_gap_multiple' => 96,
        'reject_unexpected_gaps' => true,
        'stop_loss_percent' => 0.5,
        'take_profit_percent' => 1.0,
        'max_leverage' => 5,
    ],

    'promotion' => [
        'require_all_markets_healthy' => env('PROMOTION_REQUIRE_ALL_MARKETS_HEALTHY', true),
        'paper_min_samples' => (int) env('PROMOTION_PAPER_MIN_SAMPLES', 50),
        // Champion replacement is paused while the evidence/recovery
        // pipeline is being repaired. Forward and paper observations may
        // continue, but no candidate may become champion from them.
        'freeze_champion' => env('PROMOTION_FREEZE_CHAMPION', true),
    ],

    'live_trading' => [
        'enabled' => env('LIVE_TRADING_ENABLED', false),
        'kill_switch_engaged' => env('LIVE_KILL_SWITCH_ENGAGED', true),
        'hard_stop' => env('LIVE_TRADING_HARD_STOP', true),
        'human_approval_sha256' => env('LIVE_HUMAN_APPROVAL_SHA256'),
        'max_capital' => (float) env('LIVE_MAX_CAPITAL', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | NeuroTrader Lifecycle Orchestrator
    |--------------------------------------------------------------------------
    | Safe defaults for the resumable lifecycle orchestrator. Operators may
    | override via environment variables. None of these bypass project gates.
    */
    'lifecycle_orchestrator' => [
        // Master enable/disable flag for the cycle command.
        'enabled' => env('NEUROTRADER_LIFECYCLE_ENABLED', true),
        // Maximum bound for a single recovery dispatch batch (dojo tasks).
        'max_recovery_dispatch' => (int) env('NEUROTRADER_LIFECYCLE_MAX_RECOVERY_DISPATCH', 3),
        // Cooldown (seconds) between a generation being created and the next.
        'generation_cooldown_seconds' => (int) env('NEUROTRADER_LIFECYCLE_GENERATION_COOLDOWN_SECONDS', 300),
        // Cycle lock TTL (seconds) so a crashed cycle does not block forever.
        'lock_ttl_seconds' => (int) env('NEUROTRADER_LIFECYCLE_LOCK_TTL_SECONDS', 3000),
        // Error-log retention in days.
        'log_retention_days' => (int) env('NEUROTRADER_LIFECYCLE_LOG_RETENTION_DAYS', 14),
        'draft_timeout_seconds' => (int) env('NEUROTRADER_LIFECYCLE_DRAFT_TIMEOUT_SECONDS', 5400),
        'max_job_attempts' => (int) env('NEUROTRADER_LIFECYCLE_MAX_JOB_ATTEMPTS', 3),
        'max_stale_reserved_jobs' => (int) env('NEUROTRADER_LIFECYCLE_MAX_STALE_RESERVED_JOBS', 1),
        'max_failed_jobs' => (int) env('NEUROTRADER_LIFECYCLE_MAX_FAILED_JOBS', 1),
        'failed_job_window_seconds' => (int) env('NEUROTRADER_LIFECYCLE_FAILED_JOB_WINDOW_SECONDS', 3600),
        'recovery_cooldown_seconds' => (int) env('NEUROTRADER_LIFECYCLE_RECOVERY_COOLDOWN_SECONDS', 900),
        'max_recovery_dispatch_per_day' => (int) env('NEUROTRADER_LIFECYCLE_MAX_RECOVERY_DISPATCH_PER_DAY', 9),
        'autonomous_learning_recovery_enabled' => env('NEUROTRADER_AUTONOMOUS_LEARNING_RECOVERY_ENABLED', true),
        // The scheduler may retry only immutable screening transport
        // timeouts, once per agent, after authenticated AI readiness and
        // same-generation dataset-hash verification. Other technical faults
        // still require an explicit operator repair mode.
        'autonomous_technical_recovery_enabled' => env('NEUROTRADER_AUTONOMOUS_TECHNICAL_RECOVERY_ENABLED', true),
        'autonomous_technical_recovery_max_dispatch' => max(1, min(2, (int) env('NEUROTRADER_AUTONOMOUS_TECHNICAL_RECOVERY_MAX_DISPATCH', 2))),
        // Per-agent recovery is already one-shot and daily bounded. A short
        // batch cooldown lets a shared incident across one 20-seat cohort
        // drain over successive lifecycle ticks instead of blocking it for
        // hours, without increasing either safety budget.
        'autonomous_technical_recovery_cooldown_seconds' => max(60, min(900, (int) env('NEUROTRADER_AUTONOMOUS_TECHNICAL_RECOVERY_COOLDOWN_SECONDS', 60))),
        // A single 20-seat generation can be hit by one shared runtime
        // incident. Keep every agent one-shot and every dispatch at two, but
        // allow that one complete cohort to drain autonomously in a day.
        'autonomous_technical_recovery_daily_limit' => max(1, min(20, (int) env('NEUROTRADER_AUTONOMOUS_TECHNICAL_RECOVERY_DAILY_LIMIT', 20))),
    ],

    // Bounded learning-recovery dispatch limit reuse (shadow lane only).
    'learning_lane_recovery_limit' => (int) env('NEUROTRADER_LEARNING_RECOVERY_LIMIT', 3),

    // Production admission for the autonomous Edge-to-Mastery state machine.
    // Two scheduler observations must agree that the replay lane is idle;
    // delayed jobs alone are not classified as a retry storm.
    'edge_director' => [
        // The production default has one generation authority: the normal
        // twenty-seat lifecycle. Edge Genesis may finish/reconcile existing
        // immutable trials, but it must not pre-empt that lifecycle by opening
        // a separate five-seat generation every minute. Deliberate causal-lab
        // runs can opt in without changing the normal production contract.
        'autonomous_specialized_cohorts_enabled' => (bool) env('EDGE_DIRECTOR_AUTONOMOUS_SPECIALIZED_COHORTS_ENABLED', false),
        'idle_stability_seconds' => max(0, (int) env('EDGE_DIRECTOR_IDLE_STABILITY_SECONDS', 10)),
        'idle_stability_max_age_seconds' => max(30, (int) env('EDGE_DIRECTOR_IDLE_STABILITY_MAX_AGE_SECONDS', 180)),
        'retry_storm_attempts' => max(2, (int) env('EDGE_DIRECTOR_RETRY_STORM_ATTEMPTS', 5)),
        'failed_jobs_per_ten_minutes' => max(1, (int) env('EDGE_DIRECTOR_FAILED_JOBS_PER_TEN_MINUTES', 3)),
        'comparable_scalar_generations' => max(3, (int) env('EDGE_DIRECTOR_COMPARABLE_SCALAR_GENERATIONS', 3)),
        'compiled_hypothesis_budget' => max(1, min(12, (int) env('EDGE_DIRECTOR_COMPILED_HYPOTHESIS_BUDGET', 10))),
        'compiled_hypothesis_budget_per_baseline' => max(1, min(5, (int) env('EDGE_DIRECTOR_COMPILED_HYPOTHESIS_BUDGET_PER_BASELINE', 3))),
        'compiled_hypothesis_budget_per_axis_epoch' => max(1, min(3, (int) env('EDGE_DIRECTOR_COMPILED_HYPOTHESIS_BUDGET_PER_AXIS_EPOCH', 2))),
        'promotion_debt_limit' => max(1, min(30, (int) env('EDGE_DIRECTOR_PROMOTION_DEBT_LIMIT', 8))),
        'fair_interleave_cooldown_seconds' => max(5, min(300, (int) env('EDGE_DIRECTOR_FAIR_INTERLEAVE_COOLDOWN_SECONDS', 30))),
    ],

    'release_seal' => [
        'required' => env('RELEASE_SEAL_REQUIRED', false),
        'manifest_path' => env('RELEASE_SEAL_MANIFEST_PATH') ?: storage_path('app/release/release-manifest.json'),
    ],

];
