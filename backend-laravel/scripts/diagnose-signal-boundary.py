"""Read-only reconstruction of one immutable screening request; no credit writes."""
import argparse
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai-service-python"))
from app.schemas import SimpleBacktestRequest
from app.services.backtester import _load_simple_candles, prepare_feature_snapshot, _timeframe_duration_minutes
from app.strategies.registry import get_strategy
from app.services.volume_features import apply_volume_policy
from app.services.composition_runtime import apply_composition_entry_contract
from app.services.market_sessions import apply_specialist_scope

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("agent_id", type=int, help="LabAgent ID with a completed immutable screening request")
agent = parser.parse_args().agent_id
php = r'''require "vendor/autoload.php"; $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection(); $db->statement("SET TRANSACTION READ ONLY"); $db->beginTransaction();
$run=App\Models\LabEvaluationRun::where("lab_agent_id",AGENT)->where("phase","screening")->where("status","completed")->latest("id")->firstOrFail();
echo json_encode(["run_id"=>$run->run_id,"code_hash"=>$run->code_hash,"payload"=>$run->request_meta["payload"]]); $db->rollBack();'''.replace("AGENT", str(agent))
record = json.loads(subprocess.check_output(["php", "-r", php], cwd=ROOT / "backend-laravel"))
payload = record["payload"]
config = next(row for row in payload["strategies"] if int(row.get("lab_agent_id", 0)) == agent)
payload.update(config)
payload["strategies"] = []
# This is a current-code diagnostic reconstruction, NOT a replacement for the
# original market receipt or a release-attested canonical replay.
payload["research_release"] = {}
for name, field in SimpleBacktestRequest.model_fields.items():
    if payload.get(name) == [] and field.annotation is not None and str(field.annotation).startswith("dict["):
        payload[name] = {}
request = SimpleBacktestRequest.model_validate(payload)
data = _load_simple_candles(request).sort_values("time").tail(5000).reset_index(drop=True)
frame = prepare_feature_snapshot(request, data).frame
report = {"agent": agent, "run_id": record["run_id"], "base_strategy": request.base_strategy,
          "source_code_hash": record["code_hash"], "rows": len(frame), "stages": {},
          "specialist_scope": request.specialist_context_contract,
          "status": "diagnostic_reconstruction_only", "canonical_evidence": False,
          "promotion_evidence": False}
def summarize(name, value):
    report["stages"][name] = {"signals": int(value["signal"].isin(["BUY", "SELL"]).sum()),
                             "signals_after_warmup": int(value.iloc[200:]["signal"].isin(["BUY", "SELL"]).sum())}
    for column in ["market_regime", "volatility_regime", "selected_specialist", "specialist_scope_reason", "composition_decision_reason"]:
        if column in value:
            report["stages"][name][column] = value[column].value_counts().to_dict()
frame = get_strategy(request.strategy, request.base_strategy)(frame, request.parameters)
summarize("strategy", frame)
frame = apply_volume_policy(frame, request.parameters, request.base_strategy or request.strategy)
summarize("volume", frame)
frame = apply_specialist_scope(frame, request.specialist_context_contract, _timeframe_duration_minutes(request.timeframe))
summarize("specialist", frame)
report["specialist_scope_receipt"] = frame.attrs.get("specialist_context_contract", {})
frame = apply_composition_entry_contract(frame, request.composition_runtime_contract)
summarize("composition", frame)
print(json.dumps(report, indent=2, default=str))
