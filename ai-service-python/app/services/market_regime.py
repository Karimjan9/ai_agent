import pandas as pd

try:
    from ta.trend import ADXIndicator, EMAIndicator
    from ta.volatility import AverageTrueRange
except ImportError:  # pragma: no cover - fallback keeps local smoke tests usable before deps are installed.
    ADXIndicator = None
    EMAIndicator = None
    AverageTrueRange = None


def apply_market_regime(df: pd.DataFrame) -> pd.DataFrame:
    df = df.copy()

    if EMAIndicator and ADXIndicator and AverageTrueRange:
        df["ema_50_regime"] = EMAIndicator(close=df["close"], window=50).ema_indicator()
        df["ema_200_regime"] = EMAIndicator(close=df["close"], window=200).ema_indicator()

        adx = ADXIndicator(
            high=df["high"],
            low=df["low"],
            close=df["close"],
            window=14,
        )
        df["adx"] = adx.adx()

        atr = AverageTrueRange(
            high=df["high"],
            low=df["low"],
            close=df["close"],
            window=14,
        )
        df["atr_regime"] = atr.average_true_range()
    else:
        df["ema_50_regime"] = df["close"].ewm(span=50, adjust=False).mean()
        df["ema_200_regime"] = df["close"].ewm(span=200, adjust=False).mean()
        df["atr_regime"] = _average_true_range(df, 14)
        df["adx"] = _adx(df, 14)
    df["atr_percent"] = (df["atr_regime"] / df["close"]) * 100

    df["market_regime"] = "unknown"
    df["volatility_regime"] = "normal_volatility"

    trend_up = (df["ema_50_regime"] > df["ema_200_regime"]) & (df["adx"] >= 20)
    trend_down = (df["ema_50_regime"] < df["ema_200_regime"]) & (df["adx"] >= 20)
    range_market = df["adx"] < 20

    volatility_window = df["atr_percent"].rolling(100, min_periods=20)
    high_volatility = df["atr_percent"] >= volatility_window.quantile(0.75)
    low_volatility = df["atr_percent"] <= volatility_window.quantile(0.25)

    df.loc[trend_up, "market_regime"] = "trend_up"
    df.loc[trend_down, "market_regime"] = "trend_down"
    df.loc[range_market, "market_regime"] = "range"

    df.loc[high_volatility, "volatility_regime"] = "high_volatility"
    df.loc[low_volatility, "volatility_regime"] = "low_volatility"

    return df


def _average_true_range(df: pd.DataFrame, window: int) -> pd.Series:
    previous_close = df["close"].shift(1)
    true_range = pd.concat(
        [
            df["high"] - df["low"],
            (df["high"] - previous_close).abs(),
            (df["low"] - previous_close).abs(),
        ],
        axis=1,
    ).max(axis=1)

    return true_range.ewm(alpha=1 / window, min_periods=window, adjust=False).mean()


def _adx(df: pd.DataFrame, window: int) -> pd.Series:
    high_diff = df["high"].diff()
    low_diff = -df["low"].diff()

    plus_dm = high_diff.where((high_diff > low_diff) & (high_diff > 0), 0.0)
    minus_dm = low_diff.where((low_diff > high_diff) & (low_diff > 0), 0.0)

    atr = _average_true_range(df, window).replace(0, pd.NA)
    plus_di = 100 * plus_dm.ewm(alpha=1 / window, min_periods=window, adjust=False).mean() / atr
    minus_di = 100 * minus_dm.ewm(alpha=1 / window, min_periods=window, adjust=False).mean() / atr
    dx = ((plus_di - minus_di).abs() / (plus_di + minus_di).replace(0, pd.NA)) * 100

    return dx.ewm(alpha=1 / window, min_periods=window, adjust=False).mean().fillna(0)
