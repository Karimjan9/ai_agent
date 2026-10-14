"""Real method input/consumer guards on synthetic candles, not profit evidence."""

import copy
import json
import unittest
from pathlib import Path
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.instrument_method_learning import InstrumentMethodLearning
from test_instrument_method_learning import learning_contract, pinned_recipe, strategy


DECLARATION = json.loads((Path(__file__).resolve().parents[2]
    / 'backend-laravel/resources/research/instrument-methods/observed-quote-firewall/method.json').read_text())


def frame(count=224, spread=None):
    data = pd.DataFrame({'time': pd.date_range('2025-01-06', periods=count, freq='5min', tz='UTC'),
        'open': 100., 'high': 101., 'low': 99., 'close': 100., 'volume': 100., 'volume_available': True})
    if spread is not None:
        data['spread_available'] = True
        data['bid_close'] = data['close']
        data['ask_close'] = data['close'] + spread
        data['bid_ask_spread'] = spread
        data['spread'] = spread
        data['quote_age_ms'] = 60000.
        data['quote_time_utc'] = data['time'] + pd.Timedelta(minutes=4)
        data['quote_available_after_utc'] = data['time'] + pd.Timedelta(minutes=5)
    return data


def request(**execution):
    return SimpleBacktestRequest(strategy='ema_rsi_v1', base_strategy='ema_rsi', timeframe='M5',
        replay_dataset_hash='synthetic-observed-quote-fixture', emit_decision_trace=True,
        parameters={'time_stop_candles': 3},
        execution=ExecutionConfig(stop_loss_percent=5, take_profit_percent=5,
            spread_points=2, slippage_points=1, point_size=.01, commission_percent=.1, **execution))


def contract(payload, data):
    implementation = DECLARATION['implementation']
    recipe = pinned_recipe(payload, 'observed-quote-fixture',
        {'confirmation': copy.deepcopy(implementation['ast'])}, copy.deepcopy(implementation['input_bindings']))
    return learning_contract(payload, data, [recipe])


class ObservedQuoteMethodTest(unittest.TestCase):
    def decision(self, data, index=20, injected=None):
        payload = request()
        data = strategy(data, {})
        runtime = InstrumentMethodLearning(contract(payload, data), payload)
        runtime.validate_frame(data)
        row = data.iloc[index].to_dict()
        row.update(injected or {})
        stamp = (row['time'] + pd.Timedelta(minutes=5)).isoformat()
        return runtime.evaluate(row, decision_id='quote-fixture', phase='entry',
            decision_at=stamp, observed_at=stamp, direction='BUY')

    def test_missing_observed_quote_rejects_injected_method_values_and_modeled_spread(self):
        event = self.decision(frame(24), injected={
            'method_quote_available': True, 'method_spread_atr': 0., 'spread_points': 0.})
        self.assertIs(event['outputs']['confirmation'], False)
        self.assertEqual(event['reason'], 'method_observed_quote_unavailable')
        self.assertFalse(event['feature_availability']['method_quote_available'])
        self.assertFalse(event['economic_authority'])

    def test_quote_provenance_and_strict_threshold_are_checked_by_actual_feature_owner(self):
        narrow = frame(24, .10)
        self.assertIs(self.decision(narrow)['outputs']['confirmation'], True)
        for spread in (.20, .40):
            self.assertIs(self.decision(frame(24, spread))['outputs']['confirmation'], False)
        mutations = {
            'missing_flag': ('spread_available', False),
            'ask_mismatch': ('ask_close', 101.),
            'bid_mismatch': ('bid_close', 99.),
            'spread_mismatch': ('bid_ask_spread', .05),
            'negative_spread': ('bid_ask_spread', -.1),
            'future_quote': ('quote_time_utc', narrow.iloc[20]['time'] + pd.Timedelta(minutes=6)),
            'premature_availability': ('quote_available_after_utc', narrow.iloc[20]['time'] + pd.Timedelta(minutes=4)),
        }
        for name, (column, value) in mutations.items():
            with self.subTest(name=name):
                changed = narrow.copy()
                changed.loc[20, column] = value
                self.assertIs(self.decision(changed)['outputs']['confirmation'], False)
        self.assertIs(self.decision(narrow, index=12)['outputs']['confirmation'], False)
        zero = narrow.copy()
        zero['high'] = zero['low'] = zero['close']
        self.assertIs(self.decision(zero)['outputs']['confirmation'], False)

    def replay(self, data, *, with_method=True, **execution):
        payload = request(**execution)
        before = payload.execution.model_dump()
        execution_hash = execution_contract_metadata(payload)['execution_hash']
        if with_method:
            payload.policy_context['instrument_method_learning'] = contract(payload, data)
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            result = run_simple_ema_rsi_backtest_on_dataframe(payload, data)
        self.assertEqual(before, payload.execution.model_dump())
        self.assertEqual(execution_hash, execution_contract_metadata(payload)['execution_hash'])
        return result

    def test_original_replay_missing_and_wide_quotes_wait_while_narrow_preserves_exact_cost_result(self):
        narrow = frame(spread=.10)
        baseline = self.replay(narrow, with_method=False)
        allowed = self.replay(narrow)
        self.assertGreater(allowed.total_trades, 0)
        self.assertEqual(baseline.total_trades, allowed.total_trades)
        self.assertEqual(baseline.final_balance, allowed.final_balance)
        self.assertEqual(baseline.trades, allowed.trades)
        receipt = allowed.data_quality['instrument_method_learning_receipt']
        self.assertGreater(receipt['used_count'], 0)
        self.assertFalse(receipt['economic_authority'])
        for data in (frame(), frame(spread=.20), frame(spread=.40)):
            blocked = self.replay(data)
            self.assertEqual(blocked.total_trades, 0)
            self.assertGreater(blocked.data_quality['instrument_method_learning_receipt']['behavior_changed_count'], 0)
            self.assertEqual(baseline.data_quality['replay_executed_clock']['schedule_hash'],
                blocked.data_quality['replay_executed_clock']['schedule_hash'])

    def test_existing_runtime_confirmation_still_vetoes_without_fabricated_method_invocations(self):
        result = self.replay(frame(spread=.10), min_volume=500.)
        self.assertEqual(result.total_trades, 0)
        self.assertEqual(result.data_quality['instrument_method_learning_receipt']['event_count'], 0)


if __name__ == '__main__':
    unittest.main()
