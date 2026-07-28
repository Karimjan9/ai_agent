import hmac
import os
import math
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
import pandas as pd

from app.routers.backtests import router as backtests_router
from app.schemas import SimpleBacktestRequest, SimpleBacktestResponse
from app.services.backtester import _load_simple_candles, run_simple_ema_rsi_backtest, run_simple_ema_rsi_backtest_on_dataframe
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
            comparisons.append({"strategy": other["strategy"], "signal_similarity": round(signal, 3), "trade_overlap": round(overlap, 3), "equity_correlation": round(equity, 3)})
        clone = [value for value in comparisons if (value["signal_similarity"] >= .85 and value["trade_overlap"] >= .85) or value["equity_correlation"] >= .95]
        item["result"]["behavioral_diversity"] = {
            "status": "near_duplicate" if clone else "diverse",
            "signal_similarity_threshold": .85, "equity_correlation_threshold": .95,
            "nearest": sorted(comparisons, key=lambda value: max(value["signal_similarity"], value["trade_overlap"], value["equity_correlation"]), reverse=True)[:3],
            "near_duplicate_with": clone,
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
        df = apply_market_regime(df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").tail(1000))
        prepared = get_strategy(payload.strategy, payload.base_strategy)(df, parameters)
        row = prepared.iloc[-1]
        signal = str(row.get("signal", "WAIT"))
        price = float(row["close"])
        return {
            "signal": signal, "signal_time": pd.Timestamp(row["time"]).isoformat(), "price": price,
            "stop_loss": price * (0.995 if signal == "BUY" else 1.005),
            "take_profit": price * (1.01 if signal == "BUY" else 0.99),
            "market_regime": str(row.get("market_regime", "unknown")),
            "volatility_regime": str(row.get("volatility_regime", "normal_volatility")),
            "confidence": float(row.get("signal_confidence", 1.0) or 0),
        }
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


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
