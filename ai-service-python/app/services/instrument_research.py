from __future__ import annotations

import hashlib
import json
from typing import Any


ASSIGNMENT_PROTOCOL = "lab_instrument_research_assignment_v2"
HASH_PROTOCOL = "numeric_canonical_json_v1"
TRACE_PROTOCOL = "lab_instrument_runtime_trace_v1"


def build_instrument_research_trace(
    assignment: dict[str, Any] | None,
    parameters: dict[str, Any] | None,
    result: dict[str, Any] | None,
    declared_parameters: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Attest a pre-registered instrument assignment after one replay."""

    assignment = dict(assignment or {})
    parameters = dict(parameters or {})
    declared_parameters = dict(declared_parameters if declared_parameters is not None else parameters)
    result = dict(result or {})
    if assignment.get("protocol") != ASSIGNMENT_PROTOCOL:
        return _empty("assignment_missing_or_invalid")

    declared_hash = str(assignment.get("assignment_hash") or "")
    hash_payload = dict(assignment)
    hash_payload.pop("assignment_hash", None)
    calculated_hash = _hash(hash_payload)
    assignment_hash_valid = (
        assignment.get("hash_protocol") == HASH_PROTOCOL
        and bool(declared_hash)
        and declared_hash == calculated_hash
    )
    # Laravel seals the raw JSON parameter vector. Pydantic then normalizes
    # legal runtime types (for example 1 -> 1.0). Compare the seal with the raw
    # declaration and verify each instrument binding against the normalized
    # executable vector below; conflating the two hashes rejects valid runs.
    parameter_hash_valid = str(assignment.get("parameter_hash") or "") == _hash(declared_parameters)
    runtime_observed = (
        isinstance(result.get("execution_contract"), dict)
        and (
            "total_trades" in result
            or isinstance(result.get("causal_observation"), dict)
            or isinstance(result.get("opportunity_recall"), dict)
        )
    )
    entry_funnel = (result.get("causal_observation") or {}).get("entry_funnel", {})
    if not isinstance(entry_funnel, dict):
        entry_funnel = {}
    rejection_counts = entry_funnel.get("rejected", {})
    if not isinstance(rejection_counts, dict):
        rejection_counts = {}

    traces: list[dict[str, Any]] = []
    for selected in assignment.get("selected", []):
        if not isinstance(selected, dict):
            continue
        bindings = selected.get("parameter_bindings", {})
        if not isinstance(bindings, dict):
            bindings = {}
        binding_match = bool(bindings) and all(
            key in parameters and parameters[key] == expected
            for key, expected in bindings.items()
        )
        consumed = assignment_hash_valid and parameter_hash_valid and runtime_observed and binding_match
        traces.append({
            "instrument_key": str(selected.get("instrument_key") or ""),
            "role": str(selected.get("role") or ""),
            "status": "consumed" if consumed else "not_attested",
            "causal_candidate": bool(selected.get("causal_candidate", False)),
            "parameter_bindings": bindings,
            "parameter_bindings_match": binding_match,
            "runtime_observation": {
                "total_trades": int(result.get("total_trades", 0) or 0),
                "raw_strategy_signals": int(entry_funnel.get("raw_strategy_signals", 0) or 0),
                "accepted_entries": int(entry_funnel.get("accepted_entries", 0) or 0),
                "rejection_counts": rejection_counts,
            },
            "promotion_evidence": False,
        })

    consumed_count = sum(1 for item in traces if item["status"] == "consumed")
    context_slices = _context_slices(result)
    return {
        "protocol": TRACE_PROTOCOL,
        "status": "consumed" if traces and consumed_count == len(traces) else "incomplete",
        "assignment_protocol": ASSIGNMENT_PROTOCOL,
        "assignment_hash": declared_hash,
        "calculated_assignment_hash": calculated_hash,
        "assignment_hash_valid": assignment_hash_valid,
        "parameter_hash_valid": parameter_hash_valid,
        "runtime_bindings_valid": bool(traces) and all(item["parameter_bindings_match"] for item in traces),
        "runtime_observed": runtime_observed,
        "selected_count": len(traces),
        "consumed_count": consumed_count,
        "instruments": traces,
        # These labels come from the immutable entry-time trade ledger.  They
        # let the paired Laravel settlement compare London with London (and
        # the same regime/volatility/direction) instead of awarding a global
        # historical_mixed posterior.  A slice is diagnostic until both arms
        # have enough observations; this trace never promotes it by itself.
        "context_slices": context_slices,
        "context_source": "decision_time_trade_ledger",
        "causal_value": "awaiting_verified_paired_control",
        "paper_execution_authority": False,
        "promotion_evidence": False,
    }


def _empty(reason: str) -> dict[str, Any]:
    return {
        "protocol": TRACE_PROTOCOL,
        "status": "not_applicable",
        "reason": reason,
        "selected_count": 0,
        "consumed_count": 0,
        "instruments": [],
        "paper_execution_authority": False,
        "promotion_evidence": False,
    }


def _context_slices(result: dict[str, Any]) -> list[dict[str, Any]]:
    matrix = result.get("robustness_matrix") or {}
    envelopes = matrix.get("envelopes") if isinstance(matrix, dict) else {}
    if not isinstance(envelopes, dict):
        return []

    slices: list[dict[str, Any]] = []
    for key, raw_metrics in sorted(envelopes.items()):
        if not isinstance(raw_metrics, dict):
            continue
        parts = str(key).split("|")
        if len(parts) != 4:
            continue
        regime, volatility, raw_hour, direction = parts
        try:
            hour = int(raw_hour)
        except (TypeError, ValueError):
            continue
        if not 0 <= hour <= 23:
            continue
        trades = int(raw_metrics.get("trades", 0) or 0)
        slices.append({
            "context_key": f"{regime}|{volatility}|{_session_for_hour(hour)}|{direction}",
            "context": {
                "regime": regime,
                "volatility": volatility,
                "session": _session_for_hour(hour),
                "session_utc_hour": hour,
                "direction": direction,
            },
            "metrics": {
                "trades": trades,
                "net_pf": float(raw_metrics.get("net_pf", 0) or 0),
                "net_profit_percent": float(raw_metrics.get("net_profit_percent", 0) or 0),
                "max_drawdown_percent": float(raw_metrics.get("max_drawdown_percent", 0) or 0),
                "execution_cost_percent": float(raw_metrics.get("execution_cost_percent", 0) or 0),
            },
            "powered": trades >= 3,
            "promotion_evidence": False,
        })

    return slices


def _session_for_hour(hour: int) -> str:
    if 7 <= hour < 12:
        return "london"
    if 12 <= hour <= 16:
        return "overlap"
    if 16 < hour <= 21:
        return "new_york"
    return "asia"


def _hash(value: Any) -> str:
    encoded = json.dumps(_canonicalize(value), sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _canonicalize(value: Any) -> Any:
    """Keep Laravel/Python seals stable when JSON round-trips 1.0 as 1."""
    if isinstance(value, bool) or value is None or isinstance(value, str):
        return value
    if isinstance(value, (int, float)):
        number = f"{float(value):.14f}".rstrip("0").rstrip(".")
        return f"number:{'0' if number in ('', '-0') else number}"
    if isinstance(value, list):
        return [_canonicalize(item) for item in value]
    if isinstance(value, dict):
        return {str(key): _canonicalize(item) for key, item in sorted(value.items(), key=lambda row: str(row[0]))}
    return value
