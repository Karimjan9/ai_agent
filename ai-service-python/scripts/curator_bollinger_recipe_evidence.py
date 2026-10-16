"""Strict original method evidence for one sealed curator recipe question.

Only feature preparation and the existing typed method owner are re-evaluated;
no trade replay, dispatcher, alternate fill engine or receipt bypass is used.
The legacy physical verifier retains its unconditional method-deny boundary.
"""
from collections import Counter

import pandas as pd

from scripts import curator_scalp_panel as physical

need = physical.need
AGENT_KEY = 'curator-bollinger-complete-recipe-v1'


def _midline_owner(request):
    from app.schemas import SimpleBacktestRequest
    from app.services.instrument_method_learning import InstrumentMethodLearning
    context = request.get('policy_context')
    need(isinstance(context, dict) and set(context) == {'instrument_method_learning'},
         'EXACT_CURATOR_MIDLINE_POLICY_REQUIRED')
    payload = SimpleBacktestRequest.model_validate(request)
    runtime = InstrumentMethodLearning(context['instrument_method_learning'], payload, expected_agent_key=AGENT_KEY)
    need(runtime.body.get('expected_agent_key') == AGENT_KEY and runtime.policy['default_action'] == 'BASELINE'
         and 'meta_model' not in runtime.body, 'ONLY_CURATOR_MIDLINE_POLICY_ALLOWED')
    expected = {(regime, volatility, session) for regime in ('range', 'trend_up', 'trend_down')
                for volatility in ('low', 'normal', 'high') for session in ('london', 'overlap')}
    observed = set()
    for identity, recipe in runtime.recipes.items():
        context = recipe['context_predicate']
        need(set(context) == {'regime', 'volatility', 'session'}, 'EXACT_MIDLINE_SCALAR_CONTEXT_REQUIRED')
        observed.add(tuple(context[key] for key in ('regime', 'volatility', 'session')))
        programs = runtime.programs[identity]
        need(not recipe.get('parameter_adapters') and len(programs) == 1 and programs[0].target == 'exit',
             'ONLY_CLOSED_MIDLINE_FULL_EXIT_ALLOWED')
        program = programs[0]
        need(program.bindings == {'available': 'method_midline20_available', 'effect': 'method_midline20_exit'},
             'EXACT_CLOSED_MIDLINE_INPUTS_REQUIRED')
        ast = program.ast
        need(set(ast) in ({'op', 'args'}, {'op', 'type', 'args'}) and ast['op'] == 'AND'
             and ast.get('type', 'bool') == 'bool'
             and len(ast['args']) == 2, 'EXACT_CLOSED_MIDLINE_PROGRAM_REQUIRED')
        for leaf, key in zip(ast['args'], ('available', 'effect')):
            need(set(leaf) in ({'op', 'input_key', 'available_at'}, {'op', 'type', 'input_key', 'available_at'})
                 and leaf['op'] == 'BOOL' and leaf.get('type', 'bool') == 'bool' and leaf['input_key'] == key
                 and leaf['available_at'] == '2000-01-01T00:00:00Z', 'EXACT_CLOSED_MIDLINE_PROGRAM_REQUIRED')
        methods = recipe['recipe_revision']['method_contracts']
        need(len(methods) == 1 and all(method['instrument_key'] == 'cost_aware_exit' for method in methods.values()),
             'EXACT_COST_AWARE_EXIT_INSTRUMENT_REQUIRED')
    need(len(runtime.recipes) == 18 and observed == expected, 'EXACT_FINITE_MIDLINE_CONTEXT_POOL_REQUIRED')
    return payload, runtime


def _stamp(value):
    stamp = pd.Timestamp(value)
    need(not pd.isna(stamp) and stamp.tzinfo is not None, 'ORIGINAL_METHOD_UTC_TIME_REQUIRED')
    return stamp.tz_convert('UTC')


def _call_schedule(frame, witness, time_stop):
    """Reopen only actual filled positions; never simulate an entry/fill/exit."""
    times = [_stamp(value) for value in frame['time']]
    positions = {stamp: index for index, stamp in enumerate(times)}
    need(len(positions) == len(times) and list(frame.index) == list(range(len(frame))),
         'ORIGINAL_METHOD_ORDERED_FRAME_REQUIRED')
    closed = {}
    notifications = {}
    for event in witness['closed_events']:
        entered, exited = _stamp(event['entry_time']), _stamp(event['exit_time'])
        need(entered not in closed and exited in positions, 'ORIGINAL_METHOD_CLOSE_LINK_REQUIRED')
        closed[entered] = event
        notifications.setdefault(positions[exited], []).append(event)
    calls = {}
    prior_exit = -1
    entries = witness['entry_events']
    need(witness['entry_indices'] == sorted(set(witness['entry_indices']))
         and set(entries) == {str(index) for index in witness['entry_indices']}, 'EXACT_METHOD_FILLED_ENTRY_ROSTER_REQUIRED')
    linked = set()
    for signal_index in witness['entry_indices']:
        entry_index = signal_index + 1
        entry = entries[str(signal_index)]
        entered, direction = _stamp(entry['entry_time']), entry['direction']
        need(type(signal_index) is int and 200 <= entry_index < len(frame) and entry_index > prior_exit
             and positions.get(entered) == entry_index and frame.iloc[signal_index]['signal'] == direction
             and direction in ('BUY', 'SELL'), 'ORIGINAL_METHOD_FILLED_DIRECTION_REQUIRED')
        close = closed.get(entered)
        end = positions[_stamp(close['exit_time'])] if close else len(frame) - 1
        need(end >= entry_index and (close is None or close['direction'] == direction), 'ORIGINAL_METHOD_HOLDING_LINK_REQUIRED')
        if time_stop:
            need(end <= entry_index + time_stop and (close is not None or end < entry_index + time_stop),
                 'ORIGINAL_METHOD_TIME_STOP_SCOPE_REQUIRED')
            if close is not None:
                need((end == entry_index + time_stop) == (close['exit_reason'] == 'time_stop'),
                     'ORIGINAL_TIME_STOP_PRECEDENCE_REQUIRED')
        calls[entry_index] = ('entry', direction, None)
        for index in range(entry_index + 1, end + 1):
            if time_stop and index - entry_index >= time_stop:
                continue
            need(index not in calls, 'ONE_ORIGINAL_METHOD_CALL_PER_EXECUTION_ROW_REQUIRED')
            calls[index] = ('exit', direction, entry_index)
        if close is not None:
            linked.add(entered)
            prior_exit = end
    need(linked == set(closed), 'COMPLETE_METHOD_CLOSE_LINKS_REQUIRED')
    return calls, notifications, positions


def _verify_method_evidence(body, request, window, witness):
    from app.services.backtester import _load_simple_candles, prepare_signal_snapshot
    from app.services.instrument_method_learning import receipt_current
    from app.services.research_program_tasks import canonical_hash, canonical_json
    payload, runtime = _midline_owner(request)
    need(physical.sha(window['path']) == window['sha256'] == payload.replay_dataset_hash,
         'ORIGINAL_METHOD_SOURCE_BYTES_REQUIRED')
    # The same existing feature/signal owner, not a market replay or a new
    # classifier/session approximation. Actual closed context is recomputed.
    frame = prepare_signal_snapshot(payload, _load_simple_candles(payload)).frame
    need(len(frame) == window['loaded_rows'], 'ORIGINAL_METHOD_FULL_FRAME_REQUIRED')
    runtime.validate_frame(frame)
    time_stop = int(payload.parameters.get('time_stop_candles', 0) or 0)
    calls, notifications, positions = _call_schedule(frame, witness, time_stop)
    trace = [row for row in body['decision_trace'] if row.get('event_type') == 'instrument_method_decision']
    need([row['candle_index'] for row in trace] == sorted(calls)
         and all(set(row) == {'event_type', 'candle_index', 'method_learning'} for row in trace),
         'COMPLETE_ORIGINAL_METHOD_CALL_SCHEDULE_REQUIRED')
    by_index = {row['candle_index']: row['method_learning'] for row in trace}
    contexts, reasons, phases, selected = Counter(), Counter(), Counter(), Counter()
    exit_requests = method_closes = 0
    for index in range(200, len(frame)):
        if index in calls:
            phase, direction, entry_index = calls[index]
            row = frame.iloc[index - 1]
            observed = (_stamp(row['time']) + pd.Timedelta(minutes=5)).isoformat()
            identity = {'owner': 'ordinary_single_position_v1', 'data_hash': payload.replay_dataset_hash,
                        'candle_index': index, 'observed_at': observed, 'phase': phase}
            identity.update(direction=direction) if phase == 'entry' else identity.update(entry_index=entry_index)
            event = runtime.evaluate(row.to_dict(), decision_id=canonical_hash(identity), phase=phase,
                                     decision_at=observed, observed_at=observed, direction=direction)
            need(canonical_json(event) == canonical_json(by_index[index]), 'ORIGINAL_METHOD_EVENT_REDERIVATION_REQUIRED')
            phases[phase] += 1
            reasons[event['reason']] += 1
            contexts[canonical_json(event['context'])] += 1
            if event['recipe_hash'] is not None:
                selected[event['recipe_hash']] += 1
            requested = isinstance(event['outputs'], dict) and event['outputs'].get('exit') is True
            exit_requests += int(requested)
            matching = [close for close in notifications.get(index, [])
                        if positions[_stamp(close['entry_time'])] == entry_index]
            if requested:
                need(len(matching) == 1 and matching[0]['exit_reason'] in ('method_learning_exit', 'gap_stop', 'gap_target'),
                     'ORIGINAL_REQUESTED_FULL_EXIT_REQUIRED')
        for close in notifications.get(index, []):
            entry_index = positions[_stamp(close['entry_time'])]
            if close['exit_reason'] == 'method_learning_exit':
                need(index in calls and calls[index] == ('exit', close['direction'], entry_index)
                     and by_index[index].get('outputs', {}).get('exit') is True, 'ORIGINAL_METHOD_CLOSE_REQUEST_REQUIRED')
                method_closes += 1
            runtime.notify_mature_close(position_id='ordinary:' + str(entry_index), net_pnl=float(close['profit_percent']),
                available_at=(_stamp(close['exit_time']) + pd.Timedelta(minutes=5)).isoformat(),
                mature=close['exit_reason'] != 'end_of_data')
    actual = body['data_quality'].get('instrument_method_learning_receipt')
    contract = request['policy_context']['instrument_method_learning']
    need(receipt_current(body, contract) and canonical_json(actual) == canonical_json(runtime.receipt()),
         'COMPLETE_ORIGINAL_METHOD_RECEIPT_REDERIVATION_REQUIRED')
    ledger_reasons = {key.split(':', 1)[1]: value for key, value in body['event_ledger_categories'].items()
                      if key.startswith('method_learning:')}
    need(ledger_reasons == dict(reasons) and 'method_learning' not in body['event_ledger_categories'],
         'ORIGINAL_METHOD_EVENT_LEDGER_REQUIRED')
    return {'protocol': 'curator_bollinger_recipe_method_evidence_v1', 'contract_hash': runtime.contract_hash,
        'policy_key': runtime.policy['agent_key'], 'policy_hash': runtime.policy_hash,
        'receipt_hash': actual['receipt_hash'], 'events_hash': actual['events_hash'],
        'complete_call_schedule_rederived': True, 'event_count': len(trace), 'phase_counts': dict(phases),
        'selected_recipe_counts': dict(selected), 'reason_counts': dict(reasons),
        'actual_closed_context_counts': dict(contexts), 'exit_requested': exit_requests,
        'method_full_closes': method_closes, 'feature_only_reverification': True,
        'research_only': True, 'own_agent_outcome': False, 'independent_validation': False, 'economic_authority': False}


def verify_response(body, declaration, window, envelope, spec, response_sha):
    from scripts.curator_bollinger_recipe import validate
    # The code-owned adapter pins the exact actual PHP-compiled contract hash
    # before outcomes. No caller flag or arbitrary policy receipt authorizes it.
    validate(spec)
    key = declaration['window'] + '__' + declaration['arm']
    need(declaration == spec['requests'].get(key) and window == spec['windows'].get(declaration['window']),
         'SEALED_CURATOR_RECIPE_DECLARATION_REQUIRED')
    if declaration['arm'] == 'control':
        need(not declaration['request'].get('policy_context'), 'CONTROL_METHOD_POLICY_FORBIDDEN')
        return physical.verify_response(body, declaration, window, envelope, spec, response_sha)
    need(declaration['arm'] == 'population_midline', 'EXACT_CURATOR_RECIPE_ARM_REQUIRED')
    result = physical.verify_physical_response(body, declaration, window, envelope, spec, response_sha)
    return {**result, 'curator_method_evidence': _verify_method_evidence(body, declaration['request'], window,
                                                                     envelope['full_close_witness'])}
