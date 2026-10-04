"""Attest the exact discovery slice; never infer independent validation."""

from __future__ import annotations

import hashlib
import json

import pandas as pd


PROTOCOL = "prospective_repair_probe_window_v1"
EVALUATOR = "incremental_probe_window_v2"


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
