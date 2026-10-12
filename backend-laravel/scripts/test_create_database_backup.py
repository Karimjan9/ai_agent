import contextlib
import gzip
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('backup_stream', Path(__file__).with_name('create-database-backup.py'))
backup_stream = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup_stream)


class NativeProcess:
    def __init__(self, payload, status=0):
        self.stdout = io.BytesIO(payload)
        self.status = status

    def wait(self, timeout=None):
        return self.status

    def poll(self):
        return self.status


class BackupStreamTest(unittest.TestCase):
    def setUp(self):
        if os.name == 'nt' and not Path('G:/').is_dir():
            self.skipTest('The Windows backup contract requires a G: test directory.')
        self.directory = tempfile.TemporaryDirectory(prefix='backup-stream-test-', dir='G:/' if os.name == 'nt' else None)
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.old = self.root / 'neurotrader_20000101_000000.sql.gz'
        self.old.write_bytes(b'original retained backup')
        self.payload = b'CREATE TABLE `original` (`id` int);\nINSERT INTO `original` VALUES (1);\n' * 17000
        self.configuration = {'directory': str(self.root), 'mysqldump_binary': 'fixture-native-dump',
                              'host': '127.0.0.1', 'port': 3306, 'username': 'fixture',
                              'password': 'private-password-not-a-cli-argument', 'database': 'fixture'}

    def run_backup(self, status=0):
        output = io.StringIO()
        with patch.object(backup_stream.sys, 'stdin', io.StringIO(json.dumps(self.configuration))), \
                patch.object(backup_stream.subprocess, 'Popen', return_value=NativeProcess(self.payload, status)) as native, \
                contextlib.redirect_stdout(output):
            backup_stream.main()
        return json.loads(output.getvalue()), native

    def test_complete_stream_verifies_native_bytes_and_keeps_old_backup_for_owner_prune(self):
        manifest, native = self.run_backup()
        final = self.root / manifest['file']
        self.assertEqual(self.payload, gzip.decompress(final.read_bytes()))
        self.assertEqual(hashlib.sha256(self.payload).hexdigest(), manifest['uncompressed_sha256'])
        self.assertEqual(hashlib.sha256(final.read_bytes()).hexdigest(), manifest['sha256'])
        self.assertEqual(manifest, json.loads(Path(str(final) + '.manifest.json').read_text()))
        self.assertTrue(manifest['gzip_crc_and_stream_hash_verified'])
        self.assertEqual(b'original retained backup', self.old.read_bytes())
        self.assertFalse(list(self.root.glob('*.partial')))
        self.assertIn('--max-allowed-packet=1G', native.call_args.args[0])
        self.assertNotIn(self.configuration['password'], ' '.join(native.call_args.args[0]))
        if os.name == 'nt':
            self.assertEqual(backup_stream.subprocess.CREATE_NO_WINDOW, native.call_args.kwargs['creationflags'])

    def test_failed_native_dump_never_publishes_or_deletes_old_backup(self):
        with self.assertRaisesRegex(RuntimeError, 'BACKUP_NATIVE_DUMP_FAILED'):
            self.run_backup(status=1)
        self.assertEqual([self.old], list(self.root.iterdir()))
        self.assertEqual(b'original retained backup', self.old.read_bytes())

    def test_truncated_gzip_is_refused_before_atomic_publication(self):
        original_open = backup_stream.gzip.open
        calls = 0

        def corrupted_open(path, mode, **kwargs):
            nonlocal calls
            calls += 1
            if calls == 2:
                partial = Path(path)
                partial.write_bytes(partial.read_bytes()[:-8])
            return original_open(path, mode, **kwargs)

        with patch.object(backup_stream.gzip, 'open', side_effect=corrupted_open):
            with self.assertRaises(EOFError):
                self.run_backup()
        self.assertEqual([self.old], list(self.root.glob('*.sql.gz')))
        self.assertFalse(list(self.root.glob('*.manifest.json')))
        self.assertEqual(b'original retained backup', self.old.read_bytes())

    def test_native_timeout_stops_owned_dump_and_preserves_the_previous_backup(self):
        stopped = threading.Event()

        class BlockingOutput:
            def read(self, size):
                if not stopped.wait(4):
                    raise AssertionError('Native deadline did not stop the owned dump.')
                return b''

            def close(self):
                pass

        class BlockingNative:
            stdout = BlockingOutput()

            def poll(self):
                return 1 if stopped.is_set() else None

            def kill(self):
                stopped.set()

            def wait(self, timeout=None):
                return 1

        self.configuration['native_timeout_seconds'] = 1
        started = time.monotonic()
        with patch.object(backup_stream.sys, 'stdin', io.StringIO(json.dumps(self.configuration))), \
                patch.object(backup_stream.subprocess, 'Popen', return_value=BlockingNative()):
            with self.assertRaisesRegex(RuntimeError, 'BACKUP_NATIVE_DUMP_FAILED'):
                backup_stream.main()
        self.assertTrue(stopped.is_set())
        self.assertLess(time.monotonic() - started, 3)
        self.assertEqual([self.old], list(self.root.iterdir()))


if __name__ == '__main__':
    unittest.main()
