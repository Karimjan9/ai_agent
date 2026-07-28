from __future__ import annotations

import re
from typing import Any


PARAMETER_SCHEMAS: dict[str, dict[str, dict[str, Any]]] = {
    "breakout": {
        "lookback": {"type": int, "min": 10, "max": 100},
        "atr_period": {"type": int, "min": 2, "max": 100},
        "atr_multiplier": {"type": float, "min": 0.1, "max": 3.0},
        "confirmation_candles": {"type": int, "min": 1, "max": 5},
        "retest_required": {"type": bool},
        "trend_strength_min": {"type": float, "min": 10, "max": 50},
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
        "trend_strength_min": {"type": float, "min": 10, "max": 50},
        "pullback_atr_fraction": {"type": float, "min": 0.1, "max": 2.0},
    },
    "volatility": {
        "atr_period": {"type": int, "min": 2, "max": 100},
        "atr_threshold": {"type": float, "min": 0.1, "max": 5.0},
        "lookback": {"type": int, "min": 10, "max": 100},
        "compression_ratio": {"type": float, "min": 0.3, "max": 1.0},
        "expansion_multiplier": {"type": float, "min": 1.0, "max": 3.0},
    },
    "mean_reversion": {
        "lookback": {"type": int, "min": 10, "max": 200},
        "deviation": {"type": float, "min": 0.5, "max": 4.0},
        "rsi_period": {"type": int, "min": 2, "max": 100},
        "adx_max": {"type": float, "min": 5, "max": 35},
        "low_volatility_only": {"type": bool},
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
    "hybrid": {
        "trend_weight": {"type": float, "min": 0.0, "max": 3.0},
        "breakout_weight": {"type": float, "min": 0.0, "max": 3.0},
        "mean_reversion_weight": {"type": float, "min": 0.0, "max": 3.0},
        "minimum_confidence": {"type": float, "min": 0.1, "max": 6.0},
        "high_volatility_wait": {"type": bool},
    },
    "regime_ensemble": {
        "atr_period": {"type": int, "min": 2, "max": 100},
        "lookback": {"type": int, "min": 10, "max": 100},
        "trend_strength_min": {"type": float, "min": 10, "max": 50},
        "pullback_atr_fraction": {"type": float, "min": 0.1, "max": 2.0},
        "session_start": {"type": int, "min": 0, "max": 23},
        "session_end": {"type": int, "min": 1, "max": 24},
        "adx_max": {"type": float, "min": 5, "max": 35},
        "deviation": {"type": float, "min": 0.5, "max": 4.0},
    },
}

# These controls are intentionally shared by every signal family.  They make
# the evolutionary search vary trade management and execution safety, instead
# of repeatedly trying nearly identical indicator values with a fixed 0.5/1.0
# percent exit.  A value of zero disables the optional control.
EXECUTION_PARAMETER_SCHEMA: dict[str, dict[str, Any]] = {
    "atr_stop_multiplier": {"type": float, "min": 0.5, "max": 4.0},
    "atr_target_multiplier": {"type": float, "min": 0.75, "max": 8.0},
    "trailing_atr_multiplier": {"type": float, "min": 0.0, "max": 4.0},
    "time_stop_candles": {"type": int, "min": 0, "max": 240},
    "high_volatility_risk_multiplier": {"type": float, "min": 0.1, "max": 1.0},
    "max_spread_atr_ratio": {"type": float, "min": 0.01, "max": 0.5},
    "avoid_high_volatility": {"type": bool},
    "minimum_signal_confidence": {"type": float, "min": 0.0, "max": 1.0},
    "max_loss_streak_before_wait": {"type": int, "min": 1, "max": 10},
    "loss_cooldown_candles": {"type": int, "min": 1, "max": 48},
    # Dynamic cooldown is an online policy: it may inspect only shadow trades
    # whose exits were already observable at the current candle.
    "dynamic_cooldown_enabled": {"type": bool},
    "cooldown_shadow_min_samples": {"type": int, "min": 3, "max": 50},
    "cooldown_shadow_edge_pf": {"type": float, "min": 0.8, "max": 2.0},
    # Meta labeling is intentionally online: its label store contains only
    # closed earlier trades, never outcomes from the active test window.
    "meta_label_enabled": {"type": bool},
    "meta_label_min_history": {"type": int, "min": 5, "max": 100},
    "meta_label_min_pf": {"type": float, "min": 0.5, "max": 2.0},
    "meta_label_risk_multiplier": {"type": float, "min": 0.1, "max": 1.0},
    "partial_take_profit_fraction": {"type": float, "min": 0.0, "max": 0.75},
    "partial_target_atr_multiplier": {"type": float, "min": 0.25, "max": 4.0},
}


def strategy_family(strategy: str, base_strategy: str | None = None) -> str:
    value = (base_strategy or strategy).lower()
    value = re.sub(r"^(xauusd|eurusd|gbpusd)_", "", value)
    value = re.sub(r"_g\d+_a\d+$", "", value)
    value = re.sub(r"_v\d+$", "", value)
    return {
        "trend_retest": "trend",
        "breakout_retest": "breakout",
        "breakout_continuation": "breakout",
        "volatility_compression_expansion": "volatility",
        "volatility_breakout": "volatility",
        "range_mean_reversion": "mean_reversion",
        "range_rsi_reversion": "mean_reversion",
        "session_breakout": "session",
        "session_mean_reversion": "session",
        "momentum_continuation": "momentum",
        "momentum_pullback": "momentum",
        "regime_router": "hybrid",
        "regime_consensus": "hybrid",
        "regime_ensemble": "regime_ensemble",
    }.get(value, value)


def validate_strategy_parameters(
    strategy: str,
    parameters: dict[str, Any] | None,
    base_strategy: str | None = None,
) -> dict[str, Any]:
    family = strategy_family(strategy, base_strategy)
    base_schema = PARAMETER_SCHEMAS.get(family)
    schema = {**base_schema, **EXECUTION_PARAMETER_SCHEMA} if base_schema else None
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
