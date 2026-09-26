import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from app.main import (
    _bounded_replay_seconds,
    _candidate_cache_payload,
    _candidate_scoped_contract,
    _dataset_dependency_manifest,
    _latest_replay_checkpoint,
    _run_all_backtests_sync,
    _screen_replay_capacity,
    _write_replay_checkpoint,
)
from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import execution_contract_metadata


class ReplayCacheContractTest(unittest.TestCase):
    def test_candidate_contract_prefers_lab_agent_identity_when_strategy_labels_collide(self):
        contracts = {
            "41": {"role": "memory_guided"},
            "42": {"role": "blinded_counterfactual"},
            "shared-strategy": {"role": "legacy-fallback"},
        }

        self.assertEqual(
            "memory_guided",
            _candidate_scoped_contract(contracts, "41", "shared-strategy")["role"],
        )
        self.assertEqual(
            "blinded_counterfactual",
            _candidate_scoped_contract(contracts, "42", "shared-strategy")["role"],
        )
        self.assertEqual(
            "legacy-fallback",
            _candidate_scoped_contract(contracts, "99", "shared-strategy")["role"],
        )

    def test_checkpoint_is_atomic_small_and_marks_no_promotion_evidence(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(
            "os.environ", {"AI_REPLAY_CHECKPOINT_DIR": directory}, clear=False
        ):
            manifest = {
                "completed_candidates": ["agent-1"],
                "pending_candidates": ["agent-2"],
            }
            _write_replay_checkpoint(
                "checkpoint-test",
                "execution_state",
                manifest,
                candidate="agent-1",
                trace_size=0,
            )

            checkpoint = _latest_replay_checkpoint()

            self.assertEqual("replay_checkpoint_v1", checkpoint["protocol"])
            self.assertEqual("execution_state", checkpoint["stage"])
            self.assertEqual(1, checkpoint["completed_count"])
            self.assertEqual(1, checkpoint["pending_count"])
            self.assertFalse(checkpoint["promotion_evidence"])
            self.assertTrue((Path(directory) / "checkpoint-test.json").is_file())

    def test_incremental_screen_timeout_is_bounded_with_operational_margin(self):
        payload = SimpleBacktestRequest(evaluation_mode="incremental")

        with patch.dict("os.environ", {"AI_REPLAY_SCREEN_HARD_TIMEOUT_SECONDS": "999"}, clear=False):
            self.assertEqual(900, _bounded_replay_seconds(payload, "run_all"))

        with patch.dict("os.environ", {"AI_REPLAY_SCREEN_HARD_TIMEOUT_SECONDS": "450"}, clear=False):
            self.assertEqual(450, _bounded_replay_seconds(payload, "run_all"))

    def test_nested_base_strategy_selects_the_differential_child_deadline(self):
        payload = SimpleBacktestRequest(
            evaluation_mode="incremental",
            strategies=[
                {
                    "strategy": "kernel_g218_s6_control",
                    "base_strategy": "differential_router_v1",
                    "version": "v216-kernel-218-6",
                }
            ],
        )

        with patch.dict(
            "os.environ", {"AI_REPLAY_DIFFERENTIAL_SCREEN_HARD_TIMEOUT_SECONDS": "780"}, clear=False
        ):
            self.assertEqual(780, _bounded_replay_seconds(payload, "run_all"))
        with patch.dict(
            "os.environ", {"AI_REPLAY_DIFFERENTIAL_SCREEN_HARD_TIMEOUT_SECONDS": "999"}, clear=False
        ):
            self.assertEqual(840, _bounded_replay_seconds(payload, "run_all"))

    def test_screen_capacity_defaults_to_one_on_small_hosts_and_keeps_an_explicit_override(self):
        with patch.dict("os.environ", {}, clear=True):
            self.assertEqual(1, _screen_replay_capacity(4))
            self.assertEqual(2, _screen_replay_capacity(8))

        with patch.dict("os.environ", {"AI_SCREEN_REPLAY_CONCURRENCY": "3"}, clear=True):
            self.assertEqual(3, _screen_replay_capacity(4))

        with patch.dict("os.environ", {"AI_SCREEN_REPLAY_CONCURRENCY": "auto"}, clear=True):
            self.assertEqual(1, _screen_replay_capacity(4))

        with patch.dict("os.environ", {"AI_SCREEN_REPLAY_CONCURRENCY": "invalid"}, clear=True):
            self.assertEqual(1, _screen_replay_capacity(4))

    def test_causal_confirmation_has_a_separate_sub_hour_hard_ceiling(self):
        payload = SimpleBacktestRequest(
            evaluation_mode="replay",
            policy_context={
                "learning_confirmation_contracts": {
                    "guided": {
                        "protocol": "bounded_cold_start_learning_confirmation_v1",
                        "admitted": True,
                    },
                },
            },
        )

        self.assertEqual(720, _bounded_replay_seconds(payload, "run_all"))
        with patch.dict("os.environ", {"AI_REPLAY_CAUSAL_HARD_TIMEOUT_SECONDS": "5000"}, clear=False):
            self.assertEqual(900, _bounded_replay_seconds(payload, "run_all"))
        with patch.dict("os.environ", {"AI_REPLAY_CAUSAL_HARD_TIMEOUT_SECONDS": "360"}, clear=False):
            self.assertEqual(360, _bounded_replay_seconds(payload, "run_all"))

    def test_durable_causal_fold_budget_covers_three_bounded_arms(self):
        payload = SimpleBacktestRequest(
            evaluation_mode="replay",
            policy_context={
                "learning_confirmation_contracts": {
                    "guided": {
                        "protocol": "bounded_cold_start_learning_confirmation_v1",
                        "execution_mode": "durable_single_fold_job",
                        "per_fold_budget_seconds": 180,
                        "admitted": True,
                    },
                },
            },
        )

        self.assertEqual(720, _bounded_replay_seconds(payload, "run_all"))
        with patch.dict("os.environ", {"AI_REPLAY_CAUSAL_FOLD_HARD_TIMEOUT_SECONDS": "5000"}, clear=False):
            self.assertEqual(900, _bounded_replay_seconds(payload, "run_all"))
        with patch.dict("os.environ", {"AI_REPLAY_CAUSAL_FOLD_HARD_TIMEOUT_SECONDS": "240"}, clear=False):
            self.assertEqual(720, _bounded_replay_seconds(payload, "run_all"))

        payload.policy_context["learning_confirmation_contracts"]["guided"]["per_fold_budget_seconds"] = 240
        self.assertEqual(900, _bounded_replay_seconds(payload, "run_all"))

    def test_skill_cartridge_confirmation_uses_the_same_bounded_research_lane(self):
        payload = SimpleBacktestRequest(
            evaluation_mode="replay",
            policy_context={
                "skill_cartridge_confirmation_contracts": {
                    "categorical-arm": {
                        "protocol": "bounded_skill_cartridge_confirmation_v1",
                        "admitted": True,
                        "fold_count": 3,
                        "fold_offset": 3,
                    },
                },
            },
        )

        self.assertEqual(720, _bounded_replay_seconds(payload, "run_all"))

    def test_simple_toolbox_replay_has_a_five_minute_default_and_six_minute_ceiling(self):
        payload = SimpleBacktestRequest(evaluation_mode="replay")

        self.assertEqual(300, _bounded_replay_seconds(payload, "simple"))
        with patch.dict("os.environ", {"AI_REPLAY_SIMPLE_HARD_TIMEOUT_SECONDS": "999"}, clear=False):
            self.assertEqual(360, _bounded_replay_seconds(payload, "simple"))
        with patch.dict("os.environ", {"AI_REPLAY_SIMPLE_HARD_TIMEOUT_SECONDS": "180"}, clear=False):
            self.assertEqual(180, _bounded_replay_seconds(payload, "simple"))

    def test_candidate_cache_identity_is_independent_of_sibling_contracts(self):
        cohort = SimpleBacktestRequest(
            strategy="all",
            policy_context={
                "trial_ledger": {"context_hash": "changes-per-trial"},
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
        self.assertNotIn("trial_ledger", cached.policy_context)
        self.assertNotIn("full_replay_runtime_policy", cached.policy_context)
        self.assertEqual([], cached.strategies)

    def test_candidate_cache_identity_does_not_change_with_trial_statistics(self):
        base = SimpleBacktestRequest(
            strategy="trend_v1",
            base_strategy="trend_v1",
            policy_context={
                "trial_ledger": {"context_hash": "old", "trial_count": 2},
                "repair_contracts": {"trend_v1": {"changed_gene": "ema_fast"}},
            },
        )
        newer = base.model_copy(update={
            "policy_context": {
                **base.policy_context,
                "trial_ledger": {"context_hash": "new", "trial_count": 99},
            },
        })

        old_cache_payload = _candidate_cache_payload(base, base, "trend_v1")
        new_cache_payload = _candidate_cache_payload(newer, newer, "trend_v1")

        self.assertEqual(old_cache_payload.model_dump(), new_cache_payload.model_dump())

    def test_all_candidate_cache_hits_skip_sealed_dataset_loading(self):
        payload = SimpleBacktestRequest(
            strategy="all",
            evaluation_mode="replay",
            strategies=[{
                "strategy": "trend_v1",
                "base_strategy": "trend_v1",
                "version": "v1",
                "parameters": {},
            }],
        )
        cached_item = {
            "strategy": "trend_v1",
            "base_strategy": "trend_v1",
            "version": "v1",
            "parameters": {},
            "score": 50,
            "train_score": 50,
            "validation_score": 50,
            "forward_score": 50,
            "forward_window_scores": [1, 2, 3, 4],
            "rolling_windows_count": 4,
            "robustness_score": 0,
            "is_overfit": False,
            "result": {
                "equity_curve": [10000, 10010],
                "statistical_evidence": {},
                "behavioral_signature": {},
                "trades": [],
            },
        }
        cached = {
            "protocol": "candidate_replay_cache_v1",
            "strategy": "trend_v1",
            "item": cached_item,
        }

        with patch("app.main._load_immutable_replay_cache", return_value=cached), \
             patch("app.main._candidate_cache_contract_is_current", return_value=True), \
             patch("app.main._load_simple_candles", side_effect=AssertionError("cache hit loaded the dataset")), \
             patch("app.main._load_foundation_candles", side_effect=AssertionError("cache hit loaded the foundation")):
            response = _run_all_backtests_sync(payload)

        self.assertEqual(["trend_v1"], [item["strategy"] for item in response["leaderboard"]])
        self.assertIn("selection_validation", response["leaderboard"][0]["result"])

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
