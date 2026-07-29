"""Optional time-series foundation-model prior contract.

The local strategy remains the only alpha source.  An external foundation
model may supply an as-of volatility/transition prior, but no installed or
missing model is silently replaced with a pseudo-forecast.
"""
from __future__ import annotations

from typing import Any


def evaluate_foundation_prior(policy_context: dict[str, Any], direction: str) -> dict[str, object]:
    prior = policy_context.get("foundation_prior", {}) if isinstance(policy_context, dict) else {}
    if not isinstance(prior, dict) or not prior.get("provider") or not prior.get("as_of"):
        return {"status": "unavailable", "influenced_decision": False,
                "rule": "No provider/as-of prior means the local agent decides alone."}
    forecast_direction = str(prior.get("direction", "WAIT"))
    confidence = max(0.0, min(1.0, float(prior.get("confidence", 0) or 0)))
    incremental = max(0.0, float(prior.get("incremental_evidence", 0) or 0))
    agreement = forecast_direction == direction and confidence >= .55 and incremental > 0
    return {
        "status": "as_of_prior_received", "provider": str(prior["provider"]), "version": prior.get("version"),
        "as_of": str(prior["as_of"]), "direction": forecast_direction, "confidence": round(confidence, 5),
        "incremental_evidence": round(incremental, 5), "influenced_decision": agreement,
        "rule": "Prior may confirm a local cost-adjusted edge; it never creates a trade by itself.",
    }
