"""Closed source-entry engineering, not market performance qualification."""

import unittest

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services import backtester
from app.services.backtester import prepare_signal_snapshot
from app.services.parameter_schema import validate_strategy_parameters
from app.services.specialist_council import _compile_member, _closed_trace_input, MEMBER_TRACE_FIELDS
from app.strategies.laboratory import apply_trend_specialist, _rsi
from test_instrument_method_pullback_retest import pullback_frame
from test_instrument_method_ema_pretrend import interrupted_trend
from test_instrument_method_pullback_retest import mirror
from test_specialist_council import member


VARIANT = 'source_ema_pretrend_pullback_v1'


def parameters(**overrides):
    return {'ema_fast': 20, 'ema_slow': 50, 'rsi_period': 14,
            'trend_strength_min': 20, 'entry_topology_variant': VARIANT, **overrides}


def source_frame(**kwargs):
    data = pullback_frame(**kwargs)
    data['adx'] = 40.0
    data.attrs['execution_timeframe'] = 'M5'
    return data


def legacy(data, p):
    """Frozen original formula, including its confidence and ATR fallback."""
    out = data.copy()
    out['trend_fast'] = out.close.ewm(span=int(p.get('ema_fast', 50)), adjust=False).mean()
    out['trend_slow'] = out.close.ewm(span=int(p.get('ema_slow', 200)), adjust=False).mean()
    out['trend_rsi'] = _rsi(out.close, int(p.get('rsi_period', 14)))
    adx = out.get('adx', 0)
    strength = float(p.get('trend_strength_min', 20))
    atr = out.get('atr_regime', (out.high - out.low).rolling(14).mean())
    near = (out.close - out.trend_fast).abs() <= atr * float(p.get('pullback_atr_fraction', .75))
    out['signal'] = 'WAIT'
    out.loc[(out.trend_fast > out.trend_slow) & (adx >= strength) & near & (out.trend_rsi > 50), 'signal'] = 'BUY'
    out.loc[(out.trend_fast < out.trend_slow) & (adx >= strength) & near & (out.trend_rsi < 50), 'signal'] = 'SELL'
    out['signal_confidence'] = ((adx - strength) / max(1, 50 - strength)).clip(0, 1)
    return out


class SourceEmaPretrendEntryTest(unittest.TestCase):
    def test_every_preexisting_topology_keeps_exact_old_frame_behavior(self):
        for variant in [None, 'frozen', 'regime_consensus_v1', 'trend_regime_confirmation_v1']:
            for direction in ['BUY', 'SELL']:
                with self.subTest(variant=variant, direction=direction):
                    data = source_frame(direction=direction)
                    p = {} if variant is None else {'entry_topology_variant': variant}
                    pd.testing.assert_frame_equal(legacy(data, p), apply_trend_specialist(data, p))

    def test_closed_pretrend_touch_reclaim_generates_genuine_buy_and_sell(self):
        for direction in ['BUY', 'SELL']:
            with self.subTest(direction=direction):
                data = source_frame(direction=direction)
                out = apply_trend_specialist(data, parameters())
                self.assertEqual(out.loc[61, 'signal'], direction)
                self.assertEqual(out.loc[61, 'source_entry_rule_direction'], direction)
                self.assertTrue(out.loc[61, 'source_entry_rule_available'])
                self.assertTrue(out.loc[61, 'source_entry_rule_admitted'])
                self.assertEqual(out.loc[61, 'source_entry_rule_protocol'], 'closed_ema_pretrend_entry_v1')
                self.assertEqual(out.loc[60, 'signal'], 'WAIT')
                # The new candidate is not a veto on the old proximity signal.
                self.assertEqual(apply_trend_specialist(data, parameters(entry_topology_variant='frozen')).loc[61, 'signal'], 'WAIT')
                self.assertAlmostEqual(out.loc[61, 'signal_confidence'], (40 - 20) / 30)

    def test_strength_and_rsi_confirmation_and_confidence_are_not_bypassed(self):
        data = source_frame()
        data['adx'] = 10.0
        out = apply_trend_specialist(data, parameters())
        self.assertEqual(out.loc[61, 'source_entry_rule_direction'], 'BUY')
        self.assertEqual(out.loc[61, 'signal'], 'WAIT')
        self.assertEqual(out.loc[61, 'signal_confidence'], 0.0)
        # An unavailable actual RSI cannot be replaced with positive confidence.
        out = apply_trend_specialist(source_frame(), parameters(rsi_period=100))
        self.assertEqual(out.loc[61, 'signal'], 'WAIT')

    def test_falling_pretrend_and_flat_slope_do_not_generate_an_entry(self):
        for flat, direction in [(False, 'BUY'), (False, 'SELL'), (True, 'BUY')]:
            data = interrupted_trend(flat=flat)
            if direction == 'SELL':
                data = mirror(data)
            data['adx'] = 40.0
            data.attrs['execution_timeframe'] = 'M5'
            with self.subTest(flat=flat, direction=direction):
                out = apply_trend_specialist(data, parameters())
                self.assertTrue(out.loc[61, 'source_entry_rule_available'])
                self.assertEqual(out.loc[61, 'source_entry_rule_direction'], 'WAIT')
                self.assertEqual(out.loc[61, 'signal'], 'WAIT')

    def test_missing_actual_clock_or_original_adx_refuses_instead_of_inventing_them(self):
        data = source_frame()
        data.attrs.clear()
        with self.assertRaisesRegex(ValueError, 'ACTUAL_EXECUTION_CLOCK_REQUIRED'):
            apply_trend_specialist(data, parameters())
        with self.assertRaisesRegex(ValueError, 'ORIGINAL_ADX_REQUIRED'):
            apply_trend_specialist(source_frame().drop(columns=['adx']), parameters())

    def test_immaturity_and_each_sequence_gap_fail_closed(self):
        immature = apply_trend_specialist(source_frame(count=49, pullback=45), parameters())
        self.assertFalse(immature.source_entry_rule_available.any())
        self.assertTrue(immature.signal.eq('WAIT').all())
        for start in [59, 60, 61]:
            data = source_frame()
            data.loc[start:, 'time'] += pd.Timedelta(minutes=5)
            with self.subTest(start=start):
                out = apply_trend_specialist(data, parameters())
                self.assertEqual(out.loc[61, 'signal'], 'WAIT')
                self.assertFalse(out.loc[61, 'source_entry_rule_available'])

    def test_future_changes_cannot_change_closed_entry_or_audit_prefix(self):
        original = source_frame()
        before = apply_trend_specialist(original, parameters())
        changed = original.copy()
        changed.loc[62:, ['open', 'high', 'low', 'close']] *= 3
        after = apply_trend_specialist(changed, parameters())
        pd.testing.assert_frame_equal(before.iloc[:62], after.iloc[:62])
        pd.testing.assert_frame_equal(before.iloc[:62], apply_trend_specialist(original.iloc[:62], parameters()))

    def test_explicit_schema_rejects_wrong_family_or_nonexact_periods(self):
        self.assertEqual(validate_strategy_parameters('trend_v1', parameters())['entry_topology_variant'], VARIANT)
        wrong_family = {key: value for key, value in parameters().items() if key != 'trend_strength_min'}
        for p, family in [(parameters(ema_fast=21), 'trend'), (parameters(ema_slow=51), 'trend'),
                          (wrong_family, 'ema_rsi'), (parameters(ema_fast=50, ema_slow=20), 'trend')]:
            with self.subTest(parameters=p, family=family):
                with self.assertRaisesRegex(ValueError, 'FIXED_TREND_20_50_REQUIRED'):
                    validate_strategy_parameters(family, p)
        with self.assertRaisesRegex(ValueError, 'TREND_ENTRY_RUNTIME_REQUIRED'):
            validate_strategy_parameters('trend_retest_v1', parameters())

    def test_native_member_compiles_actual_opt_in_programme_with_closed_account_clock(self):
        data = source_frame(count=260, pullback=230)
        data['volume'] = 100.0
        data['volume_available'] = True
        request = SimpleBacktestRequest(strategy='portfolio_v1', base_strategy='portfolio', timeframe='M5')
        declaration = member('hour')
        declaration.update(strategy='trend_v1', base_strategy='trend', parameters=parameters())
        runtime = _compile_member(request, data, declaration, 'prospective-source-entry')
        self.assertEqual(runtime.payload.parameters['entry_topology_variant'], VARIANT)
        self.assertEqual(runtime.payload.timeframe, 'M5')
        self.assertEqual(runtime.rows[231]['source_entry_rule_direction'], 'BUY')
        self.assertEqual(runtime.rows[231]['source_entry_rule_protocol'], 'closed_ema_pretrend_entry_v1')
        self.assertEqual(runtime.rows[231]['signal'], 'BUY')
        closed, _, _ = _closed_trace_input(runtime.rows[231], MEMBER_TRACE_FIELDS, backtester)
        self.assertEqual(closed['source_entry_rule_protocol'], 'closed_ema_pretrend_entry_v1')
        self.assertEqual(closed['source_entry_rule_direction'], 'BUY')
        ordinary = backtester._decision_trace_event(232, pd.Series(runtime.rows[232]), pd.Series(runtime.rows[231]),
            'entry', 'BUY', False, 'software_fixture_only', {})
        self.assertEqual(ordinary['features']['source_entry_rule_protocol'], 'closed_ema_pretrend_entry_v1')
        self.assertEqual(ordinary['features']['source_entry_rule_direction'], 'BUY')
        # Actual canonical preparation uses the same strategy; no score injection.
        direct = prepare_signal_snapshot(SimpleBacktestRequest(strategy='trend_v1', base_strategy='trend',
            timeframe='M5', parameters=parameters()), data)
        self.assertEqual(direct.frame.iloc[231]['signal'], runtime.rows[231]['signal'])


if __name__ == '__main__':
    import sys
    if sys.argv[1:] == ['--native-json']:
        import json
        wire = json.load(sys.stdin)
        request = SimpleBacktestRequest.model_validate(wire['request'])
        data = source_frame(count=260, pullback=230)
        data['volume'], data['volume_available'] = 100.0, True
        runtime = _compile_member(request, data, wire['member'], 'php-source-entry')
        row = runtime.rows[231]
        print(json.dumps({'strategy': runtime.payload.strategy, 'base_strategy': runtime.payload.base_strategy,
            'parameters': runtime.payload.parameters, 'timeframe': runtime.payload.timeframe,
            'signal': row['signal'], 'entry_protocol': row['source_entry_rule_protocol'],
            'entry_available': bool(row['source_entry_rule_available']),
            'entry_direction': row['source_entry_rule_direction'],
            'independent_qualification': False}))
    else:
        unittest.main()
