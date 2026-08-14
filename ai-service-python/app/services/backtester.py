from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta
import hashlib
import json
import math
from pathlib import Path

import pandas as pd
from dateutil.easter import easter

from app.schemas import (
    BacktestRequest,
    BacktestResponse,
    DailyReport,
    DailyReportDay,
    Metrics,
    MistakeJournalEntry,
    SimpleBacktestRequest,
    SimpleBacktestResponse,
    SimpleTrade,
    Trade,
)
from app.services.data_loader import load_candles
from app.services.execution_contract import enforce_policy_boundary, execution_contract_metadata
from app.services.control_roots import control_root_for
from app.services.indicators import add_indicators
from app.services.market_regime import apply_market_regime
from app.services.multitimeframe import annotate_regime_source, apply_signal_policy
from app.services.monte_carlo import MonteCarloService
from app.services.strategy_dna import StrategyDnaService
from app.services.statistical_validation import bootstrap_profit_factor_lower_bound
from app.services.volume_features import (
    add_volume_features,
    apply_volume_policy,
    volume_shadow_report,
)
from app.strategies.registry import get_strategy, strategy_label


@dataclass(frozen=True)
class PreparedFeatureSnapshot:
    """Normalized market/context features reusable by compatible lanes."""

    source_frame: pd.DataFrame
    frame: pd.DataFrame
    unexpected_gap_count: int
    data_quality: dict[str, object]


@dataclass(frozen=True)
class PreparedSignalSnapshot:
    """Strategy/router signals on a feature snapshot, before execution state."""

    source_frame: pd.DataFrame
    frame: pd.DataFrame
    unexpected_gap_count: int
    data_quality: dict[str, object]


def core_replay_gate(result: dict[str, object]) -> dict[str, object]:
    """Decide whether expensive replay diagnostics are economically justified.

    This is intentionally a cheap, fail-closed pre-gate.  It mirrors the
    existing screening admission contract (minimum trade count and positive
    PF) and does not replace any promotion gate.  Its only purpose is to stop
    Monte Carlo, paired lanes and deep diagnostics from consuming time on a
    candidate that has no core replay evidence.
    """
    try:
        total_trades = int(result.get("total_trades", 0) or 0)
    except (TypeError, ValueError):
        total_trades = 0
    try:
        profit_factor = float(result.get("profit_factor", 0) or 0)
    except (TypeError, ValueError):
        profit_factor = 0.0
    data_quality = result.get("data_quality", {})
    if not isinstance(data_quality, dict):
        data_quality = {}
    try:
        hard_gate_failures = int(data_quality.get("hard_gate_failure_count", 0) or 0)
    except (TypeError, ValueError):
        hard_gate_failures = 0

    reasons: list[str] = []
    if total_trades < 10:
        reasons.append("insufficient_core_trades")
    if not math.isfinite(profit_factor) or profit_factor <= 0:
        reasons.append("non_positive_core_profit_factor")
    if hard_gate_failures > 0:
        reasons.append("historical_data_hard_gate_failure")
    return {
        "protocol": "core_replay_gate_v1",
        "passed": not reasons,
        "minimum_trades": 10,
        "total_trades": total_trades,
        "profit_factor": round(profit_factor, 6) if math.isfinite(profit_factor) else 0.0,
        "hard_gate_failure_count": hard_gate_failures,
        "reasons": reasons,
        "promotion_evidence": False,
        "rule": "Core gate only authorizes expensive diagnostics; it never promotes a candidate.",
    }


def run_backtest(payload: BacktestRequest) -> BacktestResponse:
    candles = load_candles(payload.dataset_path, payload.candles, payload.from_date, payload.to_date)
    strategy = payload.strategy
    prepared = add_indicators(
        candles,
        ema_fast=strategy.ema_fast,
        ema_slow=strategy.ema_slow,
        rsi_period=strategy.rsi_period,
        atr_period=strategy.atr_period,
    )

    trades = _simulate(prepared, payload)
    mistakes = _mistakes(trades)
    metrics = _metrics(trades, payload.strategy.initial_balance)
    daily_report = _daily_report(trades, mistakes)

    return BacktestResponse(
        symbol=payload.symbol,
        timeframe=payload.timeframe,
        metrics=metrics,
        trades=trades,
        mistake_journal=mistakes,
        daily_report=daily_report,
    )


def run_simple_ema_rsi_backtest(payload: SimpleBacktestRequest) -> SimpleBacktestResponse:
    df = _load_simple_candles(payload)

    return run_simple_ema_rsi_backtest_on_dataframe(payload, df)


def _prepare_simple_dataframe(payload: SimpleBacktestRequest, df: pd.DataFrame) -> pd.DataFrame:
    """Normalize and validate one candle stream exactly once per snapshot."""
    frame = df.copy()
    if "volume" not in frame.columns:
        frame["volume"] = 0
    if "volume_available" not in frame.columns:
        frame["volume_available"] = False

    required_columns = {"time", "open", "high", "low", "close", "volume"}
    missing_columns = required_columns - set(frame.columns)
    if missing_columns:
        missing = ", ".join(sorted(missing_columns))
        raise ValueError(f"Dataset is missing required columns: {missing}")

    data_quality = _data_quality_diagnostics(frame)
    if payload.execution.reject_unexpected_gaps and data_quality["hard_gate_failure_count"]:
        raise ValueError(
            "Historical data hard-gate failed: data_quality "
            f"{data_quality['hard_gate_failures']}"
        )

    frame["time"] = pd.to_datetime(frame["time"], errors="coerce", utc=True)
    for column in ["open", "high", "low", "close", "volume"]:
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    # CSV/API payloads may materialize this marker as an object column when
    # unavailable rows contain nulls.  Calling fillna directly on that object
    # column emits pandas' future downcasting warning; in the bounded worker
    # that warning is captured with stderr and can be misclassified as a
    # technical replay failure.  Normalize through the nullable BooleanDtype
    # before filling so the no-volume lane remains explicit and warning-free.
    frame["volume_available"] = (
        frame["volume_available"].astype("boolean").fillna(False).astype(bool)
    )
    frame = frame.dropna(subset=["time", "open", "high", "low", "close"])
    frame = frame.sort_values("time").reset_index(drop=True)

    unexpected_gap_count = _validate_data_gaps(frame, payload)
    if unexpected_gap_count and payload.execution.reject_unexpected_gaps:
        raise ValueError(
            f"Historical data hard-gate failed: {unexpected_gap_count} unexpected candle gaps."
        )
    data_quality["rows_after_cleaning"] = len(frame)
    data_quality["unexpected_gap_count"] = unexpected_gap_count
    data_quality["status"] = "warning" if data_quality["hard_gate_failure_count"] or unexpected_gap_count else "passed"
    frame.attrs["unexpected_gap_count"] = unexpected_gap_count
    frame.attrs["data_quality"] = data_quality

    if payload.from_date:
        frame = frame[frame["time"].dt.date >= payload.from_date]
    if payload.to_date:
        frame = frame[frame["time"].dt.date <= payload.to_date]
    frame = frame.reset_index(drop=True)
    if len(frame) < 2:
        raise ValueError("At least 2 candles are required for backtest.")
    frame.attrs["unexpected_gap_count"] = unexpected_gap_count
    frame.attrs["data_quality"] = data_quality
    return frame


def run_simple_ema_rsi_backtest_on_dataframe(
    payload: SimpleBacktestRequest,
    df: pd.DataFrame,
    *,
    include_differential_pair: bool = True,
    lightweight: bool = False,
) -> SimpleBacktestResponse:
    df = _prepare_simple_dataframe(payload, df)

    return _run_prepared_simple_backtest(
        payload,
        df,
        include_differential_pair=include_differential_pair,
        lightweight=lightweight,
    )


def prepare_feature_snapshot(
    payload: SimpleBacktestRequest,
    df: pd.DataFrame,
) -> PreparedFeatureSnapshot:
    """Build closed-context, volume and ATR features once per candle snapshot."""
    normalized = _prepare_simple_dataframe(payload, df)
    source = normalized.copy()
    regime_source = _load_regime_source(payload)
    prepared = _apply_execution_regime(normalized, regime_source)
    prepared = add_volume_features(prepared, payload.volume_context)
    previous_close = prepared["close"].shift(1)
    true_range = pd.concat([
        prepared["high"] - prepared["low"],
        (prepared["high"] - previous_close).abs(),
        (prepared["low"] - previous_close).abs(),
    ], axis=1).max(axis=1)
    prepared["_management_atr"] = true_range.rolling(14, min_periods=1).mean()
    prepared.attrs["unexpected_gap_count"] = int(normalized.attrs.get("unexpected_gap_count", 0))
    prepared.attrs["data_quality"] = dict(normalized.attrs.get("data_quality") or {})
    prepared.attrs["regime_source"] = (
        "closed_h1" if regime_source is not None else "execution_timeframe"
    )
    return PreparedFeatureSnapshot(
        source_frame=source,
        frame=prepared,
        unexpected_gap_count=int(normalized.attrs.get("unexpected_gap_count", 0)),
        data_quality=dict(normalized.attrs.get("data_quality") or {}),
    )


def prepare_signal_snapshot(
    payload: SimpleBacktestRequest,
    df: pd.DataFrame | None = None,
    *,
    feature_snapshot: PreparedFeatureSnapshot | None = None,
) -> PreparedSignalSnapshot:
    """Apply one strategy/router signal layer to a reusable feature snapshot."""
    features = feature_snapshot or prepare_feature_snapshot(
        payload,
        df if df is not None else _load_simple_candles(payload),
    )
    prepared = features.frame.copy()
    if payload.portfolio_members:
        prepared = _apply_portfolio_strategy(prepared, payload.portfolio_members)
    else:
        strategy_function = get_strategy(payload.strategy, payload.base_strategy)
        prepared = strategy_function(prepared, payload.parameters)
        prepared = apply_volume_policy(
            prepared,
            payload.parameters,
            payload.base_strategy or payload.strategy,
        )
    prepared = _apply_signal_delay(prepared, payload.signal_delay_candles)
    prepared.attrs["unexpected_gap_count"] = features.unexpected_gap_count
    prepared.attrs["data_quality"] = dict(features.data_quality)
    prepared.attrs["regime_source"] = str(
        features.frame.attrs.get("regime_source", "execution_timeframe")
    )
    return PreparedSignalSnapshot(
        source_frame=features.source_frame,
        frame=prepared,
        unexpected_gap_count=features.unexpected_gap_count,
        data_quality=dict(features.data_quality),
    )


def _load_simple_candles(payload: SimpleBacktestRequest) -> pd.DataFrame:
    if payload.candles:
        df = pd.DataFrame([
            candle.model_dump() if hasattr(candle, "model_dump") else candle
            for candle in payload.candles
        ])
    else:
        dataset_path = payload.dataset_path
        if not dataset_path:
            normalized = payload.symbol.replace("/", "").replace("_", "").upper()
            dataset_path = f"../datasets/{normalized}_H1.csv"
        csv_path = _resolve_dataset_path(dataset_path)
        df = pd.read_csv(csv_path)

    return df


def _load_regime_source(payload: SimpleBacktestRequest) -> pd.DataFrame | None:
    if payload.regime_candles:
        return pd.DataFrame([candle.model_dump() if hasattr(candle, "model_dump") else candle for candle in payload.regime_candles])
    if payload.regime_dataset_path:
        return pd.read_csv(_resolve_dataset_path(payload.regime_dataset_path))
    return None


def _apply_execution_regime(execution_df: pd.DataFrame, regime_source: pd.DataFrame | None) -> pd.DataFrame:
    """Merge only closed H1 state into an M15 execution stream (no look-ahead)."""
    if regime_source is None:
        return apply_market_regime(execution_df)
    higher_raw = regime_source.copy()
    higher_raw["time"] = pd.to_datetime(higher_raw["time"], utc=True, errors="coerce")
    higher = annotate_regime_source(apply_market_regime(higher_raw))
    if higher is None:
        return apply_market_regime(execution_df)
    higher["regime_available_at"] = higher["_h1_closed_at"]
    columns = [
        "regime_available_at", "_h1_open_at", "_h1_closed_at", "_h1_context_hash",
        "market_regime", "volatility_regime", "adx", "atr_regime",
    ]
    base = execution_df.copy()
    base["time"] = pd.to_datetime(base["time"], utc=True)
    merged = pd.merge_asof(base.sort_values("time"), higher[columns].sort_values("regime_available_at"), left_on="time", right_on="regime_available_at", direction="backward").drop(columns=["regime_available_at"])
    merged["market_regime"] = merged["market_regime"].fillna("unknown")
    merged["volatility_regime"] = merged["volatility_regime"].fillna("normal_volatility")
    merged["adx"] = merged["adx"].fillna(0.0)
    merged["atr_regime"] = merged["atr_regime"].ffill().fillna(0.0)
    return merged


def _run_prepared_simple_backtest(
    payload: SimpleBacktestRequest,
    df: pd.DataFrame,
    *,
    differential_lane: str | None = None,
    include_differential_pair: bool = True,
    lightweight: bool = False,
    prepared_snapshot: PreparedSignalSnapshot | None = None,
) -> SimpleBacktestResponse:
    policy_boundary = enforce_policy_boundary(payload)
    snapshot = prepared_snapshot or prepare_signal_snapshot(payload, df)
    source_df = snapshot.source_frame.copy()
    df = snapshot.frame.copy()
    unexpected_gap_count = snapshot.unexpected_gap_count
    df.attrs["unexpected_gap_count"] = unexpected_gap_count
    df.attrs["data_quality"] = dict(snapshot.data_quality)
    regime_source_label = str(df.attrs.get("regime_source", "execution_timeframe"))

    balance = payload.initial_balance
    peak_balance = balance
    max_drawdown = 0.0
    trades: list[SimpleTrade] = []
    position: dict[str, object] | None = None
    gross_profit = 0.0
    gross_loss = 0.0
    # A loss streak is a finite risk-control state, never a permanent entry
    # veto.  In particular, do not use ``loss_streak >= threshold`` at entry:
    # a blocked strategy cannot produce the winning trade that would reset it.
    loss_streak = 0
    # Dynamic cooldown is a local containment policy.  A range specialist
    # losing once must not freeze a healthy trend lane (and vice versa).  The
    # key contains only already-known regime/volatility/direction/specialist
    # context; it is therefore safe for paired differential replay as well.
    cooldown_until_by_context: dict[str, int] = {}
    loss_streak_wait_until = -1
    recovery_probe = False
    loss_streak_by_context: dict[str, int] = defaultdict(int)
    context_wait_until: dict[str, int] = {}
    context_recovery_probes: set[str] = set()
    # Regime quality is a separate, context-local finite state machine.  It
    # must never turn a weak historical PF into an end-of-replay hard lock.
    weak_regime_state: dict[str, dict[str, object]] = {}
    regime_returns: dict[str, list[float]] = {}
    entry_funnel: Counter[str] = Counter()
    opportunities_by_month: Counter[str] = Counter()
    accepted_by_month: Counter[str] = Counter()
    decision_trace: list[dict[str, object]] = []
    emit_decision_trace = bool(payload.emit_decision_trace)
    # Shadow positions never touch balance, loss streak, or real-position
    # state.  They are a counterfactual evidence ledger, not hidden trades.
    shadow_positions: list[dict[str, object]] = []
    shadow_ledger: list[dict[str, object]] = []
    shadow_history: dict[str, dict[str, list[float]]] = defaultdict(lambda: defaultdict(list))
    meta_returns: dict[str, list[float]] = defaultdict(list)
    confidence_history: dict[str, list[dict[str, float]]] = defaultdict(list)
    cooldown_decisions: list[dict[str, object]] = []
    loss_streak_wait_events: list[dict[str, object]] = []
    recovery_probe_events: list[dict[str, object]] = []
    weak_regime_events: list[dict[str, object]] = []
    transition_wait_until = -1
    transition_events = 0
    transition_vetoes = 0
    mtf_vetoes = 0
    mtf_contexts: Counter[str] = Counter()
    entry_funnel["raw_strategy_signals"] = _count_lane_signals(df, differential_lane)

    # A signal is only knowable after its candle closes. Execute it at the
    # following candle's open, then include that same candle in exit checks.
    for index in range(200, len(df)):
        candle = df.iloc[index]
        signal_row = df.iloc[index - 1]
        transition_event = _regime_transitioned(signal_row, df.iloc[index - 2] if index >= 2 else None)
        if transition_event and bool(payload.parameters.get("transition_firewall_enabled", False)):
            transition_events += 1
            transition_wait_until = max(transition_wait_until, index + _transition_wait_duration(payload))
        transition_wait_active = bool(payload.parameters.get("transition_firewall_enabled", False)) and index < transition_wait_until
        _advance_shadow_positions(
            shadow_positions, shadow_ledger, shadow_history, candle, df.iloc[index - 1], payload, index
        )

        if emit_decision_trace and position is not None:
            decision_trace.append(_decision_trace_event(
                index, candle, signal_row, 'position_management', 'WAIT', False, 'position_open',
                {'position_open': True, 'loss_streak': loss_streak},
            ))

        # A completed wait earns exactly one reduced-risk probe.  This is
        # evaluated even when there is no signal so expiry is driven by time,
        # not by a future accepted trade.
        if loss_streak_wait_until >= 0 and index >= loss_streak_wait_until:
            loss_streak = 0
            loss_streak_wait_until = -1
            recovery_probe = True
            recovery_probe_events.append({"time": str(candle["time"]), "scope": "global", "event": "wait_expired"})

        if position is None:
            signal, lane_confidence, lane_specialist = _effective_lane_signal(signal_row, differential_lane)
            if signal not in {"BUY", "SELL"} and _is_volume_policy_veto(signal_row):
                # Keep the policy-visible signal WAIT for paper/UI callers,
                # but restore the causal opportunity in replay so the
                # shadow ledger measures missed edge and recall honestly.
                policy_signal = str(signal_row.get("pre_volume_signal", "WAIT"))
                if policy_signal in {"BUY", "SELL"}:
                    signal = policy_signal
                    lane_confidence = float(
                        signal_row.get("pre_volume_signal_confidence", 0.0) or 0.0
                    )
                    signal_row = signal_row.copy()
                    signal_row["signal"] = signal
                    signal_row["signal_confidence"] = lane_confidence
                    signal_row["selected_specialist"] = lane_specialist
            if differential_lane is not None:
                signal_row = signal_row.copy()
                signal_row["signal"] = signal
                signal_row["signal_confidence"] = lane_confidence
                signal_row["selected_specialist"] = lane_specialist
            mtf_policy = apply_signal_policy(
                signal,
                signal_row,
                payload.mtf_pilot,
                signal_row.get("time"),
            )
            mtf_context = dict(mtf_policy.get("context", {}))
            if emit_decision_trace:
                signal_row = signal_row.copy()
                signal_row["h1_context_hash"] = mtf_context.get("h1_context_hash")
                signal_row["h1_closed_at"] = mtf_context.get("h1_closed_at")
                signal_row["m15_raw_decision"] = signal
                signal_row["risk_decision"] = mtf_policy.get("risk_decision")
                signal_row["council_decision"] = mtf_policy.get("decision")
                signal_row["specialist_strategy"] = lane_specialist
            mtf_contexts[str(mtf_context.get("h1_regime", mtf_context.get("status", "not_applicable")))] += 1
            if mtf_policy.get("decision") != signal:
                if signal in {"BUY", "SELL"} and mtf_policy.get("decision") == "WAIT":
                    mtf_vetoes += 1
                    entry_funnel[f"rejected_mtf_{mtf_policy.get('reason', 'unknown')}"] += 1
                signal_row = signal_row.copy()
                signal_row["mtf_raw_signal"] = signal
                signal_row["mtf_decision"] = mtf_policy.get("decision", "WAIT")
                signal_row["mtf_veto_reason"] = mtf_policy.get("reason")
                signal_row["mtf_context"] = mtf_context
                signal = str(mtf_policy.get("decision", "WAIT"))
            else:
                signal_row = signal_row.copy()
                signal_row["mtf_decision"] = signal
                signal_row["mtf_context"] = mtf_context
            if signal not in {"BUY", "SELL"}:
                if emit_decision_trace:
                    decision_trace.append(_decision_trace_event(
                        index, candle, signal_row, 'signal_evaluation', 'WAIT', False, 'no_signal',
                        {'position_open': False, 'loss_streak': loss_streak, 'h1_context': mtf_context,
                         'm15_raw_decision': signal, 'risk_decision': mtf_policy.get('risk_decision'),
                         'council_decision': mtf_policy.get('decision'), 'specialist_strategy': lane_specialist},
                    ))
                continue
            # A portfolio owns both the signal and its sealed execution
            # topology.  Using the first member's stop/target parameters for
            # every member makes a multi-agent result a hidden single-agent
            # replay and can erase the very complementarity being tested.
            execution_payload = _portfolio_payload_for_signal(payload, signal_row)
            entry_funnel["flat_signal_opportunities"] += 1
            month_key = _utc_month(signal_row["time"])
            opportunities_by_month[month_key] += 1
            context_key = _risk_context(signal_row, signal)
            context_wait = int(context_wait_until.get(context_key, -1))
            if context_wait >= 0 and index >= context_wait:
                loss_streak_by_context[context_key] = 0
                context_wait_until.pop(context_key, None)
                context_recovery_probes.add(context_key)
                recovery_probe_events.append({"time": str(candle["time"]), "scope": "context", "context": context_key, "event": "wait_expired"})
            global_wait_active = loss_streak_wait_until >= 0 and index < loss_streak_wait_until
            context_wait_active = context_wait >= 0 and index < context_wait
            weak_regime_wait_active, weak_regime_probe = _advance_weak_regime_state(
                weak_regime_state, context_key, index, candle, weak_regime_events
            )
            confidence_assessment = _confidence_assessment(
                signal_row, signal, confidence_history, execution_payload, candle
            )
            liquid, rejection_reason = _entry_eligibility(
                candle,
                execution_payload,
                signal_row,
                loss_streak,
                index < int(cooldown_until_by_context.get(context_key, -1)),
                regime_returns,
                meta_returns,
                loss_streak_wait_active=global_wait_active or context_wait_active,
                weak_regime_wait_active=weak_regime_wait_active,
                confidence_assessment=confidence_assessment,
                transition_wait_active=transition_wait_active,
            )
            if not liquid:
                entry_funnel[f"rejected_{rejection_reason or 'unknown'}"] += 1
                if rejection_reason == "regime_transition_wait":
                    transition_vetoes += 1
                shadow = _open_shadow_position(candle, signal_row, signal, execution_payload, index, rejection_reason or "unknown")
                if shadow is not None:
                    settled = _advance_shadow_position(shadow, candle, df.iloc[index - 1], execution_payload, index)
                    if settled is None:
                        shadow_positions.append(shadow)
                    else:
                        _record_shadow_outcome(shadow_ledger, shadow_history, settled)
                if emit_decision_trace:
                    decision_trace.append(_decision_trace_event(
                        index, candle, signal_row, 'signal_evaluation', signal, False,
                        rejection_reason or 'unknown', {
                            'position_open': False, 'loss_streak': loss_streak,
                            'context_key': context_key, 'confidence_assessment': confidence_assessment,
                        },
                    ))
                continue
            entry_funnel["accepted_entries"] += 1
            accepted_by_month[month_key] += 1
            probe_active = recovery_probe or context_key in context_recovery_probes or weak_regime_probe
            probe_scope = "global" if recovery_probe else ("context" if context_key in context_recovery_probes else None)

            market_price = float(candle["open"])
            entry_price = _entry_price(market_price, signal, execution_payload)
            stop_distance, target_distance = _exit_distances(market_price, signal_row, execution_payload)
            if signal == "BUY":
                stop_loss = market_price - stop_distance
                take_profit = market_price + target_distance
            else:
                stop_loss = market_price + stop_distance
                take_profit = market_price - target_distance

            position = {
                "direction": signal,
                "signal_time": signal_row["time"],
                "entry_time": candle["time"],
                "entry_price": entry_price,
                "market_entry_price": market_price,
                "stop_loss": stop_loss,
                "take_profit": take_profit,
                "position_size_multiple": _position_size_multiple(
                    entry_price, stop_loss, signal, execution_payload
                ) * _volatility_risk_multiplier(signal_row, execution_payload) * _meta_risk_multiplier(signal_row, signal, execution_payload, meta_returns)
                * _regime_transition_multiplier(signal_row, df.iloc[index - 2] if index >= 2 else None)
                * _regime_specific_risk_multiplier(signal_row, execution_payload)
                * _volume_risk_multiplier(signal_row)
                * float(mtf_policy.get("risk_multiplier", 1.0) or 1.0)
                * (_recovery_probe_risk_multiplier(execution_payload) if probe_active else 1.0),
                "market_regime": signal_row.get("market_regime", "unknown"),
                "volatility_regime": signal_row.get("volatility_regime", "normal_volatility"),
                "portfolio_member": signal_row.get("selected_specialist") if payload.portfolio_members else None,
                "signal_row": signal_row,
                "risk_context": context_key,
                "recovery_probe": probe_active,
                "recovery_probe_scope": probe_scope,
                "weak_regime_probe": weak_regime_probe,
                "entry_index": index,
                "execution_parameters": dict(execution_payload.parameters),
                "partial_closed": False,
                "partial_fraction": float(execution_payload.parameters.get("partial_take_profit_fraction", 0) or 0),
                "partial_exit_price": None,
            }
            if emit_decision_trace:
                decision_trace.append(_decision_trace_event(
                    index, candle, signal_row, 'signal_evaluation', signal, True, None, {
                        'position_open': True, 'loss_streak': loss_streak,
                        'context_key': context_key, 'entry_price': entry_price,
                        'stop_loss': stop_loss, 'take_profit': take_profit,
                        'position_size_multiple': position['position_size_multiple'],
                        'recovery_probe': probe_active, 'recovery_probe_scope': probe_scope,
                    },
                ))

        direction = str(position["direction"])
        position_payload = _payload_for_position(payload, position)
        _advance_trailing_stop(position, df.iloc[index - 1], position_payload)
        time_stop = int(position_payload.parameters.get("time_stop_candles", 0) or 0)
        if time_stop and index - int(position["entry_index"]) >= time_stop:
            exit_price, exit_reason = _exit_price(float(candle["open"]), direction, position_payload), "time_stop"
        else:
            exit_price, exit_reason = _intrabar_exit(direction, position, candle, position_payload)

        if exit_reason is None and _take_partial_profit(position, candle, position_payload):
            continue
        if exit_reason is None or exit_price is None:
            continue

        entry_price = float(position["entry_price"])
        if direction == "BUY":
            market_profit_percent = ((exit_price - entry_price) / entry_price) * 100
        else:
            market_profit_percent = ((entry_price - exit_price) / entry_price) * 100
        partial_fraction = float(position.get("partial_fraction", 0) or 0) if bool(position.get("partial_closed")) else 0.0
        partial_exit = position.get("partial_exit_price")
        if partial_fraction and partial_exit is not None:
            partial_return = ((float(partial_exit) - entry_price) / entry_price) * 100 if direction == "BUY" else ((entry_price - float(partial_exit)) / entry_price) * 100
            market_profit_percent = market_profit_percent * (1 - partial_fraction) + partial_return * partial_fraction
            exit_reason = f"partial_target+{exit_reason}"

        holding_days = max(
            (pd.Timestamp(candle["time"]) - pd.Timestamp(position["entry_time"])).total_seconds() / 86400,
            0,
        )
        explicit_cost = payload.execution.commission_percent + payload.execution.swap_per_day_percent * holding_days
        position_size = float(position["position_size_multiple"])
        gross_profit_percent = market_profit_percent * position_size
        scaled_cost_percent = explicit_cost * position_size
        profit_percent = gross_profit_percent - scaled_cost_percent
        result = "WIN" if profit_percent > 0 else "LOSS"
        closed_signal_row = position["signal_row"]
        closed_context = str(position.get("risk_context", _risk_context(closed_signal_row, direction)))
        was_recovery_probe = bool(position.get("recovery_probe", False))
        was_weak_regime_probe = bool(position.get("weak_regime_probe", False))
        probe_scope = position.get("recovery_probe_scope")
        if result == "WIN":
            # A successful probe returns to normal operation.  A normal win
            # also clears the global streak, as before, but leaves unrelated
            # context history intact.
            loss_streak = 0
            loss_streak_by_context[closed_context] = 0
            if probe_scope == "global":
                recovery_probe = False
                context_recovery_probes.discard(closed_context)
            if probe_scope == "context":
                context_recovery_probes.discard(closed_context)
            if was_recovery_probe:
                recovery_probe_events.append({"time": str(candle["time"]), "scope": probe_scope, "context": closed_context, "event": "probe_win"})
        else:
            loss_streak += 1
            loss_streak_by_context[closed_context] += 1
        if result == "LOSS":
            cooldown, evidence = _dynamic_cooldown_duration(closed_signal_row, payload, loss_streak, shadow_history)
            cooldown_until_by_context[closed_context] = index + cooldown
            cooldown_decisions.append({
                "time": str(candle["time"]), "market_regime": str(closed_signal_row.get("market_regime", "unknown")),
                "volatility_regime": str(closed_signal_row.get("volatility_regime", "normal_volatility")),
                "context": closed_context, "scope": "context", "loss_streak": loss_streak,
                "cooldown_candles": cooldown, "until_index": index + cooldown,
                "shadow_evidence": evidence,
            })
            threshold = int(payload.parameters.get("max_loss_streak_before_wait", 99) or 99)
            wait_candles = _loss_streak_wait_duration(payload)
            global_wait = bool(probe_scope == "global") or loss_streak >= threshold
            context_wait = bool(probe_scope == "context") or loss_streak_by_context[closed_context] >= threshold
            if global_wait:
                loss_streak_wait_until = index + wait_candles
                recovery_probe = False
                loss_streak_wait_events.append({"time": str(candle["time"]), "scope": "global", "until_index": loss_streak_wait_until, "loss_streak": loss_streak, "reason": "probe_loss" if probe_scope == "global" else "threshold"})
            if context_wait:
                context_wait_until[closed_context] = index + wait_candles
                context_recovery_probes.discard(closed_context)
                loss_streak_wait_events.append({"time": str(candle["time"]), "scope": "context", "context": closed_context, "until_index": context_wait_until[closed_context], "loss_streak": loss_streak_by_context[closed_context], "reason": "probe_loss" if probe_scope == "context" else "threshold"})
            if was_recovery_probe:
                recovery_probe_events.append({"time": str(candle["time"]), "scope": probe_scope, "context": closed_context, "event": "probe_loss"})
        _record_weak_regime_outcome(
            weak_regime_state, closed_context, profit_percent, result, was_weak_regime_probe,
            index, candle, payload, weak_regime_events,
        )
        regime_returns.setdefault(str(position.get("market_regime", "unknown")), []).append(profit_percent)
        meta_returns[_meta_context(position["signal_row"], direction)].append(profit_percent)
        _record_confidence_observation(confidence_history, position["signal_row"], direction, profit_percent)

        balance += balance * (profit_percent / 100)
        peak_balance = max(peak_balance, balance)
        drawdown = ((peak_balance - balance) / peak_balance) * 100 if peak_balance else 0
        max_drawdown = max(max_drawdown, drawdown)

        if profit_percent > 0:
            gross_profit += profit_percent
        else:
            gross_loss += abs(profit_percent)

        mistake = classify_mistake(
            direction,
            position["signal_row"],
            candle,
            position,
        ) if result == "LOSS" else None

        trades.append(
            SimpleTrade(
                direction=direction,
                entry_time=str(position["entry_time"]),
                exit_time=str(candle["time"]),
                entry_price=round(entry_price, 2),
                exit_price=round(exit_price, 2),
                stop_loss=round(float(position["stop_loss"]), 2),
                take_profit=round(float(position["take_profit"]), 2),
                result=result,
                profit_percent=round(profit_percent, 3),
                gross_profit_percent=round(gross_profit_percent, 3),
                execution_cost_percent=round(scaled_cost_percent, 5),
                market_profit_percent=round(market_profit_percent, 5),
                position_size_multiple=round(position_size, 5),
                risk_budget_percent=payload.risk_per_trade,
                signal_time=str(position["signal_time"]),
                signal_confidence=round(float(position["signal_row"].get("signal_confidence", 1.0) or 0), 4),
                exit_reason=exit_reason,
                balance=round(balance, 2),
                market_regime=str(position.get("market_regime", "unknown")),
                volatility_regime=str(position.get("volatility_regime", "normal_volatility")),
                mistake_type=mistake["type"] if mistake else None,
                reason=mistake["reason"] if mistake else None,
                suggestion=mistake["suggestion"] if mistake else None,
                portfolio_member=position.get("portfolio_member"),
            )
        )
        if emit_decision_trace:
            decision_trace.append(_decision_trace_event(
                index, candle, closed_signal_row, 'trade_exit', direction, True, None, {
                    'position_open': False, 'outcome': result, 'exit_reason': exit_reason,
                    'profit_percent': round(profit_percent, 5), 'balance': round(balance, 2),
                    'entry_time': str(position['entry_time']), 'exit_time': str(candle['time']),
                },
            ))
        position = None

    # A shadow still open at the end of a bounded replay is closed at the last
    # observable close.  This is explicitly marked, rather than silently
    # dropping a veto from the evidence denominator.
    if shadow_positions:
        final_candle = df.iloc[-1]
        for shadow in shadow_positions:
            _record_shadow_outcome(shadow_ledger, shadow_history, _force_close_shadow(shadow, final_candle, payload))

    total_trades = len(trades)
    wins = len([trade for trade in trades if trade.result == "WIN"])
    losses = len([trade for trade in trades if trade.result == "LOSS"])
    winrate = round((wins / total_trades) * 100, 2) if total_trades else 0.0
    net_profit = round(((balance - payload.initial_balance) / payload.initial_balance) * 100, 2)
    equity_curve = [round(payload.initial_balance, 2), *[trade.balance for trade in trades]]
    max_drawdown = calculate_max_drawdown(equity_curve)
    profit_factor = calculate_profit_factor(trades)
    average_win = calculate_average_win(trades)
    average_loss = calculate_average_loss(trades)
    risk_reward_ratio = calculate_risk_reward_ratio(average_win, average_loss)
    max_consecutive_losses = calculate_max_consecutive_losses(trades)
    stability_score = calculate_stability_score(
        max_drawdown=max_drawdown,
        max_consecutive_losses=max_consecutive_losses,
        profit_factor=profit_factor,
        total_trades=total_trades,
    )
    period_start = payload.from_date.isoformat() if payload.from_date else df["time"].min().date().isoformat()
    period_end = payload.to_date.isoformat() if payload.to_date else df["time"].max().date().isoformat()
    top_mistakes = _top_simple_mistakes(trades)
    regime_performance = calculate_regime_performance(trades)
    volatility_performance = calculate_volatility_performance(trades)
    if lightweight:
        # Screening sub-replays exist only to measure the same core trade,
        # cost, temporal and calendar outcomes under a frozen contract. They
        # must not spend CPU on promotion-only Monte Carlo/DNA diagnostics;
        # the primary screening replay and every full replay still compute
        # those immutable evidence streams in full.
        monte_carlo = {}
        strategy_dna = {}
    else:
        monte_carlo = MonteCarloService(
            simulations=1000,
            starting_balance=payload.initial_balance,
            seed=payload.random_seed,
        ).run([trade.model_dump() for trade in trades])
        strategy_dna = StrategyDnaService().generate({
            "strategy": strategy_label(payload.strategy),
            "total_trades": total_trades,
            "max_drawdown_percent": max_drawdown,
            "risk_reward_ratio": risk_reward_ratio,
            "equity_curve": equity_curve,
            "regime_performance": regime_performance,
            "volatility_performance": volatility_performance,
            "monte_carlo": monte_carlo,
        }, [trade.model_dump() for trade in trades])
    buy_hold_percent = ((float(df.iloc[-1]["close"]) - float(df.iloc[0]["close"])) / max(float(df.iloc[0]["close"]), 0.0000001)) * 100
    statistical_evidence = _statistical_evidence(trades, wins, total_trades)
    statistical_evidence["edge_quality"] = (
        {"status": "deferred_screening_subreplay", "promotion_evidence": False}
        if lightweight else _edge_quality_evidence(trades)
    )
    # Keep the full trade ledger's chronological bucket attribution alongside
    # the usual regime/month breakdown.  Screening can consume this exact
    # ledger without resetting indicator/risk state at artificial chunk
    # boundaries; full validation still runs its independent strict replay.
    pf_attribution = _pf_attribution(trades, df)
    entry_funnel_report = _entry_funnel_report(entry_funnel)
    behavioral_signature = {} if lightweight else _behavioral_signature(df, trades)
    diagnostic_telemetry = (
        {"status": "deferred_screening_subreplay", "promotion_evidence": False}
        if lightweight else _diagnostic_telemetry(trades, entry_funnel_report, pf_attribution)
    )
    veto_regret = {} if lightweight else _veto_regret_report(shadow_ledger)
    decision_blame_graph = {} if lightweight else _decision_blame_graph(trades, veto_regret)
    cooldown_policy = (
        {"status": "deferred_screening_subreplay", "promotion_evidence": False}
        if lightweight else _cooldown_policy_report(cooldown_decisions, loss_streak_wait_events, recovery_probe_events, weak_regime_events)
    )
    transition_firewall = {
        "enabled": bool(payload.parameters.get("transition_firewall_enabled", False)),
        "wait_candles": _transition_wait_duration(payload),
        "transition_events": transition_events,
        "vetoes": transition_vetoes,
        "rule": "A regime or volatility boundary creates a finite WAIT state; it never uses future outcomes or lowers a gate.",
    }
    confidence_calibration = {} if lightweight else _confidence_calibration_report(confidence_history, payload)
    robustness_matrix = _robustness_matrix(trades)
    # The paired differential lane is part of screening's causal contract,
    # even when promotion-only diagnostics are deferred.  Leaving this report
    # empty in a lightweight replay passed ``False`` identities into the
    # paired report, creating a deterministic false FAILED_NON_TARGET_REGRESSION
    # while the branch hashes and ledgers were identical.
    differential_router = (
        _differential_router_report(df, trades)
        if _is_differential_router(payload, df)
        else ({} if lightweight else _differential_router_report(df, trades))
    )
    # Paired differential lanes use lightweight execution intentionally, but
    # their branch hashes are the causal identity evidence.  Keep that small
    # invariant calculation while deferring Monte Carlo/DNA/diagnostics to the
    # primary full replay; otherwise one full candidate pays for four copies
    # of promotion-only analysis and can hit the hard timeout without changing
    # a single trade or gate metric.
    differential_invariants = (
        _differential_invariants(df, trades)
        if differential_lane is not None
        else ({} if lightweight else _differential_invariants(df, trades))
    )
    if differential_lane is not None:
        differential_router["replay_lane"] = differential_lane
    window_survival = _window_survival(df, trades, opportunities_by_month, accepted_by_month)
    regime_ensemble = {} if lightweight else _regime_ensemble_report(df, payload)
    portfolio_evidence = {} if lightweight else _portfolio_evidence(df, trades, payload)
    opportunity_metrics = _opportunity_metrics(net_profit, entry_funnel_report, window_survival)
    certified_coverage_passport = _certified_coverage_passport(trades, shadow_ledger)
    opportunity_recall = _opportunity_recall(entry_funnel_report, shadow_ledger, trades)
    router_evidence = (
        {"status": "deferred_screening_subreplay", "promotion_evidence": False}
        if lightweight
        else _router_evidence(df, payload, portfolio_evidence, opportunity_recall, statistical_evidence)
    )
    edge_claim = {} if lightweight else _edge_claim(payload, pf_attribution, statistical_evidence["edge_quality"])
    volume_quality = dict(df.attrs.get("volume_quality") or {})
    volume_shadow = (
        {"status": "deferred_screening_subreplay", "promotion_evidence": False, "quality": volume_quality}
        if lightweight
        else volume_shadow_report(df, [trade.model_dump() for trade in trades], payload.volume_context)
    )
    volume_policy = _volume_policy_report(df, payload.parameters, volume_quality)

    response = SimpleBacktestResponse(
        strategy=strategy_label(payload.strategy),
        parameters=payload.parameters,
        instrument=_display_symbol(payload.symbol),
        timeframe=payload.timeframe,
        period=f"{period_start} - {period_end}",
        initial_balance=payload.initial_balance,
        final_balance=round(balance, 2),
        net_profit_percent=net_profit,
        total_trades=total_trades,
        wins=wins,
        losses=losses,
        winrate=winrate,
        profit_factor=profit_factor,
        max_drawdown=max_drawdown,
        max_drawdown_percent=max_drawdown,
        average_win_percent=average_win,
        average_loss_percent=average_loss,
        risk_reward_ratio=risk_reward_ratio,
        max_consecutive_losses=max_consecutive_losses,
        stability_score=stability_score,
        equity_curve=equity_curve,
        regime_performance=regime_performance,
        volatility_performance=volatility_performance,
        monte_carlo=monte_carlo,
        strategy_dna=strategy_dna,
        execution_assumptions=payload.execution.model_dump(),
        execution_contract=execution_contract_metadata(payload),
        control_root=control_root_for(payload.base_strategy or payload.strategy),
        policy_boundary=policy_boundary,
        core_replay_gate=core_replay_gate({
            "total_trades": total_trades,
            "profit_factor": profit_factor,
            "data_quality": dict(df.attrs.get("data_quality") or {}),
        }),
        data_quality={**dict(df.attrs.get("data_quality") or {}),
                      "status": "warning" if (dict(df.attrs.get("data_quality") or {}).get("hard_gate_failure_count", 0) or unexpected_gap_count) else "passed",
                      "rows": len(df), "gap_control": True, "hard_gate": payload.execution.reject_unexpected_gaps,
                      "unexpected_gap_count": unexpected_gap_count,
                      "spread_quality": _spread_quality(df, payload),
                      "regime_source": regime_source_label,
                      "mtf_pilot": {
                          "protocol": "xauusd_h1_m15_mtf_v1",
                          "enabled": bool(payload.mtf_pilot.get("enabled", False)),
                          "mode": payload.mtf_pilot.get("mode", "m15_only"),
                          "veto_count": mtf_vetoes,
                          "context_counts": dict(mtf_contexts),
                          "promotion_evidence": False,
                      },
                      "decision_trace": {
                          "protocol": "candle_decision_trace_v1", "requested": emit_decision_trace,
                          "complete": emit_decision_trace, "event_count": len(decision_trace),
                          "evaluated_candle_count": max(0, len(df) - 200),
                          "promotion_evidence": False,
                      }},
        volume_quality=volume_quality,
        volume_policy=volume_policy,
        volume_shadow=volume_shadow,
        statistical_evidence=statistical_evidence,
        pf_attribution=pf_attribution,
        entry_funnel=entry_funnel_report,
        behavioral_signature=behavioral_signature,
        diagnostic_telemetry=diagnostic_telemetry,
        veto_regret=veto_regret,
        decision_blame_graph=decision_blame_graph,
        observability_protocol_version=1,
        cooldown_policy=cooldown_policy,
        transition_firewall=transition_firewall if not lightweight else {"status": "deferred_screening_subreplay", "promotion_evidence": False},
        confidence_calibration=confidence_calibration,
        robustness_matrix=robustness_matrix,
        differential_router=differential_router,
        differential_invariants=differential_invariants,
        window_survival=window_survival,
        regime_ensemble=regime_ensemble,
        portfolio_evidence=portfolio_evidence,
        router_evidence=router_evidence,
        opportunity_metrics=opportunity_metrics,
        certified_coverage_passport=certified_coverage_passport,
        opportunity_recall=opportunity_recall,
        edge_claim=edge_claim,
        benchmark={"buy_and_hold_percent": round(buy_hold_percent, 3), "edge_vs_buy_and_hold_percent": round(net_profit - buy_hold_percent, 3)},
        trade_ledger_scope="full evaluation; API display is capped to the latest 20 closed trades",
        trade_ledger_hash=_trade_ledger_hash(trades),
        displayed_trade_count=min(total_trades, 20),
        top_mistakes=top_mistakes,
        trades=trades[-20:],
        trade_ledger=[],
        conclusion=_simple_conclusion(
            winrate,
            net_profit,
            total_trades,
            top_mistakes,
            max_drawdown=max_drawdown,
            profit_factor=profit_factor,
            stability_score=stability_score,
        ),
        decision_trace=[],
    )
    response.proof_carrying_replay = _proof_carrying_replay(response.model_dump(), trades, payload)
    if emit_decision_trace:
        response.decision_trace = decision_trace
        response.trade_ledger = trades

    if (
        differential_lane is None
        and include_differential_pair
        and bool(payload.parameters.get("differential_pair_replay_enabled", True))
        and _is_differential_router(payload, df)
    ):
        if not lightweight and bool(response.core_replay_gate.get("passed", False)):
            target_regime = _differential_target_regime(payload, df)
            portfolio_non_target_trades = [trade for trade in trades if trade.market_regime != target_regime]
            portfolio_target_trades = [trade for trade in trades if trade.market_regime == target_regime]
            response.differential_router = {
                **response.differential_router,
                "paired_lane": _paired_differential_lane_report(
                    payload, source_df, _trade_summary(portfolio_non_target_trades),
                    _trade_summary(portfolio_target_trades),
                    bool(response.differential_router.get("non_target_signal_identity", False)),
                    bool(response.differential_router.get("non_target_confidence_identity", False)),
                    prepared_snapshot=snapshot,
                ),
            }
        else:
            response.differential_router = {
                **response.differential_router,
                "paired_lane": {
                    "protocol": "differential_paired_lane_v4_calendar_context_v1",
                    "status": "deferred_until_core_gate",
                    "core_gate": response.core_replay_gate,
                    "promotion_evidence": False,
                    "rule": "Paired differential replay runs only after the core candidate gate passes.",
                },
            }
    return response


def _data_quality_diagnostics(df: pd.DataFrame) -> dict[str, object]:
    """Inspect source order/values before sorting or dropping anything."""
    raw = df.copy()
    timestamps = pd.to_datetime(raw["time"], errors="coerce", utc=True)
    valid_times = timestamps.dropna()
    duplicate_count = int(valid_times.duplicated(keep="first").sum())
    non_monotonic_pairs = int((valid_times.diff().dropna() <= pd.Timedelta(0)).sum())
    numeric_invalid: dict[str, int] = {}
    converted: dict[str, pd.Series] = {}
    for column in ["open", "high", "low", "close", "volume"]:
        values = pd.to_numeric(raw[column], errors="coerce")
        converted[column] = values
        numeric_invalid[column] = int(values.isna().sum())

    required_missing = int((timestamps.isna() | converted["open"].isna() | converted["high"].isna()
                            | converted["low"].isna() | converted["close"].isna()).sum())
    valid_ohlc = ~(converted["open"].isna() | converted["high"].isna()
                   | converted["low"].isna() | converted["close"].isna())
    non_positive = valid_ohlc & ((converted["open"] <= 0) | (converted["high"] <= 0)
                                 | (converted["low"] <= 0) | (converted["close"] <= 0))
    invalid_geometry = valid_ohlc & (
        (converted["high"] < converted["open"])
        | (converted["high"] < converted["close"])
        | (converted["low"] > converted["open"])
        | (converted["low"] > converted["close"])
        | (converted["high"] < converted["low"])
    )
    invalid_ohlc_rows = int((non_positive | invalid_geometry).sum())
    failures = []
    if int(timestamps.isna().sum()): failures.append("invalid_timestamp")
    if duplicate_count: failures.append("duplicate_timestamp")
    if non_monotonic_pairs: failures.append("non_monotonic_timestamp")
    if required_missing: failures.append("missing_or_non_numeric_required_value")
    if invalid_ohlc_rows: failures.append("invalid_ohlc_geometry")
    return {
        "protocol": "historical_data_quality_v2",
        "rows_before_cleaning": len(raw),
        "invalid_timestamp_count": int(timestamps.isna().sum()),
        "duplicate_timestamp_count": duplicate_count,
        "non_monotonic_timestamp_pairs": non_monotonic_pairs,
        "missing_or_non_numeric_required_rows": required_missing,
        "invalid_ohlc_rows": invalid_ohlc_rows,
        "numeric_invalid_counts": numeric_invalid,
        "hard_gate_failures": failures,
        "hard_gate_failure_count": len(failures),
        "repair_action": "rejected_before_sort_or_dropna" if failures else "none",
        "promotion_evidence": True,
    }


def _spread_quality(df: pd.DataFrame, payload: SimpleBacktestRequest) -> dict[str, object]:
    observed_column = next((column for column in ("spread_points", "spread", "bid_ask_spread") if column in df.columns), None)
    return {
        "status": "observed" if observed_column else "assumed",
        "source": observed_column or "execution_config",
        "provider_observed": observed_column is not None,
        "column": observed_column,
        "spread_points": float(payload.execution.spread_points),
        "point_size": float(payload.execution.point_size),
        "round_trip_cost_assumption": "spread + slippage + commission",
        "promotion_evidence": observed_column is not None,
    }


def _apply_signal_delay(df: pd.DataFrame, delay: int) -> pd.DataFrame:
    """Move signal outputs forward without moving observed market features."""
    delay = max(0, int(delay or 0))
    if delay == 0:
        return df
    delayed = df.copy()
    signal_columns = [
        column for column in delayed.columns
        if column in {"signal", "parent_signal", "target_signal", "pre_volume_signal", "selected_specialist"}
        or column.endswith("_signal") or column.endswith("_specialist")
        or column.endswith("_signal_confidence") or column in {"signal_confidence", "parent_signal_confidence", "target_signal_confidence", "pre_volume_signal_confidence"}
    ]
    for column in sorted(set(signal_columns)):
        shifted = delayed[column].shift(delay)
        if "confidence" in column:
            delayed[column] = pd.to_numeric(shifted, errors="coerce").fillna(0.0)
        elif column.endswith("target") or column == "differential_target":
            delayed[column] = shifted.fillna(False).astype(bool)
        elif "specialist" in column:
            delayed[column] = shifted.where(shifted.notna(), None)
        else:
            delayed[column] = shifted.fillna("WAIT")
    delayed.attrs = dict(df.attrs)
    delayed.attrs["signal_delay_candles"] = delay
    return delayed


def _apply_portfolio_strategy(
    df: pd.DataFrame,
    members: list[object],
    *,
    prepared_member_frames: list[pd.DataFrame] | None = None,
) -> pd.DataFrame:
    """Apply a sealed complementary-member router to one candle stream.

    Members are never selected after seeing outcomes.  A member may speak only
    inside its declared regime/volatility niche.  If several members own the
    same niche, they must agree on direction; disagreement becomes WAIT. This
    prevents duplicate trades and turns disagreement into an explicit risk
    control instead of an accidental vote for the most active agent.
    """
    prepared = df.copy()
    member_frames: list[tuple[dict[str, object], pd.DataFrame]] = []
    if prepared_member_frames is not None:
        if len(prepared_member_frames) != len(members):
            raise ValueError("Prepared portfolio member snapshot count does not match the sealed council.")
        for raw, member in zip(members, prepared_member_frames):
            config = raw.model_dump() if hasattr(raw, "model_dump") else dict(raw)
            member_frames.append((config, member.copy()))
    else:
        for raw in members:
            config = raw.model_dump() if hasattr(raw, "model_dump") else dict(raw)
            function = get_strategy(str(config["strategy"]), config.get("base_strategy"))
            member = function(prepared.copy(), dict(config.get("parameters") or {}))
            member = apply_volume_policy(
                member,
                dict(config.get("parameters") or {}),
                str(config.get("base_strategy") or config.get("strategy") or ""),
            )
            member_frames.append((config, member))

    # Canonical archives contain 100k+ candles and a portfolio replay is
    # repeated for cost, temporal, adversarial and checkpoint evidence.  The
    # router is outcome-independent, so boolean masks preserve the exact
    # eligibility/disagreement rules without a nested Python row/member loop.
    return _apply_portfolio_strategy_vectorized(prepared, member_frames)

    prepared["signal"] = "WAIT"
    prepared["signal_confidence"] = 0.0
    prepared["selected_specialist"] = "portfolio_wait"
    prepared["portfolio_member_count"] = 0
    prepared["portfolio_disagreement"] = False
    prepared["portfolio_wait_reason"] = ""
    # Object-valued metadata is kept on each signal row so the execution
    # contract can carry the selected member's exits into the position.  It is
    # observability plus deterministic replay input, never an outcome label.
    prepared["portfolio_execution_parameters"] = pd.Series(
        [None] * len(prepared), index=prepared.index, dtype=object
    )

    for index in prepared.index:
        regime = str(prepared.at[index, "market_regime"] if "market_regime" in prepared else "unknown")
        volatility = str(prepared.at[index, "volatility_regime"] if "volatility_regime" in prepared else "normal_volatility")
        eligible: list[tuple[dict[str, object], pd.DataFrame]] = []
        for config, frame in member_frames:
            target_regime = config.get("target_regime")
            target_volatility = config.get("target_volatility")
            target_direction = config.get("target_direction")
            if target_direction not in {None, "BUY", "SELL"}:
                raise ValueError(f"Unsupported portfolio target direction: {target_direction}")
            if target_regime and target_regime != regime:
                continue
            if target_volatility and target_volatility != volatility:
                continue
            # A directional specialist owns only its declared side. If its
            # base strategy emits the opposite side, that is a WAIT for this
            # specialist—not a veto against a complementary opposite-side
            # member. This keeps same-regime BUY/SELL councils independent.
            if target_direction and str(frame.at[index, "signal"]) != target_direction:
                continue
            eligible.append((config, frame))

        if not eligible:
            continue
        signals = [str(frame.at[index, "signal"]) for _, frame in eligible]
        actionable = [signal for signal in signals if signal in {"BUY", "SELL"}]
        if not actionable:
            continue
        if len(set(actionable)) > 1:
            prepared.at[index, "portfolio_disagreement"] = True
            prepared.at[index, "portfolio_member_count"] = len(eligible)
            continue

        direction = actionable[0]
        agreeing = [
            (config, frame) for config, frame in eligible
            if str(frame.at[index, "signal"]) == direction
        ]
        # If an owner exists but another same-niche member explicitly says the
        # opposite, the disagreement was handled above. WAIT is preferable to
        # silently treating missing/weak specialists as a vote.
        if not agreeing:
            continue
        confidences = [
            float(frame.at[index, "signal_confidence"] if "signal_confidence" in frame else 0.0)
            for _, frame in agreeing
        ]
        # When multiple specialists own the same niche and agree on
        # direction, the first database row must not silently own execution
        # forever.  Choose the strongest *current* signal confidence in a
        # deterministic tie-preserving way; this uses no outcome/future
        # information and keeps the member's own exits bound to the trade.
        selected_config, _selected_frame = max(
            agreeing,
            key=lambda item: float(item[1].at[index, "signal_confidence"] if "signal_confidence" in item[1] else 0.0),
        )
        selected = selected_config
        prepared.at[index, "signal"] = direction
        prepared.at[index, "signal_confidence"] = max(0.0, min(1.0, sum(confidences) / max(1, len(confidences))))
        prepared.at[index, "selected_specialist"] = _portfolio_member_key(selected)
        prepared.at[index, "portfolio_member_count"] = len(agreeing)
        prepared.at[index, "portfolio_execution_parameters"] = dict(selected.get("parameters") or {})
    return prepared


def _apply_portfolio_strategy_vectorized(
    prepared: pd.DataFrame,
    member_frames: list[tuple[dict[str, object], pd.DataFrame]],
) -> pd.DataFrame:
    """Apply the sealed portfolio router using column masks.

    This is semantically equivalent to the original row-wise router.  It
    deliberately keeps the old implementation above as a readable reference
    contract while the production path avoids per-candle ``.at`` writes.
    """
    index = prepared.index
    regime = prepared.get("market_regime", pd.Series("unknown", index=index)).astype(str)
    volatility = prepared.get(
        "volatility_regime", pd.Series("normal_volatility", index=index)
    ).astype(str)

    eligible_masks: list[pd.Series] = []
    buy_masks: list[pd.Series] = []
    sell_masks: list[pd.Series] = []
    confidence_series: list[pd.Series] = []
    volume_risk_series: list[pd.Series] = []
    volume_rejection_series: list[pd.Series] = []
    member_keys: list[str] = []

    for config, frame in member_frames:
        target_regime = config.get("target_regime")
        target_volatility = config.get("target_volatility")
        target_direction = config.get("target_direction")
        if target_direction not in {None, "BUY", "SELL"}:
            raise ValueError(f"Unsupported portfolio target direction: {target_direction}")
        eligible = pd.Series(True, index=index)
        # Unknown market state is an explicit abstention boundary. A generic
        # member (target_regime omitted) must not turn missing regime evidence
        # into a trade just because its local strategy emitted BUY/SELL.
        eligible &= regime.isin(["trend_up", "trend_down", "range"])
        if target_regime:
            eligible &= regime.eq(str(target_regime))
        if target_volatility:
            eligible &= volatility.eq(str(target_volatility))

        signals = frame.get("signal", pd.Series("WAIT", index=index)).astype(str)
        # A directional specialist owns only its declared side. An opposite
        # signal is WAIT for that member, not a veto against another member.
        if target_direction:
            eligible &= signals.eq(str(target_direction))
        confidence = pd.to_numeric(
            frame.get("signal_confidence", pd.Series(0.0, index=index)),
            errors="coerce",
        ).fillna(0.0)
        eligible_masks.append(eligible)
        buy_masks.append(eligible & signals.eq("BUY"))
        sell_masks.append(eligible & signals.eq("SELL"))
        confidence_series.append(confidence)
        volume_risk_series.append(pd.to_numeric(
            frame.get("volume_risk_multiplier", pd.Series(1.0, index=index)),
            errors="coerce",
        ).fillna(1.0).clip(lower=0.1, upper=1.0))
        volume_rejection_series.append(frame.get(
            "volume_policy_rejection", pd.Series("", index=index)
        ).astype(str))
        member_keys.append(_portfolio_member_key(config))

    prepared["signal"] = "WAIT"
    prepared["signal_confidence"] = 0.0
    prepared["selected_specialist"] = "portfolio_wait"
    prepared["portfolio_member_count"] = 0
    prepared["portfolio_disagreement"] = False
    prepared["portfolio_wait_reason"] = ""
    prepared["portfolio_execution_parameters"] = pd.Series(
        [None] * len(index), index=index, dtype=object
    )
    prepared["volume_risk_multiplier"] = 1.0
    prepared["volume_policy_rejection"] = ""
    if not member_frames:
        return prepared

    eligible_count = sum(mask.astype(int) for mask in eligible_masks)
    buy_count = sum(mask.astype(int) for mask in buy_masks)
    sell_count = sum(mask.astype(int) for mask in sell_masks)
    disagreement = buy_count.gt(0) & sell_count.gt(0)
    buy_only = buy_count.gt(0) & sell_count.eq(0)
    sell_only = sell_count.gt(0) & buy_count.eq(0)
    actionable = buy_only | sell_only

    prepared.loc[buy_only, "signal"] = "BUY"
    prepared.loc[sell_only, "signal"] = "SELL"
    prepared.loc[disagreement, "portfolio_disagreement"] = True
    prepared.loc[disagreement, "portfolio_member_count"] = eligible_count.loc[disagreement]
    prepared.loc[disagreement, "portfolio_wait_reason"] = "council_disagreement"
    no_specialist = eligible_count.eq(0)
    prepared.loc[no_specialist & regime.eq("unknown"), "portfolio_wait_reason"] = "unknown_state_wait"
    prepared.loc[no_specialist & regime.ne("unknown"), "portfolio_wait_reason"] = "no_specialist_for_state"

    # Average confidence uses only agreeing specialists. Selecting the
    # strongest current confidence uses strict `>` so ties preserve the first
    # database member exactly as the old deterministic max() did.
    selected_index = pd.Series(-1, index=index, dtype="int64")
    selected_confidence = pd.Series(float("-inf"), index=index, dtype="float64")
    confidence_total = pd.Series(0.0, index=index, dtype="float64")
    agreeing_count = pd.Series(0, index=index, dtype="int64")
    for member_index, (buy_mask, sell_mask, confidence) in enumerate(
        zip(buy_masks, sell_masks, confidence_series)
    ):
        agreeing = (buy_mask | sell_mask) & actionable
        confidence_total += confidence.where(agreeing, 0.0)
        agreeing_count += agreeing.astype(int)
        stronger = agreeing & confidence.gt(selected_confidence)
        selected_index.loc[stronger] = member_index
        selected_confidence.loc[stronger] = confidence.loc[stronger]

    normal_action = actionable & ~disagreement
    prepared.loc[normal_action, "signal_confidence"] = (
        confidence_total.loc[normal_action] / agreeing_count.loc[normal_action].clip(lower=1)
    ).clip(lower=0.0, upper=1.0)
    # The council refuses a low-confidence consensus as well as an
    # opposite-direction disagreement. This is a fixed safety invariant,
    # not a PF-trained threshold; calibration is evaluated separately.
    low_confidence = normal_action & prepared["signal_confidence"].lt(0.35)
    prepared.loc[low_confidence, "signal"] = "WAIT"
    prepared.loc[low_confidence, "portfolio_wait_reason"] = "calibrated_confidence_below_minimum"
    prepared.loc[low_confidence, "portfolio_member_count"] = eligible_count.loc[low_confidence]
    normal_action = normal_action & ~low_confidence
    prepared.loc[normal_action, "portfolio_member_count"] = agreeing_count.loc[normal_action]

    # Execution metadata remains bound to the selected sealed member. Build
    # object columns once instead of issuing one pandas .at write per candle.
    selected_specialists = ["portfolio_wait"] * len(index)
    execution_parameters: list[object] = [None] * len(index)
    index_positions = {label: position for position, label in enumerate(index)}
    for label, member_index in selected_index.items():
        if not normal_action.loc[label] or int(member_index) < 0:
            continue
        position = index_positions[label]
        selected_config = member_frames[int(member_index)][0]
        selected_specialists[position] = member_keys[int(member_index)]
        execution_parameters[position] = dict(selected_config.get("parameters") or {})
        prepared.at[label, "volume_risk_multiplier"] = float(
            volume_risk_series[int(member_index)].loc[label]
        )
        prepared.at[label, "volume_policy_rejection"] = str(
            volume_rejection_series[int(member_index)].loc[label]
        )
    prepared["selected_specialist"] = pd.Series(selected_specialists, index=index, dtype=object)
    prepared["portfolio_execution_parameters"] = pd.Series(execution_parameters, index=index, dtype=object)
    return prepared


def _portfolio_member_key(config: dict[str, object]) -> str:
    """Return a stable, outcome-independent identity for one sealed member.

    A role such as ``trend_up`` is a routing niche, not an agent identity:
    two generations can legitimately own the same niche while having
    different execution topology.  Attribution must therefore retain the
    immutable performance key when Laravel supplies it, with a deterministic
    strategy/niche fallback for direct API and unit-test callers.
    """
    explicit = str(config.get("member_key") or "").strip()
    if explicit:
        return explicit
    strategy = str(config.get("strategy") or "portfolio_member").strip()
    role = str(config.get("role") or "").strip()
    regime = str(config.get("target_regime") or "").strip()
    volatility = str(config.get("target_volatility") or "").strip()
    direction = str(config.get("target_direction") or "").strip()
    return "|".join([strategy, role, regime, volatility, direction])


def _portfolio_payload_for_signal(
    payload: SimpleBacktestRequest, signal_row: pd.Series
) -> SimpleBacktestRequest:
    """Bind the selected member's sealed execution genes for one entry.

    The portfolio-level policy remains authoritative for transition and risk
    governance.  All member parameters are otherwise preserved so a breakout
    specialist does not inherit a range specialist's exit topology merely
    because it happened to be the first member in the registry.
    """
    if not payload.portfolio_members:
        return payload
    member_parameters = signal_row.get("portfolio_execution_parameters")
    if not isinstance(member_parameters, dict) or not member_parameters:
        return payload
    merged = {**member_parameters, **dict(payload.parameters or {})}
    # These are portfolio-owned controls and must never be overridden by a
    # member's local experiment.
    for key in ("portfolio_policy_version", "transition_firewall_enabled", "transition_wait_candles"):
        if key in payload.parameters:
            merged[key] = payload.parameters[key]
    return payload.model_copy(update={"parameters": merged})


def _payload_for_position(
    payload: SimpleBacktestRequest, position: dict[str, object]
) -> SimpleBacktestRequest:
    """Rehydrate the exact entry-time execution contract for open positions."""
    parameters = position.get("execution_parameters")
    if not isinstance(parameters, dict) or not parameters:
        return payload
    return payload.model_copy(update={"parameters": dict(parameters)})


def _portfolio_evidence(df: pd.DataFrame, trades: list[SimpleTrade], payload: SimpleBacktestRequest) -> dict[str, object]:
    if not payload.portfolio_members:
        return {"status": "not_applicable"}
    regimes = sorted({str(trade.market_regime) for trade in trades})
    roles = sorted({str(trade.reason or "") for trade in trades if trade.reason})
    disagreements = int(df.get("portfolio_disagreement", pd.Series(dtype=bool)).sum())
    member_configs = {
        _portfolio_member_key(member.model_dump() if hasattr(member, "model_dump") else dict(member)): member
        for member in payload.portfolio_members
    }
    by_member: dict[str, list[SimpleTrade]] = {key: [] for key in member_configs}
    for trade in trades:
        member = str(trade.portfolio_member or "unknown")
        by_member.setdefault(member, []).append(trade)
    member_breakdown = {}
    for member, member_trades in by_member.items():
        by_month: dict[str, list[float]] = {}
        by_context: dict[str, list[float]] = {}
        by_context_month: dict[str, dict[str, list[float]]] = {}
        by_context_direction: dict[str, dict[str, list[float]]] = {}
        by_context_direction_month: dict[str, dict[str, dict[str, list[float]]]] = {}
        for trade in member_trades:
            month = _utc_month(trade.entry_time)
            by_month.setdefault(month, []).append(float(trade.profit_percent))
            context = f"{trade.market_regime}|{trade.volatility_regime}"
            by_context.setdefault(context, []).append(float(trade.profit_percent))
            by_context_month.setdefault(context, {}).setdefault(month, []).append(float(trade.profit_percent))
            direction = str(trade.direction)
            by_context_direction.setdefault(context, {}).setdefault(direction, []).append(float(trade.profit_percent))
            by_context_direction_month.setdefault(context, {}).setdefault(direction, {}).setdefault(month, []).append(float(trade.profit_percent))
        config = member_configs.get(member)
        config_dict = config.model_dump() if hasattr(config, "model_dump") else dict(config or {})
        direction_breakdown = {
            context: {
                direction: {
                    "trades": len(values),
                    "profit_factor": _profit_factor_for(values),
                    "wins": sum(value > 0 for value in values),
                    "losses": sum(value <= 0 for value in values),
                    "monthly": {
                        month: {"trades": len(month_values), "profit_factor": _profit_factor_for(month_values)}
                        for month, month_values in by_context_direction_month.get(context, {}).get(direction, {}).items()
                    },
                }
                for direction, values in directions.items()
            }
            for context, directions in by_context_direction.items()
        }
        member_breakdown[member] = {
            "role": config_dict.get("role"),
            "target_regime": config_dict.get("target_regime"),
            "target_volatility": config_dict.get("target_volatility"),
            "target_direction": config_dict.get("target_direction"),
            "trades": len(member_trades),
            "profit_factor": _profit_factor_for([float(trade.profit_percent) for trade in member_trades]),
            "wins": sum(float(trade.profit_percent) > 0 for trade in member_trades),
            "losses": sum(float(trade.profit_percent) <= 0 for trade in member_trades),
            "monthly": {
                month: {"trades": len(values), "profit_factor": _profit_factor_for(values)}
                for month, values in by_month.items()
            },
            # This is diagnostic evidence, not a selector.  It lets the
            # Laravel curriculum identify a recurring regime x volatility
            # failure without turning a calendar label into a feature.
            "context_breakdown": {
                context: {
                    "trades": len(values),
                    "profit_factor": _profit_factor_for(values),
                    "wins": sum(value > 0 for value in values),
                    "losses": sum(value <= 0 for value in values),
                    "monthly": {
                        month: {
                            "trades": len(month_values),
                            "profit_factor": _profit_factor_for(month_values),
                        }
                        for month, month_values in months.items()
                    },
                }
                for context, values in by_context.items()
                for months in [by_context_month.get(context, {})]
            },
            "direction_breakdown": direction_breakdown,
        }
    loss_sets = {
        member: {str(trade.entry_time) for trade in member_trades if float(trade.profit_percent) < 0}
        for member, member_trades in by_member.items()
    }
    correlations = []
    member_keys = sorted(loss_sets)
    for index, left in enumerate(member_keys):
        for right in member_keys[index + 1:]:
            union = loss_sets[left] | loss_sets[right]
            correlations.append(len(loss_sets[left] & loss_sets[right]) / len(union) if union else 0.0)
    member_returns = {
        member: [float(trade.profit_percent) for trade in member_trades]
        for member, member_trades in by_member.items()
    }
    # These are deterministic fixed-route counterfactual replays. They do
    # not select a replacement after seeing outcomes: they simply remove one
    # sealed member from the already routed ledger, then re-aggregate it.
    leave_one_out = {
        member: {
            "trades": sum(len(values) for key, values in member_returns.items() if key != member),
            "profit_factor": _profit_factor_for([value for key, values in member_returns.items() if key != member for value in values]),
        }
        for member in member_returns
    }
    perturbations = {}
    for member, values in member_returns.items():
        for multiplier in (0.8, 1.2):
            weighted = [value * multiplier if key == member else value for key, rows in member_returns.items() for value in rows]
            perturbations[f"{member}@{multiplier}"] = {"profit_factor": _profit_factor_for(weighted), "trades": len(weighted)}
    selected = df.get("selected_specialist", pd.Series("portfolio_wait", index=df.index)).astype(str)
    contexts = (df.get("market_regime", pd.Series("unknown", index=df.index)).astype(str)
        + "|" + df.get("volatility_regime", pd.Series("unknown", index=df.index)).astype(str))
    active = selected.ne("portfolio_wait")
    comparable = active & active.shift(1, fill_value=False) & contexts.eq(contexts.shift(1))
    switches = int((selected.ne(selected.shift(1)) & comparable).sum())
    stable_rows = int(comparable.sum())
    contribution = {member: sum(values) for member, values in member_returns.items()}
    positive_contribution = sum(max(0.0, value) for value in contribution.values())
    contribution_share = max((max(0.0, value) / positive_contribution for value in contribution.values()), default=0.0) if positive_contribution else 0.0
    regime_opportunities = {regime: sum(1 for trade in trades if str(trade.market_regime) == regime) for regime in regimes}
    return {
        "status": "observed",
        "member_count": len(payload.portfolio_members),
        "declared_members": [
            {
                "member_key": _portfolio_member_key(member.model_dump() if hasattr(member, "model_dump") else dict(member)),
                "strategy": member.strategy,
                "role": member.role,
                "target_regime": member.target_regime,
                "target_volatility": member.target_volatility,
                "target_direction": member.target_direction,
            }
            for member in payload.portfolio_members
        ],
        "trade_regimes": regimes,
        "disagreement_rows": disagreements,
        "disagreement_rate": round(disagreements / max(1, len(df)), 6),
        "loss_correlation": {
            "max_jaccard": round(max(correlations, default=0.0), 6),
            "mean_jaccard": round(sum(correlations) / len(correlations), 6) if correlations else 0.0,
            "pair_count": len(correlations),
        },
        "leave_one_member_out": {
            "method": "sealed_fixed_route_ledger_replay", "members": leave_one_out,
            "minimum_profit_factor": round(min((row["profit_factor"] for row in leave_one_out.values()), default=0.0), 6),
        },
        "weight_perturbation": {
            "method": "symmetric_member_return_scaling", "scenarios": perturbations,
            "minimum_profit_factor": round(min((row["profit_factor"] for row in perturbations.values()), default=0.0), 6),
        },
        "router_stability": {
            "same_context_comparisons": stable_rows, "switches": switches,
            "switch_rate": round(switches / max(1, stable_rows), 6),
        },
        "member_contribution": {
            "net_profit_percent": {key: round(value, 6) for key, value in contribution.items()},
            "max_positive_share": round(contribution_share, 6),
        },
        "opportunity_coverage": {
            "regime_accepted_entries": regime_opportunities,
            "covered_regimes": len([count for count in regime_opportunities.values() if count >= 3]),
        },
        "member_breakdown": member_breakdown,
        "execution_contract": "member_specific_execution_v1",
        "rule": "Members are independently validated; portfolio replay only measures sealed routing interaction.",
    }


def _statistical_evidence(trades: list[SimpleTrade], wins: int, total_trades: int) -> dict[str, object]:
    if total_trades == 0:
        return {"trade_count": 0, "winrate_ci_95": [0.0, 0.0], "regime_profit_factor": {}}

    # Wilson interval is robust for small samples and makes the uncertainty of
    # a 10-trade backtest visible instead of presenting a point win rate as fact.
    z = 1.96
    p = wins / total_trades
    denominator = 1 + z * z / total_trades
    centre = (p + z * z / (2 * total_trades)) / denominator
    margin = z * (((p * (1 - p) / total_trades) + (z * z / (4 * total_trades * total_trades))) ** 0.5) / denominator
    regime_pf: dict[str, float] = {}
    for regime in {trade.market_regime for trade in trades}:
        subset = [trade.profit_percent for trade in trades if trade.market_regime == regime]
        gross_win = sum(value for value in subset if value > 0)
        gross_loss = abs(sum(value for value in subset if value <= 0))
        regime_pf[regime] = round(gross_win / gross_loss, 3) if gross_loss else (99.0 if gross_win else 0.0)

    return {
        "trade_count": total_trades,
        "winrate_ci_95": [round(max(0, centre - margin) * 100, 2), round(min(1, centre + margin) * 100, 2)],
        "regime_profit_factor": regime_pf,
        "minimum_sample_recommendation": 50,
    }


def _entry_price(close: float, signal: str, payload: SimpleBacktestRequest) -> float:
    execution = payload.execution
    spread = execution.spread_points * execution.point_size
    slippage = execution.slippage_points * execution.point_size
    return close + spread / 2 + slippage if signal == "BUY" else close - spread / 2 - slippage


def _exit_price(market_price: float, direction: str, payload: SimpleBacktestRequest) -> float:
    execution = payload.execution
    spread = execution.spread_points * execution.point_size
    slippage = execution.slippage_points * execution.point_size
    return market_price - spread / 2 - slippage if direction == "BUY" else market_price + spread / 2 + slippage


def _position_size_multiple(
    entry_price: float,
    stop_loss: float,
    direction: str,
    payload: SimpleBacktestRequest,
) -> float:
    stop_execution_price = _exit_price(stop_loss, direction, payload)
    if direction == "BUY":
        stop_return = (entry_price - stop_execution_price) / max(entry_price, 0.0000001) * 100
    else:
        stop_return = (stop_execution_price - entry_price) / max(entry_price, 0.0000001) * 100
    stop_return = max(stop_return + payload.execution.commission_percent, 0.000001)
    return min(payload.execution.max_leverage, payload.risk_per_trade / stop_return)


def _risk_context(signal_row: pd.Series, direction: str) -> str:
    """Stable context key for loss containment; it contains no future data."""
    return "|".join([
        str(signal_row.get("market_regime", "unknown")),
        str(signal_row.get("volatility_regime", "normal_volatility")),
        direction, str(signal_row.get("selected_specialist", "parent")),
    ])


def _differential_target_regime(payload: SimpleBacktestRequest, df: pd.DataFrame | None = None) -> str:
    value = str(payload.parameters.get("differential_target_regime", "trend_down"))
    if value in {"trend_up", "range", "trend_down"}:
        return value
    if df is not None and "differential_target_regime" in df.columns:
        observed = df["differential_target_regime"].dropna().astype(str)
        if not observed.empty and observed.iloc[0] in {"trend_up", "range", "trend_down"}:
            return observed.iloc[0]
    return "trend_down"


def _is_differential_router(payload: SimpleBacktestRequest, df: pd.DataFrame) -> bool:
    return "differential_target" in df.columns or "differential_router" in str(payload.base_strategy or payload.strategy).lower()


def _effective_lane_signal(signal_row: pd.Series, lane: str | None) -> tuple[str, float, str]:
    """Return the signal owned by one side of a paired differential replay.

    ``*_parent`` lanes use the frozen parent signal, while ``*_child`` lanes
    use the candidate signal.  A lane never sees another lane's signal.  This
    makes risk state and position occupancy local to the replay being measured
    instead of letting a target mutation rewrite the control lane.
    """
    current = str(signal_row.get("signal", "WAIT"))
    current_confidence = float(signal_row.get("signal_confidence", 1.0) or 0)
    parent = str(signal_row.get("parent_signal", current))
    parent_confidence = float(signal_row.get("parent_signal_confidence", current_confidence) or 0)
    target = bool(signal_row.get("differential_target", False))
    if lane is None:
        return current, current_confidence, str(signal_row.get("selected_specialist", "parent"))
    target_lane = lane.startswith("target_")
    if target != target_lane:
        return "WAIT", 0.0, "parent"
    parent_lane = lane.endswith("_parent")
    if parent_lane:
        return parent, parent_confidence, "parent"
    return current, current_confidence, str(signal_row.get("selected_specialist", "target_child"))


def _is_volume_policy_veto(row: pd.Series | None) -> bool:
    if row is None:
        return False
    rejection = str(row.get("volume_policy_rejection", "") or "")
    return rejection.startswith((
        "breakout_volume",
        "transition_volume",
        "low_volume_wait",
    ))


def _count_lane_signals(df: pd.DataFrame, lane: str | None) -> int:
    if lane is None:
        signals = df.iloc[199:]["signal"].astype(str)
        policy_veto = df.iloc[199:].apply(_is_volume_policy_veto, axis=1)
        pre = df.iloc[199:].get("pre_volume_signal", signals).astype(str)
        signals = signals.where(~policy_veto, pre)
        return int(signals.isin(["BUY", "SELL"]).sum())
    return sum(
        _effective_lane_signal(row, lane)[0] in {"BUY", "SELL"}
        for _, row in df.iloc[199:].iterrows()
    )


def _volume_policy_report(
    df: pd.DataFrame,
    parameters: dict[str, object] | None,
    quality: dict[str, object] | None = None,
) -> dict[str, object]:
    """Expose the causal effect of a volume lane without promoting it.

    A child that receives volume data but never owns a matching specialist is
    reported as zero-effect rather than being mistaken for a successful
    confirmation. Missing volume remains ``volume_unavailable`` and is never
    counted as low-volume evidence.
    """
    params = parameters or {}
    lane = str(params.get("volume_lane", "none") or "none")
    quality = dict(quality or {})
    index = df.index
    available = df.get("volume_feature_available", pd.Series(False, index=index)).fillna(False).astype(bool)
    pre = df.get("pre_volume_signal", df.get("signal", pd.Series("WAIT", index=index))).astype(str)
    final = df.get("signal", pd.Series("WAIT", index=index)).astype(str)
    actionable = pre.isin(["BUY", "SELL"])
    accepted = final.isin(["BUY", "SELL"])
    rejection = df.get("volume_policy_rejection", pd.Series("", index=index)).astype(str)
    risk = pd.to_numeric(
        df.get("volume_risk_multiplier", pd.Series(1.0, index=index)), errors="coerce"
    ).fillna(1.0)
    observed = df.iloc[199:] if len(df) > 199 else df.iloc[0:0]
    observed_available = available.loc[observed.index]
    observed_actionable = actionable.loc[observed.index]
    observed_accepted = accepted.loc[observed.index]
    observed_rejection = rejection.loc[observed.index]
    observed_risk = risk.loc[observed.index]
    counts = observed_rejection[observed_rejection.ne("")].value_counts().to_dict()
    specialist = df.get("selected_specialist", pd.Series("unknown", index=index)).astype(str)
    specialist_counts = specialist.loc[observed.index][observed_accepted].value_counts().to_dict()
    quality_status = str(quality.get("status", "volume_unavailable") or "volume_unavailable")
    return {
        "protocol": "volume_policy_telemetry_v1",
        "lane": lane,
        "status": "control" if lane == "none" else ("applied" if quality_status == "passed" else "volume_unavailable"),
        "quality_status": quality_status,
        "rows_evaluated": int(len(observed)),
        "feature_available_rows": int(observed_available.sum()),
        "feature_coverage": round(float(observed_available.mean()), 6) if len(observed_available) else 0.0,
        "pre_volume_actionable": int(observed_actionable.sum()),
        "post_volume_actionable": int(observed_accepted.sum()),
        "volume_vetoes": int((observed_actionable & ~observed_accepted).sum()),
        "unavailable_actionable": int((observed_actionable & ~observed_available).sum()),
        "reduced_risk_rows": int((observed_actionable & observed_risk.lt(1.0)).sum()),
        "rejection_counts": {str(key): int(value) for key, value in counts.items()},
        "selected_specialist_counts": {str(key): int(value) for key, value in specialist_counts.items()},
        "promotion_evidence": False,
    }


def _trade_ledger_hash(trades: list[SimpleTrade]) -> str:
    values = [
        "|".join([
            str(trade.entry_time), str(trade.exit_time), str(trade.direction),
            f"{float(trade.profit_percent):.8f}", str(trade.exit_reason), str(trade.market_regime),
        ])
        for trade in trades
    ]
    return hashlib.sha256("\n".join(values).encode()).hexdigest()


def _decision_trace_event(
    index: int,
    candle: pd.Series,
    signal_row: pd.Series,
    event_type: str,
    action: str,
    accepted: bool,
    rejection_code: str | None,
    state: dict[str, object],
) -> dict[str, object]:
    """Build a compact, deterministic per-candle decision record.

    The trace contains only information known at the decision candle. Trade
    outcomes are separate ``trade_exit`` events, so no future result leaks
    into an entry decision.
    """
    features: dict[str, object] = {}
    for key, value in signal_row.items():
        name = str(key)
        if name in {"time", "signal", "parent_signal", "selected_specialist"} or name.startswith("_"):
            continue
        if isinstance(value, (dict, list, tuple)):
            continue
        try:
            missing = pd.isna(value)
            if not hasattr(missing, "__len__") and bool(missing):
                continue
        except (TypeError, ValueError):
            pass
        if isinstance(value, bool):
            features[name] = value
        elif isinstance(value, (int, float)):
            features[name] = float(value)
        else:
            try:
                scalar = value.item()
                features[name] = float(scalar) if isinstance(scalar, (int, float)) else str(scalar)
            except (AttributeError, TypeError, ValueError):
                if isinstance(value, str):
                    features[name] = value

    for name in ["open", "high", "low", "close", "volume"]:
        value = candle.get(name)
        if value is not None:
            try:
                features[f"candle_{name}"] = float(value)
            except (TypeError, ValueError):
                pass

    safe_state = json.loads(json.dumps(state, default=str))
    return {
        "candle_time": str(candle.get("time", signal_row.get("time", ""))),
        "candle_index": index,
        "event_type": event_type,
        "action": action,
        "accepted": accepted,
        "rejection_code": rejection_code,
        "market_regime": str(signal_row.get("market_regime", "unknown")),
        "volatility_regime": str(signal_row.get("volatility_regime", "normal_volatility")),
        "confidence": float(signal_row.get("signal_confidence", 0.0) or 0.0),
        "price": float(candle.get("open", 0.0) or 0.0),
        "features": features,
        "state": safe_state,
    }


def _trade_summary(trades: list[SimpleTrade], ledger_hash: str | None = None) -> dict[str, object]:
    values = [float(trade.profit_percent) for trade in trades]
    return {
        "trades": len(trades),
        "profit_factor": _profit_factor_for(values),
        "net_profit_percent": round(sum(values), 6),
        "ledger_hash": ledger_hash or _trade_ledger_hash(trades),
        "entry_times_hash": hashlib.sha256("\n".join(str(trade.entry_time) for trade in trades).encode()).hexdigest(),
    }


def _response_trade_summary(response: SimpleBacktestResponse) -> dict[str, object]:
    return _trade_summary([], response.trade_ledger_hash) | {
        "trades": int(response.total_trades),
        "profit_factor": float(response.profit_factor),
        "net_profit_percent": float(response.net_profit_percent),
    }


def _differential_invariants(df: pd.DataFrame, trades: list[SimpleTrade]) -> dict[str, object]:
    if "differential_target" not in df.columns:
        return {"enabled": False}
    target = df["differential_target"].fillna(False).astype(bool)
    result: dict[str, object] = {"enabled": True, "branches": {}}
    for regime in sorted(set(df.loc[~target, "market_regime"].astype(str))):
        mask = (~target) & (df["market_regime"].astype(str) == regime)
        rows = df.loc[mask, ["time", "signal", "signal_confidence", "parent_signal", "parent_signal_confidence"]]
        def digest(columns: list[str]) -> str:
            values = ["|".join(str(row[column]) for column in columns) for _, row in rows.iterrows()]
            return hashlib.sha256("\n".join(values).encode()).hexdigest()
        branch_trades = [trade for trade in trades if trade.market_regime == regime]
        result["branches"][regime] = {
            "child_signal_hash": digest(["time", "signal"]),
            "parent_signal_hash": digest(["time", "parent_signal"]),
            "child_confidence_hash": digest(["time", "signal_confidence"]),
            "parent_confidence_hash": digest(["time", "parent_signal_confidence"]),
            "trade_ledger_hash": _trade_ledger_hash(branch_trades),
            "entry_times_hash": hashlib.sha256("\n".join(str(trade.entry_time) for trade in branch_trades).encode()).hexdigest(),
        }
    return result


def _paired_differential_lane_report(
    payload: SimpleBacktestRequest,
    source_df: pd.DataFrame,
    portfolio_non_target: dict[str, object],
    portfolio_target: dict[str, object],
    signal_identity: bool = False,
    confidence_identity: bool = False,
    prepared_snapshot: PreparedSignalSnapshot | None = None,
) -> dict[str, object]:
    """Run parent/child lane ledgers under identical data and cost rules."""
    target = _differential_target_regime(payload)
    # The four paired ledgers must retain the exact execution core, but they
    # do not need Monte Carlo, strategy DNA, behavioral telemetry, or other
    # promotion-only diagnostics. Those are already computed once by the
    # primary full replay. This cuts causal replay cost without changing any
    # trade, ledger hash, branch identity, or gate outcome.
    paired_kwargs = {"include_differential_pair": False, "lightweight": True}
    parent_non_target = _run_prepared_simple_backtest(
        payload, source_df.copy(), differential_lane="non_target_parent",
        prepared_snapshot=prepared_snapshot, **paired_kwargs
    )
    child_non_target = _run_prepared_simple_backtest(
        payload, source_df.copy(), differential_lane="non_target_child",
        prepared_snapshot=prepared_snapshot, **paired_kwargs
    )
    parent_target = _run_prepared_simple_backtest(
        payload, source_df.copy(), differential_lane="target_parent",
        prepared_snapshot=prepared_snapshot, **paired_kwargs
    )
    parent_summary = _response_trade_summary(parent_non_target)
    child_summary = _response_trade_summary(child_non_target)
    target_parent_summary = _response_trade_summary(parent_target)
    # The primary full replay above already produced the sealed child target
    # ledger. Reusing its immutable summary avoids a fourth identical child
    # execution while retaining an independent parent target comparison.
    target_child_summary = portfolio_target
    parent_branches = (parent_non_target.differential_invariants or {}).get("branches", {})
    child_branches = (child_non_target.differential_invariants or {}).get("branches", {})
    branch_identity = parent_branches == child_branches
    ledger_identity = parent_summary["ledger_hash"] == child_summary["ledger_hash"] and branch_identity
    target_delta = round(float(target_child_summary["net_profit_percent"]) - float(target_parent_summary["net_profit_percent"]), 6)
    isolated_status = (
        signal_identity
        and confidence_identity
        and ledger_identity
        and int(parent_summary["trades"]) == int(child_summary["trades"])
        and float(child_summary["net_profit_percent"]) >= float(parent_summary["net_profit_percent"]) - .01
    )
    portfolio_delta = round(
        float(portfolio_non_target.get("net_profit_percent", 0)) - float(child_summary["net_profit_percent"]), 6
    )
    return {
        "protocol": "differential_paired_lane_v4_calendar_context_v1",
        "status": "passed" if isolated_status else "failed",
        "target_regime": target,
        "non_target_signal_identity": signal_identity,
        "non_target_confidence_identity": confidence_identity,
        "non_target_ledger_identity": ledger_identity,
        "non_target_entry_times_identity": all(
            data.get("entry_times_hash") == child_branches.get(regime, {}).get("entry_times_hash")
            for regime, data in parent_branches.items()
        ) and set(parent_branches) == set(child_branches),
        "non_target_branch_hashes": {"parent": parent_branches, "child": child_branches},
        "parent_non_target": parent_summary,
        "child_non_target": child_summary,
        "parent_target": target_parent_summary,
        "child_target": target_child_summary,
        "target_delta_net_profit_percent": target_delta,
        "portfolio_child_non_target": portfolio_non_target,
        "portfolio_interaction_delta_net_profit_percent": portfolio_delta,
        "rule": "Non-target identity is judged on isolated paired ledgers; portfolio interaction is reported separately.",
        "promotion_evidence": False,
    }


def _loss_streak_wait_duration(payload: SimpleBacktestRequest) -> int:
    """Finite wait duration, defaulting to the configured short cooldown."""
    fallback = int(payload.parameters.get("loss_cooldown_candles", 1) or 1)
    return max(1, int(payload.parameters.get("loss_streak_wait_candles", fallback) or fallback))


def _recovery_probe_risk_multiplier(payload: SimpleBacktestRequest) -> float:
    """The probe is deliberately bounded below normal risk, never enlarged."""
    return min(1.0, max(0.1, float(payload.parameters.get("recovery_probe_risk_multiplier", 0.5) or 0.5)))


def _weak_regime_minimum_samples(payload: SimpleBacktestRequest) -> int:
    return max(15, int(payload.parameters.get("weak_regime_min_samples", 15) or 15))


def _weak_regime_wait_duration(payload: SimpleBacktestRequest) -> int:
    return max(1, int(payload.parameters.get("weak_regime_wait_candles", payload.parameters.get("loss_streak_wait_candles", 4)) or 4))


def _advance_weak_regime_state(
    states: dict[str, dict[str, object]], context: str, index: int, candle: pd.Series,
    events: list[dict[str, object]],
) -> tuple[bool, bool]:
    """Return (temporarily_blocked, this-entry-is-recovery-probe)."""
    state = states.get(context)
    if state is None:
        return False, False
    wait_until = int(state.get("wait_until", -1) or -1)
    if wait_until >= 0 and index < wait_until:
        return True, False
    if wait_until >= 0:
        state["wait_until"] = -1
        state["probe_pending"] = True
        events.append({"time": str(candle["time"]), "context": context, "event": "wait_expired"})
    if bool(state.get("probe_pending", False)):
        # Mark it consumed only when the caller actually accepts the entry.
        # Until then, the next eligible signal remains the one bounded probe.
        return False, True
    return False, False


def _record_weak_regime_outcome(
    states: dict[str, dict[str, object]], context: str, profit_percent: float, result: str,
    was_probe: bool, index: int, candle: pd.Series, payload: SimpleBacktestRequest,
    events: list[dict[str, object]],
) -> None:
    state = states.setdefault(context, {"returns_window": [], "loss_count": 0, "wait_until": -1, "probe_pending": False})
    values = list(state.get("returns_window", []))[-19:]
    values.append(float(profit_percent))
    state["returns_window"] = values
    state["loss_count"] = sum(value <= 0 for value in values)
    if was_probe:
        state["probe_pending"] = False
        if result == "WIN":
            # A probe win removes the veto and starts fresh evidence for this
            # exact context.  Other contexts remain untouched.
            state.update({"returns_window": [], "loss_count": 0, "wait_until": -1})
            events.append({"time": str(candle["time"]), "context": context, "event": "probe_win"})
            return
        state["wait_until"] = index + _weak_regime_wait_duration(payload)
        events.append({"time": str(candle["time"]), "context": context, "event": "probe_loss", "until_index": state["wait_until"]})
        return
    if len(values) >= _weak_regime_minimum_samples(payload) and _profit_factor_for(values) < 1.0:
        state["wait_until"] = index + _weak_regime_wait_duration(payload)
        state["probe_pending"] = False
        events.append({
            "time": str(candle["time"]), "context": context, "event": "weak_regime_wait_started",
            "until_index": state["wait_until"], "sample_count": len(values), "profit_factor": round(_profit_factor_for(values), 4),
        })


def _intrabar_exit(
    direction: str,
    position: dict[str, object],
    candle: pd.Series,
    payload: SimpleBacktestRequest,
) -> tuple[float | None, str | None]:
    stop = float(position["stop_loss"])
    target = float(position["take_profit"])
    candle_open = float(candle["open"])
    high, low = float(candle["high"]), float(candle["low"])

    if direction == "BUY":
        if candle_open <= stop:
            return _exit_price(candle_open, direction, payload), "gap_stop"
        if candle_open >= target:
            return _exit_price(candle_open, direction, payload), "gap_target"
    else:
        if candle_open >= stop:
            return _exit_price(candle_open, direction, payload), "gap_stop"
        if candle_open <= target:
            return _exit_price(candle_open, direction, payload), "gap_target"

    stop_hit = low <= stop if direction == "BUY" else high >= stop
    target_hit = high >= target if direction == "BUY" else low <= target

    if stop_hit and target_hit:
        choose_stop = payload.execution.intrabar_policy == "conservative"
        market_exit = stop if choose_stop else target
        return _exit_price(market_exit, direction, payload), "intrabar_stop" if choose_stop else "intrabar_target"
    if stop_hit:
        return _exit_price(stop, direction, payload), "intrabar_stop"
    if target_hit:
        return _exit_price(target, direction, payload), "intrabar_target"
    return None, None


def _exit_distances(market_price: float, signal_row: pd.Series, payload: SimpleBacktestRequest) -> tuple[float, float]:
    atr = float(signal_row.get("_management_atr", 0) or 0)
    stop_multiplier = payload.parameters.get("atr_stop_multiplier")
    target_multiplier = payload.parameters.get("atr_target_multiplier")
    stop = atr * float(stop_multiplier) if atr > 0 and stop_multiplier else market_price * payload.execution.stop_loss_percent / 100
    target = atr * float(target_multiplier) if atr > 0 and target_multiplier else market_price * payload.execution.take_profit_percent / 100
    return max(stop, market_price * 0.00001), max(target, market_price * 0.00001)


def _volatility_risk_multiplier(signal_row: pd.Series, payload: SimpleBacktestRequest) -> float:
    if str(signal_row.get("volatility_regime", "")) == "high_volatility":
        return float(payload.parameters.get("high_volatility_risk_multiplier", 1.0))
    return 1.0


def _regime_specific_risk_multiplier(signal_row: pd.Series, payload: SimpleBacktestRequest) -> float:
    """Apply only explicitly declared directional-specialist risk scaling."""
    if str(signal_row.get("selected_specialist", "")) in {"trend_down", "trend_down_child"}:
        return min(1.0, max(0.1, float(payload.parameters.get("trend_down_risk_multiplier", 1.0) or 1.0)))
    if str(signal_row.get("selected_specialist", "")) in {"trend_up", "trend_up_child"}:
        return min(1.0, max(0.1, float(payload.parameters.get("trend_up_risk_multiplier", 1.0) or 1.0)))
    return 1.0


def _volume_risk_multiplier(signal_row: pd.Series) -> float:
    """Apply only an explicit, available-volume risk reduction."""
    if not bool(signal_row.get("volume_feature_available", False)):
        return 1.0
    value = signal_row.get("volume_risk_multiplier", 1.0)
    try:
        return max(0.1, min(1.0, float(value or 1.0)))
    except (TypeError, ValueError):
        return 1.0


def _differential_router_report(df: pd.DataFrame, trades: list[SimpleTrade]) -> dict[str, object]:
    if "differential_target" not in df.columns:
        return {"enabled": False}
    target = df["differential_target"].fillna(False).astype(bool)
    non_target = ~target
    target_regime = "trend_down"
    if "differential_target_regime" in df.columns:
        observed = df["differential_target_regime"].dropna().astype(str)
        if not observed.empty:
            target_regime = observed.iloc[0]
    # WAIT/zero is the canonical representation of an unavailable signal.
    # Pandas treats NaN != NaN, so a pair of equally unavailable non-target
    # values used to fail the identity gate even though the branch hashes and
    # ledgers were identical.  Normalize only missing values; do not round or
    # otherwise soften a real child/parent difference.
    child_signals = df.loc[non_target, "signal"].fillna("WAIT").astype(str).reset_index(drop=True)
    parent_signals = df.loc[non_target, "parent_signal"].fillna("WAIT").astype(str).reset_index(drop=True)
    child_confidence = pd.to_numeric(df.loc[non_target, "signal_confidence"], errors="coerce").fillna(0.0).reset_index(drop=True)
    parent_confidence = pd.to_numeric(df.loc[non_target, "parent_signal_confidence"], errors="coerce").fillna(0.0).reset_index(drop=True)
    signals_match = bool(child_signals.equals(parent_signals))
    confidence_match = bool(child_confidence.equals(parent_confidence))
    target_trades = sum(trade.market_regime == target_regime for trade in trades)
    non_target_trades = len(trades) - target_trades
    return {
        "enabled": True, "protocol": "differential_router_v2",
        "target_regime": target_regime, "target_candles": int(target.sum()), "non_target_candles": int(non_target.sum()),
        "non_target_signal_identity": signals_match,
        "non_target_confidence_identity": confidence_match,
        "target_trade_count": target_trades, "non_target_trade_count": non_target_trades,
        "non_target_trade_count_invariant": "requires paired parent replay under identical data/execution contract",
        "promotion_evidence": False,
    }


def _regime_transition_multiplier(signal_row: pd.Series, previous_row: pd.Series | None) -> float:
    """Transition firewall: regime changes reduce exposure, never fabricate a trade."""
    return 0.5 if _regime_transitioned(signal_row, previous_row) else 1.0


def _regime_transitioned(signal_row: pd.Series, previous_row: pd.Series | None) -> bool:
    if previous_row is None:
        return False
    return (
        str(previous_row.get("market_regime", "unknown")) != str(signal_row.get("market_regime", "unknown"))
        or str(previous_row.get("volatility_regime", "normal_volatility")) != str(signal_row.get("volatility_regime", "normal_volatility"))
    )


def _transition_wait_duration(payload: SimpleBacktestRequest) -> int:
    return max(1, min(6, int(payload.parameters.get("transition_wait_candles", 2) or 2)))


def _advance_trailing_stop(position: dict[str, object], previous_candle: pd.Series, payload: SimpleBacktestRequest) -> None:
    multiplier = float(payload.parameters.get("trailing_atr_multiplier", 0) or 0)
    atr = float(previous_candle.get("_management_atr", 0) or 0)
    if multiplier <= 0 or atr <= 0:
        return
    distance = multiplier * atr
    if str(position["direction"]) == "BUY":
        position["stop_loss"] = max(float(position["stop_loss"]), float(previous_candle["close"]) - distance)
    else:
        position["stop_loss"] = min(float(position["stop_loss"]), float(previous_candle["close"]) + distance)


def _entry_eligibility(
    row: pd.Series, payload: SimpleBacktestRequest, signal_row: pd.Series | None = None,
    loss_streak: int = 0, cooldown_active: bool = False, regime_returns: dict[str, list[float]] | None = None,
    meta_returns: dict[str, list[float]] | None = None, loss_streak_wait_active: bool = False,
    weak_regime_wait_active: bool = False, confidence_assessment: dict[str, object] | None = None,
    transition_wait_active: bool = False,
) -> tuple[bool, str | None]:
    if _is_volume_policy_veto(signal_row if signal_row is not None else row):
        return False, "volume_policy"
    execution = payload.execution
    if execution.allowed_sessions_utc:
        hour = pd.Timestamp(row["time"]).hour
        allowed = False
        for session in execution.allowed_sessions_utc:
            start, end = (int(value) for value in session.split("-", 1))
            allowed = allowed or (start <= hour < end if start < end else hour >= start or hour < end)
        if not allowed:
            return False, "outside_session"
    if execution.min_volume is not None:
        if not bool(row.get("volume_available", False)):
            return False, "volume_unavailable"
        if float(row.get("volume", 0) or 0) < execution.min_volume:
            return False, "minimum_volume"
    if signal_row is not None:
        # These columns are supplied only by a time-aligned official calendar
        # or risk controller. Missing data never masquerades as a veto.
        if pd.notna(signal_row.get("news_veto", False)) and bool(signal_row.get("news_veto", False)):
            return False, "news_veto"
        if pd.notna(signal_row.get("risk_veto", False)) and bool(signal_row.get("risk_veto", False)):
            return False, "risk_veto"
        if loss_streak_wait_active:
            return False, "loss_streak_wait"
        if weak_regime_wait_active:
            return False, "weak_regime_wait"
        if transition_wait_active:
            return False, "regime_transition_wait"
        if cooldown_active:
            return False, "loss_cooldown"
        confidence = float(signal_row.get("signal_confidence", 1.0) or 0)
        minimum_signal_confidence = float(payload.parameters.get("minimum_signal_confidence", 0.0) or 0)
        # Differential recall experiments may lower the entry threshold only
        # inside their declared target regime.  The parent/non-target lane
        # keeps the original threshold, preserving paired signal and ledger
        # identity while allowing a falsifiable precision-vs-recall test.
        target_lane = signal_row.get("differential_target", False)
        try:
            target_lane = bool(target_lane) if pd.notna(target_lane) else False
        except (TypeError, ValueError):
            target_lane = False
        if target_lane and "differential_target_min_signal_confidence" in payload.parameters:
            minimum_signal_confidence = float(
                payload.parameters.get("differential_target_min_signal_confidence", minimum_signal_confidence)
            )
        if confidence < minimum_signal_confidence:
            return False, "minimum_confidence"
        if bool(payload.parameters.get("confidence_ev_lower_bound_enabled", False)) and (confidence_assessment or {}).get("status") == "assessed" and bool((confidence_assessment or {}).get("hard_veto_eligible", False)):
            if float((confidence_assessment or {}).get("ev_lower_bound", 0)) <= 0:
                return False, "negative_ev_lower_bound"
        if bool(payload.parameters.get("avoid_high_volatility", False)) and str(signal_row.get("volatility_regime", "")) == "high_volatility":
            return False, "high_volatility_veto"
        atr = float(signal_row.get("_management_atr", 0) or 0)
        spread = execution.spread_points * execution.point_size
        maximum = float(payload.parameters.get("max_spread_atr_ratio", 1.0))
        if atr > 0 and spread / atr > maximum:
            return False, "spread_to_atr"
        if bool(payload.parameters.get("meta_label_enabled", False)):
            meta_prior = (meta_returns or {}).get(_meta_context(signal_row, str(signal_row.get("signal", "WAIT"))), [])
            minimum = int(payload.parameters.get("meta_label_min_history", 10) or 10)
            minimum_pf = float(payload.parameters.get("meta_label_min_pf", 1.0) or 1.0)
            if len(meta_prior) >= minimum and _profit_factor_for(meta_prior) < minimum_pf:
                return False, "meta_label_veto"
        expected_target = atr * float(payload.parameters.get("atr_target_multiplier", 0) or 0)
        expected_edge = expected_target / max(float(row["open"]), 0.0000001) * 100
        round_trip_cost = (spread + execution.slippage_points * execution.point_size * 2) / max(float(row["open"]), 0.0000001) * 100 + execution.commission_percent
        if expected_target > 0 and expected_edge <= round_trip_cost:
            return False, "cost_exceeds_target"
    return True, None


def _is_liquid_entry(
    row: pd.Series, payload: SimpleBacktestRequest, signal_row: pd.Series | None = None,
    loss_streak: int = 0, cooldown_active: bool = False, regime_returns: dict[str, list[float]] | None = None,
    meta_returns: dict[str, list[float]] | None = None, loss_streak_wait_active: bool = False,
    weak_regime_wait_active: bool = False,
    confidence_assessment: dict[str, object] | None = None,
) -> bool:
    """Compatibility wrapper for callers/tests that need only a yes/no veto."""
    return _entry_eligibility(
        row, payload, signal_row, loss_streak, cooldown_active, regime_returns, meta_returns, loss_streak_wait_active,
        weak_regime_wait_active, confidence_assessment,
    )[0]


def _meta_context(signal_row: pd.Series, direction: str) -> str:
    return _risk_context(signal_row, direction)


def _meta_risk_multiplier(
    signal_row: pd.Series, direction: str, payload: SimpleBacktestRequest, meta_returns: dict[str, list[float]],
) -> float:
    if not bool(payload.parameters.get("meta_label_enabled", False)):
        return 1.0
    values = meta_returns.get(_meta_context(signal_row, direction), [])
    minimum = int(payload.parameters.get("meta_label_min_history", 10) or 10)
    if len(values) < minimum or _profit_factor_for(values) < float(payload.parameters.get("meta_label_min_pf", 1.0) or 1.0):
        return 1.0
    return float(payload.parameters.get("meta_label_risk_multiplier", 1.0) or 1.0)


def _confidence_assessment(
    signal_row: pd.Series, direction: str, history: dict[str, list[dict[str, float]]],
    payload: SimpleBacktestRequest, entry_candle: pd.Series,
) -> dict[str, object]:
    """Strictly online confidence -> calibrated probability -> EV lower bound.

    The history contains only already closed real trades.  Context evidence is
    preferred; sparse contexts fall back to the global closed-trade history.
    """
    if not bool(payload.parameters.get("confidence_calibration_enabled", False)):
        return {"status": "disabled"}
    minimum = max(15, int(payload.parameters.get("confidence_calibration_min_samples", 15) or 15))
    context = _risk_context(signal_row, direction)
    context_values = history.get(context, [])
    values = context_values
    source = "context"
    if len(context_values) < minimum:
        values = history.get("__global__", [])
        source = "global_fallback"
    if len(values) < minimum:
        return {"status": "insufficient_evidence", "sample_count": len(values), "source": source}
    raw = float(signal_row.get("signal_confidence", 1.0) or 0)
    nearby = [item for item in values if abs(float(item["confidence"]) - raw) <= .20]
    if len(nearby) < minimum:
        nearby = values
        source += "_hierarchical"
    wins = [float(item["profit_percent"]) for item in nearby if float(item["profit_percent"]) > 0]
    losses = [abs(float(item["profit_percent"])) for item in nearby if float(item["profit_percent"]) <= 0]
    n = len(nearby)
    probability = len(wins) / n if n else 0.0
    lower_probability = _wilson_lower_bound(len(wins), n)
    average_win = sum(wins) / len(wins) if wins else 0.0
    average_loss = sum(losses) / len(losses) if losses else 0.0
    execution = payload.execution
    spread_cost = (execution.spread_points * execution.point_size + execution.slippage_points * execution.point_size * 2) / max(float(entry_candle["open"]), .0000001) * 100
    cost = spread_cost + execution.commission_percent
    ev = probability * average_win - (1 - probability) * average_loss - cost
    lower_ev = lower_probability * average_win - (1 - lower_probability) * average_loss - cost
    return {
        "status": "assessed", "source": source, "sample_count": n, "raw_confidence": round(raw, 4),
        "calibrated_win_probability": round(probability, 5), "win_probability_lower_bound": round(lower_probability, 5),
        "expected_value": round(ev, 6), "ev_lower_bound": round(lower_ev, 6),
        # A global fallback is a prior for sizing/diagnostics, not a hard
        # veto for a new regime.  Only enough same-context closed evidence can
        # justify suppressing the next signal; otherwise the candidate stays
        # observable and the gate judges its realized temporal/regime edge.
        "hard_veto_eligible": len(context_values) >= minimum,
        "fallback_is_risk_prior": len(context_values) < minimum,
    }


def _record_confidence_observation(
    history: dict[str, list[dict[str, float]]], signal_row: pd.Series, direction: str, profit_percent: float,
) -> None:
    observation = {"confidence": float(signal_row.get("signal_confidence", 1.0) or 0), "profit_percent": float(profit_percent)}
    context = _risk_context(signal_row, direction)
    history[context] = [*history.get(context, [])[-199:], observation]
    history["__global__"] = [*history.get("__global__", [])[-499:], observation]


def _confidence_calibration_report(history: dict[str, list[dict[str, float]]], payload: SimpleBacktestRequest) -> dict[str, object]:
    values = history.get("__global__", [])
    minimum = max(15, int(payload.parameters.get("confidence_calibration_min_samples", 15) or 15))
    if not bool(payload.parameters.get("confidence_calibration_enabled", False)):
        return {"status": "disabled", "promotion_evidence": False}
    if len(values) < minimum:
        return {"status": "insufficient_evidence", "sample_count": len(values), "minimum_samples": minimum, "promotion_evidence": False}
    bins = []
    for lower, upper in [(0.0, .33), (.33, .66), (.66, 1.01)]:
        rows = [row for row in values if lower <= row["confidence"] < upper]
        if not rows:
            continue
        bins.append({"range": [lower, min(upper, 1.0)], "sample_count": len(rows),
                     "win_probability": round(sum(row["profit_percent"] > 0 for row in rows) / len(rows), 5),
                     "average_profit_percent": round(sum(row["profit_percent"] for row in rows) / len(rows), 5)})
    return {"status": "assessed", "protocol": "train_only_online_hierarchical_calibration_v1", "sample_count": len(values),
            "bins": bins, "rule": "Only previously closed real trades update calibration; vetoed shadows never train it.", "promotion_evidence": False}


def _wilson_lower_bound(successes: int, sample: int, z: float = 1.96) -> float:
    if sample <= 0:
        return 0.0
    p = successes / sample
    denominator = 1 + z * z / sample
    centre = (p + z * z / (2 * sample)) / denominator
    margin = z * ((p * (1 - p) / sample + z * z / (4 * sample * sample)) ** .5) / denominator
    return max(0.0, centre - margin)


def _open_shadow_position(
    candle: pd.Series,
    signal_row: pd.Series,
    direction: str,
    payload: SimpleBacktestRequest,
    index: int,
    veto_reason: str,
) -> dict[str, object] | None:
    """Open the counterfactual at the same next-candle price as a real trade.

    The caller invokes this only after an execution veto.  It deliberately
    does not call eligibility again: we want to measure the signal that the
    veto prevented, under otherwise identical costs and exits.
    """
    market_price = float(candle["open"])
    entry_price = _entry_price(market_price, direction, payload)
    stop_distance, target_distance = _exit_distances(market_price, signal_row, payload)
    if direction == "BUY":
        stop_loss, take_profit = market_price - stop_distance, market_price + target_distance
    else:
        stop_loss, take_profit = market_price + stop_distance, market_price - target_distance
    spread_percent = payload.execution.spread_points * payload.execution.point_size / max(market_price, 1e-9) * 100
    atr = max(float(signal_row.get("_management_atr", 0) or 0), 1e-9)
    spread_to_atr = (payload.execution.spread_points * payload.execution.point_size) / atr
    spread_context = "high_spread" if spread_to_atr >= .20 else ("medium_spread" if spread_to_atr >= .08 else "low_spread")
    # Predetermined, deterministic 5% shadow exploration assignment. It
    # never opens a real position; it only makes policy propensities explicit
    # for later counterfactual OPE.
    exploration = int(hashlib.sha256(f"{signal_row['time']}|{veto_reason}".encode()).hexdigest()[:8], 16) % 100 < 5
    return {
        "veto_reason": veto_reason, "direction": direction,
        "signal_time": signal_row["time"], "entry_time": candle["time"],
        "entry_price": entry_price, "market_entry_price": market_price,
        "stop_loss": stop_loss, "take_profit": take_profit,
        "position_size_multiple": _position_size_multiple(entry_price, stop_loss, direction, payload)
        * _volatility_risk_multiplier(signal_row, payload),
        "market_regime": str(signal_row.get("market_regime", "unknown")),
        "volatility_regime": str(signal_row.get("volatility_regime", "normal_volatility")),
        "spread_context": spread_context, "spread_percent": round(spread_percent, 7),
        "p_allow": .05, "p_veto": .95, "exploration_assigned": exploration,
        "policy_arm": "shadow_exploration" if exploration else "shadow_control",
        "entry_index": index, "signal_row": signal_row,
        "execution_parameters": dict(payload.parameters),
        "partial_closed": False,
        "partial_fraction": float(payload.parameters.get("partial_take_profit_fraction", 0) or 0),
        "partial_exit_price": None,
    }


def _advance_shadow_positions(
    positions: list[dict[str, object]], ledger: list[dict[str, object]],
    history: dict[str, dict[str, list[float]]], candle: pd.Series,
    previous_candle: pd.Series, payload: SimpleBacktestRequest, index: int,
) -> None:
    active: list[dict[str, object]] = []
    for position in positions:
        settled = _advance_shadow_position(position, candle, previous_candle, _payload_for_position(payload, position), index)
        if settled is None:
            active.append(position)
        else:
            _record_shadow_outcome(ledger, history, settled)
    positions[:] = active


def _advance_shadow_position(
    position: dict[str, object], candle: pd.Series, previous_candle: pd.Series,
    payload: SimpleBacktestRequest, index: int,
) -> dict[str, object] | None:
    direction = str(position["direction"])
    _advance_trailing_stop(position, previous_candle, payload)
    time_stop = int(payload.parameters.get("time_stop_candles", 0) or 0)
    if time_stop and index - int(position["entry_index"]) >= time_stop:
        exit_price, exit_reason = _exit_price(float(candle["open"]), direction, payload), "time_stop"
    else:
        exit_price, exit_reason = _intrabar_exit(direction, position, candle, payload)
    if exit_reason is None and _take_partial_profit(position, candle, payload):
        return None
    if exit_reason is None or exit_price is None:
        return None
    return _shadow_outcome(position, candle, float(exit_price), exit_reason, payload)


def _force_close_shadow(position: dict[str, object], candle: pd.Series, payload: SimpleBacktestRequest) -> dict[str, object]:
    position_payload = _payload_for_position(payload, position)
    return _shadow_outcome(
        position, candle, _exit_price(float(candle["close"]), str(position["direction"]), position_payload), "replay_end", position_payload,
    )


def _shadow_outcome(
    position: dict[str, object], candle: pd.Series, exit_price: float, exit_reason: str,
    payload: SimpleBacktestRequest,
) -> dict[str, object]:
    direction, entry_price = str(position["direction"]), float(position["entry_price"])
    market_profit = ((exit_price - entry_price) / entry_price) * 100 if direction == "BUY" else ((entry_price - exit_price) / entry_price) * 100
    partial_fraction = float(position.get("partial_fraction", 0) or 0) if bool(position.get("partial_closed")) else 0.0
    partial_exit = position.get("partial_exit_price")
    if partial_fraction and partial_exit is not None:
        partial_return = ((float(partial_exit) - entry_price) / entry_price) * 100 if direction == "BUY" else ((entry_price - float(partial_exit)) / entry_price) * 100
        market_profit = market_profit * (1 - partial_fraction) + partial_return * partial_fraction
        exit_reason = f"partial_target+{exit_reason}"
    holding_days = max((pd.Timestamp(candle["time"]) - pd.Timestamp(position["entry_time"])).total_seconds() / 86400, 0)
    cost = (payload.execution.commission_percent + payload.execution.swap_per_day_percent * holding_days) * float(position["position_size_multiple"])
    profit = market_profit * float(position["position_size_multiple"]) - cost
    return {
        "veto_reason": str(position["veto_reason"]), "market_regime": str(position["market_regime"]),
        "volatility_regime": str(position["volatility_regime"]), "direction": direction,
        "spread_context": str(position.get("spread_context", "unknown")), "p_allow": float(position.get("p_allow", 0)),
        "p_veto": float(position.get("p_veto", 1)), "exploration_assigned": bool(position.get("exploration_assigned", False)),
        "policy_arm": str(position.get("policy_arm", "shadow_control")),
        "signal_time": str(position["signal_time"]), "entry_time": str(position["entry_time"]),
        "exit_time": str(candle["time"]), "exit_reason": exit_reason,
        "shadow_profit": round(max(profit, 0.0), 5), "shadow_loss": round(abs(min(profit, 0.0)), 5),
        "shadow_profit_percent": round(profit, 5), "outcome": "WIN" if profit > 0 else "LOSS",
    }


def _record_shadow_outcome(
    ledger: list[dict[str, object]], history: dict[str, dict[str, list[float]]], outcome: dict[str, object],
) -> None:
    ledger.append(outcome)
    reason = str(outcome["veto_reason"])
    context = f"{outcome['market_regime']}|{outcome['volatility_regime']}"
    history[reason][context].append(float(outcome["shadow_profit_percent"]))


def _dynamic_cooldown_duration(
    signal_row: pd.Series, payload: SimpleBacktestRequest, loss_streak: int,
    shadow_history: dict[str, dict[str, list[float]]],
) -> tuple[int, dict[str, object]]:
    base = max(1, int(payload.parameters.get("loss_cooldown_candles", 1) or 1))
    regime = str(signal_row.get("market_regime", "unknown"))
    volatility = str(signal_row.get("volatility_regime", "normal_volatility"))
    if not bool(payload.parameters.get("dynamic_cooldown_enabled", True)):
        return base, {"mode": "fixed", "samples": 0, "shadow_pf": None}

    # Frozen policy before the replay begins.  Only the final one-candle
    # nudge depends on already-closed counterfactuals from earlier candles.
    if regime in {"trend_up", "trend_down"} and volatility == "normal_volatility":
        duration = min(2, base)
    elif regime == "range" and volatility == "high_volatility":
        duration = max(4, min(6, base))
    else:
        duration = min(6, base)
    duration = min(6, duration + max(0, min(loss_streak - 1, 2)))

    context = f"{regime}|{volatility}"
    values = shadow_history.get("loss_cooldown", {}).get(context, [])
    minimum = int(payload.parameters.get("cooldown_shadow_min_samples", 5) or 5)
    threshold = float(payload.parameters.get("cooldown_shadow_edge_pf", 1.1) or 1.1)
    pf = _profit_factor_for(values) if len(values) >= minimum else None
    adjustment = 0
    if pf is not None and pf >= threshold:
        adjustment = -1
    elif pf is not None and pf < 1.0:
        adjustment = 1
    return max(1, min(6, duration + adjustment)), {
        "mode": "online_shadow_regret", "samples": len(values), "shadow_pf": pf,
        "adjustment": adjustment, "context": context,
    }


def _veto_regret_report(ledger: list[dict[str, object]]) -> dict[str, object]:
    def summarize(items: list[dict[str, object]], reason: str | None = None) -> dict[str, object]:
        profits = [float(item["shadow_profit_percent"]) for item in items]
        gross_profit = sum(float(item["shadow_profit"]) for item in items)
        gross_loss = sum(float(item["shadow_loss"]) for item in items)
        pf = round(gross_profit / gross_loss, 3) if gross_loss else (99.0 if gross_profit else 0.0)
        monthly: dict[str, list[dict[str, object]]] = defaultdict(list)
        for item in items:
            monthly[_utc_month(item["exit_time"])].append(item)
        monthly_summary = {month: summarize_month(values) for month, values in monthly.items()}
        robust_months = sum(float(data["shadow_profit_factor"]) > 1.30 for data in monthly_summary.values())
        exploration = [item for item in items if bool(item.get("exploration_assigned", False))]
        mean = sum(profits) / len(profits) if profits else 0.0
        # Logged-propensity DR surrogate. It is useful for ranking bounded
        # research experiments but is explicitly counterfactual-only.
        dr = sum(mean + ((value - mean) / max(.01, float(item.get("p_allow", .05)))) if bool(item.get("exploration_assigned", False)) else mean for item, value in zip(items, profits)) / len(items) if items else 0.0
        variance = sum((value - mean) ** 2 for value in profits) / max(1, len(profits) - 1)
        lower_bound = dr - 1.645 * (variance / max(1, len(profits))) ** .5
        action = "preserve_veto"
        if len(items) >= 30 and robust_months >= 3 and len(exploration) >= 5 and lower_bound > 0:
            action = "bounded_relaxation_experiment"
        return {
            "shadow_trades": len(items), "wins": sum(value > 0 for value in profits),
            "losses": sum(value <= 0 for value in profits), "shadow_profit": round(gross_profit, 5),
            "shadow_loss": round(gross_loss, 5), "shadow_profit_factor": pf,
            "net_shadow_profit_percent": round(sum(profits), 5), "recommended_action": action,
            "monthly_passport": monthly_summary, "monthly_pf_gt_1_30": robust_months,
            "doubly_robust_value": round(dr, 6), "lower_confidence_bound": round(lower_bound, 6),
            "exploration_count": len(exploration), "policy_evidence": "counterfactual_only",
        }

    def summarize_month(items: list[dict[str, object]]) -> dict[str, object]:
        profits = [float(item["shadow_profit_percent"]) for item in items]
        gross_profit = sum(float(item["shadow_profit"]) for item in items)
        gross_loss = sum(float(item["shadow_loss"]) for item in items)
        return {
            "shadow_trades": len(items), "shadow_profit_factor": round(gross_profit / gross_loss, 3) if gross_loss else (99.0 if gross_profit else 0.0),
            "net_shadow_profit_percent": round(sum(profits), 5),
        }

    by_veto: dict[str, list[dict[str, object]]] = defaultdict(list)
    by_context: dict[str, list[dict[str, object]]] = defaultdict(list)
    for item in ledger:
        by_veto[str(item["veto_reason"])].append(item)
        key = f"{item['veto_reason']}|{item['market_regime']}|{item['volatility_regime']}|{item.get('spread_context', 'unknown')}"
        by_context[key].append(item)
    ranked = sorted(by_veto.items(), key=lambda pair: summarize(pair[1], pair[0])["shadow_profit_factor"], reverse=True)
    return {
        "protocol": "same next-candle-open, costs, exits; logged shadow propensity + doubly-robust surrogate; never promotion evidence",
        "shadow_trade_count": len(ledger),
        "by_veto_reason": {key: summarize(items, key) for key, items in by_veto.items()},
        "by_regime_context": {key: summarize(items) for key, items in by_context.items()},
        "highest_regret_veto": ranked[0][0] if ranked else None,
        # Bounded sample keeps API and model metadata practical; aggregates
        # above always include the complete replay ledger.
        "sample_records": ledger[:200], "sample_records_truncated": len(ledger) > 200,
    }


def _decision_blame_graph(trades: list[SimpleTrade], veto_regret: dict[str, object]) -> dict[str, object]:
    """Aggregate, intervention-labelled decision graph from the full trade ledger.

    No-trade and half-risk branches are exact accounting counterfactuals. Exit
    topology and specialist alternatives remain explicitly unassessed until a
    frozen, same-contract replay supplies them.
    """
    edges: list[dict[str, object]] = []
    losses = [trade for trade in trades if float(trade.profit_percent) < 0]
    by_regime: dict[str, list[SimpleTrade]] = defaultdict(list)
    for trade in losses:
        by_regime[str(trade.market_regime)].append(trade)
    for regime, rows in by_regime.items():
        values = [float(trade.profit_percent) for trade in rows]
        mean = sum(values) / len(values)
        variance = sum((value - mean) ** 2 for value in values) / max(1, len(values) - 1)
        margin = 1.96 * (variance / max(1, len(values))) ** .5
        common = {"regime": regime, "cost_scenario": "normal_execution", "sample_count": len(values),
                  "confidence_interval": [round(mean - margin, 6), round(mean + margin, 6)]}
        edges.extend([
            {"edge_key": f"market_context|regime_belief|{regime}", "source_node": "market_context", "target_node": "regime_belief",
             "baseline": 1.0, "intervention": 1.0, "delta": 0.0, "evidence_status": "observed", "intervention_type": "none", **common},
            {"edge_key": f"regime_belief|specialist_choice|{regime}", "source_node": "regime_belief", "target_node": "specialist_choice",
             "baseline": None, "intervention": None, "delta": None, "evidence_status": "not_assessed", "intervention_type": "frozen_specialist_replay_required", **common},
            {"edge_key": f"signal|entry_timing|{regime}", "source_node": "signal", "target_node": "entry_timing",
             "baseline": round(mean, 6), "intervention": 0.0, "delta": round(-mean, 6), "evidence_status": "assessed_accounting", "intervention_type": "no_trade", **common},
            {"edge_key": f"entry_timing|position_size|{regime}", "source_node": "entry_timing", "target_node": "position_size",
             "baseline": round(mean, 6), "intervention": round(mean / 2, 6), "delta": round(-mean / 2, 6), "evidence_status": "assessed_accounting", "intervention_type": "half_size", **common},
            {"edge_key": f"position_size|exit|{regime}", "source_node": "position_size", "target_node": "exit",
             "baseline": round(mean, 6), "intervention": None, "delta": None, "evidence_status": "not_assessed", "intervention_type": "alternative_exit_replay_required", **common},
            {"edge_key": f"exit|outcome|{regime}", "source_node": "exit", "target_node": "outcome",
             "baseline": round(mean, 6), "intervention": None, "delta": None, "evidence_status": "observed", "intervention_type": "none", **common},
        ])
    for context, metrics in (veto_regret.get("by_regime_context", {}) or {}).items():
        sample = int(metrics.get("shadow_trades", 0) or 0)
        baseline = float(metrics.get("net_shadow_profit_percent", 0) or 0) / max(1, sample)
        edges.append({"edge_key": f"veto|outcome|{context}", "source_node": "veto", "target_node": "outcome",
                      "regime": context.split("|")[1] if "|" in context else "unknown", "cost_scenario": "same_next_open_costed_shadow",
                      "baseline": round(baseline, 6), "intervention": 0.0, "delta": round(-baseline, 6), "confidence_interval": [None, None],
                      "sample_count": sample, "evidence_status": "counterfactual_shadow_only", "intervention_type": "no_trade_vs_shadow_allow"})
    return {"protocol": "decision_blame_graph_v1; full trade ledger aggregates; promotion evidence forbidden", "nodes": ["market_context", "regime_belief", "specialist_choice", "signal", "veto", "entry_timing", "position_size", "exit", "outcome"], "edges": edges}


def _cooldown_policy_report(
    decisions: list[dict[str, object]], loss_streak_wait_events: list[dict[str, object]] | None = None,
    recovery_probe_events: list[dict[str, object]] | None = None, weak_regime_events: list[dict[str, object]] | None = None,
) -> dict[str, object]:
    durations = [int(item["cooldown_candles"]) for item in decisions]
    adjusted = sum(int((item.get("shadow_evidence", {}) or {}).get("adjustment", 0) or 0) != 0 for item in decisions)
    waits = loss_streak_wait_events or []
    probes = recovery_probe_events or []
    regime_events = weak_regime_events or []
    return {
        "protocol": "finite loss cooldown + finite global/context streak wait + reduced-risk recovery probe; prior closed shadow evidence only",
        "loss_events": len(decisions), "average_cooldown_candles": round(sum(durations) / len(durations), 3) if durations else 0.0,
        "shadow_adjusted_events": adjusted, "decisions": decisions[-100:],
        "loss_streak_wait_events": len(waits), "loss_streak_wait_decisions": waits[-100:],
        "recovery_probe_trades": sum(item.get("event") in {"probe_win", "probe_loss"} for item in probes),
        "recovery_probe_wins": sum(item.get("event") == "probe_win" for item in probes),
        "recovery_probe_losses": sum(item.get("event") == "probe_loss" for item in probes),
        "recovery_probe_events": probes[-100:],
        "weak_regime_wait_events": sum(item.get("event") == "weak_regime_wait_started" for item in regime_events),
        "weak_regime_probe_wins": sum(item.get("event") == "probe_win" for item in regime_events),
        "weak_regime_probe_losses": sum(item.get("event") == "probe_loss" for item in regime_events),
        "weak_regime_events": regime_events[-100:],
    }


def _window_survival(
    df: pd.DataFrame, trades: list[SimpleTrade], opportunities: Counter[str], accepted: Counter[str],
) -> dict[str, object]:
    windows: list[dict[str, object]] = []
    start_month = _utc_month(df["time"].min())
    end_month = _utc_month(df["time"].max())
    for period in pd.period_range(start_month, end_month, freq="M"):
        month = str(period)
        subset = [trade for trade in trades if _utc_month(trade.entry_time) == month]
        returns = [trade.profit_percent for trade in subset]
        opportunity_count = int(opportunities.get(month, 0))
        pf = _profit_factor_for(returns) if returns else 0.0
        net = sum(returns)
        if opportunity_count == 0:
            status = "activity_absence"
        elif net > 0 and pf >= 1.0:
            status = "positive"
        else:
            status = "edge_failure"
        windows.append({
            "month": month, "opportunities": opportunity_count, "accepted_entries": int(accepted.get(month, 0)),
            "trades": len(subset), "profit_factor": pf, "net_profit_percent": round(net, 4),
            "status": status, "catastrophic": bool(opportunity_count and net <= -5.0),
        })
    return {
        "protocol": "calendar windows; activity absence is distinct from edge failure",
        "windows": windows, "positive_windows": sum(item["status"] == "positive" for item in windows),
        "edge_failures": sum(item["status"] == "edge_failure" for item in windows),
        "activity_absence": sum(item["status"] == "activity_absence" for item in windows),
        "catastrophic_windows": sum(bool(item["catastrophic"]) for item in windows),
    }


def _opportunity_metrics(net_profit_percent: float, funnel: dict[str, object], survival: dict[str, object]) -> dict[str, object]:
    valid = int(funnel.get("flat_signal_opportunities", 0))
    accepted = int(funnel.get("accepted_entries", 0))
    coverage = accepted / valid if valid else 0.0
    return {
        "valid_signal_opportunities": valid,
        "accepted_entries": accepted,
        "coverage": round(coverage, 5),
        "edge_density": round(net_profit_percent / valid, 6) if valid else 0.0,
        "rolling_consistency": int(survival.get("positive_windows", 0)),
        "activity_absence_windows": int(survival.get("activity_absence", 0)),
        "classification": "coverage_preserving" if coverage >= .30 and int(survival.get("positive_windows", 0)) >= 3 else "insufficient_coverage_evidence",
    }


def _regime_ensemble_report(df: pd.DataFrame, payload: SimpleBacktestRequest) -> dict[str, object]:
    if "selected_specialist" not in df.columns:
        return {"enabled": False}
    selected = df["selected_specialist"].value_counts().to_dict()
    return {
        "enabled": True,
        "architecture": "frozen_regime_specialist_ensemble_v2",
        "router_policy": {
            "high_volatility": "breakout", "trend_up": "trend_up", "trend_down": "trend_down",
            "range": "range", "other": "session", "maximum_signals_per_candle": 1,
        },
        "specialist_candle_ownership": {str(key): int(value) for key, value in selected.items()},
        "selection_timing": "fixed before replay; no post-result specialist selection",
    }


def _entry_funnel_report(funnel: Counter[str]) -> dict[str, object]:
    raw = int(funnel["raw_strategy_signals"])
    flat = int(funnel["flat_signal_opportunities"])
    accepted = int(funnel["accepted_entries"])
    rejected = {key.removeprefix("rejected_"): int(value) for key, value in funnel.items() if key.startswith("rejected_")}
    return {
        "raw_strategy_signals": raw,
        "flat_signal_opportunities": flat,
        "accepted_entries": accepted,
        "occupied_or_superseded_signals": max(0, raw - flat),
        "rejected": rejected,
        "acceptance_rate_percent": round(accepted / flat * 100, 2) if flat else 0.0,
        "dominant_rejection": max(rejected, key=rejected.get) if rejected else None,
    }


def _diagnostic_telemetry(trades: list[SimpleTrade], funnel: dict[str, object], attribution: dict[str, object]) -> dict[str, object]:
    holding_hours = [
        max(0.0, (pd.Timestamp(trade.exit_time) - pd.Timestamp(trade.entry_time)).total_seconds() / 3600)
        for trade in trades
    ]
    rejected = funnel.get("rejected", {}) if isinstance(funnel.get("rejected"), dict) else {}
    return {
        "signal_count": int(funnel.get("raw_strategy_signals", 0)),
        "trade_count": len(trades),
        "entry_rejection_count": int(sum(int(value) for value in rejected.values())),
        "confirmation_rejection_count": int(rejected.get("confirmation", 0)),
        # News/risk vetoes are explicit even where the current strategy has
        # no such filter; a missing value is never mistaken for hidden alpha.
        "news_veto_count": int(rejected.get("news_veto", 0)),
        "risk_veto_count": int(rejected.get("risk", 0) + rejected.get("risk_veto", 0)),
        "average_holding_time_hours": round(sum(holding_hours) / len(holding_hours), 3) if holding_hours else 0.0,
        "exit_distribution": attribution.get("by_exit_reason", {}),
        "signal_coverage": round(float(funnel.get("accepted_entries", 0)) / max(1, int(funnel.get("flat_signal_opportunities", 0))), 4),
    }


def _take_partial_profit(position: dict[str, object], candle: pd.Series, payload: SimpleBacktestRequest) -> bool:
    fraction = float(position.get("partial_fraction", 0) or 0)
    if not fraction or bool(position.get("partial_closed")):
        return False
    atr = float(candle.get("_management_atr", 0) or 0)
    if atr <= 0:
        return False
    entry = float(position["market_entry_price"])
    distance = atr * float(payload.parameters.get("partial_target_atr_multiplier", 1.0))
    target = entry + distance if str(position["direction"]) == "BUY" else entry - distance
    hit = float(candle["high"]) >= target if str(position["direction"]) == "BUY" else float(candle["low"]) <= target
    if not hit:
        return False
    position["partial_closed"] = True
    position["partial_exit_price"] = _exit_price(target, str(position["direction"]), payload)
    return True


def _profit_factor_for(values: list[float]) -> float:
    gross_win = sum(value for value in values if value > 0)
    gross_loss = abs(sum(value for value in values if value <= 0))
    return round(gross_win / gross_loss, 3) if gross_loss else (99.0 if gross_win else 0.0)


def _pf_attribution(
    trades: list[SimpleTrade],
    df: pd.DataFrame | None = None,
    temporal_chunk_count: int = 4,
) -> dict[str, object]:
    """Full-ledger diagnostics; the response's displayed ledger is capped."""
    if not trades:
        return {
            "summary": {"gross_pf": 0.0, "net_pf": 0.0, "cost_percent": 0.0, "cost_to_gross_profit_percent": 0.0},
            "by_direction": {}, "by_session": {}, "by_regime": {},
            "by_volatility": {}, "by_regime_volatility": {},
            "by_regime_volatility_direction": {}, "by_regime_volatility_session": {},
            "by_temporal_chunk": {}, "by_exit_reason": {},
        }

    def breakdown(items: list[SimpleTrade]) -> dict[str, float | int]:
        gross_positive = sum(max(trade.gross_profit_percent, 0) for trade in items)
        costs = sum(trade.execution_cost_percent for trade in items)
        values = [float(trade.profit_percent) for trade in items]
        equity = 100.0
        peak = equity
        max_drawdown = 0.0
        consecutive_losses = 0
        max_consecutive_losses = 0
        for value in values:
            equity += value
            peak = max(peak, equity)
            max_drawdown = max(max_drawdown, ((peak - equity) / peak) * 100 if peak > 0 else 0.0)
            consecutive_losses = consecutive_losses + 1 if value <= 0 else 0
            max_consecutive_losses = max(max_consecutive_losses, consecutive_losses)
        return {
            "trades": len(items), "gross_pf": _profit_factor_for([trade.gross_profit_percent for trade in items]),
            "net_pf": _profit_factor_for([trade.profit_percent for trade in items]),
            "winrate": round(sum(trade.profit_percent > 0 for trade in items) / len(items) * 100, 2),
            "average_win": round(sum(trade.profit_percent for trade in items if trade.profit_percent > 0) / max(1, sum(trade.profit_percent > 0 for trade in items)), 4),
            "average_loss": round(sum(trade.profit_percent for trade in items if trade.profit_percent <= 0) / max(1, sum(trade.profit_percent <= 0 for trade in items)), 4),
            "wins": sum(value > 0 for value in values),
            "losses": sum(value <= 0 for value in values),
            "net_profit_percent": round(sum(values), 6),
            "max_drawdown_percent": round(max_drawdown, 4),
            "max_consecutive_losses": max_consecutive_losses,
            "cost_percent": round(costs, 5),
            "cost_to_gross_profit_percent": round(costs / gross_positive * 100, 2) if gross_positive else 0.0,
        }

    def grouped(key) -> dict[str, dict[str, float | int]]:
        values: dict[str, list[SimpleTrade]] = {}
        for trade in trades:
            values.setdefault(str(key(trade)), []).append(trade)
        return {name: breakdown(items) for name, items in values.items()}

    def grouped_context_month() -> dict[str, dict[str, dict[str, float | int]]]:
        values: dict[str, dict[str, list[SimpleTrade]]] = {}
        for trade in trades:
            context = f"{trade.market_regime}|{trade.volatility_regime}"
            month = pd.Timestamp(trade.entry_time).strftime("%Y-%m")
            values.setdefault(context, {}).setdefault(month, []).append(trade)
        return {
            context: {month: breakdown(items) for month, items in months.items()}
            for context, months in values.items()
        }

    def grouped_context_dimension(dimension) -> dict[str, dict[str, dict[str, float | int]]]:
        """Expose an observable micro-context without making it a selector."""
        values: dict[str, dict[str, list[SimpleTrade]]] = {}
        for trade in trades:
            context = f"{trade.market_regime}|{trade.volatility_regime}"
            dimension_value = str(dimension(trade))
            values.setdefault(context, {}).setdefault(dimension_value, []).append(trade)
        return {
            context: {dimension_value: breakdown(items) for dimension_value, items in dimensions.items()}
            for context, dimensions in values.items()
        }

    def temporal_chunk(trade: SimpleTrade) -> str:
        """Assign a trade to its candle-history bucket without replaying it.

        This is diagnostic attribution only.  It uses the entry timestamp and
        the already-normalized chronological frame, so no future outcome can
        affect the label.  The strict full-validation replay remains the
        authority for temporal survival; this bucket is a bounded screening
        accelerator that preserves the stateful single-ledger semantics.
        """
        if df is None or df.empty or "time" not in df.columns:
            return "unknown"
        try:
            times = pd.to_datetime(df["time"], errors="coerce", utc=True).dropna().reset_index(drop=True)
            if len(times) < 3:
                return "unknown"
            position = int(times.searchsorted(pd.Timestamp(trade.entry_time), side="left"))
            chunk_count = max(1, int(temporal_chunk_count))
            chunk_size = max(1, len(times) // chunk_count)
            return f"chunk_{min(chunk_count, position // chunk_size + 1)}"
        except (TypeError, ValueError, IndexError):
            return "unknown"

    return {
        "summary": breakdown(trades),
        "by_direction": grouped(lambda trade: trade.direction),
        "by_session": grouped(lambda trade: pd.Timestamp(trade.entry_time).hour),
        "by_regime": grouped(lambda trade: trade.market_regime),
        "by_volatility": grouped(lambda trade: trade.volatility_regime),
        # Calendar evidence must be derived from the one chronological trade
        # ledger. Re-running each month from an empty indicator state creates
        # artificial boundary signals and is not a valid survival test.
        "by_month": grouped(lambda trade: pd.Timestamp(trade.entry_time).strftime("%Y-%m")),
        # Diagnostic only: this intersection tells the council which market
        # context failed inside a weak month. Month labels never enter the
        # strategy/router contract or promotion selector.
        "by_regime_volatility_month": grouped_context_month(),
        # Portfolio members are routed on this sealed intersection, not on a
        # global PF. Persisting it makes admission auditable and prevents a
        # strategy that is good in a regime but bad in the declared volatility
        # lane from masquerading as a complementary specialist.
        "by_regime_volatility": grouped(lambda trade: f"{trade.market_regime}|{trade.volatility_regime}"),
        # Second-order diagnostics guide the next specialist council. They
        # never lower promotion gates and never turn calendar labels into a
        # routing feature.
        "by_regime_volatility_direction": grouped_context_dimension(lambda trade: trade.direction),
        "by_regime_volatility_session": grouped_context_dimension(lambda trade: pd.Timestamp(trade.entry_time).hour),
        "by_temporal_chunk": grouped(temporal_chunk),
        "by_exit_reason": grouped(lambda trade: trade.exit_reason or "unknown"),
    }


def _robustness_matrix(trades: list[SimpleTrade]) -> dict[str, object]:
    """Attribute robustness to the full causal context, never to a month alone.

    The key intentionally includes calendar only as an evidence coordinate.
    Selection consumes the weakest *regime/volatility/session/direction*
    envelope and may use calendar to verify recurrence, but cannot mutate or
    route on a month name.
    """
    cells: dict[str, list[SimpleTrade]] = defaultdict(list)
    envelopes: dict[str, list[SimpleTrade]] = defaultdict(list)
    for trade in trades:
        timestamp = pd.Timestamp(trade.entry_time)
        month = timestamp.strftime("%Y-%m")
        session = str(timestamp.hour)
        envelope = f"{trade.market_regime}|{trade.volatility_regime}|{session}|{trade.direction}"
        cells[f"{envelope}|{month}"].append(trade)
        envelopes[envelope].append(trade)

    def summary(rows: list[SimpleTrade]) -> dict[str, float | int]:
        values = [float(row.profit_percent) for row in rows]
        return {
            "trades": len(rows),
            "net_pf": _profit_factor_for(values),
            "net_profit_percent": round(sum(values), 6),
            "winrate": round(100 * sum(value > 0 for value in values) / max(1, len(values)), 2),
        }

    cell_rows = {key: summary(rows) for key, rows in cells.items()}
    envelope_rows = {key: summary(rows) for key, rows in envelopes.items()}
    weak = [
        {"context": key, **row,
         "regime": key.split("|")[0], "volatility": key.split("|")[1],
         "session": key.split("|")[2], "direction": key.split("|")[3]}
        for key, row in envelope_rows.items()
        if int(row["trades"]) >= 3 and float(row["net_pf"]) < 1.0
    ]
    weak.sort(key=lambda item: (float(item["net_pf"]), -int(item["trades"])))
    return {
        "protocol": "robustness_matrix_v1",
        "axes": ["regime", "volatility", "session_utc_hour", "direction", "calendar_month"],
        "cells": cell_rows,
        "envelopes": envelope_rows,
        "weakest_envelopes": weak[:20],
        "calendar_role": "diagnostic_recurrence_only_not_mutation_or_router_feature",
        "rule": "A failure is actionable only as a full causal context, never as a calendar label.",
    }


def _certified_coverage_passport(trades: list[SimpleTrade], shadow_ledger: list[dict[str, object]]) -> dict[str, object]:
    """Build an auditable trade/abstain passport with conservative backoff.

    Session and side are useful diagnostics, but a finite sample often makes
    their Cartesian product too sparse to certify anything. The passport keeps
    those fine cells immutable and diagnostic, then maps every observed cell
    through a declared hierarchy to an aggregate envelope. Unobserved
    contexts never become permission by silence.
    """
    scope_order = [
        ("regime|volatility|session|direction", ("regime", "volatility", "session", "direction")),
        ("regime|volatility|direction", ("regime", "volatility", "direction")),
        ("regime|direction", ("regime", "direction")),
        ("regime", ("regime",)),
    ]
    fine_axes = scope_order[0][1]
    cells: dict[str, dict[str, object]] = {}

    def context_for(regime: str, volatility: str, session: str, direction: str) -> dict[str, str]:
        return {"regime": regime, "volatility": volatility, "session": session, "direction": direction}

    def key_for(context: dict[str, str], axes: tuple[str, ...]) -> str:
        return "|".join(context[axis] for axis in axes)

    def hour_for(value: object) -> str:
        timestamp = pd.Timestamp(value)
        return str(timestamp.hour) if not pd.isna(timestamp) else "unknown"

    def cell_for(context: dict[str, str]) -> dict[str, object]:
        key = key_for(context, fine_axes)
        return cells.setdefault(key, {
            "regime": context["regime"], "volatility": context["volatility"],
            "session_utc_hour": context["session"], "direction": context["direction"],
            "trades": [], "abstains": [],
        })

    for trade in trades:
        stamp = pd.Timestamp(trade.entry_time)
        cell_for(context_for(
            str(trade.market_regime), str(trade.volatility_regime), str(stamp.hour), str(trade.direction)
        ))["trades"].append(float(trade.profit_percent))
    for shadow in shadow_ledger:
        cell_for(context_for(
            str(shadow.get("market_regime", "unknown")),
            str(shadow.get("volatility_regime", "unknown")),
            hour_for(shadow.get("entry_time")),
            str(shadow.get("direction", "unknown")),
        ))["abstains"].append(float(shadow.get("shadow_profit_percent", 0)))

    def summary(scope: str, key: str, context: dict[str, str], trade_values: list[float], abstain_values: list[float]) -> dict[str, object]:
        profitable_missed = sum(value > 0 for value in abstain_values)
        harmful_filtered = sum(value <= 0 for value in abstain_values)
        trade_permission = len(trade_values) >= 3 and _profit_factor_for(trade_values) >= 1.0
        # Abstaining is certified only when there is observed shadow evidence;
        # silence/zero opportunity cannot become an automatic pass.
        abstain_permission = len(abstain_values) >= 3 and harmful_filtered >= profitable_missed
        permissions = []
        if trade_permission:
            permissions.append("TRADE")
        if abstain_permission:
            permissions.append("ABSTAIN")
        return {
            "scope": scope, "key": key,
            "regime": context.get("regime"), "volatility": context.get("volatility"),
            "session_utc_hour": context.get("session"), "direction": context.get("direction"),
            "trade_permission": "TRADE" if trade_permission else "NOT_CERTIFIED",
            "abstain_permission": "ABSTAIN" if abstain_permission else "NOT_CERTIFIED",
            "trade_count": len(trade_values), "abstain_shadow_count": len(abstain_values),
            "missed_profitable_opportunities": profitable_missed, "harmful_opportunities_filtered": harmful_filtered,
            "trade_pf": _profit_factor_for(trade_values) if trade_values else 0.0,
            "permissions": permissions,
        }

    aggregates: dict[str, dict[str, dict[str, object]]] = {}
    for scope, axes in scope_order:
        grouped: dict[str, dict[str, object]] = {}
        for cell in cells.values():
            context = {
                "regime": str(cell["regime"]), "volatility": str(cell["volatility"]),
                "session": str(cell["session_utc_hour"]), "direction": str(cell["direction"]),
            }
            key = key_for(context, axes)
            group = grouped.setdefault(key, {"context": context, "trades": [], "abstains": []})
            group["trades"].extend(cell["trades"])
            group["abstains"].extend(cell["abstains"])
        aggregates[scope] = {
            key: summary(scope, key, {
                axis: str(group["context"][axis])
                for axis in ("regime", "volatility", "session", "direction")
                if axis in axes
            }, group["trades"], group["abstains"])
            for key, group in grouped.items()
        }

    evidence: dict[str, dict[str, object]] = {}
    effective_cells: dict[str, dict[str, object]] = {}
    for key, cell in cells.items():
        context = {
            "regime": str(cell["regime"]), "volatility": str(cell["volatility"]),
            "session": str(cell["session_utc_hour"]), "direction": str(cell["direction"]),
        }
        selected = None
        for scope, axes in scope_order:
            candidate = aggregates[scope].get(key_for(context, axes))
            if candidate and candidate["permissions"]:
                selected = candidate
                break

        local = summary(scope_order[0][0], key, context, cell["trades"], cell["abstains"])
        permissions = list(selected["permissions"]) if selected else []
        effective_permission = permissions[0] if permissions else "NOT_CERTIFIED"
        effective_scope = selected["scope"] if selected else None
        effective_key = selected["key"] if selected else None
        row = {
            "regime": context["regime"], "volatility": context["volatility"],
            "session_utc_hour": context["session"], "direction": context["direction"],
            "trade_permission": "TRADE" if "TRADE" in permissions else "NOT_CERTIFIED",
            "abstain_permission": "ABSTAIN" if "ABSTAIN" in permissions else "NOT_CERTIFIED",
            "effective_permission": effective_permission,
            "effective_scope": effective_scope, "effective_key": effective_key,
            "backoff_used": effective_scope not in {None, scope_order[0][0]},
            "trade_count": local["trade_count"], "abstain_shadow_count": local["abstain_shadow_count"],
            "missed_profitable_opportunities": local["missed_profitable_opportunities"],
            "harmful_opportunities_filtered": local["harmful_opportunities_filtered"],
            "trade_pf": local["trade_pf"],
            "effective_trade_count": selected["trade_count"] if selected else 0,
            "effective_abstain_shadow_count": selected["abstain_shadow_count"] if selected else 0,
            "effective_trade_pf": selected["trade_pf"] if selected else 0.0,
        }
        evidence[key] = row
        if selected:
            effective_cells[f"{selected['scope']}|{selected['key']}"] = selected

    certified = [row for row in evidence.values() if row["effective_permission"] != "NOT_CERTIFIED"]
    return {
        "protocol": "certified_coverage_passport_v2", "cells": evidence,
        "effective_cells": effective_cells,
        "scope_order": [scope for scope, _axes in scope_order],
        "status": "assessed" if evidence else "insufficient_evidence",
        "certified_cells": len(certified), "uncertified_cells": len(evidence) - len(certified),
        "runtime_policy": {
            "protocol": "coverage_backoff_policy_v1",
            "declared_scope": "the narrowest evidence-backed envelope in scope_order",
            "unobserved_action": "WAIT",
            "rule": "Fine session/side cells remain diagnostic; backoff is allowed only to an aggregate with observed trade or abstain evidence.",
        },
        "rule": "Every observed envelope needs evidence-backed TRADE or ABSTAIN permission; no activity is never a pass.",
    }


def _opportunity_recall(funnel: dict[str, object], shadow_ledger: list[dict[str, object]], trades: list[SimpleTrade]) -> dict[str, object]:
    opportunities = int(funnel.get("flat_signal_opportunities", 0))
    accepted = int(funnel.get("accepted_entries", 0))
    missed_profitable = sum(float(row.get("shadow_profit_percent", 0)) > 0 for row in shadow_ledger)
    harmful_filtered = sum(float(row.get("shadow_profit_percent", 0)) <= 0 for row in shadow_ledger)
    recall = accepted / max(1, opportunities)
    by_regime: dict[str, dict[str, int]] = {}
    for trade in trades:
        row = by_regime.setdefault(str(trade.market_regime), {"opportunities": 0, "accepted_entries": 0})
        row["opportunities"] += 1
        row["accepted_entries"] += 1
    for shadow in shadow_ledger:
        row = by_regime.setdefault(str(shadow.get("market_regime", "unknown")), {"opportunities": 0, "accepted_entries": 0})
        row["opportunities"] += 1
    by_regime_report = {
        regime: {**row, "recall": round(int(row["accepted_entries"]) / max(1, int(row["opportunities"])), 6)}
        for regime, row in by_regime.items()
    }
    # A candidate cannot manufacture a high PF by nearly never trading: it
    # needs both observable opportunities and recall evidence. Thresholds are
    # reported here; Laravel applies them only to G98 promotion passports.
    status = "assessed" if opportunities >= 10 else "insufficient_evidence"
    return {"protocol": "opportunity_recall_gate_v1", "status": status,
            "opportunities": opportunities, "accepted_entries": accepted,
            "by_regime": by_regime_report,
            "opportunity_recall": round(recall, 6), "missed_profitable_waits": missed_profitable,
            "harmful_waits": harmful_filtered, "trade_count": len(trades),
            "abstention_precision": round(harmful_filtered / max(1, harmful_filtered + missed_profitable), 6),
            "rule": "PF is insufficient: a candidate must show opportunity recall and prove that WAIT filters more harm than missed edge."}


def _router_evidence(
    df: pd.DataFrame,
    payload: SimpleBacktestRequest,
    portfolio_evidence: dict[str, object],
    opportunity_recall: dict[str, object],
    statistical_evidence: dict[str, object],
) -> dict[str, object]:
    """Score the router on calibration and safe abstention only.

    The economic PF remains available to the ordinary passport, but it is
    explicitly excluded from this objective. This prevents the routing layer
    from selecting a high-PF specialist that is poorly calibrated or unsafe
    in disagreement/unknown states.
    """
    edge_quality = statistical_evidence.get("edge_quality", {}) if isinstance(statistical_evidence, dict) else {}
    calibration = dict(edge_quality.get("confidence_calibration", {}) or {}) if isinstance(edge_quality, dict) else {}
    sample_count = int(calibration.get("sample_count", 0) or 0)
    calibration_score = calibration.get("score", calibration.get("calibration_score"))
    if isinstance(calibration_score, (int, float)):
        calibration_score = float(calibration_score)
        if calibration_score > 1:
            calibration_score /= 100.0
    else:
        calibration_score = None

    abstention_precision = opportunity_recall.get("abstention_precision")
    if isinstance(abstention_precision, (int, float)):
        abstention_precision = float(abstention_precision)
    else:
        abstention_precision = None

    disagreement = df.get("portfolio_disagreement", pd.Series(False, index=df.index)).fillna(False).astype(bool)
    signals = df.get("signal", pd.Series("WAIT", index=df.index)).astype(str)
    disagreement_wait_invariant = bool(signals.loc[disagreement].eq("WAIT").all())
    wait_reasons = df.get("portfolio_wait_reason", pd.Series("", index=df.index)).astype(str)
    reason_counts = {
        str(reason): int(count)
        for reason, count in wait_reasons[wait_reasons.ne("")].value_counts().to_dict().items()
    }
    components = {
        "calibrated_confidence": calibration_score,
        "abstention_precision": abstention_precision,
        "disagreement_wait_safety": 1.0 if disagreement_wait_invariant else 0.0,
    }
    objective = None
    if calibration_score is not None or abstention_precision is not None:
        objective = round(100.0 * (
            .50 * float(calibration_score or 0.0)
            + .35 * float(abstention_precision or 0.0)
            + .15 * (1.0 if disagreement_wait_invariant else 0.0)
        ), 4)
    status = (
        "assessed"
        if objective is not None and sample_count >= 15
        and float(abstention_precision or 0.0) >= .50
        and disagreement_wait_invariant
        else "insufficient_evidence"
    )
    return {
        "protocol": "router_calibration_abstention_v1",
        "status": status,
        "training_objective": "calibrated_confidence_plus_abstention_precision",
        "objective_score": objective,
        "calibration": calibration,
        "calibration_score": calibration_score,
        "abstention_precision": abstention_precision,
        "sample_count": sample_count,
        "portfolio_member_count": len(payload.portfolio_members),
        "disagreement_rows": int(disagreement.sum()),
        "disagreement_rate": round(float(disagreement.mean()) if len(disagreement) else 0.0, 6),
        "disagreement_wait_invariant": disagreement_wait_invariant,
        "wait_reason_counts": reason_counts,
        "components": components,
        "profit_factor_used_for_training": False,
        "promotion_evidence": False,
        "rule": "Router objective is calibration + abstention precision; economic PF is not an input.",
    }


def _proof_carrying_replay(result: dict[str, object], trades: list[SimpleTrade], payload: SimpleBacktestRequest) -> dict[str, object]:
    """Independent ledger verifier for promotion identity and arithmetic.

    The primary replay reports compounded account return, while the trade
    ledger stores each return rounded to three decimals.  Comparing that
    account return to an additive ledger sum created false proof failures
    (and the primary PF was rounded to two decimals while the verifier used
    three).  Both sides now use the same declared canonical contract: PF is
    rounded to two decimals and net return is independently recomputed by
    compounding the stored ledger returns.  The small net-return tolerance is
    only the unavoidable effect of the ledger's three-decimal serialization;
    the trade count, ledger hash and PF must still match exactly.
    """
    values = [float(trade.profit_percent) for trade in trades]
    verifier_balance = float(payload.initial_balance)
    for value in values:
        verifier_balance += verifier_balance * (value / 100.0)
    verifier_net_profit = round(
        ((verifier_balance - float(payload.initial_balance)) / max(float(payload.initial_balance), 0.0000001)) * 100,
        2,
    )
    # Match the primary replay's canonical rounding contract exactly.  The
    # helper used by diagnostics rounds to three decimals first, which can
    # flip a half-cent boundary (for example 1.845 -> 1.84 vs 1.85) and create
    # a false proof mismatch for an otherwise identical ledger.
    verifier_profit_factor = calculate_profit_factor(trades)
    verifier = {
        "total_trades": len(trades),
        "profit_factor": verifier_profit_factor,
        "net_profit_percent": verifier_net_profit,
        "ledger_net_profit_percent": round(sum(values), 6),
        "trade_ledger_hash": _trade_ledger_hash(trades),
    }
    primary = {"total_trades": int(result.get("total_trades", 0)), "profit_factor": round(float(result.get("profit_factor", 0)), 2),
               "net_profit_percent": round(float(result.get("net_profit_percent", 0)), 6), "trade_ledger_hash": result.get("trade_ledger_hash", "")}
    matches = (
        primary["total_trades"] == verifier["total_trades"]
        and primary["profit_factor"] == verifier["profit_factor"]
        and abs(float(primary["net_profit_percent"]) - float(verifier["net_profit_percent"])) <= 0.02
        and primary["trade_ledger_hash"] == verifier["trade_ledger_hash"]
    )
    data_hash = hashlib.sha256(json.dumps(payload.candles and [c.model_dump(mode="json") for c in payload.candles] or [], sort_keys=True, default=str).encode()).hexdigest()
    config_hash = hashlib.sha256(json.dumps({"strategy": payload.strategy, "base_strategy": payload.base_strategy, "parameters": payload.parameters, "execution": payload.execution.model_dump()}, sort_keys=True, default=str).encode()).hexdigest()
    return {"protocol": "proof_carrying_replay_v1", "status": "passed" if matches else "mismatch",
            "primary": primary, "independent_ledger_verifier": verifier,
            "data_hash": data_hash, "config_hash": config_hash,
            "gate_decision_hash": hashlib.sha256(json.dumps({"primary": primary, "verifier": verifier}, sort_keys=True).encode()).hexdigest(),
            "comparison_tolerances": {"profit_factor": 0.0, "net_profit_percent": 0.02},
            "rule": "Promotion fails closed when independently recomputed full-ledger facts differ from the primary replay after the canonical rounding/compounding contract."}


def _edge_quality_evidence(trades: list[SimpleTrade]) -> dict[str, object]:
    values = [trade.profit_percent for trade in trades]
    regimes: dict[str, list[float]] = {}
    for trade in trades:
        regimes.setdefault(trade.market_regime, []).append(trade.profit_percent)
    usable = {name: _profit_factor_for(items) for name, items in regimes.items() if len(items) >= 5}
    return {
        "bootstrap_pf": bootstrap_profit_factor_lower_bound(values),
        "worst_regime_pf": round(min(usable.values()), 3) if usable else None,
        "worst_regime_sampled": bool(usable),
        "regime_pf": usable,
        "confidence_calibration": _confidence_calibration(trades),
    }


def _confidence_calibration(trades: list[SimpleTrade]) -> dict[str, object]:
    if len(trades) < 10:
        return {"status": "insufficient_trades", "trade_count": len(trades)}
    brier = sum((trade.signal_confidence - float(trade.profit_percent > 0)) ** 2 for trade in trades) / len(trades)
    bins: dict[int, list[SimpleTrade]] = {}
    for trade in trades:
        bins.setdefault(min(4, int(trade.signal_confidence * 5)), []).append(trade)
    return {
        "schema_version": "2.0", "status": "assessed", "method": "closed_trade_confidence_calibration",
        # Laravel consumes a normalized skill score while Brier remains the
        # audit metric. Exposing both prevents a missing-field mismatch from
        # silently turning calibration capability into zero.
        "brier_score": round(brier, 4), "calibration_score": round(max(0.0, min(1.0, 1 - brier)), 4),
        "score": round(max(0.0, min(100.0, (1 - brier) * 100)), 2),
        "sample_count": len(trades),
        "bins": {str(bucket): {"trades": len(items), "mean_confidence": round(sum(item.signal_confidence for item in items) / len(items), 3), "realized_winrate": round(sum(item.profit_percent > 0 for item in items) / len(items), 3)} for bucket, items in bins.items()},
    }


def _edge_claim(payload: SimpleBacktestRequest, attribution: dict[str, object], edge_quality: dict[str, object]) -> dict[str, object]:
    regimes = attribution.get("by_regime", {})
    viable = [(name, data) for name, data in regimes.items() if int(data.get("trades", 0)) >= 5]
    best = max(viable, key=lambda item: float(item[1].get("net_pf", 0)), default=("unproven", {"net_pf": 0, "trades": 0}))
    return {
        "hypothesis": f"{payload.symbol} {payload.base_strategy or payload.strategy} claims net edge in {best[0]} regime.",
        "target_regime": best[0], "observed_net_pf": best[1].get("net_pf", 0), "observed_trades": best[1].get("trades", 0),
        "falsification_conditions": ["stress_cost_pf_below_1_05", "bootstrap_pf_5pct_below_1_10", "worst_regime_pf_below_1_00", "checkpoint_or_pbo_dsr_failure"],
        "status": "candidate_claim" if best[0] != "unproven" else "insufficient_regime_evidence",
        "confidence_calibration": edge_quality.get("confidence_calibration", {}).get("status"),
    }


def _behavioral_signature(df: pd.DataFrame, trades: list[SimpleTrade]) -> dict[str, object]:
    events = [f"{pd.Timestamp(row.time).isoformat()}:{row.signal}" for row in df[["time", "signal"]].itertuples(index=False) if str(row.signal) in {"BUY", "SELL"}]
    # Fixed MinHash sketch permits behaviour comparison without persisting a
    # large signal series in every model's JSON metrics.
    sketch: list[int] = []
    for salt in range(32):
        hashes = [int(hashlib.sha256(f"{salt}|{event}".encode()).hexdigest()[:16], 16) for event in events]
        sketch.append(min(hashes) if hashes else -1)
    return {
        "signal_event_count": len(events), "signal_minhash": sketch,
        "trade_entries": [trade.entry_time for trade in trades],
    }


def _validate_data_gaps(df: pd.DataFrame, payload: SimpleBacktestRequest) -> int:
    if len(df) < 2:
        return 0

    expected = pd.Timedelta(minutes=15 if payload.timeframe == "M15" else 60)
    unexpected = 0
    previous = pd.Timestamp(df.iloc[0]["time"])

    for index in range(1, len(df)):
        current = pd.Timestamp(df.iloc[index]["time"])
        if current <= previous:
            raise ValueError("Candle timestamps takrorlangan yoki tartibsiz.")

        if _is_scheduled_market_closure(previous, current, payload.symbol):
            previous = current
            continue

        missing = previous + expected
        while missing < current:
            if _is_expected_market_candle(missing, payload.symbol):
                unexpected += 1
            missing += expected
        previous = current

    return unexpected


def _is_expected_market_candle(timestamp: pd.Timestamp, symbol: str) -> bool:
    # Keep this calendar aligned with Laravel's HistoricalDataQualityService.
    # Full-lab exports are gated in Laravel, but standalone backtests still
    # enable this guard and must not reject a valid Dukascopy market closure.
    if (timestamp.month, timestamp.day) in {(1, 1), (12, 25)}:
        return False
    if symbol.upper().startswith("XAU"):
        utc_time = _as_utc(timestamp)
        if utc_time.hour == 0 or utc_time.tz_convert("America/New_York").hour == 17:
            return False
    return timestamp.weekday() < 5 and not (timestamp.weekday() == 4 and timestamp.hour >= 21)


def _is_scheduled_market_closure(previous: pd.Timestamp, current: pd.Timestamp, symbol: str) -> bool:
    duration_hours = (current - previous).total_seconds() / 3600
    if duration_hours <= 96 and previous.weekday() == 4 and current.weekday() in {6, 0}:
        return True
    if (
        duration_hours <= 100
        and previous.month == 12
        and previous.day == 31
        and current.year == previous.year + 1
        and current.month == 1
        and current.day <= 3
    ):
        return True
    # The canonical FX archive closes from the Christmas-Eve afternoon
    # session through the Christmas holiday.  Keep this in lockstep with the
    # Laravel historical-data gate so a valid foundation archive is not
    # rejected as if its scheduled holiday bars were a feed outage.
    if duration_hours <= 48 and _crosses_fx_christmas_closure(previous, current, symbol):
        return True
    if not symbol.upper().startswith("XAU"):
        return False
    if duration_hours <= 120 and _crosses_xau_market_holiday(previous, current):
        return True
    if duration_hours <= 8 and previous.month == 12 and previous.day == 31 and current.normalize() == previous.normalize():
        return True
    return duration_hours <= 3 and previous.hour == 23 and current.hour == 1


def _as_utc(timestamp: pd.Timestamp) -> pd.Timestamp:
    normalized = pd.Timestamp(timestamp)
    return normalized.tz_localize("UTC") if normalized.tzinfo is None else normalized.tz_convert("UTC")


def _utc_month(value: object) -> str:
    """Extract a calendar month without dropping timezone from the source."""
    return _as_utc(pd.Timestamp(value)).strftime("%Y-%m")


def _crosses_fx_christmas_closure(previous: pd.Timestamp, current: pd.Timestamp, symbol: str) -> bool:
    if symbol.upper().startswith("XAU"):
        return False

    previous_is_christmas_eve = previous.month == 12 and previous.day == 24 and previous.hour >= 12
    current_is_christmas_day = current.month == 12 and current.day == 25
    previous_is_christmas_day = previous.month == 12 and previous.day == 25
    current_is_day_after_christmas = current.month == 12 and current.day == 26

    return (
        previous_is_christmas_eve and (current_is_christmas_day or current.day == 24)
    ) or (
        previous_is_christmas_day and (current_is_christmas_day or current_is_day_after_christmas)
    )


def _crosses_xau_market_holiday(previous: pd.Timestamp, current: pd.Timestamp) -> bool:
    date = previous.normalize()
    end = current.normalize()
    while date <= end:
        if date.date() in _xau_market_holidays(date.year):
            return True
        date += pd.Timedelta(days=1)
    return False


def _xau_market_holidays(year: int) -> set:
    holidays = {
        _observed_fixed_holiday(year, 1, 1),
        _nth_weekday_of_month(year, 1, 0, 3),
        _nth_weekday_of_month(year, 2, 0, 3),
        easter(year) - timedelta(days=3),
        easter(year) - timedelta(days=2),
        _last_weekday_of_month(year, 5, 0),
        _observed_fixed_holiday(year, 7, 4),
        _nth_weekday_of_month(year, 9, 0, 1),
        _nth_weekday_of_month(year, 11, 3, 4),
        _observed_fixed_holiday(year, 12, 25),
    }
    if year >= 2022:
        holidays.add(_observed_fixed_holiday(year, 6, 19))
    return holidays


def _observed_fixed_holiday(year: int, month: int, day: int):
    holiday = pd.Timestamp(year=year, month=month, day=day)
    if holiday.weekday() == 5:
        holiday -= pd.Timedelta(days=1)
    elif holiday.weekday() == 6:
        holiday += pd.Timedelta(days=1)
    return holiday.date()


def _nth_weekday_of_month(year: int, month: int, weekday: int, nth: int):
    first = pd.Timestamp(year=year, month=month, day=1)
    return (first + pd.Timedelta(days=(weekday - first.weekday()) % 7 + ((nth - 1) * 7))).date()


def _last_weekday_of_month(year: int, month: int, weekday: int):
    last = pd.Timestamp(year=year, month=month, day=1) + pd.offsets.MonthEnd(0)
    return (last - pd.Timedelta(days=(last.weekday() - weekday) % 7)).date()


def classify_mistake(
    direction: str,
    row: pd.Series,
    next_row: pd.Series,
    position: dict[str, object],
) -> dict[str, str]:
    ema_50 = _series_float(row, "ema_50")
    ema_200 = _series_float(row, "ema_200")
    rsi = _series_float(row, "rsi")
    close = _series_float(row, "close")
    stop_loss = float(position["stop_loss"])
    entry_price = float(position["entry_price"])

    if direction == "BUY" and ema_50 is not None and ema_200 is not None and ema_50 < ema_200:
        return {
            "type": "trend_against_entry",
            "reason": "BUY signal umumiy trendga qarshi berilgan.",
            "suggestion": "BUY signal uchun EMA 50 EMA 200 dan yuqori bo'lishi shart.",
        }

    if direction == "SELL" and ema_50 is not None and ema_200 is not None and ema_50 > ema_200:
        return {
            "type": "trend_against_entry",
            "reason": "SELL signal umumiy trendga qarshi berilgan.",
            "suggestion": "SELL signal uchun EMA 50 EMA 200 dan past bo'lishi shart.",
        }

    if rsi is not None and rsi > 68:
        return {
            "type": "late_entry",
            "reason": "RSI juda yuqori bo'lgan, kirish kechikkan bo'lishi mumkin.",
            "suggestion": "RSI 65 dan yuqori bo'lsa, BUY signalni kamaytirish kerak.",
        }

    if rsi is not None and rsi < 32:
        return {
            "type": "late_entry",
            "reason": "RSI juda past bo'lgan, SELL kirish kechikkan bo'lishi mumkin.",
            "suggestion": "RSI 35 dan past bo'lsa, SELL signalni kamaytirish kerak.",
        }

    if rsi is not None and 45 <= rsi <= 55:
        return {
            "type": "rsi_false_signal",
            "reason": "RSI neytral zonada bo'lgan, signal yetarlicha kuchli emas.",
            "suggestion": "RSI signal zonasini trend kuchi bilan birga tekshirish kerak.",
        }

    if close and ema_50 is not None and ema_200 is not None and abs(ema_50 - ema_200) / close < 0.001:
        return {
            "type": "sideways_market",
            "reason": "EMA 50 va EMA 200 juda yaqin, bozor sideways bo'lishi mumkin.",
            "suggestion": "Sideways market uchun ATR volatility yoki trend strength filter qo'shish kerak.",
        }

    if entry_price and abs(entry_price - stop_loss) / entry_price < 0.004:
        return {
            "type": "stop_loss_too_close",
            "reason": "Stop-loss entry narxiga juda yaqin joylashgan.",
            "suggestion": "Stop-loss masofasini ATR asosida moslashtirish kerak.",
        }

    return {
        "type": "unknown_loss",
        "reason": "Loss sababi aniq klassifikatsiya qilinmadi.",
        "suggestion": "Qo'shimcha indikatorlar: ATR, trend strength, news filter qo'shish kerak.",
    }


def _top_simple_mistakes(trades: list[SimpleTrade]) -> list[dict[str, int | str]]:
    mistake_counter = Counter([trade.mistake_type for trade in trades if trade.mistake_type])
    return [
        {"type": mistake_type, "count": count}
        for mistake_type, count in mistake_counter.most_common(5)
    ]


def calculate_max_drawdown(equity_curve: list[float]) -> float:
    if not equity_curve:
        return 0.0

    peak = equity_curve[0]
    max_drawdown = 0.0

    for equity in equity_curve:
        peak = max(peak, equity)
        drawdown = ((peak - equity) / peak) * 100 if peak > 0 else 0
        max_drawdown = max(max_drawdown, drawdown)

    return round(max_drawdown, 2)


def calculate_profit_factor(trades: list[SimpleTrade]) -> float:
    gross_profit = sum(trade.profit_percent for trade in trades if trade.profit_percent > 0)
    gross_loss = abs(sum(trade.profit_percent for trade in trades if trade.profit_percent < 0))

    if gross_loss == 0:
        return round(gross_profit, 2) if gross_profit > 0 else 0.0

    return round(gross_profit / gross_loss, 2)


def calculate_average_win(trades: list[SimpleTrade]) -> float:
    wins = [trade.profit_percent for trade in trades if trade.profit_percent > 0]
    if not wins:
        return 0.0

    return round(sum(wins) / len(wins), 3)


def calculate_average_loss(trades: list[SimpleTrade]) -> float:
    losses = [abs(trade.profit_percent) for trade in trades if trade.profit_percent < 0]
    if not losses:
        return 0.0

    return round(sum(losses) / len(losses), 3)


def calculate_risk_reward_ratio(avg_win: float, avg_loss: float) -> float:
    if avg_loss == 0:
        return 0.0

    return round(avg_win / avg_loss, 2)


def calculate_max_consecutive_losses(trades: list[SimpleTrade]) -> int:
    max_streak = 0
    current_streak = 0

    for trade in trades:
        if trade.result == "LOSS":
            current_streak += 1
            max_streak = max(max_streak, current_streak)
        else:
            current_streak = 0

    return max_streak


def calculate_stability_score(
    max_drawdown: float,
    max_consecutive_losses: int,
    profit_factor: float,
    total_trades: int,
) -> int:
    score = 100

    if max_drawdown > 20:
        score -= 40
    elif max_drawdown > 15:
        score -= 30
    elif max_drawdown > 10:
        score -= 20
    elif max_drawdown > 5:
        score -= 10

    if max_consecutive_losses >= 10:
        score -= 30
    elif max_consecutive_losses >= 7:
        score -= 20
    elif max_consecutive_losses >= 5:
        score -= 10

    if profit_factor >= 1.7:
        score += 10
    elif profit_factor < 1:
        score -= 25

    if total_trades < 20:
        score -= 20
    elif total_trades >= 100:
        score += 5

    return max(min(score, 100), 0)


def calculate_regime_performance(trades: list[SimpleTrade]) -> dict[str, dict[str, float | int]]:
    regimes: dict[str, dict[str, float | int]] = {}

    for trade in trades:
        regime = trade.market_regime or "unknown"
        regimes.setdefault(regime, {
            "trades": 0,
            "wins": 0,
            "losses": 0,
            "profit_percent": 0.0,
        })

        regimes[regime]["trades"] += 1
        if trade.result == "WIN":
            regimes[regime]["wins"] += 1
        if trade.result == "LOSS":
            regimes[regime]["losses"] += 1

        regimes[regime]["profit_percent"] += trade.profit_percent

    return _finalize_regime_performance(regimes)


def calculate_volatility_performance(trades: list[SimpleTrade]) -> dict[str, dict[str, float | int]]:
    regimes: dict[str, dict[str, float | int]] = {}

    for trade in trades:
        regime = trade.volatility_regime or "normal_volatility"
        regimes.setdefault(regime, {
            "trades": 0,
            "wins": 0,
            "losses": 0,
            "profit_percent": 0.0,
        })

        regimes[regime]["trades"] += 1
        if trade.result == "WIN":
            regimes[regime]["wins"] += 1
        if trade.result == "LOSS":
            regimes[regime]["losses"] += 1

        regimes[regime]["profit_percent"] += trade.profit_percent

    return _finalize_regime_performance(regimes)


def _finalize_regime_performance(
    regimes: dict[str, dict[str, float | int]],
) -> dict[str, dict[str, float | int]]:
    for data in regimes.values():
        trades_count = int(data["trades"])
        wins = int(data["wins"])
        data["winrate"] = round((wins / trades_count) * 100, 2) if trades_count else 0.0
        data["profit_percent"] = round(float(data["profit_percent"]), 3)

    return regimes


def _resolve_dataset_path(dataset_path: str) -> Path:
    path = Path(dataset_path)
    if path.exists():
        return path

    repo_root = Path(__file__).resolve().parents[3]
    repo_relative = repo_root / dataset_path
    if repo_relative.exists():
        return repo_relative

    dataset_relative = repo_root / "datasets" / path.name
    if dataset_relative.exists():
        return dataset_relative

    raise FileNotFoundError(f"Dataset not found: {dataset_path}")


def _display_symbol(symbol: str) -> str:
    normalized = symbol.replace("/", "").replace("_", "").upper()
    if len(normalized) == 6:
        return f"{normalized[:3]}/{normalized[3:]}"
    return symbol


def _simple_conclusion(
    winrate: float,
    net_profit: float,
    total_trades: int,
    top_mistakes: list[dict[str, int | str]],
    max_drawdown: float = 0,
    profit_factor: float = 0,
    stability_score: int = 0,
) -> str:
    if total_trades == 0:
        return "Strategiya bu periodda trade ochmadi."

    main_mistake = top_mistakes[0]["type"] if top_mistakes else None
    if net_profit > 0 and profit_factor >= 1.3 and max_drawdown <= 10:
        conclusion = "Strategiya risk va profit bo'yicha yaxshi natija berdi. "
    elif net_profit > 0 and max_drawdown > 15:
        conclusion = "Strategiya profit berdi, lekin drawdown yuqori. Riskni kamaytirish kerak. "
    elif net_profit < 0:
        conclusion = "Strategiya zarar bilan yakunlandi. Parametrlarni qayta optimizatsiya qilish kerak. "
    else:
        conclusion = "Strategiya o'rtacha natija berdi. Qo'shimcha filterlar kerak. "

    if profit_factor < 1:
        conclusion += "Profit Factor 1 dan past, zararli trade hajmi foydali tradelardan ko'proq. "
    elif profit_factor >= 1.7:
        conclusion += "Profit Factor kuchli, strategiyada yaxshi risk/reward bor. "

    if max_drawdown > 15:
        conclusion += "Max drawdown yuqori, position size yoki stop-loss logikasini yaxshilash kerak. "

    if stability_score < 50:
        conclusion += "Stability score past, strategiya barqaror emas. "
    elif stability_score >= 75:
        conclusion += "Stability score yaxshi, agent barqarorroq ishlayapti. "

    if main_mistake == "trend_against_entry":
        conclusion += "Eng ko'p xato trendga qarshi kirish bilan bog'liq. Trend filterni kuchaytirish kerak."
    elif main_mistake == "late_entry":
        conclusion += "Eng ko'p xato kech kirish bilan bog'liq. RSI chegaralarini qayta sozlash kerak."
    elif main_mistake == "rsi_false_signal":
        conclusion += "Eng ko'p xato RSI false signal bilan bog'liq. RSI signalini trend kuchi bilan tasdiqlash kerak."
    elif main_mistake == "sideways_market":
        conclusion += "Eng ko'p xato sideways market bilan bog'liq. ATR volatility filter qo'shish kerak."
    elif main_mistake == "stop_loss_too_close":
        conclusion += "Eng ko'p xato stop-loss juda yaqin bo'lgani bilan bog'liq. Stop-lossni ATR asosida sozlash kerak."
    elif main_mistake == "unknown_loss":
        conclusion += "Ko'p loss sababi aniq emas. ATR, volatility va market regime filter qo'shish kerak."
    else:
        conclusion += "Keyingi bosqichda drawdown va market regime tahlil qilish kerak."

    return conclusion


def _simulate(candles: pd.DataFrame, payload: BacktestRequest) -> list[Trade]:
    strategy = payload.strategy
    trades: list[Trade] = []
    open_trade: Trade | None = None

    start_index = max(
        strategy.ema_slow,
        strategy.rsi_period,
        strategy.atr_period,
        strategy.swing_lookback,
    )
    if len(candles) <= start_index:
        return trades

    for index in range(start_index, len(candles)):
        row = candles.iloc[index]

        if open_trade:
            closed = _try_close_trade(open_trade, row)
            if closed:
                trades.append(closed)
                open_trade = None
            continue

        signal = _signal(candles, index, payload)
        if signal is None:
            continue

        direction = signal["direction"]
        entry_price = float(row["close"])
        atr_value = float(row["atr"])
        risk = atr_value * strategy.atr_stop_multiplier

        if direction == "long":
            stop_loss = entry_price - risk
            take_profit = entry_price + (risk * strategy.risk_reward)
        else:
            stop_loss = entry_price + risk
            take_profit = entry_price - (risk * strategy.risk_reward)

        open_trade = Trade(
            direction=direction,
            entry_time=_to_datetime(row["time"]),
            exit_time=None,
            entry_price=round(entry_price, 5),
            exit_price=None,
            stop_loss=round(stop_loss, 5),
            take_profit=round(take_profit, 5),
            pnl=None,
            result="open",
            indicator_snapshot={
                "ema_fast": _rounded(row["ema_fast"]),
                "ema_slow": _rounded(row["ema_slow"]),
                "rsi": _rounded(row["rsi"]),
                "atr": _rounded(row["atr"]),
                "fib_zone": signal["fib_zone"],
            },
        )

    if open_trade:
        last = candles.iloc[-1]
        exit_price = float(last["close"])
        trades.append(_close_trade(open_trade, last["time"], exit_price))

    return trades


def _signal(candles: pd.DataFrame, index: int, payload: BacktestRequest) -> dict[str, str] | None:
    strategy = payload.strategy
    row = candles.iloc[index]

    if pd.isna(row["rsi"]) or pd.isna(row["atr"]):
        return None

    window = candles.iloc[index - strategy.swing_lookback : index + 1]
    swing_high = float(window["high"].max())
    swing_low = float(window["low"].min())
    swing_range = swing_high - swing_low
    if swing_range <= 0:
        return None

    close = float(row["close"])
    ema_fast = float(row["ema_fast"])
    ema_slow = float(row["ema_slow"])
    rsi_value = float(row["rsi"])

    long_zone_low = swing_high - (swing_range * strategy.fibonacci_max)
    long_zone_high = swing_high - (swing_range * strategy.fibonacci_min)
    short_zone_low = swing_low + (swing_range * strategy.fibonacci_min)
    short_zone_high = swing_low + (swing_range * strategy.fibonacci_max)

    if ema_fast > ema_slow and 45 <= rsi_value <= 70 and long_zone_low <= close <= long_zone_high:
        return {"direction": "long", "fib_zone": "38.2-61.8 pullback"}

    if ema_fast < ema_slow and 30 <= rsi_value <= 55 and short_zone_low <= close <= short_zone_high:
        return {"direction": "short", "fib_zone": "38.2-61.8 pullback"}

    return None


def _try_close_trade(trade: Trade, row: pd.Series) -> Trade | None:
    high = float(row["high"])
    low = float(row["low"])

    if trade.direction == "long":
        if low <= trade.stop_loss:
            return _close_trade(trade, row["time"], trade.stop_loss)
        if high >= trade.take_profit:
            return _close_trade(trade, row["time"], trade.take_profit)
    else:
        if high >= trade.stop_loss:
            return _close_trade(trade, row["time"], trade.stop_loss)
        if low <= trade.take_profit:
            return _close_trade(trade, row["time"], trade.take_profit)

    return None


def _close_trade(trade: Trade, exit_time: datetime, exit_price: float) -> Trade:
    pnl = exit_price - trade.entry_price
    if trade.direction == "short":
        pnl = trade.entry_price - exit_price

    return trade.model_copy(
        update={
            "exit_time": _to_datetime(exit_time),
            "exit_price": round(float(exit_price), 5),
            "pnl": round(float(pnl), 5),
            "result": "win" if pnl > 0 else "loss",
        }
    )


def _metrics(trades: list[Trade], initial_balance: float) -> Metrics:
    wins = [trade for trade in trades if trade.result == "win"]
    losses = [trade for trade in trades if trade.result == "loss"]
    net_pnl = sum(trade.pnl or 0 for trade in trades)
    gross_profit = sum(trade.pnl or 0 for trade in wins)
    gross_loss = abs(sum(trade.pnl or 0 for trade in losses))
    profit_factor = gross_profit / gross_loss if gross_loss else 0.0

    return Metrics(
        total_trades=len(trades),
        wins=len(wins),
        losses=len(losses),
        win_rate=round((len(wins) / len(trades)) * 100, 2) if trades else 0.0,
        net_pnl=round(float(net_pnl), 5),
        profit_factor=round(float(profit_factor), 2),
        max_drawdown=_max_drawdown(trades, initial_balance),
    )


def _max_drawdown(trades: list[Trade], initial_balance: float) -> float:
    equity = initial_balance
    peak = initial_balance
    max_drawdown = 0.0

    for trade in trades:
        equity += trade.pnl or 0.0
        peak = max(peak, equity)
        if peak <= 0:
            continue
        drawdown = ((peak - equity) / peak) * 100
        max_drawdown = max(max_drawdown, drawdown)

    return round(float(max_drawdown), 2)


def _mistakes(trades: list[Trade]) -> list[MistakeJournalEntry]:
    entries: list[MistakeJournalEntry] = []
    for trade in trades:
        if trade.result != "loss":
            continue

        reason = "ATR stop-loss was hit before take-profit."
        entries.append(
            MistakeJournalEntry(
                reason=reason,
                trade=trade,
                context={
                    "direction": trade.direction,
                    "entry_price": trade.entry_price,
                    "exit_price": trade.exit_price,
                    "pnl": trade.pnl,
                    "rsi": trade.indicator_snapshot.get("rsi"),
                    "atr": trade.indicator_snapshot.get("atr"),
                    "fib_zone": trade.indicator_snapshot.get("fib_zone"),
                },
            )
        )
    return entries


def _daily_report(trades: list[Trade], mistakes: list[MistakeJournalEntry]) -> DailyReport:
    if not trades:
        return DailyReport(summary="No trades were generated.", days=[])

    grouped: dict[str, list[Trade]] = {}
    for trade in trades:
        key = trade.entry_time.date().isoformat()
        grouped.setdefault(key, []).append(trade)

    mistake_reasons = Counter(entry.reason for entry in mistakes)
    most_common_mistake = mistake_reasons.most_common(1)[0][0] if mistake_reasons else None

    days: list[DailyReportDay] = []
    for date, day_trades in sorted(grouped.items()):
        wins = [trade for trade in day_trades if trade.result == "win"]
        losses = [trade for trade in day_trades if trade.result == "loss"]
        net_pnl = sum(trade.pnl or 0 for trade in day_trades)
        win_rate = (len(wins) / len(day_trades)) * 100 if day_trades else 0

        if net_pnl > 0:
            conclusion = "Positive day. Strategy conditions produced net profit."
        elif net_pnl < 0:
            conclusion = "Negative day. Review mistake journal before next test."
        else:
            conclusion = "Flat day. More data is needed for a useful conclusion."

        days.append(
            DailyReportDay(
                date=date,
                total_trades=len(day_trades),
                wins=len(wins),
                losses=len(losses),
                win_rate=round(win_rate, 2),
                net_pnl=round(float(net_pnl), 5),
                most_common_mistake=most_common_mistake,
                conclusion=conclusion,
            )
        )

    summary = f"Generated {len(trades)} trades across {len(days)} day(s)."
    return DailyReport(summary=summary, days=days)


def _to_datetime(value: object) -> datetime:
    return _as_utc(pd.Timestamp(value)).to_pydatetime()


def _rounded(value: object) -> float | None:
    if pd.isna(value):
        return None
    return round(float(value), 5)


def _series_float(row: pd.Series, key: str) -> float | None:
    value = row.get(key)
    if value is None or pd.isna(value):
        return None
    return float(value)
