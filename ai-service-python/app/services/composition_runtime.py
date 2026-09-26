from __future__ import annotations

import hashlib
import json
from copy import deepcopy
from typing import Any

import numpy as np
import pandas as pd

CONTRACT_PROTOCOL = "xauusd_composition_runtime_contract_v3"
TRACE_PROTOCOL = "xauusd_composition_runtime_trace_v3"
RECEIPT_PROTOCOL = "xauusd_composition_execution_receipt_v3"
ENTRY_PROTOCOL = "composition_entry_contract_v2"
EXECUTION_AUTHORITY_PROTOCOL = "composition_execution_authority_v1"
MANAGEMENT_ADAPTER_PROTOCOL = "trade_management_runtime_adapter_v1"
HASH_PROTOCOL = "numeric_canonical_json_v1"
PROGRAM_PROTOCOL = "xauusd_executable_composition_program_v2"
DECISION_RECEIPT_PROTOCOL = "composition_decision_receipt_chain_v1"
DECISION_RECEIPT_SAMPLE_LIMIT = 32
EXECUTABLE_NODE_ORDER = (
    "mtf_context_gate",
    "regime_detector",
    "strategy_runtime",
    "tactic_runtime",
    "location_model",
    "setup_model",
    "confirmation_engine",
    "entry_model",
    "invalidation_model",
    "instrument_context_gate",
    "mtf_permission_gate",
    "central_risk_governor",
    "order_execution",
    "management_policy",
)
EXECUTABLE_NODE_PORTS = {
    "mtf_context_gate": (("InstrumentAssignment", "closed_candle"), "DecisionContext"),
    "regime_detector": (("DecisionContext",), "RegimeEvidence"),
    "strategy_runtime": (("DecisionContext", "RegimeEvidence"), "SignalIntent"),
    "tactic_runtime": (("DecisionContext", "SignalIntent"), "TacticDecision"),
    "location_model": (("DecisionContext", "RegimeEvidence", "TacticDecision"), "SetupLocation"),
    "setup_model": (("SetupLocation",), "SetupCandidate"),
    "confirmation_engine": (("SetupCandidate",), "ConfirmationProof"),
    "entry_model": (("ConfirmationProof", "TacticDecision"), "EntryIntent"),
    "invalidation_model": (("EntryIntent",), "TradeIdeaFailure"),
    "instrument_context_gate": (("EntryIntent", "InstrumentAssignment"), "InstrumentContextReceipt"),
    "mtf_permission_gate": (("DecisionContext", "InstrumentContextReceipt"), "MtfPermissionReceipt"),
    "central_risk_governor": (("EntryIntent", "TradeIdeaFailure", "MtfPermissionReceipt"), "RiskAuthorizedOrder"),
    "order_execution": (("RiskAuthorizedOrder",), "FillReceipt"),
    "management_policy": (("FillReceipt",), "ManagementReceipt"),
}
REQUIRED_TYPED_NODES = set(EXECUTABLE_NODE_ORDER)
SUPPORTED_PROGRAM_TIMEFRAMES = {"M5", "M15", "H1", "H4"}
EXECUTION_TIMEFRAME_NODES = {
    "instrument_context_gate",
    "mtf_permission_gate",
    "strategy_runtime",
    "tactic_runtime",
    "entry_model",
    "central_risk_governor",
    "order_execution",
}


class CompositionRuntimeContractError(ValueError):
    """The frozen passport cannot safely own this replay."""


def compile_composition_program(contract: dict[str, Any]) -> dict[str, Any]:
    """Compile the frozen typed nodes into the one replay-owned decision DAG.

    The Laravel passport carries the typed trading stages; these fixed runtime
    adapters connect that declaration to the exact instrument/context,
    strategy/tactic, order and position lifecycle boundaries implemented here.
    A missing edge, reordered node or changed port is a construction failure,
    not an opportunity to infer a permissive fallback at replay time.
    """

    declared_nodes = contract.get("typed_program_nodes")
    if contract.get("typed_program_protocol") != PROGRAM_PROTOCOL:
        raise CompositionRuntimeContractError("COMPOSITION_PROGRAM_PROTOCOL_UNSUPPORTED")
    if not isinstance(declared_nodes, list):
        raise CompositionRuntimeContractError("COMPOSITION_TYPED_PROGRAM_INCOMPLETE")
    by_module: dict[str, dict[str, Any]] = {}
    for raw_node in declared_nodes:
        if not isinstance(raw_node, dict):
            raise CompositionRuntimeContractError("COMPOSITION_TYPED_PROGRAM_INCOMPLETE")
        module = str(raw_node.get("module") or "")
        if not module or module in by_module:
            raise CompositionRuntimeContractError("COMPOSITION_TYPED_PROGRAM_INCOMPLETE")
        by_module[module] = raw_node
    if tuple(by_module) != EXECUTABLE_NODE_ORDER:
        raise CompositionRuntimeContractError("COMPOSITION_TYPED_PROGRAM_INCOMPLETE")

    available = {"InstrumentAssignment", "closed_candle"}
    compiled_nodes: list[dict[str, Any]] = []
    for module in EXECUTABLE_NODE_ORDER:
        raw_node = by_module[module]
        expected_requires, expected_provides = EXECUTABLE_NODE_PORTS[module]
        requires = raw_node.get("requires")
        provides = str(raw_node.get("provides") or "")
        timeframe = str(raw_node.get("timeframe") or "").upper()
        if (
            not isinstance(requires, list)
            or tuple(str(value) for value in requires) != expected_requires
            or provides != expected_provides
            or timeframe not in SUPPORTED_PROGRAM_TIMEFRAMES
        ):
            raise CompositionRuntimeContractError("COMPOSITION_PROGRAM_PORTS_INVALID")
        declared_execution_timeframe = str(
            contract.get("execution_timeframe") or ""
        ).upper()
        if (
            module in EXECUTION_TIMEFRAME_NODES
            and timeframe != declared_execution_timeframe
        ):
            raise CompositionRuntimeContractError(
                f"COMPOSITION_PROGRAM_TIMEFRAME_MISMATCH:{module}"
            )
        if any(value not in available for value in expected_requires):
            raise CompositionRuntimeContractError(
                f"COMPOSITION_PROGRAM_DEPENDENCY_UNSATISFIED:{module}"
            )
        available.add(provides)
        compiled_nodes.append(
            {
                "module": module,
                "timeframe": timeframe,
                "requires": list(expected_requires),
                "provides": provides,
                "veto_codes": list(raw_node.get("veto_codes") or []),
            }
        )

    manifest_payload = {
        key: value for key, value in contract.items() if key != "contract_hash"
    }
    program = {
        "protocol": PROGRAM_PROTOCOL,
        "compiler": "python_composition_runtime_v2",
        "composition_id": str(contract.get("composition_id") or ""),
        "manifest_hash": _hash(manifest_payload),
        "typed_program_id": str(contract.get("typed_program_id") or ""),
        "initial_inputs": ["InstrumentAssignment", "closed_candle"],
        "nodes": compiled_nodes,
        "terminal_type": "ManagementReceipt",
        "promotion_evidence": False,
    }
    if str(contract.get("contract_hash") or "") != str(program["manifest_hash"]):
        raise CompositionRuntimeContractError("COMPOSITION_RUNTIME_CONTRACT_HASH_INVALID")
    program["program_hash"] = _hash(program)
    return program


def _runtime_signal_regimes(base_strategy: str) -> tuple[str, ...]:
    """Return the declared outer regime envelope for a registered strategy.

    This is a capability envelope, not a claim that a replay will emit signals
    in every regime.  Unknown adapters fail closed so their scope cannot be
    guessed from historical outcomes.
    """

    normalized = str(base_strategy or "").strip().lower()
    if normalized in {"trend_v1", "trend_pullback_v1", "trend_retest_v1", "trend_breakout_retest_v1", "momentum_v1", "momentum_pullback_v1"}:
        return ("trend_up", "trend_down")
    if normalized in {"breakout_v1", "breakout_continuation_v1", "volatility_v1", "volatility_breakout_v1", "session_v1"}:
        return ("trend_up", "trend_down", "high_volatility")
    if normalized in {"mean_reversion_v1", "range_rsi_reversion_v1", "session_mean_reversion_v1"}:
        return ("range", "low_volatility")
    if normalized in {"hybrid_v1", "regime_consensus_v1"}:
        return ("trend_up", "trend_down", "range", "unknown", "transition", "high_volatility")
    if normalized == "differential_router_v1":
        return ("trend_up", "trend_down", "range")
    if normalized == "regime_ensemble_v1":
        return ("trend_up", "trend_down", "range", "high_volatility")
    return ()


def bind_diagnostic_execution_contract(
    contract: dict[str, Any] | None,
    *,
    execution_hash: str,
    diagnostic_lane: str,
) -> dict[str, Any]:
    """Seal an execution counterfactual without weakening canonical binding.

    Cost and stress diagnostics deliberately change execution assumptions.
    They must not reuse the canonical passport unchanged, because doing so
    would claim authority over an execution hash the passport never sealed.
    The derived contract retains the organism but is explicitly diagnostic
    and can never provide promotion evidence.
    """

    source = dict(contract or {})
    if not source:
        return {}
    if source.get("protocol") != CONTRACT_PROTOCOL:
        raise CompositionRuntimeContractError("COMPOSITION_RUNTIME_CONTRACT_INVALID")
    if len(str(execution_hash or "")) != 64:
        raise CompositionRuntimeContractError("COMPOSITION_EXECUTION_NOT_BOUND")

    derived = deepcopy(source)
    authority = derived.get("execution_authority")
    execution = authority.get("execution") if isinstance(authority, dict) else None
    if not isinstance(execution, dict):
        raise CompositionRuntimeContractError("COMPOSITION_EXECUTION_AUTHORITY_MISSING")

    parent_contract_hash = str(source.get("contract_hash") or "")
    canonical_execution_hash = str(execution.get("execution_hash") or "")
    execution["execution_hash"] = str(execution_hash)
    execution["bound"] = True
    derived["diagnostic_execution_derivation"] = {
        "protocol": "composition_diagnostic_execution_derivation_v1",
        "lane": str(diagnostic_lane),
        "parent_contract_hash": parent_contract_hash,
        "canonical_execution_hash": canonical_execution_hash,
        "diagnostic_execution_hash": str(execution_hash),
        "authority_ceiling": "diagnostic_only",
        "promotion_evidence": False,
    }
    derived["promotion_evidence"] = False
    derived.pop("contract_hash", None)
    derived["contract_hash"] = _hash(derived)
    return derived


def validate_composition_runtime_contract(
    contract: dict[str, Any] | None,
    *,
    base_strategy: str | None,
    parameters: dict[str, Any] | None,
    execution_timeframe: str | None = None,
    runtime_authority: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Fail closed before replay when a declared composition is not bound."""

    contract = dict(contract or {})
    if not contract:
        return {}
    if contract.get("protocol") != CONTRACT_PROTOCOL:
        raise CompositionRuntimeContractError("COMPOSITION_RUNTIME_CONTRACT_INVALID")
    declared_hash = str(contract.get("contract_hash") or "")
    payload = dict(contract)
    payload.pop("contract_hash", None)
    if (
        contract.get("hash_protocol") != HASH_PROTOCOL
        or not declared_hash
        or declared_hash != _hash(payload)
    ):
        raise CompositionRuntimeContractError("COMPOSITION_RUNTIME_CONTRACT_HASH_INVALID")
    declared_timeframe = str(contract.get("execution_timeframe") or "").upper()
    if (
        execution_timeframe is not None
        and declared_timeframe
        and declared_timeframe != str(execution_timeframe).upper()
    ):
        raise CompositionRuntimeContractError(
            "COMPOSITION_EXECUTION_TIMEFRAME_NOT_BOUND"
        )

    bindings = contract.get("runtime_bindings") or {}
    if not isinstance(bindings, dict):
        raise CompositionRuntimeContractError("COMPOSITION_RUNTIME_BINDINGS_INVALID")
    strategy = _binding(bindings, "strategy")
    tactic = _binding(bindings, "tactic")
    risk = _binding(bindings, "risk")
    management = _binding(bindings, "management")
    if (
        not bool(strategy.get("bound"))
        or not str(strategy.get("expected_family") or "")
        or str(strategy.get("expected_family") or "")
        != str(strategy.get("actual_family") or "")
        or not str(strategy.get("expected_architecture") or "")
        or str(strategy.get("expected_architecture") or "")
        != str(strategy.get("actual_architecture") or "")
        or str(strategy.get("base_strategy") or "") != str(base_strategy or "")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_STRATEGY_NOT_BOUND")
    if (
        not bool(tactic.get("bound"))
        or not str(tactic.get("declared") or "")
        or str(tactic.get("declared") or "")
        != str(tactic.get("actual_architecture") or "")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_TACTIC_NOT_BOUND")
    components = contract.get("components") or {}
    strategy_contract = contract.get("strategy_contract") or {}
    tactic_contract = contract.get("tactic_contract") or {}
    if (
        not isinstance(components, dict)
        or not isinstance(strategy_contract, dict)
        or strategy_contract.get("protocol") != "composable_strategy_library_v1"
        or str(_binding(strategy_contract, "strategy_spec").get("id") or "")
        != str(components.get("strategy_id") or "")
        or not isinstance(tactic_contract, dict)
        or tactic_contract.get("protocol") != "audited_tactic_catalogue_v1"
        or str(tactic_contract.get("architecture") or "")
        != str(components.get("tactic_id") or "")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_TACTIC_CONTRACT_INVALID")

    strategy_scope = contract.get("strategy_signal_scope") or {}
    scope_binding = contract.get("strategy_scope_binding") or {}
    declared_regimes = tuple(
        str(value)
        for value in (strategy_scope.get("regimes") or [])
        if isinstance(value, str) and value
    ) if isinstance(strategy_scope, dict) else ()
    runtime_regimes = _runtime_signal_regimes(str(base_strategy or ""))
    if (
        not isinstance(strategy_scope, dict)
        or not isinstance(scope_binding, dict)
        or strategy_scope.get("protocol") != "strategy_signal_scope_v1"
        or str(strategy_scope.get("runtime") or "") != str(base_strategy or "")
        or not declared_regimes
        or declared_regimes != runtime_regimes
        or scope_binding.get("bound") is not True
        or str(scope_binding.get("expected_runtime") or "") != str(base_strategy or "")
        or str(scope_binding.get("actual_runtime") or "") != str(base_strategy or "")
        or tuple(scope_binding.get("expected_regimes") or []) != declared_regimes
        or tuple(scope_binding.get("actual_regimes") or []) != runtime_regimes
    ):
        raise CompositionRuntimeContractError("COMPOSITION_ACTIVATION_SCOPE_UNPROVEN")
    tactic_regimes = tuple(
        str(value)
        for value in (tactic_contract.get("target_regimes") or [])
        if isinstance(value, str) and value
    )
    if not set(declared_regimes).intersection(tactic_regimes):
        raise CompositionRuntimeContractError("COMPOSITION_ACTIVATION_SCOPE_EMPTY")
    compile_composition_program(contract)

    parameters = dict(parameters or {})
    risk_contract = contract.get("risk_contract") or {}
    risk_profile = (
        risk_contract.get("profile") if isinstance(risk_contract, dict) else {}
    )
    risk_gene = str(risk.get("gene") or "")
    if (
        not bool(risk.get("bound"))
        or not isinstance(risk_contract, dict)
        or risk_contract.get("protocol") != "composable_risk_management_library_v1"
        or not isinstance(risk_profile, dict)
        or str(risk_profile.get("id") or "") != str(components.get("risk_id") or "")
        or str(risk_profile.get("gene") or "") != risk_gene
        or not risk_gene
        or risk_gene not in parameters
        or parameters[risk_gene] != risk.get("value")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_RISK_NOT_BOUND")

    adapter = management.get("adapter") or {}
    management_contract = contract.get("management_contract") or {}
    if (
        not bool(management.get("bound"))
        or not isinstance(adapter, dict)
        or adapter.get("protocol") != MANAGEMENT_ADAPTER_PROTOCOL
        or str(adapter.get("profile") or "") != str(management.get("profile") or "")
        or not isinstance(management_contract, dict)
        or management_contract.get("protocol") != "trade_management_library_v1"
        or str(management_contract.get("profile") or "")
        != str(components.get("management_id") or "")
        or management_contract.get("runtime_adapter") != adapter
    ):
        raise CompositionRuntimeContractError("COMPOSITION_MANAGEMENT_NOT_BOUND")
    typed_nodes = contract.get("typed_program_nodes") or []
    declared_modules = {
        str(node.get("module") or "")
        for node in typed_nodes
        if isinstance(node, dict)
    }
    if not isinstance(typed_nodes, list) or not REQUIRED_TYPED_NODES.issubset(
        declared_modules
    ):
        raise CompositionRuntimeContractError("COMPOSITION_TYPED_PROGRAM_INCOMPLETE")
    _validate_execution_authority(contract, runtime_authority)
    return contract


def _validate_execution_authority(
    contract: dict[str, Any], runtime_authority: dict[str, Any] | None
) -> None:
    """Bind the component passport to the exact data/context execution envelope."""

    expected = contract.get("execution_authority") or {}
    actual = dict(runtime_authority or {})
    if (
        not isinstance(expected, dict)
        or expected.get("protocol") != EXECUTION_AUTHORITY_PROTOCOL
        or not actual
    ):
        raise CompositionRuntimeContractError("COMPOSITION_EXECUTION_AUTHORITY_MISSING")

    expected_symbol = _normalize_symbol(expected.get("symbol"))
    actual_symbol = _normalize_symbol(actual.get("symbol"))
    if not expected_symbol or expected_symbol != actual_symbol:
        raise CompositionRuntimeContractError("COMPOSITION_SYMBOL_AUTHORITY_MISMATCH")
    expected_timeframe = str(expected.get("execution_timeframe") or "").upper()
    actual_timeframe = str(actual.get("execution_timeframe") or "").upper()
    if not expected_timeframe or expected_timeframe != actual_timeframe:
        raise CompositionRuntimeContractError(
            "COMPOSITION_EXECUTION_TIMEFRAME_NOT_BOUND"
        )

    dataset = _binding(expected, "dataset")
    if (
        not bool(dataset.get("bound"))
        or len(str(dataset.get("replay_dataset_hash") or "")) != 64
        or str(dataset.get("replay_dataset_hash") or "")
        != str(actual.get("replay_dataset_hash") or "")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_DATASET_NOT_BOUND")

    execution = _binding(expected, "execution")
    if (
        not bool(execution.get("bound"))
        or len(str(execution.get("execution_hash") or "")) != 64
        or str(execution.get("execution_hash") or "")
        != str(actual.get("execution_hash") or "")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_EXECUTION_NOT_BOUND")

    instrument = _binding(expected, "instrument")
    assignment = actual.get("instrument_assignment") or {}
    assignment = dict(assignment) if isinstance(assignment, dict) else {}
    assignment_hash = str(assignment.get("assignment_hash") or "")
    assignment_payload = dict(assignment)
    assignment_payload.pop("assignment_hash", None)
    source_components = assignment.get("source_components") or {}
    frozen_components = contract.get("components") or {}
    selected_keys = assignment.get("selected_keys")
    if (
        not bool(instrument.get("bound"))
        or assignment.get("protocol") != "lab_instrument_research_assignment_v2"
        or assignment.get("hash_protocol") != HASH_PROTOCOL
        or len(assignment_hash) != 64
        or assignment_hash != _hash(assignment_payload)
        or assignment_hash != str(instrument.get("assignment_hash") or "")
        or not isinstance(source_components, dict)
        or str(source_components.get("composition_id") or "")
        != str(contract.get("composition_id") or "")
        or not isinstance(frozen_components, dict)
        or any(
            str(source_components.get(source_key) or "")
            != str(frozen_components.get(component_key) or "")
            for source_key, component_key in (
                ("strategy_library_id", "strategy_id"),
                ("tactic_library_key", "tactic_id"),
                ("risk_library_id", "risk_id"),
                ("management_id", "management_id"),
            )
        )
        or not isinstance(selected_keys, list)
        or selected_keys != instrument.get("selected_keys")
    ):
        raise CompositionRuntimeContractError("COMPOSITION_INSTRUMENT_NOT_BOUND")

    runtime_nodes = {
        str(node.get("module") or "")
        for node in (_binding(expected, "runtime").get("nodes") or [])
        if isinstance(node, dict)
    }
    if not {"instrument_context_gate", "mtf_permission_gate"}.issubset(runtime_nodes):
        raise CompositionRuntimeContractError("COMPOSITION_EXECUTION_NODES_INCOMPLETE")

    mtf = _binding(expected, "mtf")
    mtf_required = bool(mtf.get("required"))
    if mtf_required != (expected_timeframe == "M5"):
        raise CompositionRuntimeContractError("COMPOSITION_MTF_NOT_BOUND")
    manifest = actual.get("mtf_snapshot_manifest") or {}
    manifest = dict(manifest) if isinstance(manifest, dict) else {}
    if mtf_required:
        stream_hashes = {
            str(timeframe).upper(): str((row or {}).get("sha256") or "")
            for timeframe, row in dict(manifest.get("streams") or {}).items()
            if isinstance(row, dict)
        }
        if (
            not bool(mtf.get("bound"))
            or manifest.get("protocol") != "closed_h4_h1_m15_m5_snapshot_v1"
            or len(str(manifest.get("bundle_hash") or "")) != 64
            or str(manifest.get("bundle_hash") or "")
            != str(mtf.get("bundle_hash") or "")
            or stream_hashes != dict(mtf.get("stream_hashes") or {})
            or any(
                len(stream_hashes.get(key, "")) != 64
                for key in ("M5", "M15", "H1", "H4")
            )
        ):
            raise CompositionRuntimeContractError("COMPOSITION_MTF_NOT_BOUND")
    elif not bool(mtf.get("bound")):
        raise CompositionRuntimeContractError("COMPOSITION_MTF_NOT_BOUND")


def effective_management_parameters(
    contract: dict[str, Any] | None, parameters: dict[str, Any] | None
) -> dict[str, Any]:
    """Return the concrete management values owned by the frozen passport."""

    merged = dict(parameters or {})
    contract = dict(contract or {})
    if not contract:
        return merged
    management = _binding(contract.get("runtime_bindings") or {}, "management")
    adapter = management.get("adapter") or {}
    if (
        not bool(management.get("bound"))
        or not isinstance(adapter, dict)
        or adapter.get("protocol") != MANAGEMENT_ADAPTER_PROTOCOL
    ):
        return merged

    stop_multiplier = float(merged.get("atr_stop_multiplier", 0) or 0)
    partial_r = adapter.get("partial_target_r")
    merged["partial_take_profit_fraction"] = float(
        adapter.get("partial_close_fraction", 0) or 0
    )
    if partial_r is not None and stop_multiplier > 0:
        merged["partial_target_atr_multiplier"] = float(partial_r) * stop_multiplier
    merged["trailing_atr_multiplier"] = float(
        adapter.get("trailing_atr_multiplier", 0) or 0
    )
    merged["time_stop_candles"] = max(
        0, int(adapter.get("time_stop_replay_candles", 0) or 0)
    )
    final_r = adapter.get("final_target_r")
    merged["composition_final_target_r"] = (
        float(final_r) if final_r is not None else None
    )
    merged["composition_management_profile"] = str(adapter.get("profile") or "")
    merged["composition_management_adapter_protocol"] = str(
        adapter.get("protocol") or ""
    )
    return merged


def apply_composition_entry_contract(
    frame: pd.DataFrame, contract: dict[str, Any] | None
) -> pd.DataFrame:
    """Expose an ordinary strategy signal through the typed entry interface.

    The adapter never creates or upgrades a signal. It records the ordered
    context/location/setup/confirmation/trigger checks implied by the already
    executable strategy output; risk geometry stays in the replay engine.
    """

    contract = dict(contract or {})
    if contract.get("protocol") != CONTRACT_PROTOCOL:
        return frame
    out = frame.copy()
    out.attrs = dict(frame.attrs)
    strategy_signal = out.get("signal", pd.Series("WAIT", index=out.index)).astype(str).str.upper()
    strategy_actionable = strategy_signal.isin(["BUY", "SELL"])
    finite_price = pd.Series(True, index=out.index, dtype="bool")
    for column in ("open", "high", "low", "close"):
        values = pd.to_numeric(out.get(column), errors="coerce")
        finite_price &= values.notna() & np.isfinite(values)
    has_regime_stream = "market_regime" in out.columns
    raw_regime = out.get("market_regime", pd.Series("", index=out.index))
    regime_value_valid = raw_regime.notna() & raw_regime.astype(str).str.strip().str.lower().ne("")
    regime_value_valid &= ~raw_regime.astype(str).str.strip().str.lower().isin(
        {"nan", "none", "null"}
    )
    regime = raw_regime.fillna("").astype(str).str.strip()
    # `unknown` is a valid classified regime when the closed-candle classifier
    # produced it. The selected tactic, not the generic context gate, decides
    # whether that regime is admissible. A missing/blank classifier stream is
    # still not-ready.
    context_valid = finite_price & regime_value_valid & has_regime_stream
    execution_timeframe = str(contract.get("execution_timeframe") or "").upper()
    requires_closed_mtf = execution_timeframe == "M5"
    mtf_ready = pd.Series(True, index=out.index, dtype="bool")
    if requires_closed_mtf:
        mtf_ready = out.get(
            "mtf_stack_status", pd.Series("incomplete", index=out.index)
        ).astype(str).eq("ready")
        for column in ("h4_context_hash", "h1_context_hash", "m15_context_hash"):
            values = out.get(column, pd.Series(np.nan, index=out.index))
            mtf_ready &= values.notna() & values.astype(str).ne("")
        context_valid &= mtf_ready
    tactic_contract = contract.get("tactic_contract") or {}
    target_regimes = {
        str(value)
        for value in tactic_contract.get("target_regimes", [])
        if isinstance(value, str) and value
    } if isinstance(tactic_contract, dict) else set()
    volatility = out.get(
        "volatility_regime",
        pd.Series("normal_volatility", index=out.index),
    ).astype(str)
    tactic_context_valid = pd.Series(True, index=out.index, dtype="bool")
    if target_regimes:
        tactic_context_valid = regime.isin(target_regimes) | volatility.isin(
            target_regimes
        )
    tactic_evaluated = strategy_actionable & context_valid
    tactic_rejected = tactic_evaluated & ~tactic_context_valid
    signal = strategy_signal.where(~tactic_rejected, "WAIT")
    actionable = signal.isin(["BUY", "SELL"])
    out["composition_strategy_signal"] = strategy_signal
    out["composition_tactic_signal"] = signal
    out["composition_tactic_evaluated"] = tactic_evaluated
    out["composition_tactic_accepted"] = tactic_evaluated & ~tactic_rejected
    out["composition_tactic_context_valid"] = tactic_context_valid
    out["composition_tactic_rejection"] = np.where(
        tactic_rejected, "tactic_context_outside_scope", ""
    )
    if "signal_confidence" not in out.columns:
        out["signal_confidence"] = 0.0
    atr = pd.to_numeric(out.get("_management_atr"), errors="coerce").fillna(0.0)
    h1_location_ready = pd.Series(True, index=out.index, dtype="bool")
    m15_setup_ready = pd.Series(True, index=out.index, dtype="bool")
    m15_direction_valid = pd.Series(True, index=out.index, dtype="bool")
    if requires_closed_mtf:
        h1_location_ready = out.get(
            "h1_available_at", pd.Series(pd.NaT, index=out.index)
        ).notna()
        m15_setup_ready = out.get(
            "m15_available_at", pd.Series(pd.NaT, index=out.index)
        ).notna()
        m15_direction = out.get(
            "m15_structure_direction", pd.Series("", index=out.index)
        ).astype(str).str.lower()
        buy_context = m15_direction.str.contains("up|bull|long|buy", regex=True)
        sell_context = m15_direction.str.contains("down|bear|short|sell", regex=True)
        m15_direction_valid = (
            signal.eq("BUY") & buy_context
        ) | (
            signal.eq("SELL") & sell_context
        ) | ~actionable
    location_valid = (
        context_valid & tactic_context_valid & h1_location_ready & atr.gt(0)
    )
    confidence = pd.to_numeric(
        out.get("signal_confidence", pd.Series(0.0, index=out.index)), errors="coerce"
    ).fillna(0.0)
    setup = location_valid & m15_setup_ready & actionable
    confirmation = setup & m15_direction_valid & confidence.ge(0.0)
    trigger = confirmation
    invalidation = trigger & atr.gt(0)
    reward = invalidation
    chase = reward
    event = chase

    # Every strategy opportunity gets one stable identity and one first-veto
    # receipt. Non-signal candles remain idle; they are not mislabeled as
    # context failures. The shared decision id is also used by the bounded
    # decision ledger in the final execution receipt.
    raw_opportunity = strategy_actionable
    rejection_reason = pd.Series("", index=out.index, dtype="object")
    rejection_stage = pd.Series("entry_authorized", index=out.index, dtype="object")
    first_vetoes = (
        ("context", requires_closed_mtf and ~mtf_ready, "mtf_not_ready"),
        ("context", ~context_valid, "market_context_not_ready"),
        ("tactic", tactic_rejected, "tactic_scope_mismatch"),
        ("location", ~h1_location_ready, "h1_location_missing"),
        ("location", ~atr.gt(0), "atr_invalid"),
        ("setup", ~m15_setup_ready, "m15_setup_missing"),
        ("confirmation", ~m15_direction_valid, "m15_direction_mismatch"),
    )
    still_open = raw_opportunity.copy()
    for stage_name, failed, reason_code in first_vetoes:
        failed_mask = pd.Series(bool(failed), index=out.index) if not isinstance(
            failed, pd.Series
        ) else failed
        failed_mask = still_open & failed_mask
        rejection_reason.loc[failed_mask] = reason_code
        rejection_stage.loc[failed_mask] = stage_name
        still_open &= ~(
            pd.Series(bool(failed), index=out.index)
            if not isinstance(failed, pd.Series)
            else failed
        )
    rejection_reason.loc[~raw_opportunity] = "no_strategy_signal"
    rejection_stage.loc[~raw_opportunity] = "idle"
    out["composition_decision_stage"] = rejection_stage
    out["composition_decision_reason"] = rejection_reason
    out["composition_decision_accepted"] = raw_opportunity & event
    out["composition_row_ordinal"] = np.arange(len(out), dtype="int64")
    program_hash = compile_composition_program(contract)["program_hash"]
    candle_values = out["time"].astype(str) if "time" in out.columns else pd.Series(
        [str(value) for value in out.index], index=out.index
    )
    out["composition_decision_id"] = [
        _hash(
            {
                "composition_id": str(contract.get("composition_id") or ""),
                "contract_hash": str(contract.get("contract_hash") or ""),
                "program_hash": program_hash,
                "candle": str(candle_values.iloc[position]),
                "row_ordinal": int(position),
            }
        )
        if bool(raw_opportunity.iloc[position])
        else ""
        for position in range(len(out))
    ]
    # Emit the typed gate chain at the same boundary that turns the strategy
    # signal into WAIT or an entry intent. The later trace must consume these
    # receipts, never re-run the predicates against a post-replay frame.
    manifest_hash = str(contract.get("contract_hash") or "")
    preentry_receipts: list[list[dict[str, Any]] | None] = [None] * len(out)
    preentry_hashes: list[str] = [""] * len(out)
    for position in np.flatnonzero(raw_opportunity.to_numpy(dtype=bool)):
        decision_id = str(out["composition_decision_id"].iloc[position])
        candle = str(candle_values.iloc[position])
        location_ready = bool(h1_location_ready.iloc[position])
        atr_ready = float(atr.iloc[position]) > 0
        predicates = (
            ("mtf_context_gate", bool(mtf_ready.iloc[position]), "mtf_not_ready"),
            ("regime_detector", bool(context_valid.iloc[position]), "market_context_not_ready"),
            ("strategy_runtime", True, ""),
            ("tactic_runtime", bool(tactic_context_valid.iloc[position]), "tactic_scope_mismatch"),
            (
                "location_model",
                location_ready and atr_ready,
                "h1_location_missing" if not location_ready else "atr_invalid",
            ),
            ("setup_model", bool(m15_setup_ready.iloc[position]), "m15_setup_missing"),
            ("confirmation_engine", bool(m15_direction_valid.iloc[position]), "m15_direction_mismatch"),
            ("entry_model", bool(event.iloc[position]), "entry_gate_rejected"),
            ("invalidation_model", bool(invalidation.iloc[position]), "invalidation_invalid"),
        )
        stages: list[dict[str, Any]] = []
        for module, passed, veto in predicates:
            receipt = {
                "module": module,
                "decision_id": decision_id,
                "candle": candle,
                "manifest_hash": manifest_hash,
                "program_hash": program_hash,
                "status": "accepted" if passed else "rejected",
            }
            if not passed:
                receipt["reason"] = veto
            stages.append(receipt)
            if not passed:
                break
        preentry_receipts[position] = stages
        preentry_hashes[position] = _hash(stages)
    out["composition_preentry_stage_receipts"] = pd.Series(
        preentry_receipts, index=out.index, dtype="object"
    )
    out["composition_preentry_chain_hash"] = preentry_hashes

    strategy_binding = _binding(contract.get("runtime_bindings") or {}, "strategy")
    components = contract.get("components") or {}
    direction_sign = pd.Series(np.where(signal.eq("BUY"), 1.0, -1.0), index=out.index)
    close = pd.to_numeric(out.get("close"), errors="coerce")
    out["entry_contract_protocol"] = ENTRY_PROTOCOL
    out["entry_contract_model"] = str(
        components.get("tactic_id") if isinstance(components, dict) else "composition"
    )
    out["entry_contract_mode"] = "frozen_strategy_tactic_pipeline"
    out["entry_contract_direction"] = signal.where(actionable, "WAIT")
    out["entry_context_valid"] = context_valid
    out["composition_mtf_required"] = requires_closed_mtf
    out["composition_mtf_ready"] = mtf_ready
    out["composition_h1_location_ready"] = h1_location_ready
    out["composition_m15_setup_ready"] = m15_setup_ready
    out["composition_m15_direction_valid"] = m15_direction_valid
    out["entry_location_valid"] = location_valid
    out["entry_setup_detected"] = setup
    out["entry_confirmation_valid"] = confirmation
    out["entry_trigger_valid"] = trigger
    out["entry_invalidation_valid"] = invalidation
    out["entry_reward_space_valid"] = reward
    out["entry_chase_valid"] = chase
    out["entry_event_valid"] = event
    out["composition_entry_accepted"] = event
    out["entry_reference_price"] = close.where(setup)
    out["entry_invalidation_reference_price"] = (close - direction_sign * atr).where(
        setup
    )
    # The central risk governor still owns executable stop distance. This
    # receipt proves an invalidation node was evaluated without replacing the
    # existing risk gene with a synthetic structure stop.
    out["trade_invalidation_price"] = np.nan
    out["entry_target_reference_price"] = (close + direction_sign * atr).where(setup)
    out["entry_trigger_anchor_price"] = close.where(trigger)
    out["entry_structure_atr"] = atr.where(setup)
    out["entry_reward_space_r"] = np.where(reward, 1.0, np.nan)
    out["entry_chase_distance_atr"] = np.where(trigger, 0.0, np.nan)
    out["entry_score"] = confidence
    out["entry_grade"] = np.where(event, "COMPOSITION_READY", "WAIT")
    out["entry_contract_status"] = np.select(
        [
            ~raw_opportunity,
            raw_opportunity & requires_closed_mtf & ~mtf_ready,
            raw_opportunity & ~context_valid,
            raw_opportunity & tactic_rejected,
            raw_opportunity & ~h1_location_ready,
            raw_opportunity & ~atr.gt(0),
            raw_opportunity & ~m15_setup_ready,
            raw_opportunity & ~m15_direction_valid,
            raw_opportunity & ~event,
        ],
        [
            "no_strategy_signal",
            "mtf_not_ready",
            "market_context_not_ready",
            "tactic_scope_mismatch",
            "h1_location_missing",
            "atr_invalid",
            "m15_setup_missing",
            "m15_direction_mismatch",
            "entry_gate_rejected",
        ],
        default="entry_ready",
    )
    out["entry_contract_stage"] = np.select(
        [~raw_opportunity, ~context_valid, ~location_valid, ~setup, ~confirmation, ~trigger, ~invalidation],
        ["idle", "context", "location", "setup", "confirmation", "trigger", "invalidation"],
        default="entry",
    )
    # The typed composition pipeline is executable authority, not telemetry.
    # A tactic-approved strategy signal reaches the risk governor only after
    # every declared pre-risk node has accepted the same candle.
    out["signal"] = signal.where(event, "WAIT")
    out.loc[~event, "signal_confidence"] = 0.0
    out["composition_strategy_base"] = str(strategy_binding.get("base_strategy") or "")
    return out


def build_composition_decision_receipts(
    frame: pd.DataFrame,
    contract: dict[str, Any] | None,
    *,
    execution_outcomes: dict[str, list[dict[str, Any]]] | None = None,
    sample_limit: int = DECISION_RECEIPT_SAMPLE_LIMIT,
    warmup_rows: int = 200,
) -> dict[str, Any]:
    """Retain every strategy opportunity plus a bounded inspection sample."""

    contract = dict(contract or {})
    program = compile_composition_program(contract)
    required_columns = {
        "composition_strategy_signal",
        "composition_decision_id",
        "composition_decision_stage",
        "composition_decision_reason",
        "composition_decision_accepted",
        "composition_preentry_stage_receipts",
        "composition_preentry_chain_hash",
    }
    if not required_columns.issubset(frame.columns):
        return {
            "protocol": DECISION_RECEIPT_PROTOCOL,
            "status": "missing_runtime_decision_rows",
            "program_hash": program["program_hash"],
            "decision_count": 0,
            "decision_digest": _hash([]),
            "samples": [],
            "node_activity": {},
            "promotion_evidence": False,
        }

    start = (
        max(0, min(max(0, int(warmup_rows) - 1), len(frame)))
        if int(warmup_rows) > 0
        else 0
    )
    stop = max(start, len(frame) - 1) if int(warmup_rows) > 0 else len(frame)
    scope = frame.iloc[start:stop]
    # The four arms use the same immutable dataset and execution contract.
    # This compact digest attests the entire candle universe; signal receipts
    # below identify the sparse non-no_signal subset without copying millions
    # of no-signal rows into every replay response.
    universe = hashlib.sha256()
    universe.update(b"composition_paired_opportunity_universe_v1\n")
    for ordinal, candle_time in enumerate(scope.get("time", pd.Series(scope.index, index=scope.index)).astype(str), start=start):
        universe.update(f"{ordinal}|{candle_time}\n".encode("utf-8"))
    opportunity_universe_hash = universe.hexdigest()
    action = scope["composition_strategy_signal"].astype(str).str.upper().isin(
        ["BUY", "SELL"]
    )
    selected = scope.loc[action]
    node_activity: dict[str, dict[str, Any]] = {}
    decisions_for_digest: list[dict[str, Any]] = []
    samples: list[dict[str, Any]] = []

    for position, row in selected.iterrows():
        decision_id = str(row.get("composition_decision_id") or "")
        candle = str(row.get("time", position))
        reason = str(row.get("composition_decision_reason") or "")
        stage = str(row.get("composition_decision_stage") or "")
        emitted_stages = row.get("composition_preentry_stage_receipts")
        emitted_hash = str(row.get("composition_preentry_chain_hash") or "")
        if (
            not isinstance(emitted_stages, list)
            or not emitted_stages
            or not all(isinstance(item, dict) for item in emitted_stages)
            or emitted_hash != _hash(emitted_stages)
        ):
            raise CompositionRuntimeContractError("COMPOSITION_PREENTRY_RECEIPT_INVALID")
        payload = {
            "decision_id": decision_id,
            "paired_context_id": _hash(["composition_paired_context_v1", int(row.get("composition_row_ordinal", position)), candle]),
            "composition_id": str(contract.get("composition_id") or ""),
            "contract_hash": str(contract.get("contract_hash") or ""),
            "manifest_hash": str(program["manifest_hash"]),
            "program_hash": str(program["program_hash"]),
            "candle": candle,
            "row_ordinal": int(row.get("composition_row_ordinal", position)),
            "strategy_signal": str(row.get("composition_strategy_signal") or "").upper(),
            "tactic_signal": str(row.get("composition_tactic_signal") or "").upper(),
            "market_regime": str(row.get("market_regime") or ""),
            "volatility_regime": str(row.get("volatility_regime") or ""),
            "terminal_stage": stage,
            "stage_receipts": emitted_stages,
            "preentry_chain_hash": emitted_hash,
            "preentry_accepted": bool(row.get("composition_decision_accepted", False)),
            "preentry_reason": reason,
            "accepted": bool(row.get("composition_decision_accepted", False)),
            "reason": reason,
        }
        runtime_stages = list((execution_outcomes or {}).get(decision_id, []))
        payload["runtime_stage_receipts"] = runtime_stages
        if payload["preentry_accepted"] and execution_outcomes is not None and not runtime_stages:
            payload["accepted"] = False
            payload["terminal_stage"] = "entry_model"
            payload["reason"] = "runtime_execution_not_reached"
        elif payload["preentry_accepted"] and runtime_stages:
            first_runtime_rejection = next(
                (
                    item
                    for item in runtime_stages
                    if isinstance(item, dict) and item.get("status") == "rejected"
                ),
                None,
            )
            if first_runtime_rejection is not None:
                payload["accepted"] = False
                payload["terminal_stage"] = str(first_runtime_rejection.get("module") or "")
                payload["reason"] = str(first_runtime_rejection.get("reason") or "runtime_gate_rejected")
            elif any(
                item.get("module") == "order_execution" and item.get("status") == "accepted"
                for item in runtime_stages
                if isinstance(item, dict)
            ):
                payload["accepted"] = True
                payload["terminal_stage"] = str(runtime_stages[-1].get("module") or "order_execution")
                payload["reason"] = ""
            else:
                payload["accepted"] = False
                payload["terminal_stage"] = str(runtime_stages[-1].get("module") or "")
                payload["reason"] = str(runtime_stages[-1].get("reason") or "runtime_execution_not_reached")
        for emitted_stage in emitted_stages:
            module = str(emitted_stage.get("module") or "")
            activity = node_activity.setdefault(
                module,
                {"evaluations": 0, "accepted": 0, "rejected": 0, "vetoes": {}},
            )
            activity["evaluations"] += 1
            disposition = str(emitted_stage.get("status") or "")
            if disposition == "accepted":
                activity["accepted"] += 1
            else:
                activity["rejected"] += 1
                veto = str(emitted_stage.get("reason") or "rejected_without_reason")
                activity["vetoes"][veto] = int(activity["vetoes"].get(veto, 0)) + 1
        payload["receipt_hash"] = _hash(payload)
        decisions_for_digest.append(payload)
        if len(samples) < max(0, int(sample_limit)):
            samples.append(payload)

    rejection_counts: dict[str, int] = {}
    for payload in decisions_for_digest:
        if not bool(payload["accepted"]):
            reason = str(payload["reason"] or "rejected_without_reason")
            rejection_counts[reason] = rejection_counts.get(reason, 0) + 1
    return {
        "protocol": DECISION_RECEIPT_PROTOCOL,
        "status": "observed" if decisions_for_digest else "no_strategy_opportunity",
        "execution_observed": execution_outcomes is not None,
        "program_hash": str(program["program_hash"]),
        "decision_count": len(decisions_for_digest),
        "opportunity_count": len(scope),
        "opportunity_universe_hash": opportunity_universe_hash,
        "no_signal_count": len(scope) - len(decisions_for_digest),
        "decision_digest": _hash(decisions_for_digest),
        "receipts": decisions_for_digest,
        "rejection_counts": dict(sorted(rejection_counts.items())),
        "node_activity": node_activity,
        "samples": samples,
        "sample_limit": max(0, int(sample_limit)),
        "promotion_evidence": False,
    }


def build_composition_execution_receipt(
    contract: dict[str, Any] | None,
    *,
    base_strategy: str | None,
    parameters: dict[str, Any] | None,
    rows: int,
    entry_funnel: dict[str, Any] | None,
    entry_contract_funnel: dict[str, Any] | None,
    management_evidence: dict[str, Any] | None,
    runtime_authority: dict[str, Any] | None,
    decision_receipts: dict[str, Any] | None = None,
) -> dict[str, Any]:
    contract = validate_composition_runtime_contract(
        contract,
        base_strategy=base_strategy,
        parameters=parameters,
        execution_timeframe=str((runtime_authority or {}).get("execution_timeframe") or ""),
        runtime_authority=runtime_authority,
    )
    if not contract:
        return {}
    program = compile_composition_program(contract)
    funnel = dict(entry_funnel or {})
    entry = dict(entry_contract_funnel or {})
    stages = entry.get("stage_counts") or {}
    if not isinstance(stages, dict):
        stages = {}
    decision_report = dict(decision_receipts or {})
    node_activity = decision_report.get("node_activity") or {}
    if not isinstance(node_activity, dict):
        node_activity = {}
    management = dict(management_evidence or {})
    accepted = int(funnel.get("accepted_entries", 0) or 0)
    observed_trades = int(management.get("observed_trades", 0) or 0)
    evaluated_candles = int(entry.get("evaluated_candles", rows) or 0)
    evaluations = {
        "instrument_context_gate": int(
            funnel.get("instrument_context_gate_evaluations", 0) or 0
        ),
        "mtf_permission_gate": int(
            funnel.get("mtf_permission_gate_evaluations", 0) or 0
        ),
        "mtf_context_gate": int(
            funnel.get("mtf_context_gate_evaluations", evaluated_candles) or 0
        ),
        "regime_detector": max(0, evaluated_candles),
        "strategy_runtime": int(
            funnel.get("strategy_runtime_evaluations", evaluated_candles) or 0
        ),
        "tactic_runtime": int(
            funnel.get("composition_tactic_evaluations", 0) or 0
        ),
        "location_model": int(stages.get("context", 0) or 0),
        "setup_model": int(stages.get("location", 0) or 0),
        "confirmation_engine": int(stages.get("setup", 0) or 0),
        "entry_model": int(stages.get("confirmation", 0) or 0),
        "invalidation_model": int(stages.get("trigger", 0) or 0),
        "central_risk_governor": int(
            funnel.get("risk_governor_evaluations", 0) or 0
        ),
        "management_policy": int(
            funnel.get("management_policy_evaluations", 0) or 0
        ),
        "order_execution": observed_trades,
    }
    for module, activity in node_activity.items():
        if module in evaluations and isinstance(activity, dict):
            if module not in {
                "mtf_context_gate",
                "regime_detector",
                "strategy_runtime",
            }:
                evaluations[module] = int(activity.get("evaluations", evaluations[module]) or 0)
    node_activity = dict(node_activity)
    for module, activity in {
        "instrument_context_gate": {
            "evaluations": evaluations["instrument_context_gate"],
            "accepted": int(funnel.get("instrument_context_gate_acceptances", 0) or 0),
            "rejected": int(funnel.get("instrument_context_gate_rejections", 0) or 0),
        },
        "mtf_permission_gate": {
            "evaluations": evaluations["mtf_permission_gate"],
            "accepted": int(funnel.get("mtf_permission_gate_acceptances", 0) or 0),
            "rejected": int(funnel.get("mtf_permission_gate_rejections", 0) or 0),
        },
        "central_risk_governor": {
            "evaluations": evaluations["central_risk_governor"],
            "accepted": int(funnel.get("risk_governor_approvals", 0) or 0),
            "rejected": int(funnel.get("risk_governor_rejections", 0) or 0),
        },
        "order_execution": {
            "evaluations": int(funnel.get("risk_governor_approvals", 0) or 0),
            "accepted": observed_trades,
            "rejected": max(0, int(funnel.get("risk_governor_approvals", 0) or 0) - observed_trades),
        },
        "management_policy": {
            "evaluations": evaluations["management_policy"],
            "accepted": observed_trades,
            "rejected": 0,
        },
    }.items():
        node_activity.setdefault(module, activity)
    evidence_sources = {
        "instrument_context_gate": "instrument_owner_scope.runtime_gate",
        "mtf_context_gate": "closed_mtf_snapshot.runtime_gate",
        "mtf_permission_gate": "h1_m15_m5_permission.runtime_gate",
        "regime_detector": "prepared_feature.market_regime",
        "strategy_runtime": "prepared_strategy.signal",
        "tactic_runtime": "frozen_tactic.scope_gate",
        "location_model": "entry_contract.stage.context",
        "setup_model": "entry_contract.stage.location",
        "confirmation_engine": "entry_contract.stage.setup",
        "entry_model": "entry_contract.stage.confirmation",
        "invalidation_model": "entry_contract.stage.trigger",
        "central_risk_governor": "entry_eligibility.runtime_decision",
        "order_execution": "backtester.fill_and_trade_ledger",
        "management_policy": "open_position.management_state_machine",
    }
    nodes = []
    declared_nodes = list(program["nodes"])
    seen_modules: set[str] = set()
    for raw_node in declared_nodes:
        if not isinstance(raw_node, dict) or not raw_node.get("module"):
            continue
        module = str(raw_node["module"])
        if module in seen_modules:
            continue
        seen_modules.add(module)
        count = int(evaluations.get(module, 0) or 0)
        node = {
            "module": module,
            "provides": str(raw_node.get("provides") or ""),
            "timeframe": str(raw_node.get("timeframe") or ""),
            "requires": list(raw_node.get("requires") or []),
            "evaluation_count": count,
            "accepted_count": int(
                (node_activity.get(module) or {}).get("accepted", 0)
            ),
            "rejected_count": int(
                (node_activity.get(module) or {}).get("rejected", 0)
            ),
            "veto_counts": dict((node_activity.get(module) or {}).get("vetoes") or {}),
            "manifest_hash": str(program["manifest_hash"]),
            "observed": count > 0,
            "status": "observed" if count > 0 else "not_reached",
            "evidence_source": str(evidence_sources.get(module) or "runtime_counter"),
            "program_hash": str(program["program_hash"]),
        }
        node["receipt_hash"] = _hash(
            {
                "composition_id": str(contract.get("composition_id") or ""),
                "contract_hash": str(contract.get("contract_hash") or ""),
                **node,
            }
        )
        nodes.append(node)
    receipt = {
        "protocol": RECEIPT_PROTOCOL,
        "composition_id": str(contract.get("composition_id") or ""),
        "contract_hash": str(contract.get("contract_hash") or ""),
        "program": program,
        "program_hash": str(program["program_hash"]),
        "base_strategy": str(base_strategy or ""),
        "entry_contract_protocol": str(entry.get("entry_contract_protocol") or ""),
        "component_bindings": {
            key: True for key in ("strategy", "tactic", "risk", "management")
        },
        "authority_bindings": {
            key: True for key in ("dataset", "execution", "instrument", "mtf")
        },
        "component_execution": {
            "strategy": {
                "evaluation_count": evaluated_candles,
                "signal_count": int(
                    funnel.get("composition_strategy_signals_before_tactic", 0) or 0
                ),
            },
            "tactic": {
                "evaluation_count": int(
                    funnel.get("composition_tactic_evaluations", 0) or 0
                ),
                "accepted_count": int(
                    funnel.get("composition_tactic_acceptances", 0) or 0
                ),
            },
            "risk": {
                "evaluation_count": int(
                    funnel.get("risk_governor_evaluations", 0) or 0
                ),
                "approved_count": int(
                    funnel.get("risk_governor_approvals", 0) or 0
                ),
                "rejected_count": int(
                    funnel.get("risk_governor_rejections", 0) or 0
                ),
            },
            "management": {
                "evaluation_count": int(
                    funnel.get("management_policy_evaluations", 0) or 0
                ),
                "completed_trade_count": observed_trades,
            },
        },
        "management_profile": str(
            _binding(contract.get("runtime_bindings") or {}, "management").get("profile")
            or ""
        ),
        "nodes": nodes,
        "decision_receipts": dict(decision_receipts or {}),
        "observations": {
            "rows": int(rows),
            "strategy_signals_before_tactic": int(
                funnel.get("composition_strategy_signals_before_tactic", 0) or 0
            ),
            "raw_strategy_signals": int(funnel.get("raw_strategy_signals", 0) or 0),
            "accepted_entries": accepted,
            "total_trades": observed_trades,
            "execution_completed": observed_trades > 0,
        },
        "paper_execution_authority": False,
        "promotion_evidence": False,
    }
    receipt["receipt_hash"] = _hash(receipt)
    return receipt


def build_composition_runtime_trace(
    contract: dict[str, Any] | None,
    *,
    base_strategy: str | None,
    parameters: dict[str, Any] | None,
    result: dict[str, Any] | None,
    runtime_authority: dict[str, Any] | None,
) -> dict[str, Any]:
    """Attest only the exact backtester receipt for the frozen passport."""

    contract = dict(contract or {})
    result = dict(result or {})
    if contract.get("protocol") != CONTRACT_PROTOCOL:
        empty = _empty("contract_missing_or_invalid")
        if contract:
            empty["status"] = "compile_failed"
            empty["compile_failed"] = True
        return empty
    try:
        validate_composition_runtime_contract(
            contract,
            base_strategy=base_strategy,
            parameters=parameters,
            execution_timeframe=str((runtime_authority or {}).get("execution_timeframe") or ""),
            runtime_authority=runtime_authority,
        )
    except CompositionRuntimeContractError as exc:
        empty = _empty(str(exc))
        empty["status"] = "compile_failed"
        empty["compile_failed"] = True
        return empty

    receipt = result.get("composition_runtime_receipt") or {}
    if not isinstance(receipt, dict) or receipt.get("protocol") != RECEIPT_PROTOCOL:
        empty = _empty("execution_receipt_missing")
        empty.update(
            {
                "status": "incomplete",
                "composition_id": str(contract.get("composition_id") or ""),
                "contract_hash_valid": True,
            }
        )
        return empty
    declared_receipt_hash = str(receipt.get("receipt_hash") or "")
    receipt_payload = dict(receipt)
    receipt_payload.pop("receipt_hash", None)
    receipt_valid = (
        str(receipt.get("composition_id") or "")
        == str(contract.get("composition_id") or "")
        and str(receipt.get("contract_hash") or "")
        == str(contract.get("contract_hash") or "")
        and str(receipt.get("base_strategy") or "") == str(base_strategy or "")
        and bool(declared_receipt_hash)
        and declared_receipt_hash == _hash(receipt_payload)
    )
    program = compile_composition_program(contract)
    receipt_program = receipt.get("program") or {}
    program_valid = (
        isinstance(receipt_program, dict)
        and str(receipt.get("program_hash") or "") == str(program["program_hash"])
        and receipt_program == program
    )
    nodes = [dict(node) for node in receipt.get("nodes", []) if isinstance(node, dict)]
    node_receipts_valid = (
        bool(nodes)
        and [str(node.get("module") or "") for node in nodes]
        == list(EXECUTABLE_NODE_ORDER)
        and all(
            str(node.get("program_hash") or "") == str(program["program_hash"])
            and _node_receipt_valid(node, contract)
            for node in nodes
        )
    )
    decision_report = receipt.get("decision_receipts") or {}
    decision_receipts_valid = _decision_receipts_valid(
        decision_report,
        contract=contract,
        program=program,
    )
    receipt_items = decision_report.get("receipts")
    if not isinstance(receipt_items, list):
        receipt_items = []
    witness_decision_id = next(
        (
            str(sample.get("decision_id") or "")
            for sample in receipt_items
            if decision_receipts_valid and _complete_decision_witness(sample)
        ),
        "",
    )
    complete_decision_witness = bool(witness_decision_id)
    component_bindings = dict(receipt.get("component_bindings") or {})
    bindings_valid = receipt_valid and all(
        component_bindings.get(key) is True
        for key in ("strategy", "tactic", "risk", "management")
    )
    authority_bindings = dict(receipt.get("authority_bindings") or {})
    authority_bindings_valid = receipt_valid and all(
        authority_bindings.get(key) is True
        for key in ("dataset", "execution", "instrument", "mtf")
    )
    required_nodes_observed = bool(nodes) and all(
        bool(node.get("observed")) for node in nodes
    )
    execution_completed = bool(
        (receipt.get("observations") or {}).get("execution_completed", False)
    )
    consumed = (
        receipt_valid
        and program_valid
        and node_receipts_valid
        and decision_receipts_valid
        and bindings_valid
        and authority_bindings_valid
        and required_nodes_observed
        and execution_completed
        and complete_decision_witness
    )
    return {
        "protocol": TRACE_PROTOCOL,
        "status": _organism_outcome_status(
            receipt=receipt,
            receipt_valid=receipt_valid and program_valid,
            node_receipts_valid=node_receipts_valid,
            decision_receipts_valid=decision_receipts_valid,
            consumed=consumed,
        ),
        "composition_id": str(contract.get("composition_id") or ""),
        "typed_program_id": str(contract.get("typed_program_id") or ""),
        "contract_hash": str(contract.get("contract_hash") or ""),
        "calculated_contract_hash": _hash(
            {key: value for key, value in contract.items() if key != "contract_hash"}
        ),
        "contract_hash_valid": True,
        "runtime_observed": receipt_valid and program_valid and node_receipts_valid,
        "execution_receipt_valid": (
            receipt_valid and program_valid and node_receipts_valid and decision_receipts_valid
        ),
        "program_compiled": True,
        "program_hash": str(program["program_hash"]),
        "program_valid": program_valid,
        "node_receipts_valid": node_receipts_valid,
        "decision_receipts_valid": decision_receipts_valid,
        "complete_decision_witness": complete_decision_witness,
        "witness_decision_id": witness_decision_id,
        "decision_receipts": dict(decision_report),
        "component_bindings": component_bindings,
        "component_bindings_valid": bindings_valid,
        "authority_bindings": authority_bindings,
        "authority_bindings_valid": authority_bindings_valid,
        "required_nodes_observed": required_nodes_observed,
        "execution_completed": execution_completed,
        "nodes": nodes,
        "component_execution": dict(receipt.get("component_execution") or {}),
        "observations": dict(receipt.get("observations") or {}),
        "unbound_components": [
            key
            for key in ("strategy", "tactic", "risk", "management")
            if component_bindings.get(key) is not True
        ],
        "paper_execution_authority": False,
        "promotion_evidence": False,
    }


def _binding(bindings: object, key: str) -> dict[str, Any]:
    value = bindings.get(key) if isinstance(bindings, dict) else {}
    return dict(value) if isinstance(value, dict) else {}


def _node_receipt_valid(node: dict[str, Any], contract: dict[str, Any]) -> bool:
    if str(node.get("manifest_hash") or "") != str(
        contract.get("contract_hash") or ""
    ):
        return False
    declared = str(node.get("receipt_hash") or "")
    payload = dict(node)
    payload.pop("receipt_hash", None)
    return bool(declared) and declared == _hash(
        {
            "composition_id": str(contract.get("composition_id") or ""),
            "contract_hash": str(contract.get("contract_hash") or ""),
            **payload,
        }
    )


def _decision_receipts_valid(
    report: object,
    *,
    contract: dict[str, Any],
    program: dict[str, Any],
) -> bool:
    if not isinstance(report, dict):
        return False
    samples = report.get("samples") or []
    if (
        report.get("protocol") != DECISION_RECEIPT_PROTOCOL
        or report.get("execution_observed") is not True
        or str(report.get("program_hash") or "") != str(program["program_hash"])
        or not isinstance(report.get("decision_count"), int)
        or int(report.get("decision_count", -1)) < 0
        or len(str(report.get("decision_digest") or "")) != 64
        or len(str(report.get("opportunity_universe_hash") or "")) != 64
        or not isinstance(report.get("opportunity_count"), int)
        or int(report.get("opportunity_count", -1)) < int(report.get("decision_count", 0))
        or report.get("no_signal_count") != report.get("opportunity_count") - report.get("decision_count")
        or not isinstance(samples, list)
    ):
        return False
    receipts = report.get("receipts")
    if (
        not isinstance(receipts, list)
        or len(receipts) != int(report.get("decision_count", -1))
        or str(report.get("decision_digest") or "") != _hash(receipts)
        or samples != receipts[: len(samples)]
        or len(samples) > DECISION_RECEIPT_SAMPLE_LIMIT
    ):
        return False
    receipt_ids: set[str] = set()
    pipeline_order = (
        "mtf_context_gate",
        "regime_detector",
        "strategy_runtime",
        "tactic_runtime",
        "location_model",
        "setup_model",
        "confirmation_engine",
        "entry_model",
        "invalidation_model",
    )
    for sample in receipts:
        if not isinstance(sample, dict):
            return False
        sample_payload = dict(sample)
        sample_hash = str(sample_payload.pop("receipt_hash", ""))
        expected_id = _hash(
            {
                "composition_id": str(contract.get("composition_id") or ""),
                "contract_hash": str(contract.get("contract_hash") or ""),
                "program_hash": str(program["program_hash"]),
                "candle": str(sample.get("candle") or ""),
                "row_ordinal": int(sample.get("row_ordinal", -1)),
            }
        )
        if (
            not sample_hash
            or sample_hash != _hash(sample_payload)
            or str(sample.get("decision_id") or "") != expected_id
            or str(sample.get("composition_id") or "")
            != str(contract.get("composition_id") or "")
            or str(sample.get("contract_hash") or "")
            != str(contract.get("contract_hash") or "")
            or str(sample.get("manifest_hash") or "")
            != str(program["manifest_hash"])
            or str(sample.get("program_hash") or "")
            != str(program["program_hash"])
            or str(sample.get("paired_context_id") or "")
            != _hash(["composition_paired_context_v1", int(sample.get("row_ordinal", -1)), str(sample.get("candle") or "")])
        ):
            return False
        stage_receipts = sample.get("stage_receipts")
        if not isinstance(stage_receipts, list) or not stage_receipts:
            return False
        if str(sample.get("preentry_chain_hash") or "") != _hash(stage_receipts):
            return False
        modules = [
            str(stage.get("module") or "")
            for stage in stage_receipts
            if isinstance(stage, dict)
        ]
        if (
            expected_id in receipt_ids
            or modules != list(pipeline_order[: len(modules)])
            or len(modules) != len(stage_receipts)
        ):
            return False
        if any(
            stage.get("status") not in {"accepted", "rejected"}
            or stage.get("decision_id") != expected_id
            or stage.get("candle") != sample.get("candle")
            or stage.get("manifest_hash") != program["manifest_hash"]
            or stage.get("program_hash") != program["program_hash"]
            for stage in stage_receipts
            if isinstance(stage, dict)
        ):
            return False
        if any(
            stage.get("status") != "accepted" or str(stage.get("reason") or "")
            for stage in stage_receipts[:-1]
        ):
            return False
        stage_accepted = all(stage["status"] == "accepted" for stage in stage_receipts)
        preentry_accepted = sample.get("preentry_accepted") is True
        if preentry_accepted != (stage_accepted and modules == list(pipeline_order)):
            return False
        if not stage_accepted:
            terminal_stage = stage_receipts[-1]
            if (
                terminal_stage.get("status") != "rejected"
                or str(terminal_stage.get("reason") or "")
                != str(sample.get("preentry_reason") or "")
            ):
                return False
        elif str(sample.get("preentry_reason") or ""):
            return False
        runtime_receipts = sample.get("runtime_stage_receipts")
        if not isinstance(runtime_receipts, list):
            return False
        runtime_order = (
            "entry_model",
            "instrument_context_gate",
            "mtf_permission_gate",
            "central_risk_governor",
            "order_execution",
            "management_policy",
        )
        runtime_modules = [
            str(stage.get("module") or "")
            for stage in runtime_receipts
            if isinstance(stage, dict)
        ]
        if len(runtime_modules) != len(runtime_receipts) or any(
            module not in runtime_order for module in runtime_modules
        ):
            return False
        positions = [runtime_order.index(module) for module in runtime_modules]
        if positions != sorted(set(positions)):
            return False
        if any(
            stage.get("status") not in {"accepted", "rejected"}
            or stage.get("decision_id") != expected_id
            or stage.get("candle") != sample.get("candle")
            or stage.get("manifest_hash") != program["manifest_hash"]
            or stage.get("program_hash") != program["program_hash"]
            for stage in runtime_receipts
            if isinstance(stage, dict)
        ):
            return False
        if any(
            stage.get("status") != "accepted" or str(stage.get("reason") or "")
            for stage in runtime_receipts[:-1]
        ):
            return False
        for stage in runtime_receipts:
            if stage.get("module") == "management_policy" and stage.get("status") == "accepted":
                if not all(str(stage.get(key) or "") for key in ("closed_at", "exit_reason", "trade_result")):
                    return False
        if runtime_receipts and runtime_receipts[-1].get("status") == "rejected":
            if (
                bool(sample.get("accepted"))
                or sample.get("terminal_stage") != runtime_receipts[-1].get("module")
                or sample.get("reason") != runtime_receipts[-1].get("reason")
                or not str(sample.get("reason") or "")
            ):
                return False
        elif runtime_receipts and not bool(sample.get("accepted")):
            if str(sample.get("reason") or "") != "runtime_execution_not_reached":
                return False
        if bool(sample.get("accepted")) and sample.get("preentry_accepted") is not True:
            return False
        if bool(sample.get("accepted")):
            if (
                "order_execution" not in runtime_modules
                or any(stage.get("status") != "accepted" for stage in runtime_receipts)
                or str(sample.get("reason") or "")
                or sample.get("terminal_stage") != runtime_receipts[-1].get("module")
            ):
                return False
        elif preentry_accepted and not runtime_receipts:
            if str(sample.get("reason") or "") != "runtime_execution_not_reached":
                return False
        elif not preentry_accepted:
            if runtime_receipts or sample.get("terminal_stage") != stage_receipts[-1].get("module"):
                return False
        receipt_ids.add(expected_id)
    ledger = report.get("execution_ledger") or {}
    if not isinstance(ledger, dict):
        return False
    ledger_digest = str(ledger.get("digest") or "")
    ledger_count = int(ledger.get("count", -1) or 0)
    if (
        ledger.get("protocol") != "composition_execution_decision_ledger_v1"
        or str(ledger.get("composition_id") or "")
        != str(contract.get("composition_id") or "")
        or str(ledger.get("contract_hash") or "")
        != str(contract.get("contract_hash") or "")
        or str(ledger.get("manifest_hash") or "")
        != str(program["manifest_hash"])
        or str(ledger.get("program_hash") or "") != str(program["program_hash"])
        or ledger_count < 0
        or (ledger_count > 0 and len(ledger_digest) != 64)
        or not isinstance(ledger.get("samples"), list)
    ):
        return False
    for event in ledger.get("samples", []):
        if (
            not isinstance(event, dict)
            or len(str(event.get("decision_id") or "")) != 64
            or not str(event.get("decision_candle") or "")
            or str(event.get("manifest_hash") or "")
            != str(program["manifest_hash"])
            or str(event.get("program_hash") or "") != str(program["program_hash"])
            or str(event.get("decision_id") or "") not in receipt_ids
        ):
            return False
    return True


def _complete_decision_witness(sample: object) -> bool:
    """A consumed organism needs one causal path, not unrelated node totals."""

    if not isinstance(sample, dict) or sample.get("preentry_accepted") is not True:
        return False
    if sample.get("accepted") is not True:
        return False
    preentry = sample.get("stage_receipts") or []
    runtime = sample.get("runtime_stage_receipts") or []
    return (
        [stage.get("module") for stage in preentry] == list(EXECUTABLE_NODE_ORDER[:9])
        and [stage.get("module") for stage in runtime]
        == list(EXECUTABLE_NODE_ORDER[9:])
        and all(stage.get("status") == "accepted" for stage in [*preentry, *runtime])
        and all(str(runtime[-1].get(key) or "") for key in ("closed_at", "exit_reason", "trade_result"))
    )


def _organism_outcome_status(
    *,
    receipt: dict[str, Any],
    receipt_valid: bool,
    node_receipts_valid: bool,
    decision_receipts_valid: bool,
    consumed: bool,
) -> str:
    if not receipt_valid or not node_receipts_valid or not decision_receipts_valid:
        return "incomplete"
    if consumed:
        return "consumed"
    observations = receipt.get("observations") or {}
    if int(observations.get("total_trades", 0) or 0) > 0:
        return "position_managed"
    management_reached = any(
        str(node.get("module") or "") == "management_policy"
        and int(node.get("evaluation_count", 0) or 0) > 0
        for node in receipt.get("nodes", [])
        if isinstance(node, dict)
    )
    if management_reached:
        return "position_managed"
    if int(observations.get("accepted_entries", 0) or 0) > 0:
        return "entry_authorized"
    if int(observations.get("strategy_signals_before_tactic", 0) or 0) > 0:
        return "rejected_at_node"
    return "valid_unactivated"


def _normalize_symbol(value: object) -> str:
    return str(value or "").upper().replace("/", "").replace("_", "").replace("-", "")


def _empty(reason: str) -> dict[str, Any]:
    return {
        "protocol": TRACE_PROTOCOL,
        "status": "not_applicable",
        "reason": reason,
        "component_bindings_valid": False,
        "required_nodes_observed": False,
        "nodes": [],
        "paper_execution_authority": False,
        "promotion_evidence": False,
    }


def _hash(value: Any) -> str:
    encoded = json.dumps(
        _canonicalize(value), sort_keys=True, separators=(",", ":"), ensure_ascii=False
    )
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _canonicalize(value: Any) -> Any:
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
