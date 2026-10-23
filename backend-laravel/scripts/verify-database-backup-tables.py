"""Verify a gzip dump in a restricted scratch schema, releasing each table.

Configuration is JSON on stdin. Credentials are passed only through MYSQL_PWD.
The owner creates the empty scratch schema/account and removes them afterwards.
Receipts and progress are append-only JSONL; this helper never connects to the
source schema and never creates or drops a database or account.
"""
import gzip
import hashlib
import json
import os
from pathlib import Path
import queue
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time


PROTOCOL = "database_backup_table_verification_v1"
BLOCK_BYTES = 1024 ** 2
TRANSACTION_BYTES = 8 * BLOCK_BYTES
MAX_SQL_BYTES = 16 * BLOCK_BYTES
MAX_LINE_BYTES = 1024 ** 3 + BLOCK_BYTES
IDENTIFIER = re.compile(r"^[A-Za-z0-9_$]+$")
SCRATCH = re.compile(r"^codex_archive_verify_[a-z0-9_]+$")
INSERT_VALUE = re.compile(rb"(?:0x[0-9a-fA-F]+|[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|NULL|_binary|[(), \t\r\n])")


class VerificationError(RuntimeError):
    pass


def require(condition, reason):
    if not condition:
        raise VerificationError(reason)


def identifier(value):
    return isinstance(value, str) and 0 < len(value) <= 64 and bool(IDENTIFIER.fullmatch(value))


def quoted(value):
    require(identifier(value), "INVALID_SQL_IDENTIFIER")
    return "`" + value + "`"


def append_json(path, value):
    with open(path, "a", encoding="utf-8", newline="\n") as output:
        output.write(json.dumps(value, separators=(",", ":"), ensure_ascii=True) + "\n")
        output.flush()
        os.fsync(output.fileno())


def safe_receipt_paths(configuration):
    if not isinstance(configuration, dict):
        return False
    keys = ("backup_file", "manifest_file", "inventory_file", "report_file", "progress_file")
    if not all(isinstance(configuration.get(key), str) and configuration[key] for key in keys):
        return False
    try:
        return len({Path(configuration[key]).resolve() for key in keys}) == len(keys)
    except (OSError, ValueError):
        return False


def sql_tokens(raw, incomplete=False):
    """Read SQL outside literals/comments, expanding executable dump comments."""
    tokens = []
    index = 0
    while index < len(raw):
        value = raw[index]
        if value in b" \t\r\n":
            index += 1
        elif raw[index:index + 2] == b"--" and (index + 2 == len(raw) or raw[index + 2] <= 32):
            end = raw.find(b"\n", index)
            index = len(raw) if end < 0 else end + 1
        elif value == 35:
            end = raw.find(b"\n", index)
            index = len(raw) if end < 0 else end + 1
        elif raw[index:index + 2] == b"/*":
            end = raw.find(b"*/", index + 2)
            if end < 0:
                if incomplete:
                    return tokens, False
                raise VerificationError("UNTERMINATED_SQL_COMMENT")
            body = raw[index + 2:end]
            if body.startswith(b"!"):
                body = re.sub(rb"^!\d*\s*", b"", body)
                expanded, _ = sql_tokens(body)
                tokens.extend(expanded)
            elif body.startswith(b"M!"):
                raise VerificationError("UNSUPPORTED_EXECUTABLE_SQL_COMMENT")
            index = end + 2
        elif value in (39, 34, 96):
            delimiter = value
            start = index
            index += 1
            contents = bytearray()
            closed = False
            while index < len(raw):
                if raw[index] == 92 and delimiter != 96:
                    index += 2
                elif raw[index] == delimiter:
                    if index + 1 < len(raw) and raw[index + 1] == delimiter:
                        contents.append(delimiter)
                        index += 2
                    else:
                        index += 1
                        closed = True
                        break
                else:
                    contents.append(raw[index])
                    index += 1
            if not closed:
                if incomplete:
                    return tokens, False
                raise VerificationError("UNTERMINATED_SQL_LITERAL")
            if delimiter == 96:
                tokens.append(("identifier", contents.decode("utf-8")))
            else:
                tokens.append(("literal", raw[start:index]))
        elif (65 <= value <= 90) or (97 <= value <= 122) or value in (95, 36):
            start = index
            index += 1
            while index < len(raw) and (raw[index:index + 1].isalnum() or raw[index] in (95, 36)):
                index += 1
            tokens.append(("word", raw[start:index].decode("ascii")))
        elif 48 <= value <= 57:
            start = index
            while index < len(raw) and 48 <= raw[index] <= 57:
                index += 1
            tokens.append(("number", raw[start:index].decode("ascii")))
        elif value in b"();,=.+-*/@<>!|&%^~:":
            tokens.append(("symbol", chr(value)))
            index += 1
        else:
            raise VerificationError("UNSUPPORTED_SQL_TOKEN")
    return tokens, True


def normalize_create(raw):
    if isinstance(raw, str):
        raw = raw.encode("utf-8")
    tokens, _ = sql_tokens(raw)
    if tokens and tokens[-1] == ("symbol", ";"):
        tokens.pop()
    return tokens


def validate_configuration(configuration):
    required = ("backup_file", "manifest_file", "inventory_file", "mysql_binary", "host", "port",
                "user", "password", "scratch_database", "data_directory", "report_file", "progress_file")
    require(isinstance(configuration, dict) and all(key in configuration for key in required), "CONFIGURATION_FIELDS_REQUIRED")
    for key in required:
        if key != "port":
            require(isinstance(configuration[key], str) and (configuration[key] or key == "password"), "INVALID_CONFIGURATION_FIELD")
    target = configuration["scratch_database"]
    require(identifier(target) and bool(SCRATCH.fullmatch(target)), "ISOLATED_SCRATCH_DATABASE_REQUIRED")
    require(str(configuration["port"]).isdigit() and 0 < int(configuration["port"]) < 65536, "INVALID_MYSQL_PORT")
    timeout = configuration.get("verification_timeout_seconds", 7200)
    require(isinstance(timeout, (int, float)) and not isinstance(timeout, bool) and 0 < timeout <= 86400, "INVALID_VERIFICATION_TIMEOUT")
    reserve = configuration.get("reserve_bytes", 2 * 1024 ** 3)
    require(isinstance(reserve, int) and not isinstance(reserve, bool) and reserve >= 2 * 1024 ** 3, "MINIMUM_DISK_RESERVE_REQUIRED")
    batch_bytes = configuration.get("insert_batch_bytes", 0)
    require(isinstance(batch_bytes, int) and not isinstance(batch_bytes, bool) and 0 <= batch_bytes <= BLOCK_BYTES, "INVALID_INSERT_BATCH_BYTES")
    require(safe_receipt_paths(configuration), "DISTINCT_INPUT_AND_RECEIPT_PATHS_REQUIRED")
    with open(configuration["inventory_file"], encoding="utf-8-sig") as source:
        inventory = json.load(source)
    require(isinstance(inventory, dict) and identifier(inventory.get("database")), "INVALID_SOURCE_INVENTORY")
    require(target.lower() != inventory["database"].lower(), "SOURCE_DATABASE_TARGET_FORBIDDEN")
    require(identifier(inventory.get("charset")) and identifier(inventory.get("collation")), "SOURCE_CHARSET_COLLATION_REQUIRED")
    require(isinstance(inventory.get("tables"), list) and inventory["tables"], "SOURCE_TABLE_INVENTORY_REQUIRED")
    tables = {}
    for table in inventory["tables"]:
        require(isinstance(table, dict) and identifier(table.get("name")), "INVALID_TABLE_INVENTORY")
        name = table["name"]
        require(name not in tables and name.lower() not in {item.lower() for item in tables}, "DUPLICATE_INVENTORY_TABLE")
        require(isinstance(table.get("row_count"), int) and not isinstance(table["row_count"], bool) and table["row_count"] >= 0, "EXACT_SOURCE_ROW_COUNT_REQUIRED")
        checksum = table.get("checksum")
        require(not isinstance(checksum, bool) and isinstance(checksum, (str, int)) and str(checksum).isdigit(), "EXACT_SOURCE_CHECKSUM_REQUIRED")
        require(isinstance(table.get("create_sql"), str) and table["create_sql"], "SOURCE_CREATE_SQL_REQUIRED")
        create = normalize_create(table["create_sql"])
        require(len(create) > 3 and [token[1].upper() for token in create[:2]] == ["CREATE", "TABLE"] and create[2] == ("identifier", name), "SOURCE_CREATE_TABLE_NAME_MISMATCH")
        tables[name] = {**table, "checksum": str(int(checksum)), "normalized_create": create}
    with open(configuration["manifest_file"], encoding="utf-8-sig") as source:
        manifest = json.load(source)
    require(isinstance(manifest, dict) and manifest.get("schema") == "neurotrader-db-backup/v1", "BACKUP_MANIFEST_SCHEMA_REQUIRED")
    require(manifest.get("database") == inventory["database"] and manifest.get("file") == Path(configuration["backup_file"]).name, "BACKUP_MANIFEST_IDENTITY_MISMATCH")
    for key in ("sha256", "uncompressed_sha256"):
        require(isinstance(manifest.get(key), str) and bool(re.fullmatch(r"[a-f0-9]{64}", manifest[key])), "BACKUP_MANIFEST_HASH_REQUIRED")
    for key in ("bytes", "uncompressed_bytes"):
        require(isinstance(manifest.get(key), int) and not isinstance(manifest[key], bool) and manifest[key] > 0, "BACKUP_MANIFEST_BYTE_COUNT_REQUIRED")
    return inventory, tables, manifest


class Guard:
    def __init__(self, configuration):
        self.configuration = configuration
        self.started = time.monotonic()
        self.deadline = self.started + configuration.get("verification_timeout_seconds", 7200)
        self.active = None
        self.lock = threading.Lock()
        self.timer = threading.Timer(self.remaining(), self.expire)
        self.timer.daemon = True
        self.timer.start()

    def remaining(self):
        return max(0.01, self.deadline - time.monotonic())

    def check(self):
        require(time.monotonic() < self.deadline, "VERIFICATION_DEADLINE_EXCEEDED")

    def check_disk(self):
        # Byte-bound callers probe before each 1 MiB input read. SQL lines and
        # writes retain cheap deadline checks; they must not query the Windows
        # volume once per record in a multi-million-row dump.
        self.check()
        require(shutil.disk_usage(self.configuration["data_directory"]).free >= self.configuration.get("reserve_bytes", 2 * 1024 ** 3), "VERIFICATION_DISK_RESERVE_REQUIRED")

    def register(self, process):
        with self.lock:
            self.active = process
        self.check()

    def release(self, process):
        with self.lock:
            if self.active is process:
                self.active = None

    def expire(self):
        with self.lock:
            if self.active is not None and self.active.poll() is None:
                self.active.kill()

    def close(self):
        self.timer.cancel()


class Client:
    def __init__(self, configuration, guard, capture=False):
        guard.check_disk()
        self.guard = guard
        self.errors = tempfile.TemporaryFile()
        self.process = None
        self.reader = None
        self.lines = queue.Queue(maxsize=8)
        command = [configuration["mysql_binary"], "--protocol=TCP", "--host=" + configuration["host"],
                   "--port=" + str(configuration["port"]), "--user=" + configuration["user"],
                   "--binary-mode", "--batch", "--skip-column-names", "--unbuffered",
                   "--max-allowed-packet=1G", "--default-character-set=utf8mb4", configuration["scratch_database"]]
        try:
            self.process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                            stderr=self.errors, env={**os.environ, "MYSQL_PWD": configuration["password"]},
                                            creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
            guard.register(self.process)
            if capture:
                self.reader = threading.Thread(target=self.read_output, daemon=True)
                self.reader.start()
        except BaseException:
            self.abort()
            raise

    def read_output(self):
        try:
            while True:
                line = self.process.stdout.readline(MAX_SQL_BYTES + 1)
                if len(line) > MAX_SQL_BYTES:
                    self.lines.put(VerificationError("MYSQL_METADATA_LINE_TOO_LARGE"))
                    break
                self.lines.put(line)
                if not line:
                    break
        except BaseException:
            self.lines.put(VerificationError("MYSQL_METADATA_READ_FAILED"))

    def write(self, block, flush=False):
        self.guard.check()
        try:
            self.process.stdin.write(block)
            if flush:
                self.process.stdin.flush()
        except (BrokenPipeError, OSError):
            self.guard.check()
            raise VerificationError("MYSQL_INPUT_REJECTED") from None

    def until(self, marker):
        result = []
        while True:
            self.guard.check()
            try:
                line = self.lines.get(timeout=self.guard.remaining())
            except queue.Empty:
                raise VerificationError("MYSQL_METADATA_DEADLINE_EXCEEDED") from None
            if isinstance(line, BaseException):
                raise line
            require(bool(line), "MYSQL_METADATA_EARLY_EOF")
            if line.rstrip(b"\r\n") == marker:
                return result
            result.append(line.rstrip(b"\r\n"))
            require(len(result) <= 3, "UNEXPECTED_MYSQL_METADATA_OUTPUT")

    def finish(self):
        try:
            self.process.stdin.close()
            result = self.process.wait(timeout=self.guard.remaining())
            self.guard.check()
            require(result == 0, "MYSQL_CHILD_FAILED")
        except subprocess.TimeoutExpired:
            raise VerificationError("MYSQL_CHILD_DEADLINE_EXCEEDED") from None
        finally:
            self.abort()

    def abort(self):
        try:
            if self.process is not None:
                process = self.process
                try:
                    if process.poll() is None:
                        process.kill()
                    process.wait(timeout=30)
                except (OSError, subprocess.TimeoutExpired):
                    raise VerificationError("MYSQL_CHILD_REAP_FAILED") from None
                finally:
                    for stream in (process.stdin, process.stdout):
                        if stream is not None:
                            try:
                                stream.close()
                            except OSError:
                                pass
                    self.guard.release(process)
                    if self.reader is not None:
                        self.reader.join(timeout=1)
                    self.process = None
        finally:
            self.errors.close()


def unescape_mysql(value):
    escapes = {48: 0, 110: 10, 114: 13, 116: 9, 98: 8, 90: 26, 92: 92}
    result = bytearray()
    index = 0
    while index < len(value):
        if value[index] == 92 and index + 1 < len(value):
            index += 1
            result.append(escapes.get(value[index], value[index]))
        else:
            result.append(value[index])
        index += 1
    return result.decode("utf-8")


def inspect_and_drop(configuration, guard, name, expected):
    client = Client(configuration, guard, capture=True)
    marker = b"CODEX_ARCHIVE_VERIFY_INSPECTION_END"
    dropped = b"CODEX_ARCHIVE_VERIFY_DROP_END"
    table = quoted(name)
    try:
        statements = ("SET SESSION foreign_key_checks=0; SET SESSION time_zone='+00:00';\n"
                      f"SELECT COUNT(*) FROM {table};\nCHECKSUM TABLE {table} EXTENDED;\nSHOW CREATE TABLE {table};\n"
                      f"SELECT '{marker.decode()}';\n")
        client.write(statements.encode(), flush=True)
        lines = client.until(marker)
        require(len(lines) == 3 and lines[0].isdigit(), "INVALID_MYSQL_TABLE_METADATA")
        count = int(lines[0])
        checksum = lines[1].split(b"\t")
        create = lines[2].split(b"\t", 1)
        require(len(checksum) == 2 and checksum[0].decode() == configuration["scratch_database"] + "." + name and checksum[1].isdigit(), "INVALID_MYSQL_CHECKSUM_RESULT")
        require(len(create) == 2 and create[0].decode() == name, "INVALID_MYSQL_CREATE_RESULT")
        require(count == expected["row_count"], "RESTORED_ROW_COUNT_MISMATCH")
        actual_checksum = str(int(checksum[1]))
        require(actual_checksum == expected["checksum"], "RESTORED_TABLE_CHECKSUM_MISMATCH")
        require(normalize_create(unescape_mysql(create[1])) == expected["normalized_create"], "RESTORED_CREATE_SQL_MISMATCH")
        # This is the only data removal in the helper: the exact just-verified
        # unqualified table through the scratch-only account/connection.
        guard.check_disk()
        client.write((f"DROP TABLE {table};\nSELECT '{dropped.decode()}';\n").encode(), flush=True)
        require(client.until(dropped) == [], "UNEXPECTED_MYSQL_DROP_OUTPUT")
        client.finish()
        return {"name": name, "row_count": count, "checksum": actual_checksum, "create_sql_equal": True, "scratch_table_dropped": True}
    finally:
        client.abort()


def validate_insert(line, table):
    prefix = re.match(rb"^INSERT INTO `([A-Za-z0-9_$]+)` VALUES \(", line)
    require(prefix is not None and prefix[1].decode() == table, "INSERT_TABLE_SCOPE_MISMATCH")
    index = prefix.end()
    while index < len(line):
        value = line[index]
        if value == 39:
            index += 1
            closed = False
            while index < len(line):
                quote_index = line.find(b"'", index)
                if quote_index < 0:
                    break
                backslashes = 0
                position = quote_index - 1
                while position >= index and line[position] == 92:
                    backslashes += 1
                    position -= 1
                if backslashes % 2:
                    index = quote_index + 1
                elif quote_index + 1 < len(line) and line[quote_index + 1] == 39:
                    index = quote_index + 2
                else:
                    index = quote_index + 1
                    closed = True
                    break
            require(closed, "UNTERMINATED_INSERT_STRING")
        elif value == 59:
            require(line[index - 1:index] == b")" and not line[index + 1:].strip(), "MULTIPLE_OR_INCOMPLETE_INSERT_STATEMENTS")
            return prefix.end() - 1, index
        else:
            token = INSERT_VALUE.match(line, index)
            require(token is not None, "UNSAFE_INSERT_VALUE")
            index = token.end()
    raise VerificationError("INCOMPLETE_INSERT_STATEMENT")


def validate_statement(tokens):
    require(tokens and tokens[-1] == ("symbol", ";") and tokens.count(("symbol", ";")) == 1, "ONE_COMPLETE_SQL_STATEMENT_REQUIRED")
    for before, middle, after in zip(tokens, tokens[1:], tokens[2:]):
        if middle == ("symbol", ".") and before[0] in ("identifier", "word") and after[0] in ("identifier", "word"):
            raise VerificationError("QUALIFIED_SQL_IDENTIFIER_FORBIDDEN")
    forbidden = {"USE", "DATABASE", "SCHEMA", "GLOBAL", "OUTFILE", "INFILE", "LOAD", "CALL", "EXECUTE", "PREPARE", "DELIMITER", "PROCEDURE", "TRIGGER", "EVENT"}
    require(not any(kind == "word" and value.upper() in forbidden for kind, value in tokens), "UNSAFE_SQL_COMMAND")


class TableVerifier:
    def __init__(self, configuration, tables, guard, report):
        self.configuration, self.tables, self.guard, self.report = configuration, tables, guard, report
        self.header = bytearray()
        self.pending = bytearray()
        self.current = None
        self.client = None
        self.seen = set()
        self.created = False
        self.transaction_bytes = 0
        self.insert_batch_bytes = configuration.get("insert_batch_bytes", 0)
        self.batch_prefix = b""
        self.batch_values = bytearray()

    def write_insert(self, statement):
        self.client.write(statement)
        self.transaction_bytes += len(statement)
        if self.transaction_bytes >= TRANSACTION_BYTES:
            self.client.write(b"COMMIT;\nSET SESSION autocommit=0;\n")
            self.transaction_bytes = 0

    def flush_inserts(self):
        if self.batch_values:
            statement = self.batch_prefix + bytes(self.batch_values) + b";\n"
            self.batch_prefix = b""
            self.batch_values.clear()
            self.write_insert(statement)

    def accept_insert(self, line):
        values_start, values_end = validate_insert(line, self.current)
        if not self.insert_batch_bytes:
            self.write_insert(line)
            return
        prefix, values = line[:values_start], line[values_start:values_end]
        # Count the prefix, separators and statement terminator in the cap.
        single_size = len(prefix) + len(values) + 2
        if max(single_size, len(line)) > self.insert_batch_bytes:
            self.flush_inserts()
            self.write_insert(line)
            return
        if self.batch_values and len(self.batch_prefix) + len(self.batch_values) + 2 + len(values) + 2 > self.insert_batch_bytes:
            self.flush_inserts()
        if self.batch_values:
            require(prefix == self.batch_prefix, "INSERT_BATCH_TABLE_SCOPE_MISMATCH")
            self.batch_values.extend(b",\n")
        else:
            self.batch_prefix = prefix
        self.batch_values.extend(values)

    def consume(self, line):
        self.guard.check()
        if line.startswith(b"INSERT INTO "):
            require(not self.pending and self.client is not None and self.created, "INSERT_WITHOUT_TABLE_STRUCTURE")
            self.accept_insert(line)
            return
        self.flush_inserts()
        self.pending.extend(line)
        require(len(self.pending) <= MAX_SQL_BYTES, "SQL_STRUCTURE_TOO_LARGE")
        tokens, complete = sql_tokens(bytes(self.pending), incomplete=True)
        if not complete or (tokens and tokens[-1] != ("symbol", ";")):
            return
        raw = bytes(self.pending)
        self.pending.clear()
        if tokens:
            self.statement(raw, tokens)
        elif self.client is not None:
            self.client.write(raw)
        else:
            self.header.extend(raw)
            require(len(self.header) <= MAX_SQL_BYTES, "DUMP_HEADER_TOO_LARGE")

    def statement(self, raw, tokens):
        validate_statement(tokens)
        words = [value.upper() if kind == "word" else value for kind, value in tokens]
        if words[:4] == ["DROP", "TABLE", "IF", "EXISTS"]:
            require(len(tokens) == 6 and tokens[4][0] == "identifier", "UNSAFE_DROP_TABLE_STATEMENT")
            name = tokens[4][1]
            require(name in self.tables and name not in self.seen, "UNKNOWN_OR_DUPLICATE_DUMP_TABLE")
            self.finish_table()
            self.current = name
            self.seen.add(name)
            self.created = False
            self.transaction_bytes = 0
            self.client = Client(self.configuration, self.guard)
            self.client.write(bytes(self.header))
            self.client.write(b"SET SESSION time_zone='+00:00';\nSET SESSION foreign_key_checks=0;\nSET SESSION autocommit=0;\n")
        elif words[:2] == ["CREATE", "TABLE"]:
            require(self.client is not None and not self.created and tokens[2] == ("identifier", self.current), "CREATE_TABLE_SCOPE_MISMATCH")
            self.created = True
        elif words[:2] == ["LOCK", "TABLES"]:
            require(self.client is not None and len(tokens) == 5 and tokens[2] == ("identifier", self.current) and words[3] == "WRITE", "LOCK_TABLE_SCOPE_MISMATCH")
        elif words[:2] == ["UNLOCK", "TABLES"]:
            require(self.client is not None and len(tokens) == 3, "UNSAFE_UNLOCK_STATEMENT")
        elif words[:2] == ["ALTER", "TABLE"]:
            require(self.client is not None and len(tokens) == 6 and tokens[2] == ("identifier", self.current)
                    and words[3] in ("DISABLE", "ENABLE") and words[4] == "KEYS", "ALTER_TABLE_SCOPE_MISMATCH")
        elif words[0] == "SET":
            require(not any(kind == "word" and value.upper() in {"SELECT", "PERSIST", "PASSWORD"} for kind, value in tokens), "UNSAFE_SESSION_DIRECTIVE")
            if self.client is None:
                self.header.extend(raw)
                require(len(self.header) <= MAX_SQL_BYTES, "DUMP_HEADER_TOO_LARGE")
                return
        else:
            raise VerificationError("UNSUPPORTED_DUMP_STATEMENT")
        self.client.write(raw)

    def finish_table(self):
        if self.client is None:
            return
        self.flush_inserts()
        require(self.created, "DUMP_TABLE_CREATE_REQUIRED")
        client, self.client = self.client, None
        try:
            client.write(b"COMMIT;\n")
            client.finish()
        finally:
            client.abort()
        result = inspect_and_drop(self.configuration, self.guard, self.current, self.tables[self.current])
        self.report["table_results"].append(result)
        self.report["tables_verified"] = len(self.report["table_results"])
        append_json(self.configuration["progress_file"], {"event": "table_verified", **result,
                    "tables_verified": self.report["tables_verified"], "elapsed_seconds": round(time.monotonic() - self.guard.started, 3)})

    def finish(self):
        require(not self.pending, "INCOMPLETE_SQL_AT_END_OF_DUMP")
        self.finish_table()
        require(self.seen == set(self.tables), "MISSING_DUMP_TABLES")

    def abort(self):
        self.batch_prefix = b""
        self.batch_values.clear()
        if self.client is not None:
            self.client.abort()
            self.client = None


def raw_lines(source, guard, digest, report):
    pending = bytearray()
    progress_bytes = 0
    while True:
        guard.check_disk()
        block = source.read(BLOCK_BYTES)
        if not block:
            break
        digest.update(block)
        report["uncompressed_bytes"] += len(block)
        if report["uncompressed_bytes"] - progress_bytes >= 64 * BLOCK_BYTES:
            append_json(guard.configuration["progress_file"], {"event": "stream_progress",
                        "uncompressed_bytes": report["uncompressed_bytes"], "tables_verified": report["tables_verified"],
                        "elapsed_seconds": round(time.monotonic() - guard.started, 3)})
            progress_bytes = report["uncompressed_bytes"]
        pending.extend(block)
        start = 0
        while True:
            end = pending.find(b"\n", start)
            if end < 0:
                break
            require(end + 1 - start <= MAX_LINE_BYTES, "DUMP_LINE_EXCEEDS_MYSQL_PACKET")
            yield bytes(pending[start:end + 1])
            start = end + 1
        if start:
            del pending[:start]
        require(len(pending) <= MAX_LINE_BYTES, "DUMP_LINE_EXCEEDS_MYSQL_PACKET")
    if pending:
        yield bytes(pending)


def verify(configuration):
    report = {"protocol": PROTOCOL, "completed": False, "reason_code": "VERIFICATION_NOT_COMPLETED",
              "tables_verified": 0, "table_results": [], "uncompressed_bytes": 0}
    guard = verifier = None
    receipts_safe = safe_receipt_paths(configuration)
    try:
        inventory, tables, manifest = validate_configuration(configuration)
        report.update({"source_database": inventory["database"], "scratch_database": configuration["scratch_database"],
                       "table_count": len(tables), "source_accessed": False})
        guard = Guard(configuration)
        guard.check_disk()
        append_json(configuration["progress_file"], {"event": "verification_started", "table_count": len(tables)})
        compressed = hashlib.sha256()
        compressed_bytes = 0
        with open(configuration["backup_file"], "rb") as source:
            while True:
                guard.check_disk()
                block = source.read(BLOCK_BYTES)
                if not block:
                    break
                compressed.update(block)
                compressed_bytes += len(block)
        report["compressed_sha256"] = compressed.hexdigest()
        require(compressed_bytes == manifest["bytes"] and compressed.hexdigest() == manifest["sha256"], "COMPRESSED_BACKUP_HASH_OR_SIZE_MISMATCH")
        verifier = TableVerifier(configuration, tables, guard, report)
        uncompressed = hashlib.sha256()
        with gzip.open(configuration["backup_file"], "rb") as source:
            for line in raw_lines(source, guard, uncompressed, report):
                verifier.consume(line)
        report["uncompressed_sha256"] = uncompressed.hexdigest()
        require(report["uncompressed_bytes"] == manifest["uncompressed_bytes"] and uncompressed.hexdigest() == manifest["uncompressed_sha256"], "UNCOMPRESSED_BACKUP_HASH_OR_SIZE_MISMATCH")
        verifier.finish()
        guard.check_disk()
        report.update({"completed": True, "reason_code": "ALL_BACKUP_TABLES_RESTORED_AND_VERIFIED",
                       "gzip_crc_verified": True, "all_table_inventory_matched": True})
    except BaseException as error:
        report["reason_code"] = str(error) if isinstance(error, VerificationError) else type(error).__name__
    finally:
        if verifier is not None:
            try:
                verifier.abort()
            except BaseException:
                report.update({"completed": False, "reason_code": "MYSQL_CHILD_CLEANUP_FAILED"})
        if guard is not None:
            guard.close()
            report["elapsed_seconds"] = round(time.monotonic() - guard.started, 3)
        if receipts_safe:
            try:
                append_json(configuration["report_file"], report)
            except (OSError, ValueError):
                report["completed"] = False
                report["reason_code"] = "VERIFICATION_RECEIPT_WRITE_FAILED"
    return report


def main():
    try:
        configuration = json.load(sys.stdin)
    except (ValueError, OSError):
        print(json.dumps({"completed": False, "reason_code": "CONFIGURATION_JSON_REQUIRED"}))
        return 1
    report = verify(configuration)
    print(json.dumps({key: value for key, value in report.items() if key != "table_results"}))
    return 0 if report["completed"] else 1


if __name__ == "__main__":
    sys.exit(main())
