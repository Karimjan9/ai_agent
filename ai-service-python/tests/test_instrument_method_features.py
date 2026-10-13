"""Closed prefixes and owned mature outcomes, not profitable-market evidence."""

import copy
import math
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.instrument_method_features import (
    ClosedInstrumentMethodFeatures, BOOL_FIELDS, NUMBER_FIELDS, PROTOCOL,
)
from app.services.instrument_method_learning import InstrumentMethodLearning
from app.services.research_program_tasks import BoundedDecisionProgram, canonical_hash
from app.services.specialist_council import seal_contract
from test_instrument_method_learning import pinned_recipe, learning_contract, const, strategy
from test_specialist_council import fixtures, member


def frame(count=96):
    closes = [100 + .015 * index + 2 * math.sin(index * math.pi / 6) for index in range(count)]
    return pd.DataFrame({'time': pd.date_range('2025-01-06T08:00Z', periods=count, freq='5min'),
        'open': closes, 'high': [value + .3 for value in closes], 'low': [value - .3 for value in closes],
        'close': closes, 'volume': [100.0 + index for index in range(count)], 'volume_available': True,
        'market_session': 'london', 'market_regime': ['trend_up' if index < 48 else 'trend_down' for index in range(count)],
        'signal': ['BUY' if index % 5 else 'WAIT' for index in range(count)]})


def values(owner, data, index, direction='BUY'):
    return owner.inputs(data.iloc[index].to_dict(), direction=direction,
        observed_at=(data.iloc[index]['time'] + pd.Timedelta(minutes=5)).isoformat())


def operator(ast, bindings, *, owner=True, owner_capability=True):
    return BoundedDecisionProgram(seal_contract({'protocol': 'typed_operator_decision_v1',
        'source_task_key': 'method:synthetic', 'scope_key': 'closed-fixture',
        'source_context': {'method_feature_owner': PROTOCOL} if owner else {},
        'target': 'confirmation', 'ast': ast, 'ast_hash': canonical_hash(ast), 'abstractions': {},
        'input_bindings': bindings, 'budget': {'cpu_seconds': 1.0, 'max_calls': 1000, 'max_node_evaluations': 48000}}),
        allow_method_features=owner_capability)


def node(key, bool_type=False):
    return {'op': 'BOOL' if bool_type else 'NUMBER', 'input_key': key, 'available_at': '2024-01-01T00:00:00Z'}


class InstrumentMethodFeatureTest(unittest.TestCase):
    def test_future_tail_cannot_rewrite_any_past_feature(self):
        data = frame()
        prefix = data.iloc[:70].copy()
        changed = data.copy()
        changed.loc[70:, ['open', 'high', 'low', 'close']] *= 100
        left, right = ClosedInstrumentMethodFeatures(prefix), ClosedInstrumentMethodFeatures(changed)
        for index in range(70):
            self.assertEqual(values(left, prefix, index), values(right, changed, index))

    def test_pivot_not_visible_until_two_right_bars_close(self):
        data = frame(6)
        data.loc[:, ['open', 'close']] = 100
        data.loc[:, 'high'] = [101, 102, 105, 103, 102, 101]
        data.loc[:, 'low'] = [99, 98, 97, 98, 99, 99]
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertIsNone(owner.features[3]['_high_pivot'])
        self.assertEqual(owner.features[4]['_high_pivot'], (105.0, 4))
        self.assertEqual(owner.features[4]['_low_pivot'], (97.0, 4))

    def test_compression_then_expansion_is_disjoint_history_not_atr_level(self):
        data = frame(30)
        data.loc[:, ['open', 'close']] = 100
        data.loc[:, 'high'] = 101
        data.loc[:, 'low'] = 99
        data.loc[20:24, 'high'] = 100.1
        data.loc[20:24, 'low'] = 99.9
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(owner.features[24]['method_compression_expansion'])
        self.assertTrue(owner.features[25]['method_compression_expansion'])

    def test_directional_bos_choch_and_sweep_are_distinct_closed_price_proxies(self):
        data = frame(45)
        closes = [100] * 20 + [100, 101, 103, 101, 100, 99, 97, 99, 100, 104, 100, 96,
            100, 101, 100, 99, 98, 99, 100, 101, 100, 99, 100, 101, 100]
        for index, close in enumerate(closes):
            data.loc[index, ['open', 'close', 'high', 'low']] = [close, close, close + .1, close - .1]
        data.loc[30, 'low'] = 95.5
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertTrue(values(owner, data, 29)['method_bos'])
        self.assertTrue(values(owner, data, 30)['method_sweep_reclaim'])
        self.assertEqual(owner.features[30]['_low'], 95.5)
        self.assertEqual(owner.features[30]['_low_pivot'], (96.9, 28))
        self.assertTrue(values(owner, data, 31, 'SELL')['method_choch'])

    def test_session_breakout_and_fibonacci_use_only_confirmed_prior_levels(self):
        data = frame()
        data.loc[70, ['open', 'close', 'high', 'low']] = [110, 110, 110.3, 109.7]
        owner = ClosedInstrumentMethodFeatures(data)
        output = values(owner, data, 70)
        self.assertTrue(output['method_session_available'])
        self.assertGreater(output['method_session_distance_atr'], 0)
        self.assertTrue(output['method_swing_available'])
        high, low = owner.features[70]['_high_pivot'][0], owner.features[70]['_low_pivot'][0]
        self.assertAlmostEqual(output['method_fib_retracement'], (high - 110) / (high - low))

    def test_all_price_context_fields_have_typed_distinct_observations(self):
        data, seen = frame(), set()
        owner = ClosedInstrumentMethodFeatures(data)
        for index in range(len(data)):
            output = values(owner, data, index, 'BUY' if index < 48 else 'SELL')
            seen |= set(output)
            for key, value in output.items():
                self.assertIsInstance(value, bool if key in BOOL_FIELDS else float)
                if key in NUMBER_FIELDS:
                    self.assertTrue(math.isfinite(value))
        self.assertTrue({'method_atr_ratio', 'method_session_range_atr', 'method_fib_retracement',
            'method_sr_distance_atr', 'method_sr_touch_count', 'method_sr_age', 'method_equal_level_count',
            'method_volume_ratio', 'method_transition_age', 'method_signal_age', 'method_signal_survival',
            'method_regime_code', 'method_compression_expansion', 'method_bos', 'method_choch'} <= seen)
        self.assertNotEqual(owner.features[70]['method_atr_ratio'], owner.features[70]['method_range_atr'])

    def test_missing_volume_quote_and_model_are_not_invented(self):
        data = frame()
        data['volume_available'] = False
        data['method_meta_score'] = .99
        data['method_meta_model_available'] = True
        data['method_volume_ratio'] = 100
        data['method_quote_available'] = True
        owner = ClosedInstrumentMethodFeatures(data)
        output = values(owner, data, 70)
        for key in ('method_volume_available', 'method_quote_available', 'method_meta_model_available'):
            self.assertIs(output[key], False)
        for key in ('method_volume_ratio', 'method_spread_atr', 'method_meta_score'):
            self.assertNotIn(key, output)

    def test_no_session_context_no_session_claim_and_observed_quote_requires_flag(self):
        data = frame()
        data['market_session'] = 'unknown'
        data['bid_ask_spread'] = .2
        owner = ClosedInstrumentMethodFeatures(data)
        output = values(owner, data, 70)
        self.assertFalse(output['method_session_available'])
        self.assertNotIn('method_session_range_atr', output)
        self.assertFalse(output['method_quote_available'])
        data['spread_available'] = True
        data['bid_close'] = data['close']
        data['ask_close'] = data['close'] + .2
        data['quote_time_utc'] = data['time'] + pd.Timedelta(minutes=4)
        data['quote_available_after_utc'] = data['time'] + pd.Timedelta(minutes=5)
        output = values(ClosedInstrumentMethodFeatures(data), data, 70)
        self.assertTrue(output['method_quote_available'])
        self.assertGreater(output['method_spread_atr'], 0)
        data.loc[70, 'quote_time_utc'] = data.loc[70, 'time'] + pd.Timedelta(minutes=6)
        self.assertFalse(values(ClosedInstrumentMethodFeatures(data), data, 70)['method_quote_available'])

    def test_actual_closed_clock_ohlc_and_order_are_required(self):
        data = frame()
        owner = ClosedInstrumentMethodFeatures(data)
        with self.assertRaisesRegex(ValueError, 'ORIGINAL_CLOSED_ROW'):
            owner.inputs(data.iloc[20].to_dict(), direction='BUY', observed_at=data.iloc[20]['time'].isoformat())
        values(owner, data, 21)
        with self.assertRaisesRegex(ValueError, 'NON_MONOTONIC'):
            values(owner, data, 20)
        row = data.iloc[22].to_dict(); row['close'] += .1
        with self.assertRaisesRegex(ValueError, 'ORIGINAL_ROW_DRIFT'):
            owner.inputs(row, direction='BUY', observed_at=(row['time'] + pd.Timedelta(minutes=5)).isoformat())
        with self.assertRaisesRegex(ValueError, 'ORDERED_UNIQUE'):
            ClosedInstrumentMethodFeatures(data.iloc[::-1])

    def test_only_observable_mature_owned_closes_update_loss_inputs(self):
        data, owner = frame(), ClosedInstrumentMethodFeatures(frame())
        at = (data.iloc[30]['time'] + pd.Timedelta(minutes=5)).isoformat()
        owner.notify_mature_close(position_id='censored', net_pnl=-10, available_at=at, mature=False)
        owner.notify_mature_close(position_id='owned-1', net_pnl=-1, available_at=at)
        owner.notify_mature_close(position_id='owned-1', net_pnl=-1, available_at=at)
        before = values(owner, data, 29)
        self.assertTrue(before['method_loss_history_available'])
        self.assertEqual(before['method_loss_streak'], 0)
        after = values(owner, data, 30)
        self.assertTrue(after['method_loss_history_available'])
        self.assertEqual(after['method_loss_streak'], 1)
        self.assertEqual(after['method_bars_since_loss'], 0)
        self.assertFalse(after['method_dynamic_cooldown_ready'])
        self.assertEqual(values(owner, data, 31)['method_bars_since_loss'], 1)
        self.assertTrue(values(owner, data, 34)['method_dynamic_cooldown_ready'])
        with self.assertRaisesRegex(ValueError, 'ID_COLLISION'):
            owner.notify_mature_close(position_id='owned-1', net_pnl=1, available_at=at)

    def test_owner_marker_and_bool_number_types_are_checked(self):
        ast = {'op': 'GREATER_THAN', 'args': [node('atr'), const(0)]}
        with self.assertRaisesRegex(ValueError, 'METHOD_FEATURE_OWNER'):
            operator(ast, {'atr': 'method_atr_ratio'}, owner=False)
        with self.assertRaisesRegex(ValueError, 'METHOD_FEATURE_OWNER'):
            operator(ast, {'atr': 'method_atr_ratio'}, owner_capability=False)
        with self.assertRaisesRegex(ValueError, 'BINDING_TYPE'):
            operator(ast, {'atr': 'method_history_available'})

    def test_availability_boolean_short_circuits_unavailable_numeric_input(self):
        ast = {'op': 'AND', 'args': [node('available', True),
            {'op': 'GREATER_THAN', 'args': [node('score'), const(.6)]}]}
        program = operator(ast, {'available': 'method_meta_model_available', 'score': 'method_meta_score'})
        output = program.evaluate({'method_meta_model_available': False}, decision_at='2025-01-01T00:00:00Z',
            observed_at='2025-01-01T00:00:00Z')
        self.assertIs(output, False)
        self.assertEqual(program.visits, 2)

    def test_method_runtime_overwrites_injected_features_and_missing_input_is_wait(self):
        request, data = fixtures([member('hour')])
        data = strategy(data, {})
        ast = {'op': 'GREATER_THAN', 'args': [node('model'), const(.6)]}
        recipe = pinned_recipe(request, 'missing-model', {'confirmation': ast}, {'model': 'method_meta_score'})
        runtime = InstrumentMethodLearning(learning_contract(request, data, [recipe]), request)
        runtime.validate_frame(data)
        row = data.iloc[15].to_dict(); row['method_meta_score'] = .99
        stamp = (row['time'] + pd.Timedelta(hours=1)).isoformat()
        event = runtime.evaluate(row, decision_id='test', phase='entry', decision_at=stamp, observed_at=stamp, direction='BUY')
        self.assertEqual(event['reason'], 'method_input_unavailable')
        self.assertIs(event['outputs']['confirmation'], False)
        self.assertEqual(event['feature_protocol'], PROTOCOL)

    def test_actual_native_runtime_consumes_owned_closed_loss_state(self):
        request, data = fixtures([member('hour', holding=2)], execution=ExecutionConfig(
            stop_loss_percent=1, take_profit_percent=2, commission_percent=.1, max_leverage=5))
        request.emit_decision_trace = True
        ast = {'op': 'LESS_THAN', 'args': [node('losses'), const(1)]}
        recipe = pinned_recipe(request, 'owned-loss', {'confirmation': ast}, {'losses': 'method_loss_streak'})
        contract = learning_contract(request, data, [recipe])
        original = copy.deepcopy(request.specialist_council_contract)
        declaration = copy.deepcopy(original['members'][0]); declaration['method_learning'] = contract
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            baseline = run_simple_ema_rsi_backtest_on_dataframe(request, data)
            request.specialist_council_contract = seal_contract({**original, 'members': [declaration]})
            variant = run_simple_ema_rsi_backtest_on_dataframe(request, data)
        self.assertLess(variant.total_trades, baseline.total_trades)
        receipt = variant.specialist_council_receipt['members'][0]['method_learning_receipt']
        self.assertGreater(receipt['behavior_changed_count'], 0)
        self.assertTrue(any(event.get('feature_protocol') == PROTOCOL for event in receipt['samples']))
        self.assertFalse(receipt['economic_authority'])

    def test_actual_ordinary_runtime_publishes_owner_computed_missing_model_wait(self):
        request = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='H1',
            replay_dataset_hash='fixture-data', parameters={'time_stop_candles': 3},
            execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2))
        request.emit_decision_trace = True
        data = pd.DataFrame({'time': pd.date_range('2025-01-06', periods=224, freq='h', tz='UTC'),
            'open': 100.0, 'high': 100.05, 'low': 99.95, 'close': 100.0,
            'volume': 100.0, 'volume_available': True, 'method_meta_model_available': True})
        ast = node('available', True)
        recipe = pinned_recipe(request, 'untrained-model', {'confirmation': ast},
            {'available': 'method_meta_model_available'})
        contract = learning_contract(request, data, [recipe])
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            baseline = run_simple_ema_rsi_backtest_on_dataframe(request, data)
            request.policy_context['instrument_method_learning'] = contract
            variant = run_simple_ema_rsi_backtest_on_dataframe(request, data)
        self.assertGreater(baseline.total_trades, 0)
        self.assertEqual(variant.total_trades, 0)
        receipt = variant.data_quality['instrument_method_learning_receipt']
        self.assertGreater(receipt['reason_counts']['method_model_unavailable'], 0)
        self.assertTrue(all(event['feature_availability']['method_meta_model_available'] is False
            for event in receipt['samples']))
        self.assertEqual(baseline.data_quality['replay_executed_clock']['schedule_hash'],
            variant.data_quality['replay_executed_clock']['schedule_hash'])


if __name__ == '__main__':
    unittest.main()
