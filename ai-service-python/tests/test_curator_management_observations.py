"""Immutable curator discovery artifacts, not independent market qualification."""
import hashlib
import json
import math
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
RESOURCES = ROOT / 'backend-laravel/resources/research/instrument-operating-skills'


def read(name):
    return json.loads((RESOURCES / name).read_bytes())


class CuratorManagementObservationsTest(unittest.TestCase):
    def setUp(self):
        self.management = read('curator-management-observations-v1.json')
        self.activation = read('curator-activation-observations-v1.json')
        self.usage = read('curator-activation-session-usage-v1.json')

    def test_versioned_original_artifacts_and_older_knowledge_are_unchanged(self):
        identities = {
            'curator-management-observations-v1.json': 'e2f93af1bfa7a82effe0b6caf0f6fd87eb20dfc078bc93c72f7ffc0982a0856f',
            'curator-activation-observations-v1.json': 'cd7e00d0c31d1cc35990434660f1f5de3bf8b46103dc7579bfba63027cf95594',
            'curator-activation-session-usage-v1.json': 'f8bd8c81e1bc0e7064d99f2ddebd9bdd1eccf72129c0664575603b7aadf71c95',
            'curator-context-observations-v5.json': '3a62699181d30e0c549141e9099c0872366818b4831f31c80239e3f0fd15c231',
        }
        for name, identity in identities.items():
            with self.subTest(name=name):
                self.assertEqual(hashlib.sha256((RESOURCES / name).read_bytes()).hexdigest(), identity)

    def test_original_completion_does_not_grant_authority_or_own_agent_outcomes(self):
        for body, count in ((self.management, 16), (self.activation, 12)):
            self.assertEqual(body['completed_originals'], count)
            self.assertEqual(len(body['originals']), count)
            self.assertTrue(body['curator_owned_not_agent_experience'])
            self.assertTrue(body['research_only'])
            for key in ('own_agent_outcome', 'independent_validation', 'credit', 'mastery',
                        'economic_authority', 'paper_authority', 'live_authority'):
                self.assertIs(body[key], False, key)
            for case in body['originals'].values():
                self.assertEqual(case['status'], 'completed')
                self.assertEqual(len(case['response_sha256']), 64)
                self.assertTrue(math.isfinite(case['evidence']['net_cash']))
            for window in body['windows'].values():
                self.assertLess(window['evaluation_last_close_utc'], '2026-01-01T00:00:00Z')

    def test_factorial_arithmetic_retains_both_positive_and_negative_interaction(self):
        signs = []
        for window, comparison in self.management['window_comparisons'].items():
            values = {arm: self.management['originals'][window + '__' + arm]['evidence']['net_cash']
                      for arm in ('control', 'native_atr_ratchet', 'chandelier_close', 'hybrid')}
            interaction = values['hybrid'] - values['native_atr_ratchet'] - values['chandelier_close'] + values['control']
            self.assertAlmostEqual(comparison['factorial']['interaction'], interaction, places=8)
            signs.append(interaction)
        self.assertTrue(any(value < 0 for value in signs))
        self.assertTrue(any(value > 0 for value in signs))

    def test_new_source_controls_are_originally_executed_not_old_arm_substitution(self):
        self.assertNotEqual(self.management['original_source_snapshot_hash'], self.activation['original_source_snapshot_hash'])
        for window in self.activation['windows']:
            for old, new in (('control', 'control'), ('native_atr_ratchet', 'immediate_atr2')):
                before = self.management['originals'][window + '__' + old]
                after = self.activation['originals'][window + '__' + new]
                self.assertEqual(before['dataset_sha256'], after['dataset_sha256'])
                self.assertEqual(before['cost_sha256'], after['cost_sha256'])
                self.assertNotEqual(before['request_sha256'], after['request_sha256'])
                self.assertAlmostEqual(before['evidence']['net_cash'], after['evidence']['net_cash'], places=8)

    def test_session_projection_reconciles_all_actual_and_shadow_counts_without_profit_claim(self):
        usage = self.usage
        self.assertEqual(len(usage['original_case_totals']), 12)
        self.assertEqual(usage['original_observation_resource_sha256'],
                         hashlib.sha256((RESOURCES / 'curator-activation-observations-v1.json').read_bytes()).hexdigest())
        self.assertTrue(usage['original_raw_context_unchanged'])
        self.assertTrue(usage['calendar_context_not_proven_decision_consumed'])
        self.assertFalse(usage['session_or_phase_PnL_attribution'])
        self.assertFalse(usage['economic_skill'])
        self.assertFalse(usage['automatic_agent_preference'])
        total_armed = 0
        for expected in usage['original_case_totals']:
            metrics = self.activation['originals'][expected['case_key']]['evidence']['activation_metrics']
            rows = [row for row in usage['descriptive_session_usage']
                    if row['window'] + '__' + row['arm'] == expected['case_key']]
            actual = [row for row in rows if row['scope'] == 'accepted_original']
            shadow = [row for row in rows if row['scope'] == 'shadow_counterfactual']
            self.assertEqual(sum(row['calls'] for row in actual), metrics['accepted_event_count'])
            self.assertEqual(sum(row['calls'] for row in shadow), metrics['shadow_counterfactual_event_count'])
            self.assertEqual(sum(row['newly_armed'] for row in actual), metrics['armed_positions'])
            self.assertEqual(sum(row['tightened'] for row in actual), metrics['tightened_stop_events'])
            total_armed += metrics['armed_positions']
        self.assertEqual(total_armed, 96)

    def test_open_eof_and_null_context_never_turn_into_settled_utility(self):
        self.assertTrue(self.activation['eof_open_exposure_is_unknown_total_terminal_utility'])
        self.assertFalse(self.activation['total_account_utility_claim_allowed'])
        self.assertFalse(self.usage['raw_context_completion_claim'])
        rows = list(self.activation['originals'].values())
        self.assertTrue(any(row['evidence']['open_position_count'] for row in rows))
        for row in rows:
            self.assertFalse(row['evidence']['dominance_claim'])
            self.assertTrue(row['evidence']['cash_metrics']['per_trade_tokens_remain_in_immutable_original_witness'])


if __name__ == '__main__':
    unittest.main()
