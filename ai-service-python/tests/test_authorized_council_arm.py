"""Mechanism fixtures: future clock/data are not real independent evidence."""
import json
import hashlib
from copy import deepcopy
from pathlib import Path

import pandas as pd
import pytest

from test_authorized_research_transport import authorized, resign, KEY
from app import main
from app.schemas import StrategyRuntimeConfig, ExecutionConfig
from app.services import research_release as transport
from app.services.authorized_council_arm import original_arm_identity, run_original_full_arm, measured_scope, result_scope_current
from app.services.execution_contract import execution_contract_metadata


@pytest.fixture
def arm(authorized, tmp_path, monkeypatch):
    payload = authorized
    files = payload.policy_context['authorized_research_transport']['files']
    records = {'M5': {'path': payload.dataset_path, 'sha256': payload.replay_dataset_hash}}
    for timeframe, minutes in [('M15', 15), ('H1', 60), ('H4', 240)]:
        path = tmp_path / (timeframe + '.csv')
        last = pd.Timestamp('2027-02-01', tz='UTC') + pd.Timedelta(minutes=minutes)
        path.write_text('time,open,high,low,close,volume\n2027-02-01T00:00:00Z,100,101,99,100,10\n'
                        + last.isoformat() + ',100,101,99,100,10\n', encoding='utf-8')
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        records[timeframe] = {'path': str(path), 'sha256': digest}
        files[timeframe] = {'path': str(path), 'sha256': digest, 'rows': 2,
                            'start_inclusive': '2027-02-01T00:00:00+00:00', 'last_candle_at': last.isoformat()}
    payload.mtf_dataset_paths = {key: value['path'] for key, value in records.items()}
    payload.mtf_snapshot_manifest = {'bundle_hash': payload.replay_dataset_hash, 'streams': records}
    payload.emit_decision_trace = True
    payload.execution_contract = execution_contract_metadata(payload)
    payload.research_release['agent_execution_hashes'] = {'1': payload.execution_contract['execution_hash']}
    release = {key: value for key, value in payload.research_release.items() if key != 'release_hash'}
    payload.research_release['release_hash'] = hashlib.sha256(transport._release_json(release).encode()).hexdigest()
    payload.policy_context['authorized_research_transport']['release_hash'] = payload.research_release['release_hash']
    binding = {'protocol': 'specialist_council_evaluation_plan_v1', 'version_id': 1,
               'manifest_hash': 'a' * 64, 'plan_hash': 'b' * 64, 'arm_key': 'w1:solo'}
    raw = {'lab_agent_id': 1, 'strategy': 'ema_rsi_v1', 'version': 'fixture',
           'parameters': {}, 'specialist_council_evaluation': binding}
    payload.strategies = [StrategyRuntimeConfig.model_validate(raw)]
    policy = {'protocol': 'specialist_council_original_full_source_v1', 'evaluation_mode': 'full',
              'selection': 'entire_authorized_source', 'maximum_source_rows': 200000,
              'maximum_runtime_seconds': 600, 'warmup_rows': 0, 'no_walk_forward_selection': True,
              'promotion_evidence': False}
    scope = measured_scope(payload, files['M5'], policy)
    declared = {'generation_id': 1, 'work_item_id': 8, 'reservation_hash': 'c' * 64,
                'arm_key': 'w1:solo', 'window_key': payload.policy_context['authorized_research_transport']['window']['window_key'],
                'plan_hash': binding['plan_hash'], 'model_hash': 'd' * 64, 'evaluation_scope': scope}
    payload.policy_context['specialist_council_authorized_arm'] = declared
    payload.policy_context['full_replay_runtime_policy'] = policy
    signed = {'protocol': 'authorized_original_council_arm_v1', 'purpose': 'independent',
              **{key: value for key, value in declared.items() if key != 'evaluation_scope'},
              'model_version_id': 1, 'version_id': 1, 'manifest_hash': binding['manifest_hash'], 'kind': 'solo',
              'strategy_payload_json': json.dumps(raw), 'runtime_policy_json': json.dumps(policy),
              'evaluation_scope_json': json.dumps(scope), 'shared_runtime_json': json.dumps({
                  'initial_balance': payload.initial_balance, 'risk_per_trade': payload.risk_per_trade,
                  'execution': payload.execution.model_dump(mode='json'), 'execution_contract': payload.execution_contract,
                  'volume_context': payload.volume_context, 'emit_decision_trace': True}),
              'independent_evidence': False, 'promotion_evidence': False}
    payload.policy_context['authorized_research_transport']['original_council_arm'] = signed
    resign(payload)
    monkeypatch.setattr(main, '_load_immutable_replay_cache', lambda *args: None)
    monkeypatch.setattr(main, '_store_immutable_replay_cache', lambda *args, **kwargs: None)
    monkeypatch.setattr(main, '_write_replay_checkpoint', lambda *args, **kwargs: None)
    return payload


def test_actual_signed_full_solo_runs_original_engine_once_never_walkforward(arm, monkeypatch):
    monkeypatch.setattr(main.WalkForwardService, 'run', lambda *args, **kwargs: pytest.fail('unpaired walk-forward invoked'))
    result = main._run_all_backtests_sync(arm)['leaderboard'][0]['result']
    scope = result['data_quality']['replay_evaluation_scope']
    assert scope['rows'] == 2 and scope['decision_rows'] == 1 and scope['warmup_rows'] == 0
    assert result['data_quality']['authorized_original_council_arm']['independent_evidence'] is False
    assert result['data_quality']['authorized_original_council_arm']['promotion_evidence'] is False
    assert main._bounded_replay_seconds(arm, 'run_all') == 570


@pytest.mark.parametrize('poison', ['unsigned', 'signature', 'strategy', 'binding', 'risk', 'policy', 'scope', 'mode', 'native'])
def test_only_actual_signed_original_identity_selects_full_route(arm, poison):
    if poison == 'unsigned': del arm.policy_context['authorized_research_transport']
    if poison == 'signature': arm.policy_context['authorized_research_transport']['hmac_sha256'] = '0' * 64
    if poison == 'strategy': arm.strategies[0].parameters['ema_fast'] = 7
    if poison == 'binding': arm.strategies[0].specialist_council_evaluation['arm_key'] = 'w1:champion'
    if poison == 'risk': arm.risk_per_trade = 1.9
    if poison == 'policy': arm.policy_context['full_replay_runtime_policy']['warmup_rows'] = 1
    if poison == 'scope': arm.policy_context['specialist_council_authorized_arm']['evaluation_scope']['rows'] = 3
    if poison == 'mode': arm.evaluation_mode = 'replay'
    if poison == 'native': arm.strategies[0].specialist_council_contract = {'caller': 'one-member-relabel'}
    with pytest.raises(ValueError):
        original_arm_identity(arm, transport.verify_research_transport(arm, KEY))


def test_truncated_consumed_frame_is_not_a_successful_original_arm(arm):
    identity = original_arm_identity(arm, transport.verify_research_transport(arm, KEY))
    frame = pd.read_csv(arm.dataset_path).iloc[:1]
    with pytest.raises(ValueError, match='CONSUMED_FULL_SCOPE_MISMATCH|FULL_ROW_BUDGET_INVALID'):
        run_original_full_arm(arm, frame, identity, lambda *args, **kwargs: pytest.fail('replay began'))


def test_aggregate_result_without_measured_original_costs_is_refused(arm):
    identity = original_arm_identity(arm, transport.verify_research_transport(arm, KEY))
    frame = pd.read_csv(arm.dataset_path)
    with pytest.raises(ValueError, match='ORIGINAL_SOLO_ACCOUNT_METRICS_REQUIRED'):
        run_original_full_arm(arm, frame, identity, lambda *args, **kwargs: {
            'net_profit': -2, 'data_quality': {'rows_after_cleaning': 2}})


def signed_trading_frame(arm, *, win=True, censored=False, quotes=False):
    """Actual CSV/signature mechanism fixture, never market authority."""
    times = pd.date_range('2027-02-01', periods=24, freq='5min', tz='UTC')
    frame = pd.DataFrame({'time': times, 'open': 100.0,
                          'high': 100.01 if censored or not win else 102.0,
                          'low': 99.99 if censored or win else 98.0,
                          'close': 100.0, 'volume': 100.0})
    # The first real fill remains open across several actual candles, so
    # carry is measured as well as same-candle spread/slippage/commission.
    if not censored:
        frame.loc[:5, ['high', 'low']] = [100.01, 99.99]
    if quotes:
        frame['spread_available'] = 1
        frame['spread'] = 0.0001
        frame['bid_close'] = frame['close']
        frame['ask_close'] = frame['close'] + 0.0001
        frame['quote_time_utc'] = frame['time'] + pd.Timedelta(minutes=4, seconds=59)
        frame['quote_available_after_utc'] = frame['time'] + pd.Timedelta(minutes=5)
        frame['quote_age_ms'] = 1000
    frame.to_csv(arm.dataset_path, index=False)
    digest = hashlib.sha256(Path(arm.dataset_path).read_bytes()).hexdigest()
    envelope = arm.policy_context['authorized_research_transport']
    arm.replay_dataset_hash = digest
    arm.mtf_snapshot_manifest['bundle_hash'] = digest
    arm.mtf_snapshot_manifest['streams']['M5']['sha256'] = digest
    envelope['dataset_hash'] = digest
    envelope['files']['M5'].update(sha256=digest, rows=len(frame),
        start_inclusive=times[0].isoformat(), last_candle_at=times[-1].isoformat())
    window = {key: value for key, value in envelope['window'].items() if key != 'window_key'}
    window['dataset_sha256'] = digest
    window['window_key'] = hashlib.sha256(json.dumps(window, separators=(',', ':')).encode()).hexdigest()
    envelope['window'] = window
    arm.execution = ExecutionConfig(stop_loss_percent=20 if censored else 1,
        take_profit_percent=20 if censored else 0.3, spread_points=2, point_size=0.01,
        slippage_points=1, commission_percent=0.01, swap_per_day_percent=0.1, max_leverage=5)
    arm.execution_contract = execution_contract_metadata(arm)
    arm.research_release['dataset_hash'] = digest
    arm.research_release['agent_execution_hashes'] = {'1': arm.execution_contract['execution_hash']}
    release = {key: value for key, value in arm.research_release.items() if key != 'release_hash'}
    arm.research_release['release_hash'] = hashlib.sha256(transport._release_json(release).encode()).hexdigest()
    envelope['release_hash'] = arm.research_release['release_hash']
    signed = envelope['original_council_arm']
    signed['window_key'] = window['window_key']
    shared = json.loads(signed['shared_runtime_json'])
    shared['execution'] = arm.execution.model_dump(mode='json')
    shared['execution_contract'] = arm.execution_contract
    signed['shared_runtime_json'] = json.dumps(shared)
    policy = arm.policy_context['full_replay_runtime_policy']
    scope = measured_scope(arm, envelope['files']['M5'], policy)
    signed['evaluation_scope_json'] = json.dumps(scope)
    declared = arm.policy_context['specialist_council_authorized_arm']
    declared.update(window_key=window['window_key'], evaluation_scope=scope)
    resign(arm)
    return frame


@pytest.mark.parametrize('win,censored', [(True, False), (False, False), (True, True)])
def test_real_signed_solo_engine_account_reconciles_actual_fills_costs_and_scope(arm, monkeypatch, win, censored):
    from app.services import backtester
    signed_trading_frame(arm, win=win, censored=censored)
    def deterministic(frame, parameters):
        frame = frame.copy()
        frame['signal'] = 'WAIT'
        frame.loc[2:18, 'signal'] = 'BUY'
        frame['signal_confidence'] = 1.0
        return frame
    monkeypatch.setattr(backtester, 'get_strategy', lambda *args: deterministic)
    monkeypatch.setattr(main.WalkForwardService, 'run', lambda *args, **kwargs: pytest.fail('walk-forward selected'))
    result = main._run_all_backtests_sync(arm)['leaderboard'][0]['result']
    account = result['data_quality']['original_solo_account']
    metrics = result['metrics']
    ledger = account['position_ledger']
    assert ledger, result['entry_funnel']
    assert account['decision_rows'] == 23
    assert result['data_quality']['decision_trace']['evaluated_candle_count'] == 23
    assert result['data_quality']['replay_evaluation_scope']['decision_rows'] == 23
    assert metrics['net_profit'] == pytest.approx(sum(row['net_pnl'] for row in ledger), abs=1e-8)
    assert metrics['total_costs'] == pytest.approx(sum(row['explicit_cost'] + row['spread_slippage'] for row in ledger))
    assert metrics['total_costs'] > 0 and metrics['max_gross_exposure_percent'] > 0 and metrics['max_total_risk_percent'] > 0
    assert metrics['commission'] > 0 and metrics['carry'] > 0 and metrics['spread'] > 0 and metrics['slippage'] > 0
    assert metrics['total_costs'] == pytest.approx(sum(metrics[key] for key in ('commission', 'carry', 'spread', 'slippage')))
    for row in ledger:
        sign = 1 if row['direction'] == 'BUY' else -1
        assert row['net_pnl'] == pytest.approx(row['units'] * sign * (row['weighted_exit_price'] - row['entry_price'])
            - row['commission'] - row['carry'])
        assert row['gross_market_pnl'] - row['total_costs'] == pytest.approx(row['net_pnl'])
        assert row['cash_after_exit'] - row['cash_before_exit'] == pytest.approx(row['net_pnl'])
    assert account['decision_clock'] == {'first_signal_at': '2027-02-01T00:00:00+00:00',
        'last_signal_at': '2027-02-01T01:50:00+00:00', 'first_execution_at': '2027-02-01T00:05:00+00:00',
        'last_execution_at': '2027-02-01T01:55:00+00:00', 'rows': 23}
    assert account['reconciliation_error'] == pytest.approx(0, abs=1e-8)
    assert account['realized_cash'] == pytest.approx(result['final_balance'], abs=0.006)
    if censored:
        assert metrics['censored_trades'] == 1 and metrics['matured_trades'] == 0
        assert ledger[-1]['censor_reason'] == 'authorized_window_end'
        assert account['realized_cash'] == arm.initial_balance
        assert account['marked_terminal_cash'] != account['realized_cash']
    else:
        assert metrics['matured_trades'] > 0 and metrics['censored_trades'] == 0
        assert metrics['net_profit'] > 0 if win else metrics['net_profit'] < 0
        assert account['marked_terminal_cash'] == account['realized_cash']
    assert account['independent_evidence'] is False and account['promotion_evidence'] is False
    identity = original_arm_identity(arm, transport.verify_research_transport(arm, KEY))
    assert result_scope_current(result, identity)
    candidate = main._candidate_cache_payload(arm, arm, '1')
    assert candidate.policy_context['full_replay_runtime_policy'] == arm.policy_context['full_replay_runtime_policy']
    assert main._candidate_cache_contract_is_current({'result': result}, candidate)
    for missing in ('commission', 'carry', 'spread', 'slippage', 'total_costs', 'matured_trades'):
        damaged = deepcopy(result)
        damaged['data_quality']['original_solo_account']['metrics'].pop(missing)
        assert not result_scope_current(damaged, identity), missing
        with pytest.raises(ValueError, match='ORIGINAL_SOLO_ACCOUNT_METRICS_REQUIRED'):
            run_original_full_arm(arm, pd.read_csv(arm.dataset_path), identity, lambda *args, **kwargs: damaged)


def test_normalized_drop_or_native_wrong_scope_never_gets_original_scope_receipt(arm):
    identity = original_arm_identity(arm, transport.verify_research_transport(arm, KEY))
    frame = pd.read_csv(arm.dataset_path)
    with pytest.raises(ValueError, match='NORMALIZED_FULL_ROWS_CHANGED'):
        run_original_full_arm(arm, frame, identity, lambda *args, **kwargs: {'data_quality': {'rows_after_cleaning': 1}})
    with pytest.raises(ValueError, match='NATIVE_PRODUCER_SCOPE_MISMATCH'):
        run_original_full_arm(arm, frame, identity, lambda *args, **kwargs: {'specialist_council_receipt': {'evaluated_scope': {}}})


def test_unsigned_historical_route_unchanged(arm):
    arm.policy_context = {}
    assert original_arm_identity(arm, None) is None


@pytest.mark.parametrize('unavailable', [pd.NA, None, float('nan')])
def test_unavailable_closed_instrument_scalars_never_assert_an_observation(unavailable, monkeypatch):
    from app.services import backtester
    emitted = []
    monkeypatch.setattr(backtester, '_record_instrument_runtime_event', lambda state, key, *args, **kwargs: emitted.append(key))
    row = {key: unavailable for key in ('entry_contract_model', 'selected_specialist', 'entry_contract_status',
        'entry_setup_detected', 'entry_location_valid', 'bos_event', 'choch_event', 'm15_trap_direction')}
    backtester._record_strategy_instrument_events({}, row, 'WAIT', '')
    assert emitted == []
    backtester._record_strategy_instrument_events({}, row, 'BUY', '')
    assert set(emitted) == {'session_breakout', 'session_range'}


def test_ordinary_single_strategy_keeps_legacy_clock_without_original_account(arm, monkeypatch):
    from app.services import backtester
    frame = signed_trading_frame(arm)
    arm.policy_context = {}
    arm.research_release = {}
    arm.execution_contract = {}
    arm.replay_dataset_hash = None
    arm.mtf_dataset_paths = {}
    arm.mtf_snapshot_manifest = {}
    def deterministic(frame, parameters):
        frame = frame.copy()
        frame['signal'] = 'BUY'
        frame['signal_confidence'] = 1.0
        return frame
    monkeypatch.setattr(backtester, 'get_strategy', lambda *args: deterministic)
    result = backtester._run_prepared_simple_backtest(arm, frame, lightweight=True).model_dump()
    assert result['total_trades'] == 0
    assert result['final_balance'] == arm.initial_balance
    assert 'original_solo_account' not in result['data_quality']


def test_signed_declared_mtf_confirmation_keeps_unclosed_context_wait(arm, monkeypatch):
    from app.services import backtester
    signed_trading_frame(arm)
    arm.mtf_pilot = {'enabled': True, 'symbol': 'XAUUSD', 'requested_timeframe': 'M5',
        'execution_timeframe': 'M5', 'entry_timeframe': 'M15', 'mode': 'h1_veto_m15_risk'}
    signed = arm.policy_context['authorized_research_transport']['original_council_arm']
    shared = json.loads(signed['shared_runtime_json'])
    shared['mtf_pilot'] = arm.mtf_pilot
    signed['shared_runtime_json'] = json.dumps(shared)
    resign(arm)
    def deterministic(frame, parameters):
        frame = frame.copy()
        frame['signal'] = 'BUY'
        frame['signal_confidence'] = 1.0
        return frame
    monkeypatch.setattr(backtester, 'get_strategy', lambda *args: deterministic)
    result = main._run_all_backtests_sync(arm)['leaderboard'][0]['result']
    assert result['data_quality']['replay_evaluation_scope']['decision_rows'] == 23
    assert result['metrics']['total_trades'] == 0
    assert result['entry_funnel']['mtf_permission_gate_evaluations'] == 23
    assert result['entry_funnel']['mtf_permission_gate_rejections'] == 23
    assert result['data_quality']['original_solo_account']['position_ledger'] == []


def signed_original_window_bundle(arm):
    from app.services.execution_contract import PROTOCOL as EXECUTION_PROTOCOL
    arm.execution_contract['protocol'] = EXECUTION_PROTOCOL
    arm.execution_contract = execution_contract_metadata(arm)
    arm.mtf_snapshot_manifest.update(protocol='closed_h4_h1_m15_m5_snapshot_v1',
        validation_bundle_protocol='authorized_original_council_window_bundle_v1')
    arm.mtf_pilot = {'enabled': True, 'activation_status': 'execution_stream_bound', 'symbol': 'XAUUSD',
        'requested_timeframe': 'M5', 'execution_timeframe': 'M5', 'entry_timeframe': 'M15', 'mode': 'h1_veto_m15_risk'}
    signed = arm.policy_context['authorized_research_transport']['original_council_arm']
    shared = json.loads(signed['shared_runtime_json'])
    shared['mtf_pilot'] = arm.mtf_pilot
    shared['execution_contract'] = arm.execution_contract
    signed['shared_runtime_json'] = json.dumps(shared)
    resign(arm)


def test_signed_original_window_bundle_requires_private_owner_at_direct_context_entry(arm, monkeypatch):
    from app.services import backtester
    signed_trading_frame(arm)
    signed_original_window_bundle(arm)
    with pytest.raises(ValueError, match='AUTONOMOUS_MTF_ORIGINAL_WINDOW_AUTHORIZATION_REQUIRED'):
        backtester.prepare_feature_snapshot(arm, backtester._load_simple_candles(arm))
    unsigned = arm.model_copy(deep=True)
    unsigned.policy_context = {}
    with pytest.raises(ValueError, match='COUNCIL_ARM_ORIGINAL_SIGNED_TRANSPORT_REQUIRED'):
        original_arm_identity(unsigned, None)
    monkeypatch.setattr(main, '_load_immutable_replay_cache', lambda *args: pytest.fail('unsigned window reached cache'))
    with pytest.raises(ValueError, match='COUNCIL_ARM_ORIGINAL_SIGNED_TRANSPORT_REQUIRED'):
        main._run_all_backtests_sync(unsigned)


@pytest.mark.parametrize('raw_signal', ['WAIT', 'BUY'])
def test_real_signed_native_window_publishes_actual_zero_warmup_clock_and_closed_member_inputs(arm, monkeypatch, raw_signal):
    from app.services import backtester
    from app.services.specialist_council import seal_contract
    from app.services.research_program_tasks import canonical_hash
    from test_specialist_council import fixtures
    signed_trading_frame(arm)
    signed_original_window_bundle(arm)
    request, _ = fixtures()
    body = {key: value for key, value in request.specialist_council_contract.items() if key != 'contract_hash'}
    body.update(execution_timeframe='M5', replay_dataset_hash=arm.replay_dataset_hash,
        execution_hash=arm.execution_contract['execution_hash'])
    binding = dict(arm.strategies[0].specialist_council_evaluation, arm_key='w1:candidate')
    raw = {'lab_agent_id': 1, 'strategy': 'portfolio_v1', 'base_strategy': 'portfolio', 'version': 'fixture',
        'parameters': {}, 'specialist_council_evaluation': binding, 'specialist_council_contract': seal_contract(body)}
    arm.strategies = [StrategyRuntimeConfig.model_validate(raw)]
    arm.policy_context['specialist_council_authorized_arm']['arm_key'] = 'w1:candidate'
    signed = arm.policy_context['authorized_research_transport']['original_council_arm']
    signed.update(arm_key='w1:candidate', kind='candidate', strategy_payload_json=json.dumps(raw))
    resign(arm)
    def unavailable(frame, parameters):
        frame = frame.copy()
        frame['signal'] = raw_signal
        frame['signal_confidence'] = 1.0 if raw_signal == 'BUY' else 0.0
        for key in ('volume_policy_rejection', 'specialist_scope_first_veto', 'composition_decision_reason', 'entry_contract_status'):
            frame[key] = pd.NA
        return frame
    monkeypatch.setattr(backtester, 'get_strategy', lambda *args: unavailable)
    result = main._run_all_backtests_sync(arm)['leaderboard'][0]['result']
    receipt = result['specialist_council_receipt']
    trace = result['decision_trace']
    assert receipt['status'] == 'computed'
    assert result['total_trades'] == 0 and result['trade_ledger'] == []
    assert len(trace) == 23 and [row['candle_index'] for row in trace] == list(range(1, 24))
    producer = result['data_quality']['decision_trace']
    assert producer['complete'] is True and producer['first_candle_index'] == 1 and producer['warmup_rows'] == 0
    assert producer['trace_hash'] == receipt['decision_trace_identity']['trace_hash'] == canonical_hash(trace)
    assert receipt['decision_trace_identity']['decision_rows'] == 23
    for row in trace:
        assert row['decision_id'] == canonical_hash(row['source_clock'])
        assert pd.Timestamp(row['signal_time']) + pd.Timedelta(minutes=5) == pd.Timestamp(row['decision_at'])
        assert row['decision_at'] == row['execution_time'] == row['candle_time']
        assert row['action'] == 'WAIT' and row['accepted'] is False
        assert len(row['member_decisions']) == 4
        for member in row['member_decisions']:
            assert member['closed_inputs']['time'] == row['signal_time']
            assert member['decision_id'] == canonical_hash({'account_decision_id': row['decision_id'],
                'member_version_hash': member['member_version_hash']})
            assert member['action'] == 'WAIT'
            if raw_signal == 'BUY':
                assert member['rejection_code'].startswith('mtf_')


def test_actual_signed_future_quotes_activate_original_liquidity_scope_without_calendar_flags(arm, monkeypatch):
    """Synthetic source-column mechanism fixture, never provider/market evidence."""
    from app.services import backtester
    from app.services.historical_quotes import validate_historical_quotes
    frame = signed_trading_frame(arm, quotes=True)
    arm.strategies[0].specialist_context_contract = {'spread_liquidity_state': 'liquid'}
    signed = arm.policy_context['authorized_research_transport']['original_council_arm']
    raw = json.loads(signed['strategy_payload_json'])
    raw['specialist_context_contract'] = arm.strategies[0].specialist_context_contract
    signed['strategy_payload_json'] = json.dumps(raw)
    resign(arm)
    # A genuine envelope or caller-shaped attrs alone do not select the private
    # admission. Only the already-authenticated original full helper does.
    for declared in (None, {'verified': True, 'start_inclusive': '2027-01-01T00:00:00Z'}):
        unadmitted = frame.copy()
        if declared is not None:
            unadmitted.attrs['_authorized_original_quote_calendar'] = declared
        with pytest.raises(ValueError, match='HISTORICAL_QUOTE_ALIGNMENT_INVALID'):
            validate_historical_quotes(unadmitted, arm)
    def deterministic(frame, parameters):
        frame = frame.copy()
        frame['signal'] = 'BUY'
        frame['signal_confidence'] = 1.0
        return frame
    monkeypatch.setattr(backtester, 'get_strategy', lambda *args: deterministic)
    result = main._run_all_backtests_sync(arm)['leaderboard'][0]['result']
    quality = result['data_quality']['spread_quality']
    assert result['metrics']['matured_trades'] > 0
    assert result['metrics']['net_profit'] != 0
    assert quality['status'] == 'observed' and quality['available_rows'] == 24
    assert quality['execution_cost_model_changed'] is False
    assert quality['promotion_evidence'] is False
    assert quality['calendar_admission']['window_key'] == signed['window_key']
    assert quality['calendar_admission']['stream'] == 'M5'
    assert result['metrics']['total_costs'] > 0
    # The original quote values themselves remain part of the consumed seal.
    identity = original_arm_identity(arm, transport.verify_research_transport(arm, KEY))
    damaged = frame.copy()
    damaged.loc[0, 'ask_close'] += 1
    with pytest.raises(ValueError, match='SEALED_DATASET_CONSUMED_ROWS_MISMATCH'):
        run_original_full_arm(arm, damaged, identity, lambda *args, **kwargs: pytest.fail('altered quote entered replay'))


@pytest.mark.parametrize('year', [2026, 2027])
def test_default_quote_calendar_still_refuses_paper_and_unsigned_future(year):
    from app.schemas import SimpleBacktestRequest
    from app.services.historical_quotes import validate_historical_quotes
    stamp = pd.Timestamp(f'{year}-02-01', tz='UTC')
    frame = pd.DataFrame({'time': [stamp], 'close': [100.0], 'spread_available': [1],
        'spread': [0.1], 'bid_close': [100.0], 'ask_close': [100.1],
        'quote_time_utc': [stamp + pd.Timedelta(minutes=4, seconds=59)],
        'quote_available_after_utc': [stamp + pd.Timedelta(minutes=5)], 'quote_age_ms': [1000]})
    payload = SimpleBacktestRequest(timeframe='M5', evaluation_mode='full')
    with pytest.raises(ValueError, match='HISTORICAL_QUOTE_ALIGNMENT_INVALID'):
        validate_historical_quotes(frame, payload)
