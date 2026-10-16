"""Synthetic signal accounting only; never invoke the retained archive diagnostic."""
import copy
from contextlib import ExitStack
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import pandas as pd
from scripts import curator_ema_opportunity_audit as audit


def fixture(*, execution='2025-01-06T08:00:00Z', confidence=1.0, reason=None, phase='signal_evaluation'):
    times = pd.date_range(pd.Timestamp(execution) - pd.Timedelta(minutes=1000), periods=203, freq='5min')
    frame = pd.DataFrame({'time': times, 'open': 100., 'high': 101., 'low': 99., 'close': 100.,
        'adx': 20 + confidence * 30, 'trend_rsi': 55., 'trend_fast': 100., 'trend_slow': 99.,
        'signal_confidence': confidence, 'signal': 'WAIT'})
    frame.loc[199, 'signal'] = 'BUY'
    features = [{'method_pullback_available': True, '_pullback_direction': 'BUY' if i == 199 else None,
        'method_pullback_pretrend_available': True, '_pretrend_pullback_direction': 'BUY' if i == 199 else None,
        '_ema20': 100., '_ema50': 99.} for i in range(203)]
    trace = []
    for i in range(200, 203):
        source = frame.iloc[i - 1]
        trace.append({'candle_index': i, 'candle_time': times[i].isoformat(),
            'event_type': phase if i == 200 else 'signal_evaluation', 'action': 'BUY' if i == 200 else 'WAIT',
            'accepted': i == 200 and reason is None and phase == 'signal_evaluation',
            'rejection_code': reason if i == 200 else 'wait', 'confidence': confidence,
            'market_regime': 'trend_up', 'volatility_regime': 'normal_volatility',
            'features': {**{k: float(source[k]) for k in ('adx', 'trend_rsi', 'trend_fast', 'trend_slow', 'signal_confidence')},
                'source_entry_rule_admitted': i == 200, 'source_entry_rule_direction': 'BUY' if i == 200 else 'WAIT',
                'source_entry_rule_protocol': 'closed_ema_pretrend_entry_v1'}})
    flat = int(phase == 'signal_evaluation'); accepted = int(flat and reason is None)
    request = {'parameters': {'trend_strength_min': 20., 'minimum_signal_confidence': .35}}
    body = {'decision_trace': trace, 'entry_funnel': {'raw_strategy_signals': 1,
        'flat_signal_opportunities': flat, 'accepted_entries': accepted,
        'rejected': {reason: 1} if flat and reason is not None else {}}}
    original = (request, body, {'entry_indices': [199] if accepted else []})
    return frame, features, [copy.deepcopy(original), copy.deepcopy(original)]


class EmaOpportunityAuditTest(unittest.TestCase):
    def test_intersections_first_veto_and_filled_indices_reconcile(self):
        frame, features, pair = fixture()
        result, cards = audit.analyze_month(frame, features, pair, 1)
        self.assertEqual(result['raw'], 1)
        self.assertEqual(result['all_raw_intersections']['inside_confidence_pass'], 1)
        self.assertEqual(result['source_stages']['two_bar_core'], 1)
        self.assertEqual(result['arms']['control']['filled'], 1)
        self.assertEqual(cards['original_fill']['source_index'], 199)
        self.assertEqual(cards['original_fill']['execution_index'], 200)
        self.assertEqual(cards['outside_session_confidence_pass'], {'status': 'absent'})

    def test_execution_1400_is_outside_even_when_signal_1355_is_inside(self):
        frame, features, pair = fixture(execution='2025-01-06T14:00:00Z', reason='outside_session')
        result, cards = audit.analyze_month(frame, features, pair, 1)
        self.assertEqual(result['all_raw_intersections']['outside_confidence_pass'], 1)
        self.assertTrue(cards['exact_session_boundary']['source_time'].endswith('13:55:00+00:00'))

    def test_outside_session_hides_an_already_low_recorded_confidence(self):
        frame, features, pair = fixture(execution='2025-01-06T15:00:00Z', confidence=.1, reason='outside_session')
        result, _ = audit.analyze_month(frame, features, pair, 1)
        self.assertEqual(result['arms']['control']['first_veto'], {'outside_session': 1})
        self.assertEqual(result['all_raw_intersections']['outside_confidence_fail'], 1)

    def test_occupied_vs_actual_state_veto_is_not_a_fabricated_flat_default(self):
        frame, features, pair = fixture(reason='position_open', phase='position_management')
        _, _, changed = fixture(confidence=1, reason='loss_streak_wait')
        pair[1] = changed[1]
        result, cards = audit.analyze_month(frame, features, pair, 1)
        self.assertEqual(result['arms']['control']['occupied'], 1)
        self.assertEqual(result['arms']['structural']['first_veto'], {'loss_streak_wait': 1})
        self.assertEqual(cards['occupied_or_state_different']['execution_index'], 200)

    def test_missing_extra_raw_trace_rows_or_execution_off_by_one_refuse(self):
        for change in ('missing_raw', 'extra_raw', 'index', 'time', 'feature', 'raw_count'):
            frame, features, pair = fixture()
            events = pair[0][1]['decision_trace']
            if change == 'missing_raw': events[0]['features']['source_entry_rule_admitted'] = False
            elif change == 'extra_raw': events[1]['features']['source_entry_rule_admitted'] = True
            elif change == 'index': events[0]['candle_index'] -= 1
            elif change == 'time': events[0]['candle_time'] = frame.iloc[199]['time'].isoformat()
            elif change == 'feature': events[0]['features']['adx'] += 1
            with self.subTest(change=change), self.assertRaises(ValueError):
                audit.analyze_month(frame, features, pair, 2 if change == 'raw_count' else 1)

    def test_descriptors_are_past_only_and_gaps_or_immaturity_are_unknown(self):
        frame, features, _ = fixture()
        before = audit.past_descriptors(frame, features, 199)
        self.assertEqual(before['ema20_inside_closed_candle_range_rows'], 20)
        frame.loc[200:, ['low', 'high']] = 1000.
        features[200]['_ema20'] = 1000.
        self.assertEqual(before, audit.past_descriptors(frame, features, 199))
        self.assertIn('unavailable', audit.past_descriptors(frame, features, 10)['status'])
        frame.loc[195:, 'time'] += pd.Timedelta(minutes=5)
        self.assertIn('unavailable', audit.past_descriptors(frame, features, 199)['status'])

    def test_authority_and_non_boolean_flags_cannot_change_the_question(self):
        question = json.loads(audit.QUESTION.read_bytes())
        audit.validate_question(question)
        for value in (True, 0):
            changed = copy.deepcopy(question); changed['economic_authority'] = value
            with self.subTest(value=value), self.assertRaises(ValueError): audit.validate_question(changed)

    def test_byte_source_roster_and_before_after_hash_tamper_refuse(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); path = root / 'ai-service-python/app/a.py'; path.parent.mkdir(parents=True)
            path.write_bytes(b'original'); roster = {'ai-service-python/app/a.py': audit.digest(b'original')}; custody = {}
            audit.bind_app(root, roster, custody); self.assertEqual(audit.unchanged(custody), custody)
            path.write_bytes(b'changed')
            with self.assertRaises(ValueError): audit.bind_app(root, roster, {})
            with self.assertRaises(ValueError): audit.unchanged(custody)
            path.write_bytes(b'original'); path.with_name('extra.py').write_bytes(b'extra')
            with self.assertRaises(ValueError): audit.bind_app(root, roster, {})

    def test_unreviewed_source_fails_before_attempt_or_real_original_reads(self):
        with patch.object(audit, 'inputs', side_effect=AssertionError('real originals read')), \
                patch.object(audit.AbsoluteResearchDeadline, 'start') as start:
            with self.assertRaisesRegex(ValueError, 'REVIEWED_AUDITOR_SOURCE'): audit.export('f' * 64)
            start.assert_not_called()

    def test_runtime_drift_and_expired_attempt_fail_closed_without_retry(self):
        with patch.object(audit, 'runtime_fingerprint', return_value={'changed': True}):
            with self.assertRaisesRegex(ValueError, 'ORIGINAL_DECLARED_RUNTIME'):
                audit.require_runtime({'runtime': {'original': True}})
        clock = [0.]
        deadline = audit.AbsoluteResearchDeadline.start(300, monotonic=lambda: clock[0], utc=lambda: clock[0])
        clock[0] = 301.
        with tempfile.TemporaryDirectory() as folder, patch.object(audit, 'OUTPUT', Path(folder) / 'one-attempt'), \
                patch.object(audit.AbsoluteResearchDeadline, 'start', return_value=deadline), \
                patch.object(audit, 'inputs', side_effect=lambda d: d.checkpoint('synthetic_expiry')) as inputs:
            source_sha = audit.digest(Path(audit.__file__).read_bytes())
            with self.assertRaisesRegex(RuntimeError, 'RESEARCH_ABSOLUTE_DEADLINE_EXPIRED'):
                audit.export(source_sha)
            self.assertEqual(json.loads((audit.OUTPUT / 'failure.json').read_bytes())['status'], 'technical_unknown')
            with self.assertRaises(FileExistsError): audit.export(source_sha)
            inputs.assert_called_once()

    def test_real_synthetic_feature_preparation_never_enters_account_or_eligibility(self):
        from app.schemas import SimpleBacktestRequest
        from app.services import backtester
        from test_source_grounded_method_runtime import software_frame
        request = SimpleBacktestRequest(strategy='trend_v1', base_strategy='trend', timeframe='M5',
            parameters={'ema_fast': 20, 'ema_slow': 50, 'rsi_period': 14, 'trend_strength_min': 20.,
                'entry_topology_variant': 'source_ema_pretrend_pullback_v1', 'volume_lane': 'none'})
        request.execution.allowed_sessions_utc = ['7-14']
        with ExitStack() as stack:
            for name in ('run_simple_ema_rsi_backtest', 'run_simple_ema_rsi_backtest_on_dataframe',
                         '_run_prepared_simple_backtest', '_entry_eligibility'):
                stack.enter_context(patch.object(backtester, name, side_effect=AssertionError('account/gate called')))
            feature = stack.enter_context(patch.object(backtester, 'prepare_feature_snapshot', wraps=backtester.prepare_feature_snapshot))
            signal = stack.enter_context(patch.object(backtester, 'prepare_signal_snapshot', wraps=backtester.prepare_signal_snapshot))
            frame, values = audit.prepare_month(request.model_dump(), software_frame().to_csv(index=False).encode())
            self.assertEqual(len(frame), len(values)); self.assertEqual(len(frame), 272)
            feature.assert_called_once(); signal.assert_called_once()
            self.assertIn('feature_snapshot', signal.call_args.kwargs)


if __name__ == '__main__':
    unittest.main()
