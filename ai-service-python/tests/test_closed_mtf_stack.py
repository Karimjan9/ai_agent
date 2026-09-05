from __future__ import annotations

import pandas as pd

from app.services.multitimeframe_stack import (
    apply_closed_mtf_context,
    prepare_closed_mtf_context,
)
from app.services.parameter_schema import validate_strategy_parameters
from app.strategies.structure import (
    apply_liquidity_trap_mtf_strategy,
    apply_mtf_research_control_strategy,
    apply_mtf_research_playbook_strategy,
)


def _candles(start: str, periods: int, frequency: str) -> pd.DataFrame:
    times = pd.date_range(start, periods=periods, freq=frequency, tz="UTC")
    prices = [2000.0 + index * .2 for index in range(periods)]
    return pd.DataFrame({
        "time": times,
        "open": prices,
        "high": [value + .3 for value in prices],
        "low": [value - .3 for value in prices],
        "close": [value + .1 for value in prices],
        "volume": 1000.0,
    })


def test_higher_timeframe_context_is_unavailable_until_its_candle_closes() -> None:
    m5 = _candles("2026-01-01", 72, "5min")
    h4 = _candles("2025-12-31", 12, "4h")
    h1 = _candles("2026-01-01", 8, "h")
    m15 = _candles("2026-01-01", 24, "15min")

    merged = apply_closed_mtf_context(m5, {"H4": h4, "H1": h1, "M15": m15})
    before_h1_close = merged.loc[merged["time"] == pd.Timestamp("2026-01-01T00:50:00Z")].iloc[0]
    at_h1_close = merged.loc[merged["time"] == pd.Timestamp("2026-01-01T00:55:00Z")].iloc[0]

    assert pd.isna(before_h1_close["h1_available_at"])
    assert at_h1_close["h1_available_at"] == pd.Timestamp("2026-01-01T01:00:00Z")
    assert at_h1_close["m15_available_at"] == pd.Timestamp("2026-01-01T01:00:00Z")
    assert at_h1_close["h4_available_at"] <= at_h1_close["decision_at"]
    assert "h1_structure_regime" in merged.columns


def test_missing_context_fails_closed_and_cannot_create_a_trade_signal() -> None:
    m5 = _candles("2026-01-01", 120, "5min")
    h1 = _candles("2026-01-01", 12, "h")
    m15 = _candles("2026-01-01", 40, "15min")

    merged = apply_closed_mtf_context(m5, {"H1": h1, "M15": m15})
    result = apply_liquidity_trap_mtf_strategy(merged, {"swing_lookback": 10})

    assert set(merged["mtf_stack_status"]) == {"incomplete"}
    assert set(result["signal"]) == {"WAIT"}
    assert set(result["liquidity_trap_status"]) == {"missing_closed_mtf_context"}


def test_invalid_higher_timeframe_ohlc_fails_the_whole_context_stream_closed() -> None:
    m5 = _candles("2026-01-01", 120, "5min")
    h4 = _candles("2025-12-31", 12, "4h")
    h1 = _candles("2026-01-01", 12, "h")
    m15 = _candles("2026-01-01", 40, "15min")
    h1.loc[4, "high"] = h1.loc[4, "low"] - 1

    merged = apply_closed_mtf_context(m5, {"H4": h4, "H1": h1, "M15": m15})

    assert set(merged["mtf_stack_status"]) == {"incomplete"}
    assert set(merged["mtf_stack_reason"]) == {"invalid_h1_stream"}


def test_precompiled_context_is_byte_equivalent_across_independent_entry_folds() -> None:
    m5 = _candles("2026-01-01", 144, "5min")
    streams = {
        "H4": _candles("2025-12-28", 30, "4h"),
        "H1": _candles("2025-12-31", 60, "h"),
        "M15": _candles("2025-12-31", 180, "15min"),
    }
    parameters = {"swing_lookback": 10, "h1_range_adx_max": 20}
    compiled = prepare_closed_mtf_context(streams, parameters)

    for fold in (m5.iloc[:72].reset_index(drop=True), m5.iloc[72:].reset_index(drop=True)):
        direct = apply_closed_mtf_context(fold, streams, parameters)
        reused = apply_closed_mtf_context(
            fold,
            {},
            parameters,
            prepared_context=compiled,
        )
        pd.testing.assert_frame_equal(direct, reused)
        assert direct.attrs == reused.attrs


def test_liquidity_trap_mtf_parameter_contract_matches_the_research_modes() -> None:
    assert validate_strategy_parameters(
        "liquidity_trap_mtf_v1",
        {"entry_mode": "balanced", "m15_trap_expiry_minutes": 30},
    ) == {"entry_mode": "balanced", "m15_trap_expiry_minutes": 30}


def test_catalogue_playbook_and_control_use_closed_context_and_smt_fails_closed_without_related_market() -> None:
    m5 = _candles("2026-01-02", 500, "5min")
    h4 = _candles("2025-12-25", 60, "4h")
    h1 = _candles("2026-01-01", 80, "h")
    m15 = _candles("2026-01-02", 180, "15min")
    d1 = _candles("2025-11-01", 40, "D")
    merged = apply_closed_mtf_context(m5, {"H4": h4, "H1": h1, "M15": m15, "D1": d1})

    control = apply_mtf_research_control_strategy(merged, {"swing_lookback": 10})
    smt = apply_mtf_research_playbook_strategy(merged, {
        "research_model_id": "smt_sweep_mss", "swing_lookback": 10,
    })
    ict = apply_mtf_research_playbook_strategy(merged, {
        "research_model_id": "ict_2022_raid_mss_fvg", "swing_lookback": 10,
    })

    assert set(control["signal"]).issubset({"BUY", "SELL", "WAIT"})
    assert set(smt["signal"]) == {"WAIT"}
    assert set(smt["research_playbook_status"]) == {"related_market_missing"}
    assert "d1_available_at" in ict.columns


def test_catalogue_playbook_parameter_contract_accepts_only_declared_model_ids() -> None:
    assert validate_strategy_parameters(
        "mtf_research_playbook_v1",
        {"research_model_id": "orb_htf_bias", "opening_range_minutes": 60},
    ) == {"research_model_id": "orb_htf_bias", "opening_range_minutes": 60}
