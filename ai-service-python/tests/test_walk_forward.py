import unittest

import pandas as pd

from app.services.walk_forward import (
    WalkForwardService,
    calculate_robustness_score,
    detect_overfit,
)


class WalkForwardSplitTest(unittest.TestCase):
    def test_split_dataset_uses_70_15_15_ratios(self):
        df = pd.DataFrame({
            "time": pd.date_range("2024-01-01", periods=100, freq="h"),
            "open": range(100),
            "high": range(100),
            "low": range(100),
            "close": range(100),
            "volume": [0] * 100,
        })

        splits = WalkForwardService().split_dataset(df)

        self.assertEqual(len(splits["train"]), 70)
        self.assertEqual(len(splits["validation"]), 15)
        self.assertEqual(len(splits["forward"]), 15)

    def test_rolling_windows_reserve_final_two_years(self):
        df = pd.DataFrame({
            "time": pd.date_range("2004-01-01", "2026-01-01", freq="30D"),
            "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1,
        })

        windows, holdout = WalkForwardService().rolling_windows(df)

        self.assertGreaterEqual(len(windows), 3)
        self.assertEqual(windows[0]["train"]["time"].min().year, 2004)
        self.assertEqual(windows[0]["validation"]["time"].min().year, 2012)
        self.assertEqual(windows[0]["forward"]["time"].min().year, 2014)
        self.assertGreaterEqual(holdout["time"].min(), df["time"].max() - pd.DateOffset(years=2))

    def test_sparse_history_uses_three_chronological_row_windows_before_holdout(self):
        dates = pd.concat([
            pd.Series(pd.date_range("2004-01-01", "2013-01-01", freq="30D")),
            pd.Series(pd.date_range("2016-01-01", "2026-01-01", freq="30D")),
        ]).reset_index(drop=True)
        df = pd.DataFrame({
            "time": dates,
            "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1,
        })

        windows, holdout = WalkForwardService().rolling_windows(df)

        self.assertEqual(3, len(windows))
        self.assertLess(windows[-1]["forward"]["time"].max(), holdout["time"].min())


class OverfitDetectionTest(unittest.TestCase):
    def test_train_forward_gap_over_threshold_is_overfit(self):
        self.assertTrue(detect_overfit(95, 40))


class RobustnessScoreTest(unittest.TestCase):
    def test_robustness_score_uses_score_range(self):
        self.assertEqual(calculate_robustness_score(91, 88, 84), 93)


if __name__ == "__main__":
    unittest.main()
