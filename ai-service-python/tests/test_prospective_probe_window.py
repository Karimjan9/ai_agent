import hashlib
import json

import pandas as pd
import pytest

from app.services.prospective_probe_window import select_probe_window


def _fixture():
    rows = pd.DataFrame({"time": pd.date_range("2025-11-01", periods=6, freq="D").strftime("%Y-%m-%dT%H:%M:%SZ"),
                         "close": [1, 2, 3, 4, 5, 6]})
    contract = {
        "protocol": "prospective_repair_probe_window_v1",
        "evaluator_version": "incremental_probe_window_v2",
        "experiment_key": "experiment-1",
        "dataset_hash": "a" * 64,
        "execution_hash": "b" * 64,
        "loaded_rows": 6,
        "warmup_rows": 2,
        "evaluated_rows": 4,
        "loaded_start": "2025-11-01T00:00:00Z",
        "loaded_end": "2025-11-06T00:00:00Z",
        "evaluated_start": "2025-11-03T00:00:00Z",
        "evaluated_end": "2025-11-06T00:00:00Z",
        "evaluated_month_counts": {"2025-11": 4},
        "independent_validation": False,
        "paper_2026_eligible": False,
    }
    contract["contract_hash"] = hashlib.sha256(json.dumps(contract, separators=(",", ":")).encode()).hexdigest()
    return rows, contract


def test_exact_window_receipt_and_rows():
    rows, contract = _fixture()
    evaluated, receipt = select_probe_window(rows, contract, "a" * 64, "b" * 64)
    assert len(evaluated) == 4
    assert evaluated.iloc[0]["close"] == 3
    assert receipt == {**contract, "complete": True}


@pytest.mark.parametrize("change,code", [
    ({"evaluated_rows": 3}, "PROSPECTIVE_PROBE_WINDOW_HASH_MISMATCH"),
    ({"dataset_hash": "c" * 64}, "PROSPECTIVE_PROBE_WINDOW_HASH_MISMATCH"),
])
def test_tampered_contract_fails(change, code):
    rows, contract = _fixture()
    contract.update(change)
    with pytest.raises(ValueError, match=code):
        select_probe_window(rows, contract, "a" * 64, "b" * 64)


def test_paper_row_and_wrong_loaded_slice_fail():
    rows, contract = _fixture()
    rows.loc[5, "time"] = "2026-01-01T00:00:00Z"
    with pytest.raises(ValueError, match="PAPER_DATA_FORBIDDEN"):
        select_probe_window(rows, contract, "a" * 64, "b" * 64)
    rows, contract = _fixture()
    with pytest.raises(ValueError, match="ROW_BUDGET_MISMATCH"):
        select_probe_window(rows.tail(5), contract, "a" * 64, "b" * 64)


def test_full_policy_keeps_15000_evaluated_rows_separate_from_512_warmup_rows():
    times = pd.date_range("2025-10-01", periods=15512, freq="5min", tz="UTC")
    rows = pd.DataFrame({"time": times.strftime("%Y-%m-%dT%H:%M:%SZ"), "close": range(15512)})
    contract = {
        "protocol": "prospective_repair_probe_window_v1",
            "evaluator_version": "incremental_probe_window_v2",
        "experiment_key": "full-probe",
        "dataset_hash": "a" * 64,
        "execution_hash": "b" * 64,
        "loaded_rows": 15512,
        "warmup_rows": 512,
        "evaluated_rows": 15000,
        "loaded_start": rows.iloc[0]["time"],
        "loaded_end": rows.iloc[-1]["time"],
        "evaluated_start": rows.iloc[512]["time"],
        "evaluated_end": rows.iloc[-1]["time"],
        "evaluated_month_counts": {
            key: int(value) for key, value in pd.Series(times[512:].strftime("%Y-%m"))
            .value_counts().sort_index().items()
        },
        "independent_validation": False,
        "paper_2026_eligible": False,
    }
    contract["evaluated_month_counts"] = {
        str(key): value for key, value in contract["evaluated_month_counts"].items()
    }
    contract["contract_hash"] = hashlib.sha256(json.dumps(contract, separators=(",", ":")).encode()).hexdigest()
    evaluated, receipt = select_probe_window(rows, contract, "a" * 64, "b" * 64)
    assert len(evaluated) == 15000
    assert evaluated.iloc[0]["close"] == 512
    assert sum(receipt["evaluated_month_counts"].values()) == 15000
