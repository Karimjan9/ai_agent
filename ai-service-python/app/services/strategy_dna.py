from typing import Any


class StrategyDnaService:
    def generate(self, result: dict[str, Any], trades: list[dict[str, Any]]) -> dict[str, Any]:
        regime_performance = result.get("regime_performance", {}) or {}
        volatility_performance = result.get("volatility_performance", {}) or {}
        equity_curve = result.get("equity_curve", []) or []
        monte_carlo = result.get("monte_carlo", {}) or {}

        aggression_score = self._aggression_score(result)
        trend_dependency = self._trend_dependency(regime_performance)
        range_dependency = self._range_dependency(regime_performance)
        volatility_sensitivity = self._volatility_sensitivity(volatility_performance)
        adaptability_score = self._adaptability_score(regime_performance)
        recovery_score = self._recovery_score(equity_curve)
        survival_score = self._survival_score(result, monte_carlo, recovery_score)

        dna = {
            "aggression_score": aggression_score,
            "trend_dependency": trend_dependency,
            "range_dependency": range_dependency,
            "volatility_sensitivity": volatility_sensitivity,
            "adaptability_score": adaptability_score,
            "recovery_score": recovery_score,
            "survival_score": survival_score,
        }
        dna["dna_summary"] = self._summary(dna, result, trades)

        return dna

    def _aggression_score(self, result: dict[str, Any]) -> int:
        total_trades = float(result.get("total_trades", 0) or 0)
        max_drawdown = float(result.get("max_drawdown_percent", result.get("max_drawdown", 0)) or 0)
        risk_reward = float(result.get("risk_reward_ratio", 0) or 0)

        trade_component = min(total_trades / 500, 1) * 50
        drawdown_component = min(max_drawdown / 25, 1) * 35
        risk_reward_component = min(risk_reward / 3, 1) * 15

        return self._clamp(trade_component + drawdown_component + risk_reward_component)

    def _trend_dependency(self, regime_performance: dict[str, dict[str, Any]]) -> int:
        trend_profit = 0.0
        total_positive_profit = 0.0

        for regime, data in regime_performance.items():
            profit = float(data.get("profit_percent", 0) or 0)
            if profit > 0:
                total_positive_profit += profit
            if "trend" in regime and profit > 0:
                trend_profit += profit

        if total_positive_profit <= 0:
            return 0

        return self._clamp((trend_profit / total_positive_profit) * 100)

    def _range_dependency(self, regime_performance: dict[str, dict[str, Any]]) -> int:
        range_profit = 0.0
        total_positive_profit = 0.0

        for regime, data in regime_performance.items():
            profit = float(data.get("profit_percent", 0) or 0)
            if profit > 0:
                total_positive_profit += profit
            if "range" in regime and profit > 0:
                range_profit += profit

        if total_positive_profit <= 0:
            return 0

        return self._clamp((range_profit / total_positive_profit) * 100)

    def _volatility_sensitivity(self, volatility_performance: dict[str, dict[str, Any]]) -> int:
        profits = [
            float(data.get("profit_percent", 0) or 0)
            for data in volatility_performance.values()
            if int(data.get("trades", 0) or 0) > 0
        ]
        if len(profits) <= 1:
            return 0

        return self._clamp(min(max(profits) - min(profits), 50) * 2)

    def _adaptability_score(self, regime_performance: dict[str, dict[str, Any]]) -> int:
        active_regimes = [
            data for data in regime_performance.values()
            if int(data.get("trades", 0) or 0) > 0
        ]
        if not active_regimes:
            return 0

        profitable = [
            data for data in active_regimes
            if float(data.get("profit_percent", 0) or 0) > 0
        ]
        breadth = len(profitable) / max(len(active_regimes), 1)
        return self._clamp(20 + (breadth * 80))

    def _recovery_score(self, equity_curve: list[float]) -> int:
        if len(equity_curve) < 2:
            return 0

        peak = float(equity_curve[0])
        recovery_durations: list[int] = []
        current_drawdown_length = 0

        for equity in equity_curve[1:]:
            value = float(equity)
            if value >= peak:
                if current_drawdown_length > 0:
                    recovery_durations.append(current_drawdown_length)
                    current_drawdown_length = 0
                peak = value
                continue

            current_drawdown_length += 1

        if current_drawdown_length > 0:
            recovery_durations.append(current_drawdown_length * 2)

        if not recovery_durations:
            return 100

        average_recovery = sum(recovery_durations) / len(recovery_durations)
        return self._clamp(100 - min(average_recovery, 50) * 2)

    def _survival_score(
        self,
        result: dict[str, Any],
        monte_carlo: dict[str, Any],
        recovery_score: int,
    ) -> int:
        robustness = float(result.get("robustness_score", 0) or 0)
        drawdown = float(result.get("max_drawdown_percent", result.get("max_drawdown", 0)) or 0)
        risk_of_ruin = float(monte_carlo.get("risk_of_ruin_percent", 0) or 0)
        worst_drawdown = float(monte_carlo.get("worst_drawdown_percent", drawdown) or 0)

        mc_component = max(0, 100 - (risk_of_ruin * 2) - max(0, worst_drawdown - 25))
        drawdown_component = max(0, 100 - (drawdown * 3))

        return self._clamp(
            (mc_component * 0.35)
            + (robustness * 0.25)
            + (drawdown_component * 0.20)
            + (recovery_score * 0.20)
        )

    def _summary(
        self,
        dna: dict[str, int],
        result: dict[str, Any],
        trades: list[dict[str, Any]],
    ) -> str:
        strategy = result.get("strategy", "Strategy")
        risk_level = "low-risk"
        if dna["aggression_score"] >= 70:
            risk_level = "aggressive"
        elif dna["aggression_score"] >= 40:
            risk_level = "medium-risk"

        focus = "balanced"
        if dna["trend_dependency"] >= 70:
            focus = "trend-focused"
        elif dna["range_dependency"] >= 60:
            focus = "range-focused"

        recovery = "weak"
        if dna["recovery_score"] >= 75:
            recovery = "strong"
        elif dna["recovery_score"] >= 50:
            recovery = "acceptable"

        survival = "low"
        if dna["survival_score"] >= 80:
            survival = "high"
        elif dna["survival_score"] >= 60:
            survival = "moderate"

        trade_note = "no trade sample"
        if trades:
            trade_note = f"{len(trades)} recent trades"

        return (
            f"{strategy} is a {focus} {risk_level} strategy based on {trade_note}. "
            f"Recovery capability is {recovery}, and survival quality is {survival}."
        )

    def _clamp(self, value: float) -> int:
        return round(max(min(value, 100), 0))
