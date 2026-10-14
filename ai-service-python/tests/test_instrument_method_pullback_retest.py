"""Finite closed confirmations on synthetic prices; no empirical skill claim."""

import copy
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.instrument_method_learning import InstrumentMethodLearning, receipt_current
from app.services.research_program_tasks import BoundedDecisionProgram
from test_instrument_method_learning import learning_contract, pinned_recipe


def prices(closes):
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=len(closes), freq="5min"),
        "open": closes, "high": [value + .2 for value in closes],
        "low": [value - .2 for value in closes], "close": closes,
        "market_session": "london", "market_regime": "trend_up", "volatility_regime": "normal_volatility"})


def recursive_ema(values, period):
    result = [float(values[0])]
    alpha = 2.0 / (period + 1)
    for value in values[1:]:
        result.append(alpha * (value - result[-1]) + result[-1])
    return result


def mirror(data):
    result = data.copy()
    for key in ("open", "close"):
        result[key] = 500.0 - data[key]
    result["high"], result["low"] = 500.0 - data["low"], 500.0 - data["high"]
    result["market_regime"] = "trend_down"
    return result


def pullback_frame(count=80, *, pullback=60, direction="BUY"):
    closes = [100.0 + index * .25 for index in range(count)]
    fast, slow = recursive_ema(closes[:pullback], 20), recursive_ema(closes[:pullback], 50)
    closes[pullback] = (fast[-1] + slow[-1]) / 2
    data = prices(closes)
    return mirror(data) if direction == "SELL" else data


def retest_frame(count=40, *, breakout=24, delay=1, direction="BUY"):
    data = prices([100.0] * count)
    data.loc[:, "high"], data.loc[:, "low"] = 101.0, 99.0
    data.loc[breakout, ["open", "high", "low", "close"]] = [100.0, 103.0, 100.0, 102.0]
    for index in range(breakout + 1, count):
        data.loc[index, ["open", "high", "low", "close"]] = [102.0, 102.5, 101.5, 102.0]
    data.loc[breakout + delay, "low"] = 101.0
    return mirror(data) if direction == "SELL" else data


def inputs(owner, data, index, *, direction="BUY", seconds=300):
    row = data.iloc[index].to_dict()
    return owner.inputs(row, direction=direction,
        observed_at=(row["time"] + pd.Timedelta(seconds=seconds)).isoformat())


def recipe(request, feature):
    ast = {"op": "AND", "args": [{"op": "BOOL", "input_key": "available", "available_at": "2024-01-01T00:00:00Z"},
        {"op": "BOOL", "input_key": "confirmed", "available_at": "2024-01-01T00:00:00Z"}]}
    return pinned_recipe(request, "closed-" + feature, {"confirmation": ast},
        {"available": "method_" + feature + "_available", "confirmed": "method_" + feature + "_confirmed"})


def supplied_buy(data, parameters):
    result = data.copy()
    result["signal"], result["signal_confidence"] = "BUY", 1.0
    result["market_regime"], result["market_session"] = "trend_up", "london"
    result["volatility_regime"] = "normal_volatility"
    return result


class ClosedPullbackRetestTest(unittest.TestCase):
    def test_pullback_requires_two_mature_50_observation_ema_values_with_exact_recursive_formula(self):
        data = pullback_frame()
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(inputs(owner, data, 49)["method_pullback_available"])
        self.assertTrue(inputs(owner, data, 50)["method_pullback_available"])
        for period in (20, 50):
            expected = recursive_ema(data["close"].tolist(), period)
            self.assertAlmostEqual(expected[60], owner.features[60]["_ema" + str(period)], places=12)
        self.assertFalse(inputs(owner, data, 60)["method_pullback_confirmed"])
        self.assertTrue(inputs(owner, data, 61)["method_pullback_confirmed"])

    def test_pullback_both_directions_are_confirmations_not_generated_signals(self):
        for direction in ("BUY", "SELL"):
            data = pullback_frame(direction=direction)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertTrue(inputs(owner, data, 61, direction=direction)["method_pullback_confirmed"])
            wrong = "SELL" if direction == "BUY" else "BUY"
            self.assertFalse(inputs(owner, data, 61, direction=wrong)["method_pullback_confirmed"])
            self.assertFalse(inputs(owner, data, 61, direction="WAIT")["method_pullback_confirmed"])
            self.assertFalse(inputs(owner, data, 62, direction=direction)["method_pullback_confirmed"])

    def test_pullback_needs_prior_close_between_fast_and_slow_and_current_reclaim(self):
        no_pullback = prices([100.0 + index * .25 for index in range(80)])
        self.assertFalse(inputs(ClosedInstrumentMethodFeatures(no_pullback), no_pullback, 61)["method_pullback_confirmed"])
        for changed in ("below_slow", "no_reclaim"):
            data = pullback_frame()
            value = 100.0 if changed == "below_slow" else float(data.iloc[60]["close"])
            index = 60 if changed == "below_slow" else 61
            data.loc[index, ["open", "high", "low", "close"]] = [value, value + .2, value - .2, value]
            self.assertFalse(inputs(ClosedInstrumentMethodFeatures(data), data, 61)["method_pullback_confirmed"])

    def test_pullback_immediate_pair_cannot_bridge_a_gap_and_uses_configured_duration(self):
        data = pullback_frame()
        data.loc[61:, "time"] += pd.Timedelta(minutes=5)
        current = inputs(ClosedInstrumentMethodFeatures(data), data, 61)
        self.assertFalse(current["method_pullback_available"])
        self.assertFalse(current["method_pullback_confirmed"])
        # Earlier gaps do not erase fifty already observed closes.
        history_gap = pullback_frame()
        history_gap.loc[20:, "time"] += pd.Timedelta(minutes=5)
        self.assertTrue(inputs(ClosedInstrumentMethodFeatures(history_gap), history_gap, 61)["method_pullback_confirmed"])
        wide = pullback_frame()
        wide["time"] = pd.date_range("2025-01-06T08:00:00Z", periods=len(wide), freq="15min")
        self.assertTrue(inputs(ClosedInstrumentMethodFeatures(wide, bar_duration_seconds=900), wide, 61, seconds=900)["method_pullback_confirmed"])
        self.assertFalse(inputs(ClosedInstrumentMethodFeatures(wide), wide, 61)["method_pullback_available"])

    def test_retest_requires_prior20_and_later_bar_touching_exact_frozen_level_on_its_own_side(self):
        for direction in ("BUY", "SELL"):
            data = retest_frame(direction=direction)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertFalse(inputs(owner, data, 19, direction=direction)["method_retest_available"])
            self.assertTrue(inputs(owner, data, 20, direction=direction)["method_retest_available"])
            # Breakout candle itself already touches the old channel: it must not confirm.
            self.assertFalse(inputs(owner, data, 24, direction=direction)["method_retest_confirmed"])
            self.assertEqual(101.0 if direction == "BUY" else 399.0, owner.features[25]["_retest_level"])
            self.assertTrue(inputs(owner, data, 25, direction=direction)["method_retest_confirmed"])
            self.assertFalse(inputs(owner, data, 25, direction="SELL" if direction == "BUY" else "BUY")["method_retest_confirmed"])
            self.assertFalse(inputs(owner, data, 25, direction="WAIT")["method_retest_confirmed"])
            self.assertFalse(inputs(owner, data, 26, direction=direction)["method_retest_confirmed"])
            # Frozen101 confirms even though the rolling channel now contains breakout high103.
            self.assertNotEqual(owner.features[25]["_retest_level"], owner.features[25]["_donchian_high"])

    def test_retest_one_to_three_bars_confirm_but_fourth_bar_expires_and_touch_is_consumed(self):
        for delay in (1, 2, 3, 4):
            data = retest_frame(delay=delay)
            data.loc[25:30, "low"] = 101.5
            data.loc[24 + delay:30, "low"] = 101.0
            owner = ClosedInstrumentMethodFeatures(data)
            confirmations = [index for index in range(25, 31) if inputs(owner, data, index)["method_retest_confirmed"]]
            self.assertEqual([24 + delay] if delay <= 3 else [], confirmations)

    def test_pending_breakout_level_is_not_overwritten_by_a_later_fresh_high(self):
        data = retest_frame(delay=2)
        data.loc[25, ["open", "high", "low", "close"]] = [104.0, 106.0, 104.0, 105.0]
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(inputs(owner, data, 25)["method_retest_confirmed"])
        self.assertEqual(101.0, owner.features[25]["_retest_level"])
        self.assertTrue(inputs(owner, data, 26)["method_retest_confirmed"])
        self.assertEqual(101.0, owner.features[26]["_retest_level"])
        self.assertEqual(106.0, owner.features[26]["_donchian_high"])

    def test_opposing_close_cancels_and_equality_waits_for_strict_back_on_side(self):
        for direction in ("BUY", "SELL"):
            data = retest_frame(direction=direction)
            value = 100.5 if direction == "BUY" else 399.5
            data.loc[25, ["open", "high", "low", "close"]] = [value, value + .2, value - .2, value]
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertFalse(inputs(owner, data, 25, direction=direction)["method_retest_confirmed"])
            self.assertFalse(inputs(owner, data, 26, direction=direction)["method_retest_confirmed"])
        equal = retest_frame(delay=2)
        equal.loc[25, ["open", "high", "low", "close"]] = [101.0, 101.5, 100.8, 101.0]
        owner = ClosedInstrumentMethodFeatures(equal)
        self.assertFalse(inputs(owner, equal, 25)["method_retest_confirmed"])
        self.assertTrue(inputs(owner, equal, 26)["method_retest_confirmed"])

    def test_clock_gap_cancels_pending_and_configured_timeframe_defines_contiguity(self):
        data = retest_frame()
        data.loc[25:, "time"] += pd.Timedelta(minutes=5)
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(inputs(owner, data, 25)["method_retest_confirmed"])
        self.assertFalse(inputs(owner, data, 26)["method_retest_confirmed"])
        wide = retest_frame()
        wide["time"] = pd.date_range("2025-01-06T08:00:00Z", periods=len(wide), freq="15min")
        self.assertTrue(inputs(ClosedInstrumentMethodFeatures(wide, bar_duration_seconds=900), wide, 25, seconds=900)["method_retest_confirmed"])
        self.assertFalse(inputs(ClosedInstrumentMethodFeatures(wide), wide, 25)["method_retest_confirmed"])

    def test_future_tail_cannot_rewrite_prefix_and_unclosed_invalid_or_injected_inputs_refuse(self):
        for feature, data, target in (("pullback", pullback_frame(), 61), ("retest", retest_frame(), 25)):
            changed = data.copy()
            changed.loc[target + 1:, ["open", "high", "low", "close"]] *= 2
            prefix, full = ClosedInstrumentMethodFeatures(data.iloc[:target + 1]), ClosedInstrumentMethodFeatures(changed)
            for index in range(target + 1):
                self.assertEqual(inputs(prefix, data, index), inputs(full, changed, index))
            row = data.iloc[target].to_dict()
            with self.assertRaisesRegex(ValueError, "ORIGINAL_CLOSED_ROW"):
                ClosedInstrumentMethodFeatures(data).inputs(row, direction="BUY", observed_at=row["time"].isoformat())
            with self.assertRaisesRegex(ValueError, "EXPLICIT_UTC"):
                ClosedInstrumentMethodFeatures(data).inputs(row, direction="BUY", observed_at="2025-01-06T12:00:00")
            altered = row.copy(); altered["close"] += 1
            with self.assertRaisesRegex(ValueError, "ORIGINAL_ROW_DRIFT"):
                ClosedInstrumentMethodFeatures(data).inputs(altered, direction="BUY", observed_at=(row["time"] + pd.Timedelta(minutes=5)).isoformat())
            malformed = data.copy(); malformed.loc[target, "high"] = float("nan")
            with self.assertRaisesRegex(ValueError, "OHLC_INVALID"):
                ClosedInstrumentMethodFeatures(malformed)
            with self.assertRaisesRegex(ValueError, "CLOSED_OHLC_REQUIRED"):
                ClosedInstrumentMethodFeatures(data.drop(columns="open"))

    def test_typed_runtime_uses_owner_values_not_injected_fields(self):
        for feature, data, before, confirmed in (("pullback", pullback_frame(), 60, 61), ("retest", retest_frame(), 24, 25)):
            request = SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5", replay_dataset_hash="finite-confirmation-fixture")
            pinned = recipe(request, feature)
            program = BoundedDecisionProgram(pinned["operators"][0], allow_method_features=True)
            self.assertEqual("confirmation", program.target)
            self.assertEqual({"available": "method_" + feature + "_available", "confirmed": "method_" + feature + "_confirmed"}, program.bindings)
            runtime = InstrumentMethodLearning(learning_contract(request, data, [pinned]), request)
            runtime.validate_frame(data)
            for index, expected in ((before, False), (confirmed, True)):
                row = data.iloc[index].to_dict()
                row.update({"method_" + feature + "_available": True, "method_" + feature + "_confirmed": not expected})
                observed = (row["time"] + pd.Timedelta(minutes=5)).isoformat()
                event = runtime.evaluate(row, decision_id=feature + str(index), phase="entry", observed_at=observed, decision_at=observed, direction="BUY")
                self.assertIs(expected, event["outputs"]["confirmation"])

    def test_actual_ordinary_replay_records_each_real_confirmation_and_veto_without_cost_clock_changes(self):
        for feature, data in (("pullback", pullback_frame(232, pullback=220)), ("retest", retest_frame(232, breakout=220))):
            request = SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5", replay_dataset_hash="finite-confirmation-fixture",
                parameters={"time_stop_candles": 1}, execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2))
            request.emit_decision_trace = True
            pinned = recipe(request, feature)
            contract = learning_contract(request, data, [pinned])
            execution_before = execution_contract_metadata(request)["execution_hash"]
            with patch("app.services.backtester.get_strategy", return_value=supplied_buy):
                baseline = run_simple_ema_rsi_backtest_on_dataframe(copy.deepcopy(request), data)
                request.policy_context["instrument_method_learning"] = contract
                actual = run_simple_ema_rsi_backtest_on_dataframe(request, data)
            self.assertGreater(actual.total_trades, 0)
            self.assertLess(actual.total_trades, baseline.total_trades)
            self.assertTrue(receipt_current(actual.model_dump(), contract))
            receipt = actual.data_quality["instrument_method_learning_receipt"]
            self.assertGreater(receipt["behavior_changed_count"], 0)
            self.assertTrue(any(event["outputs"].get("confirmation") is True for event in receipt["samples"]))
            self.assertTrue(any(event["outputs"].get("confirmation") is False for event in receipt["samples"]))
            self.assertFalse(receipt["economic_authority"])
            self.assertEqual(execution_before, execution_contract_metadata(request)["execution_hash"])
            self.assertEqual(baseline.data_quality["replay_executed_clock"]["schedule_hash"], actual.data_quality["replay_executed_clock"]["schedule_hash"])


if __name__ == "__main__":
    unittest.main()
