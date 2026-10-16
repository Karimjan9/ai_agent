"""Calendar-only raw native windows; no replay, download or scientific authority."""
from __future__ import annotations

import argparse
import ast
import bisect
import calendar
import csv
from datetime import datetime, timedelta, timezone
import hashlib
import json
import math
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
MAIN = ROOT.parent.parent if ROOT.parent.name == ".runtime" else ROOT
sys.path.insert(0, str(ROOT / "ai-service-python"))
sys.dont_write_bytecode = True
import pandas as pd

from scripts.curator_research_deadline import AbsoluteResearchDeadline

PROTOCOL = "curator_instrument_calendar_windows_v1"
PARENT_BUNDLE = "81a2c184801348d5107077165423acad72f53f4785f68ce0445aa89ffa94f435"
PARENT_SHA256 = "0d030418beba5191a05c0ec0e3fd59db28e9a6887e168024b4227edebb05135d"
PARENT_MANIFEST_SHA256 = "4e3587b15d414589f9e00de7682e015ebc7da9355f64f8f71818dde4cf939374"
AUDIT_SHA256 = "e0fd72637df6735fde9f5fa8f47017f57cdbd8d6c54febb0068be9da7079f19d"
TIMESTAMP_SEMANTICS = "original_M5_open_in_UTC_decision_inputs_available_only_after_closure"
MONTH_GROUPS = ((4, 5, 6), (8,))
WARMUP_ROWS = 200
UTC = timezone.utc
STEP = timedelta(minutes=5)
AUTHORITY = {"replay_started": False, "outcomes_read": False, "independent_validation": False,
    "economic_skill": False, "paper_authority": False, "live_authority": False,
    "order_flow_or_depth_inferred": False, "whole_source_continuity_claimed": False}


def _need(ok, reason):
    if not ok:
        raise ValueError(reason)


def _sha(raw):
    return hashlib.sha256(raw).hexdigest()


def _encoded(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2, allow_nan=False) + "\n").encode()


def _utc(value):
    return value.astimezone(UTC).isoformat().replace("+00:00", "Z")


def _stamp(value):
    stamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
    return stamp.replace(tzinfo=UTC) if stamp.tzinfo is None else stamp.astimezone(UTC)


def _fields(raw, *, header=False):
    rows = list(csv.reader([raw.decode("utf-8-sig" if header else "utf-8")], strict=True))
    _need(len(rows) == 1, "ONE_PHYSICAL_ORIGINAL_CSV_RECORD_REQUIRED")
    return rows[0]


def _parse_rows(raw, expected_rows, deadline=None):
    lines = raw.splitlines(keepends=True)
    _need(len(lines) == expected_rows + 1, "EXACT_ORIGINAL_PARENT_ROWS_REQUIRED")
    header = _fields(lines[0], header=True)
    required = {"time", "open", "high", "low", "close", "volume", "volume_available", "spread_available",
        "spread", "bid_close", "ask_close", "quote_time_utc", "quote_available_after_utc", "quote_age_ms"}
    _need(len(set(header)) == len(header) and required <= set(header), "EXACT_ORIGINAL_CSV_FIELDS_REQUIRED")
    columns, times = {key: header.index(key) for key in header}, []
    for row in lines[1:]:
        values = _fields(row)
        _need(len(values) == len(header), "COMPLETE_ORIGINAL_CSV_CELLS_REQUIRED")
        stamp = _stamp(values[columns["time"]])
        _need(stamp + STEP < datetime(2026, 1, 1, tzinfo=UTC)
            and stamp.second == 0 and stamp.minute % 5 == 0
            and (not times or times[-1] < stamp), "ORDERED_ORIGINAL_PRE2026_M5_CLOCK_REQUIRED")
        times.append(stamp)
        if deadline is not None and len(times) % 2048 == 0:
            deadline.checkpoint("during_exact_native_parent_parse")
    return {"header": lines[0], "records": lines[1:], "times": times, "columns": columns}


def _source(deadline):
    parent = MAIN / "backend-laravel/storage/app/lab-datasets/mtf" / PARENT_BUNDLE
    path, manifest_path = parent / "m5.csv", parent / "manifest.json"
    raw, manifest_raw = path.read_bytes(), manifest_path.read_bytes()
    _need(_sha(raw) == PARENT_SHA256 and _sha(manifest_raw) == PARENT_MANIFEST_SHA256,
          "EXACT_NATIVE_PARENT_AND_MANIFEST_REQUIRED")
    manifest = json.loads(manifest_raw)
    _need(manifest["provider"] == "dukascopy" and manifest["streams"]["M5"]["sha256"] == PARENT_SHA256
        and manifest["volume_provenance"]["status"] == "passed"
        and manifest["volume_provenance"]["price_side"] == "BID", "NATIVE_PARENT_PROVENANCE_REQUIRED")
    parsed = _parse_rows(raw, 200000, deadline)
    deadline.checkpoint("after_exact_native_parent_parse")
    return {**parsed, "path": path.resolve(), "manifest_path": manifest_path.resolve(),
        "manifest": manifest, "sha256": PARENT_SHA256, "manifest_sha256": PARENT_MANIFEST_SHA256}


def _calendar_owner():
    from app.services.backtester import _is_scheduled_market_closure, _is_expected_market_candle
    path = ROOT / "backend-laravel/scripts/audit-frozen-m5-gap-source.py"
    raw = path.read_bytes()
    _need(_sha(raw) == AUDIT_SHA256, "EXACT_CANONICAL_MISSING_TIMES_OWNER_REQUIRED")
    tree = ast.parse(raw)
    nodes = [node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == "missing_times"]
    _need(len(nodes) == 1, "ONE_CANONICAL_MISSING_TIMES_FUNCTION_REQUIRED")
    namespace = {"pd": pd, "_is_scheduled_market_closure": _is_scheduled_market_closure,
        "_is_expected_market_candle": _is_expected_market_candle}
    exec(compile(ast.Module(body=nodes, type_ignores=[]), str(path), "exec"), namespace)
    native = ROOT / "ai-service-python/app/services/backtester.py"
    return namespace["missing_times"], {"audit_owner_path": str(path.resolve()), "audit_owner_sha256": AUDIT_SHA256,
        "missing_times_ast_sha256": _sha(ast.get_source_segment(raw.decode(), nodes[0]).encode()),
        "native_calendar_owner_path": str(native.resolve()), "native_calendar_owner_sha256": _sha(native.read_bytes()),
        "calendar_semantics": "unchanged_native_XAUUSD_expected_candles_and_scheduled_closures"}


def _weeks(month):
    first = datetime(2025, month, 1, tzinfo=UTC)
    monday = first + timedelta(days=(-first.weekday()) % 7)
    while monday.month == month and (monday + timedelta(days=4)).month == month:
        yield monday, monday + timedelta(days=5)
        monday += timedelta(days=7)


def _availability(records, columns):
    report = {"rows": len(records), "observed_quote_rows": 0, "unavailable_quote_rows": 0,
        "available_volume_rows": 0, "unavailable_volume_rows": 0}
    for raw in records:
        row = _fields(raw)
        quote, volume = row[columns["spread_available"]], row[columns["volume_available"]]
        _need(quote in ("0", "1") and volume in ("0", "1"), "EXPLICIT_ORIGINAL_AVAILABILITY_REQUIRED")
        report["available_volume_rows" if volume == "1" else "unavailable_volume_rows"] += 1
        report["observed_quote_rows" if quote == "1" else "unavailable_quote_rows"] += 1
        amount = float(row[columns["volume"]])
        _need(math.isfinite(amount) and amount >= 0, "FINITE_ORIGINAL_PROVIDER_VOLUME_REQUIRED")
        if quote == "0":
            continue
        opened = _stamp(row[columns["time"]])
        closed = opened + STEP
        observed, available = _stamp(row[columns["quote_time_utc"]]), _stamp(row[columns["quote_available_after_utc"]])
        bid, ask, spread, close, age = (float(row[columns[key]]) for key in ("bid_close", "ask_close", "spread", "close", "quote_age_ms"))
        _need(all(math.isfinite(value) for value in (bid, ask, spread, close, age))
            and opened <= observed < closed and available == closed and 0 < age <= 60000
            and age == round((closed - observed).total_seconds() * 1000)
            and 0 < bid <= ask and spread >= 0 and abs(bid - close) <= 1e-6 and abs(ask - bid - spread) <= 1e-6,
            "ORIGINAL_ASOF_QUOTE_BINDING_REQUIRED")
    return report


def _select(source, missing_times, deadline):
    times, records, audit, selected = source["times"], source["records"], [], {}
    for months in MONTH_GROUPS:
        found = False
        for month in months:
            month_record = {"month": f"2025-{month:02}", "status": "not_reached_earlier_month_selected" if found else "no_eligible_week",
                "candidate_weeks": []}
            audit.append(month_record)
            if found:
                continue
            for start, end in _weeks(month):
                first, finish = bisect.bisect_left(times, start), bisect.bisect_left(times, end)
                item = {"declared_start_utc": _utc(start), "declared_end_utc_exclusive": _utc(end),
                    "original_evaluation_start_row": first, "original_end_row_exclusive": finish,
                    "evaluated_rows": finish - first, "refusal": None}
                month_record["candidate_weeks"].append(item)
                if finish <= first:
                    item["refusal"] = "no_actual_week_rows"
                    continue
                if first < WARMUP_ROWS:
                    item["refusal"] = "fewer_than_200_original_warmup_rows"
                    continue
                loaded_first = first - WARMUP_ROWS
                loaded = times[loaded_first:finish]
                item["loaded_missing_utc"] = missing_times(pd.Series(loaded))
                # Virtual boundary probes are audit coordinates only. They are
                # never exported as candles or used as strategy observations.
                item["boundary_missing_utc"] = missing_times(pd.Series([start - STEP, times[first]])) \
                    + missing_times(pd.Series([times[finish - 1], end]))
                deadline.checkpoint("after_calendar_candidate_week_audit")
                if item["loaded_missing_utc"] or item["boundary_missing_utc"]:
                    item["refusal"] = "unexpected_missing_in_complete_week_or_200_warmup"
                    continue
                key = calendar.month_name[month].lower() + "2025"
                warmup, evaluated = records[loaded_first:first], records[first:finish]
                raw = source["header"] + b"".join(records[loaded_first:finish])
                selected[key] = {"filename": key + ".csv", "sha256": _sha(raw),
                    "raw_header_sha256": _sha(source["header"]), "header_and_bom_preserved": True,
                    "warmup_raw_rows_sha256": _sha(b"".join(warmup)), "evaluation_raw_rows_sha256": _sha(b"".join(evaluated)),
                    "original_start_row": loaded_first, "original_evaluation_start_row": first, "original_end_row_exclusive": finish,
                    "loaded_rows": finish - loaded_first, "warmup_rows": WARMUP_ROWS, "evaluated_rows": finish - first,
                    "loaded_start_utc": _utc(times[loaded_first]), "warmup_end_utc": _utc(times[first - 1]),
                    "evaluation_start_utc": _utc(times[first]), "evaluation_last_open_utc": _utc(times[finish - 1]),
                    "evaluation_last_close_utc": _utc(times[finish - 1] + STEP),
                    "declared_start_utc": _utc(start), "declared_end_utc_exclusive": _utc(end),
                    "loaded_unexpected_missing_utc": [], "complete_week_boundary_missing_utc": [],
                    "loaded_availability": _availability(records[loaded_first:finish], source["columns"]),
                    "warmup_availability": _availability(warmup, source["columns"]),
                    "evaluation_availability": _availability(evaluated, source["columns"]),
                    "all_raw_source_cells_unchanged": True, "missing_quotes_preserved": True,
                    "synthetic_candles_or_volume_added": False}
                item["selected_window_key"], month_record["status"], found = key, "selected", True
                break
        _need(found, "NO_COMPLETE_CALENDAR_WINDOW_FOR_GROUP:" + ",".join(map(str, months)) + ":" + json.dumps(audit))
    return {"windows": selected, "selection_audit": audit,
        "selection_rule": "first_clean_complete_UTC_Monday_to_Saturday_exclusive_week_in_April_then_May_then_June_2025_and_separately_August_2025",
        "eligibility": "unchanged_native_calendar_only_including_all_200_warmup_rows_and_week_edges_no_price_signal_or_outcome_selection"}


def _material(folder, deadline):
    folder = Path(folder).resolve()
    _need(folder != (ROOT / ".runtime").resolve() and folder.is_relative_to((ROOT / ".runtime").resolve()),
          "ISOLATED_RESEARCH_RUNTIME_SUBFOLDER_REQUIRED")
    source = _source(deadline)
    missing_times, owner = _calendar_owner()
    chosen = _select(source, missing_times, deadline)
    windows, payloads = {}, {}
    for key, record in chosen["windows"].items():
        windows[key] = {**record, "path": str(folder / record["filename"])}
        payloads[record["filename"]] = source["header"] + b"".join(
            source["records"][record["original_start_row"]:record["original_end_row_exclusive"]])
    parent = source["manifest"]
    manifest = {"protocol": PROTOCOL, "producer_path": str(Path(__file__).resolve()), "producer_sha256": _sha(Path(__file__).read_bytes()),
        "source_path": str(source["path"]), "source_sha256": PARENT_SHA256,
        "source_manifest_path": str(source["manifest_path"]), "source_manifest_sha256": PARENT_MANIFEST_SHA256,
        "source_bundle": PARENT_BUNDLE, "source_rows": len(source["records"]), "source_provider": "dukascopy", "source_price_side": "BID",
        "timestamp_semantics": TIMESTAMP_SEMANTICS, "source_first_utc": _utc(source["times"][0]), "source_last_utc": _utc(source["times"][-1]),
        "volume_provenance": parent["volume_provenance"],
        "volume_limit": "legacy_tick_volume_label_is_provider_BID_volume_millions_not_exchange_traded_volume_tick_count_orderflow_or_depth",
        "source_quote_provider": parent["quote_spread_provenance"]["provider"],
        "source_quote_artifact_identities": [item["identity_hash"] for item in parent["quote_spread_provenance"]["sources"]],
        "quote_observation": "last_synchronized_bid_ask_tick_strictly_before_M5_close_not_broker_fill",
        "maximum_quote_age_ms": 60000, "network_requests": 0, "original_archive_modified": False,
        "calendar_owner": owner, **{key: value for key, value in chosen.items() if key != "windows"},
        "windows": windows, **AUTHORITY, "untouched_status": "not_proven_reused_archive_discovery_only"}
    deadline.checkpoint("before_window_material_return")
    return payloads, manifest


def selection():
    """Read-only fixed-rule selection, including every considered refusal."""
    deadline = AbsoluteResearchDeadline.start(600)
    source = _source(deadline)
    missing_times, owner = _calendar_owner()
    return {**_select(source, missing_times, deadline), "calendar_owner": owner,
        "source_sha256": PARENT_SHA256, "source_manifest_sha256": PARENT_MANIFEST_SHA256, **AUTHORITY}


def extract(window_key):
    """Reopen the fixed original selection; no arbitrary row/time selection API."""
    deadline = AbsoluteResearchDeadline.start(600)
    source = _source(deadline)
    missing_times, _ = _calendar_owner()
    chosen = _select(source, missing_times, deadline)
    _need(window_key in chosen["windows"], "FIXED_SELECTED_WINDOW_KEY_REQUIRED")
    record = chosen["windows"][window_key]
    return {"window": record, "raw": source["header"] + b"".join(
        source["records"][record["original_start_row"]:record["original_end_row_exclusive"]])}


def prepare(folder):
    deadline = AbsoluteResearchDeadline.start(600)
    folder = Path(folder).resolve()
    _need(not folder.exists(), "NEW_EXCLUSIVE_WINDOW_FOLDER_REQUIRED")
    payloads, manifest = _material(folder, deadline)  # Neither output exists unless BOTH windows passed.
    payloads["provenance-manifest.json"] = _encoded(manifest)
    deadline.checkpoint("before_exclusive_window_publication")
    folder.parent.mkdir(parents=True, exist_ok=True)
    folder.mkdir(exist_ok=False)
    for name, raw in payloads.items():
        with (folder / name).open("xb") as output:
            _need(output.write(raw) == len(raw), "COMPLETE_EXCLUSIVE_WINDOW_WRITE_REQUIRED")
        _need((folder / name).read_bytes() == raw, "EXACT_PUBLISHED_WINDOW_BYTES_REQUIRED")
    deadline.checkpoint("after_exclusive_window_publication")
    return {"manifest_path": str(folder / "provenance-manifest.json"),
        "manifest_sha256": _sha(payloads["provenance-manifest.json"]), "windows": manifest["windows"]}


def validate_manifest(path, expected):
    """Re-derive source, calendar selection, refusals, metadata and raw slices."""
    deadline = AbsoluteResearchDeadline.start(600)
    path = Path(path).resolve()
    _need(path.name == "provenance-manifest.json", "EXACT_WINDOW_MANIFEST_FILENAME_REQUIRED")
    raw = path.read_bytes()
    _need(isinstance(expected, str) and len(expected) == 64 and _sha(raw) == expected,
          "EXACT_ORIGINAL_WINDOW_MANIFEST_SHA_REQUIRED")
    manifest = json.loads(raw)
    payloads, derived = _material(path.parent, deadline)
    _need(manifest == derived and raw == _encoded(derived), "INDEPENDENT_ORIGINAL_WINDOW_REDERIVATION_REQUIRED")
    for name, content in payloads.items():
        _need((path.parent / name).read_bytes() == content, "EXACT_ORIGINAL_PARENT_SLICE_REQUIRED:" + name)
    deadline.checkpoint("after_window_manifest_reinspection")
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--prepare", action="store_true")
    modes.add_argument("--selection", action="store_true")
    modes.add_argument("--validate", action="store_true")
    parser.add_argument("--folder")
    parser.add_argument("--manifest")
    parser.add_argument("--expected")
    args = parser.parse_args()
    if args.prepare:
        _need(bool(args.folder), "NEW_WINDOW_FOLDER_REQUIRED")
        result = prepare(args.folder)
    elif args.validate:
        _need(bool(args.manifest and args.expected), "ORIGINAL_WINDOW_MANIFEST_AND_HASH_REQUIRED")
        result = validate_manifest(args.manifest, args.expected)
    else:
        result = selection()
    print(json.dumps(result, ensure_ascii=False, sort_keys=True, allow_nan=False))


if __name__ == "__main__":
    main()
