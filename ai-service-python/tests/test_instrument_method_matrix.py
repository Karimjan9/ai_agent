"""Bounded software fixtures; none of these inputs is market evidence."""

import copy
import importlib.util
import math
from pathlib import Path
import tempfile
import unittest

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.research_program_tasks import canonical_hash, canonical_json
from test_instrument_method_learning import pinned_recipe, learning_contract, const

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/backtest-instrument-method-matrix.py'
MODULE_SPEC = importlib.util.spec_from_file_location('instrument_method_matrix', SCRIPT)
matrix = importlib.util.module_from_spec(MODULE_SPEC)
MODULE_SPEC.loader.exec_module(matrix)


def fixture(directory, rows=224):
    frame = pd.DataFrame({'time': pd.date_range('2025-01-06', periods=rows, freq='5min', tz='UTC'),
        'close': [100 + math.sin(index / 3) for index in range(rows)],
        'volume': 100.0, 'volume_available': True})
    frame['open'] = frame['close'].shift(1).fillna(frame.iloc[0]['close'])
    frame['high'] = frame[['open', 'close']].max(axis=1) + 0.1
    frame['low'] = frame[['open', 'close']].min(axis=1) - 0.1
    csv_path = Path(directory) / 'synthetic-software-fixture.csv'
    frame.to_csv(csv_path, index=False)
    sha = matrix.sha_file(csv_path)
    base = SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='M5',
        parameters={'ema_fast': 2, 'ema_slow': 5, 'rsi_period': 2, 'rsi_buy_min': 0.0,
            'rsi_buy_max': 100.0, 'rsi_sell_min': 0.0, 'rsi_sell_max': 100.0, 'time_stop_candles': 3},
        replay_dataset_hash=sha, emit_decision_trace=True, emit_trade_ledger=True,
        execution=ExecutionConfig(stop_loss_percent=1, take_profit_percent=2, commission_percent=0.1))
    allow = pinned_recipe(base, 'fixture-allow', {'confirmation': const(True)})
    veto = pinned_recipe(base, 'fixture-veto', {'confirmation': const(False)})
    arms = {}
    for arm, recipes, preferred, agent in (
            ('L1A1', [allow], 0, 'fixture-A1'), ('L2A1', [allow, veto], 0, 'fixture-A1'),
            ('L1A2', [allow], 0, 'fixture-A2'), ('L2A2', [allow, veto], 1, 'fixture-A2')):
        request = base.model_copy(deep=True)
        request.policy_context['instrument_method_learning'] = learning_contract(request, frame, recipes,
            preferred=preferred, agent=agent)
        arms[arm] = {'request': request.model_dump(mode='json')}
    body = {'protocol': matrix.PROTOCOL, 'spec_key': 'synthetic-software-fixture-not-market-evidence',
        'registered_at': '2026-10-01T00:00:00Z', 'minimum_trades': 10, 'per_arm_timeout_seconds': 60,
        'source_kind': 'synthetic_software_fixture', 'data': {'path': str(csv_path), 'sha256': sha,
            'start': frame.iloc[0]['time'].isoformat(), 'end': frame.iloc[-1]['time'].isoformat(),
            'warmup_rows': 200, 'max_rows': rows, 'selected_rows': rows}, 'arms': arms}
    spec = {**body, 'spec_hash': canonical_hash(body), 'spec_json': canonical_json(body)}
    spec_path = Path(directory) / 'spec.json'
    matrix.write_new(spec_path, spec)
    return spec, spec_path, frame


class InstrumentMethodMatrixTest(unittest.TestCase):
    def test_archive_reopens_exact_bounded_original_rows_and_rejects_hash_drift(self):
        with tempfile.TemporaryDirectory() as directory:
            spec, _, frame = fixture(directory)
            actual, identity = matrix.load_archive(spec['data'])
            self.assertEqual(len(actual), len(frame))
            self.assertEqual(identity['source_sha256'], spec['data']['sha256'])
            broken = {**spec['data'], 'sha256': '0' * 64}
            with self.assertRaisesRegex(ValueError, 'SHA256_MISMATCH'):
                matrix.load_archive(broken)
            with self.assertRaisesRegex(ValueError, 'ROW_BOUND'):
                matrix.load_archive({**spec['data'], 'max_rows': 4097})
            with self.assertRaisesRegex(ValueError, 'EXACT_ARCHIVE_SLICE'):
                matrix.load_archive({**spec['data'], 'start': '2025-01-06T00:00:01Z'})

    def test_source_post_2026_and_unsealed_or_drifted_requests_reject(self):
        with tempfile.TemporaryDirectory() as directory:
            spec, spec_path, frame = fixture(directory)
            parsed, _ = matrix.read_spec(spec_path)
            matrix.validate_requests(parsed)
            drift = copy.deepcopy(parsed)
            drift['arms']['L2A2']['request']['risk_per_trade'] = 0.1
            with self.assertRaisesRegex(ValueError, 'BASELINE_ACCOUNT_STRATEGY_COST_OR_RISK_DRIFT'):
                matrix.validate_requests(drift)
            frame.loc[len(frame) - 1, 'time'] = pd.Timestamp('2026-01-01T00:00:00Z')
            frame.to_csv(spec['data']['path'], index=False)
            data = {**spec['data'], 'sha256': matrix.sha_file(spec['data']['path'])}
            with self.assertRaisesRegex(ValueError, 'POST_2026'):
                matrix.load_archive(data)

    def test_partial_or_unequal_clocks_never_produce_a_comparison(self):
        spec = {'spec_key': 'software-fixture', 'spec_hash': 'fixture', 'minimum_trades': 1}
        partial = {'L1A1': {'status': 'computed'}}
        summary = matrix.summarize(spec, partial, {})
        self.assertEqual(summary['status'], 'incomplete_no_comparison')
        self.assertNotIn('conditional_comparisons', summary)
        observations = {arm: {'status': 'computed', 'actual_clock': {'schedule_hash': arm}}
                        for arm in matrix.ARMS}
        summary = matrix.summarize(spec, observations, {})
        self.assertEqual(summary['status'], 'unequal_actual_clocks_no_comparison')
        self.assertNotIn('conditional_comparisons', summary)

    def test_underpower_and_interaction_are_diagnostic_and_never_authority(self):
        spec = {'spec_key': 'software-fixture', 'spec_hash': 'fixture', 'minimum_trades': 2,
            'source_kind': 'synthetic_software_fixture'}
        observations = {}
        for arm, net in zip(matrix.ARMS, (1.0, 3.0, 2.0, 8.0)):
            observations[arm] = {'status': 'computed', 'actual_clock': {'schedule_hash': 'same'},
                'net_pnl': net, 'total_trades': 1, 'method_used_count': 1,
                'method_behavior_changed_count': 0, 'entry_veto_count': 0}
        summary = matrix.summarize(spec, observations, {})
        self.assertEqual(summary['interaction']['net_pnl_delta'], 4.0)
        self.assertEqual(summary['interaction']['classification'], 'underpowered')
        for observation in observations.values():
            observation['total_trades'] = 2
        summary = matrix.summarize(spec, observations, {})
        self.assertEqual(summary['interaction']['classification'], 'positive_diagnostic')
        self.assertFalse(summary['economic_authority'])
        self.assertFalse(summary['independent_validation'])
        self.assertFalse(summary['credit_granted'])
        self.assertEqual(summary['source_kind'], 'synthetic_software_fixture')
        self.assertEqual(summary['policy_level_semantics']['A2'],
            'preregistered_candidate_selector_class_not_proven_learned_master_policy')

    def test_four_independent_real_executor_processes_retain_original_software_fixture_products(self):
        with tempfile.TemporaryDirectory() as directory:
            _, spec_path, _ = fixture(directory)
            output = Path(directory) / 'new-output'
            summary = matrix.run_matrix(spec_path, output)
            self.assertEqual(summary['status'], 'underpowered_local_observation')
            self.assertEqual(set(summary['observations']), set(matrix.ARMS))
            self.assertTrue((output / 'before-outcome.snapshot.json').is_file())
            for arm, observation in summary['observations'].items():
                self.assertEqual(observation['status'], 'computed')
                self.assertEqual(observation['response_sha256'], matrix.sha_file(output / (arm + '.response.json')))
                self.assertEqual(observation['actual_clock']['decision_rows'], 24)
                self.assertFalse(observation['economic_authority'])
            with self.assertRaisesRegex(ValueError, 'DIRECTORY_MUST_BE_NEW'):
                matrix.run_matrix(spec_path, output)


if __name__ == '__main__':
    unittest.main()
