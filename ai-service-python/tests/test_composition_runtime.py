import hashlib
from unittest.mock import patch

import pandas as pd
import pytest

from app.schemas import ExecutionConfig, SimpleBacktestRequest, StrategyRuntimeConfig
from app.services.backtester import (
    _load_verified_dataset_csv,
    _apply_portfolio_strategy,
    _portfolio_payload_for_signal,
    run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.composition_runtime import (
    CONTRACT_PROTOCOL,
    EXECUTABLE_NODE_ORDER,
    EXECUTABLE_NODE_PORTS,
    ENTRY_PROTOCOL,
    HASH_PROTOCOL,
    PROGRAM_PROTOCOL,
    RECEIPT_PROTOCOL,
    CompositionRuntimeContractError,
    _hash,
    apply_composition_entry_contract,
    bind_diagnostic_execution_contract,
    build_composition_execution_receipt,
    build_composition_decision_receipts,
    build_composition_runtime_trace,
    effective_management_parameters,
    validate_composition_runtime_contract,
)
from app.services.execution_contract import execution_contract_metadata

DATASET_HASH = "d" * 64
EXECUTION_HASH = "e" * 64
COMPOSITION_ID = "xau-comp-test"


def _assignment() -> dict:
    value = {
        "protocol": "lab_instrument_research_assignment_v2",
        "hash_protocol": HASH_PROTOCOL,
        "status": "assigned",
        "source_components": {
            "composition_id": COMPOSITION_ID,
            "strategy_library_id": "str_001_ema_adx_pullback",
            "tactic_library_key": "trend_pullback",
            "risk_library_id": "atr_risk_envelope",
            "management_id": "balanced_professional",
        },
        "activation_policy": {
            "protocol": "instrument_runtime_activation_contract_v1"
        },
        "selected": [],
        "selected_keys": [],
    }
    value["assignment_hash"] = _hash(value)
    return value


def _runtime_authority(
    *,
    execution_hash: str = EXECUTION_HASH,
    dataset_hash: str = DATASET_HASH,
    assignment: dict | None = None,
) -> dict:
    return {
        "symbol": "XAUUSD",
        "execution_timeframe": "H1",
        "replay_dataset_hash": dataset_hash,
        "execution_hash": execution_hash,
        "instrument_assignment": assignment or _assignment(),
        "mtf_snapshot_manifest": {},
    }


def _contract(
    *,
    management_bound: bool = True,
    execution_hash: str = EXECUTION_HASH,
    dataset_hash: str = DATASET_HASH,
    assignment: dict | None = None,
) -> dict:
    assignment = assignment or _assignment()
    value = {
        "protocol": CONTRACT_PROTOCOL,
        "hash_protocol": HASH_PROTOCOL,
        "composition_id": COMPOSITION_ID,
        "typed_program_id": "xau-program-test",
        "typed_program_protocol": PROGRAM_PROTOCOL,
        "execution_timeframe": "H1",
        "components": {
            "strategy_id": "str_001_ema_adx_pullback",
            "tactic_id": "trend_pullback",
            "risk_id": "atr_risk_envelope",
            "management_id": "balanced_professional",
        },
        "runtime_bindings": {
            "strategy": {
                "expected_family": "trend",
                "actual_family": "trend",
                "expected_architecture": "trend_pullback",
                "actual_architecture": "trend_pullback",
                "base_strategy": "trend_v1",
                "bound": True,
            },
            "tactic": {
                "declared": "trend_pullback",
                "actual_architecture": "trend_pullback",
                "bound": True,
            },
            "risk": {"gene": "atr_stop_multiplier", "value": 1.5, "bound": True},
            "management": {
                "profile": "balanced_professional",
                "bound": management_bound,
                "adapter": {
                    "protocol": "trade_management_runtime_adapter_v1",
                    "profile": "balanced_professional",
                    "partial_close_fraction": 0.4,
                    "partial_target_r": 1.0,
                    "final_target_r": 2.0,
                    "trailing_atr_multiplier": 1.5,
                    "time_stop_replay_candles": 24,
                },
            },
        },
        "strategy_contract": {
            "protocol": "composable_strategy_library_v1",
            "strategy_spec": {"id": "str_001_ema_adx_pullback"},
        },
        "tactic_contract": {
            "protocol": "audited_tactic_catalogue_v1",
            "architecture": "trend_pullback",
            "target_regimes": ["trend_up", "trend_down"],
        },
        "strategy_signal_scope": {
            "protocol": "strategy_signal_scope_v1",
            "runtime": "trend_v1",
            "regimes": ["trend_up", "trend_down"],
        },
        "strategy_scope_binding": {
            "expected_runtime": "trend_v1",
            "actual_runtime": "trend_v1",
            "expected_regimes": ["trend_up", "trend_down"],
            "actual_regimes": ["trend_up", "trend_down"],
            "bound": True,
        },
        "risk_contract": {
            "protocol": "composable_risk_management_library_v1",
            "profile": {
                "id": "atr_risk_envelope",
                "gene": "atr_stop_multiplier",
            },
        },
        "management_contract": {
            "protocol": "trade_management_library_v1",
            "profile": "balanced_professional",
            "runtime_adapter": {
                "protocol": "trade_management_runtime_adapter_v1",
                "profile": "balanced_professional",
                "partial_close_fraction": 0.4,
                "partial_target_r": 1.0,
                "final_target_r": 2.0,
                "trailing_atr_multiplier": 1.5,
                "time_stop_replay_candles": 24,
            },
        },
        "typed_program_nodes": [
            {
                "module": module,
                "timeframe": "H1",
                "requires": list(EXECUTABLE_NODE_PORTS[module][0]),
                "provides": EXECUTABLE_NODE_PORTS[module][1],
            }
            for module in EXECUTABLE_NODE_ORDER
        ],
        "decision_tools": ["atr_risk_envelope"],
        "execution_authority": {
            "protocol": "composition_execution_authority_v1",
            "symbol": "XAUUSD",
            "execution_timeframe": "H1",
            "dataset": {
                "replay_dataset_hash": dataset_hash,
                "bound": True,
            },
            "execution": {
                "execution_hash": execution_hash,
                "bound": True,
            },
            "instrument": {
                "assignment_hash": assignment["assignment_hash"],
                "selected_keys": [],
                "bound": True,
            },
            "mtf": {"required": False, "bound": True},
            "runtime": {
                "nodes": [
                    {
                        "module": "instrument_context_gate",
                        "provides": "InstrumentContextReceipt",
                    },
                    {
                        "module": "mtf_permission_gate",
                        "provides": "MtfPermissionReceipt",
                    },
                ]
            },
        },
        "paper_execution_authority": False,
        "promotion_evidence": False,
    }
    value["contract_hash"] = _hash(value)
    return value


def _bind_program_execution_timeframe(contract: dict, timeframe: str) -> None:
    execution_nodes = {
        "instrument_context_gate",
        "mtf_permission_gate",
        "strategy_runtime",
        "tactic_runtime",
        "entry_model",
        "central_risk_governor",
        "order_execution",
    }
    contract["execution_timeframe"] = timeframe
    contract["execution_authority"]["execution_timeframe"] = timeframe
    for node in contract["typed_program_nodes"]:
        if node["module"] in execution_nodes:
            node["timeframe"] = timeframe


def _result(
    contract: dict | None = None,
    *,
    trades: int = 2,
    runtime_authority: dict | None = None,
) -> dict:
    contract = contract or _contract()
    runtime_authority = runtime_authority or _runtime_authority()
    entry_funnel = {
        "raw_strategy_signals": 3,
        "composition_strategy_signals_before_tactic": 3,
        "composition_tactic_evaluations": 3,
        "composition_tactic_acceptances": 3,
        "instrument_context_gate_evaluations": 3,
        "instrument_context_gate_acceptances": 3,
        "mtf_permission_gate_evaluations": 3,
        "mtf_permission_gate_acceptances": 3,
        "risk_governor_evaluations": 3,
        "risk_governor_approvals": trades,
        "risk_governor_rejections": max(0, 3 - trades),
        "management_policy_evaluations": trades,
        "accepted_entries": trades,
    }
    entry_contract_funnel = {
        "entry_contract_protocol": ENTRY_PROTOCOL,
        "evaluated_candles": 300,
        "stage_counts": {
            "context": 300,
            "location": 300,
            "setup": 3,
            "confirmation": 3,
            "trigger": 3,
        },
    }
    decision_frame = apply_composition_entry_contract(
        pd.DataFrame(
            [{
                "time": "2026-01-01T00:00:00Z",
                "open": 100.0,
                "high": 101.0,
                "low": 99.0,
                "close": 100.5,
                "market_regime": "trend_up",
                "volatility_regime": "normal_volatility",
                "_management_atr": 1.0,
                "signal": "BUY",
                "signal_confidence": 0.8,
            }]
        ),
        contract,
    )
    initial_receipts = build_composition_decision_receipts(
        decision_frame, contract, warmup_rows=0
    )
    signal_receipt = initial_receipts["receipts"][0]
    runtime_modules = [
        "instrument_context_gate",
        "mtf_permission_gate",
        "central_risk_governor",
    ]
    if trades:
        runtime_modules.extend(["order_execution", "management_policy"])
    execution_stages = [
        {
            "module": module,
            "decision_id": signal_receipt["decision_id"],
            "status": "rejected" if not trades and module == "central_risk_governor" else "accepted",
            "reason": "risk_rejected" if not trades and module == "central_risk_governor" else "",
            "candle": signal_receipt["candle"],
            "manifest_hash": signal_receipt["manifest_hash"],
            "program_hash": signal_receipt["program_hash"],
            **(
                {
                    "closed_at": "2026-01-01T01:00:00Z",
                    "exit_reason": "take_profit",
                    "trade_result": "WIN",
                }
                if module == "management_policy"
                else {}
            ),
        }
        for module in runtime_modules
    ]
    decision_receipts = build_composition_decision_receipts(
        decision_frame,
        contract,
        execution_outcomes={signal_receipt["decision_id"]: execution_stages},
        warmup_rows=0,
    )
    decision_receipts["execution_ledger"] = {
        "protocol": "composition_execution_decision_ledger_v1",
        "composition_id": contract["composition_id"],
        "contract_hash": contract["contract_hash"],
        "manifest_hash": decision_receipts["receipts"][0]["manifest_hash"],
        "program_hash": decision_receipts["program_hash"],
        "count": 1,
        "digest": "f" * 64,
        "categories": {"signal_evaluation:accepted": 1},
        "samples": [
            {
                "decision_id": decision_receipts["receipts"][0]["decision_id"],
                "decision_candle": decision_receipts["receipts"][0]["candle"],
                "manifest_hash": decision_receipts["receipts"][0]["manifest_hash"],
                "program_hash": decision_receipts["program_hash"],
                "phase": "signal_evaluation",
                "action": decision_receipts["receipts"][0]["strategy_signal"],
                "accepted": decision_receipts["receipts"][0]["accepted"],
                "reason": decision_receipts["receipts"][0]["reason"],
                "context": "",
            }
        ],
        "ordered": True,
        "promotion_evidence": False,
    }
    receipt = build_composition_execution_receipt(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        rows=500,
        entry_funnel=entry_funnel,
        entry_contract_funnel=entry_contract_funnel,
        management_evidence={"observed_trades": trades},
        runtime_authority=runtime_authority,
        decision_receipts=decision_receipts,
    )
    return {
        "execution_contract": {"protocol": "execution_contract_v1"},
        "total_trades": trades,
        "data_quality": {"rows": 500},
        "entry_funnel": entry_funnel,
        "entry_contract_funnel": entry_contract_funnel,
        "composition_runtime_receipt": receipt,
    }


def test_complete_bound_program_returns_consumption_receipt() -> None:
    contract = _contract()
    authority = _runtime_authority()
    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result=_result(contract, runtime_authority=authority),
        runtime_authority=authority,
    )

    assert trace["status"] == "consumed", trace
    assert trace["execution_receipt_valid"] is True
    assert trace["component_bindings_valid"] is True
    assert trace["required_nodes_observed"] is True
    assert trace["witness_decision_id"] == trace["decision_receipts"]["receipts"][0]["decision_id"]


def test_legacy_program_protocol_cannot_own_the_new_runtime_graph() -> None:
    contract = _contract()
    contract["typed_program_protocol"] = "xauusd_executable_composition_program_v1"
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )

    with pytest.raises(
        CompositionRuntimeContractError,
        match="COMPOSITION_PROGRAM_PROTOCOL_UNSUPPORTED",
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(),
        )


def test_unbound_management_fails_before_replay() -> None:
    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_MANAGEMENT_NOT_BOUND"
    ):
        validate_composition_runtime_contract(
            _contract(management_bound=False),
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
        )


def test_risk_binding_requires_the_exact_runtime_parameter_value() -> None:
    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_RISK_NOT_BOUND"
    ):
        validate_composition_runtime_contract(
            _contract(),
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.75},
        )


def test_tampered_contract_fails_hash_attestation() -> None:
    contract = _contract()
    contract["components"]["risk_id"] = "cost_firewall"
    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result={},
        runtime_authority=_runtime_authority(),
    )

    assert trace["status"] == "compile_failed"
    assert trace["reason"] == "COMPOSITION_RUNTIME_CONTRACT_HASH_INVALID"


def test_declared_composition_execution_timeframe_is_fail_closed() -> None:
    with pytest.raises(
        CompositionRuntimeContractError,
        match="COMPOSITION_EXECUTION_TIMEFRAME_NOT_BOUND",
    ):
        validate_composition_runtime_contract(
            _contract(),
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="M5",
        )


def test_bound_flag_cannot_hide_strategy_identity_drift() -> None:
    contract = _contract()
    contract["runtime_bindings"]["strategy"]["actual_architecture"] = "breakout_retest"
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )

    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_STRATEGY_NOT_BOUND"
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(),
        )


def test_required_typed_node_cannot_be_removed_from_sealed_organism() -> None:
    contract = _contract()
    contract["typed_program_nodes"] = [
        node
        for node in contract["typed_program_nodes"]
        if node["module"] != "management_policy"
    ]
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )

    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_TYPED_PROGRAM_INCOMPLETE"
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(),
        )


def test_executable_program_rejects_a_port_drift_before_replay() -> None:
    contract = _contract()
    contract["typed_program_nodes"] = [
        {
            **node,
            "requires": ["SignalIntent"]
            if node["module"] == "entry_model"
            else node["requires"],
        }
        for node in contract["typed_program_nodes"]
    ]
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )

    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_PROGRAM_PORTS_INVALID"
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(),
        )


def test_executable_program_rejects_execution_node_timeframe_drift() -> None:
    contract = _contract()
    strategy = next(
        node
        for node in contract["typed_program_nodes"]
        if node["module"] == "strategy_runtime"
    )
    strategy["timeframe"] = "M15"
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )

    with pytest.raises(
        CompositionRuntimeContractError,
        match="COMPOSITION_PROGRAM_TIMEFRAME_MISMATCH:strategy_runtime",
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(),
        )


def test_empty_strategy_tactic_scope_is_a_pre_replay_compile_failure() -> None:
    contract = _contract()
    contract["tactic_contract"]["target_regimes"] = ["unknown", "transition"]
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )

    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_ACTIVATION_SCOPE_EMPTY"
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
        )


def test_m5_contract_cannot_disable_closed_mtf_authority() -> None:
    contract = _contract()
    _bind_program_execution_timeframe(contract, "M5")
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )
    authority = _runtime_authority()
    authority["execution_timeframe"] = "M5"

    with pytest.raises(CompositionRuntimeContractError, match="COMPOSITION_MTF_NOT_BOUND"):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="M5",
            runtime_authority=authority,
        )


@pytest.mark.parametrize(
    ("field", "value", "reason"),
    [
        ("replay_dataset_hash", "x" * 64, "COMPOSITION_DATASET_NOT_BOUND"),
        ("execution_hash", "x" * 64, "COMPOSITION_EXECUTION_NOT_BOUND"),
    ],
)
def test_aggregate_execution_authority_rejects_identity_drift(
    field: str, value: str, reason: str
) -> None:
    authority = _runtime_authority()
    authority[field] = value
    with pytest.raises(CompositionRuntimeContractError, match=reason):
        validate_composition_runtime_contract(
            _contract(),
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=authority,
        )


def test_aggregate_execution_authority_rejects_tampered_instrument_assignment() -> None:
    authority = _runtime_authority()
    authority["instrument_assignment"]["status"] = "tampered"
    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_INSTRUMENT_NOT_BOUND"
    ):
        validate_composition_runtime_contract(
            _contract(),
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=authority,
        )


def test_rehashed_stale_instrument_source_cannot_bind_to_frozen_composition() -> None:
    assignment = _assignment()
    assignment["source_components"]["tactic_library_key"] = "trend_breakout_retest"
    assignment["assignment_hash"] = _hash(
        {key: value for key, value in assignment.items() if key != "assignment_hash"}
    )
    contract = _contract(assignment=assignment)

    with pytest.raises(
        CompositionRuntimeContractError,
        match="COMPOSITION_INSTRUMENT_NOT_BOUND",
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(assignment=assignment),
        )


def test_mtf_bundle_identity_is_part_of_the_same_execution_authority() -> None:
    stream_hashes = {
        timeframe: (timeframe.lower() * 64)[:64]
        for timeframe in ("M5", "M15", "H1", "H4")
    }
    manifest = {
        "protocol": "closed_h4_h1_m15_m5_snapshot_v1",
        "bundle_hash": "b" * 64,
        "streams": {
            timeframe: {"sha256": digest}
            for timeframe, digest in stream_hashes.items()
        },
    }
    contract = _contract(dataset_hash="b" * 64)
    _bind_program_execution_timeframe(contract, "M5")
    contract["execution_authority"]["mtf"] = {
        "required": True,
        "bound": True,
        "bundle_hash": "b" * 64,
        "stream_hashes": stream_hashes,
    }
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )
    authority = _runtime_authority(dataset_hash="b" * 64)
    authority["execution_timeframe"] = "M5"
    authority["mtf_snapshot_manifest"] = manifest
    validate_composition_runtime_contract(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        execution_timeframe="M5",
        runtime_authority=authority,
    )
    authority["mtf_snapshot_manifest"]["bundle_hash"] = "c" * 64
    with pytest.raises(
        CompositionRuntimeContractError, match="COMPOSITION_MTF_NOT_BOUND"
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="M5",
            runtime_authority=authority,
        )


def test_management_node_is_not_claimed_when_no_trade_reached_it() -> None:
    contract = _contract()
    authority = _runtime_authority()
    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result=_result(contract, trades=0, runtime_authority=authority),
        runtime_authority=authority,
    )

    management = next(
        node for node in trace["nodes"] if node["module"] == "management_policy"
    )
    assert management["status"] == "not_reached"
    assert trace["required_nodes_observed"] is False


def test_strategy_signal_is_exposed_as_typed_contract_without_signal_creation() -> None:
    frame = pd.DataFrame(
        {
            "open": [100.0, 101.0],
            "high": [102.0, 103.0],
            "low": [99.0, 100.0],
            "close": [101.0, 102.0],
            "signal": ["WAIT", "BUY"],
            "signal_confidence": [0.0, 0.8],
            "market_regime": ["trend_up", "trend_up"],
            "_management_atr": [2.0, 2.0],
        }
    )
    compiled = apply_composition_entry_contract(frame, _contract())

    assert compiled["signal"].tolist() == ["WAIT", "BUY"]
    assert compiled["entry_contract_status"].tolist() == ["no_strategy_signal", "entry_ready"]
    assert compiled.loc[1, "entry_invalidation_valid"]


def test_specialist_veto_keeps_the_original_signal_and_emitted_rejection_chain():
    contract = _contract()
    frame = pd.DataFrame({"time": ["2025-01-06T11:00:00Z"],
        "open": [100.0], "high": [102.0], "low": [99.0], "close": [101.0],
        "signal": ["WAIT"], "pre_specialist_signal": ["BUY"],
        "signal_confidence": [0.0], "market_regime": ["trend_up"],
        "_management_atr": [2.0], "specialist_scope_eligible": [False],
        "specialist_scope_first_veto": ["liquidity_observation_missing"]})
    compiled = apply_composition_entry_contract(frame, contract)
    assert compiled.loc[0, "signal"] == "WAIT"
    assert compiled.loc[0, "composition_strategy_signal"] == "BUY"
    assert compiled.loc[0, "composition_decision_reason"] == "liquidity_observation_missing"
    receipt = build_composition_decision_receipts(compiled, contract, warmup_rows=0)
    assert receipt["decision_count"] == 1
    assert receipt["receipts"][0]["accepted"] is False
    assert receipt["receipts"][0]["reason"] == "liquidity_observation_missing"


def test_explicit_unknown_regime_is_context_when_frozen_tactic_allows_it() -> None:
    contract = _contract()
    contract["tactic_contract"]["target_regimes"] = ["unknown"]
    contract["runtime_bindings"]["strategy"]["base_strategy"] = "regime_consensus_v1"
    contract["strategy_signal_scope"] = {
        "protocol": "strategy_signal_scope_v1",
        "runtime": "regime_consensus_v1",
        "regimes": [
            "trend_up",
            "trend_down",
            "range",
            "unknown",
            "transition",
            "high_volatility",
        ],
    }
    contract["strategy_scope_binding"] = {
        "expected_runtime": "regime_consensus_v1",
        "actual_runtime": "regime_consensus_v1",
        "expected_regimes": [
            "trend_up",
            "trend_down",
            "range",
            "unknown",
            "transition",
            "high_volatility",
        ],
        "actual_regimes": [
            "trend_up",
            "trend_down",
            "range",
            "unknown",
            "transition",
            "high_volatility",
        ],
        "bound": True,
    }
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )
    frame = pd.DataFrame(
        {
            "time": ["2026-01-01T00:00:00Z"],
            "open": [100.0],
            "high": [101.0],
            "low": [99.0],
            "close": [100.5],
            "signal": ["BUY"],
            "signal_confidence": [0.8],
            "market_regime": ["unknown"],
            "_management_atr": [1.0],
        }
    )

    compiled = apply_composition_entry_contract(frame, contract)

    validate_composition_runtime_contract(
        contract,
        base_strategy="regime_consensus_v1",
        parameters={"atr_stop_multiplier": 1.5},
        execution_timeframe="H1",
        runtime_authority=_runtime_authority(),
    )

    assert bool(compiled.loc[0, "entry_context_valid"])
    assert bool(compiled.loc[0, "composition_tactic_accepted"])
    assert bool(compiled.loc[0, "composition_entry_accepted"])
    assert compiled.loc[0, "entry_contract_status"] == "entry_ready"
    assert compiled.loc[0, "composition_decision_id"]


def test_opportunity_receipt_names_the_first_tactic_veto() -> None:
    contract = _contract()
    contract["tactic_contract"]["target_regimes"] = ["trend_down"]
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )
    frame = pd.DataFrame(
        {
            "time": ["2026-01-01T00:00:00Z"],
            "open": [100.0],
            "high": [101.0],
            "low": [99.0],
            "close": [100.5],
            "signal": ["BUY"],
            "signal_confidence": [0.8],
            "market_regime": ["trend_up"],
            "_management_atr": [1.0],
        }
    )

    compiled = apply_composition_entry_contract(frame, contract)
    report = build_composition_decision_receipts(compiled, contract, warmup_rows=0)

    assert compiled.loc[0, "composition_decision_reason"] == "tactic_scope_mismatch"
    assert compiled.loc[0, "signal"] == "WAIT"
    assert report["decision_count"] == 1
    assert report["rejection_counts"] == {"tactic_scope_mismatch": 1}
    assert report["samples"][0]["decision_id"] == compiled.loc[0, "composition_decision_id"]
    assert report["samples"][0]["program_hash"] == report["program_hash"]


def test_paired_opportunity_universe_includes_implicit_no_signal_candles() -> None:
    contract = _contract()
    frame = pd.DataFrame(
        {
            "time": ["2026-01-01T00:00:00Z", "2026-01-01T01:00:00Z"],
            "open": [100.0, 100.0],
            "high": [101.0, 101.0],
            "low": [99.0, 99.0],
            "close": [100.5, 100.5],
            "signal": ["WAIT", "BUY"],
            "market_regime": ["trend_up", "trend_up"],
            "_management_atr": [1.0, 1.0],
        }
    )
    first = build_composition_decision_receipts(
        apply_composition_entry_contract(frame, contract), contract, warmup_rows=0
    )
    alternate = frame.copy()
    alternate.loc[0, "signal"] = "BUY"
    second = build_composition_decision_receipts(
        apply_composition_entry_contract(alternate, contract), contract, warmup_rows=0
    )

    assert first["opportunity_count"] == second["opportunity_count"] == 2
    assert first["no_signal_count"] == 1
    assert second["no_signal_count"] == 0
    assert first["opportunity_universe_hash"] == second["opportunity_universe_hash"]
    assert first["receipts"][0]["paired_context_id"] == second["receipts"][1]["paired_context_id"]
    assert first["receipts"][0]["decision_id"] == second["receipts"][1]["decision_id"]


def test_preentry_receipt_is_emitted_at_gate_and_not_reconstructed_after_replay() -> None:
    contract = _contract()
    frame = pd.DataFrame(
        [{
            "time": "2026-01-01T00:00:00Z",
            "open": 100.0,
            "high": 101.0,
            "low": 99.0,
            "close": 100.5,
            "signal": "BUY",
            "market_regime": "trend_up",
            "_management_atr": 1.0,
        }]
    )
    compiled = apply_composition_entry_contract(frame, contract)
    emitted = compiled.loc[0, "composition_preentry_stage_receipts"]
    assert emitted[-1]["module"] == "invalidation_model"
    assert compiled.loc[0, "composition_preentry_chain_hash"] == _hash(emitted)

    compiled.loc[0, "composition_preentry_chain_hash"] = "0" * 64
    with pytest.raises(
        CompositionRuntimeContractError,
        match="COMPOSITION_PREENTRY_RECEIPT_INVALID",
    ):
        build_composition_decision_receipts(compiled, contract, warmup_rows=0)


def test_every_signal_gets_manifest_bound_first_veto_and_ordered_stage_receipt() -> None:
    contract = _contract()
    frame = pd.DataFrame(
        {
            "time": pd.date_range("2026-01-01", periods=3, freq="h", tz="UTC"),
            "open": [100.0, 100.0, 100.0],
            "high": [101.0, 101.0, 101.0],
            "low": [99.0, 99.0, 99.0],
            "close": [100.5, 100.5, 100.5],
            "signal": ["BUY", "BUY", "BUY"],
            "signal_confidence": [0.8, 0.8, 0.8],
            "market_regime": ["trend_up", "", "trend_up"],
            "volatility_regime": ["normal_volatility"] * 3,
            "_management_atr": [1.0, 1.0, 0.0],
        }
    )

    compiled = apply_composition_entry_contract(frame, contract)
    report = build_composition_decision_receipts(compiled, contract, warmup_rows=0)

    assert report["decision_count"] == len(report["receipts"]) == 3
    assert report["rejection_counts"] == {
        "market_context_not_ready": 1,
        "atr_invalid": 1,
    }
    assert all(
        receipt["manifest_hash"] == report["receipts"][0]["manifest_hash"]
        for receipt in report["receipts"]
    )
    assert report["receipts"][1]["reason"] == "market_context_not_ready"
    assert report["receipts"][1]["stage_receipts"][-1]["module"] == "regime_detector"
    assert report["receipts"][1]["stage_receipts"][-1]["status"] == "rejected"
    assert report["receipts"][1]["stage_receipts"][-1]["reason"] == "market_context_not_ready"
    assert report["receipts"][2]["reason"] == "atr_invalid"
    assert report["receipts"][2]["stage_receipts"][-1]["module"] == "location_model"
    assert report["receipts"][2]["stage_receipts"][-1]["status"] == "rejected"
    assert report["receipts"][2]["stage_receipts"][-1]["reason"] == "atr_invalid"


@pytest.mark.parametrize(
    "regime,atr,terminal_module",
    [("", 1.0, "regime_detector"), ("range", 1.0, "tactic_runtime"), ("trend_up", 0.0, "location_model")],
)
def test_valid_preentry_veto_remains_a_valid_decision_receipt(regime, atr, terminal_module) -> None:
    from app.services.composition_runtime import _decision_receipts_valid, compile_composition_program

    contract = _contract()
    frame = pd.DataFrame([{
        "time": "2025-12-22T13:05:00Z", "open": 100.0, "high": 101.0,
        "low": 99.0, "close": 100.5, "signal": "BUY",
        "market_regime": regime, "_management_atr": atr,
    }])
    report = build_composition_decision_receipts(
        apply_composition_entry_contract(frame, contract), contract, execution_outcomes={}, warmup_rows=0,
    )
    report["execution_ledger"] = {
        "protocol": "composition_execution_decision_ledger_v1",
        "composition_id": contract["composition_id"], "contract_hash": contract["contract_hash"],
        "manifest_hash": contract["contract_hash"], "program_hash": report["program_hash"],
        "count": 0, "digest": _hash([]), "samples": [],
    }
    receipt = report["receipts"][0]
    assert receipt["preentry_accepted"] is False
    assert receipt["terminal_stage"] == terminal_module
    assert receipt["stage_receipts"][-1]["module"] == terminal_module
    assert _decision_receipts_valid(report, contract=contract, program=compile_composition_program(contract)) is True


def test_typed_pipeline_vetoes_raw_signal_before_risk_when_location_is_invalid() -> None:
    frame = pd.DataFrame(
        {
            "open": [100.0],
            "high": [100.0],
            "low": [100.0],
            "close": [100.0],
            "signal": ["BUY"],
            "signal_confidence": [0.9],
            "market_regime": ["trend_up"],
            "_management_atr": [0.0],
        }
    )

    compiled = apply_composition_entry_contract(frame, _contract())

    assert compiled.loc[0, "composition_strategy_signal"] == "BUY"
    assert compiled.loc[0, "composition_tactic_accepted"]
    assert not bool(compiled.loc[0, "composition_entry_accepted"])
    assert compiled.loc[0, "entry_contract_status"] == "atr_invalid"
    assert compiled.loc[0, "signal"] == "WAIT"
    assert compiled.loc[0, "signal_confidence"] == 0.0


def test_management_adapter_owns_exit_parameters_without_mutating_risk_gene() -> None:
    parameters = effective_management_parameters(
        _contract(),
        {"atr_stop_multiplier": 1.5, "atr_target_multiplier": 4.0},
    )

    assert parameters["atr_stop_multiplier"] == 1.5
    assert parameters["atr_target_multiplier"] == 4.0
    assert parameters["partial_take_profit_fraction"] == 0.4
    assert parameters["partial_target_atr_multiplier"] == 1.5
    assert parameters["composition_final_target_r"] == 2.0
    assert parameters["time_stop_candles"] == 24


def test_backtester_emits_runtime_receipt_from_real_decision_path() -> None:
    rows = 230
    prices = [100.0 + (index % 10) * 0.01 for index in range(rows)]
    frame = pd.DataFrame(
        {
            "time": pd.date_range("2026-01-01", periods=rows, freq="h", tz="UTC"),
            "open": prices,
            "high": [price + 0.4 for price in prices],
            "low": [price - 0.4 for price in prices],
            "close": prices,
            "volume": [1000.0] * rows,
        }
    )

    def strategy(source: pd.DataFrame, _parameters: dict) -> pd.DataFrame:
        result = source.copy()
        result["signal"] = "WAIT"
        result["signal_confidence"] = 1.0
        result["market_regime"] = "trend_up"
        result.loc[199, "signal"] = "BUY"
        return result

    execution = ExecutionConfig(
        stop_loss_percent=0.5,
        take_profit_percent=1.0,
        max_leverage=5,
    )
    execution_hash = execution_contract_metadata(
        SimpleBacktestRequest(execution=execution)
    )["execution_hash"]
    assignment = _assignment()
    contract = _contract(
        execution_hash=execution_hash,
        assignment=assignment,
    )
    payload = SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="H1",
        strategy="composition_test_v1",
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5, "atr_target_multiplier": 2.5},
        replay_dataset_hash=DATASET_HASH,
        instrument_research_assignment=assignment,
        composition_runtime_contract=contract,
        execution=execution,
    )
    with patch("app.services.backtester.get_strategy", return_value=strategy):
        result = run_simple_ema_rsi_backtest_on_dataframe(payload, frame)

    assert result.entry_contract_funnel["entry_contract_protocol"] == ENTRY_PROTOCOL
    assert result.composition_runtime_receipt["protocol"] == RECEIPT_PROTOCOL
    assert result.composition_runtime_receipt["observations"]["accepted_entries"] == 1
    assert result.composition_runtime_receipt["authority_bindings"] == {
        "dataset": True,
        "execution": True,
        "instrument": True,
        "mtf": True,
    }
    assert next(
        node
        for node in result.composition_runtime_receipt["nodes"]
        if node["module"] == "management_policy"
    )["observed"] is True
    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters=payload.parameters,
        result=result.model_dump(),
        runtime_authority=_runtime_authority(
            execution_hash=execution_hash,
            assignment=assignment,
        ),
    )
    assert trace["status"] == "consumed", (
        "unobserved="
        + repr([node["module"] for node in trace["nodes"] if not node["observed"]])
        + "; stages="
        + repr(result.entry_contract_funnel.get("stage_counts"))
    )
    assert trace["node_receipts_valid"] is True
    assert trace["program_valid"] is True
    assert trace["decision_receipts_valid"] is True
    assert trace["decision_receipts"]["samples"][0]["decision_id"]
    assert trace["execution_completed"] is True


def test_sealed_m5_mtf_organism_reaches_trade_management(tmp_path) -> None:
    start = pd.Timestamp("2026-01-01T00:00:00Z")

    def rising_stream(rows: int, frequency: str, step: float) -> pd.DataFrame:
        close = 1900.0 + pd.Series(range(rows), dtype="float64") * step
        return pd.DataFrame(
            {
                "time": pd.date_range(start, periods=rows, freq=frequency),
                "open": close - step * 0.1,
                "high": close + step * 0.2,
                "low": close - step * 0.2,
                "close": close,
                "volume": [1000.0] * rows,
            }
        )

    frames = {
        "M5": rising_stream(250, "5min", 0.02),
        "M15": rising_stream(160, "15min", 0.2),
        "H1": rising_stream(80, "1h", 1.0),
        "H4": rising_stream(40, "4h", 4.0),
    }
    paths: dict[str, str] = {}
    stream_hashes: dict[str, str] = {}
    for timeframe, frame in frames.items():
        path = tmp_path / f"{timeframe.lower()}.csv"
        frame.to_csv(path, index=False)
        paths[timeframe] = str(path)
        stream_hashes[timeframe] = hashlib.sha256(path.read_bytes()).hexdigest()

    bundle_hash = _hash(
        {
            "protocol": "closed_h4_h1_m15_m5_snapshot_v1",
            "streams": stream_hashes,
        }
    )
    manifest = {
        "protocol": "closed_h4_h1_m15_m5_snapshot_v1",
        "validation_bundle_protocol": "agent_owned_mtf_foundation_bundle_v1",
        "bundle_hash": bundle_hash,
        "streams": {
            timeframe: {"path": paths[timeframe], "sha256": stream_hashes[timeframe]}
            for timeframe in ("M5", "H4", "H1", "M15")
        },
    }
    assignment = _assignment()
    execution = ExecutionConfig(
        stop_loss_percent=0.5,
        take_profit_percent=1.0,
        max_leverage=5,
    )
    payload_seed = SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="M5",
        strategy="composition_test_v1",
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5, "atr_target_multiplier": 2.5},
        dataset_path=paths["M5"],
        replay_dataset_hash=bundle_hash,
        mtf_dataset_paths={key: paths[key] for key in ("H4", "H1", "M15")},
        mtf_snapshot_manifest=manifest,
        mtf_pilot={
            "enabled": True,
            "activation_status": "execution_stream_bound",
            "symbol": "XAUUSD",
            "entry_timeframe": "M15",
            "execution_timeframe": "M5",
            "requested_timeframe": "M5",
            "mode": "h1_veto_m15_risk",
        },
        instrument_research_assignment=assignment,
        execution=execution,
    )
    contract = _contract(
        execution_hash=execution_contract_metadata(payload_seed)["execution_hash"],
        dataset_hash=bundle_hash,
        assignment=assignment,
    )
    _bind_program_execution_timeframe(contract, "M5")
    contract["execution_authority"]["mtf"] = {
        "required": True,
        "bound": True,
        "bundle_hash": bundle_hash,
        "stream_hashes": stream_hashes,
    }
    timeframe_by_node = {
        "instrument_context_gate": "M5",
        "mtf_permission_gate": "M5",
        "mtf_context_gate": "H4",
        "regime_detector": "H4",
        "strategy_runtime": "M5",
        "tactic_runtime": "M5",
        "location_model": "H1",
        "setup_model": "M15",
        "confirmation_engine": "M15",
        "entry_model": "M5",
        "invalidation_model": "H1",
        "central_risk_governor": "M5",
        "order_execution": "M5",
        "management_policy": "M5",
    }
    for node in contract["typed_program_nodes"]:
        node["timeframe"] = timeframe_by_node[node["module"]]
    contract["contract_hash"] = _hash(
        {key: value for key, value in contract.items() if key != "contract_hash"}
    )
    payload = payload_seed.model_copy(
        update={"composition_runtime_contract": contract}
    )

    def strategy(source: pd.DataFrame, _parameters: dict) -> pd.DataFrame:
        result = source.copy()
        assert "h1_structure_regime" in result
        assert "m15_structure_direction" in result
        result["signal"] = "WAIT"
        result["signal_confidence"] = 1.0
        result["market_regime"] = result["h1_structure_regime"].fillna("unknown")
        result["volatility_regime"] = "normal_volatility"
        result.loc[199, "signal"] = "BUY"
        return result

    with patch("app.services.backtester.get_strategy", return_value=strategy):
        # Consume the sealed CSV representation, not the pre-serialization
        # float frame; strict row attestation intentionally distinguishes them.
        sealed_frame = _load_verified_dataset_csv(payload, paths["M5"], "M5")
        result = run_simple_ema_rsi_backtest_on_dataframe(payload, sealed_frame)

    assert result.composition_runtime_receipt["contract_hash"] == contract["contract_hash"]
    assert result.composition_runtime_receipt["program"]["manifest_hash"] == contract["contract_hash"]
    assert next(
        node
        for node in result.composition_runtime_receipt["program"]["nodes"]
        if node["module"] == "strategy_runtime"
    )["timeframe"] == "M5"
    assert result.composition_runtime_receipt["observations"]["accepted_entries"] == 1
    assert result.total_trades >= 1
    decision_sample = result.composition_runtime_receipt["decision_receipts"]["samples"][0]
    assert decision_sample["accepted"] is True
    assert decision_sample["program_hash"]
    assert decision_sample["manifest_hash"] == contract["contract_hash"]
    assert [
        stage["module"] for stage in decision_sample["runtime_stage_receipts"]
    ] == [
        "instrument_context_gate",
        "mtf_permission_gate",
        "central_risk_governor",
        "order_execution",
        "management_policy",
    ]
    assert all(
        stage["status"] == "accepted"
        and stage["manifest_hash"] == contract["contract_hash"]
        for stage in decision_sample["runtime_stage_receipts"]
    )
    assert next(
        node
        for node in result.composition_runtime_receipt["nodes"]
        if node["module"] == "management_policy"
    )["observed"] is True

    runtime_authority = _runtime_authority(
        execution_hash=execution_contract_metadata(payload)["execution_hash"],
        dataset_hash=bundle_hash,
        assignment=assignment,
    )
    runtime_authority["execution_timeframe"] = "M5"
    runtime_authority["mtf_snapshot_manifest"] = manifest
    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters=payload.parameters,
        result=result.model_dump(),
        runtime_authority=runtime_authority,
    )
    assert trace["status"] == "consumed", trace
    assert trace["program_valid"] is True
    assert trace["decision_receipts_valid"] is True
    assert trace["execution_completed"] is True


def test_execution_counterfactual_gets_derived_non_promotable_authority() -> None:
    canonical_payload = SimpleBacktestRequest()
    canonical_hash = execution_contract_metadata(canonical_payload)["execution_hash"]
    contract = _contract(execution_hash=canonical_hash)
    stressed = canonical_payload.model_copy(
        update={
            "execution": canonical_payload.execution.model_copy(
                update={"spread_points": canonical_payload.execution.spread_points + 1}
            )
        }
    )
    stressed_hash = execution_contract_metadata(stressed)["execution_hash"]

    with pytest.raises(
        CompositionRuntimeContractError,
        match="COMPOSITION_EXECUTION_NOT_BOUND",
    ):
        validate_composition_runtime_contract(
            contract,
            base_strategy="trend_v1",
            parameters={"atr_stop_multiplier": 1.5},
            execution_timeframe="H1",
            runtime_authority=_runtime_authority(execution_hash=stressed_hash),
        )

    derived = bind_diagnostic_execution_contract(
        contract,
        execution_hash=stressed_hash,
        diagnostic_lane="cost_profile:stress_cost",
    )
    validate_composition_runtime_contract(
        derived,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        execution_timeframe="H1",
        runtime_authority=_runtime_authority(execution_hash=stressed_hash),
    )

    assert contract["execution_authority"]["execution"]["execution_hash"] == canonical_hash
    assert derived["execution_authority"]["execution"]["execution_hash"] == stressed_hash
    assert derived["diagnostic_execution_derivation"] == {
        "protocol": "composition_diagnostic_execution_derivation_v1",
        "lane": "cost_profile:stress_cost",
        "parent_contract_hash": contract["contract_hash"],
        "canonical_execution_hash": canonical_hash,
        "diagnostic_execution_hash": stressed_hash,
        "authority_ceiling": "diagnostic_only",
        "promotion_evidence": False,
    }
    assert derived["promotion_evidence"] is False
    assert derived["contract_hash"] == _hash(
        {key: value for key, value in derived.items() if key != "contract_hash"}
    )


def test_tampered_node_receipt_cannot_be_reported_as_partially_observed() -> None:
    contract = _contract()
    authority = _runtime_authority()
    result = _result(contract, runtime_authority=authority)
    result["composition_runtime_receipt"]["nodes"][0]["evaluation_count"] += 1
    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result=result,
        runtime_authority=authority,
    )
    assert trace["status"] == "incomplete"
    assert trace["execution_receipt_valid"] is False


def test_aggregate_node_totals_cannot_replace_one_managed_decision_witness() -> None:
    contract = _contract()
    authority = _runtime_authority()
    result = _result(contract, runtime_authority=authority)
    outer = result["composition_runtime_receipt"]
    report = outer["decision_receipts"]
    signal = report["receipts"][0]
    signal["runtime_stage_receipts"].pop()
    signal["terminal_stage"] = "order_execution"
    signal["receipt_hash"] = _hash({k: v for k, v in signal.items() if k != "receipt_hash"})
    report["decision_digest"] = _hash(report["receipts"])
    outer["receipt_hash"] = _hash({k: v for k, v in outer.items() if k != "receipt_hash"})

    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result=result,
        runtime_authority=authority,
    )

    assert trace["decision_receipts_valid"] is True
    assert trace["required_nodes_observed"] is True
    assert trace["execution_completed"] is True
    assert trace["complete_decision_witness"] is False
    assert trace["witness_decision_id"] == ""
    assert trace["status"] == "position_managed"


def test_resealed_management_receipt_without_trade_close_is_invalid() -> None:
    contract = _contract()
    authority = _runtime_authority()
    result = _result(contract, runtime_authority=authority)
    outer = result["composition_runtime_receipt"]
    report = outer["decision_receipts"]
    signal = report["receipts"][0]
    signal["runtime_stage_receipts"][-1].pop("closed_at")
    signal["receipt_hash"] = _hash({k: v for k, v in signal.items() if k != "receipt_hash"})
    report["decision_digest"] = _hash(report["receipts"])
    outer["receipt_hash"] = _hash({k: v for k, v in outer.items() if k != "receipt_hash"})

    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result=result,
        runtime_authority=authority,
    )

    assert trace["decision_receipts_valid"] is False
    assert trace["execution_receipt_valid"] is False
    assert trace["status"] == "incomplete"


def test_tampered_signal_receipt_cannot_hide_behind_an_unchanged_digest() -> None:
    contract = _contract()
    authority = _runtime_authority()
    result = _result(contract, runtime_authority=authority)
    receipt = result["composition_runtime_receipt"]
    receipt["decision_receipts"]["receipts"][0]["reason"] = "tactic_scope_mismatch"
    receipt_payload = {key: value for key, value in receipt.items() if key != "receipt_hash"}
    receipt["receipt_hash"] = _hash(receipt_payload)

    trace = build_composition_runtime_trace(
        contract,
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        result=result,
        runtime_authority=authority,
    )

    assert trace["status"] == "incomplete"
    assert trace["decision_receipts_valid"] is False


def test_portfolio_selected_member_keeps_its_composition_management_owner() -> None:
    frame = pd.DataFrame(
        {
            "time": pd.date_range("2026-01-01", periods=2, freq="h", tz="UTC"),
            "open": [100.0, 100.0], "high": [101.0, 101.0],
            "low": [99.0, 99.0], "close": [100.0, 100.0],
            "market_regime": ["trend_up", "trend_up"],
            "volatility_regime": ["normal_volatility", "normal_volatility"],
            "_management_atr": [2.0, 2.0],
        }
    )

    def member_strategy(source: pd.DataFrame, _parameters: dict) -> pd.DataFrame:
        result = source.copy()
        result["signal"] = "BUY"
        result["signal_confidence"] = 0.9
        return result

    execution_hash = execution_contract_metadata(SimpleBacktestRequest())[
        "execution_hash"
    ]
    assignment = _assignment()
    contract = _contract(
        execution_hash=execution_hash,
        assignment=assignment,
    )
    member = StrategyRuntimeConfig(
        strategy="member_v1",
        base_strategy="trend_v1",
        parameters={"atr_stop_multiplier": 1.5},
        instrument_research_assignment=assignment,
        composition_runtime_contract=contract,
    )
    with patch("app.services.backtester.get_strategy", return_value=member_strategy):
        routed = _apply_portfolio_strategy(
            frame,
            [member],
            execution_timeframe="H1",
            symbol="XAUUSD",
            replay_dataset_hash=DATASET_HASH,
            execution_hash=execution_hash,
        )
    payload = SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="H1",
        strategy="portfolio_v1",
        base_strategy="portfolio",
        replay_dataset_hash=DATASET_HASH,
        portfolio_members=[member],
    )
    selected = _portfolio_payload_for_signal(payload, routed.iloc[0])

    assert selected.base_strategy == "trend_v1"
    assert selected.composition_runtime_contract["composition_id"] == "xau-comp-test"
    assert effective_management_parameters(
        selected.composition_runtime_contract, selected.parameters
    )["composition_management_profile"] == "balanced_professional"
