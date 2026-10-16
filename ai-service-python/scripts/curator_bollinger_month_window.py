"""One fixed complete October 2025 native discovery month; no replay or repair.

This is a new append-only data contract. It never alters the earlier two-week
producer, a failed month, old study history, scientific authority or paper policy.
"""
from __future__ import annotations

import argparse
import bisect
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai-service-python"))
sys.dont_write_bytecode = True

import pandas as pd
from scripts import curator_instrument_windows as native
from scripts.curator_research_deadline import AbsoluteResearchDeadline

PROTOCOL = "curator_complete_month_window_v1"
WINDOW_KEY = "october2025"
START = datetime(2025, 10, 1, tzinfo=timezone.utc)
END = datetime(2025, 11, 1, tzinfo=timezone.utc)
EXPECTED_NATIVE_MONTH = {"loaded_rows": 6524, "warmup_rows": 200, "evaluated_rows": 6324,
    "original_start_row": 182004, "original_evaluation_start_row": 182204,
    "original_end_row_exclusive": 188528,
    "sha256": "7e546aa5caae9ceb577654925b205a7b40121349a72f5e344ff80c8bccb911dc"}


def _need(ok, reason):
    if not ok:
        raise ValueError(reason)


def _sha(raw):
    return hashlib.sha256(raw).hexdigest()


def _encoded(value):
    return (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=False, allow_nan=False) + "\n").encode()


def _derive_month(source, missing_times, deadline):
    times, records = source["times"], source["records"]
    first, finish = bisect.bisect_left(times, START), bisect.bisect_left(times, END)
    _need(first >= 200 and finish > first, "COMPLETE_FIXED_MONTH_AND_200_ORIGINAL_WARMUP_REQUIRED")
    loaded_first = first - 200
    warmup, evaluated, loaded = records[loaded_first:first], records[first:finish], records[loaded_first:finish]
    loaded_times = times[loaded_first:finish]
    audit = {"loaded_missing_utc": missing_times(pd.Series(loaded_times)),
        "warmup_internal_missing_utc": missing_times(pd.Series(times[loaded_first:first])),
        "evaluation_internal_missing_utc": missing_times(pd.Series(times[first:finish])),
        "left_boundary_missing_utc": missing_times(pd.Series([START - native.STEP, times[first]])),
        "right_boundary_missing_utc": missing_times(pd.Series([times[finish - 1], END])),
        "boundary_probes_are_not_exported_candles_or_observations": True}
    deadline.checkpoint("after_fixed_complete_month_calendar_audit")
    _need(not any(audit[key] for key in audit if key.endswith("missing_utc")),
          "FIXED_OCTOBER_MONTH_OR_WARMUP_OR_BOUNDARY_HAS_REAL_CALENDAR_GAPS:" + json.dumps(audit, sort_keys=True))
    raw = source["header"] + b"".join(loaded)
    record = {"filename": WINDOW_KEY + ".csv", "sha256": _sha(raw),
        "raw_header_sha256": _sha(source["header"]), "header_and_bom_preserved": True,
        "warmup_raw_rows_sha256": _sha(b"".join(warmup)), "evaluation_raw_rows_sha256": _sha(b"".join(evaluated)),
        "evaluation_csv_sha256": _sha(source["header"] + b"".join(evaluated)),
        "original_start_row": loaded_first, "original_evaluation_start_row": first,
        "original_end_row_exclusive": finish, "loaded_rows": len(loaded), "warmup_rows": 200,
        "evaluated_rows": len(evaluated), "loaded_start_utc": native._utc(times[loaded_first]),
        "warmup_end_utc": native._utc(times[first - 1]), "evaluation_start_utc": native._utc(times[first]),
        "evaluation_last_open_utc": native._utc(times[finish - 1]),
        "evaluation_last_close_utc": native._utc(times[finish - 1] + native.STEP),
        "declared_start_utc": native._utc(START), "declared_end_utc_exclusive": native._utc(END),
        "loaded_availability": native._availability(loaded, source["columns"]),
        "warmup_availability": native._availability(warmup, source["columns"]),
        "evaluation_availability": native._availability(evaluated, source["columns"]),
        "all_raw_source_cells_unchanged": True, "missing_quotes_preserved": True,
        "synthetic_candles_or_volume_added": False, "calendar_audit": audit}
    return raw, record


def _material(folder, deadline):
    folder = Path(folder).resolve()
    _need(folder != (ROOT / ".runtime").resolve() and folder.is_relative_to((ROOT / ".runtime").resolve()),
          "ISOLATED_COMPLETE_MONTH_RUNTIME_SUBFOLDER_REQUIRED")
    source = native._source(deadline)
    missing_times, calendar_owner = native._calendar_owner()
    raw, record = _derive_month(source, missing_times, deadline)
    _need(all(record[key] == value for key, value in EXPECTED_NATIVE_MONTH.items()),
          "EXACT_FIXED_NATIVE_OCTOBER_ROWS_AND_RAW_BYTES_REQUIRED")
    parent = source["manifest"]
    upstream = Path(native.__file__).resolve()
    manifest = {"protocol": PROTOCOL, "producer_path": str(Path(__file__).resolve()),
        "producer_sha256": _sha(Path(__file__).read_bytes()), "raw_parent_owner_path": str(upstream),
        "raw_parent_owner_sha256": _sha(upstream.read_bytes()),
        "source_path": str(source["path"]), "source_sha256": native.PARENT_SHA256,
        "source_manifest_path": str(source["manifest_path"]), "source_manifest_sha256": native.PARENT_MANIFEST_SHA256,
        "source_bundle": native.PARENT_BUNDLE, "source_rows": len(source["records"]),
        "source_provider": "dukascopy", "source_price_side": "BID",
        "timestamp_semantics": native.TIMESTAMP_SEMANTICS, "calendar_owner": calendar_owner,
        "selection_rule": "fixed_entire_2025_10_01_UTC_to_2025_11_01_exclusive_plus_200_actual_original_warmup_no_alternate_month",
        "date_policy": {"research_exclusive_end_utc": "2026-01-01T00:00:00Z", "all_2026_and_later": "paper_only"},
        "volume_provenance": parent["volume_provenance"],
        "volume_limit": "legacy_tick_volume_label_is_provider_BID_volume_millions_not_exchange_traded_volume_tick_count_orderflow_or_depth",
        "source_quote_provider": parent["quote_spread_provenance"]["provider"],
        "source_quote_artifact_identities": [item["identity_hash"] for item in parent["quote_spread_provenance"]["sources"]],
        "quote_observation": "last_synchronized_bid_ask_tick_strictly_before_M5_close_not_broker_fill",
        "maximum_quote_age_ms": 60000, "observed_quote_absence_is_not_filled_by_modeled_execution_cost": True,
        "network_requests": 0, "original_archive_modified": False, "other_months_not_exported_or_certified": True,
        "windows": {WINDOW_KEY: {**record, "path": str(folder / record["filename"]) }},
        **native.AUTHORITY, "research_only": True, "own_agent_outcome": False, "credit": False, "mastery": False,
        "untouched_status": "not_proven_reused_archive_discovery_only"}
    deadline.checkpoint("before_complete_month_material_return")
    return {record["filename"]: raw}, manifest


def prepare(folder):
    """Publish one new fixed month only after every source/calendar check passes."""
    deadline = AbsoluteResearchDeadline.start(600)
    folder = Path(folder).resolve()
    _need(not folder.exists(), "NEW_EXCLUSIVE_COMPLETE_MONTH_FOLDER_REQUIRED")
    payloads, manifest = _material(folder, deadline)
    payloads["provenance-manifest.json"] = _encoded(manifest)
    deadline.checkpoint("before_exclusive_complete_month_publication")
    folder.parent.mkdir(parents=True, exist_ok=True)
    folder.mkdir(exist_ok=False)
    for name, raw in payloads.items():
        with (folder / name).open("xb") as output:
            _need(output.write(raw) == len(raw), "COMPLETE_EXCLUSIVE_MONTH_WRITE_REQUIRED")
        _need((folder / name).read_bytes() == raw, "EXACT_PUBLISHED_MONTH_BYTES_REQUIRED")
    deadline.checkpoint("after_exclusive_complete_month_publication")
    return {"manifest_path": str(folder / "provenance-manifest.json"),
        "manifest_sha256": _sha(payloads["provenance-manifest.json"]), "windows": manifest["windows"]}


def validate_manifest(path, expected):
    """Reopen original pinned data/owners and fully rederive the fixed month."""
    deadline = AbsoluteResearchDeadline.start(600)
    path = Path(path).resolve()
    _need(path.name == "provenance-manifest.json", "EXACT_COMPLETE_MONTH_MANIFEST_FILENAME_REQUIRED")
    raw = path.read_bytes()
    _need(isinstance(expected, str) and len(expected) == 64 and _sha(raw) == expected,
          "EXACT_ORIGINAL_COMPLETE_MONTH_MANIFEST_SHA_REQUIRED")
    manifest = json.loads(raw)
    payloads, derived = _material(path.parent, deadline)
    _need(manifest == derived and raw == _encoded(derived), "ORIGINAL_COMPLETE_MONTH_REDERIVATION_REQUIRED")
    for name, content in payloads.items():
        _need((path.parent / name).read_bytes() == content, "EXACT_COMPLETE_MONTH_PARENT_SLICE_REQUIRED")
    deadline.checkpoint("after_complete_month_manifest_reinspection")
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument("--prepare", action="store_true")
    action.add_argument("--validate", action="store_true")
    parser.add_argument("--folder")
    parser.add_argument("--manifest")
    parser.add_argument("--expected")
    args = parser.parse_args()
    if args.prepare:
        _need(bool(args.folder), "NEW_COMPLETE_MONTH_FOLDER_REQUIRED")
        result = prepare(args.folder)
    else:
        _need(bool(args.manifest and args.expected), "ORIGINAL_COMPLETE_MONTH_MANIFEST_AND_HASH_REQUIRED")
        result = validate_manifest(args.manifest, args.expected)
    print(json.dumps(result, sort_keys=True, ensure_ascii=False, allow_nan=False))


if __name__ == "__main__":
    main()
