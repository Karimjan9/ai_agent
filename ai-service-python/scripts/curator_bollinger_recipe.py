"""One complete lab recipe against its original recross/account control.

This is a two-arm bundle question, not author-system reproduction, component
attribution, independent validation or an agent-owned experience producer.
"""
import copy
import json
from pathlib import Path

from scripts import curator_intraday_panel as shared
from scripts.curator_bollinger_month_window import validate_manifest

need, sha = shared.need, shared.sha
QUESTION = 'bollinger-complete-recipe-20261016-v1'
OWNER = 'bollinger_recipe_v1'
WINDOW = 'october2025'
ARMS = ('control', 'population_midline')
# Pinned after the actual PHP declaration, before either outcome is computed.
POLICY_CONTRACT_HASH = '48395ad2549a0a178b17d639f830b3c908bbf9cbe1308a0934dbb52a9997a9ff'
SOURCE_REVIEW_SHA = 'ed68b747e61d659e9f59912684f217c70b605de5a9f904645281309f9e5ebba7'


def validate(spec):
    from app.schemas import SimpleBacktestRequest
    from app.services.parameter_schema import validate_strategy_parameters
    from app.services.execution_contract import execution_contract_metadata
    from app.services.research_program_tasks import canonical_hash
    from app.services.instrument_method_learning import InstrumentMethodLearning
    from app.strategies.registry import get_strategy
    from app.strategies.laboratory import apply_session_mean_reversion_strategy

    need(spec['study_owner'] == OWNER and spec['question_id'] == QUESTION
         and spec['instrument'] == 'bollinger_bands' and spec['instrument_key'] == 'range_reentry'
         and set(spec['windows']) == set(spec['baselines']) == {WINDOW}
         and set(spec['requests']) == {WINDOW + '__' + arm for arm in ARMS}
         and spec['predeclared_arms'] == 2, 'EXACT_COMPLETE_RECIPE_ROSTER_REQUIRED')
    need(spec['research_only'] is True and all(spec[flag] is False for flag in shared.FLAGS)
         and spec['per_original_timeout_seconds'] == 2700 and spec['global_timeout_seconds'] == 6000
         and spec['max_attempts_per_arm'] == 1 and spec['serial_execution'] is True
         and spec['worker_threads'] == 1 and spec['process_priority'] == 'below_normal_hidden',
         'EXACT_BOUNDED_COMPLETE_RECIPE_REQUIRED')
    need(set(spec['upstream']) == {'windows', 'account_control', 'source_review'}, 'EXACT_RECIPE_UPSTREAM_REQUIRED')
    review, data, control = (spec['upstream'][name] for name in ('source_review', 'windows', 'account_control'))
    need(review['sha256'] == SOURCE_REVIEW_SHA and sha(review['path']) == SOURCE_REVIEW_SHA,
         'EXACT_ORIGINAL_ARTICLE_REVIEW_REQUIRED')
    manifest = validate_manifest(data['path'], data['sha256'])
    need(manifest['windows'] == spec['windows'] and control['sha256'] == shared.original.PRIOR_SHA
         and sha(control['path']) == control['sha256'], 'ORIGINAL_COMPLETE_RECIPE_DATA_AND_COST_REQUIRED')
    baseline = spec['baselines'][WINDOW]
    need(canonical_hash(baseline['parameters']) == '7a9d42b6719845fa4acf0624e5443546049e1d3af663ec8bc264806c3fb35e49'
         and not baseline.get('policy_context') and baseline['initial_balance'] == 10000.
         and baseline['risk_per_trade'] == 1. and baseline['strategy'] == 'session_mean_reversion_v1'
         and baseline['base_strategy'] == 'session_mean_reversion', 'EXACT_UNCHANGED_RECIPE_CONTROL_REQUIRED')
    actual = copy.deepcopy(baseline)
    old = json.loads(Path(control['path']).read_bytes())['baselines']['october_pre_dst']
    for body in (actual, old):
        for field in ('strategy', 'base_strategy', 'parameters', 'dataset_path', 'replay_dataset_hash', 'execution_contract'):
            body.pop(field)
    old['execution']['allowed_sessions_utc'] = ['7-14']
    need(actual == old, 'UNCHANGED_RECIPE_ACCOUNT_AND_MODELED_COST_REQUIRED')
    for key, case in spec['requests'].items():
        arm, request = case['arm'], case['request']
        need(key == WINDOW + '__' + arm and case['window'] == WINDOW and case['question_id'] == QUESTION
             and case['baseline_key'] == WINDOW + '__control', 'EXACT_RECIPE_CASE_SCOPE_REQUIRED')
        restored = copy.deepcopy(request)
        if arm == 'population_midline':
            need(restored['parameters'].pop('session_band_variance', None) == 'population', 'POPULATION_RECIPE_FORMULA_REQUIRED')
            context = restored.pop('policy_context')
            need(set(context) == {'instrument_method_learning'}, 'ONLY_DECLARED_CURATOR_METHOD_POLICY_REQUIRED')
            contract = context['instrument_method_learning']
            need(POLICY_CONTRACT_HASH != 'DECLARATION_PENDING'
                 and contract['contract_hash'] == POLICY_CONTRACT_HASH, 'EXACT_COMPILED_RECIPE_CONTRACT_REQUIRED')
        need(restored == baseline, 'ONLY_DECLARED_RECIPE_BUNDLE_COORDINATES_ALLOWED')
        shared.original.verify_wire(case)
        payload = SimpleBacktestRequest.model_validate_json(case['request_json'])
        need(validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy) == request['parameters']
             and get_strategy(payload.strategy, payload.base_strategy) is apply_session_mean_reversion_strategy
             and execution_contract_metadata(payload)['status'] == 'matched'
             and execution_contract_metadata(payload)['execution_hash'] == case['cost_sha256']
             and request['execution_contract']['execution_hash'] == case['cost_sha256']
             and request['execution_contract']['parameters'] == request['execution']
             and payload.dataset_tail_rows is None and not payload.candles and not payload.portfolio_members
             and not payload.specialist_council_contract and not payload.composition_runtime_contract
             and request['dataset_path'] == spec['windows'][WINDOW]['path']
             and request['replay_dataset_hash'] == case['dataset_sha256'] == spec['windows'][WINDOW]['sha256'],
             'ACTUAL_COMPLETE_RECIPE_REQUEST_REQUIRED')
        if arm == 'population_midline':
            InstrumentMethodLearning(contract, payload, expected_agent_key='curator-bollinger-complete-recipe-v1')


def verify_response(body, case, window, witness, spec, identity):
    from scripts.curator_bollinger_recipe_evidence import verify_response as verify
    return verify(body, case, window, witness, spec, identity)


def comparisons(report):
    control, recipe = (report['results'][WINDOW + '__' + arm] for arm in ARMS)
    matched = shared.original.matched_pair(control, recipe)
    return {WINDOW: {'matched_complete': matched,
        'realized_cash_delta': recipe['evidence']['net_cash'] - control['evidence']['net_cash'] if matched else None,
        'unknown_not_zero': not matched, 'effect_scope': 'whole_account_two_coordinate_recipe_bundle',
        'individual_formula_or_exit_credit': False, 'independent': False,
        'dominance_claim': False, 'total_marked_utility_claim': False}}
