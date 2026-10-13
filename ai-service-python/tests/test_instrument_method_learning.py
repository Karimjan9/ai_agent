"""Small synthetic fixtures exercise actual native/ordinary replay consumption."""

import copy
import hashlib
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_learning import InstrumentMethodLearning, receipt_current, validate_contract
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract
from test_specialist_council import fixtures, member


def pinned_recipe(request, key, targets, input_bindings=None):
    context = {'regime': 'trend_up', 'session': 'london', 'volatility': 'normal'}
    bindings = {'symbol': request.symbol, 'timeframe': request.timeframe,
        'data_hash': request.replay_dataset_hash,
        'execution_hash': execution_contract_metadata(request)['execution_hash'],
        'pre_2026_only': True, 'baseline_hash': 'b' * 64, 'context_hash': canonical_hash(context)}
    methods, operators = {}, []
    for target, ast in targets.items():
        implementation = {'kind': 'typed_ast', 'target': target, 'ast': ast,
            'input_bindings': input_bindings or {}, 'budget': {'cpu_seconds': 1.0, 'max_calls': 1000, 'max_node_evaluations': 48000}}
        method = {'protocol': 'instrument_method_v1', 'instrument_key': 'synthetic-confirmation',
            'method_key': key + '-' + target, 'revision': 1, 'context_predicate': context,
            'implementation': implementation, 'research_only': True, 'promotion_evidence': False}
        identity = canonical_hash(method)
        methods[identity] = method
        operators.append(seal_contract({'protocol': 'typed_operator_decision_v1',
            'source_task_key': 'method:' + identity, 'scope_key': 'fixture-scope', 'source_context': bindings,
            'target': target, 'ast': ast, 'ast_hash': canonical_hash(ast), 'abstractions': {},
            'input_bindings': implementation['input_bindings'], 'budget': implementation['budget']}))
    methods = dict(sorted(methods.items()))
    revision = {'protocol': 'instrument_method_recipe_v1', 'recipe_key': key, 'revision': 1,
        'method_hashes': sorted(methods), 'method_contracts': methods, 'context_predicate': context,
        'horizon': 'hour', 'hybrid': len(methods) > 1, 'research_only': True,
        'qualification_granted': False, 'promotion_evidence': False}
    body = {'protocol': 'instrument_method_recipe_runtime_v1', 'recipe_key': key,
        'recipe_hash': canonical_hash(revision), 'recipe_revision': revision,
        'recipe_revision_json': canonical_json(revision), 'context_predicate': context,
        'operators': operators, 'parameter_adapters': [], 'scope_bindings': bindings}
    return {**body, 'runtime_hash': canonical_hash(body), 'runtime_json': canonical_json(body)}


def learning_contract(request, frame, recipes, *, preferred=0, default='BASELINE', agent='fixture-agent'):
    library = {'protocol': 'instrument_method_library_v1', 'instrument_inventory_hash': 'a' * 64,
        'method_hashes': sorted({key for recipe in recipes for key in recipe['recipe_revision']['method_hashes']}),
        'recipe_hashes': sorted(recipe['recipe_hash'] for recipe in recipes)}
    library_hash = canonical_hash(library)
    policy = {'protocol': 'agent_method_policy_v1', 'agent_key': agent, 'revision': 1,
        'library_contract': library, 'library_hash': library_hash,
        'rules': [{'context_predicate': recipes[preferred]['context_predicate'],
            'preferred_recipes': [recipes[preferred]['recipe_hash']]}], 'default_action': default}
    policy = {**policy, 'policy_hash': canonical_hash(policy), 'policy_json': canonical_json(policy)}
    pool = {'recipes': sorted([{'recipe_hash': recipe['recipe_hash'], 'runtime_hash': recipe['runtime_hash']}
        for recipe in recipes], key=lambda item: item['recipe_hash'])}
    body = {'protocol': 'instrument_method_learning_v1', 'data_hash': request.replay_dataset_hash,
        'execution_hash': execution_contract_metadata(request)['execution_hash'], 'expected_agent_key': agent,
        'scope': {'symbol': request.symbol, 'timeframe': request.timeframe,
            'evaluated_start': frame.iloc[0]['time'].isoformat(), 'evaluated_end': frame.iloc[-1]['time'].isoformat(), 'context': {}},
        'library_contract': library, 'library_contract_json': canonical_json(library),
        'library_hash': library_hash, 'compiled_pool_hash': canonical_hash(pool), 'recipes': recipes, 'policy': policy}
    return {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}


def strategy(frame, parameters):
    result = frame.copy()
    result['signal'] = 'BUY'
    result['signal_confidence'] = 1.0
    result['market_regime'] = 'trend_up'
    result['market_session'] = 'london'
    result['volatility_regime'] = 'normal_volatility'
    return result


def const(value):
    return {'op': 'CONST', 'type': 'bool' if isinstance(value, bool) else 'number', 'value': value}


class InstrumentMethodLearningTest(unittest.TestCase):
    def native(self, contract_targets, *, preferred=0, execution=None, policy=None):
        request, frame = fixtures([member('hour', holding=3)], execution=execution, policy=policy)
        request.emit_decision_trace = True
        recipes = [pinned_recipe(request, 'recipe-' + str(index), targets)
                   for index, targets in enumerate(contract_targets)]
        contract = learning_contract(request, frame, recipes, preferred=preferred)
        declaration = copy.deepcopy(request.specialist_council_contract['members'][0])
        declaration['method_learning'] = contract
        request.specialist_council_contract = seal_contract({**request.specialist_council_contract, 'members': [declaration]})
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            result = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
        return request, frame, contract, result

    def test_native_independent_policy_selects_distinct_recipe_with_real_effect_and_receipts(self):
        targets = [{'confirmation': const(True)}, {'confirmation': const(False)}]
        _, _, contract, allowed = self.native(targets, preferred=0)
        _, _, _, vetoed = self.native(targets, preferred=1)
        self.assertGreater(allowed.total_trades, 0)
        self.assertEqual(vetoed.total_trades, 0)
        receipt = vetoed.specialist_council_receipt['members'][0]['method_learning_receipt']
        events = [event for row in vetoed.decision_trace for event in row.get('method_learning_events', [])]
        self.assertEqual(receipt['event_count'], len(events))
        self.assertEqual(receipt['used_count'], len(events))
        self.assertEqual(receipt['behavior_changed_count'], len(events))
        self.assertTrue(all(event['policy_hash'] == receipt['policy_hash'] for event in events))
        hasher = hashlib.sha256()
        for event in events:
            self.assertEqual(event['receipt_hash'], canonical_hash({key: value for key, value in event.items() if key != 'receipt_hash'}))
            encoded = canonical_json(event).encode()
            hasher.update(len(encoded).to_bytes(8, 'big')); hasher.update(encoded)
        self.assertEqual(receipt['events_hash'], hasher.hexdigest())
        self.assertFalse(receipt['economic_authority'])
        self.assertTrue(receipt_current(allowed.model_dump(), contract))

    def test_native_hybrid_two_pinned_methods_reduces_actual_risk(self):
        _, _, _, baseline = self.native([{'confirmation': const(True)}])
        _, _, _, hybrid = self.native([{'confirmation': const(True), 'risk_multiplier': const(0.5)}])
        first = next(item for item in baseline.specialist_council_receipt['intent_execution_ledger'] if item['reason'] == 'filled')
        reduced = next(item for item in hybrid.specialist_council_receipt['intent_execution_ledger'] if item['reason'] == 'filled')
        self.assertAlmostEqual(reduced['risk_amount'], first['risk_amount'] * 0.5)
        receipt = hybrid.specialist_council_receipt['members'][0]['method_learning_receipt']
        self.assertEqual(len(receipt['operator_receipts']), 2)
        self.assertTrue(any(event['outputs'].get('risk_multiplier') == 0.5
            for row in hybrid.decision_trace for event in row.get('method_learning_events', []) if isinstance(event['outputs'], dict)))

    def test_external_gate_still_rejects_and_never_fabricates_method_use(self):
        request, _, contract, result = self.native([{'confirmation': const(True)}],
            policy={'max_open_positions': 1})
        receipt = result.specialist_council_receipt['members'][0]['method_learning_receipt']
        self.assertGreater(receipt['event_count'], 0)
        self.assertTrue(any(row['member_decisions'][0]['rejection_code'] == 'owner_position_open' for row in result.decision_trace))
        stale = result.model_dump(); stale['specialist_council_receipt']['members'][0]['method_learning_receipt']['used_count'] += 1
        self.assertFalse(receipt_current(stale, contract))
        declaration = copy.deepcopy(request.specialist_council_contract['members'][0])
        declaration['allowed_actions'] = ['wait']
        request.specialist_council_contract = seal_contract({**request.specialist_council_contract, 'members': [declaration]})
        frame = pd.DataFrame({'time': pd.date_range('2025-01-06', periods=22, freq='h', tz='UTC'),
            'open': 100.0, 'high': 100.05, 'low': 99.95, 'close': 100.0, 'volume': 100.0, 'volume_available': True})
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            denied = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
        self.assertEqual(denied.specialist_council_receipt['members'][0]['method_learning_receipt']['used_count'], 0)
        self.assertFalse(any(row.get('method_learning_events') for row in denied.decision_trace))

    def test_wrong_library_policy_and_json_copies_fail_closed(self):
        request, frame = fixtures([member('hour')])
        recipe = pinned_recipe(request, 'recipe', {'confirmation': const(True)})
        contract = learning_contract(request, frame, [recipe])
        validate_contract(contract, dataset_hash=request.replay_dataset_hash, execution_hash=contract['execution_hash'])
        changed = copy.deepcopy(contract); changed['policy']['agent_key'] = 'different-agent'
        with self.assertRaisesRegex(ValueError, 'JSON_COPY_MISMATCH'):
            InstrumentMethodLearning(changed, request)
        with self.assertRaisesRegex(ValueError, 'AGENT_OR_REVISION'):
            validate_contract(contract, dataset_hash=request.replay_dataset_hash, execution_hash=contract['execution_hash'], expected_agent_key='different-agent')
        with self.assertRaisesRegex(ValueError, 'DATA_OR_EXECUTION'):
            validate_contract(contract, dataset_hash='other-data', execution_hash=contract['execution_hash'])

    def test_operator_rehash_cannot_relabel_original_method_source(self):
        request, frame = fixtures([member('hour')])
        recipe = pinned_recipe(request, 'recipe', {'confirmation': const(True)})
        raw = {key: value for key, value in recipe.items() if key not in {'runtime_hash', 'runtime_json'}}
        operator = raw['operators'][0]
        operator['ast'] = const(False); operator['ast_hash'] = canonical_hash(operator['ast'])
        raw['operators'][0] = seal_contract(operator)
        recipe = {**raw, 'runtime_hash': canonical_hash(raw), 'runtime_json': canonical_json(raw)}
        contract = learning_contract(request, frame, [recipe])
        with self.assertRaisesRegex(ValueError, 'PINNED_SOURCE'):
            InstrumentMethodLearning(contract, request)

    def test_future_observation_and_post_2026_inputs_reject(self):
        request, frame = fixtures([member('hour')])
        recipe = pinned_recipe(request, 'recipe', {'confirmation': const(True)})
        runtime = InstrumentMethodLearning(learning_contract(request, frame, [recipe]), request)
        with self.assertRaisesRegex(ValueError, 'FUTURE_OBSERVATION'):
            runtime.evaluate({}, decision_id='x', phase='entry', decision_at='2025-01-06T00:00:00Z',
                observed_at='2025-01-06T01:00:00Z', direction='BUY')
        changed = frame.copy(); changed.loc[0, 'time'] = pd.Timestamp('2026-01-01T00:00:00Z')
        with self.assertRaisesRegex(ValueError, 'POST_2026'):
            runtime.validate_frame(changed)

    def test_future_operator_input_and_risk_increase_reject_without_fallback(self):
        request, frame = fixtures([member('hour')])
        ast = {'op': 'GREATER_THAN', 'args': [
            {'op': 'PRICE_CLOSE', 'input_key': 'price', 'available_at': '2025-12-01T00:00:00Z'}, const(0.0)]}
        recipe = pinned_recipe(request, 'future', {'confirmation': ast}, {'price': 'close'})
        runtime = InstrumentMethodLearning(learning_contract(request, frame, [recipe]), request)
        row = {'close': 100.0, 'market_regime': 'trend_up', 'market_session': 'london', 'volatility_regime': 'normal_volatility'}
        with self.assertRaisesRegex(ValueError, 'FUTURE_INPUT'):
            runtime.evaluate(row, decision_id='future', phase='entry', decision_at='2025-01-06T00:00:00Z',
                observed_at='2025-01-06T00:00:00Z', direction='BUY')
        increased = pinned_recipe(request, 'increase', {'risk_multiplier': const(1.01)})
        runtime = InstrumentMethodLearning(learning_contract(request, frame, [increased]), request)
        with self.assertRaisesRegex(ValueError, 'RISK_INCREASE'):
            runtime.evaluate(row, decision_id='increase', phase='entry', decision_at='2025-01-06T00:00:00Z',
                observed_at='2025-01-06T00:00:00Z', direction='BUY')

    def test_native_exit_requests_existing_exit_and_preserves_gap_pricing(self):
        request, frame, _, result = self.native([{'confirmation': const(True), 'exit': const(True)}])
        self.assertTrue(any(item['exit_reason'] == 'method_learning_exit' for item in result.specialist_council_receipt['position_ledger']))
        # The first owned position opens at index 1; its next open gaps through
        # the existing stop before the learned exit can choose a price.
        frame.loc[2, ['open', 'high', 'low', 'close']] = [98.0, 98.05, 97.95, 98.0]
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            gap = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
        first = gap.specialist_council_receipt['position_ledger'][0]
        self.assertEqual(first['exit_reason'], 'gap_stop')
        self.assertAlmostEqual(gap.trade_ledger[0].exit_price, 98.0)

    def test_wrong_policy_library_and_declared_post_2026_scope_reject(self):
        request, frame = fixtures([member('hour')])
        recipe = pinned_recipe(request, 'recipe', {'confirmation': const(True)})
        contract = learning_contract(request, frame, [recipe])
        body = {key: value for key, value in contract.items() if key not in {'contract_hash', 'contract_json'}}
        policy = {key: value for key, value in body['policy'].items() if key not in {'policy_hash', 'policy_json'}}
        policy['library_hash'] = 'f' * 64
        body['policy'] = {**policy, 'policy_hash': canonical_hash(policy), 'policy_json': canonical_json(policy)}
        changed = {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}
        with self.assertRaisesRegex(ValueError, 'POLICY_LIBRARY_MISMATCH'):
            InstrumentMethodLearning(changed, request)
        body = {key: value for key, value in contract.items() if key not in {'contract_hash', 'contract_json'}}
        body['scope'] = {**body['scope'], 'evaluated_end': '2026-01-01T00:00:00Z'}
        changed = {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}
        with self.assertRaisesRegex(ValueError, 'PRE_2026_SCOPE'):
            InstrumentMethodLearning(changed, request)

    def test_sealed_full_runtime_vector_rejects_parameter_drift(self):
        request, frame = fixtures([member('hour')])
        request.strategy, request.base_strategy = 'ema_rsi_v1', 'ema_rsi'
        request.parameters = {'ema_fast': 3, 'ema_slow': 10}
        recipe = pinned_recipe(request, 'recipe', {'confirmation': const(True)})
        contract = learning_contract(request, frame, [recipe])
        body = {key: value for key, value in contract.items() if key not in {'contract_hash', 'contract_json'}}
        body.update(strategy_family='ema_rsi', runtime_parameters=request.parameters,
            runtime_parameters_hash=canonical_hash(request.parameters))
        contract = {**body, 'contract_hash': canonical_hash(body), 'contract_json': canonical_json(body)}
        InstrumentMethodLearning(contract, request)
        request.parameters = {**request.parameters, 'ema_fast': 4}
        with self.assertRaisesRegex(ValueError, 'PARAMETERS_DRIFT'):
            validate_contract(contract, dataset_hash=request.replay_dataset_hash,
                execution_hash=contract['execution_hash'], payload=request)

    def test_ordinary_actual_replay_consumes_veto_preserving_clock_and_baseline(self):
        request = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='H1',
            replay_dataset_hash='fixture-data', parameters={'time_stop_candles': 3},
            execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2))
        request.emit_decision_trace = True
        frame = pd.DataFrame({'time': pd.date_range('2025-01-06', periods=224, freq='h', tz='UTC'),
            'open': 100.0, 'high': 100.05, 'low': 99.95, 'close': 100.0, 'volume': 100.0, 'volume_available': True})
        ast = {'op': 'NOT', 'args': [{'op': 'BOOL', 'input_key': 'measured_volume',
            'available_at': '2024-01-01T00:00:00Z'}]}
        recipe = pinned_recipe(request, 'ordinary-veto', {'confirmation': ast}, {'measured_volume': 'volume_available'})
        contract = learning_contract(request, frame, [recipe])
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            baseline = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
            request.policy_context['instrument_method_learning'] = contract
            vetoed = run_simple_ema_rsi_backtest_on_dataframe(request, frame)
        self.assertGreater(baseline.total_trades, 0)
        self.assertEqual(vetoed.total_trades, 0)
        receipt = vetoed.data_quality['instrument_method_learning_receipt']
        events = [row['method_learning'] for row in vetoed.decision_trace if row.get('event_type') == 'instrument_method_decision']
        self.assertEqual(receipt['event_count'], len(events))
        self.assertEqual(len(events), 24)
        self.assertTrue(receipt_current(vetoed.model_dump(), contract))
        self.assertEqual(baseline.data_quality['replay_executed_clock']['schedule_hash'],
            vetoed.data_quality['replay_executed_clock']['schedule_hash'])


if __name__ == '__main__':
    unittest.main()
