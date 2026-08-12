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
        # Each lane has its own bounded operating envelope.  Keeping these
        # genes explicit prevents a calendar/temporal rescue from mutating a
        # fixed hidden specialist and then claiming causal credit for the
        # whole hybrid.
        "trend_roc_period": {"type": int, "min": 4, "max": 60},
        "trend_roc_threshold": {"type": float, "min": 0.01, "max": 5.0},
        "trend_ema_period": {"type": int, "min": 10, "max": 300},
        "breakout_atr_period": {"type": int, "min": 2, "max": 100},
        "breakout_atr_threshold": {"type": float, "min": 0.1, "max": 5.0},
        "breakout_lookback": {"type": int, "min": 10, "max": 100},
        "breakout_compression_ratio": {"type": float, "min": 0.3, "max": 1.0},
        "breakout_expansion_multiplier": {"type": float, "min": 1.0, "max": 3.0},
        "range_lookback": {"type": int, "min": 10, "max": 200},
        "range_deviation": {"type": float, "min": 0.5, "max": 4.0},
        "range_adx_max": {"type": float, "min": 5, "max": 35},
        "range_low_volatility_only": {"type": bool},
        "range_reentry_required": {"type": bool},
        "range_signal_mode": {"type": str, "choices": {"reentry", "mean_reversion", "inverse_extreme", "mid_cross"}},
        "session_filter_enabled": {"type": bool},
        "session_start": {"type": int, "min": 0, "max": 23},
        "session_end": {"type": int, "min": 1, "max": 24},
    },
    "differential_router": {
        "trend_weight": {"type": float, "min": 0.0, "max": 3.0},
        "breakout_weight": {"type": float, "min": 0.0, "max": 3.0},
        "mean_reversion_weight": {"type": float, "min": 0.0, "max": 3.0},
        "minimum_confidence": {"type": float, "min": 0.1, "max": 6.0},
        # Differential recall is allowed to lower the entry threshold only
        # on the declared target regime; the replay layer preserves the
        # parent threshold everywhere else.
        "differential_target_min_signal_confidence": {"type": float, "min": 0.0, "max": 1.0},
        "high_volatility_wait": {"type": bool},
        "trend_down_strength_min": {"type": float, "min": 10, "max": 50},
        "trend_down_pullback_atr_fraction": {"type": float, "min": 0.1, "max": 2.0},
        "trend_down_risk_multiplier": {"type": float, "min": 0.1, "max": 1.0},
        "trend_up_risk_multiplier": {"type": float, "min": 0.1, "max": 1.0},
        "trend_up_strength_min": {"type": float, "min": 10, "max": 50},
        "trend_up_pullback_atr_fraction": {"type": float, "min": 0.1, "max": 2.0},
        # v2 differential lanes reuse the parent hybrid momentum topology and
        # mutate only the declared regime's ROC/EMA envelope.  v1 remains the
        # compatibility path for sealed historical models.
        "trend_up_roc_period": {"type": int, "min": 4, "max": 60},
        "trend_up_roc_threshold": {"type": float, "min": 0.01, "max": 5.0},
        "trend_up_ema_period": {"type": int, "min": 10, "max": 300},
        "trend_down_roc_period": {"type": int, "min": 4, "max": 60},
        "trend_down_roc_threshold": {"type": float, "min": 0.01, "max": 5.0},
        "trend_down_ema_period": {"type": int, "min": 10, "max": 300},
        "range_lookback": {"type": int, "min": 10, "max": 200},
        "range_deviation": {"type": float, "min": 0.5, "max": 4.0},
        "range_adx_max": {"type": float, "min": 5, "max": 35},
        "range_low_volatility_only": {"type": bool},
        "range_reentry_required": {"type": bool},
        "range_signal_mode": {"type": str, "choices": {"reentry", "mean_reversion", "inverse_extreme", "mid_cross"}},
        "trend_roc_period": {"type": int, "min": 4, "max": 60},
        "trend_roc_threshold": {"type": float, "min": 0.01, "max": 5.0},
        "trend_ema_period": {"type": int, "min": 10, "max": 300},
        "breakout_atr_period": {"type": int, "min": 2, "max": 100},
        "breakout_atr_threshold": {"type": float, "min": 0.1, "max": 5.0},
        "breakout_lookback": {"type": int, "min": 10, "max": 100},
        "breakout_compression_ratio": {"type": float, "min": 0.3, "max": 1.0},
        "breakout_expansion_multiplier": {"type": float, "min": 1.0, "max": 3.0},
        "session_filter_enabled": {"type": bool},
        "session_start": {"type": int, "min": 0, "max": 23},
        "session_end": {"type": int, "min": 1, "max": 24},
        "differential_target_session_filter_enabled": {"type": bool},
        "differential_target_session_start": {"type": int, "min": 0, "max": 23},
        "differential_target_session_end": {"type": int, "min": 1, "max": 24},
        "differential_target_regime": {"type": str, "choices": {"trend_up", "range", "trend_down"}},
        "differential_replay_mode": {"type": str, "choices": {"portfolio", "paired_isolated"}},
        "differential_router_version": {"type": str, "choices": {"v1", "v2"}},
    },
    "regime_ensemble": {
        "atr_period": {"type": int, "min": 2, "max": 100},
        "lookback": {"type": int, "min": 10, "max": 100},
        "trend_strength_min": {"type": float, "min": 10, "max": 50},
        "pullback_atr_fraction": {"type": float, "min": 0.1, "max": 2.0},
        # trend_down is its own operating envelope.  These values are used
        # only by the frozen trend-down specialist; they do not perturb the
        # trend-up or range specialists.
        "trend_down_strength_min": {"type": float, "min": 10, "max": 50},
        "trend_down_pullback_atr_fraction": {"type": float, "min": 0.1, "max": 2.0},
        "trend_down_risk_multiplier": {"type": float, "min": 0.1, "max": 1.0},
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
    # Optional context-specialist lane.  It is intentionally shared by all
    # families so a sealed child can be compared with its exact no-volume
    # control without changing the alpha topology.
    "volume_lane": {
        "type": str,
        "choices": {
            "none",
            "breakout_volume_confirmation",
            "transition_volume_router",
            "low_volume_risk_firewall",
        },
    },
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
    "loss_streak_wait_candles": {"type": int, "min": 1, "max": 96},
    "recovery_probe_risk_multiplier": {"type": float, "min": 0.1, "max": 1.0},
    "weak_regime_min_samples": {"type": int, "min": 15, "max": 100},
    "weak_regime_wait_candles": {"type": int, "min": 1, "max": 96},
    "transition_firewall_enabled": {"type": bool},
    "transition_wait_candles": {"type": int, "min": 1, "max": 6},
    "confidence_calibration_enabled": {"type": bool},
    "confidence_calibration_min_samples": {"type": int, "min": 15, "max": 200},
    "confidence_ev_lower_bound_enabled": {"type": bool},
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


def _normalise_strategy_identity(value: str | None) -> str:
    identity = (value or "").lower()
    identity = re.sub(r"^(xauusd|eurusd|gbpusd)_", "", identity)
    identity = re.sub(r"_g\d+_a\d+$", "", identity)
    identity = re.sub(r"_v\d+$", "", identity)
    return identity


def _strategy_family_alias(value: str) -> str:
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
        "differential_router": "differential_router",
        "regime_ensemble": "regime_ensemble",
    }.get(value, value)


def strategy_family(strategy: str, base_strategy: str | None = None) -> str:
    # Composite agents own their runtime identity. Their generated parameters
    # intentionally contain the parent topology plus specialist genes, so a
    # stale architectural base such as breakout_v1 must not select breakout's
    # schema or execution function.
    strategy_value = _strategy_family_alias(_normalise_strategy_identity(strategy))
    if strategy_value in {"differential_router", "regime_ensemble"}:
        return strategy_value
    if base_strategy:
        return _strategy_family_alias(_normalise_strategy_identity(base_strategy))
    return strategy_value


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

        if expected is str:
            choices = rule.get("choices", set())
            if not isinstance(value, str) or (choices and value not in choices):
                raise ValueError(f"{family}.{key} qiymati ruxsat etilmagan.")
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

    # Range-valid is not necessarily strategy-valid. Keep the runtime
    # contract aligned with Laravel's generator so an old or externally
    # supplied model cannot silently run an inverted EMA/RSI topology.
    if family in {"trend", "ema_rsi"}:
        if "ema_fast" in validated and "ema_slow" in validated:
            fast = int(validated["ema_fast"])
            slow = int(validated["ema_slow"])
            if fast >= slow:
                validated["ema_slow"] = min(500, max(3, fast + 1))
        for lower, upper in (("rsi_buy_min", "rsi_buy_max"), ("rsi_sell_min", "rsi_sell_max")):
            if lower in validated and upper in validated and validated[lower] > validated[upper]:
                validated[lower], validated[upper] = validated[upper], validated[lower]

    if family == "macd_trend" and "macd_fast" in validated and "macd_slow" in validated:
        fast = int(validated["macd_fast"])
        slow = int(validated["macd_slow"])
        if fast >= slow:
            validated["macd_slow"] = min(200, max(3, fast + 1))

    return validated
