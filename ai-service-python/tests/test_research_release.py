import hashlib
import json
from pathlib import Path

import pytest

from app.schemas import SimpleBacktestRequest
from app.services import research_release


def test_legacy_is_explicitly_unattested_and_release_is_schema_preserved():
    assert research_release.attest({}) == {"status": "legacy_unsealed", "loaded_code_attested": False}
    payload = SimpleBacktestRequest(research_release={"protocol": "test"})
    assert payload.research_release == {"protocol": "test"}


def test_release_requires_current_and_loaded_source(monkeypatch):
    monkeypatch.setattr(research_release, "source_hash", lambda: "a" * 64)
    monkeypatch.setattr(research_release, "BOOT_SOURCE_HASH", "a" * 64)
    identity = {"protocol": "prospective_research_release_v1", "python_source_hash": "a" * 64,
                "dataset_hash": "d" * 64, "agent_execution_hashes": {"1": "e" * 64}}
    seal = {**identity, "release_hash": hashlib.sha256(json.dumps(identity,
        sort_keys=True, separators=(",", ":")).encode()).hexdigest()}
    assert research_release.attest(seal)["loaded_code_attested"] is True
    assert research_release.attest(seal, dataset_hash="d" * 64, execution_hash="e" * 64)["loaded_code_attested"] is True
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_REQUEST_DATASET_DRIFT"):
        research_release.attest(seal, dataset_hash="f" * 64)
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_REQUEST_DATASET_DRIFT"):
        research_release.attest(seal, dataset_hash=None)
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_REQUEST_EXECUTION_DRIFT"):
        research_release.attest(seal, execution_hash="f" * 64)
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_REQUEST_EXECUTION_DRIFT"):
        research_release.attest(seal, execution_hash=None)
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_IDENTITY_INVALID"):
        research_release.attest({**seal, "dataset_hash": "f" * 64})
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_SOURCE_DRIFT"):
        research_release.attest({**seal, "python_source_hash": "c" * 64})
    monkeypatch.setattr(research_release, "BOOT_SOURCE_HASH", "c" * 64)
    with pytest.raises(ValueError, match="RESEARCH_WORKER_LOADED_RELEASE_MISMATCH"):
        research_release.attest(seal)


def test_source_hash_is_relative_ordered_content_hash(tmp_path: Path):
    (tmp_path / "app").mkdir()
    (tmp_path / "app" / "z.py").write_bytes(b"z")
    (tmp_path / "app" / "a.py").write_bytes(b"a")
    (tmp_path / "requirements.txt").write_bytes(b"dep")
    parts = {"app/a.py": hashlib.sha256(b"a").hexdigest(),
             "app/z.py": hashlib.sha256(b"z").hexdigest(),
             "requirements.txt": hashlib.sha256(b"dep").hexdigest()}
    expected = hashlib.sha256(json.dumps(parts, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    assert research_release.source_hash(tmp_path) == expected


def test_health_reports_actual_boot_drift_without_claiming_replay_proof(monkeypatch):
    monkeypatch.setattr(research_release, "source_hash", lambda: "a" * 64)
    monkeypatch.setattr(research_release, "BOOT_SOURCE_HASH", "c" * 64)
    health = research_release.health_receipt()
    assert health["loaded_code_current"] is False
    assert health["boot_source_hash"] == "c" * 64
    assert health["promotion_evidence"] is False
    monkeypatch.setattr(research_release, "BOOT_SOURCE_HASH", "a" * 64)
    assert research_release.health_receipt()["loaded_code_current"] is True


def test_release_hash_orders_numeric_agent_ids_like_php():
    identity = {"protocol": "prospective_research_release_v1",
                "agent_execution_hashes": {"10": "b", "9": "a"}}
    expected = '{"agent_execution_hashes":{"9":"a","10":"b"},"protocol":"prospective_research_release_v1"}'
    assert research_release._release_json(identity) == expected


def test_real_source_seal_exposes_distinct_aggregate_without_reinterpreting_python_hash():
    current = research_release.source_hash()
    identity = {"protocol": "prospective_research_release_v1", "python_source_hash": current,
        "source_hash": "a" * 64, "dataset_hash": "d" * 64, "agent_execution_hashes": {"1": "e" * 64}}
    seal = {**identity, "release_hash": hashlib.sha256(research_release._release_json(identity).encode()).hexdigest()}
    receipt = research_release.attest(seal, dataset_hash="d" * 64, execution_hash="e" * 64)
    assert receipt["source_hash"] == current
    assert receipt["boot_source_hash"] == current
    assert receipt["sealed_full_runtime_source_hash"] == "a" * 64
    assert receipt["full_runtime_source_provenance"] == "validated_sealed_release_identity"
    assert receipt["promotion_evidence"] is False
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_IDENTITY_INVALID"):
        research_release.attest({**seal, "source_hash": "b" * 64})
    with pytest.raises(ValueError, match="RESEARCH_RELEASE_SOURCE_DRIFT"):
        research_release.attest({**seal, "python_source_hash": "b" * 64})


def test_missing_or_unsealed_aggregate_cannot_borrow_full_runtime_identity():
    legacy = research_release.attest({})
    assert "sealed_full_runtime_source_hash" not in legacy
    identity = {"protocol": "prospective_research_release_v1", "python_source_hash": research_release.source_hash()}
    for aggregate in (None, "", "not-a-sha256"):
        candidate = {**identity, **({"source_hash": aggregate} if aggregate is not None else {})}
        seal = {**candidate, "release_hash": hashlib.sha256(research_release._release_json(candidate).encode()).hexdigest()}
        attested = research_release.attest(seal)
        assert attested["loaded_code_attested"] is True  # Python-only legacy attestation.
        assert attested["sealed_full_runtime_source_hash"] == ""
        assert attested["full_runtime_source_provenance"] == "legacy_missing_full_runtime_identity"


def test_stale_api_cannot_use_cache_or_spawn_a_fresh_child_to_claim_attestation(monkeypatch):
    from app import main
    monkeypatch.setattr(research_release, "source_hash", lambda: "a" * 64)
    monkeypatch.setattr(research_release, "BOOT_SOURCE_HASH", "c" * 64)
    monkeypatch.setattr(main, "_load_immutable_replay_cache", lambda _key: pytest.fail("stale API reached cache"))
    payload = SimpleBacktestRequest(research_release={"protocol": "prospective_research_release_v1",
        "python_source_hash": "a" * 64, "release_hash": "b" * 64})
    with pytest.raises(ValueError, match="RESEARCH_WORKER_LOADED_RELEASE_MISMATCH"):
        main._run_bounded_replay("simple", payload)


def test_run_all_reports_release_rejection_as_client_contract_error(monkeypatch):
    from app import main
    from fastapi import HTTPException

    def reject(_operation, _payload):
        raise ValueError("RESEARCH_RELEASE_SOURCE_DRIFT")

    monkeypatch.setattr(main, "_run_bounded_replay", reject)
    with pytest.raises(HTTPException) as error:
        main.run_all_backtests(SimpleBacktestRequest())
    assert error.value.status_code == 400
    assert error.value.detail == "RESEARCH_RELEASE_SOURCE_DRIFT"
