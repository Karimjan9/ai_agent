"""Offline custody, deadline and orchestration tests; no research replay runs."""
from contextlib import redirect_stdout
import copy
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

from scripts import curator_scalp_panel as panel
from app.services.research_program_tasks import canonical_hash, canonical_json


class CuratorScalpPanelTest(unittest.TestCase):
    def baseline(self):
        return {'parameters': {'entry_topology_variant': 'frozen', 'time_stop_candles': 0,
            'ema_fast': 20, 'trailing_atr_multiplier': 0.0}, 'risk_per_trade': 1.0,
            'execution': {'take_profit_percent': 1.0, 'commission_percent': .01}}

    def attempt(self):
        return {'started_at_monotonic': 100.0, 'global_deadline_monotonic': 5500.0,
            'spec_sha256': 'spec', 'parent_pid': 42, 'max_attempts': 1}

    def launch(self):
        return {'started_at_monotonic': 150.0, 'deadline_monotonic': 750.0,
            'spec_sha256': 'spec', 'parent_pid': 42, 'case_key': 'case', 'request_sha256': 'request'}

    def authorize(self, attempt=None, launch=None, now=160.0, parent=42):
        panel.authorize_worker(attempt or self.attempt(), launch or self.launch(), 'spec', 'case', 'request', now, parent)

    def test_only_two_physical_coordinates_change(self):
        baseline = self.baseline()
        for arm, values in panel.ARMS.items():
            request = panel.physical_for_arm(baseline, arm)
            panel.validate_physical(request, baseline, arm)
            self.assertEqual((request['parameters']['entry_topology_variant'], request['parameters']['time_stop_candles']), values)
            restored = copy.deepcopy(request)
            restored['parameters'].update(entry_topology_variant='frozen', time_stop_candles=0)
            self.assertEqual(restored, baseline)
        self.assertEqual(baseline, self.baseline())

    def test_exact_two_by_four_roster_and_wire_seals(self):
        spec = self.fake_spec(); spec.update(predeclared_arms=8, baselines={key: {} for key in panel.WINDOWS})
        panel.validate_roster(spec)
        del spec['requests']['october_pre_dst__pretrend_6bars']
        with self.assertRaisesRegex(RuntimeError, 'TWO_BY_FOUR_ROSTER'):
            panel.validate_roster(spec)
        request = self.baseline(); raw = panel.encoded(request)
        declaration = {'request': request, 'request_json': raw.decode(), 'request_sha256': hashlib.sha256(raw).hexdigest(),
            'request_semantic_hash': canonical_hash(request)}
        panel.verify_wire(declaration)
        declaration['request_json'] = declaration['request_json'].replace('20', '21')
        with self.assertRaisesRegex(RuntimeError, 'EXACT_WIRE_AND_SEMANTIC'):
            panel.verify_wire(declaration)

    def test_risk_cost_and_undeclared_policy_drift_refused(self):
        for field in ('risk', 'cost', 'policy', 'float_time'):
            request = panel.physical_for_arm(self.baseline(), 'pretrend_6bars')
            if field == 'risk':
                request['risk_per_trade'] = 2.0
            elif field == 'cost':
                request['execution']['commission_percent'] = 0.0
            elif field == 'policy':
                request['policy_context'] = {}
            else:
                request['parameters']['time_stop_candles'] = 6.0
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'ONLY_TOPOLOGY_AND_TIME_STOP'):
                panel.validate_physical(request, self.baseline(), 'pretrend_6bars')

    def test_original_global_and_per_arm_limits(self):
        self.assertEqual(panel.remaining_budget(self.attempt(), 200.0), 600)
        self.assertEqual(panel.remaining_budget(self.attempt(), 5400.0), 100)
        self.authorize()
        late = self.launch(); late.update(started_at_monotonic=5400.0, deadline_monotonic=5500.0)
        self.authorize(launch=late, now=5450.0)

    def test_expired_global_and_arm_cannot_renew(self):
        with self.assertRaisesRegex(RuntimeError, 'GLOBAL_BUDGET_EXPIRED'):
            self.authorize(now=5500.0)
        with self.assertRaisesRegex(RuntimeError, 'PER_ARM_DEADLINE'):
            self.authorize(now=750.0)
        for field, value in (('global_deadline_monotonic', 5501.0), ('started_at_monotonic', float('nan'))):
            attempt = self.attempt(); attempt[field] = value
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'GLOBAL_DEADLINE'):
                self.authorize(attempt=attempt)

    def test_changed_deadline_parent_case_or_wire_refused(self):
        for field, value in (('deadline_monotonic', 751.0), ('parent_pid', 43), ('request_sha256', 'other'),
                             ('case_key', 'other'), ('spec_sha256', 'other')):
            launch = self.launch(); launch[field] = value
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'PER_ARM_DEADLINE_OR_PARENT'):
                self.authorize(launch=launch)
        with self.assertRaisesRegex(RuntimeError, 'PER_ARM_DEADLINE_OR_PARENT'):
            self.authorize(parent=43)

    def times(self):
        start = datetime(2025, 10, 13, tzinfo=timezone.utc)
        return [(start + timedelta(minutes=5 * index)).isoformat() for index in range(205)]

    def test_full_clock_warmup_and_interior_schedule(self):
        stamps = self.times(); clock = panel.clock_from_times(stamps, 'data', 'cost')
        self.assertEqual(clock['decision_rows'], 5); self.assertEqual(clock['first_evaluation_index'], 200)
        self.assertEqual(clock['signal_start'], stamps[199]); self.assertEqual(clock['execution_end'], stamps[-1])
        changed = stamps[:]; changed[202] = (datetime.fromisoformat(changed[202]) + timedelta(seconds=1)).isoformat()
        other = panel.clock_from_times(changed, 'data', 'cost')
        self.assertEqual(clock['index_set_hash'], other['index_set_hash'])
        self.assertNotEqual(clock['schedule_hash'], other['schedule_hash'])

    def test_rehashed_clock_tamper_fails_against_exact_csv(self):
        with tempfile.TemporaryDirectory(prefix='scalp-clock-test-') as directory:
            path = Path(directory) / 'input.csv'
            panel.write_new(path, ('time\n' + '\n'.join(self.times()) + '\n').encode())
            identity = panel.sha(path)
            body = panel.clock_from_times(self.times(), identity, 'cost')
            window = {'path': str(path), 'sha256': identity, 'loaded_rows': 205, 'evaluated_rows': 5}
            clock = {**body, 'receipt_json': canonical_json(body), 'receipt_hash': canonical_hash(body)}
            panel.verify_clock(clock, window, 'cost')
            for field, value in (('decision_rows', 4), ('schedule_hash', 'a' * 64), ('index_set_hash', 'b' * 64)):
                changed = {**body, field: value}
                tampered = {**changed, 'receipt_json': canonical_json(changed), 'receipt_hash': canonical_hash(changed)}
                with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'ORIGINAL_FULL_CLOCK_INVALID'):
                    panel.verify_clock(tampered, window, 'cost')

    def test_duplicate_times_cannot_be_complete(self):
        stamps = self.times(); stamps[201] = stamps[200]
        with self.assertRaisesRegex(RuntimeError, 'FULL_ORDERED_INPUT_CLOCK'):
            panel.clock_from_times(stamps, 'data', 'cost')

    def fake_spec(self):
        spec = {'question_id': 'test', 'requests': {}, 'windows': {key: {} for key in panel.WINDOWS}, 'source_archive': {'sha256': 'archive'}}
        for window in panel.WINDOWS:
            for arm in panel.ARMS:
                key = window + '__' + arm; raw = json.dumps({'case': key})
                spec['requests'][key] = {'window': window, 'arm': arm, 'baseline_key': window + '__proximity_unbounded',
                    'request_json': raw, 'request_sha256': hashlib.sha256(raw.encode()).hexdigest(),
                    'dataset_sha256': window, 'cost_sha256': 'cost'}
        return spec

    def test_all_eight_wires_precede_workers_raw_streams_precede_verification_and_no_retry(self):
        with tempfile.TemporaryDirectory(prefix='scalp-orchestration-test-') as directory:
            folder = Path(directory); products = folder / 'products'; spec = self.fake_spec(); starts, verifies = [], []
            class Process:
                returncode = 0
                def __init__(self, argv, **kwargs):
                    self.key = argv[argv.index('--worker') + 1]; starts.append(self.key)
                    for key, declaration in spec['requests'].items():
                        if panel.sha(products / (key + '.request.json')) != declaration['request_sha256']:
                            raise AssertionError('worker started before complete fixed request roster')
                def communicate(self, raw, timeout=None):
                    if not 0 < timeout <= 600:
                        raise AssertionError('per-arm deadline extended')
                    panel.write_new(products / (self.key + '.witness.json'), {})
                    return b'{"genuine":1}', b'original stderr'
            def verify(body, declaration, *args):
                key = declaration['window'] + '__' + declaration['arm']; verifies.append(key)
                self.assertEqual((products / (key + '.response.json')).read_bytes(), b'{"genuine":1}')
                self.assertEqual((products / (key + '.stderr.txt')).read_bytes(), b'original stderr')
                raise RuntimeError('synthetic verification rejection')
            with patch.object(panel, 'read_spec', return_value=spec), patch.object(panel, 'frozen'), \
                 patch.object(panel.subprocess, 'Popen', Process), patch.object(panel, 'verify_response', side_effect=verify), redirect_stdout(io.StringIO()):
                report = panel.execute(folder / 'before-outcome-spec.json', 'spec')
                self.assertEqual(len(starts), 8); self.assertEqual(len(verifies), 8)
                self.assertTrue(all(row['status'] == 'technical_terminal' for row in report['results'].values()))
                # Post-terminal inspection must re-open the original streams
                # without dispatching or silently promoting technical cases.
                inspection = panel.inspect_originals(folder / 'before-outcome-spec.json', 'spec')
                self.assertEqual(inspection['verification']['completed_originals_reverified'], 0)
                self.assertEqual(inspection['verification']['technical_terminal_cases'], 8)
                self.assertEqual(len(starts), 8); self.assertEqual(len(verifies), 8)
                with self.assertRaises(FileExistsError):
                    panel.execute(folder / 'before-outcome-spec.json', 'spec')
                self.assertEqual(len(starts), 8)

    def test_global_expiry_never_launches_and_is_not_cash_zero(self):
        with tempfile.TemporaryDirectory(prefix='scalp-expiry-test-') as directory:
            spec = self.fake_spec(); ticks = iter([10.0])
            def now():
                return next(ticks, 5411.0)
            with patch.object(panel, 'read_spec', return_value=spec), patch.object(panel, 'frozen'), \
                 patch.object(panel.time, 'monotonic', side_effect=now), patch.object(panel.subprocess, 'Popen') as process, redirect_stdout(io.StringIO()):
                report = panel.execute(Path(directory) / 'spec.json', 'spec')
            process.assert_not_called()
            self.assertTrue(all(row['status'] == 'not_attempted_global_cap' and 'evidence' not in row for row in report['results'].values()))
            self.assertTrue(all(pair['realized_cash_delta'] is None for window in report['window_comparisons'].values() for pair in window['pairs'].values()))

    def test_timeout_preserves_partial_raw_streams_without_verification_or_retry(self):
        with tempfile.TemporaryDirectory(prefix='scalp-timeout-test-') as directory:
            spec = self.fake_spec(); spawned = []
            class TimeoutProcess:
                returncode = 1
                def __init__(self, argv, **kwargs):
                    self.key = argv[argv.index('--worker') + 1]; self.killed = False; spawned.append(self)
                def communicate(self, raw=None, timeout=None):
                    if not self.killed:
                        raise panel.subprocess.TimeoutExpired('synthetic-worker', timeout)
                    return b'partial original response', b'original timeout stderr'
                def kill(self):
                    self.killed = True
            with patch.object(panel, 'read_spec', return_value=spec), patch.object(panel, 'frozen'), \
                 patch.object(panel.subprocess, 'Popen', TimeoutProcess), patch.object(panel, 'verify_response') as verify, redirect_stdout(io.StringIO()):
                report = panel.execute(Path(directory) / 'spec.json', 'spec')
            verify.assert_not_called(); self.assertEqual(len(spawned), 8)
            for key, row in report['results'].items():
                self.assertTrue(row['timeout']); self.assertEqual(row['status'], 'technical_terminal')
                self.assertEqual((Path(directory) / 'products' / (key + '.response.json')).read_bytes(), b'partial original response')
                self.assertEqual((Path(directory) / 'products' / (key + '.stderr.txt')).read_bytes(), b'original timeout stderr')

    def comparison_report(self):
        report = {'results': {}}
        for window in panel.WINDOWS:
            for arm, cash in zip(panel.ARMS, (1.0, 4.0, 6.0, 11.0)):
                report['results'][window + '__' + arm] = {'status': 'completed', 'dataset_sha256': window, 'cost_sha256': 'cost',
                    'evidence': {'net_cash': cash, 'open_position_count': int(arm == 'pretrend_6bars'),
                        'clock_hash': arm, 'index_set_hash': 'indices', 'schedule_hash': 'schedule', 'actual_clock': {'same': True}}}
        return report

    def test_pairs_match_actual_clock_not_request_specific_receipts(self):
        report = self.comparison_report(); panel.add_comparisons(report)
        window = report['window_comparisons']['october_pre_dst']
        self.assertEqual(window['cash_interaction'], 2.0)
        self.assertEqual(window['pairs']['joint_vs_baseline']['realized_cash_delta'], 10.0)
        self.assertTrue(window['pairs']['joint_vs_baseline']['eof_open_exposure_present'])
        self.assertFalse(window['pairs']['joint_vs_baseline']['total_marked_utility_claim'])
        report['results']['october_pre_dst__pretrend_6bars']['evidence']['schedule_hash'] = 'changed'
        panel.add_comparisons(report); window = report['window_comparisons']['october_pre_dst']
        self.assertIsNone(window['cash_interaction']); self.assertIsNone(window['pairs']['joint_vs_baseline']['realized_cash_delta'])
        self.assertEqual(window['pairs']['time_given_proximity']['realized_cash_delta'], 3.0)

    def test_technical_case_has_no_economic_delta(self):
        report = self.comparison_report(); row = report['results']['october_pre_dst__pretrend_6bars']
        row['status'] = 'technical_terminal'; row.pop('evidence')
        panel.add_comparisons(report); pair = report['window_comparisons']['october_pre_dst']['pairs']['joint_vs_baseline']
        self.assertIsNone(pair['realized_cash_delta']); self.assertTrue(pair['technical_is_not_an_economic_zero'])

    def test_archive_entries_and_source_roster_are_exact_not_just_rehashed_zip(self):
        with tempfile.TemporaryDirectory(prefix='scalp-source-test-') as directory:
            directory = Path(directory); draft = directory / 'draft.json'; panel.write_new(draft, b'{}')
            files = {'fixture.py': hashlib.sha256(b'original').hexdigest()}
            spec = {'source_snapshot': {'files': files, 'snapshot_hash': canonical_hash(files)}, 'runtime': {'identity': 1},
                'draft': {'path': str(draft), 'sha256': panel.sha(draft)}, 'upstream': {}, 'windows': {}}
            archive = directory / 'source.zip'
            with zipfile.ZipFile(archive, 'x') as zipped:
                zipped.writestr('fixture.py', b'tampered')
                zipped.writestr('external/draft.json', b'{}')
            spec['source_archive'] = {'path': str(archive), 'sha256': panel.sha(archive), 'entries': panel.archive_entries(spec)}
            with patch.object(panel, 'source_roster', return_value=files), patch.object(panel, 'runtime_fingerprint', return_value={'identity': 1}), \
                 patch.object(panel, 'validate_inputs'):
                with self.assertRaisesRegex(RuntimeError, 'ARCHIVE_ENTRY_CHANGED'):
                    panel.frozen(spec, archive_contents=True)
            with patch.object(panel, 'source_roster', return_value={**files, 'added.py': 'new'}):
                with self.assertRaisesRegex(RuntimeError, 'EXACT_FROZEN_SOURCE_ROSTER'):
                    panel.frozen(spec)

    def test_read_only_product_hash_rejects_changed_raw_bytes(self):
        with tempfile.TemporaryDirectory(prefix='scalp-inspection-test-') as directory:
            path = Path(directory) / 'response.json'; original = panel.write_new(path, b'original response')
            self.assertEqual(panel.artifact_hash(path, original), original)
            with self.assertRaisesRegex(RuntimeError, 'ORIGINAL_ARTIFACT_HASH_MISMATCH'):
                panel.artifact_hash(path, hashlib.sha256(b'different response').hexdigest())
            self.assertEqual(path.read_bytes(), b'original response')

    def test_inspector_rejects_summary_process_disagreement(self):
        process = {'status': 'technical_terminal', 'request_sha256': 'request', 'response_sha256': 'response', 'timeout': True}
        original = dict(process); declaration = {'request_sha256': 'request'}
        panel.verify_process_link(process, original, declaration)
        original['response_sha256'] = 'changed'
        with self.assertRaisesRegex(RuntimeError, 'ORIGINAL_SUMMARY_PROCESS_MISMATCH'):
            panel.verify_process_link(process, original, declaration)


if __name__ == '__main__':
    unittest.main()
