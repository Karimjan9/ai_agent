"""Session methods consume the calendar already used by ordinary selection."""

import copy
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import prepare_signal_snapshot, run_simple_ema_rsi_backtest_on_dataframe
from app.services.instrument_method_learning import InstrumentMethodLearning, _context
from app.services.market_sessions import session_membership
from test_instrument_method_learning import const, learning_contract, pinned_recipe, strategy


def sessionless_strategy(frame, parameters):
    return strategy(frame, parameters).drop(columns=['market_session'])


def source_frame(count=96, start='2025-01-06T08:00:00Z'):
    return pd.DataFrame({'time': pd.date_range(start, periods=count, freq='5min'),
        'open': 100.0, 'high': 100.2, 'low': 99.8, 'close': 100.0,
        'volume': 100.0, 'volume_available': True})


def session_runtime(data):
    request = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi',
        timeframe='M5', replay_dataset_hash='fixture-session-data',
        parameters={'time_stop_candles': 1}, emit_decision_trace=True,
        execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2))
    ast = {'op': 'AND', 'args': [
        {'op': 'BOOL', 'input_key': 'available', 'available_at': '2024-01-01T00:00:00Z'},
        {'op': 'GREATER_THAN', 'args': [
            {'op': 'NUMBER', 'input_key': 'width', 'available_at': '2024-01-01T00:00:00Z'}, const(0)]}]}
    recipe = pinned_recipe(request, 'calendar-session-width', {'confirmation': ast},
        {'available': 'method_session_available', 'width': 'method_session_range_atr'})
    contract = learning_contract(request, data, [recipe])
    return request, contract, InstrumentMethodLearning(contract, request)


def closed_inputs(runtime, data, index):
    row = data.iloc[index].to_dict()
    observed = (row['time'] + pd.Timedelta(minutes=5)).isoformat()
    return runtime.features.inputs(row, direction='BUY', observed_at=observed)


class InstrumentMethodSessionRuntimeTest(unittest.TestCase):
    def test_actual_ordinary_snapshot_without_session_columns_consumes_calendar_features(self):
        data = source_frame(256, '2025-01-05T17:00:00Z')
        request, contract, runtime = session_runtime(data)
        original = data.copy(deep=True)
        with patch('app.services.backtester.get_strategy', return_value=sessionless_strategy):
            snapshot = prepare_signal_snapshot(request, data)
            self.assertFalse({'market_session', 'session'}.intersection(snapshot.frame.columns))
            runtime.validate_frame(snapshot.frame)
            calendar = session_membership(snapshot.frame['time'])
            for index in (0, 200, 210):
                row = runtime.features.rows[index]
                self.assertEqual(row['market_session'], calendar.iloc[index]['session'])
                self.assertEqual(row['market_session_instance_id'], calendar.iloc[index]['session_instance_id'])
                self.assertEqual(row['market_session_calendar_version'], calendar.iloc[index]['calendar_version'])
                context = _context(snapshot.frame.iloc[index].to_dict(), request.symbol, request.timeframe, 'BUY')
                self.assertEqual(row['market_session'], context['session'])
            self.assertTrue(closed_inputs(runtime, snapshot.frame, 200)['method_session_available'])
            request.policy_context['instrument_method_learning'] = contract
            result = run_simple_ema_rsi_backtest_on_dataframe(request, data)
        receipt = result.data_quality['instrument_method_learning_receipt']
        available = [event['method_learning'] for event in result.decision_trace
            if event.get('event_type') == 'instrument_method_decision'
            and event['method_learning']['phase'] == 'entry'
            and event['method_learning']['feature_availability']['method_session_available']]
        self.assertGreater(receipt['used_count'], 0)
        self.assertTrue(any(event['outputs'].get('confirmation') is True for event in available))
        self.assertFalse(receipt['economic_authority'])
        pd.testing.assert_frame_equal(data, original)

    def test_explicit_unknown_sessions_are_not_filled_from_the_calendar(self):
        for column, value in (('market_session', 'unknown'), ('session', 'unknown'), ('market_session', None)):
            with self.subTest(column=column, value=value):
                data = source_frame()
                data[column] = value
                _, _, runtime = session_runtime(data)
                runtime.validate_frame(data)
                self.assertTrue(all(not item['method_session_available'] for item in runtime.features.features))
                inputs = closed_inputs(runtime, data, 70)
                self.assertNotIn('method_session_range_atr', inputs)
                self.assertNotIn('market_session_instance_id', runtime.features.rows[70])

    def test_calendar_instance_change_resets_the_closed_range_even_with_same_session_label(self):
        data = source_frame()
        _, _, runtime = session_runtime(data)
        runtime.validate_frame(data)
        self.assertFalse(closed_inputs(runtime, data, 18)['method_session_available'])
        self.assertTrue(closed_inputs(runtime, data, 19)['method_session_available'])
        self.assertTrue(closed_inputs(runtime, data, 29)['method_session_available'])
        first, changed = runtime.features.rows[29], runtime.features.rows[30]
        self.assertEqual(first['market_session'], changed['market_session'])
        self.assertNotEqual(first['market_session_instance_id'], changed['market_session_instance_id'])
        self.assertFalse(closed_inputs(runtime, data, 30)['method_session_available'])
        self.assertFalse(closed_inputs(runtime, data, 31)['method_session_available'])

    def test_future_session_extrema_cannot_rewrite_the_closed_prefix(self):
        data = source_frame()
        prefix = data.iloc[:70].copy()
        future = copy.deepcopy(data)
        future.loc[70:, ['open', 'high', 'low', 'close']] *= 100
        request, contract, left = session_runtime(data)
        right = InstrumentMethodLearning(contract, request)
        left.validate_frame(prefix)
        right.validate_frame(future)
        for index in range(len(prefix)):
            self.assertEqual(closed_inputs(left, prefix, index), closed_inputs(right, future, index))
            self.assertEqual(left.features.rows[index]['market_session_instance_id'],
                right.features.rows[index]['market_session_instance_id'])


if __name__ == '__main__':
    unittest.main()
