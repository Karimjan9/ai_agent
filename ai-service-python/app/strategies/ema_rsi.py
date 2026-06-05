import pandas as pd

try:
    from ta.momentum import RSIIndicator
    from ta.trend import EMAIndicator
except ImportError:  # pragma: no cover - fallback keeps the service usable before deps are installed.
    EMAIndicator = None
    RSIIndicator = None


def apply_ema_rsi_strategy(df: pd.DataFrame) -> pd.DataFrame:
    prepared = df.copy()

    if EMAIndicator and RSIIndicator:
        prepared["ema_50"] = EMAIndicator(close=prepared["close"], window=50).ema_indicator()
        prepared["ema_200"] = EMAIndicator(close=prepared["close"], window=200).ema_indicator()
        prepared["rsi"] = RSIIndicator(close=prepared["close"], window=14).rsi()
    else:
        prepared["ema_50"] = prepared["close"].ewm(span=50, adjust=False).mean()
        prepared["ema_200"] = prepared["close"].ewm(span=200, adjust=False).mean()
        prepared["rsi"] = _rsi(prepared["close"], 14)

    prepared["signal"] = "WAIT"

    buy_condition = (
        (prepared["ema_50"] > prepared["ema_200"])
        & (prepared["rsi"] > 50)
        & (prepared["rsi"] < 70)
    )

    sell_condition = (
        (prepared["ema_50"] < prepared["ema_200"])
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
