"""Sealed holdout API boundary.

Keeping this endpoint outside the replay coordinator makes the promotion-safe
holdout contract independently visible and testable.
"""

from fastapi import APIRouter, HTTPException
import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import _load_simple_candles, _resolve_dataset_path
from app.services.fitness import calculate_strategy_score
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.services.parameter_schema import validate_strategy_parameters

router = APIRouter(tags=["holdouts"])


@router.post("/api/holdout/run")
def run_sealed_holdout(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        if payload.portfolio_members:
            if len(payload.portfolio_members) < 2:
                raise ValueError("A portfolio holdout requires at least two sealed members.")
            members = [
                member.model_copy(update={
                    "parameters": validate_strategy_parameters(member.strategy, member.parameters, member.base_strategy),
                })
                for member in payload.portfolio_members
            ]
            payload = payload.model_copy(update={
                "strategy": "portfolio_v1", "base_strategy": "portfolio",
                "parameters": dict(payload.parameters or {}), "portfolio_members": members,
            })
        else:
            payload = payload.model_copy(update={
                "parameters": validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy),
            })
        dataframe = _load_simple_candles(payload)
        foundation = _load_foundation_candles(payload)
        result, period = MarketAdaptiveReplayService().sealed_holdout(payload, dataframe, foundation)
        return {"score": calculate_strategy_score(result), "result": result, "rows": period["rows"], "period": period,
                "protocol": "market_adaptive_replay_sealed_holdout"}
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def _load_foundation_candles(payload: SimpleBacktestRequest):
    if not payload.foundation_dataset_path:
        return None
    return pd.read_csv(_resolve_dataset_path(payload.foundation_dataset_path))
