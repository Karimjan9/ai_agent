import hashlib
import json

from app.services.instrument_research import build_instrument_research_trace


def _hash(value):
    def canonicalize(item):
        if isinstance(item, bool) or item is None or isinstance(item, str):
            return item
        if isinstance(item, (int, float)):
            number = f"{float(item):.14f}".rstrip("0").rstrip(".")
            return f"number:{'0' if number in ('', '-0') else number}"
        if isinstance(item, list):
            return [canonicalize(child) for child in item]
        if isinstance(item, dict):
            return {str(key): canonicalize(child) for key, child in sorted(item.items())}
        return item

    return hashlib.sha256(
        json.dumps(canonicalize(value), sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    ).hexdigest()


def test_pre_registered_runtime_bindings_are_attested_without_promotion():
    parameters = {"volume_lane": "breakout_volume_confirmation", "atr_stop_multiplier": 1.5}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "selected": [
            {
                "instrument_key": "volume_confirmation",
                "role": "market_lens",
                "causal_candidate": True,
                "parameter_bindings": {"volume_lane": "breakout_volume_confirmation"},
            },
            {
                "instrument_key": "atr_risk_envelope",
                "role": "execution",
                "causal_candidate": False,
                "parameter_bindings": {"atr_stop_multiplier": 1.5},
            },
        ],
        "promotion_evidence": False,
    }
    assignment["assignment_hash"] = _hash(assignment)
    result = {
        "execution_contract": {"status": "matched"},
        "total_trades": 12,
        "causal_observation": {
            "entry_funnel": {"raw_strategy_signals": 30, "accepted_entries": 12, "rejected": {"volume": 3}}
        },
    }

    trace = build_instrument_research_trace(assignment, parameters, result)

    assert trace["status"] == "consumed"
    assert trace["assignment_hash_valid"] is True
    assert trace["parameter_hash_valid"] is True
    assert trace["consumed_count"] == 2
    assert trace["causal_value"] == "awaiting_verified_paired_control"
    assert trace["promotion_evidence"] is False


def test_post_hoc_or_changed_assignment_is_not_attested():
    parameters = {"volume_lane": "none"}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "selected": [{
            "instrument_key": "volume_confirmation",
            "parameter_bindings": {"volume_lane": "breakout_volume_confirmation"},
        }],
        "assignment_hash": "not-the-sealed-hash",
    }

    trace = build_instrument_research_trace(
        assignment,
        parameters,
        {"execution_contract": {}, "total_trades": 1},
    )

    assert trace["status"] == "incomplete"
    assert trace["assignment_hash_valid"] is False
    assert trace["consumed_count"] == 0


def test_raw_parameter_seal_and_normalized_runtime_bindings_are_distinct():
    declared = {"minimum_confidence": 1, "atr_stop_multiplier": 1.5}
    runtime = {"minimum_confidence": 1.0, "atr_stop_multiplier": 1.5}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(declared),
        "selected": [{
            "instrument_key": "confidence_firewall",
            "parameter_bindings": {"minimum_confidence": 1},
        }],
    }
    assignment["assignment_hash"] = _hash(assignment)

    trace = build_instrument_research_trace(
        assignment,
        runtime,
        {"execution_contract": {"status": "matched"}, "total_trades": 2},
        declared,
    )

    assert trace["parameter_hash_valid"] is True
    assert trace["runtime_bindings_valid"] is True
    assert trace["status"] == "consumed"


def test_assignment_seal_survives_json_integer_float_round_trip():
    assert _hash({"minimum_confidence": 1}) == _hash({"minimum_confidence": 1.0})


def test_trace_exports_only_entry_time_context_slices_for_paired_settlement():
    parameters = {"volume_lane": "breakout_volume_confirmation"}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "selected": [{
            "instrument_key": "volume_confirmation",
            "parameter_bindings": parameters,
        }],
    }
    assignment["assignment_hash"] = _hash(assignment)
    result = {
        "execution_contract": {"status": "matched"},
        "total_trades": 7,
        "robustness_matrix": {"envelopes": {
            "trend_up|normal_volatility|8|BUY": {
                "trades": 4, "net_pf": 1.4, "net_profit_percent": 0.8,
                "max_drawdown_percent": 0.2, "execution_cost_percent": 0.1,
            },
            "range|high_volatility|14|SELL": {
                "trades": 2, "net_pf": 0.8, "net_profit_percent": -0.3,
            },
        }},
    }

    trace = build_instrument_research_trace(assignment, parameters, result)

    assert trace["context_source"] == "decision_time_trade_ledger"
    slices = {slice_["context_key"]: slice_ for slice_ in trace["context_slices"]}
    assert slices["range|high_volatility|overlap|SELL"]["powered"] is False
    assert slices["trend_up|normal_volatility|london|BUY"]["powered"] is True
    assert all(slice_["promotion_evidence"] is False for slice_ in trace["context_slices"])
