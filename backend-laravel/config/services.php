<?php

$protectedSecret = static function (string $file): ?string {
    $path = storage_path('app/secrets/'.$file);
    return is_file($path) ? trim((string) file_get_contents($path)) : null;
};

return [

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
    ],

    'lab_evidence' => [
        'disk' => env('LAB_EVIDENCE_DISK', 'lab_evidence'),
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
        'allowed_missing_open_hours' => (int) env('HISTORICAL_ALLOWED_MISSING_OPEN_HOURS', 0),
        'gap_repair_limit' => (int) env('HISTORICAL_GAP_REPAIR_LIMIT', 100),
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
        'm15_node_enabled' => env('DUKASCOPY_M15_NODE_ENABLED', true),
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
        'symbols' => env('MT5_SYMBOLS', 'XAUUSD,EURUSD,GBPUSD'),
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

    // XAUUSD's official multi-timeframe pilot. H1 is a closed regime
    // controller; M15 remains an independent entry population. This
    // contract is copied into screening, replay and paper requests so the
    // execution meaning cannot drift between stages.
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

    // Economic events are an execution veto, never an alpha source.  A real
    // provider credential is required before the veto is enabled; this avoids
    // pretending that a static calendar is live news data.
    'economic_calendar' => [
        'enabled' => env('ECONOMIC_CALENDAR_ENABLED', false),
        'provider' => env('ECONOMIC_CALENDAR_PROVIDER', 'financial_modeling_prep'),
        'endpoint' => env('ECONOMIC_CALENDAR_ENDPOINT', 'https://financialmodelingprep.com/stable/economic-calendar'),
        'api_key' => env('FMP_API_KEY', env('ECONOMIC_CALENDAR_API_KEY')),
        'timeout_seconds' => (int) env('ECONOMIC_CALENDAR_TIMEOUT_SECONDS', 30),
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
        // A fast screen is only a hypothesis generator.  Fewer than this many
        // observed trades is too noisy even to spend a full replay on.
      'minimum_screening_trades' => (int) env('LAB_MINIMUM_SCREENING_TRADES', 10),
      'max_screening_jobs' => (int) env('LAB_MAX_SCREENING_JOBS', 40),
      'screen_timeout_seconds' => (int) env('LAB_SCREEN_TIMEOUT_SECONDS', 900),
      'differential_screen_timeout_seconds' => (int) env('LAB_DIFFERENTIAL_SCREEN_TIMEOUT_SECONDS', 900),
      // The Python screen can return before Laravel persists the immutable
      // trace/ledger and gate projection. Stale reservation recovery must
      // wait through this bounded post-processing window as well.
      'screen_replay_post_processing_grace_seconds' => (int) env('LAB_SCREEN_REPLAY_POST_PROCESSING_GRACE_SECONDS', 300),
      // The Python child is bounded at 3600 seconds; leave a transport
      // margin so a completed evidence response is not cut off by Laravel.
      'full_replay_timeout_seconds' => (int) env('LAB_FULL_REPLAY_TIMEOUT_SECONDS', 3900),
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
      // M15 has its own pre-2026 foundation slice built from the preserved
      // M15 market history. It must never borrow H1 history as a foundation;
      // H1 is supplied separately only as the closed regime context.
      'm15_foundation_minimum_rows' => (int) env('LAB_M15_FOUNDATION_MINIMUM_ROWS', 2000),
      'm15_foundation_start' => env('LAB_M15_FOUNDATION_START', '2025-11-01 00:00:00'),
      'm15_foundation_end' => env('LAB_M15_FOUNDATION_END', '2025-12-31 23:59:59'),
      'm15_foundation_required_end' => env('LAB_M15_FOUNDATION_REQUIRED_END', '2025-12-01 00:00:00'),
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
      // Zero means no application-level population ceiling; positive values
      // are explicit infrastructure limits for a particular deployment.
      'population_max_size' => (int) env('LAB_POPULATION_MAX_SIZE', 0),
      'portfolio_council_max_niches' => (int) env('LAB_PORTFOLIO_COUNCIL_MAX_NICHES', 0),
      'portfolio_council_source_limit' => (int) env('LAB_PORTFOLIO_COUNCIL_SOURCE_LIMIT', 0),
      'forward_failure_source_limit' => (int) env('LAB_FORWARD_FAILURE_SOURCE_LIMIT', 0),
      'evidence_complement_source_limit' => (int) env('LAB_EVIDENCE_COMPLEMENT_SOURCE_LIMIT', 0),
      'robustness_matrix_frontier_limit' => (int) env('LAB_ROBUSTNESS_MATRIX_FRONTIER_LIMIT', 0),
      'robustness_matrix_source_limit' => (int) env('LAB_ROBUSTNESS_MATRIX_SOURCE_LIMIT', 0),
      'archive_failure_limit' => (int) env('LAB_ARCHIVE_FAILURE_LIMIT', 0),
      'archive_max_per_island' => (int) env('LAB_ARCHIVE_MAX_PER_ISLAND', 0),
      'archive_migration_limit' => (int) env('LAB_ARCHIVE_MIGRATION_LIMIT', 0),
      'confirmed_parent_traits_limit' => (int) env('LAB_CONFIRMED_PARENT_TRAITS_LIMIT', 0),
      'mutation_scope_source_limit' => (int) env('LAB_MUTATION_SCOPE_SOURCE_LIMIT', 0),
      'shadow_veto_decision_limit' => (int) env('LAB_SHADOW_VETO_DECISION_LIMIT', 0),
      'governor_lookback_generations' => (int) env('LAB_GOVERNOR_LOOKBACK_GENERATIONS', 3),
      'governor_diversity_collapse_threshold' => (float) env('LAB_GOVERNOR_DIVERSITY_COLLAPSE_THRESHOLD', .35),
      'governor_stagnation_generations' => (int) env('LAB_GOVERNOR_STAGNATION_GENERATIONS', 3),
  ],

    'risk' => [
        'max_open_positions' => (int) env('RISK_MAX_OPEN_POSITIONS', 3),
        'max_positions_per_group' => (int) env('RISK_MAX_POSITIONS_PER_GROUP', 2),
        'daily_loss_limit_percent' => (float) env('RISK_DAILY_LOSS_LIMIT_PERCENT', 2),
        'max_risk_per_trade_percent' => (float) env('RISK_MAX_RISK_PER_TRADE_PERCENT', 1),
        'fx_spread_points' => (float) env('RISK_FX_SPREAD_POINTS', 12),
        'xau_spread_points' => (float) env('RISK_XAU_SPREAD_POINTS', 35),
        'slippage_points' => (float) env('RISK_SLIPPAGE_POINTS', 2),
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
        // Hard stop is intentionally independent from operator env toggles;
        // live deployment stays unavailable until the evidence protocol is
        // explicitly reopened after this repair cycle.
        'hard_stop' => env('LIVE_TRADING_HARD_STOP', true),
        'human_approval_sha256' => env('LIVE_HUMAN_APPROVAL_SHA256'),
        'max_capital' => (float) env('LIVE_MAX_CAPITAL', 0),
    ],

];
