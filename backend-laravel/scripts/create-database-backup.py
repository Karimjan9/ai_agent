"""Stream a native database dump into an atomic gzip backup without shell windows."""
import gzip
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import re
import shutil
import tempfile
import threading
from datetime import datetime, timezone


def main():
    configuration = json.load(sys.stdin)
    directory = Path(configuration['directory']).resolve(strict=True)
    if os.name == 'nt' and directory.drive.upper() not in ('D:', 'G:'):
        raise RuntimeError('BACKUP_D_OR_G_DRIVE_REQUIRED')
    stamp = datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')
    final = directory / f'neurotrader_{stamp}.sql.gz'
    temporary = Path(str(final) + '.partial')
    if final.exists() or temporary.exists():
        raise RuntimeError('NEW_BACKUP_PATH_REQUIRED')
    command = [configuration['mysqldump_binary'], '--single-transaction', '--quick', '--routines', '--triggers', '--events',
               '--max-allowed-packet=1G',
               '--host=' + configuration['host'], '--port=' + str(configuration['port']),
               '--user=' + configuration['username'], '--default-character-set=utf8mb4', configuration['database']]
    layout = configuration.get('verification_layout')
    if layout is not None and layout != 'per_table_v1':
        raise RuntimeError('UNKNOWN_BACKUP_VERIFICATION_LAYOUT')
    if layout == 'per_table_v1':
        command[1:1] = ['--skip-extended-insert', '--order-by-primary', '--hex-blob']
    environment = {**os.environ, 'MYSQL_PWD': configuration['password']}
    digest = hashlib.sha256()
    size = 0
    with tempfile.TemporaryFile() as errors:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=errors, env=environment,
                                   creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
        def stop_native_dump():
            if process.poll() is None:
                process.kill()

        native_deadline = threading.Timer(max(1, int(configuration.get('native_timeout_seconds', 2700))), stop_native_dump)
        native_deadline.daemon = True
        native_deadline.start()
        try:
            with gzip.open(temporary, 'xb', compresslevel=1) as output:
                while block := process.stdout.read(1024 * 1024):
                    if shutil.disk_usage(directory).free < int(configuration.get('reserve_bytes', 2 * 1024 ** 3)):
                        raise RuntimeError('BACKUP_DISK_RESERVE_REQUIRED')
                    output.write(block)
                    digest.update(block)
                    size += len(block)
            process.stdout.close()
            if process.wait(timeout=60) != 0 or size == 0:
                errors.seek(0)
                diagnostic = errors.read(8192).decode('utf-8', errors='replace')
                code = re.search(r'(?:Error|ERROR|errno)\D{0,12}(\d+)', diagnostic)
                table = re.search(r"(?:dumping table|table) ['`]([a-zA-Z0-9_]+)['`]", diagnostic)
                details = {'completed': False, 'reason_code': 'BACKUP_NATIVE_DUMP_FAILED' + ('_' + code[1] if code else ''),
                           'dump_bytes_before_failure': size, 'table': table[1] if table else None}
                failure = RuntimeError(details['reason_code'])
                failure.details = details
                raise failure
        except BaseException:
            if process.poll() is None:
                process.kill()
                process.wait()
            temporary.unlink(missing_ok=True)
            raise
        finally:
            native_deadline.cancel()
    verified = hashlib.sha256()
    verified_size = 0
    with gzip.open(temporary, 'rb') as source:
        while block := source.read(1024 * 1024):
            verified.update(block)
            verified_size += len(block)
    if verified_size != size or verified.hexdigest() != digest.hexdigest():
        raise RuntimeError('BACKUP_GZIP_VERIFICATION_FAILED')
    with temporary.open('rb') as source:
        compressed_digest = hashlib.file_digest(source, 'sha256').hexdigest()
    manifest = {'schema': 'neurotrader-db-backup/v1', 'database': configuration['database'],
                'created_at_utc': datetime.now(timezone.utc).isoformat(), 'file': final.name,
                'bytes': temporary.stat().st_size, 'sha256': compressed_digest, 'compression': 'gzip',
                'uncompressed_bytes': size, 'uncompressed_sha256': digest.hexdigest(), 'backup_path': str(final),
                'gzip_crc_and_stream_hash_verified': True}
    if layout is not None:
        manifest['verification_layout'] = layout
    manifest_final = Path(str(final) + '.manifest.json')
    manifest_temporary = Path(str(manifest_final) + '.partial')
    manifest_temporary.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    temporary.replace(final)
    manifest_temporary.replace(manifest_final)
    print(json.dumps(manifest))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps(getattr(error, 'details', {'completed': False, 'reason_code': str(error) if isinstance(error, RuntimeError) else type(error).__name__})))
        sys.exit(1)
