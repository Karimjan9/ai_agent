"""Fixed post-outcome EMA signal diagnostic; no account executor or gate changes."""
import argparse
from collections import Counter
import hashlib
import io
import json
import math
from pathlib import Path
import sys
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'ai-service-python'))
from scripts.curator_research_deadline import AbsoluteResearchDeadline
from scripts.curator_scalp_panel import runtime_fingerprint, write_new

QUESTION = ROOT / 'docs/research/methods/ema-pullback-fidelity/question.json'
QUESTION_SHA = '4e78282e4386278960d6d2bb4317f70a547dbdf2ad46d22a3521295748e7a866'
ORIGINAL = ROOT.parent / 'complete-intraday-methods-20261016/.runtime/complete-intraday-originals-20261016'
OUTPUT = ROOT / '.runtime/ema-opportunity-audit-20261016'
WINDOWS = ('june2025', 'september2025')
CASES = tuple(w + '__ema_' + arm for w in WINDOWS for arm in ('control', 'structural'))
FALSE_FLAGS = ('own_agent_outcome', 'independent_validation', 'credit', 'mastery',
               'economic_authority', 'paper_authority', 'live_authority')
CEILING = {'research_only': True, 'curator_owned': True, **dict.fromkeys(FALSE_FLAGS, False)}
CATEGORIES = ('original_fill', 'outside_session_confidence_pass', 'outside_session_confidence_fail',
              'in_session_confidence_fail', 'occupied_or_state_different', 'exact_session_boundary')


def need(ok, reason):
    if not ok:
        raise ValueError(reason)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def encoded(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()


def checked(path, expected, custody):
    raw = Path(path).read_bytes()
    need(digest(raw) == expected, 'ORIGINAL_BYTES_CHANGED:' + Path(path).name)
    custody[str(Path(path).resolve())] = expected
    return raw


def validate_question(question):
    need(question['protocol'] == 'curator_ema_source_and_opportunity_question_v1'
         and question['source_cases'] == list(CASES) and question['windows'] == list(WINDOWS)
         and question['resource_contract'] == {'max_windows': 2, 'max_loaded_rows_per_window': 6500,
             'seconds': 300, 'attempts': 1, 'serial': True}
         and all(question.get(k) is v for k, v in CEILING.items()), 'EXACT_RESEARCH_QUESTION_REQUIRED')


def app_names(root):
    return {p.relative_to(root).as_posix() for p in (root / 'ai-service-python/app').rglob('*')
            if p.is_file() and '__pycache__' not in p.parts and p.suffix != '.pyc'}


def bind_app(root, roster, custody):
    names = app_names(root)
    need(names == {n for n in roster if n.startswith('ai-service-python/app/')}, 'EXACT_ORIGINAL_APP_ROSTER_REQUIRED')
    for name in sorted(names):
        checked(root / name, roster[name], custody)


def unchanged(custody):
    after = {p: digest(Path(p).read_bytes()) for p in custody}
    need(after == custody, 'SOURCE_OR_INPUT_CHANGED_DURING_DIAGNOSTIC')
    return after


def require_runtime(spec):
    need(runtime_fingerprint() == spec['runtime'], 'ORIGINAL_DECLARED_RUNTIME_REQUIRED')


def inputs(deadline):
    custody = {}
    q = json.loads(checked(QUESTION, QUESTION_SHA, custody)); validate_question(q)
    spec = json.loads(checked(ORIGINAL / 'before-outcome-spec.json', q['original_spec_sha256'], custody))
    report = json.loads(checked(ORIGINAL / 'original-panel-summary.json', q['original_report_sha256'], custody))
    need(report['final_source_check'] == 'unchanged' and all(report.get(k) is False for k in FALSE_FLAGS),
         'ORIGINAL_RESEARCH_TERMINAL_REQUIRED')
    roster = spec['source_snapshot']['files']
    require_runtime(spec)
    bind_app(ROOT, roster, custody)
    for name in ('ai-service-python/scripts/curator_research_deadline.py', 'ai-service-python/scripts/curator_scalp_panel.py'):
        checked(ROOT / name, roster[name], custody)
    archive = ORIGINAL / 'before-outcome-source-bytes.zip'
    need(spec['source_archive']['sha256'] == q['original_archive_sha256'], 'ORIGINAL_ARCHIVE_BINDING_REQUIRED')
    checked(archive, q['original_archive_sha256'], custody)
    with zipfile.ZipFile(archive) as z:
        expected = spec['source_archive']['entries']
        need(len(z.namelist()) == len(set(z.namelist())) and set(z.namelist()) == set(expected), 'EXACT_ARCHIVE_ROSTER_REQUIRED')
        for name, identity in expected.items():
            need(digest(z.read(name)) == identity, 'ORIGINAL_ARCHIVE_ENTRY_CHANGED:' + name)
    originals = {}
    for key in CASES:
        deadline.checkpoint('before_original_' + key)
        case, row = spec['requests'][key], report['results'][key]
        process_body = {k: v for k, v in row.items() if k not in ('window', 'arm', 'baseline_key', 'dataset_sha256', 'cost_sha256')}
        p = json.loads(checked(ORIGINAL / 'products' / (key + '.process.json'), digest(encoded(process_body)), custody))
        facts = p['deadline_facts']
        need(p['status'] == 'completed' and facts['exit_code'] == 0 and facts['timeout'] is False
             and facts['owned_child_reaped'] is True and facts['publication_allowed'] is True, 'COMPLETE_ORIGINAL_REQUIRED')
        products = {suffix: checked(ORIGINAL / 'products' / (key + '.' + suffix), sha, custody)
                    for suffix, sha in p['artifact_sha256'].items()}
        request, body, envelope = (json.loads(products[n]) for n in ('request.json', 'response.json', 'witness.json'))
        need(digest(products['request.json']) == case['request_sha256'] and request == case['request']
             and envelope['case_key'] == key and envelope['request_sha256'] == case['request_sha256']
             and envelope['response_sha256'] == digest(products['response.json']), 'ORIGINAL_REQUEST_RESPONSE_WITNESS_BINDING_REQUIRED')
        quality = body['data_quality']['decision_trace']
        need(quality['complete'] is True and quality['event_count'] == len(body['decision_trace'])
             and envelope['full_close_witness']['public_trades'] == body['trade_ledger'], 'COMPLETE_ORIGINAL_TRACE_REQUIRED')
        originals[key] = (request, body, envelope['full_close_witness'])
    csvs = {w: checked(Path(spec['windows'][w]['path']), spec['windows'][w]['sha256'], custody) for w in WINDOWS}
    return spec, originals, csvs, custody


def gate_cells(records):
    """Intersections, not a replay or hypothetical state/position calculation."""
    counts = Counter(('inside' if r['inside_session'] else 'outside') + '_' +
                     ('confidence_pass' if r['confidence_pass'] else 'confidence_fail') for r in records)
    return {key: counts[key] for key in ('inside_confidence_pass', 'inside_confidence_fail',
                                       'outside_confidence_pass', 'outside_confidence_fail')}


def past_descriptors(frame, features, index):
    import pandas as pd
    start = index - 19
    rows = features[max(0, start):index + 1]
    valid = start >= 0 and all(r.get('_ema20') is not None and r.get('_ema50') is not None
        and math.isfinite(r['_ema20']) and math.isfinite(r['_ema50']) for r in rows)
    times = pd.to_datetime(frame.iloc[max(0, start):index + 1]['time'], utc=True)
    valid = valid and times.diff().iloc[1:].eq(pd.Timedelta(minutes=5)).all()
    if not valid:
        return {'status': 'unavailable_immature_or_gap', 'observed_rows': len(rows)}
    fast, slow = [[float(r[key]) for r in rows] for key in ('_ema20', '_ema50')]
    def changes(values):
        signs = [1 if x > 0 else -1 for x in values if x != 0]
        return sum(a != b for a, b in zip(signs, signs[1:]))
    return {'status': 'past_only_descriptive_not_filter', 'observed_rows': 20,
        'ema20_inside_closed_candle_range_rows': sum(float(frame.iloc[start + k]['low']) <= x <= float(frame.iloc[start + k]['high']) for k, x in enumerate(fast)),
        'ema20_nonzero_slope_sign_changes': changes([b - a for a, b in zip(fast, fast[1:])]),
        'lab_ema50_nonzero_slope_sign_changes': changes([b - a for a, b in zip(slow, slow[1:])]),
        'lab_ema20_ema50_nonzero_order_changes': changes([a - b for a, b in zip(fast, slow)])}


def prepare_month(request, raw):
    import pandas as pd
    from app.schemas import SimpleBacktestRequest
    from app.services.backtester import prepare_feature_snapshot, prepare_signal_snapshot
    from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
    payload = SimpleBacktestRequest.model_validate(request)
    need(payload.strategy == 'trend_v1' and payload.base_strategy == 'trend'
         and payload.parameters.get('entry_topology_variant') == 'source_ema_pretrend_pullback_v1'
         and payload.parameters.get('volume_lane') == 'none' and payload.execution.allowed_sessions_utc == ['7-14']
         and payload.signal_delay_candles == 0 and not payload.portfolio_members and not payload.strategies,
         'EXACT_ORIGINAL_EMA_PROGRAMME_REQUIRED')
    frame = pd.read_csv(io.BytesIO(raw), low_memory=False)
    need(200 < len(frame) <= 6500, 'BOUNDED_ORIGINAL_MONTH_REQUIRED')
    feature = prepare_feature_snapshot(payload, frame)
    signal = prepare_signal_snapshot(payload, feature_snapshot=feature)
    return signal.frame, ClosedInstrumentMethodFeatures(feature.frame, bar_duration_seconds=300).features


def primary_trace(body, rows):
    events = [e for e in body['decision_trace'] if e['event_type'] in ('signal_evaluation', 'position_management')]
    mapping = {e['candle_index']: e for e in events}
    need(len(events) == len(mapping) and set(mapping) == set(range(200, rows)), 'EXACT_ORIGINAL_DECISION_INDEX_SCOPE_REQUIRED')
    return mapping


def select_examples(records):
    predicates = (lambda r: r['control']['accepted'],
        lambda r: not r['inside_session'] and r['confidence_pass'] and r['control']['phase'] == 'signal_evaluation',
        lambda r: not r['inside_session'] and not r['confidence_pass'] and r['control']['phase'] == 'signal_evaluation',
        lambda r: r['inside_session'] and not r['confidence_pass'] and r['control']['phase'] == 'signal_evaluation',
        lambda r: r['control']['phase'] == 'position_management' or r['control'] != r['structural'],
        lambda r: r['execution_time'][11:19] in ('07:00:00', '14:00:00'))
    return {category: next((r for r in records if predicate(r)), None)
            for category, predicate in zip(CATEGORIES, predicates)}


def analyze_month(frame, features, originals, expected_raw):
    import pandas as pd
    request = originals[0][0]; params = request['parameters']; n = len(frame)
    need(len(features) == n and frame.index.tolist() == list(range(n)), 'EXACT_PREPARED_SOURCE_ROWS_REQUIRED')
    traces = [primary_trace(item[1], n) for item in originals]
    stages, records = Counter(), []
    summarize = lambda e: {'phase': e['event_type'], 'accepted': e['accepted'], 'first_veto': e['rejection_code'],
        'regime': e['market_regime'], 'volatility': e['volatility_regime'], 'raw_session': e['features'].get('market_session')}
    for index in range(199, n - 1):
        row, source = frame.iloc[index], features[index]
        for name, value in (('two_bar_available', source['method_pullback_available']),
            ('two_bar_core', source['_pullback_direction']), ('four_bar_available', source['method_pullback_pretrend_available']),
            ('four_bar_pretrend', source['_pretrend_pullback_direction'])):
            stages[name] += bool(value)
        direction = source['_pretrend_pullback_direction']; adx = float(row['adx']); rsi = float(row['trend_rsi'])
        adx_pass = direction is not None and adx >= params['trend_strength_min']
        confirmed = adx_pass and ((direction == 'BUY' and rsi > 50) or (direction == 'SELL' and rsi < 50))
        stages['four_bar_plus_adx'] += adx_pass; stages['four_bar_plus_adx_rsi'] += confirmed
        raw_signal = row['signal'] in ('BUY', 'SELL')
        need(raw_signal == confirmed, 'ORIGINAL_SOURCE_CONFIRMATION_RECONCILIATION_REQUIRED')
        need(all((trace[index + 1]['features']['source_entry_rule_admitted'] in (True, 1.0)) == raw_signal
                 for trace in traces), 'ORIGINAL_RAW_SIGNAL_ROW_RECONCILIATION_REQUIRED')
        if not raw_signal:
            continue
        events = [trace[index + 1] for trace in traces]
        for event in events:
            need(pd.Timestamp(event['candle_time']) == pd.Timestamp(frame.iloc[index + 1]['time'])
                 and event['features']['source_entry_rule_direction'] == row['signal']
                 and event['features']['source_entry_rule_protocol'] == 'closed_ema_pretrend_entry_v1', 'ORIGINAL_SOURCE_EXECUTION_LINK_REQUIRED')
            for key in ('adx', 'trend_rsi', 'trend_fast', 'trend_slow', 'signal_confidence'):
                need(math.isclose(float(event['features'][key]), float(row[key]), abs_tol=1e-10, rel_tol=1e-12), 'ORIGINAL_SIGNAL_FEATURE_CHANGED:' + key)
            need(math.isclose(event['confidence'], float(row['signal_confidence']), abs_tol=1e-12), 'ORIGINAL_CONFIDENCE_CHANGED')
        stamp = pd.Timestamp(frame.iloc[index + 1]['time'])
        records.append({'source_index': index, 'source_time': pd.Timestamp(row['time']).isoformat(),
            'execution_index': index + 1, 'execution_time': stamp.isoformat(), 'direction': row['signal'],
            'adx': adx, 'rsi14': rsi, 'confidence': float(row['signal_confidence']),
            'confidence_pass': float(row['signal_confidence']) >= params['minimum_signal_confidence'],
            'inside_session': 7 <= stamp.hour < 14, 'control': summarize(events[0]), 'structural': summarize(events[1])})
    need(len(records) == expected_raw and frame.iloc[-1]['signal'] == 'WAIT', 'EXACT_ORIGINAL_RAW_SIGNAL_COUNT_REQUIRED')
    arms = {}
    for arm, item, trace in zip(('control', 'structural'), originals, traces):
        flat = [r for r in records if r[arm]['phase'] == 'signal_evaluation']
        filled = [r for r in flat if r[arm]['accepted']]
        reasons = Counter(r[arm]['first_veto'] for r in flat if not r[arm]['accepted'])
        funnel = item[1]['entry_funnel']
        need(funnel['raw_strategy_signals'] == len(records) and funnel['flat_signal_opportunities'] == len(flat)
             and funnel['accepted_entries'] == len(filled) and funnel['rejected'] == dict(reasons)
             and sorted(r['source_index'] for r in filled) == item[2]['entry_indices'], 'ORIGINAL_FUNNEL_ENTRY_RECONCILIATION_REQUIRED')
        arms[arm] = {'flat': len(flat), 'filled': len(filled), 'occupied': len(records) - len(flat),
            'first_veto': dict(reasons), 'flat_intersections': gate_cells(flat)}
    examples = select_examples(records); cards = {}
    for category, record in examples.items():
        cards[category] = {'status': 'absent'} if record is None else {'status': 'observed', **record,
            'past20': past_descriptors(frame, features, record['source_index'])}
    return {'source_stages': dict(stages), 'raw': len(records), 'all_raw_intersections': gate_cells(records),
            'arms': arms, 'raw_candidates': records}, cards


def export(expected_source_sha256):
    need(digest(Path(__file__).read_bytes()) == expected_source_sha256, 'REVIEWED_AUDITOR_SOURCE_REQUIRED')
    deadline = AbsoluteResearchDeadline.start(300)
    OUTPUT.mkdir(parents=True, exist_ok=False)
    own = {str(p): digest(p.read_bytes()) for p in (Path(__file__).resolve(), ROOT / 'ai-service-python/tests/test_curator_ema_opportunity_audit.py')}
    write_new(OUTPUT / 'attempt.json', {'protocol': 'curator_ema_opportunity_attempt_v1', 'attempt': 1,
        'question_sha256': QUESTION_SHA, 'source_hashes': own, 'deadline': deadline.record(), **CEILING})
    try:
        spec, originals, csvs, custody = inputs(deadline); custody.update(own)
        result, cards = {}, {}
        for window, expected in zip(WINDOWS, (64, 57)):
            deadline.checkpoint('before_features_' + window)
            pair = [originals[window + '__ema_' + a] for a in ('control', 'structural')]
            frame, features = prepare_month(pair[0][0], csvs[window])
            need(len(frame) == spec['windows'][window]['loaded_rows'], 'ORIGINAL_LOADED_ROW_COUNT_REQUIRED')
            result[window], cards[window] = analyze_month(frame, features, pair, expected)
            deadline.checkpoint('after_diagnostic_' + window)
        after = unchanged(custody)
        require_runtime(spec)
        need(app_names(ROOT) == {n for n in spec['source_snapshot']['files'] if n.startswith('ai-service-python/app/')},
             'EXACT_ORIGINAL_APP_ROSTER_REQUIRED')
        facts = deadline.checkpoint('before_diagnostic_publication')
        card_sha = write_new(OUTPUT / 'cards.json', {'protocol': 'curator_ema_chronological_cards_v1', 'windows': cards, **CEILING})
        report_sha = write_new(OUTPUT / 'diagnostic.json', {'protocol': 'curator_ema_opportunity_audit_v1',
            'question_sha256': QUESTION_SHA, 'original_spec_sha256': custody[str((ORIGINAL / 'before-outcome-spec.json').resolve())],
            'source_and_input_sha256_before': custody, 'source_and_input_sha256_after': after,
            'windows': result, 'cards_sha256': card_sha, 'deadline_facts': facts,
            'descriptors': 'Last20 contiguous closed rows only; EMA20 candle-range intersections and slope sign changes; zero slopes omitted. EMA50/order counts are laboratory descriptors, not author thresholds or entry filters.',
            'limits': 'Static intersections do not establish hypothetical acceptance/profit; first veto censors later gates; original state and missing raw context preserved; reused history, no economic calculation.', **CEILING})
        final = deadline.checkpoint('after_report_publication')
        write_new(OUTPUT / 'verification.json', {'status': 'verified_signal_diagnostic_only', 'report_sha256': report_sha,
            'cards_sha256': card_sha, 'question_sha256': QUESTION_SHA, 'deadline_facts': final,
            'valid_only_if_failure_json_absent': True, **CEILING})
        deadline.checkpoint('after_verification_publication')
        return {'status': 'verified_signal_diagnostic_only', 'report_sha256': report_sha, 'cards_sha256': card_sha}
    except BaseException as error:
        write_new(OUTPUT / 'failure.json', {'status': 'technical_unknown', 'reason': str(error), **CEILING})
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--export', action='store_true', required=True)
    parser.add_argument('--expected-source-sha256', required=True)
    arguments = parser.parse_args()
    print(json.dumps(export(arguments.expected_source_sha256)))
