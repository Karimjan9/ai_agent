"""Ranking-only fitness calculations for replay and holdout results.

Promotion gates are intentionally not implemented here.  This module only
turns an already computed replay result into a comparable score and quality
breakdown.
"""

from __future__ import annotations

import math


def _numeric(value: object, default: float = 0.0) -> float:
    try:
        number = float(value)
        return number if math.isfinite(number) else default
    except (TypeError, ValueError):
        return default


def _row_profit_factor(row: object) -> float | None:
    if not isinstance(row, dict):
        return None
    for key in ("profit_factor", "net_pf", "pf"):
        if key in row and row[key] is not None:
            return _numeric(row[key], 0.0)
    wins = _numeric(row.get("wins"), 0.0)
    losses = _numeric(row.get("losses"), 0.0)
    if wins or losses:
        return wins / losses if losses else (99.0 if wins else 0.0)
    return None


def _rows_from_metric(value: object) -> list[dict[str, object]]:
    if isinstance(value, dict):
        return [row for row in value.values() if isinstance(row, dict)]
    if isinstance(value, list):
        return [row for row in value if isinstance(row, dict)]
    return []


def fitness_quality(result: dict) -> dict[str, object]:
    survival = result.get("screening_survival") or {}
    monthly = result.get("monthly_passport") or {}
    survival_months = (survival.get("calendar_month_survival") or {}).get("months")
    attribution = result.get("pf_attribution") or {}
    attribution_breakdown = attribution.get("breakdown") or {}
    month_rows = _rows_from_metric(survival_months)
    if not month_rows:
        month_rows = _rows_from_metric(monthly.get("months"))
    if not month_rows:
        month_rows = _rows_from_metric(attribution_breakdown.get("by_month"))
    if not month_rows:
        month_rows = _rows_from_metric(attribution.get("by_month"))

    month_pfs = [pf for row in month_rows if (pf := _row_profit_factor(row)) is not None and _numeric(row.get("trades"), 0) >= 2]
    month_positive = sum(
        pf >= 1.0 and _numeric(row.get("net_profit_percent"), 0.0) > 0
        for row, pf in ((row, _row_profit_factor(row)) for row in month_rows)
        if pf is not None and _numeric(row.get("trades"), 0) >= 2
    )
    regime_rows = _rows_from_metric(result.get("regime_performance"))
    regime_pfs = [pf for row in regime_rows if (pf := _row_profit_factor(row)) is not None and _numeric(row.get("trades"), 0) >= 10]
    stress = survival.get("stress_cost_pf")
    if stress is None:
        stress = (attribution.get("stress_cost") or {}).get("profit_factor")
    if stress is None:
        stress = (attribution_breakdown.get("stress_cost") or {}).get("profit_factor")
    summary = attribution.get("summary") or attribution_breakdown.get("summary") or {}
    explicit_worst_month = survival.get("worst_calendar_month_pf")
    return {
        "trade_count": int(_numeric(result.get("total_trades"), 0)),
        "trade_confidence": round(min(1.0, _numeric(result.get("total_trades"), 0) / 30.0), 4),
        "worst_month_pf": round(_numeric(explicit_worst_month), 4) if explicit_worst_month is not None else (round(min(month_pfs), 4) if month_pfs else None),
        "month_consistency": round(month_positive / len(month_pfs), 4) if month_pfs else None,
        "months_observed": len(month_pfs),
        "worst_regime_pf": round(min(regime_pfs), 4) if regime_pfs else None,
        "regime_coverage": round(sum(pf >= 1.0 for pf in regime_pfs) / len(regime_pfs), 4) if regime_pfs else None,
        "regimes_observed": len(regime_pfs),
        "stress_cost_pf": round(_numeric(stress), 4) if stress is not None else None,
        "cost_to_gross_profit_percent": round(_numeric(summary.get("cost_to_gross_profit_percent")), 4),
    }


def calculate_strategy_score(result: dict) -> int:
    winrate = result.get("winrate", 0)
    profit = result.get("net_profit_percent", 0)
    total_trades = result.get("total_trades", 0)
    max_drawdown = result.get("max_drawdown_percent", result.get("max_drawdown", 0))
    profit_factor = result.get("profit_factor", 0)
    max_consecutive_losses = result.get("max_consecutive_losses", 0)
    stability_score = result.get("stability_score", 0)
    regime_performance = result.get("regime_performance", {})
    quality = fitness_quality(result)
    score = 0
    score += min(profit, 30) * 0.8 if profit > 0 else profit * 1.2
    score += winrate * 0.2
    score += 25 if profit_factor >= 2 else 20 if profit_factor >= 1.7 else 15 if profit_factor >= 1.4 else 8 if profit_factor >= 1.1 else -15 if profit_factor < 1 else 0
    score += -35 if max_drawdown > 25 else -25 if max_drawdown > 20 else -18 if max_drawdown > 15 else -10 if max_drawdown > 10 else 8 if max_drawdown <= 5 else 0
    score += -20 if max_consecutive_losses >= 10 else -12 if max_consecutive_losses >= 7 else -6 if max_consecutive_losses >= 5 else 0
    score += stability_score * 0.2
    score += -20 if total_trades < 20 else 5 if total_trades >= 100 else 0
    profitable_regimes = sum(1 for data in regime_performance.values() if data.get("trades", 0) >= 10 and data.get("profit_percent", 0) > 0)
    score += 8 if profitable_regimes >= 3 else 5 if profitable_regimes == 2 else -8 if profitable_regimes == 0 else 0
    if quality["month_consistency"] is not None:
        score += (_numeric(quality["month_consistency"]) - .5) * 12
        score += max(-1.0, min(1.0, _numeric(quality["worst_month_pf"]) - 1.0)) * 6
    if quality["regime_coverage"] is not None:
        score += (_numeric(quality["regime_coverage"]) - .5) * 8
        score += max(-1.0, min(1.0, _numeric(quality["worst_regime_pf"]) - 1.0)) * 6
    if quality["stress_cost_pf"] is not None:
        score += max(-1.0, min(1.0, _numeric(quality["stress_cost_pf"]) - 1.05)) * 8
        score -= max(0.0, _numeric(quality["cost_to_gross_profit_percent"]) - 15.0) * .15
    return round(max(min(score, 100), 0))


def build_fitness_breakdown(result: dict, fitness_score: int | None = None) -> dict[str, object]:
    quality = fitness_quality(result)
    return {
        "protocol": "fitness_quality_v2",
        "score": int(fitness_score if fitness_score is not None else calculate_strategy_score(result)),
        "components": quality,
        "weights": {"profit_and_pf": "base_score", "monthly_stability": "worst_month_pf + positive_month_ratio", "regime_coverage": "worst_regime_pf + observed_regime_ratio", "execution_cost": "stress_cost_pf + cost_to_gross_profit_percent", "sample_size": "trade_confidence"},
        "promotion_evidence": False,
        "rule": "Ranking only; forward, paper, portfolio and live gates remain unchanged.",
    }


def calculate_final_walk_forward_score(forward_score: int, robustness_score: int, is_overfit: bool, fitness_score: int | None = None) -> int:
    score = (forward_score * .70) + (robustness_score * .30) if fitness_score is None else (forward_score * .55) + (robustness_score * .25) + (fitness_score * .20)
    return round(max(min(score - 20 if is_overfit else score, 100), 0))
