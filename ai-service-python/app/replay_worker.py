"""Bounded child entry point for heavy replay requests.

The HTTP process owns the deadline and may terminate this worker at any time.
The worker never writes database state; it returns only serialized replay
evidence to the parent process.
"""

from __future__ import annotations

import json
import sys

from fastapi import HTTPException

from app.main import _run_all_backtests_sync, _run_portfolio_backtest_sync
from app.schemas import SimpleBacktestRequest


def main() -> int:
    try:
        envelope = json.loads(sys.stdin.read() or "{}")
        operation = str(envelope.get("operation", "run_all"))
        payload = SimpleBacktestRequest.model_validate(envelope.get("payload", {}))
        value = (
            _run_all_backtests_sync(payload)
            if operation == "run_all"
            else _run_portfolio_backtest_sync(payload)
        )
        message = {"ok": True, "value": value}
    except FileNotFoundError as exc:
        message = {"ok": False, "kind": "not_found", "detail": str(exc)}
    except ValueError as exc:
        message = {"ok": False, "kind": "value", "detail": str(exc)}
    except HTTPException as exc:
        message = {
            "ok": False,
            "kind": "http",
            "status": int(exc.status_code),
            "detail": str(exc.detail),
        }
    except Exception as exc:  # pragma: no cover - fault boundary
        message = {"ok": False, "kind": "runtime", "detail": f"{type(exc).__name__}: {exc}"}

    sys.stdout.write(json.dumps(message, ensure_ascii=False, default=str, separators=(",", ":")))
    sys.stdout.flush()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
