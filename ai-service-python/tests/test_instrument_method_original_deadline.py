"""Future original deadlines; fixture HMACs are not research-window authority."""

import hashlib
import hmac
import os
from unittest.mock import patch

import pandas as pd
import pytest
from fastapi import HTTPException

from app import main
from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_original_deadline import (
    AUTHORITY, MARKER, PROTOCOL, verify_original_method_deadline, _original_source_utc, _utc,
)
from app.services.parameter_schema import validate_strategy_parameters
from app.services.research_program_tasks import canonical_hash, canonical_json
from test_instrument_method_learning import pinned_recipe, learning_contract, const

KEY = 'synthetic-private-original-method-key-not-production-0123456789'


def fixture(tmp_path, *, rows=224, seconds=600):
    frame = pd.DataFrame({'time': pd.date_range('2025-01-06T08:00Z', periods=rows, freq='5min'),
        'open': 100.0, 'high': 100.1, 'low': 99.9, 'close': 100.0})
    path = tmp_path / 'original.csv'
    frame.to_csv(path, index=False)
    sha = hashlib.sha256(path.read_bytes()).hexdigest()
    parameters = validate_strategy_parameters('ema_rsi_v1', {'ema_fast': 3, 'ema_slow': 10}, 'ema_rsi')
    request = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='M5',
        parameters=parameters, dataset_path=path.as_posix(), replay_dataset_hash=sha, evaluation_mode='incremental')
    recipe = pinned_recipe(request, 'deadline-fixture', {'confirmation': const(True)})
    contract = learning_contract(request, frame, [recipe])
    scope = {**contract['scope'], 'evaluated_start': frame.iloc[200]['time'].isoformat()}
    body = {name: value for name, value in contract.items() if name not in {'contract_hash', 'contract_json'}}
    body['scope'] = scope
    contract = {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}
    source = {'dataset_path': path.as_posix(), 'dataset_sha256': sha, 'loaded_rows': rows, 'warmup_rows': 200,
        'evaluated_rows': rows - 200, 'loaded_start': frame.iloc[0]['time'].isoformat(),
        'loaded_end': frame.iloc[-1]['time'].isoformat(), 'selection': 'entire_exact_source', 'independent_validation': False}
    caps = {'max_rows': min(4096, rows), 'max_decision_events': 4096, 'max_runtime_seconds': seconds}
    request.policy_context = {'instrument_method_learning': contract,
        'instrument_method_study': {'study_hash': 'a' * 64, 'arm': 'L1A1', 'resource_caps': caps},
        'instrument_method_study_resource_cap': caps, 'hard_limits': {'risk_per_trade_percent': 1.0},
        'instrument_method_execution': {'protocol': 'instrument_method_original_execution_v1', 'original_source': source,
            'research_only': True, 'uses_existing_simple_replay': True, 'independent_validation': False, 'promotion_evidence': False}}
    return sign(request), frame


def sign(request):
    policy = {name: value for name, value in request.policy_context.items() if name != MARKER}
    study = policy['instrument_method_study']
    method = policy['instrument_method_learning']
    body = {'protocol': PROTOCOL, 'operation': 'simple', 'study_hash': study['study_hash'], 'arm': study['arm'],
        'resource_caps': study['resource_caps'], 'symbol': request.symbol, 'timeframe': request.timeframe,
        'strategy': request.strategy, 'base_strategy': request.base_strategy, 'parameters_hash': canonical_hash(request.parameters),
        'data_hash': request.replay_dataset_hash, 'execution_hash': execution_contract_metadata(request)['execution_hash'],
        'method_contract_hash': method['contract_hash'], 'initial_balance': request.initial_balance,
        'risk_per_trade': request.risk_per_trade, 'scope': method['scope'],
        'source_identity': policy['instrument_method_execution']['original_source'],
        'policy_context_hash': canonical_hash(policy), 'research_release_hash': canonical_hash(request.research_release or []), **AUTHORITY}
    body['runtime_controls_hash'] = canonical_hash({'random_seed': request.random_seed,
        'signal_delay_candles': request.signal_delay_candles, 'emit_decision_trace': request.emit_decision_trace,
        'emit_trade_ledger': request.emit_trade_ledger})
    encoded = canonical_json(body)
    digest = hashlib.sha256(encoded.encode()).hexdigest()
    request.policy_context[MARKER] = {**body, 'contract_json': encoded, 'contract_hash': digest,
        'issuer_signature': hmac.new(KEY.encode(), (PROTOCOL + '\n' + digest).encode(), hashlib.sha256).hexdigest()}
    return request


def guards(tmp_path):
    from contextlib import ExitStack
    stack = ExitStack()
    stack.enter_context(patch('app.main._internal_api_token', return_value=KEY))
    stack.enter_context(patch('app.services.instrument_method_original_deadline._data_roots', return_value=(tmp_path,)))
    stack.enter_context(patch.dict(os.environ, {'AI_REPLAY_SIMPLE_HARD_TIMEOUT_SECONDS': '300'}))
    return stack


def test_signed_original_600_honors_declared_simple_budget_without_full_transport(tmp_path):
    request, _ = fixture(tmp_path)
    with guards(tmp_path), patch('app.main._verified_research_transport', side_effect=AssertionError('must not borrow full transport')):
        main._validate_method_learning_before_cache(request)
        assert main._bounded_replay_seconds(request, 'simple') == 600


def test_legacy_unsigned_study_caps_do_not_raise_ordinary_simple_deadline(tmp_path):
    request, _ = fixture(tmp_path)
    request.policy_context.pop(MARKER)
    with guards(tmp_path):
        assert main._bounded_replay_seconds(request, 'simple') == 300
    request.policy_context = {}
    with guards(tmp_path):
        assert main._bounded_replay_seconds(request, 'simple') == 300


def test_actual_simple_route_normalized_payload_selects_private_deadline_before_child(tmp_path):
    request, _ = fixture(tmp_path)
    selected = []
    def conditional_child(operation, actual):
        main._validate_native_diagnostics_before_cache(actual, operation=operation)
        selected.append(main._bounded_replay_seconds(actual, operation))
        raise HTTPException(status_code=418, detail='bounded software pre-worker proof only')
    with guards(tmp_path), patch('app.main._run_bounded_replay', side_effect=conditional_child):
        with pytest.raises(HTTPException) as caught:
            main.run_simple_backtest_api(request)
    assert caught.value.status_code == 418
    assert selected == [600]


def test_forged_signature_is_refused_before_cache_and_valid_marker_cannot_be_run_all(tmp_path):
    request, _ = fixture(tmp_path)
    bad = request.model_copy(deep=True)
    bad.policy_context[MARKER]['issuer_signature'] = '0' * 64
    with guards(tmp_path), patch('app.main._load_immutable_replay_cache') as cache:
        with pytest.raises(ValueError, match='ISSUER_SIGNATURE_MISMATCH'):
            main._run_all_backtests_sync(bad)
        cache.assert_not_called()
        with pytest.raises(ValueError, match='SIMPLE_ORIGINAL_SCOPE_REQUIRED'):
            main._run_all_backtests_sync(request)
        cache.assert_not_called()


@pytest.mark.parametrize('change', ['balance', 'risk', 'parameters', 'arm', 'scope', 'method', 'release', 'hard_limits', 'seed', 'delay', 'trace'])
def test_copied_marker_cannot_authorize_changed_actual_request(tmp_path, change):
    request, _ = fixture(tmp_path)
    if change == 'balance': request.initial_balance += 1
    elif change == 'risk': request.risk_per_trade += 1
    elif change == 'parameters': request.parameters['ema_fast'] += 1
    elif change == 'arm': request.policy_context['instrument_method_study']['arm'] = 'L2A2'
    elif change == 'scope': request.policy_context['instrument_method_learning']['scope']['evaluated_end'] = '2025-12-31T00:00:00Z'
    elif change == 'method': request.policy_context['instrument_method_learning']['contract_hash'] = 'f' * 64
    elif change == 'release': request.research_release = {'source_hash': 'f' * 64}
    elif change == 'hard_limits': request.policy_context['hard_limits']['risk_per_trade_percent'] = 99.0
    elif change == 'seed': request.random_seed = 99
    elif change == 'delay': request.signal_delay_candles = 3
    else: request.emit_decision_trace = not request.emit_decision_trace
    with guards(tmp_path), pytest.raises(ValueError):
        verify_original_method_deadline(request, KEY, operation='simple')


@pytest.mark.parametrize('change', ['601seconds', '4097rows', 'native', 'batch', 'tail', 'inline', 'full', 'authority',
    'date_filter', 'version', 'lane', 'mtf_manifest'])
def test_even_signed_declarations_cannot_escape_new_domain_ceiling(tmp_path, change):
    request, frame = fixture(tmp_path, rows=4097 if change == '4097rows' else 224,
        seconds=601 if change == '601seconds' else 600)
    if change == 'native': request.specialist_council_contract = {'members': [{'id': 'not-an-account-proof'}]}
    elif change == 'batch': request.strategies = [StrategyRuntimeConfig(strategy='ema_rsi_v1', base_strategy='ema_rsi')]
    elif change == 'tail': request.dataset_tail_rows = 201
    elif change == 'inline': request.candles = [frame.iloc[0].to_dict()]
    elif change == 'full': request.evaluation_mode = 'full'
    elif change == 'date_filter': request.from_date = pd.Timestamp('2025-01-07').date()
    elif change == 'version': request.version = 'copied-foreign-version'
    elif change == 'lane': request.twin_lane = 'council'
    elif change == 'mtf_manifest': request.mtf_snapshot_manifest = {'context': 'foreign-stream'}
    elif change == 'authority':
        body = request.policy_context[MARKER]
        body['paper_authority'] = True
        encoded = canonical_json({name: value for name, value in body.items() if name not in {'contract_json', 'contract_hash', 'issuer_signature'}})
        body.update(contract_json=encoded, contract_hash=hashlib.sha256(encoded.encode()).hexdigest())
        body['issuer_signature'] = hmac.new(KEY.encode(), (PROTOCOL + '\n' + body['contract_hash']).encode(), hashlib.sha256).hexdigest()
    with guards(tmp_path), pytest.raises(ValueError):
        verify_original_method_deadline(request, KEY, operation='simple')


def test_source_mutation_after_signing_is_not_hidden_by_cache_or_header_hash(tmp_path):
    request, _ = fixture(tmp_path)
    with open(request.dataset_path, 'a', encoding='utf-8') as source:
        source.write('2025-12-01T00:00:00Z,100,100,100,100\n')
    with guards(tmp_path), pytest.raises(ValueError, match='ORIGINAL_SOURCE_BYTES_CHANGED'):
        main._validate_method_learning_before_cache(request)


@pytest.mark.parametrize('raw', ['2025-10-13 07:10:00', '2025-10-13T07:10:00'])
def test_signed_original_raw_source_finite_bare_utc_vocabulary_only(raw):
    assert _original_source_utc(raw) == pd.Timestamp('2025-10-13T07:10:00Z')
    # The compatibility belongs only to original CSV cells, never scope/API.
    with pytest.raises(ValueError, match='EXPLICIT_UTC_REQUIRED'):
        _utc(raw)


@pytest.mark.parametrize('raw', ['2025-10-13', '13/10/2025 07:10:00', '2025-10-13 07:10',
    '2025-10-13 07:10:00.0', '2025-10-13T07:10:00+05:00', '2025-13-13 07:10:00'])
def test_original_csv_does_not_infer_other_local_clock_formats(raw):
    with pytest.raises(ValueError):
        _original_source_utc(raw)
