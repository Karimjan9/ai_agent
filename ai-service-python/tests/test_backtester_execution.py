import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest, SimpleTrade
from app.services.backtester import (
    _differential_router_report,
    _proof_carrying_replay,
    _trade_ledger_hash,
    _validate_data_gaps,
    run_simple_ema_rsi_backtest_on_dataframe,
)
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
    frame["market_regime"] = "range"
    frame["volatility_regime"] = "normal_volatility"
    return frame


def loss_streak_probe_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    # Signals execute one candle later: four losses, a signal during the
    # finite wait, then one reduced-risk recovery probe.
    for index in [199, 201, 203, 205, 207, 209]:
        frame.loc[index, "signal"] = "BUY"
    frame["signal_confidence"] = 1.0
    return frame


def cooldown_timing_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame.loc[199, "signal"] = "BUY"
    frame.loc[201, "signal"] = "BUY"
    frame["signal_confidence"] = 1.0
    frame["market_regime"] = "range"
    frame["volatility_regime"] = "normal_volatility"
    return frame


def cooldown_context_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame["signal_confidence"] = 1.0
    frame["market_regime"] = "unknown"
    frame["volatility_regime"] = "normal_volatility"
    frame.loc[199, ["signal", "market_regime"]] = ["BUY", "trend_up"]
    frame.loc[201, ["signal", "market_regime"]] = ["BUY", "range"]
    return frame


def differential_identity_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame["signal_confidence"] = 0.0
    frame["parent_signal"] = "WAIT"
    frame["parent_signal_confidence"] = 0.0
    frame["differential_target"] = False
    frame["differential_target_regime"] = "trend_up"
    frame["market_regime"] = "range"
    frame["volatility_regime"] = "normal_volatility"
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

    def extended_candles(self) -> pd.DataFrame:
        frame = self.candles()
        extension = pd.DataFrame({
            "time": pd.date_range(frame.iloc[-1]["time"] + pd.Timedelta(hours=1), periods=10, freq="h"),
            "open": [100.0] * 10,
            "high": [100.4] * 10,
            "low": [99.6] * 10,
            "close": [100.0] * 10,
            "volume": [1000.0] * 10,
        })
        return pd.concat([frame, extension], ignore_index=True)

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

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_opt_in_decision_trace_carries_features_and_rejections(self, _strategy):
        payload = self.payload(reject_gaps=False)
        payload.emit_decision_trace = True
        payload.parameters["minimum_signal_confidence"] = 1.1

        result = run_simple_ema_rsi_backtest_on_dataframe(payload, self.candles())

        trace = result.decision_trace
        self.assertTrue(trace)
        self.assertTrue(result.data_quality["decision_trace"]["complete"])
        rejected = [row for row in trace if row.get("rejection_code") == "minimum_confidence"]
        self.assertTrue(rejected)
        self.assertIn("features", rejected[0])
        self.assertIn("candle_close", rejected[0]["features"])
        self.assertEqual(rejected[0]["accepted"], False)

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

    @patch("app.services.backtester.get_strategy", return_value=loss_streak_probe_strategy)
    def test_loss_streak_wait_expires_and_recovery_probe_returns_to_normal_risk(self, _strategy):
        frame = self.extended_candles()
        for index in [200, 202, 204, 206]:
            frame.loc[index, "low"] = 99.4
        frame.loc[210, "high"] = 101.5
        payload = self.payload(reject_gaps=False)
        payload.parameters.update({
            "loss_cooldown_candles": 1,
            "loss_streak_wait_candles": 3,
            "max_loss_streak_before_wait": 4,
            "dynamic_cooldown_enabled": False,
            "recovery_probe_risk_multiplier": 0.5,
        })

        result = run_simple_ema_rsi_backtest_on_dataframe(payload, frame)

        self.assertEqual(result.total_trades, 5)
        self.assertEqual(result.trades[-1].result, "WIN")
        self.assertEqual(pd.Timestamp(result.trades[-1].entry_time), frame.loc[210, "time"])
        self.assertEqual(result.trades[-1].position_size_multiple, 1.0)
        self.assertGreaterEqual(result.entry_funnel["rejected"]["loss_streak_wait"], 1)
        self.assertEqual(result.cooldown_policy["loss_streak_wait_events"], 1)
        self.assertEqual(result.cooldown_policy["recovery_probe_trades"], 1)
        self.assertEqual(result.cooldown_policy["recovery_probe_wins"], 1)
        self.assertEqual(result.cooldown_policy["recovery_probe_losses"], 0)

    @patch("app.services.backtester.get_strategy", return_value=cooldown_timing_strategy)
    def test_cooldown_two_and_three_change_accepted_trade_timing(self, _strategy):
        frame = self.candles()
        frame.loc[200, "low"] = 99.4
        frame.loc[202, "low"] = 99.4
        common = {"dynamic_cooldown_enabled": False, "max_loss_streak_before_wait": 10}
        cooldown_two = self.payload(reject_gaps=False)
        cooldown_two.parameters.update({**common, "loss_cooldown_candles": 2})
        cooldown_three = self.payload(reject_gaps=False)
        cooldown_three.parameters.update({**common, "loss_cooldown_candles": 3})

        two = run_simple_ema_rsi_backtest_on_dataframe(cooldown_two, frame)
        three = run_simple_ema_rsi_backtest_on_dataframe(cooldown_three, frame)

        self.assertEqual(two.total_trades, 2)
        self.assertEqual(three.total_trades, 1)
        self.assertEqual(pd.Timestamp(two.trades[-1].entry_time), frame.loc[202, "time"])
        self.assertGreaterEqual(three.entry_funnel["rejected"]["loss_cooldown"], 1)

    @patch("app.services.backtester.get_strategy", return_value=cooldown_context_strategy)
    def test_loss_cooldown_is_scoped_to_the_risk_context(self, _strategy):
        frame = self.candles()
        frame.loc[200, "low"] = 99.4
        frame.loc[202, "low"] = 99.4
        payload = self.payload(reject_gaps=False)
        payload.parameters.update({"dynamic_cooldown_enabled": False, "loss_cooldown_candles": 4})

        result = run_simple_ema_rsi_backtest_on_dataframe(payload, frame)

        # The trend-up loss creates a cooldown only for its own context; the
        # range lane remains eligible and takes the second signal.
        self.assertEqual(result.total_trades, 2)
        self.assertEqual(pd.Timestamp(result.trades[-1].entry_time), frame.loc[202, "time"])
        self.assertEqual(result.cooldown_policy["loss_events"], 2)

    def test_global_confidence_fallback_is_not_a_hard_veto_for_a_new_context(self):
        from app.services.backtester import _confidence_assessment

        payload = self.payload(reject_gaps=False)
        payload.parameters.update({
            "confidence_calibration_enabled": True,
            "confidence_calibration_min_samples": 15,
            "confidence_ev_lower_bound_enabled": True,
        })
        signal = pd.Series({
            "market_regime": "range", "volatility_regime": "normal_volatility",
            "selected_specialist": "range_child", "signal_confidence": .8,
        })
        history = {
            "__global__": [{"confidence": .8, "profit_percent": -1.0} for _ in range(15)],
        }

        assessment = _confidence_assessment(signal, "BUY", history, payload, self.candles().iloc[200])

        self.assertEqual(assessment["source"], "global_fallback")
        self.assertFalse(assessment["hard_veto_eligible"])

    def test_weak_regime_veto_is_context_local_finite_and_recovers_with_a_probe(self):
        from app.services.backtester import _advance_weak_regime_state, _record_weak_regime_outcome

        payload = self.payload(reject_gaps=False)
        payload.parameters.update({"weak_regime_min_samples": 15, "weak_regime_wait_candles": 3})
        states, events = {}, []
        candle = self.candles().iloc[200]
        context = "trend_down|normal_volatility|SELL|trend_down_child"
        for index in range(15):
            _record_weak_regime_outcome(states, context, -1.0, "LOSS", False, index, candle, payload, events)

        self.assertEqual(events[-1]["event"], "weak_regime_wait_started")
        wait_until = states[context]["wait_until"]
        self.assertTrue(_advance_weak_regime_state(states, context, wait_until - 1, candle, events)[0])
        self.assertEqual(_advance_weak_regime_state(states, "trend_up|normal_volatility|BUY|parent", wait_until - 1, candle, events), (False, False))
        self.assertEqual(_advance_weak_regime_state(states, context, wait_until, candle, events), (False, True))

        _record_weak_regime_outcome(states, context, 1.0, "WIN", True, wait_until, candle, payload, events)
        self.assertEqual(states[context]["wait_until"], -1)
        self.assertEqual(states[context]["returns_window"], [])

        for index in range(15, 30):
            _record_weak_regime_outcome(states, context, -1.0, "LOSS", False, index, candle, payload, events)
        retry_at = states[context]["wait_until"]
        _advance_weak_regime_state(states, context, retry_at, candle, events)
        _record_weak_regime_outcome(states, context, -1.0, "LOSS", True, retry_at, candle, payload, events)
        self.assertGreater(states[context]["wait_until"], retry_at)

    def test_confidence_calibration_uses_only_closed_history_and_rejects_negative_lower_ev(self):
        from collections import defaultdict
        from app.services.backtester import _confidence_assessment, _record_confidence_observation

        payload = self.payload(reject_gaps=False)
        payload.parameters.update({"confidence_calibration_enabled": True, "confidence_calibration_min_samples": 15})
        frame = self.candles()
        signal = frame.iloc[199].copy()
        signal["signal_confidence"] = 0.9
        history = defaultdict(list)
        self.assertEqual(_confidence_assessment(signal, "BUY", history, payload, frame.iloc[200])["status"], "insufficient_evidence")
        for _ in range(15):
            _record_confidence_observation(history, signal, "BUY", -1.0)
        calibrated = _confidence_assessment(signal, "BUY", history, payload, frame.iloc[200])
        self.assertEqual(calibrated["status"], "assessed")
        self.assertLessEqual(calibrated["ev_lower_bound"], 0)

    def test_differential_lane_selector_uses_parent_and_child_ledgers_separately(self):
        from app.services.backtester import _effective_lane_signal

        row = pd.Series({
            "signal": "BUY", "signal_confidence": .8,
            "parent_signal": "SELL", "parent_signal_confidence": .6,
            "differential_target": True, "selected_specialist": "range_child",
        })

        self.assertEqual(_effective_lane_signal(row, "target_parent")[:2], ("SELL", .6))
        self.assertEqual(_effective_lane_signal(row, "target_child")[:2], ("BUY", .8))
        self.assertEqual(_effective_lane_signal(row, "non_target_child")[:2], ("WAIT", 0.0))

    def test_differential_identity_normalizes_equally_missing_non_target_values(self):
        frame = pd.DataFrame({
            "market_regime": ["range", "trend_down"],
            "differential_target": [False, False],
            "differential_target_regime": ["trend_up", "trend_up"],
            "signal": [None, "WAIT"],
            "parent_signal": [None, "WAIT"],
            "signal_confidence": [None, 0.0],
            "parent_signal_confidence": [None, 0.0],
        })

        report = _differential_router_report(frame, [])

        self.assertTrue(report["non_target_signal_identity"])
        self.assertTrue(report["non_target_confidence_identity"])

    def test_proof_replay_uses_compounded_net_return_and_canonical_pf_rounding(self):
        payload = self.payload(reject_gaps=False)
        trades = [
            SimpleTrade(
                direction="BUY", entry_time="2026-01-01T00:00:00", exit_time="2026-01-01T01:00:00",
                entry_price=100, exit_price=101, result="WIN", profit_percent=1.0, balance=10100,
            ),
            SimpleTrade(
                direction="SELL", entry_time="2026-01-01T02:00:00", exit_time="2026-01-01T03:00:00",
                entry_price=100, exit_price=100.5, result="LOSS", profit_percent=-0.5, balance=10049.5,
            ),
        ]
        result = _proof_carrying_replay({
            "total_trades": 2,
            "profit_factor": 2.0,
            "net_profit_percent": 0.5,
            "trade_ledger_hash": _trade_ledger_hash(trades),
        }, trades, payload)

        self.assertEqual(result["status"], "passed")
        self.assertEqual(result["independent_ledger_verifier"]["ledger_net_profit_percent"], 0.5)

    def test_proof_replay_still_fails_on_real_ledger_mismatch(self):
        payload = self.payload(reject_gaps=False)
        trades = [SimpleTrade(
            direction="BUY", entry_time="2026-01-01T00:00:00", exit_time="2026-01-01T01:00:00",
            entry_price=100, exit_price=101, result="WIN", profit_percent=1.0, balance=10100,
        )]
        result = _proof_carrying_replay({
            "total_trades": 1,
            "profit_factor": 1.0,
            "net_profit_percent": 1.0,
            "trade_ledger_hash": "tampered",
        }, trades, payload)

        self.assertEqual(result["status"], "mismatch")

    @patch("app.services.backtester.get_strategy", return_value=differential_identity_strategy)
    def test_lightweight_differential_replay_keeps_paired_identity_evidence(self, _strategy):
        payload = self.payload(reject_gaps=False).model_copy(update={
            "strategy": "XAUUSD_differential_router_g95_a01",
            "base_strategy": "differential_router_v1",
        })

        result = run_simple_ema_rsi_backtest_on_dataframe(payload, self.candles(), lightweight=True)

        paired = result.differential_router["paired_lane"]
        self.assertTrue(paired["non_target_signal_identity"])
        self.assertTrue(paired["non_target_confidence_identity"])
        self.assertEqual(paired["status"], "passed")

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
