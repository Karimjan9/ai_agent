import pandas as pd


def apply_trend_specialist(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Trend pullback continuation with a strength and re-entry filter."""
    p = parameters or {}
    out = df.copy()
    fast, slow = int(p.get("ema_fast", 50)), int(p.get("ema_slow", 200))
    rsi_period = int(p.get("rsi_period", 14))
    strength = float(p.get("trend_strength_min", 20))
    pullback = float(p.get("pullback_atr_fraction", 0.75))
    out["trend_fast"] = out.close.ewm(span=fast, adjust=False).mean()
    out["trend_slow"] = out.close.ewm(span=slow, adjust=False).mean()
    out["trend_rsi"] = _rsi(out.close, rsi_period)
    atr = out.get("atr_regime", (out.high - out.low).rolling(14).mean())
    # A completed-candle pullback: price was near/beyond fast EMA, then closed
    # back with the long-term trend. It avoids chasing extension candles.
    near_fast = (out.close - out.trend_fast).abs() <= atr * pullback
    out["signal"] = "WAIT"
    buy = (out.trend_fast > out.trend_slow) & (out.get("adx", 0) >= strength) & near_fast & (out.trend_rsi > 50)
    sell = (out.trend_fast < out.trend_slow) & (out.get("adx", 0) >= strength) & near_fast & (out.trend_rsi < 50)
    out.loc[buy, "signal"] = "BUY"
    out.loc[sell, "signal"] = "SELL"
    out["signal_confidence"] = ((out.get("adx", 0) - strength) / max(1, 50 - strength)).clip(0, 1)
    return out


def apply_trend_retest_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Breakout-retest trend continuation, distinct from EMA pullback logic."""
    p = parameters or {}
    out = df.copy()
    lookback = int(p.get("lookback", 20))
    strength = float(p.get("trend_strength_min", 20))
    atr = out.get("atr_regime", (out.high - out.low).rolling(14).mean()).replace(0, pd.NA)
    fast = out.close.ewm(span=int(p.get("ema_fast", 50)), adjust=False).mean()
    slow = out.close.ewm(span=int(p.get("ema_slow", 200)), adjust=False).mean()
    prior_high = out.high.rolling(lookback).max().shift(1)
    prior_low = out.low.rolling(lookback).min().shift(1)
    # The prior candle must break a level; the current candle re-tests it and
    # closes back in the directional trend.  This prevents chasing the initial spike.
    bullish_retest = (out.close.shift(1) > prior_high.shift(1)) & (out.low <= prior_high) & (out.close > prior_high)
    bearish_retest = (out.close.shift(1) < prior_low.shift(1)) & (out.high >= prior_low) & (out.close < prior_low)
    out["signal"] = "WAIT"
    out.loc[(fast > slow) & (out.get("adx", 0) >= strength) & bullish_retest, "signal"] = "BUY"
    out.loc[(fast < slow) & (out.get("adx", 0) >= strength) & bearish_retest, "signal"] = "SELL"
    distance = ((out.close - prior_high).abs().where(out.close >= prior_high, (out.close - prior_low).abs()) / atr).fillna(2)
    out["signal_confidence"] = ((out.get("adx", 0) - strength) / max(1, 50 - strength) * (1 - distance.clip(0, 1))).clip(0, 1)
    return out


def apply_volatility_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    period, lookback = int(p.get("atr_period", 14)), int(p.get("lookback", 20))
    threshold = float(p.get("atr_threshold", 1.2))
    compression_ratio = float(p.get("compression_ratio", 0.75))
    expansion_multiplier = float(p.get("expansion_multiplier", 1.2))
    breakout_confirmation = bool(p.get("breakout_confirmation", False))
    tr = pd.concat([(out.high - out.low), (out.high - out.close.shift()).abs(), (out.low - out.close.shift()).abs()], axis=1).max(axis=1)
    atr = tr.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
    baseline = atr.rolling(lookback).mean()
    out["signal"] = "WAIT"
    compressed = atr.shift(1) <= baseline.shift(1) * compression_ratio
    active = compressed & (atr >= baseline * max(threshold, expansion_multiplier))
    if breakout_confirmation:
        # The expansion candle must also clear the prior Donchian boundary.
        # This is the classic squeeze-to-breakout variant and avoids treating
        # every large two-sided candle as a directional signal.
        prior_high = out.high.rolling(lookback).max().shift(1)
        prior_low = out.low.rolling(lookback).min().shift(1)
        buy = active & (out.close > out.open) & (out.close > prior_high)
        sell = active & (out.close < out.open) & (out.close < prior_low)
    else:
        buy = active & (out.close > out.open)
        sell = active & (out.close < out.open)
    out.loc[buy, "signal"] = "BUY"
    out.loc[sell, "signal"] = "SELL"
    out["signal_confidence"] = ((atr / baseline.replace(0, pd.NA)) / max(threshold, expansion_multiplier) - 1).clip(0, 1).fillna(0)
    return out


def apply_volatility_breakout_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """ATR squeeze/expansion with an explicit prior-range breakout."""
    return apply_volatility_strategy(df, {**(parameters or {}), "breakout_confirmation": True})


def apply_mean_reversion_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    lookback, deviation = int(p.get("lookback", 20)), float(p.get("deviation", 2.0))
    rsi_period = int(p.get("rsi_period", 14))
    mean, std = out.close.rolling(lookback).mean(), out.close.rolling(lookback).std()
    out["signal"] = "WAIT"
    adx_max = float(p.get("adx_max", 20))
    low_volatility_only = bool(p.get("low_volatility_only", True))
    rsi_confirmation = bool(p.get("rsi_confirmation", False))
    range_filter = out.get("adx", pd.Series(0, index=out.index)) <= adx_max
    if low_volatility_only:
        range_filter &= out.get("volatility_regime", pd.Series("normal_volatility", index=out.index)) == "low_volatility"
    buy = range_filter & (out.close < mean - std * deviation)
    sell = range_filter & (out.close > mean + std * deviation)
    if rsi_confirmation:
        rsi = _rsi(out.close, rsi_period)
        buy &= rsi <= float(p.get("rsi_oversold", 35))
        sell &= rsi >= float(p.get("rsi_overbought", 65))
    out.loc[buy, "signal"] = "BUY"
    out.loc[sell, "signal"] = "SELL"
    out["signal_confidence"] = ((out.close - mean).abs() / (std.replace(0, pd.NA) * max(deviation, .01)) - 1).clip(0, 1).fillna(0)
    return out


def apply_mean_reversion_rsi_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Bollinger/z-score re-entry with a bounded RSI exhaustion confirmation."""
    return apply_mean_reversion_strategy(df, {**(parameters or {}), "rsi_confirmation": True})


def apply_range_reentry_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Range specialist with an explicit, sealed signal topology.

    ``reentry``/``mean_reversion`` are contrarian hypotheses.  A range label
    can also contain failed mean-reversion moves, so ``inverse_extreme`` is a
    separately scored continuation hypothesis.  It is never silently mixed
    into the parent; the router changes only the declared range lane.
    """
    p = parameters or {}
    lookback = int(p.get("range_lookback", p.get("lookback", 20)))
    deviation = float(p.get("range_deviation", p.get("deviation", 2.0)))
    adx_max = float(p.get("range_adx_max", p.get("adx_max", 20)))
    low_volatility_only = bool(p.get("range_low_volatility_only", p.get("low_volatility_only", False)))
    reentry_required = bool(p.get("range_reentry_required", True))
    signal_mode = str(p.get("range_signal_mode", "reentry"))
    mean = df.close.rolling(lookback).mean()
    std = df.close.rolling(lookback).std().replace(0, pd.NA)
    lower, upper = mean - std * deviation, mean + std * deviation
    range_filter = df.get("adx", pd.Series(0, index=df.index)) <= adx_max
    if low_volatility_only:
        range_filter &= df.get("volatility_regime", pd.Series("normal_volatility", index=df.index)) == "low_volatility"
    if signal_mode == "inverse_extreme":
        # A failed fade is treated as continuation only inside the target
        # range lane.  This is a falsifiable specialist hypothesis, not a
        # global direction flip.
        buy = df.close > upper
        sell = df.close < lower
    elif signal_mode == "mid_cross":
        buy = (df.close.shift(1) < mean.shift(1)) & (df.close >= mean)
        sell = (df.close.shift(1) > mean.shift(1)) & (df.close <= mean)
    elif signal_mode == "reentry" and reentry_required:
        buy = (df.close.shift(1) < lower.shift(1)) & (df.close >= lower) & (df.close > df.close.shift(1))
        sell = (df.close.shift(1) > upper.shift(1)) & (df.close <= upper) & (df.close < df.close.shift(1))
    else:
        buy = df.close < lower
        sell = df.close > upper
    out = df.copy()
    out["signal"] = "WAIT"
    out.loc[range_filter & buy, "signal"] = "BUY"
    out.loc[range_filter & sell, "signal"] = "SELL"
    distance = ((df.close - mean).abs() / (std * max(deviation, .01))).clip(0, 2).fillna(0)
    out["signal_confidence"] = ((distance - 1) / 1).clip(0, 1).fillna(0)
    return out


def _rsi(close: pd.Series, period: int) -> pd.Series:
    delta = close.diff()
    gain, loss = delta.clip(lower=0), -delta.clip(upper=0)
    rs = gain.ewm(alpha=1 / period, min_periods=period, adjust=False).mean() / loss.ewm(alpha=1 / period, min_periods=period, adjust=False).mean().replace(0, pd.NA)
    return 100 - (100 / (1 + rs))


def apply_session_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    start, end, lookback = int(p.get("session_start", 7)), int(p.get("session_end", 16)), int(p.get("lookback", 20))
    high, low = out.high.rolling(lookback).max().shift(), out.low.rolling(lookback).min().shift()
    active = pd.to_datetime(out.time, utc=True, errors="coerce").dt.hour.between(start, end - 1)
    out["signal"] = "WAIT"
    out.loc[active & (out.close > high), "signal"] = "BUY"
    out.loc[active & (out.close < low), "signal"] = "SELL"
    spread = (high - low).replace(0, pd.NA)
    out["signal_confidence"] = ((out.close - low) / spread).clip(0, 1).fillna(0)
    out.loc[out["signal"] == "SELL", "signal_confidence"] = ((high - out.close) / spread).clip(0, 1).fillna(0)
    return out


def apply_session_mean_reversion_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Session-only range re-entry; no signal is emitted outside the session."""
    p = parameters or {}
    start, end, lookback = int(p.get("session_start", 7)), int(p.get("session_end", 16)), int(p.get("lookback", 20))
    active = pd.to_datetime(df.time, utc=True, errors="coerce").dt.hour.between(start, end - 1)
    mean = df.close.rolling(lookback).mean()
    std = df.close.rolling(lookback).std().replace(0, pd.NA)
    lower, upper = mean - 2.0 * std, mean + 2.0 * std
    buy = active & (df.close.shift(1) < lower.shift(1)) & (df.close >= lower) & (df.close > df.close.shift(1))
    sell = active & (df.close.shift(1) > upper.shift(1)) & (df.close <= upper) & (df.close < df.close.shift(1))
    out = df.copy()
    out["signal"] = "WAIT"
    out.loc[buy, "signal"] = "BUY"
    out.loc[sell, "signal"] = "SELL"
    out["signal_confidence"] = ((df.close - mean).abs() / (std * 2.0)).clip(0, 1).fillna(0)
    return out


def apply_momentum_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    roc_period, threshold, ema_period = int(p.get("roc_period", 12)), float(p.get("roc_threshold", 0.2)), int(p.get("ema_period", 50))
    roc = out.close.pct_change(roc_period) * 100
    ema = out.close.ewm(span=ema_period, adjust=False).mean()
    out["signal"] = "WAIT"
    out.loc[(roc > threshold) & (out.close > ema), "signal"] = "BUY"
    out.loc[(roc < -threshold) & (out.close < ema), "signal"] = "SELL"
    atr = out.get("atr_regime", (out.high - out.low).rolling(14, min_periods=1).mean()).replace(0, pd.NA)
    roc_strength = ((roc.abs() - abs(threshold)) / max(abs(threshold), .01)).clip(0, 1)
    trend_separation = ((out.close - ema).abs() / atr).clip(0, 3).fillna(0) / 3
    structural_quality = (roc_strength * .65 + trend_separation * .35).clip(0, 1)
    # Confidence is an ordered signal-quality score, not a claim that the
    # raw indicator is a calibrated probability.  The replay layer calibrates
    # it only from already-closed trades and may abstain on a negative EV
    # lower bound.  A small floor preserves the existing minimum-confidence
    # contract for a valid momentum signal while still separating weak/strong
    # entries for the calibration ledger.
    out["signal_confidence"] = structural_quality.where(
        out["signal"].isin(["BUY", "SELL"]), 0.0
    ).fillna(0).mul(.65).add(.35).where(
        out["signal"].isin(["BUY", "SELL"]), 0.0
    ).clip(0, 1)
    return out


def apply_momentum_pullback_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Time-series momentum that refuses entries after ATR-sized extension."""
    p = parameters or {}
    out = apply_momentum_strategy(df, p)
    ema_period = int(p.get("ema_period", 50))
    ema = df.close.ewm(span=ema_period, adjust=False).mean()
    atr = df.get("atr_regime", (df.high - df.low).rolling(14, min_periods=1).mean()).replace(0, pd.NA)
    near_ema = (df.close - ema).abs() <= atr * 0.75
    invalid = ~near_ema.fillna(False)
    out.loc[invalid, "signal"] = "WAIT"
    out.loc[invalid, "signal_confidence"] = 0.0
    return out


def apply_hybrid_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Route a signal family by the *current* regime, with no look-ahead.

    Trend candles use trend-following, ranges use mean-reversion, and high
    volatility can be explicitly parked.  Outside those clear states, the
    weighted agreement of independent families is required.  The specialist
    envelopes are explicit genes rather than hidden constants: a temporal or
    monthly rescue can therefore test one causal lane without refitting the
    whole hybrid.
    """
    p = parameters or {}
    out = df.copy()
    trend = apply_momentum_strategy(out, {
        "roc_period": int(p.get("trend_roc_period", 12)),
        "roc_threshold": float(p.get("trend_roc_threshold", 0.2)),
        "ema_period": int(p.get("trend_ema_period", 50)),
    })
    breakout = apply_volatility_strategy(out, {
        "atr_period": int(p.get("breakout_atr_period", 14)),
        "atr_threshold": float(p.get("breakout_atr_threshold", 1.2)),
        "lookback": int(p.get("breakout_lookback", 20)),
        "compression_ratio": float(p.get("breakout_compression_ratio", 0.75)),
        "expansion_multiplier": float(p.get("breakout_expansion_multiplier", 1.2)),
    })
    mean_reversion = apply_mean_reversion_strategy(out, {
        "lookback": int(p.get("range_lookback", 20)),
        "deviation": float(p.get("range_deviation", 2.0)),
        "adx_max": float(p.get("range_adx_max", 20.0)),
        "low_volatility_only": bool(p.get("range_low_volatility_only", True)),
    })
    weights = {
        "trend": float(p.get("trend_weight", 1.0)),
        "breakout": float(p.get("breakout_weight", 1.0)),
        "mean_reversion": float(p.get("mean_reversion_weight", 1.0)),
    }
    minimum = float(p.get("minimum_confidence", 1.0))
    session_enabled = bool(p.get("session_filter_enabled", False))
    session_start = int(p.get("session_start", 0))
    session_end = int(p.get("session_end", 24))
    out["signal"] = "WAIT"
    out["selected_specialist"] = "none"
    # Confidence is populated from the selected specialist below. Keeping it
    # at zero for WAIT prevents a no-signal row from entering the calibration
    # sample and removes the old implicit-confidence=1.0 blind spot.
    out["signal_confidence"] = 0.0

    # This router used to perform a Python ``.at`` lookup for every candle.
    # Full canonical archives contain 100k+ rows and replay this topology many
    # times (normal/stress, temporal, monthly and adversarial lanes), so that
    # implementation turned a deterministic vector operation into an
    # accidental multi-hour bottleneck. The masks below preserve the exact
    # precedence of the old loop: trend specialist first, breakout fallback,
    # range specialist next, weighted consensus only for unknown regimes.
    regime = out.get("market_regime", pd.Series("unknown", index=out.index)).astype(str)
    volatility = out.get(
        "volatility_regime", pd.Series("normal_volatility", index=out.index)
    ).astype(str)
    active = pd.Series(True, index=out.index)
    if bool(p.get("high_volatility_wait", True)):
        active &= volatility != "high_volatility"
    if session_enabled:
        hours = pd.to_datetime(out["time"], errors="coerce", utc=True).dt.hour
        if session_start < session_end:
            active &= hours.ge(session_start) & hours.lt(session_end)
        else:
            active &= hours.ge(session_start) | hours.lt(session_end)

    trend_signal = trend["signal"].astype(str)
    breakout_signal = breakout["signal"].astype(str)
    mean_signal = mean_reversion["signal"].astype(str)

    def clipped_confidence(source: pd.DataFrame) -> pd.Series:
        return pd.to_numeric(
            source.get("signal_confidence", pd.Series(0.0, index=out.index)),
            errors="coerce",
        ).fillna(0.0).clip(lower=.35, upper=1.0)

    trend_confidence = clipped_confidence(trend)
    breakout_confidence = clipped_confidence(breakout)
    mean_confidence = clipped_confidence(mean_reversion)

    trend_regime = active & regime.isin(["trend_up", "trend_down"])
    trend_lane = trend_regime & trend_signal.ne("WAIT")
    breakout_lane = trend_regime & trend_signal.eq("WAIT")
    out.loc[trend_regime, "selected_specialist"] = "breakout"
    out.loc[trend_lane, "selected_specialist"] = "trend"
    out.loc[trend_lane, "signal"] = trend_signal.loc[trend_lane]
    out.loc[breakout_lane, "signal"] = breakout_signal.loc[breakout_lane]
    out.loc[trend_lane & trend_signal.ne("WAIT"), "signal_confidence"] = trend_confidence.loc[
        trend_lane & trend_signal.ne("WAIT")
    ]
    out.loc[breakout_lane & breakout_signal.ne("WAIT"), "signal_confidence"] = breakout_confidence.loc[
        breakout_lane & breakout_signal.ne("WAIT")
    ]

    range_lane = active & regime.eq("range")
    range_signal = range_lane & mean_signal.ne("WAIT")
    out.loc[range_lane, "selected_specialist"] = "range"
    out.loc[range_lane, "signal"] = mean_signal.loc[range_lane]
    out.loc[range_signal, "signal_confidence"] = mean_confidence.loc[range_signal]

    unknown_lane = active & ~regime.isin(["trend_up", "trend_down", "range"])
    trend_buy = trend_signal.eq("BUY").astype(float) * weights["trend"]
    breakout_buy = breakout_signal.eq("BUY").astype(float) * weights["breakout"]
    mean_buy = mean_signal.eq("BUY").astype(float) * weights["mean_reversion"]
    trend_sell = trend_signal.eq("SELL").astype(float) * weights["trend"]
    breakout_sell = breakout_signal.eq("SELL").astype(float) * weights["breakout"]
    mean_sell = mean_signal.eq("SELL").astype(float) * weights["mean_reversion"]
    buy_weight = trend_buy + breakout_buy + mean_buy
    sell_weight = trend_sell + breakout_sell + mean_sell
    buy_lane = unknown_lane & buy_weight.ge(minimum) & buy_weight.gt(sell_weight)
    sell_lane = unknown_lane & sell_weight.ge(minimum) & sell_weight.gt(buy_weight)

    buy_confidence_weight = (
        trend_buy * trend_confidence
        + breakout_buy * breakout_confidence
        + mean_buy * mean_confidence
    )
    sell_confidence_weight = (
        trend_sell * trend_confidence
        + breakout_sell * breakout_confidence
        + mean_sell * mean_confidence
    )
    out.loc[buy_lane | sell_lane, "selected_specialist"] = "consensus"
    out.loc[buy_lane, "signal"] = "BUY"
    out.loc[sell_lane, "signal"] = "SELL"
    out.loc[buy_lane, "signal_confidence"] = (
        buy_confidence_weight / buy_weight.clip(lower=.0001)
    ).clip(lower=.35, upper=1.0).loc[buy_lane]
    out.loc[sell_lane, "signal_confidence"] = (
        sell_confidence_weight / sell_weight.clip(lower=.0001)
    ).clip(lower=.35, upper=1.0).loc[sell_lane]
    return out


def apply_hybrid_consensus_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Keep regime-owned signals, but require two votes in unknown regimes."""
    p = parameters or {}
    out = apply_hybrid_strategy(df, p)
    trend = apply_momentum_strategy(out, {
        "roc_period": int(p.get("trend_roc_period", 12)),
        "roc_threshold": float(p.get("trend_roc_threshold", 0.2)),
        "ema_period": int(p.get("trend_ema_period", 50)),
    })
    breakout = apply_volatility_strategy(out, {
        "atr_period": int(p.get("breakout_atr_period", 14)),
        "atr_threshold": float(p.get("breakout_atr_threshold", 1.2)),
        "lookback": int(p.get("breakout_lookback", 20)),
        "compression_ratio": float(p.get("breakout_compression_ratio", 0.75)),
        "expansion_multiplier": float(p.get("breakout_expansion_multiplier", 1.2)),
    })
    mean = apply_mean_reversion_strategy(out, {
        "lookback": int(p.get("range_lookback", 20)),
        "deviation": float(p.get("range_deviation", 2.0)),
        "adx_max": float(p.get("range_adx_max", 20.0)),
        "low_volatility_only": bool(p.get("range_low_volatility_only", True)),
    })
    regime = out.get("market_regime", pd.Series("unknown", index=out.index)).astype(str)
    unknown = ~regime.isin(["trend_up", "trend_down", "range"])
    buy_votes = (trend["signal"] == "BUY").astype(int) + (breakout["signal"] == "BUY").astype(int) + (mean["signal"] == "BUY").astype(int)
    sell_votes = (trend["signal"] == "SELL").astype(int) + (breakout["signal"] == "SELL").astype(int) + (mean["signal"] == "SELL").astype(int)
    consensus = unknown & ((buy_votes >= 2) | (sell_votes >= 2))
    out.loc[unknown & ~consensus, "signal"] = "WAIT"
    out.loc[unknown & ~consensus, "signal_confidence"] = 0.0
    out.loc[unknown & ~consensus, "selected_specialist"] = "consensus_wait"
    return out


def apply_differential_router_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Freeze a parent hybrid except for one pre-declared regime lane.

    The original rescue was permanently wired to ``trend_down``.  That made
    the experiment optimize a healthy lane while the actual deficit was often
    ``range``.  The target is now part of the sealed experiment contract and
    the child topology is selected before replay.  Untargeted candles are
    copied verbatim from the parent so a lane mutation cannot silently rewrite
    the rest of the strategy.
    """
    p = parameters or {}
    target_regime = str(p.get("differential_target_regime", "trend_down"))
    if target_regime not in {"trend_up", "range", "trend_down"}:
        raise ValueError(f"Unsupported differential target regime: {target_regime}")

    parent = apply_hybrid_strategy(df, p)
    router_version = str(p.get("differential_router_version", "v1"))
    if target_regime == "range":
        child = apply_range_reentry_strategy(parent, p)
    elif router_version == "v2":
        # Preserve the parent's hybrid momentum topology.  The old v1 path
        # swapped in a different ADX/EMA specialist, which silently destroyed
        # target-lane opportunity coverage and made a one-gene rescue look
        # like a new strategy family.  v2 changes only the declared regime's
        # ROC/EMA envelope; outside that regime the parent frame is copied
        # verbatim below.
        prefix = "trend_up" if target_regime == "trend_up" else "trend_down"
        child = apply_momentum_strategy(parent, {
            "roc_period": int(p.get(f"{prefix}_roc_period", p.get("trend_roc_period", 12))),
            "roc_threshold": float(p.get(f"{prefix}_roc_threshold", p.get("trend_roc_threshold", .2))),
            "ema_period": int(p.get(f"{prefix}_ema_period", p.get("trend_ema_period", 50))),
        })
    else:
        prefix = "trend_up" if target_regime == "trend_up" else "trend_down"
        child = apply_trend_specialist(parent, {
            **p,
            "trend_strength_min": p.get(f"{prefix}_strength_min", p.get("trend_strength_min", 20)),
            "pullback_atr_fraction": p.get(
                f"{prefix}_pullback_atr_fraction", p.get("pullback_atr_fraction", .75)
            ),
        })

    out = parent.copy()
    # Keep the exact parent context outside the target lane.  The replay
    # engine uses this marker to run an isolated paired lane contract.
    out["selected_specialist"] = "parent"
    out["parent_signal"] = parent["signal"]
    out["parent_signal_confidence"] = parent["signal_confidence"]
    target = out.get("market_regime", pd.Series("unknown", index=out.index)).astype(str) == target_regime
    out.loc[target, "signal"] = child.loc[target, "signal"]
    out.loc[target, "signal_confidence"] = child.loc[target, "signal_confidence"]
    out.loc[target, "selected_specialist"] = f"{target_regime}_child"

    # Temporal hypotheses may own only the declared regime lane. Applying a
    # session filter to the whole parent would invalidate the paired
    # differential contract by changing non-target signals as well.
    if bool(p.get("differential_target_session_filter_enabled", False)):
        start = int(p.get("differential_target_session_start", 7))
        end = int(p.get("differential_target_session_end", 16))
        hours = pd.to_datetime(out["time"], utc=True, errors="coerce").dt.hour
        in_session = (
            (hours.ge(start) & hours.lt(end))
            if start < end
            else (hours.ge(start) | hours.lt(end))
        )
        target_outside_session = target & ~in_session.fillna(False)
        out.loc[target_outside_session, "signal"] = "WAIT"
        out.loc[target_outside_session, "signal_confidence"] = 0.0

    out["differential_target"] = target
    out["differential_target_regime"] = target_regime
    return out


def apply_differential_trend_down_router_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Backward-compatible alias for old trend-down model versions."""
    return apply_differential_router_strategy(df, parameters)


def apply_regime_ensemble_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Frozen one-signal router for complementary regime specialists.

    This is an architecture, not a post-hoc portfolio selection: priority and
    ownership are fixed before the replay.  A candle is owned by exactly one
    specialist, so duplicate specialist signals cannot inflate trade count.
    """
    p = parameters or {}
    out = df.copy()
    trend_up = apply_trend_specialist(out, p)
    # A trend-down signal is generated by a separate frozen specialist.  Its
    # thresholds cannot leak into trend-up, range, or breakout decisions.
    trend_down = apply_trend_specialist(out, {
        **p,
        "trend_strength_min": p.get("trend_down_strength_min", p.get("trend_strength_min", 20)),
        "pullback_atr_fraction": p.get("trend_down_pullback_atr_fraction", p.get("pullback_atr_fraction", .75)),
    })
    breakout = apply_volatility_strategy(out, p)
    range_agent = apply_mean_reversion_strategy(out, p)
    out["signal"] = "WAIT"
    out["signal_confidence"] = 0.0
    out["selected_specialist"] = "none"

    for index in out.index:
        regime = str(out.at[index, "market_regime"] if "market_regime" in out else "unknown")
        volatility = str(out.at[index, "volatility_regime"] if "volatility_regime" in out else "normal_volatility")
        if regime not in {"trend_up", "trend_down", "range"}:
            specialist, source = "unknown_wait", None
        elif volatility == "high_volatility":
            specialist, source = "breakout", breakout
        elif regime == "trend_up":
            specialist, source = "trend_up", trend_up
        elif regime == "trend_down":
            specialist, source = "trend_down", trend_down
        elif regime == "range":
            specialist, source = "range", range_agent
        else:
            # An unclassified regime is an epistemic state, not a session
            # regime. Falling back to a specialist here silently converts
            # missing/ambiguous evidence into a live trading decision and
            # makes the router look more capable than it is. Keep the
            # decision fail-closed; the portfolio router has the same
            # invariant and uses "portfolio_wait" for this state.
            specialist, source = "unknown_wait", None
        out.at[index, "selected_specialist"] = specialist
        if source is not None:
            out.at[index, "signal"] = str(source.at[index, "signal"])
            out.at[index, "signal_confidence"] = float(source.at[index, "signal_confidence"] if "signal_confidence" in source else 1.0)
    return out
