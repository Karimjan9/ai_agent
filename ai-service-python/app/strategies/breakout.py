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

    if confirmation_candles > 1:
        buy_condition = breakout_up.rolling(window=confirmation_candles).sum() >= confirmation_candles
        sell_condition = breakout_down.rolling(window=confirmation_candles).sum() >= confirmation_candles
    else:
        buy_condition = breakout_up
        sell_condition = breakout_down

    prepared.loc[buy_condition, "signal"] = "BUY"
    prepared.loc[sell_condition, "signal"] = "SELL"

    return prepared


def _atr(candles: pd.DataFrame, period: int) -> pd.Series:
    high_low = candles["high"] - candles["low"]
    high_close = (candles["high"] - candles["close"].shift()).abs()
    low_close = (candles["low"] - candles["close"].shift()).abs()
    true_range = pd.concat([high_low, high_close, low_close], axis=1).max(axis=1)
    return true_range.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
