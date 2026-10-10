"""A running process must not re-label stale imports with today's disk hash."""
import hashlib
import hmac
import io
import json
import os
import re
import sys
from pathlib import Path

import pandas as pd


def source_hash(root: Path | None = None) -> str:
    root = root or Path(__file__).resolve().parents[2]
    paths = sorted((root / "app").rglob("*.py"))
    paths += [root / name for name in ("requirements.txt", "pyproject.toml", "poetry.lock") if (root / name).is_file()]
    parts = {path.relative_to(root).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in paths}
    return hashlib.sha256(json.dumps(parts, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


BOOT_SOURCE_HASH = source_hash()
_UNSET = object()

RESEARCH_TRANSPORT_PROTOCOL = "authorized_research_transport_v1"
_TRANSPORT_ISSUER = object()


class _AuthenticatedResearchTransport(dict):
    """An internal verification product; caller JSON never carries its issuer."""
    def __init__(self, envelope, issuer):
        if issuer is not _TRANSPORT_ISSUER:
            raise TypeError("RESEARCH_TRANSPORT_PRIVATE_ISSUER_REQUIRED")
        canonical = _release_json(envelope)
        super().__init__(json.loads(canonical))
        self._issuer = issuer
        self._verified_digest = hashlib.sha256(canonical.encode()).hexdigest()


def authenticated_research_transport_current(transport):
    return (isinstance(transport, _AuthenticatedResearchTransport)
        and transport._issuer is _TRANSPORT_ISSUER
        and hashlib.sha256(_release_json(dict(transport)).encode()).hexdigest() == transport._verified_digest)


def _research_transport_now() -> pd.Timestamp:
    return pd.Timestamp.now(tz="UTC")


def _research_data_roots() -> tuple[Path, ...]:
    root = Path(__file__).resolve().parents[3]
    return (root / "datasets", root / "backend-laravel/storage/app/lab-datasets")


def _transport_path(raw: object) -> Path:
    if not isinstance(raw, str) or not raw or ".." in Path(raw).parts:
        raise ValueError("RESEARCH_TRANSPORT_SOURCE_PATH_INVALID")
    path = Path(raw).resolve(strict=True)
    if not path.is_file() or path.suffix.lower() != ".csv" or Path(raw).is_symlink():
        raise ValueError("RESEARCH_TRANSPORT_SOURCE_PATH_INVALID")
    if not any(path.is_relative_to(root.resolve()) for root in _research_data_roots()):
        raise ValueError("RESEARCH_TRANSPORT_SOURCE_PATH_OUTSIDE_DATA_ROOT")
    return path


def _transport_inventory(payload) -> dict[str, tuple[object, dict]]:
    primary = str(payload.timeframe).upper()
    manifest = dict(payload.mtf_snapshot_manifest or {})
    records = dict(manifest.get("streams") or {})
    paths = {primary: payload.dataset_path}
    if payload.regime_dataset_path:
        paths["REGIME_H1"] = payload.regime_dataset_path
    if payload.foundation_dataset_path:
        paths["FOUNDATION"] = payload.foundation_dataset_path
    for key, value in payload.mtf_dataset_paths.items():
        stream = str(key).upper()
        if stream not in {"M1", "M5", "M15", "M30", "H1", "H4", "D1"}:
            raise ValueError("RESEARCH_TRANSPORT_STREAM_INVALID")
        if stream in paths and _transport_path(paths[stream]) != _transport_path(value):
            raise ValueError("RESEARCH_TRANSPORT_DUPLICATE_STREAM_PATH_MISMATCH:" + stream)
        paths[stream] = value
    for key, value in payload.related_mtf_dataset_paths.items():
        if str(key).upper() not in {"M1", "M5", "M15", "M30", "H1", "H4", "D1"}:
            raise ValueError("RESEARCH_TRANSPORT_STREAM_INVALID")
        stream = "RELATED_" + str(key).upper()
        if stream in paths and _transport_path(paths[stream]) != _transport_path(value):
            raise ValueError("RESEARCH_TRANSPORT_DUPLICATE_STREAM_PATH_MISMATCH:" + stream)
        paths[stream] = value
    if manifest.get("bundle_hash") and manifest["bundle_hash"] != payload.replay_dataset_hash:
        raise ValueError("RESEARCH_TRANSPORT_BUNDLE_MISMATCH")
    if not 1 <= len(paths) <= 16 or not paths.get(primary):
        raise ValueError("RESEARCH_TRANSPORT_SOURCE_MISSING")
    inventory = {}
    for stream, path in paths.items():
        record = dict(records.get("H1" if stream == "REGIME_H1" else stream) or {})
        if not records and stream == primary:
            record = {"path": path, "sha256": payload.replay_dataset_hash}
        if not record.get("path") or not re.fullmatch(r"[a-f0-9]{64}", str(record.get("sha256") or "")):
            raise ValueError("RESEARCH_TRANSPORT_SOURCE_SEAL_MISSING:" + stream)
        inventory[stream] = (path, record)
    return inventory


def verify_research_transport(payload, internal_key: str) -> dict | None:
    """Authenticate admission and actual bytes before cache; never attest independence.

    The API's existing internal token is the issuer/verifier key. The stable
    signature is scoped to one persisted release and immutable window/source
    contract; re-delivery is not another scientific observation. Default
    unsigned historical requests retain their original boundary.
    """
    envelope = (payload.policy_context or {}).get("authorized_research_transport")
    if envelope is None:
        return None
    if not isinstance(envelope, dict) or len(internal_key) < 32:
        raise ValueError("RESEARCH_TRANSPORT_AUTHENTICATION_REQUIRED")
    identity = {key: value for key, value in envelope.items() if key not in {"contract_hash", "hmac_sha256"}}
    canonical = _release_json(identity)
    expected = hmac.new(internal_key.encode(), (RESEARCH_TRANSPORT_PROTOCOL + "\n" + canonical).encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, str(envelope.get("hmac_sha256") or "")):
        raise ValueError("RESEARCH_TRANSPORT_SIGNATURE_INVALID")
    if not hmac.compare_digest(hashlib.sha256(canonical.encode()).hexdigest(), str(envelope.get("contract_hash") or "")):
        raise ValueError("RESEARCH_TRANSPORT_IDENTITY_INVALID")
    if (identity.get("protocol") != RESEARCH_TRANSPORT_PROTOCOL
            or identity.get("purpose") != "server_authorized_research_execution"
            or identity.get("independent_evidence") is not False
            or identity.get("promotion_evidence") is not False
            or payload.evaluation_mode != "full" or identity.get("evaluation_mode") != "full"
            or identity.get("symbol") != payload.symbol or identity.get("timeframe") != payload.timeframe
            or type(identity.get("generation_id")) is not int or identity["generation_id"] <= 0
            or identity.get("dataset_hash") != payload.replay_dataset_hash
            or identity.get("release_hash") != (payload.research_release or {}).get("release_hash")):
        raise ValueError("RESEARCH_TRANSPORT_REQUEST_SCOPE_INVALID")
    attestation = attest(payload.research_release, dataset_hash=payload.replay_dataset_hash,
                         execution_hash=payload.execution_contract.get("execution_hash"))
    if not attestation.get("loaded_code_attested") or not attestation.get("sealed_full_runtime_source_hash"):
        raise ValueError("RESEARCH_TRANSPORT_SEALED_RELEASE_REQUIRED")
    window = dict(identity.get("window") or {})
    fields = ("protocol", "authorization_id", "research_epoch_id", "start_inclusive", "end_exclusive", "dataset_sha256")
    if (set(window) != {*fields, "window_key"} or window.get("protocol") != "instrument_research_window_v1"
            or not isinstance(window.get("authorization_id"), str) or not window["authorization_id"]
            or not isinstance(window.get("research_epoch_id"), str) or not window["research_epoch_id"]
            or window.get("dataset_sha256") != payload.replay_dataset_hash):
        raise ValueError("RESEARCH_TRANSPORT_WINDOW_INVALID")
    # Original PHP window identity has insertion order, unlike the transport map.
    original = json.dumps({key: window[key] for key in fields}, separators=(",", ":"))
    if hashlib.sha256(original.encode()).hexdigest() != window["window_key"]:
        raise ValueError("RESEARCH_TRANSPORT_WINDOW_IDENTITY_INVALID")
    start = pd.Timestamp(window["start_inclusive"]); end = pd.Timestamp(window["end_exclusive"])
    if start.tzinfo is None or end.tzinfo is None:
        raise ValueError("RESEARCH_TRANSPORT_WINDOW_UTC_REQUIRED")
    start = start.tz_convert("UTC"); end = end.tz_convert("UTC")
    if start < pd.Timestamp("2027-01-01", tz="UTC") or end <= start or end > _research_transport_now():
        raise ValueError("RESEARCH_TRANSPORT_PAPER_OR_FUTURE_WINDOW_FORBIDDEN")
    exclusions = identity.get("paper_exclusions")
    if not isinstance(exclusions, list) or not 1 <= len(exclusions) <= 64:
        raise ValueError("RESEARCH_TRANSPORT_PAPER_POLICY_MISSING")
    legacy = False
    for paper in exclusions:
        if not isinstance(paper, dict) or set(paper) != {"start_inclusive", "end_exclusive"}:
            raise ValueError("RESEARCH_TRANSPORT_PAPER_POLICY_INVALID")
        lower = pd.Timestamp(paper["start_inclusive"]); upper = pd.Timestamp(paper["end_exclusive"])
        if lower.tzinfo is None or upper.tzinfo is None or upper <= lower:
            raise ValueError("RESEARCH_TRANSPORT_PAPER_POLICY_INVALID")
        legacy |= lower == pd.Timestamp("2026-01-01", tz="UTC") and upper == pd.Timestamp("2027-01-01", tz="UTC")
        if start < upper and end > lower:
            raise ValueError("RESEARCH_TRANSPORT_PAPER_OVERLAP")
    if not legacy:
        raise ValueError("RESEARCH_TRANSPORT_IMMUTABLE_2026_POLICY_REQUIRED")
    if (payload.candles or payload.regime_candles or any(payload.mtf_streams.values())
            or any(payload.related_mtf_streams.values())):
        raise ValueError("RESEARCH_TRANSPORT_INLINE_FORBIDDEN")
    inventory = _transport_inventory(payload)
    files = dict(identity.get("files") or {})
    if set(files) != set(inventory):
        raise ValueError("RESEARCH_TRANSPORT_SOURCE_INVENTORY_MISMATCH")
    total_bytes = 0
    for stream, (raw, record) in inventory.items():
        path = _transport_path(raw)
        signed = dict(files[stream])
        if path != _transport_path(record["path"]) or path != _transport_path(signed.get("path")):
            raise ValueError("RESEARCH_TRANSPORT_PATH_MISMATCH:" + stream)
        remaining = 268435456 - total_bytes
        if path.stat().st_size > remaining:
            raise ValueError("RESEARCH_TRANSPORT_SOURCE_SIZE_INVALID")
        with path.open("rb") as handle:
            contents = handle.read(remaining + 1)
        if len(contents) > remaining:
            raise ValueError("RESEARCH_TRANSPORT_SOURCE_SIZE_INVALID")
        total_bytes += len(contents)
        digest = hashlib.sha256(contents).hexdigest()
        if digest != record["sha256"] or digest != signed.get("sha256"):
            raise ValueError("RESEARCH_TRANSPORT_SOURCE_HASH_MISMATCH:" + stream)
        frame = pd.read_csv(io.BytesIO(contents), usecols=["time"], low_memory=False)
        times = pd.to_datetime(frame["time"], utc=True, errors="coerce")
        if (len(times) < 2 or len(times) > 2000000 or times.isna().any()
                or not times.is_unique or not times.is_monotonic_increasing
                or (times < start).any() or (times >= end).any() or (times > _research_transport_now()).any()):
            raise ValueError("RESEARCH_TRANSPORT_TIME_SCOPE_INVALID:" + stream)
        if (type(signed.get("rows")) is not int or signed["rows"] != len(times)
                or pd.Timestamp(signed.get("start_inclusive")) != times.iloc[0]
                or pd.Timestamp(signed.get("last_candle_at")) != times.iloc[-1]):
            raise ValueError("RESEARCH_TRANSPORT_SOURCE_CHRONOLOGY_MISMATCH:" + stream)
    verified = _AuthenticatedResearchTransport(envelope, _TRANSPORT_ISSUER)
    if ((payload.mtf_snapshot_manifest or {}).get("validation_bundle_protocol") == "authorized_scoped_original_window_bundle_v1"
            or verified.get("scoped_original_window") is not None):
        from app.services.scoped_research_runtime import bind_scoped_research_runtime
        bind_scoped_research_runtime(payload, verified)
    return verified


def _release_json(value: object) -> str:
    """Match PHP's sorted object keys, including numeric LabAgent IDs."""
    def ordered(item: object) -> object:
        if isinstance(item, dict):
            keys = list(item)
            numeric = bool(keys) and all(str(key).isdecimal() for key in keys)
            return {str(key): ordered(item[key]) for key in sorted(
                keys, key=(lambda key: int(str(key))) if numeric else (lambda key: str(key)))}
        if isinstance(item, list):
            return [ordered(value) for value in item]
        return item
    return json.dumps(ordered(value), separators=(",", ":"))


def health_receipt() -> dict:
    current = source_hash()
    return {"protocol": "research_worker_source_health_v1", "source_hash": current,
            "boot_source_hash": BOOT_SOURCE_HASH, "loaded_code_current": current == BOOT_SOURCE_HASH,
            "pid": os.getpid(), "promotion_evidence": False}


def attest(release: dict, *, dataset_hash: str | None | object = _UNSET,
           execution_hash: str | None | object = _UNSET) -> dict:
    if not release:
        return {"status": "legacy_unsealed", "loaded_code_attested": False}
    current = source_hash()
    if release.get("protocol") != "prospective_research_release_v1" or current != release.get("python_source_hash"):
        raise ValueError("RESEARCH_RELEASE_SOURCE_DRIFT")
    if BOOT_SOURCE_HASH != current:
        raise ValueError("RESEARCH_WORKER_LOADED_RELEASE_MISMATCH")
    identity = {key: value for key, value in release.items()
                if key not in {"release_hash", "sealed_at", "promotion_evidence"}}
    expected = hashlib.sha256(_release_json(identity).encode()).hexdigest()
    if expected != release.get("release_hash"):
        raise ValueError("RESEARCH_RELEASE_IDENTITY_INVALID")
    if dataset_hash is not _UNSET and dataset_hash != release.get("dataset_hash"):
        raise ValueError("RESEARCH_RELEASE_REQUEST_DATASET_DRIFT")
    if execution_hash is not _UNSET and execution_hash not in (release.get("agent_execution_hashes") or {}).values():
        raise ValueError("RESEARCH_RELEASE_REQUEST_EXECUTION_DRIFT")
    # This is the Laravel-sealed aggregate identity, not a claim that Python
    # has inspected PHP bytes. Keep source_hash's historical Python meaning.
    aggregate = str(release.get("source_hash") or "")
    aggregate = aggregate if re.fullmatch(r"[a-f0-9]{64}", aggregate) else ""
    return {"protocol": "research_worker_release_receipt_v1", "release_hash": release["release_hash"],
            "source_hash": current, "boot_source_hash": BOOT_SOURCE_HASH, "pid": os.getpid(),
            "sealed_full_runtime_source_hash": aggregate,
            "full_runtime_source_provenance": "validated_sealed_release_identity" if aggregate else "legacy_missing_full_runtime_identity",
            "python_version": sys.version, "loaded_code_attested": True, "promotion_evidence": False}
