"""Curator know-how projections; never agent experience or skill authority."""
import hashlib
import json
import math
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
RESOURCE = ROOT / 'backend-laravel/resources/research/instrument-operating-skills/curator-scalp-day-observations-v1.json'


class CuratorIntradayObservationsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads(RESOURCE.read_bytes())

    def test_curator_know_how_never_becomes_agent_or_trading_authority(self):
        self.assertTrue(self.data['usable_for_research'])
        self.assertTrue(self.data['research_only'])
        for key in ('own_agent_outcome', 'independent_validation', 'credit', 'mastery', 'economic_authority',
                    'paper_authority', 'live_authority', 'automatic_agent_attachment'):
            self.assertIs(self.data[key], False)
        self.assertEqual(self.data['scope']['all_2026_and_later'], 'paper_only')
        for name in ('original_spec_sha256', 'original_summary_sha256', 'original_source_archive_sha256', 'original_artifact_roster_sha256'):
            self.assertRegex(self.data[name], r'^[0-9a-f]{64}$')

    def test_eight_cases_retain_negative_results_and_real_horizon_counts(self):
        self.assertEqual(len(self.data['cases']), 8)
        self.assertEqual(self.data['completed_reverified_cases'], self.data['terminal_original_cases'])
        self.assertEqual(sum(case['net_cash'] > 0 for case in self.data['cases'].values()), 2)
        for key, case in self.data['cases'].items():
            self.assertEqual(case['status'], 'completed')
            self.assertEqual(case['mature'], sum(case['daily_full_closes']))
            self.assertEqual(case['mature'], case['closed_same_utc_date'])
            self.assertEqual(case['open'], 0)
            self.assertTrue(math.isfinite(case['net_cash']))
            self.assertLessEqual(case['max_holding_minutes'], 30 if key.endswith('scalp6') else 120)
            if key.endswith('scalp6'):
                self.assertTrue(all(count >= 2 for count in case['daily_full_closes']))

    def test_selected_contexts_are_small_exploratory_samples_not_permissions(self):
        scope = self.data['context_interpretation']
        self.assertIs(scope['isolated_context_effect'], False)
        self.assertIs(scope['general_harmful_ban'], False)
        self.assertIs(scope['conditional_account_replay_required_before_using_as_filter'], True)
        self.assertIn('selected_after_original_outcomes', scope['selection'])
        self.assertTrue(all(0 < row[period]['n'] < 10 for row in self.data['context_examples'] for period in ('october', 'december')))
        # Positive range observations do not erase the actual losing accounts.
        for window in ('october_pre_dst', 'december_standard'):
            self.assertLess(self.data['cases'][window + '__reentry_scalp6']['net_cash'], 0)

    def test_actual_retained_summary_products_and_context_projections(self):
        folder = ROOT / '.runtime/intraday-originals-20261016'
        if not folder.is_dir():
            self.skipTest('Private original market products unavailable; no reconstructed evidence claimed')
        def identity(path):
            return hashlib.sha256(path.read_bytes()).hexdigest()
        self.assertEqual(identity(folder / 'before-outcome-spec.json'), self.data['original_spec_sha256'])
        self.assertEqual(identity(folder / 'original-panel-summary.json'), self.data['original_summary_sha256'])
        self.assertEqual(identity(folder / 'before-outcome-source-bytes.zip'), self.data['original_source_archive_sha256'])
        summary = json.loads((folder / 'original-panel-summary.json').read_bytes())
        inspection = json.loads((folder / 'original-terminal-inspection.json').read_bytes())
        self.assertEqual(inspection['artifact_roster_sha256'], self.data['original_artifact_roster_sha256'])
        self.assertEqual(inspection['completed_originals_reverified'], 8)
        for key, case in self.data['cases'].items():
            original = summary['results'][key]; evidence = original['evidence']; metrics = evidence['scalp_metrics']
            self.assertEqual(case['status'], original['status'])
            self.assertEqual(case['response_sha256'], original['response_sha256'])
            self.assertEqual(identity(folder / 'products' / (key + '.response.json')), case['response_sha256'])
            for field in ('mature', 'net_cash'):
                self.assertEqual(case[field], evidence[field])
            self.assertEqual(case['daily_full_closes'], [day['mature_full_closes'] for day in metrics['per_day']])
            self.assertEqual(case['median_holding_minutes'], metrics['holding_minutes']['median'])
            self.assertEqual(case['max_holding_minutes'], metrics['holding_minutes']['max'])
            self.assertEqual(case['closed_same_utc_date'], evidence['intraday_profile']['closed_same_utc_date'])
            self.assertEqual(case['open'], evidence['open_position_count'])
        for example in self.data['context_examples']:
            for period, window in [('october', 'october_pre_dst'), ('december', 'december_standard')]:
                metrics = summary['results'][window + '__' + example['arm']]['evidence']['scalp_metrics']
                if example['kind'] == 'entry_calendar_phase':
                    rows = [row for row in metrics['entry_context_groups'] if row['venue_phase'] == example['phase']]
                    count = sum(row['mature_full_closes'] for row in rows)
                    cash = sum(row['realized_net_cash_by_entry_context'] for row in rows)
                else:
                    rows = [row for row in metrics['per_trade'] if
                        row['raw_public_trade_context']['market_regime'] == example['regime'] and
                        row['raw_public_trade_context']['volatility_regime'] == example['volatility']]
                    count, cash = len(rows), sum(row['exact_closed_pnl_cash'] for row in rows)
                self.assertEqual(count, example[period]['n'])
                self.assertAlmostEqual(cash, example[period]['cash'], places=7)


if __name__ == '__main__':
    unittest.main()
