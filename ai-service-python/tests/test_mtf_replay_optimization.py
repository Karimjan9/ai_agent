from __future__ import annotations

import pandas as pd

from app.main import _run_mtf_variants, run_mtf_hypothesis_batch
from app.schemas import SimpleBacktestRequest


def _candles(start: str, periods: int, freq: str) -> list[dict[str, object]]:
    timestamps = pd.date_range(start, periods=periods, freq=freq, tz="UTC")
    prices = [2000.0 + index * 0.03 + ((index % 17) - 8) * 0.08 for index in range(periods)]
    return [
        {
            "time": timestamp.isoformat(),
            "open": price,
            "high": price + 0.35,
            "low": price - 0.35,
            "close": price,
            "volume": 1000.0,
            "volume_available": True,
        }
        for timestamp, price in zip(timestamps, prices)
    ]


def _request(ema_fast: int = 12) -> SimpleBacktestRequest:
    return SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="M15",
        strategy="ema_rsi_v1",
        base_strategy="ema_rsi",
        parameters={
            "ema_fast": ema_fast,
            "ema_slow": 40,
            "rsi_period": 14,
            "rsi_buy_min": 50.0,
            "rsi_buy_max": 75.0,
            "rsi_sell_min": 25.0,
            "rsi_sell_max": 50.0,
        },
        mtf_pilot={"enabled": True, "mode": "h1_veto_m15_risk"},
    )


def test_mtf_cost_and_exit_profiles_reuse_features_and_signals() -> None:
    h1 = _candles("2026-01-01", 260, "h")
    m15 = _candles("2026-01-01", 520, "15min")
    cache: dict[str, object] = {}

    _run_mtf_variants(_request(), h1, m15, True, snapshot_cache=cache)
    cost = _request().model_copy(update={
        "execution": _request().execution.model_copy(update={"spread_points": 2.0}),
    })
    _run_mtf_variants(cost, h1, m15, True, snapshot_cache=cache)
    exit_stress = _request().model_copy(update={
        "parameters": {**_request().parameters, "atr_stop_multiplier": 2.0},
    })
    _run_mtf_variants(exit_stress, h1, m15, True, snapshot_cache=cache)

    assert cache["feature_builds"] == 3
    assert cache["feature_cache_hits"] == 2
    assert cache["signal_builds"] == 3
    assert cache["signal_cache_hits"] == 2
    assert cache["execution_replays"] == 12


def test_hypothesis_batch_uses_one_shared_feature_snapshot() -> None:
    h1 = _candles("2026-01-01", 260, "h")
    m15 = _candles("2026-01-01", 520, "15min")
    first = _request()
    second = _request(16)

    result = run_mtf_hypothesis_batch({
        "hypotheses": [
            {"key": "first", "base_request": first.model_dump(mode="json")},
            {"key": "second", "base_request": second.model_dump(mode="json")},
        ],
        "h1_candles": h1,
        "m15_candles": m15,
        "lightweight": True,
    })

    optimization = result["optimization"]
    assert result["hypothesis_count"] == 2
    assert optimization["execution_mode"] == "serial_deterministic_shared_cache"
    assert optimization["feature_snapshot_builds"] == 3
    assert optimization["feature_cache_hits"] == 1
    assert optimization["signal_snapshot_builds"] == 6
    assert optimization["execution_replays"] == 8
    assert optimization["strategy_signal_recomputed_in_cost_stress"] is False
