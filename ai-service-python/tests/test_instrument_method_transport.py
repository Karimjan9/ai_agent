"""Small software fixtures for candidate transport and cache admission only."""

import copy
import unittest
from unittest.mock import patch

from app import main
from app.schemas import SimpleBacktestRequest, StrategyRuntimeConfig
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_learning import InstrumentMethodLearning, validate_contract
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract
from test_instrument_method_learning import pinned_recipe, learning_contract, const
from test_specialist_council import fixtures, member


def sealed(body):
    body = {key: value for key, value in body.items() if key not in {'contract_hash', 'contract_json'}}
    return {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}


def ordinary(agent='fixture-agent'):
    _, frame = fixtures([member('hour')])
    request = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='H1',
        replay_dataset_hash='transport-software-fixture', parameters={'ema_fast': 3, 'ema_slow': 10})
    recipe = pinned_recipe(request, 'transport-recipe', {'confirmation': const(True)})
    contract = learning_contract(request, frame, [recipe], agent=agent)
    contract = sealed({**contract, 'strategy_family': 'ema_rsi', 'runtime_parameters': dict(request.parameters),
        'runtime_parameters_hash': canonical_hash(request.parameters)})
    request.policy_context['instrument_method_learning'] = contract
    return request, frame, contract


def current_ordinary_cache(request, contract):
    runtime = InstrumentMethodLearning(contract, request)
    runtime.evaluate({'market_regime': 'trend_up', 'market_session': 'london', 'volatility_regime': 'normal_volatility'},
        decision_id='software-fixture-decision', phase='entry', direction='BUY',
        decision_at='2025-01-06T01:00:00Z', observed_at='2025-01-06T01:00:00Z')
    return {'result': {'execution_contract': execution_contract_metadata(request),
        'data_quality': {'instrument_method_learning_receipt': runtime.receipt()}}}


class InstrumentMethodTransportTest(unittest.TestCase):
    def test_study_caps_bound_existing_deadline_and_reject_unowned_or_oversized_budgets(self):
        request, _, _ = ordinary()
        original = main._bounded_replay_seconds(request, 'backtest')
        cap = {'max_rows': 2048, 'max_decision_events': 5000, 'max_runtime_seconds': 12}
        request.policy_context['instrument_method_study'] = {'study_hash': 'a' * 64,
            'arm': 'L1A1', 'resource_caps': cap}
        request.policy_context['instrument_method_study_resource_cap'] = cap
        self.assertEqual(main._bounded_replay_seconds(request, 'backtest'), min(original, 12))
        bad = request.model_copy(deep=True)
        bad.policy_context['instrument_method_study_resource_cap']['max_runtime_seconds'] = 1681
        with self.assertRaisesRegex(ValueError, 'CEILING_EXCEEDED'):
            main._validate_method_learning_before_cache(bad)
        bad = request.model_copy(deep=True)
        bad.policy_context.pop('instrument_method_study')
        with self.assertRaisesRegex(ValueError, 'RESOURCE_OWNER_REQUIRED'):
            main._validate_method_learning_before_cache(bad)

    def test_invalid_ordinary_binding_rejects_before_any_cache_lookup(self):
        request, _, _ = ordinary()
        request.policy_context['instrument_method_learning']['contract_hash'] = '0' * 64
        with patch('app.main._load_immutable_replay_cache') as cache:
            with self.assertRaisesRegex(ValueError, 'CONTRACT_HASH_MISMATCH'):
                main._run_all_backtests_sync(request)
            cache.assert_not_called()

    def test_batch_guard_collapses_the_exact_per_strategy_bindings(self):
        first, _, first_contract = ordinary('fixture-first')
        second, _, second_contract = ordinary('fixture-second')
        cohort = first.model_copy(deep=True)
        cohort.policy_context = {}
        cohort.strategies = [StrategyRuntimeConfig(strategy=item.strategy, base_strategy=item.base_strategy,
            parameters=item.parameters, instrument_method_learning=contract)
            for item, contract in ((first, first_contract), (second, second_contract))]
        with patch('app.services.instrument_method_learning.validate_contract', wraps=validate_contract) as validate:
            main._validate_method_learning_before_cache(cohort)
        calls = validate.call_args_list
        self.assertEqual([call.args[0]['contract_hash'] for call in calls],
            [first_contract['contract_hash'], second_contract['contract_hash']])
        self.assertEqual([call.kwargs['payload'].policy_context['instrument_method_learning']['policy']['agent_key'] for call in calls],
            ['fixture-first', 'fixture-second'])
        self.assertTrue(all(not call.kwargs['payload'].specialist_council_contract for call in calls))

    def test_candidate_cache_payload_keeps_candidate_method_and_study_identity(self):
        first, _, first_contract = ordinary('fixture-first')
        second, _, second_contract = ordinary('fixture-second')
        cohort = first.model_copy(deep=True)
        cohort.policy_context = {'trial_ledger': {'historical': 9},
            'repair_contracts': {'first': {'gene': 'ema_fast'}, 'second': {'gene': 'ema_slow'}}}
        candidates = []
        for label, request, contract in (('first', first, first_contract), ('second', second, second_contract)):
            child = request.model_copy(deep=True)
            child.policy_context = {**cohort.policy_context, 'instrument_method_learning': contract,
                'instrument_method_study': {'fixture_owner': label}}
            collapsed = main._candidate_cache_payload(cohort, child, label)
            self.assertEqual(collapsed.policy_context['instrument_method_learning']['contract_hash'], contract['contract_hash'])
            self.assertEqual(collapsed.policy_context['instrument_method_study']['fixture_owner'], label)
            self.assertEqual(set(collapsed.policy_context['repair_contracts']), {label})
            self.assertNotIn('trial_ledger', collapsed.policy_context)
            self.assertFalse(collapsed.strategies)
            candidates.append(collapsed)
        self.assertNotEqual(main._replay_cache_key('candidate', candidates[0]), main._replay_cache_key('candidate', candidates[1]))

    def test_cache_requires_current_actual_method_receipt_and_refuses_legacy_or_sibling(self):
        request, _, contract = ordinary()
        item = current_ordinary_cache(request, contract)
        self.assertTrue(main._candidate_cache_contract_is_current(item, request))
        legacy = copy.deepcopy(item)
        legacy['result']['data_quality'].pop('instrument_method_learning_receipt')
        self.assertFalse(main._candidate_cache_contract_is_current(legacy, request))
        altered = copy.deepcopy(item)
        altered['result']['data_quality']['instrument_method_learning_receipt']['used_count'] += 1
        self.assertFalse(main._candidate_cache_contract_is_current(altered, request))
        sibling, _, _ = ordinary('sibling-policy')
        self.assertFalse(main._candidate_cache_contract_is_current(item, sibling))

    def test_native_member_guard_validates_actual_member_parameters_and_policy(self):
        request, frame = fixtures([member('hour')])
        declaration = copy.deepcopy(request.specialist_council_contract['members'][0])
        child = request.model_copy(update={'strategy': declaration['strategy'], 'base_strategy': declaration['base_strategy'],
            'parameters': declaration['parameters'], 'specialist_council_contract': {}})
        recipe = pinned_recipe(child, 'native-transport-recipe', {'confirmation': const(True)})
        binding = learning_contract(child, frame, [recipe], agent='native-fixture-agent')
        binding = sealed({**binding, 'strategy_family': 'ema_rsi', 'runtime_parameters': dict(child.parameters),
            'runtime_parameters_hash': canonical_hash(child.parameters)})
        declaration['method_learning'] = binding
        request.specialist_council_contract = seal_contract({**request.specialist_council_contract, 'members': [declaration]})
        main._validate_method_learning_before_cache(request)
        drifted = request.model_copy(deep=True)
        drifted.specialist_council_contract['members'][0]['parameters']['ema_fast'] = 4
        with self.assertRaisesRegex(ValueError, 'PARAMETERS_DRIFT'):
            main._validate_method_learning_before_cache(drifted)
        changed = request.model_copy(deep=True)
        body = copy.deepcopy(binding)
        policy = {key: value for key, value in body['policy'].items() if key not in {'policy_hash', 'policy_json'}}
        policy['agent_key'] = 'unowned-fixture-agent'
        body['policy'] = {**policy, 'policy_hash': canonical_hash(policy), 'policy_json': canonical_json(policy)}
        changed.specialist_council_contract['members'][0]['method_learning'] = sealed(body)
        with self.assertRaisesRegex(ValueError, 'POLICY_AGENT_OR_REVISION_MISMATCH'):
            main._validate_method_learning_before_cache(changed)

    def test_false_scope_and_parameter_drift_reject_before_cache(self):
        request, _, contract = ordinary()
        false_scope = request.model_copy(deep=True)
        false_scope.policy_context['instrument_method_learning'] = sealed({**contract, 'scope': False})
        with self.assertRaisesRegex(ValueError, 'SCOPE_MISMATCH'):
            main._validate_method_learning_before_cache(false_scope)
        drift = request.model_copy(deep=True)
        drift.parameters['ema_fast'] = 4
        with self.assertRaisesRegex(ValueError, 'PARAMETERS_DRIFT'):
            main._validate_method_learning_before_cache(drift)

    def test_explicit_falsy_bindings_never_bypass_guard_or_reuse_legacy_cache(self):
        request, _, _ = ordinary()
        legacy = {'result': {'execution_contract': execution_contract_metadata(request)}}
        for invalid in ({}, False, None, []):
            with self.subTest(binding=invalid):
                bad = request.model_copy(deep=True)
                bad.policy_context['instrument_method_learning'] = invalid
                with self.assertRaises(ValueError):
                    main._validate_method_learning_before_cache(bad)
                self.assertFalse(main._candidate_cache_contract_is_current(legacy, bad))
        native, _ = fixtures([member('hour')])
        native.specialist_council_contract['members'][0]['method_learning'] = False
        with self.assertRaises(ValueError):
            main._validate_method_learning_before_cache(native)


if __name__ == '__main__':
    unittest.main()
