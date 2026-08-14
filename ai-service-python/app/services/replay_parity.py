"""Deterministic parity helpers for optimized stateful replay lanes.

The canonical trace path remains the reference.  This harness compares the
trace-free optimized executor with the reference on the same prepared signal
snapshot before the fast lane is allowed to carry cost/mutation evidence.
"""

from __future__ import annotations

from typing import Any

import pandas as pd

from app.schemas import SimpleBacktestRequest


def compare_stateful_replay(
    payload: SimpleBacktestRequest,
    frame: pd.DataFrame,
    *,
    prepared_snapshot: Any = None,
    lightweight: bool = True,
) -> dict[str, Any]:
    from app.services.backtester import _run_prepared_simple_backtest

    reference = _run_prepared_simple_backtest(
        payload,
        frame,
        lightweight=lightweight,
        prepared_snapshot=prepared_snapshot,
        fast_stateful=False,
    ).model_dump(mode="json")
    optimized = _run_prepared_simple_backtest(
        payload,
        frame,
        lightweight=lightweight,
        prepared_snapshot=prepared_snapshot,
        fast_stateful=True,
    ).model_dump(mode="json")

    # These fields cover both the stateful trade ledger and every gate-facing
    # aggregate. Trace/diagnostic payloads are intentionally excluded because
    # the optimized lane is only used without canonical trace emission.
    fields = (
        "total_trades",
        "wins",
        "losses",
        "net_profit_percent",
        "profit_factor",
        "max_drawdown_percent",
        "trade_ledger_hash",
        "entry_funnel",
        "pf_attribution",
        "window_survival",
        "robustness_matrix",
        "data_quality",
    )
    mismatches = {
        field: {"reference": reference.get(field), "optimized": optimized.get(field)}
        for field in fields
        if reference.get(field) != optimized.get(field)
    }

    return {
        "protocol": "stateful_subreplay_parity_v1",
        "passed": mismatches == {},
        "compared_fields": list(fields),
        "mismatches": mismatches,
        "reference": reference,
        "optimized": optimized,
    }
