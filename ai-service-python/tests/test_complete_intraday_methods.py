"""Prospective complete-method declarations/continuation, never market replay."""
import copy
import json
from pathlib import Path
import unittest

from scripts import curator_complete_intraday_methods as study
from scripts import curator_instrument_study as kernel


def synthetic_row(net, *, mature=25, dd=1.):
    return {'status': 'completed', 'dataset_sha256': 'data', 'cost_sha256': 'cost',
        'evidence': {'net_cash': net, 'index_set_hash': 'i', 'schedule_hash': 's', 'actual_clock': {'rows': 1000},
            'mature': mature, 'closed_cash_drawdown_percent': dd, 'open_position_count': 0,
            'scalp_metrics': {'holding_minutes': {'over_cap_count': 0}}}}


class IntradayContinuationTest(unittest.TestCase):
    def report(self, net=-1.):
        return {'results': {window + '__' + arm: synthetic_row(-2. if arm.endswith('_control') else net)
                            for window in study.WINDOWS for arm in study.ARMS}}

    def test_negative_relative_improvement_cannot_auto_become_a_skill(self):
        result = study.comparisons(self.report())
        self.assertEqual(result['june2025']['ema']['realized_cash_delta'], 1.)
        self.assertIsNone(result['prospective_agent_practice']['selected_method'])
        self.assertFalse(result['prospective_agent_practice']['automatic_dispatch'])

    def test_missing_arms_are_unknown_not_zero_and_cannot_create_handoff(self):
        report = {'results': {window + '__' + arm: {'status': 'technical_terminal'}
                             for window in study.WINDOWS for arm in study.ARMS}}
        result = study.comparisons(report)
        for window in study.WINDOWS:
            for family in study.FAMILIES:
                self.assertIsNone(result[window][family]['realized_cash_delta'])
                self.assertTrue(result[window][family]['unknown_not_zero'])
        self.assertIsNone(result['prospective_agent_practice']['selected_method'])

    def test_control_eof_exposure_is_visible_and_blocks_continuation(self):
        report = self.report(2.)
        report['results']['june2025__ema_control']['evidence']['open_position_count'] = 1
        value = study.comparisons(report)
        self.assertTrue(value['june2025']['ema']['eof_open_exposure_present'])
        self.assertEqual(value['june2025']['ema']['control_open_position_count'], 1)
        self.assertEqual(value['june2025']['ema']['recipe_open_position_count'], 0)
        self.assertNotIn('ema', value['prospective_agent_practice']['eligible_methods_fixed_priority'])

    def test_fixed_priority_is_not_an_outcome_ranking_or_authority(self):
        report = self.report(2.)
        for window in study.WINDOWS:
            report['results'][window + '__bb_recipe'] = synthetic_row(100.)
        value = study.comparisons(report)['prospective_agent_practice']
        self.assertEqual(value['selected_method'], 'ema')
        self.assertFalse(value['skill_credit'])
        self.assertFalse(value['independent'])
        self.assertTrue(value['requires_fresh_agent_originals'])

    def test_one_period_low_sample_open_position_or_higher_risk_blocks_continuation(self):
        for mutation in ('negative', 'sample', 'control_sample', 'open', 'risk', 'holding'):
            report = self.report(2.)
            evidence = report['results']['september2025__ema_structural']['evidence']
            if mutation == 'negative': evidence['net_cash'] = -1.
            elif mutation == 'sample': evidence['mature'] = 19
            elif mutation == 'control_sample': report['results']['september2025__ema_control']['evidence']['mature'] = 19
            elif mutation == 'open': evidence['open_position_count'] = 1
            elif mutation == 'risk': evidence['closed_cash_drawdown_percent'] = 2.
            else: evidence['scalp_metrics']['holding_minutes']['over_cap_count'] = 1
            with self.subTest(mutation=mutation):
                self.assertNotIn('ema', study.comparisons(report)['prospective_agent_practice']['eligible_methods_fixed_priority'])


class ActualCompleteIntradayDeclarationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        path = kernel.ROOT / '.runtime/intraday-methods-draft-20261016.json'
        if not path.is_file():
            raise unittest.SkipTest('Actual prospective PHP declaration not yet available')
        cls.draft = json.loads(path.read_bytes())

    def test_all_twelve_original_wires_have_identical_declared_physical_accounts(self):
        study.validate(self.draft)
        self.assertEqual(len(self.draft['requests']), 12)
        for case in self.draft['requests'].values():
            self.assertEqual(case['request']['risk_per_trade'], 1.)
            self.assertEqual(case['request']['initial_balance'], 10000.)
            self.assertIsNone(case['request'].get('dataset_tail_rows'))

    def test_authority_compute_or_an_undeclared_gene_cannot_drift(self):
        for field, value in (('credit', True), ('own_agent_outcome', True), ('max_attempts_per_arm', 2),
                             ('global_timeout_seconds', 12000), ('per_original_timeout_seconds', 1600)):
            broken = copy.deepcopy(self.draft); broken[field] = value
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'FIXED_INTRADAY_RESEARCH_CAPS'):
                study.validate(broken)
        broken = copy.deepcopy(self.draft)
        broken['requests']['june2025__ema_structural']['request']['risk_per_trade'] = 2.
        with self.assertRaisesRegex(RuntimeError, 'ONLY_PREDECLARED_METHOD_COORDINATES'):
            study.validate(broken)

    def test_the_actual_other_month_cannot_borrow_a_bollinger_contract(self):
        broken = copy.deepcopy(self.draft)
        broken['requests']['june2025__bb_recipe']['request']['policy_context'] = copy.deepcopy(
            broken['requests']['september2025__bb_recipe']['request']['policy_context'])
        with self.assertRaisesRegex(RuntimeError, 'EXACT_WINDOW_BB_COMPILATION'):
            study.validate(broken)


if __name__ == '__main__':
    unittest.main()
