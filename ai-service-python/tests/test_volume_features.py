import pandas as pd

from app.services.volume_features import (
    add_volume_features,
    apply_volume_policy,
    volume_quality_gate,
    volume_shadow_report,
)


def _frame(rows: int = 240, *, available: bool = True) -> pd.DataFrame:
    times = pd.date_range("2025-01-01", periods=rows, freq="h", tz="UTC")
    close = pd.Series(range(rows), dtype=float) + 100
    return pd.DataFrame({
        "time": times,
        "open": close,
        "high": close + 1,
        "low": close - 1,
        "close": close,
        "volume": 100.0,
        "volume_available": available,
        "market_regime": "trend_up",
        "volatility_regime": "normal_volatility",
        "signal": "BUY",
        "signal_confidence": 0.8,
    })


def test_missing_source_marker_is_unavailable_not_low_volume():
    frame = _frame().drop(columns=["volume_available"])
    prepared = add_volume_features(frame)
    policy = apply_volume_policy(
        prepared,
        {"volume_lane": "low_volume_risk_firewall"},
        "hybrid_v1",
    )

    assert volume_quality_gate(prepared)["status"] == "volume_unavailable"
    assert policy.iloc[-1]["signal"] == "BUY"
    assert policy.iloc[-1]["volume_regime"] == "unavailable"
    assert policy.iloc[-1]["volume_risk_multiplier"] == 1.0


def test_relative_volume_does_not_change_when_only_future_volume_changes():
    baseline = _frame(360)
    changed = baseline.copy()
    changed.loc[300:, "volume"] = 10000.0

    left = add_volume_features(baseline)
    right = add_volume_features(changed)
    assert left.loc[:299, "volume_ratio"].equals(right.loc[:299, "volume_ratio"])


def test_low_volume_firewall_is_bounded_to_available_rows():
    frame = _frame(240)
    frame.loc[220, "volume"] = 1.0
    prepared = add_volume_features(frame)
    policy = apply_volume_policy(
        prepared,
        {"volume_lane": "low_volume_risk_firewall"},
        "hybrid_v1",
    )

    assert policy.loc[220, "volume_feature_available"]
    assert policy.loc[220, "signal"] in {"WAIT", "BUY"}
    assert policy.loc[220, "volume_risk_multiplier"] <= 1.0


def test_shadow_report_is_observational_and_has_decile_evidence():
    frame = add_volume_features(_frame(360))
    report = volume_shadow_report(frame, [
        {
            "signal_time": str(frame.iloc[250]["time"]),
            "profit_percent": 1.0,
            "result": "WIN",
        },
    ])

    assert report["status"] == "assessed"
    assert report["promotion_evidence"] is False
    assert len(report["deciles"]) == 10
    assert report["trade_deciles"]
