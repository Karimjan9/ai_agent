"""Two fixed whole-month native discovery slices with retained Q1 refusals.

No date search, calendar repair, strategy, PnL, download or replay is performed.
New files do not make previously used market events independent or untouched.
"""
from __future__ import annotations

import argparse
import bisect
import calendar
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

PROTOCOL = "curator_intraday_replication_windows_v1"
WINDOW_MONTHS = {"june2025": 6, "september2025": 9}
WINDOW_KEYS = tuple(WINDOW_MONTHS)
REFUSED_MONTHS = (1, 2, 3)
EXPECTED_REFUSAL_COUNTS = {1: 2, 2: 3, 3: 8}
EXPECTED_WINDOWS = {
    "june2025": {"loaded_rows": 5989, "warmup_rows": 200, "evaluated_rows": 5789,
        "original_start_row": 158082, "original_evaluation_start_row": 158282,
        "original_end_row_exclusive": 164071,
        "sha256": "494cd01a799905f85fcc864c6b75904898aaf0326283a137f7f255ba18a08a02"},
    "september2025": {"loaded_rows": 6241, "warmup_rows": 200, "evaluated_rows": 6041,
        "original_start_row": 175963, "original_evaluation_start_row": 176163,
        "original_end_row_exclusive": 182204,
        "sha256": "bef23cd40a5faab063dd337ea2218db3c192af9a3c44c18534f46d494591d7f8"}}


def _need(ok, reason):
    if not ok:
        raise ValueError(reason)


def _sha(raw):
    return hashlib.sha256(raw).hexdigest()


def _encoded(value):
    return (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=False, allow_nan=False) + "\n").encode()


def selected_keys():
    return WINDOW_KEYS


def _month_record(source, month, missing_times, deadline):
    start, end = datetime(2025, month, 1, tzinfo=timezone.utc), datetime(2025, month + 1, 1, tzinfo=timezone.utc)
    times, rows = source["times"], source["records"]
    first, finish = bisect.bisect_left(times, start), bisect.bisect_left(times, end)
    _need(first >= 200 and finish > first, "WHOLE_FIXED_MONTH_AND_200_ORIGINAL_WARMUP_REQUIRED")
    begin = first - 200
    warm, evaluated, loaded = rows[begin:first], rows[first:finish], rows[begin:finish]
    audit = {"loaded_missing_utc": missing_times(pd.Series(times[begin:finish])),
        "warmup_internal_missing_utc": missing_times(pd.Series(times[begin:first])),
        "evaluation_internal_missing_utc": missing_times(pd.Series(times[first:finish])),
        "left_boundary_missing_utc": missing_times(pd.Series([start - native.STEP, times[first]])),
        "right_boundary_missing_utc": missing_times(pd.Series([times[finish - 1], end])),
        "boundary_probes_are_not_exported_candles_or_observations": True}
    unexpected = sorted({stamp for key, values in audit.items() if key.endswith("missing_utc") for stamp in values})
    audit["unexpected_missing_utc"], audit["unexpected_missing_count"] = unexpected, len(unexpected)
    deadline.checkpoint("after_fixed_month_calendar_audit_" + str(month))
    raw = source["header"] + b"".join(loaded)
    key = calendar.month_name[month].lower() + "2025"
    record = {"month": f"2025-{month:02}", "filename": key + ".csv", "sha256": _sha(raw),
        "raw_header_sha256": _sha(source["header"]), "header_and_bom_preserved": True,
        "warmup_raw_rows_sha256": _sha(b"".join(warm)), "evaluation_raw_rows_sha256": _sha(b"".join(evaluated)),
        "evaluation_csv_sha256": _sha(source["header"] + b"".join(evaluated)),
        "original_start_row": begin, "original_evaluation_start_row": first, "original_end_row_exclusive": finish,
        "loaded_rows": len(loaded), "warmup_rows": 200, "evaluated_rows": len(evaluated),
        "loaded_start_utc": native._utc(times[begin]), "warmup_end_utc": native._utc(times[first - 1]),
        "evaluation_start_utc": native._utc(times[first]), "evaluation_last_open_utc": native._utc(times[finish - 1]),
        "evaluation_last_close_utc": native._utc(times[finish - 1] + native.STEP),
        "declared_start_utc": native._utc(start), "declared_end_utc_exclusive": native._utc(end),
        "calendar_audit": audit, "clean_complete_month_under_existing_calendar": not unexpected,
        "loaded_availability": native._availability(loaded, source["columns"]),
        "warmup_availability": native._availability(warm, source["columns"]),
        "evaluation_availability": native._availability(evaluated, source["columns"]),
        "all_raw_source_cells_unchanged": True, "missing_quotes_preserved": True,
        "synthetic_candles_or_volume_added": False}
    return record, raw


def _selection(source, missing_times, deadline):
    refusals, windows, payloads = {}, {}, {}
    for month in REFUSED_MONTHS:
        record, _ = _month_record(source, month, missing_times, deadline)
        _need(record["calendar_audit"]["unexpected_missing_count"] == EXPECTED_REFUSAL_COUNTS[month]
            and not record["clean_complete_month_under_existing_calendar"], "EXACT_RETAINED_Q1_DATA_REFUSAL_REQUIRED")
        key = calendar.month_name[month].lower() + "2025"
        refusals[key] = {**record, "status": "refused_real_calendar_gaps", "exported": False,
            "selection_history": "March_original_choice_refused_then_January_and_February_fixed_replacements_refused"}
    for key, month in WINDOW_MONTHS.items():
        record, raw = _month_record(source, month, missing_times, deadline)
        _need(record["clean_complete_month_under_existing_calendar"], "FIXED_SELECTED_MONTH_OR_WARMUP_OR_EDGE_HAS_GAPS:" + key)
        _need(all(record[field] == value for field, value in EXPECTED_WINDOWS[key].items()),
              "EXACT_NATIVE_REPLICATION_MONTH_ROWS_AND_BYTES_REQUIRED:" + key)
        windows[key], payloads[record["filename"]] = record, raw
    return windows, refusals, payloads


def _material(folder, deadline):
    folder = Path(folder).resolve()
    _need(folder != (ROOT / ".runtime").resolve() and folder.is_relative_to((ROOT / ".runtime").resolve()),
          "ISOLATED_INTRADAY_REPLICATION_RUNTIME_SUBFOLDER_REQUIRED")
    source = native._source(deadline)
    missing_times, owner = native._calendar_owner()
    windows, refusals, payloads = _selection(source, missing_times, deadline)
    parent, upstream = source["manifest"], Path(native.__file__).resolve()
    manifest = {"protocol": PROTOCOL, "producer_path": str(Path(__file__).resolve()),
        "producer_sha256": _sha(Path(__file__).read_bytes()), "raw_parent_owner_path": str(upstream),
        "raw_parent_owner_sha256": _sha(upstream.read_bytes()),
        "source_path": str(source["path"]), "source_sha256": native.PARENT_SHA256,
        "source_manifest_path": str(source["manifest_path"]), "source_manifest_sha256": native.PARENT_MANIFEST_SHA256,
        "source_bundle": native.PARENT_BUNDLE, "source_rows": len(source["records"]),
        "source_provider": "dukascopy", "source_price_side": "BID", "timestamp_semantics": native.TIMESTAMP_SEMANTICS,
        "calendar_owner": owner, "fixed_month_roster": list(WINDOW_KEYS),
        "selection_rule": "exact_whole_June_and_September_2025_after_retained_March_and_January_February_calendar_refusals_no_further_search",
        "refused_months": refusals,
        "windows": {key: {**record, "path": str(folder / record["filename"])} for key, record in windows.items()},
        "volume_provenance": parent["volume_provenance"],
        "volume_limit": "provider_BID_volume_millions_not_exchange_traded_volume_tick_count_orderflow_or_depth",
        "source_quote_provider": parent["quote_spread_provenance"]["provider"],
        "source_quote_artifact_identities": [item["identity_hash"] for item in parent["quote_spread_provenance"]["sources"]],
        "quote_observation": "original_synchronized_closing_BID_ASK_only_when_available_not_broker_fills",
        "maximum_quote_age_ms": 60000, "missing_quotes_not_filled_by_modeled_costs": True,
        "study_scope": "multiple_period_temporal_replication_discovery_not_independent_or_chronological_holdout",
        "fresh_file_does_not_establish_unused_history": True,
        "date_policy": {"research_exclusive_end_utc": "2026-01-01T00:00:00Z", "all_2026_and_later": "paper_only"},
        **native.AUTHORITY, "research_only": True, "own_agent_outcome": False, "credit": False, "mastery": False,
        "chronological_holdout": False, "original_archive_modified": False, "network_requests": 0}
    deadline.checkpoint("before_intraday_replication_material_return")
    return payloads, manifest


def selection():
    """Read-only fixed roster and complete refusal facts; no output publication."""
    deadline = AbsoluteResearchDeadline.start(600)
    source = native._source(deadline)
    missing_times, owner = native._calendar_owner()
    windows, refusals, _ = _selection(source, missing_times, deadline)
    return {"protocol": PROTOCOL, "windows": windows, "refused_months": refusals, "calendar_owner": owner,
        "source_sha256": native.PARENT_SHA256, "source_manifest_sha256": native.PARENT_MANIFEST_SHA256, **native.AUTHORITY}


def prepare(folder):
    deadline = AbsoluteResearchDeadline.start(600)
    folder = Path(folder).resolve()
    _need(not folder.exists(), "NEW_EXCLUSIVE_INTRADAY_REPLICATION_FOLDER_REQUIRED")
    payloads, manifest = _material(folder, deadline)
    payloads["provenance-manifest.json"] = _encoded(manifest)
    deadline.checkpoint("before_exclusive_intraday_replication_publication")
    folder.parent.mkdir(parents=True, exist_ok=True)
    folder.mkdir(exist_ok=False)
    for name, raw in payloads.items():
        with (folder / name).open("xb") as output:
            _need(output.write(raw) == len(raw), "COMPLETE_EXCLUSIVE_REPLICATION_WRITE_REQUIRED")
        _need((folder / name).read_bytes() == raw, "EXACT_PUBLISHED_REPLICATION_BYTES_REQUIRED")
    deadline.checkpoint("after_exclusive_intraday_replication_publication")
    return {"manifest_path": str(folder / "provenance-manifest.json"),
        "manifest_sha256": _sha(payloads["provenance-manifest.json"]), "windows": manifest["windows"]}


def validate_manifest(path, expected):
    deadline = AbsoluteResearchDeadline.start(600)
    path = Path(path).resolve()
    _need(path.name == "provenance-manifest.json", "EXACT_REPLICATION_MANIFEST_FILENAME_REQUIRED")
    raw = path.read_bytes()
    _need(isinstance(expected, str) and len(expected) == 64 and _sha(raw) == expected,
          "EXACT_ORIGINAL_REPLICATION_MANIFEST_SHA_REQUIRED")
    manifest = json.loads(raw)
    payloads, derived = _material(path.parent, deadline)
    _need(manifest == derived and raw == _encoded(derived), "ORIGINAL_REPLICATION_WINDOW_REDERIVATION_REQUIRED")
    _need({item.name for item in path.parent.iterdir()} == set(payloads) | {"provenance-manifest.json"},
          "EXACT_TWO_MONTH_EXPORT_ROSTER_REQUIRED")
    for name, content in payloads.items():
        _need((path.parent / name).read_bytes() == content, "EXACT_REPLICATION_PARENT_SLICE_REQUIRED:" + name)
    deadline.checkpoint("after_intraday_replication_manifest_reinspection")
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument("--prepare", action="store_true")
    action.add_argument("--validate", action="store_true")
    action.add_argument("--selection", action="store_true")
    parser.add_argument("--folder")
    parser.add_argument("--manifest")
    parser.add_argument("--expected")
    args = parser.parse_args()
    if args.prepare:
        _need(bool(args.folder), "NEW_REPLICATION_FOLDER_REQUIRED")
        result = prepare(args.folder)
    elif args.validate:
        _need(bool(args.manifest and args.expected), "REPLICATION_MANIFEST_AND_EXPECTED_SHA_REQUIRED")
        result = validate_manifest(args.manifest, args.expected)
    else:
        result = selection()
    print(json.dumps(result, sort_keys=True, ensure_ascii=False, allow_nan=False))


if __name__ == "__main__":
    main()
