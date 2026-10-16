"""Three complete-account method questions on two fixed native months.

One bounded adapter of the existing offline owner. No new replay engine,
population scheduler, agent-outcome import or economic authority is added.
"""
import copy
import json
from pathlib import Path

from scripts import curator_scalp_panel as shared
from scripts.curator_intraday_month_windows import validate_manifest

QUESTION = 'complete-intraday-methods-20261016-v1'
OWNER = 'intraday_methods_v1'
WINDOWS = ('june2025', 'september2025')
ARMS = ('bb_control', 'bb_recipe', 'ema_control', 'ema_structural', 'retest_control', 'retest_structural')
FAMILIES = ('bb', 'ema', 'retest')
PARAMETER_HASHES = {'bb': '7a9d42b6719845fa4acf0624e5443546049e1d3af663ec8bc264806c3fb35e49',
                    'ema': '2ab982d13e1d4798512547ff6e209ce12f481bac49298a9b4b0b2338e563734c',
                    'retest': '6f93391b65d62916c5bdd1dcfecd46251bf10aaa97de3706666a4a3d55e357bc'}
BB_CONTRACT_HASHES = {'june2025': '8144c6cadc3e6e81640af3d4bac33e92713051e1db16a92fffd26a80a4542337',
                      'september2025': 'faaef117a67a42191f42d1fc71f094265f0dd49d2ff7bbcfd6ae8321a160c8e2'}
SOURCE_REVIEW_SHA = 'ed68b747e61d659e9f59912684f217c70b605de5a9f904645281309f9e5ebba7'
SOURCES_SHA = '7affb895aac24a757172ac9452cb8fcb079a5b46c869b8a7c1a950d6151675a1'
need, sha = shared.need, shared.sha


def validate(spec):
    from app.schemas import SimpleBacktestRequest
    from app.services.parameter_schema import validate_strategy_parameters
    from app.services.execution_contract import execution_contract_metadata
    from app.services.research_program_tasks import canonical_hash
    from scripts.curator_bollinger_recipe_evidence import _midline_owner
    from app.strategies.registry import get_strategy
    from app.strategies.laboratory import apply_session_mean_reversion_strategy, apply_trend_specialist, apply_trend_retest_strategy
    functions = {'bb': apply_session_mean_reversion_strategy, 'ema': apply_trend_specialist, 'retest': apply_trend_retest_strategy}
    identities = {'bb': ('session_mean_reversion_v1', 'session_mean_reversion'),
                  'ema': ('trend_v1', 'trend'), 'retest': ('trend_retest_v1', 'trend_retest')}
    need(spec['study_owner'] == OWNER and spec['question_id'] == QUESTION
         and set(spec['windows']) == set(WINDOWS) and spec['predeclared_arms'] == 12
         and set(spec['baselines']) == {window + '__' + family for window in WINDOWS for family in FAMILIES}
         and set(spec['requests']) == {window + '__' + arm for window in WINDOWS for arm in ARMS},
         'EXACT_THREE_METHOD_TWO_MONTH_ROSTER_REQUIRED')
    need(spec['research_only'] is True and all(spec[flag] is False for flag in shared.FLAGS)
         and spec['per_original_timeout_seconds'] == 1500 and spec['global_timeout_seconds'] == 10800
         and spec['max_attempts_per_arm'] == 1 and spec['serial_execution'] is True
         and spec['worker_threads'] == 1 and spec['process_priority'] == 'below_normal_hidden',
         'FIXED_INTRADAY_RESEARCH_CAPS_AND_AUTHORITY_REQUIRED')
    need(set(spec['upstream']) == {'windows', 'account_control', 'source_review', 'method_sources'}, 'EXACT_INTRADAY_SOURCE_ROSTER_REQUIRED')
    sources = spec['upstream']['method_sources']; review = spec['upstream']['source_review']
    need(SOURCES_SHA != 'DECLARATION_PENDING' and sources['sha256'] == SOURCES_SHA == sha(sources['path'])
         and review['sha256'] == SOURCE_REVIEW_SHA == sha(review['path']), 'EXACT_REVIEWED_METHOD_SOURCES_REQUIRED')
    data = spec['upstream']['windows']; manifest = validate_manifest(data['path'], data['sha256'])
    control = spec['upstream']['account_control']
    need(manifest['windows'] == spec['windows'] and control['sha256'] == shared.PRIOR_SHA == sha(control['path']),
         'ORIGINAL_INTRADAY_DATA_AND_ACCOUNT_REQUIRED')
    original = json.loads(Path(control['path']).read_bytes())['baselines']['october_pre_dst']
    for name, base in spec['baselines'].items():
        window, family = name.split('__')
        need(PARAMETER_HASHES[family] != 'DECLARATION_PENDING'
             and canonical_hash(base['parameters']) == PARAMETER_HASHES[family]
             and (base['strategy'], base['base_strategy']) == identities[family]
             and base['initial_balance'] == 10000. and base['risk_per_trade'] == 1.
             and base['execution']['allowed_sessions_utc'] == ['7-14'] and not base.get('policy_context'),
             'EXACT_PREDECLARED_INTRADAY_CONTROL_VECTOR_REQUIRED')
        actual, old = copy.deepcopy(base), copy.deepcopy(original)
        for body in (actual, old):
            for key in ('strategy', 'base_strategy', 'parameters', 'dataset_path', 'replay_dataset_hash', 'execution_contract'):
                body.pop(key)
        old['execution']['allowed_sessions_utc'] = ['7-14']
        need(actual == old, 'UNCHANGED_INTRADAY_ACCOUNT_AND_EXECUTION_REQUIRED')
        need(base['dataset_path'] == spec['windows'][window]['path']
             and base['replay_dataset_hash'] == spec['windows'][window]['sha256'], 'EXACT_INTRADAY_CONTROL_WINDOW_REQUIRED')
    for key, case in spec['requests'].items():
        window, arm, family, request = case['window'], case['arm'], case['method'], case['request']
        need(family == arm.split('_')[0] and key == window + '__' + arm
             and case['question_id'] == QUESTION and case['baseline_key'] == window + '__' + family + '_control',
             'EXACT_INTRADAY_METHOD_CASE_REQUIRED')
        baseline = spec['baselines'][window + '__' + family]
        restored = copy.deepcopy(request)
        if arm == 'bb_recipe':
            need(restored['parameters'].pop('session_band_variance', None) == 'population', 'FIXED_BB_POPULATION_REQUIRED')
            context = restored.pop('policy_context')
            need(set(context) == {'instrument_method_learning'}
                 and BB_CONTRACT_HASHES[window] != 'DECLARATION_PENDING'
                 and context['instrument_method_learning']['contract_hash'] == BB_CONTRACT_HASHES[window], 'EXACT_WINDOW_BB_COMPILATION_REQUIRED')
            _midline_owner(request)
        elif arm.endswith('_structural'):
            need(restored['parameters'].pop('intraday_exit_variant', None) == 'closed_setup_2r_v1', 'FIXED_STRUCTURAL_PROTECTION_REQUIRED')
            if family == 'retest':
                need(restored['parameters'].pop('entry_topology_variant', None) == 'source_frozen_retest_v1', 'FIXED_FROZEN_RETEST_REQUIRED')
                if 'entry_topology_variant' in baseline['parameters']:
                    restored['parameters']['entry_topology_variant'] = baseline['parameters']['entry_topology_variant']
        need(restored == baseline, 'ONLY_PREDECLARED_METHOD_COORDINATES_ALLOWED')
        shared.verify_wire(case)
        payload = SimpleBacktestRequest.model_validate_json(case['request_json'])
        metadata = execution_contract_metadata(payload)
        need(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy) == request['parameters']
             and get_strategy(payload.strategy, payload.base_strategy) is functions[family]
             and metadata['status'] == 'matched' and metadata['execution_hash'] == case['cost_sha256']
             and request['execution_contract']['execution_hash'] == case['cost_sha256']
             and request['execution_contract']['parameters'] == request['execution']
             and request['dataset_path'] == spec['windows'][window]['path']
             and request['replay_dataset_hash'] == case['dataset_sha256'] == spec['windows'][window]['sha256']
             and payload.dataset_tail_rows is None and not payload.candles and not payload.portfolio_members
             and not payload.specialist_council_contract and not payload.composition_runtime_contract,
             'ACTUAL_INTRADAY_ORIGINAL_REQUEST_REQUIRED')


def verify_response(body, case, window, witness, spec, identity):
    validate(spec)
    key = case['window'] + '__' + case['arm']
    need(case == spec['requests'].get(key) and window == spec['windows'][case['window']], 'EXACT_SEALED_INTRADAY_CASE_REQUIRED')
    if case['arm'] != 'bb_recipe':
        return shared.verify_response(body, case, window, witness, spec, identity)
    from scripts.curator_bollinger_recipe_evidence import _verify_method_evidence
    physical = shared.verify_physical_response(body, case, window, witness, spec, identity)
    return {**physical, 'curator_method_evidence': _verify_method_evidence(body, case['request'], window, witness['full_close_witness'])}


def comparisons(report):
    result = {}
    for window in WINDOWS:
        result[window] = {}
        for family, treated in (('bb', 'bb_recipe'), ('ema', 'ema_structural'), ('retest', 'retest_structural')):
            control = report['results'][window + '__' + family + '_control']
            recipe = report['results'][window + '__' + treated]
            matched = shared.matched_pair(control, recipe)
            result[window][family] = {'matched_complete': matched,
                'realized_cash_delta': recipe['evidence']['net_cash'] - control['evidence']['net_cash'] if matched else None,
                'control_open_position_count': control['evidence']['open_position_count'] if matched else None,
                'recipe_open_position_count': recipe['evidence']['open_position_count'] if matched else None,
                'eof_open_exposure_present': bool(control['evidence']['open_position_count'] or recipe['evidence']['open_position_count']) if matched else None,
                'unknown_not_zero': not matched, 'independent': False,
                'effect_scope': 'complete_account_method_policy', 'individual_component_credit': False,
                'dominance_claim': False, 'total_marked_utility_claim': False}
    eligible = []
    # A research continuation prerequisite, not a power calculation or skill
    # certificate. Priority is fixed before results, never the largest PnL.
    for family, arm in (('ema', 'ema_structural'), ('retest', 'retest_structural'), ('bb', 'bb_recipe')):
        passing = True
        for window in WINDOWS:
            control = report['results'][window + '__' + family + '_control']
            recipe = report['results'][window + '__' + arm]
            if not shared.matched_pair(control, recipe):
                passing = False
                continue
            left, right = recipe['evidence'], control['evidence']
            passing &= (left['net_cash'] > 0 and left['net_cash'] > right['net_cash']
                and left['mature'] >= 20 and right['mature'] >= 20
                and left['open_position_count'] == 0 and right['open_position_count'] == 0
                and left['closed_cash_drawdown_percent'] <= right['closed_cash_drawdown_percent']
                and left['scalp_metrics']['holding_minutes']['over_cap_count'] == 0)
        if passing:
            eligible.append(family)
    result['prospective_agent_practice'] = {
        'eligible_methods_fixed_priority': eligible, 'selected_method': eligible[0] if eligible else None,
        'next_action': 'prepare_separately_sealed_provisional_agent_practice' if eligible else 'retain_results_no_automatic_agent_handoff',
        'minimum_mature_per_period': 20, 'sample_floor_is_not_statistical_power': True,
        'requires_fresh_agent_originals': True, 'automatic_dispatch': False,
        'own_agent_outcome': False, 'independent': False, 'skill_credit': False}
    return result
