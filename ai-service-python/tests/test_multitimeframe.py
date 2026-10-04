from __future__ import annotations

import hashlib

import pandas as pd
import pytest

from app.schemas import SimpleBacktestRequest
from app.services.backtester import prepare_replay_feature_context
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


def test_m5_execution_uses_the_closed_h1_context_from_the_full_stack() -> None:
    contract = {
        **pilot(),
        "requested_timeframe": "M5",
        "execution_timeframe": "M5",
        "activation_status": "execution_stream_bound",
    }
    execution_row = pd.Series({
        "time": "2026-08-11T10:15:00+00:00",
        "decision_at": "2026-08-11T10:20:00+00:00",
        "mtf_stack_status": "ready",
        "mtf_stack_reason": "ready",
        "h1_time": "2026-08-11T09:00:00+00:00",
        "h1_available_at": "2026-08-11T10:00:00+00:00",
        "h1_context_hash": "b" * 64,
        "h1_structure_regime": "trend_up",
        "h1_structure_direction": "bullish",
        "volatility_regime": "normal_volatility",
    })

    result = apply_signal_policy(
        "BUY", execution_row, contract, execution_row["decision_at"]
    )

    assert result["decision"] == "BUY"
    assert result["context"]["status"] == "ready"
    assert result["context"]["h1_context_hash"] == "b" * 64


def test_autonomous_m5_replay_accepts_one_exact_closed_mtf_manifest(tmp_path) -> None:
    def write_stream(name: str, periods: int, frequency: str) -> tuple[str, str]:
        prices = [2000.0 + index * 0.1 for index in range(periods)]
        frame = pd.DataFrame({
            "time": pd.date_range("2025-01-01", periods=periods, freq=frequency, tz="UTC"),
            "open": prices,
            "high": [price + 1.0 for price in prices],
            "low": [price - 1.0 for price in prices],
            "close": prices,
            "volume": [100.0] * periods,
        })
        path = tmp_path / f"{name.lower()}.csv"
        frame.to_csv(path, index=False)
        return str(path), hashlib.sha256(path.read_bytes()).hexdigest()

    streams = {
        "M5": write_stream("M5", 80, "5min"),
        "M15": write_stream("M15", 40, "15min"),
        "H1": write_stream("H1", 30, "h"),
        "H4": write_stream("H4", 20, "4h"),
    }
    manifest = {
        "protocol": "closed_h4_h1_m15_m5_snapshot_v1",
        "validation_bundle_protocol": "agent_owned_mtf_foundation_bundle_v1",
        "bundle_hash": "c" * 64,
        "streams": {
            timeframe: {"path": path, "sha256": digest}
            for timeframe, (path, digest) in streams.items()
        },
    }
    payload = SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="M5",
        dataset_path=streams["M5"][0],
        replay_dataset_hash=manifest["bundle_hash"],
        mtf_dataset_paths={
            timeframe: streams[timeframe][0]
            for timeframe in ("H4", "H1", "M15")
        },
        mtf_snapshot_manifest=manifest,
        mtf_pilot={
            "enabled": True,
            "requested_timeframe": "M5",
            "entry_timeframe": "M15",
            "execution_timeframe": "M5",
            "activation_status": "execution_stream_bound",
        },
    )

    context = prepare_replay_feature_context(payload)

    assert context.mtf_context is not None
    assert context.mtf_context.status == "ready"

    tampered = payload.model_copy(deep=True)
    tampered.mtf_snapshot_manifest["streams"]["M5"]["sha256"] = "d" * 64
    with pytest.raises(ValueError, match="AUTONOMOUS_MTF_M5_HASH_MISMATCH"):
        prepare_replay_feature_context(tampered)
