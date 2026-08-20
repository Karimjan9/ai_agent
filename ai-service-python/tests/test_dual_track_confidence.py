import unittest
import os
from unittest.mock import patch

from app.main import _council_confidence, paper_twin_ablation, paper_twin_inference


class DualTrackConfidenceTest(unittest.TestCase):
    def test_council_confidence_uses_all_votes_in_entropy(self):
        confidence = _council_confidence({
            "decision": "BUY",
            "agents": [
                {"agent": "direction", "decision": "BUY"},
                {"agent": "skeptic", "decision": "WAIT"},
                {"agent": "risk", "decision": "WAIT"},
            ],
        })

        self.assertGreaterEqual(confidence, 0.0)
        self.assertLessEqual(confidence, 1.0)
        self.assertLess(confidence, 0.8)

    def test_dedicated_lane_endpoint_returns_only_selected_output(self):
        fake = {
            "signal": "WAIT",
            "dual_track": {
                "snapshot_hash": "snapshot-1",
                "snapshot_manifest": {"snapshot_hash": "snapshot-1"},
                "champion": {"decision": "BUY", "inference": {"lane": "champion"}},
                "council": {"decision": "WAIT", "inference": {"lane": "council"}},
            },
        }
        with patch.dict(os.environ, {"AI_TWIN_PROCESS_ISOLATION": "false"}), patch("app.main.paper_signal", return_value=fake):
            result = paper_twin_inference("champion", {})

        self.assertEqual(result["dual_track"]["lane"], "champion")
        self.assertEqual(result["dual_track"]["output"]["decision"], "BUY")
        self.assertEqual(result["dual_track"]["independence_status"], "dedicated_lane_endpoint")

    def test_ablation_endpoint_produces_distinct_full_and_ablated_hashes(self):
        fake = {
            "signal": "BUY",
            "dual_track": {
                "snapshot_hash": "snapshot-1",
                "council": {"decision": "BUY", "committee": {"agents": [
                    {"agent": "direction", "decision": "BUY"},
                    {"agent": "skeptic", "decision": "WAIT"},
                ]}},
            },
        }
        with patch("app.main.paper_signal", return_value=fake):
            result = paper_twin_ablation({"request": {}, "member_key": "direction"})

        self.assertNotEqual(result["full_output_hash"], result["ablated_output_hash"])
        self.assertTrue(result["independent_snapshot"])


if __name__ == "__main__":
    unittest.main()
