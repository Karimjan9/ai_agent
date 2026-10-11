"""Private admission for original scoped research on authenticated future CSVs."""

from copy import deepcopy
from dataclasses import dataclass, replace
import hashlib
import json
import math
import re

import pandas as pd

from app.schemas import ExecutionConfig, StrategyRuntimeConfig

MANIFEST_PROTOCOL = "authorized_scoped_original_window_bundle_v1"
DESCRIPTOR_PROTOCOL = "scoped_original_window_v1"
PURPOSES = frozenset({"independent_scoped_component_research",
    "independent_scoped_descendant_research", "independent_scoped_selector_research"})
_ISSUER = object()
_WITNESS_ATTR = "_verified_scoped_research_runtime"


def _wire_json(value):
    from app.services.research_release import _release_json

    def wire(item):
        if isinstance(item, float):
            if not math.isfinite(item):
                raise ValueError("SCOPED_ORIGINAL_RUNTIME_NONFINITE")
            return int(item) if item.is_integer() else item
        if isinstance(item, dict):
            return {key: wire(child) for key, child in item.items()}
        if isinstance(item, (list, tuple)):
            return [wire(child) for child in item]
        return item

    return _release_json(wire(value))


def _hash(value):
    return hashlib.sha256(_wire_json(value).encode()).hexdigest()


@dataclass(frozen=True)
class _ScopedRuntimeWitness:
    issuer: object
    dataset_hash: str
    execution_hash: str
    release_hash: str
    declaration_hash: str
    maturity_hash: str
    manifest_hash: str
    shared_json: str
    arms_json: str
    files_json: str
    confirmation_hash: str
    transport_hash: str
    full_policy_hash: str
    partition_json: str | None = None

    def __post_init__(self):
        if self.issuer is not _ISSUER:
            raise TypeError("SCOPED_ORIGINAL_RUNTIME_ISSUER_REQUIRED")

    def __deepcopy__(self, memo):
        memo[id(self)] = self
        return self


def _signed_json(descriptor, field):
    raw = descriptor.get(field + "_json")
    if not isinstance(raw, str):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_JSON_REQUIRED")
    try:
        value = json.loads(raw)
    except (TypeError, ValueError) as error:
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_JSON_INVALID") from error
    if _wire_json(value) != raw or _hash(value) != descriptor.get(field + "_hash"):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_JSON_IDENTITY_INVALID")
    return value


def _shared(payload):
    return {"initial_balance": payload.initial_balance, "risk_per_trade": payload.risk_per_trade,
        "execution": payload.execution.model_dump(mode="json"), "execution_contract": payload.execution_contract,
        "mtf_pilot": payload.mtf_pilot, "volume_context": payload.volume_context}


def _validate_descriptor(payload, transport):
    declaration = (payload.policy_context or {}).get("scoped_research_certificate")
    descriptor = transport.get("scoped_original_window")
    fence = (payload.policy_context or {}).get("scoped_position_maturity_fence")
    if (not isinstance(descriptor, dict) or descriptor.get("protocol") != DESCRIPTOR_PROTOCOL
            or not isinstance(declaration, dict) or declaration.get("purpose") not in PURPOSES
            or descriptor.get("purpose") != declaration.get("purpose")
            or type(descriptor.get("certificate_id")) is not int or descriptor["certificate_id"] <= 0
            or descriptor["certificate_id"] != declaration.get("certificate_id")
            or not re.fullmatch(r"[a-f0-9]{64}", str(descriptor.get("design_hash") or ""))
            or descriptor["design_hash"] != declaration.get("design_hash")
            or declaration.get("window_key") != transport.get("window", {}).get("window_key")
            or descriptor.get("declaration_hash") != _hash(declaration)
            or descriptor.get("manifest_hash") != _hash(payload.mtf_snapshot_manifest)
            or descriptor.get("execution_hash") != payload.execution_contract.get("execution_hash")
            or descriptor.get("maturity_fence_hash") != _hash(fence)
            or descriptor.get("confirmation_contracts_hash") != _hash(
                (payload.policy_context or {}).get("learning_confirmation_contracts", []))
            or descriptor.get("full_replay_runtime_policy_hash") != _hash(
                (payload.policy_context or {}).get("full_replay_runtime_policy", []))):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_DESCRIPTOR_INVALID")
    # Validate the real fence contract, including its exact UTC duration.
    from app.services.backtester import _scoped_maturity_entry_end
    if _scoped_maturity_entry_end(payload) is None:
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_MATURITY_REQUIRED")
    if fence["end_exclusive"] != transport["window"]["end_exclusive"]:
        if pd.Timestamp(fence["end_exclusive"]) != pd.Timestamp(transport["window"]["end_exclusive"]):
            raise ValueError("SCOPED_ORIGINAL_RUNTIME_MATURITY_WINDOW_MISMATCH")
    raw_arms = _signed_json(descriptor, "strategies")
    if not isinstance(raw_arms, list) or not raw_arms:
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_ARMS_REQUIRED")
    arms = [StrategyRuntimeConfig.model_validate(arm).model_dump(mode="json") for arm in raw_arms]
    if _wire_json(arms) != _wire_json([arm.model_dump(mode="json") for arm in payload.strategies]):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_ARM_DRIFT")
    ids = [str(arm.get("lab_agent_id")) for arm in arms]
    if any(not value.isdecimal() or int(value) <= 0 for value in ids) or len(set(ids)) != len(ids):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_ARM_IDENTITY_INVALID")
    raw_shared = _signed_json(descriptor, "shared_runtime")
    required_shared = {"initial_balance", "risk_per_trade", "execution", "execution_contract"}
    if (not isinstance(raw_shared, dict) or not required_shared <= set(raw_shared)
            or set(raw_shared) - set(_shared(payload))):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_SHARED_REQUIRED")
    shared = {"mtf_pilot": {}, "volume_context": {}, **raw_shared,
        "execution": ExecutionConfig.model_validate(raw_shared["execution"]).model_dump(mode="json")}
    if _wire_json(shared) != _wire_json(_shared(payload)):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_SHARED_DRIFT")
    if set(transport.get("files", {})) != {"M5", "M15", "H1", "H4"}:
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_FOUR_STREAMS_REQUIRED")
    return descriptor, arms, shared


def bind_scoped_research_runtime(payload, verified_transport):
    """Called after real HMAC/file verification; JSON cannot supply the issuer."""
    manifest = payload.mtf_snapshot_manifest or {}
    declared = manifest.get("validation_bundle_protocol") == MANIFEST_PROTOCOL
    if not declared and not (verified_transport or {}).get("scoped_original_window"):
        return payload
    from app.services.research_release import authenticated_research_transport_current
    if (not declared or payload.evaluation_mode != "full" or payload.timeframe != "M5"
            or manifest.get("protocol") != "closed_h4_h1_m15_m5_snapshot_v1"
            or not authenticated_research_transport_current(verified_transport)
            or verified_transport.get("dataset_hash") != payload.replay_dataset_hash
            or verified_transport.get("release_hash") != (payload.research_release or {}).get("release_hash")):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_AUTHENTICATED_TRANSPORT_REQUIRED")
    existing = getattr(payload, _WITNESS_ATTR, None)
    if (isinstance(existing, _ScopedRuntimeWitness) and existing.issuer is _ISSUER
            and existing.transport_hash == verified_transport.get("contract_hash")):
        scoped_research_runtime_current(payload)
        return payload
    descriptor, arms, shared = _validate_descriptor(payload, verified_transport)
    witness = _ScopedRuntimeWitness(_ISSUER, payload.replay_dataset_hash, descriptor["execution_hash"],
        verified_transport["release_hash"], descriptor["declaration_hash"], descriptor["maturity_fence_hash"],
        descriptor["manifest_hash"], _wire_json(shared), _wire_json(arms), _wire_json(verified_transport["files"]),
        descriptor["confirmation_contracts_hash"], verified_transport["contract_hash"],
        descriptor["full_replay_runtime_policy_hash"])
    object.__setattr__(payload, _WITNESS_ATTR, witness)
    scoped_research_runtime_current(payload)
    return payload


def _arm_current(payload, expected):
    if (payload.strategy != expected["strategy"] or payload.base_strategy != expected.get("base_strategy")
            or payload.version != expected.get("version")
            or _wire_json(payload.instrument_research_assignment) != _wire_json(expected["instrument_research_assignment"])
            or _wire_json(payload.composition_runtime_contract) != _wire_json(expected["composition_runtime_contract"])
            or _wire_json(payload.specialist_context_contract) != _wire_json(expected["specialist_context_contract"])
            or _wire_json(payload.specialist_council_contract) != _wire_json(expected["specialist_council_contract"])):
        return False
    signed_parameters = expected["parameters"]
    if _wire_json(payload.parameters) == _wire_json(signed_parameters):
        return True
    # The real cohort owner validates before making the per-arm copy. Admit
    # only that exact, code-sealed canonical form, not arbitrary added keys or
    # an execution overlay. No explicitly signed gene may change its value.
    if expected.get("specialist_council_contract"):
        return False
    from app.services.parameter_schema import validate_strategy_parameters
    canonical = validate_strategy_parameters(expected["strategy"], signed_parameters, expected.get("base_strategy"))
    if any(key not in canonical or _wire_json(canonical[key]) != _wire_json(value)
            for key, value in signed_parameters.items()):
        return False
    return _wire_json(payload.parameters) == _wire_json(canonical)


def scoped_research_runtime_current(payload, frame=None):
    """Verify the internal witness and the exact request/slice, never a claim flag."""
    if (payload.mtf_snapshot_manifest or {}).get("validation_bundle_protocol") != MANIFEST_PROTOCOL:
        return False
    witness = getattr(payload, _WITNESS_ATTR, None)
    if not isinstance(witness, _ScopedRuntimeWitness) or witness.issuer is not _ISSUER:
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_PRIVATE_WITNESS_REQUIRED")
    context = payload.policy_context or {}
    if (payload.evaluation_mode != "full" or payload.timeframe != "M5"
            or payload.replay_dataset_hash != witness.dataset_hash
            or payload.execution_contract.get("execution_hash") != witness.execution_hash
            or (payload.research_release or {}).get("release_hash") != witness.release_hash
            or _hash(payload.mtf_snapshot_manifest) != witness.manifest_hash
            or _hash(context.get("scoped_research_certificate")) != witness.declaration_hash
            or _hash(context.get("scoped_position_maturity_fence")) != witness.maturity_hash
            or _hash(context.get("learning_confirmation_contracts", [])) != witness.confirmation_hash
            or _hash(context.get("full_replay_runtime_policy", [])) != witness.full_policy_hash
            or _wire_json(_shared(payload)) != witness.shared_json):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_REQUEST_DRIFT")
    arms = json.loads(witness.arms_json)
    if payload.strategy == "all":
        current = [arm.model_dump(mode="json") for arm in payload.strategies]
        if _wire_json(current) != witness.arms_json:
            raise ValueError("SCOPED_ORIGINAL_RUNTIME_ARM_DRIFT")
    elif not any(_arm_current(payload, expected) for expected in arms):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_ARM_DRIFT")
    from app.services.research_release import _transport_path
    records = json.loads(witness.files_json)
    requested = {"M5": payload.dataset_path, **payload.mtf_dataset_paths}
    for stream, record in records.items():
        path = _transport_path(requested.get(stream))
        if path != _transport_path(record["path"]):
            raise ValueError("SCOPED_ORIGINAL_RUNTIME_SOURCE_PATH_DRIFT")
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for block in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(block)
        if digest.hexdigest() != record["sha256"]:
            raise ValueError("SCOPED_ORIGINAL_RUNTIME_SOURCE_HASH_DRIFT")
    if frame is not None:
        from app.services.backtester import _consumed_dataset_attestation
        actual = _consumed_dataset_attestation(payload, frame)
        primary = records["M5"]
        times = pd.to_datetime(frame["time"], utc=True, errors="coerce")
        if (actual.get("status") != "verified" or actual.get("actual_source_sha256") != primary["sha256"]
                or actual.get("consumed_rows") != len(frame) or len(frame) < 2
                or times.isna().any() or not times.is_unique or not times.is_monotonic_increasing
                or (times < pd.Timestamp(primary["start_inclusive"])).any()
                or (times > pd.Timestamp(primary["last_candle_at"])).any()):
            raise ValueError("SCOPED_ORIGINAL_RUNTIME_CONSUMED_SOURCE_DRIFT")
        if witness.partition_json is not None:
            partition = json.loads(witness.partition_json)
            if (actual.get("consumed_data_hash") != partition["consumed_data_hash"]
                    or len(frame) != partition["rows"]
                    or times.iloc[0].isoformat() != partition["first_candle_at"]
                    or times.iloc[-1].isoformat() != partition["last_candle_at"]):
                raise ValueError("SCOPED_ORIGINAL_RUNTIME_PARTITION_DRIFT")
    return True


def derive_scoped_partition_payload(payload, frame):
    """The native producer derives a narrower entry fence from its actual sealed slice."""
    if not scoped_research_runtime_current(payload, frame):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_PRIVATE_WITNESS_REQUIRED")
    from app.services.backtester import _consumed_dataset_attestation
    actual = _consumed_dataset_attestation(payload, frame)
    witness = getattr(payload, _WITNESS_ATTR)
    context = deepcopy(payload.policy_context)
    fence = dict(context["scoped_position_maturity_fence"])
    last = pd.Timestamp(frame["time"].iloc[-1])
    end = last + pd.Timedelta(seconds=300)
    entry_end = end - pd.Timedelta(seconds=fence["holding_fence_seconds"])
    if end > pd.Timestamp(fence["end_exclusive"]) or entry_end > pd.Timestamp(fence["entry_end_exclusive"]):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_PARTITION_FENCE_EXPANDS_SCOPE")
    fence.update(end_exclusive=end.isoformat(), entry_end_exclusive=entry_end.isoformat())
    context["scoped_position_maturity_fence"] = fence
    clone = payload.model_copy(update={"policy_context": context})
    partition = {"consumed_data_hash": actual["consumed_data_hash"], "rows": len(frame),
        "first_candle_at": pd.Timestamp(frame["time"].iloc[0]).isoformat(), "last_candle_at": last.isoformat()}
    derived = replace(witness, maturity_hash=_hash(fence), partition_json=_wire_json(partition))
    object.__setattr__(clone, _WITNESS_ATTR, derived)
    scoped_research_runtime_current(clone, frame)
    return clone


def scoped_research_quote_calendar(frame, payload):
    """Only an authenticated actual source slice may extend the quote calendar."""
    if not scoped_research_runtime_current(payload, frame):
        raise ValueError("SCOPED_ORIGINAL_RUNTIME_PRIVATE_WITNESS_REQUIRED")
    witness = getattr(payload, _WITNESS_ATTR)
    record = json.loads(witness.files_json)["M5"]
    times = pd.to_datetime(frame["time"], utc=True, errors="coerce")
    return times.ge(pd.Timestamp(record["start_inclusive"])) & times.le(pd.Timestamp(record["last_candle_at"]))
