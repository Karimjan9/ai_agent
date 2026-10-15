"""Versioned phase selection; synthetic contract/owner tests, not market evidence."""

import copy
import unittest
from unittest.mock import patch

import pandas as pd

from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.instrument_method_learning import InstrumentMethodLearning
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract
from test_instrument_method_learning import const, learning_contract, pinned_recipe, strategy
from test_specialist_council import fixtures, member


SELECTION = "phase_target_applicable_v2"


def seal_body(body, hash_key="contract_hash", json_key="contract_json"):
    body = {key: value for key, value in body.items() if key not in {hash_key, json_key}}
    return {**body, hash_key: canonical_hash(body), json_key: canonical_json(body)}


def prepared(targets, *, v2=True, order=None, bindings=None):
    request, frame = fixtures([member("hour", holding=3)])
    recipes = [pinned_recipe(request, "phase-recipe-" + str(index), value,
        input_bindings=(bindings or {}).get(index)) for index, value in enumerate(targets)]
    contract = learning_contract(request, frame, recipes)
    contract["policy"]["rules"][0]["preferred_recipes"] = [recipes[index]["recipe_hash"]
        for index in (order if order is not None else range(len(recipes)))]
    contract["policy"] = seal_body(contract["policy"], "policy_hash", "policy_json")
    if v2:
        contract["selection_protocol"] = SELECTION
    return request, frame, recipes, seal_body(contract)


def engine(request, contract):
    return InstrumentMethodLearning(contract, request)


def evaluate(owner, phase, *, session="london"):
    return owner.evaluate({"close": 100., "market_regime": "trend_up", "market_session": session,
        "volatility_regime": "normal_volatility"}, decision_id="synthetic-" + phase,
        phase=phase, decision_at="2025-01-06T12:00:00Z", observed_at="2025-01-06T12:00:00Z", direction="BUY")


class InstrumentMethodPhaseSelectionTest(unittest.TestCase):
    def test_future_v2_selects_real_entry_and_exit_targets_in_either_recipe_order(self):
        for order in ([0, 1], [1, 0]):
            request, _, recipes, contract = prepared([{"confirmation": const(True)}, {"exit": const(True)}], order=order)
            owner = engine(request, contract)
            entry, exit_event = evaluate(owner, "entry"), evaluate(owner, "exit")
            self.assertEqual(entry["recipe_hash"], recipes[0]["recipe_hash"])
            self.assertEqual(entry["outputs"], {"confirmation": True})
            self.assertEqual(exit_event["recipe_hash"], recipes[1]["recipe_hash"])
            self.assertEqual(exit_event["outputs"], {"exit": True})
            self.assertTrue(entry["used"] and exit_event["used"])
            self.assertFalse(exit_event["economic_authority"])

    def test_old_contract_absence_preserves_first_recipe_and_target_unreached_exactly(self):
        request, _, recipes, contract = prepared([{"confirmation": const(True)}, {"exit": const(True)}], v2=False)
        owner = engine(request, contract)
        first = evaluate(owner, "exit")
        self.assertEqual(first["recipe_hash"], recipes[0]["recipe_hash"])
        self.assertEqual(first["reason"], "recipe_target_unreached")
        self.assertFalse(first["used"])
        self.assertEqual(first["outputs"], [])
        explicit = seal_body({**contract, "selection_protocol": SELECTION})
        self.assertNotEqual(contract["contract_hash"], explicit["contract_hash"])
        old_entry = evaluate(engine(request, contract), "entry")
        new_entry = evaluate(engine(request, explicit), "entry")
        self.assertEqual(old_entry["outputs"], new_entry["outputs"])
        self.assertEqual(old_entry["policy_hash"], new_entry["policy_hash"])
        self.assertNotEqual(old_entry["contract_hash"], new_entry["contract_hash"])

    def test_pure_entry_order_and_context_mismatch_are_not_bypassed(self):
        for v2 in (False, True):
            request, _, recipes, contract = prepared([{"confirmation": const(False)}, {"confirmation": const(True)}], v2=v2)
            event = evaluate(engine(request, contract), "entry")
            self.assertEqual(event["recipe_hash"], recipes[0]["recipe_hash"])
            self.assertFalse(event["outputs"]["confirmation"])
            outside = evaluate(engine(request, contract), "entry", session="asia")
            self.assertIsNone(outside["recipe_hash"])
            self.assertFalse(outside["used"])
            self.assertEqual(outside["reason"], "policy_default_baseline")

    def test_first_applicable_missing_observation_does_not_fall_through_to_permissive_recipe(self):
        ast = {"op": "BOOL", "input_key": "available", "available_at": "2024-01-01T00:00:00Z"}
        request, frame, recipes, contract = prepared([{"confirmation": ast}, {"confirmation": const(True)}, {"exit": const(True)}],
            bindings={0: {"available": "method_volume_available"}})
        owner = engine(request, contract)
        owner.bind_feature_frame(strategy(frame, {}))
        # The closed producer has fewer than the twenty prior observations
        # required for observed volume at this point. A later permissive recipe
        # is not a replacement for this first applicable observation dependency.
        row = strategy(frame, {}).iloc[10].to_dict()
        stamp = (row["time"] + pd.Timedelta(hours=1)).isoformat()
        event = owner.evaluate(row, decision_id="synthetic-unavailable", phase="entry", decision_at=stamp,
            observed_at=stamp, direction="BUY")
        self.assertEqual(event["recipe_hash"], recipes[0]["recipe_hash"])
        self.assertFalse(event["outputs"]["confirmation"])
        self.assertEqual(event["reason"], "method_observed_volume_unavailable")

    def test_unknown_present_null_and_unsealed_selection_field_fail_closed(self):
        request, _, _, contract = prepared([{"confirmation": const(True)}, {"exit": const(True)}], v2=False)
        for invalid in (None, True, "phase_target_applicable_v3", {}, [SELECTION]):
            changed = seal_body({**contract, "selection_protocol": invalid})
            with self.assertRaisesRegex(ValueError, "SELECTION_PROTOCOL_INVALID"):
                engine(request, changed)
        changed = {**contract, "selection_protocol": SELECTION}
        with self.assertRaisesRegex(ValueError, "JSON_COPY_MISMATCH|HASH_MISMATCH"):
            engine(request, changed)

    def test_v2_never_skips_parameter_adapter_recipe(self):
        request, frame, recipes, _ = prepared([{"confirmation": const(True)}, {"exit": const(True)}])
        raw = {key: value for key, value in recipes[0].items() if key not in {"runtime_hash", "runtime_json"}}
        revision = copy.deepcopy(raw["recipe_revision"])
        method = copy.deepcopy(next(iter(revision["method_contracts"].values())))
        method["implementation"] = {"kind": "parameter_adapter", "strategy_family": "ema_rsi", "parameters": {"ema_fast": 9}}
        identity = canonical_hash(method)
        revision["method_contracts"], revision["method_hashes"] = {identity: method}, [identity]
        raw.update(recipe_hash=canonical_hash(revision), recipe_revision=revision, recipe_revision_json=canonical_json(revision),
            operators=[], parameter_adapters=[{"method_hash": identity, "instrument_key": method["instrument_key"],
                "strategy_family": "ema_rsi", "parameters": {"ema_fast": 9}}])
        recipes[0] = seal_body(raw, "runtime_hash", "runtime_json")
        contract = learning_contract(request, frame, recipes)
        engine(request, contract)  # Existing legacy construction-reference behavior survives.
        with self.assertRaisesRegex(ValueError, "PHASE_SELECTION_TYPED_RECIPES_REQUIRED"):
            engine(request, seal_body({**contract, "selection_protocol": SELECTION}))

    def test_native_member_uses_the_same_versioned_engine_for_both_owned_phases(self):
        request, frame, _, contract = prepared([{"confirmation": const(True)}, {"exit": const(True)}])
        request.emit_decision_trace = True
        declaration = copy.deepcopy(request.specialist_council_contract["members"][0])
        declaration["method_learning"] = contract
        request.specialist_council_contract = seal_contract({**request.specialist_council_contract, "members": [declaration]})
        with patch("app.services.backtester.get_strategy", return_value=strategy):
            result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
        events = [event for row in result.decision_trace for event in row.get("method_learning_events", [])]
        self.assertTrue(any(event["phase"] == "entry" and event["outputs"] == {"confirmation": True} for event in events))
        self.assertTrue(any(event["phase"] == "exit" and event["outputs"] == {"exit": True} for event in events))
        self.assertTrue(all(event["contract_hash"] == contract["contract_hash"] for event in events))
        self.assertFalse(result.specialist_council_receipt["members"][0]["method_learning_receipt"]["economic_authority"])


if __name__ == "__main__":
    unittest.main()
