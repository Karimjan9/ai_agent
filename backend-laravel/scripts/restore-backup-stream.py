"""Restore only through an account restricted to the drill schema; never show credentials."""
import gzip
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import time


def main():
    configuration = json.load(sys.stdin)
    if not configuration["database"].startswith("codex_restore_"):
        raise ValueError("ISOLATED_RESTORE_DATABASE_REQUIRED")
    command = [configuration["mysql_binary"], "--protocol=TCP", "--host=" + configuration["host"],
               "--port=" + str(configuration["port"]), "--user=" + configuration["user"],
               "--max-allowed-packet=1G", "--default-character-set=utf8mb4", configuration["database"]]
    environment = {**os.environ, "MYSQL_PWD": configuration["password"]}
    with open(configuration["error_file"], "wb") as errors:
        process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=errors,
                                   env=environment, creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0)
        digest = hashlib.sha256()
        size = 0
        transaction_bytes = 0
        progress_bytes = 0
        started = time.monotonic()
        original_tables = set()
        try:
            process.stdin.write(b"SET SESSION autocommit=0;\n")
            with gzip.open(configuration["backup_file"], "rb") as source:
                for block in source:
                    if shutil.disk_usage(configuration["data_directory"]).free < 2 * 1024 ** 3:
                        raise RuntimeError("RESTORE_DISK_RESERVE_REQUIRED")
                    digest.update(block)
                    size += len(block)
                    if block.startswith(b"CREATE TABLE "):
                        table = re.match(rb"CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`", block)
                        if table:
                            original_tables.add(table[1].decode("utf-8"))
                    process.stdin.write(block)
                    transaction_bytes += len(block)
                    # Original dump INSERTs escape embedded newlines. Commit only
                    # after a complete statement, bounding undo and fsync costs.
                    if (transaction_bytes >= 8 * 1024 ** 2
                            and block.startswith(b"INSERT INTO ") and block.rstrip().endswith(b";")):
                        process.stdin.write(b"COMMIT;\nSET SESSION autocommit=0;\n")
                        transaction_bytes = 0
                    if size - progress_bytes >= 64 * 1024 ** 2:
                        with open(configuration["progress_file"], "w", encoding="utf-8") as progress:
                            json.dump({"original_bytes_streamed": size, "elapsed_seconds": round(time.monotonic() - started, 1)}, progress)
                        progress_bytes = size
            process.stdin.write(b"COMMIT;\n")
            process.stdin.close()
            exit_code = process.wait(timeout=60)
            if exit_code != 0:
                raise RuntimeError("ISOLATED_MYSQL_RESTORE_FAILED")
            if digest.hexdigest() != configuration["uncompressed_sha256"]:
                raise RuntimeError("RESTORED_STREAM_HASH_MISMATCH")
            print(json.dumps({"restored_bytes": size, "restored_stream_sha256": digest.hexdigest(), "mysql_exit_code": exit_code,
                              "session_bulk_transactions": True, "original_backup_table_names": sorted(original_tables)}))
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(json.dumps({"completed": False, "reason_code": str(error) if isinstance(error, (ValueError, RuntimeError)) else type(error).__name__}))
        sys.exit(1)
