"""Synthetic software integration, not market outcomes or promotion evidence.

The actual registered EMA strategy is never replaced. Its physical parameters,
hard risk and nonzero costs stay identical while typed methods consume the
source-owned closed observations in the ordinary single-position replay.
"""

import hashlib
import math
import unittest

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures, PROTOCOL
from app.services.instrument_method_learning import receipt_current
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract
from test_instrument_method_learning import learning_contract


def software_frame():
    closes = [100.0 + index * .035 + .35 * math.sin(index / 3) for index in range(272)]
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=len(closes), freq="5min"),
        "open": closes, "high": [value + .02 for value in closes],
        "low": [value - .02 for value in closes], "close": closes,
        "volume": 0.0, "volume_available": False})


def software_request():
    # No lowered signal, regime, confidence or entry gate. A missing volume
    # source remains missing and the explicitly disabled lane is a NOOP.
    return SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5",
        parameters={"volume_lane": "none"}, replay_dataset_hash="synthetic-source-grounded-runtime",
        execution=ExecutionConfig(spread_points=1.0, slippage_points=.1,
            commission_percent=.001, swap_per_day_percent=.002),
        emit_decision_trace=True, emit_trade_ledger=True)


def owned_recipe(request, key, target, ast, input_bindings=None):
    # An unrestricted software recipe tests execution, not an invented London
    # or normal-volatility label. Actual strategy/context labels stay native.
    context, input_bindings = {}, input_bindings or {}
    bindings = {"symbol": request.symbol, "timeframe": request.timeframe,
        "data_hash": request.replay_dataset_hash,
        "execution_hash": execution_contract_metadata(request)["execution_hash"],
        "pre_2026_only": True, "baseline_hash": "b" * 64, "context_hash": canonical_hash(context)}
    if any(value.startswith("method_") for value in input_bindings.values()):
        bindings["method_feature_owner"] = PROTOCOL
    implementation = {"kind": "typed_ast", "target": target, "ast": ast,
        "input_bindings": input_bindings,
        "budget": {"cpu_seconds": 1.0, "max_calls": 1000, "max_node_evaluations": 48000}}
    method = {"protocol": "instrument_method_v1", "instrument_key": "synthetic-source-grounded",
        "method_key": key, "revision": 1, "context_predicate": context,
        "implementation": implementation, "research_only": True, "promotion_evidence": False}
    identity = canonical_hash(method)
    operator = seal_contract({"protocol": "typed_operator_decision_v1",
        "source_task_key": "method:" + identity, "scope_key": "synthetic-runtime-scope",
        "source_context": bindings, "target": target, "ast": ast,
        "ast_hash": canonical_hash(ast), "abstractions": {},
        "input_bindings": input_bindings, "budget": implementation["budget"]})
    revision = {"protocol": "instrument_method_recipe_v1", "recipe_key": key, "revision": 1,
        "method_hashes": [identity], "method_contracts": {identity: method},
        "context_predicate": context, "horizon": "hour", "hybrid": False,
        "research_only": True, "qualification_granted": False, "promotion_evidence": False}
    body = {"protocol": "instrument_method_recipe_runtime_v1", "recipe_key": key,
        "recipe_hash": canonical_hash(revision), "recipe_revision": revision,
        "recipe_revision_json": canonical_json(revision), "context_predicate": context,
        "operators": [operator], "parameter_adapters": [], "scope_bindings": bindings}
    return {**body, "runtime_hash": canonical_hash(body), "runtime_json": canonical_json(body)}


def feature_recipe(request, name, target, feature):
    ast = {"op": "AND", "args": [
        {"op": "BOOL", "input_key": "ready", "available_at": "2024-01-01T00:00:00Z"},
        {"op": "BOOL", "input_key": "measured", "available_at": "2024-01-01T00:00:00Z"}]}
    return owned_recipe(request, name, target, ast,
        {"ready": "method_" + name + "_available", "measured": feature})


def replay(data, *, name=None, target=None, feature=None, multiplier=None):
    request = software_request()
    contract = None
    if name is not None:
        recipe = feature_recipe(request, name, target, feature) if feature else owned_recipe(
            request, name, "risk_multiplier", {"op": "CONST", "type": "number", "value": multiplier})
        contract = learning_contract(request, data, [recipe])
        request.policy_context["instrument_method_learning"] = contract
    result = run_simple_ema_rsi_backtest_on_dataframe(request, data,
        include_differential_pair=False, lightweight=True)
    return request, contract, result


def method_rows(result):
    return [row for row in result.decision_trace if row.get("event_type") == "instrument_method_decision"]


class SourceGroundedMethodRuntimeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = software_frame()
        cls.baseline = replay(cls.data)
        cls.chandelier = replay(cls.data, name="chandelier", target="exit", feature="method_chandelier_exit")
        cls.reduced = replay(cls.data, name="half-risk", multiplier=.5)
        cls.confirmations = {name: replay(cls.data, name=name, target="confirmation", feature=field)
            for name, field in [("macd", "method_macd_cross"), ("rsi", "method_rsi_recovery")]}

    def assert_shared_physical_contract(self, left, right):
        self.assertEqual(left[0].parameters, {"volume_lane": "none"})
        self.assertEqual(left[0].parameters, right[0].parameters)
        self.assertEqual(left[0].execution.model_dump(), right[0].execution.model_dump())
        self.assertEqual(left[0].initial_balance, right[0].initial_balance)
        self.assertEqual(left[0].risk_per_trade, right[0].risk_per_trade)
        self.assertEqual(execution_contract_metadata(left[0])["execution_hash"],
            execution_contract_metadata(right[0])["execution_hash"])
        self.assertEqual(left[2].data_quality["replay_executed_clock"]["schedule_hash"],
            right[2].data_quality["replay_executed_clock"]["schedule_hash"])

    def assert_receipt(self, result, contract):
        events = [row["method_learning"] for row in method_rows(result)]
        receipt = result.data_quality["instrument_method_learning_receipt"]
        self.assertTrue(receipt_current(result.model_dump(), contract))
        self.assertEqual(receipt["event_count"], len(events))
        self.assertEqual(receipt["used_count"], sum(event["used"] for event in events))
        self.assertEqual(receipt["behavior_changed_count"], sum(event["behavior_changed"] for event in events))
        hasher = hashlib.sha256()
        for event in events:
            self.assertEqual(event["receipt_hash"], canonical_hash(
                {key: value for key, value in event.items() if key != "receipt_hash"}))
            encoded = canonical_json(event).encode()
            hasher.update(len(encoded).to_bytes(8, "big"))
            hasher.update(encoded)
            self.assertFalse(event["economic_authority"])
            self.assertFalse(event["promotion_evidence"])
        self.assertEqual(receipt["events_hash"], hasher.hexdigest())

    def test_unpatched_native_ema_missing_volume_and_actual_context_are_preserved(self):
        request, _, result = self.baseline
        self.assertGreater(result.total_trades, 0)
        self.assertEqual(len(result.trade_ledger), result.total_trades)
        self.assertTrue(all(trade.direction == "BUY" for trade in result.trade_ledger))
        self.assertTrue(all(trade.market_regime == "trend_up" for trade in result.trade_ledger))
        self.assertTrue(all(trade.volatility_regime in {"low_volatility", "normal_volatility", "high_volatility"}
            for trade in result.trade_ledger))
        self.assertFalse(request.specialist_council_contract)
        self.assertFalse(self.data["volume_available"].any())
        self.assertGreater(request.execution.commission_percent, 0)
        self.assertTrue(all(trade.execution_cost_percent > 0 for trade in result.trade_ledger))
        self.assertFalse(execution_contract_metadata(request)["sealed"])

    def test_macd_and_wilder_rsi_confirmation_consume_closed_directional_features_not_new_signals(self):
        for name, case in self.confirmations.items():
            with self.subTest(name=name):
                owner = ClosedInstrumentMethodFeatures(self.data, bar_duration_seconds=300)
                self.assert_shared_physical_contract(self.baseline, case)
                self.assert_receipt(case[2], case[1])
                entries = [row for row in method_rows(case[2]) if row["method_learning"]["phase"] == "entry"]
                self.assertGreater(len(entries), 0)
                for row in entries:
                    event = row["method_learning"]
                    closed = self.data.iloc[row["candle_index"] - 1].to_dict()
                    actual = owner.inputs(closed, direction=event["context"]["direction"].upper(),
                        observed_at=event["observed_at"])
                    field = "method_macd_cross" if name == "macd" else "method_rsi_recovery"
                    expected = actual["method_" + name + "_available"] and actual[field]
                    self.assertEqual(event["outputs"]["confirmation"], expected)
                    self.assertTrue(event["used"])
                    self.assertEqual(event["feature_protocol"], PROTOCOL)
                    self.assertEqual(event["observed_at"], event["decision_at"])
                    self.assertEqual(pd.Timestamp(event["observed_at"]),
                        closed["time"] + pd.Timedelta(minutes=5))
                # Recovery/cross timing need not coincide with this unchanged
                # EMA's candidate timing. Refusal is real consumption, not a
                # fabricated positive entry or evidence of profitable skill.
                self.assertLessEqual(case[2].total_trades, self.baseline[2].total_trades)

    def test_chandelier_owned_exit_is_prior_closed_next_open_and_never_rewrites_stop(self):
        self.assert_shared_physical_contract(self.baseline, self.chandelier)
        request, contract, result = self.chandelier
        self.assert_receipt(result, contract)
        first, baseline = result.trade_ledger[0], self.baseline[2].trade_ledger[0]
        self.assertEqual(first.entry_time, baseline.entry_time)
        self.assertEqual(first.entry_price, baseline.entry_price)
        self.assertEqual(first.stop_loss, baseline.stop_loss)
        self.assertEqual(first.take_profit, baseline.take_profit)
        self.assertEqual(first.position_size_multiple, baseline.position_size_multiple)
        self.assertEqual(first.risk_budget_percent, baseline.risk_budget_percent)
        self.assertEqual(first.exit_reason, "method_learning_exit")
        entry_time = pd.Timestamp(first.entry_time)
        true_rows = [row for row in method_rows(result) if row["method_learning"]["phase"] == "exit"
            and row["method_learning"]["outputs"].get("exit") is True]
        self.assertGreater(len(true_rows), 0)
        row = next(row for row in true_rows if pd.Timestamp(row["method_learning"]["decision_at"])
            == pd.Timestamp(first.exit_time))
        event = row["method_learning"]
        self.assertGreater(pd.Timestamp(event["decision_at"]), entry_time)
        closed = self.data.iloc[row["candle_index"] - 1].to_dict()
        owner = ClosedInstrumentMethodFeatures(self.data, bar_duration_seconds=300)
        self.assertTrue(owner.inputs(closed, direction=first.direction,
            observed_at=event["observed_at"])["method_chandelier_exit"])
        self.assertEqual(pd.Timestamp(event["observed_at"]), closed["time"] + pd.Timedelta(minutes=5))
        price = self.data.iloc[row["candle_index"]]["open"]
        cost = (request.execution.spread_points / 2 + request.execution.slippage_points) * request.execution.point_size
        self.assertEqual(first.exit_price, round(price - cost, 2))
        self.assertTrue(all(row["method_learning"]["outputs"] == []
            for row in method_rows(result) if row["method_learning"]["phase"] == "entry"))

    def test_risk_multiplier_reduces_actual_cost_inclusive_exposure_not_hard_risk_limit(self):
        self.assert_shared_physical_contract(self.baseline, self.reduced)
        request, contract, result = self.reduced
        self.assert_receipt(result, contract)
        self.assertEqual(result.total_trades, self.baseline[2].total_trades)
        for original, reduced in zip(self.baseline[2].trade_ledger, result.trade_ledger):
            self.assertEqual((reduced.entry_time, reduced.exit_time, reduced.entry_price, reduced.exit_price,
                reduced.stop_loss, reduced.take_profit, reduced.exit_reason),
                (original.entry_time, original.exit_time, original.entry_price, original.exit_price,
                    original.stop_loss, original.take_profit, original.exit_reason))
            self.assertAlmostEqual(reduced.position_size_multiple, original.position_size_multiple * .5, places=5)
            self.assertAlmostEqual(reduced.initial_risk_percent, original.initial_risk_percent * .5, places=7)
            self.assertAlmostEqual(reduced.execution_cost_percent, original.execution_cost_percent * .5, places=5)
            self.assertEqual(reduced.risk_budget_percent, request.risk_per_trade)
            self.assertLessEqual(reduced.position_size_multiple, request.execution.max_leverage)
            self.assertLessEqual(reduced.initial_risk_percent, request.risk_per_trade + 1e-7)

    def test_chandelier_exit_cannot_bypass_existing_gap_stop_execution_price(self):
        first = self.chandelier[2].trade_ledger[0]
        index = self.data.index[self.data["time"] == pd.Timestamp(first.exit_time)][0]
        data = self.data.copy()
        gap_open = first.stop_loss - 1.0
        data.loc[index, ["open", "high", "low", "close"]] = [gap_open, gap_open + .02,
            gap_open - .02, gap_open]
        request, contract, result = replay(data, name="chandelier", target="exit", feature="method_chandelier_exit")
        self.assert_receipt(result, contract)
        trade = result.trade_ledger[0]
        self.assertEqual(trade.entry_time, first.entry_time)
        self.assertEqual(trade.exit_time, first.exit_time)
        self.assertEqual(trade.exit_reason, "gap_stop")
        cost = (request.execution.spread_points / 2 + request.execution.slippage_points) * request.execution.point_size
        self.assertEqual(trade.exit_price, round(gap_open - cost, 2))
        event = next(row["method_learning"] for row in method_rows(result)
            if row["candle_index"] == index and row["method_learning"]["phase"] == "exit")
        self.assertTrue(event["outputs"]["exit"])
        self.assertTrue(event["behavior_changed"])
        # Receipt records the requested close-only behavior; actual physical
        # fill still belongs to the pre-existing hard-stop gap guard.
        self.assertNotEqual(trade.exit_reason, "method_learning_exit")


if __name__ == "__main__":
    unittest.main()
