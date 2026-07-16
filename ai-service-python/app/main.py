import hmac
import os
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
import pandas as pd

from app.routers.backtests import router as backtests_router
from app.schemas import SimpleBacktestRequest, SimpleBacktestResponse
from app.services.backtester import _load_simple_candles, run_simple_ema_rsi_backtest, run_simple_ema_rsi_backtest_on_dataframe
from app.services.parameter_schema import validate_strategy_parameters
from app.services.walk_forward import WalkForwardService
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

    leaderboard = sorted(leaderboard, key=lambda item: item["score"], reverse=True)

    return {
        "symbol": payload.symbol,
        "timeframe": payload.timeframe,
        "leaderboard": leaderboard,
    }


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
        }
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/holdout/run")
def run_sealed_holdout(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        parameters = validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy)
        payload = payload.model_copy(update={"parameters": parameters})
        df = _load_simple_candles(payload)
        _, holdout = WalkForwardService().rolling_windows(df)
        result = run_simple_ema_rsi_backtest_on_dataframe(payload, holdout).model_dump()
        return {"score": calculate_strategy_score(result), "result": result, "rows": len(holdout)}
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
