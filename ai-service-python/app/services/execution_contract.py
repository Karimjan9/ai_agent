"""Canonical execution assumptions shared by lab, paper and holdout lanes."""

from __future__ import annotations

import hashlib
import json
import math
from typing import Any

from app.schemas import SimpleBacktestRequest


PROTOCOL = "canonical_market_execution_v1"
MANAGEMENT_PROTOCOL = "paper_trade_management_v1"


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


def management_contract_metadata(payload: SimpleBacktestRequest) -> dict[str, Any]:
    """Seal the strategy-owned management genes used after paper entry.

    The canonical execution hash owns costs and fill assumptions. This second
    hash owns only post-entry behavior so partials, trailing and time stops
    cannot drift between replay and a later paper reconciliation request.
    """
    parameters = payload.parameters or {}
    management_parameters = {
        "partial_take_profit_fraction": float(parameters.get("partial_take_profit_fraction", 0) or 0),
        "partial_target_atr_multiplier": float(parameters.get("partial_target_atr_multiplier", 1.0) or 1.0),
        "trailing_atr_multiplier": float(parameters.get("trailing_atr_multiplier", 0) or 0),
        "time_stop_candles": int(parameters.get("time_stop_candles", 0) or 0),
    }
    management_hash = hashlib.sha256(_canonical_json(management_parameters).encode()).hexdigest()
    return {
        "protocol": MANAGEMENT_PROTOCOL,
        "version": MANAGEMENT_PROTOCOL,
        "parameters": management_parameters,
        "management_hash": management_hash,
        "guards": {
            "stop_widening": "forbidden",
            "loser_add": "forbidden",
            "manual_exit_override": "forbidden",
            "risk_increase_after_entry": "forbidden",
        },
        "promotion_evidence": False,
    }


def verify_management_contract(
    payload: SimpleBacktestRequest,
    paper_contract: dict[str, Any],
) -> tuple[dict[str, Any], bool]:
    """Return the canonical contract and whether the paper order attested it.

    Legacy open orders may finish for operational continuity, but they are
    explicitly unattested. A present-but-different contract fails closed.
    """
    expected = management_contract_metadata(payload)
    received = paper_contract.get("management_contract")
    if not isinstance(received, dict):
        return expected, False
    received_parameters = received.get("parameters")
    valid = (
        received.get("protocol") == MANAGEMENT_PROTOCOL
        and received.get("version") == MANAGEMENT_PROTOCOL
        and received.get("management_hash") == expected["management_hash"]
        and isinstance(received_parameters, dict)
        and _semantic_contract_value(received_parameters)
        == _semantic_contract_value(expected["parameters"])
    )
    if not valid:
        raise ValueError("Paper management contract drifted after entry.")
    return expected, True


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
