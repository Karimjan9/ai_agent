"""Read-only scalp diagnostics from original full-close witnesses and CSV clocks.

This is an offline projection, not another executor, an agent outcome, an
economic certificate, or a source of marked-equity/tick-fill observations.
"""
from __future__ import annotations

from bisect import bisect_left, bisect_right
import csv
import hashlib
import importlib.util
import io
import json
import math
from pathlib import Path

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import execution_contract_metadata
from app.services.market_sessions import session_membership
from app.services.replay_executed_clock import INDEX_DOMAIN, SCHEDULE_DOMAIN
from app.services.research_program_tasks import canonical_hash, canonical_json

_SPEC = importlib.util.spec_from_file_location("_scalp_full_close_owner",
    Path(__file__).with_name("curator_management_witness.py"))
_OWNER = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_OWNER)
verify_management_witness = _OWNER.verify_management_witness

PROTOCOL = "offline_curator_scalp_metrics_v2"
CALENDAR_VERSION = "xauusd_market_sessions_2026_v2"
TRUSTED_CSV_MANIFEST_SHA256 = "b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92"
REGISTERED_CSV_TIME_SEMANTICS = "original_M5_open_in_UTC_decision_inputs_available_only_after_closure"


def _need(ok, reason):
    if not ok:
        raise ValueError(reason)


def _stamp(value):
    result = pd.Timestamp(value)
    _need(not pd.isna(result) and result.tzinfo is not None, "SCALP_UTC_TIMESTAMP_REQUIRED")
    return result.tz_convert("UTC")


def _number(value):
    _need(not isinstance(value, bool) and isinstance(value, (int, float)) and math.isfinite(value),
          "SCALP_FINITE_NUMBER_REQUIRED")
    return float(value)


def _same(actual, expected, reason):
    _need(math.isclose(_number(actual), _number(expected), rel_tol=1e-10, abs_tol=1e-7), reason)


def _object(value):
    return value.model_dump() if hasattr(value, "model_dump") else value


def _percentile(values, fraction):
    if not values:
        return None
    values = sorted(values)
    location = (len(values) - 1) * fraction
    lower, upper = math.floor(location), math.ceil(location)
    return values[lower] + (values[upper] - values[lower]) * (location - lower)


def _registered_csv_binding(path, source_hash, request, rows, provenance):
    """Only the pinned original registration can authorize naive CSV UTC cells.

    A copied/sideways CSV, a caller-declared timezone or an arbitrary adjacent
    manifest is not a registration. This never decodes witness/event clocks.
    """
    path = Path(path).resolve()
    if provenance is None:
        manifest_path = path.parent / "provenance-manifest.json"
        window_key = None
    else:
        _need(isinstance(provenance, dict) and set(provenance) ==
              {"manifest_path", "manifest_sha256", "window_key"}, "SCALP_EXACT_CSV_PROVENANCE_REQUIRED")
        manifest_path = Path(provenance["manifest_path"]).resolve()
        window_key = provenance["window_key"]
    _need(manifest_path.is_file(), "SCALP_REGISTERED_CSV_UTC_PROVENANCE_REQUIRED")
    raw_manifest = manifest_path.read_bytes()
    manifest_identity = hashlib.sha256(raw_manifest).hexdigest()
    _need(provenance is None or manifest_identity == provenance['manifest_sha256'],
          "SCALP_TRUSTED_CSV_MANIFEST_REQUIRED")
    manifest = json.loads(raw_manifest)
    registration = 'pinned_original_csv_utc_registration_v1'
    if manifest_identity == TRUSTED_CSV_MANIFEST_SHA256:
        _need(manifest.get('protocol') == 'curator_raw_context_window_preparation_v1',
              'SCALP_REGISTERED_CSV_UTC_SEMANTICS_REQUIRED')
    elif manifest.get('protocol') == 'curator_complete_month_window_v1':
        # The named owner independently reconstructs the one exact whole
        # October scope, native parent, warmup, calendar edges and raw cells.
        # A protocol string or caller-provided manifest hash is not proof.
        from scripts.curator_bollinger_month_window import validate_manifest
        manifest = validate_manifest(manifest_path, manifest_identity)
        registration = 'rederived_original_complete_month_csv_utc_registration_v1'
    elif manifest.get('protocol') == 'curator_intraday_replication_windows_v1':
        # Reconstruct both exact parent month slices through the code-owned
        # producer, including native custody/calendar and the F-tree boundary.
        from scripts.curator_intraday_month_windows import validate_manifest
        manifest = validate_manifest(manifest_path, manifest_identity)
        registration = 'rederived_original_intraday_replication_csv_utc_registration_v1'
    else:
        _need(manifest.get('protocol') == 'curator_instrument_calendar_windows_v1',
              'SCALP_TRUSTED_CSV_MANIFEST_REQUIRED')
        # This owner reopens the pinned original parent, reselects the exact
        # calendar-only weeks and compares every original raw cell. A caller
        # hash or a timezone flag alone never authorizes naive timestamps.
        from scripts.curator_instrument_windows import validate_manifest
        manifest = validate_manifest(manifest_path, manifest_identity)
        registration = 'rederived_original_instrument_csv_utc_registration_v1'
    _need(manifest.get("timestamp_semantics") == REGISTERED_CSV_TIME_SEMANTICS,
          "SCALP_REGISTERED_CSV_UTC_SEMANTICS_REQUIRED")
    windows = manifest.get("windows")
    _need(isinstance(windows, dict), "SCALP_REGISTERED_CSV_WINDOW_REQUIRED")
    matches = [(key, window) for key, window in windows.items()
        if isinstance(window, dict) and isinstance(window.get("path"), str)
        and Path(window["path"]).resolve() == path and (window_key is None or key == window_key)]
    _need(len(matches) == 1, "SCALP_EXACT_REGISTERED_CSV_PATH_REQUIRED")
    key, window = matches[0]
    _need(source_hash == window.get("sha256") == request.get("replay_dataset_hash")
          and type(window.get("loaded_rows")) is int and window["loaded_rows"] == rows,
          "SCALP_EXACT_REGISTERED_CSV_BYTES_REQUIRED")
    return {"protocol": registration, "manifest_sha256": manifest_identity,
        "window_key": key, "csv_sha256": source_hash, "timestamp_semantics": REGISTERED_CSV_TIME_SEMANTICS,
        "naive_utc_authority": "verified_original_source_registration_only_not_timezone_inference"}


def _original_clock(request, body, witness, path, csv_provenance=None):
    """Reconstruct schedule bytes independently; no live clock/market input."""
    raw = Path(path).read_bytes()
    rows = list(csv.DictReader(io.StringIO(raw.decode("utf-8-sig"), newline="")))
    _need(201 <= len(rows) <= 20200, "SCALP_BOUNDED_ORDINARY_CSV_REQUIRED")
    source_hash = hashlib.sha256(raw).hexdigest()
    parsed = [pd.Timestamp(row["time"]) for row in rows]
    _need(all(not pd.isna(stamp) for stamp in parsed), "SCALP_VALID_CSV_TIMESTAMP_REQUIRED")
    has_naive = any(stamp.tzinfo is None for stamp in parsed)
    binding = _registered_csv_binding(path, source_hash, request, len(rows), csv_provenance) \
        if has_naive or csv_provenance is not None else None
    # _stamp deliberately remains strict for all returned/witness clocks.
    # Only these exact source cells can carry the separately verified contract.
    times = [stamp.tz_localize("UTC") if stamp.tzinfo is None and binding else _stamp(stamp) for stamp in parsed]
    _need(all(a < b for a, b in zip(times, times[1:])), "SCALP_ORDERED_UNIQUE_CSV_REQUIRED")
    clock = body.get("data_quality", {}).get("replay_executed_clock")
    _need(isinstance(clock, dict), "SCALP_ORIGINAL_EXECUTED_CLOCK_REQUIRED")
    duration = _number(witness["request"]["duration_seconds"])
    _need(duration > 0 and duration == int(duration), "SCALP_POSITIVE_DURATION_REQUIRED")
    policy_context = request.get("policy_context") or {}
    probe = policy_context.get("prospective_probe_window")
    policy = probe if isinstance(probe, dict) else policy_context.get("full_replay_runtime_policy")
    identity = {"protocol": "replay_executed_clock_v1", "owner": "ordinary_single_position_v1",
        "semantics": "previous_closed_candle_next_open_v1", "index_basis": "evaluated_frame_zero_based_v1",
        "input_rows": len(times), "evaluation_offset_rows": 0, "execution_timeframe": request["timeframe"],
        "duration_seconds": int(duration), "dataset_hash": request.get("replay_dataset_hash"),
        "execution_hash": execution_contract_metadata(SimpleBacktestRequest.model_validate(request))["execution_hash"],
        "policy_hash": canonical_hash(policy) if isinstance(policy, dict) else None,
        "probe_contract_hash": probe.get("contract_hash") if isinstance(probe, dict) else None}
    indices, schedule = hashlib.sha256(INDEX_DOMAIN), hashlib.sha256(SCHEDULE_DOMAIN)
    for index in range(200, len(times)):
        _need(times[index - 1] + pd.Timedelta(seconds=duration) <= times[index],
              "SCALP_COMPLETED_PREVIOUS_CANDLE_REQUIRED")
        indices.update(f"{index}\n".encode("ascii"))
        schedule.update((canonical_json([index, times[index - 1].isoformat(), times[index].isoformat()])
                         + "\n").encode("utf-8"))
    expected = {**identity, "complete": True, "decision_rows": len(times) - 200,
        "first_evaluation_index": 200, "last_evaluation_index": len(times) - 1,
        "signal_start": times[199].isoformat(), "signal_end": times[-2].isoformat(),
        "execution_start": times[200].isoformat(), "execution_end": times[-1].isoformat(),
        "index_set_hash": indices.hexdigest(), "schedule_hash": schedule.hexdigest(), "promotion_evidence": False}
    expected = {**expected, "receipt_hash": canonical_hash(expected), "receipt_json": canonical_json(expected)}
    _need(clock == expected, "SCALP_EXACT_CSV_EXECUTED_CLOCK_REQUIRED")
    scope = witness.get("scope_rows", [])
    _need(len(scope) == len(times) - 200 and all(type(row["index"]) is int
        and row["index"] == index and _stamp(row["time"]) == times[index]
        for row, index in zip(scope, range(199, len(times) - 1))), "SCALP_WITNESS_CSV_SCOPE_PARITY_REQUIRED")
    return times, clock, source_hash, duration, binding


def _eligible(timestamp, sessions):
    if not sessions:
        return True
    for session in sessions:
        start, end = (int(value) for value in session.split("-", 1))
        _need(0 <= start <= 23 and 0 <= end <= 24, "SCALP_VALID_DECLARED_SESSION_REQUIRED")
        if start <= timestamp.hour < end if start < end else timestamp.hour >= start or timestamp.hour < end:
            return True
    return False


def _entry_context(signal_open, duration, entered):
    known_closed_at = signal_open + pd.Timedelta(seconds=duration)
    _need(known_closed_at <= entered, "SCALP_ASOF_ENTRY_CONTEXT_REQUIRED")
    coordinate = session_membership(pd.Series([known_closed_at]),
        calendar_version=CALENDAR_VERSION, duration_minutes=0).iloc[0]
    return {"kind": "derived_descriptive_calendar_not_decision_consumed_or_causal",
        "signal_candle_open": signal_open.isoformat(), "signal_candle_closed_at": known_closed_at.isoformat(),
        "coordinate_timestamp": known_closed_at.isoformat(), "membership_duration_minutes": 0,
        "calendar_version": coordinate["calendar_version"], "session": coordinate["session"],
        "venue_phase": coordinate["venue_phase"], "classification_status": coordinate["classification_status"],
        "independent_session_qualification": False}


def summarize_scalp_metrics(request, response, witness, *, csv_path, max_holding_minutes=30.0, csv_provenance=None):
    """Verify original mature cash/witness, then derive explicitly limited metrics.

    Missing/technical/censored originals raise rather than becoming zero trades.
    The denominator is actual observed execution-clock dates with at least one
    declared execution-session row. It is not a claim that every strategy gate
    was satisfiable on that date, nor a calendar-wide opportunity count.
    """
    request, body = _object(request), _object(response)
    _need(isinstance(request, dict) and isinstance(body, dict), "SCALP_ORIGINAL_REQUEST_RESPONSE_REQUIRED")
    cap = _number(max_holding_minutes)
    _need(cap > 0, "SCALP_POSITIVE_HOLDING_CAP_REQUIRED")
    setup_inputs = {'request': request, 'csv_path': csv_path} \
        if request['parameters'].get('intraday_exit_variant') == 'closed_setup_2r_v1' else {}
    verified = verify_management_witness(body, witness, **setup_inputs)
    for field in ("symbol", "timeframe", "initial_balance", "risk_per_trade", "parameters", "execution"):
        _need(request[field] == witness["request"][field], "SCALP_EXACT_ORIGINAL_REQUEST_REQUIRED:" + field)
    times, clock, source_hash, duration, csv_binding = _original_clock(request, body, witness, csv_path, csv_provenance)
    days = {}
    sessions = request["execution"].get("allowed_sessions_utc") or []
    for timestamp in times[200:]:
        day = timestamp.date().isoformat()
        row = days.setdefault(day, {"utc_date": day, "decision_rows": 0, "eligible_execution_rows": 0,
            "entries": 0, "mature_full_closes": 0, "same_utc_day_round_trips": 0,
            "realized_net_cash": 0.0, "commission_cash": 0.0, "carry_cash": 0.0})
        row["decision_rows"] += 1
        row["eligible_execution_rows"] += int(_eligible(timestamp, sessions))
    entered_indices = {}
    for index in witness["entry_indices"]:
        observation = witness["entry_observations"][str(index)]
        entered = _stamp(observation["execution_time"])
        _need(type(index) is int and 199 <= index < len(times) - 1 and times[index + 1] == entered
              and times[index] == _stamp(observation["signal_time"]), "SCALP_ORIGINAL_ENTRY_CSV_PARITY_REQUIRED")
        entered_indices[entered] = index
        day = entered.date().isoformat()
        _need(day in days, "SCALP_ENTRY_INSIDE_ACTUAL_CLOCK_REQUIRED")
        days[day]["entries"] += 1
    per_trade, holds, context_groups = [], [], {}
    for trade, public_trade in zip(verified["per_trade"], witness["public_trades"]):
        entered, exited = _stamp(trade["entry_time"]), _stamp(trade["exit_time"])
        _need(entered in entered_indices and entered <= exited <= times[-1] + pd.Timedelta(seconds=duration),
              "SCALP_ORIGINAL_TRADE_SCOPE_REQUIRED")
        holding = (exited - entered).total_seconds() / 60
        _same(trade["holding_seconds"], holding * 60, "SCALP_ORIGINAL_WALL_HOLDING_PARITY_REQUIRED")
        holds.append(holding)
        start, stop = bisect_left(times, entered), bisect_right(times, exited)
        held_opens = times[start:stop]
        steps = max(0, len(held_opens) - 1)
        excess = sum(max(0.0, (right - left).total_seconds() - duration)
                     for left, right in zip(held_opens, held_opens[1:])) / 60
        context = _entry_context(times[entered_indices[entered]], duration, entered)
        gap_exception = holding > cap and excess > 0 and holding - excess <= cap + 1e-9
        row = {**trade, "holding_minutes": holding, "within_holding_cap": holding <= cap,
            "same_bar_holding_is_intrabar_unknown_not_instantaneous_fill": holding == 0,
            "observed_bar_open_steps": steps, "archive_gap_excess_minutes_during_holding": excess,
            "over_cap_explained_by_observed_gap": gap_exception, "entry_context": context,
            "raw_public_trade_context": {key: public_trade[key] for key in
                ("market_regime", "regime", "session", "volatility", "volatility_regime", "context") if key in public_trade}}
        per_trade.append(row)
        close_day = exited.date().isoformat()
        # A final candle's modeled exit may be at its close on the next UTC
        # date; retain that actual settlement date without inventing decisions.
        target = days.setdefault(close_day, {"utc_date": close_day, "decision_rows": 0,
            "eligible_execution_rows": 0, "entries": 0, "mature_full_closes": 0,
            "same_utc_day_round_trips": 0, "realized_net_cash": 0.0, "commission_cash": 0.0, "carry_cash": 0.0})
        target["mature_full_closes"] += 1
        target["same_utc_day_round_trips"] += int(entered.date() == exited.date())
        target["realized_net_cash"] += trade["exact_closed_pnl_cash"]
        target["commission_cash"] += trade["commission_cash"]
        target["carry_cash"] += trade["carry_cash"]
        key = (context["session"], context["venue_phase"])
        group = context_groups.setdefault(key, {"session": key[0], "venue_phase": key[1],
            "mature_full_closes": 0, "realized_net_cash_by_entry_context": 0.0})
        group["mature_full_closes"] += 1
        group["realized_net_cash_by_entry_context"] += trade["exact_closed_pnl_cash"]
    _need(len(per_trade) == verified["mature_trade_count"], "SCALP_MATURE_COUNT_PARITY_REQUIRED")
    _same(sum(row["realized_net_cash"] for row in days.values()), verified["exact_net_cash"],
          "SCALP_DAILY_CASH_RECONCILIATION_REQUIRED")
    for field in ("commission_cash", "carry_cash"):
        _same(sum(row[field] for row in days.values()), verified[field], "SCALP_DAILY_COST_RECONCILIATION_REQUIRED")
    eligible_days = [row for row in days.values() if row["eligible_execution_rows"] > 0]
    daily_close_counts = [row["mature_full_closes"] for row in eligible_days]
    funnel = body.get("entry_funnel")
    funnel = funnel if isinstance(funnel, dict) and funnel else None
    return {"protocol": PROTOCOL, "research_only": True, "own_agent_outcome": False,
        "independent_validation": False, "economic_authority": False, "paper_authority": False,
        "live_authority": False, "csv_sha256": source_hash,
        "csv_timestamp_registration": csv_binding,
        "account_identity": {key: request[key] for key in ("symbol", "timeframe", "initial_balance", "risk_per_trade", "execution")},
        "clock_identity": {key: clock[key] for key in ("input_rows", "duration_seconds", "dataset_hash",
            "execution_timeframe", "execution_start", "execution_end", "decision_rows", "index_set_hash", "schedule_hash")},
        "clock_receipt_hash": clock["receipt_hash"], "mature_full_closes": len(per_trade),
        "open_position_count": verified["open_position_count"],
        "unclosed_exposure": verified["unclosed_exposure"], "terminal_marked_value": None,
        "exact_realized_net_cash": verified["exact_net_cash"],
        "explicit_commission_carry_cash": verified["explicit_commission_carry_cash"],
        "spread_slippage": "already_embedded_in_executable_prices_not_separately_double_charged",
        "closed_cash_drawdown_percent": verified["closed_cash_drawdown_percent"], "marked_drawdown_percent": None,
        "holding_timestamp_semantics": "original_reported_candle_execution_coordinates_not_verified_intrabar_fill_times",
        "holding_minutes": {"count": len(holds), "median": _percentile(holds, .5), "p90": _percentile(holds, .9),
            "max": max(holds) if holds else None, "cap": cap,
            "within_cap_fraction": sum(value <= cap for value in holds) / len(holds) if holds else None,
            "same_bar_intrabar_duration_unknown_count": sum(value == 0 for value in holds),
            "over_cap_count": sum(value > cap for value in holds),
            "over_cap_gap_explained_count": sum(row["over_cap_explained_by_observed_gap"] for row in per_trade)},
        "eligible_observed_utc_days": len(eligible_days),
        "zero_close_eligible_days": sum(row["mature_full_closes"] == 0 for row in eligible_days),
        "active_mature_close_days": sum(value > 0 for value in daily_close_counts),
        "eligible_days_with_multiple_mature_closes": sum(value >= 2 for value in daily_close_counts),
        "mature_closes_per_eligible_observed_day": len(per_trade) / len(eligible_days) if eligible_days else None,
        "daily_eligibility_basis": "actual_clock_dates_and_declared_execution_sessions_not_all_strategy_gates",
        "per_day": [days[key] for key in sorted(days)], "entry_funnel": funnel,
        "wait_count": None,
        "wait_count_status": "unavailable_not_inferred_from_signals_minus_entries_or_occupied_rows",
        "funnel_opportunities": {key: funnel.get(key) for key in ("raw_strategy_signals", "flat_signal_opportunities", "accepted_entries")} if funnel else None,
        "entry_context_semantics": "descriptive_reused_archive_full_account_trade_grouping_not_session_causal_effect",
        "entry_context_groups": [context_groups[key] for key in sorted(context_groups)], "per_trade": per_trade}


def compare_scalp_metrics(control, candidate):
    """Compare exact account/cost/clock scopes; never assume matched entry state."""
    for item in (control, candidate):
        _need(item.get("protocol") == PROTOCOL and item.get("research_only") is True,
              "SCALP_COMPLETED_VERIFIED_METRICS_REQUIRED")
    for field in ("csv_sha256", "account_identity", "clock_identity"):
        _need(control[field] == candidate[field], "SCALP_COMPARABLE_ACCOUNT_CLOCK_REQUIRED:" + field)
    _need(control["holding_minutes"]["cap"] == candidate["holding_minutes"]["cap"], "SCALP_SAME_HOLDING_METRIC_REQUIRED")
    return {"protocol": "offline_curator_scalp_account_contrast_v1", "research_only": True,
        "exact_realized_net_cash_delta": candidate["exact_realized_net_cash"] - control["exact_realized_net_cash"],
        "mature_full_closes_delta": candidate["mature_full_closes"] - control["mature_full_closes"],
        "closed_cash_drawdown_percent_delta": candidate["closed_cash_drawdown_percent"] - control["closed_cash_drawdown_percent"],
        "control_open_position_count": control["open_position_count"], "candidate_open_position_count": candidate["open_position_count"],
        "marked_total_account_contrast": None, "same_entry_isolated_causal_contrast": False,
        "independent_validation": False, "economic_authority": False}
