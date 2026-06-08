import pandas as pd


def apply_fibonacci_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    parameters = parameters or {}
    prepared = df.copy()
    lookback = int(parameters.get("lookback", 50))
    fib_level = float(parameters.get("fib_level", 0.618))
    tolerance = float(parameters.get("tolerance", 0.002))
    candle_confirmation = bool(parameters.get("candle_confirmation", True))
    trend_confirmation = bool(parameters.get("trend_confirmation", False))

    prepared["swing_high"] = prepared["high"].rolling(window=lookback).max()
    prepared["swing_low"] = prepared["low"].rolling(window=lookback).min()

    swing_range = prepared["swing_high"] - prepared["swing_low"]
    prepared["fib_382"] = prepared["swing_high"] - swing_range * 0.382
    prepared["fib_500"] = prepared["swing_high"] - swing_range * 0.500
    prepared["fib_618"] = prepared["swing_high"] - swing_range * fib_level

    prepared["signal"] = "WAIT"

    near_fib_618 = (
        (prepared["close"] >= prepared["fib_618"] * (1 - tolerance))
        & (prepared["close"] <= prepared["fib_618"] * (1 + tolerance))
    )

    bullish_candle = prepared["close"] > prepared["open"]
    bearish_candle = prepared["close"] < prepared["open"]
    if not candle_confirmation:
        bullish_candle = True
        bearish_candle = True

    if trend_confirmation:
        trend_average = prepared["close"].rolling(window=min(lookback, 100)).mean()
        bullish_candle = bullish_candle & (prepared["close"] > trend_average)
        bearish_candle = bearish_candle & (prepared["close"] < trend_average)

    prepared.loc[near_fib_618 & bullish_candle, "signal"] = "BUY"
    prepared.loc[near_fib_618 & bearish_candle, "signal"] = "SELL"

    return prepared
