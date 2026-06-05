import pandas as pd

try:
    from ta.momentum import RSIIndicator
    from ta.trend import EMAIndicator, MACD
except ImportError:  # pragma: no cover
    EMAIndicator = None
    MACD = None
    RSIIndicator = None


def apply_macd_trend_strategy(df: pd.DataFrame) -> pd.DataFrame:
    prepared = df.copy()

    if EMAIndicator and MACD and RSIIndicator:
        prepared["ema_100"] = EMAIndicator(close=prepared["close"], window=100).ema_indicator()
        macd = MACD(close=prepared["close"], window_slow=26, window_fast=12, window_sign=9)
        prepared["macd"] = macd.macd()
        prepared["macd_signal"] = macd.macd_signal()
        prepared["rsi"] = RSIIndicator(close=prepared["close"], window=14).rsi()
    else:
        prepared["ema_100"] = prepared["close"].ewm(span=100, adjust=False).mean()
        ema_fast = prepared["close"].ewm(span=12, adjust=False).mean()
        ema_slow = prepared["close"].ewm(span=26, adjust=False).mean()
        prepared["macd"] = ema_fast - ema_slow
        prepared["macd_signal"] = prepared["macd"].ewm(span=9, adjust=False).mean()
        prepared["rsi"] = _rsi(prepared["close"], 14)

    prepared["signal"] = "WAIT"

    buy_condition = (
        (prepared["close"] > prepared["ema_100"])
        & (prepared["macd"] > prepared["macd_signal"])
        & (prepared["rsi"] > 50)
        & (prepared["rsi"] < 70)
    )

    sell_condition = (
        (prepared["close"] < prepared["ema_100"])
        & (prepared["macd"] < prepared["macd_signal"])
        & (prepared["rsi"] < 50)
        & (prepared["rsi"] > 30)
    )

    prepared.loc[buy_condition, "signal"] = "BUY"
    prepared.loc[sell_condition, "signal"] = "SELL"

    return prepared


def _rsi(close: pd.Series, period: int) -> pd.Series:
    delta = close.diff()
    gain = delta.clip(lower=0)
    loss = -delta.clip(upper=0)
    avg_gain = gain.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
    avg_loss = loss.ewm(alpha=1 / period, min_periods=period, adjust=False).mean()
    rs = avg_gain / avg_loss.replace(0, pd.NA)
    return 100 - (100 / (1 + rs))
