"""Causal confirmation and entry contracts for role-separated MTF research.

The contract deliberately keeps setup, confirmation and trigger separate.  A
location is an area in which a setup may become interesting; it is never an
entry by itself.  All higher-timeframe values must already have been merged by
``apply_closed_mtf_context`` and therefore be available at the M5 decision
time.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd

from app.strategies.structure import apply_structure_instruments


PROTOCOL = "confirmation_entry_contract_v1"
SUPPORTED_MODELS = frozenset({
    "trend_continuation", "breakout_retest", "false_break_reversal",
    "range_sweep", "htf_reversal",
})
SUPPORTED_MODES = frozenset({"aggressive", "balanced", "conservative"})
SUPPORTED_CONFIRMATION_POLICIES = frozenset({
    "all_three_simultaneous", "structure_plus_reaction", "structure_plus_participation",
    "sequential_three", "state_adaptive_two_of_three",
})
SUPPORTED_TRIGGER_POLICIES = frozenset({
    "aggressive_structure_close", "balanced_retest_reaction", "conservative_continuation",
    "session_adaptive", "volatility_adaptive",
})
SUPPORTED_SETUP_POLICIES = frozenset({
    "breakout_and_retest", "pullback_rejection", "liquidity_sweep_reclaim",
    "range_reentry", "compression_expansion",
})


def apply_confirmation_entry_mtf_strategy(
    frame: pd.DataFrame,
    parameters: dict[str, Any] | None = None,
) -> pd.DataFrame:
    """Apply the executable wrapper after building causal M5 instruments."""
    if not _execution_stream_valid(frame):
        return compile_confirmation_entry_contract(frame, parameters)
    return compile_confirmation_entry_contract(
        apply_structure_instruments(frame, parameters),
        parameters,
    )


def compile_confirmation_entry_contract(
    enriched: pd.DataFrame,
    parameters: dict[str, Any] | None = None,
) -> pd.DataFrame:
    """Compile WHERE -> WHY -> PROVE -> TRIGGER -> INVALIDATE per candle.

    ``enriched`` is public intentionally: deterministic tests and future
    feature providers can submit already-computed, closed-candle evidence
    without hiding outcome-derived labels inside this contract.
    """
    parameters = parameters or {}
    out = enriched.copy()
    out["signal"] = "WAIT"
    out["signal_confidence"] = 0.0
    out["entry_contract_protocol"] = PROTOCOL
    model = str(parameters.get("entry_model", "trend_continuation"))
    mode = str(parameters.get("entry_mode", "balanced"))
    confirmation_policy = str(parameters.get("confirmation_family_policy", "legacy_minimum_independent"))
    trigger_policy = str(parameters.get("trigger_topology_policy", "legacy_entry_mode"))
    setup_policy = str(parameters.get("setup_topology_policy", "legacy_entry_model"))
    setup_models = {
        "breakout_and_retest": "breakout_retest",
        "pullback_rejection": "trend_continuation",
        "liquidity_sweep_reclaim": "false_break_reversal",
        "range_reentry": "range_sweep",
        "compression_expansion": "htf_reversal",
    }
    if setup_policy in setup_models:
        model = setup_models[setup_policy]
    breakout_setup_timeframe = str(parameters.get("breakout_setup_timeframe", "H1")).upper()
    out["entry_contract_model"] = model
    out["entry_contract_mode"] = mode
    out["entry_confirmation_family_policy"] = confirmation_policy
    out["entry_trigger_topology_policy"] = trigger_policy
    out["entry_setup_topology_policy"] = setup_policy
    out["entry_breakout_setup_timeframe"] = breakout_setup_timeframe
    execution_timeframe = str(out.attrs.get("execution_timeframe", "M5")).upper()
    if execution_timeframe != "M5":
        return _fail_closed(out, "unsupported_entry_timeframe")
    if model not in SUPPORTED_MODELS:
        return _fail_closed(out, "unsupported_entry_model")
    if mode not in SUPPORTED_MODES:
        return _fail_closed(out, "unsupported_entry_mode")
    if confirmation_policy not in SUPPORTED_CONFIRMATION_POLICIES | {"legacy_minimum_independent"}:
        return _fail_closed(out, "unsupported_confirmation_family_policy")
    if trigger_policy not in SUPPORTED_TRIGGER_POLICIES | {"legacy_entry_mode"}:
        return _fail_closed(out, "unsupported_trigger_topology_policy")
    if setup_policy not in SUPPORTED_SETUP_POLICIES | {"legacy_entry_model"}:
        return _fail_closed(out, "unsupported_setup_topology_policy")
    if model == "breakout_retest" and breakout_setup_timeframe not in {"H1", "M15"}:
        return _fail_closed(out, "unsupported_breakout_setup_timeframe")
    if not _execution_stream_valid(out):
        return _fail_closed(out, "invalid_entry_ohlc")

    required = {
        "mtf_stack_status", "h4_structure_direction", "h1_structure_direction",
        "h1_dynamic_fib_zone_low", "h1_dynamic_fib_zone_high",
        "h1_confirmed_swing_high", "h1_confirmed_swing_low",
        "m15_trap_direction", "m15_trap_available_at", "m15_trap_extreme",
        "structure_atr", "choch_event", "bos_event", "break_displacement_atr",
        "confirmed_swing_high", "confirmed_swing_low",
    }
    if not required.issubset(out.columns):
        return _fail_closed(out, "missing_closed_mtf_context")
    if model == "range_sweep" and "h1_structure_regime" not in out.columns:
        return _fail_closed(out, "missing_h1_range_context")

    decision_time = pd.to_datetime(out.get("decision_at", out["time"]), utc=True, errors="coerce")
    close = pd.to_numeric(out["close"], errors="coerce")
    high = pd.to_numeric(out["high"], errors="coerce")
    low = pd.to_numeric(out["low"], errors="coerce")
    open_ = pd.to_numeric(out["open"], errors="coerce")
    atr = pd.to_numeric(out["structure_atr"], errors="coerce").replace(0, np.nan)
    h1_high = pd.to_numeric(out["h1_confirmed_swing_high"], errors="coerce")
    h1_low = pd.to_numeric(out["h1_confirmed_swing_low"], errors="coerce")
    h1_range = (h1_high - h1_low).clip(lower=0)
    breakout_high = h1_high
    breakout_low = h1_low
    breakout_range = h1_range
    if model == "breakout_retest" and breakout_setup_timeframe == "M15":
        m15_swing_columns = {"m15_confirmed_swing_high", "m15_confirmed_swing_low"}
        if not m15_swing_columns.issubset(out.columns):
            return _fail_closed(out, "missing_m15_breakout_setup_context")
        breakout_high = pd.to_numeric(out["m15_confirmed_swing_high"], errors="coerce")
        breakout_low = pd.to_numeric(out["m15_confirmed_swing_low"], errors="coerce")
        breakout_range = (breakout_high - breakout_low).clip(lower=0)
    h4_direction = out["h4_structure_direction"].astype("string")
    h1_direction = out["h1_structure_direction"].astype("string")
    trap_direction = out["m15_trap_direction"].astype("string")

    context_ready = out["mtf_stack_status"].eq("ready") & h4_direction.isin(["bullish", "bearish"])
    htf_aligned = h1_direction.eq(h4_direction)
    in_h1_poi = low.le(pd.to_numeric(out["h1_dynamic_fib_zone_high"], errors="coerce")) & high.ge(
        pd.to_numeric(out["h1_dynamic_fib_zone_low"], errors="coerce")
    )
    location_tolerance = atr * float(parameters.get("location_tolerance_atr", .35))
    at_h1_low = low.le(h1_low + location_tolerance) & close.ge(h1_low - location_tolerance)
    at_h1_high = high.ge(h1_high - location_tolerance) & close.le(h1_high + location_tolerance)

    trap_time = pd.to_datetime(out["m15_trap_available_at"], utc=True, errors="coerce")
    trap_age = decision_time - trap_time
    trap_expiry = pd.Timedelta(minutes=max(5, int(parameters.get("m15_trap_expiry_minutes", 30))))
    active_trap = trap_age.ge(pd.Timedelta(0)) & trap_age.le(trap_expiry)

    minimum_displacement = float(parameters.get("m5_minimum_displacement_atr", .5))
    # Structure and participation are deliberately separate observations.
    # CHoCH/BOS direction establishes the structural event; displacement
    # qualifies the volatility-participation family instead of being counted
    # twice as both structure and participation.
    bull_shift = (
        out["choch_event"].eq("bullish")
        & trap_direction.eq("bullish")
    )
    bear_shift = (
        out["choch_event"].eq("bearish")
        & trap_direction.eq("bearish")
    )
    displacement = pd.to_numeric(out["break_displacement_atr"], errors="coerce")
    shift_window = pd.Timedelta(minutes=max(5, int(parameters.get("m5_retest_expiry_minutes", 20))))
    active_bull_shift, bull_shift_level, bull_shift_close = _active_event(
        decision_time, bull_shift, out["confirmed_swing_high"], close, shift_window,
    )
    active_bear_shift, bear_shift_level, bear_shift_close = _active_event(
        decision_time, bear_shift, out["confirmed_swing_low"], close, shift_window,
    )
    # Bind participation to the latest structural event. A later weak CHoCH
    # replaces, rather than inherits, an earlier event's displacement proof.
    active_bull_participation = active_bull_shift & displacement.where(bull_shift).ffill().ge(minimum_displacement)
    active_bear_participation = active_bear_shift & displacement.where(bear_shift).ffill().ge(minimum_displacement)
    bull_structure_retest = active_bull_shift & low.le(bull_shift_level) & close.gt(bull_shift_level)
    bear_structure_retest = active_bear_shift & high.ge(bear_shift_level) & close.lt(bear_shift_level)
    bull_fvg_retest, bear_fvg_retest = _fvg_retests(
        out, decision_time, bull_shift, bear_shift, shift_window,
    )
    bull_retest = bull_structure_retest | bull_fvg_retest
    bear_retest = bear_structure_retest | bear_fvg_retest

    candle_range = (high - low).replace(0, np.nan)
    rejection_ratio = float(parameters.get("rejection_wick_ratio", .35))
    lower_wick = np.minimum(open_, close) - low
    upper_wick = high - np.maximum(open_, close)
    bull_rejection = lower_wick.div(candle_range).ge(rejection_ratio) & close.gt(open_)
    bear_rejection = upper_wick.div(candle_range).ge(rejection_ratio) & close.lt(open_)
    bull_engulfing = close.gt(open_) & open_.le(close.shift(1)) & close.ge(open_.shift(1))
    bear_engulfing = close.lt(open_) & open_.ge(close.shift(1)) & close.le(open_.shift(1))
    bull_retest_reaction = bull_retest & (close.gt(open_) | bull_rejection | bull_engulfing)
    bear_retest_reaction = bear_retest & (close.lt(open_) | bear_rejection | bear_engulfing)

    # A breakout is a close beyond a closed H1 level. A wick beyond it does
    # not create an event. The event remains active only long enough to permit
    # a causal hold/retest decision.
    bull_break = context_ready & htf_aligned & h4_direction.eq("bullish") & close.gt(breakout_high) & close.shift(1).le(breakout_high)
    bear_break = context_ready & htf_aligned & h4_direction.eq("bearish") & close.lt(breakout_low) & close.shift(1).ge(breakout_low)
    active_bull_break, bull_break_level, bull_break_close = _active_event(
        decision_time, bull_break, breakout_high, close, shift_window,
    )
    active_bear_break, bear_break_level, bear_break_close = _active_event(
        decision_time, bear_break, breakout_low, close, shift_window,
    )
    bull_break_retest = active_bull_break & decision_time.gt(_event_time(decision_time, bull_break)) & low.le(bull_break_level) & close.gt(bull_break_level)
    bear_break_retest = active_bear_break & decision_time.gt(_event_time(decision_time, bear_break)) & high.ge(bear_break_level) & close.lt(bear_break_level)
    bull_break_reaction = bull_break_retest & (close.gt(open_) | bull_rejection | bull_engulfing)
    bear_break_reaction = bear_break_retest & (close.lt(open_) | bear_rejection | bear_engulfing)
    active_bull_break_reaction = _active_boolean_event(decision_time, bull_break_reaction, shift_window)
    active_bear_break_reaction = _active_boolean_event(decision_time, bear_break_reaction, shift_window)

    # Range ownership belongs to the closed H1 stream. An M5 range label may
    # describe execution noise, but cannot manufacture a higher-timeframe
    # range-sweep setup.
    range_state = out.get("h1_structure_regime", pd.Series("unknown", index=out.index)).astype("string").eq("range")
    context_long, context_short = _model_context(model, context_ready, htf_aligned, h4_direction, range_state)
    location_long, location_short = _model_location(
        model, in_h1_poi, at_h1_low, at_h1_high, close, breakout_high, breakout_low,
    )
    setup_long, setup_short = _model_setup(
        model, context_long, context_short, location_long, location_short,
        active_trap, trap_direction, active_bull_break, active_bear_break,
    )

    if model == "breakout_retest":
        structure_long, structure_short = active_bull_break, active_bear_break
        reaction_long, reaction_short = active_bull_break_reaction, active_bear_break_reaction
        participation_long = _breakout_participation(close, bull_break_level, atr, bull_break, active_bull_break, parameters)
        participation_short = _breakout_participation(bear_break_level, close, atr, bear_break, active_bear_break, parameters)
        aggressive_long, aggressive_short = bull_break, bear_break
        balanced_long, balanced_short = bull_break_reaction, bear_break_reaction
        trigger_anchor_long, trigger_anchor_short = bull_break_close, bear_break_close
    else:
        structure_long, structure_short = active_bull_shift, active_bear_shift
        # The M15 trap is an operational false-break + close-back-inside
        # reclaim, so it is a price-reaction family independent from M5 MSS.
        reaction_long = active_trap & trap_direction.eq("bullish")
        reaction_short = active_trap & trap_direction.eq("bearish")
        participation_long = active_bull_participation
        participation_short = active_bear_participation
        aggressive_long, aggressive_short = bull_shift, bear_shift
        balanced_long, balanced_short = bull_retest_reaction, bear_retest_reaction
        trigger_anchor_long, trigger_anchor_short = bull_shift_close, bear_shift_close

    # Conservative mode waits for a later close through the balanced reaction
    # candle. This is intentionally a separate event, not another name for the
    # same momentum observation.
    conservative_window = pd.Timedelta(minutes=max(5, int(parameters.get("conservative_expiry_minutes", 30))))
    conservative_long = _continuation_after_reaction(decision_time, balanced_long, high, close, conservative_window, "bullish")
    conservative_short = _continuation_after_reaction(decision_time, balanced_short, low, close, conservative_window, "bearish")
    selected_aggressive = pd.Series(False, index=out.index)
    if trigger_policy == "aggressive_structure_close" or (trigger_policy == "legacy_entry_mode" and mode == "aggressive"):
        trigger_long, trigger_short = aggressive_long, aggressive_short
        selected_aggressive = pd.Series(True, index=out.index)
        order_type = "stop_after_confirmation_close"
    elif trigger_policy == "conservative_continuation" or (trigger_policy == "legacy_entry_mode" and mode == "conservative"):
        trigger_long, trigger_short = conservative_long, conservative_short
        order_type = "stop_after_retest_continuation"
    elif trigger_policy == "session_adaptive":
        liquid_session = decision_time.dt.hour.between(7, 16, inclusive="left")
        trigger_long = balanced_long.where(liquid_session, conservative_long)
        trigger_short = balanced_short.where(liquid_session, conservative_short)
        order_type = pd.Series(np.where(liquid_session, "market_after_retest_close", "stop_after_retest_continuation"), index=out.index)
    elif trigger_policy == "volatility_adaptive":
        high_volatility = atr.gt(atr.rolling(50, min_periods=5).median())
        trigger_long = aggressive_long.where(high_volatility, balanced_long)
        trigger_short = aggressive_short.where(high_volatility, balanced_short)
        selected_aggressive = high_volatility.fillna(False)
        order_type = pd.Series(np.where(selected_aggressive, "stop_after_confirmation_close", "market_after_retest_close"), index=out.index)
    else:
        trigger_long, trigger_short = balanced_long, balanced_short
        order_type = "market_after_retest_close"
    out["entry_order_type"] = order_type

    direction_long = setup_long
    direction_short = setup_short
    reaction = (direction_long & reaction_long) | (direction_short & reaction_short)
    structure = (direction_long & structure_long) | (direction_short & structure_short)
    participation = (direction_long & participation_long) | (direction_short & participation_short)
    independent_count = reaction.astype(int) + structure.astype(int) + participation.astype(int)
    confirmation_ablation = bool(parameters.get("attribution_confirmation_bypass", False))
    minimum_independent = 0 if confirmation_ablation else max(1, min(3, int(parameters.get("minimum_independent_confirmations", 3))))
    if confirmation_ablation:
        confirmation_valid = direction_long | direction_short
    elif confirmation_policy == "all_three_simultaneous":
        confirmation_valid = (direction_long | direction_short) & reaction & structure & participation
    elif confirmation_policy == "structure_plus_reaction":
        confirmation_valid = (direction_long | direction_short) & structure & reaction
    elif confirmation_policy == "structure_plus_participation":
        confirmation_valid = (direction_long | direction_short) & structure & participation
    elif confirmation_policy == "sequential_three":
        confirmation_valid = (direction_long | direction_short) & structure.shift(2).fillna(False) & reaction.shift(1).fillna(False) & participation
    elif confirmation_policy == "state_adaptive_two_of_three":
        high_volatility = atr.gt(atr.rolling(50, min_periods=5).median())
        required_families = pd.Series(np.where(high_volatility.fillna(False), 3, 2), index=out.index)
        confirmation_valid = (direction_long | direction_short) & independent_count.ge(required_families)
    else:
        confirmation_valid = (direction_long | direction_short) & independent_count.ge(minimum_independent)
    trigger_valid = (direction_long & trigger_long) | (direction_short & trigger_short)
    # Preserve the counterfactual trigger topology on the same frozen setup.
    # This is diagnostic evidence only: it does not change the selected mode
    # or authorize a trade, but lets evolution distinguish "no structure
    # event" from "structure existed but its retest never arrived".
    out["entry_reaction_family_valid"] = reaction
    out["entry_structure_family_valid"] = structure
    out["entry_participation_family_valid"] = participation
    out["entry_aggressive_trigger_valid"] = (direction_long & aggressive_long) | (direction_short & aggressive_short)
    out["entry_balanced_trigger_valid"] = (direction_long & balanced_long) | (direction_short & balanced_short)
    out["entry_conservative_trigger_valid"] = (direction_long & conservative_long) | (direction_short & conservative_short)

    invalidation_buffer = atr.fillna(0) * float(parameters.get("invalidation_buffer_atr", .05))
    trap_extreme = pd.to_numeric(out["m15_trap_extreme"], errors="coerce")
    long_invalidation = trap_extreme - invalidation_buffer
    short_invalidation = trap_extreme + invalidation_buffer
    if model == "breakout_retest":
        bull_break_extreme = low.where(bull_break).ffill()
        bear_break_extreme = high.where(bear_break).ffill()
        bull_retest_extreme = low.where(bull_break_reaction).ffill()
        bear_retest_extreme = high.where(bear_break_reaction).ffill()
        long_anchor = bull_retest_extreme.where(~selected_aggressive, bull_break_extreme)
        short_anchor = bear_retest_extreme.where(~selected_aggressive, bear_break_extreme)
        long_invalidation = np.minimum(long_anchor, bull_break_level) - invalidation_buffer
        short_invalidation = np.maximum(short_anchor, bear_break_level) + invalidation_buffer
    invalidation_price = np.where(direction_long, long_invalidation, np.where(direction_short, short_invalidation, np.nan))
    invalidation_price = pd.Series(invalidation_price, index=out.index, dtype="float64")
    invalidation_valid = (direction_long & invalidation_price.lt(close)) | (direction_short & invalidation_price.gt(close))

    long_target = h1_high
    short_target = h1_low
    if model == "breakout_retest":
        # Freeze the measured-move range at the breakout event. A later H1
        # context update must not move the target after the setup was declared.
        bull_break_range = breakout_range.where(bull_break).ffill()
        bear_break_range = breakout_range.where(bear_break).ffill()
        long_target = bull_break_level + bull_break_range
        short_target = bear_break_level - bear_break_range
    target_price = pd.Series(np.where(direction_long, long_target, np.where(direction_short, short_target, np.nan)), index=out.index, dtype="float64")
    risk_distance = (close - invalidation_price).where(direction_long, invalidation_price - close).clip(lower=0)
    reward_distance = (target_price - close).where(direction_long, close - target_price).clip(lower=0)
    reward_space_r = reward_distance / risk_distance.replace(0, np.nan)
    minimum_reward_r = float(parameters.get("minimum_reward_space_r", 1.5))
    reward_space_valid = reward_space_r.ge(minimum_reward_r)

    favorable_distance = (close - trigger_anchor_long).where(direction_long, trigger_anchor_short - close).clip(lower=0)
    chase_distance_atr = favorable_distance / atr
    chase_valid = chase_distance_atr.notna() & chase_distance_atr.le(float(parameters.get("max_chase_atr", 1.25)))
    news_veto = out.get("news_veto", pd.Series(False, index=out.index)).fillna(False).astype(bool)
    risk_veto = out.get("risk_veto", pd.Series(False, index=out.index)).fillna(False).astype(bool)
    event_valid = ~(news_veto | risk_veto)

    raw_confirmation_count = (
        reaction.astype(int) + structure.astype(int) + participation.astype(int)
        + ((direction_long & bull_rejection) | (direction_short & bear_rejection)).astype(int)
        + ((direction_long & bull_engulfing) | (direction_short & bear_engulfing)).astype(int)
        + ((direction_long & close.gt(open_)) | (direction_short & close.lt(open_))).astype(int)
    )
    redundancy_penalty = (raw_confirmation_count - independent_count).clip(lower=0)

    context_valid = context_long | context_short
    location_valid = (context_long & location_long) | (context_short & location_short)
    setup_detected = setup_long | setup_short
    score = (
        context_valid.astype(int) * 2 + location_valid.astype(int) * 2
        + reaction.astype(int) + structure.astype(int) * 2
        + participation.astype(int) + (bull_retest | bear_retest | bull_break_retest | bear_break_retest).astype(int) * 2
        + invalidation_valid.astype(int) + reward_space_valid.astype(int) * 2
        + trigger_valid.astype(int)
    ).clip(0, 14)
    grade = np.select([score.ge(12), score.ge(9), score.ge(7)], ["A+", "A", "B"], default="SKIP")

    entry_ready = (
        context_valid & location_valid & setup_detected & confirmation_valid & trigger_valid
        & invalidation_valid & reward_space_valid & chase_valid & event_valid
    )
    out.loc[entry_ready & direction_long, "signal"] = "BUY"
    out.loc[entry_ready & direction_short, "signal"] = "SELL"
    out["signal_confidence"] = (score / 14).clip(0, 1)
    trigger_anchor_price = pd.Series(
        np.where(direction_long, trigger_anchor_long, np.where(direction_short, trigger_anchor_short, np.nan)),
        index=out.index,
        dtype="float64",
    )
    out["entry_reference_price"] = close.where(setup_detected)
    out["entry_invalidation_reference_price"] = invalidation_price.where(setup_detected)
    out["trade_invalidation_price"] = np.where(entry_ready, invalidation_price, np.nan)
    out["entry_target_reference_price"] = target_price.where(setup_detected)
    out["entry_trigger_anchor_price"] = trigger_anchor_price.where(setup_detected)
    out["entry_structure_atr"] = atr.where(setup_detected)
    out["entry_reward_space_r"] = reward_space_r.replace([np.inf, -np.inf], np.nan)
    out["entry_chase_distance_atr"] = chase_distance_atr.replace([np.inf, -np.inf], np.nan)
    out["entry_context_valid"] = context_valid
    out["entry_location_valid"] = location_valid
    out["entry_setup_detected"] = setup_detected
    out["entry_confirmation_valid"] = confirmation_valid
    out["entry_trigger_valid"] = trigger_valid
    out["entry_invalidation_valid"] = invalidation_valid
    out["entry_reward_space_valid"] = reward_space_valid
    out["entry_chase_valid"] = chase_valid
    out["entry_event_valid"] = event_valid
    out["entry_independent_confirmation_count"] = independent_count
    out["entry_raw_confirmation_count"] = raw_confirmation_count
    out["entry_redundancy_penalty"] = redundancy_penalty
    out["entry_confirmation_families"] = _confirmation_family_labels(reaction, structure, participation)
    out["entry_score"] = score
    out["entry_grade"] = grade
    out["entry_contract_direction"] = np.select([direction_long, direction_short], ["BUY", "SELL"], default="WAIT")
    out["entry_contract_status"] = np.select(
        [
            ~context_valid, ~location_valid, ~setup_detected, ~confirmation_valid,
            ~trigger_valid, ~invalidation_valid, ~reward_space_valid, ~chase_valid, ~event_valid,
        ],
        [
            "context_not_ready", "outside_entry_location", "setup_missing", "confirmation_missing",
            "trigger_missing", "invalidation_invalid", "reward_space_insufficient", "chasing_veto", "event_veto",
        ],
        default="entry_ready",
    )
    out["entry_contract_stage"] = np.select(
        [
            ~context_valid, ~location_valid, ~setup_detected, ~confirmation_valid,
            ~trigger_valid, ~invalidation_valid, ~reward_space_valid | ~chase_valid | ~event_valid,
        ],
        ["context", "location", "setup", "confirmation", "trigger", "invalidation", "admission"],
        default="entry",
    )
    return out


def _execution_stream_valid(frame: pd.DataFrame) -> bool:
    required = {"time", "open", "high", "low", "close"}
    if frame.empty or not required.issubset(frame.columns):
        return False
    time = pd.to_datetime(frame["time"], utc=True, errors="coerce")
    open_ = pd.to_numeric(frame["open"], errors="coerce")
    high = pd.to_numeric(frame["high"], errors="coerce")
    low = pd.to_numeric(frame["low"], errors="coerce")
    close = pd.to_numeric(frame["close"], errors="coerce")
    finite = pd.Series(
        np.isfinite(open_) & np.isfinite(high) & np.isfinite(low) & np.isfinite(close),
        index=frame.index,
    )
    geometry = high.ge(low) & high.ge(open_) & high.ge(close) & low.le(open_) & low.le(close)
    volume_valid = pd.Series(True, index=frame.index)
    if "volume" in frame.columns:
        volume = pd.to_numeric(frame["volume"], errors="coerce")
        volume_valid = volume.isna() | (np.isfinite(volume) & volume.ge(0))

    return bool((time.notna() & finite & geometry & volume_valid).all())


def _model_context(
    model: str,
    ready: pd.Series,
    aligned: pd.Series,
    h4_direction: pd.Series,
    range_state: pd.Series,
) -> tuple[pd.Series, pd.Series]:
    if model == "range_sweep":
        return ready & range_state, ready & range_state
    if model == "htf_reversal":
        return ready & h4_direction.eq("bearish"), ready & h4_direction.eq("bullish")
    if model == "false_break_reversal":
        return ready, ready
    return ready & aligned & h4_direction.eq("bullish"), ready & aligned & h4_direction.eq("bearish")


def _model_location(
    model: str,
    in_poi: pd.Series,
    at_low: pd.Series,
    at_high: pd.Series,
    close: pd.Series,
    h1_high: pd.Series,
    h1_low: pd.Series,
) -> tuple[pd.Series, pd.Series]:
    if model == "breakout_retest":
        return close.ge(h1_high), close.le(h1_low)
    if model in {"range_sweep", "false_break_reversal", "htf_reversal"}:
        return at_low, at_high
    return in_poi, in_poi


def _model_setup(
    model: str,
    context_long: pd.Series,
    context_short: pd.Series,
    location_long: pd.Series,
    location_short: pd.Series,
    active_trap: pd.Series,
    trap_direction: pd.Series,
    active_bull_break: pd.Series,
    active_bear_break: pd.Series,
) -> tuple[pd.Series, pd.Series]:
    if model == "breakout_retest":
        return context_long & location_long & active_bull_break, context_short & location_short & active_bear_break
    return (
        context_long & location_long & active_trap & trap_direction.eq("bullish"),
        context_short & location_short & active_trap & trap_direction.eq("bearish"),
    )


def _active_event(
    decision_time: pd.Series,
    event: pd.Series,
    level: pd.Series,
    event_close: pd.Series,
    window: pd.Timedelta,
) -> tuple[pd.Series, pd.Series, pd.Series]:
    event_time = _event_time(decision_time, event)
    active = event_time.notna() & decision_time.ge(event_time) & (decision_time - event_time).le(window)
    return active, pd.to_numeric(level, errors="coerce").where(event).ffill(), event_close.where(event).ffill()


def _event_time(decision_time: pd.Series, event: pd.Series) -> pd.Series:
    return decision_time.where(event).ffill()


def _active_boolean_event(
    decision_time: pd.Series,
    event: pd.Series,
    window: pd.Timedelta,
) -> pd.Series:
    event_time = _event_time(decision_time, event)
    return event_time.notna() & decision_time.ge(event_time) & (decision_time - event_time).le(window)


def _fvg_retests(
    out: pd.DataFrame,
    decision_time: pd.Series,
    bull_event: pd.Series,
    bear_event: pd.Series,
    window: pd.Timedelta,
) -> tuple[pd.Series, pd.Series]:
    if "fvg" not in out or "fvg_midpoint" not in out:
        empty = pd.Series(False, index=out.index)
        return empty, empty.copy()
    bull_time = decision_time.where(bull_event & out["fvg"].eq("bullish")).ffill()
    bear_time = decision_time.where(bear_event & out["fvg"].eq("bearish")).ffill()
    bull_mid = pd.to_numeric(out["fvg_midpoint"], errors="coerce").where(bull_event & out["fvg"].eq("bullish")).ffill()
    bear_mid = pd.to_numeric(out["fvg_midpoint"], errors="coerce").where(bear_event & out["fvg"].eq("bearish")).ffill()
    bull_active = bull_time.notna() & decision_time.gt(bull_time) & (decision_time - bull_time).le(window)
    bear_active = bear_time.notna() & decision_time.gt(bear_time) & (decision_time - bear_time).le(window)
    return (
        bull_active & out["low"].le(bull_mid) & out["high"].ge(bull_mid),
        bear_active & out["low"].le(bear_mid) & out["high"].ge(bear_mid),
    )


def _breakout_participation(
    favorable: pd.Series,
    level: pd.Series,
    atr: pd.Series,
    event: pd.Series,
    active: pd.Series,
    parameters: dict[str, Any],
) -> pd.Series:
    minimum = float(parameters.get("breakout_minimum_expansion_atr", .35))
    displacement = (favorable - level).clip(lower=0) / atr
    event_value = displacement.where(event).ffill()
    return active & event_value.ge(minimum)


def _continuation_after_reaction(
    decision_time: pd.Series,
    reaction: pd.Series,
    reaction_level: pd.Series,
    close: pd.Series,
    window: pd.Timedelta,
    direction: str,
) -> pd.Series:
    event_time = decision_time.where(reaction).ffill()
    level = reaction_level.where(reaction).ffill()
    active = event_time.notna() & decision_time.gt(event_time) & (decision_time - event_time).le(window)
    return active & (close.gt(level) if direction == "bullish" else close.lt(level))


def _confirmation_family_labels(
    reaction: pd.Series,
    structure: pd.Series,
    participation: pd.Series,
) -> pd.Series:
    labels = []
    for reaction_value, structure_value, participation_value in zip(reaction, structure, participation):
        families = []
        if reaction_value:
            families.append("price_reaction")
        if structure_value:
            families.append("market_structure")
        if participation_value:
            families.append("volatility_participation")
        labels.append("|".join(families))
    return pd.Series(labels, index=reaction.index, dtype="object")


def _fail_closed(out: pd.DataFrame, reason: str) -> pd.DataFrame:
    false = pd.Series(False, index=out.index)
    out["entry_context_valid"] = false
    out["entry_location_valid"] = false
    out["entry_setup_detected"] = false
    out["entry_confirmation_valid"] = false
    out["entry_trigger_valid"] = false
    out["entry_invalidation_valid"] = false
    out["entry_reward_space_valid"] = false
    out["entry_chase_valid"] = false
    out["entry_event_valid"] = false
    out["entry_reaction_family_valid"] = false
    out["entry_structure_family_valid"] = false
    out["entry_participation_family_valid"] = false
    out["entry_aggressive_trigger_valid"] = false
    out["entry_balanced_trigger_valid"] = false
    out["entry_conservative_trigger_valid"] = false
    out["entry_independent_confirmation_count"] = 0
    out["entry_raw_confirmation_count"] = 0
    out["entry_redundancy_penalty"] = 0
    out["entry_confirmation_families"] = ""
    out["entry_reward_space_r"] = np.nan
    out["entry_chase_distance_atr"] = np.nan
    out["entry_reference_price"] = np.nan
    out["entry_invalidation_reference_price"] = np.nan
    out["entry_target_reference_price"] = np.nan
    out["entry_trigger_anchor_price"] = np.nan
    out["entry_structure_atr"] = np.nan
    out["trade_invalidation_price"] = np.nan
    out["entry_score"] = 0
    out["entry_grade"] = "SKIP"
    out["entry_order_type"] = "none"
    out["entry_contract_direction"] = "WAIT"
    out["entry_contract_status"] = reason
    out["entry_contract_stage"] = "context"
    return out
