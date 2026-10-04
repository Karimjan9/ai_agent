"""Typed discovery stays separate from full evidence before caches and child spawn."""

import copy
import hashlib
import json
from unittest.mock import patch

import numpy as np
import pandas as pd
import pytest

from app import main
from app.main import _assert_clean_discovery_boundary, _run_all_backtests_sync, _run_bounded_replay
from app.schemas import SimpleBacktestRequest
from app.services import backtester
from app.services.execution_contract import _canonical_json, _semantic_contract_value
from app.services.prospective_probe_window import assert_clean_discovery_boundary, select_probe_window


def _request():
    calendar = {
        "loaded_rows": 15512, "warmup_rows": 512, "evaluated_rows": 15000,
        "loaded_start": "2025-09-01T00:00:00Z", "loaded_end": "2025-12-01T00:00:00Z",
        "evaluated_start": "2025-09-03T00:00:00Z", "evaluated_end": "2025-12-01T00:00:00Z",
        "evaluated_month_counts": {"2025-09": 5000, "2025-10": 5000, "2025-11": 5000},
        "selected_unexpected_gaps": 0,
    }
    scope = {"protocol": "prospective_clean_discovery_scope_v1", "calendar": calendar,
             "independent_evidence": False, "full_validation_eligible": False,
             "paper_eligible": False, "promotion_evidence": False}
    scope["scope_hash"] = hashlib.sha256(_canonical_json(_semantic_contract_value(scope)).encode()).hexdigest()
    probe = {"protocol": "prospective_repair_probe_window_v1", "evaluator_version": "incremental_probe_window_v2",
             "experiment_key": "synthetic-boundary-only", "dataset_hash": "a" * 64, "execution_hash": "b" * 64,
             **{key: value for key, value in calendar.items() if key != "selected_unexpected_gaps"},
             "independent_validation": False, "paper_2026_eligible": False}
    probe["contract_hash"] = hashlib.sha256(json.dumps(probe, separators=(",", ":")).encode()).hexdigest()
    manifest = {"validation_bundle_protocol": "prospective_clean_discovery_bundle_v1",
                "data_role": "pre_2026_discovery_only", "bundle_hash": "a" * 64,
                "discovery_scope": scope, "independent_evidence": False, "full_validation_eligible": False,
                "paper_eligible": False, "promotion_evidence": False}
    return SimpleBacktestRequest(evaluation_mode="incremental", timeframe="M5", replay_dataset_hash="a" * 64,
                                mtf_snapshot_manifest=manifest, execution_contract={"execution_hash": "b" * 64},
                                policy_context={"prospective_clean_discovery_scope": copy.deepcopy(scope), "prospective_probe_window": probe})


def test_exact_discovery_contract_is_only_an_incremental_routing_input():
    _assert_clean_discovery_boundary(_request())
    _assert_clean_discovery_boundary(SimpleBacktestRequest(evaluation_mode="full"))  # ordinary full remains unchanged


@pytest.mark.parametrize("mode", ["full", "replay"])
def test_full_or_replay_discovery_refuses_before_any_cache_or_child_spawn(mode):
    request = _request().model_copy(update={"evaluation_mode": mode})
    with patch("app.main._load_immutable_replay_cache") as cache, patch("app.main.subprocess.Popen") as spawn:
        with pytest.raises(ValueError, match="DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN"):
            _run_bounded_replay("run_all", request)
        with pytest.raises(ValueError, match="DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN"):
            _run_all_backtests_sync(request)
        cache.assert_not_called()
        spawn.assert_not_called()


@pytest.mark.parametrize("mutation,code", [
    ("missing_probe", "DISCOVERY_SCOPE_OR_PROBE_MISSING"),
    ("different_scope", "DISCOVERY_SCOPE_OR_PROBE_MISSING"),
    ("tail_override", "DISCOVERY_REPLAY_CONTRACT_MISMATCH"),
    ("stratification", "DISCOVERY_REPLAY_CONTRACT_MISMATCH"),
    ("false_independence", "DISCOVERY_EVIDENCE_BOUNDARY_INVALID"),
    ("smaller_window", "DISCOVERY_PROBE_SCOPE_MISMATCH"),
    ("probe_hash", "PROSPECTIVE_PROBE_WINDOW_HASH_MISMATCH"),
])
def test_scope_flags_and_shortcut_windows_cannot_relabel_discovery(mutation, code):
    request = _request()
    if mutation == "missing_probe": request.policy_context.pop("prospective_probe_window")
    elif mutation == "different_scope": request.policy_context["prospective_clean_discovery_scope"]["calendar"]["evaluated_rows"] = 5000
    elif mutation == "tail_override": request.dataset_tail_rows = 5000
    elif mutation == "stratification": request.policy_context["historical_stratified_windows"] = {"window_rows": 1500}
    elif mutation == "false_independence": request.mtf_snapshot_manifest["independent_evidence"] = True
    elif mutation == "smaller_window": request.policy_context["prospective_probe_window"]["evaluated_rows"] = 5000
    elif mutation == "probe_hash": request.policy_context["prospective_probe_window"]["contract_hash"] = "0" * 64
    with pytest.raises(ValueError, match=code): _assert_clean_discovery_boundary(request)


@pytest.fixture
def closed_mtf_discovery_request(tmp_path):
    """Real file loaders/compiler, not an EMA or mocked-MTF admission path.

    Prices are deterministic test inputs only. No market/skill authority is
    asserted; this fixture reproduces the enabled G256 confirmation runtime.
    """
    request = _request()
    primary_times = pd.date_range("2025-09-01T00:00:00Z", periods=15512, freq="5min")
    streams = {}
    for timeframe, times in {
        "M5": primary_times,
        "H4": pd.date_range("2025-08-20T00:00:00Z", primary_times[-1], freq="4h"),
        "H1": pd.date_range("2025-08-25T00:00:00Z", primary_times[-1], freq="h"),
        "M15": pd.date_range("2025-08-30T00:00:00Z", primary_times[-1], freq="15min"),
    }.items():
        steps = np.arange(len(times), dtype=float)
        prices = 2000.0 + steps * 0.01 + np.sin(steps / 10.0)
        frame = pd.DataFrame({
            "time": times, "open": prices, "high": prices + 0.5,
            "low": prices - 0.5, "close": prices + 0.1,
            "volume": 100.0, "volume_available": False,
        })
        path = tmp_path / f"{timeframe}.csv"
        frame.to_csv(path, index=False)
        streams[timeframe] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
    calendar = request.mtf_snapshot_manifest["discovery_scope"]["calendar"]
    calendar.update({
        "loaded_start": primary_times[0].strftime("%Y-%m-%dT%H:%M:%SZ"),
        "loaded_end": primary_times[-1].strftime("%Y-%m-%dT%H:%M:%SZ"),
        "evaluated_start": primary_times[512].strftime("%Y-%m-%dT%H:%M:%SZ"),
        "evaluated_end": primary_times[-1].strftime("%Y-%m-%dT%H:%M:%SZ"),
        "evaluated_month_counts": primary_times[512:].strftime("%Y-%m").value_counts(sort=False).to_dict(),
    })
    scope = request.mtf_snapshot_manifest["discovery_scope"]
    body = {key: value for key, value in scope.items() if key != "scope_hash"}
    scope["scope_hash"] = hashlib.sha256(_canonical_json(_semantic_contract_value(body)).encode()).hexdigest()
    request.policy_context["prospective_clean_discovery_scope"] = copy.deepcopy(scope)
    probe = request.policy_context["prospective_probe_window"]
    probe.update({key: value for key, value in calendar.items() if key != "selected_unexpected_gaps"})
    probe_body = {key: value for key, value in probe.items() if key != "contract_hash"}
    probe["contract_hash"] = hashlib.sha256(json.dumps(probe_body, separators=(",", ":")).encode()).hexdigest()
    request.mtf_snapshot_manifest.update({
        "protocol": "closed_h4_h1_m15_m5_snapshot_v1", "streams": streams,
    })
    request.mtf_pilot = {
        "enabled": True, "mode": "m15_only", "activation_status": "execution_stream_bound",
        "execution_timeframe": "M5",
    }
    request.dataset_path = streams["M5"]["path"]
    request.mtf_dataset_paths = {key: stream["path"] for key, stream in streams.items() if key != "M5"}
    request.strategy = "confirmation_entry_mtf_v1"
    request.parameters = {"entry_model": "trend_continuation", "entry_mode": "balanced"}
    return request


def test_api_and_direct_runtime_share_one_discovery_boundary():
    assert main._assert_clean_discovery_boundary is assert_clean_discovery_boundary
    assert backtester.assert_clean_discovery_boundary is assert_clean_discovery_boundary


def test_enabled_closed_mtf_discovery_reaches_real_confirmation_strategy(closed_mtf_discovery_request):
    request = closed_mtf_discovery_request
    context = backtester.prepare_replay_feature_context(request)
    assert context.mtf_context.status == "ready"
    assert set(context.mtf_context.prepared) == {"H4", "H1", "M15"}
    loaded = backtester._load_simple_candles(request)
    evaluated, receipt = select_probe_window(
        loaded, request.policy_context["prospective_probe_window"],
        request.replay_dataset_hash, request.execution_contract["execution_hash"],
    )
    # Keep the real warmup for features, then expose only evaluated rows just
    # as the native incremental branch does. Nothing mocks the MTF validator.
    features = backtester.tail_feature_snapshot(
        backtester.prepare_feature_snapshot(request, loaded, replay_context=context), len(evaluated),
    )
    signal = backtester.prepare_signal_snapshot(request, feature_snapshot=features)
    assert len(loaded) == 15512
    assert len(signal.frame) == receipt["evaluated_rows"] == 15000
    assert receipt["warmup_rows"] == 512 and receipt["complete"] is True
    assert set(signal.frame["mtf_stack_status"]) == {"ready"}
    assert "entry_contract_status" in signal.frame
    assert set(signal.frame["entry_contract_model"]) == {"trend_continuation"}
    assert signal.data_quality["dataset_attestation"]["status"] == "verified"
    for timeframe in ("h4", "h1", "m15"):
        available = signal.frame[f"{timeframe}_available_at"].dropna()
        assert (available <= signal.frame.loc[available.index, "decision_at"]).all()


@pytest.mark.parametrize("mutation,code", [
    ("full", "DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN"),
    ("missing_scope", "DISCOVERY_SCOPE_OR_PROBE_MISSING"),
    ("scope_hash", "DISCOVERY_SCOPE_HASH_MISMATCH"),
    ("independence", "DISCOVERY_EVIDENCE_BOUNDARY_INVALID"),
    ("tail", "DISCOVERY_REPLAY_CONTRACT_MISMATCH"),
])
def test_direct_mtf_preparation_rejects_invalid_discovery_before_loading(closed_mtf_discovery_request, mutation, code):
    request = closed_mtf_discovery_request
    if mutation == "full": request.evaluation_mode = "full"
    elif mutation == "missing_scope": request.mtf_snapshot_manifest.pop("discovery_scope")
    elif mutation == "scope_hash":
        request.mtf_snapshot_manifest["discovery_scope"]["scope_hash"] = "0" * 64
        request.policy_context["prospective_clean_discovery_scope"]["scope_hash"] = "0" * 64
    elif mutation == "independence": request.mtf_snapshot_manifest["independent_evidence"] = True
    elif mutation == "tail": request.dataset_tail_rows = 5000
    with patch.object(backtester.pd, "read_csv", side_effect=AssertionError("invalid scope loaded data")):
        with pytest.raises(ValueError, match=code): backtester.prepare_replay_feature_context(request)


@pytest.mark.parametrize("timeframe", ["M5", "H4", "H1", "M15"])
def test_discovery_protocol_preserves_all_four_file_hash_guards(closed_mtf_discovery_request, timeframe):
    request = closed_mtf_discovery_request
    context = backtester.prepare_replay_feature_context(request)
    request.mtf_snapshot_manifest["streams"][timeframe]["sha256"] = "0" * 64
    with pytest.raises(ValueError, match=f"AUTONOMOUS_MTF_{timeframe}_HASH_MISMATCH"):
        backtester._assert_closed_mtf_runtime(request, context.mtf_context)


def test_foundation_protocol_is_not_a_discovery_authority_shortcut(closed_mtf_discovery_request):
    request = closed_mtf_discovery_request
    request.mtf_snapshot_manifest["validation_bundle_protocol"] = "agent_owned_mtf_foundation_bundle_v1"
    with pytest.raises(ValueError, match="DISCOVERY_SCOPE_OR_PROBE_MISSING"):
        backtester.prepare_replay_feature_context(request)
