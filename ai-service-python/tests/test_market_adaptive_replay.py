import unittest

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.strategies.laboratory import apply_hybrid_strategy


class MarketAdaptiveReplayTest(unittest.TestCase):
    def setUp(self):
        time = pd.Index([
            *pd.date_range("2004-01-01", "2025-12-31", freq="D"),
            *pd.date_range("2026-01-01", "2026-07-17", freq="h"),
        ])
        close = pd.Series(range(len(time)), dtype=float).mod(37).add(100.0)
        self.df = pd.DataFrame({
            "time": time,
            "open": close,
            "high": close + 1,
            "low": close - 1,
            "close": close,
            "volume": 1,
        })

    def test_last_six_weeks_are_excluded_from_foundation_and_replay(self):
        parts = MarketAdaptiveReplayService().split_dataset(self.df)

        self.assertLessEqual(parts["foundation"]["time"].max(), pd.Timestamp("2025-12-31 23:59:59"))
        self.assertGreaterEqual(parts["replay"]["time"].min(), pd.Timestamp("2026-01-01"))
        self.assertLess(parts["replay"]["time"].max(), parts["holdout"]["time"].min())
        self.assertGreaterEqual(parts["holdout"]["time"].min(), self.df["time"].max() - pd.Timedelta(weeks=6))

    def test_vendor_archive_starting_on_gbpusd_baseline_is_accepted(self):
        gbpusd_archive = self.df[self.df["time"] >= pd.Timestamp("2005-01-02")]

        parts = MarketAdaptiveReplayService().split_dataset(gbpusd_archive)

        self.assertEqual(parts["foundation"]["time"].min(), pd.Timestamp("2005-01-02"))

    def test_holdout_runner_receives_only_the_sealed_tail(self):
        service = MarketAdaptiveReplayService()
        payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", strategy="trend_v1")
        _, period = service.sealed_holdout(payload, self.df)

        self.assertEqual(period["start"], service.split_dataset(self.df)["holdout"]["time"].min().isoformat())

    def test_monthly_passport_marks_one_good_month_as_seasonal_not_consistent(self):
        passport = MarketAdaptiveReplayService._monthly_passport({"windows": [
            {"profit_factor": 1.4, "net_profit_percent": 2},
            {"profit_factor": .8, "net_profit_percent": -1},
            {"profit_factor": .9, "net_profit_percent": -1},
        ]})

        self.assertEqual(passport["status"], "seasonal_or_luck")
        self.assertEqual(passport["rolling_forward_wins"], 1)


class HybridStrategyTest(unittest.TestCase):
    def test_high_volatility_is_wait_when_gene_enables_safety_filter(self):
        df = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=250, freq="h"),
            "open": range(100, 350), "high": range(101, 351), "low": range(99, 349), "close": range(100, 350),
            "market_regime": "trend_up", "volatility_regime": "high_volatility",
        })

        result = apply_hybrid_strategy(df, {"high_volatility_wait": True})

        self.assertTrue((result["signal"] == "WAIT").all())


if __name__ == "__main__":
    unittest.main()
