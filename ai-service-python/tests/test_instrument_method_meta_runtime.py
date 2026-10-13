"""Conditional runtime fixtures, not original market training/qualification.

The PHP producer suite additionally exports models fitted from its compressed
original-product fixtures. These finite count fixtures exercise consumer guards;
they are not evidence that any live/market model has been fitted or inherited.
"""

import copy
import json
from unittest.mock import patch

import pandas as pd
import pytest

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.instrument_method_learning import (
    InstrumentMethodLearning, actual_meta_evaluation_binding, validate_contract,
)
from app.services.research_program_tasks import canonical_hash, canonical_json
from test_instrument_method_learning import pinned_recipe, learning_contract, strategy, const
from test_instrument_method_meta_model import original_export, reseal


def case(*, start='2025-01-06T08:00Z', rows=224):
    request = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='M5',
        replay_dataset_hash='d' * 64, parameters={'time_stop_candles': 3},
        execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2))
    data = pd.DataFrame({'time': pd.date_range(start, periods=rows, freq='5min'),
        'open': 100.0, 'high': 100.05, 'low': 99.95, 'close': 100.0,
        'volume': 100.0, 'volume_available': True})
    data = strategy(data, {})
    artifact, _ = original_export()
    body = json.loads(artifact['artifact_json'])
    evaluation = actual_meta_evaluation_binding(request)
    body['binding'].update(evaluation)
    artifact, expected = reseal(body)
    ast = {'op': 'AND', 'args': [
        {'op': 'BOOL', 'input_key': 'available', 'available_at': '2024-01-01T00:00:00Z'},
        {'op': 'GREATER_THAN', 'args': [
            {'op': 'NUMBER', 'input_key': 'score', 'available_at': '2024-01-01T00:00:00Z'}, const(.6)]}]}
    recipe = pinned_recipe(request, 'finite-meta-runtime', {'confirmation': ast},
        {'available': 'method_meta_model_available', 'score': 'method_meta_score'})
    contract = learning_contract(request, data, [recipe], default='WAIT')
    body = {key: value for key, value in contract.items() if key not in {'contract_hash', 'contract_json'}}
    body['meta_model'] = {'artifact': artifact, 'expected_training_binding': expected, 'evaluation_binding': evaluation}
    return request, data, seal(body)


def seal(body):
    return {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}


def event(runtime, data, index=20, *, phase='entry', change=None):
    row = data.iloc[index].to_dict()
    if change:
        row.update(change)
    stamp = (row['time'] + pd.Timedelta(minutes=5)).isoformat()
    return runtime.evaluate(row, decision_id='fixture-' + str(index) + phase, phase=phase,
        observed_at=stamp, decision_at=stamp, direction='BUY')


def runtime_case(**changes):
    request, data, contract = case(**changes)
    runtime = InstrumentMethodLearning(contract, request)
    runtime.validate_frame(data)
    return request, data, contract, runtime


def test_registered_finite_model_computes_own_score_with_separate_training_policy():
    request, data, contract, runtime = runtime_case()
    assert contract['meta_model']['expected_training_binding']['effective_policy_hash'] != contract['library_hash']
    observed = event(runtime, data, change={'method_meta_score': .99, 'method_meta_model_available': True})
    assert observed['feature_availability']['method_meta_model_available'] is True
    assert observed['meta_score_status'] == 'diagnostic_calibrated_score_available'
    # The calibrated 7/12 is below .6. The arbitrary CSV/caller .99 was ignored.
    assert observed['outputs']['confirmation'] is False
    assert observed['meta_model_hash'] == contract['meta_model']['artifact']['artifact_hash']
    assert not observed['economic_authority']


@pytest.mark.parametrize('changes', [{'market_session': 'unknown'}, {'volatility_regime': None},
    {'signal_confidence': None}, {'signal_confidence': float('nan')}, {'market_regime': 'range'}])
def test_missing_original_context_confidence_or_unseen_cell_is_unavailable_wait(changes):
    _, data, _, runtime = runtime_case()
    observed = event(runtime, data, change=changes)
    assert observed['feature_availability']['method_meta_model_available'] is False
    assert observed['outputs']['confirmation'] is False
    assert observed['meta_score_status'] in {'closed_context_unavailable', 'signal_confidence_unavailable',
        'context_calibration_cell_unavailable'}


def test_exit_does_not_predict_an_entry_outcome_or_require_signal_confidence():
    _, data, _, runtime = runtime_case()
    observed = event(runtime, data, phase='exit', change={'signal_confidence': None, 'signal': 'WAIT'})
    assert observed['feature_availability']['method_meta_model_available'] is False
    assert observed['meta_score_status'] == 'entry_target_unreached'
    assert observed['outputs'] == []


def test_training_or_last_calibration_labels_cannot_leak_into_earlier_decisions():
    _, data, _, runtime = runtime_case(start='2025-01-01T00:00Z')
    before = event(runtime, data, 80)
    assert before['meta_score_status'] == 'calibration_labels_not_yet_available'
    assert before['feature_availability']['method_meta_model_available'] is False
    assert before['reason'] == 'method_meta_score_unavailable'
    after = event(runtime, data, 90)
    assert after['feature_availability']['method_meta_model_available'] is True


@pytest.mark.parametrize('port', ['initial_balance', 'risk_per_trade', 'timeframe', 'strategy', 'parameters'])
def test_current_actual_physical_request_drift_is_refused_before_cache(port):
    request, _, contract = case()
    change = {'initial_balance': 20000.0, 'risk_per_trade': 2.0, 'timeframe': 'M15',
        'strategy': 'differential_router', 'parameters': {'time_stop_candles': 4}}[port]
    request = request.model_copy(update={port: change})
    with pytest.raises(ValueError):
        validate_contract(contract, dataset_hash=request.replay_dataset_hash,
            execution_hash=contract['execution_hash'], payload=request)


def test_physical_policy_risk_port_changes_are_not_a_free_library_intervention():
    request, _, contract = case()
    request.policy_context['hard_limits'] = {'max_drawdown_percent': 99}
    with pytest.raises(ValueError, match='ACTUAL_EVALUATION_BASELINE_MISMATCH'):
        InstrumentMethodLearning(contract, request)


def test_copied_artifact_signature_and_self_rehash_cannot_replace_original_ref():
    request, _, contract = case()
    body = {key: copy.deepcopy(value) for key, value in contract.items() if key not in {'contract_hash', 'contract_json'}}
    forged = json.loads(body['meta_model']['artifact']['artifact_json'])
    forged['bins'][0]['wins'] = 19
    forged['bins'][0]['score'] = 20 / 22
    body['meta_model']['artifact'] = reseal(forged)[0]
    with pytest.raises(ValueError, match='SERVER_REFERENCE_MISMATCH'):
        InstrumentMethodLearning(seal(body), request)


def test_meta_reference_requires_actual_runtime_request_and_exact_sparse_shape():
    request, _, contract = case()
    with pytest.raises(ValueError, match='ACTUAL_REQUEST_REQUIRED'):
        validate_contract(contract, dataset_hash=request.replay_dataset_hash, execution_hash=contract['execution_hash'])
    body = {key: copy.deepcopy(value) for key, value in contract.items() if key not in {'contract_hash', 'contract_json'}}
    body['meta_model']['caller_score'] = .99
    with pytest.raises(ValueError, match='REFERENCE_FIELDS_REQUIRED'):
        InstrumentMethodLearning(seal(body), request)


def test_copied_ordinary_model_cannot_claim_shared_native_account_qualification():
    request, _, contract = case()
    with pytest.raises(ValueError, match='ORDINARY_ORIGINAL_ACCOUNT_SCOPE_REQUIRED'):
        InstrumentMethodLearning(contract, request, execution_owner='native_specialist_council_v1')
    with pytest.raises(ValueError, match='ORDINARY_ORIGINAL_ACCOUNT_SCOPE_REQUIRED'):
        validate_contract(contract, dataset_hash=request.replay_dataset_hash,
            execution_hash=contract['execution_hash'], payload=request,
            execution_owner='native_specialist_council_v1')


def test_actual_ordinary_replay_uses_finite_meta_model_without_external_gate_changes():
    request, data, contract = case()
    request.emit_decision_trace = True
    with patch('app.services.backtester.get_strategy', return_value=strategy):
        baseline = run_simple_ema_rsi_backtest_on_dataframe(request, data)
        request.policy_context['instrument_method_learning'] = contract
        variant = run_simple_ema_rsi_backtest_on_dataframe(request, data)
    assert baseline.total_trades > 0
    assert variant.total_trades == 0
    receipt = variant.data_quality['instrument_method_learning_receipt']
    assert receipt['used_count'] > 0
    assert all(item['meta_score_status'] == 'diagnostic_calibrated_score_available' for item in receipt['samples'])
    assert all(item['meta_model_hash'] == contract['meta_model']['artifact']['artifact_hash'] for item in receipt['samples'])
    assert baseline.data_quality['replay_executed_clock']['schedule_hash'] == variant.data_quality['replay_executed_clock']['schedule_hash']
    assert not receipt['economic_authority']
