"""Instrument-study declaration/UTC custody checks, never original replay."""
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from scripts import curator_instrument_study as kernel
from scripts import curator_bollinger_study as study
from scripts import curator_scalp_metrics as metrics


class CuratorInstrumentStudyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get('PHP_BINARY') or shutil.which('php')
        manifest = kernel.ROOT / '.runtime/bollinger-new-months-20261016/provenance-manifest.json'
        if not php or not manifest.is_file():
            raise unittest.SkipTest('Exact actual PHP/new private native windows unavailable')
        identity = hashlib.sha256(manifest.read_bytes()).hexdigest()
        result = subprocess.run([php, str(kernel.ROOT / 'backend-laravel/scripts/prepare-curator-bollinger-study.php'),
            '--windows', str(manifest), '--windows-sha256', identity], cwd=kernel.ROOT,
            capture_output=True, check=True, timeout=60, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        cls.draft = json.loads(result.stdout)

    def test_actual_eight_cross_runtime_wires_and_new_months(self):
        study.validate(self.draft)
        self.assertEqual(set(self.draft['windows']), {'may2025', 'august2025'})
        self.assertEqual(len(self.draft['requests']), 8)
        for case in self.draft['requests'].values():
            self.assertEqual(case['request'], study.physical(self.draft['baselines'][case['window']], case['arm']))
            self.assertEqual(hashlib.sha256(case['request_json'].encode()).hexdigest(), case['request_sha256'])

    def test_optional_gate_is_not_an_undeclared_risk_or_cost_change(self):
        broken = copy.deepcopy(self.draft)
        next(iter(broken['requests'].values()))['request']['execution']['spread_points'] = 0.
        with self.assertRaisesRegex(RuntimeError, 'ONLY_CONTEXT_AND_HOLDING'):
            study.validate(broken)

    def test_unknown_adapter_and_authority_are_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'CODE_OWNED_INSTRUMENT_STUDY'):
            kernel.owner({'study_owner': 'external_code.py'})
        for flag in kernel.FLAGS:
            broken = copy.deepcopy(self.draft); broken[flag] = True
            with self.assertRaisesRegex(RuntimeError, 'BOUNDED_BOLLINGER_RESEARCH_ONLY'):
                study.validate(broken)

    def test_entire_declared_parameter_vector_is_pinned_before_outcomes(self):
        for mutation in ('omit', 'add', 'change'):
            broken = copy.deepcopy(self.draft)
            for base in broken['baselines'].values():
                if mutation == 'omit':
                    base['parameters'].pop('dynamic_cooldown_enabled')
                elif mutation == 'add':
                    base['parameters']['additional_undeclared_gene'] = 1
                else:
                    base['parameters']['max_loss_streak_before_wait'] = 5
            with self.assertRaisesRegex(RuntimeError, 'EXACT_PREDECLARED_REENTRY_PARAMETER_VECTOR'):
                study.validate(broken)

    def test_case_deadlines_cannot_borrow_a_fresh_outer_budget(self):
        limits = {'started_at_monotonic': 100., 'started_at_unix': 1000., 'deadline_monotonic': 5500.,
            'deadline_unix': 6400., 'budget_seconds': 5400}
        child = {'started_at_monotonic': 5400., 'started_at_unix': 6300., 'deadline_monotonic': 5500.,
            'deadline_unix': 6400., 'budget_seconds': 600}
        attempt = {'max_attempts': 1, 'spec_sha256': 'same', 'deadline': limits}
        launch = {'spec_sha256': 'same', 'deadline': child}
        kernel.validate_launch(attempt, launch, self.draft)
        broken = copy.deepcopy(launch); broken['deadline']['deadline_unix'] += 1
        with self.assertRaisesRegex(RuntimeError, 'NO_RENEWED_OR_OVERSHOT'):
            kernel.validate_launch(attempt, broken, self.draft)

    def test_all_terminal_streams_remain_bound_even_for_technical_cases(self):
        with tempfile.TemporaryDirectory() as directory:
            products = Path(directory); key = 'technical'
            (products / (key + '.request.json')).write_bytes(b'original')
            (products / (key + '.response.json')).write_bytes(b'partial-output')
            case = {'request_sha256': kernel.sha(products / (key + '.request.json'))}
            process = {'status': 'technical_terminal', **case,
                'artifact_sha256': kernel.case_artifacts(products, key),
                'response_sha256': kernel.sha(products / (key + '.response.json'))}
            kernel.verify_case_artifacts(products, key, case, process)
            broken = copy.deepcopy(process); broken.pop('response_sha256')
            with self.assertRaisesRegex(RuntimeError, 'ORIGINAL_CASE_STREAM_CHANGED'):
                kernel.verify_case_artifacts(products, key, case, broken)
            (products / (key + '.request.json')).write_bytes(b'changed')
            broken = copy.deepcopy(process)
            broken['request_sha256'] = kernel.sha(products / (key + '.request.json'))
            broken['artifact_sha256'] = kernel.case_artifacts(products, key)
            with self.assertRaisesRegex(RuntimeError, 'ORIGINAL_SEALED_REQUEST_STREAM'):
                kernel.verify_case_artifacts(products, key, case, broken)

    def test_missing_original_case_does_not_create_zero_economic_effect(self):
        results = {window + '__' + arm: {'status': 'technical_terminal'} for window in study.WINDOWS for arm in study.ARMS}
        for window in study.comparisons({'results': results}).values():
            self.assertIsNone(window['cash_interaction'])
            for effect in window['pairs'].values():
                self.assertIsNone(effect['realized_cash_delta']); self.assertTrue(effect['unknown_not_zero'])

    def test_real_naive_utc_rows_require_full_native_manifest_rederivation(self):
        manifest_ref = self.draft['upstream']['windows']
        window = self.draft['windows']['may2025']
        binding = metrics._registered_csv_binding(window['path'], window['sha256'],
            self.draft['baselines']['may2025'], window['loaded_rows'],
            {'manifest_path': manifest_ref['path'], 'manifest_sha256': manifest_ref['sha256'], 'window_key': 'may2025'})
        self.assertEqual(binding['protocol'], 'rederived_original_instrument_csv_utc_registration_v1')
        self.assertEqual(binding['manifest_sha256'], manifest_ref['sha256'])
        with patch('scripts.curator_instrument_windows.validate_manifest', side_effect=ValueError('ORIGINAL_REDERIVATION_REFUSED')):
            with self.assertRaisesRegex(ValueError, 'ORIGINAL_REDERIVATION_REFUSED'):
                metrics._registered_csv_binding(window['path'], window['sha256'], self.draft['baselines']['may2025'], window['loaded_rows'], None)

    def test_caller_timestamp_flag_or_wrong_manifest_hash_never_grants_utc(self):
        window = self.draft['windows']['may2025']; ref = self.draft['upstream']['windows']
        for provenance in ({'timezone': 'UTC'}, {'manifest_path': ref['path'], 'manifest_sha256': '0' * 64, 'window_key': 'may2025'}):
            with self.assertRaises(ValueError):
                metrics._registered_csv_binding(window['path'], window['sha256'], self.draft['baselines']['may2025'], window['loaded_rows'], provenance)


if __name__ == '__main__':
    unittest.main()
