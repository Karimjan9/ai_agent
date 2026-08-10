import pandas as pd

try:
    from ta.volatility import AverageTrueRange
except ImportError:  # pragma: no cover
    AverageTrueRange = None


def apply_breakout_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    parameters = parameters or {}
    prepared = df.copy()
    lookback = int(parameters.get("lookback", 20))
    atr_period = int(parameters.get("atr_period", 14))
    atr_multiplier = float(parameters.get("atr_multiplier", 0.2))
    confirmation_candles = max(int(parameters.get("confirmation_candles", 1)), 1)
    retest_required = bool(parameters.get("retest_required", False))
    trend_strength_min = float(parameters.get("trend_strength_min", 0))

    prepared["range_high"] = prepared["high"].rolling(window=lookback).max().shift(1)
    prepared["range_low"] = prepared["low"].rolling(window=lookback).min().shift(1)

    if AverageTrueRange:
        atr = AverageTrueRange(
            high=prepared["high"],
            low=prepared["low"],
            close=prepared["close"],
            window=atr_period,
        )
        prepared["atr"] = atr.average_true_range()
    else:
        prepared["atr"] = _atr(prepared, atr_period)

    prepared["signal"] = "WAIT"

    breakout_up = (
        (prepared["close"] > prepared["range_high"])
        & ((prepared["close"] - prepared["range_high"]) > prepared["atr"] * atr_multiplier)
    )

    breakout_down = (
        (prepared["close"] < prepared["range_low"])
        & ((prepared["range_low"] - prepared["close"]) > prepared["atr"] * atr_multiplier)
    )

    if retest_required:
        # Confirmation is a retest of the broken boundary on the next closed
        # candle, not an immediate chase of the breakout wick.
        buy_condition = breakout_up.shift(1).fillna(False) & (prepared["low"] <= prepared["range_high"]) & (prepared["close"] > prepared["range_high"])
        sell_condition = breakout_down.shift(1).fillna(False) & (prepared["high"] >= prepared["range_low"]) & (prepared["close"] < prepared["range_low"])
    elif confirmation_candles > 1:
        buy_condition = breakout_up.rolling(window=confirmation_candles).sum() >= confirmation_candles
        sell_condition = breakout_down.rolling(window=confirmation_candles).sum() >= confirmation_candles
    else:
        buy_condition = breakout_up
        sell_condition = breakout_down

    if trend_strength_min > 0 and "adx" in prepared:
        buy_condition &= prepared["adx"] >= trend_strength_min
        sell_condition &= prepared["adx"] >= trend_strength_min

    prepared.loc[buy_condition, "signal"] = "BUY"
    prepared.loc[sell_condition, "signal"] = "SELL"
    prepared["signal_confidence"] = (prepared["adx"] / max(trend_strength_min, 1)).clip(0, 1) if "adx" in prepared else 0.5

    return prepared


def apply_breakout_continuation_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    """Confirmed Donchian/ATR continuation without a retest requirement."""
    p = dict(parameters or {})
    p["retest_required"] = False
    return apply_breakout_strategy(df, p)


def _atr(candles: pd.DataFrame, period: int) -> pd.Series:
    high_low = candles["high"] - candles["low"]
    high_close = (candles["high"] - candles["close"].shift()).abs()
    low_close = (candles["low"] - candles["close"].shift()).abs()
    true_range = pd.concat([high_low, high_close, low_close], axis=1).max(axis=1)
    return true_range.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
