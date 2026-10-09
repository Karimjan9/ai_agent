"""Synthetic source mechanism tests; native producer, no market qualification."""

import copy
import hashlib
import hmac
from functools import cache
from unittest.mock import patch

import pandas as pd
import pytest

from app import main
from app.schemas import SimpleBacktestRequest
from app.services import backtester
from app.services.execution_contract import execution_contract_metadata
from app.services.native_reachability_depth_audit import (
    PROTOCOL, SEAL_PROTOCOL, DECLARATION_PROTOCOL, VIEW_PROTOCOL, SELECTION_PROTOCOL,
    STANDALONE_PROTOCOL, STANDALONE_SEAL_PROTOCOL, STANDALONE_CRITERIA,
    standalone_qualification_statistics, validate_standalone_qualification, NativeReachabilityDepthAudit, raw_source_signal, validate_depth_audit,
)
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract, validate_contract
from test_clean_discovery_boundary import closed_mtf_discovery_request
from test_specialist_council import fixtures, member

KEY = 'isolated-native-depth-mechanism-fixture-hmac-key'
CONTEXT = {'regime': 'trend_up', 'volatility': 'normal', 'session': 'london', 'venue_phase': 'london_pre_am_fix', 'direction': 'BUY'}


def signed_depth(request, arm='cheap', *, cheap=8, deeper=16, minimum=1, selected=None, seed='native-depth-unit-seed-42'):
    native = request.specialist_council_contract
    declaration = {'protocol': DECLARATION_PROTOCOL, 'criterion': 'instrument_gate_reached',
        'minimum_observed_opportunities': minimum, 'contexts': {role: dict(CONTEXT) for role in ('scalp', 'hour', 'day', 'swing')},
        'cheap_evaluated_rows': cheap, 'deeper_evaluated_rows': deeper, 'sample_cap': 2, 'seed': seed,
        'initial_capital': request.initial_balance}
    identity = {'native_contract_hash': native['contract_hash'], 'dataset_hash': request.replay_dataset_hash,
        'execution_hash': native['execution_hash'], 'account_policy_hash': canonical_hash(native['policy']),
        'initial_capital': request.initial_balance, 'evaluation_policy_hash': canonical_hash(request.policy_context['prospective_probe_window']),
        'context_hash': canonical_hash(declaration['contexts']), 'declaration_hash': canonical_hash(declaration),
        'members_hash': canonical_hash(native['members']),
        'quote_provenance_hash': canonical_hash(request.mtf_snapshot_manifest.get('quote_spread_provenance') or {})}
    rows = cheap if arm == 'cheap' else deeper
    selection = None
    if arm == 'deeper':
        selected = selected or ['scalp']
        by_id = {item['specialist_id']: canonical_hash({'council_version': native['council_version'], 'member': item}) for item in native['members']}
        selection = {'protocol': SELECTION_PROTOCOL, 'cheap_run_id': 123, 'cheap_response_hash': 'c' * 64,
            'cheap_receipt_hash': 'd' * 64, 'pool_hash': 'e' * 64, 'selected_specialist_ids': selected,
            'selected_member_hashes': [by_id[item] for item in selected]}
        selection['sample_hash'] = canonical_hash(selection)
    body = {'protocol': PROTOCOL, 'audit_id': 'synthetic-native-mechanism-test', 'arm': arm,
        'identity': identity, 'identity_hash': canonical_hash(identity), 'declaration': declaration,
        'execution_view': {'protocol': VIEW_PROTOCOL, 'selection': 'prefix', 'evaluated_rows': rows,
            'decision_rows': rows - 1, 'warmup_rows': 512, 'source_evaluated_rows': 15000, 'source_loaded_rows': 15512},
        'selection': selection, 'authority': 'research_only', 'economic_authority': False, 'skill_authority': False,
        'independent_evidence': False, 'promotion_evidence': False}
    digest = canonical_hash(body)
    return {**body, 'contract_hash': digest, 'contract_json': canonical_json(body),
        'server_seal': {'protocol': SEAL_PROTOCOL, 'hmac_sha256': hmac.new(KEY.encode(), (PROTOCOL + '\n' + digest).encode(), 'sha256').hexdigest()}}


@pytest.fixture
def depth_request(closed_mtf_discovery_request):
    request = closed_mtf_discovery_request.model_copy(deep=True)
    path = request.dataset_path
    frame = pd.read_csv(path)
    times = pd.to_datetime(frame['time'], utc=True)
    frame['bid_close'] = frame['close']
    frame['ask_close'] = frame['close'] + .1
    frame['spread_available'] = True
    frame['spread'] = .1
    frame['quote_age_ms'] = 30000
    frame['quote_time_utc'] = times + pd.Timedelta(minutes=4, seconds=30)
    frame['quote_available_after_utc'] = times + pd.Timedelta(minutes=5)
    frame.to_csv(path, index=False)
    sha = hashlib.sha256(open(path, 'rb').read()).hexdigest()
    request.mtf_snapshot_manifest['streams']['M5']['sha256'] = sha
    request.mtf_snapshot_manifest['quote_spread_provenance'] = {'protocol': 'historical_quote_spread_snapshot_v1',
        'provider': 'dukascopy_historical_synchronized_tick_v1', 'maximum_quote_age_ms': 60000,
        'paper_2026_included': False, 'promotion_evidence': False, 'source_m5_csv_sha256': sha,
        'sources': [{'sha256': sha, 'fixture_economic_evidence': False}]}
    request.execution_contract = execution_contract_metadata(request)
    request.execution_contract['protocol'] = 'canonical_market_execution_v1'
    probe = request.policy_context['prospective_probe_window']
    probe['execution_hash'] = request.execution_contract['execution_hash']
    import json
    probe['contract_hash'] = hashlib.sha256(json.dumps({key: value for key, value in probe.items() if key != 'contract_hash'}, separators=(',', ':')).encode()).hexdigest()
    members = [member(role, holding=50) for role in ('scalp', 'hour', 'day', 'swing')]
    policy = fixtures()[0].specialist_council_contract['policy']
    request.specialist_council_contract = seal_contract({'protocol': 'specialist_council_runtime_v1',
        'council_id': 'synthetic-full-inventory', 'council_version': 'synthetic-v1', 'execution_timeframe': 'M5',
        'replay_dataset_hash': request.replay_dataset_hash, 'execution_hash': request.execution_contract['execution_hash'],
        'members': members, 'policy': policy, 'upgrades': []})
    request.emit_decision_trace = True
    return request


def source_strategy(frame, parameters):
    result = frame.copy()
    result['signal'] = 'BUY'
    result['signal_confidence'] = 1.0
    result['market_regime'] = 'trend_up'
    result['volatility_regime'] = 'normal_volatility'
    result['market_session'] = 'london'
    result['market_venue_phase'] = 'london_pre_am_fix'
    return result


def replay(request, *, strategy=source_strategy):
    # Preserve the real calendar predicate and every gap check. Memoize only
    # its year-invariant holiday set for this synthetic full-inventory test.
    holidays = cache(backtester._xau_market_holidays)
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._xau_market_holidays', side_effect=holidays), \
            patch('app.services.backtester.get_strategy', return_value=strategy):
        return backtester.run_simple_ema_rsi_backtest(request)


def test_native_prefix_preserves_physical_inventory_original_probe_and_four_member_account(depth_request):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request)
    original_probe = copy.deepcopy(request.policy_context['prospective_probe_window'])
    result = replay(request)
    native, audit = result.specialist_council_receipt, result.native_reachability_depth_audit_receipt
    assert native['source_rows'] == audit['physical_source_rows'] == 15512
    assert native['execution_input_rows'] == audit['execution_input_rows'] == 520
    assert native['evaluated_scope']['rows'] == 8 and native['evaluated_scope']['decision_rows'] == 7
    assert native['replay_executed_clock']['input_rows'] == 520
    assert native['replay_executed_clock']['decision_rows'] == 7
    assert len(native['members']) == 4 and len(audit['pool']) == 4
    assert all(row['counts']['raw_opportunities'] == 7 for row in audit['pool'])
    assert all(row['counts']['observed_quote_opportunities'] == 7 for row in audit['pool'])
    assert all(row['status'] == 'reached' for row in audit['pool'])
    assert len(result.decision_trace) == 7 and result.decision_trace[-1]['candle_index'] == 519
    assert result.data_quality['decision_trace']['complete']
    assert result.prospective_probe_window_receipt == {**original_probe, 'complete': True}
    assert request.policy_context['prospective_probe_window'] == original_probe
    assert audit == result.data_quality['native_reachability_depth_audit_receipt']
    assert audit['pool_hash'] == canonical_hash(audit['pool']) and audit['events_hash'] == canonical_hash(audit['events'])
    assert audit['economic_authority'] is False
    assert audit['effort']['executed_decision_rows'] == 7
    assert audit['effort']['process_cpu_seconds'] >= 0 and audit['effort']['elapsed_seconds'] >= 0


def test_terminal_forceclose_uses_actual_view_last_close_never_physical_future_tail(depth_request):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request, cheap=2)
    result = replay(request)
    frame = pd.read_csv(request.dataset_path)
    assert result.specialist_council_receipt['replay_end'] == (pd.Timestamp(frame.iloc[513]['time']) + pd.Timedelta(minutes=5)).isoformat()
    censored = [row for row in result.specialist_council_receipt['position_ledger'] if row['exit_reason'] == 'end_of_data']
    assert censored and all(row['outcome_matured'] is False for row in censored)
    assert all(trade.exit_price == pytest.approx(backtester._exit_price(float(frame.iloc[513]['close']), trade.direction, request)) for trade in result.trades if trade.exit_reason == 'end_of_data')
    assert float(frame.iloc[513]['close']) != float(frame.iloc[-1]['close'])


def test_pure_observer_deeper_selection_keeps_whole_programme_and_account(depth_request):
    cheap = depth_request.model_copy(deep=True)
    cheap.native_reachability_depth_audit_contract = signed_depth(cheap, cheap=8, deeper=16)
    deeper = depth_request.model_copy(deep=True)
    deeper.native_reachability_depth_audit_contract = signed_depth(deeper, arm='deeper', selected=['hour', 'swing'])
    result = replay(deeper)
    pool = result.native_reachability_depth_audit_receipt['pool']
    assert len(result.specialist_council_receipt['members']) == len(pool) == 4
    assert {row['specialist_id'] for row in pool if row['observed']} == {'hour', 'swing'}
    assert {row['status'] for row in pool if not row['observed']} == {'not_selected'}
    assert all(row['stages']['decision:observed'] > 0 for row in result.specialist_council_receipt['members'])
    assert {row['role'] for row in result.specialist_council_receipt['intent_execution_ledger'] if row['reason'] == 'created'} == {'scalp', 'hour', 'day', 'swing'}
    assert all({member['specialist_id'] for member in row['member_decisions']} == {'scalp', 'hour', 'day', 'swing'} for row in result.decision_trace)
    assert result.native_reachability_depth_audit_receipt['status'] == 'computed'


def test_no_signal_unknown_quotes_and_underpower_are_dependencies_never_negative(depth_request):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request, minimum=8)
    result = replay(request)
    assert {row['status'] for row in result.native_reachability_depth_audit_receipt['pool']} == {'dependency'}
    def wait(frame, parameters):
        result = source_strategy(frame, parameters); result['signal'] = 'WAIT'; return result
    result = replay(request, strategy=wait)
    assert {row['status'] for row in result.native_reachability_depth_audit_receipt['pool']} == {'dependency'}
    assert result.native_reachability_depth_audit_receipt['events'] == []
    # Unknown quotes are real sealed source values. A strategy cannot rewrite
    # verified quote columns after loading to manufacture that dependency.
    frame = pd.read_csv(request.dataset_path)
    frame['spread_available'] = False
    frame.to_csv(request.dataset_path, index=False)
    with open(request.dataset_path, 'rb') as source:
        sha = hashlib.sha256(source.read()).hexdigest()
    request.mtf_snapshot_manifest['streams']['M5']['sha256'] = sha
    provenance = request.mtf_snapshot_manifest['quote_spread_provenance']
    provenance['source_m5_csv_sha256'] = sha
    provenance['sources'][0]['sha256'] = sha
    # This is a distinct sealed synthetic source request, not a retrofit of an
    # already observed source or a strategy-authored quote replacement.
    request.replay_dataset_hash = canonical_hash({key: value['sha256'] for key, value in request.mtf_snapshot_manifest['streams'].items()})
    request.mtf_snapshot_manifest['bundle_hash'] = request.replay_dataset_hash
    probe = request.policy_context['prospective_probe_window']
    probe['dataset_hash'] = request.replay_dataset_hash
    import json
    probe['contract_hash'] = hashlib.sha256(json.dumps({key: value for key, value in probe.items() if key != 'contract_hash'}, separators=(',', ':')).encode()).hexdigest()
    native = {key: value for key, value in request.specialist_council_contract.items() if key not in {'contract_hash', 'contract_json'}}
    native['replay_dataset_hash'] = request.replay_dataset_hash
    request.specialist_council_contract = seal_contract(native)
    request.native_reachability_depth_audit_contract = signed_depth(request, minimum=8)
    result = replay(request)
    assert all(row['counts']['missing_quote_opportunities'] == 7 for row in result.native_reachability_depth_audit_receipt['pool'])
    assert {row['status'] for row in result.native_reachability_depth_audit_receipt['pool']} == {'dependency'}


def test_powered_exact_raw_quotes_before_cadence_can_produce_valid_rejected_pool(depth_request):
    request = depth_request
    native = {key: value for key, value in request.specialist_council_contract.items() if key not in {'contract_hash', 'contract_json'}}
    for item in native['members']:
        item['horizon']['decision_interval_bars'] = 10000
    request.specialist_council_contract = seal_contract(native)
    request.native_reachability_depth_audit_contract = signed_depth(request)
    result = replay(request)
    pool = result.native_reachability_depth_audit_receipt['pool']
    assert {row['status'] for row in pool} == {'rejected'}
    assert all(row['counts']['observed_quote_opportunities'] == 7 and row['counts']['gate_reached_opportunities'] == 0 for row in pool)
    assert result.total_trades == 0


@pytest.mark.parametrize('mutation', ['unsigned', 'copy', 'mode', 'tail', 'short_probe', 'stream', 'whole_programme', 'criterion', 'quote_provenance'])
def test_invalid_depth_cannot_load_sources_or_return_api_cache(depth_request, mutation):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request)
    if mutation == 'unsigned': request.native_reachability_depth_audit_contract['server_seal']['hmac_sha256'] = '0' * 64
    elif mutation == 'copy': request.native_reachability_depth_audit_contract['execution_view']['evaluated_rows'] = 3
    elif mutation == 'mode': request.evaluation_mode = 'full'
    elif mutation == 'tail': request.dataset_tail_rows = 520
    elif mutation == 'short_probe': request.policy_context['prospective_probe_window']['evaluated_rows'] = 8
    elif mutation == 'stream': request.mtf_snapshot_manifest['streams'].pop('H4')
    elif mutation == 'whole_programme':
        body = {key: value for key, value in request.specialist_council_contract.items() if key not in {'contract_hash', 'contract_json'}}
        body['members'] = body['members'][:1]; request.specialist_council_contract = seal_contract(body)
    elif mutation == 'criterion': request.native_reachability_depth_audit_contract['declaration']['criterion'] = 'net_pnl_positive'
    elif mutation == 'quote_provenance': request.mtf_snapshot_manifest['quote_spread_provenance']['provider'] = 'changed-provider'
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('invalid depth loaded source')), \
            patch('app.main._load_immutable_replay_cache', side_effect=AssertionError('invalid depth returned cache')):
        with pytest.raises(ValueError): backtester.run_simple_ema_rsi_backtest(request)
        with pytest.raises(ValueError): main._run_all_backtests_sync(request)
        with pytest.raises(ValueError): main._run_bounded_replay('run_all', request)


@pytest.mark.parametrize('seed', ['a', 'native-depth-unit-seed-42', '42', 'a' * 128])
def test_original_bounded_string_seed_is_preserved_without_coercion_or_hash_change(depth_request, seed):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request, seed=seed)
    original = copy.deepcopy(request.native_reachability_depth_audit_contract)
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('seed validation entered replay')):
        validated = validate_depth_audit(request, validate_contract(request))
    assert validated['declaration']['seed'] == seed and isinstance(validated['declaration']['seed'], str)
    assert validated['contract_hash'] == original['contract_hash']
    assert request.native_reachability_depth_audit_contract == original


@pytest.mark.parametrize('seed', ['', ' leading', 'trailing ', 'a' * 129, 42, True, None])
def test_seed_must_be_an_original_nonempty_trimmed_bounded_string_before_source_loading(depth_request, seed):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request, seed=seed)
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('invalid seed loaded source')):
        with pytest.raises(ValueError, match='NATIVE_REACHABILITY_DEPTH_AUDIT_DECLARATION_INVALID'):
            validate_contract(request)


@pytest.mark.parametrize('timeframe', ['M5', 'M15', 'H1', 'H4'])
def test_signed_view_cannot_bypass_any_physical_stream_byte_hash(depth_request, timeframe):
    request = depth_request
    request.mtf_snapshot_manifest['streams'][timeframe]['sha256'] = '0' * 64
    request.native_reachability_depth_audit_contract = signed_depth(request)
    with pytest.raises(ValueError, match='HASH_MISMATCH'):
        replay(request)


def test_cache_requires_current_actual_diagnostic_view_and_complete_receipt(depth_request):
    request = depth_request
    request.native_reachability_depth_audit_contract = signed_depth(request)
    item = {'result': replay(request).model_dump(mode='json')}
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}):
        assert main._candidate_cache_contract_is_current(item, request)
        legacy = copy.deepcopy(item); legacy['result'].pop('native_reachability_depth_audit_receipt')
        assert not main._candidate_cache_contract_is_current(legacy, request)
        deeper = request.model_copy(deep=True); deeper.native_reachability_depth_audit_contract = signed_depth(deeper, arm='deeper')
        assert not main._candidate_cache_contract_is_current(item, deeper)


def test_actual_upstream_closed_port_survives_wait_without_inventing_gate_reach(depth_request):
    from types import SimpleNamespace
    request = depth_request
    contract = signed_depth(request)
    native = request.specialist_council_contract
    runtime = SimpleNamespace(identity='scalp', declaration=native['members'][0],
        version_hash=canonical_hash({'council_version': native['council_version'], 'member': native['members'][0]}))
    source_sha = request.mtf_snapshot_manifest['streams']['M5']['sha256']
    observer = NativeReachabilityDepthAudit(contract, request, native, {'status': 'verified', 'actual_source_sha256': source_sha})
    prior = pd.read_csv(request.dataset_path).iloc[512].to_dict()
    prior.update(signal='WAIT', composition_strategy_signal='BUY', pre_specialist_signal='SELL',
        market_regime='trend_up', volatility_regime='normal_volatility', market_session='london', market_venue_phase='london_pre_am_fix')
    assert raw_source_signal(prior) == ('composition_strategy_signal', 'BUY')
    observer.begin(runtime, prior, 1, 513, pd.Timestamp(prior['time']) + pd.Timedelta(minutes=5))
    assert observer.counts['scalp']['raw_opportunities'] == observer.counts['scalp']['observed_quote_opportunities'] == 1
    assert observer.events[0]['raw_signal_source'] == 'composition_strategy_signal'
    assert observer.events[0]['raw_signal_hash'] == canonical_hash({'source_key': 'composition_strategy_signal', 'value': 'BUY'})
    observer.gate(runtime, 'SELL')
    assert not observer.events[0]['gate_reached']
    observer.gate(runtime, 'BUY')
    assert observer.events[0]['gate_reached']
    # A present WAIT upstream port stays WAIT, even if a later port says BUY.
    assert raw_source_signal({'composition_strategy_signal': 'WAIT', 'signal': 'BUY'}) == ('composition_strategy_signal', 'WAIT')


def signed_standalone(request):
    criteria = {**STANDALONE_CRITERIA, 'minimum_paired_trades': 20}
    body = {'protocol': STANDALONE_PROTOCOL, 'panel_plan_hash': 'a' * 64, 'source_projection_hash': 'b' * 64,
        'criteria_hash': canonical_hash(criteria), 'criteria': criteria}
    digest = canonical_hash(body)
    return {**body, 'declaration_hash': digest, 'declaration_json': canonical_json(body),
        'server_seal': hmac.new(KEY.encode(), (STANDALONE_SEAL_PROTOCOL + '\n' + digest).encode(), 'sha256').hexdigest()}


def standalone_request():
    source = member('hour'); source['capital_weight'] = 1.0
    request, frame = fixtures([source])
    request.evaluation_mode = 'full'
    request.native_standalone_qualification = signed_standalone(request)
    return request, frame


def test_standalone_statistics_hash_ordered_full_precision_actual_net_pnl_and_detect_all_censors():
    request, _ = standalone_request()
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}):
        native = validate_contract(request)
        declaration = validate_standalone_qualification(request, native)
    values = [1.123456789123 + index / 1000000 if index % 3 else -.3123456789123 for index in range(24)]
    ledger = [{'net_pnl': value, 'outcome_matured': True, 'exit_reason': 'take_profit'} for value in values]
    stats = standalone_qualification_statistics(declaration, native, request, ledger)
    assert stats['input_hash'] == canonical_hash(values)
    assert stats['input_hash'] != canonical_hash([round(value, 6) for value in values])
    assert stats['status'] == 'complete' and stats['mature_trade_count'] == stats['trade_count'] == 24
    assert stats['bootstrap']['simulations'] == 500 and stats['bootstrap']['seed'] == 42
    assert 'qualified' not in stats and stats['economic_authority'] is False
    ledger += [{'net_pnl': 99.0, 'outcome_matured': True, 'exit_reason': 'end_of_data'},
        {'net_pnl': 99.0, 'outcome_matured': True, 'exit_reason': 'hard_drawdown'},
        {'net_pnl': 99.0, 'outcome_matured': True, 'exit_reason': 'forced_closed'},
        {'net_pnl': 99.0, 'exit_reason': 'take_profit'}]
    stats = standalone_qualification_statistics(declaration, native, request, ledger)
    assert stats['input_hash'] == canonical_hash(values)
    assert stats['trade_count'] == 28 and stats['censored_trade_count'] == 3 and stats['unknown_maturity_count'] == 1
    assert stats['status'] == 'unknown'


def test_full_native_statistics_are_inside_actual_native_receipt_seal():
    request, frame = standalone_request()
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester.get_strategy', return_value=source_strategy):
        result = backtester.run_simple_ema_rsi_backtest_on_dataframe(request, frame)
        receipt = result.specialist_council_receipt
        assert receipt['standalone_qualification_statistics']['trade_count'] == len(receipt['position_ledger'])
        assert receipt['standalone_qualification_statistics']['censored_trade_count'] >= 1
        assert receipt['standalone_qualification_statistics']['status'] == 'incomplete'
        from app.services.specialist_council import receipt_hash_is_current
        assert receipt_hash_is_current(receipt)


@pytest.mark.parametrize('mutation', ['unsigned', 'copy', 'threshold', 'mode', 'allocation', 'depth', 'spread'])
def test_standalone_unsigned_changed_threshold_or_nonfull_views_refuse_before_source(mutation):
    request, _ = standalone_request()
    if mutation == 'unsigned': request.native_standalone_qualification['server_seal'] = '0' * 64
    elif mutation == 'copy': request.native_standalone_qualification['source_projection_hash'] = 'c' * 64
    elif mutation == 'threshold': request.native_standalone_qualification['criteria']['bootstrap_pf_5_percentile_minimum'] = 1.0
    elif mutation == 'mode': request.evaluation_mode = 'incremental'
    elif mutation == 'allocation':
        body = {key: value for key, value in request.specialist_council_contract.items() if key not in {'contract_hash', 'contract_json'}}
        body['members'][0]['capital_weight'] = .25; request.specialist_council_contract = seal_contract(body)
    elif mutation == 'depth': request.native_reachability_depth_audit_contract = {'protocol': PROTOCOL}
    elif mutation == 'spread': request.native_spread_context_study_contract = {'protocol': 'native_spread_context_study_v1'}
    with patch.dict('os.environ', {'INTERNAL_API_TOKEN': KEY, 'INTERNAL_API_TOKEN_FILE': ''}), \
            patch('app.services.backtester._load_simple_candles', side_effect=AssertionError('invalid qualification loaded source')):
        with pytest.raises(ValueError): backtester.run_simple_ema_rsi_backtest(request)
