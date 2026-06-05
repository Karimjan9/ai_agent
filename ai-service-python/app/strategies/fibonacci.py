import pandas as pd


def apply_fibonacci_strategy(df: pd.DataFrame) -> pd.DataFrame:
    prepared = df.copy()
    lookback = 50

    prepared["swing_high"] = prepared["high"].rolling(window=lookback).max()
    prepared["swing_low"] = prepared["low"].rolling(window=lookback).min()

    swing_range = prepared["swing_high"] - prepared["swing_low"]
    prepared["fib_382"] = prepared["swing_high"] - swing_range * 0.382
    prepared["fib_500"] = prepared["swing_high"] - swing_range * 0.500
    prepared["fib_618"] = prepared["swing_high"] - swing_range * 0.618

    prepared["signal"] = "WAIT"

    near_fib_618 = (
        (prepared["close"] >= prepared["fib_618"] * 0.998)
        & (prepared["close"] <= prepared["fib_618"] * 1.002)
    )

    bullish_candle = prepared["close"] > prepared["open"]
    bearish_candle = prepared["close"] < prepared["open"]

    prepared.loc[near_fib_618 & bullish_candle, "signal"] = "BUY"
    prepared.loc[near_fib_618 & bearish_candle, "signal"] = "SELL"

    return prepared
