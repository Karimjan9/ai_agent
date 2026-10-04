"""Closed-candle H4 -> H1 -> M15 context for an M5 execution stream.

This module has one narrow responsibility: expose only information whose
source candle had already closed by the M5 decision timestamp.  It does not
declare an OHLC sweep to be real order-book liquidity.
"""

from __future__ import annotations

from dataclasses import dataclass
import hashlib
import json
from datetime import timedelta
from typing import Any

import numpy as np
import pandas as pd

from app.strategies.structure import apply_structure_instruments


PROTOCOL = "closed_h4_h1_m15_m5_stack_v1"
TIMEFRAME_DURATION = {
    "M5": pd.Timedelta(minutes=5),
    "M15": pd.Timedelta(minutes=15),
    "H1": pd.Timedelta(hours=1),
    "H4": pd.Timedelta(hours=4),
    "D1": pd.Timedelta(days=1),
}


@dataclass(frozen=True)
class PreparedClosedMtfContext:
    """Immutable higher-timeframe compiler output reusable across folds.

    A causal replay changes only the bounded M5 observation window between
    folds.  Its sealed H4/H1/M15 inputs and feature parameters do not change,
    so rebuilding structure, swing and trap state for the full context files
    on every fold is duplicate compute.  This object owns no execution or
    performance state; it is only a precompiled, closed-candle context plane.
    """

    status: str
    reason: str
    supplied_timeframes: tuple[str, ...]
    prepared: dict[str, pd.DataFrame]
    missing: tuple[str, ...]
    related_m15: pd.DataFrame | None = None


def prepare_closed_mtf_context(
    streams: dict[str, pd.DataFrame] | None,
    parameters: dict[str, Any] | None = None,
    related_streams: dict[str, pd.DataFrame] | None = None,
) -> PreparedClosedMtfContext:
    """Compile sealed context streams once without observing an M5 fold."""
    parameters = parameters or {}
    supplied = {
        str(key).upper(): value
        for key, value in (streams or {}).items()
        if value is not None
    }
    required = ("H4", "H1", "M15")
    missing = tuple(
        timeframe
        for timeframe in required
        if timeframe not in supplied or supplied[timeframe].empty
    )
    if missing:
        return PreparedClosedMtfContext(
            status="incomplete",
            reason="missing_" + "_".join(item.lower() for item in missing),
            supplied_timeframes=tuple(sorted(supplied)),
            prepared={},
            missing=missing,
        )

    prepared: dict[str, pd.DataFrame] = {}
    for timeframe in required:
        prepared[timeframe] = _prepare_stream(supplied[timeframe], timeframe, parameters)
        if prepared[timeframe].empty:
            return PreparedClosedMtfContext(
                status="incomplete",
                reason=f"invalid_{timeframe.lower()}_stream",
                supplied_timeframes=tuple(sorted(supplied)),
                prepared={},
                missing=(timeframe,),
            )

    if "D1" in supplied and not supplied["D1"].empty:
        d1 = _prepare_stream(supplied["D1"], "D1", parameters)
        if d1.empty:
            return PreparedClosedMtfContext(
                status="incomplete", reason="invalid_d1_stream",
                supplied_timeframes=tuple(sorted(supplied)), prepared={}, missing=("D1",),
            )
        prepared["D1"] = d1

    related_m15 = None
    related = {
        str(key).upper(): value
        for key, value in (related_streams or {}).items()
        if value is not None
    }
    if "M15" in related and not related["M15"].empty:
        candidate = _prepare_stream(related["M15"], "M15", parameters)
        if candidate.empty:
            return PreparedClosedMtfContext(
                status="incomplete", reason="invalid_related_m15_stream",
                supplied_timeframes=tuple(sorted(supplied)), prepared={}, missing=("RELATED_M15",),
            )
        related_m15 = _m15_trap_state(candidate)

    return PreparedClosedMtfContext(
        status="ready",
        reason="closed_context_compiled",
        supplied_timeframes=tuple(sorted(supplied)),
        prepared=prepared,
        missing=(),
        related_m15=related_m15,
    )


def apply_closed_mtf_context(
    entry_frame: pd.DataFrame,
    streams: dict[str, pd.DataFrame] | None,
    parameters: dict[str, Any] | None = None,
    related_streams: dict[str, pd.DataFrame] | None = None,
    *,
    prepared_context: PreparedClosedMtfContext | None = None,
) -> pd.DataFrame:
    """Merge H4, H1 and M15 snapshots into an M5 frame without look-ahead."""
    parameters = parameters or {}
    base = entry_frame.copy()
    base["time"] = pd.to_datetime(base["time"], utc=True, errors="coerce")
    base = base.dropna(subset=["time"]).sort_values("time").reset_index(drop=True)
    # Strategy values on an M5 row are decided only after that candle closes;
    # a higher-timeframe candle closing at that same instant is therefore
    # known, while one closing later is not.
    base["decision_at"] = base["time"] + TIMEFRAME_DURATION["M5"]
    compiled = prepared_context or prepare_closed_mtf_context(
        streams,
        parameters,
        related_streams,
    )
    supplied = compiled.supplied_timeframes
    if compiled.status != "ready":
        base["mtf_stack_status"] = "incomplete"
        base["mtf_stack_reason"] = compiled.reason
        base.attrs["mtf_stack"] = _summary(
            "incomplete", compiled.reason, supplied, list(compiled.missing)
        )
        return base

    prepared = compiled.prepared

    merged = _merge_context(base, prepared["H4"], "h4", [
        "structure_direction", "confirmed_swing_high", "confirmed_swing_low",
        "dynamic_fib_zone_low", "dynamic_fib_zone_high", "context_hash",
    ])
    merged = _merge_context(merged, prepared["H1"], "h1", [
        "structure_direction", "confirmed_swing_high", "confirmed_swing_low",
        "dynamic_fib_zone_low", "dynamic_fib_zone_high", "structure_regime", "context_hash",
    ])
    merged = _merge_context(merged, _m15_trap_state(prepared["M15"]), "m15", [
        "structure_direction", "confirmed_swing_high", "confirmed_swing_low",
        "dynamic_fib_zone_low", "dynamic_fib_zone_high", "context_hash",
        "trap_direction", "trap_available_at", "trap_extreme", "trap_context_hash",
    ])
    if "D1" in prepared:
        merged = _merge_context(merged, prepared["D1"], "d1", [
            "structure_direction", "confirmed_swing_high", "confirmed_swing_low", "context_hash",
        ])
    if compiled.related_m15 is not None:
        merged = _merge_context(merged, compiled.related_m15, "related_m15", [
            "trap_direction", "trap_available_at", "trap_extreme", "trap_context_hash",
        ])

    ready = pd.Series(True, index=merged.index)
    reasons = pd.Series("ready", index=merged.index, dtype="object")
    for timeframe in ("h4", "h1", "m15"):
        available = f"{timeframe}_available_at"
        missing_context = merged.get(available, pd.Series(pd.NaT, index=merged.index)).isna()
        ready &= ~missing_context
        reasons = reasons.mask(missing_context, f"{timeframe}_not_closed")
        if available in merged:
            maximum = _maximum_age(timeframe, parameters)
            stale = (merged["decision_at"] - merged[available]) > maximum
            ready &= ~stale
            reasons = reasons.mask(stale, f"{timeframe}_context_stale")

    merged["mtf_stack_status"] = np.where(ready, "ready", "blocked")
    merged["mtf_stack_reason"] = reasons
    merged["mtf_stack_context_hash"] = merged.apply(_stack_hash, axis=1)
    merged.attrs["mtf_stack"] = _summary(
        "ready" if bool(ready.any()) else "blocked",
        "closed_context_merged",
        supplied,
        [],
    )
    return merged


def _prepare_stream(frame: pd.DataFrame, timeframe: str, parameters: dict[str, Any]) -> pd.DataFrame:
    out = frame.copy()
    required = ["time", "open", "high", "low", "close"]
    if any(column not in out for column in required):
        return pd.DataFrame()
    out["time"] = pd.to_datetime(out["time"], utc=True, errors="coerce")
    for column in ["open", "high", "low", "close", "volume"]:
        if column not in out:
            out[column] = 0.0
        out[column] = pd.to_numeric(out[column], errors="coerce")
    if out[required].isna().any().any() or out["time"].duplicated().any():
        return pd.DataFrame()
    if not out["time"].is_monotonic_increasing:
        return pd.DataFrame()
    prices = out[["open", "high", "low", "close"]]
    if not np.isfinite(prices.to_numpy()).all() or not prices.gt(0).all().all():
        return pd.DataFrame()
    out["volume"] = out["volume"].fillna(0.0)
    if not np.isfinite(out["volume"].to_numpy()).all():
        return pd.DataFrame()
    out = out.reset_index(drop=True)
    if len(out) < 2:
        return pd.DataFrame()
    valid_geometry = (
        out["high"].ge(out[["open", "close"]].max(axis=1))
        & out["low"].le(out[["open", "close"]].min(axis=1))
        & out["high"].ge(out["low"])
        & out["volume"].ge(0)
    )
    if not bool(valid_geometry.all()):
        return pd.DataFrame()
    out = apply_structure_instruments(out, parameters)
    range_adx_max = float(parameters.get("h1_range_adx_max", 20.0) or 20.0)
    out["structure_regime"] = np.where(
        pd.to_numeric(out["adx"], errors="coerce").lt(range_adx_max),
        "range",
        np.where(out["structure_direction"].eq("bullish"), "trend_up", "trend_down"),
    )
    out["available_at"] = out["time"] + TIMEFRAME_DURATION[timeframe]
    out["context_hash"] = out.apply(lambda row: _row_hash(row, timeframe), axis=1)
    return out


def _m15_trap_state(frame: pd.DataFrame) -> pd.DataFrame:
    out = frame.copy()
    bullish = out["liquidity_sweep"].eq("bullish")
    bearish = out["liquidity_sweep"].eq("bearish")
    event = bullish | bearish
    out["trap_direction"] = out["liquidity_sweep"].astype("string").where(event, pd.NA).ffill()
    out["trap_available_at"] = out["available_at"].where(event).ffill()
    out["trap_extreme"] = np.where(bullish, out["low"], np.where(bearish, out["high"], np.nan))
    out["trap_extreme"] = pd.Series(out["trap_extreme"], index=out.index).ffill()
    out["trap_context_hash"] = out["context_hash"].astype("string").where(event, pd.NA).ffill()
    return out


def _merge_context(base: pd.DataFrame, source: pd.DataFrame, prefix: str, fields: list[str]) -> pd.DataFrame:
    selected = source[["available_at", *fields]].copy()
    selected = selected.rename(columns={field: f"{prefix}_{field}" for field in fields})
    selected = selected.rename(columns={"available_at": f"{prefix}_available_at"})
    return pd.merge_asof(
        base.sort_values("decision_at"),
        selected.sort_values(f"{prefix}_available_at"),
        left_on="decision_at",
        right_on=f"{prefix}_available_at",
        direction="backward",
    )


def _maximum_age(prefix: str, parameters: dict[str, Any]) -> pd.Timedelta:
    timeframe = prefix.upper()
    default_bars = {"H4": 2.0, "H1": 2.0, "M15": 2.0}[timeframe]
    bars = float(parameters.get(f"{prefix}_context_max_age_bars", default_bars) or default_bars)
    return TIMEFRAME_DURATION[timeframe] * max(1.0, bars)


def _row_hash(row: pd.Series, timeframe: str) -> str:
    values = {
        "protocol": PROTOCOL,
        "timeframe": timeframe,
        "time": str(row["time"]),
        "open": round(float(row["open"]), 10),
        "high": round(float(row["high"]), 10),
        "low": round(float(row["low"]), 10),
        "close": round(float(row["close"]), 10),
        "structure_direction": str(row.get("structure_direction", "unknown")),
        "structure_regime": str(row.get("structure_regime", "unknown")),
        "confirmed_swing_high": _hash_number(row.get("confirmed_swing_high")),
        "confirmed_swing_low": _hash_number(row.get("confirmed_swing_low")),
        "dynamic_fib_zone_low": _hash_number(row.get("dynamic_fib_zone_low")),
        "dynamic_fib_zone_high": _hash_number(row.get("dynamic_fib_zone_high")),
        "liquidity_sweep": str(row.get("liquidity_sweep", "none")),
    }
    return hashlib.sha256(json.dumps(values, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def _hash_number(value: object) -> float | None:
    try:
        numeric = float(value)
    except (TypeError, ValueError):
        return None
    return round(numeric, 10) if np.isfinite(numeric) else None


def _stack_hash(row: pd.Series) -> str:
    values = [
        str(row.get("h4_context_hash", "")), str(row.get("h1_context_hash", "")),
        str(row.get("m15_trap_context_hash", "")), str(row.get("time", "")),
        str(row.get("d1_context_hash", "")), str(row.get("related_m15_trap_context_hash", "")),
    ]
    return hashlib.sha256("|".join(values).encode()).hexdigest()


def _summary(status: str, reason: str, streams: dict[str, pd.DataFrame], missing: list[str]) -> dict[str, object]:
    return {
        "protocol": PROTOCOL,
        "status": status,
        "reason": reason,
        "provided_streams": sorted(streams),
        "missing_streams": missing,
        "rule": "Each H4/H1/M15 row is available only at candle_open + timeframe_duration and is merged backward into the M5 candle-close decision time.",
        "promotion_evidence": False,
    }
