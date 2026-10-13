"""Execute one preregistered, bounded 2x2 on unchanged local archive bytes.

This runner proves local software execution and observations only. It cannot
issue independent research, economic, paper, live, parent, or credit authority.
"""

from __future__ import annotations

import argparse
import copy
import hashlib
import io
import json
import math
import os
from pathlib import Path
import subprocess
import sys
import time
from datetime import datetime, timezone

APP_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(APP_ROOT))

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_learning import validate_contract, receipt_current
from app.services.research_program_tasks import canonical_hash, canonical_json, _same_ast_copy

PROTOCOL = 'instrument_method_matrix_spec_v1'
ARMS = ('L1A1', 'L2A1', 'L1A2', 'L2A2')
FALSE_AUTHORITY = {'research_only': True, 'independent_validation': False,
    'economic_authority': False, 'promotion_evidence': False, 'paper_authority': False,
    'live_authority': False, 'credit_granted': False, 'parent_authority': False}
SOURCE_FILES = ('scripts/backtest-instrument-method-matrix.py', 'app/services/backtester.py',
    'app/services/specialist_council.py', 'app/services/instrument_method_learning.py',
    'app/services/research_program_tasks.py', 'app/schemas.py')


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def utc(value):
    if not isinstance(value, str) or not value.endswith(('Z', '+00:00')):
        raise ValueError('MATRIX_EXPLICIT_UTC_REQUIRED')
    stamp = pd.Timestamp(value)
    if pd.isna(stamp) or stamp.tzinfo is None or stamp.utcoffset().total_seconds() != 0:
        raise ValueError('MATRIX_EXPLICIT_UTC_REQUIRED')
    return stamp.tz_convert('UTC')


def sha_file(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1048576), b''):
            digest.update(block)
    return digest.hexdigest()


def write_new(path, value):
    encoded = canonical_json(value).encode('utf-8')
    with Path(path).open('xb') as stream:
        stream.write(encoded)
    return hashlib.sha256(encoded).hexdigest()


def read_spec(path):
    raw = Path(path).read_bytes()
    if len(raw) > 8388608:
        raise ValueError('MATRIX_SPEC_SIZE_EXCEEDED')
    spec = json.loads(raw)
    if not isinstance(spec, dict):
        raise ValueError('MATRIX_SPEC_OBJECT_REQUIRED')
    body = {key: value for key, value in spec.items() if key not in {'spec_hash', 'spec_json'}}
    if 'spec_json' in spec:
        if not isinstance(spec['spec_json'], str):
            raise ValueError('MATRIX_SPEC_JSON_COPY_INVALID')
        preserved = json.loads(spec['spec_json'])
        if not _same_ast_copy(body, preserved):
            raise ValueError('MATRIX_SPEC_JSON_COPY_MISMATCH')
        body = preserved
    if spec.get('spec_hash') != canonical_hash(body) or body.get('protocol') != PROTOCOL:
        raise ValueError('MATRIX_SPEC_HASH_OR_PROTOCOL_MISMATCH')
    if not isinstance(body.get('spec_key'), str) or not body['spec_key']:
        raise ValueError('MATRIX_SPEC_KEY_REQUIRED')
    if utc(body.get('registered_at')) > pd.Timestamp.now(tz='UTC'):
        raise ValueError('MATRIX_REGISTRATION_IN_FUTURE')
    if not isinstance(body.get('arms'), dict) or set(body['arms']) != set(ARMS):
        raise ValueError('MATRIX_EXACT_FOUR_ARMS_REQUIRED')
    for field, minimum, maximum in (('minimum_trades', 1, 100000), ('per_arm_timeout_seconds', 1, 120)):
        value = body.get(field)
        if not isinstance(value, int) or isinstance(value, bool) or not minimum <= value <= maximum:
            raise ValueError('MATRIX_RESOURCE_OR_POWER_BOUND_INVALID')
    epsilon = body.get('net_pnl_epsilon', 0.01)
    if not isinstance(epsilon, (int, float)) or isinstance(epsilon, bool) or not math.isfinite(epsilon) or epsilon < 0:
        raise ValueError('MATRIX_DIAGNOSTIC_EPSILON_INVALID')
    if body.get('source_kind', 'pinned_local_archive') not in {'pinned_local_archive', 'synthetic_software_fixture'}:
        raise ValueError('MATRIX_SOURCE_KIND_UNSUPPORTED')
    for key, value in FALSE_AUTHORITY.items():
        if key in body and body[key] is not value:
            raise ValueError('MATRIX_AUTHORITY_CLAIM_FORBIDDEN')
    return {**body, 'spec_hash': spec['spec_hash']}, hashlib.sha256(raw).hexdigest()


def load_archive(data):
    if not isinstance(data, dict):
        raise ValueError('MATRIX_ARCHIVE_BINDING_REQUIRED')
    declared_path = Path(data.get('path', ''))
    if not declared_path.is_absolute():
        raise ValueError('MATRIX_ABSOLUTE_ARCHIVE_PATH_REQUIRED')
    path = declared_path.resolve(strict=True)
    if not path.is_file() or path.suffix.lower() != '.csv' or path.stat().st_size > 134217728:
        raise ValueError('MATRIX_BOUNDED_LOCAL_CSV_REQUIRED')
    raw = path.read_bytes()
    source_hash = hashlib.sha256(raw).hexdigest()
    if source_hash != data.get('sha256'):
        raise ValueError('MATRIX_ARCHIVE_SHA256_MISMATCH')
    if (type(data.get('max_rows')) is not int or not 2 <= data['max_rows'] <= 4096
            or type(data.get('warmup_rows')) is not int or not 0 <= data['warmup_rows'] < data['max_rows']):
        raise ValueError('MATRIX_ARCHIVE_ROW_BOUND_INVALID')
    frame = pd.read_csv(io.BytesIO(raw))
    if not {'time', 'open', 'high', 'low', 'close'}.issubset(frame.columns):
        raise ValueError('MATRIX_ARCHIVE_OHLC_REQUIRED')
    stamps = pd.to_datetime(frame['time'], errors='coerce', utc=True)
    if stamps.isna().any() or stamps.duplicated().any() or not stamps.is_monotonic_increasing:
        raise ValueError('MATRIX_ARCHIVE_CHRONOLOGY_INVALID')
    for column in ('open', 'high', 'low', 'close'):
        values = pd.to_numeric(frame[column], errors='coerce')
        if values.isna().any() or not values.map(math.isfinite).all() or (values <= 0).any():
            raise ValueError('MATRIX_ARCHIVE_PRICE_INVALID')
    frame['time'] = stamps
    if (stamps >= pd.Timestamp('2026-01-01T00:00:00Z')).any():
        raise ValueError('MATRIX_POST_2026_SOURCE_FORBIDDEN')
    start, end = utc(data.get('start')), utc(data.get('end'))
    if start > end or end >= pd.Timestamp('2026-01-01T00:00:00Z'):
        raise ValueError('MATRIX_PRE_2026_WINDOW_REQUIRED')
    selected = frame.loc[(stamps >= start) & (stamps <= end)].copy()
    if (len(selected) <= data['warmup_rows'] or len(selected) > data['max_rows']
            or selected.iloc[0]['time'] != start or selected.iloc[-1]['time'] != end
            or 'selected_rows' in data and len(selected) != data['selected_rows']):
        raise ValueError('MATRIX_EXACT_ARCHIVE_SLICE_REQUIRED')
    for field, actual in (('source_start', stamps.iloc[0]), ('source_end', stamps.iloc[-1])):
        if field in data and utc(data[field]) != actual:
            raise ValueError('MATRIX_SOURCE_BOUND_MISMATCH')
    identity = {'path': str(path), 'source_sha256': source_hash, 'source_rows': len(frame),
        'source_start': stamps.iloc[0].isoformat(), 'source_end': stamps.iloc[-1].isoformat(),
        'selected_rows': len(selected), 'selected_start': start.isoformat(), 'selected_end': end.isoformat(),
        'source_first_row': int(selected.index[0]), 'source_last_row': int(selected.index[-1]),
        'declared_feature_warmup_rows': data['warmup_rows'],
        'parsed_selected_rows_sha256': hashlib.sha256(selected.to_csv(index=False).encode()).hexdigest()}
    return selected.reset_index(drop=True), identity


def method_contracts(request):
    if request.specialist_council_contract:
        return [member['method_learning'] for member in request.specialist_council_contract.get('members', [])
                if 'method_learning' in member]
    contract = request.policy_context.get('instrument_method_learning')
    return [contract] if contract is not None else []


def baseline_identity(request):
    body = copy.deepcopy(request.model_dump(mode='json'))
    body['policy_context'].pop('instrument_method_learning', None)
    council = body.get('specialist_council_contract')
    if council:
        council.pop('contract_hash', None); council.pop('contract_json', None)
        for member in council.get('members', []):
            member.pop('method_learning', None)
    return canonical_hash(body)


def validate_requests(spec):
    requests, baselines = {}, set()
    for arm in ARMS:
        declaration = spec['arms'][arm]
        if not isinstance(declaration, dict) or not isinstance(declaration.get('request'), dict):
            raise ValueError('MATRIX_ARM_REQUEST_REQUIRED')
        if not declaration['request'].get('execution'):
            raise ValueError('MATRIX_EXPLICIT_EXECUTION_CONFIG_REQUIRED')
        request = SimpleBacktestRequest.model_validate(declaration['request'])
        if (not request.emit_decision_trace or request.candles or request.regime_candles or request.mtf_streams
                or request.related_mtf_streams or request.from_date is not None or request.to_date is not None
                or request.dataset_tail_rows is not None or request.regime_dataset_tail_rows is not None
                or request.mtf_dataset_tail_rows or request.related_mtf_dataset_tail_rows):
            raise ValueError('MATRIX_ORIGINAL_TRACE_AND_NO_INLINE_OR_TAIL_OVERRIDE_REQUIRED')
        execution = execution_contract_metadata(request)
        if execution['status'] != 'matched':
            raise ValueError('MATRIX_EXECUTION_CONTRACT_MISMATCH')
        contracts = method_contracts(request)
        if not contracts:
            raise ValueError('MATRIX_METHOD_LEARNING_CONTRACT_REQUIRED')
        if request.specialist_council_contract:
            from app.services.parameter_schema import validate_strategy_parameters
            from app.services.specialist_council import validate_contract as validate_council_contract
            validate_council_contract(request)
            for member in request.specialist_council_contract['members']:
                if 'method_learning' not in member:
                    continue
                child = request.model_copy(update={'strategy': member['strategy'],
                    'base_strategy': member.get('base_strategy'), 'version': member['version'],
                    'parameters': validate_strategy_parameters(member['strategy'], member.get('parameters', {}), member.get('base_strategy')),
                    'risk_per_trade': member['risk_per_trade_percent'], 'specialist_council_contract': {},
                    'portfolio_members': [], 'strategies': []})
                validate_contract(member['method_learning'], dataset_hash=child.replay_dataset_hash,
                    execution_hash=execution['execution_hash'], payload=child)
        else:
            for contract in contracts:
                validate_contract(contract, dataset_hash=request.replay_dataset_hash,
                    execution_hash=execution['execution_hash'], payload=request)
        baselines.add(baseline_identity(request))
        requests[arm] = request
    if len(baselines) != 1:
        raise ValueError('MATRIX_BASELINE_ACCOUNT_STRATEGY_COST_OR_RISK_DRIFT')
    for left, right in (('L1A1', 'L1A2'), ('L2A1', 'L2A2')):
        if [item['library_hash'] for item in method_contracts(requests[left])] != [item['library_hash'] for item in method_contracts(requests[right])]:
            raise ValueError('MATRIX_LIBRARY_LEVEL_DRIFT')
    if [item['library_hash'] for item in method_contracts(requests['L1A1'])] == [item['library_hash'] for item in method_contracts(requests['L2A1'])]:
        raise ValueError('MATRIX_DISTINCT_LIBRARY_LEVELS_REQUIRED')
    return requests, next(iter(baselines))


def trace_events(response):
    events = []
    for row in response.get('decision_trace', []):
        events.extend(row.get('method_learning_events', []))
        if row.get('event_type') == 'instrument_method_decision':
            events.append(row['method_learning'])
    return events


def measured_arm(response, request, input_identity):
    clock = (response.get('data_quality') or {}).get('replay_executed_clock')
    if not isinstance(clock, dict) or clock.get('complete') is not True or clock.get('input_rows') != input_identity['selected_rows']:
        raise ValueError('MATRIX_ACTUAL_CLOCK_REQUIRED')
    if (response.get('specialist_council_receipt') or {}).get('status') == 'dependency':
        raise ValueError('MATRIX_NATIVE_DEPENDENCY_NO_COMPARISON')
    events = trace_events(response)
    receipts = []
    for contract in method_contracts(request):
        if not receipt_current(response, contract):
            raise ValueError('MATRIX_ACTUAL_METHOD_RECEIPT_REQUIRED')
        candidates = [(response.get('data_quality') or {}).get('instrument_method_learning_receipt')]
        candidates += [member.get('method_learning_receipt') for member in
            (response.get('specialist_council_receipt') or {}).get('members', [])]
        receipt = next(item for item in candidates if isinstance(item, dict) and item.get('contract_hash') == contract['contract_hash'])
        actual = [event for event in events if event.get('contract_hash') == contract['contract_hash']]
        hasher = hashlib.sha256()
        for event in actual:
            encoded = canonical_json(event).encode()
            hasher.update(len(encoded).to_bytes(8, 'big')); hasher.update(encoded)
        if (receipt['event_count'] != len(actual) or receipt['events_hash'] != hasher.hexdigest()
                or receipt['used_count'] != sum(event['used'] for event in actual)
                or receipt['behavior_changed_count'] != sum(event['behavior_changed'] for event in actual)):
            raise ValueError('MATRIX_TRACE_RECEIPT_COUNT_OR_HASH_MISMATCH')
        receipts.append(receipt)
    if response.get('initial_balance') != request.initial_balance or response.get('execution_assumptions') != request.execution.model_dump():
        raise ValueError('MATRIX_RESULT_ACCOUNT_OR_COST_DRIFT')
    ledger = response.get('trade_ledger', [])
    if len(ledger) != response.get('total_trades'):
        raise ValueError('MATRIX_COMPLETE_TRADE_LEDGER_REQUIRED')
    account = (response.get('specialist_council_receipt') or {}).get('account')
    net = account['net_pnl'] if isinstance(account, dict) else response['final_balance'] - response['initial_balance']
    return {'status': 'computed', 'initial_balance': response['initial_balance'], 'final_balance': response['final_balance'],
        'net_pnl': net, 'net_profit_percent': response['net_profit_percent'],
        'net_pnl_source': 'native_account_ledger_unrounded' if isinstance(account, dict) else 'ordinary_reported_balance_rounded_to_0.01',
        'total_trades': response['total_trades'], 'max_drawdown_percent': response['max_drawdown_percent'],
        'executed_decision_rows': clock['decision_rows'], 'actual_clock': clock,
        'method_event_count': len(events), 'method_used_count': sum(event['used'] for event in events),
        'method_behavior_changed_count': sum(event['behavior_changed'] for event in events),
        'entry_veto_count': sum(event['phase'] == 'entry' and isinstance(event['outputs'], dict)
            and (event['outputs'].get('confirmation') is False or event['outputs'].get('risk_multiplier') == 0) for event in events),
        'method_receipts': receipts, 'event_samples': events[:8],
        'trade_ledger_hash': response.get('trade_ledger_hash'), 'event_ledger_hash': response.get('event_ledger_hash'),
        'input_identity': input_identity, **FALSE_AUTHORITY}


def source_snapshot():
    return {name: sha_file(APP_ROOT / name) for name in SOURCE_FILES}


def worker(spec_path, output_dir, arm):
    output_dir = Path(output_dir).resolve(strict=True)
    snapshot = json.loads((output_dir / 'before-outcome.snapshot.json').read_bytes())
    spec, file_hash = read_spec(spec_path)
    if snapshot['spec_file_sha256'] != file_hash or snapshot['source_files'] != source_snapshot():
        raise ValueError('MATRIX_PREREGISTRATION_OR_SOURCE_DRIFT')
    requests, baseline = validate_requests(spec)
    frame, identity = load_archive(spec['data'])
    if baseline != snapshot['baseline_hash'] or identity != snapshot['input_identity']:
        raise ValueError('MATRIX_ORIGINAL_BINDING_DRIFT')
    started = time.perf_counter()
    response = run_simple_ema_rsi_backtest_on_dataframe(requests[arm], frame,
        include_differential_pair=False, lightweight=True).model_dump(mode='json')
    wall = time.perf_counter() - started
    if source_snapshot() != snapshot['source_files'] or sha_file(identity['path']) != identity['source_sha256']:
        raise ValueError('MATRIX_SOURCE_DRIFT_DURING_EXECUTION')
    measured = measured_arm(response, requests[arm], identity)
    measured['backtest_wall_seconds'] = wall
    measured['arm'] = arm
    measured['source_kind'] = spec.get('source_kind', 'pinned_local_archive')
    measured['policy_level'] = arm[-2:]
    measured['policy_level_meaning'] = ('preregistered_candidate_selector_class_not_proven_learned_master_policy'
        if arm.endswith('A2') else 'preregistered_baseline_selector_class')
    response_hash = write_new(output_dir / (arm + '.response.json'), response)
    measured['response_sha256'] = response_hash
    measured['response_path'] = str(output_dir / (arm + '.response.json'))
    write_new(output_dir / (arm + '.observation.json'), measured)
    print(json.dumps({'status': 'computed', 'arm': arm, 'response_sha256': response_hash}))


def diagnostic(delta, powered, epsilon):
    if not powered:
        return 'underpowered'
    return 'positive_diagnostic' if delta > epsilon else 'negative_diagnostic' if delta < -epsilon else 'null_diagnostic'


def summarize(spec, observations, snapshot):
    complete = set(observations) == set(ARMS) and all(observations[arm].get('status') == 'computed' for arm in ARMS)
    summary = {'protocol': 'instrument_method_matrix_observation_v1', 'spec_key': spec['spec_key'],
        'spec_hash': spec['spec_hash'], 'before_outcome_snapshot_hash': canonical_hash(snapshot),
        'scope': 'local_software_execution_on_one_pinned_pre_2026_archive_slice',
        'source_kind': spec.get('source_kind', 'pinned_local_archive'),
        'policy_level_semantics': {'A1': 'preregistered_baseline_selector_class',
            'A2': 'preregistered_candidate_selector_class_not_proven_learned_master_policy'},
        'status': 'incomplete_no_comparison', 'observations': observations, **FALSE_AUTHORITY}
    if not complete:
        return summary
    signatures = {canonical_hash({key: observation['actual_clock'].get(key) for key in
        ('owner', 'input_rows', 'evaluation_offset_rows', 'execution_timeframe', 'duration_seconds',
         'decision_rows', 'first_evaluation_index', 'last_evaluation_index', 'signal_start', 'signal_end',
         'execution_start', 'execution_end', 'index_set_hash', 'schedule_hash')}) for observation in observations.values()}
    if len(signatures) != 1:
        summary['status'] = 'unequal_actual_clocks_no_comparison'
        return summary
    powered = all(item['total_trades'] >= spec['minimum_trades'] for item in observations.values())
    epsilon = spec.get('net_pnl_epsilon', 0.01)
    if not isinstance(epsilon, (int, float)) or isinstance(epsilon, bool) or not math.isfinite(epsilon) or epsilon < 0:
        raise ValueError('MATRIX_DIAGNOSTIC_EPSILON_INVALID')
    comparisons = {}
    for name, left, right in (('library_given_A1', 'L2A1', 'L1A1'), ('library_given_A2', 'L2A2', 'L1A2'),
                             ('policy_given_L1', 'L1A2', 'L1A1'), ('policy_given_L2', 'L2A2', 'L2A1')):
        delta = observations[left]['net_pnl'] - observations[right]['net_pnl']
        comparisons[name] = {'net_pnl_delta': delta, 'classification': diagnostic(delta, powered, epsilon),
            'trade_count_delta': observations[left]['total_trades'] - observations[right]['total_trades'],
            'method_use_count_delta': observations[left]['method_used_count'] - observations[right]['method_used_count'],
            'behavior_changed_count_delta': observations[left]['method_behavior_changed_count'] - observations[right]['method_behavior_changed_count'],
            'entry_veto_count_delta': observations[left]['entry_veto_count'] - observations[right]['entry_veto_count']}
    interaction = observations['L2A2']['net_pnl'] - observations['L2A1']['net_pnl'] - observations['L1A2']['net_pnl'] + observations['L1A1']['net_pnl']
    summary.update(status='local_diagnostic_measured' if powered else 'underpowered_local_observation',
        minimum_trades=spec['minimum_trades'], all_arms_meet_trade_floor=powered,
        net_pnl_epsilon=epsilon, trade_floor_is_not_statistical_significance=True,
        conditional_comparisons=comparisons, interaction={'net_pnl_delta': interaction,
            'classification': diagnostic(interaction, powered, epsilon)},
        account_coverage='actual_reached_gates_may_diverge_after_different_entry_or_exit_actions',
        paired_physical_clock_hash=next(iter(signatures)))
    return summary


def run_matrix(spec_path, output_dir):
    spec_path = Path(spec_path).resolve(strict=True)
    output_dir = Path(output_dir).resolve()
    spec, file_hash = read_spec(spec_path)
    requests, baseline = validate_requests(spec)
    _, identity = load_archive(spec['data'])
    if output_dir.exists():
        raise ValueError('MATRIX_OUTPUT_DIRECTORY_MUST_BE_NEW')
    output_dir.mkdir(parents=True, exist_ok=False)
    snapshot = {'protocol': 'instrument_method_matrix_before_outcome_v1', 'created_at': utc_now(),
        'spec_file_sha256': file_hash, 'spec_hash': spec['spec_hash'], 'registered_at': spec['registered_at'],
        'source_files': source_snapshot(), 'baseline_hash': baseline, 'input_identity': identity,
        'source_kind': spec.get('source_kind', 'pinned_local_archive'),
        'execution_mode': 'four_independent_processes_real_strategy_no_signal_override',
        'runtime_options': {'include_differential_pair': False, 'lightweight': True},
        'request_hashes': {arm: canonical_hash(request.model_dump(mode='json')) for arm, request in requests.items()},
        **FALSE_AUTHORITY}
    write_new(output_dir / 'before-outcome.snapshot.json', snapshot)
    observations, started = {}, time.perf_counter()
    try:
        for arm in ARMS:
            command = [sys.executable, str(Path(__file__).resolve()), '--spec', str(spec_path),
                '--output-dir', str(output_dir), '--worker-arm', arm]
            arm_started = time.perf_counter()
            try:
                result = subprocess.run(command, cwd=str(APP_ROOT), capture_output=True, text=True,
                    encoding='utf-8', errors='replace', timeout=spec['per_arm_timeout_seconds'],
                    creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
                write_new(output_dir / (arm + '.process.json'), {'exit_code': result.returncode,
                    'wall_seconds': time.perf_counter() - arm_started, 'stdout': result.stdout[-8192:],
                    'stderr': result.stderr[-8192:]})
                if result.returncode != 0:
                    observations[arm] = {'status': 'technical_failure', 'exit_code': result.returncode, **FALSE_AUTHORITY}
                    continue
                measured_path = output_dir / (arm + '.observation.json')
                observations[arm] = json.loads(measured_path.read_bytes())
                if sha_file(observations[arm]['response_path']) != observations[arm]['response_sha256']:
                    raise ValueError('MATRIX_ORIGINAL_RESPONSE_DRIFT')
            except subprocess.TimeoutExpired:
                observations[arm] = {'status': 'timeout', 'wall_seconds': time.perf_counter() - arm_started, **FALSE_AUTHORITY}
                write_new(output_dir / (arm + '.process.json'), observations[arm])
    except KeyboardInterrupt:
        summary = summarize(spec, observations, snapshot)
        summary['status'] = 'interrupted_no_comparison'
        summary['wall_seconds'] = time.perf_counter() - started
        write_new(output_dir / 'summary.json', summary)
        return summary
    summary = summarize(spec, observations, snapshot)
    summary['wall_seconds'] = time.perf_counter() - started
    summary['finished_at'] = utc_now()
    summary_hash = write_new(output_dir / 'summary.json', summary)
    print(json.dumps({'status': summary['status'], 'summary_path': str(output_dir / 'summary.json'),
        'summary_sha256': summary_hash, **FALSE_AUTHORITY}))
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--spec', required=True)
    parser.add_argument('--output-dir', required=True)
    parser.add_argument('--worker-arm', choices=ARMS, help=argparse.SUPPRESS)
    args = parser.parse_args()
    try:
        if args.worker_arm:
            worker(args.spec, args.output_dir, args.worker_arm)
            return 0
        summary = run_matrix(args.spec, args.output_dir)
        return 0 if summary['status'] in {'local_diagnostic_measured', 'underpowered_local_observation'} else 2
    except (ValueError, OSError, KeyError, TypeError) as error:
        print(json.dumps({'status': 'rejected', 'reason': str(error), **FALSE_AUTHORITY}), file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
