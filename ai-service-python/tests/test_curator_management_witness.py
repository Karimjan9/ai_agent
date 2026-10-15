"""Synthetic offline observer parity and hostile-witness checks; no market evidence."""
import copy
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

from app.services import backtester
from test_source_grounded_method_runtime import software_frame, software_request

_SPEC = importlib.util.spec_from_file_location('curator_management_witness',
    Path(__file__).resolve().parents[1] / 'scripts/curator_management_witness.py')
witness_owner = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(witness_owner)


class CuratorManagementWitnessTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original_function = backtester._decision_identity_receipt
        cls.baseline = backtester.run_simple_ema_rsi_backtest_on_dataframe(
            software_request(), software_frame(), include_differential_pair=False, lightweight=True).model_dump()
        with witness_owner.capture_management_witness() as observer:
            cls.observed = backtester.run_simple_ema_rsi_backtest_on_dataframe(
                software_request(), software_frame(), include_differential_pair=False, lightweight=True).model_dump()
        cls.witness = observer.snapshot()
        cls.verified = witness_owner.verify_management_witness(cls.observed, cls.witness)

    def fixture(self):
        return copy.deepcopy(self.observed), copy.deepcopy(self.witness)

    def reseal(self, body, witness):
        # Stronger tamper tests rebuild hashes, so independent financial checks
        # must reject coherent-looking forged stages rather than a stale digest.
        stages, _ = witness_owner._stage_identities(witness)
        receipt = copy.deepcopy(witness['original_receipt'])
        receipt['stage_identities'].update(stages)
        receipt['receipt_hash'] = witness_owner._hash({key: value for key, value in receipt.items() if key != 'receipt_hash'})
        witness['original_receipt'] = receipt
        body['data_quality']['decision_identity_receipt'] = receipt
        body['trade_ledger'] = copy.deepcopy(witness['public_trades'])

    def test_exact_complete_ordinary_response_parity_and_restore(self):
        self.assertEqual(self.baseline, self.observed)
        self.assertIs(backtester._decision_identity_receipt, type(self).original_function)
        self.assertGreater(self.verified['closed_trade_count'], 0)
        self.assertEqual(self.verified['entry_stage_hash'], self.observed['data_quality']['decision_identity_receipt']['stage_identities']['entry']['event_hash'])
        self.assertFalse(self.verified['economic_authority'])
        self.assertFalse(self.verified['marked_equity_independently_verified'])

    def test_independent_stage_hash_and_own_receipt_sha_refuse_tamper(self):
        for reseal_own in (False, True):
            body, witness = self.fixture()
            receipt = witness['original_receipt']
            receipt['stage_identities']['entry']['event_hash'] = 'f' * 64
            if reseal_own:
                receipt['receipt_hash'] = witness_owner._hash({key: value for key, value in receipt.items() if key != 'receipt_hash'})
            body['data_quality']['decision_identity_receipt'] = copy.deepcopy(receipt)
            with self.subTest(reseal_own=reseal_own), self.assertRaises(ValueError):
                witness_owner.verify_management_witness(body, witness)

    def test_position_size_entry_link_tamper_refuses_after_reseal(self):
        body, witness = self.fixture()
        witness['closed_events'][0]['position_size_multiple'] *= .5
        self.reseal(body, witness)
        with self.assertRaisesRegex(ValueError, 'POSITION_SIZE_PARITY'):
            witness_owner.verify_management_witness(body, witness)

    def test_explicit_cost_math_tamper_refuses_after_reseal(self):
        body, witness = self.fixture()
        witness['closed_events'][0]['execution_cost_percent'] += .01
        self.reseal(body, witness)
        with self.assertRaisesRegex(ValueError, 'FULL_CLOSE_COST_MATH'):
            witness_owner.verify_management_witness(body, witness)

    def test_partial_fraction_or_fill_refused_after_reseal(self):
        for field, value in (('partial_fraction', .5), ('partial_exit_price', 101.0)):
            body, witness = self.fixture()
            witness['closed_events'][0][field] = value
            self.reseal(body, witness)
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, 'PARTIAL'):
                witness_owner.verify_management_witness(body, witness)

    def test_strict_maturity_counts_forced_close_unknown_and_open(self):
        for field, value in (('closed_trade_count', 999), ('open_position_count', 2),
                             ('unknown_maturity_count', 1), ('censored_trade_count', 1),
                             ('forced_terminal_close_applied', True)):
            body, witness = self.fixture()
            body['statistical_evidence']['original_position_maturity'][field] = value
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, 'POSITION_MATURITY'):
                witness_owner.verify_management_witness(body, witness)

    def test_cash_balance_and_drawdown_tampering_refuse(self):
        for field in ('final_balance', 'max_drawdown_percent'):
            body, witness = self.fixture()
            body[field] += 1
            with self.subTest(field=field), self.assertRaises(ValueError):
                witness_owner.verify_management_witness(body, witness)

    def test_nonzero_drawdown_uses_public_curve_rounding_but_retains_exact_metric(self):
        data = software_frame()
        # One deliberately adverse synthetic price step exercises cash loss,
        # cents rounding and percentage rounding without changing strategy,
        # risk, costs or the ordinary producer.
        for field in ('open', 'high', 'low', 'close'):
            data.loc[220:, field] -= 3.1234567
        with witness_owner.capture_management_witness() as observer:
            body = backtester.run_simple_ema_rsi_backtest_on_dataframe(
                software_request(), data, include_differential_pair=False, lightweight=True).model_dump()
        witness = observer.snapshot()
        verified = witness_owner.verify_management_witness(body, witness)
        self.assertGreater(verified['closed_cash_drawdown_percent'], 0)
        curve = [round(body['initial_balance'], 2), *[trade['balance'] for trade in body['trade_ledger']]]
        peak, expected = curve[0], 0.0
        for cash in curve:
            peak = max(peak, cash)
            expected = max(expected, (peak - cash) / peak * 100 if peak > 0 else 0.0)
        self.assertEqual(body['max_drawdown_percent'], round(expected, 2))
        self.assertEqual(verified['displayed_closed_cash_drawdown_percent'], round(expected, 2))
        self.assertNotEqual(verified['closed_cash_drawdown_percent'], body['max_drawdown_percent'])
        body['max_drawdown_percent'] += .01
        with self.assertRaisesRegex(ValueError, 'CLOSED_CASH_DRAWDOWN'):
            witness_owner.verify_management_witness(body, witness)

    def test_initial_stop_risk_tamper_refuses_after_reseal(self):
        body, witness = self.fixture()
        witness['closed_events'][0]['initial_risk_percent'] *= .5
        self.reseal(body, witness)
        with self.assertRaisesRegex(ValueError, 'INITIAL_EXECUTABLE_RISK'):
            witness_owner.verify_management_witness(body, witness)

    def test_censored_exit_reason_never_becomes_mature(self):
        body, witness = self.fixture()
        witness['closed_events'][0]['exit_reason'] = 'forced_end'
        witness['public_trades'][0]['exit_reason'] = 'forced_end'
        self.reseal(body, witness)
        with self.assertRaisesRegex(ValueError, 'MATURE_FULL_CLOSE'):
            witness_owner.verify_management_witness(body, witness)

    def test_nested_capture_refused_and_exception_restores_original(self):
        with witness_owner.capture_management_witness():
            with self.assertRaisesRegex(ValueError, 'NESTED_OR_CONCURRENT'):
                with witness_owner.capture_management_witness():
                    pass
        self.assertIs(backtester._decision_identity_receipt, type(self).original_function)
        with self.assertRaisesRegex(RuntimeError, 'synthetic interruption'):
            with witness_owner.capture_management_witness():
                raise RuntimeError('synthetic interruption')
        self.assertIs(backtester._decision_identity_receipt, type(self).original_function)

    def test_original_producer_exception_restores_exact_callable(self):
        def failed_original(*args, **kwargs):
            raise RuntimeError('original producer interrupted')
        with patch.object(backtester, '_decision_identity_receipt', failed_original):
            with self.assertRaisesRegex(RuntimeError, 'original producer interrupted'):
                with witness_owner.capture_management_witness():
                    backtester._decision_identity_receipt(software_request(), software_frame(), [], set(), {}, [])
            self.assertIs(backtester._decision_identity_receipt, failed_original)
        self.assertIs(backtester._decision_identity_receipt, type(self).original_function)

    def test_unavailable_snapshot_without_completed_original_call_refuses(self):
        with witness_owner.capture_management_witness() as observer:
            with self.assertRaises(ValueError):
                observer.snapshot()

    def test_metrics_do_not_mislabel_cost_or_unobserved_stop_path(self):
        self.assertGreater(self.verified['explicit_commission_carry_cash'], 0)
        self.assertAlmostEqual(self.verified['explicit_commission_carry_cash'],
                               self.verified['commission_cash'] + self.verified['carry_cash'])
        self.assertFalse(self.verified['native_ratchet_observation_available'])
        self.assertFalse(self.verified['intrabar_fill_path_independently_verified'])
        self.assertTrue(self.verified['explicit_cost_excludes_spread_and_slippage_already_in_executable_prices'])
        for trade in self.verified['per_trade']:
            self.assertGreaterEqual(trade['holding_seconds'], 0)
            self.assertGreater(trade['native_signal_management_atr'], 0)


if __name__ == '__main__':
    unittest.main()
