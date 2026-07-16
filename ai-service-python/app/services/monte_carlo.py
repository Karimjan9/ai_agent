import hashlib
import json
import math
import random
import statistics
from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class MonteCarloService:
    """Reproducible, dependency-aware bootstrap with execution stress.

    A simple shuffle only changes drawdown ordering: the compounded terminal
    result is identical for every path. Sampling contiguous blocks with
    replacement preserves some serial/regime dependence while allowing both
    trade frequency and terminal outcomes to vary.
    """

    simulations: int = 1000
    starting_balance: float = 10000.0
    ruin_drawdown_percent: float = 30.0
    seed: int | None = None
    block_size: int | None = None
    execution_cost_stress: float = 0.50
    regime_stress_probability: float = 0.20
    tail_shock_probability: float = 0.02

    def run(self, trades: list[dict[str, Any]]) -> dict[str, Any]:
        if not trades:
            return self.empty_result()

        seed = self.seed if self.seed is not None else self._derived_seed(trades)
        rng = random.Random(seed)
        sample_size = len(trades)
        block_size = self.block_size or max(1, min(sample_size, round(math.sqrt(sample_size))))
        regimes = sorted({str(trade.get("market_regime") or "unknown") for trade in trades})
        paths = []

        for _ in range(self.simulations):
            sampled = self._block_bootstrap(trades, sample_size, block_size, rng)
            stressed_regime = rng.choice(regimes) if regimes and rng.random() < self.regime_stress_probability else None
            balance = self.starting_balance
            peak = balance
            max_drawdown = 0.0
            equity_curve = []

            for trade in sampled:
                profit_percent = self._stressed_profit(trade, stressed_regime, rng)
                balance = max(0.0, balance * (1 + (profit_percent / 100)))
                peak = max(peak, balance)
                drawdown = ((peak - balance) / peak) * 100 if peak > 0 else 100.0
                max_drawdown = max(max_drawdown, drawdown)
                equity_curve.append(round(balance, 2))

            paths.append({
                "final_balance": balance,
                "net_profit_percent": ((balance - self.starting_balance) / self.starting_balance) * 100,
                "max_drawdown": max_drawdown,
                "equity_curve": equity_curve,
            })

        profits = [path["net_profit_percent"] for path in paths]
        drawdowns = [path["max_drawdown"] for path in paths]
        worst_path = min(paths, key=lambda path: path["net_profit_percent"])
        best_path = max(paths, key=lambda path: path["net_profit_percent"])
        ruin_balance = self.starting_balance * (1 - self.ruin_drawdown_percent / 100)
        ruin_count = len([
            path for path in paths
            if path["max_drawdown"] >= self.ruin_drawdown_percent
            or path["final_balance"] <= ruin_balance
        ])

        return {
            "simulations": self.simulations,
            "seed": seed,
            "method": "stationary_block_bootstrap_with_replacement",
            "block_size": block_size,
            "execution_cost_stress": self.execution_cost_stress,
            "regime_stress_probability": self.regime_stress_probability,
            "worst_profit_percent": round(min(profits), 2),
            "avg_profit_percent": round(statistics.mean(profits), 2),
            "best_profit_percent": round(max(profits), 2),
            "profit_percentiles": {
                "p05": self._percentile(profits, 0.05),
                "p50": self._percentile(profits, 0.50),
                "p95": self._percentile(profits, 0.95),
            },
            "worst_drawdown_percent": round(max(drawdowns), 2),
            "avg_drawdown_percent": round(statistics.mean(drawdowns), 2),
            "risk_of_ruin_percent": round((ruin_count / self.simulations) * 100, 2),
            "worst_equity_curve": worst_path["equity_curve"],
            "best_equity_curve": best_path["equity_curve"],
        }

    @staticmethod
    def _block_bootstrap(
        trades: list[dict[str, Any]],
        sample_size: int,
        block_size: int,
        rng: random.Random,
    ) -> list[dict[str, Any]]:
        sampled: list[dict[str, Any]] = []
        while len(sampled) < sample_size:
            start = rng.randrange(len(trades))
            for offset in range(block_size):
                sampled.append(trades[(start + offset) % len(trades)])
                if len(sampled) == sample_size:
                    break
        return sampled

    def _stressed_profit(
        self,
        trade: dict[str, Any],
        stressed_regime: str | None,
        rng: random.Random,
    ) -> float:
        profit = float(trade.get("profit_percent", 0) or 0)
        recorded_cost = abs(float(trade.get("execution_cost_percent", 0) or 0))
        # Stress only additional cost: the recorded profit already contains the
        # baseline spread/slippage/commission/swap deductions.
        profit -= recorded_cost * rng.uniform(0, self.execution_cost_stress)

        regime = str(trade.get("market_regime") or "unknown")
        if stressed_regime is not None and regime == stressed_regime:
            profit = profit * (0.75 if profit > 0 else 1.25)

        if rng.random() < self.tail_shock_probability:
            profit -= max(abs(profit) * 0.50, recorded_cost)

        return max(profit, -99.0)

    @staticmethod
    def _derived_seed(trades: list[dict[str, Any]]) -> int:
        canonical = json.dumps(trades, sort_keys=True, separators=(",", ":"), default=str)
        return int(hashlib.sha256(canonical.encode("utf-8")).hexdigest()[:8], 16)

    @staticmethod
    def _percentile(values: list[float], quantile: float) -> float:
        ordered = sorted(values)
        index = min(len(ordered) - 1, max(0, round((len(ordered) - 1) * quantile)))
        return round(ordered[index], 2)

    def empty_result(self) -> dict[str, Any]:
        return {
            "simulations": self.simulations,
            "seed": self.seed,
            "method": "stationary_block_bootstrap_with_replacement",
            "block_size": self.block_size or 0,
            "execution_cost_stress": self.execution_cost_stress,
            "regime_stress_probability": self.regime_stress_probability,
            "worst_profit_percent": 0,
            "avg_profit_percent": 0,
            "best_profit_percent": 0,
            "profit_percentiles": {"p05": 0, "p50": 0, "p95": 0},
            "worst_drawdown_percent": 0,
            "avg_drawdown_percent": 0,
            "risk_of_ruin_percent": 0,
            "worst_equity_curve": [],
            "best_equity_curve": [],
        }
