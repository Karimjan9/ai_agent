"""Curator instruction integrity, not independent market qualification."""
import json
import math
from pathlib import Path
import re
import unittest

PATH = Path(__file__).resolve().parents[2] / 'backend-laravel/resources/research/instrument-operating-skills/curator-scalping-observations-v1.json'


class CuratorScalpObservationsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads(PATH.read_bytes())

    def test_seven_assessed_products_do_not_relabel_eight_original_processes(self):
        cases = self.data['cases']
        self.assertEqual(len(cases), 8)
        self.assertEqual(sum(c['appendix_status'] == 'projection_verified' for c in cases.values()), 7)
        self.assertTrue(all(c['source_process_status'] == 'technical_terminal' for c in cases.values()))
        missing = cases['december_standard__pretrend_6bars']
        self.assertEqual(missing['appendix_status'], 'unassessable_unknown')
        self.assertIsNone(missing['economics'])
        self.assertFalse(missing['original_budget_eligibility']['eligible'])
        self.assertIsNone(self.data['comparisons']['december_standard']['reporting_projection_cash_interaction'])

    def test_instruction_is_not_agent_experience_confirmation_or_trading_authority(self):
        self.assertTrue(self.data['research_only'])
        self.assertTrue(self.data['usable_for_research'])
        for flag in ('own_agent_outcome', 'independent_validation', 'credit', 'mastery', 'economic_authority', 'paper_authority', 'live_authority', 'automatic_agent_attachment'):
            self.assertIs(self.data[flag], False)
        self.assertEqual(self.data['scope']['all_2026_and_later'], 'paper_only')
        for key in ('original_spec_sha256', 'original_summary_sha256', 'original_source_archive_sha256', 'reporting_appendix_sha256', 'reporting_verification_sha256'):
            self.assertIsNotNone(re.fullmatch('[0-9a-f]{64}', self.data[key]))

    def test_all_daily_cash_counts_contexts_and_matched_contrasts_reconcile(self):
        for case in self.data['cases'].values():
            e = case['economics']
            if e is None:
                continue
            self.assertEqual(sum(d['mature_full_closes'] for d in e['per_day']), e['mature_full_closes'])
            self.assertAlmostEqual(sum(d['realized_net_cash'] for d in e['per_day']), e['exact_realized_net_cash'], places=7)
            self.assertEqual(sum(g['mature_full_closes'] for g in e['entry_context_groups']), e['mature_full_closes'])
            self.assertAlmostEqual(sum(g['realized_net_cash_by_entry_context'] for g in e['entry_context_groups']), e['exact_realized_net_cash'], places=7)
            self.assertIsNone(e['marked_drawdown_percent'])
            self.assertIsNone(e['wait_count'])
            self.assertTrue(math.isfinite(e['exact_realized_net_cash']))
        for window in ('october_pre_dst', 'december_standard'):
            control = self.data['cases'][window + '__proximity_unbounded']['economics']
            treatment = self.data['cases'][window + '__proximity_6bars']['economics']
            observed = self.data['comparisons'][window]['pairs']['time_given_proximity']['realized_cash_delta']
            self.assertAlmostEqual(observed, treatment['exact_realized_net_cash'] - control['exact_realized_net_cash'], places=7)
            self.assertEqual(treatment['holding_minutes']['within_cap_fraction'], 1)
            self.assertEqual(treatment['holding_minutes']['max'], 30)
            self.assertEqual(treatment['eligible_observed_utc_days'], 5)
            self.assertEqual(treatment['eligible_days_with_multiple_mature_closes'], 4)


if __name__ == '__main__':
    unittest.main()
