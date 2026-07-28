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
    tr = pd.concat([(out.high - out.low), (out.high - out.close.shift()).abs(), (out.low - out.close.shift()).abs()], axis=1).max(axis=1)
    atr = tr.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
    baseline = atr.rolling(lookback).mean()
    out["signal"] = "WAIT"
    compressed = atr.shift(1) <= baseline.shift(1) * compression_ratio
    active = compressed & (atr >= baseline * max(threshold, expansion_multiplier))
    out.loc[active & (out.close > out.open), "signal"] = "BUY"
    out.loc[active & (out.close < out.open), "signal"] = "SELL"
    out["signal_confidence"] = ((atr / baseline.replace(0, pd.NA)) / max(threshold, expansion_multiplier) - 1).clip(0, 1).fillna(0)
    return out


def apply_mean_reversion_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    lookback, deviation = int(p.get("lookback", 20)), float(p.get("deviation", 2.0))
    mean, std = out.close.rolling(lookback).mean(), out.close.rolling(lookback).std()
    out["signal"] = "WAIT"
    adx_max = float(p.get("adx_max", 20))
    low_volatility_only = bool(p.get("low_volatility_only", True))
    range_filter = out.get("adx", pd.Series(0, index=out.index)) <= adx_max
    if low_volatility_only:
        range_filter &= out.get("volatility_regime", pd.Series("normal_volatility", index=out.index)) == "low_volatility"
    out.loc[range_filter & (out.close < mean - std * deviation), "signal"] = "BUY"
    out.loc[range_filter & (out.close > mean + std * deviation), "signal"] = "SELL"
    out["signal_confidence"] = ((out.close - mean).abs() / (std.replace(0, pd.NA) * max(deviation, .01)) - 1).clip(0, 1).fillna(0)
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
    active = pd.to_datetime(out.time).dt.hour.between(start, end - 1)
    out["signal"] = "WAIT"
    out.loc[active & (out.close > high), "signal"] = "BUY"
    out.loc[active & (out.close < low), "signal"] = "SELL"
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
    return out


def apply_hybrid_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Route a signal family by the *current* regime, with no look-ahead.

    Trend candles use trend-following, ranges use mean-reversion, and high
    volatility can be explicitly parked.  Outside those clear states, the
    weighted agreement of independent families is required.
    """
    p = parameters or {}
    out = df.copy()
    trend = apply_momentum_strategy(out, {"roc_period": 12, "roc_threshold": 0.2, "ema_period": 50})
    breakout = apply_volatility_strategy(out, {"atr_period": 14, "atr_threshold": 1.2, "lookback": 20})
    mean_reversion = apply_mean_reversion_strategy(out, {"lookback": 20, "deviation": 2.0})
    weights = {
        "trend": float(p.get("trend_weight", 1.0)),
        "breakout": float(p.get("breakout_weight", 1.0)),
        "mean_reversion": float(p.get("mean_reversion_weight", 1.0)),
    }
    minimum = float(p.get("minimum_confidence", 1.0))
    out["signal"] = "WAIT"

    for index in out.index:
        regime = str(out.at[index, "market_regime"] if "market_regime" in out else "unknown")
        volatility = str(out.at[index, "volatility_regime"] if "volatility_regime" in out else "normal_volatility")
        if bool(p.get("high_volatility_wait", True)) and volatility == "high_volatility":
            continue
        signals = {
            "trend": str(trend.at[index, "signal"]),
            "breakout": str(breakout.at[index, "signal"]),
            "mean_reversion": str(mean_reversion.at[index, "signal"]),
        }
        if regime in {"trend_up", "trend_down"}:
            out.at[index, "signal"] = signals["trend"] if signals["trend"] != "WAIT" else signals["breakout"]
            continue
        if regime == "range":
            out.at[index, "signal"] = signals["mean_reversion"]
            continue
        buy = sum(weights[name] for name, signal in signals.items() if signal == "BUY")
        sell = sum(weights[name] for name, signal in signals.items() if signal == "SELL")
        if buy >= minimum and buy > sell:
            out.at[index, "signal"] = "BUY"
        elif sell >= minimum and sell > buy:
            out.at[index, "signal"] = "SELL"
    return out


def apply_regime_ensemble_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Frozen one-signal router for complementary regime specialists.

    This is an architecture, not a post-hoc portfolio selection: priority and
    ownership are fixed before the replay.  A candle is owned by exactly one
    specialist, so duplicate specialist signals cannot inflate trade count.
    """
    p = parameters or {}
    out = df.copy()
    trend = apply_trend_specialist(out, p)
    breakout = apply_volatility_strategy(out, p)
    range_agent = apply_mean_reversion_strategy(out, p)
    session = apply_session_strategy(out, p)
    out["signal"] = "WAIT"
    out["signal_confidence"] = 0.0
    out["selected_specialist"] = "none"

    for index in out.index:
        regime = str(out.at[index, "market_regime"] if "market_regime" in out else "unknown")
        volatility = str(out.at[index, "volatility_regime"] if "volatility_regime" in out else "normal_volatility")
        if volatility == "high_volatility":
            specialist, source = "breakout", breakout
        elif regime in {"trend_up", "trend_down"}:
            specialist, source = "trend", trend
        elif regime == "range":
            specialist, source = "range", range_agent
        else:
            specialist, source = "session", session
        out.at[index, "selected_specialist"] = specialist
        out.at[index, "signal"] = str(source.at[index, "signal"])
        out.at[index, "signal_confidence"] = float(source.at[index, "signal_confidence"] if "signal_confidence" in source else 1.0)
    return out
