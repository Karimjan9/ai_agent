"""Synthetic complete-month/custody fixtures; no archive replay or qualification."""
from contextlib import ExitStack
import copy
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import pandas as pd

_SPEC = importlib.util.spec_from_file_location("curator_bollinger_month_window",
    Path(__file__).resolve().parents[1] / "scripts/curator_bollinger_month_window.py")
month_owner = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(month_owner)


class NoDeadline:
    def checkpoint(self, stage):
        return {"stage": stage}


def synthetic_missing(scope):
    result = []
    for previous, current in zip(scope.array[:-1], scope.array[1:]):
        stamp = pd.Timestamp(previous) + pd.Timedelta(minutes=5)
        while stamp < pd.Timestamp(current):
            result.append(stamp.isoformat())
            stamp += pd.Timedelta(minutes=5)
    return result


class CuratorBollingerMonthWindowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.times = [value.to_pydatetime() for value in pd.date_range(
            "2025-09-30T00:00:00Z", "2025-10-31T23:55:00Z", freq="5min")]
        cls.header = b'\xef\xbb\xbftime,open,high,low,close,volume,volume_available,spread_available,spread,bid_close,ask_close,quote_time_utc,quote_available_after_utc,quote_age_ms\r\n'
        cls.records = [f'"{value:%Y-%m-%d %H:%M:%S}",100.00,101.0,99.00,100.0,1.2300,1,0,,,,,,\r\n'.encode() for value in cls.times]
        cls.columns = {key: index for index, key in enumerate(month_owner.native._fields(cls.header, header=True))}

    def source(self, omitted=()):
        omitted = {pd.Timestamp(value).to_pydatetime() for value in omitted}
        pairs = [(stamp, raw) for stamp, raw in zip(self.times, self.records) if stamp not in omitted]
        return {"header": self.header, "times": [pair[0] for pair in pairs], "records": [pair[1] for pair in pairs],
            "columns": self.columns, "path": Path("synthetic-parent.csv"), "manifest_path": Path("synthetic-parent.json"),
            "manifest": {"volume_provenance": {"synthetic": True},
                "quote_spread_provenance": {"provider": "synthetic_unavailable", "sources": []}}}

    def setup_public_fixture(self, root, source, owner=None):
        _, record = month_owner._derive_month(source, synthetic_missing, NoDeadline())
        expected = {key: record[key] for key in month_owner.EXPECTED_NATIVE_MONTH}
        stack = ExitStack()
        self.addCleanup(stack.close)
        stack.enter_context(patch.object(month_owner, "ROOT", root))
        stack.enter_context(patch.object(month_owner.native, "_source", return_value=source))
        stack.enter_context(patch.object(month_owner.native, "_calendar_owner",
            return_value=(synthetic_missing, owner if owner is not None else {"synthetic_owner": "original"})))
        # Only synthetic tests inject a replacement trust fixture. Production
        # callers cannot pass alternate source hashes, months or row contracts.
        stack.enter_context(patch.object(month_owner, "EXPECTED_NATIVE_MONTH", expected))

    def test_fixed_native_contract_and_only_october_identity(self):
        self.assertEqual(month_owner.PROTOCOL, "curator_complete_month_window_v1")
        self.assertEqual(month_owner.WINDOW_KEY, "october2025")
        self.assertEqual(month_owner.EXPECTED_NATIVE_MONTH["evaluated_rows"], 6324)
        self.assertEqual(month_owner.EXPECTED_NATIVE_MONTH["loaded_rows"], 6524)
        self.assertEqual(month_owner.EXPECTED_NATIVE_MONTH["sha256"],
            "7e546aa5caae9ceb577654925b205a7b40121349a72f5e344ff80c8bccb911dc")

    def test_full_month_not_two_short_weeks_and_raw_cells_are_exact(self):
        source = self.source()
        raw, record = month_owner._derive_month(source, synthetic_missing, NoDeadline())
        self.assertEqual(record["declared_start_utc"], "2025-10-01T00:00:00Z")
        self.assertEqual(record["declared_end_utc_exclusive"], "2025-11-01T00:00:00Z")
        self.assertEqual(record["evaluated_rows"], 31 * 24 * 12)
        self.assertEqual(record["loaded_rows"], record["evaluated_rows"] + 200)
        self.assertEqual(raw, self.header + b"".join(source["records"][record["original_start_row"]:record["original_end_row_exclusive"]]))
        self.assertTrue(raw.startswith(self.header))
        self.assertIn(b"100.00,101.0,99.00,100.0,1.2300", raw)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), record["sha256"])
        self.assertEqual(record["warmup_availability"]["unavailable_quote_rows"], 200)
        self.assertFalse(record["synthetic_candles_or_volume_added"])

    def test_one_real_gap_cannot_be_skipped_by_selecting_another_week_or_month(self):
        for missing in ("2025-10-14T10:00:00Z", "2025-09-30T23:55:00Z", "2025-10-31T23:55:00Z"):
            with self.subTest(missing=missing), self.assertRaisesRegex(ValueError, "REAL_CALENDAR_GAPS"):
                month_owner._derive_month(self.source([missing]), synthetic_missing, NoDeadline())

    def test_insufficient_actual_200_warmup_refuses_even_full_month(self):
        source = self.source()
        first = source["times"].index(month_owner.START)
        source["times"], source["records"] = source["times"][first - 99:], source["records"][first - 99:]
        with self.assertRaisesRegex(ValueError, "200_ORIGINAL_WARMUP"):
            month_owner._derive_month(source, synthetic_missing, NoDeadline())

    def test_prepare_and_reinspection_are_exclusive_and_no_authority(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            self.setup_public_fixture(root, self.source())
            result = month_owner.prepare(output)
            manifest = month_owner.validate_manifest(result["manifest_path"], result["manifest_sha256"])
            self.assertEqual(set(manifest["windows"]), {"october2025"})
            self.assertEqual(set(path.name for path in output.iterdir()), {"october2025.csv", "provenance-manifest.json"})
            self.assertEqual(manifest["date_policy"]["all_2026_and_later"], "paper_only")
            self.assertFalse(manifest["replay_started"])
            self.assertFalse(manifest["economic_skill"])
            self.assertFalse(manifest["own_agent_outcome"])
            with self.assertRaisesRegex(ValueError, "EXCLUSIVE_COMPLETE_MONTH_FOLDER"):
                month_owner.prepare(output)

    def test_forged_manifest_with_updated_hash_cannot_grant_skill_or_change_clock(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            self.setup_public_fixture(root, self.source())
            result = month_owner.prepare(output)
            path = Path(result["manifest_path"])
            original = month_owner.validate_manifest(path, result["manifest_sha256"])
            for field in ("authority", "clock"):
                forged = copy.deepcopy(original)
                if field == "authority":
                    forged["economic_skill"] = True
                else:
                    forged["windows"]["october2025"]["evaluation_start_utc"] = "2025-10-02T00:00:00Z"
                path.write_bytes(month_owner._encoded(forged))
                with self.subTest(field=field), self.assertRaisesRegex(ValueError, "REDERIVATION"):
                    month_owner.validate_manifest(path, hashlib.sha256(path.read_bytes()).hexdigest())

    def test_actual_export_or_calendar_owner_drift_is_not_accepted(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "original"
            owner = {"synthetic_owner": "original"}
            self.setup_public_fixture(root, self.source(), owner)
            result = month_owner.prepare(output)
            csv_path = output / "october2025.csv"
            raw = csv_path.read_bytes()
            csv_path.write_bytes(raw.replace(b"100.00", b"100.01", 1))
            with self.assertRaisesRegex(ValueError, "EXACT_COMPLETE_MONTH_PARENT_SLICE"):
                month_owner.validate_manifest(result["manifest_path"], result["manifest_sha256"])
            csv_path.write_bytes(raw)
            owner["synthetic_owner"] = "changed"
            with self.assertRaisesRegex(ValueError, "REDERIVATION"):
                month_owner.validate_manifest(result["manifest_path"], result["manifest_sha256"])

    def test_failed_month_guard_publishes_no_output_folder(self):
        with tempfile.TemporaryDirectory() as folder:
            root, output = Path(folder), Path(folder) / ".runtime" / "must-not-exist"
            with patch.object(month_owner, "ROOT", root), \
                    patch.object(month_owner.native, "_source", return_value=self.source(["2025-10-14T10:00:00Z"])), \
                    patch.object(month_owner.native, "_calendar_owner", return_value=(synthetic_missing, {})):
                with self.assertRaisesRegex(ValueError, "REAL_CALENDAR_GAPS"):
                    month_owner.prepare(output)
            self.assertFalse(output.exists())
            self.assertFalse(output.parent.exists())


if __name__ == "__main__":
    unittest.main()
