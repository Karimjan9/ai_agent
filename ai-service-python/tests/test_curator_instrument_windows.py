"""Synthetic calendar/raw-custody tests, not market replay or native-data proof."""
import copy
from datetime import timedelta
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import pandas as pd

_SPEC = importlib.util.spec_from_file_location("curator_instrument_windows",
    Path(__file__).resolve().parents[1] / "scripts/curator_instrument_windows.py")
windows = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(windows)


class NoDeadline:
    def checkpoint(self, stage):
        return {"stage": stage}


def synthetic_missing(scope):
    """Synthetic every-five-minutes calendar; no actual holiday assertions."""
    result = []
    for previous, current in zip(scope.array[:-1], scope.array[1:]):
        stamp = pd.Timestamp(previous) + pd.Timedelta(minutes=5)
        while stamp < pd.Timestamp(current):
            result.append(stamp.isoformat())
            stamp += pd.Timedelta(minutes=5)
    return result


class CuratorInstrumentWindowsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.times = [stamp.to_pydatetime() for stamp in pd.date_range(
            "2025-03-24T00:00:00Z", "2025-08-31T23:55:00Z", freq="5min")]
        cls.header = b'\xef\xbb\xbftime,open,high,low,close,volume,volume_available,spread_available,spread,bid_close,ask_close,quote_time_utc,quote_available_after_utc,quote_age_ms\r\n'
        cls.records = [f'"{stamp:%Y-%m-%d %H:%M:%S}",100.00,101.0,99.00,100.0,1.2300,1,0,,,,,,\r\n'.encode() for stamp in cls.times]
        cls.columns = {key: index for index, key in enumerate(windows._fields(cls.header, header=True))}

    def source(self, *, omitted=(), before=None):
        omitted = {pd.Timestamp(stamp).to_pydatetime() for stamp in omitted}
        pairs = [(stamp, raw) for stamp, raw in zip(self.times, self.records)
            if stamp not in omitted and (before is None or stamp < pd.Timestamp(before))]
        return {"header": self.header, "records": [pair[1] for pair in pairs], "times": [pair[0] for pair in pairs],
            "columns": self.columns, "path": Path("synthetic-main.csv"), "manifest_path": Path("synthetic-manifest.json"),
            "manifest": {"volume_provenance": {"semantic": "tick_volume", "unit": "millions"},
                "quote_spread_provenance": {"provider": "synthetic_unavailable", "sources": []}}}

    def test_exact_trust_roots_and_calendar_function_are_not_caller_flags(self):
        self.assertEqual(windows.PARENT_SHA256, "0d030418beba5191a05c0ec0e3fd59db28e9a6887e168024b4227edebb05135d")
        self.assertEqual(windows.PARENT_MANIFEST_SHA256, "4e3587b15d414589f9e00de7682e015ebc7da9355f64f8f71818dde4cf939374")
        missing, owner = windows._calendar_owner()
        self.assertEqual(owner["audit_owner_sha256"], "e0fd72637df6735fde9f5fa8f47017f57cdbd8d6c54febb0068be9da7079f19d")
        self.assertEqual(missing(pd.Series(pd.to_datetime(["2025-02-04T09:00:00Z", "2025-02-04T09:10:00Z"]))),
            ["2025-02-04T09:05:00+00:00"])

    def test_complete_weeks_are_wholly_inside_month_not_partial_initial_week(self):
        april = list(windows._weeks(4))
        self.assertEqual(windows._utc(april[0][0]), "2025-04-07T00:00:00Z")
        self.assertEqual(len(april), 3)
        self.assertEqual(windows._utc(list(windows._weeks(8))[0][0]), "2025-08-04T00:00:00Z")
        self.assertTrue(all(start.weekday() == 0 and (end - timedelta(days=1)).weekday() == 4
            and start.month == (end - timedelta(days=1)).month for start, end in april))

    def test_first_clean_month_wins_and_unused_months_not_relabelled_clean(self):
        omitted = ["2025-04-06T23:55:00Z", "2025-04-13T23:55:00Z", "2025-04-20T23:55:00Z", "2025-08-03T23:55:00Z"]
        result = windows._select(self.source(omitted=omitted), synthetic_missing, NoDeadline())
        self.assertEqual(set(result["windows"]), {"may2025", "august2025"})
        self.assertEqual(result["windows"]["may2025"]["declared_start_utc"], "2025-05-05T00:00:00Z")
        self.assertEqual(result["windows"]["august2025"]["declared_start_utc"], "2025-08-11T00:00:00Z")
        audit = result["selection_audit"]
        self.assertEqual([row["status"] for row in audit],
            ["no_eligible_week", "selected", "not_reached_earlier_month_selected", "selected"])
        self.assertTrue(all(row["refusal"] for row in audit[0]["candidate_weeks"]))
        self.assertEqual(len(audit[3]["candidate_weeks"]), 2)

    def test_200_warmup_gap_is_not_hidden_by_clean_evaluation_rows(self):
        result = windows._select(self.source(omitted=["2025-04-06T23:55:00Z"]), synthetic_missing, NoDeadline())
        first = result["selection_audit"][0]["candidate_weeks"][0]
        self.assertIn("2025-04-06T23:55:00+00:00", first["loaded_missing_utc"])
        self.assertEqual(first["boundary_missing_utc"], [])
        self.assertEqual(result["windows"]["april2025"]["declared_start_utc"], "2025-04-14T00:00:00Z")

    def test_fewer_than_200_actual_warmup_rows_refuses_otherwise_complete_week(self):
        source = self.source()
        first = source["times"].index(pd.Timestamp("2025-04-07T00:00:00Z").to_pydatetime())
        source["times"], source["records"] = source["times"][first:], source["records"][first:]
        result = windows._select(source, synthetic_missing, NoDeadline())
        self.assertEqual(result["selection_audit"][0]["candidate_weeks"][0]["refusal"],
            "fewer_than_200_original_warmup_rows")
        self.assertEqual(result["windows"]["april2025"]["declared_start_utc"], "2025-04-14T00:00:00Z")

    def test_missing_final_week_edge_is_detected_without_exporting_fake_boundary(self):
        source = self.source(omitted=["2025-04-11T23:55:00Z"])
        result = windows._select(source, synthetic_missing, NoDeadline())
        first = result["selection_audit"][0]["candidate_weeks"][0]
        self.assertEqual(first["loaded_missing_utc"], [])
        self.assertEqual(first["boundary_missing_utc"], ["2025-04-11T23:55:00+00:00"])
        self.assertEqual(result["windows"]["april2025"]["declared_start_utc"], "2025-04-14T00:00:00Z")

    def test_raw_header_bom_cell_spelling_crlf_and_original_slices_preserved(self):
        source = self.source()
        parsed = windows._parse_rows(self.header + b"".join(source["records"][:250]), 250)
        self.assertEqual(parsed["header"], self.header)
        self.assertEqual(parsed["records"], source["records"][:250])
        result = windows._select(source, synthetic_missing, NoDeadline())
        for record in result["windows"].values():
            start, end, first = (record[key] for key in ("original_start_row", "original_end_row_exclusive", "original_evaluation_start_row"))
            raw = self.header + b"".join(source["records"][start:end])
            self.assertTrue(raw.startswith(self.header))
            self.assertIn(b"100.00,101.0,99.00,100.0,1.2300", raw)
            self.assertEqual(hashlib.sha256(raw).hexdigest(), record["sha256"])
            self.assertEqual(first - start, 200)
            self.assertEqual(record["loaded_rows"], record["evaluated_rows"] + 200)
            self.assertEqual(record["loaded_availability"]["observed_quote_rows"], 0)
            self.assertFalse(record["synthetic_candles_or_volume_added"])

    def test_no_output_folder_when_second_required_month_has_no_complete_window(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "no-publication"
            source = self.source(before="2025-07-01T00:00:00Z")
            with patch.object(windows, "ROOT", root), patch.object(windows, "_source", return_value=source), \
                    patch.object(windows, "_calendar_owner", return_value=(synthetic_missing, {"owner": "synthetic"})):
                with self.assertRaisesRegex(ValueError, "NO_COMPLETE_CALENDAR_WINDOW_FOR_GROUP:8"):
                    windows.prepare(output)
            self.assertFalse(output.exists())
            self.assertFalse(output.parent.exists())

    def test_manifest_reinspection_rederives_raw_slices_calendar_and_authority(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            source = self.source()
            with patch.object(windows, "ROOT", root), patch.object(windows, "_source", return_value=source), \
                    patch.object(windows, "_calendar_owner", return_value=(synthetic_missing, {"owner": "synthetic"})):
                original = windows.prepare(output)
                manifest = windows.validate_manifest(original["manifest_path"], original["manifest_sha256"])
                self.assertEqual(set(manifest["windows"]), {"april2025", "august2025"})
                with self.assertRaisesRegex(ValueError, "NEW_EXCLUSIVE_WINDOW_FOLDER"):
                    windows.prepare(output)
                path = Path(original["manifest_path"])
                forged = copy.deepcopy(manifest)
                forged["economic_skill"] = True
                path.write_bytes(windows._encoded(forged))
                with self.assertRaisesRegex(ValueError, "REDERIVATION"):
                    windows.validate_manifest(path, hashlib.sha256(path.read_bytes()).hexdigest())

    def test_unavailable_quotes_remain_unknown_and_marker_or_asof_forgery_refuses(self):
        source = self.source()
        self.assertEqual(windows._availability(source["records"][:1], self.columns)["unavailable_quote_rows"], 1)
        row = source["records"][0].replace(b',1,0,,,,,,', b',1,1,,,,,,')
        with self.assertRaises((ValueError, TypeError)):
            windows._availability([row], self.columns)

    def test_changed_export_or_calendar_owner_cannot_reuse_original_manifest(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            source, owner = self.source(), {"native_calendar_owner_sha256": "synthetic-original"}
            with patch.object(windows, "ROOT", root), patch.object(windows, "_source", return_value=source), \
                    patch.object(windows, "_calendar_owner", return_value=(synthetic_missing, owner)):
                original = windows.prepare(output)
                window_path = Path(next(iter(original["windows"].values()))["path"])
                raw = window_path.read_bytes()
                window_path.write_bytes(raw.replace(b"100.00", b"100.01", 1))
                with self.assertRaisesRegex(ValueError, "EXACT_ORIGINAL_PARENT_SLICE"):
                    windows.validate_manifest(original["manifest_path"], original["manifest_sha256"])
                window_path.write_bytes(raw)
                owner["native_calendar_owner_sha256"] = "synthetic-changed-owner"
                with self.assertRaisesRegex(ValueError, "REDERIVATION"):
                    windows.validate_manifest(original["manifest_path"], original["manifest_sha256"])


if __name__ == "__main__":
    unittest.main()
