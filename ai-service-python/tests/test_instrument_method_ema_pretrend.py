"""Prospective pre-pullback slope rule; synthetic correctness, not market skill."""

import pandas as pd
import pytest

from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from test_instrument_method_pullback_retest import (
    prices, mirror, pullback_frame, recursive_ema, inputs,
)


def interrupted_trend(*, flat=False):
    """An old two-bar bounce after a preceding flat/falling fast EMA."""
    closes = [100.0 + index * .25 for index in range(80)]
    prior = pd.Series(closes[:59])
    fast = prior.ewm(span=20, adjust=False).mean().iloc[-1]
    slow = prior.ewm(span=50, adjust=False).mean().iloc[-1]
    closes[59] = float(fast if flat else slow - 1.0)
    prefix = pd.Series(closes[:60])
    fast = prefix.ewm(span=20, adjust=False).mean().iloc[-1]
    slow = prefix.ewm(span=50, adjust=False).mean().iloc[-1]
    closes[60] = float((fast + slow) / 2.0)
    return prices(closes)


@pytest.mark.parametrize('direction', ['BUY', 'SELL'])
def test_new_pretrend_rule_preserves_exact_old_bounce_then_requires_prior_rising_or_falling_emas(direction):
    data = pullback_frame(direction=direction)
    owner = ClosedInstrumentMethodFeatures(data)
    observed = inputs(owner, data, 61, direction=direction)
    assert observed['method_pullback_available'] and observed['method_pullback_confirmed']
    assert observed['method_pullback_pretrend_available']
    assert observed['method_pullback_pretrend_confirmed']
    for period in (20, 50):
        exact = recursive_ema(data['close'].tolist(), period)
        for index in (58, 59, 60, 61):
            assert owner.features[index]['_ema' + str(period)] == pytest.approx(exact[index], abs=1e-12)
        before, after = exact[58], exact[59]
        assert after > before if direction == 'BUY' else after < before
    wrong = 'SELL' if direction == 'BUY' else 'BUY'
    assert not inputs(owner, data, 61, direction=wrong)['method_pullback_pretrend_confirmed']
    assert not inputs(owner, data, 61, direction='WAIT')['method_pullback_pretrend_confirmed']


@pytest.mark.parametrize('direction', ['BUY', 'SELL'])
def test_previously_falling_trend_alignment_can_pass_old_bounce_but_not_new_pretrend(direction):
    data = interrupted_trend()
    if direction == 'SELL':
        data = mirror(data)
    owner = ClosedInstrumentMethodFeatures(data)
    observed = inputs(owner, data, 61, direction=direction)
    assert observed['method_pullback_confirmed']  # Old semantics deliberately preserved.
    assert observed['method_pullback_pretrend_available']
    assert not observed['method_pullback_pretrend_confirmed']
    for period in (20, 50):
        earlier = owner.features[58]['_ema' + str(period)]
        before = owner.features[59]['_ema' + str(period)]
        assert before < earlier if direction == 'BUY' else before > earlier


def test_flat_pretrend_ema_slope_waits_although_old_bounce_can_confirm():
    data = interrupted_trend(flat=True)
    owner = ClosedInstrumentMethodFeatures(data)
    assert owner.features[58]['_ema20'] == owner.features[59]['_ema20']
    observed = inputs(owner, data, 61)
    assert observed['method_pullback_confirmed']
    assert observed['method_pullback_pretrend_available']
    assert not observed['method_pullback_pretrend_confirmed']


def test_pretrend_needs_four_mature_bars_not_the_old_two_bar_maturity_floor():
    data = pullback_frame(pullback=50)
    owner = ClosedInstrumentMethodFeatures(data)
    observed = inputs(owner, data, 51)
    assert observed['method_pullback_available'] and observed['method_pullback_confirmed']
    assert not observed['method_pullback_pretrend_available']
    assert not observed['method_pullback_pretrend_confirmed']
    assert inputs(owner, data, 52)['method_pullback_pretrend_available']


@pytest.mark.parametrize('gap_start', [59, 60, 61])
def test_each_of_the_four_bar_sequence_gaps_refuses_new_confirmation(gap_start):
    data = pullback_frame()
    data.loc[gap_start:, 'time'] += pd.Timedelta(minutes=5)
    observed = inputs(ClosedInstrumentMethodFeatures(data), data, 61)
    assert not observed['method_pullback_pretrend_available']
    assert not observed['method_pullback_pretrend_confirmed']
    if gap_start < 61:
        assert observed['method_pullback_confirmed']  # Old adjacent pair still contiguous.


def test_older_history_gap_does_not_erase_observed_emas_and_configured_clock_is_used():
    data = pullback_frame()
    data.loc[20:, 'time'] += pd.Timedelta(minutes=5)
    assert inputs(ClosedInstrumentMethodFeatures(data), data, 61)['method_pullback_pretrend_confirmed']
    wide = pullback_frame()
    wide['time'] = pd.date_range('2025-01-06T08:00:00Z', periods=len(wide), freq='15min')
    assert inputs(ClosedInstrumentMethodFeatures(wide, bar_duration_seconds=900), wide, 61,
        seconds=900)['method_pullback_pretrend_confirmed']
    assert not inputs(ClosedInstrumentMethodFeatures(wide), wide, 61)['method_pullback_pretrend_available']


def test_future_suffix_does_not_rewrite_any_old_or_new_prefix_observation():
    data = pullback_frame()
    changed = data.copy()
    changed.loc[62:, ['open', 'high', 'low', 'close']] *= 100
    prefix = ClosedInstrumentMethodFeatures(data.iloc[:62])
    full = ClosedInstrumentMethodFeatures(changed)
    for index in range(62):
        assert inputs(prefix, data, index) == inputs(full, changed, index)


def test_caller_cannot_supply_confirmation_or_use_the_current_unclosed_bar():
    data = pullback_frame()
    owner = ClosedInstrumentMethodFeatures(data)
    row = data.iloc[60].to_dict()
    row['method_pullback_pretrend_confirmed'] = True
    observed = owner.inputs(row, direction='BUY', observed_at=(row['time'] + pd.Timedelta(minutes=5)).isoformat())
    assert not observed['method_pullback_pretrend_confirmed']
    with pytest.raises(ValueError, match='ORIGINAL_CLOSED_ROW_REQUIRED'):
        ClosedInstrumentMethodFeatures(data).inputs(data.iloc[61].to_dict(), direction='BUY',
            observed_at=data.iloc[61]['time'].isoformat())
