"""Sealed offline instrument questions using the existing ordinary replay owner.

Code-owned study adapters define design and evidence. No arbitrary plugin,
second scheduler, broker, credit or automatic agent teaching is available.
"""
import argparse
import hashlib
import importlib
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
from scripts import curator_intraday_panel as previous
from scripts.curator_research_deadline import AbsoluteResearchDeadline, run_hidden_serial, ResearchDeadlineError, OwnedChildNotReaped
need, sha, write_new = previous.need, previous.sha, previous.write_new
FLAGS = previous.FLAGS
ADAPTERS = {'bollinger_context_v1': 'scripts.curator_bollinger_study',
    'bollinger_recipe_v1': 'scripts.curator_bollinger_recipe',
    'intraday_methods_v1': 'scripts.curator_complete_intraday_methods'}
PREPARERS = {'bollinger_context_v1': 'prepare-curator-bollinger-study.php',
    'bollinger_recipe_v1': 'prepare-curator-bollinger-recipe-study.php',
    'intraday_methods_v1': 'prepare-curator-intraday-methods.php'}
EXTRA = ('backend-laravel/scripts/prepare-curator-bollinger-study.php',
    'backend-laravel/tests/Unit/BollingerContextGateParityTest.php',
    'ai-service-python/tests/test_bollinger_context_gate.py',
    'ai-service-python/tests/test_curator_instrument_windows.py',
    'ai-service-python/tests/test_curator_instrument_study.py',
    'docs/research/instruments/range_reentry/events/2026-10-16-context-question.json',
    'backend-laravel/scripts/prepare-curator-bollinger-recipe-study.php',
    'backend-laravel/app/Services/InstrumentMethodLibraryService.php',
    'backend-laravel/app/Services/InstrumentMethodResearchBindingService.php',
    'backend-laravel/app/Services/AgentMethodLearningService.php',
    'backend-laravel/app/Services/TradingInstrumentOperatingSystemService.php',
    'backend-laravel/app/Services/TypedInstrumentFoundryService.php',
    'backend-laravel/app/Services/InstrumentMethodSourceService.php',
    'backend-laravel/app/Services/ContextContractV2Service.php',
    'backend-laravel/app/Services/MarketSessionCalendarService.php',
    'backend-laravel/database/migrations/2026_09_07_150000_create_neurotrader_lab_expansion_tables.php',
    'backend-laravel/database/migrations/2026_10_13_120000_create_instrument_method_and_agent_learning.php',
    'backend-laravel/database/migrations/2026_10_13_121000_scope_research_method_program_identity.php',
    'backend-laravel/tests/Unit/BollingerBandVarianceParityTest.php',
    'backend-laravel/tests/Unit/BollingerRecipeOfflinePreparationTest.php',
    'ai-service-python/tests/test_bollinger_complete_method.py',
    'ai-service-python/tests/test_curator_bollinger_month_window.py',
    'ai-service-python/tests/test_curator_bollinger_month_metrics.py',
    'ai-service-python/tests/test_curator_bollinger_recipe.py',
    'ai-service-python/tests/test_curator_bollinger_recipe_evidence.py',
    'docs/research/instruments/range_reentry/events/2026-10-16-complete-recipe-question.json',
    'docs/research/instruments/range_reentry/events/2026-10-16-article-review.json',
    'backend-laravel/scripts/prepare-curator-intraday-methods.php',
    'backend-laravel/tests/Unit/CuratorIntradayMethodsPreparationTest.php',
    'ai-service-python/tests/test_curator_intraday_month_windows.py',
    'ai-service-python/tests/test_complete_intraday_methods.py',
    'ai-service-python/tests/test_source_intraday_geometry.py',
    'backend-laravel/tests/Unit/SourceIntradayGeometrySchemaTest.php',
    'ai-service-python/tests/test_curator_management_witness.py',
    'docs/research/methods/intraday-completion/sources.json',
    'docs/research/methods/intraday-completion/question.json')


def owner(spec):
    need(spec.get('study_owner') in ADAPTERS, 'CODE_OWNED_INSTRUMENT_STUDY_REQUIRED')
    return importlib.import_module(ADAPTERS[spec['study_owner']])


def sources():
    return dict(sorted({**previous.source_roster(), **{name: sha(ROOT / name) for name in EXTRA}}.items()))


def entries(spec):
    return {**spec['source_snapshot']['files'], 'external/draft.json': spec['draft']['sha256'],
        **{'external/' + name + '.json': ref['sha256'] for name, ref in spec['upstream'].items()},
        **{'external/' + name + '.csv': window['sha256'] for name, window in spec['windows'].items()}}


def frozen(spec, full=False):
    from app.services.research_program_tasks import canonical_hash
    need(sources() == spec['source_snapshot']['files']
         and canonical_hash(sources()) == spec['source_snapshot']['snapshot_hash'], 'INSTRUMENT_FROZEN_SOURCE_CHANGED')
    need(previous.original.runtime_fingerprint() == spec['runtime']
         and sha(spec['draft']['path']) == spec['draft']['sha256'], 'INSTRUMENT_RUNTIME_OR_DRAFT_CHANGED')
    owner(spec).validate(spec)
    expected = entries(spec)
    need(expected == spec['source_archive']['entries'] and sha(spec['source_archive']['path']) == spec['source_archive']['sha256'],
         'INSTRUMENT_SOURCE_ARCHIVE_CHANGED')
    if full:
        with zipfile.ZipFile(spec['source_archive']['path']) as archive:
            need(len(archive.namelist()) == len(set(archive.namelist())) and set(archive.namelist()) == set(expected), 'EXACT_INSTRUMENT_ARCHIVE_REQUIRED')
            for name, identity in expected.items():
                need(hashlib.sha256(archive.read(name)).hexdigest() == identity, 'INSTRUMENT_ARCHIVE_ENTRY_CHANGED:' + name)


def read_spec(path, identity):
    need(sha(path) == identity, 'ORIGINAL_INSTRUMENT_SPEC_REQUIRED')
    spec = json.loads(Path(path).read_bytes())
    need(spec['protocol'] == 'curator_instrument_original_panel_v1', 'INSTRUMENT_SPEC_PROTOCOL_REQUIRED')
    frozen(spec, full=True)
    return spec


def prepare(php, manifest_path, manifest_sha, output, study_owner='bollinger_context_v1'):
    path = Path(output).resolve()
    need(path.is_relative_to(ROOT / '.runtime') and path.parent.is_dir(), 'ISOLATED_DRAFT_OUTPUT_REQUIRED')
    need(study_owner in PREPARERS, 'CODE_OWNED_INSTRUMENT_PREPARER_REQUIRED')
    result = subprocess.run([str(php), str(ROOT / 'backend-laravel/scripts' / PREPARERS[study_owner]),
        '--windows', str(Path(manifest_path).resolve()), '--windows-sha256', manifest_sha],
        cwd=ROOT, capture_output=True, timeout=60, check=True, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    draft = json.loads(result.stdout)
    need(draft.get('study_owner') == study_owner, 'EXACT_PREPARER_OWNER_REQUIRED')
    owner(draft).validate(draft)
    digest = write_new(path, result.stdout)
    print(json.dumps({'status': 'actual_php_prepared_no_outcomes', 'draft_path': str(path), 'draft_sha256': digest}))


def seal(draft_path, folder):
    from app.services.research_program_tasks import canonical_hash
    draft_path, folder = Path(draft_path).resolve(), Path(folder).resolve()
    need(folder.is_relative_to(ROOT / '.runtime') and folder != ROOT / '.runtime', 'ISOLATED_ORIGINAL_OUTPUT_REQUIRED')
    spec = json.loads(draft_path.read_bytes()); owner(spec).validate(spec)
    need(spec['protocol'] == 'unfrozen_curator_instrument_panel_v1', 'UNFROZEN_INSTRUMENT_DRAFT_REQUIRED')
    roster = sources(); spec.update(protocol='curator_instrument_original_panel_v1',
        runtime=previous.original.runtime_fingerprint(), draft={'path': str(draft_path), 'sha256': sha(draft_path)},
        source_snapshot={'files': roster, 'snapshot_hash': canonical_hash(roster)})
    folder.mkdir(parents=True, exist_ok=False); archive_path = folder / 'before-outcome-source-bytes.zip'
    paths = {**{name: ROOT / name for name in roster}, 'external/draft.json': draft_path,
        **{'external/' + name + '.json': Path(ref['path']) for name, ref in spec['upstream'].items()},
        **{'external/' + name + '.csv': Path(row['path']) for name, row in spec['windows'].items()}}
    with zipfile.ZipFile(archive_path, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, path in paths.items():
            archive.write(path, name)
    spec['source_archive'] = {'path': str(archive_path), 'sha256': sha(archive_path), 'entries': entries(spec)}
    frozen(spec, full=True); identity = write_new(folder / 'before-outcome-spec.json', spec)
    print(json.dumps({'status': 'sealed_no_outcomes', 'spec_sha256': identity, 'spec_path': str(folder / 'before-outcome-spec.json')}))


def validate_launch(attempt, launch, spec):
    need(attempt['max_attempts'] == 1 and attempt['spec_sha256'] == launch['spec_sha256'], 'ORIGINAL_SINGLE_ATTEMPT_REQUIRED')
    outer, inner = attempt['deadline'], launch['deadline']
    total, cap = spec['global_timeout_seconds'], spec['per_original_timeout_seconds']
    need(outer['budget_seconds'] == total and inner['budget_seconds'] == cap, 'ORIGINAL_DECLARED_BUDGETS_REQUIRED')
    for axis in ('monotonic', 'unix'):
        start, end = 'started_at_' + axis, 'deadline_' + axis
        need(outer[end] == outer[start] + total and outer[start] <= inner[start] < outer[end]
             and inner[end] == min(inner[start] + cap, outer[end]), 'NO_RENEWED_OR_OVERSHOT_INSTRUMENT_DEADLINE')


def case_artifacts(products, key):
    return {suffix: sha(products / (key + '.' + suffix)) if (products / (key + '.' + suffix)).is_file() else None
        for suffix in ('request.json', 'response.json', 'stderr.txt', 'witness.json', 'launch.json', 'worker-attempt.json')}


def verify_case_artifacts(products, key, case, process):
    actual = case_artifacts(products, key)
    need(process['request_sha256'] == actual['request.json'] == case['request_sha256'],
         'ORIGINAL_SEALED_REQUEST_STREAM_REQUIRED:' + key)
    need(process['artifact_sha256'] == actual, 'ORIGINAL_CASE_ARTIFACT_ROSTER_CHANGED:' + key)
    for suffix, field in (('response.json', 'response_sha256'), ('stderr.txt', 'stderr_sha256'), ('witness.json', 'witness_sha256')):
        if actual[suffix] is not None or field in process:
            need(field in process and process[field] == actual[suffix], 'ORIGINAL_CASE_STREAM_CHANGED:' + key + ':' + suffix)
    if process['status'] == 'completed':
        need(all(value is not None for value in actual.values()), 'COMPLETE_ORIGINAL_CASE_STREAMS_REQUIRED:' + key)
    return actual


def worker(path, identity, key):
    from app.schemas import SimpleBacktestRequest
    from app.services.backtester import _load_simple_candles, run_simple_ema_rsi_backtest_on_dataframe
    from scripts.curator_management_witness import capture_management_witness
    spec = read_spec(path, identity); declaration = spec['requests'][key]
    folder = Path(path).resolve().parent; products = folder / 'products'
    attempt = json.loads((folder / 'execution-attempt.json').read_bytes())
    launch = json.loads((products / (key + '.launch.json')).read_bytes())
    validate_launch(attempt, launch, spec)
    need(launch['case_key'] == key and launch['request_sha256'] == declaration['request_sha256']
         and launch['spec_sha256'] == identity and launch['parent_pid'] == attempt['parent_pid'] == os.getppid(), 'ORIGINAL_WORKER_PARENT_REQUIRED')
    deadline = AbsoluteResearchDeadline.from_record(launch['deadline'])
    need(sys.stdin.buffer.read() == declaration['request_json'].encode(), 'ORIGINAL_INSTRUMENT_WIRE_REQUIRED')
    for name, case in spec['requests'].items():
        need(sha(products / (name + '.request.json')) == case['request_sha256'], 'COMPLETE_ROSTER_BEFORE_FIRST_ORIGINAL_REQUIRED')
    write_new(products / (key + '.worker-attempt.json'), {'case_key': key, 'spec_sha256': identity,
        'request_sha256': declaration['request_sha256'], 'max_attempts': 1, 'deadline': deadline.record()})
    payload = SimpleBacktestRequest.model_validate_json(declaration['request_json'])
    deadline.checkpoint('before_original_compute')
    with capture_management_witness() as observer:
        response = run_simple_ema_rsi_backtest_on_dataframe(payload, _load_simple_candles(payload), lightweight=True, include_differential_pair=False)
    deadline.checkpoint('after_compute'); output = response.model_dump_json().encode()
    witness = {'protocol': 'curator_scalp_full_close_envelope_v1', 'case_key': key,
        'request_sha256': declaration['request_sha256'], 'response_sha256': hashlib.sha256(output).hexdigest(), 'full_close_witness': observer.snapshot()}
    frozen(spec); deadline.checkpoint('before_original_publication')
    write_new(products / (key + '.witness.json'), witness)
    sys.stdout.buffer.write(output); sys.stdout.buffer.flush()


def execute(path, identity):
    spec = read_spec(path, identity); adapter = owner(spec); folder = Path(path).resolve().parent
    deadline = AbsoluteResearchDeadline.start(spec['global_timeout_seconds'])
    attempt = {'spec_sha256': identity, 'parent_pid': os.getpid(), 'max_attempts': 1, 'deadline': deadline.record()}
    write_new(folder / 'execution-attempt.json', attempt); products = folder / 'products'; products.mkdir(exist_ok=False)
    previous.original.stage_requests(products, spec)
    report = {'protocol': 'curator_instrument_original_report_v1', 'question_id': spec['question_id'],
        'before_outcome_spec_sha256': identity, 'source_archive_sha256': spec['source_archive']['sha256'],
        'research_only': True, **{flag: False for flag in FLAGS}, 'results': {}}
    abort = False
    for key, case in spec['requests'].items():
        result = {'status': 'not_attempted_dependency', 'request_sha256': case['request_sha256']}
        try:
            need(not abort, 'SERIAL_STUDY_ABORTED_UNREAPED_CHILD')
            deadline.checkpoint('before_case'); frozen(spec)
            case_deadline = AbsoluteResearchDeadline.start(spec['per_original_timeout_seconds'], parent=deadline)
            launch = {'spec_sha256': identity, 'parent_pid': os.getpid(), 'case_key': key,
                'request_sha256': case['request_sha256'], 'deadline': case_deadline.record()}
            write_new(products / (key + '.launch.json'), launch)
            facts = run_hidden_serial([sys.executable, str(Path(__file__).resolve()), '--worker', key, '--spec', str(path), '--spec-sha256', identity],
                input_path=products / (key + '.request.json'), stdout_path=products / (key + '.response.json'),
                stderr_path=products / (key + '.stderr.txt'), deadline=case_deadline, cwd=ROOT, env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'})
            result.update(status='technical_terminal', deadline_facts=facts, response_sha256=sha(products / (key + '.response.json')),
                stderr_sha256=sha(products / (key + '.stderr.txt')))
            witness = products / (key + '.witness.json')
            if witness.is_file(): result['witness_sha256'] = sha(witness)
            if facts['status'] == 'completed' and facts['exit_code'] == 0:
                result['evidence'] = adapter.verify_response(json.loads((products / (key + '.response.json')).read_bytes()), case,
                    spec['windows'][case['window']], json.loads(witness.read_bytes()), spec, result['response_sha256'])
                frozen(spec); result['status'] = 'completed'
        except OwnedChildNotReaped as error:
            abort = True; result.update(status='technical_terminal', dependency=str(error), deadline_facts=error.facts)
        except ResearchDeadlineError as error:
            result.update(dependency=str(error), deadline_facts=error.facts)
        except Exception as error:
            result['dependency'] = str(error)
        result['artifact_sha256'] = case_artifacts(products, key)
        for suffix, field in (('response.json', 'response_sha256'), ('stderr.txt', 'stderr_sha256'), ('witness.json', 'witness_sha256')):
            if result['artifact_sha256'][suffix] is not None:
                result[field] = result['artifact_sha256'][suffix]
        write_new(products / (key + '.process.json'), result)
        report['results'][key] = {**{field: case[field] for field in ('window', 'arm', 'baseline_key', 'dataset_sha256', 'cost_sha256')}, **result}
        print(json.dumps({'case': key, 'status': result['status']}), flush=True)
    deadline.checkpoint('before_terminal_comparisons')
    report['comparisons'] = adapter.comparisons(report); frozen(spec, full=True)
    deadline.checkpoint('before_terminal_report_publication')
    report['final_source_check'] = 'unchanged'; digest = write_new(folder / 'original-panel-summary.json', report)
    print(json.dumps({'status': 'all_originals_terminal', 'completed': sum(row['status'] == 'completed' for row in report['results'].values()), 'summary_sha256': digest}), flush=True)


def inspect(path, identity):
    from app.services.research_program_tasks import canonical_hash
    spec = read_spec(path, identity); adapter = owner(spec); folder = Path(path).resolve().parent; products = folder / 'products'
    summary = json.loads((folder / 'original-panel-summary.json').read_bytes()); summary_sha = sha(folder / 'original-panel-summary.json')
    need(summary['protocol'] == 'curator_instrument_original_report_v1' and summary['question_id'] == spec['question_id']
         and summary['before_outcome_spec_sha256'] == identity and summary['final_source_check'] == 'unchanged'
         and set(summary['results']) == set(spec['requests']) and summary['research_only'] is True
         and all(summary[flag] is False for flag in FLAGS), 'ORIGINAL_TERMINAL_ROSTER_REQUIRED')
    attempt = json.loads((folder / 'execution-attempt.json').read_bytes()); artifacts = {}
    for key, case in spec['requests'].items():
        row = summary['results'][key]; process = json.loads((products / (key + '.process.json')).read_bytes())
        scope = {name: case[name] for name in ('window', 'arm', 'baseline_key', 'dataset_sha256', 'cost_sha256')}
        need(row['status'] in ('completed', 'technical_terminal', 'not_attempted_dependency')
             and row == {**scope, **process}, 'ORIGINAL_CASE_PROCESS_SCOPE_REQUIRED')
        digests = verify_case_artifacts(products, key, case, process)
        digests['process.json'] = sha(products / (key + '.process.json'))
        if row['status'] == 'completed':
            launch = json.loads((products / (key + '.launch.json')).read_bytes()); validate_launch(attempt, launch, spec)
            work = json.loads((products / (key + '.worker-attempt.json')).read_bytes()); facts = process['deadline_facts']
            need(launch['case_key'] == work['case_key'] == key and launch['spec_sha256'] == work['spec_sha256'] == identity
                 and launch['request_sha256'] == work['request_sha256'] == case['request_sha256']
                 and launch['parent_pid'] == attempt['parent_pid'] and work['max_attempts'] == 1
                 and launch['deadline'] == work['deadline'] == facts['limits'] and facts['status'] == 'completed'
                 and facts['exit_code'] == 0 and facts['publication_allowed'] is True and facts['timeout'] is False
                 and facts['owned_child_reaped'] is True, 'ORIGINAL_IN_BUDGET_COMPLETION_REQUIRED')
            evidence = adapter.verify_response(json.loads((products / (key + '.response.json')).read_bytes()), case,
                spec['windows'][case['window']], json.loads((products / (key + '.witness.json')).read_bytes()), spec, digests['response.json'])
            need(evidence == row['evidence'], 'ORIGINAL_CASE_EVIDENCE_REDERIVATION_REQUIRED')
        artifacts[key] = digests
    need(adapter.comparisons(summary) == summary['comparisons'], 'ORIGINAL_ACCOUNT_COMPARISONS_REQUIRED')
    frozen(spec, full=True); need(sha(folder / 'original-panel-summary.json') == summary_sha, 'SUMMARY_CHANGED_DURING_INSPECTION')
    return {'status': 'verified_read_only', 'spec_sha256': identity, 'summary_sha256': summary_sha,
        'source_archive_sha256': spec['source_archive']['sha256'], 'artifact_roster_sha256': canonical_hash(artifacts),
        'terminal_cases': len(artifacts), 'completed_originals_reverified': sum(row['status'] == 'completed' for row in summary['results'].values()),
        'research_only': True, 'independent_validation': False, 'own_agent_outcome': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--manifest'); parser.add_argument('--manifest-sha256')
    parser.add_argument('--output'); parser.add_argument('--folder'); parser.add_argument('--spec'); parser.add_argument('--spec-sha256')
    parser.add_argument('--study-owner', choices=tuple(PREPARERS), default='bollinger_context_v1')
    action = parser.add_mutually_exclusive_group(required=True)
    for name in ('prepare', 'seal', 'execute', 'inspect', 'worker'): action.add_argument('--' + name)
    args = parser.parse_args()
    if args.prepare: prepare(args.prepare, args.manifest, args.manifest_sha256, args.output, args.study_owner)
    elif args.seal: seal(args.seal, args.folder)
    elif args.worker: worker(args.spec, args.spec_sha256, args.worker)
    elif args.inspect: print(json.dumps(inspect(args.inspect, args.spec_sha256)))
    else: execute(Path(args.execute).resolve(), args.spec_sha256)
