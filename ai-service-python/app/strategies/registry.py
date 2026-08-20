from collections.abc import Callable
from typing import Any

import pandas as pd

from app.strategies.breakout import apply_breakout_continuation_strategy, apply_breakout_strategy
from app.strategies.ema_rsi import apply_ema_rsi_strategy
from app.strategies.fibonacci import apply_fibonacci_strategy
from app.strategies.structure import apply_bos_retest_strategy, apply_choch_reversal_strategy, apply_fibonacci_structure_pullback_strategy, apply_liquidity_sweep_reversion_strategy
from app.strategies.macd_trend import apply_macd_trend_strategy
from app.strategies.laboratory import apply_differential_router_strategy, apply_differential_trend_down_router_strategy, apply_hybrid_consensus_strategy, apply_hybrid_strategy, apply_mean_reversion_rsi_strategy, apply_mean_reversion_strategy, apply_momentum_pullback_strategy, apply_momentum_strategy, apply_regime_ensemble_strategy, apply_session_mean_reversion_strategy, apply_session_strategy, apply_trend_retest_strategy, apply_trend_specialist, apply_volatility_breakout_strategy, apply_volatility_strategy
from app.services.parameter_schema import strategy_family


StrategyFunction = Callable[[pd.DataFrame, dict[str, Any] | None], pd.DataFrame]


STRATEGIES: dict[str, StrategyFunction] = {
    "ema_rsi_v1": apply_ema_rsi_strategy,
    "macd_trend_v1": apply_macd_trend_strategy,
    "fibonacci_v1": apply_fibonacci_strategy,
    "fibonacci_structure_pullback_v1": apply_fibonacci_structure_pullback_strategy,
    "bos_retest_continuation_v1": apply_bos_retest_strategy,
    "choch_reversal_v1": apply_choch_reversal_strategy,
    "liquidity_sweep_reversion_v1": apply_liquidity_sweep_reversion_strategy,
    "breakout_v1": apply_breakout_strategy,
    "breakout_continuation_v1": apply_breakout_continuation_strategy,
    "trend_v1": apply_trend_specialist,
    "trend_retest_v1": apply_trend_retest_strategy,
    "volatility_v1": apply_volatility_strategy,
    "volatility_breakout_v1": apply_volatility_breakout_strategy,
    "mean_reversion_v1": apply_mean_reversion_strategy,
    "range_rsi_reversion_v1": apply_mean_reversion_rsi_strategy,
    "session_v1": apply_session_strategy,
    "session_mean_reversion_v1": apply_session_mean_reversion_strategy,
    "momentum_v1": apply_momentum_strategy,
    "momentum_pullback_v1": apply_momentum_pullback_strategy,
    "hybrid_v1": apply_hybrid_strategy,
    "regime_consensus_v1": apply_hybrid_consensus_strategy,
    "differential_router_v1": apply_differential_router_strategy,
    "regime_ensemble_v1": apply_regime_ensemble_strategy,
}

STRATEGY_BASES: dict[str, StrategyFunction] = {
    "ema_rsi": apply_ema_rsi_strategy,
    "macd_trend": apply_macd_trend_strategy,
    "fibonacci": apply_fibonacci_strategy,
    "fibonacci_structure_pullback": apply_fibonacci_structure_pullback_strategy,
    "bos_retest_continuation": apply_bos_retest_strategy,
    "choch_reversal": apply_choch_reversal_strategy,
    "liquidity_sweep_reversion": apply_liquidity_sweep_reversion_strategy,
    "breakout": apply_breakout_strategy,
    "breakout_continuation": apply_breakout_continuation_strategy,
    "trend": apply_trend_specialist,
    "trend_retest": apply_trend_retest_strategy,
    "volatility": apply_volatility_strategy,
    "volatility_breakout": apply_volatility_breakout_strategy,
    "mean_reversion": apply_mean_reversion_strategy,
    "range_rsi_reversion": apply_mean_reversion_rsi_strategy,
    "session": apply_session_strategy,
    "session_mean_reversion": apply_session_mean_reversion_strategy,
    "momentum": apply_momentum_strategy,
    "momentum_pullback": apply_momentum_pullback_strategy,
    "hybrid": apply_hybrid_strategy,
    "regime_consensus": apply_hybrid_consensus_strategy,
    "differential_router": apply_differential_router_strategy,
    "regime_ensemble": apply_regime_ensemble_strategy,
}

STRATEGY_LABELS = {
    "ema_rsi_v1": "EMA_RSI_V1",
    "macd_trend_v1": "MACD_TREND_V1",
    "fibonacci_v1": "FIBONACCI_V1",
    "fibonacci_structure_pullback_v1": "FIBONACCI_STRUCTURE_PULLBACK_V1",
    "bos_retest_continuation_v1": "BOS_RETEST_CONTINUATION_V1",
    "choch_reversal_v1": "CHOCH_REVERSAL_V1",
    "liquidity_sweep_reversion_v1": "LIQUIDITY_SWEEP_REVERSION_V1",
    "breakout_v1": "BREAKOUT_V1",
    "breakout_continuation_v1": "BREAKOUT_CONTINUATION_V1",
    "trend_v1": "TREND_V1", "volatility_v1": "VOLATILITY_V1", "volatility_breakout_v1": "VOLATILITY_BREAKOUT_V1",
    "trend_retest_v1": "TREND_RETEST_V1",
    "mean_reversion_v1": "MEAN_REVERSION_V1", "range_rsi_reversion_v1": "RANGE_RSI_REVERSION_V1", "session_v1": "SESSION_V1", "session_mean_reversion_v1": "SESSION_MEAN_REVERSION_V1", "momentum_v1": "MOMENTUM_V1", "momentum_pullback_v1": "MOMENTUM_PULLBACK_V1",
    "hybrid_v1": "HYBRID_V1", "regime_consensus_v1": "REGIME_CONSENSUS_V1",
    "differential_router_v1": "DIFFERENTIAL_ROUTER_V1",
    "regime_ensemble_v1": "REGIME_ENSEMBLE_V1",
}

AGENT_NAMES = {
    "ema_rsi_v1": "EMA RSI Agent",
    "macd_trend_v1": "MACD Trend Agent",
    "fibonacci_v1": "Fibonacci Pullback Agent",
    "fibonacci_structure_pullback_v1": "Fibonacci Structure Pullback Agent",
    "bos_retest_continuation_v1": "BOS Retest Specialist",
    "choch_reversal_v1": "CHOCH Reversal Specialist",
    "liquidity_sweep_reversion_v1": "Liquidity Sweep Specialist",
    "breakout_v1": "Breakout Agent", "breakout_continuation_v1": "Breakout Continuation Agent",
    "trend_v1": "Trend Agent", "volatility_v1": "Volatility Agent", "volatility_breakout_v1": "Volatility Breakout Agent",
    "trend_retest_v1": "Trend Retest Agent",
    "mean_reversion_v1": "Mean Reversion Agent", "range_rsi_reversion_v1": "Range RSI Reversion Agent", "session_v1": "Session Agent", "session_mean_reversion_v1": "Session Mean Reversion Agent", "momentum_v1": "Momentum Agent", "momentum_pullback_v1": "Momentum Pullback Agent",
    "hybrid_v1": "Regime Adaptive Hybrid Agent", "regime_consensus_v1": "Regime Consensus Agent",
    "differential_router_v1": "Frozen Parent + Trend-Down Specialist Router",
    "regime_ensemble_v1": "Frozen Regime Specialist Ensemble",
}


def get_strategy(strategy_name: str, base_strategy: str | None = None) -> StrategyFunction:
    # A composite model may retain a parent architecture (for example
    # breakout_v1) in metadata, but its explicit strategy identity still owns
    # the runtime router used to generate the evidence.
    explicit_family = strategy_family(strategy_name)
    if explicit_family in {"differential_router", "regime_ensemble"}:
        return STRATEGY_BASES[explicit_family]

    normalized = (base_strategy or strategy_name).lower()
    strategy = STRATEGIES.get(normalized)

    if strategy is None:
        strategy = STRATEGY_BASES.get(_base_name(normalized))

    if strategy is None:
        raise ValueError(f"Strategiya topilmadi: {strategy_name}")

    return strategy


def strategy_label(strategy_name: str) -> str:
    return STRATEGY_LABELS.get(strategy_name.lower(), strategy_name.upper())


def list_strategies() -> list[str]:
    return list(STRATEGIES.keys())


def list_strategy_agents() -> list[dict[str, str]]:
    return [
        {
            "strategy": strategy_name,
            "label": STRATEGY_LABELS[strategy_name],
            "agent": AGENT_NAMES[strategy_name],
        }
        for strategy_name in STRATEGIES
    ]


def _base_name(strategy_name: str) -> str:
    if "_v" not in strategy_name:
        return strategy_name

    return strategy_name.rsplit("_v", 1)[0]
