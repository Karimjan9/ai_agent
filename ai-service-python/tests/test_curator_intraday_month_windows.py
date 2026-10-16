"""Synthetic custody/calendar checks; not native market or economic evidence."""
from contextlib import ExitStack
import copy
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import pandas as pd

_SPEC = importlib.util.spec_from_file_location("curator_intraday_month_windows",
    Path(__file__).resolve().parents[1] / "scripts/curator_intraday_month_windows.py")
windows = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(windows)


class NoDeadline:
    def checkpoint(self, stage):
        return {"stage": stage}


def synthetic_missing(scope):
    """A tiny synthetic weekday09-10 calendar; never used by production."""
    missing = []
    for previous, current in zip(scope.array[:-1], scope.array[1:]):
        stamp = pd.Timestamp(previous) + pd.Timedelta(minutes=5)
        while stamp < pd.Timestamp(current):
            if stamp.weekday() < 5 and stamp.hour == 9:
                missing.append(stamp.isoformat())
            stamp += pd.Timedelta(minutes=5)
    return missing


class CuratorIntradayMonthWindowsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        all_times = pd.date_range("2024-12-01T00:00:00Z", "2025-09-30T23:55:00Z", freq="5min")
        cls.base_times = [stamp.to_pydatetime() for stamp in all_times if stamp.weekday() < 5 and stamp.hour == 9]
        cls.q1_missing = [f"2025-01-02T09:{minute:02}:00Z" for minute in (0, 5)] \
            + [f"2025-02-03T09:{minute:02}:00Z" for minute in (0, 5, 10)] \
            + [f"2025-03-03T09:{minute:02}:00Z" for minute in range(0, 40, 5)]
        cls.header = b'\xef\xbb\xbftime,open,high,low,close,volume,volume_available,spread_available,spread,bid_close,ask_close,quote_time_utc,quote_available_after_utc,quote_age_ms\r\n'
        cls.columns = {key: index for index, key in enumerate(windows.native._fields(cls.header, header=True))}

    def source(self, extra_missing=()):
        omitted = {pd.Timestamp(value).to_pydatetime() for value in [*self.q1_missing, *extra_missing]}
        times = [stamp for stamp in self.base_times if stamp not in omitted]
        rows = [f'"{stamp:%Y-%m-%d %H:%M:%S}",100.00,101.0,99.00,100.0,1.2300,1,0,,,,,,\r\n'.encode() for stamp in times]
        return {"header": self.header, "times": times, "records": rows, "columns": self.columns,
            "path": Path("synthetic-parent.csv"), "manifest_path": Path("synthetic-parent.json"),
            "manifest": {"volume_provenance": {"synthetic": True},
                "quote_spread_provenance": {"provider": "synthetic_unavailable", "sources": []}}}

    def setup_public_fixture(self, root, source, owner=None):
        expected = {}
        for key, month in windows.WINDOW_MONTHS.items():
            record, _ = windows._month_record(source, month, synthetic_missing, NoDeadline())
            expected[key] = {field: record[field] for field in windows.EXPECTED_WINDOWS[key]}
        stack = ExitStack()
        self.addCleanup(stack.close)
        stack.enter_context(patch.object(windows, "ROOT", root))
        stack.enter_context(patch.object(windows.native, "_source", return_value=source))
        stack.enter_context(patch.object(windows.native, "_calendar_owner",
            return_value=(synthetic_missing, owner if owner is not None else {"synthetic_owner": "original"})))
        # Only tests replace the fixed trust fixture; callers cannot supply
        # different sources, months, calendars or expected native hashes.
        stack.enter_context(patch.object(windows, "EXPECTED_WINDOWS", expected))

    def test_fixed_native_roster_and_trust_pins(self):
        self.assertEqual(windows.PROTOCOL, "curator_intraday_replication_windows_v1")
        self.assertEqual(windows.selected_keys(), ("june2025", "september2025"))
        self.assertEqual(windows.EXPECTED_WINDOWS["june2025"]["evaluated_rows"], 5789)
        self.assertEqual(windows.EXPECTED_WINDOWS["september2025"]["evaluated_rows"], 6041)
        self.assertEqual(windows.EXPECTED_REFUSAL_COUNTS, {1: 2, 2: 3, 3: 8})
        self.assertEqual(windows.native.PARENT_SHA256, "0d030418beba5191a05c0ec0e3fd59db28e9a6887e168024b4227edebb05135d")

    def test_full_month_rows_cells_bom_and_actual200_warmup_are_preserved(self):
        source = self.source()
        record, raw = windows._month_record(source, 6, synthetic_missing, NoDeadline())
        self.assertEqual(record["declared_start_utc"], "2025-06-01T00:00:00Z")
        self.assertEqual(record["declared_end_utc_exclusive"], "2025-07-01T00:00:00Z")
        self.assertEqual(record["warmup_rows"], 200)
        self.assertEqual(record["loaded_rows"], record["evaluated_rows"] + 200)
        self.assertEqual(raw, self.header + b"".join(source["records"][record["original_start_row"]:record["original_end_row_exclusive"]]))
        self.assertIn(b"100.00,101.0,99.00,100.0,1.2300", raw)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), record["sha256"])
        self.assertEqual(record["loaded_availability"]["observed_quote_rows"], 0)
        self.assertFalse(record["synthetic_candles_or_volume_added"])

    def test_q1_refusal_counts_and_exact_missing_times_rederive_without_exports(self):
        source = self.source()
        with tempfile.TemporaryDirectory() as folder:
            self.setup_public_fixture(Path(folder), source)
            selected, refusals, payloads = windows._selection(source, synthetic_missing, NoDeadline())
        self.assertEqual(set(selected), {"june2025", "september2025"})
        self.assertEqual(set(payloads), {"june2025.csv", "september2025.csv"})
        self.assertEqual([refusals[key]["calendar_audit"]["unexpected_missing_count"] for key in
            ("january2025", "february2025", "march2025")], [2, 3, 8])
        self.assertTrue(all(record["exported"] is False for record in refusals.values()))

    def test_gapped_selected_month_never_chooses_another_period_or_creates_output(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "must-not-exist"
            source = self.source(["2025-06-03T09:25:00Z"])
            with patch.object(windows, "ROOT", root), patch.object(windows.native, "_source", return_value=source), \
                    patch.object(windows.native, "_calendar_owner", return_value=(synthetic_missing, {})):
                with self.assertRaisesRegex(ValueError, "FIXED_SELECTED_MONTH_OR_WARMUP_OR_EDGE_HAS_GAPS:june2025"):
                    windows.prepare(output)
            self.assertFalse(output.exists())
            self.assertFalse(output.parent.exists())

    def test_selected_warmup_or_final_month_edge_gap_is_not_hidden(self):
        for missing in ("2025-05-30T09:55:00Z", "2025-09-30T09:55:00Z"):
            source = self.source([missing])
            month = 6 if "05-" in missing else 9
            record, _ = windows._month_record(source, month, synthetic_missing, NoDeadline())
            self.assertFalse(record["clean_complete_month_under_existing_calendar"])
            self.assertIn(pd.Timestamp(missing).isoformat(), record["calendar_audit"]["unexpected_missing_utc"])

    def test_insufficient_200_actual_warmup_refuses_even_if_month_complete(self):
        source = self.source()
        june = next(index for index, stamp in enumerate(source["times"]) if stamp.month == 6)
        source["times"], source["records"] = source["times"][june - 99:], source["records"][june - 99:]
        with self.assertRaisesRegex(ValueError, "200_ORIGINAL_WARMUP"):
            windows._month_record(source, 6, synthetic_missing, NoDeadline())

    def test_publication_reinspection_only_two_csv_and_no_authority(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            self.setup_public_fixture(root, self.source())
            result = windows.prepare(output)
            manifest = windows.validate_manifest(result["manifest_path"], result["manifest_sha256"])
            self.assertEqual(set(path.name for path in output.iterdir()), {"june2025.csv", "september2025.csv", "provenance-manifest.json"})
            self.assertFalse(manifest["independent_validation"])
            self.assertFalse(manifest["chronological_holdout"])
            self.assertFalse(manifest["own_agent_outcome"])
            self.assertTrue(manifest["fresh_file_does_not_establish_unused_history"])
            self.assertEqual(manifest["date_policy"]["all_2026_and_later"], "paper_only")
            with self.assertRaisesRegex(ValueError, "EXCLUSIVE_INTRADAY_REPLICATION_FOLDER"):
                windows.prepare(output)

    def test_forged_clean_refusal_or_authority_updated_manifest_hash_still_refuses(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            self.setup_public_fixture(root, self.source())
            result = windows.prepare(output)
            path = Path(result["manifest_path"])
            manifest = windows.validate_manifest(path, result["manifest_sha256"])
            for changed in ("refusal", "authority"):
                forged = copy.deepcopy(manifest)
                if changed == "refusal":
                    forged["refused_months"]["march2025"]["calendar_audit"]["unexpected_missing_utc"] = []
                else:
                    forged["economic_skill"] = True
                path.write_bytes(windows._encoded(forged))
                with self.subTest(changed=changed), self.assertRaisesRegex(ValueError, "REDERIVATION"):
                    windows.validate_manifest(path, hashlib.sha256(path.read_bytes()).hexdigest())

    def test_export_tamper_and_undeclared_failed_month_export_refuse(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            self.setup_public_fixture(root, self.source())
            result = windows.prepare(output)
            path = output / "june2025.csv"
            raw = path.read_bytes()
            path.write_bytes(raw.replace(b"100.00", b"100.01", 1))
            with self.assertRaisesRegex(ValueError, "EXACT_REPLICATION_PARENT_SLICE"):
                windows.validate_manifest(result["manifest_path"], result["manifest_sha256"])
            path.write_bytes(raw)
            (output / "march2025.csv").write_bytes(b"not-an-authorized-export")
            with self.assertRaisesRegex(ValueError, "EXACT_TWO_MONTH_EXPORT_ROSTER"):
                windows.validate_manifest(result["manifest_path"], result["manifest_sha256"])


if __name__ == "__main__":
    unittest.main()
