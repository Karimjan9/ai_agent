import pandas as pd

from app.strategies.structure import (
    apply_bos_retest_strategy,
    apply_fibonacci_structure_pullback_strategy,
    apply_structure_instruments,
)
from app.strategies.registry import get_strategy
from app.main import _tactical_contract
from app.schemas import SimpleBacktestRequest


def _frame() -> pd.DataFrame:
    rows = []
    for index in range(80):
        close = 100 + index * .1
        rows.append({"time": pd.Timestamp("2025-01-01", tz="UTC") + pd.Timedelta(minutes=15 * index), "open": close - .03, "high": close + .12, "low": close - .12, "close": close, "volume": 1000 + index})
    rows[-1] = {**rows[-1], "open": 107.7, "high": 108.1, "low": 107.2, "close": 107.95}
    return pd.DataFrame(rows)


def test_structure_instruments_are_causal_and_return_a_value_vector():
    output = apply_structure_instruments(_frame(), {"swing_lookback": 20, "atr_period": 14})

    assert {"confirmed_swing_high", "confirmed_swing_low", "bos_event", "choch_event", "liquidity_sweep", "dynamic_fib_zone_low", "dynamic_fib_zone_high", "false_break_probability"}.issubset(output.columns)
    assert output.loc[20, "confirmed_swing_high"] == _frame().loc[:19, "high"].max()
    assert output["false_break_probability"].between(0, 1).all()


def test_structure_playbooks_are_registered_and_executable():
    frame = _frame()
    fibonacci = get_strategy("fibonacci_structure_pullback_v1")(frame, {"swing_lookback": 20})
    bos = apply_bos_retest_strategy(frame, {"swing_lookback": 20})

    assert set(fibonacci["signal"].unique()).issubset({"BUY", "SELL", "WAIT"})
    assert "signal_confidence" in fibonacci
    assert set(bos["signal"].unique()).issubset({"BUY", "SELL", "WAIT"})


def test_execution_tactic_contract_forbids_martingale_and_full_kelly():
    contract = _tactical_contract(SimpleBacktestRequest(strategy="fibonacci_structure_pullback_v1", candles=_frame().to_dict("records")))

    assert contract["sizing"] == "volatility_scaled_fractional"
    assert contract["risk"]["martingale"] == "forbidden"
    assert contract["risk"]["full_kelly"] == "forbidden"
