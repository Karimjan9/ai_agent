import numpy as np
import pandas as pd

from app.strategies.breakout import apply_breakout_continuation_strategy
from app.strategies.laboratory import (
    apply_hybrid_consensus_strategy,
    apply_mean_reversion_rsi_strategy,
    apply_momentum_pullback_strategy,
    apply_session_mean_reversion_strategy,
    apply_volatility_breakout_strategy,
)
from app.strategies.registry import get_strategy


def _frame(rows: int = 260) -> pd.DataFrame:
    close = 100 + np.cumsum(np.sin(np.arange(rows) / 13) * 0.15 + 0.04)
    high = close + 0.4
    low = close - 0.4
    return pd.DataFrame({
        "time": pd.date_range("2025-01-01", periods=rows, freq="h", tz="UTC"),
        "open": close - 0.05,
        "high": high,
        "low": low,
        "close": close,
        "market_regime": ["unknown"] * rows,
        "volatility_regime": ["normal_volatility"] * rows,
        "adx": [12.0] * rows,
        "atr_regime": [0.8] * rows,
    })


def test_architecture_aliases_resolve_to_distinct_tactic_functions():
    assert get_strategy("xauusd_breakout_g1_a01", "breakout_continuation_v1") is apply_breakout_continuation_strategy
    assert get_strategy("eurusd_mean_reversion_g1_a01", "range_rsi_reversion_v1") is apply_mean_reversion_rsi_strategy
    assert get_strategy("gbpusd_momentum_g1_a01", "momentum_pullback_v1") is apply_momentum_pullback_strategy
    assert get_strategy("eurusd_session_g1_a01", "session_mean_reversion_v1") is apply_session_mean_reversion_strategy
    assert get_strategy("gbpusd_volatility_g1_a01", "volatility_breakout_v1") is apply_volatility_breakout_strategy
    assert get_strategy("xauusd_hybrid_g1_a01", "regime_consensus_v1") is apply_hybrid_consensus_strategy


def test_tactic_functions_return_closed_candle_signal_contract():
    frame = _frame()
    for function in [
        apply_breakout_continuation_strategy,
        apply_volatility_breakout_strategy,
        apply_mean_reversion_rsi_strategy,
        apply_session_mean_reversion_strategy,
        apply_momentum_pullback_strategy,
        apply_hybrid_consensus_strategy,
    ]:
        result = function(frame)
        assert len(result) == len(frame)
        assert set(result["signal"].unique()).issubset({"BUY", "SELL", "WAIT"})
        assert "signal_confidence" in result
