import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("verify_backup", Path(__file__).with_name("verify-database-backup-tables.py"))
verify_backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verify_backup)


CREATE = "CREATE TABLE `sample` (\n  `id` bigint NOT NULL,\n  `body` longtext DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
HEADER = b"-- Database: neurotrader_lab\n/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"


def dump(table="sample", body=None):
    create = CREATE.replace("`sample`", "`" + table + "`")
    body = body if body is not None else b"INSERT INTO `" + table.encode() + b"` VALUES (1,'a\\nUSE db; DROP DATABASE source; \\\'quoted\\\'');\n"
    return (HEADER + b"-- Table structure\nDROP TABLE IF EXISTS `" + table.encode() + b"`;\n"
            + create.encode() + b";\nLOCK TABLES `" + table.encode() + b"` WRITE;\n"
            + b"/*!40000 ALTER TABLE `" + table.encode() + b"` DISABLE KEYS */;\n" + body
            + b"/*!40000 ALTER TABLE `" + table.encode() + b"` ENABLE KEYS */;\nUNLOCK TABLES;\n"
            + b"/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n-- Dump completed\n")


class FakeClient:
    instances = []

    def __init__(self, configuration, guard, capture=False):
        self.blocks = []
        self.configuration = configuration
        self.finished = False
        self.aborted = False
        self.instances.append(self)

    def write(self, block, flush=False):
        self.blocks.append(block)

    def finish(self):
        self.finished = True

    def abort(self):
        self.aborted = True


class BackupVerificationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="backup-verification-test-")
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.configuration = {"backup_file": str(self.directory / "snapshot.sql.gz"),
                              "manifest_file": str(self.directory / "snapshot.sql.gz.manifest.json"),
                              "inventory_file": str(self.directory / "inventory.json"),
                              "mysql_binary": "fixture-native-mysql", "host": "127.0.0.1", "port": 3306,
                              "user": "fixture_scratch_only", "password": "secret-never-in-arguments",
                              "scratch_database": "codex_archive_verify_fixture", "data_directory": str(self.directory),
                              "report_file": str(self.directory / "report.jsonl"), "progress_file": str(self.directory / "progress.jsonl")}
        self.inventory = {"database": "neurotrader_lab", "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci",
                          "tables": [{"name": "sample", "row_count": 1, "checksum": "123456789", "create_sql": CREATE}]}
        FakeClient.instances = []
        self.client_patch = patch.object(verify_backup, "Client", FakeClient)
        self.client_patch.start()
        self.addCleanup(self.client_patch.stop)
        self.disk_patch = patch.object(verify_backup.shutil, "disk_usage", return_value=type("Disk", (), {"free": 20 * 1024 ** 3})())
        self.disk_patch.start()
        self.addCleanup(self.disk_patch.stop)
        self.inspection_patch = patch.object(verify_backup, "inspect_and_drop", side_effect=self.inspection)
        self.inspection = self.inspection_patch.start()
        self.addCleanup(self.inspection_patch.stop)

    @staticmethod
    def inspection(configuration, guard, name, expected):
        return {"name": name, "row_count": expected["row_count"], "checksum": expected["checksum"], "create_sql_equal": True, "scratch_table_dropped": True}

    def fixtures(self, raw=None):
        raw = dump() if raw is None else raw
        compressed = gzip.compress(raw)
        Path(self.configuration["backup_file"]).write_bytes(compressed)
        Path(self.configuration["inventory_file"]).write_text(json.dumps(self.inventory), encoding="utf-8")
        manifest = {"schema": "neurotrader-db-backup/v1", "database": "neurotrader_lab", "file": "snapshot.sql.gz",
                    "bytes": len(compressed), "sha256": hashlib.sha256(compressed).hexdigest(),
                    "uncompressed_bytes": len(raw), "uncompressed_sha256": hashlib.sha256(raw).hexdigest()}
        Path(self.configuration["manifest_file"]).write_text(json.dumps(manifest), encoding="utf-8")
        return manifest

    def test_complete_dump_restores_one_table_and_accepts_sql_words_inside_escaped_data(self):
        self.fixtures()
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        self.assertEqual(1, report["tables_verified"])
        self.assertTrue(FakeClient.instances[0].finished)
        self.assertTrue(FakeClient.instances[0].blocks[0].startswith(HEADER))
        self.assertEqual(self.configuration["scratch_database"], self.inspection.call_args.args[0]["scratch_database"])
        self.assertNotIn(self.configuration["password"], json.dumps(report))

    def test_table_is_verified_and_dropped_before_next_table_import(self):
        other = {**self.inventory["tables"][0], "name": "other", "create_sql": CREATE.replace("`sample`", "`other`")}
        self.inventory["tables"].append(other)
        self.fixtures(dump() + dump("other"))
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        self.assertEqual(["sample", "other"], [call.args[2] for call in self.inspection.call_args_list])
        self.assertEqual(2, len(FakeClient.instances))
        self.assertTrue(all(client.blocks[0].startswith(HEADER) for client in FakeClient.instances))

    def test_big_single_row_commits_only_after_complete_insert(self):
        body = b"INSERT INTO `sample` VALUES (1,'" + b"x" * (verify_backup.TRANSACTION_BYTES + 25) + b"');\n"
        self.fixtures(dump(body=body))
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        blocks = FakeClient.instances[0].blocks
        index = blocks.index(body)
        self.assertEqual(b"COMMIT;\nSET SESSION autocommit=0;\n", blocks[index + 1])

    def test_optional_batch_preserves_exact_literals_and_flushes_before_directives(self):
        self.configuration["insert_batch_bytes"] = 512
        self.inventory["tables"][0]["row_count"] = 2
        first = b"INSERT INTO `sample` VALUES (1,'a\\n; DROP DATABASE text;');\n"
        second = b"INSERT INTO `sample` VALUES (-1,'escaped\\\'quote',0x00FF,NULL);\n"
        self.fixtures(dump(body=first + second))
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        blocks = FakeClient.instances[0].blocks
        inserts = [block for block in blocks if block.startswith(b"INSERT INTO ")]
        expected = b"INSERT INTO `sample` VALUES (1,'a\\n; DROP DATABASE text;'),\n(-1,'escaped\\\'quote',0x00FF,NULL);\n"
        self.assertEqual([expected], inserts)
        self.assertEqual(b"/*!40000 ALTER TABLE `sample` ENABLE KEYS */;\n", blocks[blocks.index(expected) + 1])

    def test_batches_obey_total_byte_cap_and_oversized_original_row_passes_unchanged(self):
        self.configuration["insert_batch_bytes"] = 100
        small = b"INSERT INTO `sample` VALUES (1,'small');\n"
        large = b"INSERT INTO `sample` VALUES (2,'" + b"x" * 200 + b"');\r\n"
        self.fixtures(dump(body=small + large + small * 8))
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        inserts = [block for block in FakeClient.instances[0].blocks if block.startswith(b"INSERT INTO ")]
        self.assertEqual(small, inserts[0])
        self.assertEqual(large, inserts[1])
        self.assertTrue(all(len(block) <= 100 for block in inserts if block != large))
        self.assertTrue(any(b"),\n(" in block for block in inserts[2:]))

    def test_batch_flush_precedes_transaction_commit(self):
        self.configuration["insert_batch_bytes"] = 512
        body = b"INSERT INTO `sample` VALUES (1,'" + b"x" * 50 + b"');\n"
        self.fixtures(dump(body=body * 2))
        with patch.object(verify_backup, "TRANSACTION_BYTES", 64):
            report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        blocks = FakeClient.instances[0].blocks
        index = next(index for index, block in enumerate(blocks) if block.startswith(b"INSERT INTO "))
        self.assertIn(b"),\n(", blocks[index])
        self.assertEqual(b"COMMIT;\nSET SESSION autocommit=0;\n", blocks[index + 1])
        self.assertEqual(b"/*!40000 ALTER TABLE `sample` ENABLE KEYS */;\n", blocks[index + 2])

    def test_default_zero_keeps_original_single_row_statements(self):
        body = b"INSERT INTO `sample` VALUES (1,'original');\r\n"
        self.fixtures(dump(body=body * 3))
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        self.assertEqual([body] * 3, [block for block in FakeClient.instances[0].blocks if block.startswith(b"INSERT INTO ")])

    def test_batch_flushes_at_eof_and_never_crosses_tables(self):
        self.configuration["insert_batch_bytes"] = 512
        self.inventory["tables"].append({**self.inventory["tables"][0], "name": "other", "create_sql": CREATE.replace("`sample`", "`other`")})
        first = dump().split(b"/*!40000 ALTER TABLE `sample` ENABLE KEYS */;")[0]
        second = dump("other").split(b"/*!40000 ALTER TABLE `other` ENABLE KEYS */;")[0]
        self.fixtures(first + second)
        report = verify_backup.verify(self.configuration)
        self.assertTrue(report["completed"], report)
        self.assertEqual(2, len(FakeClient.instances))
        for client, name in zip(FakeClient.instances, ("sample", "other")):
            inserts = [block for block in client.blocks if block.startswith(b"INSERT INTO ")]
            self.assertEqual(1, len(inserts))
            self.assertTrue(inserts[0].startswith(b"INSERT INTO `" + name.encode() + b"` VALUES "))
            self.assertEqual(b"COMMIT;\n", client.blocks[-1])

    def test_batch_rejects_unsafe_original_insert_before_flushing_pending_values(self):
        self.configuration["insert_batch_bytes"] = 512
        valid = b"INSERT INTO `sample` VALUES (1,'original');\n"
        invalid = b"INSERT INTO `sample` VALUES (2,'bad'); DROP DATABASE `outside`;\n"
        self.fixtures(dump(body=valid + invalid))
        report = verify_backup.verify(self.configuration)
        self.assertFalse(report["completed"])
        self.assertFalse(any(block.startswith(b"INSERT INTO ") for block in FakeClient.instances[0].blocks))

    def test_batch_cap_rejects_invalid_or_unbounded_configuration(self):
        self.fixtures()
        for cap in (-1, verify_backup.BLOCK_BYTES + 1, True, 1.5):
            with self.subTest(cap=cap):
                self.configuration["insert_batch_bytes"] = cap
                self.assertEqual("INVALID_INSERT_BATCH_BYTES", verify_backup.verify(self.configuration)["reason_code"])
        self.assertEqual([], FakeClient.instances)

    def test_low_space_at_input_boundary_aborts_without_flushing_pending_batch(self):
        self.configuration["insert_batch_bytes"] = verify_backup.BLOCK_BYTES
        raw = dump(body=b"INSERT INTO `sample` VALUES (1,'x');\n" * 100000)
        self.fixtures(raw)
        _, tables, _ = verify_backup.validate_configuration(self.configuration)
        report = {"uncompressed_bytes": 0, "tables_verified": 0, "table_results": []}
        guard = verify_backup.Guard(self.configuration)
        verifier = verify_backup.TableVerifier(self.configuration, tables, guard, report)
        high = type("Disk", (), {"free": 20 * 1024 ** 3})()
        low = type("Disk", (), {"free": 1024})()
        source = io.BytesIO(raw)
        try:
            with patch.object(verify_backup.shutil, "disk_usage", side_effect=lambda path: high if not report["uncompressed_bytes"] else low):
                with self.assertRaisesRegex(verify_backup.VerificationError, "VERIFICATION_DISK_RESERVE_REQUIRED"):
                    for line in verify_backup.raw_lines(source, guard, hashlib.sha256(), report):
                        verifier.consume(line)
            self.assertEqual(verify_backup.BLOCK_BYTES, source.tell())
            self.assertTrue(verifier.batch_values)
            self.assertFalse(any(block.startswith(b"INSERT INTO ") for block in FakeClient.instances[0].blocks))
            self.inspection.assert_not_called()
        finally:
            verifier.abort()
            guard.close()
        self.assertFalse(verifier.batch_values)
        self.assertTrue(FakeClient.instances[0].aborted)

    def test_missing_unknown_and_duplicate_dump_tables_fail_closed(self):
        cases = [(HEADER, "MISSING_DUMP_TABLES"), (dump("unknown"), "UNKNOWN_OR_DUPLICATE_DUMP_TABLE"),
                 (dump() + dump(), "UNKNOWN_OR_DUPLICATE_DUMP_TABLE")]
        for raw, reason in cases:
            with self.subTest(reason=reason):
                self.fixtures(raw)
                report = verify_backup.verify(self.configuration)
                self.assertFalse(report["completed"])
                self.assertEqual(reason, report["reason_code"])

    def test_unsafe_directives_and_source_qualified_statements_are_rejected(self):
        cases = [b"USE `neurotrader_lab`;\n", b"CREATE DATABASE `other`;\n", b"DROP DATABASE `neurotrader_lab`;\n",
                 b"DROP TABLE IF EXISTS `neurotrader_lab`.`sample`;\n", b"SET @a=(SELECT 1);\n"]
        for statement in cases:
            with self.subTest(statement=statement):
                self.fixtures(HEADER + statement + dump())
                report = verify_backup.verify(self.configuration)
                self.assertFalse(report["completed"])
                self.assertEqual([], FakeClient.instances)

    def test_multiple_insert_statements_and_real_embedded_newline_fail(self):
        cases = [b"INSERT INTO `sample` VALUES (1,'x'); DROP DATABASE `other`;\n",
                 b"INSERT INTO `sample` VALUES (1,'actual\nnewline');\n",
                 b"INSERT INTO `sample` VALUES (1,(SELECT 1));\n"]
        for body in cases:
            with self.subTest(body=body):
                self.fixtures(dump(body=body))
                self.assertFalse(verify_backup.verify(self.configuration)["completed"])

    def test_malformed_inventory_and_source_target_never_launch_client(self):
        cases = [{"row_count": -1}, {"checksum": None}, {"name": "sample`.`outside"}, {"create_sql": "CREATE TABLE `other` (`id` int)"}]
        original = self.inventory["tables"][0].copy()
        for changed in cases:
            with self.subTest(changed=changed):
                self.inventory["tables"] = [{**original, **changed}]
                self.fixtures()
                self.assertFalse(verify_backup.verify(self.configuration)["completed"])
                self.assertEqual([], FakeClient.instances)
        self.configuration["scratch_database"] = "neurotrader_lab"
        self.assertEqual("ISOLATED_SCRATCH_DATABASE_REQUIRED", verify_backup.verify(self.configuration)["reason_code"])

    def test_duplicate_inventory_and_identical_source_scratch_are_rejected(self):
        self.inventory["tables"].append(self.inventory["tables"][0].copy())
        self.fixtures()
        self.assertEqual("DUPLICATE_INVENTORY_TABLE", verify_backup.verify(self.configuration)["reason_code"])
        self.inventory["database"] = self.configuration["scratch_database"]
        self.fixtures()
        self.assertEqual("SOURCE_DATABASE_TARGET_FORBIDDEN", verify_backup.verify(self.configuration)["reason_code"])
        self.assertEqual([], FakeClient.instances)

    def test_wrong_hash_and_corrupt_gzip_fail_and_append_receipts(self):
        self.fixtures()
        manifest_path = Path(self.configuration["manifest_file"])
        manifest = json.loads(manifest_path.read_text())
        manifest["sha256"] = "0" * 64
        manifest_path.write_text(json.dumps(manifest))
        report = verify_backup.verify(self.configuration)
        self.assertEqual("COMPRESSED_BACKUP_HASH_OR_SIZE_MISMATCH", report["reason_code"])
        self.assertEqual([], FakeClient.instances)
        self.fixtures()
        backup = Path(self.configuration["backup_file"])
        corrupted = backup.read_bytes()[:-8]
        backup.write_bytes(corrupted)
        manifest = json.loads(manifest_path.read_text())
        manifest.update(bytes=len(corrupted), sha256=hashlib.sha256(corrupted).hexdigest())
        manifest_path.write_text(json.dumps(manifest))
        self.assertFalse(verify_backup.verify(self.configuration)["completed"])
        receipts = Path(self.configuration["report_file"]).read_text().splitlines()
        self.assertEqual(2, len(receipts))
        self.assertTrue(all(not json.loads(line)["completed"] for line in receipts))

    def test_disk_reserve_fails_before_import(self):
        self.fixtures()
        with patch.object(verify_backup.shutil, "disk_usage", return_value=type("Disk", (), {"free": 1024})()):
            report = verify_backup.verify(self.configuration)
        self.assertEqual("VERIFICATION_DISK_RESERVE_REQUIRED", report["reason_code"])
        self.assertEqual([], FakeClient.instances)

    def test_receipt_path_cannot_append_to_backup_on_invalid_configuration(self):
        self.fixtures()
        backup = Path(self.configuration["backup_file"])
        original = backup.read_bytes()
        self.configuration["report_file"] = self.configuration["backup_file"]
        self.assertFalse(verify_backup.verify(self.configuration)["completed"])
        self.assertEqual(original, backup.read_bytes())

    def test_literal_escapes_hex_null_and_negative_exponent_numbers_are_allowed(self):
        valid = [b"INSERT INTO `sample` VALUES (1,'escaped\\\\','doubled''quote',0x00FABC,NULL,-1.25e-20);\n",
                 b"INSERT INTO `sample` VALUES (1,'\\\'escaped\\\'',_binary 'bytes');\n"]
        for statement in valid:
            with self.subTest(statement=statement):
                verify_backup.validate_insert(statement, "sample")

    def test_wrong_uncompressed_manifest_hash_never_reports_complete(self):
        manifest = self.fixtures()
        manifest["uncompressed_sha256"] = "f" * 64
        Path(self.configuration["manifest_file"]).write_text(json.dumps(manifest))
        self.assertEqual("UNCOMPRESSED_BACKUP_HASH_OR_SIZE_MISMATCH", verify_backup.verify(self.configuration)["reason_code"])

    def test_create_normalization_preserves_quoted_whitespace_and_columns(self):
        self.assertEqual(verify_backup.normalize_create(CREATE), verify_backup.normalize_create(CREATE.replace("\n", " ") + ";"))
        self.assertNotEqual(verify_backup.normalize_create(CREATE), verify_backup.normalize_create(CREATE.replace("`body` longtext DEFAULT NULL,", "")))
        self.assertNotEqual(verify_backup.normalize_create("CREATE TABLE `x` (`s` text COMMENT 'a  b')"),
                            verify_backup.normalize_create("CREATE TABLE `x` (`s` text COMMENT 'a b')"))


class MetadataAndNativeClientTest(unittest.TestCase):
    def test_metadata_checks_precede_drop_and_reject_count_checksum_or_schema_drift(self):
        expected = {"row_count": 1, "checksum": "123", "normalized_create": verify_backup.normalize_create(CREATE)}
        encoded = CREATE.replace("\\", "\\\\").replace("\n", "\\n").encode()

        class MetadataClient(FakeClient):
            response = [b"1", b"codex_archive_verify_fixture.sample\t123", b"sample\t" + encoded]

            def until(self, marker):
                return self.response if marker.endswith(b"INSPECTION_END") else []

        configuration = {"scratch_database": "codex_archive_verify_fixture"}
        guard = type("Guard", (), {"check_disk": lambda self: None})()
        with patch.object(verify_backup, "Client", MetadataClient):
            result = verify_backup.inspect_and_drop(configuration, guard, "sample", expected)
            self.assertTrue(result["scratch_table_dropped"])
            self.assertIn(b"DROP TABLE `sample`;", MetadataClient.instances[-1].blocks[-1])
            for response, reason in [([b"2", *MetadataClient.response[1:]], "RESTORED_ROW_COUNT_MISMATCH"),
                                     ([b"1", b"codex_archive_verify_fixture.sample\t999", MetadataClient.response[2]], "RESTORED_TABLE_CHECKSUM_MISMATCH"),
                                     ([b"1", MetadataClient.response[1], b"sample\tCREATE TABLE `sample` (`id` int)"], "RESTORED_CREATE_SQL_MISMATCH")]:
                with self.subTest(reason=reason):
                    MetadataClient.response = response
                    with self.assertRaisesRegex(verify_backup.VerificationError, reason):
                        verify_backup.inspect_and_drop(configuration, guard, "sample", expected)
                    self.assertFalse(any(b"DROP TABLE" in block for block in MetadataClient.instances[-1].blocks))

    def test_native_command_uses_scratch_only_no_shell_and_environment_secret(self):
        configuration = {"mysql_binary": "fixture-mysql", "host": "127.0.0.1", "port": 3306, "user": "limited",
                         "password": "never-on-command-line", "scratch_database": "codex_archive_verify_fixture"}

        class Guard:
            def check(self):
                pass

            def check_disk(self):
                pass

            def register(self, process):
                pass

            def release(self, process):
                pass

        class Process:
            stdin = io.BytesIO()
            stdout = None

            def poll(self):
                return 0

            def wait(self, timeout=None):
                return 0

        with patch.object(verify_backup.subprocess, "Popen", return_value=Process()) as native:
            client = verify_backup.Client(configuration, Guard())
            client.abort()
        arguments, keywords = native.call_args.args[0], native.call_args.kwargs
        self.assertEqual(configuration["scratch_database"], arguments[-1])
        self.assertNotIn(configuration["password"], " ".join(arguments))
        self.assertEqual(configuration["password"], keywords["env"]["MYSQL_PWD"])
        self.assertNotIn("shell", keywords)
        self.assertIn("--binary-mode", arguments)
        self.assertIn("--max-allowed-packet=1G", arguments)
        if verify_backup.os.name == "nt":
            self.assertEqual(verify_backup.subprocess.CREATE_NO_WINDOW, keywords["creationflags"])

    def test_timed_out_native_child_is_killed_and_reaped(self):
        configuration = {"mysql_binary": "fixture-mysql", "host": "127.0.0.1", "port": 3306, "user": "limited",
                         "password": "secret", "scratch_database": "codex_archive_verify_fixture"}

        class Guard:
            def check(self):
                pass

            def check_disk(self):
                pass

            def register(self, process):
                pass

            def release(self, process):
                pass

            def remaining(self):
                return 0.01

        class Process:
            stdin = io.BytesIO()
            stdout = None
            killed = False
            waits = 0

            def poll(self):
                return -9 if self.killed else None

            def kill(self):
                self.killed = True

            def wait(self, timeout=None):
                self.waits += 1
                if self.waits == 1:
                    raise verify_backup.subprocess.TimeoutExpired("fixture-mysql", timeout)
                return -9

        process = Process()
        with patch.object(verify_backup.subprocess, "Popen", return_value=process):
            client = verify_backup.Client(configuration, Guard())
            with self.assertRaisesRegex(verify_backup.VerificationError, "MYSQL_CHILD_DEADLINE_EXCEEDED"):
                client.finish()
        self.assertTrue(process.killed)
        self.assertEqual(2, process.waits)
        self.assertTrue(process.stdin.closed)

    def test_whole_verification_deadline_refuses_work(self):
        configuration = {"data_directory": ".", "verification_timeout_seconds": 7200}
        guard = verify_backup.Guard(configuration)
        try:
            guard.deadline = verify_backup.time.monotonic() - 1
            with self.assertRaisesRegex(verify_backup.VerificationError, "VERIFICATION_DEADLINE_EXCEEDED"):
                guard.check()
        finally:
            guard.close()


class ByteBoundDiskGuardTest(unittest.TestCase):
    def setUp(self):
        self.configuration = {"data_directory": ".", "verification_timeout_seconds": 7200}
        self.guard = verify_backup.Guard(self.configuration)
        self.addCleanup(self.guard.close)
        self.high = type("Disk", (), {"free": 20 * 1024 ** 3})()
        self.low = type("Disk", (), {"free": 1024})()

    def test_deadline_checks_do_not_probe_disk_per_sql_line(self):
        source = io.BytesIO(b"-- comment\n" * 10000)
        report = {"uncompressed_bytes": 0, "tables_verified": 0}
        with patch.object(verify_backup.shutil, "disk_usage", return_value=self.high) as disk:
            for line in verify_backup.raw_lines(source, self.guard, hashlib.sha256(), report):
                self.guard.check()  # Per-line parsing.
                self.guard.check()  # Per-write check.
            self.assertEqual(2, disk.call_count)  # One block and the EOF read.
        self.assertEqual(10000 * len(b"-- comment\n"), report["uncompressed_bytes"])

    def test_low_space_at_next_one_mib_boundary_refuses_further_input(self):
        source = io.BytesIO(b"x" * (verify_backup.BLOCK_BYTES + 1) + b"\n")
        report = {"uncompressed_bytes": 0, "tables_verified": 0}
        with patch.object(verify_backup.shutil, "disk_usage", side_effect=[self.high, self.low]) as disk:
            with self.assertRaisesRegex(verify_backup.VerificationError, "VERIFICATION_DISK_RESERVE_REQUIRED"):
                list(verify_backup.raw_lines(source, self.guard, hashlib.sha256(), report))
            self.assertEqual(2, disk.call_count)
        self.assertEqual(verify_backup.BLOCK_BYTES, source.tell())
        self.assertEqual(verify_backup.BLOCK_BYTES, report["uncompressed_bytes"])

    def test_later_low_space_refuses_before_next_native_import(self):
        configuration = {**self.configuration, "mysql_binary": "fixture-mysql", "host": "127.0.0.1", "port": 3306,
                         "user": "limited", "password": "secret", "scratch_database": "codex_archive_verify_fixture"}

        class Process:
            stdin = io.BytesIO()
            stdout = None

            def poll(self):
                return 0

            def wait(self, timeout=None):
                return 0

        with patch.object(verify_backup.shutil, "disk_usage", side_effect=[self.high, self.low]), \
                patch.object(verify_backup.subprocess, "Popen", return_value=Process()) as native:
            first = verify_backup.Client(configuration, self.guard)
            first.abort()
            with self.assertRaisesRegex(verify_backup.VerificationError, "VERIFICATION_DISK_RESERVE_REQUIRED"):
                verify_backup.Client(configuration, self.guard)
            self.assertEqual(1, native.call_count)


if __name__ == "__main__":
    unittest.main()
