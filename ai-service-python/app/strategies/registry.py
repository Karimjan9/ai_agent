from collections.abc import Callable

import pandas as pd

from app.strategies.breakout import apply_breakout_strategy
from app.strategies.ema_rsi import apply_ema_rsi_strategy
from app.strategies.fibonacci import apply_fibonacci_strategy
from app.strategies.macd_trend import apply_macd_trend_strategy


STRATEGIES: dict[str, Callable[[pd.DataFrame], pd.DataFrame]] = {
    "ema_rsi_v1": apply_ema_rsi_strategy,
    "macd_trend_v1": apply_macd_trend_strategy,
    "fibonacci_v1": apply_fibonacci_strategy,
    "breakout_v1": apply_breakout_strategy,
}

STRATEGY_LABELS = {
    "ema_rsi_v1": "EMA_RSI_V1",
    "macd_trend_v1": "MACD_TREND_V1",
    "fibonacci_v1": "FIBONACCI_V1",
    "breakout_v1": "BREAKOUT_V1",
}

AGENT_NAMES = {
    "ema_rsi_v1": "EMA RSI Agent",
    "macd_trend_v1": "MACD Trend Agent",
    "fibonacci_v1": "Fibonacci Pullback Agent",
    "breakout_v1": "Breakout Agent",
}


def get_strategy(strategy_name: str) -> Callable[[pd.DataFrame], pd.DataFrame]:
    normalized = strategy_name.lower()
    strategy = STRATEGIES.get(normalized)

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
