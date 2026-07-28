from collections import Counter, defaultdict
from datetime import datetime, timedelta
import hashlib
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
from app.services.indicators import add_indicators
from app.services.market_regime import apply_market_regime
from app.services.monte_carlo import MonteCarloService
from app.services.strategy_dna import StrategyDnaService
from app.services.statistical_validation import bootstrap_profit_factor_lower_bound
from app.strategies.registry import get_strategy, strategy_label


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


def run_simple_ema_rsi_backtest_on_dataframe(
    payload: SimpleBacktestRequest,
    df: pd.DataFrame,
) -> SimpleBacktestResponse:
    df = df.copy()

    if "volume" not in df.columns:
        df["volume"] = 0

    required_columns = {"time", "open", "high", "low", "close", "volume"}
    missing_columns = required_columns - set(df.columns)
    if missing_columns:
        missing = ", ".join(sorted(missing_columns))
        raise ValueError(f"Dataset is missing required columns: {missing}")

    df["time"] = pd.to_datetime(df["time"])
    for column in ["open", "high", "low", "close", "volume"]:
        df[column] = pd.to_numeric(df[column], errors="coerce")

    df = df.dropna(subset=["time", "open", "high", "low", "close"])
    df = df.sort_values("time").reset_index(drop=True)

    unexpected_gap_count = _validate_data_gaps(df, payload)
    if unexpected_gap_count and payload.execution.reject_unexpected_gaps:
        raise ValueError(
            f"Historical data hard-gate failed: {unexpected_gap_count} unexpected candle gaps."
        )
    df.attrs["unexpected_gap_count"] = unexpected_gap_count

    if payload.from_date:
        df = df[df["time"].dt.date >= payload.from_date]
    if payload.to_date:
        df = df[df["time"].dt.date <= payload.to_date]

    df = df.reset_index(drop=True)
    if len(df) < 2:
        raise ValueError("At least 2 candles are required for backtest.")

    return _run_prepared_simple_backtest(payload, df)


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
    higher = regime_source.copy()
    higher["time"] = pd.to_datetime(higher["time"], utc=True)
    higher = apply_market_regime(higher).sort_values("time")
    higher["regime_available_at"] = higher["time"] + pd.Timedelta(hours=1)
    columns = ["regime_available_at", "market_regime", "volatility_regime", "adx", "atr_regime"]
    base = execution_df.copy()
    base["time"] = pd.to_datetime(base["time"], utc=True)
    merged = pd.merge_asof(base.sort_values("time"), higher[columns].sort_values("regime_available_at"), left_on="time", right_on="regime_available_at", direction="backward").drop(columns=["regime_available_at"])
    merged["market_regime"] = merged["market_regime"].fillna("unknown")
    merged["volatility_regime"] = merged["volatility_regime"].fillna("normal_volatility")
    merged["adx"] = merged["adx"].fillna(0.0)
    merged["atr_regime"] = merged["atr_regime"].ffill().fillna(0.0)
    return merged


def _run_prepared_simple_backtest(payload: SimpleBacktestRequest, df: pd.DataFrame) -> SimpleBacktestResponse:
    unexpected_gap_count = int(df.attrs.get("unexpected_gap_count", 0))
    regime_source = _load_regime_source(payload)
    df = _apply_execution_regime(df, regime_source)
    # Use only the completed candle range for management decisions.  ATR is
    # calculated here so every strategy family can evolve exits consistently.
    previous_close = df["close"].shift(1)
    true_range = pd.concat([
        df["high"] - df["low"],
        (df["high"] - previous_close).abs(),
        (df["low"] - previous_close).abs(),
    ], axis=1).max(axis=1)
    df["_management_atr"] = true_range.rolling(14, min_periods=1).mean()
    strategy_function = get_strategy(payload.strategy, payload.base_strategy)
    df = strategy_function(df, payload.parameters)

    balance = payload.initial_balance
    peak_balance = balance
    max_drawdown = 0.0
    trades: list[SimpleTrade] = []
    position: dict[str, object] | None = None
    gross_profit = 0.0
    gross_loss = 0.0
    loss_streak = 0
    cooldown_until = -1
    regime_returns: dict[str, list[float]] = {}
    entry_funnel: Counter[str] = Counter()
    opportunities_by_month: Counter[str] = Counter()
    accepted_by_month: Counter[str] = Counter()
    # Shadow positions never touch balance, loss streak, or real-position
    # state.  They are a counterfactual evidence ledger, not hidden trades.
    shadow_positions: list[dict[str, object]] = []
    shadow_ledger: list[dict[str, object]] = []
    shadow_history: dict[str, dict[str, list[float]]] = defaultdict(lambda: defaultdict(list))
    meta_returns: dict[str, list[float]] = defaultdict(list)
    cooldown_decisions: list[dict[str, object]] = []
    entry_funnel["raw_strategy_signals"] = int(df.iloc[199:]["signal"].isin(["BUY", "SELL"]).sum())

    # A signal is only knowable after its candle closes. Execute it at the
    # following candle's open, then include that same candle in exit checks.
    for index in range(200, len(df)):
        candle = df.iloc[index]
        signal_row = df.iloc[index - 1]
        _advance_shadow_positions(
            shadow_positions, shadow_ledger, shadow_history, candle, df.iloc[index - 1], payload, index
        )

        if position is None:
            signal = str(signal_row["signal"])
            if signal not in {"BUY", "SELL"}:
                continue
            entry_funnel["flat_signal_opportunities"] += 1
            month_key = str(pd.Timestamp(signal_row["time"]).to_period("M"))
            opportunities_by_month[month_key] += 1
            liquid, rejection_reason = _entry_eligibility(
                candle, payload, signal_row, loss_streak, index < cooldown_until, regime_returns, meta_returns
            )
            if not liquid:
                entry_funnel[f"rejected_{rejection_reason or 'unknown'}"] += 1
                shadow = _open_shadow_position(candle, signal_row, signal, payload, index, rejection_reason or "unknown")
                if shadow is not None:
                    settled = _advance_shadow_position(shadow, candle, df.iloc[index - 1], payload, index)
                    if settled is None:
                        shadow_positions.append(shadow)
                    else:
                        _record_shadow_outcome(shadow_ledger, shadow_history, settled)
                continue
            entry_funnel["accepted_entries"] += 1
            accepted_by_month[month_key] += 1

            market_price = float(candle["open"])
            entry_price = _entry_price(market_price, signal, payload)
            stop_distance, target_distance = _exit_distances(market_price, signal_row, payload)
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
                    entry_price, stop_loss, signal, payload
                ) * _volatility_risk_multiplier(signal_row, payload) * _meta_risk_multiplier(signal_row, signal, payload, meta_returns),
                "market_regime": signal_row.get("market_regime", "unknown"),
                "volatility_regime": signal_row.get("volatility_regime", "normal_volatility"),
                "signal_row": signal_row,
                "entry_index": index,
                "partial_closed": False,
                "partial_fraction": float(payload.parameters.get("partial_take_profit_fraction", 0) or 0),
                "partial_exit_price": None,
            }

        direction = str(position["direction"])
        _advance_trailing_stop(position, df.iloc[index - 1], payload)
        time_stop = int(payload.parameters.get("time_stop_candles", 0) or 0)
        if time_stop and index - int(position["entry_index"]) >= time_stop:
            exit_price, exit_reason = _exit_price(float(candle["open"]), direction, payload), "time_stop"
        else:
            exit_price, exit_reason = _intrabar_exit(direction, position, candle, payload)

        if exit_reason is None and _take_partial_profit(position, candle, payload):
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
        loss_streak = 0 if result == "WIN" else loss_streak + 1
        if result == "LOSS":
            cooldown, evidence = _dynamic_cooldown_duration(signal_row, payload, loss_streak, shadow_history)
            cooldown_until = index + cooldown
            cooldown_decisions.append({
                "time": str(candle["time"]), "market_regime": str(signal_row.get("market_regime", "unknown")),
                "volatility_regime": str(signal_row.get("volatility_regime", "normal_volatility")),
                "loss_streak": loss_streak, "cooldown_candles": cooldown, "shadow_evidence": evidence,
            })
        regime_returns.setdefault(str(position.get("market_regime", "unknown")), []).append(profit_percent)
        meta_returns[_meta_context(position["signal_row"], direction)].append(profit_percent)

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
            )
        )
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
    statistical_evidence["edge_quality"] = _edge_quality_evidence(trades)
    pf_attribution = _pf_attribution(trades)
    entry_funnel_report = _entry_funnel_report(entry_funnel)
    behavioral_signature = _behavioral_signature(df, trades)
    diagnostic_telemetry = _diagnostic_telemetry(trades, entry_funnel_report, pf_attribution)
    veto_regret = _veto_regret_report(shadow_ledger)
    cooldown_policy = _cooldown_policy_report(cooldown_decisions)
    window_survival = _window_survival(df, trades, opportunities_by_month, accepted_by_month)
    regime_ensemble = _regime_ensemble_report(df, payload)
    opportunity_metrics = _opportunity_metrics(net_profit, entry_funnel_report, window_survival)
    edge_claim = _edge_claim(payload, pf_attribution, statistical_evidence["edge_quality"])

    return SimpleBacktestResponse(
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
        data_quality={"status": "warning" if unexpected_gap_count else "passed", "rows": len(df),
                      "gap_control": True, "hard_gate": payload.execution.reject_unexpected_gaps,
                      "unexpected_gap_count": unexpected_gap_count,
                      "regime_source": "closed_h1" if regime_source is not None else "execution_timeframe"},
        statistical_evidence=statistical_evidence,
        pf_attribution=pf_attribution,
        entry_funnel=entry_funnel_report,
        behavioral_signature=behavioral_signature,
        diagnostic_telemetry=diagnostic_telemetry,
        veto_regret=veto_regret,
        observability_protocol_version=1,
        cooldown_policy=cooldown_policy,
        window_survival=window_survival,
        regime_ensemble=regime_ensemble,
        opportunity_metrics=opportunity_metrics,
        edge_claim=edge_claim,
        benchmark={"buy_and_hold_percent": round(buy_hold_percent, 3), "edge_vs_buy_and_hold_percent": round(net_profit - buy_hold_percent, 3)},
        trade_ledger_scope="full evaluation; API display is capped to the latest 20 closed trades",
        displayed_trade_count=min(total_trades, 20),
        top_mistakes=top_mistakes,
        trades=trades[-20:],
        conclusion=_simple_conclusion(
            winrate,
            net_profit,
            total_trades,
            top_mistakes,
            max_drawdown=max_drawdown,
            profit_factor=profit_factor,
            stability_score=stability_score,
        ),
    )


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
    meta_returns: dict[str, list[float]] | None = None,
) -> tuple[bool, str | None]:
    execution = payload.execution
    if execution.allowed_sessions_utc:
        hour = pd.Timestamp(row["time"]).hour
        allowed = False
        for session in execution.allowed_sessions_utc:
            start, end = (int(value) for value in session.split("-", 1))
            allowed = allowed or (start <= hour < end if start < end else hour >= start or hour < end)
        if not allowed:
            return False, "outside_session"
    if execution.min_volume is not None and float(row.get("volume", 0) or 0) < execution.min_volume:
        return False, "minimum_volume"
    if signal_row is not None:
        # These columns are supplied only by a time-aligned official calendar
        # or risk controller. Missing data never masquerades as a veto.
        if pd.notna(signal_row.get("news_veto", False)) and bool(signal_row.get("news_veto", False)):
            return False, "news_veto"
        if pd.notna(signal_row.get("risk_veto", False)) and bool(signal_row.get("risk_veto", False)):
            return False, "risk_veto"
        if cooldown_active or loss_streak >= int(payload.parameters.get("max_loss_streak_before_wait", 99)):
            return False, "loss_cooldown"
        confidence = float(signal_row.get("signal_confidence", 1.0) or 0)
        if confidence < float(payload.parameters.get("minimum_signal_confidence", 0.0)):
            return False, "minimum_confidence"
        if bool(payload.parameters.get("avoid_high_volatility", False)) and str(signal_row.get("volatility_regime", "")) == "high_volatility":
            return False, "high_volatility_veto"
        atr = float(signal_row.get("_management_atr", 0) or 0)
        spread = execution.spread_points * execution.point_size
        maximum = float(payload.parameters.get("max_spread_atr_ratio", 1.0))
        if atr > 0 and spread / atr > maximum:
            return False, "spread_to_atr"
        # An online regime veto: only closed, earlier trades are used.  A
        # regime with enough evidence and PF below one is parked until a later
        # generation redesigns its specialist rules.
        prior = (regime_returns or {}).get(str(signal_row.get("market_regime", "unknown")), [])
        if len(prior) >= 10 and _profit_factor_for(prior) < 1.0:
            return False, "weak_regime_history"
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
    meta_returns: dict[str, list[float]] | None = None,
) -> bool:
    """Compatibility wrapper for callers/tests that need only a yes/no veto."""
    return _entry_eligibility(row, payload, signal_row, loss_streak, cooldown_active, regime_returns, meta_returns)[0]


def _meta_context(signal_row: pd.Series, direction: str) -> str:
    return "|".join([
        str(signal_row.get("market_regime", "unknown")),
        str(signal_row.get("volatility_regime", "normal_volatility")), direction,
    ])


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
    return {
        "veto_reason": veto_reason, "direction": direction,
        "signal_time": signal_row["time"], "entry_time": candle["time"],
        "entry_price": entry_price, "market_entry_price": market_price,
        "stop_loss": stop_loss, "take_profit": take_profit,
        "position_size_multiple": _position_size_multiple(entry_price, stop_loss, direction, payload)
        * _volatility_risk_multiplier(signal_row, payload),
        "market_regime": str(signal_row.get("market_regime", "unknown")),
        "volatility_regime": str(signal_row.get("volatility_regime", "normal_volatility")),
        "entry_index": index, "signal_row": signal_row,
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
        settled = _advance_shadow_position(position, candle, previous_candle, payload, index)
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
    return _shadow_outcome(
        position, candle, _exit_price(float(candle["close"]), str(position["direction"]), payload), "replay_end", payload,
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
            monthly[str(pd.Timestamp(item["exit_time"]).to_period("M"))].append(item)
        monthly_summary = {month: summarize_month(values) for month, values in monthly.items()}
        robust_months = sum(float(data["shadow_profit_factor"]) > 1.30 for data in monthly_summary.values())
        action = "insufficient_evidence"
        if len(items) >= 5:
            # Cooldown may be softened only after the counterfactual edge
            # repeats in three distinct calendar windows. Other vetoes retain
            # the less-strong aggregate diagnostic until their own protocol
            # has enough regime-specific evidence.
            if reason == "loss_cooldown":
                action = "relax_bounded_veto" if robust_months >= 3 else ("preserve_veto" if pf < 1.0 else "hold")
            else:
                action = "relax_bounded_veto" if pf >= 1.1 else ("preserve_veto" if pf < 1.0 else "hold")
        return {
            "shadow_trades": len(items), "wins": sum(value > 0 for value in profits),
            "losses": sum(value <= 0 for value in profits), "shadow_profit": round(gross_profit, 5),
            "shadow_loss": round(gross_loss, 5), "shadow_profit_factor": pf,
            "net_shadow_profit_percent": round(sum(profits), 5), "recommended_action": action,
            "monthly_passport": monthly_summary, "monthly_pf_gt_1_30": robust_months,
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
        key = f"{item['veto_reason']}|{item['market_regime']}|{item['volatility_regime']}"
        by_context[key].append(item)
    ranked = sorted(by_veto.items(), key=lambda pair: summarize(pair[1], pair[0])["shadow_profit_factor"], reverse=True)
    return {
        "protocol": "same next-candle-open, costs, exits; counterfactual only; never promotion evidence",
        "shadow_trade_count": len(ledger),
        "by_veto_reason": {key: summarize(items, key) for key, items in by_veto.items()},
        "by_regime_context": {key: summarize(items) for key, items in by_context.items()},
        "highest_regret_veto": ranked[0][0] if ranked else None,
        # Bounded sample keeps API and model metadata practical; aggregates
        # above always include the complete replay ledger.
        "sample_records": ledger[:200], "sample_records_truncated": len(ledger) > 200,
    }


def _cooldown_policy_report(decisions: list[dict[str, object]]) -> dict[str, object]:
    durations = [int(item["cooldown_candles"]) for item in decisions]
    adjusted = sum(int((item.get("shadow_evidence", {}) or {}).get("adjustment", 0) or 0) != 0 for item in decisions)
    return {
        "protocol": "closed-trade loss -> frozen regime policy -> prior closed shadow evidence only",
        "loss_events": len(decisions), "average_cooldown_candles": round(sum(durations) / len(durations), 3) if durations else 0.0,
        "shadow_adjusted_events": adjusted, "decisions": decisions[-100:],
    }


def _window_survival(
    df: pd.DataFrame, trades: list[SimpleTrade], opportunities: Counter[str], accepted: Counter[str],
) -> dict[str, object]:
    windows: list[dict[str, object]] = []
    for period in pd.period_range(pd.Timestamp(df["time"].min()).to_period("M"), pd.Timestamp(df["time"].max()).to_period("M"), freq="M"):
        month = str(period)
        subset = [trade for trade in trades if str(pd.Timestamp(trade.entry_time).to_period("M")) == month]
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
        "architecture": "frozen_regime_specialist_ensemble_v1",
        "router_policy": {
            "high_volatility": "breakout", "trend_up_or_down": "trend",
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


def _pf_attribution(trades: list[SimpleTrade]) -> dict[str, object]:
    """Full-ledger diagnostics; the response's displayed ledger is capped."""
    if not trades:
        return {"summary": {"gross_pf": 0.0, "net_pf": 0.0, "cost_percent": 0.0, "cost_to_gross_profit_percent": 0.0}, "by_direction": {}, "by_session": {}, "by_regime": {}, "by_exit_reason": {}}

    def breakdown(items: list[SimpleTrade]) -> dict[str, float | int]:
        gross_positive = sum(max(trade.gross_profit_percent, 0) for trade in items)
        costs = sum(trade.execution_cost_percent for trade in items)
        return {
            "trades": len(items), "gross_pf": _profit_factor_for([trade.gross_profit_percent for trade in items]),
            "net_pf": _profit_factor_for([trade.profit_percent for trade in items]),
            "winrate": round(sum(trade.profit_percent > 0 for trade in items) / len(items) * 100, 2),
            "average_win": round(sum(trade.profit_percent for trade in items if trade.profit_percent > 0) / max(1, sum(trade.profit_percent > 0 for trade in items)), 4),
            "average_loss": round(sum(trade.profit_percent for trade in items if trade.profit_percent <= 0) / max(1, sum(trade.profit_percent <= 0 for trade in items)), 4),
            "cost_percent": round(costs, 5),
            "cost_to_gross_profit_percent": round(costs / gross_positive * 100, 2) if gross_positive else 0.0,
        }

    def grouped(key) -> dict[str, dict[str, float | int]]:
        values: dict[str, list[SimpleTrade]] = {}
        for trade in trades:
            values.setdefault(str(key(trade)), []).append(trade)
        return {name: breakdown(items) for name, items in values.items()}

    return {
        "summary": breakdown(trades),
        "by_direction": grouped(lambda trade: trade.direction),
        "by_session": grouped(lambda trade: pd.Timestamp(trade.entry_time).hour),
        "by_regime": grouped(lambda trade: trade.market_regime),
        "by_volatility": grouped(lambda trade: trade.volatility_regime),
        "by_exit_reason": grouped(lambda trade: trade.exit_reason or "unknown"),
    }


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
        "status": "assessed", "method": "closed_trade_confidence_calibration",
        "brier_score": round(brier, 4),
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
    return pd.Timestamp(value).to_pydatetime()


def _rounded(value: object) -> float | None:
    if pd.isna(value):
        return None
    return round(float(value), 5)


def _series_float(row: pd.Series, key: str) -> float | None:
    value = row.get(key)
    if value is None or pd.isna(value):
        return None
    return float(value)
