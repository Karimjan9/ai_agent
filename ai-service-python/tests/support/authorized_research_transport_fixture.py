"""Synthetic cross-runtime admission fixture, not production data authorization."""
import argparse
import ast
import json
import os
import sys
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--test-data-root", required=True)
parser.add_argument("--fixture-clock", default="2028-01-01T00:00:00Z")
args = parser.parse_args()
root = Path(__file__).resolve().parents[3] if "tests" in Path(__file__).parts else Path(__file__).resolve().parents[2]
sys.path.insert(0, str(root / "ai-service-python"))
from app.services import research_release
if os.getenv("AUTHORIZED_RESEARCH_TRANSPORT_STAGING") == "1":
    stage = root / ".runtime/authorized-research-transport-staging-2026-10-03"
    exec(compile((stage / "research_release.py").read_text(encoding="utf-8"), research_release.__file__, "exec"), research_release.__dict__)
from app import main
if os.getenv("AUTHORIZED_RESEARCH_TRANSPORT_STAGING") == "1":
    source = (stage / "main.py").read_text(encoding="utf-8")
    tree = ast.parse(source)
    main.verify_research_transport = research_release.verify_research_transport
    for node in tree.body:
        if isinstance(node, ast.FunctionDef) and node.name == "_assert_non_paper_source_pre_2026":
            exec(compile(ast.Module(body=[node], type_ignores=[]), main.__file__, "exec"), main.__dict__)
from app.schemas import SimpleBacktestRequest
from app.services.backtester import _load_verified_dataset_csv
import pandas as pd

research_release._research_data_roots = lambda: (Path(args.test_data_root),)
research_release._research_transport_now = lambda: pd.Timestamp(args.fixture_clock)
payload = SimpleBacktestRequest.model_validate(json.load(sys.stdin))
signed = research_release.verify_research_transport(payload, main._internal_api_token())
frame = _load_verified_dataset_csv(payload, payload.dataset_path, str(payload.timeframe).upper())
main._assert_non_paper_source_pre_2026(payload, frame)
print(json.dumps({"admission_verified": signed is not None, "source_rows": len(frame),
    "contract_hash": signed["contract_hash"], "window_key": signed["window"]["window_key"],
    "independent_evidence": False, "promotion_evidence": False}))
