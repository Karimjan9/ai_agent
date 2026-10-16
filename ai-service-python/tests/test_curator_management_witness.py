"""Synthetic offline observer parity and hostile-witness checks; no market evidence."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
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

    def test_legacy_request_keywords_preserve_exact_output_and_capture_shape(self):
        self.assertNotIn('closed_setup_geometry', self.witness)
        self.assertEqual(self.verified, witness_owner.verify_management_witness(
            self.observed, self.witness, request=software_request(), csv_path='unused-legacy.csv'))


class CuratorStructuralSetupWitnessTest(unittest.TestCase):
    """Real registered runtimes on software prices only; no archive replay."""
    @classmethod
    def setUpClass(cls):
        import pandas as pd
        from app.schemas import ExecutionConfig, SimpleBacktestRequest
        from test_source_ema_pretrend_entry import source_frame
        from test_source_intraday_geometry import retest_source
        cls.folder = tempfile.TemporaryDirectory(prefix='curator-structural-witness-')
        cls.cases = {}
        for family in ('trend', 'trend_retest'):
            for direction in ('BUY', 'SELL'):
                if family == 'trend':
                    data = source_frame(count=320, pullback=260, direction=direction)
                    variant = 'source_ema_pretrend_pullback_v1'
                else:
                    data = retest_source(count=320, breakout=260, direction=direction)
                    for index in range(200):
                        close = 90 + index * .05
                        if direction == 'SELL':
                            close = 500 - close
                        data.loc[index, ['open', 'high', 'low', 'close']] = [close, close + .2, close - .2, close]
                    variant = 'source_frozen_retest_v1'
                # The custody source is quotes only. Actual preparation owns
                # ADX/regime/session; hand labels from feature-unit fixtures
                # are not admissible original dataset columns here.
                data = data[['time', 'open', 'high', 'low', 'close']].copy()
                data['volume'], data['volume_available'] = 0.0, False
                path = Path(cls.folder.name) / (family + '-' + direction + '.csv')
                data.to_csv(path, index=False)
                data = pd.read_csv(path)
                request = SimpleBacktestRequest(strategy=family + '_v1', base_strategy=family,
                    timeframe='M5', dataset_path=str(path), replay_dataset_hash=hashlib.sha256(path.read_bytes()).hexdigest(),
                    parameters={'ema_fast': 20, 'ema_slow': 50, 'rsi_period': 14, 'trend_strength_min': 20,
                        'entry_topology_variant': variant, 'intraday_exit_variant': 'closed_setup_2r_v1',
                        'volume_lane': 'none', 'time_stop_candles': 1,
                        'trailing_atr_multiplier': 0.0, 'partial_take_profit_fraction': 0.0},
                    risk_per_trade=1.0, execution=ExecutionConfig(max_leverage=5, spread_points=1,
                        slippage_points=.1, commission_percent=.001, swap_per_day_percent=.002),
                    emit_decision_trace=True, emit_trade_ledger=True)
                with witness_owner.capture_management_witness() as observer:
                    body = backtester.run_simple_ema_rsi_backtest_on_dataframe(
                        request, data, include_differential_pair=False, lightweight=True).model_dump()
                witness = observer.snapshot()
                cls.cases[(family, direction)] = (request, path, body, witness)

    @classmethod
    def tearDownClass(cls):
        cls.folder.cleanup()

    def fixture(self, key=('trend', 'BUY')):
        request, path, body, witness = self.cases[key]
        return request.model_copy(deep=True), path, copy.deepcopy(body), copy.deepcopy(witness)

    def verify(self, fixture):
        request, path, body, witness = fixture
        return witness_owner.verify_management_witness(body, witness, request=request, csv_path=path)

    def test_both_actual_source_functions_and_directions_have_independent_closed_geometry(self):
        for key in self.cases:
            with self.subTest(runtime=key):
                with patch.object(backtester, '_exit_distances', side_effect=AssertionError('producer geometry called')), \
                        patch.object(backtester, 'run_simple_ema_rsi_backtest_on_dataframe',
                                     side_effect=AssertionError('replay called during verification')):
                    verified = self.verify(self.fixture(key))
                proof = verified['closed_setup_geometry']
                self.assertGreater(proof['accepted_entry_count'], 0)
                self.assertEqual(proof['accepted_entry_count'], verified['closed_trade_count'] + verified['open_position_count'])
                self.assertFalse(proof['economic_authority'])
                self.assertTrue(proof['nominal_price_2r_not_cost_adjusted_net_2r'])
                self.assertTrue(all(item['direction'] == key[1] for item in proof['per_entry'].values()))

    def test_eof_open_entry_keeps_unclosed_exposure_and_initial_target_proof(self):
        import pandas as pd
        request, original_path, _, _ = self.fixture()
        data = pd.read_csv(original_path).iloc[:263].copy()
        path = Path(self.folder.name) / 'eof-open.csv'
        data.to_csv(path, index=False)
        request.dataset_path = str(path)
        request.replay_dataset_hash = hashlib.sha256(path.read_bytes()).hexdigest()
        with witness_owner.capture_management_witness() as observer:
            body = backtester.run_simple_ema_rsi_backtest_on_dataframe(
                request, pd.read_csv(path), include_differential_pair=False, lightweight=True).model_dump()
        verified = witness_owner.verify_management_witness(body, observer.snapshot(), request=request, csv_path=path)
        self.assertEqual(verified['open_position_count'], 1)
        self.assertEqual(verified['closed_trade_count'], 0)
        self.assertEqual(verified['closed_setup_geometry']['accepted_entry_count'], 1)
        self.assertEqual(verified['unclosed_exposure'], 'censored_terminal_marked_value_unknown')

    def test_structural_opt_in_requires_exact_request_and_reopened_original_csv(self):
        request, path, body, witness = self.fixture()
        for keywords in ({}, {'request': request}, {'csv_path': path}):
            with self.subTest(keywords=list(keywords)), self.assertRaisesRegex(ValueError, 'ORIGINAL_REQUEST_AND_CSV'):
                witness_owner.verify_management_witness(body, witness, **keywords)
        other = Path(self.folder.name) / 'copied.csv'
        other.write_bytes(path.read_bytes())
        try:
            with self.assertRaisesRegex(ValueError, 'ORIGINAL_CSV_PATH'):
                witness_owner.verify_management_witness(body, witness, request=request, csv_path=other)
        finally:
            other.unlink()

    def test_wrong_actual_runtime_topology_delay_or_physical_controls_cannot_claim_setup(self):
        for change in ('runtime', 'topology', 'delay', 'trailing', 'leverage'):
            fixture = self.fixture()
            request, _, _, witness = fixture
            if change == 'runtime':
                request.strategy, request.base_strategy = 'ema_rsi_v1', 'ema_rsi'
            elif change == 'topology':
                request.parameters['entry_topology_variant'] = 'frozen'
            elif change == 'delay':
                request.signal_delay_candles = 1
            elif change == 'trailing':
                request.parameters['trailing_atr_multiplier'] = 2.0
            else:
                request.execution.max_leverage = 6
            # Even a coherent-looking replacement request in the sidecar does
            # not let the caller authorize another runtime or risk envelope.
            witness['closed_setup_geometry']['request'] = request.model_dump(mode='json')
            with self.subTest(change=change), self.assertRaises(ValueError):
                self.verify(fixture)

    def test_source_anchor_asof_direction_or_roster_tamper_refuses(self):
        for field, value in (('entry_setup_invalidation_price', 1.0),
                             ('entry_setup_source_time', '2025-01-01T00:00:00+00:00'),
                             ('entry_setup_direction', 'SELL'), ('entry_setup_protocol', 'caller-proof')):
            fixture = self.fixture()
            rows = fixture[3]['closed_setup_geometry']['entry_observations']
            self.assertTrue(rows)
            rows[next(iter(rows))][field] = value
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, 'SOURCE_ROW_PROOF'):
                self.verify(fixture)
        fixture = self.fixture()
        fixture[3]['closed_setup_geometry']['entry_observations'].clear()
        with self.assertRaisesRegex(ValueError, 'ENTRY_PROOF_ROSTER'):
            self.verify(fixture)

    def test_resealed_sidecar_cannot_change_original_risk_or_duration(self):
        for field, value, reason in (('risk_per_trade', 1.1, 'PHYSICAL_REQUEST_PARITY'),
                                      ('duration_seconds', 600, 'ACTUAL_EXECUTION_DURATION')):
            fixture = self.fixture()
            fixture[3]['request'][field] = value
            # Duration participates in stage hashes; preserve coherent-looking
            # receipt stages so the source clock, not merely a digest, refuses.
            CuratorManagementWitnessTest.reseal(self, fixture[2], fixture[3])
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, reason):
                self.verify(fixture)

    def test_coherent_trace_target_or_stop_tamper_refuses_before_cash_math(self):
        for field in ('stop_loss', 'take_profit'):
            fixture = self.fixture()
            rows = [row for row in fixture[2]['decision_trace'] if row['event_type'] == 'signal_evaluation'
                    and row['accepted'] and row['action'] in ('BUY', 'SELL')]
            self.assertTrue(rows)
            rows[0]['state'][field] += .5
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, 'INITIAL_EXECUTION_GEOMETRY'):
                self.verify(fixture)

    def test_original_csv_bytes_and_injected_csv_geometry_cannot_authorize_proof(self):
        fixture = self.fixture()
        request, path, body, witness = fixture
        original = path.read_bytes()
        try:
            path.write_bytes(original + b'\n')
            with self.assertRaisesRegex(ValueError, 'ORIGINAL_CSV_SHA'):
                self.verify(fixture)
            import pandas as pd
            raw = pd.read_csv(path)
            raw['entry_setup_protocol'] = 'closed_setup_2r_v1'
            raw.to_csv(path, index=False)
            request.replay_dataset_hash = hashlib.sha256(path.read_bytes()).hexdigest()
            witness['closed_setup_geometry']['request'] = request.model_dump(mode='json')
            with self.assertRaisesRegex(ValueError, 'UNFORGED_BOUNDED_SOURCE_CSV'):
                self.verify(fixture)
        finally:
            path.write_bytes(original)


class CuratorIntradayCsvRegistrationTest(unittest.TestCase):
    def test_new_protocol_delegates_owner_and_retains_exact_path_sha_and_row_guards(self):
        from scripts import curator_scalp_metrics as metrics
        from scripts import curator_intraday_month_windows as owner
        with tempfile.TemporaryDirectory(prefix='curator-registration-only-') as folder:
            path, manifest_path = Path(folder) / 'june.csv', Path(folder) / 'provenance-manifest.json'
            path.write_bytes(b'synthetic-csv-only')
            sha = hashlib.sha256(path.read_bytes()).hexdigest()
            manifest = {'protocol': 'curator_intraday_replication_windows_v1',
                'timestamp_semantics': metrics.REGISTERED_CSV_TIME_SEMANTICS,
                'windows': {'june2025': {'path': str(path), 'sha256': sha, 'loaded_rows': 202}}}
            manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
            with patch.object(owner, 'validate_manifest', return_value=manifest) as validate:
                result = metrics._registered_csv_binding(path, sha, {'replay_dataset_hash': sha}, 202, None)
                validate.assert_called_once_with(manifest_path, hashlib.sha256(manifest_path.read_bytes()).hexdigest())
                self.assertEqual(result['protocol'], 'rederived_original_intraday_replication_csv_utc_registration_v1')
                for supplied_sha, count in (('f' * 64, 202), (sha, 203)):
                    with self.assertRaisesRegex(ValueError, 'EXACT_REGISTERED_CSV_BYTES'):
                        metrics._registered_csv_binding(path, supplied_sha, {'replay_dataset_hash': sha}, count, None)
                copied = Path(folder) / 'sideways.csv'
                with self.assertRaisesRegex(ValueError, 'EXACT_REGISTERED_CSV_PATH'):
                    metrics._registered_csv_binding(copied, sha, {'replay_dataset_hash': sha}, 202,
                        {'manifest_path': str(manifest_path), 'manifest_sha256': hashlib.sha256(manifest_path.read_bytes()).hexdigest(),
                         'window_key': 'june2025'})
            with patch.object(owner, 'validate_manifest', side_effect=ValueError('owner-rederivation-refused')):
                with self.assertRaisesRegex(ValueError, 'owner-rederivation-refused'):
                    metrics._registered_csv_binding(path, sha, {'replay_dataset_hash': sha}, 202, None)


if __name__ == '__main__':
    unittest.main()
