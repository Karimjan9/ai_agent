"""Source-backed ATR% semantics; synthetic correctness, not market authority."""

import json
from pathlib import Path
import unittest

import pandas as pd

from app.services.instrument_method_features import ClosedInstrumentMethodFeatures, PROTOCOL
from app.services.research_program_tasks import BoundedDecisionProgram, canonical_hash
from app.services.specialist_council import seal_contract


METHOD = json.loads((Path(__file__).resolve().parents[2] / "backend-laravel/resources/research/"
    "instrument-methods/closed-atr-percent/method.json").read_text(encoding="utf-8"))


def frame(count=30, close=2000.0, true_range=2.0):
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=count, freq="5min"),
        "open": close, "high": close + true_range / 2, "low": close - true_range / 2, "close": close})


def values(owner, data, index, direction="BUY"):
    row = data.iloc[index].to_dict()
    observed = (row["time"] + pd.Timedelta(minutes=5)).isoformat()
    return owner.inputs(row, direction=direction, observed_at=observed)


def operator():
    implementation = METHOD["implementation"]
    return BoundedDecisionProgram(seal_contract({"protocol": "typed_operator_decision_v1",
        "source_task_key": "method:synthetic-atr-percent", "scope_key": "closed-atr-percent-software",
        "source_context": {"method_feature_owner": PROTOCOL}, "target": "confirmation",
        "ast": implementation["ast"], "ast_hash": canonical_hash(implementation["ast"]), "abstractions": {},
        "input_bindings": implementation["input_bindings"], "budget": implementation["budget"]}),
        allow_method_features=True)


def allowed(programme, inputs):
    stamp = "2025-01-07T00:00:00Z"
    return programme.evaluate(inputs, decision_at=stamp, observed_at=stamp)


class ClosedAtrPercentMethodTest(unittest.TestCase):
    def test_fourteen_completed_true_ranges_seed_and_gap_inclusive_formula(self):
        data = frame(count=16)
        # A real close gap belongs to the current closed true range, not just
        # its high-minus-low. Other feature formulae remain the same owners.
        data.loc[14:, ["open", "high", "low", "close"]] += 10.0
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(values(owner, data, 12)["method_atr_percent_available"])
        self.assertNotIn("method_atr_percent", values(owner, data, 12))
        self.assertAlmostEqual(values(owner, data, 13)["method_atr_percent"], .1)
        # Current gap TR=max(2,11,9)=11; preceding thirteen TR values are 2.
        expected = 100 * (13 * 2.0 + 11.0) / 14 / 2010.0
        self.assertAlmostEqual(values(owner, data, 14)["method_atr_percent"], expected, places=13)
        self.assertTrue(values(owner, data, 15)["method_atr_percent_available"])

    def test_price_scale_invariance_and_identical_directional_decisions(self):
        data = frame(true_range=5.0)
        scaled = data.copy()
        scaled.loc[:, ["open", "high", "low", "close"]] *= 10.0
        left, right = ClosedInstrumentMethodFeatures(data), ClosedInstrumentMethodFeatures(scaled)
        for index in (13, 20, 29):
            for direction in ("BUY", "SELL"):
                a, b = values(left, data, index, direction), values(right, scaled, index, direction)
                self.assertAlmostEqual(a["method_atr_percent"], .25, places=13)
                self.assertAlmostEqual(a["method_atr_percent"], b["method_atr_percent"], places=13)
                self.assertFalse(allowed(operator(), a))
                self.assertFalse(allowed(operator(), b))

    def test_source_rule_admits_equality_and_rejects_strictly_greater_without_a_direction(self):
        programme = operator()
        for amount, answer in [(0.099999, True), (0.1, True), (0.100001, False)]:
            self.assertIs(allowed(programme, {"method_atr_percent_available": True, "method_atr_percent": amount}), answer)
        # Missing ratio short-circuits through genuine availability; no zero is
        # fabricated solely to satisfy the number leaf or its comparison.
        self.assertFalse(allowed(programme, {"method_atr_percent_available": False}))
        data = frame(true_range=2.0)
        for direction in ("BUY", "SELL", "WAIT"):
            self.assertTrue(allowed(operator(), values(ClosedInstrumentMethodFeatures(data), data, 13, direction)))
        self.assertEqual(programme.receipt()["target"], "confirmation")
        self.assertFalse(programme.receipt()["promotion_evidence"])

    def test_zero_atr_and_nonpositive_close_are_unavailable_not_fake_ratios(self):
        for data in (frame(true_range=0.0), frame(close=0.0), frame(close=-10.0)):
            with self.subTest(close=data.iloc[-1]["close"]):
                result = values(ClosedInstrumentMethodFeatures(data), data, 20)
                self.assertFalse(result["method_atr_percent_available"])
                self.assertNotIn("method_atr_percent", result)
                self.assertFalse(allowed(operator(), result))
        for invalid in (float("nan"), float("inf"), float("-inf")):
            data = frame()
            data.loc[20, "close"] = invalid
            with self.assertRaisesRegex(ValueError, "OHLC_INVALID"):
                ClosedInstrumentMethodFeatures(data)

    def test_future_suffix_and_supplied_method_columns_cannot_rewrite_original_prefix(self):
        data = frame(count=40)
        data["method_atr_percent"] = 0.0
        data["method_atr_percent_available"] = True
        prefix, future = data.iloc[:25].copy(), data.copy()
        future.loc[25:, ["open", "high", "low", "close"]] *= 30.0
        left, right = ClosedInstrumentMethodFeatures(prefix), ClosedInstrumentMethodFeatures(future)
        for index in range(25):
            a, b = values(left, prefix, index), values(right, future, index)
            self.assertEqual(a["method_atr_percent_available"], b["method_atr_percent_available"])
            self.assertEqual(a.get("method_atr_percent"), b.get("method_atr_percent"))
            if index >= 13:
                self.assertAlmostEqual(a["method_atr_percent"], .1, places=13)
        row = data.iloc[20].to_dict()
        with self.assertRaisesRegex(ValueError, "ORIGINAL_CLOSED_ROW"):
            ClosedInstrumentMethodFeatures(data).inputs(row, direction="BUY", observed_at=row["time"].isoformat())


if __name__ == "__main__":
    unittest.main()
