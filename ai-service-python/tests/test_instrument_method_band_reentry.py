"""Closed-band confirmation software fixtures; no empirical trading claim."""

import copy
import json
from pathlib import Path
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures, PROTOCOL
from app.services.instrument_method_learning import InstrumentMethodLearning
from app.services.research_program_tasks import BoundedDecisionProgram, canonical_hash, canonical_json
from app.services.specialist_council import seal_contract
from test_instrument_method_learning import learning_contract


ROOT = Path(__file__).resolve().parents[2]
DECLARATION = ROOT / "backend-laravel/resources/research/instrument-methods/closed-band-reentry/method.json"


def frame(count=40, *, outside=24, side="BUY"):
    closes = [100.0 + (1.0 if index % 2 else -1.0) for index in range(count)]
    closes[outside] = 90.0 if side == "BUY" else 110.0
    closes[outside + 1] = 99.5 if side == "BUY" else 100.5
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=count, freq="5min"),
        "open": closes, "high": [value + .1 for value in closes],
        "low": [value - .1 for value in closes], "close": closes,
        "market_session": "london", "market_regime": "range", "volatility_regime": "normal_volatility"})


def inputs(owner, data, index, *, direction="BUY"):
    row = data.iloc[index].to_dict()
    return owner.inputs(row, direction=direction,
        observed_at=(row["time"] + pd.Timedelta(minutes=5)).isoformat())


def range_strategy(data, parameters):
    result = data.copy()
    result["signal"] = "BUY"
    result["signal_confidence"] = 1.0
    result["market_regime"] = "range"
    result["market_session"] = "london"
    result["volatility_regime"] = "normal_volatility"
    return result


def pinned_declaration(request):
    """Pin the real checked-in AST into the existing original runtime contract."""
    method = json.loads(DECLARATION.read_text(encoding="utf-8"))
    context = method["context_predicate"]
    implementation = method["implementation"]
    method_hash = canonical_hash(method)
    bindings = {"symbol": request.symbol, "timeframe": request.timeframe,
        "data_hash": request.replay_dataset_hash,
        "execution_hash": execution_contract_metadata(request)["execution_hash"],
        "pre_2026_only": True, "baseline_hash": "b" * 64,
        "context_hash": canonical_hash(context), "method_feature_owner": PROTOCOL}
    operator = seal_contract({"protocol": "typed_operator_decision_v1",
        "source_task_key": "method:" + method_hash, "scope_key": "range-software-fixture",
        "source_context": bindings, "target": implementation["target"],
        "ast": implementation["ast"], "ast_hash": canonical_hash(implementation["ast"]),
        "abstractions": {}, "input_bindings": implementation["input_bindings"],
        "budget": implementation["budget"]})
    revision = {"protocol": "instrument_method_recipe_v1", "recipe_key": "closed-band-software-fixture",
        "revision": 2, "method_hashes": [method_hash], "method_contracts": {method_hash: method},
        "context_predicate": context, "horizon": "hour", "hybrid": False,
        "research_only": True, "qualification_granted": False, "promotion_evidence": False}
    body = {"protocol": "instrument_method_recipe_runtime_v1", "recipe_key": revision["recipe_key"],
        "recipe_hash": canonical_hash(revision), "recipe_revision": revision,
        "recipe_revision_json": canonical_json(revision), "context_predicate": context,
        "operators": [operator], "parameter_adapters": [], "scope_bindings": bindings}
    return {**body, "runtime_hash": canonical_hash(body), "runtime_json": canonical_json(body)}


class ClosedBandReentryTest(unittest.TestCase):
    def test_two_positive_width_windows_are_required_and_degenerate_bands_wait(self):
        data = frame()
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(inputs(owner, data, 19)["method_band_available"])
        self.assertTrue(inputs(owner, data, 20)["method_band_available"])
        flat = data.copy()
        flat.loc[:, ["open", "close"]] = 100.0
        flat.loc[:, "high"], flat.loc[:, "low"] = 100.1, 99.9
        result = inputs(ClosedInstrumentMethodFeatures(flat), flat, 25)
        self.assertFalse(result["method_band_available"])
        self.assertFalse(result["method_band_reentry"])

    def test_buy_and_sell_need_their_own_side_prior_outside_and_current_inside(self):
        for direction in ("BUY", "SELL"):
            data = frame(side=direction)
            owner = ClosedInstrumentMethodFeatures(data)
            previous, current = owner.features[24], owner.features[25]
            self.assertFalse(inputs(owner, data, 24, direction=direction)["method_band_reentry"])
            self.assertTrue(current["_band_lower"] < data.iloc[25]["close"] < current["_band_upper"])
            if direction == "BUY":
                self.assertLess(data.iloc[24]["close"], previous["_band_lower"])
            else:
                self.assertGreater(data.iloc[24]["close"], previous["_band_upper"])
            self.assertNotEqual(previous["_band_lower"], current["_band_lower"])
            self.assertTrue(inputs(owner, data, 25, direction=direction)["method_band_reentry"])
            wrong = "SELL" if direction == "BUY" else "BUY"
            self.assertFalse(inputs(owner, data, 25, direction=wrong)["method_band_reentry"])
            self.assertFalse(inputs(owner, data, 25, direction="WAIT")["method_band_reentry"])
            self.assertFalse(inputs(owner, data, 26, direction=direction)["method_band_reentry"])

    def test_contact_and_continuing_outside_close_are_not_reentry(self):
        data = frame()
        data.loc[24, ["open", "close", "high", "low"]] = [99.0, 99.0, 100.0, 80.0]
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertLess(data.iloc[24]["low"], owner.features[24]["_band_lower"])
        self.assertFalse(inputs(owner, data, 25)["method_band_reentry"])
        continuing = frame()
        continuing.loc[25, ["open", "close", "high", "low"]] = [89.0, 89.0, 89.1, 88.9]
        self.assertFalse(inputs(ClosedInstrumentMethodFeatures(continuing), continuing, 25)["method_band_reentry"])

    def test_future_extremes_do_not_rewrite_prefix_or_enter_before_the_bar_closes(self):
        data = frame()
        altered = data.copy()
        altered.loc[26:, ["open", "high", "low", "close"]] *= 100.0
        prefix, full = ClosedInstrumentMethodFeatures(data.iloc[:26]), ClosedInstrumentMethodFeatures(altered)
        for index in range(26):
            self.assertEqual(inputs(prefix, data, index), inputs(full, altered, index))
        owner = ClosedInstrumentMethodFeatures(data)
        row = data.iloc[25].to_dict()
        with self.assertRaisesRegex(ValueError, "ORIGINAL_CLOSED_ROW"):
            owner.inputs(row, direction="BUY", observed_at=row["time"].isoformat())

    def test_widening_band_alone_cannot_recapture_a_stationary_outside_close(self):
        data = frame()
        data.loc[24:27, ["open", "close", "high", "low"]] = [90.0, 90.0, 90.1, 89.9]
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertLess(data.iloc[26]["close"], owner.features[26]["_band_lower"])
        current = owner.features[27]
        self.assertTrue(current["_band_lower"] < data.iloc[27]["close"] < current["_band_upper"])
        self.assertTrue(inputs(owner, data, 27)["method_band_available"])
        self.assertFalse(inputs(owner, data, 27)["method_band_reentry"])

    def test_real_declaration_binds_typed_closed_inputs_and_owner_overwrites_injection(self):
        request = SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5",
            replay_dataset_hash="range-reentry-software-fixture")
        data = range_strategy(frame(), {})
        recipe = pinned_declaration(request)
        program = BoundedDecisionProgram(recipe["operators"][0], allow_method_features=True)
        self.assertEqual(program.target, "confirmation")
        self.assertEqual(program.bindings, {"available": "method_band_available", "reentry": "method_band_reentry"})
        runtime = InstrumentMethodLearning(learning_contract(request, data, [recipe]), request)
        runtime.validate_frame(data)
        observed = (data.iloc[24]["time"] + pd.Timedelta(minutes=5)).isoformat()
        forged = data.iloc[24].to_dict()
        forged.update(method_band_available=True, method_band_reentry=True)
        denied = runtime.evaluate(forged, decision_id="outside", phase="entry",
            observed_at=observed, decision_at=observed, direction="BUY")
        self.assertFalse(denied["outputs"]["confirmation"])
        observed = (data.iloc[25]["time"] + pd.Timedelta(minutes=5)).isoformat()
        admitted = runtime.evaluate(data.iloc[25].to_dict(), decision_id="reentry", phase="entry",
            observed_at=observed, decision_at=observed, direction="BUY")
        self.assertTrue(admitted["outputs"]["confirmation"])
        self.assertEqual(admitted["recipe_hash"], recipe["recipe_hash"])
        self.assertEqual(admitted["feature_protocol"], PROTOCOL)

    def test_actual_ordinary_backtest_records_veto_and_reentry_without_clock_or_cost_changes(self):
        request = SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5",
            replay_dataset_hash="range-reentry-software-fixture", parameters={"time_stop_candles": 1},
            execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2))
        request.emit_decision_trace = True
        data = frame(232, outside=220)
        recipe = pinned_declaration(request)
        contract = learning_contract(request, data, [recipe])
        execution_before = execution_contract_metadata(request)["execution_hash"]
        with patch("app.services.backtester.get_strategy", return_value=range_strategy):
            baseline = run_simple_ema_rsi_backtest_on_dataframe(copy.deepcopy(request), data)
            request.policy_context["instrument_method_learning"] = contract
            variant = run_simple_ema_rsi_backtest_on_dataframe(request, data)
        self.assertGreater(variant.total_trades, 0)
        self.assertLess(variant.total_trades, baseline.total_trades)
        receipt = variant.data_quality["instrument_method_learning_receipt"]
        self.assertGreater(receipt["behavior_changed_count"], 0)
        self.assertTrue(any(event["outputs"].get("confirmation") is True for event in receipt["samples"]))
        self.assertTrue(any(event["outputs"].get("confirmation") is False for event in receipt["samples"]))
        self.assertFalse(receipt["economic_authority"])
        self.assertEqual(execution_before, execution_contract_metadata(request)["execution_hash"])
        self.assertEqual(baseline.data_quality["replay_executed_clock"]["schedule_hash"],
            variant.data_quality["replay_executed_clock"]["schedule_hash"])


if __name__ == "__main__":
    unittest.main()
