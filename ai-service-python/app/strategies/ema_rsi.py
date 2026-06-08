import pandas as pd

try:
    from ta.momentum import RSIIndicator
    from ta.trend import EMAIndicator
except ImportError:  # pragma: no cover - fallback keeps the service usable before deps are installed.
    EMAIndicator = None
    RSIIndicator = None


def apply_ema_rsi_strategy(df: pd.DataFrame, parameters: dict | None = None) -> pd.DataFrame:
    parameters = parameters or {}
    prepared = df.copy()
    ema_fast = int(parameters.get("ema_fast", 50))
    ema_slow = int(parameters.get("ema_slow", 200))
    rsi_period = int(parameters.get("rsi_period", 14))
    rsi_buy_min = float(parameters.get("rsi_buy_min", 50))
    rsi_buy_max = float(parameters.get("rsi_buy_max", 70))
    rsi_sell_min = float(parameters.get("rsi_sell_min", 30))
    rsi_sell_max = float(parameters.get("rsi_sell_max", 50))

    if EMAIndicator and RSIIndicator:
        prepared["ema_50"] = EMAIndicator(close=prepared["close"], window=ema_fast).ema_indicator()
        prepared["ema_200"] = EMAIndicator(close=prepared["close"], window=ema_slow).ema_indicator()
        prepared["rsi"] = RSIIndicator(close=prepared["close"], window=rsi_period).rsi()
    else:
        prepared["ema_50"] = prepared["close"].ewm(span=ema_fast, adjust=False).mean()
        prepared["ema_200"] = prepared["close"].ewm(span=ema_slow, adjust=False).mean()
        prepared["rsi"] = _rsi(prepared["close"], rsi_period)

    prepared["signal"] = "WAIT"

    buy_condition = (
        (prepared["ema_50"] > prepared["ema_200"])
        & (prepared["rsi"] > rsi_buy_min)
        & (prepared["rsi"] < rsi_buy_max)
    )

    sell_condition = (
        (prepared["ema_50"] < prepared["ema_200"])
        & (prepared["rsi"] < rsi_sell_max)
        & (prepared["rsi"] > rsi_sell_min)
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
