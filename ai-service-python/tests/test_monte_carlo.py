import unittest

from app.services.monte_carlo import MonteCarloService


class MonteCarloServiceTest(unittest.TestCase):
    def test_monte_carlo_returns_expected_keys(self):
        result = MonteCarloService(simulations=25, starting_balance=10000, seed=1).run([
            {"profit_percent": 2},
            {"profit_percent": -1},
            {"profit_percent": 3},
            {"profit_percent": -2},
        ])

        self.assertEqual(result["simulations"], 25)
        self.assertIn("worst_profit_percent", result)
        self.assertIn("avg_profit_percent", result)
        self.assertIn("best_profit_percent", result)
        self.assertIn("worst_drawdown_percent", result)
        self.assertIn("risk_of_ruin_percent", result)
        self.assertIn("worst_equity_curve", result)
        self.assertIn("best_equity_curve", result)
        self.assertEqual(result["seed"], 1)
        self.assertEqual(result["method"], "stationary_block_bootstrap_with_replacement")
        self.assertLess(result["worst_profit_percent"], result["best_profit_percent"])

    def test_monte_carlo_empty_trades(self):
        result = MonteCarloService(simulations=25, starting_balance=10000).run([])

        self.assertEqual(result["worst_profit_percent"], 0)
        self.assertEqual(result["risk_of_ruin_percent"], 0)
        self.assertEqual(result["worst_equity_curve"], [])

    def test_monte_carlo_risk_of_ruin_detects_bad_paths(self):
        result = MonteCarloService(
            simulations=25,
            starting_balance=10000,
            ruin_drawdown_percent=30,
            seed=1,
        ).run([
            {"profit_percent": -35},
            {"profit_percent": 2},
            {"profit_percent": -5},
        ])

        self.assertGreater(result["risk_of_ruin_percent"], 0)
        self.assertGreaterEqual(result["worst_drawdown_percent"], 30)

    def test_same_seed_is_reproducible(self):
        trades = [
            {"profit_percent": 2, "execution_cost_percent": 0.1, "market_regime": "trend"},
            {"profit_percent": -1, "execution_cost_percent": 0.1, "market_regime": "range"},
            {"profit_percent": 3, "execution_cost_percent": 0.2, "market_regime": "trend"},
            {"profit_percent": -2, "execution_cost_percent": 0.1, "market_regime": "volatile"},
        ]

        first = MonteCarloService(simulations=50, seed=123).run(trades)
        second = MonteCarloService(simulations=50, seed=123).run(trades)

        self.assertEqual(first, second)


if __name__ == "__main__":
    unittest.main()
