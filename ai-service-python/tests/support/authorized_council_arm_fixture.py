"""Test-only original PHP request -> real Python full arm (no market proof)."""
import argparse
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
import pandas as pd
from app import main
from app.schemas import SimpleBacktestRequest
from app.services import research_release

parser = argparse.ArgumentParser()
parser.add_argument('--test-data-root', required=True)
parser.add_argument('--fixture-clock', required=True)
args = parser.parse_args()
research_release._research_data_roots = lambda: (Path(args.test_data_root),)
research_release._research_transport_now = lambda: pd.Timestamp(args.fixture_clock)
# The owner's real original HMAC, data bytes, source, engine and scope checks
# remain active. Only test-clock/source root and disposable cache I/O differ.
main._load_immutable_replay_cache = lambda *args: None
main._store_immutable_replay_cache = lambda *args, **kwargs: None
main._write_replay_checkpoint = lambda *args, **kwargs: None
payload = SimpleBacktestRequest.model_validate(json.load(sys.stdin))
print(json.dumps(main._run_all_backtests_sync(payload), allow_nan=False, separators=(',', ':')))
