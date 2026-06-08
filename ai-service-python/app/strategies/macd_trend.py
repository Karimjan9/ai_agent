import pandas as pd

try:
    from ta.momentum import RSIIndicator
    from ta.trend import EMAIndicator, MACD
except ImportError:  # pragma: no cover
    EMAIndicator = None
    MACD = None
    RSIIndicator = None


def apply_macd_trend_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    parameters = parameters or {}
    prepared = df.copy()
    ema_trend = int(parameters.get("ema_trend", 100))
    macd_fast = int(parameters.get("macd_fast", 12))
    macd_slow = int(parameters.get("macd_slow", 26))
    macd_signal = int(parameters.get("macd_signal", 9))
    rsi_period = int(parameters.get("rsi_period", 14))

    if EMAIndicator and MACD and RSIIndicator:
        prepared["ema_100"] = EMAIndicator(close=prepared["close"], window=ema_trend).ema_indicator()
        macd = MACD(close=prepared["close"], window_slow=macd_slow, window_fast=macd_fast, window_sign=macd_signal)
        prepared["macd"] = macd.macd()
        prepared["macd_signal"] = macd.macd_signal()
        prepared["rsi"] = RSIIndicator(close=prepared["close"], window=rsi_period).rsi()
    else:
        prepared["ema_100"] = prepared["close"].ewm(span=ema_trend, adjust=False).mean()
        ema_fast = prepared["close"].ewm(span=macd_fast, adjust=False).mean()
        ema_slow = prepared["close"].ewm(span=macd_slow, adjust=False).mean()
        prepared["macd"] = ema_fast - ema_slow
        prepared["macd_signal"] = prepared["macd"].ewm(span=macd_signal, adjust=False).mean()
        prepared["rsi"] = _rsi(prepared["close"], rsi_period)

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
