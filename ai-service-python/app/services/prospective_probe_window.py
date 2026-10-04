"""Attest the exact discovery slice; never infer independent validation."""

from __future__ import annotations

import hashlib
import json

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import _canonical_json, _semantic_contract_value


PROTOCOL = "prospective_repair_probe_window_v1"
EVALUATOR = "incremental_probe_window_v2"


def assert_clean_discovery_boundary(payload: SimpleBacktestRequest) -> None:
    """Validate one typed discovery input at both API and MTF runtime admission.

    This shared guard is deliberately independent of the API orchestration so
    direct feature/replay callers cannot skip the same discovery-only policy.
    A valid scope remains neither full nor independent evidence.
    """
    manifest = dict(payload.mtf_snapshot_manifest or {})
    kind = manifest.get("validation_bundle_protocol")
    if kind != "prospective_clean_discovery_bundle_v1" and manifest.get("data_role") != "pre_2026_discovery_only":
        return
    if payload.evaluation_mode != "incremental":
        raise ValueError("DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN")
    scope = manifest.get("discovery_scope")
    policy = dict(payload.policy_context or {})
    probe = policy.get("prospective_probe_window")
    if (kind != "prospective_clean_discovery_bundle_v1"
            or manifest.get("data_role") != "pre_2026_discovery_only"
            or not isinstance(scope, dict)
            or scope.get("protocol") != "prospective_clean_discovery_scope_v1"
            or policy.get("prospective_clean_discovery_scope") != scope
            or not isinstance(probe, dict)):
        raise ValueError("DISCOVERY_SCOPE_OR_PROBE_MISSING")
    for key in ("independent_evidence", "full_validation_eligible", "paper_eligible", "promotion_evidence"):
        if manifest.get(key) is not False or scope.get(key) is not False:
            raise ValueError("DISCOVERY_EVIDENCE_BOUNDARY_INVALID")
    body = {key: value for key, value in scope.items() if key != "scope_hash"}
    if scope.get("scope_hash") != hashlib.sha256(_canonical_json(_semantic_contract_value(body)).encode("utf-8")).hexdigest():
        raise ValueError("DISCOVERY_SCOPE_HASH_MISMATCH")
    calendar = scope.get("calendar")
    if (not isinstance(calendar, dict) or calendar.get("selected_unexpected_gaps") != 0
            or payload.dataset_tail_rows is not None
            or policy.get("historical_stratified_windows")
            or manifest.get("bundle_hash") != payload.replay_dataset_hash
            or probe.get("protocol") != PROTOCOL
            or probe.get("evaluator_version") != EVALUATOR
            or probe.get("dataset_hash") != payload.replay_dataset_hash
            or probe.get("execution_hash") != (payload.execution_contract or {}).get("execution_hash")
            or probe.get("independent_validation") is not False
            or probe.get("paper_2026_eligible") is not False):
        raise ValueError("DISCOVERY_REPLAY_CONTRACT_MISMATCH")
    for key in ("loaded_rows", "warmup_rows", "evaluated_rows", "loaded_start", "loaded_end",
                "evaluated_start", "evaluated_end", "evaluated_month_counts"):
        if probe.get(key) != calendar.get(key):
            raise ValueError("DISCOVERY_PROBE_SCOPE_MISMATCH")
    if (type(probe.get("evaluated_rows")) is not int or probe["evaluated_rows"] != 15000
            or type(probe.get("warmup_rows")) is not int or probe["warmup_rows"] != 512
            or type(probe.get("loaded_rows")) is not int or probe["loaded_rows"] != 15512):
        raise ValueError("DISCOVERY_ROW_BUDGET_INVALID")
    probe_body = {key: value for key, value in probe.items() if key != "contract_hash"}
    expected_probe = hashlib.sha256(json.dumps(probe_body, separators=(",", ":"), ensure_ascii=False).encode("utf-8")).hexdigest()
    if probe.get("contract_hash") != expected_probe:
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_HASH_MISMATCH")


def select_probe_window(
    loaded: pd.DataFrame,
    contract: dict[str, object],
    dataset_hash: str | None,
    execution_hash: str | None,
) -> tuple[pd.DataFrame, dict[str, object]]:
    if contract.get("protocol") != PROTOCOL or contract.get("evaluator_version") != EVALUATOR:
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_PROTOCOL_MISMATCH")
    body = {key: value for key, value in contract.items() if key != "contract_hash"}
    expected_hash = hashlib.sha256(
        json.dumps(body, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    ).hexdigest()
    if contract.get("contract_hash") != expected_hash:
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_HASH_MISMATCH")
    if (contract.get("dataset_hash") != dataset_hash
            or contract.get("execution_hash") != execution_hash
            or not contract.get("experiment_key")
            or contract.get("independent_validation") is not False
            or contract.get("paper_2026_eligible") is not False):
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_IDENTITY_MISMATCH")
    warmup = contract.get("warmup_rows")
    evaluated = contract.get("evaluated_rows")
    if (type(warmup) is not int or type(evaluated) is not int
            or warmup < 0 or evaluated < 2 or len(loaded) != warmup + evaluated
            or contract.get("loaded_rows") != len(loaded)):
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_ROW_BUDGET_MISMATCH")
    times = pd.to_datetime(loaded["time"], utc=True, errors="coerce")
    if times.isna().any() or not times.is_monotonic_increasing or times.duplicated().any():
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_TIME_INVALID")
    if (times >= pd.Timestamp("2026-01-01T00:00:00Z")).any():
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_PAPER_DATA_FORBIDDEN")

    def utc(value: pd.Timestamp) -> str:
        return value.strftime("%Y-%m-%dT%H:%M:%SZ")

    evaluation_times = times.iloc[warmup:]
    months: dict[str, int] = {}
    for time in evaluation_times:
        key = time.strftime("%Y-%m")
        months[key] = months.get(key, 0) + 1
    observed = {
        "loaded_start": utc(times.iloc[0]),
        "loaded_end": utc(times.iloc[-1]),
        "evaluated_start": utc(evaluation_times.iloc[0]),
        "evaluated_end": utc(evaluation_times.iloc[-1]),
        "evaluated_month_counts": months,
    }
    if any(contract.get(key) != value for key, value in observed.items()):
        raise ValueError("PROSPECTIVE_PROBE_WINDOW_BOUNDS_MISMATCH")
    receipt = {**contract, "complete": True}
    return loaded.iloc[warmup:].reset_index(drop=True), receipt
