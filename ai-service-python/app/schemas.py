from datetime import date, datetime
from typing import Any, Literal

from pydantic import BaseModel, Field, model_validator

Timeframe = Literal["M1", "M5", "M15", "M30", "H1", "H4", "D1"]
Direction = Literal["long", "short"]
TradeResult = Literal["win", "loss", "open"]


class Candle(BaseModel):
    time: datetime
    open: float
    high: float
    low: float
    close: float
    volume: float | None = None
    # Explicit source-quality marker.  A false/absent marker means volume is
    # unavailable for the optional research context layer.
    volume_available: bool | None = None


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
    # Cross-runtime content identity of the exact replay input. For the
    # autonomous M5 organism this is the frozen H4/H1/M15/M5 bundle hash.
    # A declared composition must bind this value before replay starts.
    replay_dataset_hash: str | None = None
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
    # Stable Laravel-side identity for bounded laboratory cohorts.  It is
    # carried through the evaluator response so the caller can project each
    # result back to the correct agent even when parameter validation adds or
    # normalizes defaults.
    lab_agent_id: int | None = Field(default=None, ge=1)
    # Portfolio membership is sealed before replay.  These fields describe
    # ownership, not a label inferred from the replay outcome.
    member_key: str | None = None
    role: str | None = None
    target_regime: str | None = None
    target_volatility: str | None = None
    # Optional directional ownership makes a council useful when BUY and SELL
    # edges are asymmetric inside the same regime/volatility niche. It is a
    # sealed routing declaration, never inferred from the combined outcome.
    target_direction: Literal["BUY", "SELL"] | None = None
    target_session: Literal["asia", "london", "new_york", "overlap"] | None = None
    target_venue_phase: Literal[
        "asia_sge_night", "asia_sge_day", "london_pre_am_fix", "london_am_fix",
        "london_interfix", "london_pm_fix", "comex_active", "comex_pre_settlement",
        "comex_post_settlement", "comex_maintenance", "london_comex_overlap",
    ] | None = None
    specialist_context_contract: dict[str, Any] = Field(default_factory=dict)
    # Sealed by Laravel before a laboratory replay. The runtime may attest
    # only bindings it actually received and consumed; the declaration grants
    # neither paper authority nor promotion evidence.
    instrument_research_assignment: dict[str, Any] = Field(default_factory=dict)
    # Frozen Laravel composition declaration. Runtime consumption is reported
    # separately; receiving this contract grants no component credit.
    composition_runtime_contract: dict[str, Any] = Field(default_factory=dict)
    research_release: dict[str, Any] = Field(default_factory=dict)
    specialist_council_contract: dict[str, Any] = Field(default_factory=dict)
    native_spread_context_study_contract: dict[str, Any] = Field(default_factory=dict)
    # Original PHP plan binding survives validation, but grants no authority
    # without the server-signed full-window transport and immutable exam.
    specialist_council_evaluation: dict[str, Any] = Field(default_factory=dict)


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
    portfolio_members: list[StrategyRuntimeConfig] = Field(default_factory=list)
    initial_balance: float = Field(default=10000.0, gt=0)
    risk_per_trade: float = Field(default=1.0, gt=0)
    from_date: date | None = None
    to_date: date | None = None
    dataset_path: str | None = None
    # Exact replay identity sealed by Laravel. For the autonomous organism it
    # is the content-addressed H4/H1/M15/M5 bundle hash.
    replay_dataset_hash: str | None = None
    # Immutable snapshot transport: Laravel sends a sealed CSV/Parquet path
    # plus its manifest instead of serialising thousands of candles into the
    # HTTP body.  The tail is applied before normalisation so it is equivalent
    # to the legacy bounded inline payload.
    dataset_tail_rows: int | None = Field(default=None, ge=2)
    # Full replay may use a separately sealed pre-2026 foundation archive.
    # The primary dataset remains the canonical rolling/forward/paper stream.
    foundation_dataset_path: str | None = None
    candles: list[Candle] = Field(default_factory=list)
    regime_dataset_path: str | None = None
    # M15 uses the last closed H1 context from the same immutable generation
    # snapshot.  This keeps the request small without weakening the no-
    # look-ahead contract.
    regime_dataset_tail_rows: int | None = Field(default=None, ge=2)
    regime_candles: list[Candle] = Field(default_factory=list)
    # Generic closed-context stack used by research-only role-separated MTF
    # playbooks. The primary `candles`/`dataset_path` remains the execution
    # stream (M5 for Liquidity Trap MTF); these are independent H4/H1/M15
    # streams whose candle availability is enforced by the replay engine.
    mtf_streams: dict[str, list[Candle]] = Field(default_factory=dict)
    mtf_dataset_paths: dict[str, str] = Field(default_factory=dict)
    mtf_dataset_tail_rows: dict[str, int] = Field(default_factory=dict)
    # Optional, independently sealed related-market context.  It is used only
    # by cross-market research hypotheses such as SMT; an absent stream must
    # produce WAIT rather than a synthetic confirmation.
    related_mtf_streams: dict[str, list[Candle]] = Field(default_factory=dict)
    related_mtf_dataset_paths: dict[str, str] = Field(default_factory=dict)
    related_mtf_dataset_tail_rows: dict[str, int] = Field(default_factory=dict)
    # Laravel seals hashes, source/aggregation rules and the common cutoff
    # here. It is observability evidence; Python independently uses only
    # closed candles when merging each stream.
    mtf_snapshot_manifest: dict[str, Any] = Field(default_factory=dict)
    execution: ExecutionConfig = Field(default_factory=ExecutionConfig)
    # Laravel seals this map before lab/paper/holdout execution. A missing
    # declaration is accepted for local unit tests but is never promotion
    # evidence.
    execution_contract: dict[str, Any] = Field(default_factory=dict)
    evaluation_mode: Literal["incremental", "full", "replay", "temporal_ablation"] = (
        "full"
    )
    # A delayed signal is a deterministic execution-stress variant.  OHLC,
    # regime and volume features stay anchored to their observed candle; only
    # signal-derived columns move forward so the test cannot introduce look-ahead.
    signal_delay_candles: int = Field(default=0, ge=0, le=3)
    random_seed: int = 42
    # Sealed policy evidence used by the paper execution path for OOD and
    # uncertainty-aware abstention. It does not alter replay gate thresholds.
    policy_context: dict[str, Any] = Field(default_factory=dict)
    # Candidate-specific assignment copied from StrategyRuntimeConfig while a
    # cohort is replayed. It is part of the immutable candidate cache key.
    instrument_research_assignment: dict[str, Any] = Field(default_factory=dict)
    composition_runtime_contract: dict[str, Any] = Field(default_factory=dict)
    # A contextual specialist must abstain outside this pre-registered cell.
    research_release: dict[str, Any] = Field(default_factory=dict)
    # It is routing scope only and cannot create promotion evidence.
    specialist_context_contract: dict[str, Any] = Field(default_factory=dict)
    # Canonical volume provenance is passed separately from strategy genes so
    # an unavailable source can never be interpreted as low volume.
    volume_context: dict[str, Any] = Field(default_factory=dict)
    # Sealed runtime router provenance. The executable member list remains in
    # portfolio_members; this map is an audit contract and never authorizes
    # genetic parent IDs by itself.
    runtime_ensemble_policy: dict[str, Any] = Field(default_factory=dict)
    # Explicit opt-in to independent specialist positions on a synchronized
    # account. Undeclared requests retain the original portfolio router.
    specialist_council_contract: dict[str, Any] = Field(default_factory=dict)
    native_spread_context_study_contract: dict[str, Any] = Field(default_factory=dict)
    # Canonical XAUUSD multi-timeframe routing contract. H1 remains a closed
    # regime context and M15 remains the independent entry population.
    mtf_pilot: dict[str, Any] = Field(default_factory=dict)
    # Full candle-level observability is opt-in so ordinary paper/status calls
    # do not pay for a large trace. Laboratory runs set this true and persist
    # the returned immutable trace in the Laravel evidence plane.
    emit_decision_trace: bool = False
    # Economic evidence is independent from the verbose candle trace. Causal
    # metric folds request the complete trade ledger while retaining the fast
    # stateful execution path; the bounded audit slice owns decision details.
    emit_trade_ledger: bool = False
    # Paper twin calls are sent to separate lane endpoints. The field is
    # transport metadata only and never changes the canonical market data.
    twin_lane: Literal["champion", "council"] | None = None


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
    signal_confidence: float = 1.0
    exit_reason: str | None = None
    balance: float
    market_regime: str = "unknown"
    volatility_regime: str = "normal_volatility"
    mistake_type: str | None = None
    reason: str | None = None
    suggestion: str | None = None
    # Sealed portfolio ownership is copied from the pre-replay router. It is
    # diagnostic attribution, never an outcome-derived label.
    portfolio_member: str | None = None
    # Management-path evidence is measured against the immutable initial
    # invalidation distance. Candle extrema include the exit candle, so these
    # fields are diagnostic bounds rather than a promotion shortcut.
    initial_risk_distance: float | None = None
    initial_risk_percent: float | None = None
    mfe_r: float | None = None
    mae_r: float | None = None
    # Conservative path bound measured only through the candle preceding the
    # exit candle. Unlike mfe_r, it cannot benefit from unknown intrabar order
    # between the stop/target touch and the exit candle's later extreme.
    mfe_r_before_exit_bar: float | None = None
    mae_r_before_exit_bar: float | None = None
    realized_r_multiple: float | None = None
    mfe_capture_ratio: float | None = None


class SimpleBacktestResponse(BaseModel):
    prospective_probe_window_receipt: dict[str, Any] = Field(default_factory=dict)
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
    volatility_performance: dict[str, dict[str, float | int]] = Field(
        default_factory=dict
    )
    monte_carlo: dict[str, Any] = Field(default_factory=dict)
    strategy_dna: dict[str, Any] = Field(default_factory=dict)
    execution_assumptions: dict[str, Any] = Field(default_factory=dict)
    execution_contract: dict[str, Any] = Field(default_factory=dict)
    control_root: dict[str, Any] = Field(default_factory=dict)
    policy_boundary: dict[str, Any] = Field(default_factory=dict)
    specialist_council_receipt: dict[str, Any] = Field(default_factory=dict)
    native_spread_context_study_receipt: dict[str, Any] = Field(default_factory=dict)
    core_replay_gate: dict[str, Any] = Field(default_factory=dict)
    data_quality: dict[str, Any] = Field(default_factory=dict)
    volume_quality: dict[str, Any] = Field(default_factory=dict)
    # Causal observability for the declared volume child lane. This is
    # evidence/telemetry only; it never relaxes a screening or promotion gate.
    volume_policy: dict[str, Any] = Field(default_factory=dict)
    volume_shadow: dict[str, Any] = Field(default_factory=dict)
    statistical_evidence: dict[str, Any] = Field(default_factory=dict)
    pf_attribution: dict[str, Any] = Field(default_factory=dict)
    # The entry funnel distinguishes a strategy that finds no opportunities
    # from one whose execution/risk filters reject otherwise valid signals.
    # This is evidence for the evolutionary loop, never a promotion shortcut.
    entry_funnel: dict[str, Any] = Field(default_factory=dict)
    # Strategy-owned WHERE->WHY->PROVE->TRIGGER funnel. Unlike entry_funnel,
    # it retains WAIT opportunities that never became raw trade signals.
    entry_contract_funnel: dict[str, Any] = Field(default_factory=dict)
    # Instrument selection and parameter binding are not runtime invocation.
    # This bounded ledger is written at the exact decision/management event
    # that invoked each pre-registered instrument and is still research-only.
    instrument_runtime_observations: dict[str, Any] = Field(default_factory=dict)
    # Architecture Genesis needs the entire decision-to-outcome chain even
    # when a stage truthfully observed zero entries. Values are structured
    # observations; their presence is not an edge or promotion claim.
    edge_observability: dict[str, Any] = Field(default_factory=dict)
    confirmation_entry_observed: bool = False
    behavior_delta_observed: bool = False
    context_declared_before_replay: bool = False
    context_occurrences: int = 0
    # A declared Edge context is authority only when the evaluator proves that
    # out-of-scope signals were converted to WAIT before entry. Declaration
    # alone is deliberately insufficient.
    edge_context_enforcement: dict[str, Any] = Field(default_factory=dict)
    risk_governor_compliant: bool = False
    forbidden_risk_bypass: bool = False
    after_cost_expectancy_r: float = 0.0
    # Replay-side management observability. This reports attested MFE/MAE and
    # realized-R capture without pretending that bar-level path order is
    # known, and it never authorizes promotion by itself.
    management_evidence: dict[str, Any] = Field(default_factory=dict)
    # Emitted inside the execution loop boundary. The outer composition trace
    # may attest this receipt but may not reconstruct node use post hoc.
    composition_runtime_receipt: dict[str, Any] = Field(default_factory=dict)
    # Post-replay Academy telemetry: it is forbidden from runtime inference,
    # ranking, and promotion, and unavailable oracle inputs stay unavailable.
    edge_formation_academy_diagnostic: dict[str, Any] = Field(default_factory=dict)
    behavioral_signature: dict[str, Any] = Field(default_factory=dict)
    diagnostic_telemetry: dict[str, Any] = Field(default_factory=dict)
    # Learning evidence only.  These counterfactual outcomes are never used as
    # promotion evidence and are derived with the same next-open/cost/exit
    # convention as a real candidate trade.
    veto_regret: dict[str, Any] = Field(default_factory=dict)
    # Full decision graph is aggregate learning evidence. It retains edge
    # uncertainty and deliberately leaves unavailable interventions unassessed.
    decision_blame_graph: dict[str, Any] = Field(default_factory=dict)
    # Lets the Laravel evidence ledger distinguish a replay produced after
    # the counterfactual-observability protocol was deployed from legacy
    # cached results.  This is observability only; it is never a gate bypass.
    observability_protocol_version: int = 1
    cooldown_policy: dict[str, Any] = Field(default_factory=dict)
    transition_firewall: dict[str, Any] = Field(default_factory=dict)
    differential_router: dict[str, Any] = Field(default_factory=dict)
    differential_invariants: dict[str, Any] = Field(default_factory=dict)
    confidence_calibration: dict[str, Any] = Field(default_factory=dict)
    # Online temporal survival/abstention telemetry. It is computed only
    # from information available at each decision candle and is research
    # evidence, never an automatic promotion signal.
    temporal_survival: dict[str, Any] = Field(default_factory=dict)
    # Complete diagnostic attribution for failure-eliminator selection.  It is
    # deliberately not a routing feature: calendar is evidence only and the
    # mutation contract names the failing causal context instead.
    robustness_matrix: dict[str, Any] = Field(default_factory=dict)
    # Complete candle-level venue/session classification coverage. Candidate
    # and control hashes must match before local causal authority can exist.
    market_session_calendar_coverage: dict[str, Any] = Field(default_factory=dict)
    replay_compiler: dict[str, Any] = Field(default_factory=dict)
    certified_coverage_passport: dict[str, Any] = Field(default_factory=dict)
    opportunity_recall: dict[str, Any] = Field(default_factory=dict)
    proof_carrying_replay: dict[str, Any] = Field(default_factory=dict)
    window_survival: dict[str, Any] = Field(default_factory=dict)
    regime_ensemble: dict[str, Any] = Field(default_factory=dict)
    portfolio_evidence: dict[str, Any] = Field(default_factory=dict)
    # Router learning is intentionally orthogonal to economics: calibrated
    # confidence, safe abstention and disagreement-WAIT invariants are
    # persisted separately from PF so the router cannot learn to chase PF.
    router_evidence: dict[str, Any] = Field(default_factory=dict)
    opportunity_metrics: dict[str, Any] = Field(default_factory=dict)
    red_team: dict[str, Any] = Field(default_factory=dict)
    monthly_passport: dict[str, Any] = Field(default_factory=dict)
    evidence_streams: dict[str, Any] = Field(default_factory=dict)
    edge_claim: dict[str, Any] = Field(default_factory=dict)
    benchmark: dict[str, Any] = Field(default_factory=dict)
    trade_ledger_scope: str = "full evaluation"
    trade_ledger_hash: str = ""
    # Compact event identity is emitted even when the full candle trace is
    # disabled. It is the minimum evidence required to compare veto,
    # cooldown, transition and exit-path behaviour.
    event_ledger_hash: str = ""
    event_ledger_count: int = 0
    event_ledger_categories: dict[str, int] = Field(default_factory=dict)
    event_digest: dict[str, Any] = Field(default_factory=dict)
    # Bounded, ordered identity of the policy decisions made at each candle.
    # It is emitted even when the verbose decision trace is disabled so a
    # frozen control and its candidate remain causally comparable.
    signal_decision_hash: str = ""
    signal_decision_count: int = 0
    signal_decision_categories: dict[str, int] = Field(default_factory=dict)
    signal_decision_digest: dict[str, Any] = Field(default_factory=dict)
    state_machine: dict[str, Any] = Field(default_factory=dict)
    displayed_trade_count: int = 0
    top_mistakes: list[dict[str, int | str]]
    trades: list[SimpleTrade]
    # The UI keeps ``trades`` capped, while laboratory runs may request the
    # complete immutable ledger. It is populated only when observability is
    # explicitly enabled and is never used to alter a gate.
    trade_ledger: list[SimpleTrade] = Field(default_factory=list)
    conclusion: str
    decision_trace: list[dict[str, Any]] = Field(default_factory=list)
