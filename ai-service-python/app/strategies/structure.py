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
