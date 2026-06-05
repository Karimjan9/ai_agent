-- NeuroTrader Lab MVP database sketch.
-- Laravel migrations live in backend-laravel/database/migrations.

CREATE TABLE symbols (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL UNIQUE,
    display_name VARCHAR(64) NOT NULL,
    asset_class VARCHAR(32) NOT NULL DEFAULT 'metal',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE TABLE candles (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    symbol_id BIGINT UNSIGNED NOT NULL,
    timeframe VARCHAR(16) NOT NULL,
    time DATETIME NOT NULL,
    open DECIMAL(16, 5) NOT NULL,
    high DECIMAL(16, 5) NOT NULL,
    low DECIMAL(16, 5) NOT NULL,
    close DECIMAL(16, 5) NOT NULL,
    volume DECIMAL(20, 4) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY candles_symbol_timeframe_time_unique (symbol_id, timeframe, time),
    FOREIGN KEY (symbol_id) REFERENCES symbols(id) ON DELETE CASCADE
);

CREATE TABLE strategies (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(128) NOT NULL,
    config JSON NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE TABLE model_versions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(96) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    metadata JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE TABLE training_sessions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    model_version_id BIGINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    metrics JSON NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE SET NULL
);

CREATE TABLE backtest_runs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    symbol_id BIGINT UNSIGNED NOT NULL,
    strategy_id BIGINT UNSIGNED NULL,
    training_session_id BIGINT UNSIGNED NULL,
    timeframe VARCHAR(16) NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    request_payload JSON NULL,
    metrics JSON NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (symbol_id) REFERENCES symbols(id) ON DELETE CASCADE,
    FOREIGN KEY (strategy_id) REFERENCES strategies(id) ON DELETE SET NULL,
    FOREIGN KEY (training_session_id) REFERENCES training_sessions(id) ON DELETE SET NULL
);

CREATE TABLE trades (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    backtest_run_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(32) NOT NULL,
    timeframe VARCHAR(16) NOT NULL,
    entry_time DATETIME NOT NULL,
    exit_time DATETIME NULL,
    direction VARCHAR(8) NOT NULL,
    entry_price DECIMAL(16, 5) NOT NULL,
    exit_price DECIMAL(16, 5) NULL,
    stop_loss DECIMAL(16, 5) NOT NULL,
    take_profit DECIMAL(16, 5) NOT NULL,
    result VARCHAR(16) NULL,
    profit_loss DECIMAL(16, 5) NULL,
    mistake_type VARCHAR(64) NULL,
    reason TEXT NULL,
    indicator_snapshot JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE
);

CREATE TABLE mistakes (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    backtest_run_id BIGINT UNSIGNED NOT NULL,
    trade_id BIGINT UNSIGNED NULL,
    mistake_type VARCHAR(64) NOT NULL,
    reason TEXT NOT NULL,
    context JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE SET NULL
);

CREATE TABLE daily_reports (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    backtest_run_id BIGINT UNSIGNED NULL,
    report_date DATE NOT NULL,
    metrics JSON NOT NULL,
    conclusion TEXT NULL,
    recommendations JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY daily_reports_run_date_unique (backtest_run_id, report_date),
    FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE SET NULL
);
