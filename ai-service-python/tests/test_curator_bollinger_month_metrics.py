"""Synthetic full-owner registration tests; no replay or native market proof."""
from contextlib import ExitStack
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from scripts import curator_bollinger_month_window as month
import test_curator_bollinger_month_window as fixtures

_SPEC = importlib.util.spec_from_file_location("curator_scalp_month_metrics",
    Path(__file__).resolve().parents[1] / "scripts/curator_scalp_metrics.py")
metrics = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(metrics)


class CuratorBollingerMonthMetricsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixtures.CuratorBollingerMonthWindowTest.setUpClass()

    def original(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        root, output = Path(folder.name), Path(folder.name) / ".runtime" / "original"
        source = fixtures.CuratorBollingerMonthWindowTest().source()
        _, record = month._derive_month(source, fixtures.synthetic_missing, fixtures.NoDeadline())
        expected = {key: record[key] for key in month.EXPECTED_NATIVE_MONTH}
        stack = ExitStack()
        self.addCleanup(stack.close)
        stack.enter_context(patch.object(month, "ROOT", root))
        stack.enter_context(patch.object(month.native, "_source", return_value=source))
        stack.enter_context(patch.object(month.native, "_calendar_owner", return_value=(fixtures.synthetic_missing, {"synthetic": True})))
        stack.enter_context(patch.object(month, "EXPECTED_NATIVE_MONTH", expected))
        # Synthetic trust fixture only. The actual namespace validator below
        # still rederives the complete month, cells, owners and metadata.
        prepared = month.prepare(output)
        window = prepared["windows"]["october2025"]
        provenance = {"manifest_path": prepared["manifest_path"], "manifest_sha256": prepared["manifest_sha256"],
            "window_key": "october2025"}
        return window, provenance

    def binding(self, window, provenance):
        return metrics._registered_csv_binding(window["path"], window["sha256"],
            {"replay_dataset_hash": window["sha256"]}, window["loaded_rows"], provenance)

    def test_real_named_month_validator_runs_before_registration_acceptance(self):
        window, provenance = self.original()
        with patch.object(month, "validate_manifest", wraps=month.validate_manifest) as owner:
            explicit = self.binding(window, provenance)
            automatic = self.binding(window, None)
        self.assertEqual(owner.call_count, 2)
        self.assertEqual(explicit, automatic)
        self.assertEqual(explicit["protocol"], "rederived_original_complete_month_csv_utc_registration_v1")
        self.assertEqual(explicit["window_key"], "october2025")
        self.assertEqual(owner.call_args.args[1], provenance["manifest_sha256"])

    def test_protocol_and_updated_caller_hash_cannot_grant_forged_authority(self):
        window, provenance = self.original()
        path = Path(provenance["manifest_path"])
        manifest = json.loads(path.read_bytes())
        manifest["economic_skill"] = True
        path.write_bytes(month._encoded(manifest))
        forged = {**provenance, "manifest_sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
        with self.assertRaisesRegex(ValueError, "COMPLETE_MONTH_REDERIVATION"):
            self.binding(window, forged)

    def test_changed_original_csv_is_refused_even_with_matching_protocol(self):
        window, provenance = self.original()
        path = Path(window["path"])
        path.write_bytes(path.read_bytes().replace(b"100.00", b"100.01", 1))
        with self.assertRaisesRegex(ValueError, "EXACT_COMPLETE_MONTH_PARENT_SLICE"):
            self.binding(window, provenance)

    def test_exact_window_path_key_rows_and_request_hash_stay_required(self):
        window, provenance = self.original()
        path = Path(window["path"])
        copied = path.with_name("sideways.csv")
        copied.write_bytes(path.read_bytes())
        with self.assertRaisesRegex(ValueError, "EXACT_REGISTERED_CSV_PATH"):
            self.binding({**window, "path": str(copied)}, provenance)
        with self.assertRaisesRegex(ValueError, "EXACT_REGISTERED_CSV_PATH"):
            self.binding(window, {**provenance, "window_key": "november2025"})
        with self.assertRaisesRegex(ValueError, "EXACT_REGISTERED_CSV_BYTES"):
            self.binding({**window, "loaded_rows": window["loaded_rows"] - 1}, provenance)
        with self.assertRaisesRegex(ValueError, "EXACT_REGISTERED_CSV_BYTES"):
            metrics._registered_csv_binding(window["path"], window["sha256"],
                {"replay_dataset_hash": "f" * 64}, window["loaded_rows"], provenance)

    def test_bad_caller_hash_refuses_before_validator_and_witness_stamps_stay_strict(self):
        window, provenance = self.original()
        with patch.object(month, "validate_manifest", wraps=month.validate_manifest) as owner:
            with self.assertRaisesRegex(ValueError, "TRUSTED_CSV_MANIFEST"):
                self.binding(window, {**provenance, "manifest_sha256": "f" * 64})
        owner.assert_not_called()
        with self.assertRaisesRegex(ValueError, "SCALP_UTC_TIMESTAMP_REQUIRED"):
            metrics._stamp("2025-10-01 00:00:00")


if __name__ == "__main__":
    unittest.main()
