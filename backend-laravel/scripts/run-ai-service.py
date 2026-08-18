import os
import json
import socket
import sys
import time
from urllib.request import urlopen
from pathlib import Path

import uvicorn

backend_root = Path(__file__).resolve().parents[1]
root = backend_root.parent / "ai-service-python"
sys.path.insert(0, str(root))
os.chdir(root)

# PM2 normally supplies this through ecosystem.config.cjs.  A stale Windows
# PM2 daemon (or a direct supervisor restart) can lose that non-secret path
# while Laravel still has the protected token available.  Resolve the local
# protected file as a fail-closed fallback; never copy the token into the
# process command line or a plain environment value.
configured_token_file = os.getenv("INTERNAL_API_TOKEN_FILE", "").strip()
runtime_token_file = backend_root.parent / "runtime" / "internal-api.token"
legacy_token_file = backend_root / "storage" / "app" / "secrets" / "internal-api.token"
# The workspace runtime secret is the coordinated rotation target. Prefer it
# even when a stale PM2 environment still points at the old protected file;
# otherwise Laravel and Python can each be healthy while authenticating with
# different tokens. Fall back to the configured/legacy path only when the
# rotated file is absent.
if runtime_token_file.is_file():
    os.environ["INTERNAL_API_TOKEN_FILE"] = str(runtime_token_file)
else:
    default_token_file = Path(configured_token_file) if configured_token_file else legacy_token_file
    if default_token_file.is_file():
        os.environ["INTERNAL_API_TOKEN_FILE"] = str(default_token_file)

port = int(os.getenv("AI_SERVICE_PORT", "9000"))


def _healthy_peer(port_number: int) -> bool:
    """Return true only for the expected local AI service, not any port user."""
    try:
        with urlopen(f"http://127.0.0.1:{port_number}/health", timeout=1.5) as response:
            if response.status != 200:
                return False
            body = json.loads(response.read(4096).decode("utf-8", errors="replace"))
            # /health intentionally carries additive replay-liveness metrics.
            # Supervisor ownership only depends on the stable identity fields;
            # exact-dict comparison makes a healthy peer look absent and
            # causes repeated bind attempts on Windows.
            return (
                isinstance(body, dict)
                and body.get("status") == "ok"
                and body.get("service") == "neurotrader-ai-service"
            )
    except Exception:
        return False


def _port_accepting(port_number: int) -> bool:
    try:
        with socket.create_connection(("127.0.0.1", port_number), timeout=0.5):
            return True
    except OSError:
        return False


# More than one stale Windows supervisor can briefly launch this entry point.
# Keep only one healthy listener and leave duplicate supervisors online but
# idle; otherwise PM2 repeatedly respawns bind-failing processes and can
# interrupt a legitimate response. If the peer disappears, one instance wins
# the bind race and the others retry without bypassing the health boundary.
while True:
    if _healthy_peer(port):
        time.sleep(5)
        continue
    try:
        uvicorn.run("app.main:app", host="127.0.0.1", port=port)
    except SystemExit as exc:
        # Uvicorn reports a Windows bind collision as SystemExit(1), not
        # OSError. If another healthy instance owns the port, stay online and
        # let this duplicate supervisor become the standby instead of making
        # PM2 respawn it in a tight loop. Other startup failures remain loud.
        if _port_accepting(port):
            time.sleep(5)
            continue
        raise exc
    except OSError as exc:
        if not _port_accepting(port) and "10048" not in str(exc) and "address already in use" not in str(exc).lower():
            raise
        time.sleep(5)
    else:
        # Uvicorn can return after a bind failure without raising on Windows;
        # always back off before the next ownership attempt.
        time.sleep(5)
