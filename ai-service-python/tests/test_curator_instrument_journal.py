"""Public journal custody and synthetic selected-classifier checks only.

No private original product is opened and no replay is invoked. Presence of a
review record is not proof that its source claims or a trading method are true.
The optional ta package is not installed by this test: the actual selected
classifier path, including the existing fallback when absent, is tested honestly.
"""
import hashlib
import json
from pathlib import Path
import re
import unittest
from urllib.parse import urlparse

import numpy as np
import pandas as pd

from app.services import market_regime
from app.strategies.laboratory import apply_session_mean_reversion_strategy
from app.strategies.registry import get_strategy


ROOT = Path(__file__).resolve().parents[2]
JOURNAL = ROOT / 'docs/research/instruments/range_reentry/events'
EVENT_NAMES = ('2026-10-16-source-review.json', '2026-10-16-context-question.json',
               '2026-10-16-source-addendum.json')
EVENT_IDS = ('range_reentry-source-review-20261016-01',
             'range_reentry-context-question-20261016-02',
             'range_reentry-source-addendum-20261016-03')
SOURCE_SHA = 'bb5e378b4ca700bbcacf85594bd4d8f00112b160fcb0ebd106e21893bc18fdc8'
QUESTION_SHA = '87ecdc06b7d3a5971687391e7181d8c1b406ff18de009fb02cabb72603c195e6'
QUESTION = 'bollinger-range-context-20261016-v1'
FALSE_FLAGS = ('own_agent_outcome', 'independent_validation', 'economic_authority',
               'paper_authority', 'live_authority')
PARAMETERS = {'session_start': 7, 'session_end': 14, 'lookback': 20}
PREFIXES = (200, 201, 250, 377, 500, 599, 600)
TA_AVAILABLE = all(getattr(market_regime, name) is not None
                   for name in ('ADXIndicator', 'EMAIndicator', 'AverageTrueRange'))


def synthetic_closed_prices():
    """One fixed 600-row M5 arithmetic fixture; no supplied context labels."""
    index = np.arange(600, dtype=float)
    close = 100.0 + .12 * np.sin(index / 3.0) + .03 * np.sin(index / 11.0)
    for row, excursion in ((240, -1.), (280, 1.), (320, -1.),
                           (360, 1.), (400, -1.), (440, 1.)):
        close[row] += excursion
    width = 2.0 - .001 * index
    return pd.DataFrame({'time': pd.date_range('2025-01-06T06:00:00Z', periods=600, freq='5min'),
                         'open': close, 'high': close + width, 'low': close - width, 'close': close})


class CuratorInstrumentJournalTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Only these three public events are read. Later result events, private
        # summaries and partial worker products are deliberately not discovered.
        cls.raw = [(JOURNAL / name).read_bytes() for name in EVENT_NAMES]
        cls.events = [json.loads(raw) for raw in cls.raw]

    def test_three_public_events_keep_protocol_instrument_and_no_authority(self):
        for event, identity in zip(self.events, EVENT_IDS):
            with self.subTest(event=identity):
                self.assertEqual(event['protocol'], 'curator_instrument_research_event_v1')
                self.assertEqual(event['event_id'], identity)
                self.assertEqual(event['instrument_key'], 'range_reentry')
                self.assertEqual(event['research_date'], '2026-10-16')
                self.assertEqual(event['curator'], 'coding_assistant_not_agent_owned_experience')
                self.assertIs(event['research_only'], True)
                for flag in FALSE_FLAGS:
                    self.assertIs(event[flag], False)
                for optional in ('credit', 'mastery', 'promotion_evidence', 'production_authority'):
                    if optional in event:
                        self.assertIs(event[optional], False)
        self.assertEqual(len(set(EVENT_IDS)), 3)

    def test_exact_original_bytes_form_source_question_addendum_sha_chain(self):
        source, question, addendum = self.events
        hashes = [hashlib.sha256(raw).hexdigest() for raw in self.raw]
        self.assertEqual(hashes[0], SOURCE_SHA)
        self.assertEqual(hashes[1], QUESTION_SHA)
        self.assertIsNone(source['previous_event_sha256'])
        self.assertEqual(question['previous_event_sha256'], hashes[0])
        self.assertEqual(addendum['previous_event_sha256'], hashes[1])
        self.assertEqual(question['question_id'], QUESTION)
        self.assertEqual(addendum['sealed_question_id'], QUESTION)
        self.assertRegex(addendum['sealed_spec_sha256'], r'^[0-9a-f]{64}$')
        self.assertIs(addendum['execution_change'], False)
        # The addendum's own hash is not pinned: later journal events can link
        # its genuine bytes without requiring this test's hash in the journal.

    def test_every_public_event_extends_one_exact_historical_chain(self):
        events = []
        for path in JOURNAL.glob('*.json'):
            raw = path.read_bytes(); event = json.loads(raw)
            events.append((int(event['event_id'].rsplit('-', 1)[1]), raw, event))
        events.sort(key=lambda row: row[0])
        self.assertEqual([row[0] for row in events], list(range(1, len(events) + 1)))
        previous = None
        for _, raw, event in events:
            self.assertEqual(event['previous_event_sha256'], previous)
            self.assertEqual(event['instrument_key'], 'range_reentry')
            self.assertIs(event['research_only'], True)
            for flag in FALSE_FLAGS:
                self.assertIs(event[flag], False)
            previous = hashlib.sha256(raw).hexdigest()

    def test_subsequent_captures_do_not_claim_to_be_pre_outcome_attestations(self):
        event = json.loads((JOURNAL / '2026-10-16-source-capture.json').read_bytes())
        self.assertEqual(event['event_type'], 'public_author_PDF_capture_after_question_seal')
        self.assertIs(event['execution_change'], False)
        self.assertIn('not a backdated', event['custody_boundary'])
        self.assertEqual({row['source_id'] for row in event['source_captures']},
                         {row['source_id'] for row in self.events[0]['source_reviews']})
        for row in event['source_captures']:
            self.assertRegex(row['sha256'], r'^[0-9a-f]{64}$')
            self.assertGreater(row['bytes'], 0)
            self.assertIs(row['pdf_magic'], True)

    def test_retained_actual_pdf_captures_match_the_recorded_bytes(self):
        event = json.loads((JOURNAL / '2026-10-16-source-capture.json').read_bytes())
        folder = ROOT / event['capture_folder']
        if not folder.is_dir():
            self.skipTest('Private author source captures are not redistributed')
        for row in event['source_captures']:
            raw = (folder / row['file']).read_bytes()
            self.assertEqual(raw[:5], b'%PDF-')
            self.assertEqual(len(raw), row['bytes'])
            self.assertEqual(hashlib.sha256(raw).hexdigest(), row['sha256'])

    def test_public_terminal_account_table_is_not_a_qualified_skill(self):
        event = json.loads((JOURNAL / '2026-10-16-terminal-results.json').read_bytes())
        self.assertEqual(event['knowledge_label'], 'observed_know_how_not_qualified_skill')
        self.assertEqual(len(event['cases']), 8)
        self.assertEqual(event['read_only_inspection']['original_replays_repeated'], 0)
        self.assertEqual(event['read_only_inspection']['completed_originals_reverified'], 8)
        diagnostic = {row['window']: row for row in event['post_outcome_feature_diagnostic']['windows']}
        self.assertEqual(diagnostic['august2025']['joint_active_context_rows'], 0)
        self.assertEqual(diagnostic['august2025']['joint_context_recross_signals'], 0)
        for case in event['cases']:
            self.assertEqual(case['status'], 'completed')
            self.assertEqual(sum(case['per_day_mature_closes']), case['mature'])
            if case['case_key'].startswith('august2025__range_low'):
                self.assertEqual((case['mature'], case['raw_strategy_signals'], case['net_cash']), (0, 0, 0.))

    def test_private_original_summary_reproduces_each_public_result_without_replay(self):
        event = json.loads((JOURNAL / '2026-10-16-terminal-results.json').read_bytes())
        path = ROOT / event['original_folder'] / 'original-panel-summary.json'
        if not path.is_file():
            self.skipTest('Private original products are not redistributed')
        raw = path.read_bytes(); summary = json.loads(raw)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), event['summary_sha256'])
        self.assertEqual(summary['before_outcome_spec_sha256'], event['spec_sha256'])
        self.assertEqual(summary['source_archive_sha256'], event['source_archive_sha256'])
        self.assertEqual({row['case_key'] for row in event['cases']}, set(summary['results']))
        for row in event['cases']:
            original = summary['results'][row['case_key']]; evidence = original['evidence']
            for field in ('mature', 'net_cash', 'closed_cash_drawdown_percent'):
                self.assertEqual(row[field], evidence[field])
            self.assertEqual(row['raw_strategy_signals'], evidence['scalp_metrics']['entry_funnel']['raw_strategy_signals'])
            self.assertEqual(row['per_day_mature_closes'],
                             [day['mature_full_closes'] for day in evidence['scalp_metrics']['per_day']])
        for window, effects in event['whole_account_contrasts'].items():
            original = summary['comparisons'][window]
            self.assertEqual(effects['cash_interaction'], original['cash_interaction'])
            for name, effect in original['pairs'].items():
                self.assertTrue(effect['matched_complete'])
                self.assertEqual(effects[name], effect['realized_cash_delta'])

    def test_source_records_have_real_text_and_explicit_unreviewed_boundaries(self):
        identities = []
        placeholder = re.compile(r'\b(?:TODO|TBD|PLACEHOLDER)\b', re.IGNORECASE)
        for event in (self.events[0], self.events[2]):
            self.assertTrue(event['source_reviews'])
            for review in event['source_reviews']:
                identities.append(review['source_id'])
                with self.subTest(source=review['source_id']):
                    for field in ('source_id', 'url', 'publisher', 'reviewed', 'finding'):
                        self.assertIsInstance(review[field], str)
                        self.assertTrue(review[field].strip())
                        self.assertNotRegex(review[field], placeholder)
                    parsed = urlparse(review['url'])
                    self.assertEqual(parsed.scheme, 'https')
                    self.assertTrue(parsed.netloc)
                    self.assertIsInstance(review['not_reviewed'], list)
                    self.assertTrue(review['not_reviewed'])
                    for boundary in review['not_reviewed']:
                        self.assertIsInstance(boundary, str)
                        self.assertTrue(boundary.strip())
                        self.assertNotRegex(boundary, placeholder)
                    self.assertTrue(any('video' in item.lower() for item in review['not_reviewed']))
                    self.assertTrue(any('book' in item.lower() for item in review['not_reviewed']))
        self.assertEqual(len(identities), len(set(identities)))
        self.assertTrue(self.events[0]['known_implementation_differences'])
        self.assertTrue(self.events[2]['access_boundary'].strip())

    def test_prospective_question_and_followup_preserve_their_bounded_scope(self):
        source, question, addendum = self.events
        expected = [('all_scalp6', 'none', 6), ('range_low_scalp6', 'range_low_v1', 6),
                    ('all_day24', 'none', 24), ('range_low_day24', 'range_low_v1', 24)]
        self.assertEqual([(arm['key'], arm['session_context_gate'], arm['time_stop_candles'])
                          for arm in question['predeclared_arms']], expected)
        self.assertEqual(question['hypothesis_ids'], ['BB-H001', 'BB-H002'])
        self.assertEqual({row['key'] for row in question['selected_windows']}, {'may2025', 'august2025'})
        budget = question['budgets']
        self.assertEqual((budget['original_cases'], budget['attempts_per_case'],
                          budget['per_case_seconds'], budget['whole_panel_seconds']), (8, 1, 600, 5400))
        for field in ('selection_rule', 'execution', 'gate', 'analysis_boundary', 'terminal_rules', 'exposure'):
            self.assertTrue(question[field].strip())
        self.assertIn('not identical-entry', question['analysis_boundary'])
        self.assertIn('not a day-flat guarantee', question['analysis_boundary'])
        self.assertIn('unknown, not zero', question['terminal_rules'])
        self.assertIn('No untouched claim', question['exposure'])
        missing_exit = next(item for item in source['hypotheses'] if item['id'] == 'BB-H006')
        self.assertEqual(missing_exit['status'], 'adapter_dependent_unexecuted')
        self.assertIn('not_currently_available', missing_exit['dependency'])
        self.assertEqual(addendum['new_followup']['status'], 'unexecuted_source_fidelity_question')


class SelectedClassifierContextGateTest(unittest.TestCase):
    def classify_and_gate(self, frame):
        classified = market_regime.apply_market_regime(frame, variant='frozen')
        function = get_strategy('session_mean_reversion_v1', 'session_mean_reversion')
        self.assertIs(function, apply_session_mean_reversion_strategy)
        gated = function(classified, {**PARAMETERS, 'session_context_gate': 'range_low_v1'})
        return classified, gated

    def test_actual_selected_dependency_path_is_explicit_not_claimed_as_optional_ta(self):
        source = synthetic_closed_prices()
        classified = market_regime.apply_market_regime(source, variant='frozen')
        optional = [market_regime.ADXIndicator, market_regime.EMAIndicator, market_regime.AverageTrueRange]
        self.assertTrue(all(item is None for item in optional) or all(item is not None for item in optional))
        if not TA_AVAILABLE:
            # The original frozen runtime lacks ta. These are the existing
            # source-owned fallback calculations, not evidence about ta/Wilder.
            pd.testing.assert_series_equal(classified['ema_50_regime'],
                source.close.ewm(span=50, adjust=False).mean(), check_names=False, check_exact=True)
            pd.testing.assert_series_equal(classified['ema_200_regime'],
                source.close.ewm(span=200, adjust=False).mean(), check_names=False, check_exact=True)
            pd.testing.assert_series_equal(classified['atr_regime'],
                market_regime._average_true_range(source, 14), check_names=False, check_exact=True)
            pd.testing.assert_series_equal(classified['adx'],
                market_regime._adx(source, 14), check_names=False, check_exact=True)

    def test_actual_classifier_then_registered_gate_is_exact_on_mature_prefixes(self):
        source = synthetic_closed_prices()
        original = source.copy(deep=True)
        self.assertNotIn('market_regime', source)
        self.assertNotIn('volatility_regime', source)
        full_context, full_gate = self.classify_and_gate(source)
        eligible = full_context.market_regime.eq('range') & full_context.volatility_regime.eq('low_volatility')
        self.assertTrue(eligible.any())
        self.assertTrue((~eligible).any())
        baseline = apply_session_mean_reversion_strategy(full_context, {**PARAMETERS, 'session_context_gate': 'none'})
        expected = baseline.copy(deep=True)
        expected.loc[~eligible, 'signal'] = 'WAIT'
        expected.loc[~eligible, 'signal_confidence'] = 0.0
        pd.testing.assert_frame_equal(full_gate, expected, check_exact=True)
        for length in PREFIXES:
            with self.subTest(prefix=length):
                context, gate = self.classify_and_gate(source.iloc[:length].copy())
                pd.testing.assert_frame_equal(context, full_context.iloc[:length], check_exact=True)
                pd.testing.assert_frame_equal(gate, full_gate.iloc[:length], check_exact=True)
        future = source.copy(deep=True)
        future.loc[500:, ['open', 'high', 'low', 'close']] *= 10.0
        changed_context, changed_gate = self.classify_and_gate(future)
        pd.testing.assert_frame_equal(changed_context.iloc[:500], full_context.iloc[:500], check_exact=True)
        pd.testing.assert_frame_equal(changed_gate.iloc[:500], full_gate.iloc[:500], check_exact=True)
        pd.testing.assert_frame_equal(source, original, check_exact=True)

    @unittest.skipUnless(TA_AVAILABLE, 'Optional ta unavailable; actual fallback is tested, not genuine ta evidence')
    def test_optional_ta_binding_when_available(self):
        from ta.trend import ADXIndicator, EMAIndicator
        from ta.volatility import AverageTrueRange
        self.assertIs(market_regime.ADXIndicator, ADXIndicator)
        self.assertIs(market_regime.EMAIndicator, EMAIndicator)
        self.assertIs(market_regime.AverageTrueRange, AverageTrueRange)


if __name__ == '__main__':
    unittest.main()
