"""Private single-thread native trailing observations; no broker or skill authority.

The wrapper calls the existing native function exactly once and returns its
unchanged result. Verification reproduces the small price-R/closed-ATR rule,
not a replay or an execution-price model. Shadow calls remain counterfactual.
"""
from __future__ import annotations

from contextlib import contextmanager
import copy
from datetime import datetime
import hashlib
import importlib
import json
import math
import threading

import numpy as np
import pandas as pd

PROTOCOL = 'offline_curator_trailing_activation_witness_v1'
MAX_EVENTS = 20000
_LOCK = threading.Lock()
_STATE_KEYS = ('entry_time', 'direction', 'entry_price', 'initial_stop_loss',
               'initial_risk_distance', 'stop_loss', 'trailing_activation_armed')
_CONTEXT_KEYS = ('market_regime', 'volatility_regime', 'market_session', 'market_session_version',
                 'session', 'session_bucket', 'venue_phase')
_CEILING = dict(research_only=True, private_offline=True, own_agent_outcome=False,
                independent_validation=False, economic_authority=False,
                paper_authority=False, live_authority=False)


def _need(ok, reason):
    if not ok:
        raise ValueError(reason)


def _safe(value):
    if value is None or value is pd.NA or value is pd.NaT:
        return None
    if isinstance(value, dict):
        return {str(key): _safe(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_safe(item) for item in value]
    if isinstance(value, (bool, np.bool_)):
        return bool(value)
    if isinstance(value, (datetime, pd.Timestamp)):
        return pd.Timestamp(value).isoformat()
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, (float, np.floating)):
        return float(value) if math.isfinite(float(value)) else None
    return value


def _bytes(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False, allow_nan=False).encode()


def _sha(value):
    return hashlib.sha256(_bytes(value)).hexdigest()


def _framed(events):
    digest = hashlib.sha256()
    for event in events:
        raw = _bytes(event)
        digest.update(len(raw).to_bytes(8, 'big'))
        digest.update(raw)
    return digest.hexdigest()


def _number(value, reason, *, positive=False):
    _need(value is not None and not isinstance(value, bool), reason)
    result = float(value)
    _need(math.isfinite(result) and (not positive or result > 0), reason)
    return result


def _equal(actual, expected, reason):
    _need(math.isclose(_number(actual, reason), expected, rel_tol=1e-12, abs_tol=1e-10), reason)


def _time(value):
    stamp = pd.Timestamp(value)
    _need(not pd.isna(stamp) and stamp.tzinfo is not None, 'EXPLICIT_ORIGINAL_UTC_CLOCK_REQUIRED')
    return stamp.tz_convert('UTC')


def _key(state):
    return (_time(state['entry_time']).isoformat(), state['direction'])


def _binding(payload):
    return _safe({'symbol': payload.symbol, 'timeframe': payload.timeframe,
                  'parameters': copy.deepcopy(payload.parameters),
                  'initial_balance': payload.initial_balance, 'risk_per_trade': payload.risk_per_trade,
                  'execution': copy.deepcopy(payload.execution.model_dump())})


class _Observer:
    def __init__(self):
        self.events = []
        self.request = None

    def snapshot(self):
        body = {'protocol': PROTOCOL, **_CEILING, 'request': copy.deepcopy(self.request),
                'events': copy.deepcopy(self.events), 'event_count': len(self.events),
                'framed_event_sha256': _framed(self.events)}
        return {**body, 'witness_sha256': _sha(body)}


@contextmanager
def capture_trailing_activation_witness():
    """Observe one isolated ordinary replay; restore the callable even on error."""
    _need(_LOCK.acquire(blocking=False), 'NESTED_OR_CONCURRENT_TRAILING_CAPTURE_REFUSED')
    owner, original, observer = None, None, _Observer()
    thread = threading.get_ident()
    try:
        owner = importlib.import_module('app.services.backtester')
        original = owner._advance_trailing_stop
        _need(not getattr(original, '_offline_activation_capture_active', False),
              'NESTED_OR_CONCURRENT_TRAILING_CAPTURE_REFUSED')

        def observe(position, previous_candle, payload):
            _need(threading.get_ident() == thread, 'OFFLINE_SINGLE_THREAD_CAPTURE_REQUIRED')
            _need(len(observer.events) < MAX_EVENTS, 'BOUNDED_TRAILING_EVENT_CAP_REQUIRED')
            _need(not payload.portfolio_members and not payload.specialist_council_contract
                  and not payload.composition_runtime_contract, 'ORDINARY_UNCOMPOSED_TRAILING_ONLY')
            binding = _binding(payload)
            _need(observer.request is None or observer.request == binding, 'ONE_PHYSICAL_REQUEST_PER_CAPTURE_REQUIRED')
            observer.request = binding
            state = lambda: _safe(copy.deepcopy({key: position[key] for key in _STATE_KEYS if key in position}))
            event = {'sequence': len(observer.events), 'before': state(),
                     'scope': 'shadow_counterfactual' if str(position.get('policy_arm', '')).startswith('shadow_')
                              and 'veto_reason' in position else 'accepted_original',
                     'shadow_policy_arm': _safe(position.get('policy_arm')),
                     'closed_row': _safe(copy.deepcopy({key: previous_candle.get(key)
                         for key in ('time', 'close', '_management_atr')})),
                     'context': _safe(copy.deepcopy({key: previous_candle[key]
                         for key in _CONTEXT_KEYS if key in previous_candle})),
                     'context_missing_fields': [key for key in _CONTEXT_KEYS if key not in previous_candle],
                     'parameters': copy.deepcopy(binding['parameters'])}
            result = original(position, previous_candle, payload)
            event['after'] = state()
            observer.events.append(event)
            return result

        observe._offline_activation_capture_active = True
        owner._advance_trailing_stop = observe
        yield observer
    finally:
        if owner is not None and original is not None:
            owner._advance_trailing_stop = original
        _LOCK.release()


def _expected(before, row, multiplier, activation):
    """Independent close-only rule; positive activation ignores pre-entry rows."""
    after = copy.deepcopy(before)
    if activation > 0:
        try:
            direction = before['direction']
            entry = _number(before['entry_price'], 'VALID_ENTRY_REQUIRED', positive=True)
            close = _number(row['close'], 'VALID_CLOSE_REQUIRED', positive=True)
            _number(before['stop_loss'], 'VALID_STOP_REQUIRED')
            risk = before.get('initial_risk_distance')
            if risk is None:
                initial = _number(before['initial_stop_loss'], 'VALID_INITIAL_STOP_REQUIRED')
                risk = entry - initial if direction == 'BUY' else initial - entry
            risk = _number(risk, 'VALID_INITIAL_PRICE_R_REQUIRED', positive=True)
            atr = _number(row['_management_atr'], 'VALID_ATR_REQUIRED', positive=True)
            _need(direction in ('BUY', 'SELL'), 'VALID_ACTIVATION_DIRECTION_REQUIRED')
            observed_at, entered_at = _time(row['time']), _time(before['entry_time'])
            threshold = entry + activation * risk if direction == 'BUY' else entry - activation * risk
            _need(math.isfinite(threshold), 'FINITE_ACTIVATION_THRESHOLD_REQUIRED')
        except (KeyError, ValueError, TypeError, OverflowError):
            return after, 'unavailable_original_input'
        if multiplier <= 0:
            return after, 'disabled_or_missing_atr'
        if observed_at < entered_at:
            return after, 'pre_entry_not_eligible'
        if not before.get('trailing_activation_armed', False):
            reached = close >= threshold if direction == 'BUY' else close <= threshold
            if not reached:
                return after, 'pre_activation_held'
            after['trailing_activation_armed'] = True
    atr = float(row.get('_management_atr') or 0)
    if multiplier <= 0 or atr <= 0:
        return after, 'disabled_or_missing_atr'
    _need(math.isfinite(atr), 'FINITE_NATIVE_ATR_REQUIRED')
    stop, close = _number(before['stop_loss'], 'FINITE_NATIVE_STOP_REQUIRED'), _number(row['close'], 'FINITE_NATIVE_CLOSE_REQUIRED')
    candidate = close - multiplier * atr if before['direction'] == 'BUY' else close + multiplier * atr
    after['stop_loss'] = max(stop, candidate) if before['direction'] == 'BUY' else min(stop, candidate)
    return after, 'tightened' if after['stop_loss'] != stop else 'unchanged'


def verify_trailing_activation_witness(request_dict, response_dict, witness, full_close_witness=None):
    """Check local call evidence; optional raw accepted entries remove rounding ambiguity."""
    request = request_dict.model_dump() if hasattr(request_dict, 'model_dump') else request_dict
    response = response_dict.model_dump() if hasattr(response_dict, 'model_dump') else response_dict
    _need(witness.get('protocol') == PROTOCOL and all(witness.get(key) is value for key, value in _CEILING.items()),
          'PRIVATE_RESEARCH_CEILING_REQUIRED')
    _need(_sha({key: value for key, value in witness.items() if key != 'witness_sha256'}) == witness.get('witness_sha256'),
          'ORIGINAL_TRAILING_WITNESS_SHA_REQUIRED')
    events = witness['events']
    _need(type(witness['event_count']) is int and witness['event_count'] == len(events) <= MAX_EVENTS
          and _framed(events) == witness['framed_event_sha256'], 'ORIGINAL_FRAMED_TRAILING_EVENTS_REQUIRED')
    binding = witness['request']
    if binding is None:
        _need(not events, 'NO_REQUEST_BINDING_ONLY_WHEN_NO_NATIVE_CALLS')
        # An empty stream is explicitly unactivated, not an observed formula.
        # The runner binds this empty witness to the original raw response.
        binding = {key: request[key] for key in ('symbol', 'timeframe', 'parameters',
                    'initial_balance', 'risk_per_trade', 'execution')}
    for key in ('symbol', 'timeframe', 'parameters', 'initial_balance', 'risk_per_trade', 'execution'):
        _need(request[key] == binding[key], 'EXACT_REQUEST_BINDING_REQUIRED:' + key)
    _need(response['parameters'] == binding['parameters'] and response['execution_assumptions'] == binding['execution']
          and response['initial_balance'] == binding['initial_balance'], 'ORIGINAL_RESPONSE_PARAMETER_PARITY_REQUIRED')
    params = binding['parameters']
    multiplier = _number(params.get('trailing_atr_multiplier', 0) or 0, 'FINITE_DECLARED_TRAILING_REQUIRED')
    activation = _number(params.get('trailing_activation_r', 0) or 0, 'FINITE_DECLARED_ACTIVATION_REQUIRED')
    _need(multiplier >= 0 and 0 <= activation <= 4 and float(params.get('partial_take_profit_fraction', 0) or 0) == 0,
          'BOUNDED_FULL_CLOSE_DECLARATION_REQUIRED')
    trades = response['trade_ledger']
    _need(response['total_trades'] == len(trades), 'ORIGINAL_PUBLIC_TRADE_COUNT_REQUIRED')
    public = {_key(trade): trade for trade in trades}
    _need(len(public) == len(trades), 'UNIQUE_ORIGINAL_PUBLIC_POSITION_REQUIRED')
    maturity = response['statistical_evidence']['original_position_maturity']
    _need(maturity.get('protocol') == 'original_position_maturity_v1'
          and type(maturity.get('closed_trade_count')) is int and maturity['closed_trade_count'] == len(trades)
          and type(maturity.get('open_position_count')) is int and maturity['open_position_count'] in (0, 1)
          and type(maturity.get('censored_trade_count')) is int and maturity['censored_trade_count'] == 0
          and type(maturity.get('unknown_maturity_count')) is int and maturity['unknown_maturity_count'] == 0
          and maturity.get('forced_terminal_close_applied') is False, 'STRICT_ORIGINAL_POSITION_MATURITY_REQUIRED')
    raw_entries = None
    if full_close_witness is not None:
        _need(full_close_witness.get('protocol') == 'offline_curator_full_close_witness_v1'
              and full_close_witness.get('research_only') is True
              and full_close_witness['public_trades'] == trades
              and full_close_witness['request']['parameters'] == params
              and full_close_witness['request']['execution'] == binding['execution'], 'ORIGINAL_FULL_CLOSE_BINDING_REQUIRED')
        raw_entries = {_key(entry): entry for entry in full_close_witness['entry_events'].values()}
        _need(len(raw_entries) == len(full_close_witness['entry_events']), 'UNIQUE_RAW_ORIGINAL_ENTRY_REQUIRED')
    groups, shadow_groups, context_counts = {}, {}, {}
    counts = dict(accepted_event_count=0, shadow_counterfactual_event_count=0,
                  tightened_stop_events=0, preactivation_held_events=0,
                  pre_entry_not_eligible_events=0, unavailable_original_input_events=0,
                  disabled_or_missing_atr_events=0)
    for sequence, event in enumerate(events):
        _need(event['sequence'] == sequence and event['parameters'] == params, 'EXACT_CALL_SEQUENCE_AND_PARAMETERS_REQUIRED')
        before, after, row = event['before'], event['after'], event['closed_row']
        _need(before.get('direction') in ('BUY', 'SELL') and isinstance(before.get('trailing_activation_armed', False), bool),
              'VALID_ORIGINAL_DIRECTION_AND_LATCH_REQUIRED')
        _need(event['scope'] in ('accepted_original', 'shadow_counterfactual'), 'EXPLICIT_POSITION_SCOPE_REQUIRED')
        key = _key(before)
        state_groups = groups if event['scope'] == 'accepted_original' else shadow_groups
        _need((event['scope'] == 'shadow_counterfactual') == str(event['shadow_policy_arm'] or '').startswith('shadow_'),
              'SHADOW_SCOPE_PARITY_REQUIRED')
        if key in state_groups:
            previous = state_groups[key]
            _need(before == previous['after'] and _time(row['time']) > _time(previous['closed_row']['time']),
                  'EXACT_MUTABLE_POSITION_STATE_CONTINUITY_REQUIRED')
        else:
            _need(not before.get('trailing_activation_armed', False), 'FRESH_POSITION_CANNOT_START_ARMED')
        expected, classification = _expected(before, row, multiplier, activation)
        _need(after == expected, 'INDEPENDENT_CLOSE_ONLY_ACTIVATION_AND_RATCHET_REQUIRED')
        if after['direction'] == 'BUY':
            _need(after['stop_loss'] >= before['stop_loss'], 'FAVORABLE_ONLY_MONOTONIC_STOP_REQUIRED')
        else:
            _need(after['stop_loss'] <= before['stop_loss'], 'FAVORABLE_ONLY_MONOTONIC_STOP_REQUIRED')
        state_groups[key] = event
        if event['scope'] == 'shadow_counterfactual':
            if activation > 0:
                shadow_entry = _number(before['entry_price'], 'POSITIVE_SHADOW_ACTIVATION_ENTRY_REQUIRED', positive=True)
                shadow_stop = _number(before['initial_stop_loss'], 'POSITIVE_SHADOW_INITIAL_STOP_REQUIRED', positive=True)
                _equal(_number(before['initial_risk_distance'], 'POSITIVE_SHADOW_INITIAL_PRICE_R_REQUIRED', positive=True),
                       abs(shadow_entry - shadow_stop), 'SHADOW_INITIAL_PRICE_R_GEOMETRY_REQUIRED')
            counts['shadow_counterfactual_event_count'] += 1
            continue
        counts['accepted_event_count'] += 1
        if classification == 'tightened':
            counts['tightened_stop_events'] += 1
        elif classification == 'pre_activation_held':
            counts['preactivation_held_events'] += 1
        elif classification == 'pre_entry_not_eligible':
            counts['pre_entry_not_eligible_events'] += 1
        elif classification == 'unavailable_original_input':
            counts['unavailable_original_input_events'] += 1
        elif classification == 'disabled_or_missing_atr':
            counts['disabled_or_missing_atr_events'] += 1
        entry, initial = _number(before['entry_price'], 'POSITIVE_ORIGINAL_ENTRY_REQUIRED', positive=True), \
                         _number(before['initial_stop_loss'], 'POSITIVE_ORIGINAL_INITIAL_STOP_REQUIRED', positive=True)
        risk = _number(before.get('initial_risk_distance', abs(entry - initial)), 'POSITIVE_ORIGINAL_PRICE_R_REQUIRED', positive=True)
        _equal(risk, abs(entry - initial), 'IMMUTABLE_INITIAL_PRICE_R_GEOMETRY_REQUIRED')
        _need((before['direction'] == 'BUY' and initial < entry) or (before['direction'] == 'SELL' and initial > entry),
              'CORRECT_INITIAL_INVALIDATION_SIDE_REQUIRED')
        if raw_entries is not None:
            _need(key in raw_entries, 'ORIGINAL_ACCEPTED_ENTRY_LINK_REQUIRED')
            for field in ('entry_price', 'initial_stop_loss'):
                _equal(before[field], float(raw_entries[key][field]), 'EXACT_RAW_ACCEPTED_ENTRY_GEOMETRY_REQUIRED:' + field)
        else:
            _need(key in public, 'RAW_ACCEPTED_ENTRY_REQUIRED_FOR_OPEN_POSITION')
            _equal(public[key]['entry_price'], round(entry, 2), 'ROUNDED_PUBLIC_ENTRY_LINK_REQUIRED')
            _equal(public[key]['initial_risk_distance'], round(risk, 8), 'ROUNDED_PUBLIC_INITIAL_PRICE_R_REQUIRED')
        context_identity = json.dumps({'context': event['context'], 'missing_fields': event['context_missing_fields']},
                                      sort_keys=True, separators=(',', ':'))
        context_counts[context_identity] = context_counts.get(context_identity, 0) + 1
    _need(set(public) <= set(groups), 'ALL_CLOSED_ORIGINAL_POSITIONS_OBSERVED_REQUIRED')
    open_keys = set(groups) - set(public)
    _need(len(open_keys) == maturity['open_position_count'] and (not open_keys or raw_entries is not None),
          'EXACT_ORIGINAL_OPEN_POSITION_LINK_REQUIRED')
    if raw_entries is not None:
        _need(set(raw_entries) == set(groups), 'COMPLETE_RAW_ACCEPTED_POSITION_ROSTER_REQUIRED')
    ordered = sorted(groups)
    previous_exit = None
    for key in ordered:
        entry_at = _time(key[0])
        _need(previous_exit is None or entry_at >= previous_exit, 'ONE_POSITION_AT_A_TIME_REQUIRED')
        if key in public:
            trade = public[key]
            _equal(trade['stop_loss'], round(groups[key]['after']['stop_loss'], 2), 'FINAL_PUBLIC_STOP_ROUNDING_REQUIRED')
            previous_exit = _time(trade['exit_time'])
            _need(previous_exit >= entry_at and _time(groups[key]['closed_row']['time']) < previous_exit,
                  'CLOSED_ROW_MUST_PRECEDE_ACTUAL_EXIT_BAR_REQUIRED')
        else:
            _need(key == ordered[-1], 'ONLY_FINAL_POSITION_MAY_REMAIN_OPEN')
    return {'protocol': 'offline_curator_trailing_activation_verified_v1', **_CEILING, **counts,
            'event_count': len(events), 'accepted_position_count': len(groups),
            'observed_native_call': bool(events),
            'requested_trailing_atr_multiplier': multiplier, 'requested_activation_price_r': activation,
            'armed_positions': sum(bool(event['after'].get('trailing_activation_armed', False)) for event in groups.values()),
            'open_position_count': len(open_keys), 'witness_sha256': witness['witness_sha256'],
            'framed_event_sha256': witness['framed_event_sha256'],
            'actual_context_event_counts': [{'context': json.loads(key), 'event_count': count}
                                            for key, count in sorted(context_counts.items())],
            'raw_accepted_entry_links_verified': raw_entries is not None,
            'broker_or_intrabar_path_independently_observed': False,
            'terminal_marked_value_unknown': bool(open_keys)}
