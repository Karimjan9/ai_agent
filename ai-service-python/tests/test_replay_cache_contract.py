import json
import tempfile
import unittest
from pathlib import Path

from app.main import _candidate_cache_payload, _dataset_dependency_manifest
from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import execution_contract_metadata


class ReplayCacheContractTest(unittest.TestCase):
    def test_candidate_cache_identity_is_independent_of_sibling_contracts(self):
        cohort = SimpleBacktestRequest(
            strategy="all",
            policy_context={
                "trial_ledger": {"context_hash": "same-cohort"},
                "full_replay_runtime_policy": {"original_cohort_size": 4, "max_cohort_size": 2},
                "repair_contracts": {
                    "trend_v1": {"changed_gene": "ema_fast"},
                    "range_v1": {"changed_gene": "range_lookback"},
                },
            },
        )
        candidate = cohort.model_copy(update={
            "strategy": "trend_v1",
            "base_strategy": "trend_v1",
            "strategies": [],
        })

        cached = _candidate_cache_payload(cohort, candidate, "trend_v1")

        self.assertEqual(
            {"trend_v1": {"changed_gene": "ema_fast"}},
            cached.policy_context["repair_contracts"],
        )
        self.assertEqual({"context_hash": "same-cohort"}, cached.policy_context["trial_ledger"])
        self.assertNotIn("full_replay_runtime_policy", cached.policy_context)
        self.assertEqual([], cached.strategies)

    def test_foundation_dataset_participates_in_immutable_cache_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            rolling = root / "rolling.csv"
            foundation = root / "foundation.csv"
            rolling.write_text("time,open,high,low,close,volume\n", encoding="utf-8")
            foundation.write_text("time,open,high,low,close,volume\n", encoding="utf-8")
            (foundation.with_name(foundation.name + ".manifest.json")).write_text(
                json.dumps({"sha256": "foundation-content-hash"}),
                encoding="utf-8",
            )

            payload = SimpleBacktestRequest(
                symbol="XAUUSD",
                timeframe="H1",
                strategy="trend_v1",
                evaluation_mode="replay",
                dataset_path=str(rolling),
                foundation_dataset_path=str(foundation),
            )

            manifest = _dataset_dependency_manifest(payload)

            self.assertIn("foundation_dataset", manifest)
            self.assertEqual(
                "foundation-content-hash",
                manifest["foundation_dataset"]["manifest_sha256"],
            )
            self.assertIn("file_hash", manifest["foundation_dataset"])
            self.assertEqual(str(foundation), manifest["foundation_dataset"]["requested_path"])

    def test_php_execution_contract_hash_preserves_small_float_format(self):
        execution = {
            "spread_points": 12,
            "point_size": 0.00001,
            "commission_percent": 0.01,
            "slippage_points": 2,
            "swap_per_day_percent": 0.002,
            "allowed_sessions_utc": ["1-22"],
            "min_volume": None,
            "intrabar_policy": "conservative",
            "max_gap_multiple": 96,
            "reject_unexpected_gaps": True,
            "stop_loss_percent": 0.5,
            "take_profit_percent": 1,
            "max_leverage": 5,
        }
        payload = SimpleBacktestRequest(
            symbol="EURUSD",
            timeframe="H1",
            execution=execution,
            execution_contract={
                "protocol": "canonical_market_execution_v1",
                "version": "canonical_market_execution_v1",
                "execution_hash": "b1f45ac65c1ceb9e19f35dc924c158479674e3c2b6ef1e25f5d82c209b213950",
                "parameters": execution,
            },
        )

        metadata = execution_contract_metadata(payload)

        self.assertEqual(
            "b1f45ac65c1ceb9e19f35dc924c158479674e3c2b6ef1e25f5d82c209b213950",
            metadata["execution_hash"],
        )
        self.assertTrue(metadata["declared_parameters_match"])
        self.assertEqual("matched", metadata["status"])
        self.assertTrue(metadata["promotion_evidence"])

    def test_sealed_execution_contract_fails_closed_when_parameters_drift(self):
        payload = SimpleBacktestRequest(
            execution={"point_size": 0.00001},
            execution_contract={
                "protocol": "canonical_market_execution_v1",
                "version": "canonical_market_execution_v1",
                "execution_hash": "not-the-canonical-hash",
                "parameters": {"point_size": 0.00002},
            },
        )

        metadata = execution_contract_metadata(payload)

        self.assertEqual("mismatch", metadata["status"])
        self.assertFalse(metadata["declared_parameters_match"])
        self.assertFalse(metadata["promotion_evidence"])


if __name__ == "__main__":
    unittest.main()
