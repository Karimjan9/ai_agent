from __future__ import annotations

import re
from typing import Any


PARAMETER_SCHEMAS: dict[str, dict[str, dict[str, Any]]] = {
    "breakout": {
        "lookback": {"type": int, "min": 10, "max": 100},
        "atr_period": {"type": int, "min": 2, "max": 100},
        "atr_multiplier": {"type": float, "min": 0.1, "max": 3.0},
        "confirmation_candles": {"type": int, "min": 1, "max": 5},
    },
    "ema_rsi": {
        "ema_fast": {"type": int, "min": 2, "max": 200},
        "ema_slow": {"type": int, "min": 3, "max": 500},
        "rsi_period": {"type": int, "min": 2, "max": 100},
        "rsi_buy_min": {"type": float, "min": 0, "max": 100},
        "rsi_buy_max": {"type": float, "min": 0, "max": 100},
        "rsi_sell_min": {"type": float, "min": 0, "max": 100},
        "rsi_sell_max": {"type": float, "min": 0, "max": 100},
    },
    "fibonacci": {
        "lookback": {"type": int, "min": 10, "max": 300},
        "fib_level": {"type": float, "min": 0, "max": 1},
        "tolerance": {"type": float, "min": 0.0001, "max": 0.05},
        "candle_confirmation": {"type": bool},
        "trend_confirmation": {"type": bool},
    },
    "macd_trend": {
        "ema_trend": {"type": int, "min": 10, "max": 500},
        "macd_fast": {"type": int, "min": 2, "max": 100},
        "macd_slow": {"type": int, "min": 3, "max": 200},
        "macd_signal": {"type": int, "min": 2, "max": 100},
        "rsi_period": {"type": int, "min": 2, "max": 100},
    },
    "trend": {
        "ema_fast": {"type": int, "min": 2, "max": 200},
        "ema_slow": {"type": int, "min": 3, "max": 500},
        "rsi_period": {"type": int, "min": 2, "max": 100},
        "rsi_buy_min": {"type": float, "min": 0, "max": 100},
        "rsi_buy_max": {"type": float, "min": 0, "max": 100},
        "rsi_sell_min": {"type": float, "min": 0, "max": 100},
        "rsi_sell_max": {"type": float, "min": 0, "max": 100},
    },
    "volatility": {
        "atr_period": {"type": int, "min": 2, "max": 100},
        "atr_threshold": {"type": float, "min": 0.1, "max": 5.0},
        "lookback": {"type": int, "min": 10, "max": 100},
    },
    "mean_reversion": {
        "lookback": {"type": int, "min": 10, "max": 200},
        "deviation": {"type": float, "min": 0.5, "max": 4.0},
        "rsi_period": {"type": int, "min": 2, "max": 100},
    },
    "session": {
        "session_start": {"type": int, "min": 0, "max": 23},
        "session_end": {"type": int, "min": 1, "max": 24},
        "lookback": {"type": int, "min": 5, "max": 100},
    },
    "momentum": {
        "roc_period": {"type": int, "min": 2, "max": 100},
        "roc_threshold": {"type": float, "min": 0.01, "max": 10.0},
        "ema_period": {"type": int, "min": 2, "max": 300},
    },
}


def strategy_family(strategy: str, base_strategy: str | None = None) -> str:
    value = (base_strategy or strategy).lower()
    value = re.sub(r"^(xauusd|eurusd|gbpusd)_", "", value)
    value = re.sub(r"_g\d+_a\d+$", "", value)
    value = re.sub(r"_v\d+$", "", value)
    return value


def validate_strategy_parameters(
    strategy: str,
    parameters: dict[str, Any] | None,
    base_strategy: str | None = None,
) -> dict[str, Any]:
    family = strategy_family(strategy, base_strategy)
    schema = PARAMETER_SCHEMAS.get(family)
    if schema is None:
        raise ValueError(f"Parameter schema topilmadi: {family}")

    values = parameters or {}
    unknown = sorted(set(values) - set(schema))
    if unknown:
        raise ValueError(
            f"{family} uchun noma'lum parametrlar: {', '.join(unknown)}"
        )

    validated: dict[str, Any] = {}
    for key, value in values.items():
        rule = schema[key]
        expected = rule["type"]
        if expected is bool:
            if not isinstance(value, bool):
                raise ValueError(f"{family}.{key} boolean bo'lishi kerak")
            validated[key] = value
            continue

        if isinstance(value, bool) or not isinstance(value, (int, float)):
            raise ValueError(f"{family}.{key} raqam bo'lishi kerak")
        numeric = expected(value)
        if numeric < rule["min"] or numeric > rule["max"]:
            raise ValueError(
                f"{family}.{key} {rule['min']}..{rule['max']} oralig'ida bo'lishi kerak"
            )
        validated[key] = numeric

    return validated
