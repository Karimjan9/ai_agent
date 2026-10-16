"""Declaration, bundle attribution and immutable journal checks; no replay."""
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest
from unittest.mock import patch

from scripts import curator_bollinger_recipe as study
from scripts import curator_instrument_study as kernel


class CompleteBollingerRecipeDesignTest(unittest.TestCase):
    def test_missing_arm_is_unknown_not_zero_or_component_credit(self):
        results = {study.WINDOW + '__' + arm: {'status': 'technical_terminal'} for arm in study.ARMS}
        value = study.comparisons({'results': results})[study.WINDOW]
        self.assertFalse(value['matched_complete'])
        self.assertIsNone(value['realized_cash_delta'])
        self.assertTrue(value['unknown_not_zero'])
        self.assertFalse(value['individual_formula_or_exit_credit'])
        self.assertFalse(value['independent'])

    def test_complete_same_clock_pair_only_measures_whole_recipe(self):
        def row(net):
            return {'status': 'completed', 'dataset_sha256': 'data', 'cost_sha256': 'cost',
                'evidence': {'net_cash': net, 'index_set_hash': 'i', 'schedule_hash': 's', 'actual_clock': {'rows': 6324}}}
        results = {study.WINDOW + '__control': row(-20.), study.WINDOW + '__population_midline': row(-10.)}
        value = study.comparisons({'results': results})[study.WINDOW]
        self.assertEqual(value['realized_cash_delta'], 10.)
        self.assertEqual(value['effect_scope'], 'whole_account_two_coordinate_recipe_bundle')
        self.assertFalse(value['dominance_claim'])
        results[study.WINDOW + '__population_midline']['cost_sha256'] = 'changed'
        self.assertIsNone(study.comparisons({'results': results})[study.WINDOW]['realized_cash_delta'])

    def test_only_named_preparer_and_adapter_are_available(self):
        self.assertIs(kernel.owner({'study_owner': study.OWNER}), study)
        with self.assertRaisesRegex(RuntimeError, 'CODE_OWNED_INSTRUMENT_STUDY'):
            kernel.owner({'study_owner': 'arbitrary_plugin.py'})
        with patch.object(kernel.subprocess, 'run', side_effect=AssertionError('must never launch')):
            with self.assertRaisesRegex(RuntimeError, 'CODE_OWNED_INSTRUMENT_PREPARER'):
                kernel.prepare('php', 'unused', 'unused', kernel.ROOT / '.runtime/never-created.json', '../arbitrary.php')


class ActualPhpCompleteRecipeDeclarationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get('PHP_BINARY') or shutil.which('php')
        manifest = kernel.ROOT / '.runtime/recipe-october-data-20261016/provenance-manifest.json'
        if not php or not manifest.is_file():
            raise unittest.SkipTest('Exact actual PHP/private original month unavailable')
        args = [php, str(kernel.ROOT / 'backend-laravel/scripts/prepare-curator-bollinger-recipe-study.php'),
            '--windows', str(manifest), '--windows-sha256', hashlib.sha256(manifest.read_bytes()).hexdigest()]
        result = subprocess.run(args, cwd=kernel.ROOT, capture_output=True, timeout=60,
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        if result.returncode:
            raise AssertionError(result.stderr.decode(errors='replace'))
        cls.draft = json.loads(result.stdout)

    def test_actual_two_wire_declaration_and_original_rows(self):
        study.validate(self.draft)
        self.assertEqual(len(self.draft['requests']), 2)
        window = self.draft['windows'][study.WINDOW]
        self.assertEqual((window['loaded_rows'], window['warmup_rows'], window['evaluated_rows']), (6524, 200, 6324))
        for case in self.draft['requests'].values():
            self.assertEqual(hashlib.sha256(case['request_json'].encode()).hexdigest(), case['request_sha256'])
        control = self.draft['requests'][study.WINDOW + '__control']['request']
        treatment = self.draft['requests'][study.WINDOW + '__population_midline']['request']
        self.assertFalse(control.get('policy_context'))
        self.assertNotIn('session_band_variance', control['parameters'])
        self.assertEqual(treatment['parameters']['session_band_variance'], 'population')

    def test_unrequested_controls_deadlines_or_authority_cannot_drift(self):
        for field, value in (('per_original_timeout_seconds', 2800), ('global_timeout_seconds', 6100),
                             ('credit', True), ('own_agent_outcome', True), ('max_attempts_per_arm', 2)):
            broken = copy.deepcopy(self.draft); broken[field] = value
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'BOUNDED_COMPLETE_RECIPE'):
                study.validate(broken)
        broken = copy.deepcopy(self.draft)
        broken['requests'][study.WINDOW + '__population_midline']['request']['risk_per_trade'] = 2.
        with self.assertRaisesRegex(RuntimeError, 'ONLY_DECLARED_RECIPE_BUNDLE'):
            study.validate(broken)

    def test_complete_runtime_policy_cannot_be_replaced_by_caller_claim(self):
        broken = copy.deepcopy(self.draft)
        context = broken['requests'][study.WINDOW + '__population_midline']['request']['policy_context']
        context['instrument_method_learning']['contract_hash'] = '0' * 64
        with self.assertRaisesRegex(RuntimeError, 'EXACT_COMPILED_RECIPE_CONTRACT'):
            study.validate(broken)


if __name__ == '__main__':
    unittest.main()
