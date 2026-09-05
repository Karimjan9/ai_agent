from __future__ import annotations

import pandas as pd

from app.main import _entry_contract_projection, paper_signal
from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.parameter_schema import validate_strategy_parameters
from app.services.backtester import (
    _confirmation_fill_admission,
    _entry_contract_funnel_report,
    _exit_distances,
)
from app.strategies.confirmation_entry import (
    PROTOCOL,
    apply_confirmation_entry_mtf_strategy,
    compile_confirmation_entry_contract,
)


def _enriched_trend_setup(h1_target: float = 110.0) -> pd.DataFrame:
    times = pd.date_range("2026-01-01T00:00:00Z", periods=3, freq="5min")
    return pd.DataFrame({
        "time": times,
        "decision_at": times + pd.Timedelta(minutes=5),
        "open": [100.0, 100.8, 100.2],
        "high": [100.5, 101.5, 101.4],
        "low": [99.7, 100.7, 100.1],
        "close": [100.2, 101.3, 101.4],
        "mtf_stack_status": "ready",
        "h4_structure_direction": "bullish",
        "h1_structure_direction": "bullish",
        "h1_structure_regime": "trend_up",
        "h1_dynamic_fib_zone_low": 99.0,
        "h1_dynamic_fib_zone_high": 102.0,
        "h1_confirmed_swing_high": h1_target,
        "h1_confirmed_swing_low": 95.0,
        "m15_trap_direction": "bullish",
        "m15_trap_available_at": pd.Timestamp("2026-01-01T00:00:00Z"),
        "m15_trap_extreme": 98.0,
        "structure_atr": 1.0,
        "choch_event": ["none", "bullish", "none"],
        "bos_event": ["none", "bullish", "none"],
        "break_displacement_atr": [0.0, 0.8, 0.0],
        "confirmed_swing_high": [100.5, 100.5, 100.5],
        "confirmed_swing_low": [99.0, 99.0, 99.0],
        "fvg": "none",
        "fvg_midpoint": float("nan"),
        "market_regime": "trend_up",
    })


def _enriched_breakout(close_outside: bool = True) -> pd.DataFrame:
    frame = _enriched_trend_setup().copy()
    frame["h1_confirmed_swing_high"] = 100.0
    frame["h1_confirmed_swing_low"] = 90.0
    frame["h1_dynamic_fib_zone_low"] = 92.0
    frame["h1_dynamic_fib_zone_high"] = 96.0
    frame["open"] = [99.2, 99.7, 100.1]
    frame["high"] = [99.7, 100.8, 100.7]
    frame["low"] = [99.0, 99.6, 99.9]
    frame["close"] = [99.5, 100.6 if close_outside else 99.8, 100.5]
    frame["choch_event"] = "none"
    frame["bos_event"] = "none"
    frame["break_displacement_atr"] = 0.0
    frame["confirmed_swing_high"] = 99.5
    frame["confirmed_swing_low"] = 98.5
    return frame


def test_balanced_contract_separates_setup_confirmation_trigger_and_entry() -> None:
    result = compile_confirmation_entry_contract(_enriched_trend_setup(), {
        "entry_model": "trend_continuation",
        "entry_mode": "balanced",
        "minimum_reward_space_r": 1.5,
        "rejection_wick_ratio": 0.1,
    })

    displacement = result.iloc[1]
    entry = result.iloc[2]
    assert displacement["entry_setup_detected"]
    assert displacement["entry_confirmation_valid"]
    assert not displacement["entry_trigger_valid"]
    assert displacement["signal"] == "WAIT"
    assert entry["entry_trigger_valid"]
    assert entry["entry_invalidation_valid"]
    assert entry["entry_reward_space_valid"]
    assert entry["entry_independent_confirmation_count"] == 3
    assert entry["entry_raw_confirmation_count"] > entry["entry_independent_confirmation_count"]
    assert entry["entry_redundancy_penalty"] > 0
    assert entry["entry_contract_status"] == "entry_ready"
    assert entry["entry_grade"] == "A+"
    assert entry["signal"] == "BUY"

    projection = _entry_contract_projection(entry)
    assert projection["protocol"] == PROTOCOL
    assert projection["checks"]["setup"] is True
    assert projection["checks"]["confirmation"] is True
    assert projection["checks"]["trigger"] is True
    assert projection["temporal_roles"] == {
        "bias": "H1", "setup": "H1", "trigger": "M5", "execution": "M5",
    }
    assert projection["confirmation"]["independent_count"] == 3
    assert projection["reference_price"] == 101.4
    assert projection["invalidation_price"] == 97.95
    assert len(projection["contract_hash"]) == 64

    funnel = _entry_contract_funnel_report(result, warmup_rows=0)
    assert funnel["protocol"] == "entry_contract_funnel_v2"
    assert funnel["count_semantics"] == "ordered_cumulative_pipeline"
    assert funnel["stage_counts"]["setup"] == 3
    assert funnel["stage_counts"]["confirmation"] == 2
    assert funnel["stage_counts"]["trigger"] == 1
    assert funnel["stage_counts"]["entry_ready"] == 1
    assert funnel["conversion"]["confirmation_to_trigger_percent"] == 50.0
    assert funnel["confirmation_cost"]["average_redundancy_penalty"] > 0
    assert funnel["trigger_topology"]["counterfactual_mode_counts"]["aggressive"] is not None
    assert funnel["trigger_topology"]["setup_aligned_confirmation_families"]["market_structure"] is not None
    assert funnel["trigger_topology"]["diagnosis"] == "entry_path_activated"

    # Independent trigger predicates are useful diagnostics, but a trigger
    # that did not pass the preceding stages must never inflate conversion.
    independent_trigger = result.copy()
    independent_trigger.loc[independent_trigger.index[0], "entry_trigger_valid"] = True
    ordered = _entry_contract_funnel_report(independent_trigger, warmup_rows=0)
    assert ordered["predicate_counts"]["trigger"] == 2
    assert ordered["stage_counts"]["trigger"] == 1
    counts = ordered["stage_counts"]
    assert counts["setup"] >= counts["confirmation"] >= counts["trigger"] >= counts["entry_ready"]


def test_topology_genomes_change_the_executable_confirmation_entry_contract() -> None:
    result = compile_confirmation_entry_contract(_enriched_breakout(), {
        "entry_model": "trend_continuation",
        "setup_topology_policy": "breakout_and_retest",
        "confirmation_family_policy": "structure_plus_reaction",
        "trigger_topology_policy": "aggressive_structure_close",
    })

    row = result.iloc[-1]
    assert row["entry_contract_model"] == "breakout_retest"
    assert row["entry_setup_topology_policy"] == "breakout_and_retest"
    assert row["entry_confirmation_family_policy"] == "structure_plus_reaction"
    assert row["entry_trigger_topology_policy"] == "aggressive_structure_close"
    assert row["entry_order_type"] == "stop_after_confirmation_close"


def test_right_direction_is_still_skipped_when_target_space_is_too_small() -> None:
    result = compile_confirmation_entry_contract(_enriched_trend_setup(h1_target=102.0), {
        "entry_model": "trend_continuation",
        "entry_mode": "balanced",
        "minimum_reward_space_r": 1.5,
    })
    row = result.iloc[-1]

    assert row["entry_trigger_valid"]
    assert not row["entry_reward_space_valid"]
    assert row["entry_contract_status"] == "reward_space_insufficient"
    assert row["signal"] == "WAIT"


def test_breakout_requires_close_then_hold_retest_not_a_wick_only_event() -> None:
    parameters = {
        "entry_model": "breakout_retest", "entry_mode": "balanced",
        "breakout_minimum_expansion_atr": .35,
    }
    valid = compile_confirmation_entry_contract(_enriched_breakout(), parameters).iloc[-1]
    wick_only = compile_confirmation_entry_contract(_enriched_breakout(close_outside=False), parameters)

    assert valid["entry_setup_detected"]
    assert valid["entry_confirmation_valid"]
    assert valid["entry_trigger_valid"]
    assert valid["signal"] == "BUY"
    assert set(wick_only["signal"]) == {"WAIT"}
    assert not wick_only.iloc[1]["entry_setup_detected"]
    # The following candle may become a genuine close-break setup, but a
    # balanced model still waits for a later hold/retest trigger.
    assert not wick_only.iloc[-1]["entry_trigger_valid"]


def test_breakout_close_does_not_count_as_an_independent_reaction_family() -> None:
    default = compile_confirmation_entry_contract(_enriched_breakout(), {
        "entry_model": "breakout_retest", "entry_mode": "aggressive",
        "breakout_minimum_expansion_atr": .35,
    }).iloc[1]
    minimum_sufficient = compile_confirmation_entry_contract(_enriched_breakout(), {
        "entry_model": "breakout_retest", "entry_mode": "aggressive",
        "breakout_minimum_expansion_atr": .35,
        "minimum_independent_confirmations": 2,
    }).iloc[1]

    assert default["entry_independent_confirmation_count"] == 2
    assert not default["entry_confirmation_valid"]
    assert default["signal"] == "WAIT"
    assert minimum_sufficient["signal"] == "BUY"


def test_breakout_temporal_role_binder_keeps_h1_bias_but_can_use_m15_setup_level() -> None:
    frame = _enriched_breakout()
    # H1 stays the directional authority and remains unbroken. M15 owns only
    # the setup level, allowing M5 to execute a real intermediate-timeframe
    # breakout instead of pretending that every setup must be an H1 break.
    frame["h1_confirmed_swing_high"] = 110.0
    frame["h1_confirmed_swing_low"] = 90.0
    frame["m15_confirmed_swing_high"] = 100.0
    frame["m15_confirmed_swing_low"] = 96.0
    parameters = {
        "entry_model": "breakout_retest",
        "entry_mode": "aggressive",
        "minimum_independent_confirmations": 2,
    }

    h1 = compile_confirmation_entry_contract(frame, {
        **parameters, "breakout_setup_timeframe": "H1",
    })
    m15 = compile_confirmation_entry_contract(frame, {
        **parameters, "breakout_setup_timeframe": "M15",
    })

    assert set(h1["signal"]) == {"WAIT"}
    assert m15.iloc[1]["signal"] == "BUY"
    assert m15.iloc[1]["entry_breakout_setup_timeframe"] == "M15"
    assert m15.iloc[1]["entry_target_reference_price"] == 104.0

    missing = compile_confirmation_entry_contract(
        frame.drop(columns=["m15_confirmed_swing_high"]),
        {**parameters, "breakout_setup_timeframe": "M15"},
    )
    assert set(missing["entry_contract_status"]) == {"missing_m15_breakout_setup_context"}


def test_later_weak_structure_event_cannot_inherit_old_displacement_proof() -> None:
    frame = _enriched_trend_setup()
    frame.loc[2, "choch_event"] = "bullish"
    frame.loc[2, "break_displacement_atr"] = .1
    row = compile_confirmation_entry_contract(frame, {
        "entry_model": "trend_continuation", "entry_mode": "balanced",
        "minimum_independent_confirmations": 3,
    }).iloc[-1]

    assert row["entry_independent_confirmation_count"] == 2
    assert not row["entry_confirmation_valid"]
    assert row["signal"] == "WAIT"


def test_fill_revalidates_reward_space_chase_and_structural_target() -> None:
    parameters = {
        "entry_model": "trend_continuation", "entry_mode": "balanced",
        "minimum_reward_space_r": 1.5, "max_chase_atr": 1.25,
    }
    row = compile_confirmation_entry_contract(_enriched_trend_setup(), parameters).iloc[-1]
    payload = SimpleBacktestRequest(
        symbol="XAUUSD", timeframe="M5", strategy="confirmation_entry_mtf_v1",
        parameters=parameters,
    )

    admitted = _confirmation_fill_admission(pd.Series({"open": 101.4}), payload, row)
    late = _confirmation_fill_admission(pd.Series({"open": 109.0}), payload, row)
    _, target_distance = _exit_distances(101.4, row, payload)

    assert admitted["allowed"] is True
    assert late["allowed"] is False
    assert late["reason"] == "entry_contract_fill_reward_space"
    assert round(target_distance, 6) == 8.6

    chased_row = compile_confirmation_entry_contract(_enriched_trend_setup(h1_target=120.0), parameters).iloc[-1]
    chased = _confirmation_fill_admission(pd.Series({"open": 103.0}), payload, chased_row)
    assert chased["allowed"] is False
    assert chased["reason"] == "entry_contract_fill_chase"

    expensive_payload = payload.model_copy(update={
        "execution": ExecutionConfig(commission_percent=3.0),
    })
    cost_veto = _confirmation_fill_admission(pd.Series({"open": 101.4}), expensive_payload, row)
    assert cost_veto["allowed"] is False
    assert cost_veto["reason"] == "entry_contract_fill_reward_space"
    assert cost_veto["commission_distance"] > 0


def test_unknown_model_or_mode_fails_closed_in_direct_compiler_calls() -> None:
    unknown_model = compile_confirmation_entry_contract(_enriched_trend_setup(), {"entry_model": "invented"})
    unknown_mode = compile_confirmation_entry_contract(_enriched_trend_setup(), {"entry_mode": "instant"})

    assert set(unknown_model["entry_contract_status"]) == {"unsupported_entry_model"}
    assert set(unknown_mode["entry_contract_status"]) == {"unsupported_entry_mode"}
    assert set(unknown_model["signal"]) == {"WAIT"}


def test_invalid_m5_ohlc_invalidates_the_whole_entry_stream() -> None:
    invalid = _enriched_trend_setup()
    invalid.loc[1, "high"] = invalid.loc[1, "low"] - 1

    result = apply_confirmation_entry_mtf_strategy(invalid, {
        "entry_model": "trend_continuation", "entry_mode": "balanced",
    })

    assert set(result["signal"]) == {"WAIT"}
    assert set(result["entry_contract_status"]) == {"invalid_entry_ohlc"}


def test_missing_closed_mtf_context_fails_closed() -> None:
    raw = _enriched_trend_setup().drop(columns=["h4_structure_direction"])
    result = apply_confirmation_entry_mtf_strategy(raw, {"swing_lookback": 10})

    assert set(result["signal"]) == {"WAIT"}
    assert set(result["entry_contract_status"]) == {"missing_closed_mtf_context"}
    assert not result["entry_context_valid"].any()


def test_parameter_contract_declares_models_modes_and_confirmation_cost_gates() -> None:
    values = validate_strategy_parameters("confirmation_entry_mtf_v1", {
        "entry_model": "false_break_reversal",
        "entry_mode": "conservative",
        "minimum_independent_confirmations": 3,
        "minimum_reward_space_r": 2.0,
        "max_chase_atr": 0.75,
    })

    assert values["entry_model"] == "false_break_reversal"
    assert values["entry_mode"] == "conservative"
    assert values["minimum_reward_space_r"] == 2.0


def test_paper_signal_uses_same_mtf_compiler_and_fails_closed_without_context_streams() -> None:
    times = pd.date_range("2026-01-01T00:00:00Z", periods=80, freq="5min")
    candles = [
        {
            "time": time, "open": 100 + index * .01,
            "high": 100.2 + index * .01, "low": 99.8 + index * .01,
            "close": 100.1 + index * .01, "volume": 1000,
        }
        for index, time in enumerate(times)
    ]
    result = paper_signal(SimpleBacktestRequest(
        symbol="XAUUSD", timeframe="M5", strategy="confirmation_entry_mtf_v1",
        parameters={"entry_model": "trend_continuation", "entry_mode": "balanced"},
        candles=candles,
    ))

    assert result["signal"] == "WAIT"
    assert result["entry_contract"]["protocol"] == PROTOCOL
    assert result["entry_contract"]["status"] == "missing_closed_mtf_context"
    assert result["execution_contract_preview"]["entry_contract"]["status"] == "missing_closed_mtf_context"
    assert result["entry_fill_admission"] == result["execution_contract_preview"]["entry_fill_admission"]


def test_confirmation_entry_contract_is_m5_only_even_when_context_is_requested_elsewhere() -> None:
    times = pd.date_range("2026-01-01T00:00:00Z", periods=80, freq="15min")
    candles = [
        {
            "time": time, "open": 100 + index * .01,
            "high": 100.2 + index * .01, "low": 99.8 + index * .01,
            "close": 100.1 + index * .01, "volume": 1000,
        }
        for index, time in enumerate(times)
    ]
    result = paper_signal(SimpleBacktestRequest(
        symbol="XAUUSD", timeframe="M15", strategy="confirmation_entry_mtf_v1",
        parameters={"entry_model": "trend_continuation", "entry_mode": "balanced"},
        candles=candles,
    ))

    assert result["signal"] == "WAIT"
    assert result["entry_contract"]["status"] == "unsupported_entry_timeframe"
