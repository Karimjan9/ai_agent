"""Execute one authenticated original component/descendant window, not a certificate.

These frozen programmes do not train on the assessment events. The ordinary
stateful executor, costs, closed MTF snapshots and hard-risk observations are
retained; neither a historical survivor contract nor a council identity is borrowed.
"""
import pandas as pd

from app.services.scoped_research_runtime import scoped_research_runtime_current

PURPOSES = frozenset({"independent_scoped_component_research", "independent_scoped_descendant_research"})
POLICY_FIELDS = frozenset({"protocol", "evaluation_mode", "selection", "maximum_source_rows",
    "maximum_runtime_seconds", "warmup_rows", "no_walk_forward_selection", "promotion_evidence"})


def original_scoped_trade_context(result):
    """Attribute actual closed outcomes without inventing an instrument assignment."""
    from app.schemas import SimpleTrade
    from app.services.backtester import _robustness_matrix, _trade_ledger_hash
    from app.services.instrument_research import _context_slices
    ledger = result.get("trade_ledger")
    if not isinstance(ledger, list) or len(ledger) != result.get("total_trades"):
        raise ValueError("SCOPED_NATIVE_ORIGINAL_CONTEXT_TRADE_LEDGER_REQUIRED")
    original = [SimpleTrade.model_validate(trade) for trade in ledger]
    digest = _trade_ledger_hash(original)
    if result.get("trade_ledger_hash") != digest:
        raise ValueError("SCOPED_NATIVE_ORIGINAL_CONTEXT_TRADE_LEDGER_DRIFT")
    return {"protocol": "scoped_original_trade_context_v1", "context_source": "decision_time_trade_ledger",
        "context_slice_protocol": "venue_phase_v1", "trade_ledger_hash": digest, "trade_ledger_count": len(original),
        "exact_context_slices": _context_slices({"robustness_matrix": _robustness_matrix(original)}, exact=True),
        "instrument_assignment_or_activation_claimed": False, "independent_evidence": False, "promotion_evidence": False}


def original_scoped_runtime_policy(payload):
    declaration = (payload.policy_context or {}).get("scoped_research_certificate")
    if not isinstance(declaration, dict) or declaration.get("purpose") not in PURPOSES:
        return None
    if payload.evaluation_mode != "full" or not scoped_research_runtime_current(payload):
        raise ValueError("SCOPED_NATIVE_ORIGINAL_AUTHENTICATED_FULL_REQUIRED")
    policy = (payload.policy_context or {}).get("full_replay_runtime_policy")
    if (not isinstance(policy, dict) or set(policy) != POLICY_FIELDS
            or policy.get("protocol") != "scoped_original_full_source_v1"
            or policy.get("evaluation_mode") != "full" or policy.get("selection") != "entire_authorized_source"
            or type(policy.get("maximum_source_rows")) is not int or not 201 <= policy["maximum_source_rows"] <= 200000
            or type(policy.get("maximum_runtime_seconds")) is not int or not 30 <= policy["maximum_runtime_seconds"] <= 1620
            or type(policy.get("warmup_rows")) is not int or policy["warmup_rows"] != 0
            or policy.get("no_walk_forward_selection") is not True or policy.get("promotion_evidence") is not False):
        raise ValueError("SCOPED_NATIVE_ORIGINAL_FULL_POLICY_REQUIRED")
    return policy


def run_scoped_original_full_window(payload, frame, run_replay):
    policy = original_scoped_runtime_policy(payload)
    if policy is None or not scoped_research_runtime_current(payload, frame):
        raise ValueError("SCOPED_NATIVE_ORIGINAL_AUTHENTICATED_FULL_REQUIRED")
    if not 201 <= len(frame) <= policy["maximum_source_rows"]:
        raise ValueError("SCOPED_NATIVE_ORIGINAL_SOURCE_ROW_BUDGET_REQUIRED")
    signed = payload.policy_context["authorized_research_transport"]
    source = signed["files"]["M5"]
    times = pd.to_datetime(frame["time"], utc=True, errors="coerce")
    if (len(frame) != source["rows"] or times.iloc[0] != pd.Timestamp(source["start_inclusive"])
            or times.iloc[-1] != pd.Timestamp(source["last_candle_at"])):
        raise ValueError("SCOPED_NATIVE_ORIGINAL_ENTIRE_SOURCE_REQUIRED")
    response = run_replay(payload, frame, include_differential_pair=False, lightweight=False)
    result = response.model_dump() if hasattr(response, "model_dump") else dict(response)
    result["scoped_research_context_trace"] = original_scoped_trade_context(result)
    clock = (result.get("data_quality") or {}).get("replay_executed_clock")
    if (not isinstance(clock, dict) or clock.get("complete") is not True
            or clock.get("input_rows") != len(frame) or clock.get("decision_rows") != len(frame) - 200
            or clock.get("first_evaluation_index") != 200 or clock.get("last_evaluation_index") != len(frame) - 1):
        raise ValueError("SCOPED_NATIVE_ORIGINAL_EXECUTED_CLOCK_REQUIRED")
    result["data_quality"] = {**(result.get("data_quality") or {}), "scoped_original_window": {
        "protocol": "scoped_original_full_window_result_v1", "purpose": payload.policy_context["scoped_research_certificate"]["purpose"],
        "window_key": signed["window"]["window_key"], "source_rows": len(frame), "decision_rows": len(frame) - 200,
        "selection": "entire_authorized_source", "historical_survivor_evidence": False,
        "independent_evidence": False, "promotion_evidence": False}}
    return {"result": result, "train_score": 0, "validation_score": 0, "forward_score": 0,
        "forward_window_scores": [], "rolling_windows_count": 0, "robustness_score": 0, "is_overfit": False}
