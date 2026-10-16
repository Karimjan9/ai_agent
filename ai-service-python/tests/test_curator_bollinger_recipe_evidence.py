"""Synthetic owner/receipt checks, not archive originals or economic evidence."""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import numpy as np
import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services import backtester
from app.services.execution_contract import execution_contract_metadata
from app.services.research_program_tasks import canonical_hash, canonical_json
from scripts import curator_bollinger_recipe_evidence as evidence
from scripts import curator_scalp_panel as physical
from scripts.curator_management_witness import capture_management_witness


def sealed(body, hash_key, json_key):
    return {**body, hash_key: canonical_hash(body), json_key: canonical_json(body)}


def synthetic_contract(request, frame):
    """Canonical existing-owner software fixture; production hash is separate."""
    recipes = []
    execution = execution_contract_metadata(request)['execution_hash']
    # Match the actual Foundry export: boolean node types are inferred.
    ast = {'op': 'AND', 'args': [
        {'op': 'BOOL', 'input_key': key, 'available_at': '2000-01-01T00:00:00Z'}
        for key in ('available', 'effect')]}
    for regime in ('range', 'trend_up', 'trend_down'):
        for volatility in ('low', 'normal', 'high'):
            for session in ('london', 'overlap'):
                context = dict(regime=regime, volatility=volatility, session=session)
                key = regime + '-' + volatility + '-' + session
                bindings = {'symbol': request.symbol, 'timeframe': request.timeframe,
                    'data_hash': request.replay_dataset_hash, 'execution_hash': execution,
                    'pre_2026_only': True, 'baseline_hash': 'b' * 64, 'context_hash': canonical_hash(context),
                    'method_feature_owner': 'closed_instrument_method_features_v1'}
                implementation = {'kind': 'typed_ast', 'target': 'exit', 'ast': ast,
                    'input_bindings': {'available': 'method_midline20_available', 'effect': 'method_midline20_exit'},
                    'budget': {'cpu_seconds': 1.0, 'max_calls': 100000, 'max_node_evaluations': 4800000}}
                method = {'protocol': 'instrument_method_v1', 'instrument_key': 'cost_aware_exit',
                    'method_key': key, 'revision': 1, 'context_predicate': context,
                    'implementation': implementation, 'research_only': True, 'promotion_evidence': False}
                method_hash = canonical_hash(method)
                operator = sealed({'protocol': 'typed_operator_decision_v1', 'source_task_key': 'method:' + method_hash,
                    'scope_key': key, 'source_context': bindings, 'target': 'exit', 'ast': ast,
                    'ast_hash': canonical_hash(ast), 'abstractions': {}, 'input_bindings': implementation['input_bindings'],
                    'budget': implementation['budget']}, 'contract_hash', 'contract_json')
                revision = {'protocol': 'instrument_method_recipe_v1', 'recipe_key': key, 'revision': 1,
                    'method_hashes': [method_hash], 'method_contracts': {method_hash: method},
                    'context_predicate': context, 'horizon': 'hour', 'hybrid': False,
                    'research_only': True, 'qualification_granted': False, 'promotion_evidence': False}
                recipes.append(sealed({'protocol': 'instrument_method_recipe_runtime_v1', 'recipe_key': key,
                    'recipe_hash': canonical_hash(revision), 'recipe_revision': revision,
                    'recipe_revision_json': canonical_json(revision), 'context_predicate': context,
                    'operators': [operator], 'parameter_adapters': [], 'scope_bindings': bindings}, 'runtime_hash', 'runtime_json'))
    library = {'protocol': 'instrument_method_library_v1', 'instrument_inventory_hash': 'a' * 64,
        'method_hashes': sorted(key for recipe in recipes for key in recipe['recipe_revision']['method_hashes']),
        'recipe_hashes': sorted(recipe['recipe_hash'] for recipe in recipes)}
    rules = [{'context_predicate': {'regime': regime, 'volatility': volatility, 'session': ['london', 'overlap']},
        'preferred_recipes': [recipe['recipe_hash'] for recipe in recipes
            if recipe['context_predicate']['regime'] == regime and recipe['context_predicate']['volatility'] == volatility]}
        for regime in ('range', 'trend_up', 'trend_down') for volatility in ('low', 'normal', 'high')]
    policy = sealed({'protocol': 'agent_method_policy_v1', 'agent_key': evidence.AGENT_KEY, 'revision': 1,
        'library_contract': library, 'library_hash': canonical_hash(library), 'rules': rules,
        'default_action': 'BASELINE'}, 'policy_hash', 'policy_json')
    pool = {'recipes': sorted([{'recipe_hash': row['recipe_hash'], 'runtime_hash': row['runtime_hash']}
                              for row in recipes], key=lambda row: row['recipe_hash'])}
    return sealed({'protocol': 'instrument_method_learning_v1', 'data_hash': request.replay_dataset_hash,
        'execution_hash': execution, 'expected_agent_key': evidence.AGENT_KEY,
        'scope': {'symbol': request.symbol, 'timeframe': request.timeframe,
                  'evaluated_start': frame.time.iloc[0].isoformat(), 'evaluated_end': frame.time.iloc[-1].isoformat(), 'context': {}},
        'library_contract': library, 'library_contract_json': canonical_json(library), 'library_hash': canonical_hash(library),
        'compiled_pool_hash': canonical_hash(pool), 'recipes': recipes, 'policy': policy}, 'contract_hash', 'contract_json')


class CuratorBollingerRecipeEvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory()
        path = Path(cls.temporary.name) / 'synthetic.csv'
        index = np.arange(280, dtype=float)
        close = 100.0 + .02 * np.sin(index / 3.0)
        close[210:214] = [99., 99.7, 99.7, 100.1]
        close[230:234] = [101., 100.3, 100.3, 99.9]
        frame = pd.DataFrame({'time': pd.date_range('2025-01-06T17:20:00Z', periods=len(close), freq='5min'),
            'open': close, 'high': close + .05, 'low': close - .05, 'close': close,
            'volume': 0., 'volume_available': False})
        frame.to_csv(path, index=False)
        identity = hashlib.sha256(path.read_bytes()).hexdigest()
        request = SimpleBacktestRequest(strategy='session_mean_reversion_v1', base_strategy='session_mean_reversion',
            timeframe='M5', dataset_path=str(path), replay_dataset_hash=identity,
            parameters={'session_start': 7, 'session_end': 14, 'lookback': 20, 'session_band_variance': 'population',
                'session_context_gate': 'none', 'minimum_signal_confidence': .35, 'time_stop_candles': 6,
                'volume_lane': 'none', 'trailing_atr_multiplier': 0., 'partial_take_profit_fraction': 0.,
                'atr_stop_multiplier': 1.5, 'atr_target_multiplier': 2.5},
            execution=ExecutionConfig(allowed_sessions_utc=['7-14'], spread_points=1., slippage_points=.1,
                commission_percent=.001, swap_per_day_percent=.002), emit_decision_trace=True, emit_trade_ledger=True)
        request.policy_context = {'instrument_method_learning': synthetic_contract(request, frame)}
        with capture_management_witness() as observer:
            response = backtester.run_simple_ema_rsi_backtest_on_dataframe(request, backtester._load_simple_candles(request),
                lightweight=True, include_differential_pair=False)
        cls.body, cls.witness, cls.request = response.model_dump(), observer.snapshot(), request.model_dump()
        cls.window = {'path': str(path), 'sha256': identity, 'loaded_rows': len(frame), 'evaluated_rows': len(frame) - 200}
        cls.declaration = {'window': 'synthetic', 'arm': 'population_midline', 'request': cls.request,
            'request_sha256': canonical_hash(cls.request), 'cost_sha256': execution_contract_metadata(request)['execution_hash']}
        cls.envelope = {'protocol': 'curator_scalp_full_close_envelope_v1', 'case_key': 'synthetic__population_midline',
            'request_sha256': cls.declaration['request_sha256'], 'response_sha256': 'r' * 64,
            'full_close_witness': cls.witness}

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def verify(self, body=None, witness=None):
        return evidence._verify_method_evidence(body or self.body, self.request, self.window, witness or self.witness)

    def test_real_original_owner_full_close_clock_and_complete_method_receipt(self):
        physical_result = physical.verify_physical_response(self.body, self.declaration, self.window, self.envelope, {}, 'r' * 64)
        result = self.verify()
        self.assertGreater(physical_result['mature'], 0)
        self.assertGreater(result['event_count'], 0)
        self.assertGreater(result['exit_requested'], 0)
        self.assertGreater(result['method_full_closes'], 0)
        self.assertTrue(result['complete_call_schedule_rederived'])
        self.assertEqual(result['policy_key'], evidence.AGENT_KEY)
        self.assertEqual(result['receipt_hash'], self.body['data_quality']['instrument_method_learning_receipt']['receipt_hash'])

    def test_legacy_verifier_never_delegates_method_events_or_any_receipt(self):
        for body in ({'decision_trace': [{'event_type': 'instrument_method_decision'}], 'data_quality': {}},
                     *({'decision_trace': [], 'data_quality': {'instrument_method_learning_receipt': value}}
                       for value in (None, {}, [], {'receipt_hash': 'x'}))):
            with self.subTest(body=body), patch.object(physical, 'verify_physical_response') as verify:
                with self.assertRaisesRegex(RuntimeError, 'NO_AGENT_METHOD_SELECTION_ALLOWED'):
                    physical.verify_response(body, {}, {}, {}, {}, '')
                verify.assert_not_called()

    def test_legacy_method_free_wrapper_still_delegates_identical_arguments(self):
        body = {'decision_trace': [], 'data_quality': {}}
        args = (body, {}, {}, {}, {}, 'raw')
        with patch.object(physical, 'verify_physical_response', return_value={'same': True}) as verify:
            self.assertEqual(physical.verify_response(*args), {'same': True})
            verify.assert_called_once_with(*args)

    def test_omitted_and_duplicate_method_calls_cannot_hide_behind_counters(self):
        for changed in ('omit', 'duplicate'):
            body = copy.deepcopy(self.body)
            index = next(index for index, row in enumerate(body['decision_trace']) if row['event_type'] == 'instrument_method_decision')
            if changed == 'omit':
                body['decision_trace'].pop(index)
            else:
                body['decision_trace'].insert(index, copy.deepcopy(body['decision_trace'][index]))
            body['data_quality']['decision_trace']['event_count'] = len(body['decision_trace'])
            with self.subTest(changed=changed), self.assertRaisesRegex(RuntimeError, 'COMPLETE_ORIGINAL_METHOD_CALL_SCHEDULE'):
                self.verify(body)

    def test_event_direction_timing_outputs_and_hash_are_independently_rederived(self):
        for changed in ('context', 'time', 'output', 'hash'):
            body = copy.deepcopy(self.body)
            row = next(row['method_learning'] for row in body['decision_trace'] if row['event_type'] == 'instrument_method_decision')
            if changed == 'context': row['context']['direction'] = 'sell' if row['context']['direction'] != 'sell' else 'buy'
            elif changed == 'time': row['observed_at'] = '2025-01-01T00:00:00Z'
            elif changed == 'output': row['outputs'] = {'exit': True}
            else: row['receipt_hash'] = 'f' * 64
            with self.subTest(changed=changed), self.assertRaisesRegex(RuntimeError, 'ORIGINAL_METHOD_EVENT_REDERIVATION'):
                self.verify(body)

    def test_self_consistent_but_false_aggregate_receipt_is_rejected(self):
        body = copy.deepcopy(self.body)
        receipt = body['data_quality']['instrument_method_learning_receipt']
        raw = {key: value for key, value in receipt.items() if key not in ('receipt_hash', 'receipt_json')}
        raw['events_hash'] = 'f' * 64
        body['data_quality']['instrument_method_learning_receipt'] = sealed(raw, 'receipt_hash', 'receipt_json')
        with self.assertRaisesRegex(RuntimeError, 'COMPLETE_ORIGINAL_METHOD_RECEIPT_REDERIVATION'):
            self.verify(body)

    def test_event_ledger_method_count_is_not_an_optional_claim(self):
        body = copy.deepcopy(self.body)
        body['event_ledger_categories']['method_learning'] = 0
        with self.assertRaisesRegex(RuntimeError, 'ORIGINAL_METHOD_EVENT_LEDGER'):
            self.verify(body)

    def test_new_public_verifier_requires_code_owned_sealed_adapter_validation(self):
        with patch('scripts.curator_bollinger_recipe.validate', side_effect=RuntimeError('sealed hash rejected')), \
                patch.object(physical, 'verify_physical_response') as verify:
            with self.assertRaisesRegex(RuntimeError, 'sealed hash rejected'):
                evidence.verify_response({}, {}, {}, {}, {}, '')
            verify.assert_not_called()

    def test_schedule_preserves_time_stop_precedence_and_eof_unknown_exposure(self):
        times = pd.date_range('2025-01-06T00:00:00Z', periods=208, freq='5min')
        frame = pd.DataFrame({'time': times, 'signal': ['BUY'] * len(times)})
        entry = {'entry_time': times[201].isoformat(), 'direction': 'BUY'}
        close = {'entry_time': times[201].isoformat(), 'exit_time': times[207].isoformat(),
                 'direction': 'BUY', 'exit_reason': 'time_stop'}
        witness = {'entry_indices': [200], 'entry_events': {'200': entry}, 'closed_events': [close]}
        calls, notifications, _ = evidence._call_schedule(frame, witness, 6)
        self.assertEqual(sorted(calls), list(range(201, 207)))
        self.assertEqual(calls[201], ('entry', 'BUY', None))
        self.assertNotIn(207, calls)
        self.assertIn(207, notifications)
        witness['closed_events'] = []
        with self.assertRaisesRegex(RuntimeError, 'ORIGINAL_METHOD_TIME_STOP_SCOPE'):
            evidence._call_schedule(frame, witness, 6)
        calls, notifications, _ = evidence._call_schedule(frame, witness, 0)
        self.assertEqual(calls[207], ('exit', 'BUY', 201))
        self.assertEqual(notifications, {})
        empty = {'entry_indices': [], 'entry_events': {}, 'closed_events': []}
        self.assertEqual(evidence._call_schedule(frame, empty, 6)[:2], ({}, {}))

    def test_valid_other_spec_cannot_authorize_an_unlisted_caller_declaration(self):
        declaration = {'window': 'october2025', 'arm': 'population_midline'}
        with patch('scripts.curator_bollinger_recipe.validate'), patch.object(physical, 'verify_physical_response') as verify:
            with self.assertRaisesRegex(RuntimeError, 'SEALED_CURATOR_RECIPE_DECLARATION'):
                evidence.verify_response({}, declaration, {}, {}, {'requests': {}, 'windows': {}}, '')
            verify.assert_not_called()

    def test_resealed_but_undeclared_wait_policy_is_not_a_midline_exit_recipe(self):
        request = copy.deepcopy(self.request)
        contract = request['policy_context']['instrument_method_learning']
        body = {key: value for key, value in contract.items() if key not in ('contract_hash', 'contract_json')}
        policy = {key: value for key, value in body['policy'].items() if key not in ('policy_hash', 'policy_json')}
        policy['default_action'] = 'WAIT'
        body['policy'] = sealed(policy, 'policy_hash', 'policy_json')
        request['policy_context']['instrument_method_learning'] = sealed(body, 'contract_hash', 'contract_json')
        with self.assertRaisesRegex(RuntimeError, 'ONLY_CURATOR_MIDLINE_POLICY_ALLOWED'):
            evidence._midline_owner(request)


class ActualPhpMidlinePolicyAdmissionTest(unittest.TestCase):
    def test_existing_actual_php_compiled_policy_is_admitted_without_trade_replay(self):
        path = physical.ROOT / '.runtime/bollinger-recipe-draft-20261016.json'
        if not path.is_file():
            self.skipTest('Optional local pre-outcome actual PHP draft is absent')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         '3f97939a7e0a459ce6fa21e66ef84788be6f29952133d41765136778a237855e')
        draft = json.loads(path.read_bytes())
        declaration = draft['requests']['october2025__population_midline']
        physical.verify_wire(declaration)
        request = declaration['request']
        expected = '48395ad2549a0a178b17d639f830b3c908bbf9cbe1308a0934dbb52a9997a9ff'
        self.assertEqual(request['policy_context']['instrument_method_learning']['contract_hash'], expected)
        _, runtime = evidence._midline_owner(request)
        self.assertEqual(runtime.contract_hash, expected)
        self.assertEqual(len(runtime.recipes), 18)
        self.assertEqual(runtime.policy['agent_key'], evidence.AGENT_KEY)


if __name__ == '__main__':
    unittest.main()
