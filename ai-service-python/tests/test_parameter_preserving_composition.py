import importlib.util
from pathlib import Path
from unittest.mock import patch

import app
import pandas as pd
import pytest

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.composition_runtime import (
    CompositionRuntimeContractError, _hash, _parameter_preserving_management_adapter,
    _runtime_signal_regimes, effective_management_parameters, validate_composition_runtime_contract,
)
from app.services.execution_contract import execution_contract_metadata

# Reuse existing strict manifest fixtures; no production seal or data alias.
spec = importlib.util.spec_from_file_location('composition_existing_test_helpers', Path(app.__file__).resolve().parents[1] / 'tests' / 'test_composition_runtime.py')
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)


def preserving_contract(execution_hash=helpers.EXECUTION_HASH):
    assignment = helpers._assignment()
    assignment['source_components']['management_id'] = 'parameter_preserving_research'
    assignment['assignment_hash'] = _hash({key: value for key, value in assignment.items() if key != 'assignment_hash'})
    contract = helpers._contract(execution_hash=execution_hash, assignment=assignment)
    adapter = _parameter_preserving_management_adapter()
    contract['components']['management_id'] = adapter['profile']
    contract['runtime_bindings']['management'] = {'bound': True, 'profile': adapter['profile'], 'adapter': adapter}
    contract['management_contract'] = {'protocol': 'trade_management_library_v1', 'profile': adapter['profile'], 'runtime_adapter': adapter}
    contract['contract_hash'] = _hash({key: value for key, value in contract.items() if key != 'contract_hash'})
    return contract, assignment


def test_parameter_owned_adapter_preserves_source_economics_and_management_mutations():
    contract, assignment = preserving_contract()
    original = {'atr_stop_multiplier': 1.5, 'atr_target_multiplier': 2.5,
        'partial_take_profit_fraction': 0.0, 'partial_target_atr_multiplier': 1.0,
        'trailing_atr_multiplier': 0.0, 'time_stop_candles': 0}
    for changed in ({}, {'time_stop_candles': 5}, {'partial_take_profit_fraction': .25}, {'trailing_atr_multiplier': .6}):
        values = {**original, **changed}
        validate_composition_runtime_contract(contract, base_strategy='trend_v1', parameters=values,
            execution_timeframe='H1', runtime_authority=helpers._runtime_authority(assignment=assignment))
        effective = effective_management_parameters(contract, values)
        assert all(effective[key] == value for key, value in values.items())
        assert 'composition_final_target_r' not in effective


@pytest.mark.parametrize('tamper', [
    {'engine': 'fabricated_manager'}, {'profile': 'arbitrary_custom_profile'},
    {'overrides': {'time_stop_candles': 24}}, {'final_target_r': 2.0},
])
def test_rehashed_noncanonical_research_manager_still_fails(tamper):
    contract, assignment = preserving_contract()
    contract['runtime_bindings']['management']['adapter'].update(tamper)
    contract['management_contract']['runtime_adapter'] = contract['runtime_bindings']['management']['adapter']
    contract['contract_hash'] = _hash({key: value for key, value in contract.items() if key != 'contract_hash'})
    with pytest.raises(CompositionRuntimeContractError, match='COMPOSITION_MANAGEMENT_NOT_BOUND'):
        validate_composition_runtime_contract(contract, base_strategy='trend_v1', parameters={'atr_stop_multiplier': 1.5},
            execution_timeframe='H1', runtime_authority=helpers._runtime_authority(assignment=assignment))


def test_confirmation_runtime_scope_is_capability_metadata_not_a_new_signal_rule():
    assert _runtime_signal_regimes('confirmation_entry_mtf_v1') == (
        'trend_up', 'trend_down', 'range', 'unknown', 'transition', 'high_volatility', 'low_volatility')
    from app.strategies.registry import get_strategy
    assert callable(get_strategy('confirmation_entry_mtf_v1'))


def test_parameter_preserving_adapter_uses_real_position_lifecycle_and_does_not_mask_time_stop():
    prices = [100.0 + (index % 10) * .01 for index in range(230)]
    frame = pd.DataFrame({'time': pd.date_range('2025-01-01', periods=230, freq='h', tz='UTC'),
        'open': prices, 'high': [p + .4 for p in prices], 'low': [p - .4 for p in prices], 'close': prices, 'volume': [1000.] * 230})
    # The baseline legitimately closes at its raw-parameter target; the
    # variant closes earlier via its time stop. No risk gate is relaxed.
    frame.loc[229, ['high', 'close']] = [103.0, 102.5]
    execution = ExecutionConfig(stop_loss_percent=.5, take_profit_percent=1., max_leverage=5)
    execution_hash = execution_contract_metadata(SimpleBacktestRequest(execution=execution))['execution_hash']
    contract, assignment = preserving_contract(execution_hash)
    base = {'atr_stop_multiplier': 1.5, 'atr_target_multiplier': 2.5,
        'partial_take_profit_fraction': 0., 'partial_target_atr_multiplier': 1.,
        'trailing_atr_multiplier': 0., 'time_stop_candles': 0}

    def strategy(source, _parameters):
        result = source.copy()
        result['signal'] = 'WAIT'
        result['signal_confidence'] = 1.
        result['market_regime'] = 'trend_up'
        result.loc[199, 'signal'] = 'BUY'
        return result

    results = []
    for stop in (0, 5):
        payload = SimpleBacktestRequest(symbol='XAUUSD', timeframe='H1', strategy='parameter_replay_fixture', base_strategy='trend_v1',
            parameters={**base, 'time_stop_candles': stop}, replay_dataset_hash=helpers.DATASET_HASH,
            instrument_research_assignment=assignment, composition_runtime_contract=contract, execution=execution)
        with patch('app.services.backtester.get_strategy', return_value=strategy):
            results.append(run_simple_ema_rsi_backtest_on_dataframe(payload, frame))
    for result in results:
        assert result.total_trades == 1, repr(result.entry_funnel)
        assert next(node for node in result.composition_runtime_receipt['nodes'] if node['module'] == 'management_policy')['observed'] is True
    receipts = [result.data_quality['decision_identity_receipt'] for result in results]
    assert receipts[0]['stage_identities']['entry'] == receipts[1]['stage_identities']['entry']
    assert receipts[0]['stage_identities']['closed_trade'] != receipts[1]['stage_identities']['closed_trade']
