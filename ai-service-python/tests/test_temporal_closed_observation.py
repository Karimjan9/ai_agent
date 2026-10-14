"""Synthetic timing regressions, not original research or economic evidence."""

import copy
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services import backtester as kernel


def software_strategy(frame, _parameters):
    result = frame.copy()
    result["signal"] = "BUY"
    result["signal_confidence"] = 1.0
    result["market_regime"] = "trend_up"
    result["volatility_regime"] = "normal_volatility"
    return result


def software_frame():
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=219, freq="5min"),
        "open": 100.0, "high": 100.05, "low": 99.95, "close": 100.0,
        "volume": 0.0, "volume_available": False})


class TemporalClosedObservationTest(unittest.TestCase):
    def payload(self, window=1):
        return SimpleBacktestRequest(timeframe="M5", parameters={
            "volume_lane": "none", "temporal_survival_enabled": True,
            "adaptive_signal_expiry_enabled": True, "temporal_min_history": 5,
            "temporal_followthrough_window": window, "temporal_followthrough_min_rate": .1,
            "temporal_followthrough_atr_fraction": 1.0},
            execution=ExecutionConfig(spread_points=1.0, slippage_points=.1,
                commission_percent=.001), emit_decision_trace=True, emit_trade_ledger=True)

    def test_zero_index_signal_matures_at_first_subsequent_closed_bar_for_both_directions(self):
        payload = self.payload()
        for direction in ("BUY", "SELL"):
            with self.subTest(direction=direction):
                state = kernel._temporal_survival_state()
                signal = {"close": 100.0, "_management_atr": 1.0}
                kernel._temporal_register_signal(state, signal, direction, 0, payload)
                self.assertEqual(state["pending"][0]["start_index"], 0)
                self.assertEqual(state["pending"][0]["expiry_index"], 1)
                # Even a large signal-bar wick is not subsequent follow-through.
                kernel._temporal_update_pending(state, {"high": 110.0, "low": 90.0}, 0, payload)
                self.assertEqual(state["followthrough_results"], [])
                self.assertEqual(state["pending"][0]["max_favorable_atr"], 0.0)
                candle = {"high": 101.0 if direction == "BUY" else 100.0,
                    "low": 99.0 if direction == "SELL" else 100.0}
                kernel._temporal_update_pending(state, candle, 1, payload)
                self.assertEqual(state["followthrough_results"], [True])
                self.assertEqual(state["pending"], [])

    def test_three_bar_window_counts_first_and_last_closed_observations_without_off_by_one(self):
        payload = self.payload(window=3)
        for crossing_index in (11, 13):
            with self.subTest(crossing_index=crossing_index):
                state = kernel._temporal_survival_state()
                kernel._temporal_register_signal(state,
                    {"close": 100.0, "_management_atr": 1.0}, "BUY", 10, payload)
                for closed_index in (11, 12, 13):
                    kernel._temporal_update_pending(state,
                        {"high": 101.0 if closed_index == crossing_index else 100.1, "low": 99.9},
                        closed_index, payload)
                    self.assertEqual(state["followthrough_results"], [True] if closed_index == 13 else [])
                self.assertEqual(state["pending"], [])

    def test_expired_probe_cannot_be_reclassified_by_a_later_closed_wick(self):
        payload = self.payload()
        state = kernel._temporal_survival_state()
        kernel._temporal_register_signal(state,
            {"close": 100.0, "_management_atr": 1.0}, "BUY", 10, payload)
        kernel._temporal_update_pending(state, {"high": 100.9, "low": 99.9}, 11, payload)
        self.assertEqual(state["followthrough_results"], [False])
        kernel._temporal_update_pending(state, {"high": 110.0, "low": 99.9}, 12, payload)
        self.assertEqual(state["followthrough_results"], [False])

    def observed_replay(self, data, *, block_entries=True, enabled=True):
        payload = self.payload()
        if enabled is None:
            payload.parameters.pop("temporal_survival_enabled")
        else:
            payload.parameters["temporal_survival_enabled"] = enabled
        if block_entries:
            # A legitimate session restriction keeps the account flat so
            # every proposed signal reaches the temporal assessment. It does
            # not lower or bypass the risk gates or claim economic evidence.
            payload.execution.allowed_sessions_utc = ["12-13"]
        observations = {}
        assessment = kernel._temporal_survival_assessment
        register = kernel._temporal_register_signal
        source_indices = []

        def assess_closed(row, metrics, request, loss_streak, state):
            result = assessment(row, metrics, request, loss_streak, state)
            observations[metrics["index"]] = copy.deepcopy(result)
            return result

        def register_closed(state, row, direction, source_index, request):
            source_indices.append((source_index, pd.Timestamp(row["time"])))
            return register(state, row, direction, source_index, request)

        with patch("app.services.backtester.get_strategy", return_value=software_strategy), \
                patch("app.services.backtester._temporal_survival_assessment", side_effect=assess_closed), \
                patch("app.services.backtester._temporal_register_signal", side_effect=register_closed):
            result = kernel.run_simple_ema_rsi_backtest_on_dataframe(payload, data,
                include_differential_pair=False, lightweight=True)
        return payload, result, observations, source_indices

    def test_ordinary_current_open_assessment_ignores_later_current_bar_extrema_then_matures_next_open(self):
        original = software_frame()
        changed = original.copy()
        changed.loc[205, "high"] = 101.0
        left = self.observed_replay(original)
        right = self.observed_replay(changed)
        self.assertEqual(left[1].total_trades, 0)
        self.assertEqual(right[1].total_trades, 0)
        self.assertEqual(left[0].execution, right[0].execution)
        self.assertEqual(left[0].parameters, right[0].parameters)
        self.assertEqual(left[0].risk_per_trade, right[0].risk_per_trade)
        self.assertEqual(left[1].data_quality["replay_executed_clock"]["schedule_hash"],
            right[1].data_quality["replay_executed_clock"]["schedule_hash"])
        self.assertEqual(left[2][200]["metrics"]["followthrough_sample_count"], 0)
        self.assertEqual(left[2][201]["metrics"]["followthrough_sample_count"], 1)
        self.assertEqual(left[2][205]["metrics"]["followthrough_sample_count"], 5)
        for decision_index in range(200, 206):
            # Same original closed prefix and current open: a later high in
            # candle205 cannot change any assessment at/before open205.
            self.assertEqual(left[2][decision_index], right[2][decision_index])
        self.assertTrue(left[2][205]["veto"])
        self.assertEqual(left[2][205]["reasons"], ["followthrough_failure"])
        # Only open206 can use completed candle205. Registration and expiry
        # use its source-bar indices, so the one-bar window is not delayed.
        self.assertEqual(right[2][206]["metrics"]["followthrough_sample_count"], 6)
        self.assertAlmostEqual(right[2][206]["metrics"]["followthrough_rate"], 1 / 6)
        self.assertFalse(right[2][206]["veto"])
        self.assertTrue(left[2][206]["veto"])
        self.assertEqual(left[3][0], (199, original.iloc[199]["time"]))
        for source_index, source_time in left[3]:
            self.assertEqual(source_time, original.iloc[source_index]["time"])

    def test_default_disabled_control_matches_explicit_disabled_ledger_costs_and_clock(self):
        original = software_frame()
        # Complete the existing owned position through its unchanged target;
        # an open/censored position alone would not prove ledger preservation.
        original.loc[len(original) - 1, "high"] = 101.1
        default = self.observed_replay(original, block_entries=False, enabled=None)
        disabled = self.observed_replay(original, block_entries=False, enabled=False)
        self.assertNotIn("temporal_survival_enabled", default[0].parameters)
        self.assertFalse(default[1].temporal_survival["enabled"])
        self.assertFalse(disabled[1].temporal_survival["enabled"])
        self.assertGreater(default[1].total_trades, 0)
        self.assertEqual([trade.model_dump() for trade in default[1].trade_ledger],
            [trade.model_dump() for trade in disabled[1].trade_ledger])
        self.assertEqual(default[1].final_balance, disabled[1].final_balance)
        self.assertEqual(default[0].execution, disabled[0].execution)
        self.assertEqual(default[0].risk_per_trade, disabled[0].risk_per_trade)
        self.assertEqual(default[1].data_quality["replay_executed_clock"]["schedule_hash"],
            disabled[1].data_quality["replay_executed_clock"]["schedule_hash"])
        self.assertTrue(all(result["status"] == "disabled" and not result["veto"]
            for result in default[2].values()))


if __name__ == "__main__":
    unittest.main()
