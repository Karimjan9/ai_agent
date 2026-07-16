from datetime import date, datetime
from typing import Any, Literal

from pydantic import BaseModel, Field, model_validator


Timeframe = Literal["M15", "H1"]
Direction = Literal["long", "short"]
TradeResult = Literal["win", "loss", "open"]


class Candle(BaseModel):
    time: datetime
    open: float
    high: float
    low: float
    close: float
    volume: float | None = None


class StrategyConfig(BaseModel):
    ema_fast: int = Field(default=50, ge=2)
    ema_slow: int = Field(default=200, ge=3)
    rsi_period: int = Field(default=14, ge=2)
    atr_period: int = Field(default=14, ge=2)
    atr_stop_multiplier: float = Field(default=1.5, gt=0)
    risk_reward: float = Field(default=2.0, gt=0)
    swing_lookback: int = Field(default=80, ge=10)
    fibonacci_min: float = Field(default=0.382, ge=0, le=1)
    fibonacci_max: float = Field(default=0.618, ge=0, le=1)
    initial_balance: float = Field(default=10000.0, gt=0)


class BacktestRequest(BaseModel):
    symbol: str = "XAU/USD"
    timeframe: Timeframe = "M15"
    strategy_name: str = "ema_rsi_v1"
    from_date: date | None = None
    to_date: date | None = None
    dataset_path: str | None = None
    candles: list[Candle] | None = None
    strategy: StrategyConfig = Field(default_factory=StrategyConfig)

    @model_validator(mode="after")
    def require_data_source(self) -> "BacktestRequest":
        if not self.dataset_path and not self.candles:
            raise ValueError("Provide either dataset_path or candles.")
        return self


class StrategyRuntimeConfig(BaseModel):
    strategy: str
    base_strategy: str | None = None
    version: str | None = None
    parameters: dict[str, Any] = Field(default_factory=dict)


class ExecutionConfig(BaseModel):
    spread_points: float = Field(default=0.0, ge=0)
    point_size: float = Field(default=0.01, gt=0)
    commission_percent: float = Field(default=0.0, ge=0)
    slippage_points: float = Field(default=0.0, ge=0)
    swap_per_day_percent: float = Field(default=0.0, ge=0)
    allowed_sessions_utc: list[str] = Field(default_factory=list)
    min_volume: float | None = Field(default=None, ge=0)
    intrabar_policy: Literal["conservative", "optimistic"] = "conservative"
    max_gap_multiple: float = Field(default=96.0, gt=1)
    reject_unexpected_gaps: bool = False
    stop_loss_percent: float = Field(default=0.5, gt=0)
    take_profit_percent: float = Field(default=1.0, gt=0)
    max_leverage: float = Field(default=5.0, gt=0)


class SimpleBacktestRequest(BaseModel):
    symbol: str = "XAUUSD"
    timeframe: Timeframe = "H1"
    strategy: str = "ema_rsi_v1"
    base_strategy: str | None = None
    version: str | None = None
    parameters: dict[str, Any] = Field(default_factory=dict)
    strategies: list[StrategyRuntimeConfig] = Field(default_factory=list)
    initial_balance: float = Field(default=10000.0, gt=0)
    risk_per_trade: float = Field(default=1.0, gt=0)
    from_date: date | None = None
    to_date: date | None = None
    dataset_path: str | None = None
    candles: list[Candle] = Field(default_factory=list)
    execution: ExecutionConfig = Field(default_factory=ExecutionConfig)
    evaluation_mode: Literal["incremental", "full"] = "full"
    random_seed: int = 42


class Metrics(BaseModel):
    total_trades: int
    wins: int
    losses: int
    win_rate: float
    net_pnl: float
    profit_factor: float
    max_drawdown: float


class Trade(BaseModel):
    direction: Direction
    entry_time: datetime
    exit_time: datetime | None
    entry_price: float
    exit_price: float | None
    stop_loss: float
    take_profit: float
    pnl: float | None
    result: TradeResult
    indicator_snapshot: dict[str, float | str | None]


class MistakeJournalEntry(BaseModel):
    reason: str
    trade: Trade
    context: dict[str, float | str | None]


class DailyReportDay(BaseModel):
    date: str
    total_trades: int
    wins: int
    losses: int
    win_rate: float
    net_pnl: float
    most_common_mistake: str | None
    conclusion: str


class DailyReport(BaseModel):
    summary: str
    days: list[DailyReportDay]


class BacktestResponse(BaseModel):
    symbol: str
    timeframe: Timeframe
    metrics: Metrics
    trades: list[Trade]
    mistake_journal: list[MistakeJournalEntry]
    daily_report: DailyReport


class SimpleTrade(BaseModel):
    direction: str
    entry_time: str
    exit_time: str
    entry_price: float
    exit_price: float
    stop_loss: float | None = None
    take_profit: float | None = None
    result: str
    profit_percent: float
    gross_profit_percent: float = 0.0
    execution_cost_percent: float = 0.0
    market_profit_percent: float = 0.0
    position_size_multiple: float = 1.0
    risk_budget_percent: float = 1.0
    signal_time: str | None = None
    exit_reason: str | None = None
    balance: float
    market_regime: str = "unknown"
    volatility_regime: str = "normal_volatility"
    mistake_type: str | None = None
    reason: str | None = None
    suggestion: str | None = None


class SimpleBacktestResponse(BaseModel):
    strategy: str
    parameters: dict[str, Any] = Field(default_factory=dict)
    instrument: str
    timeframe: Timeframe
    period: str
    initial_balance: float
    final_balance: float
    net_profit_percent: float
    total_trades: int
    wins: int
    losses: int
    winrate: float
    profit_factor: float
    max_drawdown: float
    max_drawdown_percent: float = 0.0
    average_win_percent: float = 0.0
    average_loss_percent: float = 0.0
    risk_reward_ratio: float = 0.0
    max_consecutive_losses: int = 0
    stability_score: int = 0
    equity_curve: list[float] = Field(default_factory=list)
    regime_performance: dict[str, dict[str, float | int]] = Field(default_factory=dict)
    volatility_performance: dict[str, dict[str, float | int]] = Field(default_factory=dict)
    monte_carlo: dict[str, Any] = Field(default_factory=dict)
    strategy_dna: dict[str, Any] = Field(default_factory=dict)
    execution_assumptions: dict[str, Any] = Field(default_factory=dict)
    data_quality: dict[str, Any] = Field(default_factory=dict)
    statistical_evidence: dict[str, Any] = Field(default_factory=dict)
    benchmark: dict[str, Any] = Field(default_factory=dict)
    trade_ledger_scope: str = "full evaluation"
    displayed_trade_count: int = 0
    top_mistakes: list[dict[str, int | str]]
    trades: list[SimpleTrade]
    conclusion: str
