"""Explicit prospective price-R activation; synthetic software checks only."""

import copy
import json
import math
from pathlib import Path
import shutil
import subprocess
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services import backtester
from app.services.composition_runtime import effective_management_parameters
from app.services.parameter_schema import EXECUTION_PARAMETER_SCHEMA, validate_strategy_parameters


def legacy_trail(position, previous_candle, payload):
    """Frozen pre-change function, for exact same-request response parity."""
    management_parameters = effective_management_parameters(
        payload.composition_runtime_contract, payload.parameters
    )
    multiplier = float(management_parameters.get("trailing_atr_multiplier", 0) or 0)
    atr = float(previous_candle.get("_management_atr", 0) or 0)
    if multiplier <= 0 or atr <= 0:
        return
    distance = multiplier * atr
    if str(position["direction"]) == "BUY":
        position["stop_loss"] = max(
            float(position["stop_loss"]), float(previous_candle["close"]) - distance
        )
    else:
        position["stop_loss"] = min(
            float(position["stop_loss"]), float(previous_candle["close"]) + distance
        )


def position(direction="BUY"):
    return dict(direction=direction, entry_price=100.0, market_entry_price=99.9,
        entry_time="2025-01-06T00:10:00Z", initial_stop_loss=95.0 if direction == "BUY" else 105.0,
        initial_risk_distance=5.0, stop_loss=95.0 if direction == "BUY" else 105.0,
        maximum_favorable_excursion=10000.0)


def closed(close=105.0, atr=1.0, time="2025-01-06T00:10:00Z"):
    return pd.Series(dict(time=time, close=close, _management_atr=atr, high=10000.0, low=.01))


def request(activation=1.0):
    parameters = dict(trailing_atr_multiplier=2.0, atr_stop_multiplier=1.5,
        atr_target_multiplier=2.5, partial_take_profit_fraction=0.0, time_stop_candles=0)
    if activation is not None:
        parameters["trailing_activation_r"] = activation
    return SimpleBacktestRequest(strategy="ema_rsi_v1", timeframe="M5", parameters=parameters,
        initial_balance=10000.0, risk_per_trade=1.0, random_seed=17, evaluation_mode="incremental",
        emit_trade_ledger=True, emit_decision_trace=True,
        execution=ExecutionConfig(spread_points=2.0, slippage_points=.5,
            commission_percent=.01, swap_per_day_percent=.002))


def software_frame():
    frame = pd.DataFrame(dict(time=pd.date_range("2025-01-06T00:00:00Z", periods=208, freq="5min"),
        open=[100.0] * 208, high=[101.0] * 208, low=[99.0] * 208, close=[100.0] * 208,
        volume=[0.0] * 208, volume_available=[False] * 208))
    frame.loc[200, ["open", "high", "low", "close"]] = [100.0, 102.1, 99.5, 102.0]
    frame.loc[201, ["open", "high", "low", "close"]] = [102.0, 102.2, 97.5, 102.0]
    frame.loc[202, ["open", "high", "low", "close"]] = [102.0, 104.0, 101.8, 103.5]
    frame.loc[203, ["open", "high", "low", "close"]] = [103.5, 104.0, 102.0, 103.8]
    frame.loc[204, ["open", "high", "low", "close"]] = [103.8, 106.0, 103.0, 105.0]
    return frame


def software_signal(frame, _parameters):
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame.loc[199, "signal"] = "BUY"
    frame["signal_confidence"] = 1.0
    return frame


def replay(payload, trail=None, frame=None):
    with patch("app.services.backtester.get_strategy", return_value=software_signal):
        if trail is None:
            return backtester.run_simple_ema_rsi_backtest_on_dataframe(payload,
                software_frame() if frame is None else frame, include_differential_pair=False, lightweight=True)
        with patch("app.services.backtester._advance_trailing_stop", side_effect=trail):
            return backtester.run_simple_ema_rsi_backtest_on_dataframe(payload,
                software_frame() if frame is None else frame, include_differential_pair=False, lightweight=True)


class TrailingActivationTest(unittest.TestCase):
    def test_buy_sell_reject_preentry_bar_and_arm_at_prior_close_equality(self):
        for direction, equality in [("BUY", 105.0), ("SELL", 95.0)]:
            with self.subTest(direction=direction):
                owned = position(direction)
                initial = copy.deepcopy(owned)
                self.assertIsNone(backtester._advance_trailing_stop(owned,
                    closed(equality, time="2025-01-06T00:05:00Z"), request()))
                self.assertEqual(owned, initial)
                below = equality - .001 if direction == "BUY" else equality + .001
                backtester._advance_trailing_stop(owned, closed(below), request())
                self.assertEqual(owned, initial)
                backtester._advance_trailing_stop(owned, closed(equality), request())
                self.assertTrue(owned["trailing_activation_armed"])
                self.assertEqual(owned["stop_loss"], 103.0 if direction == "BUY" else 97.0)

    def test_activation_latches_and_ratchet_never_loosens_after_unfavorable_close(self):
        for direction, equality, adverse, later, expected in [
                ("BUY", 105.0, 101.0, 104.5, 103.5),
                ("SELL", 95.0, 99.0, 95.5, 96.5)]:
            with self.subTest(direction=direction):
                owned = position(direction)
                backtester._advance_trailing_stop(owned, closed(equality), request())
                stop = owned["stop_loss"]
                backtester._advance_trailing_stop(owned, closed(adverse, .5), request())
                self.assertTrue(owned["trailing_activation_armed"])
                self.assertEqual(owned["stop_loss"], stop)
                backtester._advance_trailing_stop(owned, closed(later, .5), request())
                self.assertEqual(owned["stop_loss"], expected)
                self.assertEqual(owned["initial_risk_distance"], 5.0)
                self.assertEqual(owned["initial_stop_loss"], 95.0 if direction == "BUY" else 105.0)

    def test_activation_uses_executable_entry_and_immutable_initial_stop_fallback(self):
        for direction, equality in [("BUY", 105.0), ("SELL", 95.0)]:
            with self.subTest(direction=direction):
                owned = position(direction)
                del owned["initial_risk_distance"]
                owned["market_entry_price"] = 90.0 if direction == "BUY" else 110.0
                owned["stop_loss"] = 99.0 if direction == "BUY" else 101.0
                short_of_initial_r = 104.99 if direction == "BUY" else 95.01
                backtester._advance_trailing_stop(owned, closed(short_of_initial_r), request())
                self.assertNotIn("trailing_activation_armed", owned)
                backtester._advance_trailing_stop(owned, closed(equality), request())
                self.assertTrue(owned["trailing_activation_armed"])

    def test_missing_invalid_geometry_atr_and_clock_do_not_change_hard_stop(self):
        changes = [dict(entry_price=None), dict(initial_risk_distance=0.0),
            dict(initial_risk_distance=-1.0), dict(initial_risk_distance=math.nan),
            dict(initial_risk_distance=None, initial_stop_loss=None), dict(entry_time=None),
            dict(entry_time="invalid"), dict(entry_time="2025-01-06T00:10:00"),
            dict(direction="WAIT"), dict(stop_loss=math.inf)]
        for change in changes:
            with self.subTest(change=change):
                owned = {**position(), **change}
                before = copy.deepcopy(owned)
                backtester._advance_trailing_stop(owned, closed(), request())
                self.assertEqual(repr(owned), repr(before))
        for row in [closed(atr=None), closed(atr=0), closed(atr=math.nan), closed(atr=math.inf),
                closed(close=None), closed(close=math.nan), closed(time=None), closed(time="invalid"),
                closed(time="2025-01-06T00:10:00")]:
            with self.subTest(row=row.to_dict()):
                owned = position(); before = copy.deepcopy(owned)
                backtester._advance_trailing_stop(owned, row, request())
                self.assertEqual(owned, before)

    def test_current_or_prior_extrema_and_mfe_cannot_activate_below_closed_threshold(self):
        for direction, previous_close in [("BUY", 104.99), ("SELL", 95.01)]:
            with self.subTest(direction=direction):
                owned = position(direction)
                before = copy.deepcopy(owned)
                backtester._advance_trailing_stop(owned, closed(previous_close), request())
                self.assertEqual(owned, before)

    def test_zero_or_missing_retains_exact_legacy_function_state_and_return(self):
        for activation in [None, 0.0]:
            for direction, value in [("BUY", 110.0), ("SELL", 90.0)]:
                with self.subTest(activation=activation, direction=direction):
                    # Legacy does not require activation geometry or a clock.
                    owned = dict(direction=direction, stop_loss=95.0 if direction == "BUY" else 105.0)
                    expected = copy.deepcopy(owned)
                    row = pd.Series(dict(close=value, _management_atr=1.0))
                    old_return = legacy_trail(expected, row, request(activation))
                    actual_return = backtester._advance_trailing_stop(owned, row, request(activation))
                    self.assertEqual(actual_return, old_return)
                    self.assertEqual(owned, expected)

    def test_complete_same_request_response_matches_legacy_for_missing_and_zero(self):
        observed = []
        for activation in [None, 0.0]:
            with self.subTest(activation=activation):
                payload = request(activation)
                legacy = replay(payload.model_copy(deep=True), legacy_trail)
                actual = replay(payload.model_copy(deep=True))
                self.assertGreater(actual.total_trades, 0)
                self.assertEqual(actual.model_dump(), legacy.model_dump())
                observed.append(actual)
        # Different submitted parameter maps retain their own signed identity.
        # Their actual trade/cash/executed-clock outputs are identical.
        self.assertEqual(observed[0].trade_ledger, observed[1].trade_ledger)
        self.assertEqual(observed[0].final_balance, observed[1].final_balance)
        self.assertEqual(observed[0].data_quality["replay_executed_clock"]["schedule_hash"],
            observed[1].data_quality["replay_executed_clock"]["schedule_hash"])

    def test_actual_synthetic_loop_waits_for_completed_one_r_before_arming(self):
        calls = []
        native = backtester._advance_trailing_stop
        def observe(owned, previous, payload):
            before = owned["stop_loss"]
            result = native(owned, previous, payload)
            calls.append(dict(time=previous["time"], entry=owned["entry_price"],
                risk=owned["initial_risk_distance"], before=before, after=owned["stop_loss"],
                close=previous["close"], armed=owned.get("trailing_activation_armed", False)))
            return result
        delayed = replay(request(1.0), observe)
        always = replay(request(0.0))
        self.assertEqual(delayed.total_trades, 1)
        self.assertEqual(always.total_trades, 1)
        for call in calls:
            if call["close"] < call["entry"] + call["risk"] and not call["armed"]:
                self.assertEqual(call["before"], call["after"])
        first = next(call for call in calls if call["armed"])
        self.assertGreaterEqual(first["time"], pd.Timestamp("2025-01-06T16:40:00Z"))
        self.assertGreaterEqual(first["close"], first["entry"] + first["risk"])
        self.assertGreater(first["after"], first["before"])
        self.assertNotEqual(delayed.trade_ledger[0].exit_time, always.trade_ledger[0].exit_time)
        entry = lambda result: next(row for row in result.decision_trace
            if row.get("event_type") == "signal_evaluation" and row.get("accepted") is True)
        self.assertEqual(entry(delayed)["state"]["entry_price"], entry(always)["state"]["entry_price"])
        self.assertEqual(entry(delayed)["state"]["stop_loss"], entry(always)["state"]["stop_loss"])
        self.assertEqual(entry(delayed)["state"]["position_size_multiple"], entry(always)["state"]["position_size_multiple"])

    def test_new_parameter_is_explicit_bounded_and_finite_in_python(self):
        self.assertEqual(EXECUTION_PARAMETER_SCHEMA["trailing_activation_r"]["default"], 0.0)
        self.assertEqual(validate_strategy_parameters("ema_rsi_v1", {}), {})
        for value in [0, 1.0, 4.0]:
            self.assertEqual(validate_strategy_parameters("ema_rsi_v1", {"trailing_activation_r": value}),
                {"trailing_activation_r": float(value)})
        for value in [-.01, 4.01, math.nan, math.inf, True, "1.0"]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                validate_strategy_parameters("ema_rsi_v1", {"trailing_activation_r": value})

    def test_shadow_initial_geometry_is_explicit_only_and_keeps_legacy_structure(self):
        signal = pd.Series(dict(time=pd.Timestamp("2025-01-06T00:05:00Z"), _management_atr=2.0))
        candle = pd.Series(dict(time=pd.Timestamp("2025-01-06T00:10:00Z"), open=100.0))
        legacy_keys = {"veto_reason", "direction", "signal_time", "entry_time", "entry_price",
            "market_entry_price", "stop_loss", "take_profit", "position_size_multiple", "market_regime",
            "volatility_regime", "spread_context", "spread_percent", "p_allow", "p_veto", "exploration_assigned",
            "policy_arm", "entry_index", "signal_row", "execution_parameters", "partial_closed",
            "partial_fraction", "partial_exit_price"}
        for activation in [None, 0.0, 1.0]:
            with self.subTest(activation=activation):
                owned = backtester._open_shadow_position(candle, signal, "BUY", request(activation), 200, "software-veto")
                expected = legacy_keys if activation != 1.0 else legacy_keys | {"initial_stop_loss", "initial_risk_distance"}
                self.assertEqual(set(owned), expected)
                if activation == 1.0:
                    self.assertEqual(owned["initial_stop_loss"], owned["stop_loss"])
                    self.assertEqual(owned["initial_risk_distance"], abs(owned["entry_price"] - owned["stop_loss"]))
        for invalid in [-1.0, 4.01, math.nan, math.inf, True]:
            payload = request(None)
            payload = payload.model_copy(update={"parameters": {**payload.parameters, "trailing_activation_r": invalid}})
            self.assertEqual(set(backtester._open_shadow_position(candle, signal, "BUY", payload, 200, "software-veto")), legacy_keys)

    def test_actual_shadow_lifecycle_uses_preentry_guard_equality_and_latched_initial_r(self):
        payload = request(1.0)
        signal = pd.Series(dict(time=pd.Timestamp("2025-01-06T00:05:00Z"), _management_atr=2.0))
        opened = pd.Series(dict(time=pd.Timestamp("2025-01-06T00:10:00Z"), open=100.0,
            high=100.2, low=99.8, close=100.0))
        for direction in ["BUY", "SELL"]:
            with self.subTest(direction=direction):
                owned = backtester._open_shadow_position(opened, signal, direction, payload, 200, "software-veto")
                initial_stop, risk = owned["initial_stop_loss"], owned["initial_risk_distance"]
                sign = 1 if direction == "BUY" else -1
                threshold = owned["entry_price"] + sign * risk
                # Even a qualifying pre-entry close cannot activate the shadow.
                self.assertIsNone(backtester._advance_shadow_position(owned, opened,
                    closed(threshold, time=signal["time"]), payload, 200))
                self.assertEqual(owned["stop_loss"], initial_stop)
                self.assertNotIn("trailing_activation_armed", owned)
                current = lambda time, price: pd.Series(dict(time=pd.Timestamp(time),
                    open=price, high=price + .01, low=price - .01, close=price))
                self.assertIsNone(backtester._advance_shadow_position(owned,
                    current("2025-01-06T00:15:00Z", threshold), closed(threshold), payload, 201))
                self.assertTrue(owned["trailing_activation_armed"])
                self.assertAlmostEqual(owned["stop_loss"], threshold - sign * 2.0)
                # A later close short of 1R can still improve an armed trail.
                later = threshold - sign * .5
                self.assertIsNone(backtester._advance_shadow_position(owned,
                    current("2025-01-06T00:20:00Z", later),
                    closed(later, .5, "2025-01-06T00:15:00Z"), payload, 202))
                self.assertTrue(owned["trailing_activation_armed"])
                self.assertAlmostEqual(owned["stop_loss"], later - sign)
                self.assertEqual(owned["initial_stop_loss"], initial_stop)
                self.assertEqual(owned["initial_risk_distance"], risk)

    @unittest.skipUnless(shutil.which("php"), "PHP is required for cross-runtime schema witness")
    def test_php_explicit_schema_parity_without_expanding_ordinary_sampling_or_defaults(self):
        root = Path(__file__).resolve().parents[2]
        code = r'''
require 'backend-laravel/app/Services/StrategyParameterSchemaService.php';
$s = new App\Services\StrategyParameterSchemaService();
$invalid = 0;
foreach ([-.01, 4.01, NAN, INF, true, '1.0'] as $value) {
    try { $s->validate('ema_rsi', ['trailing_activation_r' => $value]); }
    catch (InvalidArgumentException $error) { $invalid++; }
}
echo json_encode(['ordinary_schema' => $s->schema('ema_rsi'),
    'explicit_schema' => $s->schema('ema_rsi', true), 'ordinary_defaults' => $s->defaults('ema_rsi'),
    'explicit_defaults' => $s->defaults('ema_rsi', true), 'invalid_refused' => $invalid,
    'valid' => $s->validate('ema_rsi', ['trailing_activation_r' => 1.0])], JSON_THROW_ON_ERROR);
'''
        completed = subprocess.run([shutil.which("php"), "-r", code], cwd=root,
            check=True, text=True, capture_output=True, timeout=20)
        actual = json.loads(completed.stdout)
        self.assertNotIn("trailing_activation_r", actual["ordinary_schema"])
        self.assertNotIn("trailing_activation_r", actual["ordinary_defaults"])
        self.assertEqual(actual["explicit_schema"]["trailing_activation_r"], ["numeric", 0.0, 4.0])
        self.assertEqual(actual["explicit_defaults"], {**actual["ordinary_defaults"], "trailing_activation_r": 0.0})
        self.assertEqual(actual["invalid_refused"], 6)
        self.assertEqual(actual["valid"], {"trailing_activation_r": 1.0})


if __name__ == "__main__":
    unittest.main()
