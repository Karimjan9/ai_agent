"""Actual offline PHP preparation/Python admission, never a replay."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import execution_contract_metadata
from app.services.parameter_schema import validate_strategy_parameters
from app.services.research_program_tasks import canonical_hash

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'backend-laravel/scripts/prepare-curator-scalp-panel.php'


class CuratorScalpPreparationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        binary = os.environ.get('PHP_BINARY') or shutil.which('php')
        if not binary:
            raise unittest.SkipTest('Actual PHP binary is unavailable; no cross-runtime evidence claimed')
        # This standalone preparer intentionally needs the exact retained private
        # custody inputs. Generic CI cannot manufacture those market originals.
        prior = ROOT.parent / 'curator-context-research-20261015/.runtime/context-practice-completion-20261015/before-outcome-spec.json'
        if not prior.is_file():
            raise unittest.SkipTest('Original private discovery custody inputs unavailable')
        preparation = subprocess.run([binary, str(SCRIPT), '--export-draft'], cwd=ROOT,
            capture_output=True, timeout=60, check=True,
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        cls.draft = json.loads(preparation.stdout)

    def test_all_eight_actual_wires_have_cross_language_schema_and_identity(self):
        self.assertEqual(len(self.draft['requests']), 8)
        for case in self.draft['requests'].values():
            wire, request = case['request_json'], case['request']
            self.assertEqual(json.loads(wire), request)
            self.assertEqual(hashlib.sha256(wire.encode()).hexdigest(), case['request_sha256'])
            self.assertEqual(canonical_hash(request), case['request_semantic_hash'])
            payload = SimpleBacktestRequest.model_validate_json(wire)
            self.assertEqual(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy), request['parameters'])
            self.assertEqual(execution_contract_metadata(payload)['execution_hash'], case['cost_sha256'])

    def test_all_arms_differ_only_in_predeclared_two_coordinates(self):
        expected = {'proximity_unbounded': ('frozen', 0), 'proximity_6bars': ('frozen', 6),
                    'pretrend_unbounded': ('source_ema_pretrend_pullback_v1', 0),
                    'pretrend_6bars': ('source_ema_pretrend_pullback_v1', 6)}
        for case in self.draft['requests'].values():
            request = json.loads(case['request_json'])
            self.assertEqual((request['parameters']['entry_topology_variant'], request['parameters']['time_stop_candles']), expected[case['arm']])
            request['parameters'].update(entry_topology_variant='frozen', time_stop_candles=0)
            self.assertEqual(request, self.draft['baselines'][case['window']])

    def test_no_agent_or_authority_and_bounded_single_attempt(self):
        self.assertTrue(self.draft['research_only'])
        for flag in ('own_agent_outcome', 'independent_validation', 'credit', 'mastery', 'economic_authority', 'paper_authority', 'live_authority'):
            self.assertIs(self.draft[flag], False)
        self.assertEqual((self.draft['max_attempts_per_arm'], self.draft['per_original_timeout_seconds'], self.draft['global_timeout_seconds']), (1, 600, 5400))
        for case in self.draft['requests'].values():
            self.assertNotIn('policy_context', case['request'])
            self.assertEqual(case['request']['strategy'], 'trend_v1')
            self.assertEqual(case['request']['parameters']['partial_take_profit_fraction'], 0)

    def test_selected_raw_rows_and_pre_paper_warmup_not_synthetic(self):
        for window in self.draft['windows'].values():
            self.assertEqual(hashlib.sha256(Path(window['path']).read_bytes()).hexdigest(), window['sha256'])
            self.assertEqual(window['warmup_rows'], 200)
            self.assertEqual(window['evaluated_rows'], window['loaded_rows'] - 200)
            self.assertLess(window['evaluation_last_close_utc'], '2026-01-01T00:00:00Z')
            self.assertTrue(window['all_raw_source_cells_unchanged'])
            self.assertFalse(window['synthetic_candles_or_volume_added'])


if __name__ == '__main__':
    unittest.main()
