"""Cross-runtime declaration and consumer guards; never an archive replay."""
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest
from unittest.mock import patch

from scripts import curator_intraday_panel as panel
from app.services.research_program_tasks import canonical_hash


class CuratorIntradayPanelTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        binary = os.environ.get('PHP_BINARY') or shutil.which('php')
        if not binary or not (panel.ROOT.parent / 'curator-context-research-20261015/.runtime/context-practice-completion-20261015/before-outcome-spec.json').is_file():
            raise unittest.SkipTest('Actual PHP/private original custody inputs required; no manufactured market proof')
        completed = subprocess.run([binary, str(panel.ROOT / 'backend-laravel/scripts/prepare-curator-intraday-panel.php'), '--export-draft'],
            cwd=panel.ROOT, capture_output=True, timeout=60, check=True,
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        cls.draft = json.loads(completed.stdout)

    def test_actual_php_to_python_eight_original_requests(self):
        panel.validate(self.draft)
        self.assertEqual(len(self.draft['requests']), 8)
        for item in self.draft['requests'].values():
            self.assertEqual(hashlib.sha256(item['request_json'].encode()).hexdigest(), item['request_sha256'])
            self.assertEqual(canonical_hash(item['request']), item['request_semantic_hash'])

    def test_all_shared_physical_controls_match_two_coordinate_declaration(self):
        for item in self.draft['requests'].values():
            self.assertEqual(item['request'], panel.physical_for_arm(self.draft['baselines'][item['window']], item['arm']))
            self.assertEqual(item['request']['execution']['allowed_sessions_utc'], ['7-14'])
            self.assertNotEqual(item['cost_sha256'], '04403537588afd0e22efffc3e210b572ca660e8d751ab8d4dbdc7225a8fed7b5')
            self.assertNotIn('policy_context', item['request'])

    def test_authority_flag_or_extra_arm_is_rejected(self):
        for flag in panel.FLAGS:
            broken = copy.deepcopy(self.draft); broken[flag] = True
            with self.assertRaisesRegex(RuntimeError, 'BOUNDED_RESEARCH_ONLY'):
                panel.validate_design(broken)
        broken = copy.deepcopy(self.draft); broken['requests']['extra'] = next(iter(broken['requests'].values()))
        with self.assertRaisesRegex(RuntimeError, 'EXACT_NEW_TWO_BY_FOUR'):
            panel.validate_design(broken)

    def test_schema_valid_but_undeclared_risk_intervention_is_rejected(self):
        broken = copy.deepcopy(self.draft)
        next(iter(broken['requests'].values()))['request']['risk_per_trade'] = 2.
        with self.assertRaisesRegex(RuntimeError, 'ONLY_METHOD_IDENTITY_AND_HOLDING'):
            panel.validate_design(broken)

    def test_actual_source_and_data_are_pinned_not_tail_override(self):
        panel.validate_inputs(self.draft)
        for item in self.draft['requests'].values():
            self.assertNotIn('dataset_tail_rows', item['request'])
            self.assertEqual(item['request']['replay_dataset_hash'], self.draft['windows'][item['window']]['sha256'])
        broken = copy.deepcopy(self.draft); broken['windows']['october_pre_dst']['loaded_rows'] -= 1
        with self.assertRaisesRegex(RuntimeError, 'EXACT_PRE2026_SELECTED_DATA'):
            panel.validate_inputs(broken)

    def test_comparison_keeps_missing_products_unknown_not_zero(self):
        rows = {}
        for window in panel.WINDOWS:
            for arm in panel.ARMS:
                rows[window + '__' + arm] = {'status': 'technical_terminal'}
        result = panel.comparisons({'results': rows})
        for window in result.values():
            self.assertIsNone(window['cash_interaction'])
            for pair in window['pairs'].values():
                self.assertFalse(pair['matched_complete']); self.assertIsNone(pair['realized_cash_delta'])

    def test_original_global_bounds_cannot_be_renewed_or_overshot(self):
        global_limits = {'started_at_monotonic': 100., 'started_at_unix': 1000.,
            'deadline_monotonic': 5500., 'deadline_unix': 6400., 'budget_seconds': 5400.}
        case = {'started_at_monotonic': 5400., 'started_at_unix': 6300.,
            'deadline_monotonic': 5500., 'deadline_unix': 6400., 'budget_seconds': 600.}
        attempt, launch = {'max_attempts': 1, 'deadline': global_limits}, {'deadline': case}
        panel.validate_launch(attempt, launch)
        for axis in ('monotonic', 'unix'):
            broken = copy.deepcopy(launch); broken['deadline']['deadline_' + axis] += 1
            with self.assertRaisesRegex(RuntimeError, 'CAPPED_BY_ORIGINAL_GLOBAL'):
                panel.validate_launch(attempt, broken)


if __name__ == '__main__':
    unittest.main()
