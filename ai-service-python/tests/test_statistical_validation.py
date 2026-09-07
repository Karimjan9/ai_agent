import unittest
import random

from app.services.statistical_validation import (
    cscv_probability_of_backtest_overfitting,
    deflated_sharpe_ratio,
    purged_cscv_probability_of_backtest_overfitting,
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

    def test_purged_cscv_refuses_a_single_candidate(self):
        intervals = [
            {"start": index, "end": index + 1, "label_start": index, "label_end": index + 1}
            for index in range(4)
        ]

        result = purged_cscv_probability_of_backtest_overfitting(
            [[1, 2, 3, 4]], intervals
        )

        self.assertEqual("insufficient_data", result["status"])
        self.assertEqual(1, result["candidate_count"])
        self.assertFalse(result["promotion_evidence"])

    def test_deflated_sharpe_accounts_for_multiple_trials(self):
        returns = [0.01, 0.02, -0.005, 0.018, 0.01, -0.002, 0.014, 0.009, -0.004, 0.012]
        result = deflated_sharpe_ratio(returns, [0.2, 0.4, 0.1, 0.3])

        self.assertEqual("assessed", result["status"])
        self.assertEqual(4, result["number_of_trials"])
        self.assertGreaterEqual(result["deflated_sharpe_probability"], 0)
        self.assertLessEqual(result["deflated_sharpe_probability"], 1)

    def test_deflated_sharpe_uses_raw_kurtosis_equivalent_denominator(self):
        rng = random.Random(17)
        returns = [rng.gauss(0, 1) + 0.55 for _ in range(60)]
        result = deflated_sharpe_ratio(returns, [-0.1, 0.0, 0.1, 0.2])

        self.assertEqual("assessed", result["status"])
        self.assertEqual("bailey_lopez_de_prado_dsr_eq2_raw_kurtosis_v1", result["formula_version"])
        self.assertIn("raw_kurtosis", result)
        # Regression fixture: the former excess/4 implementation reports a
        # materially different near-boundary probability for this seed.
        self.assertAlmostEqual(0.946156, result["deflated_sharpe_probability"], places=6)


if __name__ == "__main__":
    unittest.main()
