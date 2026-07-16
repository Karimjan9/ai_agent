import unittest

from app.services.strategy_dna import StrategyDnaService


class StrategyDnaServiceTest(unittest.TestCase):
    def test_strategy_dna_returns_expected_keys(self):
        result = {
            "strategy": "EMA_RSI_V8",
            "total_trades": 120,
            "max_drawdown_percent": 8,
            "risk_reward_ratio": 1.8,
            "equity_curve": [10000, 9800, 10100, 10300],
            "regime_performance": {
                "trend_up": {"trades": 20, "profit_percent": 12},
                "trend_down": {"trades": 18, "profit_percent": 8},
                "range": {"trades": 12, "profit_percent": -3},
            },
            "volatility_performance": {
                "high_volatility": {"trades": 10, "profit_percent": 10},
                "low_volatility": {"trades": 10, "profit_percent": -4},
            },
            "monte_carlo": {
                "risk_of_ruin_percent": 2,
                "worst_drawdown_percent": 18,
            },
        }

        dna = StrategyDnaService().generate(result, [{"profit_percent": 1}])

        self.assertIn("aggression_score", dna)
        self.assertIn("trend_dependency", dna)
        self.assertIn("range_dependency", dna)
        self.assertIn("volatility_sensitivity", dna)
        self.assertIn("adaptability_score", dna)
        self.assertIn("recovery_score", dna)
        self.assertIn("survival_score", dna)
        self.assertIn("dna_summary", dna)

    def test_trend_dependency_uses_positive_trend_profit_share(self):
        dna = StrategyDnaService().generate({
            "regime_performance": {
                "trend_up": {"trades": 10, "profit_percent": 9},
                "range": {"trades": 10, "profit_percent": 1},
            },
        }, [])

        self.assertEqual(dna["trend_dependency"], 90)


if __name__ == "__main__":
    unittest.main()
