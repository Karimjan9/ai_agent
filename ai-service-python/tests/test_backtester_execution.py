import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import _validate_data_gaps, run_simple_ema_rsi_backtest_on_dataframe
from app.services.market_adaptive_replay import MarketAdaptiveReplayService


def golden_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame.loc[199, "signal"] = "BUY"
    return frame


def cooldown_shadow_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame.loc[199, "signal"] = "BUY"
    frame.loc[200, "signal"] = "BUY"
    frame["signal_confidence"] = 1.0
    return frame


class BacktesterExecutionRegressionTest(unittest.TestCase):

    def test_h1_regime_is_available_only_after_that_h1_candle_closes(self) -> None:
        from app.services.backtester import _apply_execution_regime

        execution = pd.DataFrame([
            {"time": "2026-07-20 10:45:00", "open": 1.0, "high": 1.0, "low": 1.0, "close": 1.0, "volume": 1},
            {"time": "2026-07-20 11:00:00", "open": 1.0, "high": 1.0, "low": 1.0, "close": 1.0, "volume": 1},
            {"time": "2026-07-20 11:15:00", "open": 1.0, "high": 1.0, "low": 1.0, "close": 1.0, "volume": 1},
        ])
        h1 = pd.DataFrame([
            {"time": "2026-07-20 10:00:00", "open": 1.0, "high": 1.0, "low": 1.0, "close": 1.0, "volume": 1, "regime_label": "trend_up"},
            {"time": "2026-07-20 11:00:00", "open": 1.0, "high": 1.0, "low": 1.0, "close": 1.0, "volume": 1, "regime_label": "range"},
        ])

        def labelled_regime(frame: pd.DataFrame) -> pd.DataFrame:
            result = frame.copy()
            result["market_regime"] = result["regime_label"]
            result["volatility_regime"] = "normal_volatility"
            result["adx"] = 20.0
            result["atr_regime"] = 1.0
            return result

        with patch("app.services.backtester.apply_market_regime", side_effect=labelled_regime):
            result = _apply_execution_regime(execution, h1)

        self.assertEqual(["unknown", "trend_up", "trend_up"], result["market_regime"].tolist())
    def candles(self) -> pd.DataFrame:
        rows = 205
        prices = [100.0 + ((index % 10) * 0.01) for index in range(rows)]
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-05 00:00:00", periods=rows, freq="h"),
            "open": prices,
            "high": [price + 0.4 for price in prices],
            "low": [price - 0.4 for price in prices],
            "close": prices,
            "volume": [1000.0] * rows,
        })
        return frame

    def payload(self, reject_gaps: bool = True) -> SimpleBacktestRequest:
        return SimpleBacktestRequest(
            symbol="XAUUSD",
            timeframe="H1",
            strategy="golden_v1",
            risk_per_trade=1,
            execution=ExecutionConfig(
                stop_loss_percent=0.5,
                take_profit_percent=1.0,
                max_leverage=5,
                reject_unexpected_gaps=reject_gaps,
            ),
        )

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_signal_close_enters_next_open_and_checks_first_candle(self, _strategy):
        frame = self.candles()
        frame.loc[200, "low"] = 99.4

        result = run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)

        self.assertEqual(result.total_trades, 1)
        trade = result.trades[0]
        self.assertEqual(pd.Timestamp(trade.signal_time), frame.loc[199, "time"])
        self.assertEqual(pd.Timestamp(trade.entry_time), frame.loc[200, "time"])
        self.assertEqual(pd.Timestamp(trade.exit_time), frame.loc[200, "time"])
        self.assertEqual(trade.exit_reason, "intrabar_stop")
        self.assertEqual(trade.position_size_multiple, 2.0)
        self.assertEqual(trade.profit_percent, -1.0)
        self.assertEqual(result.final_balance, 9900.0)

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_post_entry_gap_fills_at_open_not_stop_price(self, _strategy):
        frame = self.candles()
        frame.loc[201, ["open", "high", "low", "close"]] = [99.0, 99.2, 98.8, 99.0]

        result = run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)

        trade = result.trades[0]
        self.assertEqual(pd.Timestamp(trade.exit_time), frame.loc[201, "time"])
        self.assertEqual(trade.exit_reason, "gap_stop")
        self.assertEqual(trade.exit_price, 99.0)
        self.assertEqual(trade.profit_percent, -2.0)

    def test_unexpected_weekday_gap_hard_blocks_backtest(self):
        frame = self.candles()
        frame.loc[100:, "time"] = frame.loc[100:, "time"] + pd.Timedelta(hours=1)

        with self.assertRaisesRegex(ValueError, "hard-gate failed: 1"):
            run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_entry_funnel_records_execution_filter_rejections(self, _strategy):
        payload = self.payload()
        payload.parameters["minimum_signal_confidence"] = 1.1

        result = run_simple_ema_rsi_backtest_on_dataframe(payload, self.candles())

        self.assertGreaterEqual(result.entry_funnel["raw_strategy_signals"], 1)
        self.assertEqual(result.entry_funnel["accepted_entries"], 0)
        self.assertEqual(result.entry_funnel["dominant_rejection"], "minimum_confidence")

    @patch("app.services.backtester.get_strategy", return_value=cooldown_shadow_strategy)
    def test_shadow_veto_ledger_measures_a_cooldown_rejection_without_opening_a_real_trade(self, _strategy):
        frame = self.candles()
        # First entry loses at candle 200. The next signal is then vetoed by
        # cooldown, but its counterfactual reaches target at candle 201.
        frame.loc[200, "low"] = 99.4
        frame.loc[201, "high"] = 101.5
        payload = self.payload(reject_gaps=False)
        payload.parameters.update({"loss_cooldown_candles": 4, "dynamic_cooldown_enabled": True})

        result = run_simple_ema_rsi_backtest_on_dataframe(payload, frame)

        cooldown = result.veto_regret["by_veto_reason"]["loss_cooldown"]
        self.assertEqual(result.total_trades, 1)
        self.assertEqual(cooldown["shadow_trades"], 1)
        self.assertGreater(cooldown["shadow_profit"], 0)
        self.assertIn("shadow_profit_factor", cooldown)
        self.assertNotEqual(cooldown["recommended_action"], "relax_bounded_veto")
        record = result.veto_regret["sample_records"][0]
        self.assertEqual(record["veto_reason"], "loss_cooldown")
        self.assertIsInstance(record["market_regime"], str)
        self.assertEqual(result.cooldown_policy["loss_events"], 1)

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_window_survival_keeps_activity_absence_separate_from_edge_failure(self, _strategy):
        result = run_simple_ema_rsi_backtest_on_dataframe(self.payload(reject_gaps=False), self.candles())

        self.assertIn("positive_windows", result.window_survival)
        self.assertIn("activity_absence", result.window_survival)
        self.assertEqual(result.window_survival["protocol"], "calendar windows; activity absence is distinct from edge failure")
        self.assertIn("edge_density", result.opportunity_metrics)

    def test_xau_us_holiday_closure_is_not_a_hard_gap(self):
        frame = pd.DataFrame({
            "time": pd.to_datetime(["2005-02-18 18:00:00", "2005-02-22 08:00:00"]),
            "open": [430.0, 431.0],
            "high": [431.0, 432.0],
            "low": [429.0, 430.0],
            "close": [430.0, 431.0],
            "volume": [1.0, 1.0],
        })

        self.assertEqual(_validate_data_gaps(frame, self.payload()), 0)

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_pf_attribution_uses_full_trade_ledger(self, _strategy):
        frame = self.candles()
        frame.loc[200, "low"] = 99.4

        result = run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)

        self.assertEqual(result.pf_attribution["summary"]["trades"], 1)
        self.assertIn("gross_pf", result.pf_attribution["summary"])
        self.assertIn("BUY", result.pf_attribution["by_direction"])

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_cost_profiles_replay_identical_candles(self, _strategy):
        frame = self.candles()
        frame.loc[200, "high"] = 101.5
        payload = self.payload(reject_gaps=False).model_copy(update={
            "execution": ExecutionConfig(spread_points=20, point_size=0.01, slippage_points=2, commission_percent=0.01)
        })
        normal = run_simple_ema_rsi_backtest_on_dataframe(payload, frame).model_dump()

        profiles = MarketAdaptiveReplayService._cost_profile_attribution(payload, frame, normal)

        self.assertEqual(profiles["method"], "identical_replay_execution_profiles")
        self.assertIn("profit_factor", profiles["zero_cost"])
        self.assertIn("profit_factor", profiles["stress_cost"])


if __name__ == "__main__":
    unittest.main()
