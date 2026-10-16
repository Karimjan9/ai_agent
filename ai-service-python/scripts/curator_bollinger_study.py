"""Code-owned Bollinger context question; no tuning from its own outcomes."""
import copy
import json
from pathlib import Path

from scripts import curator_intraday_panel as shared
from scripts.curator_instrument_windows import validate_manifest

need, sha = shared.need, shared.sha
ROOT = Path(__file__).resolve().parents[2]
QUESTION = 'bollinger-range-context-20261016-v1'
ARMS = {'all_scalp6': ('none', 6), 'range_low_scalp6': ('range_low_v1', 6),
        'all_day24': ('none', 24), 'range_low_day24': ('range_low_v1', 24)}
WINDOWS = ('may2025', 'august2025')


def physical(baseline, arm):
    body = copy.deepcopy(baseline)
    body['parameters']['session_context_gate'], body['parameters']['time_stop_candles'] = ARMS[arm]
    return body


def validate(spec):
    from app.schemas import SimpleBacktestRequest
    from app.services.research_program_tasks import canonical_hash
    from app.services.parameter_schema import validate_strategy_parameters
    from app.services.execution_contract import execution_contract_metadata
    from app.strategies.registry import get_strategy
    from app.strategies.laboratory import apply_session_mean_reversion_strategy
    need(spec['study_owner'] == 'bollinger_context_v1' and spec['question_id'] == QUESTION
         and spec['instrument'] == 'bollinger_bands' and spec['instrument_key'] == 'range_reentry' and set(spec['windows']) == set(WINDOWS)
         and set(spec['baselines']) == set(WINDOWS)
         and set(spec['requests']) == {window + '__' + arm for window in WINDOWS for arm in ARMS}
         and spec['predeclared_arms'] == 8, 'EXACT_BOLLINGER_CONTEXT_ROSTER_REQUIRED')
    need(spec['research_only'] is True and all(spec[flag] is False for flag in shared.FLAGS)
         and spec['per_original_timeout_seconds'] == 600 and spec['global_timeout_seconds'] == 5400
         and spec['max_attempts_per_arm'] == 1 and spec['serial_execution'] is True and spec['worker_threads'] == 1
         and spec['process_priority'] == 'below_normal_hidden', 'BOUNDED_BOLLINGER_RESEARCH_ONLY_REQUIRED')
    need(set(spec['upstream']) == {'windows', 'account_control', 'source_review'}, 'EXACT_BOLLINGER_UPSTREAM_REQUIRED')
    review = spec['upstream']['source_review']
    need(review['sha256'] == 'bb5e378b4ca700bbcacf85594bd4d8f00112b160fcb0ebd106e21893bc18fdc8'
         and sha(review['path']) == review['sha256'], 'EXACT_CURATOR_REVIEW_REQUIRED')
    window_ref, control_ref = spec['upstream']['windows'], spec['upstream']['account_control']
    manifest = validate_manifest(window_ref['path'], window_ref['sha256'])
    need(manifest['windows'] == spec['windows'] and control_ref['sha256'] == shared.original.PRIOR_SHA
         and sha(control_ref['path']) == control_ref['sha256'], 'ORIGINAL_BOLLINGER_INPUTS_REQUIRED')
    original = json.loads(Path(control_ref['path']).read_bytes())['baselines']['october_pre_dst']
    for window, baseline in spec['baselines'].items():
        p = baseline['parameters']
        fixed = {'session_start': 7, 'session_end': 14, 'lookback': 20, 'session_context_gate': 'none',
            'time_stop_candles': 6, 'minimum_signal_confidence': .35, 'atr_stop_multiplier': 1.5, 'atr_target_multiplier': 2.5,
            'trailing_atr_multiplier': 0., 'partial_take_profit_fraction': 0., 'volume_lane': 'none'}
        need(all(p.get(key) == value for key, value in fixed.items()) and baseline['strategy'] == 'session_mean_reversion_v1'
             and baseline['base_strategy'] == 'session_mean_reversion' and baseline['execution']['allowed_sessions_utc'] == ['7-14'],
             'EXACT_REENTRY_CONTROL_REQUIRED')
        # Whole declared vector, including otherwise optional controls. Omitting a
        # default or adding a schema-valid gene must not create an undeclared arm.
        need(canonical_hash(p) == '7a9d42b6719845fa4acf0624e5443546049e1d3af663ec8bc264806c3fb35e49',
             'EXACT_PREDECLARED_REENTRY_PARAMETER_VECTOR_REQUIRED')
        actual, old = copy.deepcopy(baseline), copy.deepcopy(original)
        for body in (actual, old):
            for field in ('strategy', 'base_strategy', 'parameters', 'dataset_path', 'replay_dataset_hash', 'execution_contract'):
                body.pop(field)
        old['execution']['allowed_sessions_utc'] = ['7-14']
        need(actual == old, 'ORIGINAL_ACCOUNT_AND_MODELED_COSTS_REQUIRED')
        need(all(value == original['parameters'][name] for name, value in p.items()
                 if name in original['parameters'] and name != 'time_stop_candles'), 'UNCHANGED_COMMON_PHYSICAL_CONTROLS_REQUIRED')
    for key, case in spec['requests'].items():
        window, arm = case['window'], case['arm']; request = case['request']
        need(key == window + '__' + arm and case['question_id'] == QUESTION and case['baseline_key'] == window + '__all_scalp6'
             and request == physical(spec['baselines'][window], arm), 'ONLY_CONTEXT_AND_HOLDING_DELTAS_ALLOWED')
        shared.original.verify_wire(case); payload = SimpleBacktestRequest.model_validate_json(case['request_json'])
        metadata = execution_contract_metadata(payload)
        need(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy) == request['parameters']
             and get_strategy(payload.strategy, payload.base_strategy) is apply_session_mean_reversion_strategy
             and metadata['status'] == 'matched' and metadata['execution_hash'] == case['cost_sha256']
             and request['execution_contract']['execution_hash'] == case['cost_sha256']
             and request['execution_contract']['parameters'] == request['execution']
             and request['dataset_path'] == spec['windows'][window]['path']
             and request['replay_dataset_hash'] == case['dataset_sha256'] == spec['windows'][window]['sha256']
             and payload.dataset_tail_rows is None and not payload.candles and not payload.policy_context
             and not payload.portfolio_members and not payload.specialist_council_contract and not payload.composition_runtime_contract,
             'ACTUAL_ORIGINAL_REENTRY_REQUEST_REQUIRED')


def verify_response(body, case, window, witness, spec, identity):
    return shared.verify_response(body, case, window, witness, spec, identity)


def comparisons(report):
    pairs = {'context_given_scalp': ('range_low_scalp6', 'all_scalp6'),
        'context_given_day': ('range_low_day24', 'all_day24'),
        'holding_given_all': ('all_day24', 'all_scalp6'),
        'holding_given_range_low': ('range_low_day24', 'range_low_scalp6')}
    result = {}
    for window in WINDOWS:
        rows = {arm: report['results'][window + '__' + arm] for arm in ARMS}; effects = {}
        for name, (treated, control) in pairs.items():
            left, right = rows[treated], rows[control]; matched = shared.original.matched_pair(left, right)
            effects[name] = {'matched_complete': matched,
                'realized_cash_delta': left['evidence']['net_cash'] - right['evidence']['net_cash'] if matched else None,
                'unknown_not_zero': not matched, 'isolated_same_entry_effect': False, 'independent': False}
        interaction = None
        if all(shared.original.matched_pair(row, rows['all_scalp6']) for row in rows.values()):
            cash = {arm: row['evidence']['net_cash'] for arm, row in rows.items()}
            interaction = cash['range_low_day24'] - cash['range_low_scalp6'] - cash['all_day24'] + cash['all_scalp6']
        result[window] = {'pairs': effects, 'cash_interaction': interaction, 'independent': False}
    return result
