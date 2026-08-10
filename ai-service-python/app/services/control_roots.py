"""Explainable control roots used as immutable strategy starting points."""

from __future__ import annotations

from typing import Any


PROTOCOL = "explainable_control_root_v1"


def control_root_for(strategy: str) -> dict[str, Any]:
    normalized = str(strategy or "").lower().replace("_v1", "")
    if normalized in {"trend", "momentum", "momentum_pullback", "trend_retest"}:
        root_id = "volatility_scaled_time_series_momentum"
        genes = ["roc_period", "roc_threshold", "ema_period", "atr_stop_multiplier", "atr_target_multiplier", "time_stop_candles"]
    elif normalized in {"breakout", "breakout_continuation", "volatility", "volatility_breakout"}:
        root_id = "multi_horizon_atr_breakout"
        genes = ["lookback", "confirmation_candles", "atr_period", "atr_multiplier", "atr_stop_multiplier", "atr_target_multiplier", "time_stop_candles"]
    elif normalized in {"mean_reversion", "range_rsi_reversion", "session_mean_reversion", "session"}:
        root_id = "low_volatility_range_mean_reversion"
        genes = ["lookback", "deviation", "adx_max", "low_volatility_only", "atr_stop_multiplier", "time_stop_candles"]
    elif normalized in {"hybrid", "regime_consensus", "regime_ensemble", "differential_router"}:
        root_id = "regime_router_with_wait"
        genes = ["trend_weight", "breakout_weight", "mean_reversion_weight", "minimum_confidence", "high_volatility_wait", "transition_firewall_enabled"]
    else:
        root_id = "conservative_atr_time_stop_wait"
        genes = ["atr_stop_multiplier", "atr_target_multiplier", "time_stop_candles", "minimum_signal_confidence"]

    return {
        "protocol": PROTOCOL,
        "root_id": root_id,
        "strategy": strategy,
        "baseline_components": {
            "volatility_scaling": True,
            "multi_horizon_confirmation": "momentum" in root_id or "breakout" in root_id,
            "atr_stop_target": True,
            "time_stop": True,
            "low_volatility_filter": "range" in root_id,
            "regime_router": "router" in root_id,
            "wait_state": True,
        },
        "allowed_mutation_genes": genes,
        "one_gene_rule": "One control-root gene per generation; signal family, data and execution contract stay frozen.",
        "rl_signal_authority": False,
        "llm_gate_authority": False,
        "rl_allowed_after": "paper_only_position_sizing_or_execution",
        "promotion_evidence": False,
    }
