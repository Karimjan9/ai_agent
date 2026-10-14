"""All 26 curator lessons execute their actual finite programs, not a mock.

These are synthetic operator correctness exercises. They are neither market
backtests nor evidence that the caller-provided exercise values can enter replay.
Real feature ownership, prefix invariance and physical use are tested separately.
"""

import json
from pathlib import Path
import unittest

from app.services.research_program_tasks import BoundedDecisionProgram, canonical_hash
from app.services.specialist_council import seal_contract


RESOURCES = Path(__file__).resolve().parents[2] / "backend-laravel" / "resources" / "research"


def declarations():
    methods = {}
    for filename in ("curated-v1.json", "curated-v2.json"):
        for method in json.loads((RESOURCES / "instrument-methods" / filename).read_text(encoding="utf-8"))["methods"]:
            methods[f'{method["method_key"]}@{method["revision"]}'] = method
    for filename in (RESOURCES / "instrument-methods").glob("*/method.json"):
        method = json.loads(filename.read_text(encoding="utf-8"))
        methods[f'{method["method_key"]}@{method["revision"]}'] = method
    return methods


def operator(method):
    implementation = method["implementation"]
    if implementation["kind"] != "typed_ast":
        raise AssertionError("Lesson exercises must execute a typed primary, not a construction declaration")
    body = {"protocol": "typed_operator_decision_v1", "source_task_key": "curator-operating-exercise",
        "scope_key": "synthetic-unit-correctness-not-market", "target": implementation["target"],
        "source_context": {"method_feature_owner": "closed_instrument_method_features_v1"},
        "ast": implementation["ast"], "ast_hash": canonical_hash(implementation["ast"]),
        "abstractions": {}, "input_bindings": implementation["input_bindings"],
        "budget": implementation["budget"]}
    return BoundedDecisionProgram(seal_contract(body), allow_method_features=True)


def lessons():
    result = []
    for filename in (RESOURCES / "instrument-operating-skills").glob("*-v1.json"):
        result.extend(json.loads(filename.read_text(encoding="utf-8"))["lessons"])
    return result


class InstrumentOperatingSkillExerciseTest(unittest.TestCase):
    def test_all_twenty_six_lessons_have_actual_executable_method_and_reviewed_instructions(self):
        declared = declarations()
        curriculum = lessons()
        self.assertEqual(len(curriculum), 26)
        self.assertEqual(len({lesson["instrument_key"] for lesson in curriculum}), 26)
        self.assertEqual({lesson["group"] for lesson in curriculum}, {"tactic", "market_lens", "risk", "execution", "model"})
        for lesson in curriculum:
            with self.subTest(instrument=lesson["instrument_key"]):
                method = declared[lesson["primary_method_ref"]]
                self.assertEqual(method["instrument_key"], lesson["instrument_key"])
                self.assertGreaterEqual(len(lesson["operating_steps"]), 3)
                self.assertGreaterEqual(len(lesson["exercises"]), 2)
                for ref in lesson["additional_method_refs"]:
                    self.assertEqual(declared[ref]["instrument_key"], lesson["instrument_key"])
                for exercise in lesson["exercises"]:
                    self.assertEqual(exercise["kind"], "synthetic_operator_exercise")
                    program = operator(method)
                    actual = program.evaluate(exercise["inputs"],
                        decision_at="2025-12-01T12:00:00Z", observed_at="2025-12-01T11:55:00Z")
                    self.assertEqual(type(actual), type(exercise["expected"]), exercise["name"])
                    self.assertEqual(actual, exercise["expected"], exercise["name"])

    def test_lesson_programs_reject_future_observations_and_unowned_method_input_capability(self):
        declared = declarations()
        for lesson in lessons():
            method = declared[lesson["primary_method_ref"]]
            with self.subTest(instrument=lesson["instrument_key"]):
                program = operator(method)
                with self.assertRaisesRegex(ValueError, "FUTURE_OBSERVATION_FORBIDDEN"):
                    program.evaluate(lesson["exercises"][0]["inputs"],
                        decision_at="2025-12-01T12:00:00Z", observed_at="2025-12-01T12:01:00Z")
                if any(field.startswith("method_") for field in program.bindings.values()):
                    with self.assertRaisesRegex(ValueError, "METHOD_FEATURE_OWNER_REQUIRED"):
                        BoundedDecisionProgram(program.contract)


if __name__ == "__main__":
    unittest.main()
