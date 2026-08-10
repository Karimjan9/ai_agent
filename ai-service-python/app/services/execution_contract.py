"""Canonical execution assumptions shared by lab, paper and holdout lanes."""

from __future__ import annotations

import hashlib
import json
import math
from typing import Any

from app.schemas import SimpleBacktestRequest


PROTOCOL = "canonical_market_execution_v1"


def _canonical_json(value: Any) -> str:
    """Match Laravel's sorted JSON hash, including decimal float spelling.

    PHP's JSON encoder writes the small float as ``1.0e-5`` while Python's
    default encoder writes ``1e-05``. The values are numerically identical but
    their byte hashes are not, so the cross-language contract must own a small
    deterministic serializer instead of delegating number formatting to
    either runtime.
    """
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, dict):
        return "{" + ",".join(
            f"{json.dumps(str(key), ensure_ascii=False, separators=(',', ':'))}:{_canonical_json(value[key])}"
            for key in sorted(value)
        ) + "}"
    if isinstance(value, list):
        return "[" + ",".join(_canonical_json(item) for item in value) + "]"
    if isinstance(value, float):
        if not math.isfinite(value):
            raise ValueError("Execution contract contains a non-finite number.")
        # PHP JSON uses scientific notation for small values, but formats it
        # as ``1.0e-5`` (one-digit exponent) while Python emits ``1e-05``.
        # Normalize only that spelling; ordinary decimal floats already match
        # JSON_PRESERVE_ZERO_FRACTION.
        rendered = json.dumps(value, ensure_ascii=False, allow_nan=False)
        if "e" not in rendered and "E" not in rendered:
            return rendered
        mantissa, exponent = rendered.lower().split("e", 1)
        if "." not in mantissa:
            mantissa += ".0"
        return f"{mantissa}e{int(exponent):+d}"
    if isinstance(value, int):
        return str(value)
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), default=str)


def _semantic_contract_value(value: Any) -> Any:
    """Compare PHP-decoded and Pydantic-decoded parameter maps safely."""
    if isinstance(value, dict):
        return {
            str(key): _semantic_contract_value(item)
            for key, item in sorted(value.items(), key=lambda item: str(item[0]))
        }
    if isinstance(value, list):
        return [_semantic_contract_value(item) for item in value]
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return float(value)
    return value


def execution_contract_metadata(payload: SimpleBacktestRequest) -> dict[str, Any]:
    parameters = payload.execution.model_dump()
    serialized = _canonical_json(parameters)
    execution_hash = hashlib.sha256(serialized.encode()).hexdigest()
    declared = payload.execution_contract if isinstance(payload.execution_contract, dict) else {}
    declared_hash = declared.get("execution_hash")
    sealed = bool(declared.get("protocol")) and declared.get("protocol") == PROTOCOL
    declared_parameters = declared.get("parameters")
    declared_parameters_match = isinstance(declared_parameters, dict) and (
        _semantic_contract_value(declared_parameters)
        == _semantic_contract_value(parameters)
    )
    contract_matched = not declared or (
        sealed and bool(declared_hash) and declared_parameters_match and declared_hash == execution_hash
    )
    return {
        "protocol": declared.get("protocol", "unsealed_local_execution_v1"),
        "version": declared.get("version", "unsealed_local_execution_v1"),
        "execution_hash": execution_hash,
        "declared_execution_hash": declared_hash,
        "parameters": parameters,
        "declared_parameters_match": declared_parameters_match,
        "status": "matched" if contract_matched else "mismatch",
        "sealed": sealed,
        "promotion_evidence": sealed and contract_matched,
        "rule": "Every production lane must pass the same versioned parameter map; local defaults are diagnostic only.",
    }


def enforce_policy_boundary(payload: SimpleBacktestRequest) -> dict[str, Any]:
    """Keep RL/LLM research out of signal and promotion authority."""
    context = payload.policy_context if isinstance(payload.policy_context, dict) else {}
    declared = []
    for key in ("rl", "rl_policy", "llm", "llm_policy", "adaptive_policy"):
        value = context.get(key)
        if isinstance(value, dict):
            declared.append(value)
    for policy in declared:
        if bool(policy.get("signal_generator")) or bool(policy.get("gate_threshold_mutation")):
            raise ValueError("RL/LLM signal or gate authority is disabled; use paper-only sizing/execution research.")
        forbidden = {"signal", "signal_override", "gate", "gate_thresholds", "promotion", "strategy_override"}
        if forbidden.intersection(policy.keys()):
            raise ValueError("RL/LLM may not write signal, strategy or promotion-gate fields.")
    return {
        "protocol": "bounded_ai_policy_authority_v1",
        "signal_generator": False,
        "gate_threshold_mutation": False,
        "allowed_layers": ["paper_only_position_sizing", "paper_only_execution"],
        "status": "enforced",
        "promotion_evidence": False,
    }
