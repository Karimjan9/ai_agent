"""Causal market-structure instruments used by the research playbooks.

Every value is calculated from the current and earlier candles only.  These
are proxies for structure and liquidity, not claims about an unseen order
book; callers can inspect the returned strength and failure-risk fields.
"""

from __future__ import annotations

import numpy as np
import pandas as pd


def apply_causal_feature_layer(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Canonical, causal value layer shared by structure playbooks.

    Every rolling value is shifted where it could otherwise inspect an open
    candle as history.  FVG/order-block/breaker are OHLC proxies, explicitly
    not claims about an unavailable institutional order book.
    """
    parameters = parameters or {}
    out = frame.copy()
    previous_close = out["close"].shift(1)
    tr = pd.concat([out["high"] - out["low"], (out["high"] - previous_close).abs(), (out["low"] - previous_close).abs()], axis=1).max(axis=1)
    atr_period = max(2, int(parameters.get("atr_period", 14)))
    out["atr"] = tr.rolling(atr_period, min_periods=2).mean().replace(0, np.nan)
    out["true_range"] = tr
    out["body"] = (out["close"] - out["open"]).abs()
    out["wick"] = (out["high"] - out["low"] - out["body"]).clip(lower=0)
    out["relative_volume"] = (out.get("volume", pd.Series(0, index=out.index)) / out.get("volume", pd.Series(0, index=out.index)).rolling(20, min_periods=2).mean().replace(0, np.nan)).fillna(1.0)

    up = out["high"].diff().clip(lower=0)
    down = (-out["low"].diff()).clip(lower=0)
    plus_di = 100 * up.rolling(atr_period, min_periods=2).mean() / out["atr"]
    minus_di = 100 * down.rolling(atr_period, min_periods=2).mean() / out["atr"]
    out["plus_di"] = plus_di.fillna(0)
    out["minus_di"] = minus_di.fillna(0)
    out["adx"] = (100 * (plus_di - minus_di).abs() / (plus_di + minus_di).replace(0, np.nan)).rolling(atr_period, min_periods=2).mean().fillna(0)
    basis = out["close"].rolling(20, min_periods=2).mean()
    deviation = out["close"].rolling(20, min_periods=2).std().replace(0, np.nan)
    upper, lower = basis + 2 * deviation, basis - 2 * deviation
    out["bollinger_width"] = ((upper - lower) / basis.abs().replace(0, np.nan)).fillna(0)
    out["bollinger_percent_b"] = ((out["close"] - lower) / (upper - lower).replace(0, np.nan)).clip(0, 1).fillna(.5)
    out["z_score"] = ((out["close"] - basis) / deviation).clip(-6, 6).fillna(0)
    out["macd"] = out["close"].ewm(span=12, adjust=False).mean() - out["close"].ewm(span=26, adjust=False).mean()
    out["slope"] = (out["close"] - out["close"].shift(10)) / (out["atr"] * 10)

    # The third candle is the first point at which a two-candle gap is known.
    out["fvg"] = np.select([out["low"] > out["high"].shift(2), out["high"] < out["low"].shift(2)], ["bullish", "bearish"], default="none")
    out["fvg_midpoint"] = np.where(out["fvg"] == "bullish", (out["low"] + out["high"].shift(2)) / 2, np.where(out["fvg"] == "bearish", (out["high"] + out["low"].shift(2)) / 2, np.nan))
    displacement = (out["body"] / out["atr"]).fillna(0)
    out["order_block"] = np.select([(out["close"] > out["open"]) & (displacement >= 1), (out["close"] < out["open"]) & (displacement >= 1)], ["bullish_proxy", "bearish_proxy"], default="none")
    out["breaker_block"] = np.select([(out["order_block"].shift(1) == "bullish_proxy") & (out["close"] < out["low"].shift(1)), (out["order_block"].shift(1) == "bearish_proxy") & (out["close"] > out["high"].shift(1))], ["bearish_proxy", "bullish_proxy"], default="none")

    times = pd.DatetimeIndex(pd.to_datetime(out.get("time", out.index), utc=True, errors="coerce"))
    session = np.where((times.hour >= 7) & (times.hour < 12), "london", np.where((times.hour >= 12) & (times.hour <= 16), "london_new_york_overlap", np.where((times.hour > 16) & (times.hour <= 21), "new_york", "asian")))
    out["session"] = session
    session_day = pd.Series(times.date, index=out.index).astype(str) + "|" + pd.Series(session, index=out.index)
    out["session_high"] = out["high"].groupby(session_day).cummax().shift(1)
    out["session_low"] = out["low"].groupby(session_day).cummin().shift(1)
    out["session_range"] = (out["session_high"] - out["session_low"]).fillna(0)
    out["feature_lookahead_safe"] = True
    return out


def apply_structure_instruments(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    parameters = parameters or {}
    out = apply_causal_feature_layer(frame, parameters)
    lookback = max(10, int(parameters.get("swing_lookback", parameters.get("lookback", 40))))
    atr_period = max(2, int(parameters.get("atr_period", 14)))
    equal_atr = max(.02, float(parameters.get("equal_level_atr_fraction", .15)))

    previous_close = out["close"].shift(1)
    true_range = pd.concat([
        out["high"] - out["low"], (out["high"] - previous_close).abs(), (out["low"] - previous_close).abs(),
    ], axis=1).max(axis=1)
    out["structure_atr"] = true_range.rolling(atr_period, min_periods=2).mean().replace(0, np.nan)
    out["confirmed_swing_high"] = out["high"].rolling(lookback, min_periods=lookback).max().shift(1)
    out["confirmed_swing_low"] = out["low"].rolling(lookback, min_periods=lookback).min().shift(1)
    out["swing_range"] = out["confirmed_swing_high"] - out["confirmed_swing_low"]

    up_bos = out["close"] > out["confirmed_swing_high"]
    down_bos = out["close"] < out["confirmed_swing_low"]
    out["bos_event"] = np.select([up_bos, down_bos], ["bullish", "bearish"], default="none")
    level = np.where(up_bos, out["confirmed_swing_high"], out["confirmed_swing_low"])
    out["break_displacement_atr"] = ((out["close"] - level).abs() / out["structure_atr"]).replace([np.inf, -np.inf], np.nan).fillna(0.0)
    out["bos_strength"] = out["break_displacement_atr"].clip(0, 3) / 3

    fast = out["close"].ewm(span=min(lookback, 34), adjust=False).mean()
    slow = out["close"].ewm(span=min(max(lookback, 35), 89), adjust=False).mean()
    prior_direction = np.where(fast.shift(1) >= slow.shift(1), "bullish", "bearish")
    out["choch_event"] = np.select(
        [(prior_direction == "bullish") & down_bos, (prior_direction == "bearish") & up_bos],
        ["bearish", "bullish"], default="none",
    )
    out["transition_confidence"] = np.where(out["choch_event"] != "none", out["bos_strength"], 0.0)

    tolerance = out["structure_atr"].fillna(0) * equal_atr
    prior_high = out["confirmed_swing_high"]
    prior_low = out["confirmed_swing_low"]
    out["equal_high_proxy"] = ((out["high"] - prior_high).abs() <= tolerance).astype(float)
    out["equal_low_proxy"] = ((out["low"] - prior_low).abs() <= tolerance).astype(float)
    out["liquidity_pool_score"] = (out["equal_high_proxy"] + out["equal_low_proxy"]).clip(0, 1)
    sweep_high = (out["high"] > prior_high) & (out["close"] < prior_high)
    sweep_low = (out["low"] < prior_low) & (out["close"] > prior_low)
    out["liquidity_sweep"] = np.select([sweep_low, sweep_high], ["bullish", "bearish"], default="none")
    out["liquidity_score"] = np.where(out["liquidity_sweep"] != "none", 1.0, out["liquidity_pool_score"])
    out["false_break_probability"] = (1 - out["bos_strength"]).clip(0, 1)

    direction = np.where(fast >= slow, "bullish", "bearish")
    out["structure_direction"] = direction
    out["dynamic_fib_382"] = np.where(direction == "bullish", prior_high - out["swing_range"] * .382, prior_low + out["swing_range"] * .382)
    out["dynamic_fib_618"] = np.where(direction == "bullish", prior_high - out["swing_range"] * .618, prior_low + out["swing_range"] * .618)
    out["dynamic_fib_zone_low"] = pd.concat([out["dynamic_fib_382"], out["dynamic_fib_618"]], axis=1).min(axis=1)
    out["dynamic_fib_zone_high"] = pd.concat([out["dynamic_fib_382"], out["dynamic_fib_618"]], axis=1).max(axis=1)
    out["distance_to_fib_atr"] = ((out["close"] - out["dynamic_fib_zone_low"]).clip(lower=0).where(out["close"] < out["dynamic_fib_zone_low"], (out["close"] - out["dynamic_fib_zone_high"]).clip(lower=0)) / out["structure_atr"]).fillna(99.0)
    out["support_resistance_strength"] = ((out["liquidity_pool_score"] + out["bos_strength"]) / 2).clip(0, 1)

    return out


def apply_fibonacci_structure_pullback_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    out = apply_structure_instruments(frame, parameters)
    out["signal"] = "WAIT"
    in_zone = (out["close"] >= out["dynamic_fib_zone_low"]) & (out["close"] <= out["dynamic_fib_zone_high"])
    bullish = (out["structure_direction"] == "bullish") & (out["liquidity_sweep"] == "bullish") & (out["close"] > out["open"])
    bearish = (out["structure_direction"] == "bearish") & (out["liquidity_sweep"] == "bearish") & (out["close"] < out["open"])
    out.loc[in_zone & bullish, "signal"] = "BUY"
    out.loc[in_zone & bearish, "signal"] = "SELL"
    out["signal_confidence"] = (out["liquidity_score"] * .45 + out["support_resistance_strength"] * .35 + (1 - out["false_break_probability"]) * .2).clip(0, 1)
    return out


def apply_bos_retest_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    out = apply_structure_instruments(frame, parameters)
    out["signal"] = "WAIT"
    direction = out["bos_event"].replace("none", np.nan).ffill()
    level = np.where(direction == "bullish", out["confirmed_swing_high"], out["confirmed_swing_low"])
    retest = (out["close"] - level).astype(float).abs() <= out["structure_atr"] * float((parameters or {}).get("retest_atr_fraction", .35))
    confirmed = out["bos_strength"] >= float((parameters or {}).get("minimum_displacement_atr", .5)) / 3
    out.loc[(direction == "bullish") & retest & confirmed & (out["close"] > out["open"]), "signal"] = "BUY"
    out.loc[(direction == "bearish") & retest & confirmed & (out["close"] < out["open"]), "signal"] = "SELL"
    out["retest_quality"] = (1 - ((out["close"] - level).astype(float).abs() / (out["structure_atr"] + 1e-12))).clip(0, 1)
    out["signal_confidence"] = (out["bos_strength"] * .6 + out["retest_quality"] * .4).clip(0, 1)
    return out


def apply_choch_reversal_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    out = apply_structure_instruments(frame, parameters)
    out["signal"] = "WAIT"
    threshold = float((parameters or {}).get("transition_confidence_min", .35))
    out.loc[(out["choch_event"] == "bullish") & (out["liquidity_sweep"] == "bullish") & (out["transition_confidence"] >= threshold), "signal"] = "BUY"
    out.loc[(out["choch_event"] == "bearish") & (out["liquidity_sweep"] == "bearish") & (out["transition_confidence"] >= threshold), "signal"] = "SELL"
    out["signal_confidence"] = (out["transition_confidence"] * .7 + out["liquidity_score"] * .3).clip(0, 1)
    return out


def apply_liquidity_sweep_reversion_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    out = apply_structure_instruments(frame, parameters)
    out["signal"] = "WAIT"
    min_strength = float((parameters or {}).get("zone_strength_min", .35))
    out.loc[(out["liquidity_sweep"] == "bullish") & (out["support_resistance_strength"] >= min_strength) & (out["close"] > out["open"]), "signal"] = "BUY"
    out.loc[(out["liquidity_sweep"] == "bearish") & (out["support_resistance_strength"] >= min_strength) & (out["close"] < out["open"]), "signal"] = "SELL"
    out["signal_confidence"] = (out["liquidity_score"] * .55 + out["support_resistance_strength"] * .45).clip(0, 1)
    return out


def apply_liquidity_trap_mtf_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """H4/H1/M15 context with an M5 trigger, fail-closed when context is absent.

    The higher-timeframe columns are supplied by ``closed_mtf_context``. This
    function never rebuilds them from the M5 stream, so a caller cannot
    accidentally turn a lower-timeframe candle into H1/H4 hindsight.
    """
    parameters = parameters or {}
    out = apply_structure_instruments(frame, parameters)
    out["signal"] = "WAIT"
    out["signal_confidence"] = 0.0
    required = {
        "mtf_stack_status", "h4_structure_direction", "h1_structure_direction",
        "h1_dynamic_fib_zone_low", "h1_dynamic_fib_zone_high",
        "m15_trap_direction", "m15_trap_available_at", "m15_trap_extreme",
    }
    if not required.issubset(out.columns):
        out["liquidity_trap_status"] = "missing_closed_mtf_context"
        return out

    direction = out["h4_structure_direction"]
    h1_aligned = out["h1_structure_direction"].eq(direction)
    context_ready = out["mtf_stack_status"].eq("ready") & direction.isin(["bullish", "bearish"])
    in_h1_poi = (
        (out["low"] <= out["h1_dynamic_fib_zone_high"])
        & (out["high"] >= out["h1_dynamic_fib_zone_low"])
    )
    decision_time = pd.to_datetime(out.get("decision_at", out["time"]), utc=True)
    trap_age = decision_time - pd.to_datetime(out["m15_trap_available_at"], utc=True)
    trap_expiry = pd.Timedelta(minutes=max(5, int(parameters.get("m15_trap_expiry_minutes", 30))))
    active_trap = trap_age.ge(pd.Timedelta(0)) & trap_age.le(trap_expiry)
    minimum_displacement = float(parameters.get("m5_minimum_displacement_atr", .5))
    m5_bull_trigger = (
        out["choch_event"].eq("bullish")
        & out["break_displacement_atr"].ge(minimum_displacement)
        & out["m15_trap_direction"].eq("bullish")
    )
    m5_bear_trigger = (
        out["choch_event"].eq("bearish")
        & out["break_displacement_atr"].ge(minimum_displacement)
        & out["m15_trap_direction"].eq("bearish")
    )

    # A balanced entry waits for a later FVG-midpoint retest, rather than
    # treating the displacement candle itself as both trigger and pullback.
    time = pd.to_datetime(out["time"], utc=True)
    bull_trigger_time = time.where(m5_bull_trigger & out["fvg"].eq("bullish")).ffill()
    bear_trigger_time = time.where(m5_bear_trigger & out["fvg"].eq("bearish")).ffill()
    bull_fvg_midpoint = out["fvg_midpoint"].where(m5_bull_trigger & out["fvg"].eq("bullish")).ffill()
    bear_fvg_midpoint = out["fvg_midpoint"].where(m5_bear_trigger & out["fvg"].eq("bearish")).ffill()
    retest_window = pd.Timedelta(minutes=max(5, int(parameters.get("m5_retest_expiry_minutes", 20))))
    bull_retest = (
        bull_trigger_time.notna() & (time > bull_trigger_time) & ((time - bull_trigger_time) <= retest_window)
        & (out["low"] <= bull_fvg_midpoint) & (out["high"] >= bull_fvg_midpoint)
    )
    bear_retest = (
        bear_trigger_time.notna() & (time > bear_trigger_time) & ((time - bear_trigger_time) <= retest_window)
        & (out["low"] <= bear_fvg_midpoint) & (out["high"] >= bear_fvg_midpoint)
    )

    mode = str(parameters.get("entry_mode", "balanced"))
    if mode == "aggressive":
        bullish_entry, bearish_entry = m5_bull_trigger, m5_bear_trigger
    elif mode == "conservative":
        bullish_entry = bull_retest & out["bos_event"].eq("bullish")
        bearish_entry = bear_retest & out["bos_event"].eq("bearish")
    else:
        bullish_entry, bearish_entry = bull_retest, bear_retest

    long_conditions = context_ready & h1_aligned & direction.eq("bullish") & in_h1_poi & active_trap & bullish_entry
    short_conditions = context_ready & h1_aligned & direction.eq("bearish") & in_h1_poi & active_trap & bearish_entry
    out.loc[long_conditions, "signal"] = "BUY"
    out.loc[short_conditions, "signal"] = "SELL"
    out["trade_invalidation_price"] = np.where(
        out["signal"].eq("BUY"), out["m15_trap_extreme"],
        np.where(out["signal"].eq("SELL"), out["m15_trap_extreme"], np.nan),
    )
    out["liquidity_trap_status"] = np.select(
        [
            ~context_ready, ~h1_aligned, ~in_h1_poi, ~active_trap,
            ~(m5_bull_trigger | m5_bear_trigger | bull_retest | bear_retest),
        ],
        ["context_not_ready", "h4_h1_conflict", "outside_h1_poi", "m15_trap_expired", "m5_confirmation_missing"],
        default="entry_ready",
    )
    score = (
        context_ready.astype(float) * .20 + h1_aligned.astype(float) * .15
        + in_h1_poi.astype(float) * .15 + active_trap.astype(float) * .20
        + (m5_bull_trigger | m5_bear_trigger).astype(float) * .15
        + (bull_retest | bear_retest).astype(float) * .15
    )
    out["signal_confidence"] = score.clip(0, 1)
    return out


def apply_mtf_research_control_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """One fixed M5-only control used for every catalogue comparison.

    It receives the same sealed multi-timeframe bundle and execution contract
    as a candidate, but it deliberately ignores the higher-timeframe context.
    The distinction is therefore one declared entry topology, not hidden cost
    or data changes.
    """
    parameters = parameters or {}
    out = apply_structure_instruments(frame, parameters)
    minimum = float(parameters.get("m5_minimum_displacement_atr", .5))
    bullish = out["choch_event"].eq("bullish") & out["break_displacement_atr"].ge(minimum)
    bearish = out["choch_event"].eq("bearish") & out["break_displacement_atr"].ge(minimum)
    out["signal"] = "WAIT"
    out.loc[bullish, "signal"] = "BUY"
    out.loc[bearish, "signal"] = "SELL"
    out["signal_confidence"] = (out["transition_confidence"] * .7 + out["bos_strength"] * .3).clip(0, 1)
    out["research_playbook_status"] = "frozen_m5_control"
    return out


def apply_mtf_research_playbook_strategy(frame: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Causal executable proxies for the catalogue's non-Liquidity-Trap models.

    Each branch remains one named hypothesis.  Practitioner labels are never
    treated as order-book facts: every condition is a closed-candle OHLCV or
    immutable related-market proxy and missing required context is a WAIT.
    """
    parameters = parameters or {}
    out = apply_structure_instruments(frame, parameters)
    out["signal"] = "WAIT"
    out["signal_confidence"] = 0.0
    model = str(parameters.get("research_model_id", ""))
    required = {
        "mtf_stack_status", "h4_structure_direction", "h1_structure_direction",
        "h1_dynamic_fib_zone_low", "h1_dynamic_fib_zone_high",
        "m15_trap_direction", "m15_trap_available_at", "m15_trap_extreme",
    }
    if not required.issubset(out.columns):
        out["research_playbook_status"] = "missing_closed_mtf_context"
        return out

    decision_time = pd.to_datetime(out.get("decision_at", out["time"]), utc=True)
    direction = out["h4_structure_direction"]
    h1_direction = out["h1_structure_direction"]
    htf_aligned = direction.isin(["bullish", "bearish"]) & h1_direction.eq(direction)
    context_ready = out["mtf_stack_status"].eq("ready")
    in_h1_poi = (
        out["low"].le(out["h1_dynamic_fib_zone_high"])
        & out["high"].ge(out["h1_dynamic_fib_zone_low"])
    )
    trap_age = decision_time - pd.to_datetime(out["m15_trap_available_at"], utc=True)
    trap_expiry = pd.Timedelta(minutes=max(5, int(parameters.get("m15_trap_expiry_minutes", 30))))
    active_trap = trap_age.ge(pd.Timedelta(0)) & trap_age.le(trap_expiry)
    minimum = float(parameters.get("m5_minimum_displacement_atr", .5))
    m5_bull_trigger = (
        out["choch_event"].eq("bullish")
        & out["break_displacement_atr"].ge(minimum)
        & out["m15_trap_direction"].eq("bullish")
    )
    m5_bear_trigger = (
        out["choch_event"].eq("bearish")
        & out["break_displacement_atr"].ge(minimum)
        & out["m15_trap_direction"].eq("bearish")
    )
    bull_retest, bear_retest = _research_fvg_retests(out, decision_time, m5_bull_trigger, m5_bear_trigger, parameters)
    common_long = context_ready & htf_aligned & direction.eq("bullish") & active_trap & m5_bull_trigger
    common_short = context_ready & htf_aligned & direction.eq("bearish") & active_trap & m5_bear_trigger
    retest_long, retest_short = common_long & bull_retest, common_short & bear_retest
    hours = decision_time.dt.hour
    session_start = int(parameters.get("session_start_utc", 7))
    session_end = int(parameters.get("session_end_utc", 16))
    session_window = _utc_hour_window(hours, session_start, session_end)
    opening_long, opening_short, vwap_long, vwap_short = _research_opening_range_masks(out, decision_time, parameters)

    long_conditions = pd.Series(False, index=out.index)
    short_conditions = pd.Series(False, index=out.index)
    status = "unknown_research_model"
    if model == "ict_2022_raid_mss_fvg":
        d1_direction = out.get("d1_structure_direction", pd.Series("unknown", index=out.index))
        d1_ready = out.get("d1_available_at", pd.Series(pd.NaT, index=out.index)).notna()
        d1_aligned = d1_direction.eq(direction)
        long_conditions, short_conditions = retest_long & d1_ready & d1_aligned, retest_short & d1_ready & d1_aligned
        status = "d1_h4_raid_mss_fvg"
    elif model == "po3_amd_session":
        long_conditions, short_conditions = retest_long & session_window, retest_short & session_window
        status = "session_accumulation_manipulation_distribution"
    elif model == "london_judas_swing":
        london = _utc_hour_window(hours, 7, 12)
        long_conditions, short_conditions = retest_long & london, retest_short & london
        status = "london_asia_range_sweep"
    elif model == "turtle_soup_mtf":
        long_conditions, short_conditions = retest_long & in_h1_poi, retest_short & in_h1_poi
        status = "major_level_false_break_reclaim"
    elif model == "silver_bullet_window":
        long_conditions, short_conditions = retest_long & session_window, retest_short & session_window
        status = "declared_time_window"
    elif model == "smt_sweep_mss":
        related = out.get("related_m15_trap_direction", pd.Series(pd.NA, index=out.index, dtype="string"))
        related_ready = out.get("related_m15_available_at", pd.Series(pd.NaT, index=out.index)).notna()
        # SMT is a divergence proxy: the primary market raids while the
        # independently frozen related market does not raid the same side.
        long_conditions = retest_long & related_ready & related.ne("bullish")
        short_conditions = retest_short & related_ready & related.ne("bearish")
        status = "related_market_divergence" if bool(related_ready.any()) else "related_market_missing"
    elif model == "wyckoff_spring_utad":
        h1_range = (out["h1_confirmed_swing_high"] - out["h1_confirmed_swing_low"]).gt(0)
        long_conditions, short_conditions = retest_long & h1_range, retest_short & h1_range
        status = "range_phase_spring_utad_proxy"
    elif model == "elder_triple_screen_liquidity":
        correction = h1_direction.ne(direction) & h1_direction.isin(["bullish", "bearish"])
        long_conditions = context_ready & direction.eq("bullish") & correction & active_trap & bull_retest
        short_conditions = context_ready & direction.eq("bearish") & correction & active_trap & bear_retest
        status = "h4_trend_h1_correction_m5_resumption"
    elif model == "orb_htf_bias":
        long_conditions = context_ready & htf_aligned & direction.eq("bullish") & opening_long
        short_conditions = context_ready & htf_aligned & direction.eq("bearish") & opening_short
        status = "opening_range_htf_bias"
    elif model == "orb_vwap_reclaim":
        long_conditions, short_conditions = vwap_long, vwap_short
        status = "opening_range_vwap_reclaim"
    elif model == "adaptive_timeframe_confirmation":
        # Alignment permits the M5 trigger; disagreement demands the more
        # selective M15-trap plus M5-retest path before either direction acts.
        conflict = context_ready & direction.isin(["bullish", "bearish"]) & h1_direction.ne(direction)
        long_conditions = (common_long & htf_aligned) | (conflict & direction.eq("bullish") & bull_retest)
        short_conditions = (common_short & htf_aligned) | (conflict & direction.eq("bearish") & bear_retest)
        status = "adaptive_confirmation_depth"

    out.loc[long_conditions, "signal"] = "BUY"
    out.loc[short_conditions, "signal"] = "SELL"
    out["trade_invalidation_price"] = np.where(
        out["signal"].eq("BUY"), out["m15_trap_extreme"],
        np.where(out["signal"].eq("SELL"), out["m15_trap_extreme"], np.nan),
    )
    evidence = (
        context_ready.astype(float) * .20 + htf_aligned.astype(float) * .15
        + in_h1_poi.astype(float) * .10 + active_trap.astype(float) * .20
        + (m5_bull_trigger | m5_bear_trigger).astype(float) * .20
        + (bull_retest | bear_retest).astype(float) * .15
    )
    out["signal_confidence"] = evidence.clip(0, 1)
    out["research_playbook_status"] = status
    return out


def _research_fvg_retests(
    out: pd.DataFrame,
    decision_time: pd.Series,
    bull_trigger: pd.Series,
    bear_trigger: pd.Series,
    parameters: dict,
) -> tuple[pd.Series, pd.Series]:
    bull_time = decision_time.where(bull_trigger & out["fvg"].eq("bullish")).ffill()
    bear_time = decision_time.where(bear_trigger & out["fvg"].eq("bearish")).ffill()
    bull_mid = out["fvg_midpoint"].where(bull_trigger & out["fvg"].eq("bullish")).ffill()
    bear_mid = out["fvg_midpoint"].where(bear_trigger & out["fvg"].eq("bearish")).ffill()
    window = pd.Timedelta(minutes=max(5, int(parameters.get("m5_retest_expiry_minutes", 20))))
    bull = bull_time.notna() & decision_time.gt(bull_time) & (decision_time - bull_time).le(window)
    bear = bear_time.notna() & decision_time.gt(bear_time) & (decision_time - bear_time).le(window)
    return (bull & out["low"].le(bull_mid) & out["high"].ge(bull_mid),
            bear & out["low"].le(bear_mid) & out["high"].ge(bear_mid))


def _utc_hour_window(hours: pd.Series, start: int, end: int) -> pd.Series:
    if start < end:
        return hours.ge(start) & hours.lt(end)
    return hours.ge(start) | hours.lt(end)


def _research_opening_range_masks(
    out: pd.DataFrame,
    decision_time: pd.Series,
    parameters: dict,
) -> tuple[pd.Series, pd.Series, pd.Series, pd.Series]:
    """Build a fixed UTC opening range and session VWAP without future bars."""
    start = int(parameters.get("session_start_utc", 7))
    duration = int(parameters.get("opening_range_minutes", 60))
    end = min(24, start + duration / 60)
    day = decision_time.dt.floor("D")
    hour = decision_time.dt.hour + decision_time.dt.minute / 60
    in_range = hour.ge(start) & hour.lt(end)
    range_high = out["high"].where(in_range).groupby(day).cummax().groupby(day).ffill()
    range_low = out["low"].where(in_range).groupby(day).cummin().groupby(day).ffill()
    complete = hour.ge(end) & range_high.notna() & range_low.notna()
    breakout_long = complete & out["close"].gt(range_high) & out["close"].shift(1).le(range_high)
    breakout_short = complete & out["close"].lt(range_low) & out["close"].shift(1).ge(range_low)
    session = _utc_hour_window(decision_time.dt.hour, start, int(parameters.get("session_end_utc", 16)))
    volume = pd.to_numeric(out.get("volume", 0), errors="coerce").fillna(0.0)
    weighted = ((out["high"] + out["low"] + out["close"]) / 3 * volume).where(session, 0.0)
    cumulative_volume = volume.where(session, 0.0).groupby(day).cumsum()
    vwap = weighted.groupby(day).cumsum() / cumulative_volume.replace(0, np.nan)
    vwap_long = complete & out["close"].gt(vwap) & out["close"].shift(1).le(vwap.shift(1)) & out["low"].le(range_low)
    vwap_short = complete & out["close"].lt(vwap) & out["close"].shift(1).ge(vwap.shift(1)) & out["high"].ge(range_high)
    return breakout_long, breakout_short, vwap_long, vwap_short
