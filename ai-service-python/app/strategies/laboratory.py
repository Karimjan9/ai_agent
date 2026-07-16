import pandas as pd


def apply_volatility_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    period, lookback = int(p.get("atr_period", 14)), int(p.get("lookback", 20))
    threshold = float(p.get("atr_threshold", 1.2))
    tr = pd.concat([(out.high - out.low), (out.high - out.close.shift()).abs(), (out.low - out.close.shift()).abs()], axis=1).max(axis=1)
    atr = tr.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
    baseline = atr.rolling(lookback).mean()
    out["signal"] = "WAIT"
    active = atr > baseline * threshold
    out.loc[active & (out.close > out.open), "signal"] = "BUY"
    out.loc[active & (out.close < out.open), "signal"] = "SELL"
    return out


def apply_mean_reversion_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    p = parameters or {}
    out = df.copy()
    lookback, deviation = int(p.get("lookback", 20)), float(p.get("deviation", 2.0))
    mean, std = out.close.rolling(lookback).mean(), out.close.rolling(lookback).std()
    out["signal"] = "WAIT"
    out.loc[out.close < mean - std * deviation, "signal"] = "BUY"
    out.loc[out.close > mean + std * deviation, "signal"] = "SELL"
    return out


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
