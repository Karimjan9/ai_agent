"""Bounded pure research DSL execution; never strategy, trading or authority code.

This intentionally supports only point-in-time boolean/numeric primitives.
Temporal SEQUENCE/WITHIN semantics are NOT guessed. It measures interpretation,
not synthesis/search efficiency. No Python source, eval, imports or LLM calls
are accepted from a task.
"""

import hashlib
import json
import math
import time
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

PROTOCOL = "bounded_typed_program_execution_v1"
MAX_NODES = 48
MAX_VECTORS = 128
MAX_CPU_SECONDS = 1.0


def canonical_hash(value: object) -> str:
    # Foundry uses PHP JSON_PRESERVE_ZERO_FRACTION with ASCII escaping.
    # Like execution_contract._canonical_json, normalize exponent mantissas
    # and exponent zero padding; PHP additionally keeps 1e16 in decimal form.
    encoded = _foundry_json(value)
    return hashlib.sha256(encoded.encode()).hexdigest()


def _foundry_json(value: object) -> str:
    if isinstance(value, dict):
        return "{" + ",".join(json.dumps(str(key), ensure_ascii=True) + ":" + _foundry_json(value[key]) for key in sorted(value)) + "}"
    if isinstance(value, list):
        return "[" + ",".join(_foundry_json(item) for item in value) + "]"
    rendered = json.dumps(value, separators=(",", ":"), ensure_ascii=True, allow_nan=False)
    if isinstance(value, float) and "e" in rendered.lower():
        mantissa, exponent = rendered.lower().split("e", 1)
        power = int(exponent)
        if -4 <= power < 17:
            decimal = format(Decimal(rendered), "f")
            return decimal if "." in decimal else decimal + ".0"
        if "." not in mantissa: mantissa += ".0"
        return f"{mantissa}e{power:+d}"
    return rendered


def _utc(value: object) -> datetime:
    if not isinstance(value, str) or not value.endswith(("Z", "+00:00")):
        raise ValueError("RESEARCH_TASK_EXPLICIT_UTC_REQUIRED")
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.utcoffset().total_seconds() != 0:
        raise ValueError("RESEARCH_TASK_EXPLICIT_UTC_REQUIRED")
    return parsed


def _number(value: object) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)


def _same_ast_copy(left: object, right: object, depth: int = 0) -> bool:
    if depth > 24: return False
    if isinstance(left, bool) or isinstance(right, bool):
        return type(left) is type(right) and left == right
    if isinstance(left, (int, float)) and isinstance(right, (int, float)):
        return left == right  # Exact numeric equality; never cast large ints to float.
    if type(left) is not type(right): return False
    if isinstance(left, dict):
        return left.keys() == right.keys() and all(_same_ast_copy(left[key], right[key], depth + 1) for key in left)
    if isinstance(left, list):
        return len(left) == len(right) and all(_same_ast_copy(a, b, depth + 1) for a, b in zip(left, right))
    return left == right


def _expand(node: object, definitions: dict, scope: str, depth: int = 0) -> dict:
    if not isinstance(node, dict) or depth > 12:
        raise ValueError("RESEARCH_TASK_AST_DEPTH_OR_TYPE_INVALID")
    op = node.get("op")
    if op == "PARAM":
        raise ValueError("RESEARCH_TASK_UNBOUND_PARAMETER")
    args = node.get("args", [])
    if not isinstance(args, list) or len(args) > MAX_NODES:
        raise ValueError("RESEARCH_TASK_ARGUMENT_BUDGET_INVALID")
    if op == "CALL":
        key = node.get("macro_key")
        definition = definitions.get(key)
        if not isinstance(definition, dict) or canonical_hash(definition) != key or definition.get("scope_key") != scope:
            raise ValueError("RESEARCH_TASK_MACRO_CONTENT_OR_SCOPE_INVALID")
        parameters = definition.get("parameters", [])
        if not isinstance(parameters, list) or len(parameters) > 3 or len(parameters) != len(args):
            raise ValueError("RESEARCH_TASK_MACRO_ARITY_INVALID")
        replacements = {}
        for parameter, arg in zip(parameters, args):
            expanded = _expand(arg, definitions, scope, depth + 1)
            if _infer(expanded)[0] != parameter.get("type"):
                raise ValueError("RESEARCH_TASK_MACRO_ARGUMENT_TYPE_INVALID")
            replacements[parameter["name"]] = expanded

        def substitute(template: object, level: int = 0) -> dict:
            if not isinstance(template, dict) or level > 12 or template.get("op") == "CALL":
                raise ValueError("RESEARCH_TASK_RECURSIVE_MACRO_FORBIDDEN")
            if template.get("op") == "PARAM":
                if template.get("name") not in replacements:
                    raise ValueError("RESEARCH_TASK_UNBOUND_PARAMETER")
                return replacements[template["name"]]
            result = dict(template)
            if "args" in result:
                result["args"] = [substitute(arg, level + 1) for arg in result["args"]]
            return result

        result = substitute(definition.get("template"))
        if _infer(result)[0] != definition.get("result_type"):
            raise ValueError("RESEARCH_TASK_MACRO_RESULT_TYPE_INVALID")
        return result
    result = dict(node)
    if "args" in node:
        result["args"] = [_expand(arg, definitions, scope, depth + 1) for arg in args]
    _infer(result)
    return result


def _infer(node: dict) -> tuple[str, int]:
    op = node.get("op")
    leaves = {"PRICE_CLOSE": "price", "ATR": "atr", "NUMBER": "number", "BOOL": "bool", "DURATION": "duration"}
    if op in leaves:
        _utc(node.get("available_at"))
        return leaves[op], 1
    if op == "CONST":
        kind = node.get("type", "number")
        value = node.get("value")
        if (kind == "bool" and isinstance(value, bool)) or (kind in {"number", "price", "atr", "duration"} and _number(value)):
            return kind, 1
        raise ValueError("RESEARCH_TASK_CONSTANT_TYPE_INVALID")
    children = [_infer(arg) for arg in node.get("args", [])]
    types = [kind for kind, _ in children]
    count = 1 + sum(size for _, size in children)
    if count > MAX_NODES:
        raise ValueError("RESEARCH_TASK_EXPANDED_NODE_BUDGET_EXCEEDED")
    if op in {"GREATER_THAN", "LESS_THAN"} and len(types) == 2 and all(kind in {"number", "price", "atr"} for kind in types):
        return "bool", count
    if op in {"AND", "OR", "CONFIRMED_BY"} and len(types) >= 2 and all(kind == "bool" for kind in types) and (op != "CONFIRMED_BY" or len(types) == 2):
        return "bool", count
    if op == "NOT" and types == ["bool"]:
        return "bool", count
    raise ValueError("RESEARCH_TASK_UNSUPPORTED_OPERATOR_OR_TYPE")


def execute_task(task: dict) -> dict:
    if not isinstance(task, dict) or task.get("protocol") != "sealed_research_program_task_v1":
        raise ValueError("RESEARCH_TASK_PROTOCOL_REQUIRED")
    vectors = task.get("input_vectors")
    expected = task.get("expected_outputs")
    definitions = task.get("abstractions", {})
    if definitions == []: definitions = {}  # PHP's empty associative map on the wire.
    budget = task.get("search_budget", {})
    cpu_limit = budget.get("cpu_seconds")
    node_limit = budget.get("max_expansions")
    if not isinstance(vectors, list) or not 1 <= len(vectors) <= MAX_VECTORS or not isinstance(expected, list) or len(expected) != len(vectors):
        raise ValueError("RESEARCH_TASK_VECTOR_BUDGET_INVALID")
    if not isinstance(definitions, dict) or len(definitions) > 8:
        raise ValueError("RESEARCH_TASK_LIBRARY_BUDGET_INVALID")
    if not _number(cpu_limit) or not 0 < cpu_limit <= MAX_CPU_SECONDS or not isinstance(node_limit, int) or isinstance(node_limit, bool) or not 1 <= node_limit <= MAX_NODES * MAX_VECTORS:
        raise ValueError("RESEARCH_TASK_COMPUTE_BUDGET_INVALID")
    started = time.process_time()
    source_ast = task.get("ast")
    if "ast_json" in task:
        encoded = task["ast_json"]
        if not isinstance(encoded, str) or len(encoded) > 32768:
            raise ValueError("RESEARCH_TASK_AST_JSON_COPY_INVALID")
        try:
            preserved = json.loads(encoded)
        except (ValueError, RecursionError) as error:
            raise ValueError("RESEARCH_TASK_AST_JSON_COPY_INVALID") from error
        if not isinstance(preserved, dict) or not _same_ast_copy(source_ast, preserved):
            raise ValueError("RESEARCH_TASK_AST_JSON_COPY_MISMATCH")
        source_ast = preserved
    expanded = _expand(source_ast, definitions, task.get("scope_key", ""))
    result_type, _ = _infer(expanded)
    if canonical_hash(expanded) != task.get("ast_hash"):
        raise ValueError("RESEARCH_TASK_EXPANDED_HASH_MISMATCH")
    visits = 0

    def evaluate(node: dict, vector: dict, decision: datetime) -> object:
        nonlocal visits
        visits += 1
        if visits > node_limit or time.process_time() - started > cpu_limit:
            raise ValueError("RESEARCH_TASK_COMPUTE_BUDGET_EXCEEDED")
        op = node["op"]
        if op == "CONST":
            return node["value"]
        if op in {"PRICE_CLOSE", "ATR", "NUMBER", "BOOL", "DURATION"}:
            if _utc(node["available_at"]) > decision:
                raise ValueError("RESEARCH_TASK_FUTURE_INPUT_FORBIDDEN")
            value = vector.get(node.get("input_key", op.lower()))
            if (op == "BOOL" and not isinstance(value, bool)) or (op != "BOOL" and not _number(value)):
                raise ValueError("RESEARCH_TASK_INPUT_TYPE_REQUIRED")
            return value
        values = [evaluate(arg, vector, decision) for arg in node.get("args", [])]
        if op == "GREATER_THAN": return values[0] > values[1]
        if op == "LESS_THAN": return values[0] < values[1]
        if op in {"AND", "CONFIRMED_BY"}: return all(values)
        if op == "OR": return any(values)
        if op == "NOT": return not values[0]
        raise ValueError("RESEARCH_TASK_UNSUPPORTED_OPERATOR_OR_TYPE")

    outputs = []
    for vector in vectors:
        if not isinstance(vector, dict): raise ValueError("RESEARCH_TASK_INPUT_TYPE_REQUIRED")
        decision = _utc(vector.get("decision_at"))
        if decision >= datetime(2026, 1, 1, tzinfo=timezone.utc): raise ValueError("RESEARCH_TASK_PRE2026_ASOF_REQUIRED")
        outputs.append(evaluate(expanded, vector, decision))
    elapsed = time.process_time() - started
    if elapsed > cpu_limit: raise ValueError("RESEARCH_TASK_COMPUTE_BUDGET_EXCEEDED")
    return {"producer_protocol": PROTOCOL, "task_key": task.get("task_key"), "ast_hash": task["ast_hash"],
            "executor_hash": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(), "result_type": result_type,
            "outputs": outputs, "goal_matched": outputs == expected,
            "search_resources": {"timing_scope": "bounded_program_interpretation", "cpu_seconds": elapsed,
                                 "expansions": visits, "search_efficiency_measured": False},
            "research_only": True, "promotion_evidence": False}
