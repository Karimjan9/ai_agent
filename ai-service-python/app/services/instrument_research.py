from __future__ import annotations

import hashlib
import json
from typing import Any

ASSIGNMENT_PROTOCOL = "lab_instrument_research_assignment_v2"
HASH_PROTOCOL = "numeric_canonical_json_v1"
ACTIVATION_PROTOCOL = "instrument_runtime_activation_contract_v1"
OBSERVATION_PROTOCOL = "instrument_runtime_observations_v1"
TRACE_PROTOCOL = "lab_instrument_runtime_trace_v2"


def build_instrument_research_trace(
    assignment: dict[str, Any] | None,
    parameters: dict[str, Any] | None,
    result: dict[str, Any] | None,
    declared_parameters: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Attest a pre-registered instrument assignment after one replay."""

    assignment = dict(assignment or {})
    parameters = dict(parameters or {})
    declared_parameters = dict(
        declared_parameters if declared_parameters is not None else parameters
    )
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
    parameter_hash_valid = str(assignment.get("parameter_hash") or "") == _hash(
        declared_parameters
    )
    runtime_observed = isinstance(result.get("execution_contract"), dict) and (
        "total_trades" in result
        or isinstance(result.get("causal_observation"), dict)
        or isinstance(result.get("opportunity_recall"), dict)
    )
    entry_funnel = (result.get("causal_observation") or {}).get("entry_funnel", {})
    if not isinstance(entry_funnel, dict):
        entry_funnel = {}
    rejection_counts = entry_funnel.get("rejected", {})
    if not isinstance(rejection_counts, dict):
        rejection_counts = {}

    context_slices = _context_slices(result)
    matrix = result.get("robustness_matrix") or {}
    exact_context_available = isinstance(matrix, dict) and isinstance(
        matrix.get("instrument_context_envelopes"), dict
    )
    exact_context_slices = _context_slices(result, exact=True)
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
        activation = _activation_evidence(
            selected, result, context_slices, declared_hash
        )
        consumed = (
            assignment_hash_valid
            and parameter_hash_valid
            and runtime_observed
            and binding_match
            and activation["contract_valid"]
            and activation["decision_path_activated"]
        )
        veto_observed = (
            assignment_hash_valid
            and parameter_hash_valid
            and runtime_observed
            and binding_match
            and activation["contract_valid"]
            and activation["runtime_observation_valid"]
            and activation["runtime_disposition"] == "evaluated_veto"
        )
        abstain_observed = (
            assignment_hash_valid
            and parameter_hash_valid
            and runtime_observed
            and binding_match
            and activation["contract_valid"]
            and activation["runtime_observation_valid"]
            and activation["runtime_disposition"] == "evaluated_abstain"
        )
        traces.append(
            {
                "instrument_key": str(selected.get("instrument_key") or ""),
                "role": str(selected.get("role") or ""),
                "status": (
                    "consumed"
                    if consumed
                    else (
                        "evaluated_veto"
                        if veto_observed
                        else (
                            "evaluated_abstain"
                            if abstain_observed
                            else (
                                "not_activated"
                                if assignment_hash_valid
                                and parameter_hash_valid
                                and runtime_observed
                                and binding_match
                                and activation["contract_valid"]
                                else "not_attested"
                            )
                        )
                    )
                ),
                "causal_candidate": bool(selected.get("causal_candidate", False)),
                "parameter_bindings": bindings,
                "parameter_bindings_match": binding_match,
                "activation_contract_protocol": activation["contract_protocol"],
                "activation_contract_valid": activation["contract_valid"],
                "runtime_observation_protocol": activation[
                    "runtime_observation_protocol"
                ],
                "runtime_observation_valid": activation["runtime_observation_valid"],
                "runtime_receipt_consistent": activation["runtime_receipt_consistent"],
                "decision_path_activated": activation["decision_path_activated"],
                "used_in_decision": consumed or veto_observed,
                "runtime_disposition": activation["runtime_disposition"],
                "decision_effect_counts": activation["decision_effect_counts"],
                "evaluation_count": activation["evaluation_count"],
                "veto_count": activation["veto_count"],
                "abstain_count": activation["abstain_count"],
                "matched_activation_signals": activation["matched_signals"],
                "observed_activation_signals": activation["observed_signals"],
                "activated_context_keys": activation["activated_context_keys"],
                "activated_exact_context_keys": activation["activated_exact_context_keys"],
                "out_of_scope_context_keys": activation["out_of_scope_context_keys"],
                "inactive_disposition": "NOT_INVOKED_NO_CREDIT",
                "runtime_observation": {
                    "total_trades": int(result.get("total_trades", 0) or 0),
                    "raw_strategy_signals": int(
                        entry_funnel.get("raw_strategy_signals", 0) or 0
                    ),
                    "accepted_entries": int(
                        entry_funnel.get("accepted_entries", 0) or 0
                    ),
                    "rejection_counts": rejection_counts,
                },
                "promotion_evidence": False,
            }
        )

    consumed_count = sum(1 for item in traces if item["status"] == "consumed")
    decision_effect_count = sum(
        1 for item in traces if item["status"] in {"consumed", "evaluated_veto"}
    )
    bundle_contexts = _bundle_activation_contexts(traces)
    exact_bundle_contexts = _bundle_activation_contexts(
        traces, "activated_exact_context_keys"
    )
    return {
        "protocol": TRACE_PROTOCOL,
        "status": "consumed"
        if consumed_count > 0
        else ("decision_observed" if decision_effect_count > 0 else "incomplete"),
        "assignment_protocol": ASSIGNMENT_PROTOCOL,
        "activation_protocol": ACTIVATION_PROTOCOL,
        "assignment_hash": declared_hash,
        "calculated_assignment_hash": calculated_hash,
        "assignment_hash_valid": assignment_hash_valid,
        "parameter_hash_valid": parameter_hash_valid,
        "runtime_bindings_valid": bool(traces)
        and all(item["parameter_bindings_match"] for item in traces),
        "activation_contracts_valid": bool(traces)
        and all(item["activation_contract_valid"] for item in traces),
        "runtime_observations_valid": bool(traces)
        and all(item["runtime_observation_valid"] for item in traces),
        "runtime_observed": runtime_observed,
        "selected_count": len(traces),
        "consumed_count": consumed_count,
        "decision_effect_count": decision_effect_count,
        "evaluated_veto_count": sum(
            1 for item in traces if item["status"] == "evaluated_veto"
        ),
        "evaluated_abstain_count": sum(
            1 for item in traces if item["status"] == "evaluated_abstain"
        ),
        "not_activated_count": sum(
            1 for item in traces if item["status"] == "not_activated"
        ),
        "instruments": traces,
        "bundle_activation_context_keys": bundle_contexts,
        "bundle_activation_exact_context_keys": exact_bundle_contexts,
        "bundle_fully_activated": (
            bool(traces)
            and consumed_count == len(traces)
            and bool(bundle_contexts)
        ),
        # These labels come from the immutable entry-time trade ledger.  They
        # let the paired Laravel settlement compare London with London (and
        # the same regime/volatility/direction) instead of awarding a global
        # historical_mixed posterior.  A slice is diagnostic until both arms
        # have enough observations; this trace never promotes it by itself.
        "context_slices": _decorate_context_slices(context_slices, traces),
        "exact_context_slices": _decorate_context_slices(
            exact_context_slices, traces, "activated_exact_context_keys"
        ),
        "context_slice_protocol": (
            "venue_phase_v1" if exact_context_available else "legacy_session_v1"
        ),
        "context_source": "decision_time_trade_ledger",
        "instrument_activation_source": "instrument_specific_runtime_event_ledger",
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


def _context_slices(result: dict[str, Any], *, exact: bool = False) -> list[dict[str, Any]]:
    matrix = result.get("robustness_matrix") or {}
    envelope_key = "instrument_context_envelopes" if exact else "envelopes"
    envelopes = matrix.get(envelope_key) if isinstance(matrix, dict) else {}
    if not isinstance(envelopes, dict):
        return []

    slices: list[dict[str, Any]] = []
    for key, raw_metrics in sorted(envelopes.items()):
        if not isinstance(raw_metrics, dict):
            continue
        parts = str(key).split("|")
        if len(parts) != (5 if exact else 4):
            continue
        if exact:
            regime, volatility, raw_session, venue_phase, direction = parts
        else:
            regime, volatility, raw_session, direction = parts
            venue_phase = None
        try:
            hour = int(raw_session)
            if not 0 <= hour <= 23:
                continue
            session = _session_for_hour(hour)  # legacy trace compatibility only
        except (TypeError, ValueError):
            hour = None
            session = _canonical_context_value("session", raw_session)
        if session not in {"asia", "london", "new_york", "overlap", "off_session"}:
            continue
        trades = int(raw_metrics.get("trades", 0) or 0)
        slices.append(
            {
                "context_key": (
                    f"{regime}|{volatility}|{session}|{venue_phase}|{direction}"
                    if exact else f"{regime}|{volatility}|{session}|{direction}"
                ),
                "context": {
                    "regime": regime,
                    "volatility": volatility,
                    "session": session,
                    "session_utc_hour": hour,
                    "direction": direction,
                    **({"venue_phase": venue_phase} if exact else {}),
                },
                "metrics": {
                    "trades": trades,
                    "net_pf": float(raw_metrics.get("net_pf", 0) or 0),
                    "net_profit_percent": float(
                        raw_metrics.get("net_profit_percent", 0) or 0
                    ),
                    "max_drawdown_percent": float(
                        raw_metrics.get("max_drawdown_percent", 0) or 0
                    ),
                    "execution_cost_percent": float(
                        raw_metrics.get("execution_cost_percent", 0) or 0
                    ),
                },
                "powered": trades >= 3,
                "promotion_evidence": False,
            }
        )

    return slices


def _activation_evidence(
    selected: dict[str, Any],
    result: dict[str, Any],
    context_slices: list[dict[str, Any]],
    assignment_hash: str,
) -> dict[str, Any]:
    contract = selected.get("activation_contract") or {}
    if not isinstance(contract, dict):
        contract = {}
    required_events = contract.get("required_runtime_events") or []
    valid = (
        contract.get("protocol") == ACTIVATION_PROTOCOL
        and contract.get("mode") == "instrument_specific_runtime_event"
        and contract.get("aggregate_metric_fallback_allowed") is False
        and isinstance(required_events, list)
        and bool(required_events)
    )
    runtime = result.get("instrument_runtime_observations") or {}
    if not isinstance(runtime, dict):
        runtime = {}
    key = str(selected.get("instrument_key") or "")
    instrument = (
        (runtime.get("instruments") or {}).get(key, {})
        if isinstance(runtime.get("instruments"), dict)
        else {}
    )
    if not isinstance(instrument, dict):
        instrument = {}
    runtime_valid = (
        runtime.get("protocol") == OBSERVATION_PROTOCOL
        and bool(assignment_hash)
        and str(runtime.get("assignment_hash") or "") == assignment_hash
        and key in (runtime.get("selected_keys") or [])
    )
    event_sources = instrument.get("event_sources") or {}
    if not isinstance(event_sources, dict):
        event_sources = {}
    matched = sorted(
        str(source)
        for source, count in event_sources.items()
        if int(count or 0) > 0 and _runtime_event_allowed(required_events, str(source))
    )
    observed = [
        {"event_source": source, "count": int(event_sources[source] or 0)}
        for source in sorted(event_sources)
    ]
    context_event_counts = instrument.get("context_event_counts") or {}
    if not isinstance(context_event_counts, dict):
        context_event_counts = {}
    reported_count = int(instrument.get("activation_count", 0) or 0)
    evaluation_count = int(instrument.get("evaluation_count", 0) or 0)
    veto_count = int(instrument.get("veto_count", 0) or 0)
    abstain_count = int(instrument.get("abstain_count", 0) or 0)
    source_count = sum(int(count or 0) for count in event_sources.values())
    allowed_source_count = sum(
        int(count or 0)
        for source, count in event_sources.items()
        if _runtime_event_allowed(required_events, str(source))
    )
    context_count = sum(int(count or 0) for count in context_event_counts.values())
    exact_context_counts = instrument.get("exact_context_event_counts")
    exact_context_count_valid = (
        exact_context_counts is None
        or (
            isinstance(exact_context_counts, dict)
            and sum(int(count or 0) for count in exact_context_counts.values())
            == reported_count
        )
    )
    abstention_counts = instrument.get("abstention_context_counts") or {}
    if not isinstance(abstention_counts, dict):
        abstention_counts = {}
    abstention_total = sum(int(count or 0) for count in abstention_counts.values())
    effects = instrument.get("decision_effect_counts") or {}
    if not isinstance(effects, dict):
        effects = {}
    effect_total = sum(int(count or 0) for count in effects.values())
    receipt_consistent = (
        reported_count >= 0
        and reported_count == source_count == allowed_source_count == context_count
        and exact_context_count_valid
        and bool(instrument.get("decision_path_activated", False))
        == (reported_count > 0)
        and evaluation_count == reported_count + abstention_total
        and abstention_total == veto_count + abstain_count
        and effect_total == evaluation_count
    )
    declared_activated = {
        str(context_key)
        for context_key in instrument.get("activated_context_keys", [])
        if str(context_key)
    }
    runtime_contexts = instrument.get("activated_contexts") or {}
    if not isinstance(runtime_contexts, dict):
        runtime_contexts = {}
    activated_context_keys = sorted(
        context_key
        for context_key in declared_activated
        if _context_matches(
            contract.get("context") or {},
            runtime_contexts.get(context_key, _context_from_key(context_key))
            if isinstance(
                runtime_contexts.get(context_key, _context_from_key(context_key)), dict
            )
            else _context_from_key(context_key),
        )
    )
    runtime_exact_contexts = instrument.get("activated_exact_contexts") or {}
    if not isinstance(runtime_exact_contexts, dict):
        runtime_exact_contexts = {}
    activated_exact_context_keys = sorted(
        str(context_key)
        for context_key in instrument.get("activated_exact_context_keys", [])
        if len(str(context_key).split("|")) == 5
        and isinstance(runtime_exact_contexts.get(str(context_key)), dict)
        and _context_matches(
            contract.get("context") or {},
            runtime_exact_contexts[str(context_key)],
        )
    )
    reported_abstentions = {
        str(context_key)
        for context_key in instrument.get("abstained_context_keys", [])
        if str(context_key)
    }
    activated = (
        valid
        and runtime_valid
        and instrument.get("status") == "activated"
        and receipt_consistent
        and reported_count > 0
        and bool(matched)
        and bool(activated_context_keys)
    )
    out_of_scope_context_keys: set[str] = set(reported_abstentions)
    for slice_ in context_slices:
        context_key = str(slice_.get("context_key") or "")
        context = slice_.get("context") or {}
        if not isinstance(context, dict) or not context_key:
            continue
        if context_key in activated_context_keys:
            continue
        if not _context_matches(contract.get("context") or {}, context):
            out_of_scope_context_keys.add(context_key)

    return {
        "contract_protocol": str(contract.get("protocol") or ""),
        "contract_valid": valid,
        "runtime_observation_protocol": str(runtime.get("protocol") or ""),
        "runtime_observation_valid": runtime_valid and receipt_consistent,
        "runtime_receipt_consistent": receipt_consistent,
        "decision_path_activated": activated,
        "runtime_disposition": str(instrument.get("status") or "not_reached"),
        "decision_effect_counts": {
            str(key): int(value or 0) for key, value in effects.items()
        },
        "evaluation_count": evaluation_count,
        "veto_count": veto_count,
        "abstain_count": abstain_count,
        "matched_signals": matched,
        "observed_signals": observed,
        "activated_context_keys": activated_context_keys if activated else [],
        "activated_exact_context_keys": (
            activated_exact_context_keys if activated else []
        ),
        "out_of_scope_context_keys": sorted(out_of_scope_context_keys),
    }


def _context_from_key(context_key: str) -> dict[str, str]:
    parts = context_key.split("|")
    if len(parts) != 4:
        return {}
    return {
        "regime": parts[0],
        "volatility": parts[1],
        "session": parts[2],
        "direction": parts[3],
    }


def _runtime_event_allowed(patterns: list[Any], source: str) -> bool:
    for raw_pattern in patterns:
        pattern = str(raw_pattern or "")
        if pattern == "*" or pattern == source:
            return True
        if pattern.endswith("*") and source.startswith(pattern[:-1]):
            return True
    return False


def _path_value(value: dict[str, Any], path: str) -> Any:
    current: Any = value
    for segment in path.split(".") if path else []:
        if not isinstance(current, dict) or segment not in current:
            return None
        current = current[segment]
    return current


def _signal_matches(observed: Any, operator: str, expected: Any) -> bool:
    if operator == "gt":
        try:
            return float(observed) > float(expected)
        except (TypeError, ValueError):
            return False
    if operator == "eq":
        return observed == expected
    if operator == "non_empty":
        return observed not in (None, "", [], {})
    return False


def _context_matches(contract: dict[str, Any], context: dict[str, Any]) -> bool:
    regime = str(context.get("regime") or "unknown")
    compatible = {str(value) for value in contract.get("compatible_regimes", [])}
    forbidden = {str(value) for value in contract.get("forbidden_regimes", [])}
    if regime in forbidden:
        return False
    if compatible and regime not in compatible:
        return False
    declared = contract.get("declared_context") or {}
    if not isinstance(declared, dict):
        return False
    aliases = {
        "spread_liquidity_state": "spread_liquidity_state",
        "transition_state": "transition_state",
    }
    for axis, expected in declared.items():
        actual = context.get(aliases.get(str(axis), str(axis)))
        if actual is None or _canonical_context_value(
            str(axis), actual
        ) != _canonical_context_value(str(axis), expected):
            return False
    return True


def _canonical_context_value(axis: str, value: Any) -> str:
    normalized = str(value or "").strip().lower()
    if axis == "session":
        return {
            "london_new_york_overlap": "overlap",
            "london_comex_overlap": "overlap",
            "asian": "asia",
        }.get(normalized, normalized)
    if axis == "volatility":
        return {
            "low_volatility": "low",
            "normal_volatility": "normal",
            "high_volatility": "high",
        }.get(normalized, normalized)
    return normalized


def _bundle_activation_contexts(
    traces: list[dict[str, Any]], field: str = "activated_context_keys"
) -> list[str]:
    if not traces or any(item.get("status") != "consumed" for item in traces):
        return []
    context_sets = [set(item.get(field) or []) for item in traces]
    if not context_sets or any(not values for values in context_sets):
        return []
    return sorted(set.intersection(*context_sets))


def _decorate_context_slices(
    slices: list[dict[str, Any]],
    traces: list[dict[str, Any]],
    activation_field: str = "activated_context_keys",
) -> list[dict[str, Any]]:
    decorated: list[dict[str, Any]] = []
    for slice_ in slices:
        row = dict(slice_)
        key = str(row.get("context_key") or "")
        usage: list[dict[str, str]] = []
        for trace in traces:
            instrument_key = str(trace.get("instrument_key") or "")
            if key in (trace.get(activation_field) or []):
                state = "activated"
            elif key in (trace.get("out_of_scope_context_keys") or []):
                state = "abstained_outside_contract"
            else:
                state = "not_observed"
            usage.append({"instrument_key": instrument_key, "state": state})
        row["instrument_usage"] = usage
        decorated.append(row)
    return decorated


def _session_for_hour(hour: int) -> str:
    if 7 <= hour < 12:
        return "london"
    if 12 <= hour <= 16:
        return "overlap"
    if 16 < hour <= 21:
        return "new_york"
    return "asia"


def _hash(value: Any) -> str:
    encoded = json.dumps(
        _canonicalize(value), sort_keys=True, separators=(",", ":"), ensure_ascii=False
    )
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
        return {
            str(key): _canonicalize(item)
            for key, item in sorted(value.items(), key=lambda row: str(row[0]))
        }
    return value
