import unittest
from unittest.mock import patch

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

    def test_execution_fault_contract_proves_safe_order_invariants_without_market_evidence(self):
        payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", strategy="trend_v1")

        contract = MarketAdaptiveReplayService._execution_fault_contract(payload)

        self.assertEqual(contract["status"], "passed")
        self.assertEqual(contract["evidence_class"], "synthetic_contract_only")
        self.assertEqual(contract["scenarios"]["partial_fill"]["remaining_units"], 50.0)
        self.assertFalse(contract["scenarios"]["rejected_order"]["position_open"])
        self.assertFalse(contract["scenarios"]["stale_candle"]["decision_allowed"])

    def test_portfolio_statistics_fail_closed_without_frozen_selection_frontier(self):
        payload = SimpleBacktestRequest(
            symbol="XAUUSD", timeframe="H1", strategy="portfolio_v1",
            portfolio_members=[
                {"strategy": "trend_v1", "member_key": "performance:1", "target_regime": "trend_up"},
                {"strategy": "range_v1", "member_key": "performance:2", "target_regime": "range"},
            ],
        )
        result = {
            "equity_curve": [10000 + index * 10 for index in range(20)],
            "portfolio_evidence": {
                "declared_members": [
                    {"member_key": "performance:1", "strategy": "trend_v1", "target_regime": "trend_up"},
                    {"member_key": "performance:2", "strategy": "range_v1", "target_regime": "range"},
                ],
                "member_breakdown": {
                    "performance:1": {"trades": 10},
                    "performance:2": {"trades": 10},
                },
            },
        }

        MarketAdaptiveReplayService._attach_portfolio_selection_statistics(result, payload)

        self.assertEqual(result["selection_validation"]["status"], "insufficient_data")
        self.assertFalse(result["selection_validation"]["promotion_evidence"])
        self.assertNotEqual(result["statistical_evidence"]["deflated_sharpe"]["status"], "assessed")

    def test_portfolio_behavioral_diversity_requires_active_orthogonal_specialists(self):
        result = {
            "portfolio_evidence": {
                "declared_members": [
                    {"member_key": "performance:1", "strategy": "trend_v1", "target_regime": "trend_up", "target_volatility": "normal_volatility"},
                    {"member_key": "performance:2", "strategy": "range_v1", "target_regime": "range", "target_volatility": "low_volatility"},
                ],
                "member_breakdown": {
                    "performance:1": {"trades": 10},
                    "performance:2": {"trades": 8},
                },
            },
        }

        diversity = MarketAdaptiveReplayService._portfolio_behavioral_diversity(result)

        self.assertEqual(diversity["status"], "diverse")
        self.assertEqual(diversity["active_member_count"], 2)
        self.assertEqual(diversity["active_niche_count"], 2)

    def test_screening_missing_regime_bucket_is_a_rescue_case_not_a_500(self):
        service = MarketAdaptiveReplayService()
        payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", strategy="trend_v1")
        normal_result = {
            "total_trades": 20,
            "profit_factor": 1.2,
            "trades": [],
            "pf_attribution": {"by_regime": {}},
        }
        with patch.object(MarketAdaptiveReplayService, "_cost_profile_attribution", return_value={"stress_cost": {"profit_factor": 1.1}}), \
             patch("app.services.market_adaptive_replay.run_simple_ema_rsi_backtest_on_dataframe") as run:
            run.return_value = type("Result", (), {"model_dump": lambda self: {"profit_factor": 1.1, "total_trades": 10}})()
            profile = service.screening_survival_profile(payload, self.df, normal_result, lambda item: item["profit_factor"])

        self.assertEqual(profile["worst_regime_pf"], None)
        self.assertIn("INSUFFICIENT_REGIME_EVIDENCE", profile["reason_codes"])
        self.assertEqual(profile["status"], "rescue_case")
        self.assertEqual(profile["temporal_chunk_survival"]["method"], "three_equal_candle_segments")
        self.assertEqual(profile["calendar_month_survival"]["timezone"], "UTC")

    def test_screening_calendar_uses_full_chronological_month_ledger(self):
        service = MarketAdaptiveReplayService()
        payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", strategy="trend_v1")
        normal_result = {
            "total_trades": 20,
            "profit_factor": 1.4,
            "trades": [],
            "pf_attribution": {
                "by_regime": {},
                "by_month": {
                    "2026-01": {
                        "trades": 4, "wins": 3, "losses": 1, "winrate": 75,
                        "net_pf": 1.8, "net_profit_percent": 1.6,
                    },
                },
            },
        }
        with patch.object(MarketAdaptiveReplayService, "_cost_profile_attribution", return_value={"stress_cost": {"profit_factor": 1.1}}), \
             patch("app.services.market_adaptive_replay.run_simple_ema_rsi_backtest_on_dataframe") as run:
            run.return_value = type("Result", (), {"model_dump": lambda self: {"profit_factor": 1.1, "total_trades": 10}})()
            profile = service.screening_survival_profile(payload, self.df, normal_result, lambda item: item["profit_factor"])

        month = profile["calendar_month_survival"]["months"]["2026-01"]
        self.assertEqual(profile["calendar_month_survival"]["source"], "full_chronological_trade_ledger")
        self.assertEqual(month["trades"], 4)
        self.assertEqual(month["profit_factor"], 1.8)


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
