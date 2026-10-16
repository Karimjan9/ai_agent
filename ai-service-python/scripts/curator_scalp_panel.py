"""Bounded offline scalp experiment; sealing and verification never run a replay."""
import argparse
import copy
import csv
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import zipfile

sys.dont_write_bytecode = True
for _name in ('OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS', 'BLIS_NUM_THREADS'):
    os.environ[_name] = '1'
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'ai-service-python'))
WINDOWS = {'october_pre_dst': 1556, 'december_standard': 1568}
ARMS = {'proximity_unbounded': ('frozen', 0), 'proximity_6bars': ('frozen', 6),
    'pretrend_unbounded': ('source_ema_pretrend_pullback_v1', 0),
    'pretrend_6bars': ('source_ema_pretrend_pullback_v1', 6)}
FLAGS = ('own_agent_outcome', 'independent_validation', 'credit', 'mastery', 'economic_authority', 'paper_authority', 'live_authority')
TERMINAL = {'completed', 'technical_terminal', 'not_attempted_global_cap', 'not_attempted_frozen_or_launch_dependency'}
PRIOR_SHA = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4'
MANIFEST_SHA = 'b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92'
CALENDAR_SHA = '56939bbdb421f9ad810a3caebe3a07b15cc73112275df013d7fb92477ed41b05'
SOURCE_DIRS = ('ai-service-python/app', 'ai-service-python/scripts')
SOURCE_FILES = ('backend-laravel/app/Services/StrategyParameterSchemaService.php',
    'backend-laravel/app/Services/ResearchPaperEpochContractService.php',
    'backend-laravel/app/Services/FrozenM5CalendarAuditService.php', 'backend-laravel/scripts/audit-frozen-m5-gap-source.py',
    'backend-laravel/scripts/prepare-curator-scalp-panel.php', 'ai-service-python/tests/test_curator_scalp_panel.py',
    'ai-service-python/tests/test_curator_scalp_metrics.py', 'ai-service-python/tests/test_curator_scalp_preparation.py')


def need(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def sha(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def encoded(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()


def write_new(path, value):
    raw = value if isinstance(value, bytes) else encoded(value)
    with Path(path).open('xb') as stream:
        need(stream.write(raw) == len(raw), 'COMPLETE_EXCLUSIVE_WRITE_REQUIRED')
        stream.flush()
    return hashlib.sha256(raw).hexdigest()


def runtime_fingerprint():
    import numpy
    import pandas
    import pydantic
    modules = {'numpy': numpy, 'pandas': pandas, 'pydantic': pydantic}
    body = {'python_executable': str(Path(sys.executable).resolve()), 'python_executable_sha256': sha(sys.executable),
        'python_version': sys.version, 'versions': {name: module.__version__ for name, module in modules.items()},
        'entry_module_sha256': {name: sha(module.__file__) for name, module in modules.items()},
        'scope': 'interpreter_versions_and_entry_modules_not_full_third_party_binary_closure'}
    return {**body, 'checksum': hashlib.sha256(json.dumps(body, sort_keys=True, separators=(',', ':')).encode()).hexdigest()}


def source_roster():
    files = {}
    for directory in SOURCE_DIRS:
        for path in (ROOT / directory).rglob('*'):
            if path.is_file() and path.suffix.lower() in ('.py', '.json', '.yaml', '.yml', '.toml', '.csv', '.txt') \
                    and '__pycache__' not in path.parts and not path.name.startswith('.env'):
                files[path.relative_to(ROOT).as_posix()] = sha(path)
    for relative in SOURCE_FILES:
        files[relative] = sha(ROOT / relative)
    for relative in ('ai-service-python/scripts/curator_scalp_panel.py', 'ai-service-python/scripts/curator_scalp_metrics.py',
                     'ai-service-python/scripts/curator_management_witness.py'):
        need(relative in files, 'REQUIRED_OBSERVER_OR_METRICS_SOURCE_MISSING:' + relative)
    return dict(sorted(files.items()))


def physical_for_arm(baseline, arm):
    need(arm in ARMS, 'EXACT_DECLARED_ARM_REQUIRED')
    request = copy.deepcopy(baseline)
    request['parameters']['entry_topology_variant'], request['parameters']['time_stop_candles'] = ARMS[arm]
    return request


def validate_physical(request, baseline, arm):
    need(request == physical_for_arm(baseline, arm) and type(request['parameters']['time_stop_candles']) is int
        and 'policy_context' not in request, 'ONLY_TOPOLOGY_AND_TIME_STOP_DELTAS_ALLOWED')


def validate_roster(spec):
    need(set(spec['windows']) == set(WINDOWS) and set(spec['baselines']) == set(WINDOWS)
        and set(spec['requests']) == {window + '__' + arm for window in WINDOWS for arm in ARMS}
        and len(spec['requests']) == spec['predeclared_arms'] == 8, 'EXACT_TWO_BY_FOUR_ROSTER_REQUIRED')


def verify_wire(declaration):
    from app.services.research_program_tasks import canonical_hash
    raw = declaration['request_json'].encode()
    need(json.loads(raw) == declaration['request'] and hashlib.sha256(raw).hexdigest() == declaration['request_sha256']
        and canonical_hash(declaration['request']) == declaration['request_semantic_hash'], 'EXACT_WIRE_AND_SEMANTIC_REQUEST_REQUIRED')


def validate_design(spec):
    from app.schemas import SimpleBacktestRequest
    from app.services.execution_contract import execution_contract_metadata
    from app.services.parameter_schema import validate_strategy_parameters
    validate_roster(spec)
    need(spec['per_original_timeout_seconds'] == 600 and spec['global_timeout_seconds'] == 5400
        and spec['max_attempts_per_arm'] == 1 and spec['worker_threads'] == 1 and spec['serial_execution'] is True
        and spec['process_priority'] == 'below_normal_hidden' and spec['research_only'] is True
        and all(spec[flag] is False for flag in FLAGS), 'FIXED_BOUNDED_RESEARCH_ONLY_REQUIRED')
    for window_key, baseline in spec['baselines'].items():
        params = baseline['parameters']
        fixed = {'ema_fast': 20, 'ema_slow': 50, 'rsi_period': 14, 'trend_strength_min': 20,
            'pullback_atr_fraction': .75, 'minimum_signal_confidence': .35, 'volume_lane': 'none',
            'atr_stop_multiplier': 1.5, 'atr_target_multiplier': 2.5, 'trailing_atr_multiplier': 0,
            'partial_take_profit_fraction': 0, 'entry_topology_variant': 'frozen', 'time_stop_candles': 0}
        need(baseline['strategy'] == 'trend_v1' and baseline['base_strategy'] == 'trend'
            and baseline['timeframe'] == 'M5' and baseline['symbol'] == 'XAUUSD' and 'policy_context' not in baseline
            and all(params.get(key) == value for key, value in fixed.items())
            and params.get('trailing_activation_r', 0) == 0
            and baseline['execution']['allowed_sessions_utc'] == ['1-22'], 'EXACT_FROZEN_TREND_CONTROL_REQUIRED:' + window_key)
    for key, declaration in spec['requests'].items():
        window_key, arm = declaration['window'], declaration['arm']; window = spec['windows'][window_key]
        request = declaration['request']; raw = declaration['request_json'].encode()
        need(key == window_key + '__' + arm and declaration['baseline_key'] == window_key + '__proximity_unbounded'
            and declaration['question_id'] == spec['question_id'], 'EXACT_CASE_IDENTITY_REQUIRED:' + key)
        validate_physical(request, spec['baselines'][window_key], arm)
        verify_wire(declaration)
        payload = SimpleBacktestRequest.model_validate_json(raw)
        need(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy) == request['parameters']
            and not payload.policy_context and not payload.composition_runtime_contract and not payload.portfolio_members
            and not payload.specialist_council_contract and not payload.candles and payload.dataset_tail_rows is None,
            'ACTUAL_ORDINARY_PARAMETER_SCHEMA_AND_PHYSICAL_REQUEST_REQUIRED:' + key)
        execution = execution_contract_metadata(payload)
        need(execution['status'] == 'matched' and execution['execution_hash'] == declaration['cost_sha256']
            and declaration['cost_sha256'] == request['execution_contract']['execution_hash']
            and request['execution_contract']['parameters'] == request['execution']
            and declaration['dataset_sha256'] == window['sha256'] == request['replay_dataset_hash']
            and request['dataset_path'] == window['path'], 'EXACT_DATA_AND_EXECUTION_OWNER_HASHES_REQUIRED:' + key)
    return spec


def validate_inputs(spec):
    refs = spec['upstream']
    need(refs['prior']['sha256'] == PRIOR_SHA and refs['manifest']['sha256'] == MANIFEST_SHA
        and refs['calendar']['sha256'] == CALENDAR_SHA, 'EXACT_SEEN_CUSTODY_REQUIRED')
    for key, ref in refs.items():
        need(re.fullmatch(r'[A-Za-z0-9_-]+', key) and sha(ref['path']) == ref['sha256'], 'UPSTREAM_BYTES_CHANGED:' + key)
    prior = json.loads(Path(refs['prior']['path']).read_bytes())
    manifest = json.loads(Path(refs['manifest']['path']).read_bytes())
    calendar = json.loads(Path(refs['calendar']['path']).read_bytes())
    calendar_owners = ('ai-service-python/app/services/backtester.py', 'ai-service-python/app/services/market_sessions.py',
        'backend-laravel/app/Services/FrozenM5CalendarAuditService.php', 'backend-laravel/scripts/audit-frozen-m5-gap-source.py')
    retained = {}
    for path, identity in calendar['source_hashes'].items():
        normalized = path.replace('\\', '/')
        relative = next((name for name in calendar_owners if normalized.endswith('/' + name)), None)
        need(relative is not None and relative not in retained and sha(path) == identity
            and sha(ROOT / relative) == identity, 'RETAINED_AND_CURRENT_CALENDAR_OWNER_BYTES_REQUIRED:' + path)
        retained[relative] = identity
    need(set(retained) == set(calendar_owners), 'EXACT_ORIGINAL_CALENDAR_OWNER_ROSTER_REQUIRED')
    for field, identity in (('source_path', 'source_sha256'), ('source_manifest_path', 'source_manifest_sha256'), ('producer_path', 'producer_sha256')):
        need(sha(manifest[field]) == manifest[identity], 'ORIGINAL_RAW_CUSTODY_CHANGED:' + field)
    for key, rows in WINDOWS.items():
        window = spec['windows'][key]
        need(window == manifest['windows'][key] and window['loaded_rows'] == rows and window['warmup_rows'] == 200
            and window['evaluated_rows'] == rows - 200 and window['evaluation_last_close_utc'] < '2026-01-01T00:00:00Z'
            and calendar['windows'][key]['selected_unexpected_market_candles'] == 0
            and sha(window['path']) == window['sha256'], 'EXACT_PRE2026_RAW_WINDOW_REQUIRED:' + key)
        old = {field: value for field, value in prior['baselines'][key].items() if field != 'parameters'}
        actual = {field: value for field, value in spec['baselines'][key].items() if field != 'parameters'}
        old.update(strategy='trend_v1', base_strategy='trend')
        need(old == actual, 'OLD_ACCOUNT_RISK_COST_AND_DATA_MUST_REMAIN_IDENTICAL:' + key)


def validate_requests(spec):
    validate_design(spec); validate_inputs(spec)
    if 'runtime' in spec:
        need(spec['runtime'] == runtime_fingerprint(), 'CURRENT_RUNTIME_IDENTITY_REQUIRED')
    return spec


def archive_entries(spec):
    return {**spec['source_snapshot']['files'], 'external/draft.json': spec['draft']['sha256'],
        **{'external/upstream-' + key + '.json': ref['sha256'] for key, ref in spec['upstream'].items()},
        **{'external/' + key + '.csv': window['sha256'] for key, window in spec['windows'].items()}}


def frozen(spec, archive_contents=False):
    from app.services.research_program_tasks import canonical_hash
    files = spec['source_snapshot']['files']
    need(source_roster() == files and canonical_hash(files) == spec['source_snapshot']['snapshot_hash'], 'EXACT_FROZEN_SOURCE_ROSTER_REQUIRED')
    need(spec['runtime'] == runtime_fingerprint() and sha(spec['draft']['path']) == spec['draft']['sha256'], 'FROZEN_RUNTIME_OR_DRAFT_CHANGED')
    validate_inputs(spec)
    archive = spec['source_archive']; expected = archive_entries(spec)
    need(archive['entries'] == expected and sha(archive['path']) == archive['sha256'], 'FROZEN_SOURCE_ARCHIVE_CHANGED')
    if archive_contents:
        with zipfile.ZipFile(archive['path']) as zipped:
            names = zipped.namelist()
            need(len(names) == len(set(names)) and set(names) == set(expected), 'EXACT_ARCHIVE_ROSTER_REQUIRED')
            for name, identity in expected.items():
                need(hashlib.sha256(zipped.read(name)).hexdigest() == identity, 'ARCHIVE_ENTRY_CHANGED:' + name)


def seal(draft_path, folder):
    from app.services.research_program_tasks import canonical_hash
    draft_path, folder = Path(draft_path).resolve(), Path(folder).resolve()
    need(folder.is_relative_to(ROOT / '.runtime') and folder != ROOT / '.runtime', 'ISOLATED_NEW_QUESTION_FOLDER_REQUIRED')
    draft_sha = sha(draft_path); spec = json.loads(draft_path.read_bytes())
    need(spec['protocol'] == 'unfrozen_curator_scalp_panel_v1', 'UNFROZEN_SCALP_DRAFT_REQUIRED')
    validate_requests(spec)
    files = source_roster(); spec = {**spec, 'protocol': 'curator_scalp_archive_panel_v1', 'runtime': runtime_fingerprint(),
        'draft': {'path': str(draft_path), 'sha256': draft_sha}, 'source_snapshot': {'files': files, 'snapshot_hash': canonical_hash(files)}}
    need(sha(draft_path) == draft_sha, 'DRAFT_CHANGED_DURING_SEAL')
    folder.mkdir(parents=True, exist_ok=False); archive_path = folder / 'before-outcome-source-bytes.zip'
    entries = archive_entries(spec)
    paths = {**{key: ROOT / key for key in files}, 'external/draft.json': draft_path,
        **{'external/upstream-' + key + '.json': Path(ref['path']) for key, ref in spec['upstream'].items()},
        **{'external/' + key + '.csv': Path(window['path']) for key, window in spec['windows'].items()}}
    with zipfile.ZipFile(archive_path, 'x', compression=zipfile.ZIP_DEFLATED) as zipped:
        for name, path in paths.items():
            zipped.write(path, name)
    spec['source_archive'] = {'path': str(archive_path), 'sha256': sha(archive_path), 'entries': entries}
    frozen(spec, archive_contents=True)
    digest = write_new(folder / 'before-outcome-spec.json', spec)
    print(json.dumps({'status': 'sealed_eight_no_outcomes', 'spec_sha256': digest, 'spec_path': str(folder / 'before-outcome-spec.json')}))
    return spec, digest


def read_spec(path, expected):
    path = Path(path).resolve(); need(sha(path) == expected, 'EXACT_BEFORE_OUTCOME_SPEC_REQUIRED')
    spec = json.loads(path.read_bytes()); need(spec['protocol'] == 'curator_scalp_archive_panel_v1', 'EXACT_SCALP_PROTOCOL_REQUIRED')
    frozen(spec, archive_contents=True); return validate_requests(spec)


def utc_stamp(value):
    stamp = datetime.fromisoformat(value.replace('Z', '+00:00'))
    return (stamp.replace(tzinfo=timezone.utc) if stamp.tzinfo is None else stamp.astimezone(timezone.utc)).isoformat()


def clock_from_times(stamps, dataset_hash, execution_hash):
    from app.services.research_program_tasks import canonical_json
    need(len(stamps) > 200 and all(left < right for left, right in zip(stamps, stamps[1:])), 'FULL_ORDERED_INPUT_CLOCK_REQUIRED')
    indices, schedule = hashlib.sha256(b'replay-executed-clock-v1:indices\n'), hashlib.sha256(b'replay-executed-clock-v1:schedule\n')
    for index in range(200, len(stamps)):
        indices.update((str(index) + '\n').encode())
        schedule.update((canonical_json([index, stamps[index - 1], stamps[index]]) + '\n').encode())
    return {'protocol': 'replay_executed_clock_v1', 'owner': 'ordinary_single_position_v1',
        'semantics': 'previous_closed_candle_next_open_v1', 'index_basis': 'evaluated_frame_zero_based_v1',
        'input_rows': len(stamps), 'evaluation_offset_rows': 0, 'execution_timeframe': 'M5', 'duration_seconds': 300,
        'dataset_hash': dataset_hash, 'execution_hash': execution_hash, 'policy_hash': None, 'probe_contract_hash': None,
        'complete': True, 'decision_rows': len(stamps) - 200, 'first_evaluation_index': 200, 'last_evaluation_index': len(stamps) - 1,
        'signal_start': stamps[199], 'signal_end': stamps[-2], 'execution_start': stamps[200], 'execution_end': stamps[-1],
        'index_set_hash': indices.hexdigest(), 'schedule_hash': schedule.hexdigest(), 'promotion_evidence': False}


def verify_clock(clock, window, execution_hash):
    from app.services.research_program_tasks import canonical_hash, _same_ast_copy
    need(sha(window['path']) == window['sha256'], 'EXACT_CLOCK_SOURCE_BYTES_REQUIRED')
    with Path(window['path']).open('r', encoding='utf-8-sig', newline='') as stream:
        stamps = [utc_stamp(row['time']) for row in csv.DictReader(stream)]
    expected = clock_from_times(stamps, window['sha256'], execution_hash)
    body = json.loads(clock['receipt_json'])
    need(body == expected and canonical_hash(body) == clock['receipt_hash']
        and _same_ast_copy({key: value for key, value in clock.items() if key not in ('receipt_hash', 'receipt_json')}, body)
        and len(stamps) == window['loaded_rows'] and expected['decision_rows'] == window['evaluated_rows'], 'ORIGINAL_FULL_CLOCK_INVALID')


def verify_response(body, declaration, window, envelope, spec, response_sha):
    from app.schemas import SimpleTrade
    from app.services.backtester import _trade_ledger_hash
    from app.services.research_program_tasks import canonical_hash
    from scripts.curator_management_witness import verify_management_witness
    from scripts.curator_scalp_metrics import summarize_scalp_metrics
    key = declaration['window'] + '__' + declaration['arm']; request = declaration['request']
    need(envelope['protocol'] == 'curator_scalp_full_close_envelope_v1' and envelope['case_key'] == key
        and envelope['request_sha256'] == declaration['request_sha256'] and envelope['response_sha256'] == response_sha,
        'EXACT_RAW_RESPONSE_WITNESS_BINDING_REQUIRED')
    verify_clock(body['data_quality']['replay_executed_clock'], window, declaration['cost_sha256'])
    need(body['parameters'] == request['parameters'] and body['execution_assumptions'] == request['execution']
        and body['execution_contract']['execution_hash'] == declaration['cost_sha256']
        and body['initial_balance'] == request['initial_balance'], 'ACTUAL_ACCOUNT_AND_EXECUTION_DRIFT')
    trades = [SimpleTrade.model_validate(trade) for trade in body['trade_ledger']]
    trace, quality = body['decision_trace'], body['data_quality']['decision_trace']
    need(len(trades) == body['total_trades'] and _trade_ledger_hash(trades) == body['trade_ledger_hash']
        and quality['complete'] is True and quality['event_count'] == len(trace)
        and quality['evaluated_candle_count'] == window['evaluated_rows']
        and sum(body['event_ledger_categories'].values()) == body['event_ledger_count'], 'ORIGINAL_LEDGER_AND_TRACE_REQUIRED')
    need(not any(event.get('event_type') == 'instrument_method_decision' for event in trace)
        and not body['data_quality'].get('instrument_method_learning_receipt'), 'NO_AGENT_METHOD_SELECTION_ALLOWED')
    witness = envelope['full_close_witness']; cash = verify_management_witness(body, witness)
    scalp = summarize_scalp_metrics(request, body, witness, csv_path=window['path'], max_holding_minutes=30.0)
    clock = body['data_quality']['replay_executed_clock']
    return {'net_cash': cash['exact_net_cash'], 'mature': cash['mature_trade_count'], 'trades': cash['closed_trade_count'],
        'open_position_count': cash['open_position_count'], 'closed_cash_drawdown_percent': cash['closed_cash_drawdown_percent'],
        'cash_metrics': cash, 'scalp_metrics': scalp, 'clock_hash': clock['receipt_hash'],
        'index_set_hash': clock['index_set_hash'], 'schedule_hash': clock['schedule_hash'],
        'actual_clock': {key: value for key, value in clock.items() if key not in ('receipt_hash', 'receipt_json')},
        'trade_ledger_hash': body['trade_ledger_hash'], 'trace_hash': canonical_hash(trace),
        'total_marked_utility_claim': False, 'six_bars_are_not_a_wall_clock_deadline': True}


def remaining_budget(attempt, now):
    start, deadline = attempt['started_at_monotonic'], attempt['global_deadline_monotonic']
    need(all(type(value) in (int, float) and math.isfinite(value) for value in (start, deadline, now))
        and deadline == start + 5400 and now >= start, 'ORIGINAL_GLOBAL_DEADLINE_REQUIRED')
    need(now < deadline, 'ORIGINAL_GLOBAL_BUDGET_EXPIRED')
    return min(600.0, deadline - now)


def authorize_worker(attempt, launch, expected, key, request_sha, now, parent_pid):
    remaining_budget(attempt, now)
    need(all(type(launch[field]) in (int, float) and math.isfinite(launch[field])
             for field in ('started_at_monotonic', 'deadline_monotonic'))
        and attempt['spec_sha256'] == launch['spec_sha256'] == expected and attempt['max_attempts'] == 1
        and launch['case_key'] == key and launch['request_sha256'] == request_sha
        and launch['parent_pid'] == attempt['parent_pid'] == parent_pid
        and launch['started_at_monotonic'] >= attempt['started_at_monotonic']
        and launch['deadline_monotonic'] == min(attempt['global_deadline_monotonic'], launch['started_at_monotonic'] + 600)
        and launch['started_at_monotonic'] <= now < launch['deadline_monotonic'], 'ORIGINAL_PER_ARM_DEADLINE_OR_PARENT_REQUIRED')


def stage_requests(products, spec):
    for key, declaration in spec['requests'].items():
        write_new(products / (key + '.request.json'), declaration['request_json'].encode())


def worker(spec_path, expected, key):
    from app.schemas import SimpleBacktestRequest
    from app.services.backtester import _load_simple_candles, run_simple_ema_rsi_backtest_on_dataframe
    from scripts.curator_management_witness import capture_management_witness
    spec = read_spec(spec_path, expected); declaration = spec['requests'][key]
    folder = Path(spec_path).resolve().parent; products = folder / 'products'
    raw = sys.stdin.buffer.read(); need(raw == declaration['request_json'].encode(), 'EXACT_ORIGINAL_REQUEST_WIRE_REQUIRED')
    attempt = json.loads((folder / 'execution-attempt.json').read_bytes())
    launch = json.loads((products / (key + '.launch.json')).read_bytes())
    authorize_worker(attempt, launch, expected, key, declaration['request_sha256'], time.monotonic(), os.getppid())
    for other_key, other in spec['requests'].items():
        need(sha(products / (other_key + '.request.json')) == other['request_sha256'], 'ALL_EIGHT_REQUESTS_REQUIRED_BEFORE_FIRST_WORKER')
    write_new(products / (key + '.worker-attempt.json'), {'spec_sha256': expected, 'case_key': key,
        'request_sha256': declaration['request_sha256'], 'max_attempts': 1, 'started_at_monotonic': time.monotonic()})
    payload = SimpleBacktestRequest.model_validate_json(raw)
    authorize_worker(attempt, launch, expected, key, declaration['request_sha256'], time.monotonic(), os.getppid())
    with capture_management_witness() as observer:
        response = run_simple_ema_rsi_backtest_on_dataframe(payload, _load_simple_candles(payload), lightweight=True, include_differential_pair=False)
    output = response.model_dump_json().encode(); sys.stdout.buffer.write(output); sys.stdout.buffer.flush()
    envelope = {'protocol': 'curator_scalp_full_close_envelope_v1', 'case_key': key,
        'request_sha256': declaration['request_sha256'], 'response_sha256': hashlib.sha256(output).hexdigest(),
        'full_close_witness': observer.snapshot()}
    write_new(products / (key + '.witness.json'), envelope)
    verify_response(json.loads(output), declaration, spec['windows'][declaration['window']], envelope, spec, envelope['response_sha256'])
    frozen(spec)


def matched_pair(left, right):
    return left['status'] == right['status'] == 'completed' and left['dataset_sha256'] == right['dataset_sha256'] \
        and left['cost_sha256'] == right['cost_sha256'] and all(left['evidence'][key] == right['evidence'][key]
            for key in ('index_set_hash', 'schedule_hash', 'actual_clock'))


def add_comparisons(report):
    pairs = {'time_given_proximity': ('proximity_6bars', 'proximity_unbounded'),
        'time_given_pretrend': ('pretrend_6bars', 'pretrend_unbounded'),
        'topology_given_unbounded': ('pretrend_unbounded', 'proximity_unbounded'),
        'topology_given_6bars': ('pretrend_6bars', 'proximity_6bars'), 'joint_vs_baseline': ('pretrend_6bars', 'proximity_unbounded')}
    report['window_comparisons'] = {}
    for window in WINDOWS:
        rows = {arm: report['results'][window + '__' + arm] for arm in ARMS}; comparisons = {}
        for label, (treated, control) in pairs.items():
            left, right = rows[treated], rows[control]; matched = matched_pair(left, right)
            comparisons[label] = {'matched_complete': matched, 'treated_status': left['status'], 'control_status': right['status'],
                'realized_cash_delta': left['evidence']['net_cash'] - right['evidence']['net_cash'] if matched else None,
                'eof_open_exposure_present': bool(left['evidence']['open_position_count'] or right['evidence']['open_position_count']) if matched else None,
                'technical_is_not_an_economic_zero': not matched, 'independent': False, 'dominance_claim': False,
                'total_marked_utility_claim': False}
        interaction = None
        if all(matched_pair(row, rows['proximity_unbounded']) for row in rows.values()):
            cash = {arm: row['evidence']['net_cash'] for arm, row in rows.items()}
            interaction = cash['pretrend_6bars'] - cash['pretrend_unbounded'] - cash['proximity_6bars'] + cash['proximity_unbounded']
        report['window_comparisons'][window] = {'pairs': comparisons, 'cash_interaction': interaction, 'independent': False}


def execute(spec_path, expected):
    spec_path = Path(spec_path).resolve(); spec = read_spec(spec_path, expected); folder = spec_path.parent
    started = time.monotonic(); attempt = {'spec_sha256': expected, 'max_attempts': 1, 'parent_pid': os.getpid(),
        'started_at_unix': time.time(), 'started_at_monotonic': started, 'global_deadline_monotonic': started + 5400}
    write_new(folder / 'execution-attempt.json', attempt); products = folder / 'products'; products.mkdir(exist_ok=False)
    stage_requests(products, spec)
    report = {'protocol': 'curator_scalp_original_report_v1', 'question_id': spec['question_id'], 'before_outcome_spec_sha256': expected,
        'research_only': True, **{flag: False for flag in FLAGS}, 'results': {}}
    for key, declaration in spec['requests'].items():
        result = {'status': 'not_attempted_global_cap', 'timeout': False, 'wall_seconds': 0.0,
            'request_sha256': declaration['request_sha256'], 'response_sha256': None}
        process = None
        try:
            remaining_budget(attempt, time.monotonic()); frozen(spec)
            before = time.monotonic(); limit = remaining_budget(attempt, before)
            launch = {'spec_sha256': expected, 'case_key': key, 'request_sha256': declaration['request_sha256'],
                'parent_pid': os.getpid(), 'started_at_monotonic': before,
                'deadline_monotonic': min(attempt['global_deadline_monotonic'], before + 600)}
            write_new(products / (key + '.launch.json'), launch)
            flags = subprocess.CREATE_NO_WINDOW | subprocess.BELOW_NORMAL_PRIORITY_CLASS if os.name == 'nt' else 0
            process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--worker', key, '--spec', str(spec_path), '--spec-sha256', expected],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=ROOT,
                env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'}, creationflags=flags)
            timeout = False
            try:
                output, error = process.communicate(declaration['request_json'].encode(), timeout=max(.001, launch['deadline_monotonic'] - time.monotonic()))
            except subprocess.TimeoutExpired:
                timeout = True; process.kill(); output, error = process.communicate()
            response_sha = write_new(products / (key + '.response.json'), output)
            stderr_sha = write_new(products / (key + '.stderr.txt'), error)
            result = {'status': 'technical_terminal', 'timeout': timeout, 'timeout_limit_seconds': limit,
                'exit_code': process.returncode, 'wall_seconds': time.monotonic() - before,
                'request_sha256': declaration['request_sha256'], 'response_sha256': response_sha,
                'stderr_sha256': stderr_sha, 'response_preserved_before_verification': bool(output)}
            witness_path = products / (key + '.witness.json')
            if witness_path.is_file():
                result['witness_sha256'] = sha(witness_path)
            if not timeout and process.returncode == 0:
                try:
                    result['evidence'] = verify_response(json.loads(output), declaration, spec['windows'][declaration['window']],
                        json.loads(witness_path.read_bytes()), spec, response_sha)
                    frozen(spec); result['status'] = 'completed'
                except Exception as failure:
                    result['verification_error'] = str(failure)
        except Exception as failure:
            if process is not None and process.poll() is None:
                process.kill()
                output, error = process.communicate()
                for suffix, raw, field in (('.response.json', output, 'response_sha256'), ('.stderr.txt', error, 'stderr_sha256')):
                    path = products / (key + suffix)
                    if not path.exists():
                        write_new(path, raw)
                    result[field] = sha(path)
            result['status'] = 'technical_terminal' if process is not None else ('not_attempted_global_cap' if 'BUDGET_EXPIRED' in str(failure) else 'not_attempted_frozen_or_launch_dependency')
            result['dependency'] = str(failure)
        write_new(products / (key + '.process.json'), result)
        report['results'][key] = {'case_key': key, 'window': declaration['window'], 'arm': declaration['arm'],
            'baseline_key': declaration['baseline_key'], 'dataset_sha256': declaration['dataset_sha256'], 'cost_sha256': declaration['cost_sha256'], **result}
        print(json.dumps({'case': key, 'status': result['status'], 'wall_seconds': result['wall_seconds']}), flush=True)
    add_comparisons(report)
    try:
        frozen(spec, archive_contents=True); report['final_source_check'] = 'unchanged'
    except Exception as failure:
        report['final_source_check'] = 'changed'; report['final_source_error'] = str(failure)
    report['wall_seconds'] = time.monotonic() - started
    digest = write_new(folder / 'original-panel-summary.json', report)
    print(json.dumps({'status': 'all_eight_terminal', 'summary_sha256': digest,
        'completed': sum(row['status'] == 'completed' for row in report['results'].values())}))
    return report


def artifact_hash(path, expected=None):
    need(expected is None or path.is_file() and sha(path) == expected, 'ORIGINAL_ARTIFACT_HASH_MISMATCH:' + path.name)
    return sha(path) if path.is_file() else None


def verify_process_link(process, original, declaration):
    need(process['status'] in TERMINAL and process['request_sha256'] == declaration['request_sha256']
        and all(key in original and original[key] == value for key, value in process.items()), 'ORIGINAL_SUMMARY_PROCESS_MISMATCH')
    if process['status'] == 'completed':
        need(process['timeout'] is False and process['exit_code'] == 0 and process['response_preserved_before_verification'] is True
            and all(isinstance(process.get(key), str) for key in ('response_sha256', 'stderr_sha256', 'witness_sha256')),
            'COMPLETED_ORIGINAL_STREAM_SEALS_REQUIRED')


def inspect_originals(spec_path, expected):
    """Read-only independent reopening; no replay, cache lookup or product write."""
    from app.services.research_program_tasks import canonical_hash
    spec_path = Path(spec_path).resolve(); spec = read_spec(spec_path, expected); folder = spec_path.parent
    summary_path = folder / 'original-panel-summary.json'; summary_sha = sha(summary_path)
    summary = json.loads(summary_path.read_bytes()); products = folder / 'products'
    need(summary['protocol'] == 'curator_scalp_original_report_v1' and summary['before_outcome_spec_sha256'] == expected
        and summary['question_id'] == spec['question_id'] and summary['final_source_check'] == 'unchanged'
        and set(summary['results']) == set(spec['requests']) and len(summary['results']) == 8
        and summary['research_only'] is True and all(summary[flag] is False for flag in FLAGS)
        and all(row['status'] in TERMINAL for row in summary['results'].values()), 'ALL_EIGHT_ORIGINAL_CASES_TERMINAL_REQUIRED')
    attempt_path = folder / 'execution-attempt.json'; attempt = json.loads(attempt_path.read_bytes())
    need(attempt['spec_sha256'] == expected and attempt['max_attempts'] == 1
        and attempt['global_deadline_monotonic'] == attempt['started_at_monotonic'] + 5400, 'EXACT_ORIGINAL_ATTEMPT_REQUIRED')
    rederived, artifacts = {'results': {}}, {}
    for key, declaration in spec['requests'].items():
        process_path = products / (key + '.process.json'); process = json.loads(process_path.read_bytes())
        original = summary['results'][key]; verify_process_link(process, original, declaration)
        need(all(original[field] == declaration[field] for field in ('window', 'arm', 'baseline_key', 'dataset_sha256', 'cost_sha256')),
            'ORIGINAL_DECLARATION_SCOPE_MISMATCH')
        hashes = {'request_sha256': artifact_hash(products / (key + '.request.json'), declaration['request_sha256']),
            'process_sha256': sha(process_path), 'response_sha256': artifact_hash(products / (key + '.response.json'), process.get('response_sha256')),
            'stderr_sha256': artifact_hash(products / (key + '.stderr.txt'), process.get('stderr_sha256')),
            'witness_sha256': artifact_hash(products / (key + '.witness.json'), process.get('witness_sha256')),
            'launch_sha256': artifact_hash(products / (key + '.launch.json')),
            'worker_attempt_sha256': artifact_hash(products / (key + '.worker-attempt.json'))}
        row = copy.deepcopy(original)
        if process['status'] == 'completed':
            launch = json.loads((products / (key + '.launch.json')).read_bytes())
            worker_attempt = json.loads((products / (key + '.worker-attempt.json')).read_bytes())
            need(launch['spec_sha256'] == worker_attempt['spec_sha256'] == expected
                and launch['case_key'] == worker_attempt['case_key'] == key and worker_attempt['max_attempts'] == 1
                and launch['request_sha256'] == worker_attempt['request_sha256'] == declaration['request_sha256']
                and launch['parent_pid'] == attempt['parent_pid']
                and attempt['started_at_monotonic'] <= launch['started_at_monotonic'] <= worker_attempt['started_at_monotonic'] < launch['deadline_monotonic']
                and launch['deadline_monotonic'] == min(attempt['global_deadline_monotonic'], launch['started_at_monotonic'] + 600),
                'EXACT_ORIGINAL_WORKER_AND_DEADLINE_REQUIRED')
            body = json.loads((products / (key + '.response.json')).read_bytes())
            envelope = json.loads((products / (key + '.witness.json')).read_bytes())
            row['evidence'] = verify_response(body, declaration, spec['windows'][declaration['window']], envelope, spec, hashes['response_sha256'])
            need(row['evidence'] == process['evidence'] == original['evidence'], 'REOPENED_ORIGINAL_EVIDENCE_MISMATCH')
        artifacts[key] = hashes; rederived['results'][key] = row
    add_comparisons(rederived)
    need(rederived['window_comparisons'] == summary['window_comparisons'], 'REOPENED_ORIGINAL_COMPARISONS_MISMATCH')
    frozen(spec, archive_contents=True); need(sha(summary_path) == summary_sha, 'SUMMARY_CHANGED_DURING_INSPECTION')
    verification = {'status': 'verified_read_only', 'spec_sha256': expected, 'summary_sha256': summary_sha,
        'terminal_cases': 8, 'completed_originals_reverified': sum(row['status'] == 'completed' for row in rederived['results'].values()),
        'technical_terminal_cases': sum(row['status'] == 'technical_terminal' for row in rederived['results'].values()),
        'source_archive_sha256': spec['source_archive']['sha256'], 'artifact_roster_sha256': canonical_hash(artifacts),
        'comparisons_sha256': canonical_hash(rederived['window_comparisons']), 'source_and_archive_reverified': True,
        'whole_account_only': True, 'independent_validation': False, 'own_agent_outcome': False}
    return {'spec': spec, 'summary': summary, 'results': rederived['results'], 'window_comparisons': rederived['window_comparisons'],
        'artifacts': artifacts, 'verification': verification}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--folder'); parser.add_argument('--spec'); parser.add_argument('--spec-sha256')
    mode = parser.add_mutually_exclusive_group(required=True); mode.add_argument('--seal'); mode.add_argument('--execute'); mode.add_argument('--worker')
    mode.add_argument('--validate-draft', '--validate', dest='validate_draft')
    mode.add_argument('--inspect')
    args = parser.parse_args()
    if args.seal:
        need(bool(args.folder), 'NEW_OUTPUT_FOLDER_REQUIRED'); seal(args.seal, args.folder)
    elif args.validate_draft:
        draft = json.loads(Path(args.validate_draft).read_bytes()); need(draft['protocol'] == 'unfrozen_curator_scalp_panel_v1', 'UNFROZEN_SCALP_DRAFT_REQUIRED')
        validate_requests(draft); print(json.dumps({'status': 'eight_validated_no_outcomes'}))
    elif args.worker:
        need(bool(args.spec and args.spec_sha256), 'SPEC_AND_EXPECTED_SHA_REQUIRED'); worker(args.spec, args.spec_sha256, args.worker)
    elif args.inspect:
        need(bool(args.spec_sha256), 'EXPECTED_SPEC_SHA_REQUIRED'); print(json.dumps(inspect_originals(args.inspect, args.spec_sha256)['verification']))
    else:
        need(bool(args.spec_sha256), 'EXPECTED_SPEC_SHA_REQUIRED'); execute(args.execute, args.spec_sha256)
