"""Software fixtures for exact optional context coordinates at the method gate."""

import copy
import unittest

from app.schemas import SimpleBacktestRequest
from app.services.instrument_method_learning import InstrumentMethodLearning
from app.services.research_program_tasks import canonical_hash, canonical_json
from app.services.specialist_council import seal_contract
from test_instrument_method_learning import pinned_recipe, learning_contract, const
from test_specialist_council import fixtures, member


def context_fixture():
    _, frame = fixtures([member('hour')])
    request = SimpleBacktestRequest(timeframe='H1', replay_dataset_hash='context-software-fixture')
    original = pinned_recipe(request, 'exact-context-fixture', {'confirmation': const(False)})
    context = {**original['context_predicate'], 'session_instance_id': 'Opaque-Session-A',
        'calendar_version': 'Calendar-Revision-A'}
    revision = copy.deepcopy(original['recipe_revision'])
    methods = {}
    for source in revision['method_contracts'].values():
        source['context_predicate'] = context
        methods[canonical_hash(source)] = source
    revision.update(context_predicate=context, method_contracts=dict(sorted(methods.items())), method_hashes=sorted(methods))
    body = {key: value for key, value in original.items() if key not in {'runtime_hash', 'runtime_json'}}
    body.update(recipe_revision=revision, recipe_revision_json=canonical_json(revision),
        recipe_hash=canonical_hash(revision), context_predicate=context)
    body['scope_bindings'] = {**body['scope_bindings'], 'context_hash': canonical_hash(context)}
    body['operators'] = [seal_contract({**operator, 'source_context': body['scope_bindings']})
                         for operator in body['operators']]
    recipe = {**body, 'runtime_hash': canonical_hash(body), 'runtime_json': canonical_json(body)}
    contract = learning_contract(request, frame, [recipe])
    return InstrumentMethodLearning(contract, request)


class InstrumentMethodContextContractTest(unittest.TestCase):
    def evaluate(self, runtime, **coordinates):
        row = {'market_regime': 'trend_up', 'market_session': 'london', 'volatility_regime': 'normal_volatility',
            **coordinates}
        return runtime.evaluate(row, decision_id='software-context-decision', phase='entry', direction='BUY',
            decision_at='2025-01-06T01:00:00Z', observed_at='2025-01-06T01:00:00Z')

    def test_exact_observed_session_instance_and_calendar_version_select_and_veto(self):
        event = self.evaluate(context_fixture(), market_session_instance_id='Opaque-Session-A',
            market_session_calendar_version='Calendar-Revision-A')
        self.assertTrue(event['used'])
        self.assertEqual(event['outputs'], {'confirmation': False})
        self.assertTrue(event['behavior_changed'])
        self.assertEqual(event['context']['session_instance_id'], 'Opaque-Session-A')
        self.assertEqual(event['context']['calendar_version'], 'Calendar-Revision-A')
        self.assertFalse(event['economic_authority'])

    def test_missing_or_different_coordinates_never_manufacture_a_matching_recipe(self):
        for coordinates in ({}, {'market_session_instance_id': 'Opaque-Session-A'},
                {'market_session_instance_id': 'opaque-session-a', 'market_session_calendar_version': 'Calendar-Revision-A'},
                {'market_session_instance_id': 'Opaque-Session-A', 'market_session_calendar_version': 'Calendar-Revision-B'}):
            with self.subTest(coordinates=coordinates):
                event = self.evaluate(context_fixture(), **coordinates)
                self.assertFalse(event['used'])
                self.assertIsNone(event['recipe_hash'])
                self.assertFalse(event['behavior_changed'])
                self.assertEqual(event['reason'], 'policy_default_baseline')


if __name__ == '__main__':
    unittest.main()
