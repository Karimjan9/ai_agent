"""Actual intraday strategy arithmetic on synthetic closed-price frames only.

These tests execute the real registered functions without replacing signals.
They do not run an archive replay, simulate broker fills, establish economic
skill, or turn a holding timer into a day-end flattening rule.
"""
import math
import unittest

import numpy as np
import pandas as pd

from app.strategies.laboratory import (
    apply_mean_reversion_strategy,
    apply_session_mean_reversion_strategy,
    apply_session_strategy,
)
from app.strategies.registry import STRATEGIES, STRATEGY_BASES, get_strategy


PARAMETERS = {'session_start': 7, 'session_end': 14, 'lookback': 20}
DECLARATIONS = (
    ('session_v1', 'session', apply_session_strategy),
    ('session_mean_reversion_v1', 'session_mean_reversion', apply_session_mean_reversion_strategy),
)


def frame_from_closes(closes, *, end=None):
    values = np.asarray(closes, dtype=float)
    clock = (pd.date_range('2025-01-06T06:00:00Z', periods=len(values), freq='min')
             if end is None else pd.date_range(end=pd.Timestamp(end), periods=len(values), freq='min'))
    return pd.DataFrame({'time': clock, 'open': values, 'high': values + .05,
                         'low': values - .05, 'close': values,
                         'volume': 0.0, 'volume_available': False,
                         'adx': 0.0, 'volatility_regime': 'low_volatility'})


def exercise_frame(kind):
    closes = [100 + .15 * math.sin(index / 2) for index in range(480)]
    if kind == 'breakout':
        closes[240], closes[280] = 102.0, 98.0
    elif kind == 'reentry':
        closes[240], closes[241], closes[280], closes[281] = 97.0, 100.0, 103.0, 100.0
    else:
        raise ValueError('Only explicit synthetic exercises are supported.')
    return frame_from_closes(closes)


class CuratorIntradayStrategiesTest(unittest.TestCase):
    def test_flat_history_is_unavailable_not_object_comparison_failure(self):
        source = frame_from_closes([100.] * 240, end='2025-01-06T11:00:00Z')
        result = apply_session_mean_reversion_strategy(source, PARAMETERS)
        self.assertTrue(result.signal.eq('WAIT').all())
        self.assertTrue(result.signal_confidence.eq(0.).all())
        self.assertTrue(pd.api.types.is_numeric_dtype(result.signal_confidence))

    def test_registry_pins_distinct_explicit_strategy_and_base_pairs(self):
        for name, base, expected in DECLARATIONS:
            with self.subTest(name=name, base=base):
                self.assertIs(STRATEGIES[name], expected)
                self.assertIs(STRATEGY_BASES[base], expected)
                self.assertIs(get_strategy(name), expected)
                self.assertIs(get_strategy(name, base), expected)
        self.assertIsNot(get_strategy('session_v1', 'session'),
                         get_strategy('session_mean_reversion_v1', 'session_mean_reversion'))
        # Existing registry semantics prefer a caller's base. Pinning the
        # separate mean-reversion base is therefore necessary, not cosmetic.
        self.assertIs(get_strategy('session_mean_reversion_v1', 'session'), apply_session_strategy)

    def test_real_breakout_uses_current_close_strictly_beyond_prior_twenty_high_low(self):
        source = exercise_frame('breakout')
        result = apply_session_strategy(source, PARAMETERS)
        self.assertEqual(result.loc[240, 'signal'], 'BUY')
        self.assertEqual(result.loc[280, 'signal'], 'SELL')
        self.assertGreater(source.loc[240, 'close'], source.loc[220:239, 'high'].max())
        self.assertLess(source.loc[280, 'close'], source.loc[260:279, 'low'].min())
        # Current-bar extrema cannot become their own prior breakout level.
        changed = source.copy(deep=True)
        changed.loc[240, 'high'] = 10000.0
        changed.loc[280, 'low'] = .01
        same_bar = apply_session_strategy(changed, PARAMETERS)
        self.assertEqual(same_bar.loc[240, 'signal'], 'BUY')
        self.assertEqual(same_bar.loc[280, 'signal'], 'SELL')
        self.assertTrue(result.iloc[:20]['signal'].eq('WAIT').all())

    def test_breakout_prior_level_touch_is_not_a_strict_break(self):
        for close in (100.05, 99.95):
            source = frame_from_closes([100.0] * 21, end='2025-01-06T10:00:00Z')
            source.loc[20, ['open', 'high', 'low', 'close']] = [close, close + .05, close - .05, close]
            self.assertEqual(apply_session_strategy(source, PARAMETERS).loc[20, 'signal'], 'WAIT')

    def test_real_reentry_uses_sample_std_bands_and_directional_closed_prices(self):
        source = exercise_frame('reentry')
        result = apply_session_mean_reversion_strategy(source, PARAMETERS)
        lower = source.close.rolling(20).mean() - 2 * source.close.rolling(20).std(ddof=1)
        upper = source.close.rolling(20).mean() + 2 * source.close.rolling(20).std(ddof=1)
        self.assertEqual(result.loc[240, 'signal'], 'WAIT')
        self.assertEqual(result.loc[241, 'signal'], 'BUY')
        self.assertLess(source.close.iloc[240], lower.iloc[240])
        self.assertGreaterEqual(source.close.iloc[241], lower.iloc[241])
        self.assertGreater(source.close.iloc[241], source.close.iloc[240])
        self.assertEqual(result.loc[280, 'signal'], 'WAIT')
        self.assertEqual(result.loc[281, 'signal'], 'SELL')
        self.assertGreater(source.close.iloc[280], upper.iloc[280])
        self.assertLessEqual(source.close.iloc[281], upper.iloc[281])
        self.assertLess(source.close.iloc[281], source.close.iloc[280])
        for index in (241, 281):
            expected = min(1.0, abs(source.close.iloc[index] - source.close.iloc[index-19:index+1].mean())
                           / (2 * source.close.iloc[index-19:index+1].std(ddof=1)))
            self.assertTrue(math.isclose(float(result.signal_confidence.iloc[index]), expected,
                                         rel_tol=1e-12, abs_tol=1e-12))

    def test_band_wick_touch_without_close_excursion_is_not_reentry(self):
        source = frame_from_closes([99.8 if index % 2 else 100.2 for index in range(240)],
                                    end='2025-01-06T11:00:00Z')
        source.loc[220:, 'high'], source.loc[220:, 'low'] = 150.0, 50.0
        result = apply_session_mean_reversion_strategy(source, PARAMETERS)
        self.assertTrue(result.signal.eq('WAIT').all())

    def test_moving_band_pseudo_reentry_in_wrong_price_direction_remains_wait(self):
        for tail, side in [([90., 89., 88., 87., 86., 85.], 'BUY'),
                           ([110., 111., 112., 113., 114., 115.], 'SELL')]:
            source = frame_from_closes([100.0 + .001 * math.sin(index) for index in range(220)] + tail,
                                        end='2025-01-06T11:00:00Z')
            mean, std = source.close.rolling(20).mean(), source.close.rolling(20).std(ddof=1)
            lower, upper = mean - 2 * std, mean + 2 * std
            if side == 'BUY':
                self.assertLess(source.close.iloc[-2], lower.iloc[-2])
                self.assertGreaterEqual(source.close.iloc[-1], lower.iloc[-1])
                self.assertLess(source.close.iloc[-1], source.close.iloc[-2])
            else:
                self.assertGreater(source.close.iloc[-2], upper.iloc[-2])
                self.assertLessEqual(source.close.iloc[-1], upper.iloc[-1])
                self.assertGreater(source.close.iloc[-1], source.close.iloc[-2])
            with self.subTest(side=side):
                self.assertEqual(apply_session_mean_reversion_strategy(source, PARAMETERS).signal.iloc[-1], 'WAIT')

    def test_seven_to_fourteen_utc_row_timestamp_session_is_half_open(self):
        for kind, function in [('breakout', apply_session_strategy), ('reentry', apply_session_mean_reversion_strategy)]:
            for stamp, expected in [('2025-01-06T06:59:00Z', 'WAIT'),
                                    ('2025-01-06T07:00:00Z', 'BUY'),
                                    ('2025-01-06T13:59:00Z', 'BUY'),
                                    ('2025-01-06T14:00:00Z', 'WAIT'),
                                    ('2025-01-06T09:00:00+02:00', 'BUY')]:
                closes = ([100.0] * 21 + [102.0] if kind == 'breakout'
                          else [100 + .1 * math.sin(index) for index in range(20)] + [97.0, 100.0])
                source = frame_from_closes(closes, end=stamp)
                result = function(source, PARAMETERS)
                with self.subTest(kind=kind, timestamp=stamp):
                    self.assertEqual(result.signal.iloc[-1], expected)
                    pd.testing.assert_series_equal(result.time, source.time)

    def test_confidence_is_scalar_finite_and_bounded_for_both_registered_methods(self):
        for kind, function in [('breakout', apply_session_strategy), ('reentry', apply_session_mean_reversion_strategy)]:
            result = function(exercise_frame(kind), PARAMETERS)
            self.assertTrue({'BUY', 'SELL'} <= set(result.signal))
            for value in result.signal_confidence:
                with self.subTest(kind=kind):
                    self.assertTrue(np.isscalar(value))
                    self.assertTrue(math.isfinite(float(value)))
                    self.assertGreaterEqual(float(value), 0)
                    self.assertLessEqual(float(value), 1)

    def test_two_hundred_plus_row_prefixes_and_appended_future_outliers_do_not_change_past(self):
        for kind, function in [('breakout', apply_session_strategy), ('reentry', apply_session_mean_reversion_strategy)]:
            source = exercise_frame(kind)
            original = source.copy(deep=True)
            complete = function(source, PARAMETERS)
            for length in (200, 240, 250, 300, 400, 480):
                with self.subTest(kind=kind, prefix=length):
                    pd.testing.assert_frame_equal(function(source.iloc[:length].copy(), PARAMETERS), complete.iloc[:length])
            future = frame_from_closes([1000000.0 if index % 2 else 1.0 for index in range(64)])
            future['time'] = pd.date_range(source.time.iloc[-1] + pd.Timedelta(minutes=1), periods=len(future), freq='min')
            future['high'], future['low'] = future.close * 1.01, future.close * .99
            extended = function(pd.concat([source, future], ignore_index=True), PARAMETERS)
            pd.testing.assert_frame_equal(extended.iloc[:len(source)], complete)
            pd.testing.assert_frame_equal(source, original)

    def test_legacy_default_mean_reversion_is_outside_fade_not_session_reentry(self):
        source = exercise_frame('reentry')
        legacy = apply_mean_reversion_strategy(source)
        reentry = apply_session_mean_reversion_strategy(source, PARAMETERS)
        self.assertEqual(legacy.loc[240, 'signal'], 'BUY')
        self.assertEqual(reentry.loc[240, 'signal'], 'WAIT')
        self.assertEqual(legacy.loc[280, 'signal'], 'SELL')
        self.assertEqual(reentry.loc[280, 'signal'], 'WAIT')
        self.assertEqual(reentry.loc[241, 'signal'], 'BUY')
        self.assertEqual(reentry.loc[281, 'signal'], 'SELL')

    def test_six_and_twentyfour_bar_holding_timers_do_not_change_vectorized_entries(self):
        for kind, function in [('breakout', apply_session_strategy), ('reentry', apply_session_mean_reversion_strategy)]:
            source = exercise_frame(kind)
            short = function(source, {**PARAMETERS, 'time_stop_candles': 6})
            day_candidate = function(source, {**PARAMETERS, 'time_stop_candles': 24})
            pd.testing.assert_frame_equal(short, day_candidate)
            # Entry equality asserts nothing about engine exits or day-flat.
            self.assertTrue({'BUY', 'SELL'} <= set(day_candidate.signal))


if __name__ == '__main__':
    unittest.main()
