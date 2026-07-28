import unittest

from app.services.statistical_validation import (
    cscv_probability_of_backtest_overfitting,
    deflated_sharpe_ratio,
)


class StatisticalValidationTest(unittest.TestCase):
    def test_cscv_reports_pbo_for_four_replay_checkpoints(self):
        result = cscv_probability_of_backtest_overfitting([
            [90, 90, 10, 10],
            [10, 10, 90, 90],
            [50, 50, 50, 50],
        ])

        self.assertEqual("assessed", result["status"])
        self.assertEqual(6, result["split_count"])
        self.assertGreater(result["probability_of_backtest_overfitting"], 0)

    def test_cscv_refuses_odd_or_insufficient_checkpoint_windows(self):
        result = cscv_probability_of_backtest_overfitting([[1, 2, 3], [3, 2, 1]])

        self.assertEqual("insufficient_data", result["status"])

    def test_deflated_sharpe_accounts_for_multiple_trials(self):
        returns = [0.01, 0.02, -0.005, 0.018, 0.01, -0.002, 0.014, 0.009, -0.004, 0.012]
        result = deflated_sharpe_ratio(returns, [0.2, 0.4, 0.1, 0.3])

        self.assertEqual("assessed", result["status"])
        self.assertEqual(4, result["number_of_trials"])
        self.assertGreaterEqual(result["deflated_sharpe_probability"], 0)
        self.assertLessEqual(result["deflated_sharpe_probability"], 1)


if __name__ == "__main__":
    unittest.main()
