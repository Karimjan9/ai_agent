import pandas as pd

try:
    from ta.volatility import AverageTrueRange
except ImportError:  # pragma: no cover
    AverageTrueRange = None


def apply_breakout_strategy(df: pd.DataFrame) -> pd.DataFrame:
    prepared = df.copy()
    lookback = 20

    prepared["range_high"] = prepared["high"].rolling(window=lookback).max().shift(1)
    prepared["range_low"] = prepared["low"].rolling(window=lookback).min().shift(1)

    if AverageTrueRange:
        atr = AverageTrueRange(
            high=prepared["high"],
            low=prepared["low"],
            close=prepared["close"],
            window=14,
        )
        prepared["atr"] = atr.average_true_range()
    else:
        prepared["atr"] = _atr(prepared, 14)

    prepared["signal"] = "WAIT"

    buy_condition = (
        (prepared["close"] > prepared["range_high"])
        & ((prepared["close"] - prepared["range_high"]) > prepared["atr"] * 0.2)
    )

    sell_condition = (
        (prepared["close"] < prepared["range_low"])
        & ((prepared["range_low"] - prepared["close"]) > prepared["atr"] * 0.2)
    )

    prepared.loc[buy_condition, "signal"] = "BUY"
    prepared.loc[sell_condition, "signal"] = "SELL"

    return prepared


def _atr(candles: pd.DataFrame, period: int) -> pd.Series:
    high_low = candles["high"] - candles["low"]
    high_close = (candles["high"] - candles["close"].shift()).abs()
    low_close = (candles["low"] - candles["close"].shift()).abs()
    true_range = pd.concat([high_low, high_close, low_close], axis=1).max(axis=1)
    return true_range.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
