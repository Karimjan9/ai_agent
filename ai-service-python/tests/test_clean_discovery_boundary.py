"""Typed discovery stays separate from full evidence before caches and child spawn."""

import copy
import hashlib
import json
from unittest.mock import patch

import pytest

from app.main import _assert_clean_discovery_boundary, _run_all_backtests_sync, _run_bounded_replay
from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import _canonical_json, _semantic_contract_value


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
