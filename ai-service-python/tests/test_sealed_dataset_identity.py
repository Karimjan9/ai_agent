import hashlib
import copy
import json
from pathlib import Path
import shutil
import subprocess
import sys
from unittest.mock import patch

import numpy as np
import pandas as pd
import pytest

from app.schemas import SimpleBacktestRequest, SimpleTrade
from app.services import backtester
from app.services.multitimeframe_stack import apply_closed_mtf_context
from app.services.volume_features import add_volume_features


def _candles(start, rows, frequency):
    prices = 2000.0 + np.arange(rows, dtype=float) * 0.1
    return pd.DataFrame({
        "time": pd.date_range(start, periods=rows, freq=frequency, tz="UTC"),
        "open": prices, "high": prices + 1.0, "low": prices - 1.0, "close": prices,
        "volume": 100.0, "volume_available": True,
    })


@pytest.fixture
def sealed_request(tmp_path, monkeypatch):
    # This fixture isolates input/semantic attestation; dedicated release tests
    # separately exercise real source/boot sealing.
    monkeypatch.setattr(backtester, "attest_research_release", lambda *_, **__: {
        "protocol": "research_worker_release_receipt_v1", "release_hash": "1" * 64,
        "source_hash": "f" * 64, "loaded_code_attested": True,
        "sealed_full_runtime_source_hash": "2" * 64,
        "full_runtime_source_provenance": "validated_sealed_release_identity",
    })
    streams = {}
    for timeframe, start, rows, frequency in (
        ("M5", "2025-12-22T13:00", 230, "5min"),
        ("H4", "2025-12-19", 40, "4h"),
        ("H1", "2025-12-19", 150, "h"),
        ("M15", "2025-12-22", 150, "15min"),
    ):
        path = tmp_path / (timeframe + ".csv")
        _candles(start, rows, frequency).to_csv(path, index=False)
        streams[timeframe] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
    return SimpleBacktestRequest(
        timeframe="M5", dataset_path=streams["M5"]["path"],
        replay_dataset_hash="b" * 64,
        mtf_dataset_paths={key: value["path"] for key, value in streams.items() if key != "M5"},
        mtf_snapshot_manifest={
            "protocol": "closed_h4_h1_m15_m5_snapshot_v1",
            "validation_bundle_protocol": "agent_owned_mtf_foundation_bundle_v1",
            "bundle_hash": "b" * 64, "streams": streams,
        },
        mtf_pilot={"enabled": True, "mode": "m15_only", "activation_status": "execution_stream_bound", "execution_timeframe": "M5"},
        parameters={"differential_pair_replay_enabled": False},
        execution_contract={"execution_hash": "e" * 64},
    )


def _observed_strategy(frame, parameters):
    out = frame.copy()
    out["signal"] = "WAIT"
    for column in (
        "entry_context_valid", "entry_location_valid", "entry_confirmation_valid",
        "entry_trigger_valid",
    ):
        out[column] = True
    offset = int((parameters or {}).get("fixture_setup_offset", 0))
    out["entry_setup_detected"] = (np.arange(len(out)) + offset) % 2 == 0
    return out


@pytest.mark.parametrize("transport", ["candles", "regime_candles", "mtf_streams", "related_mtf_streams"])
@pytest.mark.parametrize("loader", [
    backtester._load_simple_candles, backtester._load_mtf_streams,
    backtester.prepare_replay_feature_context,
])
def test_sealed_mixed_transport_rejected_before_any_csv_load(sealed_request, loader, transport):
    candles = json.loads(_candles("2025-12-22", 2, "5min").to_json(orient="records", date_format="iso"))
    request = sealed_request.model_copy(update={transport: {"M15": candles} if transport.endswith("streams") else candles})
    with patch.object(backtester.pd, "read_csv", side_effect=AssertionError("data loaded before guard")):
        with pytest.raises(ValueError, match="SEALED_MTF_INLINE_TRANSPORT_FORBIDDEN"):
            loader(request)


def test_api_rejects_mixed_transport_before_cache_or_child(sealed_request):
    from fastapi import HTTPException
    from app import main
    request = sealed_request.model_copy(update={"parameters": {}, "mtf_streams": {"H1": [object()]}})
    with patch.object(main, "_replay_cache_key", side_effect=AssertionError("cache reached")):
        with pytest.raises(HTTPException) as error:
            main.run_simple_backtest_api(request)
    assert error.value.status_code == 400
    assert "SEALED_MTF_INLINE_TRANSPORT_FORBIDDEN" in error.value.detail


def test_api_cache_hit_rechecks_actual_file_bytes(sealed_request):
    from fastapi import HTTPException
    from app import main
    changed = pd.read_csv(sealed_request.dataset_path)
    changed.loc[205, "close"] += 0.1
    changed.to_csv(sealed_request.dataset_path, index=False)
    request = sealed_request.model_copy(update={"parameters": {}})
    with patch.object(main, "_replay_cache_key", return_value="stale-key"), patch.object(
        main, "_load_immutable_replay_cache", return_value={}
    ), patch.object(main, "_with_replay_compiler_metadata", side_effect=AssertionError("stale cache returned")):
        with pytest.raises(HTTPException) as error:
            main.run_simple_backtest_api(request)
    assert error.value.status_code == 400
    assert "SEALED_DATASET_M5_HASH_MISMATCH" in error.value.detail


def test_valid_file_cache_hit_needs_no_csv_or_child(sealed_request):
    from app import main
    with patch.object(main, "_replay_cache_key", return_value="valid-key"), patch.object(
        main, "_load_immutable_replay_cache", return_value={"fixture": "cached"}
    ), patch.object(main, "_with_replay_compiler_metadata", side_effect=lambda result, *_: result), patch.object(
        backtester.pd, "read_csv", side_effect=AssertionError("cache rebuilt features")
    ), patch.object(main.subprocess, "Popen", side_effect=AssertionError("child started")):
        assert main._run_bounded_replay("simple", sealed_request) == {"fixture": "cached"}


def test_related_file_requires_exact_optional_manifest_record(sealed_request):
    path = sealed_request.mtf_dataset_paths["M15"]
    request = sealed_request.model_copy(update={"related_mtf_dataset_paths": {"M15": path}})
    with pytest.raises(ValueError, match="AUTONOMOUS_MTF_RELATED_M15_MANIFEST_MISSING"):
        backtester._load_related_mtf_streams(request)
    manifest = dict(sealed_request.mtf_snapshot_manifest)
    manifest["streams"] = {**manifest["streams"], "RELATED_M15": manifest["streams"]["M15"]}
    bound = request.model_copy(update={"mtf_snapshot_manifest": manifest})
    related = backtester._load_related_mtf_streams(bound)
    assert related["M15"].attrs["dataset_source_attestation"]["status"] == "verified"


def test_file_tampering_fails_at_real_backtest_loader(sealed_request):
    path = sealed_request.dataset_path
    changed = pd.read_csv(path)
    changed["close"] += 0.1
    changed.to_csv(path, index=False)
    with pytest.raises(ValueError, match="SEALED_DATASET_M5_HASH_MISMATCH"):
        backtester.run_simple_ema_rsi_backtest(sealed_request)


def test_declared_bundle_identity_must_match_request(sealed_request):
    request = sealed_request.model_copy(update={"replay_dataset_hash": "a" * 64})
    with pytest.raises(ValueError, match="AUTONOMOUS_MTF_DATASET_IDENTITY_MISMATCH"):
        backtester._load_simple_candles(request)


def test_dataframe_entrypoint_rejects_changed_consumed_rows(sealed_request):
    frame = backtester._load_simple_candles(sealed_request)
    frame.loc[0, ["open", "high", "low", "close"]] += 100
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_ROWS_MISMATCH"):
        backtester.run_simple_ema_rsi_backtest_on_dataframe(sealed_request, frame)


def test_actual_loader_row_index_is_deep_immutable_and_shared_by_pandas_views(sealed_request):
    frame = backtester._load_simple_candles(sealed_request)
    index = frame.attrs["_sealed_source_row_hashes"]
    assert copy.copy(index) is index
    assert copy.deepcopy(index) is index
    assert frame.copy().attrs["_sealed_source_row_hashes"] is index
    assert frame.iloc[:10].attrs["_sealed_source_row_hashes"] is index
    assert frame.iloc[0].attrs["_sealed_source_row_hashes"] is index
    key = next(iter(index))
    with pytest.raises(TypeError):
        index[key] = "a" * 64
    with pytest.raises(TypeError):
        index._rows[key] = "a" * 64
    with pytest.raises(TypeError, match="SOURCE_ROW_INDEX_IS_IMMUTABLE"):
        index._source = ()
    with pytest.raises(TypeError, match="SOURCE_ROW_INDEX_LOADER_OWNERSHIP_REQUIRED"):
        backtester._ImmutableSourceRowIndex(None, dict(index), frame.attrs["dataset_source_attestation"], sealed_request.dataset_path)


def test_plain_caller_attrs_cannot_forge_a_verified_loader_row_index(sealed_request):
    frame = backtester._load_simple_candles(sealed_request)
    frame.attrs["_sealed_source_row_hashes"] = dict(frame.attrs["_sealed_source_row_hashes"])
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_IDENTITY_MISMATCH"):
        backtester._consumed_dataset_attestation(sealed_request, frame)


def test_verified_index_rejects_relabelled_source_metadata_and_manifest(sealed_request):
    frame = backtester._load_simple_candles(sealed_request)
    frame.attrs["dataset_source_attestation"]["actual_source_sha256"] = "a" * 64
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_IDENTITY_MISMATCH"):
        backtester._consumed_dataset_attestation(sealed_request, frame)
    frame = backtester._load_simple_candles(sealed_request)
    manifest = copy.deepcopy(sealed_request.mtf_snapshot_manifest)
    manifest["streams"]["M5"]["sha256"] = "a" * 64
    request = sealed_request.model_copy(update={"mtf_snapshot_manifest": manifest})
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_IDENTITY_MISMATCH"):
        backtester._consumed_dataset_attestation(request, frame)


def test_verified_context_index_cannot_be_relabelled_to_a_different_stream(sealed_request):
    frame = backtester._load_mtf_streams(sealed_request)["M15"]
    frame.attrs["dataset_source_attestation"]["stream"] = "H1"
    with pytest.raises(ValueError, match="SEALED_CONTEXT_H1_CONSUMED_IDENTITY_MISMATCH"):
        backtester._context_source_attestation(sealed_request, "H1", frame)


def test_actual_subset_retains_source_proof_without_cloning_full_index(sealed_request):
    frame = backtester._load_simple_candles(sealed_request)
    sample = frame.iloc[20:30].copy()
    receipt = backtester._consumed_dataset_attestation(sealed_request, sample)
    assert receipt["status"] == "verified"
    assert receipt["source_rows"] == len(frame)
    assert receipt["consumed_rows"] == 10
    assert sample.attrs["_sealed_source_row_hashes"] is frame.attrs["_sealed_source_row_hashes"]
    sample.loc[sample.index[0], "close"] += 1
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_ROWS_MISMATCH"):
        backtester._consumed_dataset_attestation(sealed_request, sample)


def test_gap_validator_reads_only_timestamp_values_without_dataframe_rows(sealed_request):
    frame = backtester._load_simple_candles(sealed_request).iloc[:10].copy()
    expected = backtester._validate_data_gaps(frame, sealed_request)
    def denied_rows(_):
        raise AssertionError("gap check constructed full DataFrame row")
    with patch.object(pd.DataFrame, "iloc", property(denied_rows)):
        assert backtester._validate_data_gaps(frame, sealed_request) == expected
    bad = frame.copy()
    bad.loc[bad.index[1], "time"] = bad.iloc[0]["time"]
    with pytest.raises(ValueError, match="timestamps"):
        backtester._validate_data_gaps(bad, sealed_request)


def test_strategy_cannot_change_source_rows_after_feature_attestation(sealed_request):
    def changed_source(frame, parameters):
        out = _observed_strategy(frame, parameters)
        out.loc[205, "close"] += 0.1
        return out

    with patch.object(backtester, "get_strategy", return_value=changed_source):
        with pytest.raises(ValueError, match="SEALED_SIGNAL_SNAPSHOT_ROWS_MISMATCH"):
            backtester.run_simple_ema_rsi_backtest(sealed_request)


def test_file_only_backtest_emits_actual_data_and_compact_stage_identities(sealed_request):
    with patch.object(backtester, "get_strategy", return_value=_observed_strategy):
        first = backtester.run_simple_ema_rsi_backtest(sealed_request)
        repeat = backtester.run_simple_ema_rsi_backtest(sealed_request)
    data = first.data_quality["dataset_attestation"]
    receipt = first.data_quality["decision_identity_receipt"]
    assert receipt["status"] == "complete"
    assert data["status"] == "verified"
    assert data["actual_source_sha256"] == sealed_request.mtf_snapshot_manifest["streams"]["M5"]["sha256"]
    assert data["consumed_rows"] == 230
    assert first.volume_quality["status"] == "not_requested"
    assert receipt["candle_domain"]["event_count"] == 30
    assert receipt["stage_identities"]["opportunity"]["event_count"] == receipt["candle_domain"]["event_count"]
    assert receipt["protocol"] == "replay_decision_identity_v2"
    assert receipt["dependency_identity"]["status"] == "complete"
    assert receipt["dependency_identity"]["required_streams"] == ["H1", "H4", "M15", "M5"]
    assert receipt == repeat.data_quality["decision_identity_receipt"]
    assert receipt["bindings"]["data_hash"] == data["consumed_data_hash"]
    assert receipt["bindings"]["actual_source_sha256"] == data["actual_source_sha256"]
    assert receipt["stage_identities"]["entry"]["event_count"] == first.total_trades == 0
    assert receipt["counts_are_diagnostic_only"] is True
    identity = {key: value for key, value in receipt.items() if key != "receipt_hash"}
    assert receipt["receipt_hash"] == hashlib.sha256(json.dumps(identity, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()


def test_equal_stage_counts_do_not_attest_different_candle_masks(sealed_request):
    shifted = sealed_request.model_copy(update={"parameters": {"fixture_setup_offset": 1, "differential_pair_replay_enabled": False}})
    with patch.object(backtester, "get_strategy", return_value=_observed_strategy):
        left = backtester.run_simple_ema_rsi_backtest(sealed_request).data_quality["decision_identity_receipt"]
        right = backtester.run_simple_ema_rsi_backtest(shifted).data_quality["decision_identity_receipt"]
    assert left["candle_domain"] == right["candle_domain"]
    assert left["bindings"] == right["bindings"]
    assert left["stage_identities"]["setup"]["event_count"] == right["stage_identities"]["setup"]["event_count"]
    assert left["stage_identities"]["setup"]["event_hash"] != right["stage_identities"]["setup"]["event_hash"]
    assert left["stage_identities"]["confirmation"] == right["stage_identities"]["confirmation"]


def test_unsealed_inline_data_cannot_emit_controlling_identity_receipt():
    frame = _candles("2025-12-22", 230, "5min")
    request = SimpleBacktestRequest(timeframe="M5", replay_dataset_hash="b" * 64,
        parameters={"differential_pair_replay_enabled": False})
    with patch.object(backtester, "get_strategy", return_value=_observed_strategy):
        result = backtester.run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    receipt = result.data_quality["decision_identity_receipt"]
    assert receipt["status"] == "non_controlling"
    assert receipt["bindings"]["dataset_attestation_status"] == "unsealed"
    assert receipt["bindings"]["actual_source_sha256"] == ""


def test_entry_and_closed_trade_identities_follow_real_execution(sealed_request):
    def one_entry(frame, parameters):
        out = _observed_strategy(frame, parameters)
        out.loc[199, "signal"] = "BUY"
        return out

    request = sealed_request.model_copy(update={"execution": sealed_request.execution.model_copy(update={
        "stop_loss_percent": 0.01, "take_profit_percent": 0.02,
    })})
    with patch.object(backtester, "get_strategy", return_value=one_entry):
        result = backtester.run_simple_ema_rsi_backtest(request)
    receipt = result.data_quality["decision_identity_receipt"]
    assert result.total_trades == 1, json.dumps(result.entry_funnel)
    assert receipt["stage_identities"]["entry"]["event_count"] == 1
    assert receipt["stage_identities"]["closed_trade"]["event_count"] == 1
    assert receipt["stage_identities"]["entry"]["event_hash"] != receipt["stage_identities"]["closed_trade"]["event_hash"]
    assert receipt["stage_identities"]["closed_trade"]["semantic_schema"] == "entry_linked_close_v2"
    assert pd.Timestamp(result.trades[0].signal_time) == pd.Timestamp("2025-12-23T05:35Z")


def _semantic_frame(request):
    features = backtester.prepare_feature_snapshot(request, backtester._load_simple_candles(request))
    out = _observed_strategy(features.frame, {})
    out.attrs["data_quality"] = features.data_quality
    return out


def test_same_signal_membership_different_side_changes_semantic_identity(sealed_request):
    left = _semantic_frame(sealed_request)
    right = left.copy()
    left.loc[200, "signal"] = "BUY"
    right.loc[200, "signal"] = "SELL"
    a = backtester._decision_identity_receipt(sealed_request, left, [], set())
    b = backtester._decision_identity_receipt(sealed_request, right, [], set())
    assert a["status"] == b["status"] == "complete"
    assert a["bindings"] == b["bindings"]
    assert a["stage_identities"]["raw_signal"]["event_count"] == b["stage_identities"]["raw_signal"]["event_count"] == 1
    assert a["stage_identities"]["raw_signal"]["event_hash"] != b["stage_identities"]["raw_signal"]["event_hash"]
    assert a["stage_identities"]["confirmation"]["event_hash"] != b["stage_identities"]["confirmation"]["event_hash"]


def test_real_management_delta_changes_closed_semantics_not_filled_entry(sealed_request):
    def one_entry(frame, parameters):
        out = _observed_strategy(frame, parameters)
        out.loc[199, "signal"] = "BUY"
        return out

    control = sealed_request.model_copy(update={
        "parameters": {"differential_pair_replay_enabled": False, "time_stop_candles": 3},
        "execution": sealed_request.execution.model_copy(update={"stop_loss_percent": 1.0, "take_profit_percent": 10.0}),
    })
    candidate = control.model_copy(update={"parameters": {**control.parameters, "time_stop_candles": 5}})
    with patch.object(backtester, "get_strategy", return_value=one_entry):
        a = backtester.run_simple_ema_rsi_backtest(control)
        b = backtester.run_simple_ema_rsi_backtest(candidate)
    assert a.total_trades == b.total_trades == 1
    assert a.trades[0].entry_time == b.trades[0].entry_time
    assert a.trades[0].exit_time != b.trades[0].exit_time
    first = a.data_quality["decision_identity_receipt"]
    second = b.data_quality["decision_identity_receipt"]
    assert first["status"] == second["status"] == "complete"
    assert first["bindings"] == second["bindings"]
    for stage in ("opportunity", "context", "location", "setup", "confirmation", "trigger", "entry"):
        assert first["stage_identities"][stage] == second["stage_identities"][stage], stage
    assert first["stage_identities"]["closed_trade"]["event_count"] == second["stage_identities"]["closed_trade"]["event_count"]
    assert first["stage_identities"]["closed_trade"]["event_hash"] != second["stage_identities"]["closed_trade"]["event_hash"]


def test_unchanged_semantics_produce_identical_receipts(sealed_request):
    frame = _semantic_frame(sealed_request)
    a = backtester._decision_identity_receipt(sealed_request, frame, [], set())
    b = backtester._decision_identity_receipt(sealed_request, frame.copy(), [], set())
    assert a == b


def test_actual_context_source_attested_not_only_declared_bundle(sealed_request):
    first = _semantic_frame(sealed_request)
    manifest = json.loads(json.dumps(sealed_request.mtf_snapshot_manifest))
    source = manifest["streams"]["H1"]
    changed = pd.read_csv(source["path"])
    changed.loc[50, ["open", "high", "low", "close"]] += 0.1
    changed.to_csv(source["path"], index=False)
    source["sha256"] = hashlib.sha256(open(source["path"], "rb").read()).hexdigest()
    request = sealed_request.model_copy(update={"mtf_snapshot_manifest": manifest})
    second = _semantic_frame(request)
    a = backtester._decision_identity_receipt(sealed_request, first, [], set())
    b = backtester._decision_identity_receipt(request, second, [], set())
    assert a["bindings"]["dataset_identity"] == b["bindings"]["dataset_identity"]
    assert a["bindings"]["data_hash"] == b["bindings"]["data_hash"]
    assert a["dependency_identity"]["streams"]["H1"]["consumed_data_hash"] != b["dependency_identity"]["streams"]["H1"]["consumed_data_hash"]
    assert a["bindings"]["dependency_receipt_hash"] != b["bindings"]["dependency_receipt_hash"]


@pytest.mark.parametrize("missing", ["H4", "H1", "M15"])
def test_missing_actual_dependency_never_creates_controlling_receipt(sealed_request, missing):
    frame = _semantic_frame(sealed_request)
    del frame.attrs["data_quality"]["context_source_attestations"][missing]
    receipt = backtester._decision_identity_receipt(sealed_request, frame, [], set())
    assert receipt["status"] == "non_controlling"
    assert receipt["dependency_identity"]["status"] == "non_controlling"


def test_future_as_of_context_refuses_control(sealed_request):
    frame = _semantic_frame(sealed_request)
    frame.loc[200, "h1_available_at"] = frame.loc[200, "time"] + pd.Timedelta(hours=1)
    receipt = backtester._decision_identity_receipt(sealed_request, frame, [], set())
    assert receipt["status"] == "non_controlling"
    assert receipt["dependency_identity"]["streams"]["H1"]["join_status"] == "future_context"


def test_context_compiler_source_mutation_cannot_reuse_old_attestation(sealed_request):
    context = backtester.prepare_replay_feature_context(sealed_request)
    context.mtf_context.prepared["H1"].loc[50, "close"] += 0.1
    with pytest.raises(ValueError, match="SEALED_CONTEXT_H1_CONSUMED_ROWS_MISMATCH"):
        backtester.prepare_feature_snapshot(sealed_request, backtester._load_simple_candles(sealed_request), replay_context=context)


def test_feature_cache_cannot_rebind_old_context_to_changed_optional_source(sealed_request):
    features = backtester.prepare_feature_snapshot(sealed_request, backtester._load_simple_candles(sealed_request))
    manifest = json.loads(json.dumps(sealed_request.mtf_snapshot_manifest))
    manifest["streams"]["H1"]["sha256"] = "1" * 64
    request = sealed_request.model_copy(update={"mtf_snapshot_manifest": manifest})
    with patch.object(backtester, "get_strategy", side_effect=AssertionError("strategy reached before context attestation")):
        with pytest.raises(ValueError, match="SEALED_FEATURE_CONTEXT_H1_IDENTITY_MISMATCH"):
            backtester.prepare_signal_snapshot(request, feature_snapshot=features)


@pytest.mark.parametrize("stream", ["D1", "RELATED_M15", "REGIME_H1"])
def test_supplied_optional_context_has_its_own_consumed_source_and_as_of_digest(sealed_request, stream):
    manifest = json.loads(json.dumps(sealed_request.mtf_snapshot_manifest))
    updates = {}
    if stream == "D1":
        path = Path(sealed_request.dataset_path).parent / "D1.csv"
        _candles("2025-12-01", 30, "1D").to_csv(path, index=False)
        manifest["streams"]["D1"] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
        updates["mtf_dataset_paths"] = {**sealed_request.mtf_dataset_paths, "D1": str(path)}
    elif stream == "RELATED_M15":
        manifest["streams"]["RELATED_M15"] = manifest["streams"]["M15"]
        updates["related_mtf_dataset_paths"] = {"M15": sealed_request.mtf_dataset_paths["M15"]}
    else:
        updates["regime_dataset_path"] = sealed_request.mtf_dataset_paths["H1"]
    request = sealed_request.model_copy(update={**updates, "mtf_snapshot_manifest": manifest})
    frame = _semantic_frame(request)
    receipt = backtester._decision_identity_receipt(request, frame, [], set())
    assert receipt["status"] == "complete"
    actual = receipt["dependency_identity"]["streams"][stream]
    assert actual["status"] == actual["join_status"] == "verified"
    assert len(actual["consumed_data_hash"]) == len(actual["as_of_join_hash"]) == 64
    assert stream in receipt["dependency_identity"]["required_streams"]
    assert actual["as_of_rule"] == ("candle_open_utc_v1" if stream == "REGIME_H1" else "candle_open_plus_timeframe_duration_utc_v1")


def test_real_python_receipt_is_hash_valid_for_php_semantic_stage_owner():
    php = shutil.which("php")
    repository = Path(__file__).resolve().parents[2]
    if not php or not (repository / "backend-laravel/vendor/autoload.php").exists():
        pytest.skip("PHP integration runtime not available")
    producer = repository / "ai-service-python/tests/support/semantic_stage_receipt_fixture.py"
    output = subprocess.run([sys.executable, str(producer)], capture_output=True, text=True, check=True, timeout=120).stdout
    code = r'''
require $argv[1].'/vendor/autoload.php';
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$service = new App\Services\CausalStageMasteryDirectorService();
echo json_encode($service->assess('location_tolerance_atr', $fixture['control'], $fixture['candidate']), JSON_THROW_ON_ERROR);
'''
    checked = subprocess.run([php, "-r", code, str(repository / "backend-laravel")],
        input=output, capture_output=True, text=True, check=True, timeout=30)
    assessment = json.loads(checked.stdout)
    assert assessment["status"] == "controllable", assessment
    assert assessment["checks"]["decision_identity_valid"] is True
    assert assessment["checks"]["upstream_identity_preserved"] is True
    assert assessment["promotion_evidence"] is False


def test_nonfinite_management_outcome_never_yields_complete_receipt(sealed_request):
    frame = _semantic_frame(sealed_request)
    frame.loc[199, "signal"] = "BUY"
    when = pd.Timestamp(frame.loc[199, "time"]).isoformat()
    filled = {199: {"direction": "BUY", "entry_time": when, "entry_price": "2000",
        "initial_stop_loss": "1990", "position_size_multiple": "1", "risk_budget_percent": "1"}}
    trade = SimpleTrade(direction="BUY", entry_time=when, exit_time=when, signal_time=when,
        entry_price=2000, exit_price=2001, result="WIN", profit_percent=float("nan"), balance=10000)
    receipt = backtester._decision_identity_receipt(sealed_request, frame, [trade], {199}, filled)
    assert receipt["status"] == "non_controlling"
    assert receipt["stage_identities"]["closed_trade"]["status"] == "unavailable"


def test_actual_sealed_payload_emits_both_source_scopes_and_missing_seal_is_noncontrolling(sealed_request, monkeypatch):
    from app.services import research_release
    monkeypatch.setattr(backtester, "attest_research_release", research_release.attest)
    actual_python = research_release.source_hash()
    actual_execution = backtester.execution_contract_metadata(sealed_request)["execution_hash"]
    request = sealed_request.model_copy(update={"execution_contract": {"protocol": "canonical_market_execution_v1",
        "parameters": sealed_request.execution.model_dump(), "execution_hash": actual_execution}})
    identity = {"protocol": "prospective_research_release_v1", "python_source_hash": actual_python,
        "source_hash": "9" * 64, "dataset_hash": request.replay_dataset_hash,
        "agent_execution_hashes": {"1": actual_execution}}
    seal = {**identity, "release_hash": hashlib.sha256(research_release._release_json(identity).encode()).hexdigest()}
    request = request.model_copy(update={"research_release": seal})
    frame = _semantic_frame(request)
    receipt = backtester._decision_identity_receipt(request, frame, [], set())
    assert receipt["status"] == "complete"
    assert receipt["bindings"]["source_evaluator_hash"] == actual_python
    assert receipt["bindings"]["python_source_hash"] == actual_python
    assert receipt["bindings"]["full_runtime_source_hash"] == "9" * 64
    assert receipt["bindings"]["source_identity_protocol"] == "dual_runtime_source_identity_v1"
    assert receipt["bindings"]["execution_hash"] == seal["agent_execution_hashes"]["1"]
    legacy = sealed_request.model_copy(update={"research_release": {}})
    frame = _semantic_frame(legacy)
    receipt = backtester._decision_identity_receipt(legacy, frame, [], set())
    assert receipt["status"] == "non_controlling"
    assert receipt["bindings"]["full_runtime_source_hash"] == ""


def test_sub_display_precision_exit_effect_uses_unrounded_execution_outcome(sealed_request):
    frame = _semantic_frame(sealed_request)
    frame.loc[200, "signal"] = "BUY"
    when = pd.Timestamp(frame.loc[200, "time"]).isoformat()
    entry_at = pd.Timestamp(frame.loc[201, "time"]).isoformat()
    exit_at = pd.Timestamp(frame.loc[210, "time"]).isoformat()
    filled = {200: {"direction": "BUY", "entry_time": entry_at, "entry_price": "2000",
        "initial_stop_loss": "1990", "position_size_multiple": "1", "risk_budget_percent": "1"}}
    displayed = SimpleTrade(direction="BUY", entry_time=entry_at, exit_time=exit_at, signal_time=when,
        entry_price=2000, exit_price=2001, result="WIN", profit_percent=0.05, balance=10000)
    raw = {"direction": "BUY", "entry_price": 2000, "exit_price": 2001.0001,
        "profit_percent": 0.050005, "gross_profit_percent": 0.050005, "execution_cost_percent": 0,
        "market_profit_percent": 0.050005, "position_size_multiple": 1}
    control = backtester._decision_identity_receipt(sealed_request, frame, [displayed], {200}, filled, [raw])
    candidate = backtester._decision_identity_receipt(sealed_request, frame, [displayed], {200}, filled,
        [{**raw, "exit_price": 2001.0002, "profit_percent": 0.05001, "gross_profit_percent": 0.05001, "market_profit_percent": 0.05001}])
    assert control["status"] == candidate["status"] == "complete"
    assert control["stage_identities"]["entry"] == candidate["stage_identities"]["entry"]
    assert control["stage_identities"]["closed_trade"]["event_hash"] != candidate["stage_identities"]["closed_trade"]["event_hash"]


def test_shared_feature_tail_attests_only_consumed_rows(sealed_request):
    source = backtester._load_simple_candles(sealed_request)
    features = backtester.prepare_feature_snapshot(sealed_request, source)
    assert features.frame.attrs["volume_quality"]["normalization"]["slots_per_day"] == 288
    tail = backtester.tail_feature_snapshot(features, 210)
    with patch.object(backtester, "get_strategy", return_value=_observed_strategy):
        signals = backtester.prepare_signal_snapshot(sealed_request, feature_snapshot=tail)
    receipt = signals.data_quality["dataset_attestation"]
    assert receipt["consumed_rows"] == 210
    assert receipt["consumed_data_hash"] != features.data_quality["dataset_attestation"]["consumed_data_hash"]
    changed = sealed_request.model_copy(update={"replay_dataset_hash": "a" * 64})
    with pytest.raises(ValueError, match="SEALED_FEATURE_SNAPSHOT_IDENTITY_MISMATCH"):
        backtester.prepare_signal_snapshot(changed, feature_snapshot=tail)
    mixed = sealed_request.model_copy(update={"mtf_streams": {"H1": [object()]}})
    with pytest.raises(ValueError, match="SEALED_MTF_INLINE_TRANSPORT_FORBIDDEN"):
        backtester.prepare_signal_snapshot(mixed, feature_snapshot=tail)


@pytest.mark.parametrize("timeframe,minutes", [("M1", 1), ("M5", 5), ("M15", 15), ("M30", 30), ("H1", 60), ("H4", 240), ("D1", 1440)])
def test_gap_clock_uses_actual_supported_timeframe(timeframe, minutes):
    request = SimpleBacktestRequest(timeframe=timeframe)
    start = pd.Timestamp("2025-12-22T13:00Z")
    complete = pd.DataFrame({"time": [start, start + pd.Timedelta(minutes=minutes)]})
    missing = pd.DataFrame({"time": [start, start + pd.Timedelta(minutes=minutes * 2)]})
    assert backtester._validate_data_gaps(complete, request) == 0
    assert backtester._validate_data_gaps(missing, request) == 1


@pytest.mark.parametrize("corruption", ["nan", "duplicate", "unordered", "infinity", "zero"])
def test_invalid_htf_evidence_is_rejected_without_silent_repair(corruption):
    streams = {"H4": _candles("2025-12-19", 40, "4h"), "H1": _candles("2025-12-22", 12, "h"), "M15": _candles("2025-12-22", 40, "15min")}
    h1 = streams["H1"]
    if corruption == "nan":
        h1.loc[4, "close"] = np.nan
    elif corruption == "duplicate":
        h1.loc[4, "time"] = h1.loc[3, "time"]
    elif corruption == "unordered":
        streams["H1"] = h1.iloc[::-1]
    elif corruption == "infinity":
        h1.loc[4, "high"] = np.inf
    else:
        h1.loc[4, "low"] = 0
    actual = apply_closed_mtf_context(_candles("2025-12-22", 100, "5min"), streams)
    assert set(actual["mtf_stack_status"]) == {"incomplete"}
    assert set(actual["mtf_stack_reason"]) == {"invalid_h1_stream"}


@pytest.mark.parametrize("stream", ["D1", "RELATED_M15"])
def test_invalid_supplied_optional_htf_is_not_silently_discarded(stream):
    streams = {"H4": _candles("2025-12-19", 40, "4h"), "H1": _candles("2025-12-22", 12, "h"), "M15": _candles("2025-12-22", 40, "15min")}
    invalid = _candles("2025-12-19", 10, "1D" if stream == "D1" else "15min")
    invalid.loc[4, "close"] = np.nan
    related = None
    if stream == "D1":
        streams["D1"] = invalid
    else:
        related = {"M15": invalid}
    actual = apply_closed_mtf_context(_candles("2025-12-22", 100, "5min"), streams, related_streams=related)
    assert set(actual["mtf_stack_status"]) == {"incomplete"}
    assert set(actual["mtf_stack_reason"]) == {"invalid_" + stream.lower() + "_stream"}


def test_m5_volume_uses_seven_day_m5_budget_and_causal_bucket():
    frame = _candles("2025-12-22", 100, "5min")
    prepared = add_volume_features(frame, {"timeframe": "M5"})
    quality = prepared.attrs["volume_quality"]
    assert quality["timeframe"] == "M5"
    assert quality["normalization"]["slots_per_day"] == 288
    assert quality["normalization"]["global_lookback"] == 2016
    assert prepared.loc[:59, "volume_feature_available"].sum() == 0
    changed = frame.copy()
    changed.loc[80:, "volume"] = 10000
    alternative = add_volume_features(changed, {"timeframe": "M5"})
    pd.testing.assert_series_equal(prepared.loc[:79, "volume_ratio"], alternative.loc[:79, "volume_ratio"])


@pytest.mark.parametrize("values", [[np.nan, np.nan], [np.inf, -1]])
def test_invalid_legacy_spread_values_cannot_claim_observation(values):
    quality = backtester._spread_quality(pd.DataFrame({"spread": values}), SimpleBacktestRequest())
    assert quality["status"] == "unavailable"
    assert quality["provider_observed"] is False
    assert quality["promotion_evidence"] is False


def test_numeric_legacy_spread_remains_unverified_for_promotion():
    quality = backtester._spread_quality(pd.DataFrame({"spread": [0.1, 0.2]}), SimpleBacktestRequest())
    assert quality["status"] == "observed"
    assert quality["provider_observed"] is True
    assert quality["provenance_status"] == "unverified_legacy_column"
    assert quality["promotion_evidence"] is False
