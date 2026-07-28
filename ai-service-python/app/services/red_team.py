"""Adversarial replay diagnostics for learning, never a hidden promotion veto."""

from __future__ import annotations

from typing import Any


class RedTeamService:
    def evaluate(self, result: dict[str, Any]) -> dict[str, Any]:
        profile = result.get("pf_attribution", {}) or {}
        stress_pf = float((profile.get("stress_cost", {}) or {}).get("profit_factor", 0))
        volatility = result.get("volatility_performance", {}) or {}
        sessions = (profile.get("by_session", {}) or {})
        exits = (profile.get("by_exit_reason", {}) or {})
        high_vol = volatility.get("high_volatility", {}) or {}
        high_vol_pf = float(high_vol.get("profit_factor", high_vol.get("net_pf", 0)) or 0)
        session_pfs = [float(item.get("net_pf", 0) or 0) for item in sessions.values() if int(item.get("trades", 0) or 0) >= 3]
        worst_session_pf = min(session_pfs) if session_pfs else None
        stop_trades = sum(int((exits.get(key, {}) or {}).get("trades", 0) or 0) for key in ("intrabar_stop", "gap_stop"))
        scenarios = {
            "double_cost_execution": {
                "status": "assessed", "profit_factor": stress_pf,
                "pass": stress_pf >= 1.05,
            },
            "high_volatility": {
                "status": "assessed" if high_vol else "insufficient_sample", "profit_factor": high_vol_pf,
                "pass": bool(high_vol) and high_vol_pf >= 1.0,
            },
            "worst_session": {
                "status": "assessed" if worst_session_pf is not None else "insufficient_sample",
                "profit_factor": worst_session_pf, "pass": worst_session_pf is not None and worst_session_pf >= 1.0,
            },
            "stop_pressure": {"status": "assessed", "stop_exits": stop_trades, "pass": stop_trades == 0},
            # Economic events are classified only from the official calendar
            # aligned dataset. Absent alignment is visible evidence, not a
            # fabricated pass/fail assertion.
            "news_window": {"status": "not_assessed", "reason": "official_calendar_alignment_required"},
        }
        recommendations: list[str] = []
        if stress_pf and stress_pf < 1.05:
            recommendations.extend(["high_volatility_risk_multiplier", "atr_stop_multiplier", "atr_target_multiplier"])
        if high_vol and high_vol_pf < 1.0:
            recommendations.extend(["high_volatility_risk_multiplier", "avoid_high_volatility"])
        if stop_trades:
            recommendations.extend(["atr_stop_multiplier", "trailing_atr_multiplier"])
        return {
            "protocol": "adversarial diagnosis only; failure routes a bounded mutation and never lowers promotion gates",
            "scenarios": scenarios,
            "recommendations": list(dict.fromkeys(recommendations)),
            "status": "needs_rescue" if recommendations else "no_observed_red_team_failure",
        }
