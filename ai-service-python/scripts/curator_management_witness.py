"""Offline, full-close management evidence; never execution or skill authority.

Only the existing decision-identity producer is observed. Its arguments are
copied before the original function runs; its return value is never modified.
The verifier independently rebuilds its two filled execution stage digests.
"""
from __future__ import annotations

from contextlib import contextmanager
import copy
from datetime import datetime
import hashlib
import importlib
import io
import json
import math
from pathlib import Path
import threading

import numpy as np
import pandas as pd

PROTOCOL = 'offline_curator_full_close_witness_v1'
MAX_SCOPE_ROWS = 20000
_CAPTURE_LOCK = threading.Lock()
_CENSOR_MARKERS = ('end_of_replay', 'end_of_data', 'forced_close', 'forced_end', 'censored', 'unrealized')
_SETUP_VARIANT = 'closed_setup_2r_v1'
_SETUP_PROTOCOL = 'offline_curator_closed_setup_geometry_v1'
_SETUP_FIELDS = ('entry_setup_protocol', 'entry_setup_direction', 'entry_setup_invalidation_price',
                 'entry_setup_available_at', 'entry_setup_source_time')
_PUBLIC_CLOSED_FIELDS = (
    'direction', 'entry_price', 'exit_price', 'stop_loss', 'take_profit', 'profit_percent',
    'gross_profit_percent', 'execution_cost_percent', 'market_profit_percent',
    'position_size_multiple', 'risk_budget_percent', 'result', 'exit_reason', 'realized_r_multiple',
)


def _need(ok, reason):
    if not ok:
        raise ValueError(reason)


def _semantic(value):
    """Independent stage_semantics_v2 scalar codec; finite numbers are strings."""
    if value is None or value is pd.NA or value is pd.NaT:
        return None
    if isinstance(value, (bool, np.bool_)):
        return bool(value)
    if isinstance(value, (int, float, np.number)):
        number = float(value)
        return format(number, '.17g') if math.isfinite(number) else None
    if isinstance(value, (datetime, pd.Timestamp)):
        stamp = pd.Timestamp(value)
        return (stamp.tz_localize('UTC') if stamp.tzinfo is None else stamp.tz_convert('UTC')).isoformat()
    return str(value)


def _json_safe(value):
    if isinstance(value, dict):
        return {str(key): _json_safe(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_safe(item) for item in value]
    if isinstance(value, (datetime, pd.Timestamp)):
        return _semantic(value)
    if isinstance(value, (bool, np.bool_)):
        return bool(value)
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, (float, np.floating)):
        return float(value) if math.isfinite(float(value)) else None
    return value


def _encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False, allow_nan=False).encode()


def _hash(value):
    return hashlib.sha256(_encoded(value)).hexdigest()


def _framed(tokens):
    digest = hashlib.sha256()
    for token in tokens:
        raw = _encoded(token)
        digest.update(len(raw).to_bytes(8, 'big'))
        digest.update(raw)
    return digest.hexdigest()


def _number(value, reason, *, positive=False):
    _need(not isinstance(value, bool) and value is not None, reason)
    result = float(value)
    _need(math.isfinite(result) and (not positive or result > 0), reason)
    return result


def _same(actual, expected, reason, *, tolerance=1e-9):
    value = _number(actual, reason)
    _need(math.isclose(value, expected, rel_tol=1e-10, abs_tol=tolerance), reason)


def _stamp(value):
    stamp = pd.Timestamp(value)
    _need(not pd.isna(stamp), 'MANAGEMENT_ORIGINAL_TIME_REQUIRED')
    return stamp.tz_localize('UTC') if stamp.tzinfo is None else stamp.tz_convert('UTC')


def _body(response):
    return response.model_dump() if hasattr(response, 'model_dump') else response


class _Observer:
    def __init__(self):
        self._capture = None

    def snapshot(self):
        _need(self._capture is not None and 'original_receipt' in self._capture,
              'ONE_COMPLETED_ORIGINAL_IDENTITY_CALL_REQUIRED')
        return copy.deepcopy(self._capture)


@contextmanager
def capture_management_witness():
    """Capture one ordinary replay in one isolated thread; no extra replay runs."""
    _need(_CAPTURE_LOCK.acquire(blocking=False), 'NESTED_OR_CONCURRENT_MANAGEMENT_CAPTURE_REFUSED')
    owner = None
    original = None
    wrapper = None
    observer = _Observer()
    thread = threading.get_ident()
    try:
        owner = importlib.import_module('app.services.backtester')
        original = owner._decision_identity_receipt
        _need(not getattr(original, '_offline_management_capture_active', False),
              'NESTED_OR_CONCURRENT_MANAGEMENT_CAPTURE_REFUSED')

        def observe(payload, frame, trades, entry_indices, entry_events=None, closed_events=None):
            _need(threading.get_ident() == thread, 'OFFLINE_SINGLE_THREAD_CAPTURE_REQUIRED')
            _need(observer._capture is None, 'ONLY_ONE_ORIGINAL_REPLAY_PER_CAPTURE_REQUIRED')
            _need(not payload.portfolio_members and not payload.specialist_council_contract,
                  'ORDINARY_SINGLE_POSITION_WITNESS_ONLY')
            scope = frame.iloc[199:max(199, len(frame) - 1)]
            _need(0 < len(scope) <= MAX_SCOPE_ROWS and len(frame) <= MAX_SCOPE_ROWS + 200,
                  'BOUNDED_ORIGINAL_SCOPE_REQUIRED')
            _need(entry_events is not None and closed_events is not None, 'ORIGINAL_RAW_EXECUTION_EVENTS_REQUIRED')
            copied_entries = copy.deepcopy(entry_events)
            copied_closed = copy.deepcopy(closed_events)
            copied_trades = copy.deepcopy([trade.model_dump() for trade in trades])
            duration = pd.Timedelta(minutes=owner._timeframe_duration_minutes(payload.timeframe))
            observations = {}
            for index in entry_indices:
                _need(type(index) is int and index in frame.index, 'ORIGINAL_INTEGER_ENTRY_INDEX_REQUIRED')
                location = frame.index.get_loc(index)
                _need(type(location) is int and location + 1 < len(frame), 'ORIGINAL_NEXT_OPEN_ROW_REQUIRED')
                signal, execution = frame.iloc[location], frame.iloc[location + 1]
                observations[str(index)] = {
                    'signal_time': _semantic(signal['time']), 'execution_time': _semantic(execution['time']),
                    'execution_open': float(execution['open']),
                    'signal_management_atr': _json_safe(signal.get('_management_atr')),
                }
            capture = {
                'protocol': PROTOCOL, 'research_only': True, 'private_offline': True,
                'own_agent_outcome': False, 'independent_validation': False,
                'economic_authority': False, 'paper_authority': False, 'live_authority': False,
                'request': {'symbol': payload.symbol, 'timeframe': payload.timeframe,
                    'initial_balance': payload.initial_balance, 'risk_per_trade': payload.risk_per_trade,
                    'execution': copy.deepcopy(payload.execution.model_dump()),
                    'parameters': copy.deepcopy(payload.parameters), 'duration_seconds': duration.total_seconds()},
                'scope_rows': [{'index': int(index), 'time': _semantic(value)} for index, value in scope['time'].items()],
                'entry_indices': sorted(int(index) for index in entry_indices),
                'entry_events': _json_safe(copied_entries), 'closed_events': _json_safe(copied_closed),
                'public_trades': _json_safe(copied_trades), 'entry_observations': observations,
                'native_ratchet_observation_available': False,
            }
            if payload.parameters.get('intraday_exit_variant') == _SETUP_VARIANT:
                # Only original strategy-produced rows are observed. The
                # independent verifier must separately reopen the exact CSV.
                capture['closed_setup_geometry'] = {'protocol': _SETUP_PROTOCOL,
                    'request': payload.model_dump(mode='json'),
                    'entry_observations': {str(index): {
                        field: _json_safe(frame.loc[index].get(field)) for field in _SETUP_FIELDS}
                        for index in entry_indices}}
            observer._capture = _json_safe(capture)
            receipt = original(payload, frame, trades, entry_indices, entry_events, closed_events)
            observer._capture['original_receipt'] = copy.deepcopy(receipt)
            return receipt

        wrapper = observe
        wrapper._offline_management_capture_active = True
        owner._decision_identity_receipt = wrapper
        yield observer
    finally:
        if owner is not None and original is not None:
            owner._decision_identity_receipt = original
        _CAPTURE_LOCK.release()


def _stage_identities(witness):
    request = witness['request']
    symbol = str(request['symbol']).replace('/', '').replace('_', '').replace('-', '').upper()
    timeframe = str(request['timeframe']).upper()
    duration = pd.Timedelta(seconds=_number(request['duration_seconds'], 'VALID_DURATION_REQUIRED', positive=True))
    scope = witness['scope_rows']
    _need(isinstance(scope, list) and 0 < len(scope) <= MAX_SCOPE_ROWS, 'BOUNDED_CAPTURED_SCOPE_REQUIRED')
    mapping = {}
    previous = None
    for row in scope:
        index, timestamp = row['index'], _stamp(row['time'])
        _need(type(index) is int and index not in mapping and (previous is None or timestamp > previous),
              'UNIQUE_ORDERED_SCOPE_INDEX_TIME_REQUIRED')
        decision_id = hashlib.sha256(json.dumps([symbol, timeframe, (timestamp + duration).isoformat()],
                                                separators=(',', ':')).encode()).hexdigest()
        mapping[index] = (timestamp, decision_id)
        previous = timestamp
    entries = witness['entry_events']
    indices = witness['entry_indices']
    _need(indices == sorted(set(indices)) and set(entries) == {str(index) for index in indices},
          'EXACT_ORIGINAL_ENTRY_EVENT_ROSTER_REQUIRED')
    tokens, links, by_signal = [], {}, {}
    for index in mapping:
        if index not in indices:
            continue
        event = entries[str(index)]
        _need(event['direction'] in {'BUY', 'SELL'}, 'VALID_ENTRY_DIRECTION_REQUIRED')
        for key in ('entry_price', 'initial_stop_loss', 'position_size_multiple', 'risk_budget_percent'):
            _number(event[key], 'FINITE_POSITIVE_ORIGINAL_ENTRY_REQUIRED:' + key, positive=True)
        token = {'decision_id': mapping[index][1], 'entry': event}
        tokens.append(token)
        signal = _semantic(mapping[index][0])
        _need(signal not in links, 'UNIQUE_ENTRY_SIGNAL_LINK_REQUIRED')
        links[signal] = _hash(token)
        by_signal[signal] = (index, event)
    _need(len(tokens) == len(indices), 'ALL_ENTRIES_MUST_BELONG_TO_ORIGINAL_SCOPE')
    closed = witness['closed_events']
    trades = witness['public_trades']
    _need(isinstance(closed, list) and len(closed) == len(trades) <= len(indices), 'EXACT_ORIGINAL_CLOSED_EVENT_ROSTER_REQUIRED')
    close_tokens = []
    for trade, raw in zip(trades, closed):
        signal = _semantic(_stamp(trade['signal_time']))
        _need(signal in links, 'ORIGINAL_CLOSED_ENTRY_LINK_REQUIRED')
        event = {key: _semantic(trade.get(key)) for key in _PUBLIC_CLOSED_FIELDS}
        for key in ('signal_time', 'entry_time', 'exit_time'):
            event[key] = _semantic(_stamp(trade[key]))
        event['entry_link_hash'] = links[signal]
        event['execution_outcome'] = {key: _semantic(value) for key, value in raw.items()}
        close_tokens.append(event)
    return ({'entry': {'status': 'observed', 'event_count': len(tokens), 'event_hash': _framed(tokens),
                       'semantic_schema': 'filled_entry_v2'},
             'closed_trade': {'status': 'observed', 'event_count': len(close_tokens), 'event_hash': _framed(close_tokens),
                              'semantic_schema': 'entry_linked_close_v2'}}, by_signal)


def _verify_closed_setup_geometry(body, witness, entries, request, csv_path):
    """Reprepare signals, not trades; never call the execution geometry helper."""
    enabled = witness['request']['parameters'].get('intraday_exit_variant') == _SETUP_VARIANT
    declared = _body(request) if request is not None else None
    requested = isinstance(declared, dict) and declared.get('parameters', {}).get('intraday_exit_variant') == _SETUP_VARIANT
    if not enabled and not requested and 'closed_setup_geometry' not in witness:
        return None
    from app.schemas import SimpleBacktestRequest
    from app.services.backtester import prepare_signal_snapshot
    from app.strategies.laboratory import apply_trend_specialist, apply_trend_retest_strategy
    from app.strategies.registry import get_strategy

    _need(enabled and requested and csv_path is not None, 'STRUCTURAL_ORIGINAL_REQUEST_AND_CSV_REQUIRED')
    payload = SimpleBacktestRequest.model_validate(declared)
    captured = witness.get('closed_setup_geometry', {})
    _need(captured.get('protocol') == _SETUP_PROTOCOL
          and captured.get('request') == payload.model_dump(mode='json'), 'STRUCTURAL_EXACT_ORIGINAL_REQUEST_REQUIRED')
    actual_request = payload.model_dump(mode='json')
    _need(all(witness['request'].get(field) == actual_request[field] for field in (
        'symbol', 'timeframe', 'initial_balance', 'risk_per_trade', 'execution', 'parameters')),
        'STRUCTURAL_PHYSICAL_REQUEST_PARITY_REQUIRED')
    seconds = {'M1': 60, 'M5': 300, 'M15': 900, 'M30': 1800, 'H1': 3600,
               'H4': 14400, 'D1': 86400}.get(payload.timeframe)
    _need(seconds is not None and witness['request']['duration_seconds'] == seconds,
          'STRUCTURAL_ACTUAL_EXECUTION_DURATION_REQUIRED')
    params = payload.parameters
    functions = {
        ('trend_v1', 'trend', 'source_ema_pretrend_pullback_v1'): (apply_trend_specialist, 3),
        ('trend_retest_v1', 'trend_retest', 'source_frozen_retest_v1'): (apply_trend_retest_strategy, 1),
    }
    binding = (payload.strategy, payload.base_strategy, params.get('entry_topology_variant'))
    _need(binding in functions and get_strategy(payload.strategy, payload.base_strategy) is functions[binding][0]
          and params.get('ema_fast') == 20 and params.get('ema_slow') == 50
          and (binding[0] != 'trend_retest_v1' or params.get('lookback', 20) == 20),
          'STRUCTURAL_ACTUAL_SOURCE_ENTRY_RUNTIME_REQUIRED')
    _need(not payload.candles and payload.dataset_path is not None and payload.dataset_tail_rows is None
          and not payload.strategies and not payload.portfolio_members and not payload.specialist_council_contract
          and not payload.composition_runtime_contract and payload.signal_delay_candles == 0
          and float(params.get('trailing_atr_multiplier', 0)) == 0
          and float(params.get('trailing_activation_r', 0)) == 0
          and float(params.get('partial_take_profit_fraction', 0)) == 0,
          'STRUCTURAL_UNDELAYED_ORDINARY_FIXED_FULL_CLOSE_REQUIRED')
    _need(0 < payload.risk_per_trade <= 1 and 0 < payload.execution.max_leverage <= 5,
          'STRUCTURAL_UNCHANGED_HARD_RISK_AND_LEVERAGE_REQUIRED')
    path = Path(csv_path).resolve(strict=True)
    _need(path == Path(payload.dataset_path).resolve(strict=True), 'STRUCTURAL_EXACT_ORIGINAL_CSV_PATH_REQUIRED')
    contents = path.read_bytes()
    csv_sha = hashlib.sha256(contents).hexdigest()
    _need(csv_sha == payload.replay_dataset_hash, 'STRUCTURAL_ORIGINAL_CSV_SHA_REQUIRED')
    raw_frame = pd.read_csv(io.BytesIO(contents), low_memory=False)
    _need(200 < len(raw_frame) <= MAX_SCOPE_ROWS + 200
          and not any(field in raw_frame for field in _SETUP_FIELDS), 'STRUCTURAL_UNFORGED_BOUNDED_SOURCE_CSV_REQUIRED')
    snapshot = prepare_signal_snapshot(payload, raw_frame)
    frame = snapshot.frame
    _need(len(frame) == len(raw_frame) and frame.index.is_unique,
          'STRUCTURAL_UNCHANGED_ORIGINAL_SOURCE_ROWS_REQUIRED')
    scope = [{'index': int(index), 'time': _semantic(value)}
             for index, value in frame.iloc[199:max(199, len(frame) - 1)]['time'].items()]
    _need(scope == witness['scope_rows'], 'STRUCTURAL_ORIGINAL_SCOPE_ROWS_REQUIRED')
    captured_rows = captured.get('entry_observations')
    _need(isinstance(captured_rows, dict) and set(captured_rows) == {str(index) for index, _ in entries.values()},
          'STRUCTURAL_EXACT_ENTRY_PROOF_ROSTER_REQUIRED')
    trace = body.get('decision_trace')
    quality = body.get('data_quality', {}).get('decision_trace', {})
    _need(isinstance(trace, list) and quality.get('complete') is True
          and quality.get('event_count') == len(trace), 'STRUCTURAL_COMPLETE_ORIGINAL_TRACE_REQUIRED')
    accepted = {}
    for event in trace:
        if event.get('event_type') != 'signal_evaluation' or event.get('accepted') is not True \
                or event.get('action') not in ('BUY', 'SELL'):
            continue
        index = event.get('candle_index')
        _need(type(index) is int and index not in accepted, 'STRUCTURAL_UNIQUE_FILLED_TRACE_ENTRY_REQUIRED')
        accepted[index] = event
    _need(set(accepted) == {index + 1 for index, _ in entries.values()}, 'STRUCTURAL_FILLED_TRACE_ROSTER_REQUIRED')
    point = _number(payload.execution.point_size, 'STRUCTURAL_POSITIVE_POINT_REQUIRED', positive=True)
    cost = (payload.execution.spread_points / 2 + payload.execution.slippage_points) * point
    duration = pd.Timedelta(seconds=witness['request']['duration_seconds'])
    proofs = {}
    for signal, (index, entry) in entries.items():
        location = frame.index.get_loc(index)
        _need(type(location) is int and location >= functions[binding][1] - 1 and location + 1 < len(frame),
              'STRUCTURAL_ORIGINAL_ENTRY_ROW_REQUIRED')
        row, execution = frame.iloc[location], frame.iloc[location + 1]
        direction = entry['direction']
        _need(row.get('signal') == direction, 'STRUCTURAL_ORIGINAL_ADMITTED_DIRECTION_REQUIRED')
        original = {field: _json_safe(row.get(field)) for field in _SETUP_FIELDS}
        _need(captured_rows[str(index)] == original and original['entry_setup_protocol'] == _SETUP_VARIANT
              and original['entry_setup_direction'] == direction, 'STRUCTURAL_SOURCE_ROW_PROOF_REQUIRED')
        source_time = _stamp(row['time'])
        _need(_stamp(original['entry_setup_source_time']) == source_time == _stamp(signal)
              and _stamp(original['entry_setup_available_at']) == source_time + duration == _stamp(execution['time'])
              and _stamp(entry['entry_time']) == _stamp(execution['time']), 'STRUCTURAL_CLOSED_ASOF_NEXT_OPEN_REQUIRED')
        observed = witness['entry_observations'][str(index)]
        _need(_stamp(observed['signal_time']) == source_time
              and _stamp(observed['execution_time']) == _stamp(execution['time']), 'STRUCTURAL_OBSERVED_SOURCE_CLOCK_REQUIRED')
        _same(observed['execution_open'], float(execution['open']), 'STRUCTURAL_ORIGINAL_NEXT_OPEN_REQUIRED')
        window = frame.iloc[location - functions[binding][1] + 1:location + 1]
        anchor = float(window['low'].min() if direction == 'BUY' else window['high'].max())
        _same(original['entry_setup_invalidation_price'], anchor, 'STRUCTURAL_EXACT_CLOSED_PRICE_EXTREME_REQUIRED')
        sign = 1 if direction == 'BUY' else -1
        stop = anchor - sign * point
        fill = float(execution['open']) + sign * cost
        _need(stop > 0 and sign * (fill - stop) > 0, 'STRUCTURAL_ADVERSE_FIXED_INVALIDATION_REQUIRED')
        target = fill + sign * 2 * abs(fill - stop)
        _need(target > 0, 'STRUCTURAL_POSITIVE_EXECUTABLE_TARGET_REQUIRED')
        _same(entry['entry_price'], fill, 'STRUCTURAL_ACTUAL_EXECUTABLE_FILL_REQUIRED')
        _same(entry['initial_stop_loss'], stop, 'STRUCTURAL_ONE_POINT_BUFFERED_STOP_REQUIRED')
        event = accepted[index + 1]
        state, features = event.get('state', {}), event.get('features', {})
        _need(event['action'] == direction and state.get('position_open') is True
              and _stamp(event['candle_time']) == _stamp(execution['time']), 'STRUCTURAL_ORIGINAL_FILLED_TRACE_REQUIRED')
        for field in _SETUP_FIELDS:
            _need(features.get(field) == original[field], 'STRUCTURAL_ORIGINAL_TRACE_SOURCE_PROOF_REQUIRED:' + field)
        for field, expected in (('entry_price', fill), ('stop_loss', stop), ('take_profit', target),
                                ('position_size_multiple', float(entry['position_size_multiple']))):
            _same(state.get(field), expected, 'STRUCTURAL_INITIAL_EXECUTION_GEOMETRY_REQUIRED:' + field)
        proofs[signal] = {'signal_time': source_time.isoformat(), 'direction': direction,
            'source_invalidation_price': anchor, 'buffered_stop_price': stop, 'actual_entry_price': fill,
            'initial_target_price': target, 'nominal_price_r_multiple': 2.0,
            'source_window_closed_rows': functions[binding][1], 'available_at': (source_time + duration).isoformat()}
    for trade in witness['public_trades']:
        proof = proofs[_semantic(_stamp(trade['signal_time']))]
        _same(trade['take_profit'], round(proof['initial_target_price'], 2), 'STRUCTURAL_PUBLIC_FIXED_TARGET_REQUIRED')
        _same(trade['stop_loss'], round(proof['buffered_stop_price'], 2), 'STRUCTURAL_PUBLIC_FIXED_STOP_REQUIRED')
    return {'protocol': _SETUP_PROTOCOL, 'source_csv_sha256': csv_sha, 'accepted_entry_count': len(proofs),
            'actual_entry_runtime': binding[0], 'entry_topology_variant': binding[2], 'per_entry': proofs,
            'nominal_price_2r_not_cost_adjusted_net_2r': True, 'own_agent_outcome': False,
            'independent_validation': False, 'economic_authority': False}


def verify_management_witness(response, witness, *, request=None, csv_path=None):
    """Verify original full closes and closed-cash accounting without producer calls."""
    body = _body(response)
    _need(witness.get('protocol') == PROTOCOL and witness.get('research_only') is True
          and witness.get('private_offline') is True
          and all(witness.get(flag) is False for flag in ('own_agent_outcome', 'independent_validation',
                    'economic_authority', 'paper_authority', 'live_authority')), 'OFFLINE_RESEARCH_ONLY_WITNESS_REQUIRED')
    _need(body['trade_ledger'] == witness['public_trades'] and body['total_trades'] == len(witness['closed_events']),
          'EXACT_ORIGINAL_PUBLIC_TRADE_VALUES_REQUIRED')
    receipt = body['data_quality']['decision_identity_receipt']
    _need(receipt == witness['original_receipt'] and receipt.get('protocol') == 'replay_decision_identity_v2',
          'EXACT_ORIGINAL_DECISION_RECEIPT_REQUIRED')
    _need(_hash({key: value for key, value in receipt.items() if key != 'receipt_hash'}) == receipt['receipt_hash'],
          'ORIGINAL_DECISION_RECEIPT_SHA_REQUIRED')
    stages, entries = _stage_identities(witness)
    for name, stage in stages.items():
        _need(receipt['stage_identities'][name] == stage, 'INDEPENDENT_ORIGINAL_STAGE_RECONSTRUCTION_REQUIRED:' + name)
    setup_geometry = _verify_closed_setup_geometry(body, witness, entries, request, csv_path)
    request = witness['request']
    execution = request['execution']
    _need(body['execution_assumptions'] == execution and body['parameters'] == request['parameters'],
          'EXACT_ORIGINAL_PHYSICAL_EXECUTION_REQUIRED')
    initial = _number(request['initial_balance'], 'POSITIVE_ORIGINAL_ACCOUNT_REQUIRED', positive=True)
    _same(body['initial_balance'], initial, 'INITIAL_ACCOUNT_PARITY_REQUIRED')
    risk_budget = _number(request['risk_per_trade'], 'POSITIVE_HARD_RISK_REQUIRED', positive=True)
    spread_slippage = (_number(execution['spread_points'], 'FINITE_MODELED_SPREAD_REQUIRED') / 2
                       + _number(execution['slippage_points'], 'FINITE_MODELED_SLIPPAGE_REQUIRED')) \
        * _number(execution['point_size'], 'POSITIVE_POINT_SIZE_REQUIRED', positive=True)
    commission = _number(execution['commission_percent'], 'FINITE_COMMISSION_REQUIRED')
    carry = _number(execution['swap_per_day_percent'], 'FINITE_CARRY_REQUIRED')
    _need(spread_slippage >= 0 and commission >= 0 and carry >= 0, 'NONNEGATIVE_DECLARED_MODEL_COSTS_REQUIRED')
    balance, peak, drawdown, explicit_cash, commission_cash, carry_cash = initial, initial, 0.0, 0.0, 0.0, 0.0
    public_close_curve = [round(initial, 2)]
    previous_exit, used_entries, metrics = None, set(), []
    for trade, raw in zip(witness['public_trades'], witness['closed_events']):
        signal = _semantic(_stamp(trade['signal_time']))
        _need(signal not in used_entries, 'ONE_CLOSE_PER_ORIGINAL_POSITION_REQUIRED')
        used_entries.add(signal)
        index, entry = entries[signal]
        observations = witness['entry_observations'][str(index)]
        direction = entry['direction']
        _need(raw['direction'] == trade['direction'] == direction, 'ORIGINAL_CLOSE_DIRECTION_PARITY_REQUIRED')
        entered, exited = _stamp(entry['entry_time']), _stamp(raw['exit_time'])
        _need(entered >= _stamp(signal) + pd.Timedelta(seconds=request['duration_seconds'])
              and exited >= entered and (previous_exit is None or entered >= previous_exit),
              'ORDERED_SINGLE_POSITION_EXECUTION_REQUIRED')
        _need(_stamp(raw['entry_time']) == entered == _stamp(trade['entry_time'])
              and exited == _stamp(trade['exit_time'])
              and entered == _stamp(observations['execution_time'])
              and _stamp(observations['signal_time']) == _stamp(signal), 'ORIGINAL_SIGNAL_FILL_CLOSE_CLOCK_LINK_REQUIRED')
        previous_exit = exited
        fraction = raw.get('partial_fraction')
        _need(fraction is None or _number(fraction, 'VALID_FULL_CLOSE_FRACTION_REQUIRED') == 0,
              'PARTIAL_EXIT_NOT_SUPPORTED_BY_FULL_CLOSE_WITNESS')
        _need(raw.get('partial_exit_price') is None, 'PARTIAL_FILL_NOT_SUPPORTED_BY_FULL_CLOSE_WITNESS')
        price = _number(raw['entry_price'], 'POSITIVE_RAW_ENTRY_PRICE_REQUIRED', positive=True)
        exit_price = _number(raw['exit_price'], 'POSITIVE_RAW_EXIT_PRICE_REQUIRED', positive=True)
        stop = _number(entry['initial_stop_loss'], 'POSITIVE_INITIAL_INVALIDATION_REQUIRED', positive=True)
        size = _number(raw['position_size_multiple'], 'POSITIVE_EXECUTED_POSITION_SIZE_REQUIRED', positive=True)
        _same(entry['entry_price'], price, 'ENTRY_LINK_EXECUTABLE_PRICE_PARITY_REQUIRED')
        _same(entry['position_size_multiple'], size, 'ENTRY_LINK_POSITION_SIZE_PARITY_REQUIRED')
        _same(entry['risk_budget_percent'], risk_budget, 'ENTRY_LINK_HARD_RISK_PARITY_REQUIRED')
        _same(raw['risk_budget_percent'], risk_budget, 'RAW_HARD_RISK_PARITY_REQUIRED')
        _same(trade['risk_budget_percent'], risk_budget, 'PUBLIC_HARD_RISK_PARITY_REQUIRED')
        _need(size <= float(execution['max_leverage']) + 1e-10
              and ((direction == 'BUY' and stop < price) or (direction == 'SELL' and stop > price)),
              'INITIAL_INVALIDATION_AND_HARD_LEVERAGE_REQUIRED')
        market_open = _number(observations['execution_open'], 'POSITIVE_ORIGINAL_NEXT_OPEN_REQUIRED', positive=True)
        _same(price, market_open + spread_slippage * (1 if direction == 'BUY' else -1),
              'MODELED_NEXT_OPEN_ENTRY_PRICE_REQUIRED')
        risk_distance = abs(price - stop)
        stop_execution = stop - spread_slippage if direction == 'BUY' else stop + spread_slippage
        stop_return = ((price - stop_execution) if direction == 'BUY' else (stop_execution - price)) / price * 100
        initial_risk = max(0.0, stop_return + commission) * size
        _need(0 < initial_risk <= risk_budget + 1e-7, 'EXECUTED_INITIAL_RISK_WITHIN_DECLARED_BUDGET_REQUIRED')
        _same(raw['initial_risk_percent'], initial_risk, 'RAW_INITIAL_EXECUTABLE_RISK_PARITY_REQUIRED')
        _same(trade['initial_risk_distance'], round(risk_distance, 8), 'PUBLIC_INITIAL_RISK_DISTANCE_REQUIRED')
        _same(trade['initial_risk_percent'], round(initial_risk, 8), 'PUBLIC_INITIAL_EXECUTABLE_RISK_REQUIRED')
        holding_days = (exited - entered).total_seconds() / 86400
        market_return = ((exit_price - price) if direction == 'BUY' else (price - exit_price)) / price * 100
        gross = market_return * size
        explicit = (commission + carry * holding_days) * size
        net = gross - explicit
        for field, value in (('market_profit_percent', market_return), ('gross_profit_percent', gross),
                             ('execution_cost_percent', explicit), ('profit_percent', net)):
            _same(raw[field], value, 'INDEPENDENT_FULL_CLOSE_COST_MATH_REQUIRED:' + field)
        realized_r = net / initial_risk
        _same(raw['realized_r_multiple'], realized_r, 'RAW_REALIZED_RISK_RETURN_REQUIRED')
        for field, value, digits in (('entry_price', price, 2), ('exit_price', exit_price, 2),
                ('market_profit_percent', market_return, 5), ('gross_profit_percent', gross, 3),
                ('execution_cost_percent', explicit, 5), ('profit_percent', net, 3),
                ('position_size_multiple', size, 5), ('realized_r_multiple', realized_r, 6)):
            _same(trade[field], round(value, digits), 'ORIGINAL_ROUNDED_PUBLIC_FIELD_REQUIRED:' + field)
        reason = str(raw['exit_reason'] or '')
        _need(reason == trade['exit_reason'] and reason and not any(marker in reason.lower() for marker in _CENSOR_MARKERS),
              'NATURAL_MATURE_FULL_CLOSE_REASON_REQUIRED')
        _need(trade['result'] == ('WIN' if net > 0 else 'LOSS'), 'PUBLIC_NET_RESULT_PARITY_REQUIRED')
        before = balance
        cash = before * net / 100
        commission_trade_cash = before * commission * size / 100
        carry_trade_cash = before * carry * holding_days * size / 100
        balance += cash
        commission_cash += commission_trade_cash
        carry_cash += carry_trade_cash
        explicit_cash += commission_trade_cash + carry_trade_cash
        _same(trade['balance'], round(balance, 2), 'EXACT_COMPOUNDED_CASH_VS_ROUNDED_BALANCE_REQUIRED')
        public_close_curve.append(round(balance, 2))
        peak = max(peak, balance)
        drawdown = max(drawdown, (peak - balance) / peak * 100)
        for field in ('mfe_r_before_exit_bar', 'mae_r_before_exit_bar', 'mfe_r', 'mae_r'):
            value = trade.get(field)
            if value is not None:
                _need(_number(value, 'FINITE_PRODUCER_EXCURSION_BOUND_REQUIRED') >= 0, 'NONNEGATIVE_PRODUCER_EXCURSION_BOUND_REQUIRED')
        for before_field, full_field in (('mfe_r_before_exit_bar', 'mfe_r'), ('mae_r_before_exit_bar', 'mae_r')):
            if trade.get(before_field) is not None and trade.get(full_field) is not None:
                _need(trade[before_field] <= trade[full_field] + 0.000001, 'BEFORE_EXIT_BOUND_CANNOT_EXCEED_FULL_BAR_BOUND')
        metrics.append({'entry_time': entered.isoformat(), 'exit_time': exited.isoformat(), 'direction': direction,
                        'holding_seconds': (exited - entered).total_seconds(), 'exit_reason': reason,
                        'exact_closed_pnl_cash': cash, 'exact_net_profit_percent': net,
                        'commission_cash': commission_trade_cash, 'carry_cash': carry_trade_cash,
                        'initial_risk_percent': initial_risk, 'initial_risk_distance': risk_distance,
                        'realized_r_multiple': realized_r,
                        'mfe_r_before_exit_bar_bound': trade.get('mfe_r_before_exit_bar'),
                        'mae_r_before_exit_bar_bound': trade.get('mae_r_before_exit_bar'),
                        'native_signal_management_atr': observations['signal_management_atr']})
    open_entries = [value for signal, value in entries.items() if signal not in used_entries]
    _need(len(open_entries) in (0, 1), 'SINGLE_OR_ZERO_ORIGINAL_OPEN_POSITION_REQUIRED')
    if open_entries and previous_exit is not None:
        _need(_stamp(open_entries[0][1]['entry_time']) >= previous_exit, 'OPEN_POSITION_CANNOT_OVERLAP_PRIOR_CLOSED_POSITION')
    for index, entry in open_entries:
        observations = witness['entry_observations'][str(index)]
        direction = entry['direction']
        price = _number(entry['entry_price'], 'POSITIVE_OPEN_ENTRY_PRICE_REQUIRED', positive=True)
        stop = _number(entry['initial_stop_loss'], 'POSITIVE_OPEN_INITIAL_STOP_REQUIRED', positive=True)
        size = _number(entry['position_size_multiple'], 'POSITIVE_OPEN_POSITION_SIZE_REQUIRED', positive=True)
        _same(entry['risk_budget_percent'], risk_budget, 'OPEN_ENTRY_HARD_RISK_PARITY_REQUIRED')
        _need(size <= float(execution['max_leverage']) + 1e-10
              and ((direction == 'BUY' and stop < price) or (direction == 'SELL' and stop > price)),
              'OPEN_INVALIDATION_AND_HARD_LEVERAGE_REQUIRED')
        _same(price, _number(observations['execution_open'], 'POSITIVE_OPEN_NEXT_OPEN_REQUIRED', positive=True)
              + spread_slippage * (1 if direction == 'BUY' else -1), 'MODELED_OPEN_POSITION_ENTRY_REQUIRED')
        _need(_stamp(entry['entry_time']) == _stamp(observations['execution_time'])
              and _stamp(entry['entry_time']) >= _stamp(observations['signal_time'])
              + pd.Timedelta(seconds=request['duration_seconds']), 'OPEN_POSITION_SIGNAL_FILL_CLOCK_REQUIRED')
        stop_execution = stop - spread_slippage if direction == 'BUY' else stop + spread_slippage
        stop_return = ((price - stop_execution) if direction == 'BUY' else (stop_execution - price)) / price * 100
        _need(0 < max(0.0, stop_return + commission) * size <= risk_budget + 1e-7,
              'OPEN_EXECUTED_INITIAL_RISK_WITHIN_BUDGET_REQUIRED')
    maturity = body['statistical_evidence']['original_position_maturity']
    _need(maturity.get('protocol') == 'original_position_maturity_v1'
          and type(maturity.get('closed_trade_count')) is int and maturity['closed_trade_count'] == len(metrics)
          and type(maturity.get('open_position_count')) is int and maturity['open_position_count'] == len(open_entries)
          and type(maturity.get('censored_trade_count')) is int and maturity['censored_trade_count'] == 0
          and type(maturity.get('unknown_maturity_count')) is int and maturity['unknown_maturity_count'] == 0
          and maturity.get('forced_terminal_close_applied') is False, 'STRICT_ORIGINAL_POSITION_MATURITY_REQUIRED')
    _same(body['final_balance'], round(balance, 2), 'FINAL_CLOSED_CASH_ACCOUNT_RECONCILIATION_REQUIRED')
    # The response owner measures its displayed close-cash curve, whose cash
    # balances are rounded to cents, and then rounds the resulting percentage.
    # Keep the unrounded independent curve above as a distinct research metric.
    public_peak, public_drawdown = public_close_curve[0], 0.0
    for closed_cash in public_close_curve:
        public_peak = max(public_peak, closed_cash)
        percent = ((public_peak - closed_cash) / public_peak * 100) if public_peak > 0 else 0.0
        public_drawdown = max(public_drawdown, percent)
    displayed_drawdown = round(public_drawdown, 2)
    _same(body['max_drawdown_percent'], displayed_drawdown, 'CLOSED_CASH_DRAWDOWN_RECONCILIATION_REQUIRED')
    result = {'protocol': 'offline_curator_full_close_verified_v1', 'research_only': True,
            'initial_balance': initial, 'exact_final_balance': balance, 'exact_net_cash': balance - initial,
            'explicit_commission_carry_cash': explicit_cash, 'commission_cash': commission_cash, 'carry_cash': carry_cash,
            'closed_cash_drawdown_percent': drawdown, 'closed_trade_count': len(metrics),
            'displayed_closed_cash_drawdown_percent': displayed_drawdown,
            'mature_trade_count': len(metrics), 'open_position_count': len(open_entries),
            'entry_stage_hash': stages['entry']['event_hash'], 'closed_trade_stage_hash': stages['closed_trade']['event_hash'],
            'original_receipt_sha256': receipt['receipt_hash'], 'original_receipt_status': receipt['status'],
            'per_trade': metrics, 'native_ratchet_observation_available': False,
            'unclosed_exposure': 'censored_terminal_marked_value_unknown' if open_entries else 'none',
            'marked_equity_independently_verified': False, 'intrabar_fill_path_independently_verified': False,
            'excursions_are_producer_reported_bounds_not_independent_tick_paths': True,
            'explicit_cost_excludes_spread_and_slippage_already_in_executable_prices': True,
            'own_agent_outcome': False, 'independent_validation': False, 'economic_authority': False,
            'paper_authority': False, 'live_authority': False}
    if setup_geometry is not None:
        result['closed_setup_geometry'] = setup_geometry
    return result
