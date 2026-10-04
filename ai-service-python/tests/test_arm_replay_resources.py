import unittest

from app.services.walk_forward import WalkForwardService


class ArmReplayResourcesTest(unittest.TestCase):
    def row(self, cpu, wall):
        return {'benchmark': {'arm_replay_resources': {'protocol': 'arm_replay_resources_v1',
            'cpu_seconds': cpu, 'wall_seconds': wall,
            'scope': 'economic_replay_only_excludes_shared_features_and_audit'}}}

    def test_aggregation_sums_measured_segments_not_only_last_fold(self):
        result = WalkForwardService._resource_totals([self.row(1, 2), self.row(3, 5)])
        self.assertEqual(4, result['cpu_seconds'])
        self.assertEqual(7, result['wall_seconds'])
        self.assertEqual(2, result['measured_segments'])
        self.assertFalse(result['promotion_evidence'])

    def test_legacy_missing_negative_nonfinite_and_fake_measurements_stay_unknown(self):
        for rows in ([], [{}], [self.row(1, 2), {}], [self.row(-1, 2)],
                     [self.row(float('nan'), 2)], [self.row(True, 2)], [self.row(1, float('inf'))]):
            with self.subTest(rows=rows):
                self.assertIsNone(WalkForwardService._resource_totals(rows))
