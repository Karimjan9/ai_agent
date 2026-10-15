"""Synthetic software acceptance, not calibrated market/skill qualification."""

import copy
import hashlib
import json
from unittest.mock import patch

import pandas as pd
import pytest

from app import main
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.instrument_method_learning import InstrumentMethodLearning
from app.services.instrument_method_learning import _context as actual_method_context
from app.services.instrument_method_native_meta_model import (
    BINDING_FIELDS, PHYSICAL_FIELDS, PROTOCOL, FiniteNativeInstrumentMetaModel,
    actual_native_meta_evaluation_binding,
)
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract, _closed_member_session, _closed_session_calendar
from test_instrument_method_learning import pinned_recipe, learning_contract, strategy, const
from test_instrument_method_meta_model import original_export, reseal
from test_specialist_council import fixtures, member


def seal_method(value):
    body = {key: copy.deepcopy(item) for key, item in value.items() if key not in {'contract_hash', 'contract_json'}}
    return {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}


def native_case(*, threshold=.6, with_peer_method=False):
    request, frame = fixtures([member('hour', holding=3), member('swing', holding=5)])
    request.replay_dataset_hash = 'd' * 64
    request.emit_decision_trace = True
    native = copy.deepcopy(request.specialist_council_contract)
    native['replay_dataset_hash'] = request.replay_dataset_hash
    ast = {'op': 'AND', 'args': [
        {'op': 'BOOL', 'input_key': 'available', 'available_at': '2024-01-01T00:00:00Z'},
        {'op': 'GREATER_THAN', 'args': [
            {'op': 'NUMBER', 'input_key': 'score', 'available_at': '2024-01-01T00:00:00Z'}, const(threshold)]}]}
    recipe = pinned_recipe(request, 'native-finite-meta', {'confirmation': ast},
        {'available': 'method_meta_model_available', 'score': 'method_meta_score'})
    method = learning_contract(request, frame, [recipe], default='WAIT', agent='native-hour')
    native['members'][0]['method_learning'] = method
    if with_peer_method:
        peer_recipe = pinned_recipe(request, 'peer-original-risk', {'risk_multiplier': const(.5)})
        native['members'][1]['method_learning'] = learning_contract(request, frame, [peer_recipe], agent='native-peer')
    request.specialist_council_contract = seal_contract(native)
    declaration = request.specialist_council_contract['members'][0]
    physical = actual_native_meta_evaluation_binding(request, request.specialist_council_contract, declaration)
    old_artifact, _ = original_export()
    body = json.loads(old_artifact['artifact_json'])
    body['protocol'] = PROTOCOL
    body['binding'] = {key: physical.get(key, hashlib.sha256(key.encode()).hexdigest()) for key in BINDING_FIELDS}
    body['binding']['data_hash'] = request.replay_dataset_hash
    artifact, reference = reseal(body)
    declaration['method_learning'] = seal_method({**method, 'meta_model': {
        'artifact': artifact, 'expected_training_binding': reference, 'evaluation_binding': physical}})
    request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    return request, frame


def native_runtime(request):
    declaration = next(item for item in request.specialist_council_contract['members'] if 'method_learning' in item)
    child = request.model_copy(update={'strategy': declaration['strategy'], 'base_strategy': declaration['base_strategy'],
        'parameters': declaration['parameters'], 'specialist_council_contract': {}})
    return InstrumentMethodLearning(declaration['method_learning'], child,
        execution_owner='native_specialist_council_v1',
        native_context=(request, request.specialist_council_contract, declaration))


def test_actual_native_member_veto_uses_shared_account_peer_cash_clock_and_own_score():
    request, frame = native_case()
    # Caller/CSV confidence scores cannot replace the finite owner's 7/12.
    frame['method_meta_score'] = .99
    frame['method_meta_model_available'] = True
    with patch('app.services.backtester.get_strategy', return_value=strategy):
        result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    receipt = result.specialist_council_receipt
    assert result.total_trades > 0
    assert {position['specialist_id'] for position in receipt['position_ledger']} == {'swing'}
    assert sum(position['net_pnl'] for position in receipt['position_ledger']) == pytest.approx(
        result.final_balance - result.initial_balance)
    assert receipt['replay_executed_clock']['owner'] == 'native_specialist_council_v1'
    assert all(item['closed_inputs']['market_session'] == 'london'
               for row in result.decision_trace for item in row['member_decisions'])
    events = [event for row in result.decision_trace for event in row.get('method_learning_events', [])]
    assert events and all(event['outputs']['confirmation'] is False for event in events)
    assert all(event['meta_score_status'] == 'diagnostic_calibrated_score_available' for event in events)
    assert all(len(event['native_meta_evaluation_binding_hash']) == 64 for event in events)
    assert receipt['scientific_evidence'] is False and receipt['promotion_evidence'] is False
    assert all(event['economic_authority'] is False for event in events)


def test_native_consumer_can_allow_own_member_without_disabling_peer_account_guards():
    request, frame = native_case(threshold=.5)
    with patch('app.services.backtester.get_strategy', return_value=strategy):
        result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    receipt = result.specialist_council_receipt
    assert {position['specialist_id'] for position in receipt['position_ledger']} == {'hour', 'swing'}
    assert sum(position['net_pnl'] for position in receipt['position_ledger']) == pytest.approx(
        result.final_balance - result.initial_balance)


@pytest.mark.parametrize('change', ['account', 'risk', 'peer_parameters', 'peer_allocation',
    'member_horizon', 'member_id', 'ordered_peers', 'council_risk', 'policy_port', 'mode', 'cost'])
def test_actual_shared_account_peer_clock_programme_drift_refuses_not_relabels(change):
    request, _ = native_case()
    council = request.specialist_council_contract
    if change == 'account': request.initial_balance += 1
    elif change == 'risk': request.risk_per_trade += .1
    elif change == 'peer_parameters': council['members'][1]['parameters']['ema_fast'] += 1
    elif change == 'peer_allocation': council['members'][1]['capital_weight'] -= .01
    elif change == 'member_horizon': council['members'][0]['horizon']['max_holding_bars'] += 1
    elif change == 'member_id': council['members'][0]['specialist_id'] = 'foreign'
    elif change == 'ordered_peers': council['members'].reverse()
    elif change == 'council_risk': council['policy']['max_total_risk_percent'] -= .1
    elif change == 'policy_port': request.policy_context['hard_limits'] = {'max_risk': .1}
    elif change == 'mode': request.evaluation_mode = 'incremental'
    else: request.execution.commission_percent += .01
    request.specialist_council_contract = seal_contract(council)
    reason = ('METHOD_LEARNING_DATA_OR_EXECUTION_MISMATCH' if change == 'cost' else
              'METHOD_NATIVE_META_(ACTUAL_EVALUATION_BASELINE|ACTUAL_ACCOUNT_EXECUTION)_MISMATCH')
    with pytest.raises(ValueError, match=reason):
        native_runtime(request)


def test_native_model_needs_actual_parent_original_member_not_ordinary_shaped_child():
    request, _ = native_case()
    declaration = request.specialist_council_contract['members'][0]
    method = declaration['method_learning']
    with pytest.raises(ValueError, match='ACTUAL_PARENT_REQUEST_REQUIRED'):
        InstrumentMethodLearning(method, request)
    with pytest.raises(ValueError, match='ACTUAL_PARENT_REQUEST_REQUIRED'):
        InstrumentMethodLearning(method, request, execution_owner='native_specialist_council_v1')
    child = request.model_copy(update={'specialist_council_contract': {}})
    with pytest.raises(ValueError, match='ORIGINAL_COUNCIL_REQUIRED'):
        actual_native_meta_evaluation_binding(child, request.specialist_council_contract, declaration)
    foreign = {**declaration, 'specialist_id': 'other'}
    with pytest.raises(ValueError, match='ORIGINAL_MEMBER_REQUIRED'):
        actual_native_meta_evaluation_binding(request, request.specialist_council_contract, foreign)


def test_native_training_policy_is_part_of_original_shared_account_not_copyable_treatment():
    request, _ = native_case()
    declaration = request.specialist_council_contract['members'][0]
    meta = declaration['method_learning']['meta_model']
    original = copy.deepcopy(meta['artifact'])
    changed = copy.deepcopy(meta['artifact'])
    body = json.loads(changed['artifact_json'])
    body['binding']['effective_policy_hash'] = 'e' * 64
    artifact, reference = reseal(body)
    # Unlike ordinary v1, a shared account cannot borrow another peer policy.
    with pytest.raises(ValueError, match='EVALUATION_PHYSICAL_BASELINE_MISMATCH'):
        FiniteNativeInstrumentMetaModel(artifact, reference,
            {key: meta['evaluation_binding'][key] for key in PHYSICAL_FIELDS})
    assert meta['artifact'] == original
    declaration['method_learning']['meta_model']['evaluation_binding']['effective_policy_hash'] = 'f' * 64
    declaration['method_learning'] = seal_method(declaration['method_learning'])
    request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
        native_runtime(request)


def test_pre_cache_native_guard_uses_actual_parent_and_refuses_upgrade_only_model():
    request, _ = native_case()
    main._validate_method_learning_before_cache(request)
    request.initial_balance += 1
    with patch('app.main._load_immutable_replay_cache') as cache:
        with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
            main._validate_method_learning_before_cache(request)
        cache.assert_not_called()
    request, _ = native_case()
    original = copy.deepcopy(request.specialist_council_contract['members'])
    request.specialist_council_contract['members'][0].pop('method_learning')
    request.specialist_council_contract['upgrades'] = [{'council_version': 'future-v2',
        'effective_at': '2025-01-06T05:00:00Z', 'members': original}]
    request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    with pytest.raises(ValueError, match='VERSIONED_UPGRADE_UNSUPPORTED'):
        main._validate_method_learning_before_cache(request)


def test_native_meta_score_still_unavailable_before_calibration_and_at_paper_boundary():
    request, _ = native_case()
    runtime = native_runtime(request)
    model = runtime.meta_model
    context = {'regime': 'trend_up', 'volatility': 'normal', 'session': 'london', 'direction': 'BUY'}
    assert model.predict(context, 1.0, observed_at='2025-01-01T07:25:00Z', decision_at='2025-01-01T07:25:00Z') is None
    with pytest.raises(ValueError, match='FUTURE_OR_PAPER_INPUT_FORBIDDEN'):
        model.predict(context, 1.0, observed_at='2026-01-01T00:00:00Z', decision_at='2026-01-01T00:00:00Z')
    assert model.predict(context, .4, observed_at='2025-01-06T00:00:00Z', decision_at='2025-01-06T00:00:00Z') is None


@pytest.mark.parametrize('extra', ['caller_score', 'parameter_projection'])
def test_native_meta_envelope_never_accepts_supplied_scores_or_ordinary_projection(extra):
    request, _ = native_case()
    declaration = request.specialist_council_contract['members'][0]
    declaration['method_learning']['meta_model'][extra] = .99 if extra == 'caller_score' else {}
    declaration['method_learning'] = seal_method(declaration['method_learning'])
    request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    with pytest.raises(ValueError, match='REFERENCE_FIELDS_REQUIRED|ACTUAL_PARENT_REQUEST_REQUIRED'):
        native_runtime(request)


def test_new_data_and_version_envelope_do_not_change_exact_shared_physical_programme():
    request, _ = native_case()
    original = native_runtime(request).native_meta_binding
    request.replay_dataset_hash = 'e' * 64
    council = request.specialist_council_contract
    council['replay_dataset_hash'] = request.replay_dataset_hash
    council['council_version'] = 'prospective-recreation'
    council['council_id'] = 'new-server-owned-id'
    for declaration in council['members']:
        declaration['version'] = 'unused-model-version'
        declaration['model_version_id'] = 1000
        declaration['passport_hash'] = 'c' * 64
    request.specialist_council_contract = seal_contract(council)
    actual = actual_native_meta_evaluation_binding(request, request.specialist_council_contract,
        request.specialist_council_contract['members'][0])
    assert actual == original


def test_native_hard_limits_null_only_fallback_preserves_empty_php_array_scope():
    class ExplicitLimitsRequest(SimpleBacktestRequest):
        hard_limits: dict | list | None = None

    request, _ = native_case()
    wire = request.model_dump(mode='python')
    wire['policy_context']['hard_limits'] = {'max_risk_percent': .5}
    hashes = {}
    for name, value in [('null', None), ('object', {}), ('array', [])]:
        explicit = ExplicitLimitsRequest(**{**wire, 'hard_limits': value})
        hashes[name] = actual_native_meta_evaluation_binding(explicit, explicit.specialist_council_contract,
            explicit.specialist_council_contract['members'][0])['council_physical_hash']
    assert hashes['null'] != hashes['object']
    assert hashes['object'] == hashes['array']


@pytest.mark.parametrize('field,value,reason', [
    ('policy_context', [], 'POLICY_CONTEXT_OBJECT_REQUIRED'),
    ('policy_context', False, 'POLICY_CONTEXT_OBJECT_REQUIRED'),
    ('method', [], 'METHOD_OBJECT_REQUIRED'),
    ('method_policy', [], 'MEMBER_POLICY_OBJECT_REQUIRED'),
])
def test_native_physical_scope_rejects_malformed_untrusted_objects(field, value, reason):
    request, _ = native_case()
    if field == 'policy_context':
        request = request.model_copy(update={'policy_context': value})
    else:
        method = request.specialist_council_contract['members'][0]['method_learning']
        if field == 'method': request.specialist_council_contract['members'][0]['method_learning'] = value
        else: method['policy'] = value
        request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    with pytest.raises(ValueError, match=reason):
        actual_native_meta_evaluation_binding(request, request.specialist_council_contract,
            request.specialist_council_contract['members'][0])


def test_only_known_nonexecution_metadata_is_outside_native_physical_transfer_scope():
    from app.services.instrument_method_native_meta_model import NONEXECUTION_CONTEXT_FIELDS
    request, _ = native_case()
    original = native_runtime(request).native_meta_binding
    for key in NONEXECUTION_CONTEXT_FIELDS:
        request.policy_context[key] = {'old_generation': 123, 'new_original': 'transport-only'}
    assert native_runtime(request).native_meta_binding == original
    request.policy_context['unknown_future_risk_port'] = {'max_risk': .1}
    with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
        native_runtime(request)
    request.policy_context.pop('unknown_future_risk_port')
    request.policy_context['shadow_micro_probe'] = {'enabled': True}
    with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
        native_runtime(request)


@pytest.mark.parametrize('port', ['snapshot_transport', 'historical_stratified_windows', 'full_replay_runtime_policy',
    'prospective_probe_window', 'data_boundary', 'repair_contract', 'repair_contracts'])
def test_native_behavior_window_warmup_boundary_and_repair_ports_remain_strict(port):
    request, _ = native_case()
    request.policy_context[port] = {'changed_window': True, 'warmup_rows': 20}
    with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
        native_runtime(request)


def test_native_candidate_cache_preserves_original_repair_and_clock_context_ports():
    request, _ = native_case()
    request.policy_context.update({'repair_contracts': {'hour': {'changed_gene': 'ema_fast'},
        'peer': {'changed_gene': 'ema_slow'}}, 'repair_contract': {'changed_gene': 'ema_fast'},
        'full_replay_runtime_policy': {'mode': 'full', 'warmup_rows': 20},
        'trial_ledger': {'historical_trials': 99}})
    member = request.specialist_council_contract['members'][0]
    original = actual_native_meta_evaluation_binding(request, request.specialist_council_contract, member)
    cached = main._candidate_cache_payload(request, request, 'hour')
    assert cached.policy_context['repair_contracts'] == request.policy_context['repair_contracts']
    assert cached.policy_context['full_replay_runtime_policy'] == request.policy_context['full_replay_runtime_policy']
    assert 'trial_ledger' not in cached.policy_context
    assert actual_native_meta_evaluation_binding(cached, cached.specialist_council_contract, member) == original


@pytest.mark.parametrize('port,value', [
    ('signal_delay_candles', 1), ('random_seed', 43), ('from_date', '2025-01-01'),
    ('to_date', '2025-12-31'), ('dataset_tail_rows', 220), ('regime_dataset_tail_rows', 10),
    ('mtf_dataset_tail_rows', {'H1': 10}), ('related_mtf_dataset_tail_rows', {'EURUSD:H1': 10}),
    ('mtf_pilot', {'enabled': True}), ('volume_context', {'available': False}),
    ('runtime_ensemble_policy', {'weight': .5}), ('native_spread_context_study_contract', {'masked': True}),
    ('native_reachability_depth_audit_contract', {'rows': 5}), ('native_standalone_qualification', {'seed': 1}),
    ('mtf_snapshot_manifest', {'streams': {'M5': {'sha256': 'e' * 64}}}),
])
def test_all_inherited_native_parent_runtime_ports_are_physically_bound(port, value):
    request, _ = native_case()
    changed = request.model_copy(update={port: value})
    with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
        native_runtime(changed)


def test_a_valid_peer_risk_recipe_drift_cannot_be_accepted_by_resigning_current_evaluation_copy():
    request, frame = native_case()
    council = request.specialist_council_contract
    peer_recipe = pinned_recipe(request, 'peer-risk-reduction', {'risk_multiplier': const(.5)})
    council['members'][1]['method_learning'] = learning_contract(request, frame, [peer_recipe], agent='native-peer')
    request.specialist_council_contract = seal_contract(council)
    declaration = request.specialist_council_contract['members'][0]
    # Even a fresh prospective target cannot transplant the old classifier to
    # another valid shared peer programme. The original artifact is untouched.
    actual = actual_native_meta_evaluation_binding(request, request.specialist_council_contract, declaration)
    declaration['method_learning']['meta_model']['evaluation_binding'] = actual
    declaration['method_learning'] = seal_method(declaration['method_learning'])
    request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    with pytest.raises(ValueError, match='EVALUATION_PHYSICAL_BASELINE_MISMATCH'):
        native_runtime(request)


@pytest.mark.parametrize('member_index', [0, 1], ids=['subject', 'peer'])
@pytest.mark.parametrize('change', ['context', 'start', 'end', 'family'])
def test_exact_method_scope_and_family_cannot_drift_after_native_calibration(member_index, change):
    request, _ = native_case(with_peer_method=True)
    baseline = native_runtime(request).native_meta_binding
    council = request.specialist_council_contract
    method = council['members'][member_index]['method_learning']
    if change == 'context':
        method['scope']['context'] = {'session': ['ASIA']}
    elif change == 'start':
        method['scope']['evaluated_start'] = '2025-01-06T01:00:00Z'
    elif change == 'end':
        method['scope']['evaluated_end'] = '2025-01-06T20:00:00Z'
    else:
        method['strategy_family'] = 'ema_rsi'
    council['members'][member_index]['method_learning'] = seal_method(method)
    request.specialist_council_contract = seal_contract(council)
    declaration = request.specialist_council_contract['members'][0]
    actual = actual_native_meta_evaluation_binding(request, request.specialist_council_contract, declaration)
    assert actual['effective_policy_hash'] != baseline['effective_policy_hash']
    # Replacing the prospective evaluation copy still cannot rewrite the
    # original model's exact context/window/family training programme.
    declaration['method_learning']['meta_model']['evaluation_binding'] = actual
    declaration['method_learning'] = seal_method(declaration['method_learning'])
    request.specialist_council_contract = seal_contract(request.specialist_council_contract)
    with pytest.raises(ValueError, match='EVALUATION_PHYSICAL_BASELINE_MISMATCH'):
        native_runtime(request)


@pytest.mark.parametrize('peer_asset', [None, {}, {'artifact': {'artifact_hash': 'b' * 64}},
    {'artifact': {'protocol': 'instrument_method_meta_model_v1'}},
    {'artifact': {'protocol': PROTOCOL, 'artifact_hash': 'e' * 64}}])
def test_native_v1_refuses_a_peer_predictor_even_with_unchanged_library_policy(peer_asset):
    request, frame = native_case()
    council = request.specialist_council_contract
    peer_recipe = pinned_recipe(request, 'peer-baseline', {'confirmation': const(True)})
    peer = learning_contract(request, frame, [peer_recipe], agent='native-peer')
    council['members'][1]['method_learning'] = seal_method({**peer, 'meta_model': peer_asset})
    request.specialist_council_contract = seal_contract(council)
    with pytest.raises(ValueError, match='PEER_MODEL_SCOPE_UNSUPPORTED'):
        native_runtime(request)


def test_declared_optional_model_recipe_can_preserve_original_baseline_before_asset_exists():
    from app.services.research_program_tasks import BoundedDecisionProgram
    request, _ = native_case()
    recipe = pinned_recipe(request, 'optional-native-score-overlay', {'confirmation': {'op': 'OR', 'args': [
        {'op': 'NOT', 'args': [{'op': 'BOOL', 'input_key': 'available', 'available_at': '2024-01-01T00:00:00Z'}]},
        {'op': 'GREATER_THAN', 'args': [{'op': 'NUMBER', 'input_key': 'score', 'available_at': '2024-01-01T00:00:00Z'}, const(.6)]}]}},
        {'available': 'method_meta_model_available', 'score': 'method_meta_score'})
    program = BoundedDecisionProgram(recipe['operators'][0], allow_method_features=True)
    stamp = '2025-01-06T08:00:00Z'
    assert program.evaluate({'method_meta_model_available': False}, decision_at=stamp, observed_at=stamp) is True
    assert program.evaluate({'method_meta_model_available': True, 'method_meta_score': 7 / 12},
        decision_at=stamp, observed_at=stamp) is False


def test_missing_native_session_publishes_existing_calendar_without_changing_trades_or_account():
    from app.services import backtester as kernel
    request, frame = fixtures([member('hour', holding=3), member('swing', holding=5)])
    request.emit_decision_trace = True

    def missing_session(data, parameters):
        return strategy(data, parameters).drop(columns=['market_session'])

    def original_fallback_values(data, parameters):
        result = missing_session(data, parameters)
        result['market_session'] = [kernel._instrument_runtime_context(row, 'BUY')['session']
                                   for row in result.to_dict('records')]
        return result

    with patch('app.services.backtester.get_strategy', return_value=missing_session):
        derived = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    with patch('app.services.backtester.get_strategy', return_value=original_fallback_values):
        explicit = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    assert derived.total_trades == explicit.total_trades > 0
    assert derived.final_balance == explicit.final_balance
    assert derived.specialist_council_receipt['position_ledger'] == explicit.specialist_council_receipt['position_ledger']
    for row in derived.decision_trace:
        for observed in row['member_decisions']:
            assert observed['session_observation']['source'] == 'deterministic_existing_session_calendar'
            assert observed['session_observation']['provider_quote_observation'] is False
            method_context = actual_method_context(observed['closed_inputs'], request.symbol, request.timeframe, 'BUY')
            assert method_context['session'] == observed['closed_inputs']['market_session']
    assert all(observed['session_observation']['source'] == 'existing_closed_input'
        for row in explicit.decision_trace for observed in row['member_decisions'])


def test_native_closed_calendar_vector_equals_existing_scalar_owner_across_dst_changes():
    from app.services import backtester as kernel
    times = pd.to_datetime([
        '2025-03-07T07:00:00Z', '2025-03-10T07:00:00Z',
        '2025-03-28T08:00:00Z', '2025-03-31T08:00:00Z',
        '2025-03-07T13:30:00Z', '2025-03-10T13:30:00Z',
        '2025-10-24T07:00:00Z', '2025-10-27T07:00:00Z',
        '2025-10-31T13:30:00Z', '2025-11-03T13:30:00Z',
    ], utc=True)
    expected = [kernel._edge_market_session(stamp) for stamp in times]
    lookup = _closed_session_calendar(times, kernel)
    with patch('app.services.backtester.session_membership', wraps=kernel.session_membership) as calendar:
        # Repeated peers/observations share the same exact bounded calendar.
        assert [lookup(stamp) for stamp in times] == expected
        assert [lookup(stamp) for stamp in reversed(times)] == list(reversed(expected))
        assert calendar.call_count == 1
    with pytest.raises(ValueError, match='CLOSED_SESSION_CLOCK_MISMATCH'):
        lookup(pd.Timestamp('2025-04-01T00:00:00Z'))


def test_native_wait_only_run_builds_one_calendar_and_supplied_context_builds_none():
    from app.services import backtester as kernel
    request, frame = fixtures([member('hour'), member('swing')])
    request.emit_decision_trace = True

    def all_wait(data, parameters):
        return data.assign(signal='WAIT')

    with patch('app.services.backtester.get_strategy', return_value=all_wait), \
            patch('app.services.backtester.session_membership', wraps=kernel.session_membership) as calendar:
        result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    assert result.total_trades == 0
    assert len(result.decision_trace) == len(frame) - 1
    assert calendar.call_count == 1
    assert len(calendar.call_args.args[0]) == len(frame) - 1

    def all_wait_supplied(data, parameters):
        return all_wait(data, parameters).assign(market_session='ASIA')

    with patch('app.services.backtester.get_strategy', return_value=all_wait_supplied), \
            patch('app.services.backtester.session_membership', side_effect=AssertionError('unneeded calendar')):
        explicit = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
    assert explicit.total_trades == result.total_trades == 0
    assert explicit.final_balance == result.final_balance
    assert all(observed['closed_inputs']['market_session'] == 'ASIA'
        and observed['session_observation']['source'] == 'existing_closed_input'
        for row in explicit.decision_trace for observed in row['member_decisions'])


@pytest.mark.parametrize('session', [False, 3, [], {}, float('nan')])
def test_native_closed_session_rejects_malformed_supplied_context(session):
    from app.services import backtester as kernel
    with pytest.raises(ValueError, match='CLOSED_SESSION_VALUE_INVALID'):
        _closed_member_session({'time': '2025-01-06T08:00:00Z', 'market_session': session},
            decision_at='2025-01-06T09:00:00Z', kernel=kernel)


@pytest.mark.parametrize('candle', ['2025-01-06T09:00:00Z', '2025-01-06T10:00:00Z',
                                   '2025-01-06T08:00:00', 'not-a-time'])
def test_native_closed_session_rejects_future_open_or_malformed_clocks(candle):
    from app.services import backtester as kernel
    with pytest.raises(ValueError):
        _closed_member_session({'time': candle}, decision_at='2025-01-06T09:00:00Z', kernel=kernel)
