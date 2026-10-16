"""Synthetic reporting custody tests. Never inspect current research outcomes."""
from contextlib import redirect_stdout
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import zipfile

from app.services.research_program_tasks import canonical_hash

ROOT = Path(__file__).resolve().parents[2]
_SPEC = importlib.util.spec_from_file_location('_scalp_reporting_appendix', ROOT / 'scripts/research/assess-curator-scalp-products.py')
reporting = importlib.util.module_from_spec(_SPEC); _SPEC.loader.exec_module(reporting)
ARMS = ('proximity_unbounded', 'proximity_6bars', 'pretrend_unbounded', 'pretrend_6bars')
WINDOWS = ('october_pre_dst', 'december_standard')


class ScalpReportingTest(unittest.TestCase):
    def traceback(self):
        frames = [(reporting.RUNNER, '<module>'), (reporting.RUNNER, 'worker'), (reporting.RUNNER, 'verify_response'),
            (reporting.CORRECTIONS[0], 'summarize_scalp_metrics'), (reporting.CORRECTIONS[0], '_original_clock'),
            (reporting.CORRECTIONS[0], '<listcomp>'), (reporting.CORRECTIONS[0], '_stamp'), (reporting.CORRECTIONS[0], '_need')]
        return ('Traceback (most recent call last):\n' + ''.join('  File "' + str(ROOT / path) + '", line 12, in ' + name + '\n    original_call()\n'
            for path, name in frames) + 'ValueError: SCALP_UTC_TIMESTAMP_REQUIRED\n').encode()

    def process(self):
        return {'status': 'technical_terminal', 'exit_code': 1, 'timeout': False, 'response_preserved_before_verification': True,
            'wall_seconds': 20.0, 'timeout_limit_seconds': 600.0,
            'response_sha256': 'a' * 64, 'stderr_sha256': 'b' * 64, 'witness_sha256': 'c' * 64}

    def summary_fixture(self):
        requests = {window + '__' + arm: {'window': window, 'arm': arm} for window in WINDOWS for arm in ARMS}
        spec = {'protocol': 'curator_scalp_archive_panel_v1', 'question_id': 'question', 'requests': requests,
            'windows': {window: {} for window in WINDOWS}, 'source_archive': {'sha256': 'archive'},
            'source_snapshot': {'snapshot_hash': 'snapshot'}}
        summary = {'protocol': 'curator_scalp_original_report_v1', 'question_id': 'question', 'before_outcome_spec_sha256': 'spec',
            'final_source_check': 'unchanged', 'research_only': True, **{flag: False for flag in reporting.FLAGS},
            'results': {key: {'status': 'technical_terminal'} for key in requests}, 'window_comparisons': {}}
        return spec, summary

    def test_only_exact_reporting_exception_is_eligible(self):
        self.assertTrue(reporting.exact_reporting_exception(self.process(), self.traceback(), True, True))
        for change in ({'timeout': True}, {'exit_code': 2}, {'status': 'completed'}, {'response_preserved_before_verification': False}):
            with self.subTest(change=change):
                self.assertFalse(reporting.exact_reporting_exception({**self.process(), **change}, self.traceback(), True, True))
        self.assertFalse(reporting.exact_reporting_exception(self.process(), self.traceback(), False, True))
        self.assertFalse(reporting.exact_reporting_exception(self.process(), self.traceback(), True, False))
        self.assertFalse(reporting.exact_reporting_exception(self.process(), b'\xff\xfe', True, True))
        self.assertFalse(reporting.exact_reporting_exception({**self.process(), 'witness_sha256': None}, self.traceback(), True, True))

    def test_other_exception_or_foreign_stack_is_not_reporting_correction(self):
        for raw in (self.traceback().replace(b'ValueError: SCALP_UTC_TIMESTAMP_REQUIRED', b'ValueError: OTHER_ERROR'),
                    self.traceback() + b'ValueError: AFTER_ERROR\n',
                    b'Traceback (most recent call last):\n' + self.traceback(),
                    self.traceback().replace(b'in _original_clock', b'in unrelated_execution'),
                    self.traceback().replace(b'curator_scalp_metrics.py', b'backtester.py')):
            with self.subTest(raw=raw[-90:]):
                self.assertFalse(reporting.exact_reporting_exception(self.process(), raw, True, True))

    def test_utc_traceback_after_invalid_or_exceeded_original_budget_stays_unknown(self):
        for changes in ({'wall_seconds': 600.01}, {'wall_seconds': 30168.4754551}, {'wall_seconds': -1},
                        {'wall_seconds': float('nan')}, {'wall_seconds': float('inf')},
                        {'timeout_limit_seconds': 0}, {'timeout_limit_seconds': -1}, {'timeout_limit_seconds': 601},
                        {'timeout_limit_seconds': float('nan')}, {'timeout_limit_seconds': float('inf')},
                        {'wall_seconds': 61, 'timeout_limit_seconds': 60}):
            process = {**self.process(), **changes}
            with self.subTest(changes=changes):
                self.assertFalse(process['timeout'])
                self.assertFalse(reporting.exact_reporting_exception(process, self.traceback(), True, True))

    def test_original_worker_start_must_precede_original_global_and_arm_deadlines(self):
        attempt = {'started_at_monotonic': 100.0, 'global_deadline_monotonic': 5500.0}
        launch = {'started_at_monotonic': 150.0, 'deadline_monotonic': 750.0}
        worker = {'started_at_monotonic': 160.0}
        self.assertTrue(reporting.original_budget_eligibility(self.process(), attempt, launch, worker)['eligible'])
        for start in (750.0, 5500.0, 86386.5149374, -1, float('nan'), float('inf')):
            with self.subTest(start=start):
                self.assertFalse(reporting.original_budget_eligibility(self.process(), attempt, launch, {'started_at_monotonic': start})['eligible'])
        self.assertFalse(reporting.original_budget_eligibility(self.process(), attempt, None, None)['eligible'])

    def test_supplemental_receipts_are_known_same_folder_hashes_not_original_artifacts(self):
        with tempfile.TemporaryDirectory(prefix='scalp-report-supplements-') as directory:
            folder = Path(directory)
            for name, protocol in reporting.SUPPLEMENTAL.items():
                (folder / name).write_bytes(reporting.encoded({'protocol': protocol, 'original_results_changed': False}))
            values = reporting.supplemental_receipts(folder)
            self.assertEqual(set(values), set(reporting.SUPPLEMENTAL))
            for name, value in values.items():
                self.assertEqual(value['sha256'], reporting.sha(folder / name))
                self.assertFalse(value['part_of_original_artifact_roster']); self.assertFalse(value['authority_granted'])
            with self.assertRaisesRegex(RuntimeError, 'SUPPLEMENTAL_RECEIPT_SCOPE'):
                reporting.supplemental_receipts(folder, [folder.parent / 'external-expired-worker-enforcement.json'])
            name = next(iter(reporting.SUPPLEMENTAL)); (folder / name).write_bytes(reporting.encoded({'protocol': 'wrong'}))
            with self.assertRaisesRegex(RuntimeError, 'KNOWN_SUPPLEMENTAL_RECEIPT_PROTOCOL'):
                reporting.supplemental_receipts(folder)

    def test_all_originals_terminal_before_any_projection(self):
        spec, summary = self.summary_fixture(); reporting.validate_terminal(summary, spec, 'spec')
        summary['results'][next(iter(summary['results']))]['status'] = 'running'
        with self.assertRaisesRegex(RuntimeError, 'MUST_ALREADY_BE_TERMINAL'):
            reporting.validate_terminal(summary, spec, 'spec')
        summary['results'].pop(next(iter(summary['results'])))
        with self.assertRaisesRegex(RuntimeError, 'MUST_ALREADY_BE_TERMINAL'):
            reporting.validate_terminal(summary, spec, 'spec')

    def test_only_exact_two_correction_files_may_change(self):
        original = {name: 'old' for name in reporting.CORRECTIONS}; original['unchanged.py'] = 'same'
        current = {name: 'new' for name in reporting.CORRECTIONS}; current['unchanged.py'] = 'same'
        self.assertEqual(set(reporting.correction_roster(original, current)), set(reporting.CORRECTIONS))
        for changed in ({**current, 'unchanged.py': 'changed'}, {**current, reporting.CORRECTIONS[1]: 'old'}):
            with self.subTest(changed=changed), self.assertRaisesRegex(RuntimeError, 'EXACT_TWO_REPORTING'):
                reporting.correction_roster(original, changed)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_ROSTER_CHANGED'):
            reporting.correction_roster(original, {**current, 'added.py': 'new'})

    def test_original_zip_entries_are_verified_despite_rehashed_container(self):
        with tempfile.TemporaryDirectory(prefix='scalp-report-archive-') as directory:
            path = Path(directory) / 'source.zip'
            spec = {'source_snapshot': {'files': {'original.py': hashlib.sha256(b'original').hexdigest()}},
                'draft': {'sha256': hashlib.sha256(b'{}').hexdigest()}, 'upstream': {}, 'windows': {}}
            with zipfile.ZipFile(path, 'x') as zipped:
                zipped.writestr('original.py', b'tampered'); zipped.writestr('external/draft.json', b'{}')
            spec['source_archive'] = {'path': str(path), 'sha256': reporting.sha(path), 'entries': reporting.expected_entries(spec)}
            with self.assertRaisesRegex(RuntimeError, 'ARCHIVED_BYTES_CHANGED'):
                reporting.verify_archive(spec)

    def test_precorrection_inspection_binds_summary_and_all_artifacts(self):
        spec, _ = self.summary_fixture()
        inspection = {'status': 'verified_read_only', 'spec_sha256': 'spec', 'summary_sha256': 'summary', 'terminal_cases': 8,
            'source_and_archive_reverified': True, 'source_archive_sha256': 'archive', 'artifact_roster_sha256': 'artifacts',
            'independent_validation': False, 'own_agent_outcome': False}
        reporting.verify_prior_inspection(inspection, spec, 'summary', 'artifacts', 'spec')
        for field in ('summary_sha256', 'artifact_roster_sha256', 'source_archive_sha256'):
            changed = {**inspection, field: 'changed'}
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'PRECORRECTION_ORIGINAL_INSPECTION'):
                reporting.verify_prior_inspection(changed, spec, 'summary', 'artifacts', 'spec')

    def test_changed_raw_product_hash_refuses_without_modification(self):
        with tempfile.TemporaryDirectory(prefix='scalp-report-artifact-') as directory:
            path = Path(directory) / 'response.json'; path.write_bytes(b'original raw response')
            with self.assertRaisesRegex(RuntimeError, 'PRODUCT_HASH_MISMATCH'):
                reporting.artifact(path, hashlib.sha256(b'different response').hexdigest())
            self.assertEqual(path.read_bytes(), b'original raw response')

    def test_source_checks_do_not_call_old_frozen_owner(self):
        with tempfile.TemporaryDirectory(prefix='scalp-report-source-') as directory:
            path = Path(directory) / 'draft.json'; path.write_bytes(b'{}')
            files = {name: 'old' for name in reporting.CORRECTIONS}; files[reporting.RUNNER] = 'same'
            current = {name: 'new' for name in reporting.CORRECTIONS}; current[reporting.RUNNER] = 'same'
            spec = {'source_snapshot': {'files': files, 'snapshot_hash': canonical_hash(files)}, 'runtime': {'runtime': 1},
                'draft': {'path': str(path), 'sha256': reporting.sha(path)}, 'upstream': {'manifest': {'sha256': reporting.MANIFEST_SHA}}}
            owner = SimpleNamespace(source_roster=lambda: current, runtime_fingerprint=lambda: {'runtime': 1},
                validate_requests=lambda candidate: candidate, frozen=lambda *_args, **_kwargs: self.fail('old frozen must never be called'))
            self.assertEqual(set(reporting.verify_source_boundary(spec, owner)), set(reporting.CORRECTIONS))

    def projection_rows(self):
        rows = {}
        for window in WINDOWS:
            for arm, cash in zip(ARMS, (1, 4, 6, 11)):
                rows[window + '__' + arm] = {'source_process_status': 'technical_terminal', 'appendix_status': 'projection_verified',
                    'dataset_sha256': window, 'cost_sha256': 'cost', 'evidence': {'net_cash': cash, 'open_position_count': 1,
                        'index_set_hash': 'index', 'schedule_hash': 'schedule', 'actual_clock': {'same': True}, 'scalp_metrics': {}}}
        return rows

    def test_projection_comparisons_preserve_original_technical_labels_and_open_unknown(self):
        rows = self.projection_rows(); owner = SimpleNamespace(WINDOWS=WINDOWS, ARMS=ARMS)
        with patch('scripts.curator_scalp_metrics.compare_scalp_metrics', return_value={'synthetic': True}):
            value = reporting.compare_projections(rows, owner)
            pair = value['october_pre_dst']['pairs']['joint_vs_baseline']
            self.assertEqual(pair['realized_cash_delta'], 10); self.assertTrue(pair['eof_open_exposure_present'])
            self.assertEqual(pair['source_process_statuses'], ['technical_terminal', 'technical_terminal'])
            self.assertFalse(pair['successful_original_replay_claim']); self.assertFalse(pair['dominance_claim'])
            self.assertEqual(value['october_pre_dst']['reporting_projection_cash_interaction'], 2)
            rows['october_pre_dst__pretrend_6bars']['appendix_status'] = 'unassessable_unknown'
            value = reporting.compare_projections(rows, owner)
            self.assertIsNone(value['october_pre_dst']['pairs']['joint_vs_baseline']['realized_cash_delta'])
            self.assertIsNone(value['october_pre_dst']['reporting_projection_cash_interaction'])

    def test_same_count_with_changed_schedule_never_compares(self):
        rows = self.projection_rows(); rows['october_pre_dst__pretrend_6bars']['evidence']['schedule_hash'] = 'different'
        with patch('scripts.curator_scalp_metrics.compare_scalp_metrics', return_value={}):
            with self.assertRaisesRegex(RuntimeError, 'PAIR_ACCOUNT_DATA_CLOCK_MISMATCH'):
                reporting.compare_projections(rows, SimpleNamespace(WINDOWS=WINDOWS, ARMS=ARMS))

    def test_read_only_assessment_uses_mocked_products_and_never_rewrites_originals(self):
        spec, summary = self.summary_fixture()
        with tempfile.TemporaryDirectory(prefix='scalp-report-flow-') as directory:
            folder = Path(directory); products = folder / 'products'; products.mkdir()
            spec_path = folder / 'spec.json'; spec_path.write_bytes(reporting.encoded(spec)); expected = reporting.sha(spec_path)
            summary['before_outcome_spec_sha256'] = expected
            summary_path = folder / 'original-panel-summary.json'; summary_path.write_bytes(reporting.encoded(summary))
            original_summary = summary_path.read_bytes(); inspection = folder / 'original-inspection.json'
            inspection.write_bytes(reporting.encoded({'comparisons_sha256': canonical_hash(summary['window_comparisons'])}))
            rows = {}
            for key, declaration in spec['requests'].items():
                (products / (key + '.response.json')).write_bytes(b'{}')
                (products / (key + '.witness.json')).write_bytes(b'{}')
                (products / (key + '.stderr.txt')).write_bytes(self.traceback())
                rows[key] = {'case_key': key, **declaration, 'source_process_status': 'technical_terminal',
                    'appendix_status': 'unassessable_unknown', 'original_process': self.process(),
                    'original_budget_eligibility': {'eligible': True},
                    'artifacts': {'response_sha256': reporting.sha(products / (key + '.response.json')),
                        'launch_sha256': 'launch', 'worker_attempt_sha256': 'worker'}, 'evidence': None}
            owner = SimpleNamespace()
            projection = {'net_cash': 1.0, 'open_position_count': 0}
            corrections = {name: {'original_sha256': 'old', 'corrected_sha256': 'new'} for name in reporting.CORRECTIONS}
            with patch.object(reporting, 'verify_archive'), patch.object(reporting, 'load_original_owner', return_value=owner), \
                 patch.object(reporting, 'verify_source_boundary', return_value=corrections), \
                 patch.object(reporting, 'reopen_products', return_value=(rows, {}, 'artifacts', 'attempt')), \
                 patch.object(reporting, 'verify_prior_inspection'), patch.object(reporting, 'verify_projection', return_value=projection) as verify, \
                 patch.object(reporting, 'compare_projections', return_value={}), \
                 patch.object(reporting, 'write_new', side_effect=AssertionError('read-only must not write')), redirect_stdout(io.StringIO()):
                body, receipt = reporting.assess(spec_path, expected, inspection)
            self.assertEqual(verify.call_count, 8); self.assertEqual(body['projection_verified_cases'], 8)
            self.assertTrue(all(row['source_process_status'] == 'technical_terminal' for row in body['originals'].values()))
            self.assertFalse(body['new_replay_or_attempt_performed']); self.assertFalse(body['original_results_rewritten'])
            self.assertEqual(summary_path.read_bytes(), original_summary)
            self.assertFalse((folder / 'post-terminal-reporting-appendix-v1.json').exists())


if __name__ == '__main__':
    unittest.main()
