"""Versioned, IANA/DST-aware XAUUSD venue-session coordinates.

Classification never grants execution authority. Unknown/closed calendars and
out-of-scope specialists deterministically abstain.
"""

from __future__ import annotations

import hashlib
from datetime import datetime, time, timedelta
from zoneinfo import ZoneInfo

import pandas as pd

SESSION_CALENDAR_PROTOCOL = "market_session_calendar_v2"
DEFAULT_PHASES: dict[str, dict[str, object]] = {
    "asia_sge_night": {"timezone": "Asia/Shanghai", "start": "20:00", "end": "02:30", "trading_day_basis": "end", "holiday_venue": "sge"},
    "asia_sge_day": {"timezone": "Asia/Shanghai", "start": "09:00", "end": "15:30", "holiday_venue": "sge"},
    "london_pre_am_fix": {"timezone": "Europe/London", "start": "08:00", "end": "10:30", "holiday_venue": "lbma"},
    "london_am_fix": {"timezone": "Europe/London", "start": "10:30", "end": "10:32", "holiday_venue": "lbma"},
    "london_interfix": {"timezone": "Europe/London", "start": "10:32", "end": "15:00", "holiday_venue": "lbma"},
    "london_pm_fix": {"timezone": "Europe/London", "start": "15:00", "end": "15:02", "holiday_venue": "lbma"},
    "comex_active": {"timezone": "America/Chicago", "start": "17:00", "end": "16:00", "trading_day_basis": "end", "holiday_venue": "comex"},
    "comex_pre_settlement": {"timezone": "America/Chicago", "start": "12:00", "end": "12:30", "holiday_venue": "comex"},
    "comex_post_settlement": {"timezone": "America/Chicago", "start": "12:30", "end": "13:00", "holiday_venue": "comex"},
    "comex_maintenance": {"timezone": "America/Chicago", "start": "16:00", "end": "17:00", "weekdays": [1, 2, 3, 4], "holiday_venue": "comex"},
}
RESEARCH_PHASES = [*DEFAULT_PHASES, "london_comex_overlap"]


def _clock(value: object) -> tuple[int, int]:
    hour, minute = (int(part) for part in str(value).split(":", 1))
    return hour, minute


def _venue(phase: str) -> str:
    if phase.startswith("asia_sge_"):
        return "sge"
    if phase.startswith("london_") and phase != "london_comex_overlap":
        return "lbma"
    if phase.startswith("comex_"):
        return "comex"
    return "derived"


def _legacy_session(active: list[str]) -> str:
    if "london_comex_overlap" in active:
        return "overlap"
    if any(item.startswith("london_") for item in active):
        return "london"
    if any(item.startswith("asia_sge_") for item in active):
        return "asia"
    if any(item.startswith("comex_") for item in active):
        return "new_york"
    return "off_session"


def _instance(
    phase: str,
    raw: dict[str, object],
    anchor: datetime,
    holidays: dict[str, list[str]],
    no_night_dates: list[str],
) -> dict[str, object]:
    zone = ZoneInfo(str(raw["timezone"]))
    local_anchor = anchor.astimezone(zone).replace(hour=0, minute=0, second=0, microsecond=0)
    start_hour, start_minute = _clock(raw["start"])
    end_hour, end_minute = _clock(raw["end"])
    start = local_anchor.replace(hour=start_hour, minute=start_minute)
    end = local_anchor.replace(hour=end_hour, minute=end_minute)
    if end <= start:
        end += timedelta(days=1)
    basis = end if str(raw.get("trading_day_basis", "start")) == "end" else start
    trading_date = basis.strftime("%Y-%m-%d")
    weekdays = [int(value) for value in raw.get("weekdays", [1, 2, 3, 4, 5])]
    venue = str(raw.get("holiday_venue") or _venue(phase))
    closures = {str(value) for value in holidays.get(venue, [])}
    closures.update(str(value) for value in holidays.get(phase, []))
    holiday = trading_date in closures
    no_night = phase == "asia_sge_night" and (
        start.strftime("%Y-%m-%d") in no_night_dates
        or end.strftime("%Y-%m-%d") in set(str(value) for value in holidays.get("sge", []))
        or end.strftime("%Y-%m-%d") in set(str(value) for value in holidays.get("asia_sge_day", []))
    )
    weekly_closed = basis.isoweekday() not in weekdays
    closed = holiday or no_night or weekly_closed
    reason = "no_night_session_before_holiday" if no_night else ("configured_holiday" if holiday else ("weekend_or_weekly_close" if weekly_closed else None))
    offset = start.strftime("%z")
    return {
        "phase": phase,
        "venue": _venue(phase),
        "local_date": trading_date,
        "start_utc": pd.Timestamp(start).tz_convert("UTC"),
        "end_utc": pd.Timestamp(end).tz_convert("UTC"),
        "offset": offset,
        "offset_state": start.tzname(),
        "calendar_closed": closed,
        "closure_reason": reason,
        "session_instance_id": f"{phase}|{trading_date}|{offset}",
    }


def _phase_at(
    timestamp: pd.Timestamp,
    phase: str,
    raw: dict[str, object],
    holidays: dict[str, list[str]],
    no_night_dates: list[str],
    duration_minutes: int = 0,
) -> dict[str, object]:
    source = timestamp.to_pydatetime()
    rows = [_instance(phase, raw, source + timedelta(days=shift), holidays, no_night_dates) for shift in (-1, 0, 1)]
    interval_end = timestamp + pd.Timedelta(minutes=max(0, int(duration_minutes)))
    active = next((row for row in rows if not row["calendar_closed"] and (
        row["start_utc"] < interval_end and row["end_utc"] > timestamp
        if duration_minutes > 0
        else row["start_utc"] <= timestamp < row["end_utc"]
    )), None)
    chosen = active or min(rows, key=lambda row: min(abs((timestamp - row["start_utc"]).total_seconds()), abs((timestamp - row["end_utc"]).total_seconds())))
    chosen = dict(chosen)
    chosen["active"] = active is not None
    chosen["minutes_from_open"] = max(0, int((timestamp - chosen["start_utc"]).total_seconds() // 60)) if active else None
    chosen["minutes_from_boundary"] = int(min(abs((timestamp - chosen["start_utc"]).total_seconds()), abs((timestamp - chosen["end_utc"]).total_seconds())) // 60)
    return chosen


def _event_minutes(timestamp: pd.Timestamp, zone_name: str, hour: int, minute: int) -> int:
    local = timestamp.tz_convert(ZoneInfo(zone_name))
    event = local.replace(hour=hour, minute=minute, second=0, microsecond=0)
    return int((local - event).total_seconds() // 60)


def _derived_overlap_row(
    timestamp: pd.Timestamp,
    phase_rows: dict[str, dict[str, object]],
    calendar_version: str,
    duration_minutes: int,
) -> dict[str, object]:
    comex = phase_rows.get("comex_active", {})
    chicago = timestamp.tz_convert(ZoneInfo("America/Chicago"))
    chicago_day = chicago.normalize()
    core_start = (chicago_day + pd.Timedelta(hours=7)).tz_convert("UTC")
    core_end = (chicago_day + pd.Timedelta(hours=11, minutes=30)).tz_convert("UTC")
    london_rows = [
        row for phase, row in phase_rows.items()
        if phase.startswith("london_")
        and not bool(row.get("calendar_closed", True))
        and pd.Timestamp(row["start_utc"]) < core_end
        and pd.Timestamp(row["end_utc"]) > core_start
    ]
    start = timestamp
    end = timestamp
    active = False

    comex_covers_core = bool(
        comex
        and not bool(comex.get("calendar_closed", True))
        and pd.Timestamp(comex["start_utc"]) < core_end
        and pd.Timestamp(comex["end_utc"]) > core_start
    )
    if london_rows and comex_covers_core:
        start = max(
            min(pd.Timestamp(row["start_utc"]) for row in london_rows),
            pd.Timestamp(comex["start_utc"]),
            core_start,
        )
        end = min(
            max(pd.Timestamp(row["end_utc"]) for row in london_rows),
            pd.Timestamp(comex["end_utc"]),
            core_end,
        )
        interval_end = timestamp + pd.Timedelta(minutes=max(0, int(duration_minutes)))
        active = bool(end > start and (
            start < interval_end and end > timestamp
            if duration_minutes > 0
            else start <= timestamp < end
        ))

    instance_payload = "|".join([
        calendar_version,
        "london_comex_overlap",
        chicago_day.strftime("%Y-%m-%d"),
        chicago.strftime("%z"),
    ])
    return {
        "phase": "london_comex_overlap",
        "venue": "derived",
        "local_date": chicago_day.strftime("%Y-%m-%d"),
        "start_utc": pd.Timestamp(start),
        "end_utc": pd.Timestamp(end),
        "offset": chicago.strftime("%z"),
        "offset_state": chicago.tzname(),
        "calendar_closed": False,
        "closure_reason": None,
        "active": active,
        "minutes_from_open": max(0, int((timestamp - start).total_seconds() // 60)) if active else None,
        "minutes_from_boundary": int(min(abs((timestamp - start).total_seconds()), abs((timestamp - end).total_seconds())) // 60) if active else 0,
        "session_instance_id": hashlib.sha256(instance_payload.encode()).hexdigest(),
    }


def _resolve_row(
    value: object,
    definitions: dict[str, dict[str, object]],
    holidays: dict[str, list[str]],
    no_night_dates: list[str],
    calendar_version: str,
    duration_minutes: int = 0,
) -> dict[str, object]:
    timestamp = pd.to_datetime(value, utc=True, errors="coerce")
    if pd.isna(timestamp):
        return {
            **{phase: False for phase in RESEARCH_PHASES},
            "asia": False, "london": False, "new_york": False, "overlap": False,
            "session": "off_session", "venue_phase": "calendar_quarantine", "venue_phases": [],
            "active_phases": [], "overlap_mask": [], "session_instance_id": hashlib.sha256(f"{calendar_version}|invalid|{value}".encode()).hexdigest(),
            "calendar_version": calendar_version,
            "classification_status": "quarantined_invalid_timestamp", "classified_or_quarantined": True,
            "candle_utc_interval": {"start": None, "end": None},
            "local_time": {}, "utc_interval": {}, "dst_offset": {}, "minutes_from_open": {},
            "minutes_from_fix_or_settlement": {}, "holiday_or_maintenance_state": {"overall": "invalid_timestamp"},
        }
    timestamp = pd.Timestamp(timestamp)
    phase_rows = {
        phase: _phase_at(
            timestamp, phase, raw, holidays, no_night_dates, duration_minutes
        )
        for phase, raw in definitions.items()
    }
    # COMEX Globex is active almost around the clock; calling every London
    # candle an "overlap" would erase the legacy London/Asia coordinates.
    # The derived overlap is therefore the bounded US core-liquidity window,
    # intersected with the complete candle rather than only its opening tick.
    phase_rows["london_comex_overlap"] = _derived_overlap_row(
        timestamp, phase_rows, calendar_version, duration_minutes
    )
    overlap = bool(phase_rows["london_comex_overlap"]["active"])
    active = [phase for phase, row in phase_rows.items() if row["active"]]
    session = _legacy_session(active)
    priority = ["comex_maintenance", "london_am_fix", "london_pm_fix", "comex_pre_settlement", "comex_post_settlement", "london_comex_overlap"]
    venue_phase = next((phase for phase in priority if phase in active), active[0] if active else "calendar_quarantine")
    instance_ids = [str(phase_rows[phase]["session_instance_id"]) for phase in active if phase in phase_rows]
    session_instance_id = hashlib.sha256(f"{calendar_version}|{'|'.join(active)}|{'|'.join(instance_ids)}".encode()).hexdigest()
    zones = {"sge": "Asia/Shanghai", "london": "Europe/London", "comex": "America/Chicago"}
    local_time = {key: timestamp.tz_convert(ZoneInfo(zone)).isoformat() for key, zone in zones.items()}
    dst_offset = {key: {"offset": timestamp.tz_convert(ZoneInfo(zone)).strftime("%z"), "state": timestamp.tz_convert(ZoneInfo(zone)).tzname()} for key, zone in zones.items()}
    intervals = {phase: {"start": phase_rows[phase]["start_utc"].isoformat(), "end": phase_rows[phase]["end_utc"].isoformat()} for phase in active if phase in phase_rows}
    minutes_open = {phase: phase_rows[phase]["minutes_from_open"] for phase in active if phase in phase_rows}
    maintenance = "comex_maintenance" in active
    closures = {phase: row["closure_reason"] for phase, row in phase_rows.items() if row["calendar_closed"]}
    status = "classified" if active else "quarantined_market_closed"
    record: dict[str, object] = {
        **{phase: phase in active for phase in RESEARCH_PHASES},
        "asia": any(phase.startswith("asia_sge_") for phase in active),
        "london": any(phase.startswith("london_") and phase != "london_comex_overlap" for phase in active),
        "new_york": any(phase.startswith("comex_") for phase in active),
        "overlap": overlap,
        "session": session, "venue_phase": venue_phase, "venue_phases": active, "active_phases": active,
        "overlap_mask": list(dict.fromkeys(_venue(phase) for phase in active if _venue(phase) != "derived")),
        "session_instance_id": session_instance_id, "calendar_version": calendar_version,
        "classification_status": status, "classified_or_quarantined": True,
        "candle_utc_interval": {
            "start": timestamp.isoformat(),
            "end": (timestamp + pd.Timedelta(minutes=max(0, int(duration_minutes)))).isoformat()
            if duration_minutes > 0 else None,
        },
        "local_time": local_time, "utc_interval": intervals, "dst_offset": dst_offset, "minutes_from_open": minutes_open,
        "minutes_from_fix_or_settlement": {
            "lbma_am_fix": _event_minutes(timestamp, "Europe/London", 10, 30),
            "lbma_pm_fix": _event_minutes(timestamp, "Europe/London", 15, 0),
            "comex_settlement": _event_minutes(timestamp, "America/Chicago", 12, 30),
        },
        "holiday_or_maintenance_state": {
            "overall": "comex_maintenance" if maintenance else ("open_rulebook_state" if active else "calendar_closed_quarantine"),
            "maintenance": maintenance, "closures": closures,
            "calendar_data_status": "versioned_rulebook_plus_configured_overrides",
        },
    }
    for phase, row in phase_rows.items():
        record[f"{phase}_instance_id"] = row["session_instance_id"]
    # Compatibility aliases are reporting coordinates only; COMEX authority
    # uses America/Chicago through its detailed phase IDs.
    london = timestamp.tz_convert(ZoneInfo("Europe/London"))
    new_york = timestamp.tz_convert(ZoneInfo("America/New_York"))
    shanghai = timestamp.tz_convert(ZoneInfo("Asia/Shanghai"))
    record["london_instance_id"] = f"london|{london:%Y-%m-%d}|{london:%z}"
    record["new_york_instance_id"] = f"new_york|{new_york:%Y-%m-%d}|{new_york:%z}"
    record["asia_instance_id"] = f"asia|{shanghai:%Y-%m-%d}|{shanghai:%z}"
    return record


def session_membership(
    times: pd.Series,
    definitions: dict[str, dict[str, object]] | None = None,
    holidays: dict[str, list[str]] | None = None,
    no_night_session_dates: list[str] | None = None,
    calendar_version: str = "xauusd_market_sessions_2026_v2",
    duration_minutes: int = 0,
) -> pd.DataFrame:
    """Return detailed, overlap-preserving coordinates for every candle."""

    phases = definitions if isinstance(definitions, dict) and all(key in definitions for key in DEFAULT_PHASES) else DEFAULT_PHASES
    holiday_map = holidays if isinstance(holidays, dict) else {}
    no_night = [str(value) for value in (no_night_session_dates or [])]
    records = [
        _resolve_row(
            value, phases, holiday_map, no_night, calendar_version, duration_minutes
        )
        for value in times
    ]
    return pd.DataFrame(records, index=times.index)


def apply_specialist_scope(
    df: pd.DataFrame,
    contract: dict[str, object] | None,
    duration_minutes: int = 0,
) -> pd.DataFrame:
    """Turn every out-of-scope or quarantined specialist signal into WAIT."""

    if not isinstance(contract, dict) or not contract:
        return df
    cell = contract.get("contextual_specialist_cell", contract)
    if not isinstance(cell, dict):
        return df
    ownership = cell.get("session_ownership", cell)
    ownership = ownership if isinstance(ownership, dict) else {}
    target_phase = str(cell.get("venue_phase") or ownership.get("venue_phase") or "").lower()
    target_session = str(cell.get("session") or ownership.get("session") or "").lower()
    aliases = {"asian": "asia", "newyork": "new_york", "ny": "new_york", "london_new_york_overlap": "overlap", "london_ny_overlap": "overlap"}
    target_session = aliases.get(target_session, target_session)
    memberships = session_membership(
        df.get("time", pd.Series(index=df.index, dtype=object)),
        ownership.get("phase_definitions") if isinstance(ownership.get("phase_definitions"), dict) else None,
        ownership.get("holidays") if isinstance(ownership.get("holidays"), dict) else None,
        ownership.get("no_night_session_dates") if isinstance(ownership.get("no_night_session_dates"), list) else None,
        str(ownership.get("calendar_version") or "xauusd_market_sessions_2026_v2"),
        duration_minutes,
    )
    eligible = memberships["classification_status"].eq("classified")
    scope_key = target_phase if target_phase in memberships.columns else target_session
    if scope_key:
        eligible &= memberships.get(scope_key, pd.Series(False, index=df.index)).astype(bool)
    target_regime = cell.get("regime")
    if target_regime and str(target_regime).lower() not in {"unknown", "any", "mixed"}:
        eligible &= df.get("market_regime", pd.Series("unknown", index=df.index)).astype(str).eq(str(target_regime))
    target_volatility = cell.get("volatility")
    if target_volatility and str(target_volatility).lower() not in {"unknown", "any", "mixed"}:
        eligible &= df.get("volatility_regime", pd.Series("unknown", index=df.index)).astype(str).eq(str(target_volatility))
    execution_policy = str(cell.get("execution_policy") or "context_owned").lower()
    if execution_policy == "abstain_only":
        eligible &= False
    target_transition = str(cell.get("transition_state") or "").lower()
    if target_transition not in {"", "any", "unknown"}:
        observed_transition = pd.Series("stable", index=df.index)
        observed_transition.loc[
            df.get("market_regime", pd.Series("unknown", index=df.index)).astype(str).eq("transition")
        ] = "transition"
        eligible &= observed_transition.eq(target_transition)
    target_liquidity = str(cell.get("spread_liquidity_state") or "").lower()
    target_liquidity = {
        "low_spread": "liquid", "high_spread": "illiquid",
        "spread_filter_veto": "illiquid",
    }.get(target_liquidity, target_liquidity)
    if target_liquidity not in {"", "any", "unknown", "closed_or_maintenance"}:
        atr = pd.to_numeric(
            df.get("atr", df.get("structure_atr", pd.Series(float("nan"), index=df.index))),
            errors="coerce",
        )
        spread = pd.to_numeric(
            df.get("spread", pd.Series(float("nan"), index=df.index)),
            errors="coerce",
        )
        observed_liquidity = pd.Series("unknown", index=df.index)
        measurable = atr.gt(0) & spread.notna()
        observed_liquidity.loc[measurable & spread.div(atr).le(0.25)] = "liquid"
        observed_liquidity.loc[measurable & spread.div(atr).gt(0.25)] = "illiquid"
        eligible &= observed_liquidity.eq(target_liquidity)
    target_direction = str(cell.get("direction") or "").upper()
    signals = df.get("signal", pd.Series("WAIT", index=df.index)).astype(str)
    if target_direction in {"BUY", "SELL"}:
        eligible &= signals.eq(target_direction)

    scoped = df.copy()
    scoped["market_session"] = memberships["session"]
    scoped["market_venue_phase"] = memberships["venue_phase"]
    scoped["market_venue_phases"] = memberships["venue_phases"]
    scoped["market_session_instance_id"] = memberships["session_instance_id"]
    scoped["session_overlap_mask"] = memberships["overlap_mask"]
    scoped["market_session_classification_status"] = memberships["classification_status"]
    scoped["specialist_scope_eligible"] = eligible
    scoped["specialist_scope_reason"] = "owned_context"
    scoped.loc[~eligible, "specialist_scope_reason"] = "outside_owned_context_wait"
    scoped.loc[~eligible, "signal"] = "WAIT"
    if "signal_confidence" in scoped.columns:
        scoped.loc[~eligible, "signal_confidence"] = 0.0
    scoped.attrs = dict(df.attrs)
    raw_outside_signals = int((~eligible & signals.isin(["BUY", "SELL"])).sum())
    post_scope_signals = scoped.get("signal", pd.Series("WAIT", index=scoped.index)).astype(str)
    scoped.attrs["specialist_context_contract"] = {
        "protocol": str(contract.get("protocol") or "contextual_council_allocator_v1"),
        "calendar_protocol": SESSION_CALENDAR_PROTOCOL,
        "calendar_version": ownership.get("calendar_version"),
        "target_session": target_session or None, "target_venue_phase": target_phase or None,
        "target_transition_state": target_transition or None,
        "target_spread_liquidity_state": target_liquidity or None,
        "outside_scope_action": "WAIT",
        "out_of_scope_raw_signal_count": raw_outside_signals,
        "out_of_scope_activation_count": int((~eligible & post_scope_signals.isin(["BUY", "SELL"])).sum()),
        "promotion_evidence": False,
    }
    return scoped
