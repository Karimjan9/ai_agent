"""Canonical execution assumptions shared by lab, paper and holdout lanes."""

from __future__ import annotations

import hashlib
import json
from typing import Any

from app.schemas import SimpleBacktestRequest


PROTOCOL = "canonical_market_execution_v1"


def execution_contract_metadata(payload: SimpleBacktestRequest) -> dict[str, Any]:
    parameters = payload.execution.model_dump()
    serialized = json.dumps(parameters, sort_keys=True, separators=(",", ":"), default=str)
    execution_hash = hashlib.sha256(serialized.encode()).hexdigest()
    declared = payload.execution_contract if isinstance(payload.execution_contract, dict) else {}
    declared_hash = declared.get("execution_hash")
    sealed = bool(declared.get("protocol")) and declared.get("protocol") == PROTOCOL
    return {
        "protocol": declared.get("protocol", "unsealed_local_execution_v1"),
        "version": declared.get("version", "unsealed_local_execution_v1"),
        "execution_hash": execution_hash,
        "declared_execution_hash": declared_hash,
        "parameters": parameters,
        "status": "matched" if not declared_hash or declared_hash == execution_hash else "mismatch",
        "sealed": sealed,
        "promotion_evidence": sealed and (not declared_hash or declared_hash == execution_hash),
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
