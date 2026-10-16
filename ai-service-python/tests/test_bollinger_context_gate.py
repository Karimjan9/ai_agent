"""Opt-in closed-row session recross context; synthetic software checks only.

These tests call the registered strategy and canonical parameter validator.
They do not replay archived candles, evaluate cash outcomes, or qualify a
trading method. The gate must only remove existing proposals, never replace
the original sample-standard-deviation recross or its confidence arithmetic.
"""

import math
import unittest

import numpy as np
import pandas as pd

from app.services.parameter_schema import (
    EXECUTION_PARAMETER_SCHEMA,
    PARAMETER_SCHEMAS,
    validate_strategy_parameters,
)
from app.strategies.laboratory import apply_session_mean_reversion_strategy
from app.strategies.registry import get_strategy


PARAMETERS = {'session_start': 7, 'session_end': 14, 'lookback': 20}
ENABLED = {**PARAMETERS, 'session_context_gate': 'range_low_v1'}
REENTRY_ROWS = ((37, 'BUY'), (73, 'SELL'))


def closed_frame(closes=None, *, end=None):
    if closes is None:
        closes = [100.0 + .15 * math.sin(index / 2.0) for index in range(120)]
        closes[36], closes[37], closes[72], closes[73] = 97.0, 100.0, 103.0, 100.0
    values = np.asarray(closes, dtype=float)
    clock = (pd.date_range('2025-01-06T06:00:00Z', periods=len(values), freq='5min')
             if end is None else pd.date_range(end=pd.Timestamp(end), periods=len(values), freq='5min'))
    return pd.DataFrame({'time': clock, 'open': values, 'high': values + .05,
                         'low': values - .05, 'close': values,
                         'market_regime': 'range', 'volatility_regime': 'low_volatility'})


def original_recross(frame, parameters=None):
    """Frozen pre-gate formula, including ddof=1, session and WAIT confidence."""
    p = parameters or {}
    start = int(p.get('session_start', 7))
    end = int(p.get('session_end', 16))
    lookback = int(p.get('lookback', 20))
    active = pd.to_datetime(frame.time, utc=True, errors='coerce').dt.hour.between(start, end - 1)
    mean = frame.close.rolling(lookback).mean()
    std = frame.close.rolling(lookback).std().replace(0, float('nan'))
    lower, upper = mean - 2.0 * std, mean + 2.0 * std
    buy = (active & (frame.close.shift(1) < lower.shift(1))
           & (frame.close >= lower) & (frame.close > frame.close.shift(1)))
    sell = (active & (frame.close.shift(1) > upper.shift(1))
            & (frame.close <= upper) & (frame.close < frame.close.shift(1)))
    out = frame.copy()
    out['signal'] = 'WAIT'
    out.loc[buy, 'signal'] = 'BUY'
    out.loc[sell, 'signal'] = 'SELL'
    out['signal_confidence'] = ((frame.close - mean).abs() / (std * 2.0)).clip(0, 1).fillna(0)
    return out


class BollingerContextGateTest(unittest.TestCase):
    def strategy(self, frame, parameters=None):
        function = get_strategy('session_mean_reversion_v1', 'session_mean_reversion')
        self.assertIs(function, apply_session_mean_reversion_strategy)
        return function(frame, parameters)

    def assert_exact_frame(self, actual, expected):
        pd.testing.assert_frame_equal(actual, expected, check_exact=True)
        # Preserve the physical numeric vector too, not merely approximate values.
        self.assertEqual(actual.signal_confidence.to_numpy().tobytes(),
                         expected.signal_confidence.to_numpy().tobytes())

    def test_omitted_and_none_preserve_every_legacy_value_dtype_and_input_column(self):
        source = closed_frame()
        without_context = source.drop(columns=['market_regime', 'volatility_regime'])
        foreign_context = source.assign(market_regime='trend_up', volatility_regime='high_volatility')
        flat = closed_frame([100.0] * 60, end='2025-01-06T11:00:00Z')
        missing_prices = source.copy(deep=True)
        missing_prices.loc[[12, 100], 'close'] = float('nan')
        all_missing = source.assign(close=float('nan'))
        for name, frame in (('original', source), ('no_context', without_context),
                            ('foreign_context', foreign_context), ('flat', flat),
                            ('some_nan', missing_prices), ('all_nan', all_missing)):
            before = frame.copy(deep=True)
            for parameters in (None, {}, PARAMETERS, {**PARAMETERS, 'session_context_gate': 'none'}):
                with self.subTest(frame=name, parameters=parameters):
                    self.assert_exact_frame(self.strategy(frame, parameters), original_recross(frame, parameters))
                    pd.testing.assert_frame_equal(frame, before, check_exact=True)

    def test_enabled_matching_closed_context_preserves_real_buy_sell_and_confidence(self):
        source = closed_frame()
        original = original_recross(source, PARAMETERS)
        actual = self.strategy(source, ENABLED)
        for index, direction in REENTRY_ROWS:
            self.assertEqual(original.loc[index, 'signal'], direction)
            self.assertGreater(original.loc[index, 'signal_confidence'], 0.0)
        self.assert_exact_frame(actual, original)

    def test_enabled_gate_rejects_unknown_nonmatching_and_noncanonical_contexts(self):
        for field, values in (
            ('market_regime', ['trend_up', 'trend_down', 'transition', 'unknown', '',
                               'Range', 'range ', None, pd.NA, float('nan'), 0, True]),
            ('volatility_regime', ['normal_volatility', 'high_volatility', 'unknown', '',
                                  'low', 'compression', 'LOW_VOLATILITY',
                                  'low_volatility ', None, pd.NA, float('nan'), 0, True]),
        ):
            for value in values:
                with self.subTest(field=field, value=value):
                    source = closed_frame()
                    source[field] = value
                    actual = self.strategy(source, ENABLED)
                    self.assertTrue(actual.signal.eq('WAIT').all())
                    self.assertTrue(actual.signal_confidence.eq(0.0).all())

    def test_missing_context_columns_wait_without_inventing_alias_or_indicator_context(self):
        for missing in (['market_regime'], ['volatility_regime'],
                        ['market_regime', 'volatility_regime']):
            with self.subTest(missing=missing):
                source = closed_frame().drop(columns=missing)
                source['regime'], source['volatility'] = 'range', 'low'
                source['adx'], source['method_adx14'] = 0.0, 0.0
                actual = self.strategy(source, ENABLED)
                self.assertTrue(actual.signal.eq('WAIT').all())
                self.assertTrue(actual.signal_confidence.eq(0.0).all())

    def test_gate_uses_current_closed_row_not_previous_or_future_context(self):
        source = closed_frame().assign(market_regime='unknown', volatility_regime='unknown')
        for index, _ in REENTRY_ROWS:
            source.loc[index, ['market_regime', 'volatility_regime']] = ['range', 'low_volatility']
        actual = self.strategy(source, ENABLED)
        original = original_recross(source, PARAMETERS)
        admitted = source.market_regime.eq('range') & source.volatility_regime.eq('low_volatility')
        for index, direction in REENTRY_ROWS:
            self.assertEqual(actual.loc[index, 'signal'], direction)
        self.assertTrue(actual.loc[~admitted, 'signal'].eq('WAIT').all())
        self.assertTrue(actual.loc[~admitted, 'signal_confidence'].eq(0.0).all())
        pd.testing.assert_frame_equal(actual.loc[admitted], original.loc[admitted], check_exact=True)
        # Matching adjacent bars do not lend their context to the proposal row.
        for index, _ in REENTRY_ROWS:
            source.loc[[index - 1, index + 1], ['market_regime', 'volatility_regime']] = ['range', 'low_volatility']
            source.loc[index, ['market_regime', 'volatility_regime']] = ['unknown', 'unknown']
        denied = self.strategy(source, ENABLED)
        for index, _ in REENTRY_ROWS:
            self.assertEqual(denied.loc[index, 'signal'], 'WAIT')
            self.assertEqual(denied.loc[index, 'signal_confidence'], 0.0)

    def test_eligible_context_never_relaxes_recross_price_direction_or_session(self):
        for tail in ([90., 89., 88., 87., 86., 85.], [110., 111., 112., 113., 114., 115.]):
            source = closed_frame([100.0 + .001 * math.sin(index) for index in range(40)] + tail,
                                  end='2025-01-06T11:00:00Z')
            actual = self.strategy(source, ENABLED)
            self.assertEqual(actual.signal.iloc[-1], 'WAIT')
            self.assert_exact_frame(actual, original_recross(source, PARAMETERS))
        for stamp, expected in (('2025-01-06T06:59:00Z', 'WAIT'),
                                ('2025-01-06T07:00:00Z', 'BUY'),
                                ('2025-01-06T13:59:00Z', 'BUY'),
                                ('2025-01-06T14:00:00Z', 'WAIT')):
            source = closed_frame([100.0 + .1 * math.sin(index) for index in range(20)] + [97., 100.], end=stamp)
            with self.subTest(timestamp=stamp):
                actual = self.strategy(source, ENABLED)
                self.assertEqual(actual.signal.iloc[-1], expected)
                self.assert_exact_frame(actual, original_recross(source, PARAMETERS))

    def test_gate_is_prefix_invariant_and_future_price_or_context_cannot_rewrite_past(self):
        source = closed_frame()
        source.loc[::4, 'market_regime'] = 'trend_up'
        source.loc[::7, 'volatility_regime'] = 'unknown'
        before = source.copy(deep=True)
        complete = self.strategy(source, ENABLED)
        for length in (19, 20, 37, 38, 73, 74, len(source)):
            with self.subTest(prefix=length):
                self.assert_exact_frame(self.strategy(source.iloc[:length].copy(), ENABLED),
                                        complete.iloc[:length])
        future = source.copy(deep=True)
        future.loc[74:, ['open', 'high', 'low', 'close']] *= 1000.0
        future.loc[74:, 'market_regime'] = 'range'
        future.loc[74:, 'volatility_regime'] = 'low_volatility'
        self.assert_exact_frame(self.strategy(future, ENABLED).iloc[:74], complete.iloc[:74])
        pd.testing.assert_frame_equal(source, before, check_exact=True)

    def test_schema_preserves_explicit_vector_and_effective_registered_recross_precedence(self):
        declarations = (
            ('session_mean_reversion_v1', None),
            ('session_mean_reversion_v1', 'session_mean_reversion'),
            ('session_mean_reversion_v1', 'session_mean_reversion_v1'),
            ('session_v1', 'session_mean_reversion'),
            ('eurusd_session_mean_reversion_g12_a03', 'session_mean_reversion'),
        )
        for strategy, base in declarations:
            for gate in ('none', 'range_low_v1'):
                with self.subTest(strategy=strategy, base=base, gate=gate):
                    self.assertIs(get_strategy(strategy, base), apply_session_mean_reversion_strategy)
                    parameters = {**PARAMETERS, 'session_context_gate': gate}
                    self.assertEqual(validate_strategy_parameters(strategy, parameters, base), parameters)
        self.assertEqual(validate_strategy_parameters('session_mean_reversion_v1', PARAMETERS), PARAMETERS)
        self.assertEqual(validate_strategy_parameters('session_v1', PARAMETERS), PARAMETERS)
        self.assertNotIn('session_context_gate', validate_strategy_parameters('session_mean_reversion_v1', {}))
        self.assertNotIn('session_context_gate', PARAMETER_SCHEMAS['session'])
        self.assertNotIn('session_context_gate', EXECUTION_PARAMETER_SCHEMA)

    def test_schema_rejects_gate_for_wrong_family_or_effective_breakout_runtime(self):
        for strategy, base in (
            ('session_v1', None), ('session_v1', 'session'),
            ('session_mean_reversion_v1', 'session'),
            ('session_mean_reversion_v1', 'session_v1'),
            ('session_mean_reversion_v1', 'mean_reversion'),
            ('mean_reversion_v1', None), ('trend_v1', None),
            ('trend_v1', 'session_mean_reversion'),
            ('differential_router_v1', 'session_mean_reversion'),
            ('regime_ensemble_v1', 'session_mean_reversion'),
            ('eurusd_session_mean_reversion_g12_a03', None),
        ):
            for gate in ('none', 'range_low_v1'):
                with self.subTest(strategy=strategy, base=base, gate=gate):
                    with self.assertRaises(ValueError):
                        # No unrelated session genes may mask ownership failure.
                        validate_strategy_parameters(strategy, {'session_context_gate': gate}, base)

    def test_schema_rejects_non_enum_values_instead_of_silently_disabling_gate(self):
        for value in ('', 'range_low_v2', 'RANGE_LOW_V1', 'none ', None, True, 1, {}, []):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    validate_strategy_parameters('session_mean_reversion_v1',
                                                 {**PARAMETERS, 'session_context_gate': value})
                with self.assertRaises(ValueError):
                    self.strategy(closed_frame(), {**PARAMETERS, 'session_context_gate': value})


if __name__ == '__main__':
    unittest.main()
