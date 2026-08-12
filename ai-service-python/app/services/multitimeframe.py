"""Canonical XAUUSD H1 -> M15 routing contract.

The project already had a delayed H1 regime merge.  This module makes the
meaning of that merge explicit and reusable by screening, full replay and
paper execution:

* H1 is context and permission, never an M15 genetic parent;
* only an H1 candle whose close is known before the M15 decision is eligible;
* M15 owns the entry/timing signal;
* a direction conflict or missing/stale H1 context fails closed to WAIT;
* risk scaling is applied exactly once by the canonical execution path.
"""

from __future__ import annotations

import hashlib
import json
from typing import Any

import pandas as pd

from app.services.market_regime import apply_market_regime


PROTOCOL = "xauusd_h1_m15_mtf_v1"
DEFAULT_PILOT_ID = "xauusd_h1_m15_v1"


def _normalise_symbol(symbol: object) -> str:
    return str(symbol or "").upper().replace("/", "").replace("_", "").replace("-", "")


def _utc(value: object) -> pd.Timestamp:
    if value is None or (not isinstance(value, (list, tuple, dict)) and bool(pd.isna(value))):
        raise ValueError("missing timestamp")
    timestamp = pd.Timestamp(value)
    if timestamp.tzinfo is None:
        return timestamp.tz_localize("UTC")
    return timestamp.tz_convert("UTC")


def _iso(value: object) -> str | None:
    try:
        timestamp = _utc(value)
        return timestamp.isoformat()
    except (TypeError, ValueError):
        return None


def _canonical(value: object) -> object:
    if isinstance(value, dict):
        return {str(key): _canonical(value[key]) for key in sorted(value)}
    if isinstance(value, (list, tuple)):
        return [_canonical(item) for item in value]
    if isinstance(value, float):
        return round(value, 10)
    if pd.isna(value) if not isinstance(value, (str, bool, int, type(None))) else False:
        return None
    return value


def _hash(value: object) -> str:
    payload = json.dumps(_canonical(value), sort_keys=True, separators=(",", ":"), default=str)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def pilot_enabled(pilot: dict[str, Any] | None, symbol: object, timeframe: object) -> bool:
    contract = pilot or {}
    if not bool(contract.get("enabled", False)):
        return False
    if _normalise_symbol(symbol) != _normalise_symbol(contract.get("symbol", "XAUUSD")):
        return False
    if str(timeframe or "").upper() != str(contract.get("entry_timeframe", "M15")).upper():
        return False
    return str(contract.get("mode", "h1_veto_m15_risk")) != "m15_only"


def _direction_for_regime(regime: str) -> str | None:
    if regime == "trend_up":
        return "BUY"
    if regime == "trend_down":
        return "SELL"
    return None


def _permission_for_regime(regime: str, volatility: str, pilot: dict[str, Any]) -> tuple[str, float, str]:
    risk_map = {
        "high_volatility": float(pilot.get("high_volatility_risk_multiplier", 0.65)),
        "normal_volatility": float(pilot.get("normal_volatility_risk_multiplier", 1.0)),
        "low_volatility": float(pilot.get("low_volatility_risk_multiplier", 0.85)),
    }
    volatility_multiplier = max(0.0, min(1.0, risk_map.get(volatility, 0.75)))
    if regime in {"trend_up", "trend_down"}:
        return "ALLOW", volatility_multiplier, "H1_TREND_PERMISSION"
    if regime == "range":
        return "ALLOW_REDUCED", volatility_multiplier * float(pilot.get("range_risk_multiplier", 0.75)), "H1_RANGE_REDUCED_RISK"
    if regime == "transition":
        return "WAIT", 0.0, "H1_TRANSITION_WAIT"
    return "WAIT", 0.0, "H1_CONTEXT_UNCERTAIN"


def _context_from_row(row: Any, pilot: dict[str, Any], decision_time: object | None = None) -> dict[str, Any]:
    h1_closed_at = row.get("_h1_closed_at") if hasattr(row, "get") else None
    context_hash = row.get("_h1_context_hash") if hasattr(row, "get") else None
    h1_open_at = row.get("_h1_open_at") if hasattr(row, "get") else None
    regime = str(row.get("market_regime", "unknown") or "unknown") if hasattr(row, "get") else "unknown"
    volatility = str(row.get("volatility_regime", "normal_volatility") or "normal_volatility") if hasattr(row, "get") else "normal_volatility"
    missing_closed_at = h1_closed_at is None or (not isinstance(h1_closed_at, (list, tuple, dict)) and bool(pd.isna(h1_closed_at)))
    missing_context_hash = context_hash is None or (not isinstance(context_hash, (list, tuple, dict)) and bool(pd.isna(context_hash)))
    if missing_closed_at or missing_context_hash or str(context_hash) == "":
        return {
            "protocol": PROTOCOL,
            "pilot_id": str(pilot.get("pilot_id", DEFAULT_PILOT_ID)),
            "status": "blocked",
            "permission": "WAIT",
            "risk_multiplier": 0.0,
            "reason": "H1_CONTEXT_MISSING_OR_NOT_CLOSED",
            "h1_regime": regime,
            "h1_volatility_regime": volatility,
            "h1_direction": _direction_for_regime(regime),
            "h1_open_at": _iso(h1_open_at),
            "h1_closed_at": _iso(h1_closed_at),
            "h1_context_hash": context_hash,
        }

    try:
        closed = _utc(h1_closed_at)
        decision = _utc(decision_time) if decision_time is not None else closed
        age_seconds = max(0.0, (decision - closed).total_seconds())
    except (TypeError, ValueError):
        age_seconds = float("inf")
    max_age = float(pilot.get("max_h1_staleness_seconds", 7200))
    permission, multiplier, reason = _permission_for_regime(regime, volatility, pilot)
    if age_seconds > max_age:
        permission, multiplier, reason = "WAIT", 0.0, "H1_CONTEXT_STALE"
    return {
        "protocol": PROTOCOL,
        "pilot_id": str(pilot.get("pilot_id", DEFAULT_PILOT_ID)),
        "status": "ready" if permission != "WAIT" else "blocked",
        "permission": permission,
        "risk_multiplier": round(max(0.0, min(1.0, multiplier)), 6),
        "reason": reason,
        "h1_regime": regime,
        "h1_volatility_regime": volatility,
        "h1_direction": _direction_for_regime(regime),
        "h1_open_at": _iso(h1_open_at),
        "h1_closed_at": _iso(h1_closed_at),
        "h1_age_seconds": round(age_seconds, 3),
        "h1_context_hash": context_hash,
    }


def context_for_row(row: Any, pilot: dict[str, Any] | None, decision_time: object | None = None) -> dict[str, Any]:
    contract = pilot or {}
    if (
        not pilot_enabled(contract, "XAUUSD", "M15")
        or _normalise_symbol(contract.get("symbol", "XAUUSD")) != "XAUUSD"
        or str(contract.get("entry_timeframe", "M15")).upper() != "M15"
    ):
        return {
            "protocol": PROTOCOL,
            "pilot_id": str(contract.get("pilot_id", DEFAULT_PILOT_ID)),
            "status": "not_applicable",
            "permission": "BYPASS",
            "risk_multiplier": 1.0,
            "reason": "MTF_PILOT_DISABLED_OR_BASELINE",
        }
    return _context_from_row(row, contract, decision_time)


def apply_signal_policy(signal: str, row: Any, pilot: dict[str, Any] | None, decision_time: object | None = None) -> dict[str, Any]:
    """Return the MTF decision and its immutable context explanation."""
    contract = pilot or {}
    raw = str(signal or "WAIT").upper()
    context = context_for_row(row, contract, decision_time)
    if context["status"] == "not_applicable" or raw not in {"BUY", "SELL"}:
        return {
            "protocol": PROTOCOL,
            "raw_decision": raw,
            "decision": raw,
            "risk_decision": "BYPASS",
            "risk_multiplier": float(context.get("risk_multiplier", 1.0)),
            "reason": "NO_DIRECTIONAL_MTF_VETO",
            "context": context,
        }

    mode = str(contract.get("mode", "h1_veto_m15_risk"))
    if context.get("permission") not in {"ALLOW", "ALLOW_REDUCED"}:
        return {
            "protocol": PROTOCOL,
            "raw_decision": raw,
            "decision": "WAIT",
            "risk_decision": "WAIT",
            "risk_multiplier": 0.0,
            "reason": str(context.get("reason", "H1_CONTEXT_VETO")),
            "context": context,
        }
    if mode == "h1_regime_m15":
        return {
            "protocol": PROTOCOL,
            "raw_decision": raw,
            "decision": raw,
            "risk_decision": "ALLOW",
            "risk_multiplier": float(context.get("risk_multiplier", 1.0)),
            "reason": "H1_CONTEXT_ONLY_NO_DIRECTION_VETO",
            "context": context,
        }
    h1_direction = context.get("h1_direction")
    if h1_direction and raw != h1_direction:
        return {
            "protocol": PROTOCOL,
            "raw_decision": raw,
            "decision": "WAIT",
            "risk_decision": "WAIT",
            "risk_multiplier": 0.0,
            "reason": "H1_DIRECTION_VETO",
            "context": context,
        }
    return {
        "protocol": PROTOCOL,
        "raw_decision": raw,
        "decision": raw,
        "risk_decision": "ALLOW",
        "risk_multiplier": float(context.get("risk_multiplier", 1.0)),
        "reason": "H1_PERMISSION_GRANTED",
        "context": context,
    }


def annotate_regime_source(regime_source: pd.DataFrame | None) -> pd.DataFrame | None:
    """Prepare H1 rows with stable close availability and context hashes."""
    if regime_source is None or regime_source.empty:
        return None
    higher = regime_source.copy()
    higher["time"] = pd.to_datetime(higher["time"], utc=True, errors="coerce")
    higher = higher.dropna(subset=["time"]).sort_values("time").reset_index(drop=True)
    # The caller may inject the project-level regime classifier (the
    # backtester does this so its existing regression hooks remain valid).
    # Only classify raw H1 rows here when no regime fields exist yet.
    if "market_regime" not in higher.columns:
        higher = apply_market_regime(higher)
    higher["_h1_open_at"] = higher["time"]
    higher["_h1_closed_at"] = higher["time"] + pd.Timedelta(hours=1)
    higher["_h1_context_hash"] = higher.apply(
        lambda row: _hash({
            "protocol": PROTOCOL,
            "h1_open_at": _iso(row["time"]),
            "h1_closed_at": _iso(row["_h1_closed_at"]),
            "open": float(row.get("open", 0) or 0),
            "high": float(row.get("high", 0) or 0),
            "low": float(row.get("low", 0) or 0),
            "close": float(row.get("close", 0) or 0),
            "market_regime": str(row.get("market_regime", "unknown")),
            "volatility_regime": str(row.get("volatility_regime", "normal_volatility")),
        }), axis=1,
    )
    return higher


def counterfactuals(
    raw_decision: str,
    mtf_decision: dict[str, Any],
    contracts: dict[str, dict[str, Any]] | None = None,
) -> dict[str, Any]:
    context = dict(mtf_decision.get("context", {}))
    final_decision = str(mtf_decision.get("decision", raw_decision))
    h1_direction = context.get("h1_direction")
    contracts = contracts or {}
    return {
        "protocol": "mtf_shadow_twin_v1",
        "promotion_evidence": False,
        "m15_only": {
            "decision": raw_decision,
            "reason": "M15_ENTRY_WITHOUT_H1_PERMISSION",
            "execution_contract": contracts.get("m15_only"),
        },
        "h1_m15_official": {
            "decision": final_decision,
            "reason": mtf_decision.get("reason"),
            "execution_contract": contracts.get("h1_m15_official"),
        },
        "m15_without_h1_veto": {
            "decision": raw_decision,
            "reason": "COUNTERFACTUAL_H1_VETO_REMOVED",
            "execution_contract": contracts.get("m15_without_h1_veto"),
        },
        "h1_only_context": {
            "decision": h1_direction or "WAIT",
            "reason": "H1_IS_REGIME_CONTEXT_NOT_AN_M15_ENTRY",
            "h1_regime": context.get("h1_regime"),
        },
    }
