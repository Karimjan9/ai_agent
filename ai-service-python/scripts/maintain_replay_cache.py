"""Migrate and bound the rebuildable replay cache.

This cache is never promotion evidence.  The script preserves each JSON
document byte-for-byte at the semantic level, writes gzip atomically, and
only removes the legacy source after the compressed document can be read back.
Run without ``--apply`` first.
"""

from __future__ import annotations

import argparse
import gzip
import json
import os
import time
from pathlib import Path


def cache_root() -> Path:
    configured = os.getenv("AI_REPLAY_IMMUTABLE_CACHE_DIR", "")
    return Path(configured) if configured else Path(__file__).resolve().parents[1] / ".runtime" / "replay-cache"


def migrate(path: Path, apply: bool) -> tuple[bool, int]:
    target = path.with_suffix(".json.gz")
    if not apply:
        # Dry-run must not parse multi-megabyte legacy documents.  The apply
        # path performs the full semantic round-trip validation below.
        try:
            return True, path.stat().st_size
        except OSError:
            return False, 0
    try:
        raw = path.read_bytes()
        document = json.loads(raw.decode("utf-8"))
        encoded = json.dumps(document, ensure_ascii=False, separators=(",", ":"), default=str).encode("utf-8")
        compressed = gzip.compress(encoded, compresslevel=1)
        temporary = target.with_suffix(".json.gz.tmp")
        temporary.write_bytes(compressed)
        # Validate the new file before replacing/removing the legacy copy.
        with gzip.open(temporary, "rb") as handle:
            if json.loads(handle.read().decode("utf-8")) != document:
                temporary.unlink(missing_ok=True)
                return False, 0
        os.replace(temporary, target)
        path.unlink()
        return True, len(compressed)
    except (OSError, ValueError, UnicodeError, gzip.BadGzipFile):
        return False, 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true", help="Write gzip files and remove verified legacy JSON")
    parser.add_argument("--max-files", type=int, default=32, help="Maximum legacy files to inspect per invocation")
    parser.add_argument("--retention-days", type=int, default=int(os.getenv("AI_REPLAY_CACHE_RETENTION_DAYS", "14")))
    parser.add_argument("--max-bytes", type=int, default=int(os.getenv("AI_REPLAY_CACHE_MAX_BYTES", str(1536 * 1024 * 1024))))
    args = parser.parse_args()

    root = cache_root()
    root.mkdir(parents=True, exist_ok=True)
    legacy = sorted(root.glob("*.json"), key=lambda item: item.stat().st_mtime if item.exists() else 0)
    migrated = 0
    compressed_bytes = 0
    for path in legacy[: max(1, args.max_files)]:
        ok, size = migrate(path, args.apply)
        if ok:
            migrated += 1
            compressed_bytes += size

    cutoff = time.time() - max(1, args.retention_days) * 86400
    files = []
    for path in [*root.glob("*.json.gz"), *root.glob("*.json")]:
        try:
            stat = path.stat()
            files.append((path, stat.st_mtime, stat.st_size))
        except OSError:
            continue

    expired = [item for item in files if item[1] < cutoff]
    total = sum(item[2] for item in files)
    over_limit = []
    for item in sorted(files, key=lambda value: value[1]):
        if total <= max(64 * 1024 * 1024, args.max_bytes):
            break
        over_limit.append(item)
        total -= item[2]

    if args.apply:
        for path, _, _ in [*expired, *over_limit]:
            try:
                path.unlink()
            except OSError:
                pass

    print(json.dumps({
        "protocol": "replay_cache_maintenance_v1",
        "root": str(root),
        "mode": "apply" if args.apply else "dry_run",
        "legacy_candidates": len(legacy),
        "migrated": migrated if args.apply else 0,
        "would_migrate": migrated if not args.apply else 0,
        "compressed_bytes": compressed_bytes if args.apply else 0,
        "expired_candidates": len(expired),
        "over_limit_candidates": len(over_limit),
        "promotion_evidence": False,
    }, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
