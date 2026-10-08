"""Bounded pure research DSL execution and explicit sealed decision bindings.

This intentionally supports only point-in-time boolean/numeric primitives.
Temporal SEQUENCE/WITHIN semantics are NOT guessed. It measures interpretation,
not open-ended synthesis. Finite registered-pool search has its own diagnostic
protocol and resource receipts. No Python source, eval, imports or LLM calls
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

DECISION_PROTOCOL = "typed_operator_decision_v1"
DECISION_INPUTS = {
    "close", "_management_atr", "signal_confidence", "volume",
    "volume_available", "risk_veto", "news_veto",
}


def preserved_contract_body(contract: dict, error_prefix: str, maximum_bytes: int = 1048576) -> dict:
    """Preserve PHP float spelling across transports that erase .0.

    The exact JSON copy is a transport guard, never a different executable
    declaration. Both copies must have identical structure and exact values.
    """
    body = {key: value for key, value in contract.items() if key not in {"contract_hash", "contract_json"}}
    encoded = contract.get("contract_json")
    if encoded is None:
        return body
    if not isinstance(encoded, str) or len(encoded.encode("utf-8")) > maximum_bytes:
        raise ValueError(f"{error_prefix}_JSON_COPY_INVALID")
    try:
        preserved = json.loads(encoded)
    except (ValueError, RecursionError) as error:
        raise ValueError(f"{error_prefix}_JSON_COPY_INVALID") from error
    if not isinstance(preserved, dict) or not _same_ast_copy(body, preserved):
        raise ValueError(f"{error_prefix}_JSON_COPY_MISMATCH")
    return preserved


def canonical_hash(value: object) -> str:
    # Foundry uses PHP JSON_PRESERVE_ZERO_FRACTION with ASCII escaping.
    # Like execution_contract._canonical_json, normalize exponent mantissas
    # and exponent zero padding; PHP additionally keeps 1e16 in decimal form.
    encoded = canonical_json(value)
    return hashlib.sha256(encoded.encode()).hexdigest()


def canonical_json(value: object) -> str:
    """Exact PHP-compatible numeric and object spelling for transported seals."""
    return _foundry_json(value)


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
    if not isinstance(value, (int, float)) or isinstance(value, bool):
        return False
    try:
        return math.isfinite(value)
    except OverflowError:
        return False


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
    if isinstance(task, dict) and task.get("protocol") == "sealed_finite_program_search_v1":
        return execute_finite_search(task)
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


def finite_search_order(spec: dict, arm: str) -> list[dict]:
    """Order depends on descriptions or a blinded seed, never goal labels."""
    pool = spec["pool"]
    if arm == "library_guided":
        return sorted(pool, key=lambda item: (item["description_nodes"], item["ast_hash"]))
    if arm == "memory_blinded":
        return sorted(pool, key=lambda item: hashlib.sha256((spec["blind_seed"] + "|" + item["ast_hash"]).encode()).hexdigest())
    raise ValueError("FINITE_SEARCH_ARM_INVALID")


def execute_finite_search(task: dict) -> dict:
    started = time.process_time()
    if not isinstance(task.get("contract_json"), str):
        raise ValueError("FINITE_SEARCH_PRESERVED_ORIGINAL_CONTRACT_REQUIRED")
    body = preserved_contract_body(task, "FINITE_SEARCH")
    if canonical_hash(body) != task.get("contract_hash"):
        raise ValueError("FINITE_SEARCH_CONTRACT_HASH_INVALID")
    spec = body.get("spec", {})
    if not isinstance(spec, dict) or canonical_hash(spec) != body.get("spec_hash"):
        raise ValueError("FINITE_SEARCH_SPEC_HASH_INVALID")
    budget = spec.get("budget", {})
    cpu = budget.get("cpu_seconds")
    nodes = budget.get("max_expansions")
    attempts = budget.get("max_attempts")
    pool = spec.get("pool")
    vectors = spec.get("input_vectors")
    expected = spec.get("expected_outputs")
    definitions = spec.get("abstractions", {})
    if definitions == []: definitions = {}
    if not _number(cpu) or not 0 < cpu <= 1 or not isinstance(nodes, int) or isinstance(nodes, bool) or not 1 <= nodes <= 196608 \
            or not isinstance(attempts, int) or isinstance(attempts, bool) or not 1 <= attempts <= 32:
        raise ValueError("FINITE_SEARCH_EQUAL_BUDGET_INVALID")
    if not isinstance(pool, list) or not 2 <= len(pool) <= 32 or not isinstance(vectors, list) or not 1 <= len(vectors) <= 128 \
            or not isinstance(expected, list) or len(expected) != len(vectors) or not isinstance(definitions, dict) or not 1 <= len(definitions) <= 8 \
            or not isinstance(spec.get("blind_seed"), str) or not spec["blind_seed"]:
        raise ValueError("FINITE_SEARCH_POOL_TASK_OR_LIBRARY_INVALID")
    if hashlib.sha256(Path(__file__).read_bytes()).hexdigest() != spec.get("executor_hash"):
        raise ValueError("FINITE_SEARCH_EXECUTOR_SOURCE_DRIFT")
    seen = set()
    for item in pool:
        if not isinstance(item, dict) or item.get("ast_hash") in seen:
            raise ValueError("FINITE_SEARCH_DUPLICATE_SEMANTIC_PROGRAM")
        seen.add(item.get("ast_hash"))
        expanded = _expand(item.get("library_ast"), definitions, spec.get("scope_key", ""))
        if canonical_hash(expanded) != item.get("ast_hash") or not _same_ast_copy(expanded, item.get("expanded_ast")):
            raise ValueError("FINITE_SEARCH_SAME_SEMANTIC_POOL_REQUIRED")
        kind, _ = _infer(expanded)
        if kind != spec.get("result_type") or any(not isinstance(value, bool) if kind == "bool" else not _number(value) for value in expected):
            raise ValueError("FINITE_SEARCH_GOAL_TYPE_INVALID")

        def representation_nodes(node: dict) -> int:
            return 1 + sum(representation_nodes(arg) for arg in node.get("args", []))

        if item.get("description_nodes") != representation_nodes(item["library_ast"]):
            raise ValueError("FINITE_SEARCH_DESCRIPTION_COST_INVALID")
        pending = [expanded]
        while pending:
            node = pending.pop()
            for vector in vectors:
                if not isinstance(vector, dict): raise ValueError("FINITE_SEARCH_VECTOR_INVALID")
                decision = _utc(vector.get("decision_at"))
                if decision >= datetime(2026, 1, 1, tzinfo=timezone.utc): raise ValueError("FINITE_SEARCH_PRE2026_ASOF_REQUIRED")
                if node["op"] in {"PRICE_CLOSE", "ATR", "NUMBER", "BOOL", "DURATION"}:
                    if _utc(node.get("available_at")) > decision: raise ValueError("FINITE_SEARCH_FUTURE_INPUT_FORBIDDEN")
                    value = vector.get(node.get("input_key", node["op"].lower()))
                    if not isinstance(value, bool) if node["op"] == "BOOL" else not _number(value):
                        raise ValueError("FINITE_SEARCH_INPUT_TYPE_INVALID")
            pending.extend(node.get("args", []))
    ordered = finite_search_order(spec, body.get("arm"))
    receipts = []; visits = 0; solution = None; termination = "pool_exhausted"
    for candidate in ordered:
        if len(receipts) >= attempts or visits >= nodes or time.process_time() - started >= cpu:
            termination = "budget_incomplete"; break
        remaining_cpu = cpu - (time.process_time() - started)
        if remaining_cpu <= 0:
            termination = "budget_incomplete"; break
        primitive_task = {"protocol": "sealed_research_program_task_v1", "task_key": spec["task_key"],
            "ast": candidate["expanded_ast"], "ast_hash": candidate["ast_hash"], "scope_key": spec["scope_key"], "abstractions": {},
            "input_vectors": vectors, "expected_outputs": expected,
            "search_budget": {"cpu_seconds": remaining_cpu, "max_expansions": min(MAX_NODES * MAX_VECTORS, nodes - visits)}}
        try:
            result = execute_task(primitive_task)
        except ValueError as error:
            if str(error) == "RESEARCH_TASK_COMPUTE_BUDGET_EXCEEDED":
                termination = "partial_program_budget_incomplete"; break
            raise
        visits += result["search_resources"]["expansions"]
        matched = all(type(a) is type(b) and a == b if isinstance(a, bool) or isinstance(b, bool) else a == b
                      for a, b in zip(result["outputs"], expected))
        receipts.append({"ast_hash": candidate["ast_hash"], "node_evaluations": result["search_resources"]["expansions"],
            "outputs_hash": canonical_hash(result["outputs"]), "goal_matched": matched})
        if matched:
            solution = candidate["ast_hash"]; termination = "solution_found"; break
    elapsed = time.process_time() - started
    complete = termination in {"solution_found", "pool_exhausted"} and elapsed <= cpu
    if not complete and termination == "pool_exhausted": termination = "budget_incomplete"
    result = {"producer_protocol": "bounded_finite_program_search_v1", "benchmark_key": body["benchmark_key"],
        "spec_hash": body["spec_hash"], "contract_hash": task["contract_hash"], "arm": body["arm"],
        "executor_hash": spec["executor_hash"], "pool_hash": canonical_hash(pool),
        "status": "complete" if complete else "incomplete", "termination": termination,
        "solution_hash": solution, "attempted_programs": receipts,
        "search_resources": {"timing_scope": "finite_pool_search_including_validation_and_ranking",
            "cpu_seconds": elapsed, "attempts": len(receipts), "expansions": visits,
            "cpu_clock": "process_time", "cpu_clock_resolution_seconds": time.get_clock_info("process_time").resolution,
            "partial_program_nodes_unknown": termination == "partial_program_budget_incomplete"},
        "search_efficiency_measured": False, "measurement_scope": "one_explicit_finite_dsl_task_not_market_or_general_synthesis",
        "synthetic_fixture": spec["synthetic_fixture"], "economic_authority": False, "research_only": True, "promotion_evidence": False}
    return {**result, "result_hash": canonical_hash(result)}


class BoundedDecisionProgram:
    """Reuse the typed CALL kernel on actual closed observations.

    An ordinary research task remains telemetry only. This separate sealed
    contract is required before an operator can veto entry, reduce sizing or
    request an exit. Caller vectors and expected outputs are never consumed.
    """

    def __init__(self, contract: dict):
        if not isinstance(contract, dict) or contract.get("protocol") != DECISION_PROTOCOL:
            raise ValueError("DECISION_OPERATOR_PROTOCOL_REQUIRED")
        body = preserved_contract_body(contract, "DECISION_OPERATOR", 131072)
        if canonical_hash(body) != contract.get("contract_hash"):
            raise ValueError("DECISION_OPERATOR_CONTRACT_HASH_MISMATCH")
        contract = {**body, "contract_hash": contract["contract_hash"]}
        if not contract.get("source_task_key") or not contract.get("scope_key"):
            raise ValueError("DECISION_OPERATOR_SOURCE_AND_SCOPE_REQUIRED")
        if "input_vectors" in contract or "expected_outputs" in contract:
            raise ValueError("DECISION_OPERATOR_EXTERNAL_VECTORS_FORBIDDEN")
        self.contract = contract
        self.target = contract.get("target")
        if self.target not in {"confirmation", "risk_multiplier", "exit"}:
            raise ValueError("DECISION_OPERATOR_TARGET_INVALID")
        definitions = contract.get("abstractions", {}) or {}
        if not isinstance(definitions, dict) or len(definitions) > 8:
            raise ValueError("DECISION_OPERATOR_LIBRARY_BUDGET_INVALID")
        source_ast = contract.get("ast")
        if "ast_json" in contract:
            encoded = contract["ast_json"]
            if not isinstance(encoded, str) or len(encoded) > 32768:
                raise ValueError("DECISION_OPERATOR_AST_JSON_COPY_INVALID")
            try:
                preserved = json.loads(encoded)
            except (ValueError, RecursionError) as error:
                raise ValueError("DECISION_OPERATOR_AST_JSON_COPY_INVALID") from error
            if not isinstance(preserved, dict) or not _same_ast_copy(source_ast, preserved):
                raise ValueError("DECISION_OPERATOR_AST_JSON_COPY_MISMATCH")
            source_ast = preserved
        self.ast = _expand(source_ast, definitions, contract["scope_key"])
        self.result_type, self.nodes = _infer(self.ast)
        if canonical_hash(self.ast) != contract.get("ast_hash"):
            raise ValueError("DECISION_OPERATOR_AST_HASH_MISMATCH")
        required = "bool" if self.target in {"confirmation", "exit"} else "number"
        if self.result_type != required:
            raise ValueError("DECISION_OPERATOR_RESULT_TYPE_INVALID")
        self.bindings = contract.get("input_bindings", {}) or {}
        if not isinstance(self.bindings, dict) or any(value not in DECISION_INPUTS for value in self.bindings.values()):
            raise ValueError("DECISION_OPERATOR_INPUT_BINDING_INVALID")
        def validate_bindings(node: dict) -> None:
            op = node["op"]
            if op in {"PRICE_CLOSE", "ATR", "NUMBER", "BOOL", "DURATION"}:
                key = node.get("input_key", op.lower())
                field = self.bindings.get(key)
                if field is None:
                    raise ValueError("DECISION_OPERATOR_UNBOUND_INPUT")
                if ((op == "BOOL") != (field in {"volume_available", "risk_veto", "news_veto"})
                    or (op == "PRICE_CLOSE" and field != "close")
                    or (op == "ATR" and field != "_management_atr")
                    or op == "DURATION"):
                    raise ValueError("DECISION_OPERATOR_INPUT_BINDING_TYPE_INVALID")
            for arg in node.get("args", []):
                validate_bindings(arg)
        validate_bindings(self.ast)
        budget = contract.get("budget", {})
        self.cpu_limit = budget.get("cpu_seconds")
        self.call_limit = budget.get("max_calls")
        self.node_limit = budget.get("max_node_evaluations")
        if (not _number(self.cpu_limit) or not 0 < self.cpu_limit <= MAX_CPU_SECONDS
            or not isinstance(self.call_limit, int) or isinstance(self.call_limit, bool)
            or not 1 <= self.call_limit <= 100000
            or not isinstance(self.node_limit, int) or isinstance(self.node_limit, bool)
            or not 1 <= self.node_limit <= 4800000):
            raise ValueError("DECISION_OPERATOR_COMPUTE_BUDGET_INVALID")
        self.calls = self.visits = self.changed_decisions = 0
        self.cpu_used = 0.0
        self.observation_hasher = hashlib.sha256()

    def evaluate(self, row: dict, *, decision_at: str, observed_at: str) -> object:
        decision, observed = _utc(decision_at), _utc(observed_at)
        if observed > decision:
            raise ValueError("DECISION_OPERATOR_FUTURE_OBSERVATION_FORBIDDEN")
        self.calls += 1
        if self.calls > self.call_limit:
            raise ValueError("DECISION_OPERATOR_COMPUTE_BUDGET_EXCEEDED")
        started = time.process_time()
        inputs = {}

        def evaluate(node: dict) -> object:
            self.visits += 1
            if self.visits > self.node_limit or self.cpu_used + time.process_time() - started > self.cpu_limit:
                raise ValueError("DECISION_OPERATOR_COMPUTE_BUDGET_EXCEEDED")
            op = node["op"]
            if op == "CONST":
                return node["value"]
            if op in {"PRICE_CLOSE", "ATR", "NUMBER", "BOOL", "DURATION"}:
                if _utc(node["available_at"]) > decision:
                    raise ValueError("DECISION_OPERATOR_FUTURE_INPUT_FORBIDDEN")
                key = node.get("input_key", op.lower())
                field = self.bindings.get(key)
                if field is None:
                    raise ValueError("DECISION_OPERATOR_UNBOUND_INPUT")
                value = row.get(field)
                # pandas scalar booleans are converted by to_dict before here.
                if (op == "BOOL" and not isinstance(value, bool)) or (op != "BOOL" and not _number(value)):
                    raise ValueError("DECISION_OPERATOR_INPUT_TYPE_REQUIRED")
                inputs[key] = value
                return value
            values = [evaluate(arg) for arg in node.get("args", [])]
            if op == "GREATER_THAN": return values[0] > values[1]
            if op == "LESS_THAN": return values[0] < values[1]
            if op in {"AND", "CONFIRMED_BY"}: return all(values)
            if op == "OR": return any(values)
            if op == "NOT": return not values[0]
            raise ValueError("DECISION_OPERATOR_UNSUPPORTED_OPERATOR")

        result = evaluate(self.ast)
        self.cpu_used += time.process_time() - started
        if self.cpu_used > self.cpu_limit:
            raise ValueError("DECISION_OPERATOR_COMPUTE_BUDGET_EXCEEDED")
        if self.target == "risk_multiplier" and (not _number(result) or not 0 <= result <= 1):
            raise ValueError("DECISION_OPERATOR_RISK_INCREASE_FORBIDDEN")
        self.observation_hasher.update(canonical_hash({
            "decision_at": decision_at, "observed_at": observed_at,
            "inputs": inputs, "output": result,
        }).encode())
        return result

    def receipt(self) -> dict:
        return {
            "protocol": DECISION_PROTOCOL,
            "contract_hash": self.contract["contract_hash"],
            "ast_hash": self.contract["ast_hash"],
            "source_task_key": self.contract["source_task_key"],
            "target": self.target, "calls": self.calls,
            "node_evaluations": self.visits,
            "behavior_delta_decisions": self.changed_decisions,
            "observation_hash": self.observation_hasher.hexdigest(),
            "cpu_seconds_budget": self.cpu_limit,
            "cpu_budget_compliant": self.cpu_used <= self.cpu_limit,
            "research_only": True, "promotion_evidence": False,
        }
