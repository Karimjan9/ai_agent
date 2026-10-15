"""Finite native-member classifier with an actual shared-account binding.

The original PHP issuer owns training and prospective attachment. This consumer
rederives the complete ordered account programme from the actual parent request;
an ordinary single-position model or a member-shaped child is not its proof.
"""

from __future__ import annotations

import copy
from datetime import date

from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_meta_model import FiniteInstrumentMetaModel
from app.services.research_program_tasks import canonical_hash, preserved_contract_body, _same_ast_copy


PROTOCOL = "instrument_method_native_meta_model_v1"
SCOPE_PROTOCOL = "instrument_method_native_physical_scope_v1"
BINDING_FIELDS = {"symbol", "timeframe", "member_physical_hash", "council_physical_hash",
                  "effective_policy_hash", "data_hash", "execution_hash", "source_hash",
                  "python_source_hash", "clock_seconds"}
PHYSICAL_FIELDS = {"symbol", "timeframe", "member_physical_hash", "council_physical_hash",
                   "effective_policy_hash", "execution_hash", "clock_seconds"}
EVALUATION_FIELDS = PHYSICAL_FIELDS | {"effective_policy_hash"}
NONEXECUTION_CONTEXT_FIELDS = {"trial_ledger"}
PARENT_RUNTIME_PORT_DEFAULTS = {"signal_delay_candles": 0, "random_seed": 42,
    "from_date": None, "to_date": None, "dataset_tail_rows": None, "regime_dataset_tail_rows": None,
    "mtf_dataset_tail_rows": {}, "related_mtf_dataset_tail_rows": {}, "mtf_pilot": {},
    "volume_context": {}, "runtime_ensemble_policy": {}, "native_spread_context_study_contract": {},
    "native_reachability_depth_audit_contract": {}, "native_standalone_qualification": {},
    "mtf_snapshot_manifest": {}}


class FiniteNativeInstrumentMetaModel(FiniteInstrumentMetaModel):
    """Same bounded numeric algorithm, separately issued native scope only."""

    protocol = PROTOCOL
    binding_fields = BINDING_FIELDS
    physical_fields = PHYSICAL_FIELDS
    token_fields = {"symbol", "timeframe"}


def _php_arrays(value):
    if isinstance(value, dict):
        return {key: _php_arrays(item) for key, item in value.items()} if value else []
    if isinstance(value, list):
        return [_php_arrays(item) for item in value]
    return value


def member_physical_scope(member: dict) -> dict:
    return {key: copy.deepcopy(value) for key, value in member.items()
            if key not in {"model_version_id", "passport_hash", "version", "method_learning"}}


def _parent_runtime_ports(request: dict) -> dict:
    result = {}
    for field, default in PARENT_RUNTIME_PORT_DEFAULTS.items():
        value = request.get(field, copy.deepcopy(default))
        if field in {'from_date', 'to_date'}:
            if isinstance(value, date):
                value = value.isoformat()
            if value is not None:
                try:
                    if not isinstance(value, str) or date.fromisoformat(value).isoformat() != value:
                        raise ValueError
                except (ValueError, TypeError) as error:
                    raise ValueError('METHOD_NATIVE_META_PARENT_CALENDAR_PORT_REQUIRED') from error
        elif isinstance(default, dict):
            if not isinstance(value, dict):
                raise ValueError('METHOD_NATIVE_META_PARENT_RUNTIME_OBJECT_REQUIRED')
        elif default is None:
            if value is not None and (type(value) is not int or value < 2):
                raise ValueError('METHOD_NATIVE_META_PARENT_ROW_BOUND_REQUIRED')
        elif type(value) is not int or field == 'signal_delay_candles' and not 0 <= value <= 3:
            raise ValueError('METHOD_NATIVE_META_PARENT_INTEGER_PORT_REQUIRED')
        result[field] = value
    return result


def actual_native_meta_evaluation_binding(parent_payload, council_contract: dict, member: dict) -> dict:
    if parent_payload is None or not hasattr(parent_payload, "model_dump"):
        raise ValueError("METHOD_NATIVE_META_ACTUAL_PARENT_REQUEST_REQUIRED")
    actual = parent_payload.specialist_council_contract
    if (not isinstance(actual, dict) or actual.get("protocol") != "specialist_council_runtime_v1"
            or not isinstance(council_contract, dict)):
        raise ValueError("METHOD_NATIVE_META_ORIGINAL_COUNCIL_REQUIRED")
    body = preserved_contract_body(actual, "SPECIALIST_COUNCIL")
    if (canonical_hash(body) != actual.get("contract_hash")
            or council_contract.get("contract_hash") != actual.get("contract_hash")
            or not _same_ast_copy(body, preserved_contract_body(council_contract, "SPECIALIST_COUNCIL"))):
        raise ValueError("METHOD_NATIVE_META_ORIGINAL_COUNCIL_HASH_INVALID")
    upgrades = body.get('upgrades', [])
    if not isinstance(upgrades, list) or upgrades:
        raise ValueError("METHOD_NATIVE_META_VERSIONED_UPGRADE_UNSUPPORTED")
    members = body.get("members")
    if (not isinstance(members, list) or not 1 <= len(members) <= 32
            or any(not isinstance(candidate, dict) or not isinstance(candidate.get('specialist_id'), str)
                   or not candidate['specialist_id'] for candidate in members)
            or len({candidate['specialist_id'] for candidate in members}) != len(members)
            or not isinstance(member, dict)
            or sum(_same_ast_copy(candidate, member) for candidate in members) != 1):
        raise ValueError("METHOD_NATIVE_META_ORIGINAL_MEMBER_REQUIRED")
    seconds = {"M1": 60, "M5": 300, "M15": 900, "M30": 1800, "H1": 3600,
               "H4": 14400, "D1": 86400}.get(parent_payload.timeframe)
    if seconds is None:
        raise ValueError("METHOD_NATIVE_META_PHYSICAL_CLOCK_REQUIRED")
    execution = execution_contract_metadata(parent_payload)
    if (execution["status"] == "mismatch" or body.get("execution_hash") != execution["execution_hash"]
            or body.get("execution_timeframe") != parent_payload.timeframe
            or body.get("replay_dataset_hash") != parent_payload.replay_dataset_hash):
        raise ValueError("METHOD_NATIVE_META_ACTUAL_ACCOUNT_EXECUTION_MISMATCH")
    request = parent_payload.model_dump(mode="python")
    request_context = request.get('policy_context', {})
    if not isinstance(request_context, dict):
        raise ValueError('METHOD_NATIVE_META_POLICY_CONTEXT_OBJECT_REQUIRED')
    context = {key: value for key, value in request_context.items()
               if key != "instrument_method_learning" and key not in NONEXECUTION_CONTEXT_FIELDS}
    council = {key: copy.deepcopy(value) for key, value in body.items()
               if key not in {"council_id", "council_version", "manifest_hash", "version_id", "members",
                              "replay_dataset_hash"}}
    council["members"] = [member_physical_scope(candidate) for candidate in members]
    hard_limits = request.get('hard_limits')
    if hard_limits is None:
        hard_limits = context.get('hard_limits')
    if hard_limits is not None and not isinstance(hard_limits, dict) and hard_limits != []:
        raise ValueError('METHOD_NATIVE_META_HARD_LIMIT_OBJECT_REQUIRED')
    mode = request.get('evaluation_mode', 'full')
    if mode not in {'incremental', 'full', 'replay', 'temporal_ablation'}:
        raise ValueError('METHOD_NATIVE_META_EVALUATION_MODE_REQUIRED')
    physical = {"protocol": SCOPE_PROTOCOL, "symbol": parent_payload.symbol,
        "timeframe": parent_payload.timeframe, "clock_seconds": seconds,
        "evaluation_mode": mode,
        "initial_balance": float(parent_payload.initial_balance), "risk_per_trade": float(parent_payload.risk_per_trade),
        "hard_limits": hard_limits,
        "parent_runtime_ports": _parent_runtime_ports(request),
        "policy_context": context, "council": council}
    policies = []
    for candidate in members:
        method = candidate.get("method_learning")
        if method is not None and not isinstance(method, dict):
            raise ValueError('METHOD_NATIVE_META_METHOD_OBJECT_REQUIRED')
        if (candidate['specialist_id'] != member['specialist_id'] and isinstance(method, dict)
                and 'meta_model' in method):
            # This version owns one learned subject. Another predictor changes
            # the shared account programme even if its selector is unchanged.
            # Do not silently erase peer weights to make a transplant match.
            raise ValueError('METHOD_NATIVE_META_PEER_MODEL_SCOPE_UNSUPPORTED')
        policy = method.get('policy') if method is not None else None
        if method is not None and (not isinstance(policy, dict) or not isinstance(policy.get('rules'), list)
                                   or policy.get('default_action') not in {'BASELINE', 'WAIT'}):
            raise ValueError('METHOD_NATIVE_META_MEMBER_POLICY_OBJECT_REQUIRED')
        policy = policy or {}
        policies.append({"specialist_id": candidate["specialist_id"], "method": None if method is None else {
            "library_hash": method.get("library_hash"), "compiled_pool_hash": method.get("compiled_pool_hash"),
            "scope": method.get("scope"), "strategy_family": method.get("strategy_family"),
            "rules": policy.get("rules"), "default_action": policy.get("default_action")}})
    return {"symbol": parent_payload.symbol, "timeframe": parent_payload.timeframe,
        "member_physical_hash": canonical_hash(_php_arrays(member_physical_scope(member))),
        "council_physical_hash": canonical_hash(_php_arrays(physical)),
        "effective_policy_hash": canonical_hash(_php_arrays(policies)),
        "execution_hash": execution["execution_hash"], "clock_seconds": seconds}
