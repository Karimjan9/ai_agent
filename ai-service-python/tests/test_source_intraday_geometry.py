"""Complete lab-recipe mechanics on synthetic prices, never market skill proof."""

import copy
import unittest

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services import backtester
from app.services.parameter_schema import EXECUTION_PARAMETER_SCHEMA, validate_strategy_parameters
from app.strategies.laboratory import apply_trend_specialist, apply_trend_retest_strategy
from test_instrument_method_pullback_retest import mirror, prices, pullback_frame, retest_frame
from test_source_ema_pretrend_entry import source_frame, parameters as ema_parameters


EXIT = 'closed_setup_2r_v1'
RETEST = 'source_frozen_retest_v1'


def legacy_retest(frame, parameters):
    """Frozen original retest formula, including its old reference choice."""
    out = frame.copy()
    lookback, strength = int(parameters.get('lookback', 20)), float(parameters.get('trend_strength_min', 20))
    atr = out.get('atr_regime', (out.high - out.low).rolling(14).mean()).replace(0, pd.NA)
    fast = out.close.ewm(span=int(parameters.get('ema_fast', 50)), adjust=False).mean()
    slow = out.close.ewm(span=int(parameters.get('ema_slow', 200)), adjust=False).mean()
    upper, lower = out.high.rolling(lookback).max().shift(1), out.low.rolling(lookback).min().shift(1)
    buy = (out.close.shift(1) > upper.shift(1)) & (out.low <= upper) & (out.close > upper)
    sell = (out.close.shift(1) < lower.shift(1)) & (out.high >= lower) & (out.close < lower)
    out['signal'] = 'WAIT'
    out.loc[(fast > slow) & (out.get('adx', 0) >= strength) & buy, 'signal'] = 'BUY'
    out.loc[(fast < slow) & (out.get('adx', 0) >= strength) & sell, 'signal'] = 'SELL'
    distance = ((out.close - upper).abs().where(out.close >= upper, (out.close - lower).abs()) / atr).fillna(2)
    out['signal_confidence'] = ((out.get('adx', 0) - strength) / max(1, 50 - strength) * (1 - distance.clip(0, 1))).clip(0, 1)
    return out


def retest_source(*, direction='BUY', delay=1, count=80, breakout=60):
    data = retest_frame(count=count, breakout=breakout, delay=delay)
    # A completed directional rejection, not a doji at the returned level.
    data.loc[breakout + delay, 'open'] = 101.5
    data['adx'], data['atr_regime'] = 40., 2.
    data.attrs['execution_timeframe'] = 'M5'
    return mirror(data) if direction == 'SELL' else data


def retest_parameters(**extra):
    return {'ema_fast': 20, 'ema_slow': 50, 'trend_strength_min': 20,
        'entry_topology_variant': RETEST, **extra}


def request(*, retest=False, **extra):
    parameters = retest_parameters() if retest else ema_parameters()
    parameters.update({'intraday_exit_variant': EXIT, 'time_stop_candles': 6, 'volume_lane': 'none',
        'minimum_signal_confidence': .35, 'atr_stop_multiplier': 1.5, 'atr_target_multiplier': 2.5, **extra})
    return SimpleBacktestRequest(strategy='trend_retest_v1' if retest else 'trend_v1',
        base_strategy='trend_retest' if retest else 'trend', timeframe='M5', parameters=parameters,
        initial_balance=10000, risk_per_trade=1, emit_decision_trace=True, emit_trade_ledger=True,
        execution=ExecutionConfig(spread_points=1, slippage_points=.1, point_size=.01,
            commission_percent=.01, swap_per_day_percent=.002, max_leverage=5))


class SourceIntradayProposalTest(unittest.TestCase):
    def test_existing_retest_default_and_frozen_values_remain_byte_exact(self):
        for direction in ('BUY', 'SELL'):
            frame = retest_source(direction=direction)
            for parameters in ({}, {'entry_topology_variant': 'frozen'}, {'ema_fast': 20, 'ema_slow': 50}):
                actual = apply_trend_retest_strategy(frame, parameters)
                expected = legacy_retest(frame, parameters)
                pd.testing.assert_frame_equal(actual, expected, check_exact=True)
                self.assertEqual(actual.signal_confidence.to_numpy().tobytes(), expected.signal_confidence.to_numpy().tobytes())

    def test_optional_ema_management_preserves_all_old_columns_confidence_and_inputs(self):
        for direction in ('BUY', 'SELL'):
            data = source_frame(direction=direction)
            before = data.copy(deep=True)
            control = apply_trend_specialist(data, ema_parameters())
            treated = apply_trend_specialist(data, ema_parameters(intraday_exit_variant=EXIT))
            pd.testing.assert_frame_equal(treated[control.columns], control, check_exact=True)
            self.assertFalse(any(key.startswith('entry_setup_') for key in control.columns))
            self.assertEqual(treated.loc[61, 'signal'], direction)
            expected = data.low.iloc[59:62].min() if direction == 'BUY' else data.high.iloc[59:62].max()
            self.assertEqual(treated.loc[61, 'entry_setup_invalidation_price'], expected)
            self.assertEqual(pd.Timestamp(treated.loc[61, 'entry_setup_available_at']), data.time.iloc[61] + pd.Timedelta(minutes=5))
            pd.testing.assert_frame_equal(data, before, check_exact=True)

    def test_retest_freezes_original_level_and_requires_directional_closed_rejection(self):
        for direction in ('BUY', 'SELL'):
            data = retest_source(direction=direction)
            p = retest_parameters(intraday_exit_variant=EXIT)
            actual = apply_trend_retest_strategy(data, p)
            legacy = apply_trend_retest_strategy(data, {**p, 'entry_topology_variant': 'frozen'})
            self.assertEqual(actual.loc[60, 'signal'], 'WAIT')
            self.assertEqual(actual.loc[61, 'signal'], direction)
            self.assertEqual(actual.loc[61, 'entry_retest_frozen_level'], 101. if direction == 'BUY' else 399.)
            self.assertEqual(actual.loc[61, 'entry_setup_invalidation_price'], data.low.iloc[61] if direction == 'BUY' else data.high.iloc[61])
            self.assertTrue(actual.loc[62:, 'signal'].eq('WAIT').all())
            self.assertEqual(actual.loc[61, 'signal_confidence'], (40 - 20) / 30 * (1 - 1 / 2))
            # Control remains its old moving-channel reference. This explicit
            # new topology measures the same transform at its own level.
            pd.testing.assert_series_equal(legacy.signal_confidence, legacy_retest(data, p).signal_confidence, check_exact=True)
            for bad in ('doji', 'opposite_body', 'opposing_close'):
                changed = data.copy()
                if bad == 'doji': changed.loc[61, 'open'] = changed.loc[61, 'close']
                elif bad == 'opposite_body': changed.loc[61, 'open'] = changed.loc[61, 'close'] + (.1 if direction == 'BUY' else -.1)
                else: changed.loc[61, 'close'] = 100.5 if direction == 'BUY' else 399.5
                changed.loc[61, 'low'] = min(changed.loc[61, 'low'], changed.loc[61, 'close'])
                changed.loc[61, 'high'] = max(changed.loc[61, 'high'], changed.loc[61, 'close'])
                self.assertEqual(apply_trend_retest_strategy(changed, p).loc[61, 'signal'], 'WAIT')

    def test_source_retest_gaps_expiry_and_future_bars_cannot_retroactively_confirm(self):
        p = retest_parameters(intraday_exit_variant=EXIT)
        for start in (60, 61):
            changed = retest_source()
            changed.loc[start:, 'time'] += pd.Timedelta(minutes=5)
            self.assertEqual(apply_trend_retest_strategy(changed, p).loc[61, 'signal'], 'WAIT')
        self.assertEqual(apply_trend_retest_strategy(retest_source(delay=4), p).loc[64, 'signal'], 'WAIT')
        data = retest_source()
        complete = apply_trend_retest_strategy(data, p)
        for length in (20, 50, 60, 61, 62, 80):
            pd.testing.assert_frame_equal(apply_trend_retest_strategy(data.iloc[:length].copy(), p),
                                          complete.iloc[:length], check_exact=True)
        changed = data.copy()
        changed.loc[62:, ['open', 'high', 'low', 'close']] *= 100
        pd.testing.assert_frame_equal(apply_trend_retest_strategy(changed, p).iloc[:62], complete.iloc[:62], check_exact=True)

    def test_new_retest_confidence_uses_own_frozen_level_and_missing_inputs_wait(self):
        data = retest_source()
        p = retest_parameters(intraday_exit_variant=EXIT)
        actual = apply_trend_retest_strategy(data, p)
        self.assertEqual(actual.loc[61, 'signal_confidence'], (40 - 20) / 30 * (1 - abs(102 - 101) / 2))
        self.assertEqual(actual.loc[61, 'signal'], 'BUY')
        far = data.copy(); far.loc[61, ['open', 'high', 'close']] = [102., 104.5, 104.]
        self.assertEqual(apply_trend_retest_strategy(far, p).loc[61, 'signal_confidence'], 0.)
        for missing in (0., float('nan'), float('inf')):
            unavailable = data.copy(); unavailable.loc[61, 'atr_regime'] = missing
            result = apply_trend_retest_strategy(unavailable, p)
            self.assertEqual(result.loc[61, 'signal'], 'WAIT')
            self.assertEqual(result.loc[61, 'signal_confidence'], 0.)
        self.assertTrue(actual.loc[:19, 'signal'].eq('WAIT').all())
        self.assertTrue(actual.loc[:19, 'signal_confidence'].eq(0.).all())

    def test_supplied_csv_proof_is_overwritten_and_legacy_paths_ignore_it(self):
        data = source_frame()
        data['entry_setup_invalidation_price'] = 1.
        data['entry_setup_protocol'], data['entry_setup_direction'] = 'forged', 'SELL'
        data['entry_setup_available_at'], data['entry_setup_source_time'] = '2099-01-01T00:00:00Z', 'bad'
        actual = apply_trend_specialist(data, ema_parameters(intraday_exit_variant=EXIT))
        self.assertEqual(actual.loc[61, 'entry_setup_direction'], 'BUY')
        self.assertEqual(actual.loc[61, 'entry_setup_invalidation_price'], data.low.iloc[59:62].min())
        self.assertTrue(actual.loc[actual.signal.eq('WAIT'), 'entry_setup_invalidation_price'].isna().all())
        self.assertTrue(actual.loc[actual.signal.eq('WAIT'), 'entry_setup_protocol'].eq('').all())

    def test_schema_is_explicit_fixed_and_actual_runtime_owned(self):
        self.assertNotIn('intraday_exit_variant', EXECUTION_PARAMETER_SCHEMA)
        self.assertNotIn(RETEST, EXECUTION_PARAMETER_SCHEMA['entry_topology_variant']['choices'])
        for payload in (request(), request(retest=True)):
            self.assertEqual(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy), payload.parameters)
            for change in ({'ema_fast': 21}, {'ema_slow': 51}, {'intraday_exit_variant': 'unknown'}):
                with self.assertRaises(ValueError):
                    validate_strategy_parameters(payload.strategy, {**payload.parameters, **change}, payload.base_strategy)
            with self.assertRaises(ValueError):
                validate_strategy_parameters(payload.strategy, payload.parameters, 'mean_reversion')
        with self.assertRaises(ValueError):
            validate_strategy_parameters('trend_v1', retest_parameters())
        with self.assertRaises(ValueError):
            validate_strategy_parameters('trend_retest_v1', ema_parameters(intraday_exit_variant=EXIT))


class ClosedSetupExecutionGeometryTest(unittest.TestCase):
    def row(self, *, retest=False, direction='BUY'):
        data = retest_source(direction=direction) if retest else source_frame(direction=direction)
        p = retest_parameters(intraday_exit_variant=EXIT) if retest else ema_parameters(intraday_exit_variant=EXIT)
        programme = apply_trend_retest_strategy if retest else apply_trend_specialist
        return programme(data, p).iloc[61].copy()

    def test_actual_fill_one_point_stop_and_nominal_two_r_use_existing_cost_aware_sizing(self):
        for retest in (False, True):
            for direction in ('BUY', 'SELL'):
                row, payload = self.row(retest=retest, direction=direction), request(retest=retest)
                market = float(row.close)
                geometry = backtester._closed_setup_geometry(market, row, payload, execution_at=row.entry_setup_available_at)
                self.assertTrue(geometry['allowed'], geometry)
                stop = row.entry_setup_invalidation_price + (-.01 if direction == 'BUY' else .01)
                fill = backtester._entry_price(market, direction, payload)
                target = fill + (2 if direction == 'BUY' else -2) * abs(fill - stop)
                self.assertEqual(geometry['stop_price'], stop)
                self.assertEqual(geometry['target_price'], target)
                distances = backtester._exit_distances(market, row, payload)
                self.assertAlmostEqual(market + (-1 if direction == 'BUY' else 1) * distances[0], stop)
                size = backtester._position_size_multiple(fill, stop, direction, payload)
                risk = backtester._initial_executable_risk_percent(fill, stop, direction, payload, size)
                self.assertLessEqual(risk, 1.000000000001)
                self.assertLessEqual(size, payload.execution.max_leverage)
                stop_fill = backtester._exit_price(stop, direction, payload)
                commission = fill * payload.execution.commission_percent / 100
                net_reward = abs(backtester._exit_price(target, direction, payload) - fill) - commission
                self.assertLess(net_reward / (abs(fill - stop_fill) + commission), 2)

    def test_missing_wrong_side_future_direction_base_and_gap_are_vetoed_without_shadow_fallback(self):
        original, payload = self.row(), request()
        execution = pd.Series({'time': pd.Timestamp(original.entry_setup_available_at), 'open': float(original.close)})
        cases = []
        for key, value in [('entry_setup_invalidation_price', None), ('entry_setup_direction', 'SELL'),
            ('entry_setup_protocol', 'confirmation_entry_contract_v1'), ('entry_setup_available_at', '2099-01-01T00:00:00Z'),
            ('source_entry_rule_admitted', pd.NA), ('source_entry_rule_direction', pd.NA)]:
            row = original.copy(); row[key] = value; cases.append((row, execution, payload))
        crossed = execution.copy(); crossed['open'] = original.entry_setup_invalidation_price - 1
        cases.append((original, crossed, payload))
        delayed = execution.copy(); delayed['time'] += pd.Timedelta(minutes=5)
        cases.append((original, delayed, payload))
        spoof = payload.model_copy(deep=True); spoof.base_strategy = 'trend_retest'
        cases.append((original, execution, spoof))
        for row, candle, candidate in cases:
            admitted, reason = backtester._entry_eligibility(candle, candidate, row)
            self.assertFalse(admitted)
            self.assertTrue(reason.startswith('closed_setup_'), reason)
            self.assertIsNone(backtester._open_shadow_position(candle, row, 'BUY', candidate, 62, reason))

    def test_real_structural_target_not_unrelated_atr_controls_cost_guard(self):
        row, payload = self.row(), request()
        row['_management_atr'] = 1.
        # The ordinary ATR target would pass, but this actual source target
        # cannot cover the deliberately declared costs. No guard is weakened.
        row['entry_setup_invalidation_price'] = float(row.close) - .001
        payload.execution.spread_points = 0
        payload.execution.slippage_points = 0
        payload.execution.commission_percent = .1
        candle = pd.Series({'time': pd.Timestamp(row.entry_setup_available_at), 'open': float(row.close)})
        self.assertEqual(backtester._entry_eligibility(candle, payload, row), (False, 'cost_exceeds_target'))

    def test_structural_orders_keep_existing_conservative_and_gap_stop_execution(self):
        for direction in ('BUY', 'SELL'):
            row, payload = self.row(direction=direction), request()
            market = float(row.close)
            geometry = backtester._closed_setup_geometry(market, row, payload)
            position = {'stop_loss': geometry['stop_price'], 'take_profit': geometry['target_price']}
            extremes = [geometry['stop_price'], geometry['target_price'], market]
            price, reason = backtester._intrabar_exit(direction, position,
                {'open': market, 'high': max(extremes) + 1, 'low': min(extremes) - 1}, payload)
            self.assertEqual(reason, 'intrabar_stop')
            self.assertEqual(price, backtester._exit_price(geometry['stop_price'], direction, payload))
            gap = geometry['stop_price'] + (-1 if direction == 'BUY' else 1)
            price, reason = backtester._intrabar_exit(direction, position,
                {'open': gap, 'high': gap + .1, 'low': gap - .1}, payload)
            self.assertEqual(reason, 'gap_stop')
            self.assertEqual(price, backtester._exit_price(gap, direction, payload))


class OriginalSourceIntradayReplayTest(unittest.TestCase):
    """Actual registered programmes with synthetic OHLC, no signal mocks."""

    @classmethod
    def setUpClass(cls):
        cls.outputs = {}
        for retest in (False, True):
            if retest:
                data = retest_source(count=320, breakout=260)
                for index in range(200):
                    close = 90 + index * .05
                    data.loc[index, ['open', 'high', 'low', 'close']] = [close, close + .2, close - .2, close]
            else:
                data = pullback_frame(count=320, pullback=260)
            payload = request(retest=retest)
            result = backtester.run_simple_ema_rsi_backtest_on_dataframe(payload, data,
                include_differential_pair=False, lightweight=True)
            cls.outputs[retest] = (payload, data, result)

    def test_both_actual_programmes_fill_with_own_structural_stop_and_no_larger_risk(self):
        for retest, (payload, data, result) in self.outputs.items():
            with self.subTest(retest=retest):
                self.assertGreater(result.total_trades, 0)
                self.assertEqual(result.trade_ledger[0].exit_reason, 'time_stop')
                for trade in result.trade_ledger:
                    self.assertLessEqual(trade.initial_risk_percent, 1.000001)
                    entry = int(data.index[data.time == pd.Timestamp(trade.entry_time)][0])
                    source = data.iloc[entry - 1]
                    anchor = (source.low if retest else data.low.iloc[entry - 3:entry].min()) if trade.direction == 'BUY' else (
                        source.high if retest else data.high.iloc[entry - 3:entry].max())
                    stop = anchor + (-.01 if trade.direction == 'BUY' else .01)
                    self.assertAlmostEqual(trade.stop_loss, stop, places=2)
                    fill = backtester._entry_price(float(data.iloc[entry].open), trade.direction, payload)
                    target = fill + (2 if trade.direction == 'BUY' else -2) * abs(fill - stop)
                    self.assertEqual(trade.take_profit, round(target, 2))
                    self.assertLessEqual(pd.Timestamp(trade.exit_time) - pd.Timestamp(trade.entry_time), pd.Timedelta(minutes=30))
                    self.assertEqual(pd.Timestamp(trade.entry_time).date(), pd.Timestamp(trade.exit_time).date())


if __name__ == '__main__':
    unittest.main()
