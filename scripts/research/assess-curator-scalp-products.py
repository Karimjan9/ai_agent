"""Post-terminal reporting appendix. No replay, original rewrite or promotion."""
import argparse
import copy
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import sys
import zipfile

sys.dont_write_bytecode = True
for _name in ('OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS', 'BLIS_NUM_THREADS'):
    os.environ[_name] = '1'
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'ai-service-python'))
RUNNER = 'ai-service-python/scripts/curator_scalp_panel.py'
CORRECTIONS = ('ai-service-python/scripts/curator_scalp_metrics.py', 'ai-service-python/tests/test_curator_scalp_metrics.py')
TESTS = 'ai-service-python/tests/test_curator_scalp_reporting.py'
MANIFEST_SHA = 'b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92'
FLAGS = ('own_agent_outcome', 'independent_validation', 'credit', 'mastery', 'economic_authority', 'paper_authority', 'live_authority')
TERMINAL = {'completed', 'technical_terminal', 'not_attempted_global_cap', 'not_attempted_frozen_or_launch_dependency'}
SUPPLEMENTAL = {'external-expired-worker-enforcement.json': 'curator_original_external_deadline_enforcement_v1',
    'post-terminal-publication-priority.json': 'curator_post_terminal_publication_priority_v1'}


def need(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def sha(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest()


def encoded(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()


def write_new(path, value):
    raw = value if isinstance(value, bytes) else encoded(value)
    with Path(path).open('xb') as stream:
        need(stream.write(raw) == len(raw), 'COMPLETE_EXCLUSIVE_APPENDIX_WRITE_REQUIRED')
    return hashlib.sha256(raw).hexdigest()


def expected_entries(spec):
    return {**spec['source_snapshot']['files'], 'external/draft.json': spec['draft']['sha256'],
        **{'external/upstream-' + key + '.json': ref['sha256'] for key, ref in spec['upstream'].items()},
        **{'external/' + key + '.csv': window['sha256'] for key, window in spec['windows'].items()}}


def verify_archive(spec):
    archive = spec['source_archive']; entries = expected_entries(spec)
    need(archive['entries'] == entries and sha(archive['path']) == archive['sha256'], 'EXACT_ORIGINAL_SOURCE_ARCHIVE_REQUIRED')
    with zipfile.ZipFile(archive['path']) as zipped:
        names = zipped.namelist()
        need(len(names) == len(set(names)) and set(names) == set(entries), 'EXACT_ORIGINAL_ARCHIVE_ROSTER_REQUIRED')
        for name, identity in entries.items():
            need(hashlib.sha256(zipped.read(name)).hexdigest() == identity, 'ORIGINAL_ARCHIVED_BYTES_CHANGED:' + name)


def correction_roster(original, current):
    need(set(original) == set(current), 'ORIGINAL_PRODUCER_SOURCE_ROSTER_CHANGED')
    changed = {name for name in original if original[name] != current[name]}
    need(changed == set(CORRECTIONS), 'EXACT_TWO_REPORTING_CORRECTIONS_REQUIRED')
    return {name: {'original_sha256': original[name], 'corrected_sha256': current[name]} for name in CORRECTIONS}


def load_original_owner(spec):
    need(sha(ROOT / RUNNER) == spec['source_snapshot']['files'][RUNNER], 'ORIGINAL_RUNNER_MUST_REMAIN_UNCHANGED')
    loader = importlib.util.spec_from_file_location('_unchanged_scalp_original_owner', ROOT / RUNNER)
    owner = importlib.util.module_from_spec(loader); loader.loader.exec_module(owner)
    return owner


def verify_source_boundary(spec, owner):
    corrections = correction_roster(spec['source_snapshot']['files'], owner.source_roster())
    from app.services.research_program_tasks import canonical_hash
    need(canonical_hash(spec['source_snapshot']['files']) == spec['source_snapshot']['snapshot_hash'], 'ORIGINAL_SNAPSHOT_HASH_REQUIRED')
    need(owner.runtime_fingerprint() == spec['runtime'] and sha(spec['draft']['path']) == spec['draft']['sha256'],
        'ORIGINAL_RUNTIME_AND_DRAFT_REQUIRED')
    # These are pure request/input checks. Never call old frozen/read_spec/
    # inspect_originals after the two admitted reporting source corrections.
    owner.validate_requests(spec)
    need(spec['upstream']['manifest']['sha256'] == MANIFEST_SHA, 'EXACT_UTC_RAW_MANIFEST_REQUIRED')
    return corrections


def validate_terminal(summary, spec, expected):
    need(summary['protocol'] == 'curator_scalp_original_report_v1' and summary['before_outcome_spec_sha256'] == expected
        and summary['question_id'] == spec['question_id'] and summary['final_source_check'] == 'unchanged'
        and set(summary['results']) == set(spec['requests']) and len(summary['results']) == 8
        and summary['research_only'] is True and all(summary[flag] is False for flag in FLAGS)
        and all(row['status'] in TERMINAL for row in summary['results'].values()), 'ALL_EIGHT_ORIGINALS_MUST_ALREADY_BE_TERMINAL')


def exact_reporting_exception(process, stderr, response_present, witness_present):
    """Admit only the single original CSV UTC reporting failure, never a timeout."""
    if not (process_budget_eligible(process) and process.get('status') == 'technical_terminal' and process.get('exit_code') == 1
            and process.get('timeout') is False and process.get('response_preserved_before_verification') is True
            and all(isinstance(process.get(field), str) and re.fullmatch(r'[0-9a-f]{64}', process[field])
                for field in ('response_sha256', 'stderr_sha256', 'witness_sha256'))
            and response_present and witness_present):
        return False
    try:
        text = stderr.decode('utf-8', errors='strict').strip()
    except UnicodeError:
        return False
    if text.count('Traceback (most recent call last):') != 1 or text.splitlines()[-1] != 'ValueError: SCALP_UTC_TIMESTAMP_REQUIRED':
        return False
    frames = re.findall(r'^\s*File "([^"]+)", line \d+, in (.+)$', text, flags=re.MULTILINE)
    allowed = {str((ROOT / RUNNER).resolve()): {'<module>', 'worker', 'verify_response'},
        str((ROOT / CORRECTIONS[0]).resolve()): {'summarize_scalp_metrics', '_original_clock', '<listcomp>', '_stamp', '_need'}}
    seen = set()
    for path, function in frames:
        path = str(Path(path).resolve()); function = function.strip()
        if path not in allowed or function not in allowed[path]:
            return False
        seen.add((path, function))
    required = {(str((ROOT / RUNNER).resolve()), 'worker'), (str((ROOT / RUNNER).resolve()), 'verify_response'),
        (str((ROOT / CORRECTIONS[0]).resolve()), '_original_clock'), (str((ROOT / CORRECTIONS[0]).resolve()), '_stamp'),
        (str((ROOT / CORRECTIONS[0]).resolve()), '_need')}
    return required <= seen and frames[-1][1].strip() == '_need'


def finite_number(value):
    return type(value) in (int, float) and math.isfinite(value)


def process_budget_eligible(process):
    wall, limit = process.get('wall_seconds'), process.get('timeout_limit_seconds')
    return finite_number(wall) and finite_number(limit) and 0 <= wall <= limit <= 600 and limit > 0


def original_budget_eligibility(process, attempt, launch, worker):
    """Original timing facts only; never substitute a later or larger budget."""
    result = {'eligible': False, 'original_wall_seconds': process.get('wall_seconds'),
        'original_timeout_limit_seconds': process.get('timeout_limit_seconds'), 'declared_arm_cap_seconds': 600,
        'declared_global_cap_seconds': 5400}
    if not process_budget_eligible(process):
        return {**result, 'reason': 'original_wall_or_timeout_limit_invalid_or_exceeded'}
    if launch is None or worker is None:
        return {**result, 'reason': 'original_launch_or_worker_start_unavailable'}
    values = (attempt.get('started_at_monotonic'), attempt.get('global_deadline_monotonic'),
        launch.get('started_at_monotonic'), launch.get('deadline_monotonic'), worker.get('started_at_monotonic'))
    if not all(finite_number(value) and value >= 0 for value in values):
        return {**result, 'reason': 'original_monotonic_budget_facts_nonfinite_or_negative'}
    global_start, global_end, launch_start, arm_end, worker_start = values
    eligible = (global_end == global_start + 5400 and global_start <= launch_start <= worker_start < global_end
        and arm_end == min(global_end, launch_start + 600) and worker_start < arm_end
        and process['timeout_limit_seconds'] == arm_end - launch_start)
    return {**result, 'eligible': eligible, 'reason': 'within_original_budgets' if eligible else 'original_start_or_deadline_not_eligible',
        'original_worker_started_at_monotonic': worker_start, 'original_global_deadline_monotonic': global_end}


def supplemental_receipts(folder, requested=()):
    folder = Path(folder).resolve(); paths = {folder / name for name in SUPPLEMENTAL if (folder / name).is_file()}
    for path in requested:
        path = Path(path).resolve()
        need(path.parent == folder and path.name in SUPPLEMENTAL, 'SUPPLEMENTAL_RECEIPT_SCOPE_REQUIRED')
        paths.add(path)
    result = {}
    for path in sorted(paths):
        value = json.loads(path.read_bytes())
        need(value.get('protocol') == SUPPLEMENTAL[path.name], 'KNOWN_SUPPLEMENTAL_RECEIPT_PROTOCOL_REQUIRED')
        result[path.name] = {'path': str(path), 'sha256': sha(path), 'protocol': value['protocol'],
            'post_terminal_supplement_only': True, 'part_of_original_artifact_roster': False, 'authority_granted': False}
    return result


def artifact(path, expected=None):
    need(expected is None or path.is_file() and sha(path) == expected, 'ORIGINAL_PRODUCT_HASH_MISMATCH:' + path.name)
    return sha(path) if path.is_file() else None


def reopen_products(spec, summary, folder, expected, owner):
    from app.services.research_program_tasks import canonical_hash
    products = folder / 'products'; artifacts, rows = {}, {}
    attempt_path = folder / 'execution-attempt.json'; attempt = json.loads(attempt_path.read_bytes())
    need(attempt['spec_sha256'] == expected and attempt['max_attempts'] == 1
        and attempt['global_deadline_monotonic'] == attempt['started_at_monotonic'] + 5400, 'ORIGINAL_PARENT_DEADLINE_REQUIRED')
    for key, declaration in spec['requests'].items():
        process_path = products / (key + '.process.json'); process = json.loads(process_path.read_bytes())
        original = summary['results'][key]; owner.verify_process_link(process, original, declaration)
        need(original['case_key'] == key and all(original[name] == declaration[name]
            for name in ('window', 'arm', 'baseline_key', 'dataset_sha256', 'cost_sha256')), 'ORIGINAL_CASE_SCOPE_CHANGED')
        hashes = {'request_sha256': artifact(products / (key + '.request.json'), declaration['request_sha256']),
            'process_sha256': sha(process_path), 'response_sha256': artifact(products / (key + '.response.json'), process.get('response_sha256')),
            'stderr_sha256': artifact(products / (key + '.stderr.txt'), process.get('stderr_sha256')),
            'witness_sha256': artifact(products / (key + '.witness.json'), process.get('witness_sha256')),
            'launch_sha256': artifact(products / (key + '.launch.json')),
            'worker_attempt_sha256': artifact(products / (key + '.worker-attempt.json'))}
        launch, worker = None, None
        if hashes['launch_sha256'] is not None:
            launch = json.loads((products / (key + '.launch.json')).read_bytes())
            need(launch['spec_sha256'] == expected and launch['case_key'] == key
                and launch['request_sha256'] == declaration['request_sha256'] and launch['parent_pid'] == attempt['parent_pid'],
                'ORIGINAL_LAUNCH_DEADLINE_OR_SCOPE_CHANGED')
        if hashes['worker_attempt_sha256'] is not None:
            worker = json.loads((products / (key + '.worker-attempt.json')).read_bytes())
            need(hashes['launch_sha256'] is not None and worker['spec_sha256'] == expected and worker['case_key'] == key
                and worker['request_sha256'] == declaration['request_sha256'] and worker['max_attempts'] == 1,
                'ORIGINAL_WORKER_ATTEMPT_CHANGED')
        rows[key] = {'case_key': key, 'window': declaration['window'], 'arm': declaration['arm'],
            'dataset_sha256': declaration['dataset_sha256'], 'cost_sha256': declaration['cost_sha256'],
            'source_process_status': process['status'], 'appendix_status': 'unassessable_unknown',
            'original_process': {name: value for name, value in process.items() if name != 'evidence'},
            'original_budget_eligibility': original_budget_eligibility(process, attempt, launch, worker),
            'artifacts': hashes, 'evidence': None, 'economic_zero_imputed': False}
        artifacts[key] = hashes
    original_comparisons = {'results': copy.deepcopy(summary['results'])}; owner.add_comparisons(original_comparisons)
    need(original_comparisons['window_comparisons'] == summary['window_comparisons'], 'ORIGINAL_TECHNICAL_COMPARISONS_CHANGED')
    return rows, artifacts, canonical_hash(artifacts), sha(attempt_path)


def verify_prior_inspection(inspection, spec, summary_sha, artifact_sha, expected):
    need(inspection['status'] == 'verified_read_only' and inspection['spec_sha256'] == expected
        and inspection['summary_sha256'] == summary_sha and inspection['terminal_cases'] == 8
        and inspection['source_and_archive_reverified'] is True
        and inspection['source_archive_sha256'] == spec['source_archive']['sha256']
        and inspection['artifact_roster_sha256'] == artifact_sha and inspection['independent_validation'] is False
        and inspection['own_agent_outcome'] is False, 'EXACT_PRECORRECTION_ORIGINAL_INSPECTION_REQUIRED')


def verify_projection(body, declaration, window, envelope, response_sha, spec, owner):
    from app.schemas import SimpleTrade
    from app.services.backtester import _trade_ledger_hash
    from app.services.research_program_tasks import canonical_hash
    from scripts.curator_management_witness import verify_management_witness
    from scripts.curator_scalp_metrics import summarize_scalp_metrics
    key = declaration['window'] + '__' + declaration['arm']; request = declaration['request']
    need(envelope['protocol'] == 'curator_scalp_full_close_envelope_v1' and envelope['case_key'] == key
        and envelope['request_sha256'] == declaration['request_sha256'] and envelope['response_sha256'] == response_sha,
        'ORIGINAL_RAW_FULL_CLOSE_WITNESS_LINK_REQUIRED')
    owner.verify_clock(body['data_quality']['replay_executed_clock'], window, declaration['cost_sha256'])
    need(body['parameters'] == request['parameters'] and body['execution_assumptions'] == request['execution']
        and body['initial_balance'] == request['initial_balance']
        and body['execution_contract']['execution_hash'] == declaration['cost_sha256'], 'ORIGINAL_ACCOUNT_EXECUTION_DRIFT')
    trades = [SimpleTrade.model_validate(trade) for trade in body['trade_ledger']]
    trace, quality = body['decision_trace'], body['data_quality']['decision_trace']
    need(len(trades) == body['total_trades'] and _trade_ledger_hash(trades) == body['trade_ledger_hash']
        and quality['complete'] is True and quality['event_count'] == len(trace)
        and quality['evaluated_candle_count'] == window['evaluated_rows']
        and sum(body['event_ledger_categories'].values()) == body['event_ledger_count'], 'ORIGINAL_COMPLETE_LEDGER_TRACE_REQUIRED')
    need(not any(event.get('event_type') == 'instrument_method_decision' for event in trace)
        and not body['data_quality'].get('instrument_method_learning_receipt'), 'NO_AGENT_METHOD_SELECTION_ALLOWED')
    witness = envelope['full_close_witness']; cash = verify_management_witness(body, witness)
    provenance = {'manifest_path': spec['upstream']['manifest']['path'], 'manifest_sha256': MANIFEST_SHA,
        'window_key': declaration['window']}
    scalp = summarize_scalp_metrics(request, body, witness, csv_path=window['path'], max_holding_minutes=30.0, csv_provenance=provenance)
    clock = body['data_quality']['replay_executed_clock']
    return {'net_cash': cash['exact_net_cash'], 'mature': cash['mature_trade_count'], 'open_position_count': cash['open_position_count'],
        'closed_cash_drawdown_percent': cash['closed_cash_drawdown_percent'], 'cash_metrics': cash, 'scalp_metrics': scalp,
        'trade_ledger_hash': body['trade_ledger_hash'], 'trace_hash': canonical_hash(trace), 'csv_provenance': provenance,
        'index_set_hash': clock['index_set_hash'], 'schedule_hash': clock['schedule_hash'],
        'actual_clock': {key: value for key, value in clock.items() if key not in ('receipt_hash', 'receipt_json')},
        'source_process_status_unchanged': True, 'successful_original_replay_claim': False, 'total_marked_utility_claim': False}


def compare_projections(rows, owner):
    from scripts.curator_scalp_metrics import compare_scalp_metrics
    pairs = {'time_given_proximity': ('proximity_6bars', 'proximity_unbounded'),
        'time_given_pretrend': ('pretrend_6bars', 'pretrend_unbounded'),
        'topology_given_unbounded': ('pretrend_unbounded', 'proximity_unbounded'),
        'topology_given_6bars': ('pretrend_6bars', 'proximity_6bars'), 'joint_vs_baseline': ('pretrend_6bars', 'proximity_unbounded')}
    result = {}
    for window in owner.WINDOWS:
        selected = {arm: rows[window + '__' + arm] for arm in owner.ARMS}; comparisons = {}
        for label, (treated, control) in pairs.items():
            left, right = selected[treated], selected[control]
            pair = {'projection_pair_status': 'unassessable_unknown', 'realized_cash_delta': None,
                'source_process_statuses': [left['source_process_status'], right['source_process_status']],
                'independent_validation': False, 'successful_original_replay_claim': False, 'dominance_claim': False}
            if left['appendix_status'] == right['appendix_status'] == 'projection_verified':
                need(left['dataset_sha256'] == right['dataset_sha256'] and left['cost_sha256'] == right['cost_sha256']
                    and all(left['evidence'][key] == right['evidence'][key] for key in ('index_set_hash', 'schedule_hash', 'actual_clock')),
                    'PROJECTION_PAIR_ACCOUNT_DATA_CLOCK_MISMATCH')
                pair.update(projection_pair_status='matched_reporting_projections',
                    realized_cash_delta=left['evidence']['net_cash'] - right['evidence']['net_cash'],
                    account_contrast=compare_scalp_metrics(right['evidence']['scalp_metrics'], left['evidence']['scalp_metrics']),
                    eof_open_exposure_present=bool(left['evidence']['open_position_count'] or right['evidence']['open_position_count']))
            comparisons[label] = pair
        interaction = None
        if all(row['appendix_status'] == 'projection_verified' for row in selected.values()):
            cash = {arm: row['evidence']['net_cash'] for arm, row in selected.items()}
            interaction = cash['pretrend_6bars'] - cash['pretrend_unbounded'] - cash['proximity_6bars'] + cash['proximity_unbounded']
        result[window] = {'pairs': comparisons, 'reporting_projection_cash_interaction': interaction,
            'independent_validation': False, 'successful_original_replay_claim': False, 'total_marked_utility_claim': False}
    return result


def assess(spec_path, expected, inspection_path, publish=False, supplemental_paths=()):
    spec_path, inspection_path = Path(spec_path).resolve(), Path(inspection_path).resolve(); folder = spec_path.parent
    need(sha(spec_path) == expected, 'EXACT_ORIGINAL_SPEC_SHA_REQUIRED')
    spec = json.loads(spec_path.read_bytes()); need(spec['protocol'] == 'curator_scalp_archive_panel_v1', 'EXACT_ORIGINAL_SCALP_PROTOCOL_REQUIRED')
    summary_path = folder / 'original-panel-summary.json'; summary_sha = sha(summary_path)
    summary = json.loads(summary_path.read_bytes()); validate_terminal(summary, spec, expected)
    verify_archive(spec); owner = load_original_owner(spec); corrections = verify_source_boundary(spec, owner)
    from app.services.research_program_tasks import canonical_hash
    own_sources = {'appendix_owner_sha256': sha(__file__), 'appendix_tests_sha256': sha(ROOT / TESTS)}
    rows, artifacts, artifact_sha, attempt_sha = reopen_products(spec, summary, folder, expected, owner)
    inspection_sha = sha(inspection_path); inspection = json.loads(inspection_path.read_bytes())
    verify_prior_inspection(inspection, spec, summary_sha, artifact_sha, expected)
    need(inspection['comparisons_sha256'] == canonical_hash(summary['window_comparisons']), 'PRECORRECTION_COMPARISONS_HASH_REQUIRED')
    supplemental = supplemental_receipts(folder, supplemental_paths)
    products = folder / 'products'
    for key, row in rows.items():
        response_path, witness_path = products / (key + '.response.json'), products / (key + '.witness.json')
        stderr_path = products / (key + '.stderr.txt')
        eligible = exact_reporting_exception(row['original_process'], stderr_path.read_bytes() if stderr_path.is_file() else b'',
            response_path.is_file() and response_path.stat().st_size > 0, witness_path.is_file() and witness_path.stat().st_size > 0)
        eligible = eligible and row['artifacts'].get('launch_sha256') is not None and row['artifacts'].get('worker_attempt_sha256') is not None
        eligible = eligible and row['original_budget_eligibility']['eligible']
        row['specified_reporting_failure_boundary'] = eligible
        if eligible:
            try:
                row['evidence'] = verify_projection(json.loads(response_path.read_bytes()), spec['requests'][key],
                    spec['windows'][row['window']], json.loads(witness_path.read_bytes()), row['artifacts']['response_sha256'], spec, owner)
                row['appendix_status'] = 'projection_verified'
            except Exception as failure:
                row['projection_refusal'] = str(failure)
        else:
            row['projection_refusal'] = ('original_budget_not_eligible' if not row['original_budget_eligibility']['eligible']
                else 'outside_exact_original_reporting_exception_boundary')
    comparisons = compare_projections(rows, owner)
    verify_archive(spec); need(verify_source_boundary(spec, owner) == corrections and sha(summary_path) == summary_sha
        and sha(inspection_path) == inspection_sha and sha(__file__) == own_sources['appendix_owner_sha256']
        and sha(ROOT / TESTS) == own_sources['appendix_tests_sha256']
        and all(sha(item['path']) == item['sha256'] for item in supplemental.values()), 'REPORTING_CUSTODY_CHANGED_DURING_ASSESSMENT')
    body = {'protocol': 'curator_scalp_reporting_appendix_v1', 'question_id': spec['question_id'],
        'spec_sha256': expected, 'original_summary_sha256': summary_sha, 'original_source_archive_sha256': spec['source_archive']['sha256'],
        'original_source_snapshot_hash': spec['source_snapshot']['snapshot_hash'], 'original_attempt_sha256': attempt_sha,
        'original_artifact_roster_sha256': artifact_sha, 'precorrection_inspection_sha256': inspection_sha,
        'supplemental_post_terminal_receipts': supplemental, 'wall_clock_jump_root_cause_claim': None,
        'reporting_corrections': corrections, **own_sources, 'originals': rows, 'window_comparisons': comparisons,
        'terminal_original_cases': 8, 'projection_verified_cases': sum(row['appendix_status'] == 'projection_verified' for row in rows.values()),
        'research_only': True, **{flag: False for flag in FLAGS}, 'original_source_process_labels_preserved': True,
        'new_replay_or_attempt_performed': False, 'original_results_rewritten': False,
        'successful_original_replay_claim': False, 'dominance_claim': False, 'total_marked_utility_claim': False,
        'six_bars_are_not_a_wall_clock_guarantee': True}
    raw = encoded(body); digest = hashlib.sha256(raw).hexdigest()
    verification = {'protocol': 'curator_scalp_reporting_appendix_verification_v1', 'spec_sha256': expected,
        'original_summary_sha256': summary_sha, 'appendix_sha256': digest, 'original_artifact_roster_sha256': artifact_sha,
        'supplemental_post_terminal_receipts': supplemental, 'declared_budgets_unchanged': True,
        'original_archive_reverified': True, 'exact_two_reporting_corrections_only': True, **own_sources,
        'terminal_original_cases': 8, 'projection_verified_cases': body['projection_verified_cases'],
        'original_source_process_labels_preserved': True, 'new_replay_or_attempt_performed': False,
        'own_agent_outcome': False, 'independent_validation': False, 'economic_authority': False}
    if publish:
        appendix = folder / 'post-terminal-reporting-appendix-v1.json'; receipt = folder / 'post-terminal-reporting-verification-v1.json'
        need(not appendix.exists() and not receipt.exists(), 'EXCLUSIVE_NEW_REPORTING_APPENDIX_TARGETS_REQUIRED')
        write_new(appendix, raw); write_new(receipt, verification)
    print(json.dumps({'status': 'appendix_published' if publish else 'verified_read_only',
        'terminal_original_cases': 8, 'projection_verified_cases': body['projection_verified_cases'], 'appendix_sha256': digest}))
    return body, verification


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--spec', required=True); parser.add_argument('--spec-sha256', required=True)
    parser.add_argument('--original-inspection', required=True); parser.add_argument('--publish', action='store_true')
    parser.add_argument('--supplemental-receipt', action='append', default=[]); args = parser.parse_args()
    assess(args.spec, args.spec_sha256, args.original_inspection, args.publish, args.supplemental_receipt)
