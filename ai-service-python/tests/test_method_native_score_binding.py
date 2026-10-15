"""Native-score binding fixtures; no invented confidence or market authority.

Plain EMA/RSI deliberately emits no score. Its admission compatibility fallback
must not become an instrument-method observation. Real trend scores are already
computed from closed ADX and must survive ordinary execution unchanged.
"""

import copy
import json
import math
from pathlib import Path
import unittest

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import (
    _effective_lane_signal,
    prepare_signal_snapshot,
    run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_learning import InstrumentMethodLearning
from test_instrument_method_learning import learning_contract, pinned_recipe


ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "backend-laravel/resources/research/instrument-methods/curated-v1.json"


def source_frame(count=250):
    # Vary range independently of price so natural volatility classification
    # genuinely includes normal London rows after the 200-row execution warmup.
    closes = [100.0 + index * .03 + .5 * math.sin(index * .3) for index in range(count)]
    ranges = [.3 + .12 * math.sin(index * .17) for index in range(count)]
    return pd.DataFrame({
        "time": pd.date_range("2025-01-05T17:00:00Z", periods=count, freq="5min"),
        "open": closes,
        "high": [price + width for price, width in zip(closes, ranges)],
        "low": [price - width for price, width in zip(closes, ranges)],
        "close": closes,
        "volume": 0.0,
        "volume_available": False,
    })


def request(strategy="ema_rsi_v1", base="ema_rsi"):
    return SimpleBacktestRequest(
        strategy=strategy, base_strategy=base, timeframe="M5",
        replay_dataset_hash="synthetic-native-score-binding-not-market-data",
        initial_balance=10000.0, risk_per_trade=1.0,
        emit_decision_trace=True, emit_trade_ledger=True,
        parameters={
            "ema_fast": 50, "ema_slow": 200, "rsi_period": 14,
            "volume_lane": "none", "time_stop_candles": 1,
            "minimum_signal_confidence": 0.0,
            "trend_strength_min": 20.0, "pullback_atr_fraction": 10.0,
        },
        execution=ExecutionConfig(
            spread_points=1.0, point_size=.01, slippage_points=.1,
            commission_percent=.01, stop_loss_percent=1.0,
            take_profit_percent=2.0,
        ),
    )


def confidence_recipe(payload):
    method = next(method for method in json.loads(CATALOG.read_text(encoding="utf-8"))["methods"]
        if method["method_key"] == "internet_signal_score_veto_0_7_v1" and method["revision"] == 1)
    implementation = method["implementation"]
    return pinned_recipe(payload, "native-score-binding-software-fixture",
        {"confirmation": implementation["ast"]}, implementation["input_bindings"])


def attach(payload, data):
    recipe = confidence_recipe(payload)
    contract = learning_contract(payload, data, [recipe])
    payload.policy_context["instrument_method_learning"] = contract
    return contract


def entry_events(result):
    return [row["method_learning"] for row in result.decision_trace
        if row.get("event_type") == "instrument_method_decision"
        and row["method_learning"]["phase"] == "entry"
        and row["method_learning"]["used"]]


def execute(payload, data):
    return run_simple_ema_rsi_backtest_on_dataframe(
        payload, data, include_differential_pair=False, lightweight=True)


def direct_case(*, target="confirmation", value=Ellipsis):
    payload = request()
    data = source_frame(4)
    # This unit case supplies a declared context, separately from the actual
    # ordinary-executor tests, which use the genuine classifier/calendar.
    data["signal"] = "BUY"
    data["market_regime"] = "trend_up"
    data["market_session"] = "london"
    data["volatility_regime"] = "normal_volatility"
    if value is not Ellipsis:
        data["signal_confidence"] = pd.Series([value] * len(data), dtype=object)
    number = {"op": "NUMBER", "input_key": "native_score", "available_at": "2024-01-01T00:00:00Z"}
    ast = number if target == "risk_multiplier" else {"op": "GREATER_THAN", "args": [
        number, {"op": "CONST", "type": "number", "value": .7}]}
    recipe = pinned_recipe(payload, "score-dependency-" + target,
        {target: ast}, {"native_score": "signal_confidence"})
    runtime = InstrumentMethodLearning(learning_contract(payload, data, [recipe]), payload)
    runtime.validate_frame(data)
    row = data.iloc[1].to_dict()
    stamp = (row["time"] + pd.Timedelta(minutes=5)).isoformat()
    return runtime, row, stamp


class MethodNativeScoreBindingTest(unittest.TestCase):
    def test_actual_scoreless_ema_reaches_london_and_waits_without_fabricated_score(self):
        data = source_frame()
        original = data.copy(deep=True)
        payload = request()
        snapshot = prepare_signal_snapshot(payload, data)
        self.assertNotIn("signal_confidence", snapshot.frame)
        self.assertNotIn("pre_volume_signal_confidence", snapshot.frame)
        self.assertEqual(_effective_lane_signal(snapshot.frame.iloc[206], None)[1], 1.0)
        parameters = copy.deepcopy(payload.parameters)
        physical = execution_contract_metadata(payload)
        baseline = execute(payload, data)
        self.assertGreater(baseline.total_trades, 0)
        attach(payload, data)
        treated = execute(payload, data)
        events = entry_events(treated)
        self.assertGreater(len(events), 0)
        self.assertTrue(all(event["context"]["session"] == "london" for event in events))
        self.assertTrue(all(event["context"]["regime"] == "trend_up" for event in events))
        self.assertTrue(all(event["reason"] == "method_native_score_unavailable" for event in events))
        self.assertTrue(all(event["outputs"]["confirmation"] is False for event in events))
        self.assertTrue(all(event["decision_at"] == event["observed_at"] for event in events))
        self.assertEqual(treated.data_quality["replay_executed_clock"],
            baseline.data_quality["replay_executed_clock"])
        self.assertEqual(treated.execution_assumptions, baseline.execution_assumptions)
        self.assertEqual(payload.parameters, parameters)
        self.assertEqual(execution_contract_metadata(payload), physical)
        self.assertEqual(payload.risk_per_trade, 1.0)
        pd.testing.assert_frame_equal(data, original)

    def test_actual_trend_generated_closed_adx_score_is_consumed_unchanged(self):
        data = source_frame()
        payload = request("trend_v1", "trend")
        snapshot = prepare_signal_snapshot(payload, data)
        attach(payload, data)
        parameters = copy.deepcopy(payload.parameters)
        physical = execution_contract_metadata(payload)
        result = execute(payload, data)
        events = entry_events(result)
        self.assertGreater(len(events), 0)
        expected = snapshot.frame.copy()
        expected["observed"] = expected["time"] + pd.Timedelta(minutes=5)
        rows = {row["observed"].isoformat(): row for row in expected.to_dict("records")}
        outputs = []
        for event in events:
            closed = rows[event["observed_at"]]
            generated = float(closed["signal_confidence"])
            self.assertTrue(math.isfinite(generated))
            self.assertGreaterEqual(generated, 0.0)
            self.assertLessEqual(generated, 1.0)
            self.assertAlmostEqual(generated,
                max(0.0, min(1.0, (float(closed["adx"]) - 20.0) / 30.0)))
            self.assertEqual(event["outputs"]["confirmation"], generated > .7)
            self.assertEqual(event["reason"], "recipe_evaluated")
            self.assertEqual(event["decision_at"], event["observed_at"])
            outputs.append(event["outputs"]["confirmation"])
        # A fallback1 would pass these genuine low-score opportunities.
        self.assertIn(False, outputs)
        self.assertEqual(payload.parameters, parameters)
        self.assertEqual(execution_contract_metadata(payload), physical)

    def test_source_confidence_spoof_cannot_override_genuine_trend_computation(self):
        data = source_frame()
        spoofed = data.copy()
        spoofed["signal_confidence"] = 1.0
        payload = request("trend_v1", "trend")
        attach(payload, data)
        clean = execute(payload, data)
        forged = execute(payload, spoofed)
        clean_events, forged_events = entry_events(clean), entry_events(forged)
        self.assertGreater(len(clean_events), 0)
        self.assertEqual([(e["observed_at"], e["outputs"]) for e in forged_events],
            [(e["observed_at"], e["outputs"]) for e in clean_events])
        self.assertTrue(any(e["outputs"]["confirmation"] is False for e in forged_events))
        self.assertEqual(forged.trade_ledger, clean.trade_ledger)
        self.assertEqual(forged.trade_ledger_hash, clean.trade_ledger_hash)
        self.assertEqual(forged.execution_assumptions, clean.execution_assumptions)
        self.assertEqual(forged.data_quality["replay_executed_clock"],
            clean.data_quality["replay_executed_clock"])

    def test_absent_or_none_score_is_typed_dependency_for_each_method_target(self):
        for target, conservative in (("confirmation", False), ("risk_multiplier", 0.0), ("exit", False)):
            for value in (Ellipsis, None):
                with self.subTest(target=target, value=value):
                    runtime, row, stamp = direct_case(target=target, value=value)
                    event = runtime.evaluate(row, decision_id="missing-score",
                        phase="exit" if target == "exit" else "entry",
                        observed_at=stamp, decision_at=stamp, direction="BUY")
                    self.assertEqual(event["outputs"][target], conservative)
                    self.assertEqual(type(event["outputs"][target]), type(conservative))
                    self.assertEqual(event["reason"], "method_native_score_unavailable")
                    self.assertFalse(event["economic_authority"])
                    self.assertFalse(event["promotion_evidence"])

    def test_present_malformed_bool_string_or_nonfinite_score_remains_strict(self):
        for value in (True, False, ".9", "malformed", float("nan"), float("inf")):
            with self.subTest(value=value):
                runtime, row, stamp = direct_case(value=value)
                with self.assertRaisesRegex(ValueError, "DECISION_OPERATOR_INPUT_TYPE_REQUIRED"):
                    runtime.evaluate(row, decision_id="invalid-score", phase="entry",
                        observed_at=stamp, decision_at=stamp, direction="BUY")

    def test_genuine_numerical_zero_equality_and_score_above_floor_keep_their_meaning(self):
        for score, expected in ((0.0, False), (.7, False), (.75, True), (1.0, True)):
            with self.subTest(score=score):
                runtime, row, stamp = direct_case(value=score)
                event = runtime.evaluate(row, decision_id="genuine-score", phase="entry",
                    observed_at=stamp, decision_at=stamp, direction="BUY")
                self.assertEqual(event["outputs"]["confirmation"], expected)
                self.assertEqual(event["reason"], "recipe_evaluated")

    def test_other_missing_owned_observation_retains_existing_conservative_wait(self):
        payload = request()
        data = source_frame(4)
        data["signal"] = "BUY"
        data["market_regime"] = "trend_up"
        data["market_session"] = "london"
        data["volatility_regime"] = "normal_volatility"
        ast = {"op": "GREATER_THAN", "args": [
            {"op": "NUMBER", "input_key": "volume", "available_at": "2024-01-01T00:00:00Z"},
            {"op": "CONST", "type": "number", "value": 1.2}]}
        recipe = pinned_recipe(payload, "other-owned-missing",
            {"confirmation": ast}, {"volume": "method_volume_ratio"})
        runtime = InstrumentMethodLearning(learning_contract(payload, data, [recipe]), payload)
        runtime.validate_frame(data)
        row = data.iloc[1].to_dict()
        stamp = (row["time"] + pd.Timedelta(minutes=5)).isoformat()
        event = runtime.evaluate(row, decision_id="missing-volume", phase="entry",
            observed_at=stamp, decision_at=stamp, direction="BUY")
        self.assertFalse(event["outputs"]["confirmation"])
        self.assertEqual(event["reason"], "method_input_unavailable")


if __name__ == "__main__":
    unittest.main()
