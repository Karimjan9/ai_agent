"""Formula/owned-exit software checks, not market or independent skill evidence."""

import unittest

import pandas as pd

from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.parameter_schema import PARAMETER_SCHEMAS, validate_strategy_parameters
from app.strategies.laboratory import apply_session_mean_reversion_strategy
from test_bollinger_context_gate import PARAMETERS, closed_frame, original_recross


def population_recross(frame, parameters):
    active = pd.to_datetime(frame.time, utc=True).dt.hour.between(
        parameters['session_start'], parameters['session_end'] - 1)
    mean = frame.close.rolling(parameters['lookback']).mean()
    deviation = frame.close.rolling(parameters['lookback']).std(ddof=0).replace(0, float('nan'))
    lower, upper = mean - 2 * deviation, mean + 2 * deviation
    result = frame.copy()
    result['signal'] = 'WAIT'
    result.loc[active & (frame.close.shift(1) < lower.shift(1)) & (frame.close >= lower)
               & (frame.close > frame.close.shift(1)), 'signal'] = 'BUY'
    result.loc[active & (frame.close.shift(1) > upper.shift(1)) & (frame.close <= upper)
               & (frame.close < frame.close.shift(1)), 'signal'] = 'SELL'
    result['signal_confidence'] = ((frame.close - mean).abs() / (2 * deviation)).clip(0, 1).fillna(0)
    return result


def features(frame, index, direction='BUY'):
    row = frame.iloc[index].to_dict()
    return ClosedInstrumentMethodFeatures(frame).inputs(row, direction=direction,
        observed_at=(row['time'] + pd.Timedelta(minutes=5)).isoformat())


class BollingerCompleteFormulaTest(unittest.TestCase):
    def test_absent_and_sample_preserve_legacy_frame_and_float_bytes(self):
        source = closed_frame()
        for frame in (source, closed_frame([100.] * 60), source.assign(close=float('nan'))):
            expected = original_recross(frame, PARAMETERS)
            for treatment in ({}, {'session_band_variance': 'sample'}):
                actual = apply_session_mean_reversion_strategy(frame, {**PARAMETERS, **treatment})
                pd.testing.assert_frame_equal(actual, expected, check_exact=True)
                self.assertEqual(actual.signal_confidence.to_numpy().tobytes(),
                                 expected.signal_confidence.to_numpy().tobytes())

    def test_population_uses_same_population_deviation_for_bands_and_confidence(self):
        source = closed_frame()
        actual = apply_session_mean_reversion_strategy(source,
            {**PARAMETERS, 'session_band_variance': 'population'})
        pd.testing.assert_frame_equal(actual, population_recross(source, PARAMETERS), check_exact=True)
        self.assertFalse(actual.signal_confidence.equals(original_recross(source, PARAMETERS).signal_confidence))

    def test_population_is_prefix_invariant_and_does_not_mutate_original(self):
        frame = closed_frame()
        before = frame.copy(deep=True)
        parameters = {**PARAMETERS, 'session_band_variance': 'population'}
        full = apply_session_mean_reversion_strategy(frame, parameters)
        for length in (19, 20, 38, 74, 120):
            pd.testing.assert_frame_equal(apply_session_mean_reversion_strategy(frame.iloc[:length], parameters),
                                          full.iloc[:length], check_exact=True)
        future = frame.copy()
        future.loc[74:, 'close'] *= 100
        pd.testing.assert_frame_equal(apply_session_mean_reversion_strategy(future, parameters).iloc[:74],
                                      full.iloc[:74], check_exact=True)
        pd.testing.assert_frame_equal(frame, before, check_exact=True)

    def test_variance_is_explicit_and_bound_to_actual_registered_runtime(self):
        self.assertNotIn('session_band_variance', PARAMETER_SCHEMAS['session'])
        self.assertNotIn('session_band_variance', validate_strategy_parameters('session_mean_reversion_v1', {}))
        for mode in ('sample', 'population'):
            values = {**PARAMETERS, 'session_band_variance': mode}
            for strategy, base in (('session_mean_reversion_v1', None), ('session_v1', 'session_mean_reversion')):
                self.assertEqual(validate_strategy_parameters(strategy, values, base), values)
            for strategy, base in (('session_v1', None), ('session_mean_reversion_v1', 'session'),
                                   ('trend_v1', 'session_mean_reversion')):
                with self.assertRaises(ValueError):
                    validate_strategy_parameters(strategy, {'session_band_variance': mode}, base)
        for invalid in ('', 'sample ', 'POPULATION', 0, True, None, [], {}):
            with self.assertRaises(ValueError):
                validate_strategy_parameters('session_mean_reversion_v1', {'session_band_variance': invalid})
            with self.assertRaises(ValueError):
                apply_session_mean_reversion_strategy(closed_frame(), {'session_band_variance': invalid})


class ClosedMidline20Test(unittest.TestCase):
    def test_twenty_prices_and_valid_actual_direction_are_required(self):
        frame = closed_frame(list(range(100, 125)))
        for direction in ('BUY', 'SELL'):
            self.assertFalse(features(frame, 18, direction)['method_midline20_available'])
            self.assertFalse(features(frame, 18, direction)['method_midline20_exit'])
            self.assertTrue(features(frame, 19, direction)['method_midline20_available'])
        self.assertTrue(features(frame, 19, 'BUY')['method_midline20_exit'])
        self.assertFalse(features(frame, 19, 'SELL')['method_midline20_exit'])
        for direction in ('WAIT', 'buy', '', None):
            current = features(frame, 19, direction)
            self.assertFalse(current['method_midline20_available'])
            self.assertFalse(current['method_midline20_exit'])

    def test_directional_comparisons_and_equal_midline_are_explicit(self):
        descending = closed_frame(list(range(125, 100, -1)))
        self.assertFalse(features(descending, 19, 'BUY')['method_midline20_exit'])
        self.assertTrue(features(descending, 19, 'SELL')['method_midline20_exit'])
        flat = closed_frame([100.] * 25)
        for direction in ('BUY', 'SELL'):
            current = features(flat, 19, direction)
            self.assertTrue(current['method_midline20_available'])
            self.assertTrue(current['method_midline20_exit'])
            self.assertFalse(current['method_band_available'])

    def test_only_original_closed_inputs_can_publish_midline(self):
        frame = closed_frame(list(range(100, 125)))
        row = frame.iloc[19].to_dict()
        owner = ClosedInstrumentMethodFeatures(frame)
        with self.assertRaisesRegex(ValueError, 'ORIGINAL_CLOSED_ROW_REQUIRED'):
            owner.inputs(row, direction='BUY', observed_at=row['time'].isoformat())
        row['method_midline20_available'] = row['method_midline20_exit'] = False
        self.assertTrue(owner.inputs(row, direction='BUY',
            observed_at=(row['time'] + pd.Timedelta(minutes=5)).isoformat())['method_midline20_exit'])
        row['close'] += .01
        with self.assertRaisesRegex(ValueError, 'ORIGINAL_ROW_DRIFT'):
            ClosedInstrumentMethodFeatures(frame).inputs(row, direction='BUY',
                observed_at=(row['time'] + pd.Timedelta(minutes=5)).isoformat())

    def test_prefix_and_future_prices_do_not_rewrite_midline(self):
        frame = closed_frame()
        for length in (19, 20, 38, 74):
            for direction in ('BUY', 'SELL'):
                full = features(frame, length - 1, direction)
                prefix = features(frame.iloc[:length].copy(), length - 1, direction)
                self.assertEqual(full['method_midline20_available'], prefix['method_midline20_available'])
                self.assertEqual(full['method_midline20_exit'], prefix['method_midline20_exit'])
        future = frame.copy()
        future.loc[74:, ['open', 'high', 'low', 'close']] *= 100
        for direction in ('BUY', 'SELL'):
            self.assertEqual(features(frame, 73, direction)['method_midline20_exit'],
                             features(future, 73, direction)['method_midline20_exit'])


class OwnedMidlineExitRuntimeTest(unittest.TestCase):
    """Existing ordinary synthetic replay; no archive/DB/production dispatch."""

    @classmethod
    def execute(cls, frame, *, midline=True, time_stop=6):
        from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
        from test_instrument_method_learning import learning_contract
        from test_source_grounded_method_runtime import feature_recipe, software_request
        request = software_request()
        request.parameters = {**request.parameters, 'time_stop_candles': time_stop}
        if midline:
            recipe = feature_recipe(request, 'midline20', 'exit', 'method_midline20_exit')
            request.policy_context['instrument_method_learning'] = learning_contract(request, frame, [recipe])
        return request, run_simple_ema_rsi_backtest_on_dataframe(request, frame,
            include_differential_pair=False, lightweight=True)

    @classmethod
    def setUpClass(cls):
        from test_source_grounded_method_runtime import software_frame
        cls.frame = software_frame()
        cls.baseline = cls.execute(cls.frame, midline=False)
        cls.midline = cls.execute(cls.frame)

    def test_midline_uses_held_direction_previous_close_and_next_open_not_entry_bar(self):
        request, result = self.midline
        self.assertGreater(len(result.trade_ledger), 0)
        baseline = self.baseline[1].trade_ledger[0]
        first = result.trade_ledger[0]
        for field in ('entry_time', 'entry_price', 'stop_loss', 'take_profit', 'position_size_multiple', 'risk_budget_percent'):
            self.assertEqual(getattr(first, field), getattr(baseline, field))
        events = [event for event in result.decision_trace if event.get('event_type') == 'instrument_method_decision']
        entry_index = int(self.frame.index[self.frame.time == pd.Timestamp(first.entry_time)][0])
        self.assertFalse(any(event['candle_index'] == entry_index and event['method_learning']['phase'] == 'exit'
                             for event in events))
        trade = next(trade for trade in result.trade_ledger if trade.exit_reason == 'method_learning_exit')
        event = next(event for event in events if event['method_learning']['phase'] == 'exit'
            and event['method_learning']['outputs'].get('exit') is True
            and pd.Timestamp(event['method_learning']['decision_at']) == pd.Timestamp(trade.exit_time))
        closed = self.frame.iloc[event['candle_index'] - 1].to_dict()
        observed = event['method_learning']['observed_at']
        self.assertEqual(pd.Timestamp(observed), closed['time'] + pd.Timedelta(minutes=5))
        self.assertEqual(event['method_learning']['context']['direction'], trade.direction.lower())
        self.assertTrue(ClosedInstrumentMethodFeatures(self.frame).inputs(closed,
            direction=trade.direction, observed_at=observed)['method_midline20_exit'])
        price = self.frame.iloc[event['candle_index']]['open']
        cost = (request.execution.spread_points / 2 + request.execution.slippage_points) * request.execution.point_size
        self.assertEqual(trade.exit_price, round(price - cost if trade.direction == 'BUY' else price + cost, 2))

    def test_existing_gap_stop_still_owns_price_when_midline_also_requests_close(self):
        trade = next(trade for trade in self.midline[1].trade_ledger if trade.exit_reason == 'method_learning_exit')
        index = int(self.frame.index[self.frame.time == pd.Timestamp(trade.exit_time)][0])
        data = self.frame.copy()
        gap = trade.stop_loss - 1 if trade.direction == 'BUY' else trade.stop_loss + 1
        data.loc[index, ['open', 'high', 'low', 'close']] = [gap, gap + .02, gap - .02, gap]
        request, result = self.execute(data)
        changed = next(item for item in result.trade_ledger if item.entry_time == trade.entry_time)
        self.assertEqual(changed.exit_time, trade.exit_time)
        self.assertEqual(changed.exit_reason, 'gap_stop')
        cost = (request.execution.spread_points / 2 + request.execution.slippage_points) * request.execution.point_size
        self.assertEqual(changed.exit_price, round(gap - cost if trade.direction == 'BUY' else gap + cost, 2))
        event = next(event for event in result.decision_trace
            if event.get('event_type') == 'instrument_method_decision' and event['candle_index'] == index
            and event['method_learning']['phase'] == 'exit')
        self.assertTrue(event['method_learning']['outputs']['exit'])

    def test_existing_time_stop_precedes_method_evaluation_and_six_row_cap_is_unchanged(self):
        _, result = self.execute(self.frame, time_stop=1)
        self.assertGreater(len(result.trade_ledger), 0)
        self.assertTrue(all(trade.exit_reason == 'time_stop' for trade in result.trade_ledger))
        self.assertFalse(any(event.get('event_type') == 'instrument_method_decision'
            and event['method_learning']['phase'] == 'exit' for event in result.decision_trace))
        for trade in self.midline[1].trade_ledger:
            entry = int(self.frame.index[self.frame.time == pd.Timestamp(trade.entry_time)][0])
            exit_index = int(self.frame.index[self.frame.time == pd.Timestamp(trade.exit_time)][0])
            self.assertLessEqual(exit_index - entry, 6)


if __name__ == '__main__':
    unittest.main()
