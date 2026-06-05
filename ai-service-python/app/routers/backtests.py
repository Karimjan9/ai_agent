from fastapi import APIRouter, HTTPException

from app.schemas import BacktestRequest, BacktestResponse
from app.services.backtester import run_backtest

router = APIRouter(prefix="/backtests", tags=["backtests"])


@router.post("/run", response_model=BacktestResponse)
def run_backtest_endpoint(payload: BacktestRequest) -> BacktestResponse:
    try:
        return run_backtest(payload)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
