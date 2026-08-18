import pandas as pd

try:
    from ta.trend import ADXIndicator, EMAIndicator
    from ta.volatility import AverageTrueRange
except ImportError:  # pragma: no cover - fallback keeps local smoke tests usable before deps are installed.
    ADXIndicator = None
    EMAIndicator = None
    AverageTrueRange = None


def apply_market_regime(df: pd.DataFrame, variant: str = "frozen") -> pd.DataFrame:
    """Classify closed candles with an explicit, auditable regime variant.

    ``frozen`` is the historical classifier.  The other variants are
    structural research hypotheses: they alter the regime labels used by
    the router, rather than nudging an EMA/ROC/wait scalar.  All inputs are
    rolling/current closed-candle values, so no future regime information is
    introduced.
    """
    df = df.copy()
    variant = str(variant or "frozen")

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

    trend_threshold = pd.Series(20.0, index=df.index)
    range_threshold = pd.Series(20.0, index=df.index)
    if variant == "adx_hysteresis_v1":
        # Separate enter/leave thresholds reduce label flicker around the
        # historical ADX boundary.  This is a classifier hypothesis, not a
        # calendar gate.
        trend_threshold = pd.Series(22.0, index=df.index)
        range_threshold = pd.Series(18.0, index=df.index)
    elif variant == "ema_slope_consensus_v1":
        ema_fast_slope = df["ema_50_regime"].diff(3)
        ema_slow_slope = df["ema_200_regime"].diff(3)
        trend_up = (
            (df["ema_50_regime"] > df["ema_200_regime"])
            & ema_fast_slope.gt(0)
            & ema_slow_slope.ge(0)
            & df["adx"].ge(18)
        )
        trend_down = (
            (df["ema_50_regime"] < df["ema_200_regime"])
            & ema_fast_slope.lt(0)
            & ema_slow_slope.le(0)
            & df["adx"].ge(18)
        )
        range_market = df["adx"] < 18
    elif variant == "volatility_adaptive_v1":
        local_strength = (
            df["adx"].rolling(100, min_periods=20).median()
            .fillna(20.0)
            .clip(lower=15.0, upper=30.0)
        )
        trend_threshold = local_strength.clip(lower=18.0)
        range_threshold = (trend_threshold * 0.85).clip(lower=14.0)

    if variant != "ema_slope_consensus_v1":
        trend_up = (df["ema_50_regime"] > df["ema_200_regime"]) & df["adx"].ge(trend_threshold)
        trend_down = (df["ema_50_regime"] < df["ema_200_regime"]) & df["adx"].ge(trend_threshold)
        range_market = df["adx"] < range_threshold

    volatility_window = df["atr_percent"].rolling(100, min_periods=20)
    high_volatility = df["atr_percent"] >= volatility_window.quantile(0.75)
    low_volatility = df["atr_percent"] <= volatility_window.quantile(0.25)

    df.loc[trend_up, "market_regime"] = "trend_up"
    df.loc[trend_down, "market_regime"] = "trend_down"
    df.loc[range_market, "market_regime"] = "range"

    df.loc[high_volatility, "volatility_regime"] = "high_volatility"
    df.loc[low_volatility, "volatility_regime"] = "low_volatility"
    df["regime_classifier_variant"] = variant

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
