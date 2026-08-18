import numpy as np
import pandas as pd

from app.services.market_regime import apply_market_regime
from app.services.parameter_schema import validate_strategy_parameters


def _candles(rows: int = 280) -> pd.DataFrame:
    steps = np.where(np.arange(rows) < rows // 2, 0.8, -0.65)
    close = 1800.0 + np.cumsum(steps)
    return pd.DataFrame(
        {
            "time": pd.date_range("2024-01-01", periods=rows, freq="h", tz="UTC"),
            "open": close - 0.2,
            "high": close + 0.5,
            "low": close - 0.5,
            "close": close,
            "volume": 1000.0,
        }
    )


def test_regime_classifier_variants_are_executable_and_auditable():
    frame = _candles()
    frozen = apply_market_regime(frame, "frozen")
    hysteresis = apply_market_regime(frame, "adx_hysteresis_v1")

    assert set(frozen["regime_classifier_variant"].unique()) == {"frozen"}
    assert set(hysteresis["regime_classifier_variant"].unique()) == {"adx_hysteresis_v1"}
    assert "market_regime" in hysteresis
    assert set(hysteresis["market_regime"].dropna().unique()).issubset(
        {"trend_up", "trend_down", "range", "unknown"}
    )


def test_regime_classifier_gene_is_schema_valid():
    values = validate_strategy_parameters(
        "hybrid_v1",
        {"regime_classifier_variant": "ema_slope_consensus_v1"},
    )

    assert values["regime_classifier_variant"] == "ema_slope_consensus_v1"
