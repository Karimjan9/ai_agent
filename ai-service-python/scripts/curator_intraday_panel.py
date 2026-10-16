"""One prospective, serial offline scalp/day-style source study, never a scheduler.

Shares the original replay/clock/witness owners with the earlier EMA question.
Its requests, deadlines and artifacts are new; it cannot complete an old arm.
"""
import argparse
import copy
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import zipfile

sys.dont_write_bytecode = True
for _thread in ('OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS', 'BLIS_NUM_THREADS'):
    os.environ[_thread] = '1'
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'ai-service-python'))
from scripts import curator_scalp_panel as original

need, sha, encoded, write_new = original.need, original.sha, original.encoded, original.write_new
WINDOWS = original.WINDOWS
FLAGS = original.FLAGS
ARMS = {'breakout_scalp6': ('session_v1', 'session', 6),
        'reentry_scalp6': ('session_mean_reversion_v1', 'session_mean_reversion', 6),
        'breakout_day24': ('session_v1', 'session', 24),
        'reentry_day24': ('session_mean_reversion_v1', 'session_mean_reversion', 24)}
QUESTION = 'curator-intraday-session-20261016-v1'
EXTRA_SOURCE = ('backend-laravel/scripts/prepare-curator-intraday-panel.php',
    'backend-laravel/app/Services/ExecutionContractService.php',
    'backend-laravel/tests/Unit/SessionMeanReversionSchemaParityTest.php',
    'ai-service-python/tests/test_curator_intraday_panel.py',
    'ai-service-python/tests/test_curator_intraday_strategies.py',
    'ai-service-python/tests/test_curator_research_deadline.py')


def source_roster():
    return dict(sorted({**original.source_roster(), **{name: sha(ROOT / name) for name in EXTRA_SOURCE}}.items()))


def physical_for_arm(baseline, arm):
    request = copy.deepcopy(baseline)
    request['strategy'], request['base_strategy'], request['parameters']['time_stop_candles'] = ARMS[arm]
    return request


def validate_design(spec):
    from app.schemas import SimpleBacktestRequest
    from app.services.parameter_schema import validate_strategy_parameters
    from app.services.execution_contract import execution_contract_metadata
    from app.strategies.registry import get_strategy
    from app.strategies.laboratory import apply_session_strategy, apply_session_mean_reversion_strategy
    need(spec['question_id'] == QUESTION and set(spec['windows']) == set(WINDOWS)
         and set(spec['baselines']) == set(WINDOWS)
         and set(spec['requests']) == {window + '__' + arm for window in WINDOWS for arm in ARMS}
         and spec['predeclared_arms'] == 8, 'EXACT_NEW_TWO_BY_FOUR_QUESTION_REQUIRED')
    need(spec['per_original_timeout_seconds'] == 600 and spec['global_timeout_seconds'] == 5400
         and spec['max_attempts_per_arm'] == 1 and spec['serial_execution'] is True
         and spec['worker_threads'] == 1 and spec['process_priority'] == 'below_normal_hidden'
         and spec['research_only'] is True and all(spec[flag] is False for flag in FLAGS),
         'FIXED_BOUNDED_RESEARCH_ONLY_REQUIRED')
    for window, baseline in spec['baselines'].items():
        fixed = {'session_start': 7, 'session_end': 14, 'lookback': 20, 'time_stop_candles': 6,
                 'minimum_signal_confidence': .35, 'atr_stop_multiplier': 1.5,
                 'atr_target_multiplier': 2.5, 'trailing_atr_multiplier': 0.,
                 'partial_take_profit_fraction': 0., 'volume_lane': 'none'}
        need(all(baseline['parameters'].get(key) == value for key, value in fixed.items())
             and baseline['execution']['allowed_sessions_utc'] == ['7-14']
             and baseline['symbol'] == 'XAUUSD' and baseline['timeframe'] == 'M5'
             and baseline['strategy'] == 'session_v1' and baseline['base_strategy'] == 'session'
             and baseline['initial_balance'] == 10000. and baseline['risk_per_trade'] == 1.
             and not baseline.get('policy_context'), 'EXACT_SOURCE_SESSION_BASELINE_REQUIRED:' + window)
    for key, declaration in spec['requests'].items():
        window, arm = declaration['window'], declaration['arm']
        need(key == window + '__' + arm and declaration['question_id'] == QUESTION
             and declaration['baseline_key'] == window + '__breakout_scalp6'
             and declaration['request'] == physical_for_arm(spec['baselines'][window], arm),
             'ONLY_METHOD_IDENTITY_AND_HOLDING_COORDINATES_ALLOWED')
        original.verify_wire(declaration)
        request = declaration['request']; payload = SimpleBacktestRequest.model_validate_json(declaration['request_json'])
        need(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy) == request['parameters']
             and not payload.policy_context and not payload.composition_runtime_contract and not payload.portfolio_members
             and not payload.specialist_council_contract and not payload.candles and payload.dataset_tail_rows is None
             and execution_contract_metadata(payload)['execution_hash'] == declaration['cost_sha256']
             and execution_contract_metadata(payload)['status'] == 'matched'
             and request['execution_contract']['parameters'] == request['execution']
             and request['execution_contract']['execution_hash'] == declaration['cost_sha256']
             and request['replay_dataset_hash'] == declaration['dataset_sha256'] == spec['windows'][window]['sha256'],
             'ACTUAL_PYTHON_SCHEMA_EXECUTION_AND_DATA_REQUIRED')
        need(request['dataset_path'] == spec['windows'][window]['path'], 'EXACT_REQUEST_WINDOW_PATH_REQUIRED')
        function = apply_session_strategy if arm.startswith('breakout') else apply_session_mean_reversion_strategy
        need(get_strategy(payload.strategy, payload.base_strategy) is function, 'ACTUAL_DISTINCT_STRATEGY_DISPATCH_REQUIRED')


def validate_inputs(spec):
    from app.services.research_program_tasks import canonical_hash
    pinned = {'prior': original.PRIOR_SHA, 'manifest': original.MANIFEST_SHA, 'calendar': original.CALENDAR_SHA}
    sources = {}
    for name, expected in pinned.items():
        ref = spec['upstream'][name]
        need(ref['sha256'] == expected and sha(ref['path']) == expected, 'EXACT_ORIGINAL_CUSTODY_REQUIRED:' + name)
        sources[name] = json.loads(Path(ref['path']).read_bytes())
    manifest, calendar, prior = sources['manifest'], sources['calendar'], sources['prior']
    need(manifest['timestamp_semantics'] == 'original_M5_open_in_UTC_decision_inputs_available_only_after_closure',
         'ORIGINAL_DECLARED_UTC_SEMANTICS_REQUIRED')
    for field, identity in (('source_path', 'source_sha256'), ('source_manifest_path', 'source_manifest_sha256'), ('producer_path', 'producer_sha256')):
        need(sha(manifest[field]) == manifest[identity], 'ORIGINAL_RAW_PARENT_CUSTODY_CHANGED:' + field)
    for window, rows in WINDOWS.items():
        chosen = spec['windows'][window]
        need(chosen == manifest['windows'][window] and chosen['loaded_rows'] == rows
             and chosen['warmup_rows'] == 200 and chosen['evaluated_rows'] == rows - 200
             and chosen['evaluation_last_close_utc'] < '2026-01-01T00:00:00Z'
             and calendar['windows'][window]['selected_unexpected_market_candles'] == 0
             and sha(chosen['path']) == chosen['sha256'], 'EXACT_PRE2026_SELECTED_DATA_REQUIRED')
        actual, old = copy.deepcopy(spec['baselines'][window]), copy.deepcopy(prior['baselines'][window])
        for body in (actual, old):
            for field in ('strategy', 'base_strategy', 'parameters', 'execution_contract'):
                body.pop(field)
        old['execution']['allowed_sessions_utc'] = ['7-14']
        need(actual == old, 'ACCOUNT_DATA_AND_MODELED_COSTS_CANNOT_BE_TUNED')
    # A calendar classification can only be borrowed with its exact producer bytes.
    owners = set()
    for path, identity in calendar['source_hashes'].items():
        normalized = path.replace('\\', '/')
        relative = next((name for name in ('ai-service-python/app/services/backtester.py',
            'ai-service-python/app/services/market_sessions.py',
            'backend-laravel/app/Services/FrozenM5CalendarAuditService.php',
            'backend-laravel/scripts/audit-frozen-m5-gap-source.py') if normalized.endswith('/' + name)), None)
        need(relative is not None and relative not in owners and sha(path) == identity and sha(ROOT / relative) == identity,
             'EXACT_ORIGINAL_CALENDAR_OWNER_BYTES_REQUIRED')
        owners.add(relative)
    need(len(owners) == 4, 'EXACT_FOUR_CALENDAR_OWNERS_REQUIRED')
    if 'runtime' in spec:
        need(spec['runtime'] == original.runtime_fingerprint(), 'ORIGINAL_RUNTIME_CHANGED')
    return canonical_hash(spec['requests'])


def validate(spec):
    validate_design(spec); validate_inputs(spec)


def frozen(spec, archive_contents=False):
    from app.services.research_program_tasks import canonical_hash
    files = source_roster()
    need(files == spec['source_snapshot']['files'] and canonical_hash(files) == spec['source_snapshot']['snapshot_hash'],
         'EXACT_NEW_FROZEN_SOURCE_REQUIRED')
    validate(spec)
    need(sha(spec['draft']['path']) == spec['draft']['sha256'], 'ORIGINAL_DRAFT_CHANGED')
    entries = original.archive_entries(spec)
    need(entries == spec['source_archive']['entries'] and sha(spec['source_archive']['path']) == spec['source_archive']['sha256'],
         'SOURCE_ARCHIVE_CHANGED')
    if archive_contents:
        with zipfile.ZipFile(spec['source_archive']['path']) as archive:
            need(len(archive.namelist()) == len(set(archive.namelist())) and set(archive.namelist()) == set(entries),
                 'EXACT_ARCHIVE_ROSTER_REQUIRED')
            for name, identity in entries.items():
                need(hashlib.sha256(archive.read(name)).hexdigest() == identity, 'ARCHIVE_ENTRY_CHANGED:' + name)


def seal(draft_path, folder):
    from app.services.research_program_tasks import canonical_hash
    draft_path, folder = Path(draft_path).resolve(), Path(folder).resolve()
    need(folder.is_relative_to(ROOT / '.runtime') and folder != ROOT / '.runtime', 'NEW_ISOLATED_OUTPUT_REQUIRED')
    draft_sha = sha(draft_path); spec = json.loads(draft_path.read_bytes())
    need(spec['protocol'] == 'unfrozen_curator_intraday_panel_v1', 'NEW_DRAFT_REQUIRED')
    validate(spec); files = source_roster()
    spec.update(protocol='curator_intraday_archive_panel_v1', runtime=original.runtime_fingerprint(),
        draft={'path': str(draft_path), 'sha256': draft_sha},
        source_snapshot={'files': files, 'snapshot_hash': canonical_hash(files)})
    folder.mkdir(parents=True, exist_ok=False)
    paths = {**{name: ROOT / name for name in files}, 'external/draft.json': draft_path,
             **{'external/upstream-' + name + '.json': Path(ref['path']) for name, ref in spec['upstream'].items()},
             **{'external/' + name + '.csv': Path(window['path']) for name, window in spec['windows'].items()}}
    archive_path = folder / 'before-outcome-source-bytes.zip'
    with zipfile.ZipFile(archive_path, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, path in paths.items():
            archive.write(path, name)
    spec['source_archive'] = {'path': str(archive_path), 'sha256': sha(archive_path), 'entries': original.archive_entries(spec)}
    frozen(spec, archive_contents=True)
    digest = write_new(folder / 'before-outcome-spec.json', spec)
    print(json.dumps({'status': 'sealed_eight_no_outcomes', 'spec_path': str(folder / 'before-outcome-spec.json'), 'spec_sha256': digest}))
    return digest


def prepare(php_binary, output):
    path = Path(output).resolve()
    need(path.is_relative_to(ROOT / '.runtime') and path.parent.is_dir(), 'ISOLATED_EXCLUSIVE_DRAFT_PATH_REQUIRED')
    completed = subprocess.run([str(php_binary), str(ROOT / 'backend-laravel/scripts/prepare-curator-intraday-panel.php'), '--export-draft'],
        cwd=ROOT, capture_output=True, check=True, timeout=60,
        creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    draft = json.loads(completed.stdout)
    validate(draft)
    digest = write_new(path, completed.stdout)
    print(json.dumps({'status': 'actual_php_draft_prepared_no_outcomes', 'draft_sha256': digest, 'draft_path': str(path)}))


def read_spec(path, expected):
    need(sha(path) == expected, 'EXACT_BEFORE_OUTCOME_SPEC_REQUIRED')
    spec = json.loads(Path(path).read_bytes())
    need(spec['protocol'] == 'curator_intraday_archive_panel_v1', 'NEW_INTRADAY_PROTOCOL_REQUIRED')
    frozen(spec, archive_contents=True)
    return spec


def verify_response(body, declaration, window, envelope, spec, response_sha):
    evidence = original.verify_response(body, declaration, window, envelope, spec, response_sha)
    metrics = evidence['scalp_metrics']; trades = metrics['per_trade']
    cap = 30 if declaration['arm'].endswith('scalp6') else 120
    evidence['intraday_profile'] = {'requested_profile': 'scalp' if cap == 30 else 'day_style',
        'nominal_holding_minutes': cap, 'closed_same_utc_date': sum(row['entry_time'][:10] == row['exit_time'][:10] for row in trades),
        'overnight_mature_trades': sum(row['entry_time'][:10] != row['exit_time'][:10] for row in trades),
        'observed_over_profile_cap': sum(row['holding_minutes'] > cap for row in trades),
        'general_session_flat_contract': False, 'broker_demo': False, 'tick_execution': False,
        'same_bar_fill_time_is_unknown': True, 'independent_horizon_qualification': False}
    return evidence


def worker(spec_path, expected, key):
    from app.schemas import SimpleBacktestRequest
    from app.services.backtester import _load_simple_candles, run_simple_ema_rsi_backtest_on_dataframe
    from scripts.curator_management_witness import capture_management_witness
    from scripts.curator_research_deadline import AbsoluteResearchDeadline
    spec = read_spec(spec_path, expected); declaration = spec['requests'][key]
    folder, products = Path(spec_path).resolve().parent, Path(spec_path).resolve().parent / 'products'
    launch = json.loads((products / (key + '.launch.json')).read_bytes())
    attempt = json.loads((folder / 'execution-attempt.json').read_bytes())
    need(launch['spec_sha256'] == attempt['spec_sha256'] == expected and launch['case_key'] == key
         and launch['request_sha256'] == declaration['request_sha256']
         and launch['parent_pid'] == attempt['parent_pid'] == os.getppid(), 'EXACT_ORIGINAL_PARENT_REQUIRED')
    validate_launch(attempt, launch)
    deadline = AbsoluteResearchDeadline.from_record(launch['deadline'])
    deadline.checkpoint('worker_admission')
    need(sys.stdin.buffer.read() == declaration['request_json'].encode(), 'ORIGINAL_REQUEST_BYTES_REQUIRED')
    for case, item in spec['requests'].items():
        need(sha(products / (case + '.request.json')) == item['request_sha256'], 'ALL_REQUESTS_BEFORE_FIRST_ORIGINAL_REQUIRED')
    write_new(products / (key + '.worker-attempt.json'), {'case_key': key, 'spec_sha256': expected,
        'request_sha256': declaration['request_sha256'], 'max_attempts': 1, 'deadline': deadline.record()})
    payload = SimpleBacktestRequest.model_validate_json(declaration['request_json'])
    deadline.checkpoint('before_compute')
    with capture_management_witness() as observer:
        response = run_simple_ema_rsi_backtest_on_dataframe(payload, _load_simple_candles(payload),
            lightweight=True, include_differential_pair=False)
    deadline.checkpoint('after_compute_before_publication')
    output = response.model_dump_json().encode()
    envelope = {'protocol': 'curator_scalp_full_close_envelope_v1', 'case_key': key,
        'request_sha256': declaration['request_sha256'], 'response_sha256': hashlib.sha256(output).hexdigest(),
        'full_close_witness': observer.snapshot()}
    verify_response(json.loads(output), declaration, spec['windows'][declaration['window']], envelope, spec, envelope['response_sha256'])
    frozen(spec); deadline.checkpoint('final_original_publication')
    write_new(products / (key + '.witness.json'), envelope)
    sys.stdout.buffer.write(output); sys.stdout.buffer.flush()


def comparisons(report):
    pairs = {'method_given_scalp': ('reentry_scalp6', 'breakout_scalp6'),
             'method_given_day': ('reentry_day24', 'breakout_day24'),
             'holding_given_breakout': ('breakout_day24', 'breakout_scalp6'),
             'holding_given_reentry': ('reentry_day24', 'reentry_scalp6')}
    windows = {}
    for window in WINDOWS:
        rows = {arm: report['results'][window + '__' + arm] for arm in ARMS}
        contrast = {}
        for label, (treated, control) in pairs.items():
            left, right = rows[treated], rows[control]
            matched = original.matched_pair(left, right)
            contrast[label] = {'matched_complete': matched,
                'realized_cash_delta': left['evidence']['net_cash'] - right['evidence']['net_cash'] if matched else None,
                'unknown_not_zero': not matched, 'isolated_same_entry_effect': False, 'independent': False}
        interaction = None
        if all(original.matched_pair(row, rows['breakout_scalp6']) for row in rows.values()):
            cash = {arm: row['evidence']['net_cash'] for arm, row in rows.items()}
            interaction = cash['reentry_day24'] - cash['reentry_scalp6'] - cash['breakout_day24'] + cash['breakout_scalp6']
        windows[window] = {'pairs': contrast, 'cash_interaction': interaction, 'independent': False}
    return windows


def validate_launch(attempt, launch):
    global_limits, case = attempt['deadline'], launch['deadline']
    need(attempt['max_attempts'] == 1 and global_limits['budget_seconds'] == 5400 and case['budget_seconds'] == 600,
         'ORIGINAL_FIXED_GLOBAL_AND_CASE_BUDGETS_REQUIRED')
    for axis in ('monotonic', 'unix'):
        start, end = 'started_at_' + axis, 'deadline_' + axis
        need(global_limits[end] == global_limits[start] + 5400
             and global_limits[start] <= case[start] < global_limits[end]
             and case[end] == min(case[start] + 600, global_limits[end]),
             'CASE_DEADLINE_MUST_BE_CAPPED_BY_ORIGINAL_GLOBAL')


def execute(spec_path, expected):
    from scripts.curator_research_deadline import AbsoluteResearchDeadline, run_hidden_serial, OwnedChildNotReaped, ResearchDeadlineError
    spec_path = Path(spec_path).resolve(); spec = read_spec(spec_path, expected); folder = spec_path.parent
    global_deadline = AbsoluteResearchDeadline.start(5400)
    attempt = {'spec_sha256': expected, 'parent_pid': os.getpid(), 'max_attempts': 1, 'deadline': global_deadline.record()}
    write_new(folder / 'execution-attempt.json', attempt)
    products = folder / 'products'; products.mkdir(exist_ok=False)
    original.stage_requests(products, spec)
    report = {'protocol': 'curator_intraday_original_report_v1', 'question_id': QUESTION,
        'before_outcome_spec_sha256': expected, 'source_archive_sha256': spec['source_archive']['sha256'],
        'research_only': True, **{flag: False for flag in FLAGS}, 'results': {}}
    abort = None
    for key, declaration in spec['requests'].items():
        result = {'status': 'not_attempted_dependency', 'request_sha256': declaration['request_sha256']}
        try:
            need(abort is None, 'SERIAL_STUDY_PREVIOUS_UNREAPED_CHILD_ABORT')
            global_deadline.checkpoint('before_next_case'); frozen(spec)
            deadline = AbsoluteResearchDeadline.start(600, parent=global_deadline)
            launch = {'spec_sha256': expected, 'case_key': key, 'request_sha256': declaration['request_sha256'],
                'parent_pid': os.getpid(), 'deadline': deadline.record()}
            write_new(products / (key + '.launch.json'), launch)
            process_facts = run_hidden_serial([sys.executable, str(Path(__file__).resolve()), '--worker', key,
                '--spec', str(spec_path), '--spec-sha256', expected],
                input_path=products / (key + '.request.json'), stdout_path=products / (key + '.response.json'),
                stderr_path=products / (key + '.stderr.txt'), deadline=deadline, cwd=ROOT,
                env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'})
            result.update(status='technical_terminal', deadline_facts=process_facts,
                response_sha256=sha(products / (key + '.response.json')), stderr_sha256=sha(products / (key + '.stderr.txt')))
            witness = products / (key + '.witness.json')
            if witness.is_file():
                result['witness_sha256'] = sha(witness)
            if process_facts['status'] == 'completed' and process_facts['exit_code'] == 0:
                result['evidence'] = verify_response(json.loads((products / (key + '.response.json')).read_bytes()), declaration,
                    spec['windows'][declaration['window']], json.loads(witness.read_bytes()), spec, result['response_sha256'])
                frozen(spec); result['status'] = 'completed'
        except OwnedChildNotReaped as failure:
            abort = failure.facts
            result.update(status='technical_terminal', dependency=str(failure), deadline_facts=failure.facts)
        except ResearchDeadlineError as failure:
            result.update(dependency=str(failure), deadline_facts=failure.facts)
        except Exception as failure:
            result['dependency'] = str(failure)
        write_new(products / (key + '.process.json'), result)
        report['results'][key] = {'case_key': key, 'window': declaration['window'], 'arm': declaration['arm'],
            'baseline_key': declaration['baseline_key'], 'dataset_sha256': declaration['dataset_sha256'],
            'cost_sha256': declaration['cost_sha256'], **result}
        print(json.dumps({'case': key, 'status': result['status']}), flush=True)
    report['window_comparisons'] = comparisons(report)
    frozen(spec, archive_contents=True); report['final_source_check'] = 'unchanged'
    digest = write_new(folder / 'original-panel-summary.json', report)
    print(json.dumps({'status': 'all_eight_terminal', 'completed': sum(row['status'] == 'completed' for row in report['results'].values()),
        'summary_sha256': digest}), flush=True)
    return report


def inspect_originals(path, expected):
    """Reopen original bytes and recompute comparisons; never replay a case."""
    spec = read_spec(path, expected); folder = Path(path).resolve().parent
    summary_path = folder / 'original-panel-summary.json'; identity = sha(summary_path)
    report = json.loads(summary_path.read_bytes()); products = folder / 'products'
    need(report['protocol'] == 'curator_intraday_original_report_v1'
         and report['before_outcome_spec_sha256'] == expected and report['question_id'] == QUESTION
         and report['research_only'] is True and all(report[flag] is False for flag in FLAGS)
         and report['final_source_check'] == 'unchanged' and set(report['results']) == set(spec['requests']),
         'ORIGINAL_COMPLETE_TERMINAL_ROSTER_REQUIRED')
    attempt = json.loads((folder / 'execution-attempt.json').read_bytes())
    need(attempt['spec_sha256'] == expected and attempt['max_attempts'] == 1, 'ORIGINAL_SINGLE_ATTEMPT_REQUIRED')
    artifacts, recomputed = {}, {'results': {}}
    for key, declaration in spec['requests'].items():
        row = report['results'][key]; process = json.loads((products / (key + '.process.json')).read_bytes())
        need(row['status'] in ('completed', 'technical_terminal', 'not_attempted_dependency')
             and all(row[field] == value for field, value in process.items())
             and all(row[field] == declaration[field] for field in ('window', 'arm', 'baseline_key', 'dataset_sha256', 'cost_sha256')),
             'ORIGINAL_PROCESS_SCOPE_REQUIRED')
        files = {}
        for suffix, hash_key in (('request.json', 'request_sha256'), ('response.json', 'response_sha256'),
            ('stderr.txt', 'stderr_sha256'), ('witness.json', 'witness_sha256'), ('process.json', None),
            ('launch.json', None), ('worker-attempt.json', None)):
            file = products / (key + '.' + suffix)
            files[suffix] = sha(file) if file.is_file() else None
            if hash_key is not None and hash_key in process:
                need(files[suffix] == process[hash_key], 'ORIGINAL_STREAM_HASH_REQUIRED:' + key + ':' + suffix)
        if row['status'] == 'completed':
            launch = json.loads((products / (key + '.launch.json')).read_bytes())
            worker_attempt = json.loads((products / (key + '.worker-attempt.json')).read_bytes())
            validate_launch(attempt, launch)
            facts = process['deadline_facts']
            need(launch['spec_sha256'] == worker_attempt['spec_sha256'] == expected
                 and launch['case_key'] == worker_attempt['case_key'] == key
                 and launch['request_sha256'] == worker_attempt['request_sha256'] == declaration['request_sha256']
                 and launch['parent_pid'] == attempt['parent_pid'] and worker_attempt['max_attempts'] == 1
                 and launch['deadline'] == worker_attempt['deadline'] == facts['limits']
                 and facts['status'] == 'completed' and facts['exit_code'] == 0
                 and facts['publication_allowed'] is True and facts['timeout'] is False
                 and facts['owned_child_reaped'] is True, 'COMPLETED_ORIGINAL_BUDGET_AND_WORKER_REQUIRED')
            evidence = verify_response(json.loads((products / (key + '.response.json')).read_bytes()), declaration,
                spec['windows'][declaration['window']], json.loads((products / (key + '.witness.json')).read_bytes()), spec, files['response.json'])
            need(evidence == process['evidence'] == row['evidence'], 'ORIGINAL_PRODUCT_REDERIVATION_REQUIRED')
        artifacts[key] = files; recomputed['results'][key] = row
    need(comparisons(recomputed) == report['window_comparisons'], 'ORIGINAL_COMPARISON_REDERIVATION_REQUIRED')
    frozen(spec, archive_contents=True); need(sha(summary_path) == identity, 'ORIGINAL_SUMMARY_CHANGED_DURING_INSPECTION')
    from app.services.research_program_tasks import canonical_hash
    return {'status': 'verified_read_only', 'spec_sha256': expected, 'summary_sha256': identity,
        'source_archive_sha256': spec['source_archive']['sha256'], 'artifact_roster_sha256': canonical_hash(artifacts),
        'terminal_cases': 8, 'completed_originals_reverified': sum(row['status'] == 'completed' for row in report['results'].values()),
        'research_only': True, 'independent_validation': False, 'own_agent_outcome': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--folder'); parser.add_argument('--spec'); parser.add_argument('--spec-sha256')
    parser.add_argument('--draft-out')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--seal'); mode.add_argument('--execute'); mode.add_argument('--worker'); mode.add_argument('--validate-draft')
    mode.add_argument('--inspect')
    mode.add_argument('--prepare')
    args = parser.parse_args()
    if args.prepare:
        need(bool(args.draft_out), 'EXCLUSIVE_DRAFT_OUTPUT_REQUIRED'); prepare(args.prepare, args.draft_out)
    elif args.seal:
        need(bool(args.folder), 'NEW_FOLDER_REQUIRED'); seal(args.seal, args.folder)
    elif args.validate_draft:
        validate(json.loads(Path(args.validate_draft).read_bytes())); print('{"status":"eight_validated_no_outcomes"}')
    elif args.worker:
        need(bool(args.spec and args.spec_sha256), 'EXACT_SPEC_REQUIRED'); worker(args.spec, args.spec_sha256, args.worker)
    elif args.inspect:
        need(bool(args.spec_sha256), 'EXACT_SPEC_SHA_REQUIRED'); print(json.dumps(inspect_originals(args.inspect, args.spec_sha256)))
    else:
        need(bool(args.spec_sha256), 'EXACT_SPEC_SHA_REQUIRED'); execute(args.execute, args.spec_sha256)
