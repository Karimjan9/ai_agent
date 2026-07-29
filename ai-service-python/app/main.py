import hmac
import os
import math
import hashlib
import json
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
import pandas as pd

from app.routers.backtests import router as backtests_router
from app.schemas import SimpleBacktestRequest, SimpleBacktestResponse
from app.services.backtester import (
    _advance_trailing_stop, _apply_execution_regime, _entry_price, _exit_distances, _exit_price, _intrabar_exit, _load_regime_source,
    _load_simple_candles, _position_size_multiple, _volatility_risk_multiplier,
    run_simple_ema_rsi_backtest, run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.parameter_schema import validate_strategy_parameters
from app.services.walk_forward import WalkForwardService
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.services.statistical_validation import (
    cscv_probability_of_backtest_overfitting,
    deflated_sharpe_ratio,
    per_trade_sharpe,
    returns_from_equity_curve,
)
from app.strategies.registry import get_strategy, list_strategy_agents, list_strategies
from app.services.market_regime import apply_market_regime
from app.services.foundation_prior import evaluate_foundation_prior

app = FastAPI(
    title="NeuroTrader Lab AI Service",
    description="Strategy and backtest service for the NeuroTrader Lab MVP.",
    version="0.1.0",
)


@app.middleware("http")
async def require_internal_token(request: Request, call_next):
    if request.url.path.startswith("/api/"):
        token = _internal_api_token()
        if not token:
            return JSONResponse(status_code=503, content={"detail": "Internal API token is not configured."})
        supplied = request.headers.get("x-internal-token", "")
        if not hmac.compare_digest(token, supplied):
            return JSONResponse(status_code=401, content={"detail": "Invalid internal API token."})
    return await call_next(request)


def _internal_api_token() -> str:
    token = os.getenv("INTERNAL_API_TOKEN", "").strip()
    if token:
        return token
    token_file = os.getenv("INTERNAL_API_TOKEN_FILE", "").strip()
    if not token_file:
        return ""
    try:
        return Path(token_file).read_text(encoding="utf-8").strip()
    except OSError:
        return ""


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": "neurotrader-ai-service"}


@app.get("/")
def home() -> dict[str, str]:
    return {
        "service": "NeuroTrader AI Service",
        "status": "running",
    }


@app.get("/api/strategies")
def strategies() -> dict[str, object]:
    return {
        "strategies": list_strategies(),
        "agents": list_strategy_agents(),
    }


@app.post("/api/backtest/run", response_model=SimpleBacktestResponse)
def run_simple_backtest_api(payload: SimpleBacktestRequest) -> SimpleBacktestResponse:
    try:
        payload = payload.model_copy(update={
            "parameters": validate_strategy_parameters(
                payload.strategy, payload.parameters, payload.base_strategy
            )
        })
        return run_simple_ema_rsi_backtest(payload)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/backtest/run-all")
def run_all_backtests(payload: SimpleBacktestRequest) -> dict[str, object]:
    leaderboard = []
    strategy_configs = payload.strategies or [
        {
            "strategy": strategy_name,
            "base_strategy": strategy_name,
            "version": "v1",
            "parameters": {},
        }
        for strategy_name in list_strategies()
    ]

    try:
        source_df = _load_simple_candles(payload)
        walk_forward = WalkForwardService()

        for config in strategy_configs:
            if hasattr(config, "model_dump"):
                config = config.model_dump()

            strategy_name = config["strategy"]
            parameters = validate_strategy_parameters(
                strategy_name,
                config.get("parameters") or {},
                config.get("base_strategy"),
            )
            strategy_payload = payload.model_copy(update={
                "strategy": strategy_name,
                "base_strategy": config.get("base_strategy"),
                "version": config.get("version"),
                "parameters": parameters,
                "strategies": [],
            })
            if payload.evaluation_mode == "incremental":
                incremental_df = source_df.sort_values("time").tail(2000).reset_index(drop=True)
                incremental_result = run_simple_ema_rsi_backtest_on_dataframe(strategy_payload, incremental_df).model_dump()
                incremental_score = calculate_strategy_score(incremental_result)
                analysis = {
                    "train_score": incremental_score, "validation_score": incremental_score,
                    "forward_score": incremental_score, "forward_window_scores": [],
                    "rolling_windows_count": 0, "robustness_score": 0, "is_overfit": False,
                    "result": {**incremental_result, "evaluation_mode": "incremental"},
                }
            elif payload.evaluation_mode == "replay":
                analysis = MarketAdaptiveReplayService().run(strategy_payload, source_df, calculate_strategy_score)
            else:
                analysis = walk_forward.run(strategy_payload, source_df, calculate_strategy_score)
            result_data = analysis["result"]
            score = calculate_final_walk_forward_score(
                int(analysis["forward_score"]),
                int(analysis["robustness_score"]),
                bool(analysis["is_overfit"]),
            )

            leaderboard.append(
                {
                    "strategy": strategy_name,
                    "base_strategy": config.get("base_strategy"),
                    "version": config.get("version"),
                    "parameters": parameters,
                    "score": score,
                    "train_score": analysis["train_score"],
                    "validation_score": analysis["validation_score"],
                    "forward_score": analysis["forward_score"],
                    "forward_window_scores": analysis["forward_window_scores"],
                    "rolling_windows_count": analysis["rolling_windows_count"],
                    "robustness_score": analysis["robustness_score"],
                    "is_overfit": analysis["is_overfit"],
                    "result": result_data,
                }
            )
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    # The following diagnostics use replay checkpoints only.  The sealed
    # holdout remains untouched and is still run through its dedicated route.
    trial_sharpes = [
        sharpe for item in leaderboard
        if (sharpe := per_trade_sharpe(returns_from_equity_curve(item["result"].get("equity_curve", [])))) is not None
    ]
    cscv = cscv_probability_of_backtest_overfitting([
        item["forward_window_scores"] for item in leaderboard
    ])
    for item in leaderboard:
        evidence = dict(item["result"].get("statistical_evidence", {}))
        evidence["deflated_sharpe"] = deflated_sharpe_ratio(
            returns_from_equity_curve(item["result"].get("equity_curve", [])), trial_sharpes,
        )
        item["result"]["statistical_evidence"] = evidence
        item["result"]["selection_validation"] = cscv

    _attach_behavioral_diversity(leaderboard)

    leaderboard = sorted(leaderboard, key=lambda item: item["score"], reverse=True)

    return {
        "symbol": payload.symbol,
        "timeframe": payload.timeframe,
        "leaderboard": leaderboard,
        "statistical_validation": cscv,
    }


def _attach_behavioral_diversity(leaderboard: list[dict[str, object]]) -> None:
    """Annotate each batch candidate with observable behaviour similarity."""
    for item in leaderboard:
        signature = item["result"].get("behavioral_signature", {})
        comparisons = []
        for other in leaderboard:
            if other is item:
                continue
            other_signature = other["result"].get("behavioral_signature", {})
            signal = _minhash_similarity(signature.get("signal_minhash", []), other_signature.get("signal_minhash", []))
            entries, other_entries = set(signature.get("trade_entries", [])), set(other_signature.get("trade_entries", []))
            overlap = len(entries & other_entries) / len(entries | other_entries) if entries or other_entries else 0.0
            equity = _correlation(item["result"].get("equity_curve", []), other["result"].get("equity_curve", []))
            losses = {trade.get("signal_time") for trade in item["result"].get("trades", []) if float(trade.get("profit_percent", 0)) < 0}
            other_losses = {trade.get("signal_time") for trade in other["result"].get("trades", []) if float(trade.get("profit_percent", 0)) < 0}
            loss_overlap = len(losses & other_losses) / len(losses | other_losses) if losses or other_losses else 0.0
            comparisons.append({"strategy": other["strategy"], "signal_similarity": round(signal, 3), "trade_overlap": round(overlap, 3), "equity_correlation": round(equity, 3), "loss_overlap": round(loss_overlap, 3)})
        clone = [value for value in comparisons if (value["signal_similarity"] >= .85 and value["trade_overlap"] >= .85) or value["equity_correlation"] >= .95]
        item["result"]["behavioral_diversity"] = {
            "status": "near_duplicate" if clone else "diverse",
            "signal_similarity_threshold": .85, "equity_correlation_threshold": .95,
            "nearest": sorted(comparisons, key=lambda value: max(value["signal_similarity"], value["trade_overlap"], value["equity_correlation"]), reverse=True)[:3],
            "near_duplicate_with": clone,
        }
        tail_overlap = sum(value["loss_overlap"] for value in comparisons) / len(comparisons) if comparisons else 0.0
        tail_correlation = sum(max(0.0, value["equity_correlation"]) for value in comparisons) / len(comparisons) if comparisons else 0.0
        item["result"]["negative_space_portfolio"] = {
            "loss_overlap": round(tail_overlap, 3), "tail_loss_correlation": round(tail_correlation, 3),
            "diversification_score": round(max(0, 100 * (1 - ((tail_overlap + tail_correlation) / 2))), 2),
            "rule": "portfolio selection rewards uncorrelated failure modes, not only standalone PF",
        }


def _minhash_similarity(left: list[int], right: list[int]) -> float:
    pairs = [(a, b) for a, b in zip(left, right) if a >= 0 and b >= 0]
    return sum(a == b for a, b in pairs) / len(pairs) if pairs else 0.0


def _correlation(left: list[float], right: list[float]) -> float:
    pairs = list(zip(left[1:], right[1:]))
    if len(pairs) < 3:
        return 0.0
    a, b = [float(value[0]) for value in pairs], [float(value[1]) for value in pairs]
    mean_a, mean_b = sum(a) / len(a), sum(b) / len(b)
    numerator = sum((x - mean_a) * (y - mean_b) for x, y in zip(a, b))
    denominator = math.sqrt(sum((x - mean_a) ** 2 for x in a) * sum((y - mean_b) ** 2 for y in b))
    return numerator / denominator if denominator else 0.0


@app.post("/api/paper/signal")
def paper_signal(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        parameters = validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy)
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"])
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df.columns:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").tail(1000).reset_index(drop=True)
        df = _apply_execution_regime(df, _load_regime_source(payload))
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        prepared = get_strategy(payload.strategy, payload.base_strategy)(df, parameters)
        row = prepared.iloc[-1]
        signal = str(row.get("signal", "WAIT"))
        price = float(row["close"])
        meta = _abstention_meta_decision(row, prepared.iloc[-2] if len(prepared) > 1 else None, signal, price, payload)
        return {
            "signal": meta["decision"], "agent_signal": signal, "signal_time": pd.Timestamp(row["time"]).isoformat(), "price": price,
            "market_regime": str(row.get("market_regime", "unknown")),
            "volatility_regime": str(row.get("volatility_regime", "normal_volatility")),
            "confidence": float(row.get("signal_confidence", 1.0) or 0),
            "meta_agent": meta,
            "execution_contract_preview": _execution_contract(payload, row, signal, price, meta),
        }
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/execution-contract")
def paper_execution_contract(body: dict[str, object]) -> dict[str, object]:
    """The sole SL/TP/trailing/size authority for paper execution.

    Laravel provides only the observed next-candle market open.  The strategy,
    costs, ATR exits, meta decision and hashes are all produced here by the
    same code path used by replay.
    """
    try:
        payload = SimpleBacktestRequest.model_validate(body.get("request", {}))
        parameters = validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy)
        payload = payload.model_copy(update={"parameters": parameters})
        market_price = float(body["entry_market_price"])
        requested_time = str(body.get("signal_time", ""))
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"])
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").tail(1000).reset_index(drop=True)
        df = _apply_execution_regime(df, _load_regime_source(payload))
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        prepared = get_strategy(payload.strategy, payload.base_strategy)(df, parameters)
        if requested_time:
            expected = pd.Timestamp(requested_time)
            if expected.tzinfo is not None:
                expected = expected.tz_localize(None)
            matches = prepared[pd.to_datetime(prepared["time"]).eq(expected)]
            if matches.empty:
                raise ValueError("Signal time no longer matches the immutable execution contract.")
            row_index = int(matches.index[-1])
            row = prepared.loc[row_index]
            previous = prepared.loc[max(0, row_index - 1)] if row_index else None
        else:
            row, previous = prepared.iloc[-1], (prepared.iloc[-2] if len(prepared) > 1 else None)
        signal = str(row.get("signal", "WAIT"))
        meta = _abstention_meta_decision(row, previous, signal, market_price, payload)
        return _execution_contract(payload, row, signal, market_price, meta)
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/advance-contract")
def advance_paper_contract(body: dict[str, object]) -> dict[str, object]:
    """Reconcile a paper order through the same trailing/exit code as replay."""
    try:
        payload = SimpleBacktestRequest.model_validate(body.get("request", {}))
        contract = dict(body["contract"])
        entry_time = pd.Timestamp(str(body["entry_time"]))
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"])
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").reset_index(drop=True)
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        matches = df[pd.to_datetime(df["time"]).eq(entry_time.tz_localize(None) if entry_time.tzinfo else entry_time)]
        if matches.empty:
            raise ValueError("Paper entry candle is absent from execution dataset.")
        entry_index = int(matches.index[0])
        position = {
            "direction": str(contract["decision"]), "entry_price": float(contract["entry_price"]),
            "stop_loss": float(contract["stop_loss"]), "take_profit": float(contract["take_profit"]),
            "position_size_multiple": float(contract["position_size_multiple"]), "entry_time": entry_time,
            "entry_index": entry_index, "partial_closed": False,
            "partial_fraction": float(payload.parameters.get("partial_take_profit_fraction", 0) or 0), "partial_exit_price": None,
        }
        for index in range(entry_index, len(df)):
            candle = df.iloc[index]
            _advance_trailing_stop(position, df.iloc[max(0, index - 1)], payload)
            time_stop = int(contract.get("time_stop_candles", 0) or 0)
            if time_stop and index - entry_index >= time_stop:
                exit_price, reason = _exit_price(float(candle["open"]), str(position["direction"]), payload), "time_stop"
            else:
                exit_price, reason = _intrabar_exit(str(position["direction"]), position, candle, payload)
            if reason is None or exit_price is None:
                continue
            entry = float(position["entry_price"])
            gross = ((float(exit_price) - entry) / entry * 100) if position["direction"] == "BUY" else ((entry - float(exit_price)) / entry * 100)
            holding_days = max((pd.Timestamp(candle["time"]) - entry_time).total_seconds() / 86400, 0)
            profit = gross * float(position["position_size_multiple"]) - (payload.execution.commission_percent + payload.execution.swap_per_day_percent * holding_days) * float(position["position_size_multiple"])
            return {"closed": True, "exit_price": round(float(exit_price), 8), "profit_percent": round(profit, 5), "exit_reason": reason,
                "stop_loss": round(float(position["stop_loss"]), 8), "contract_version": contract.get("contract_version")}
        return {"closed": False, "stop_loss": round(float(position["stop_loss"]), 8), "contract_version": contract.get("contract_version")}
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def _abstention_meta_decision(row: pd.Series, previous: pd.Series | None, signal: str, market_price: float, payload: SimpleBacktestRequest) -> dict[str, object]:
    if signal not in {"BUY", "SELL"}:
        return {"decision": "WAIT", "reason": "NO_BASE_SIGNAL", "position_size_multiplier": 0.0, "expected_value_percent": 0.0}
    confidence = max(0.0, min(1.0, float(row.get("signal_confidence", 0) or 0)))
    stop, target = _exit_distances(market_price, row, payload)
    cost = (payload.execution.spread_points * payload.execution.point_size / max(market_price, 1e-9) * 100) + payload.execution.commission_percent
    expected_win = target / market_price * 100
    expected_loss = stop / market_price * 100
    expected = confidence * expected_win - (1 - confidence) * expected_loss - cost
    transition = previous is not None and (
        str(previous.get("market_regime", "unknown")) != str(row.get("market_regime", "unknown"))
        or str(previous.get("volatility_regime", "normal_volatility")) != str(row.get("volatility_regime", "normal_volatility"))
    )
    policy = payload.policy_context or {}
    constitution = policy.get("constitution", {}) if isinstance(policy, dict) else {}
    allowed = set(constitution.get("allowed_regimes", []) or [])
    regime = str(row.get("market_regime", "unknown"))
    atr = max(float(row.get("_management_atr", 0) or 0), 1e-9)
    range_atr = abs(float(row.get("high", 0)) - float(row.get("low", 0))) / atr
    ood_reasons = ([] if not allowed or regime in allowed else ["REGIME_OUTSIDE_CONSTITUTION"])
    if range_atr >= 3.5: ood_reasons.append("EXTREME_CANDLE_RANGE")
    ood_action = "WAIT" if ood_reasons else ("REDUCE_RISK" if transition else "ALLOW")
    body_atr = abs(float(row.get("close", 0)) - float(row.get("open", 0))) / atr
    mixture = _transition_mixture(row, previous, atr, transition)
    foundation_prior = evaluate_foundation_prior(policy, signal)
    council = _typed_agent_council(signal, regime, body_atr, cost, transition, ood_action, mixture, foundation_prior)
    council_decision = str(council["decision"])
    expected_value = {"p_win": round(confidence, 5), "expected_win_percent": round(expected_win, 5), "expected_loss_percent": round(expected_loss, 5), "execution_cost_percent": round(cost, 5), "net_expected_value_percent": round(expected, 5)}
    ood = {"status": "out_of_distribution" if ood_reasons else "in_distribution", "reasons": ood_reasons, "range_atr_multiple": round(range_atr, 4), "action": ood_action}
    samples = max(0, int(policy.get("sample_count", 0) or 0))
    calibration = max(.2, min(1.0, float(policy.get("calibration_score", 50) or 50) / 100))
    uncertainty_discount = min(1.0, samples / 50) * calibration
    stress_pf = float(policy.get("stress_cost_pf", 0) or 0)
    if confidence < .35 or expected <= 0:
        return {"decision": "WAIT", "reason": "NEGATIVE_NET_EV", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if ood_action == "WAIT" or (stress_pf and stress_pf < 1.05):
        return {"decision": "WAIT", "reason": "OUT_OF_DISTRIBUTION" if ood_action == "WAIT" else "STRESS_COST_UNCERTAIN", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if transition and (float(mixture["disagreement"]) < .12 or float(mixture["no_trade_probability"]) >= .40):
        return {"decision": "WAIT", "reason": "TRANSITION_MIXTURE_UNCERTAIN", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if council_decision == "WAIT":
        return {"decision": "WAIT", "reason": "COUNCIL_DISAGREEMENT", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    # Transition firewall does not invent a new edge: it only cuts exposure.
    firewall = float(mixture["risk_multiplier"]) if transition else 1.0
    size = max(.05, min(1.0, (.35 + confidence * .65) * firewall * max(.2, uncertainty_discount) * (.3 if ood_action == "REDUCE_RISK" else 1.0)))
    return {"decision": signal, "reason": "REGIME_TRANSITION_RISK_REDUCED" if transition else "NET_EV_ACCEPTED", "position_size_multiplier": round(size, 5), "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council, "uncertainty_discount": round(uncertainty_discount, 5), "sample_count": samples}


def _transition_mixture(row: pd.Series, previous: pd.Series | None, atr: float, transition: bool) -> dict[str, object]:
    if previous is None or not transition:
        return {"status": "steady_state", "continuation_probability": 1.0, "reversal_probability": 0.0,
                "no_trade_probability": 0.0, "entropy": 0.0, "disagreement": 1.0, "risk_multiplier": 1.0}
    move = abs(float(row.get("close", 0)) - float(previous.get("close", 0))) / max(atr, 1e-9)
    wick = (abs(float(row.get("high", 0)) - float(row.get("close", 0))) + abs(float(row.get("close", 0)) - float(row.get("low", 0)))) / max(atr, 1e-9)
    continuation = min(.8, .25 + move * .18)
    reversal = min(.8, .20 + wick * .16)
    no_trade = max(.05, 1.0 - continuation - reversal)
    total = continuation + reversal + no_trade
    probabilities = [continuation / total, reversal / total, no_trade / total]
    entropy = -sum(p * math.log(max(p, 1e-9)) for p in probabilities) / math.log(3)
    disagreement = abs(probabilities[0] - probabilities[1])
    return {"status": "transition", "continuation_probability": round(probabilities[0], 5), "reversal_probability": round(probabilities[1], 5),
            "no_trade_probability": round(probabilities[2], 5), "entropy": round(entropy, 5), "disagreement": round(disagreement, 5),
            "risk_multiplier": round(max(.3, min(.7, 1 - entropy * .55)), 5),
            "rule": "Near-equal continuation/reversal probabilities force WAIT; transition exposure is separately sized."}


def _typed_agent_council(signal: str, regime: str, body_atr: float, cost: float, transition: bool, ood_action: str, mixture: dict[str, object], foundation_prior: dict[str, object]) -> dict[str, object]:
    direction = {"agent": "direction", "schema": "direction/v1", "decision": signal if regime != "unknown" else "WAIT"}
    volatility = {"agent": "volatility", "schema": "risk_band/v1", "risk_band": "reduced" if transition else "normal"}
    execution = {"agent": "execution", "schema": "execution_quality/v1", "decision": "WAIT" if cost > .25 else signal, "cost_percent": round(cost, 5)}
    event = {"agent": "event", "schema": "event_risk/v1", "decision": "UNKNOWN", "rule": "Calendar provider must supply a real veto; unknown is not fabricated."}
    skeptic = {"agent": "skeptic", "schema": "falsification/v1", "decision": "WAIT" if ood_action == "WAIT" or float(mixture["disagreement"]) < .12 else signal,
               "warning": "transition_disagreement" if transition else None}
    votes = {"direction": direction["decision"], "volatility": signal if volatility["risk_band"] != "halt" else "WAIT", "execution": execution["decision"], "skeptic": skeptic["decision"]}
    buy_votes, sell_votes = list(votes.values()).count("BUY"), list(votes.values()).count("SELL")
    decision = "BUY" if buy_votes >= 3 else ("SELL" if sell_votes >= 3 else "WAIT")
    return {"decision": decision, "votes": votes, "buy_votes": buy_votes, "sell_votes": sell_votes,
            "agents": [direction, volatility, execution, event, skeptic, {"agent": "risk_governor", "schema": "final_decision/v1", "decision": decision}],
            "foundation_prior": foundation_prior, "rule": "Typed specialist disagreement, execution risk or skeptic warning resolves to WAIT."}


def _execution_contract(payload: SimpleBacktestRequest, row: pd.Series, signal: str, market_price: float, meta: dict[str, object]) -> dict[str, object]:
    if meta.get("decision") not in {"BUY", "SELL"}:
        return {"decision": "WAIT", "meta_agent": meta, "contract_version": "reality_parity_execution_v1"}
    direction = str(meta["decision"])
    entry = _entry_price(market_price, direction, payload)
    stop_distance, target_distance = _exit_distances(market_price, row, payload)
    stop = entry - stop_distance if direction == "BUY" else entry + stop_distance
    target = entry + target_distance if direction == "BUY" else entry - target_distance
    size = _position_size_multiple(entry, stop, direction, payload) * _volatility_risk_multiplier(row, payload) * float(meta["position_size_multiplier"])
    data_hash = hashlib.sha256(json.dumps([[str(value) for value in item] for item in row[["time", "open", "high", "low", "close"]].to_frame().T.values], separators=(",", ":")).encode()).hexdigest()
    strategy_hash = hashlib.sha256(json.dumps({"strategy": payload.strategy, "base_strategy": payload.base_strategy, "parameters": payload.parameters}, sort_keys=True, default=str).encode()).hexdigest()
    execution_hash = hashlib.sha256(json.dumps(payload.execution.model_dump(), sort_keys=True, default=str).encode()).hexdigest()
    code_version = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    return {
        "decision": direction, "entry_price": round(entry, 8), "stop_loss": round(stop, 8), "take_profit": round(target, 8),
        "position_size_multiple": round(size, 6), "trailing_atr_multiplier": float(payload.parameters.get("trailing_atr_multiplier", 0) or 0),
        "time_stop_candles": int(payload.parameters.get("time_stop_candles", 0) or 0), "management_atr": float(row.get("_management_atr", 0) or 0),
        "meta_agent": meta, "contract_version": "reality_parity_execution_v1",
        "data_hash": data_hash, "strategy_hash": strategy_hash, "execution_hash": execution_hash, "code_version": code_version,
    }


@app.post("/api/holdout/run")
def run_sealed_holdout(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        parameters = validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy)
        payload = payload.model_copy(update={"parameters": parameters})
        df = _load_simple_candles(payload)
        result, period = MarketAdaptiveReplayService().sealed_holdout(payload, df)
        return {"score": calculate_strategy_score(result), "result": result, "rows": period["rows"], "period": period,
                "protocol": "market_adaptive_replay_sealed_holdout"}
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def calculate_strategy_score(result: dict) -> int:
    winrate = result.get("winrate", 0)
    profit = result.get("net_profit_percent", 0)
    total_trades = result.get("total_trades", 0)
    max_drawdown = result.get("max_drawdown_percent", result.get("max_drawdown", 0))
    profit_factor = result.get("profit_factor", 0)
    max_consecutive_losses = result.get("max_consecutive_losses", 0)
    stability_score = result.get("stability_score", 0)
    regime_performance = result.get("regime_performance", {})

    score = 0

    if profit > 0:
        score += min(profit, 30) * 0.8
    else:
        score += profit * 1.2

    score += winrate * 0.2

    if profit_factor >= 2:
        score += 25
    elif profit_factor >= 1.7:
        score += 20
    elif profit_factor >= 1.4:
        score += 15
    elif profit_factor >= 1.1:
        score += 8
    elif profit_factor < 1:
        score -= 15

    if max_drawdown > 25:
        score -= 35
    elif max_drawdown > 20:
        score -= 25
    elif max_drawdown > 15:
        score -= 18
    elif max_drawdown > 10:
        score -= 10
    elif max_drawdown <= 5:
        score += 8

    if max_consecutive_losses >= 10:
        score -= 20
    elif max_consecutive_losses >= 7:
        score -= 12
    elif max_consecutive_losses >= 5:
        score -= 6

    score += stability_score * 0.2

    if total_trades < 20:
        score -= 20
    elif total_trades >= 100:
        score += 5

    profitable_regimes = 0
    for data in regime_performance.values():
        if data.get("trades", 0) >= 10 and data.get("profit_percent", 0) > 0:
            profitable_regimes += 1

    if profitable_regimes >= 3:
        score += 8
    elif profitable_regimes == 2:
        score += 5
    elif profitable_regimes == 0:
        score -= 8

    return round(max(min(score, 100), 0))


def calculate_final_walk_forward_score(
    forward_score: int,
    robustness_score: int,
    is_overfit: bool,
) -> int:
    score = (forward_score * 0.70) + (robustness_score * 0.30)

    if is_overfit:
        score -= 20

    return round(max(min(score, 100), 0))


app.include_router(backtests_router)
