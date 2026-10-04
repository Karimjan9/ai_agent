import copy
import hashlib
import json
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.research_program_tasks import canonical_hash, execute_task


def ast(threshold=1):
    return {"op": "AND", "args": [
        {"op": "GREATER_THAN", "args": [{"op": "PRICE_CLOSE", "available_at": "2025-01-01T10:00:00Z"}, {"op": "CONST", "type": "number", "value": threshold}]},
        {"op": "NOT", "args": [{"op": "BOOL", "available_at": "2025-01-01T10:00:00Z"}]},
    ]}


def task():
    return {"protocol": "sealed_research_program_task_v1", "task_key": "actual-question", "program_key": "example-research-only",
            "ast": ast(), "ast_hash": canonical_hash(ast()), "scope_key": "scope", "abstractions": {},
            "input_vectors": [{"decision_at": "2025-01-01T11:00:00Z", "price_close": 2000, "bool": False}],
            "expected_outputs": [True], "search_budget": {"cpu_seconds": 0.25, "max_expansions": 100}}


class ResearchProgramTasksTest(unittest.TestCase):
    def test_foundry_php_scientific_exponents_decimal_band_and_ascii_strings(self):
        values = [1e-7, 1e-6, 1e-5, 0.0001, 1e16, 1e17, 1e20, 1e21, 1e15, 0.0, -0.0, 1.234567890123456e16, 1.234e-7]
        # Independently verified PHP json_encode(..., JSON_PRESERVE_ZERO_FRACTION).
        php_json = '[1.0e-7,1.0e-6,1.0e-5,0.0001,10000000000000000.0,1.0e+17,1.0e+20,1.0e+21,1000000000000000.0,0.0,-0.0,12345678901234560.0,1.234e-7]'
        self.assertEqual(hashlib.sha256(php_json.encode()).hexdigest(), canonical_hash(values))
        self.assertEqual(hashlib.sha256('"\\u03bb/\\u0442"'.encode()).hexdigest(), canonical_hash('λ/т'))
        for threshold in (1e-7, 1e16):
            question = task(); question['ast'] = ast(threshold); question['ast_hash'] = canonical_hash(question['ast'])
            question['input_vectors'][0]['price_close'] = threshold * 2
            self.assertEqual([True], execute_task(question)['outputs'])

    def test_preserved_ast_survives_whole_float_transport_and_rejects_bool_or_malformed_copies(self):
        question = task(); question['ast'] = ast(1e16); question['ast_hash'] = canonical_hash(question['ast'])
        question['ast_json'] = json.dumps(question['ast'])
        question['ast']['args'][0]['args'][1]['value'] = 10000000000000000  # Default PHP/Guzzle JSON drops .0.
        question['input_vectors'][0]['price_close'] = 2e16
        self.assertEqual([True], execute_task(question)['outputs'])
        for case in ('number', 'boolean', 'malformed', 'wrong_hash'):
            wrong = copy.deepcopy(question)
            if case == 'number': wrong['ast']['args'][0]['args'][1]['value'] = 10000000000000001
            if case == 'boolean': wrong['ast']['args'][0]['args'][1]['value'] = True
            if case == 'malformed': wrong['ast_json'] = '{invalid-json'
            if case == 'wrong_hash': wrong['ast_hash'] = 'different-sealed-hash'
            with self.assertRaises(ValueError): execute_task(wrong)
        boolean = task(); boolean['ast'] = {'op': 'CONST', 'type': 'bool', 'value': True}
        boolean['ast_json'] = json.dumps(boolean['ast']); boolean['ast_hash'] = canonical_hash(boolean['ast'])
        boolean['ast']['value'] = 1
        with self.assertRaisesRegex(ValueError, 'AST_JSON_COPY_MISMATCH'): execute_task(boolean)

    def test_outputs_are_real_semantics_not_caller_solved_or_expected_flags(self):
        question = task()
        question["expected_outputs"] = [False]
        question["solved"] = True
        result = execute_task(question)
        self.assertEqual([True], result["outputs"])
        self.assertFalse(result["goal_matched"])
        self.assertEqual(6, result["search_resources"]["expansions"])
        self.assertGreaterEqual(result["search_resources"]["cpu_seconds"], 0)
        self.assertEqual("bounded_program_interpretation", result["search_resources"]["timing_scope"])
        self.assertFalse(result["search_resources"]["search_efficiency_measured"])
        self.assertFalse(result["promotion_evidence"])

    def test_parameterized_macro_exact_expansion_and_content_type_scope_guards(self):
        question = task()
        template = ast()
        template["args"][0]["args"][1] = {"op": "PARAM", "name": "p0", "type": "number"}
        definition = {"protocol": "parameterized_ast_abstraction_v1", "scope_key": "scope", "template": template,
                      "parameters": [{"name": "p0", "type": "number"}], "result_type": "bool"}
        key = canonical_hash(definition)
        question["abstractions"] = {key: definition}
        question["ast"] = {"op": "CALL", "macro_key": key, "args": [{"op": "CONST", "type": "number", "value": 1}]}
        self.assertEqual([True], execute_task(question)["outputs"])
        for case in ("scope", "content", "argument"):
            wrong = copy.deepcopy(question)
            if case == "scope": wrong["scope_key"] = "other"
            if case == "content": wrong["abstractions"][key]["result_type"] = "number"
            if case == "argument": wrong["ast"]["args"] = [{"op": "CONST", "type": "bool", "value": True}]
            with self.assertRaises(ValueError): execute_task(wrong)

    def test_future_2026_unsupported_code_and_nonfinite_values_fail_closed(self):
        for case in ("future", "paper", "code", "temporal", "nan", "missing_bool"):
            question = task()
            if case == "future": question["input_vectors"][0]["decision_at"] = "2024-01-01T11:00:00Z"
            if case == "paper": question["input_vectors"][0]["decision_at"] = "2026-01-01T00:00:00Z"
            if case == "code": question["ast"] = {"op": "eval", "value": "dangerous_code()"}
            if case == "temporal": question["ast"]["op"] = "SEQUENCE"
            if case == "nan": question["input_vectors"][0]["price_close"] = float("nan")
            if case == "missing_bool": del question["input_vectors"][0]["bool"]
            with self.assertRaises(ValueError): execute_task(question)

    def test_actual_compute_and_vector_budgets_are_enforced(self):
        question = task(); question["search_budget"]["max_expansions"] = 5
        with self.assertRaisesRegex(ValueError, "COMPUTE_BUDGET_EXCEEDED"): execute_task(question)
        question = task(); question["input_vectors"] *= 129; question["expected_outputs"] *= 129
        with self.assertRaisesRegex(ValueError, "VECTOR_BUDGET_INVALID"): execute_task(question)
        question = task(); question["ast_hash"] = "wrong"
        with self.assertRaisesRegex(ValueError, "EXPANDED_HASH_MISMATCH"): execute_task(question)

    def test_real_prepared_backtest_owner_preserves_optional_task_result_in_benchmark(self):
        closes = [2000 + i * 0.02 + (-1) ** i * 0.05 for i in range(202)]
        frame = pd.DataFrame({"time": pd.date_range("2025-01-01", periods=202, freq="h", tz="UTC"),
                              "open": closes, "high": [v + 0.5 for v in closes], "low": [v - 0.5 for v in closes], "close": closes, "volume": 1.0})

        def wait_strategy(data, _parameters):
            data = data.copy(); data["signal"] = "WAIT"; return data

        request = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", strategy="ema_rsi_v1", evaluation_mode="incremental",
                                        policy_context={"research_program_task": task()})
        with patch("app.services.backtester.get_strategy", return_value=wait_strategy):
            result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
            ordinary = run_simple_ema_rsi_backtest_on_dataframe(request.model_copy(update={"policy_context": {}}), frame)
            wrong_task = task(); wrong_task["input_vectors"][0]["decision_at"] = "2026-01-01T00:00:00Z"
            blocked = run_simple_ema_rsi_backtest_on_dataframe(request.model_copy(update={"policy_context": {"research_program_task": wrong_task}}), frame)
        self.assertEqual([True], result.benchmark["research_program_task"]["outputs"])
        self.assertEqual(ordinary.total_trades, result.total_trades)
        self.assertEqual(ordinary.final_balance, result.final_balance)
        self.assertNotIn("research_program_task", ordinary.benchmark)
        self.assertEqual("blocked_dependency", blocked.benchmark["research_program_task"]["status"])
        self.assertEqual(ordinary.total_trades, blocked.total_trades)
        self.assertEqual(ordinary.final_balance, blocked.final_balance)


if __name__ == "__main__":
    unittest.main()
