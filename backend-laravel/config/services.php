<?php

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
    ],

    'market_data' => [
        'provider' => env('MARKET_DATA_PROVIDER', 'csv'),
        'fallback_provider' => env('MARKET_DATA_FALLBACK_PROVIDER'),
    ],

    'historical_data' => [
        'minimum_rows' => (int) env('HISTORICAL_MINIMUM_ROWS', 5000),
        'allowed_missing_open_hours' => (int) env('HISTORICAL_ALLOWED_MISSING_OPEN_HOURS', 0),
    ],

    'secondary_intelligence' => [
        'enabled' => env('SECONDARY_INTELLIGENCE_ENABLED', false),
    ],

    'access_control' => [
        'enforce_in_tests' => env('ACCESS_CONTROL_ENFORCE_IN_TESTS', false),
    ],

    'internal_api' => [
        'token' => env('INTERNAL_API_TOKEN') ?: (is_file(storage_path('app/internal-api.token'))
            ? trim((string) file_get_contents(storage_path('app/internal-api.token')))
            : null),
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
        'timeout_seconds' => (int) env('DUKASCOPY_TIMEOUT_SECONDS', 45),
        'batch_size' => (int) env('DUKASCOPY_BATCH_SIZE', 1),
        'pause_ms' => (int) env('DUKASCOPY_PAUSE_MS', 1000),
        'chunk_days' => (int) env('DUKASCOPY_CHUNK_DAYS', 7),
        'live_chunk_hours' => (int) env('DUKASCOPY_LIVE_CHUNK_HOURS', 1),
        'empty_response_grace_minutes' => (int) env('DUKASCOPY_EMPTY_RESPONSE_GRACE_MINUTES', 15),
        'retry_attempts' => (int) env('DUKASCOPY_RETRY_ATTEMPTS', 3),
        'instruments' => [
            'XAUUSD' => env('DUKASCOPY_XAUUSD_INSTRUMENT', 'xauusd'),
            'EURUSD' => env('DUKASCOPY_EURUSD_INSTRUMENT', 'eurusd'),
            'GBPUSD' => env('DUKASCOPY_GBPUSD_INSTRUMENT', 'gbpusd'),
        ],
    ],

    'market_reality' => [
        // Hourly feed updates only need a recent rolling window; full
        // historical rebuilds can invoke MarketRealityService explicitly.
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
        'broker' => env('PAPER_BROKER', 'simulated'),
        'units' => (float) env('PAPER_UNITS', 1),
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

    'promotion' => [
        'require_all_markets_healthy' => env('PROMOTION_REQUIRE_ALL_MARKETS_HEALTHY', true),
        'paper_min_samples' => (int) env('PROMOTION_PAPER_MIN_SAMPLES', 50),
    ],

    'oanda' => [
        'environment' => env('OANDA_ENVIRONMENT', 'practice'),
        'token' => env('OANDA_API_TOKEN'),
        'account_id' => env('OANDA_ACCOUNT_ID'),
        'base_url' => env('OANDA_BASE_URL', 'https://api-fxpractice.oanda.com'),
    ],

    'live_trading' => [
        'enabled' => env('LIVE_TRADING_ENABLED', false),
        'kill_switch_engaged' => env('LIVE_KILL_SWITCH_ENGAGED', true),
        'human_approval_sha256' => env('LIVE_HUMAN_APPROVAL_SHA256'),
        'max_capital' => (float) env('LIVE_MAX_CAPITAL', 0),
    ],

];
