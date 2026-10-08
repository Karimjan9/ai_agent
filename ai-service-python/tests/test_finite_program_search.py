import copy
import hashlib
import json
from pathlib import Path
import unittest
from unittest.mock import patch

import pandas as pd
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe

from app.services import research_program_tasks as owner


def seal(spec, arm="library_guided"):
    body = {"protocol": "sealed_finite_program_search_v1", "benchmark_key": "synthetic-explicit-finite-task",
            "spec": spec, "spec_hash": owner.canonical_hash(spec), "arm": arm}
    return {**body, "contract_hash": owner.canonical_hash(body), "contract_json": json.dumps(body)}


def specification():
    # A bounded fixture library is explicit; no market quality is asserted.
    scope = "exact-synthetic-scope"
    definition = {"scope_key": scope, "parameters": [{"name": "threshold", "type": "number"}], "result_type": "bool",
        "template": {"op": "GREATER_THAN", "args": [
            {"op": "NUMBER", "input_key": "value", "available_at": "2025-01-01T00:00:00Z"},
            {"op": "PARAM", "name": "threshold"}]}}
    key = owner.canonical_hash(definition)
    definitions = {key: definition}
    library = {"op": "CALL", "macro_key": key, "args": [{"op": "CONST", "type": "number", "value": 5}]}
    other = {"op": "LESS_THAN", "args": [{"op": "NUMBER", "input_key": "value", "available_at": "2025-01-01T00:00:00Z"},
                {"op": "CONST", "type": "number", "value": 5}]}
    expanded = owner._expand(library, definitions, scope)
    pool = [{"ast_hash": owner.canonical_hash(expanded), "expanded_ast": expanded, "library_ast": library, "description_nodes": 2},
            {"ast_hash": owner.canonical_hash(other), "expanded_ast": other, "library_ast": other, "description_nodes": 3}]
    return {"protocol": "finite_program_search_spec_v1", "scope_key": scope, "task_key": "synthetic-unseen-examples",
        "pool": sorted(pool, key=lambda item: item["ast_hash"]), "abstractions": definitions, "result_type": "bool",
        "input_vectors": [{"decision_at": "2025-01-02T00:00:00Z", "value": 10}, {"decision_at": "2025-01-02T01:00:00Z", "value": 1}],
        "expected_outputs": [True, False], "budget": {"cpu_seconds": 1, "max_expansions": 100, "max_attempts": 32},
        "blind_seed": "fixed-seed", "executor_hash": hashlib.sha256(Path(owner.__file__).read_bytes()).hexdigest(), "synthetic_fixture": True}


class FiniteProgramSearchTest(unittest.TestCase):
    def test_existing_native_task_hook_exports_search_receipt_without_changing_market_replay(self):
        prices = [2000 + i * .02 for i in range(202)]
        frame = pd.DataFrame({"time": pd.date_range("2025-01-01", periods=202, freq="h", tz="UTC"),
            "open": prices, "high": [value + .5 for value in prices], "low": [value - .5 for value in prices],
            "close": prices, "volume": 1.0})

        def wait_strategy(data, _parameters):
            data = data.copy(); data["signal"] = "WAIT"; return data

        ordinary = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", strategy="ema_rsi_v1", evaluation_mode="incremental")
        question = ordinary.model_copy(update={"policy_context": {"research_program_task": seal(specification())}})
        with patch("app.services.backtester.get_strategy", return_value=wait_strategy):
            baseline = run_simple_ema_rsi_backtest_on_dataframe(ordinary, frame)
            observed = run_simple_ema_rsi_backtest_on_dataframe(question, frame)
        self.assertEqual("bounded_finite_program_search_v1", observed.benchmark["research_program_task"]["producer_protocol"])
        self.assertEqual("complete", observed.benchmark["research_program_task"]["status"])
        self.assertEqual(baseline.total_trades, observed.total_trades)
        self.assertEqual(baseline.final_balance, observed.final_balance)
        self.assertEqual(baseline.trade_ledger_hash, observed.trade_ledger_hash)
        self.assertNotIn("research_program_task", baseline.benchmark)

    def test_actual_attempts_nodes_cpu_and_solution_are_native_diagnostic_outputs(self):
        spec = specification()
        for arm in ("library_guided", "memory_blinded"):
            result = owner.execute_task(seal(spec, arm))
            self.assertEqual("bounded_finite_program_search_v1", result["producer_protocol"])
            self.assertEqual("complete", result["status"])
            self.assertEqual("solution_found", result["termination"])
            self.assertGreaterEqual(result["search_resources"]["cpu_seconds"], 0)
            self.assertEqual("process_time", result["search_resources"]["cpu_clock"])
            self.assertGreater(result["search_resources"]["cpu_clock_resolution_seconds"], 0)
            self.assertLessEqual(result["search_resources"]["cpu_seconds"], 1)
            self.assertEqual(sum(item["node_evaluations"] for item in result["attempted_programs"]), result["search_resources"]["expansions"])
            self.assertEqual(result["solution_hash"], result["attempted_programs"][-1]["ast_hash"])
            self.assertFalse(result["search_efficiency_measured"])
            self.assertFalse(result["economic_authority"])
            self.assertTrue(result["synthetic_fixture"])

    def test_labels_cannot_change_library_or_blinded_order(self):
        spec = specification(); changed = copy.deepcopy(spec); changed["expected_outputs"] = [False, True]
        for arm in ("library_guided", "memory_blinded"):
            self.assertEqual(owner.finite_search_order(spec, arm), owner.finite_search_order(changed, arm))
            first = owner.execute_task(seal(spec, arm))["attempted_programs"][0]["ast_hash"]
            second = owner.execute_task(seal(changed, arm))["attempted_programs"][0]["ast_hash"]
            self.assertEqual(first, second)

    def test_incomplete_caps_do_not_claim_a_completed_product(self):
        spec = specification(); spec["budget"]["max_expansions"] = 1
        result = owner.execute_task(seal(spec))
        self.assertEqual("incomplete", result["status"])
        self.assertTrue(result["search_resources"]["partial_program_nodes_unknown"])
        self.assertFalse(result["search_efficiency_measured"])

    def test_scope_semantics_hash_asof_types_and_external_code_are_refused(self):
        for poison in ("scope", "semantics", "future", "goal", "hash", "source", "recursion", "budget"):
            with self.subTest(poison=poison):
                spec = specification()
                if poison == "scope": spec["scope_key"] = "other"
                if poison == "semantics": spec["pool"][0]["expanded_ast"] = {"op": "CONST", "type": "bool", "value": True}
                if poison == "future": spec["input_vectors"][0]["decision_at"] = "2024-01-01T00:00:00Z"
                if poison == "goal": spec["expected_outputs"] = [1, 0]
                if poison == "source": spec["executor_hash"] = "0" * 64
                if poison == "recursion": spec["pool"][0]["library_ast"] = {"op": "__import__", "source": "external"}
                if poison == "budget": spec["budget"]["max_attempts"] = 100
                task = seal(spec)
                if poison == "hash": task["contract_hash"] = "0" * 64
                with self.assertRaises(ValueError): owner.execute_task(task)


if __name__ == "__main__":
    unittest.main()
