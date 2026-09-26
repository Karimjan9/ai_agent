import hashlib
import json

from app.services.instrument_research import (
    _context_matches,
    build_instrument_research_trace,
)


def test_snapshot_volatility_label_matches_runtime_volatility_without_widening_scope():
    boundary = {"declared_context": {"regime": "trend_up", "volatility": "normal"}}
    assert _context_matches(
        boundary, {"regime": "trend_up", "volatility": "normal_volatility"}
    )
    assert not _context_matches(
        boundary, {"regime": "trend_up", "volatility": "high_volatility"}
    )


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
            return {
                str(key): canonicalize(child) for key, child in sorted(item.items())
            }
        return item

    return hashlib.sha256(
        json.dumps(
            canonicalize(value),
            sort_keys=True,
            separators=(",", ":"),
            ensure_ascii=False,
        ).encode("utf-8")
    ).hexdigest()


def _activation(
    *paths, compatible_regimes=None, forbidden_regimes=None, declared_context=None
):
    return {
        "protocol": "instrument_runtime_activation_contract_v1",
        "mode": "instrument_specific_runtime_event",
        "required_runtime_events": ["exact_runtime_event"],
        "aggregate_metric_fallback_allowed": False,
        "context": {
            "compatible_regimes": list(compatible_regimes or []),
            "forbidden_regimes": list(forbidden_regimes or []),
            "declared_context": dict(declared_context or {}),
            "outside_scope_action": "ABSTAIN",
        },
    }


def _runtime(assignment, activated=None, abstained=None, vetoed=None):
    activated = dict(activated or {})
    abstained = dict(abstained or {})
    vetoed = dict(vetoed or {})
    instruments = {}
    for selected in assignment.get("selected", []):
        key = selected["instrument_key"]
        contexts = list(activated.get(key, []))
        abstained_contexts = list(abstained.get(key, []))
        vetoed_contexts = list(vetoed.get(key, []))
        evaluated_contexts = contexts + abstained_contexts + vetoed_contexts
        status = (
            "activated"
            if contexts
            else ("evaluated_veto" if vetoed_contexts else (
                "evaluated_abstain" if abstained_contexts else "not_reached"
            ))
        )
        instruments[key] = {
            "status": status,
            "activation_count": len(contexts),
            "evaluation_count": len(evaluated_contexts),
            "veto_count": len(vetoed_contexts),
            "abstain_count": len(abstained_contexts),
            "event_sources": {"exact_runtime_event": len(contexts)} if contexts else {},
            "activated_context_keys": contexts,
            "context_event_counts": {context_key: 1 for context_key in contexts},
            "activated_contexts": {
                context_key: {
                    "regime": context_key.split("|")[0],
                    "volatility": context_key.split("|")[1],
                    "session": context_key.split("|")[2],
                    "direction": context_key.split("|")[3],
                }
                for context_key in contexts
            },
            "evaluated_contexts": {
                context_key: {
                    "regime": context_key.split("|")[0],
                    "volatility": context_key.split("|")[1],
                    "session": context_key.split("|")[2],
                    "direction": context_key.split("|")[3],
                }
                for context_key in evaluated_contexts
            },
            "decision_effect_counts": {
                effect: count
                for effect, count in {
                    "ALLOW_OR_MODIFY": len(contexts),
                    "ABSTAIN": len(abstained_contexts),
                    "VETO": len(vetoed_contexts),
                }.items()
                if count > 0
            },
            "abstained_context_keys": abstained_contexts + vetoed_contexts,
            "abstention_context_counts": {
                context_key: 1 for context_key in abstained_contexts + vetoed_contexts
            },
            "decision_path_activated": bool(contexts),
            "used_in_decision": bool(contexts or vetoed_contexts),
        }
    return {
        "protocol": "instrument_runtime_observations_v1",
        "assignment_hash": assignment["assignment_hash"],
        "selected_keys": list(instruments),
        "instruments": instruments,
    }


def test_effectful_veto_is_a_research_decision_but_not_execution_or_promotion():
    parameters = {"pullback_atr_fraction": 0.75}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "selected": [
            {
                "instrument_key": "trend_pullback",
                "role": "tactic",
                "parameter_bindings": parameters,
                "activation_contract": _activation(
                    "exact_runtime_event", declared_context={"session": "london"}
                ),
            }
        ],
    }
    assignment["assignment_hash"] = _hash(assignment)

    trace = build_instrument_research_trace(
        assignment,
        parameters,
        {
            "execution_contract": {"status": "matched"},
            "total_trades": 0,
            "instrument_runtime_observations": _runtime(
                assignment,
                vetoed={"trend_pullback": ["trend_up|normal_volatility|asia|BUY"]},
            ),
        },
    )

    instrument = trace["instruments"][0]
    assert trace["status"] == "decision_observed"
    assert trace["consumed_count"] == 0
    assert trace["evaluated_veto_count"] == 1
    assert instrument["status"] == "evaluated_veto"
    assert instrument["used_in_decision"] is True
    assert instrument["decision_path_activated"] is False
    assert instrument["promotion_evidence"] is False


def test_pre_registered_runtime_bindings_are_attested_without_promotion():
    parameters = {
        "volume_lane": "breakout_volume_confirmation",
        "atr_stop_multiplier": 1.5,
    }
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
                "activation_contract": _activation(
                    "volume_policy.pre_volume_actionable"
                ),
            },
            {
                "instrument_key": "atr_risk_envelope",
                "role": "execution",
                "causal_candidate": False,
                "parameter_bindings": {"atr_stop_multiplier": 1.5},
                "activation_contract": _activation("total_trades"),
            },
        ],
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "promotion_evidence": False,
    }
    assignment["assignment_hash"] = _hash(assignment)
    result = {
        "execution_contract": {"status": "matched"},
        "total_trades": 12,
        "volume_policy": {"pre_volume_actionable": 30},
        "causal_observation": {
            "entry_funnel": {
                "raw_strategy_signals": 30,
                "accepted_entries": 12,
                "rejected": {"volume": 3},
            }
        },
        "instrument_runtime_observations": _runtime(
            assignment,
            {
                "volume_confirmation": ["trend_up|normal_volatility|london|BUY"],
                "atr_risk_envelope": ["trend_up|normal_volatility|london|BUY"],
            },
        ),
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
        "selected": [
            {
                "instrument_key": "volume_confirmation",
                "parameter_bindings": {"volume_lane": "breakout_volume_confirmation"},
                "activation_contract": _activation(
                    "volume_policy.pre_volume_actionable"
                ),
            }
        ],
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
        "selected": [
            {
                "instrument_key": "confidence_firewall",
                "parameter_bindings": {"minimum_confidence": 1},
                "activation_contract": _activation("entry_funnel.raw_strategy_signals"),
            }
        ],
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
    }
    assignment["assignment_hash"] = _hash(assignment)

    trace = build_instrument_research_trace(
        assignment,
        runtime,
        {
            "execution_contract": {"status": "matched"},
            "total_trades": 2,
            "entry_funnel": {"raw_strategy_signals": 3},
            "instrument_runtime_observations": _runtime(
                assignment,
                {
                    "confidence_firewall": ["trend_up|normal_volatility|london|BUY"],
                },
            ),
        },
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
        "selected": [
            {
                "instrument_key": "volume_confirmation",
                "parameter_bindings": parameters,
                "activation_contract": _activation(
                    "volume_policy.pre_volume_actionable"
                ),
            }
        ],
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
    }
    assignment["assignment_hash"] = _hash(assignment)
    result = {
        "execution_contract": {"status": "matched"},
        "total_trades": 7,
        "volume_policy": {"pre_volume_actionable": 7},
        "robustness_matrix": {
            "envelopes": {
                "trend_up|normal_volatility|8|BUY": {
                    "trades": 4,
                    "net_pf": 1.4,
                    "net_profit_percent": 0.8,
                    "max_drawdown_percent": 0.2,
                    "execution_cost_percent": 0.1,
                },
                "range|high_volatility|14|SELL": {
                    "trades": 2,
                    "net_pf": 0.8,
                    "net_profit_percent": -0.3,
                },
            }
        },
        "instrument_runtime_observations": _runtime(
            assignment,
            {
                "volume_confirmation": [
                    "trend_up|normal_volatility|london|BUY",
                    "range|high_volatility|overlap|SELL",
                ],
            },
        ),
    }

    trace = build_instrument_research_trace(assignment, parameters, result)

    assert trace["context_source"] == "decision_time_trade_ledger"
    assert (
        trace["instrument_activation_source"]
        == "instrument_specific_runtime_event_ledger"
    )
    slices = {slice_["context_key"]: slice_ for slice_ in trace["context_slices"]}
    assert slices["range|high_volatility|overlap|SELL"]["powered"] is False
    assert slices["trend_up|normal_volatility|london|BUY"]["powered"] is True
    assert all(
        slice_["promotion_evidence"] is False for slice_ in trace["context_slices"]
    )


def test_matching_binding_without_a_decision_event_is_not_an_invocation():
    parameters = {"transition_firewall_enabled": True}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "selected": [
            {
                "instrument_key": "transition_protection",
                "role": "risk",
                "causal_candidate": True,
                "parameter_bindings": parameters,
                "activation_contract": _activation(
                    "transition_firewall.transition_events",
                    "transition_firewall.vetoes",
                    compatible_regimes=["transition"],
                ),
            }
        ],
    }
    assignment["assignment_hash"] = _hash(assignment)

    trace = build_instrument_research_trace(
        assignment,
        parameters,
        {
            "execution_contract": {"status": "matched"},
            "total_trades": 4,
            "transition_firewall": {
                "enabled": True,
                "transition_events": 0,
                "vetoes": 0,
            },
            "instrument_runtime_observations": _runtime(assignment),
        },
    )

    assert trace["status"] == "incomplete"
    assert trace["consumed_count"] == 0
    assert trace["not_activated_count"] == 1
    assert trace["instruments"][0]["status"] == "not_activated"
    assert trace["instruments"][0]["decision_path_activated"] is False


def test_aggregate_signal_cannot_replace_an_instrument_specific_runtime_receipt():
    parameters = {"pullback_atr_fraction": 0.75}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "selected": [
            {
                "instrument_key": "trend_pullback",
                "role": "tactic",
                "parameter_bindings": parameters,
                "activation_contract": _activation(
                    "entry_funnel.raw_strategy_signals",
                    compatible_regimes=["trend_up", "trend_down"],
                ),
            }
        ],
    }
    assignment["assignment_hash"] = _hash(assignment)
    result = {
        "execution_contract": {"status": "matched"},
        "total_trades": 5,
        "entry_funnel": {"raw_strategy_signals": 40, "accepted_entries": 5},
        "instrument_runtime_observations": _runtime(assignment),
    }

    trace = build_instrument_research_trace(assignment, parameters, result)

    assert trace["status"] == "incomplete"
    assert trace["consumed_count"] == 0
    assert trace["runtime_observations_valid"] is True
    assert trace["instruments"][0]["matched_activation_signals"] == []


def test_tampered_runtime_receipt_counts_are_rejected():
    parameters = {"atr_stop_multiplier": 1.5}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "selected": [
            {
                "instrument_key": "atr_risk_envelope",
                "role": "execution",
                "parameter_bindings": parameters,
                "activation_contract": _activation("unused_aggregate_path"),
            }
        ],
    }
    assignment["assignment_hash"] = _hash(assignment)
    runtime = _runtime(
        assignment,
        {"atr_risk_envelope": ["trend_up|normal_volatility|london|BUY"]},
    )
    runtime["instruments"]["atr_risk_envelope"]["activation_count"] = 2

    trace = build_instrument_research_trace(
        assignment,
        parameters,
        {
            "execution_contract": {"status": "matched"},
            "total_trades": 1,
            "instrument_runtime_observations": runtime,
        },
    )

    assert trace["status"] == "incomplete"
    assert trace["runtime_observations_valid"] is False
    assert trace["instruments"][0]["runtime_receipt_consistent"] is False


def test_bundle_has_no_authority_when_components_activated_in_different_contexts():
    parameters = {"atr_stop_multiplier": 1.5, "atr_target_multiplier": 2.0}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "selected": [
            {
                "instrument_key": "atr_risk_envelope",
                "parameter_bindings": {"atr_stop_multiplier": 1.5},
                "activation_contract": _activation("unused"),
            },
            {
                "instrument_key": "cost_aware_exit",
                "parameter_bindings": {"atr_target_multiplier": 2.0},
                "activation_contract": _activation("unused"),
            },
        ],
    }
    assignment["assignment_hash"] = _hash(assignment)
    runtime = _runtime(
        assignment,
        {
            "atr_risk_envelope": ["trend_up|normal_volatility|london|BUY"],
            "cost_aware_exit": ["trend_up|normal_volatility|asia|BUY"],
        },
    )

    trace = build_instrument_research_trace(
        assignment,
        parameters,
        {
            "execution_contract": {"status": "matched"},
            "total_trades": 2,
            "instrument_runtime_observations": runtime,
        },
    )

    assert trace["consumed_count"] == 2
    assert trace["bundle_activation_context_keys"] == []
    assert trace["bundle_fully_activated"] is False


def test_activation_is_scoped_and_the_instrument_abstains_outside_its_contract():
    parameters = {"pullback_atr_fraction": 0.75}
    assignment = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": "numeric_canonical_json_v1",
        "parameter_hash": _hash(parameters),
        "activation_policy": {"protocol": "instrument_runtime_activation_contract_v1"},
        "selected": [
            {
                "instrument_key": "trend_pullback",
                "role": "tactic",
                "causal_candidate": True,
                "parameter_bindings": parameters,
                "activation_contract": _activation(
                    "entry_funnel.raw_strategy_signals",
                    compatible_regimes=["trend_up", "trend_down"],
                    forbidden_regimes=["transition"],
                    declared_context={"session": "london"},
                ),
            }
        ],
    }
    assignment["assignment_hash"] = _hash(assignment)
    result = {
        "execution_contract": {"status": "matched"},
        "total_trades": 7,
        "entry_funnel": {"raw_strategy_signals": 9},
        "robustness_matrix": {
            "envelopes": {
                "trend_up|normal_volatility|8|BUY": {"trades": 4, "net_pf": 1.3},
                "range|normal_volatility|8|BUY": {"trades": 3, "net_pf": 0.7},
                "trend_up|normal_volatility|2|BUY": {"trades": 3, "net_pf": 0.8},
            }
        },
        "instrument_runtime_observations": _runtime(
            assignment,
            {"trend_pullback": ["trend_up|normal_volatility|london|BUY"]},
            {
                "trend_pullback": [
                    "range|normal_volatility|london|BUY",
                    "trend_up|normal_volatility|asia|BUY",
                ]
            },
        ),
    }

    trace = build_instrument_research_trace(assignment, parameters, result)

    instrument = trace["instruments"][0]
    assert instrument["activated_context_keys"] == [
        "trend_up|normal_volatility|london|BUY"
    ]
    assert instrument["out_of_scope_context_keys"] == [
        "range|normal_volatility|london|BUY",
        "trend_up|normal_volatility|asia|BUY",
    ]
    slices = {slice_["context_key"]: slice_ for slice_ in trace["context_slices"]}
    assert (
        slices["trend_up|normal_volatility|london|BUY"]["instrument_usage"][0]["state"]
        == "activated"
    )
    assert (
        slices["range|normal_volatility|london|BUY"]["instrument_usage"][0]["state"]
        == "abstained_outside_contract"
    )
    assert (
        slices["trend_up|normal_volatility|asia|BUY"]["instrument_usage"][0]["state"]
        == "abstained_outside_contract"
    )
