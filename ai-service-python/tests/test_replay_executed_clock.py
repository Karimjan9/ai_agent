import copy
from unittest.mock import patch

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.replay_executed_clock import ReplayExecutedClock
from app.services.research_program_tasks import canonical_hash
from tests.test_specialist_council import deterministic_strategy, fixtures, probe_contract


def clock(offset=0, owner="ordinary_single_position_v1", *, rows=5, policy=None):
    return ReplayExecutedClock(owner=owner, input_rows=rows + offset, evaluation_offset=offset,
        timeframe="M5", duration_seconds=300, dataset_hash="dataset", execution_hash="execution",
        policy=policy, probe=None)


def test_same_physical_clock_does_not_depend_on_storage_offset_or_producer_identity():
    first, second = clock(), clock(512, "native_specialist_council_v1", policy={"native": True})
    times = pd.date_range("2025-01-06", periods=5, freq="5min", tz="UTC")
    for index in range(1, 5):
        first.observe(index, times[index - 1], times[index])
        second.observe(index + 512, times[index - 1], times[index])
    left, right = first.finish(), second.finish()
    assert left["complete"] and right["complete"]
    assert left["index_set_hash"] == right["index_set_hash"]
    assert left["schedule_hash"] == right["schedule_hash"]
    # Pin the PHP consumer's exact domain, UTC spelling and scalar/list encoding.
    assert left["index_set_hash"] == "461aae6ea2c998e738ca7c0b0445ae0aa4baaeb3d98bd7291f1d3b9eb4ac3033"
    assert left["schedule_hash"] == "49538a0a7c7ff1c62378be0426bec91dbafbdf5921d9973f38ebb0993f7aec4f"
    assert left["signal_start"] == "2025-01-06T00:00:00+00:00"
    assert left["execution_end"] == "2025-01-06T00:20:00+00:00"
    assert left["receipt_hash"] != right["receipt_hash"]
    assert left["decision_rows"] == right["decision_rows"] == 4


def test_actual_missing_iterations_and_different_interior_times_change_the_clock():
    times = pd.date_range("2025-01-06", periods=5, freq="5min", tz="UTC")
    full, delayed, changed = clock(), clock(), clock()
    for index in range(1, 5):
        full.observe(index, times[index - 1], times[index])
        if index > 1:
            delayed.observe(index, times[index - 1], times[index])
        changed.observe(index, times[index - 1], times[index] + pd.Timedelta(seconds=1 if index == 2 else 0))
    assert delayed.finish()["decision_rows"] == 3
    assert delayed.finish()["first_evaluation_index"] == 2
    assert full.finish()["index_set_hash"] != delayed.finish()["index_set_hash"]
    assert full.finish()["schedule_hash"] != changed.finish()["schedule_hash"]


def test_unknown_utc_duplicate_or_noncontiguous_clock_cannot_be_complete():
    for invalid in ["2025-01-06T00:00:00", None]:
        observed = clock()
        observed.observe(1, invalid, "2025-01-06T00:05:00Z")
        assert not observed.finish()["complete"]
    observed = clock()
    observed.observe(1, "2025-01-06T00:00:00Z", "2025-01-06T00:05:00Z")
    observed.observe(3, "2025-01-06T00:10:00Z", "2025-01-06T00:15:00Z")
    assert not observed.finish()["complete"]
    assert observed.finish()["decision_rows"] == 2
    assert not clock().finish()["complete"]


def test_ordinary_actual_backtester_retains_its_200_clock_without_inventing_native_coverage():
    frame = pd.DataFrame({"time": pd.date_range("2025-01-06", periods=240, freq="5min", tz="UTC"),
        "open": 100.0, "high": 100.05, "low": 99.95, "close": 100.0})
    request = SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5",
        replay_dataset_hash="dataset", emit_decision_trace=False)
    with patch("app.services.backtester.get_strategy", return_value=deterministic_strategy):
        result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    receipt = result.data_quality["replay_executed_clock"]
    assert receipt["complete"]
    assert receipt["owner"] == "ordinary_single_position_v1"
    assert receipt["decision_rows"] == 40
    assert receipt["first_evaluation_index"] == 200
    assert receipt["signal_start"] == frame.iloc[199]["time"].isoformat()
    assert receipt["execution_end"] == frame.iloc[-1]["time"].isoformat()
    assert receipt["execution_hash"] == execution_contract_metadata(request)["execution_hash"]
    assert receipt["receipt_hash"] == canonical_hash({key: value for key, value in receipt.items()
        if key not in {"receipt_hash", "receipt_json"}})


def test_native_actual_warmup_is_not_counted_as_executed_clock_and_receipt_is_account_bound():
    request, frame = fixtures()
    probe = probe_contract(request, frame, warmup=8)
    request.policy_context = {"prospective_probe_window": probe}
    with patch("app.services.backtester.get_strategy", return_value=deterministic_strategy):
        result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    receipt = result.data_quality["replay_executed_clock"]
    assert receipt == result.specialist_council_receipt["replay_executed_clock"]
    assert receipt["complete"]
    assert receipt["input_rows"] == len(frame)
    assert receipt["evaluation_offset_rows"] == 8
    assert receipt["first_evaluation_index"] == 1
    assert receipt["decision_rows"] == len(frame) - 8 - 1
    assert receipt["signal_start"] == frame.iloc[8]["time"].isoformat()
    assert receipt["probe_contract_hash"] == probe["contract_hash"]
    # Mutating the physical clock invalidates the original account seal.
    changed = copy.deepcopy(result.specialist_council_receipt)
    changed["replay_executed_clock"]["decision_rows"] += 199
    assert changed["receipt_hash"] != canonical_hash({k: v for k, v in changed.items()
        if k not in {"receipt_hash", "receipt_json"}})
