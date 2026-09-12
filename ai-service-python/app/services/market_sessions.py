"""DST-aware, versioned market-session coordinates for XAUUSD research.

The calendar classifies timestamps only.  It never makes a trade actionable;
spread/liquidity and the normal execution contract remain independent gates.
"""

from __future__ import annotations

from zoneinfo import ZoneInfo

import pandas as pd

SESSION_CALENDAR_PROTOCOL = "market_session_calendar_v1"
DEFAULT_PHASES: dict[str, dict[str, str]] = {
    "asia": {"timezone": "Asia/Shanghai", "start": "08:00", "end": "16:00"},
    "london": {"timezone": "Europe/London", "start": "08:00", "end": "16:30"},
    "new_york": {"timezone": "America/New_York", "start": "08:00", "end": "17:00"},
}


def _minute(value: str) -> int:
    hour, minute = (int(part) for part in value.split(":", 1))
    return hour * 60 + minute


def session_membership(
    times: pd.Series,
    definitions: dict[str, dict[str, str]] | None = None,
    holidays: dict[str, list[str]] | None = None,
) -> pd.DataFrame:
    """Return overlap-preserving session membership for every UTC timestamp."""

    utc = pd.to_datetime(times, utc=True, errors="coerce")
    phases = definitions or DEFAULT_PHASES
    holiday_map = holidays or {}
    result = pd.DataFrame(index=times.index)

    for phase, raw in phases.items():
        timezone = ZoneInfo(
            str(raw.get("timezone") or DEFAULT_PHASES[phase]["timezone"])
        )
        local = utc.dt.tz_convert(timezone)
        minute = local.dt.hour * 60 + local.dt.minute
        start = _minute(str(raw.get("start") or DEFAULT_PHASES[phase]["start"]))
        end = _minute(str(raw.get("end") or DEFAULT_PHASES[phase]["end"]))
        if start < end:
            active = minute.ge(start) & minute.lt(end)
        else:
            active = minute.ge(start) | minute.lt(end)
        local_dates = local.dt.strftime("%Y-%m-%d")
        active &= local.dt.dayofweek.lt(5)
        active &= ~local_dates.isin(
            [str(value) for value in holiday_map.get(phase, [])]
        )
        result[phase] = active.fillna(False)
        result[f"{phase}_instance_id"] = (
            phase
            + "|"
            + local_dates.fillna("invalid")
            + "|"
            + local.dt.strftime("%z").fillna("invalid")
        )

    london = result.get("london", pd.Series(False, index=times.index)).astype(bool)
    new_york = result.get("new_york", pd.Series(False, index=times.index)).astype(bool)
    asia = result.get("asia", pd.Series(False, index=times.index)).astype(bool)
    result["overlap"] = london & new_york
    result["session"] = "off_session"
    result.loc[asia, "session"] = "asia"
    result.loc[new_york, "session"] = "new_york"
    result.loc[london, "session"] = "london"
    result.loc[london & new_york, "session"] = "overlap"
    result["overlap_mask"] = [
        [
            phase
            for phase in ("asia", "london", "new_york")
            if bool(result.at[index, phase])
        ]
        for index in result.index
    ]
    return result


def apply_specialist_scope(
    df: pd.DataFrame, contract: dict[str, object] | None
) -> pd.DataFrame:
    """Turn out-of-scope specialist signals into explicit WAIT decisions."""

    if not isinstance(contract, dict) or not contract:
        return df
    cell = contract.get("contextual_specialist_cell", contract)
    if not isinstance(cell, dict):
        return df
    session_scope = cell.get("session_ownership", cell)
    if not isinstance(session_scope, dict):
        session_scope = {}
    target_session = str(
        cell.get("session") or session_scope.get("session") or ""
    ).lower()
    if target_session == "asian":
        target_session = "asia"
    if target_session in {"newyork", "ny"}:
        target_session = "new_york"
    if target_session in {"london_new_york_overlap", "london_ny_overlap"}:
        target_session = "overlap"

    definitions = session_scope.get("phase_definitions")
    memberships = session_membership(
        df.get("time", pd.Series(index=df.index, dtype=object)),
        definitions if isinstance(definitions, dict) else None,
        session_scope.get("holidays")
        if isinstance(session_scope.get("holidays"), dict)
        else None,
    )
    eligible = pd.Series(True, index=df.index)
    if target_session:
        if target_session not in memberships.columns:
            eligible &= False
        else:
            eligible &= memberships[target_session].astype(bool)
    target_regime = cell.get("regime")
    if target_regime and str(target_regime).lower() not in {"unknown", "any", "mixed"}:
        eligible &= (
            df.get("market_regime", pd.Series("unknown", index=df.index))
            .astype(str)
            .eq(str(target_regime))
        )
    target_volatility = cell.get("volatility")
    if target_volatility and str(target_volatility).lower() not in {
        "unknown",
        "any",
        "mixed",
    }:
        eligible &= (
            df.get("volatility_regime", pd.Series("unknown", index=df.index))
            .astype(str)
            .eq(str(target_volatility))
        )
    target_direction = str(cell.get("direction") or "").upper()
    signals = df.get("signal", pd.Series("WAIT", index=df.index)).astype(str)
    if target_direction in {"BUY", "SELL"}:
        eligible &= signals.eq(target_direction)

    scoped = df.copy()
    scoped["market_session"] = memberships["session"]
    scoped["session_overlap_mask"] = memberships["overlap_mask"]
    scoped["specialist_scope_eligible"] = eligible
    scoped["specialist_scope_reason"] = "owned_context"
    scoped.loc[~eligible, "specialist_scope_reason"] = "outside_owned_context_wait"
    scoped.loc[~eligible, "signal"] = "WAIT"
    if "signal_confidence" in scoped.columns:
        scoped.loc[~eligible, "signal_confidence"] = 0.0
    scoped.attrs = dict(df.attrs)
    scoped.attrs["specialist_context_contract"] = {
        "protocol": str(contract.get("protocol") or "contextual_council_allocator_v1"),
        "calendar_version": session_scope.get("calendar_version"),
        "target_session": target_session or None,
        "outside_scope_action": "WAIT",
        "promotion_evidence": False,
    }
    return scoped
