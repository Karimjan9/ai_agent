"""Synthetic native observer checks; helper patches signals, never market proof."""
import copy
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

from app.services import backtester
from test_trailing_activation import request, replay, position, closed

_SPEC = importlib.util.spec_from_file_location('curator_activation_witness',
    Path(__file__).resolve().parents[1] / 'scripts/curator_activation_witness.py')
owner = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(owner)


class CuratorActivationWitnessTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original = backtester._advance_trailing_stop
        cls.products = {}
        for activation in (None, 0.0, 1.0):
            payload = request(activation)
            baseline = replay(payload.model_copy(deep=True)).model_dump()
            with owner.capture_trailing_activation_witness() as observer:
                actual = replay(payload.model_copy(deep=True)).model_dump()
            witness = observer.snapshot()
            cls.products[activation] = (payload.model_dump(), baseline, actual, witness,
                owner.verify_trailing_activation_witness(payload.model_dump(), actual, witness))

    def fixture(self, activation=1.0):
        request_body, _, response, witness, _ = self.products[activation]
        return copy.deepcopy(request_body), copy.deepcopy(response), copy.deepcopy(witness)

    def reseal(self, witness):
        witness['framed_event_sha256'] = owner._framed(witness['events'])
        witness['witness_sha256'] = owner._sha({key: value for key, value in witness.items() if key != 'witness_sha256'})

    def test_complete_response_parity_missing_zero_and_one(self):
        for activation, (_, baseline, actual, _, metrics) in self.products.items():
            with self.subTest(activation=activation):
                self.assertEqual(actual, baseline)
                self.assertGreater(metrics['accepted_event_count'], 0)
                self.assertEqual(metrics['accepted_position_count'], 1)
                self.assertFalse(metrics['economic_authority'])
        self.assertIs(backtester._advance_trailing_stop, type(self).original)
        self.assertGreater(self.products[1.0][4]['preactivation_held_events'], 0)
        self.assertEqual(self.products[1.0][4]['armed_positions'], 1)

    def test_delayed_preentry_threshold_equality_and_latch_for_buy_and_sell(self):
        for direction, threshold, adverse in [('BUY', 105.0, 101.0), ('SELL', 95.0, 99.0)]:
            payload, owned = request(1.0), position(direction)
            with owner.capture_trailing_activation_witness() as observer:
                for row in [closed(threshold, time='2025-01-06T00:05:00Z'),
                            closed(threshold), closed(adverse, time='2025-01-06T00:15:00Z')]:
                    backtester._advance_trailing_stop(owned, row, payload)
            events = observer.snapshot()['events']
            for event in events:
                expected, _ = owner._expected(event['before'], event['closed_row'], 2.0, 1.0)
                self.assertEqual(event['after'], expected)
            self.assertEqual(events[0]['before'], events[0]['after'])
            self.assertTrue(events[1]['after']['trailing_activation_armed'])
            self.assertTrue(events[2]['after']['trailing_activation_armed'])

    def test_preentry_missing_input_and_valid_below_threshold_are_distinct(self):
        before = position()
        for row, expected_label in [
                (closed(105.0, time='2025-01-06T00:05:00Z'), 'pre_entry_not_eligible'),
                (closed(104.0, atr=None), 'unavailable_original_input'),
                (closed(104.0), 'pre_activation_held')]:
            after, label = owner._expected(before, row.to_dict(), 2.0, 1.0)
            with self.subTest(expected_label=expected_label):
                self.assertEqual(label, expected_label)
                self.assertEqual(after, before)

    def test_zero_multiplier_is_unchanged_and_legacy_preentry_formula_preserved(self):
        payload, owned = request(0.0), position()
        payload.parameters['trailing_atr_multiplier'] = 0.0
        with owner.capture_trailing_activation_witness() as observer:
            backtester._advance_trailing_stop(owned, closed(), payload)
        self.assertEqual(observer.snapshot()['events'][0]['before'], observer.snapshot()['events'][0]['after'])
        payload.parameters['trailing_atr_multiplier'] = 2.0
        with owner.capture_trailing_activation_witness() as observer:
            backtester._advance_trailing_stop(owned, closed(time='2025-01-06T00:05:00Z'), payload)
        event = observer.snapshot()['events'][0]
        self.assertEqual(event['after']['stop_loss'], 103.0)

    def test_raw_market_session_unknown_and_missing_context_are_preserved(self):
        row = closed()
        row['market_session'] = 'unknown'
        row['market_session_version'] = 'original-calendar-v2'
        row['market_regime'] = 'raw_unclassified'
        with owner.capture_trailing_activation_witness() as observer:
            backtester._advance_trailing_stop(position(), row, request())
        event = observer.snapshot()['events'][0]
        self.assertEqual(event['context'], {'market_session': 'unknown',
            'market_session_version': 'original-calendar-v2', 'market_regime': 'raw_unclassified'})
        self.assertNotIn('market_session', event['context_missing_fields'])
        self.assertNotIn('market_session_version', event['context_missing_fields'])
        self.assertIn('session', event['context_missing_fields'])
        self.assertIn('venue_phase', event['context_missing_fields'])
        self.assertNotIn('session', event['context'])

    def test_rehashed_early_activation_and_loosened_stop_refused(self):
        for change in ('activation', 'loosen'):
            payload, response, witness = self.fixture()
            event = witness['events'][0]
            if change == 'activation':
                event['after']['trailing_activation_armed'] = True
            else:
                event['after']['stop_loss'] = event['before']['stop_loss'] - .25
            self.reseal(witness)
            with self.subTest(change=change), self.assertRaisesRegex(ValueError, 'ACTIVATION_AND_RATCHET'):
                owner.verify_trailing_activation_witness(payload, response, witness)

    def test_rehashed_state_discontinuity_refused(self):
        payload, response, witness = self.fixture()
        witness['events'][1]['before']['stop_loss'] += .25
        self.reseal(witness)
        with self.assertRaisesRegex(ValueError, 'STATE_CONTINUITY'):
            owner.verify_trailing_activation_witness(payload, response, witness)

    def test_parameter_entry_stop_maturity_and_digest_tampering_refused(self):
        for change in ('parameter', 'entry', 'stop', 'maturity', 'digest'):
            payload, response, witness = self.fixture()
            if change == 'parameter':
                payload['parameters']['trailing_activation_r'] = 0
            elif change == 'entry':
                response['trade_ledger'][0]['entry_price'] += .25
            elif change == 'stop':
                response['trade_ledger'][0]['stop_loss'] += .25
            elif change == 'maturity':
                response['statistical_evidence']['original_position_maturity']['unknown_maturity_count'] = 1
            else:
                witness['events'][0]['after']['stop_loss'] += .25
            with self.subTest(change=change), self.assertRaises(ValueError):
                owner.verify_trailing_activation_witness(payload, response, witness)

    def test_optional_raw_entry_link_exact_geometry(self):
        payload, response, witness = self.fixture()
        before = witness['events'][0]['before']
        raw = {'protocol': 'offline_curator_full_close_witness_v1', 'research_only': True,
            'request': {'parameters': payload['parameters'], 'execution': payload['execution']},
            'public_trades': response['trade_ledger'], 'entry_events': {'199': copy.deepcopy(before)}}
        metrics = owner.verify_trailing_activation_witness(payload, response, witness, raw)
        self.assertTrue(metrics['raw_accepted_entry_links_verified'])
        raw['entry_events']['199']['initial_stop_loss'] += .000001
        with self.assertRaisesRegex(ValueError, 'RAW_ACCEPTED_ENTRY_GEOMETRY'):
            owner.verify_trailing_activation_witness(payload, response, witness, raw)

    def test_no_position_empty_stream_is_explicitly_unactivated(self):
        from test_trailing_activation import software_frame
        data = software_frame().iloc[:200].copy()
        payload = request(1.0)
        with owner.capture_trailing_activation_witness() as observer:
            response = replay(payload, frame=data).model_dump()
        witness = observer.snapshot()
        self.assertIsNone(witness['request'])
        self.assertEqual(witness['events'], [])
        metrics = owner.verify_trailing_activation_witness(payload.model_dump(), response, witness)
        self.assertEqual(metrics['accepted_position_count'], 0)
        self.assertFalse(metrics['observed_native_call'])

    def test_positive_shadow_geometry_and_scope_remain_separate(self):
        payload, response, witness = self.fixture()
        events = copy.deepcopy(witness['events'])
        for event in events:
            event['scope'] = 'shadow_counterfactual'
            event['shadow_policy_arm'] = 'shadow_control'
        witness['events'] = events
        response['trade_ledger'] = []
        response['total_trades'] = 0
        response['statistical_evidence']['original_position_maturity']['closed_trade_count'] = 0
        self.reseal(witness)
        metrics = owner.verify_trailing_activation_witness(payload, response, witness)
        self.assertEqual(metrics['accepted_position_count'], 0)
        self.assertEqual(metrics['armed_positions'], 0)
        self.assertEqual(metrics['shadow_counterfactual_event_count'], len(events))
        for event in witness['events']:
            event['before'].pop('initial_risk_distance')
            event['after'].pop('initial_risk_distance')
        self.reseal(witness)
        with self.assertRaises((ValueError, KeyError)):
            owner.verify_trailing_activation_witness(payload, response, witness)

    def test_nested_exception_and_original_exception_restore_exact_callable(self):
        with owner.capture_trailing_activation_witness():
            with self.assertRaisesRegex(ValueError, 'NESTED_OR_CONCURRENT'):
                with owner.capture_trailing_activation_witness():
                    pass
        with self.assertRaisesRegex(RuntimeError, 'interrupted'):
            with owner.capture_trailing_activation_witness():
                raise RuntimeError('interrupted')
        self.assertIs(backtester._advance_trailing_stop, type(self).original)
        def failed(*_args):
            raise RuntimeError('producer interrupted')
        with patch.object(backtester, '_advance_trailing_stop', failed):
            with self.assertRaisesRegex(RuntimeError, 'producer interrupted'):
                with owner.capture_trailing_activation_witness():
                    backtester._advance_trailing_stop(position(), closed(), request())
            self.assertIs(backtester._advance_trailing_stop, failed)


if __name__ == '__main__':
    unittest.main()
