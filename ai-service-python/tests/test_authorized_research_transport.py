import hashlib
import hmac
import json
from pathlib import Path

import pandas as pd
import pytest

from app import main
from app.schemas import SimpleBacktestRequest
from app.services import research_release as owner
from app.services.backtester import _load_verified_dataset_csv

KEY = "fixture-only-internal-auth-key-32-characters"


def resign(payload, mutate=lambda identity: None):
    envelope = payload.policy_context["authorized_research_transport"]
    identity = {k: v for k, v in envelope.items() if k not in {"contract_hash", "hmac_sha256"}}
    mutate(identity)
    canonical = owner._release_json(identity)
    payload.policy_context["authorized_research_transport"] = {**identity,
        "contract_hash": hashlib.sha256(canonical.encode()).hexdigest(),
        "hmac_sha256": hmac.new(KEY.encode(), (owner.RESEARCH_TRANSPORT_PROTOCOL + "\n" + canonical).encode(), hashlib.sha256).hexdigest()}


@pytest.fixture
def authorized(tmp_path, monkeypatch):
    monkeypatch.setattr(owner, "_research_data_roots", lambda: (tmp_path,))
    monkeypatch.setattr(owner, "_research_transport_now", lambda: pd.Timestamp("2028-01-01", tz="UTC"))
    monkeypatch.setattr(main, "_internal_api_token", lambda: KEY)
    path = tmp_path / "future.csv"
    path.write_text("time,open,high,low,close,volume\n2027-02-01T00:00:00Z,100,101,99,100,10\n2027-02-01T00:05:00Z,100,101,99,100,10\n", encoding="utf-8")
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    release = {"protocol": "prospective_research_release_v1", "python_source_hash": owner.source_hash(),
        "source_hash": "b" * 64, "dataset_hash": digest, "agent_execution_hashes": {"1": "e" * 64}}
    release["release_hash"] = hashlib.sha256(owner._release_json(release).encode()).hexdigest()
    window = {"protocol": "instrument_research_window_v1", "authorization_id": "server-window-1",
        "research_epoch_id": "research-2027", "start_inclusive": "2027-02-01T00:00:00+00:00",
        "end_exclusive": "2027-03-01T00:00:00+00:00", "dataset_sha256": digest}
    window["window_key"] = hashlib.sha256(json.dumps(window, separators=(",", ":")).encode()).hexdigest()
    payload = SimpleBacktestRequest(timeframe="M5", evaluation_mode="full", dataset_path=str(path),
        replay_dataset_hash=digest, research_release=release, execution_contract={"execution_hash": "e" * 64})
    identity = {"protocol": owner.RESEARCH_TRANSPORT_PROTOCOL, "purpose": "server_authorized_research_execution",
        "generation_id": 1, "release_hash": release["release_hash"], "dataset_hash": digest,
        "symbol": payload.symbol, "timeframe": payload.timeframe, "evaluation_mode": "full", "window": window,
        "files": {"M5": {"path": str(path), "sha256": digest, "rows": 2,
            "start_inclusive": "2027-02-01T00:00:00+00:00", "last_candle_at": "2027-02-01T00:05:00+00:00"}},
        "paper_exclusions": [{"start_inclusive": "2026-01-01T00:00:00+00:00", "end_exclusive": "2027-01-01T00:00:00+00:00"}],
        "independent_evidence": False, "promotion_evidence": False}
    payload.policy_context = {"authorized_research_transport": identity}
    resign(payload)
    return payload


def test_valid_actual_csv_admits_only_execution_without_independence_and_signature_is_retry_stable(authorized):
    first = owner.verify_research_transport(authorized, KEY)
    frame = _load_verified_dataset_csv(authorized, authorized.dataset_path, "M5")
    main._assert_non_paper_source_pre_2026(authorized, frame)
    assert first == owner.verify_research_transport(authorized, KEY)
    assert first["independent_evidence"] is False
    assert first["promotion_evidence"] is False
    assert "issued_at" not in first and "expires_at" not in first


@pytest.mark.parametrize("mutation", ["unsigned", "signature", "release", "dataset", "mode", "inline", "window", "window_identity", "rows", "paper_overlap", "legacy_policy", "source", "path", "context"])
def test_invalid_transport_never_borrows_future_authorization(authorized, tmp_path, mutation):
    if mutation == "unsigned":
        authorized.policy_context = {}
        with pytest.raises(ValueError, match="AUTHENTICATION_REQUIRED"):
            main._assert_non_paper_source_pre_2026(authorized, _load_verified_dataset_csv(authorized, authorized.dataset_path, "M5"))
        return
    if mutation == "signature": authorized.policy_context["authorized_research_transport"]["hmac_sha256"] = "a" * 64
    if mutation == "release": authorized.research_release["source_hash"] = "f" * 64
    if mutation == "dataset": authorized.replay_dataset_hash = "f" * 64
    if mutation == "mode": authorized.evaluation_mode = "incremental"
    if mutation == "inline": authorized.candles = [{"time": "2027-02-01", "open": 1, "high": 1, "low": 1, "close": 1}]
    if mutation == "window": resign(authorized, lambda i: i["window"].update(start_inclusive="2026-01-01T00:00:00+00:00"))
    if mutation == "window_identity": resign(authorized, lambda i: i["window"].update(window_key="f" * 64))
    if mutation == "rows": resign(authorized, lambda i: i["files"]["M5"].update(rows=3))
    if mutation == "paper_overlap": resign(authorized, lambda i: i["paper_exclusions"].append({"start_inclusive": "2027-02-01T00:00:00+00:00", "end_exclusive": "2027-04-01T00:00:00+00:00"}))
    if mutation == "legacy_policy": resign(authorized, lambda i: i.update(paper_exclusions=[{"start_inclusive": "2025-01-01T00:00:00+00:00", "end_exclusive": "2025-02-01T00:00:00+00:00"}]))
    if mutation == "source": Path(authorized.dataset_path).write_text("time,open\n2027-02-02T00:00:00Z,1\n", encoding="utf-8")
    if mutation == "path": resign(authorized, lambda i: i["files"]["M5"].update(path=str(tmp_path / ".." / "escape.csv")))
    if mutation == "context": authorized.mtf_dataset_paths = {"H4": authorized.dataset_path}
    with pytest.raises((ValueError, FileNotFoundError)):
        owner.verify_research_transport(authorized, KEY)


def test_immutable_2026_cannot_be_bypassed_by_cutoff_label_or_valid_future_signature(authorized):
    authorized.policy_context["data_boundary"] = {"training_end_exclusive": "2030-01-01"}
    frame = pd.DataFrame({"time": ["2026-02-01T00:00:00Z", "2026-02-01T00:05:00Z"]})
    with pytest.raises(ValueError, match="faqat paper lane"):
        main._assert_non_paper_source_pre_2026(authorized, frame)


def test_same_future_timestamps_changed_actual_rows_refuse_signed_file_identity(authorized):
    frame = _load_verified_dataset_csv(authorized, authorized.dataset_path, "M5")
    frame.loc[0, "close"] = 1000
    with pytest.raises(ValueError, match="SEALED_DATASET_CONSUMED_ROWS_MISMATCH"):
        main._assert_non_paper_source_pre_2026(authorized, frame)


def test_actual_primary_cannot_be_overwritten_by_duplicate_mtf_key(authorized, tmp_path):
    paper = tmp_path / "paper.csv"
    paper.write_text("time,open\n2026-02-01,1\n2026-02-02,1\n", encoding="utf-8")
    authorized.mtf_dataset_paths = {"M5": authorized.dataset_path}
    authorized.dataset_path = str(paper)
    with pytest.raises(ValueError, match="DUPLICATE_STREAM_PATH_MISMATCH"):
        owner.verify_research_transport(authorized, KEY)


@pytest.mark.parametrize("bad_stream", [None, "H4", "H1", "M15", "RELATED_M15"])
def test_all_real_mtf_and_related_streams_are_bound_and_paper_context_cannot_hide(authorized, tmp_path, bad_stream):
    streams = {"M5": {"path": authorized.dataset_path, "sha256": authorized.replay_dataset_hash}}
    files = {"M5": authorized.policy_context["authorized_research_transport"]["files"]["M5"]}
    for name, final_time in [("H4", "04:00:00"), ("H1", "01:00:00"), ("M15", "00:15:00"), ("RELATED_M15", "00:15:00")]:
        path = tmp_path / (name + ".csv")
        prefix = "2026-02-01" if name == bad_stream else "2027-02-01"
        path.write_text(f"time,open,high,low,close,volume\n{prefix}T00:00:00Z,100,101,99,100,10\n{prefix}T{final_time}Z,100,101,99,100,10\n", encoding="utf-8")
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        streams[name] = {"path": str(path), "sha256": digest}
        files[name] = {"path": str(path), "sha256": digest, "rows": 2,
            "start_inclusive": prefix + "T00:00:00+00:00", "last_candle_at": prefix + "T" + final_time + "+00:00"}
    bundle = hashlib.sha256(owner._release_json(streams).encode()).hexdigest()
    authorized.replay_dataset_hash = bundle
    authorized.mtf_snapshot_manifest = {"bundle_hash": bundle, "streams": streams}
    authorized.mtf_dataset_paths = {k: v["path"] for k, v in streams.items() if k in {"H4", "H1", "M15"}}
    authorized.related_mtf_dataset_paths = {"M15": streams["RELATED_M15"]["path"]}
    release = {k: v for k, v in authorized.research_release.items() if k != "release_hash"}
    release["dataset_hash"] = bundle
    authorized.research_release = {**release, "release_hash": hashlib.sha256(owner._release_json(release).encode()).hexdigest()}
    def update(i):
        i.update(files=files, dataset_hash=bundle, release_hash=authorized.research_release["release_hash"])
        window = {k: v for k, v in i["window"].items() if k != "window_key"}
        window["dataset_sha256"] = bundle
        i["window"] = {**window, "window_key": hashlib.sha256(json.dumps(window, separators=(",", ":")).encode()).hexdigest()}
    resign(authorized, update)
    if bad_stream:
        with pytest.raises(ValueError, match="TIME_SCOPE_INVALID:" + bad_stream):
            owner.verify_research_transport(authorized, KEY)
    else:
        assert owner.verify_research_transport(authorized, KEY)
        main._assert_non_paper_source_pre_2026(authorized, _load_verified_dataset_csv(authorized, authorized.dataset_path, "M5"))


def test_related_case_variant_duplicate_cannot_hide_a_second_source(authorized, tmp_path):
    other = tmp_path / "other.csv"
    other.write_bytes(Path(authorized.dataset_path).read_bytes())
    authorized.related_mtf_dataset_paths = {"M15": authorized.dataset_path, "m15": str(other)}
    with pytest.raises(ValueError, match="DUPLICATE_STREAM_PATH_MISMATCH:RELATED_M15"):
        owner.verify_research_transport(authorized, KEY)


@pytest.mark.parametrize("entry", ["bounded", "sync"])
def test_authentication_and_actual_bytes_are_rechecked_before_cache_or_checkpoint(authorized, monkeypatch, entry):
    Path(authorized.dataset_path).write_text("changed source bytes", encoding="utf-8")
    monkeypatch.setattr(main, "_load_immutable_replay_cache", lambda _key: pytest.fail("authentication reached cache"))
    monkeypatch.setattr(main, "_write_replay_checkpoint", lambda *a, **k: pytest.fail("authentication reached checkpoint"))
    with pytest.raises(ValueError, match="SOURCE_HASH_MISMATCH"):
        if entry == "bounded": main._run_bounded_replay("simple", authorized)
        else: main._run_all_backtests_sync(authorized)


def test_unsigned_pre2026_diagnostic_retains_original_historical_boundary():
    payload = SimpleBacktestRequest()
    assert owner.verify_research_transport(payload, "") is None
    main._assert_non_paper_source_pre_2026(payload, pd.DataFrame({"time": ["2025-12-31T23:00:00Z"]}))


def test_key_rotation_changes_authentication_not_the_sealed_scientific_cache_identity(authorized, monkeypatch):
    monkeypatch.setattr(main, "_runtime_dependency_manifest", lambda: {"test": "same-source"})
    monkeypatch.setattr(main, "_dataset_dependency_manifest", lambda _p: {"test": "same-bytes"})
    first = main._replay_cache_key("simple", authorized)
    replacement = authorized.model_copy(deep=True)
    identity = {k: v for k, v in replacement.policy_context["authorized_research_transport"].items() if k not in {"contract_hash", "hmac_sha256"}}
    rotated_key = KEY + "-rotated"
    replacement.policy_context["authorized_research_transport"]["hmac_sha256"] = hmac.new(rotated_key.encode(),
        (owner.RESEARCH_TRANSPORT_PROTOCOL + "\n" + owner._release_json(identity)).encode(), hashlib.sha256).hexdigest()
    assert owner.verify_research_transport(replacement, rotated_key)
    assert main._replay_cache_key("simple", replacement) == first
    with pytest.raises(ValueError, match="SIGNATURE_INVALID"):
        owner.verify_research_transport(authorized, rotated_key)
    resign(replacement, lambda i: i.update(generation_id=2))
    assert main._replay_cache_key("simple", replacement) != first
