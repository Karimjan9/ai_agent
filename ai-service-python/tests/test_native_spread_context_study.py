import copy
import hashlib
import hmac
import json
from unittest.mock import patch

import pandas as pd
import pytest

from app.schemas import SimpleBacktestRequest, ExecutionConfig
from app.services.backtester import run_simple_ema_rsi_backtest, _instrument_runtime_context, _entry_price
from app.services.execution_contract import execution_contract_metadata
from app.services.native_spread_context_study import PROTOCOL, CRITERION, INTERVENTION, NativeSpreadContextStudy
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract

KEY = 'isolated-spread-study-test-only-hmac-key'
CONTEXT = {'regime': 'trend_up', 'volatility': 'normal', 'session': 'london', 'venue_phase': 'london_pre_am_fix', 'direction': 'BUY'}


def strategy(frame, parameters):
    output = frame.copy()
    output['signal'] = 'BUY'
    output['signal_confidence'] = 1.0
    output['atr'] = 1.0
    output['market_regime'] = 'trend_up'
    output['volatility_regime'] = 'normal_volatility'
    output['market_session'] = 'london'
    output['market_venue_phase'] = 'london_pre_am_fix'
    return output


def signed(request, arm, binding=None):
    native = request.specialist_council_contract
    body = {'protocol': PROTOCOL, 'study_id': 'conditional-quoted-source-fixture', 'arm': arm,
        'identity': {'native_contract_hash': native['contract_hash'],
            'target_member_hash': canonical_hash({'council_version': native['council_version'], 'member': native['members'][0]}),
            'specialist_id': 'scalp', 'dataset_hash': request.replay_dataset_hash, 'execution_hash': native['execution_hash'],
            'account_policy_hash': canonical_hash(native['policy']), 'initial_capital': request.initial_balance,
            'evaluation_policy_hash': canonical_hash(request.policy_context['prospective_probe_window']),
            'quote_provenance_hash': canonical_hash(request.mtf_snapshot_manifest['quote_spread_provenance']),
            'exact_context': CONTEXT, 'minimum_paired_opportunities': 1,
            'liquidity_atr_binding': binding if binding is not None else native['members'][0].get('liquidity_atr_binding')},
        'criterion': CRITERION, 'intervention': INTERVENTION, 'authority': 'research_only',
        'economic_authority': False, 'skill_authority': False, 'independent_evidence': False, 'promotion_evidence': False}
    body['identity_hash'] = canonical_hash(body['identity'])
    digest = canonical_hash(body)
    return {**body, 'contract_hash': digest, 'contract_json': canonical_json(body),
        'server_seal': {'protocol': 'native_spread_context_study_server_seal_v1',
            'hmac_sha256': hmac.new(KEY.encode(), (PROTOCOL + '\n' + digest).encode(), 'sha256').hexdigest()}}


def fixture(tmp_path, *, available=True, future=False, binding='closed_strategy_atr_v1'):
    times = pd.date_range('2025-01-06T08:00:00Z', periods=40, freq='5min')
    frame = pd.DataFrame({'time': times, 'open': 100.0, 'high': 100.05, 'low': 99.95, 'close': 100.0,
        'volume': 100, 'volume_available': True, 'spread_available': available, 'spread': .1,
        'bid_close': 100.0, 'ask_close': 100.1, 'quote_time_utc': times + pd.Timedelta(minutes=4, seconds=30),
        'quote_available_after_utc': times + pd.Timedelta(minutes=5), 'quote_age_ms': 30000})
    if future:
        frame.loc[12, 'quote_time_utc'] = times[12] + pd.Timedelta(minutes=5, seconds=1)
    path = tmp_path / 'actual-quoted-input.csv'; frame.to_csv(path, index=False)
    sha = hashlib.sha256(path.read_bytes()).hexdigest()
    request = SimpleBacktestRequest(symbol='XAUUSD', timeframe='M5', strategy='ema_rsi_v1', base_strategy='ema_rsi',
        evaluation_mode='incremental', dataset_path=str(path), replay_dataset_hash=sha,
        execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2, spread_points=2, point_size=.01))
    request.execution_contract = execution_contract_metadata(request)
    request.execution_contract['protocol'] = 'canonical_market_execution_v1'
    assignment = {'protocol': 'lab_instrument_research_assignment_v2', 'assignment_hash': 'a' * 64,
        'activation_policy': {'protocol': 'instrument_runtime_activation_contract_v1'}, 'selected': [
            {'instrument_key': 'regime_router', 'role': 'model', 'activation_contract': {
                'protocol': 'instrument_runtime_activation_contract_v1', 'required_runtime_events': ['router_selected:*'],
                'context': {'declared_context': {'spread_liquidity_state': 'normal'}}}}]}
    member = {'specialist_id': 'scalp', 'role': 'scalp', 'strategy': 'ema_rsi_v1', 'base_strategy': 'ema_rsi', 'version': 'v1',
        'strategy_version': 'v1', 'tactic_version': 'v1', 'management_version': 'v1', 'parameters': {'ema_fast': 4, 'ema_slow': 10},
        'horizon': {'decision_interval_bars': 1, 'reevaluation_interval_bars': 1, 'max_holding_bars': 4},
        'capital_weight': .25, 'risk_per_trade_percent': .1, 'instrument_research_assignment': assignment}
    if binding is not None:
        member['liquidity_atr_binding'] = binding
    policy = {'broker_position_mode': 'hedging', 'opposite_position_policy': 'reject', 'max_open_positions': 4,
        'max_reserved_capital_percent': 100, 'max_gross_exposure_percent': 100, 'max_total_risk_percent': 2,
        'max_drawdown_percent': 10, 'max_daily_loss_percent': 3, 'max_expected_cost_percent': 1}
    request.specialist_council_contract = seal_contract({'protocol': 'specialist_council_runtime_v1', 'council_id': 'conditional-study',
        'council_version': 'v1', 'execution_timeframe': 'M5', 'replay_dataset_hash': sha,
        'execution_hash': request.execution_contract['execution_hash'], 'members': [member], 'policy': policy, 'upgrades': []})
    stamp = lambda value: value.strftime('%Y-%m-%dT%H:%M:%SZ')
    probe = {'protocol': 'prospective_repair_probe_window_v1', 'evaluator_version': 'incremental_probe_window_v2',
        'experiment_key': 'conditional-spread-context-study', 'dataset_hash': sha, 'execution_hash': request.execution_contract['execution_hash'],
        'independent_validation': False, 'paper_2026_eligible': False, 'loaded_rows': 40, 'warmup_rows': 8, 'evaluated_rows': 32,
        'loaded_start': stamp(times[0]), 'loaded_end': stamp(times[-1]), 'evaluated_start': stamp(times[8]),
        'evaluated_end': stamp(times[-1]), 'evaluated_month_counts': {'2025-01': 32}}
    probe['contract_hash'] = hashlib.sha256(json.dumps(probe, separators=(',', ':')).encode()).hexdigest()
    request.policy_context['prospective_probe_window'] = probe
    request.mtf_snapshot_manifest['quote_spread_provenance'] = {'protocol': 'historical_quote_spread_snapshot_v1',
        'provider': 'dukascopy_historical_synchronized_tick_v1', 'maximum_quote_age_ms': 60000,
        'paper_2026_included': False, 'promotion_evidence': False, 'source_m5_csv_sha256': sha,
        'sources': [{'sha256': sha, 'fixture': 'conditional_real_csv_quote_values_not_provider_qualification'}]}
    return request, path


def run(request, arm, *, strategy_function=strategy):
    request = request.model_copy(deep=True)
    request.native_spread_context_study_contract = signed(request, arm)
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester.get_strategy', return_value=strategy_function):
        return run_simple_ema_rsi_backtest(request)


def test_only_computed_context_field_is_masked_and_actual_pricing_frame_stays_unchanged(tmp_path):
    request, path = fixture(tmp_path)
    original_bytes = path.read_bytes()
    masked, unmasked = run(request, 'masked'), run(request, 'unmasked')
    first_m, first_u = masked.native_spread_context_study_receipt['events'][0], unmasked.native_spread_context_study_receipt['events'][0]
    assert first_m['gate_context']['spread_liquidity_state'] == 'unknown'
    assert first_u['gate_context']['spread_liquidity_state'] == 'liquid'
    assert first_m['action'] == 'WAIT' and first_u['action'] == 'ENTRY'
    assert first_m['gate_reached'] and first_u['gate_reached'] and first_m['feature_gate_reached'] and first_u['feature_gate_reached']
    for key in ('event_id', 'closed_input_hash', 'source_quote_hash', 'source_context_hash', 'account_before_gate_hash'):
        assert first_m[key] == first_u[key]
    assert masked.execution_assumptions == unmasked.execution_assumptions == request.execution.model_dump()
    assert path.read_bytes() == original_bytes
    assert unmasked.trades[0].entry_price == _entry_price(100, 'BUY', request) == 100.01
    assert first_u['source_quote']['ask'] == 100.1
    assert masked.data_quality['replay_executed_clock']['schedule_hash'] == unmasked.data_quality['replay_executed_clock']['schedule_hash']
    assert masked.native_spread_context_study_receipt['economic_authority'] is False


def test_missing_quote_is_dependency_and_no_raw_signal_is_underpowered(tmp_path):
    request, _ = fixture(tmp_path, available=False)
    result = run(request, 'unmasked')
    assert result.native_spread_context_study_receipt['status'] == 'data_missing'
    assert result.native_spread_context_study_receipt['counts']['missing_quote_opportunities'] > 0
    request, _ = fixture(tmp_path, available=True)
    def no_signal(frame, parameters):
        output = strategy(frame, parameters); output['signal'] = 'WAIT'; return output
    result = run(request, 'unmasked', strategy_function=no_signal)
    assert result.native_spread_context_study_receipt['status'] == 'underpowered'
    assert result.native_spread_context_study_receipt['events'] == []


def test_future_quote_is_refused_and_unsigned_study_fails_before_dataset_compute(tmp_path):
    request, _ = fixture(tmp_path, future=True)
    with pytest.raises(ValueError, match='HISTORICAL_QUOTE_ALIGNMENT_INVALID'):
        run(request, 'unmasked')
    request, _ = fixture(tmp_path)
    request.native_spread_context_study_contract = signed(request, 'masked')
    request.native_spread_context_study_contract['server_seal']['hmac_sha256'] = '0' * 64
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('compute entered')):
        with pytest.raises(ValueError, match='SERVER_SEAL_INVALID'):
            run_simple_ema_rsi_backtest(request)


def test_context_mask_is_pure_and_does_not_claim_full_feature_blindness():
    row = {'time': pd.Timestamp('2025-01-06T08:00:00Z'), 'atr': 1., 'spread': .1, 'spread_available': True, 'close': 100.}
    original = dict(row)
    visible = _instrument_runtime_context(row, 'BUY')
    masked = _instrument_runtime_context(row, 'BUY', spread_liquidity_masked=True)
    assert row == original
    assert masked == {**visible, 'spread_liquidity_state': 'unknown'}


def test_cached_study_requires_original_arm_contract_and_actual_clock_receipt(tmp_path):
    from app import main
    request, _ = fixture(tmp_path)
    request.native_spread_context_study_contract = signed(request, 'masked')
    result = run(request, 'masked').model_dump(mode='json')
    item = {'result': result}
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}):
        assert main._candidate_cache_contract_is_current(item, request)
        for field in ('native_spread_context_study_receipt', 'specialist_council_receipt'):
            legacy = copy.deepcopy(item)
            legacy['result'].pop(field)
            assert not main._candidate_cache_contract_is_current(legacy, request)
        for key, value in [('arm', 'unmasked'), ('contract_hash', '0' * 64), ('identity_hash', '0' * 64)]:
            stale = copy.deepcopy(item)
            witness = stale['result']['native_spread_context_study_receipt']
            body = {field: original for field, original in witness.items() if field not in {'receipt_hash', 'receipt_json'}}
            body[key] = value
            changed = {**body, 'receipt_hash': canonical_hash(body), 'receipt_json': canonical_json(body)}
            stale['result']['native_spread_context_study_receipt'] = changed
            stale['result']['data_quality']['native_spread_context_study_receipt'] = changed
            assert not main._candidate_cache_contract_is_current(stale, request)
        opposite = request.model_copy(deep=True)
        opposite.native_spread_context_study_contract = signed(opposite, 'unmasked')
        assert not main._candidate_cache_contract_is_current(item, opposite)


def test_malformed_original_window_fails_before_loading_dataset(tmp_path):
    request, _ = fixture(tmp_path)
    request.policy_context['prospective_probe_window']['evaluated_rows'] = '32'
    request.native_spread_context_study_contract = signed(request, 'masked')
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('compute entered')):
        with pytest.raises(ValueError, match='ORIGINAL_REQUEST_MISMATCH'):
            run_simple_ema_rsi_backtest(request)


def test_equal_cash_is_not_equal_pre_gate_causal_history(tmp_path):
    request, _ = fixture(tmp_path)
    observer = NativeSpreadContextStudy(signed(request, 'masked'), request, {})
    observer.current = {}
    observer.account = {'cash': 10000., 'positions': [], 'confidence_history': {}, 'temporal_state': {'pending': []}}
    observer.gate({**CONTEXT, 'spread_liquidity_state': 'unknown'}, False, True)
    original_hash = observer.current['account_before_gate_hash']
    observer.account['confidence_history'] = {'trend_up:BUY': [0.]}
    observer.gate({**CONTEXT, 'spread_liquidity_state': 'unknown'}, False, True)
    assert observer.current['account_before_gate_hash'] != original_hash


def test_explicit_closed_management_port_is_same_actual_input_in_gate_observer_and_no_study_program(tmp_path):
    request, path = fixture(tmp_path, binding='closed_m5_management_atr_v1')
    body = {key: value for key, value in request.specialist_council_contract.items() if key not in {'contract_hash', 'contract_json'}}
    body['members'][0]['instrument_research_assignment']['selected'][0]['activation_contract']['context']['declared_context']['spread_liquidity_state'] = 'high'
    request.specialist_council_contract = seal_contract(body)
    def no_public_atr(frame, parameters):
        output = strategy(frame, parameters); output = output.drop(columns=['atr']); return output
    masked = run(request, 'masked', strategy_function=no_public_atr)
    unmasked = run(request, 'unmasked', strategy_function=no_public_atr)
    first_m, first_u = masked.native_spread_context_study_receipt['events'][0], unmasked.native_spread_context_study_receipt['events'][0]
    quote = first_u['source_quote']
    assert quote['atr_binding'] == 'closed_m5_management_atr_v1' and quote['atr_source_key'] == '_management_atr'
    assert quote['feature_available'] and quote['observed_state'] == 'illiquid'
    assert quote['atr'] == pytest.approx(.1)
    assert quote['atr_input_hash'] == canonical_hash({'source_key': '_management_atr', 'value': quote['atr']})
    assert first_m['action'] == 'WAIT' and first_u['action'] == 'ENTRY'
    with patch('app.services.backtester.get_strategy', return_value=no_public_atr):
        no_study = run_simple_ema_rsi_backtest(request)
    assert no_study.specialist_council_receipt == unmasked.specialist_council_receipt
    assert no_study.trades == unmasked.trades
    assert no_study.native_spread_context_study_receipt == {}
    assert no_study.specialist_council_receipt['promotion_evidence'] is False
    assert hashlib.sha256(path.read_bytes()).hexdigest() == request.replay_dataset_hash


def test_requested_public_atr_never_guesses_existing_management_or_structure_field(tmp_path):
    request, _ = fixture(tmp_path)
    def other_fields_only(frame, parameters):
        output = strategy(frame, parameters).drop(columns=['atr']); output['structure_atr'] = 1.; return output
    result = run(request, 'unmasked', strategy_function=other_fields_only)
    receipt = result.native_spread_context_study_receipt
    assert receipt['status'] == 'data_missing'
    assert receipt['counts']['missing_quote_opportunities'] == 0
    assert receipt['counts']['missing_context_feature_opportunities'] > 0
    assert all(event['source_quote']['atr'] is None and event['source_quote']['atr_source_key'] == 'atr' for event in receipt['events'])
    assert all(event['action'] == 'WAIT' for event in receipt['events'])


@pytest.mark.parametrize('invalid_atr', [0., -1., float('nan'), float('inf')])
def test_nonpositive_or_nonfinite_explicit_source_feature_is_data_missing(tmp_path, invalid_atr):
    request, _ = fixture(tmp_path)
    def invalid_source(frame, parameters):
        output = strategy(frame, parameters); output['atr'] = invalid_atr; return output
    result = run(request, 'unmasked', strategy_function=invalid_source)
    receipt = result.native_spread_context_study_receipt
    assert receipt['status'] == 'data_missing' and receipt['counts']['missing_context_feature_opportunities'] > 0
    assert receipt['counts']['missing_quote_opportunities'] == 0
    assert not any(event['source_quote']['feature_available'] for event in receipt['events'])


def test_binding_must_be_single_supported_native_port_and_match_original_study_before_compute(tmp_path):
    request, _ = fixture(tmp_path, binding='closed_m5_management_atr_v1')
    request.native_spread_context_study_contract = signed(request, 'masked', 'closed_strategy_atr_v1')
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('compute entered')):
        with pytest.raises(ValueError, match='MEMBER_INVALID'):
            run_simple_ema_rsi_backtest(request)
    for unsupported in ('atr_regime', ['closed_strategy_atr_v1', 'closed_structure_atr_v1']):
        request, _ = fixture(tmp_path, binding=unsupported)
        with patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('compute entered')):
            with pytest.raises(ValueError, match='LIQUIDITY_ATR_BINDING_INVALID'):
                run_simple_ema_rsi_backtest(request)
    request, _ = fixture(tmp_path, binding='closed_m5_management_atr_v1')
    request.timeframe = 'M15'
    request.execution_contract = execution_contract_metadata(request)
    request.execution_contract['protocol'] = 'canonical_market_execution_v1'
    body = {key: value for key, value in request.specialist_council_contract.items() if key not in {'contract_hash', 'contract_json'}}
    body['execution_timeframe'] = 'M15'; body['execution_hash'] = request.execution_contract['execution_hash']
    request.specialist_council_contract = seal_contract(body)
    with patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('compute entered')):
        with pytest.raises(ValueError, match='LIQUIDITY_ATR_BINDING_INVALID'):
            run_simple_ema_rsi_backtest(request)
