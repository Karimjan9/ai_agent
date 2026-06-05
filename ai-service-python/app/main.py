from fastapi import FastAPI, HTTPException

from app.routers.backtests import router as backtests_router
from app.schemas import SimpleBacktestRequest, SimpleBacktestResponse
from app.services.backtester import run_simple_ema_rsi_backtest
from app.strategies.registry import list_strategy_agents, list_strategies

app = FastAPI(
    title="NeuroTrader Lab AI Service",
    description="Strategy and backtest service for the NeuroTrader Lab MVP.",
    version="0.1.0",
)


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
    normalized_symbol = payload.symbol.replace("/", "").replace("_", "").upper()
    if normalized_symbol != "XAUUSD":
        raise HTTPException(status_code=400, detail="Hozircha faqat XAUUSD ishlaydi")

    try:
        return run_simple_ema_rsi_backtest(payload)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/backtest/run-all")
def run_all_backtests(payload: SimpleBacktestRequest) -> dict[str, object]:
    normalized_symbol = payload.symbol.replace("/", "").replace("_", "").upper()
    if normalized_symbol != "XAUUSD":
        raise HTTPException(status_code=400, detail="Hozircha faqat XAUUSD ishlaydi")

    leaderboard = []

    try:
        for strategy_name in list_strategies():
            strategy_payload = payload.model_copy(update={"strategy": strategy_name})
            result = run_simple_ema_rsi_backtest(strategy_payload)
            result_data = result.model_dump()

            leaderboard.append(
                {
                    "strategy": strategy_name,
                    "score": calculate_strategy_score(result_data),
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


def calculate_strategy_score(result: dict) -> int:
    winrate = result.get("winrate", 0)
    profit = result.get("net_profit_percent", 0)
    total_trades = result.get("total_trades", 0)
    losses = result.get("losses", 0)

    score = 0
    score += winrate * 0.4

    if profit > 0:
        score += min(profit, 50) * 0.7
    else:
        score += profit * 0.8

    if total_trades >= 100:
        score += 15
    elif total_trades >= 50:
        score += 10
    elif total_trades >= 20:
        score += 5
    else:
        score -= 10

    if total_trades > 0:
        loss_rate = (losses / total_trades) * 100
        score -= loss_rate * 0.1

    return round(max(score, 0))


app.include_router(backtests_router)
