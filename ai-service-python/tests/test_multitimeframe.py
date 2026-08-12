from __future__ import annotations

import pandas as pd

from app.services.multitimeframe import apply_signal_policy


def pilot(mode: str = "h1_veto_m15_risk") -> dict[str, object]:
    return {
        "enabled": True,
        "pilot_id": "xauusd_h1_m15_v1",
        "symbol": "XAUUSD",
        "entry_timeframe": "M15",
        "mode": mode,
        "max_h1_staleness_seconds": 7200,
        "range_risk_multiplier": 0.75,
        "normal_volatility_risk_multiplier": 1.0,
        "high_volatility_risk_multiplier": 0.65,
        "low_volatility_risk_multiplier": 0.85,
    }


def row(regime: str = "trend_up", closed_at: str = "2026-08-11T10:00:00+00:00") -> pd.Series:
    return pd.Series({
        "time": "2026-08-11T10:15:00+00:00",
        "market_regime": regime,
        "volatility_regime": "normal_volatility",
        "_h1_open_at": "2026-08-11T09:00:00+00:00",
        "_h1_closed_at": closed_at,
        "_h1_context_hash": "a" * 64,
    })


def test_buy_is_allowed_by_closed_h1_trend_up() -> None:
    result = apply_signal_policy("BUY", row(), pilot(), row()["time"])

    assert result["decision"] == "BUY"
    assert result["context"]["status"] == "ready"
    assert result["context"]["h1_closed_at"] == "2026-08-11T10:00:00+00:00"


def test_sell_is_vetoed_by_closed_h1_trend_up() -> None:
    result = apply_signal_policy("SELL", row(), pilot(), row()["time"])

    assert result["decision"] == "WAIT"
    assert result["reason"] == "H1_DIRECTION_VETO"
    assert result["context"]["h1_context_hash"] == "a" * 64


def test_missing_h1_context_fails_closed() -> None:
    missing = row().drop(labels=["_h1_closed_at", "_h1_context_hash"])
    result = apply_signal_policy("BUY", missing, pilot(), "2026-08-11T10:15:00+00:00")

    assert result["decision"] == "WAIT"
    assert result["reason"] == "H1_CONTEXT_MISSING_OR_NOT_CLOSED"
    assert result["risk_multiplier"] == 0.0


def test_nan_h1_context_fails_closed() -> None:
    missing = row()
    missing["_h1_closed_at"] = pd.NaT
    missing["_h1_context_hash"] = float("nan")
    result = apply_signal_policy("BUY", missing, pilot(), "2026-08-11T10:15:00+00:00")

    assert result["decision"] == "WAIT"
    assert result["reason"] == "H1_CONTEXT_MISSING_OR_NOT_CLOSED"


def test_range_context_allows_reduced_risk_instead_of_direction_vote() -> None:
    result = apply_signal_policy("SELL", row("range"), pilot(), "2026-08-11T10:15:00+00:00")

    assert result["decision"] == "SELL"
    assert result["context"]["permission"] == "ALLOW_REDUCED"
    assert result["risk_multiplier"] == 0.75


def test_m15_only_ablation_bypasses_h1_veto_but_remains_explicit() -> None:
    result = apply_signal_policy("SELL", row(), pilot("m15_only"), "2026-08-11T10:15:00+00:00")

    assert result["decision"] == "SELL"
    assert result["context"]["status"] == "not_applicable"
    assert result["reason"] == "NO_DIRECTIONAL_MTF_VETO"
