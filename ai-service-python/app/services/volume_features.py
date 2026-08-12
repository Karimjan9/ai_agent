"""Causal volume context for the research and paper execution paths.

The volume layer is deliberately an optional context specialist.  It never
turns a missing source into a low-volume observation and it never changes a
frozen non-volume replay.  Relative volume uses only earlier candles from the
same UTC session bucket, with a global prior-candle fallback.
"""

from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd


VOLUME_LANES = {
    "none",
    "breakout_volume_confirmation",
    "transition_volume_router",
    "low_volume_risk_firewall",
}
VOLUME_PROTOCOL = "relative_volume_session_v2"
SOURCE_CONTRACT = "dukascopy_jetta_bid_tick_volume_millions_v1"


def _utc_timestamp(value: object) -> pd.Timestamp:
    timestamp = pd.Timestamp(value)
    return timestamp.tz_localize("UTC") if timestamp.tzinfo is None else timestamp.tz_convert("UTC")


def _normalization_context(context: dict[str, Any] | None = None) -> dict[str, Any]:
    """Return the causal calendar contract for the requested timeframe.

    H1 and M15 do not share the same intraday seasonality bucket.  An hour
    bucket is too coarse for M15 because it mixes the first, second, third,
    and fourth quarter-hour of the same hour.  The bucket also includes the
    UTC weekday so Monday 00:15 is not normalized against Friday 00:15.
    """
    context = dict(context or {})
    timeframe = str(context.get("timeframe") or "H1").upper()
    if timeframe == "M15":
        return {
            "timeframe": "M15",
            "seasonality_bucket": "utc_weekday_m15_slot_v2",
            "slots_per_day": 96,
            "global_lookback": 672,  # seven days of M15 candles
            "global_min_periods": 20,
            "session_lookback": 20,
        }
    return {
        "timeframe": "H1",
        "seasonality_bucket": "utc_weekday_h1_slot_v2",
        "slots_per_day": 24,
        "global_lookback": 168,  # seven days of H1 candles
        "global_min_periods": 10,
        "session_lookback": 20,
    }


def _seasonality_bucket(
    times: pd.Series,
    normalization: dict[str, Any],
) -> pd.Series:
    slots_per_day = int(normalization["slots_per_day"])
    if slots_per_day == 96:
        intraday_slot = times.dt.hour * 4 + (times.dt.minute // 15)
    else:
        intraday_slot = times.dt.hour
    return times.dt.dayofweek * slots_per_day + intraday_slot


def _causal_baselines(
    values: pd.Series,
    bucket: pd.Series,
    *,
    session_lookback: int,
    global_lookback: int,
    global_min_periods: int,
) -> tuple[pd.Series, pd.Series, pd.Series]:
    """Build session and global medians using only candles before the row."""
    session_prior = values.groupby(bucket, dropna=False, sort=False).transform(
        lambda grouped: grouped.shift(1).rolling(
            session_lookback,
            min_periods=3,
        ).median()
    )
    global_prior = values.shift(1).rolling(
        global_lookback,
        min_periods=global_min_periods,
    ).median()
    return session_prior, global_prior, session_prior.where(
        session_prior.gt(0), global_prior
    )


def volume_quality_gate(
    frame: pd.DataFrame,
    context: dict[str, Any] | None = None,
    *,
    minimum_coverage: float = 0.95,
    minimum_usable_ratio: float = 0.95,
) -> dict[str, Any]:
    """Validate the explicit canonical volume column before using it.

    A missing ``volume_available`` column is intentionally a hard unavailable
    result.  This prevents legacy provider values, zero-filled API payloads,
    and mixed-unit archives from silently becoming a research feature.
    """
    total = int(len(frame))
    context = dict(context or {})
    normalization = _normalization_context(context)
    source_contract = str(context.get("source_contract") or SOURCE_CONTRACT)
    provider = str(context.get("provider") or "dukascopy")
    unit = str(context.get("unit") or "millions")
    session = str(context.get("session") or "UTC")
    if total == 0:
        return {
            "status": "volume_unavailable",
            "reason": "empty_dataset",
            "provider": provider,
            "unit": unit,
            "session": session,
            "source_contract": source_contract,
            "protocol": VOLUME_PROTOCOL,
            "timeframe": normalization["timeframe"],
            "normalization": normalization,
            "coverage": 0.0,
            "usable_ratio": 0.0,
            "rows": 0,
            "promotion_evidence": False,
        }

    if "volume_available" not in frame.columns:
        return {
            "status": "volume_unavailable",
            "reason": "source_contract_missing",
            "provider": provider,
            "unit": unit,
            "session": session,
            "source_contract": source_contract,
            "protocol": VOLUME_PROTOCOL,
            "timeframe": normalization["timeframe"],
            "normalization": normalization,
            "coverage": 0.0,
            "usable_ratio": 0.0,
            "rows": total,
            "promotion_evidence": False,
        }

    available = frame["volume_available"].fillna(False).astype(bool)
    raw = pd.to_numeric(frame.get("volume", 0.0), errors="coerce")
    usable = available & raw.gt(0) & raw.notna() & np.isfinite(raw)
    coverage = float(available.mean())
    usable_ratio = float(usable.sum() / max(int(available.sum()), 1))
    status = (
        "passed"
        if coverage >= minimum_coverage and usable_ratio >= minimum_usable_ratio
        else "volume_unavailable"
    )
    reasons: list[str] = []
    if coverage < minimum_coverage:
        reasons.append("coverage_below_threshold")
    if usable_ratio < minimum_usable_ratio:
        reasons.append("usable_ratio_below_threshold")
    if not reasons and status == "passed":
        reasons.append("quality_gate_passed")
    return {
        "status": status,
        "reason": reasons[0],
        "reasons": reasons,
        "provider": provider,
        "unit": unit,
        "session": session,
        "source_contract": source_contract,
        "protocol": VOLUME_PROTOCOL,
        "timeframe": normalization["timeframe"],
        "normalization": normalization,
        "coverage": round(coverage, 6),
        "usable_ratio": round(usable_ratio, 6),
        "rows": total,
        "available_rows": int(available.sum()),
        "usable_rows": int(usable.sum()),
        "zero_rows": int((available & ~usable).sum()),
        "minimum_coverage": minimum_coverage,
        "minimum_usable_ratio": minimum_usable_ratio,
        "promotion_evidence": False,
    }


def add_volume_features(
    frame: pd.DataFrame,
    context: dict[str, Any] | None = None,
) -> pd.DataFrame:
    """Add causal, normalized relative-volume context to a candle frame."""
    out = frame.copy()
    if "volume" not in out.columns:
        out["volume"] = 0.0
    out["volume"] = pd.to_numeric(out["volume"], errors="coerce")
    if "volume_available" in out.columns:
        out["volume_available"] = out["volume_available"].fillna(False).astype(bool)
    else:
        # An explicit source marker is required by the canonical contract.
        out["volume_available"] = False

    if "time" in out.columns:
        times = pd.to_datetime(out["time"], errors="coerce", utc=True)
    else:
        times = pd.Series(pd.NaT, index=out.index, dtype="datetime64[ns, UTC]")
    available = out["volume_available"] & out["volume"].gt(0) & out["volume"].notna()
    raw = out["volume"].where(available)

    normalization = _normalization_context(context)
    # The bucket is a provider/session normalization key, not a future-aware
    # calendar label.  The source contract fixes the timezone to UTC.
    seasonality_bucket = _seasonality_bucket(times, normalization)
    _, _, baseline = _causal_baselines(
        raw,
        seasonality_bucket,
        session_lookback=int(normalization["session_lookback"]),
        global_lookback=int(normalization["global_lookback"]),
        global_min_periods=int(normalization["global_min_periods"]),
    )
    feature_available = available & baseline.gt(0) & baseline.notna()
    ratio = (out["volume"] / baseline).where(feature_available)

    out["volume_feature_available"] = feature_available.astype(bool)
    out["volume_ratio"] = ratio.astype(float)
    out["volume_ratio"] = out["volume_ratio"].replace([np.inf, -np.inf], np.nan)
    out["volume_ratio_filled"] = out["volume_ratio"].fillna(1.0)
    out["volume_regime"] = "unavailable"
    out.loc[feature_available & ratio.lt(0.75), "volume_regime"] = "low"
    out.loc[feature_available & ratio.between(0.75, 1.5, inclusive="left"), "volume_regime"] = "normal"
    out.loc[feature_available & ratio.ge(1.5), "volume_regime"] = "high"
    previous_ratio = out["volume_ratio"].shift(1)
    out["volume_shock"] = (
        feature_available
        & ratio.ge(1.5)
        & (previous_ratio.isna() | ratio.ge(previous_ratio * 1.25))
    )

    # Effort-vs-result is deliberately diagnostic for now.  It compares
    # activity with the candle's causal range baseline, but it never changes
    # a signal or a risk multiplier.  This lets the shadow lane test whether
    # high activity is initiative (large result) or absorption (small result)
    # without turning a descriptive label into an unproven gate.
    price_range = (pd.to_numeric(out.get("high"), errors="coerce") - pd.to_numeric(out.get("low"), errors="coerce")).where(
        lambda values: values.gt(0)
    )
    range_session, range_global, range_baseline = _causal_baselines(
        price_range,
        seasonality_bucket,
        session_lookback=int(normalization["session_lookback"]),
        global_lookback=int(normalization["global_lookback"]),
        global_min_periods=int(normalization["global_min_periods"]),
    )
    # Keep the local variables visible in debugging snapshots while choosing
    # the same session-first/global-fallback rule as volume.
    del range_session, range_global
    range_feature_available = feature_available & range_baseline.gt(0) & range_baseline.notna()
    range_ratio = (price_range / range_baseline).where(range_feature_available)
    out["volume_range_ratio"] = range_ratio.replace([np.inf, -np.inf], np.nan).astype(float)
    out["effort_result_regime"] = "unavailable"
    out.loc[range_feature_available, "effort_result_regime"] = "balanced"
    out.loc[
        range_feature_available & ratio.ge(1.5) & range_ratio.ge(1.25),
        "effort_result_regime",
    ] = "initiative"
    out.loc[
        range_feature_available & ratio.ge(1.5) & range_ratio.lt(0.75),
        "effort_result_regime",
    ] = "absorption"
    out.loc[
        range_feature_available & ratio.lt(0.75) & range_ratio.ge(1.25),
        "effort_result_regime",
    ] = "thin_move"
    out["volume_risk_multiplier"] = 1.0
    out["volume_policy_rejection"] = ""
    out["volume_lane_applied"] = "none"
    out["volume_quality"] = None
    quality = volume_quality_gate(out, context)
    # Keep the aggregate contract in attrs; the response layer serializes it
    # once, avoiding an object-valued column in every output row.
    out.attrs["volume_quality"] = quality
    out.attrs["volume_protocol"] = VOLUME_PROTOCOL
    out.attrs["volume_source_contract"] = SOURCE_CONTRACT
    return out


def apply_volume_policy(
    frame: pd.DataFrame,
    parameters: dict[str, Any] | None = None,
    strategy_name: str | None = None,
) -> pd.DataFrame:
    """Apply one bounded volume child policy to a prepared signal frame."""
    out = frame.copy()
    p = parameters or {}
    lane = str(p.get("volume_lane", "none") or "none")
    if lane not in VOLUME_LANES:
        raise ValueError(f"Unsupported volume lane: {lane}")
    if "volume_feature_available" not in out.columns:
        out = add_volume_features(out)
    if "signal" not in out.columns:
        out["signal"] = "WAIT"
    if "signal_confidence" not in out.columns:
        out["signal_confidence"] = 0.0
    out["pre_volume_signal"] = out["signal"].astype(str)
    out["pre_volume_signal_confidence"] = pd.to_numeric(
        out["signal_confidence"], errors="coerce"
    ).fillna(0.0)
    out["volume_lane_applied"] = lane
    if "volume_policy_rejection" not in out.columns:
        out["volume_policy_rejection"] = ""
    if "volume_risk_multiplier" not in out.columns:
        out["volume_risk_multiplier"] = 1.0

    if lane == "none":
        return out

    available = out["volume_feature_available"].fillna(False).astype(bool)
    ratio = pd.to_numeric(out.get("volume_ratio"), errors="coerce")
    actionable = out["signal"].astype(str).isin(["BUY", "SELL"])
    unavailable = actionable & ~available
    # Missing volume is an explicit unavailable state. It is never a low
    # volume veto and does not reduce risk.
    out.loc[unavailable, "volume_policy_rejection"] = "volume_unavailable"

    if lane == "breakout_volume_confirmation":
        specialist = out.get("selected_specialist", pd.Series("", index=out.index)).astype(str).str.lower()
        identity = str(strategy_name or "").lower()
        breakout_scope = specialist.str.contains("breakout", na=False) | (
            specialist.eq("") & specialist.ne("none")
        )
        if "breakout" in identity or "volatility" in identity:
            breakout_scope = pd.Series(True, index=out.index)
        veto = actionable & breakout_scope & available & ratio.lt(1.25)
        out.loc[veto, "signal"] = "WAIT"
        out.loc[veto, "signal_confidence"] = 0.0
        out.loc[veto, "volume_policy_rejection"] = "breakout_volume_not_confirmed"

    elif lane == "transition_volume_router":
        regime = out.get("market_regime", pd.Series("unknown", index=out.index)).astype(str)
        volatility = out.get("volatility_regime", pd.Series("normal_volatility", index=out.index)).astype(str)
        transition = regime.ne(regime.shift(1)) | volatility.ne(volatility.shift(1))
        transition &= regime.shift(1).notna()
        shock = pd.to_numeric(out.get("volume_ratio"), errors="coerce").ge(1.5)
        veto = actionable & transition & available & ~shock
        out.loc[veto, "signal"] = "WAIT"
        out.loc[veto, "signal_confidence"] = 0.0
        out.loc[veto, "volume_policy_rejection"] = "transition_volume_not_shocked"

    elif lane == "low_volume_risk_firewall":
        hard_wait = actionable & available & ratio.lt(0.50)
        reduced = actionable & available & ratio.ge(0.50) & ratio.lt(0.75)
        out.loc[hard_wait, "signal"] = "WAIT"
        out.loc[hard_wait, "signal_confidence"] = 0.0
        out.loc[hard_wait, "volume_policy_rejection"] = "low_volume_wait"
        out.loc[reduced, "volume_risk_multiplier"] = 0.5
        out.loc[reduced, "volume_policy_rejection"] = "low_volume_reduced_risk"

    return out


def volume_shadow_report(
    frame: pd.DataFrame,
    trades: list[dict[str, Any]] | None = None,
    context: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Produce observational decile evidence without promotion semantics."""
    out = add_volume_features(frame, context)
    quality = dict(out.attrs.get("volume_quality") or volume_quality_gate(out, context))
    usable = out[out["volume_feature_available"]].copy()
    report: dict[str, Any] = {
        "status": "assessed" if len(usable) >= 30 else "insufficient_evidence",
        "protocol": VOLUME_PROTOCOL,
        "source_contract": SOURCE_CONTRACT,
        "quality": quality,
        "promotion_evidence": False,
        "control_required": True,
        "deciles": [],
        "trade_deciles": [],
        "trade_decile_summary": [],
        "signal_deciles": [],
        "effort_result_summary": [],
    }
    if usable.empty:
        return report

    effort = usable[usable["effort_result_regime"] != "unavailable"]
    if not effort.empty:
        for regime, bucket in effort.groupby("effort_result_regime", sort=True):
            entry: dict[str, Any] = {
                "regime": str(regime),
                "rows": int(len(bucket)),
                "median_volume_ratio": round(float(bucket["volume_ratio"].median()), 6),
                "median_range_ratio": round(float(bucket["volume_range_ratio"].median()), 6),
            }
            for horizon in (1, 4, 12):
                returns = (out["close"].shift(-horizon) / out["close"] - 1.0) * 100.0
                values = returns.loc[bucket.index].dropna()
                entry[f"forward_{horizon}_candles"] = {
                    "samples": int(len(values)),
                    "mean_return_percent": round(float(values.mean()), 6) if len(values) else None,
                    "win_rate": round(float((values > 0).mean()), 6) if len(values) else None,
                }
            report["effort_result_summary"].append(entry)

    usable["volume_decile"] = pd.qcut(
        usable["volume_ratio"].rank(method="first"), 10, labels=False, duplicates="drop"
    ) + 1
    decile_by_index = usable["volume_decile"].to_dict()
    for decile, bucket in usable.groupby("volume_decile", dropna=True):
        entry: dict[str, Any] = {
            "decile": int(decile),
            "rows": int(len(bucket)),
            "median_ratio": round(float(bucket["volume_ratio"].median()), 6),
        }
        for horizon in (1, 4, 12):
            returns = (out["close"].shift(-horizon) / out["close"] - 1.0) * 100.0
            values = returns.loc[bucket.index].dropna()
            entry[f"forward_{horizon}_candles"] = {
                "samples": int(len(values)),
                "mean_return_percent": round(float(values.mean()), 6) if len(values) else None,
                "win_rate": round(float((values > 0).mean()), 6) if len(values) else None,
            }
        report["deciles"].append(entry)

    trade_rows = trades or []
    if trade_rows and "time" in out.columns:
        keyed = {
            _utc_timestamp(value).isoformat(): (ratio, decile_by_index.get(index))
            for index, (value, ratio) in zip(out.index, zip(out["time"], out["volume_ratio"]))
            if pd.notna(value)
        }
        summary: dict[int, list[float]] = {}
        for trade in trade_rows:
            signal_time = str(trade.get("signal_time") or "")
            try:
                normalized_signal_time = _utc_timestamp(signal_time).isoformat()
            except (TypeError, ValueError):
                normalized_signal_time = signal_time
            keyed_value = keyed.get(normalized_signal_time)
            if keyed_value is None:
                continue
            ratio, decile = keyed_value
            if ratio is None or not np.isfinite(float(ratio)):
                continue
            # Use the empirical rank cut points from the usable sample. This
            # is descriptive only and does not feed any gate.
            rank = float((usable["volume_ratio"] <= float(ratio)).mean())
            decile = min(10, max(1, int(np.ceil(rank * 10))))
            summary.setdefault(decile, []).append(float(trade.get("profit_percent", 0) or 0))
            report["trade_deciles"].append({
                "signal_time": signal_time,
                "decile": decile,
                "volume_ratio": round(float(ratio), 6),
                "result": trade.get("result"),
                "profit_percent": trade.get("profit_percent"),
            })
        for decile, values in sorted(summary.items()):
            positive = sum(value for value in values if value > 0)
            negative = abs(sum(value for value in values if value < 0))
            equity = 100.0
            peak = equity
            drawdown = 0.0
            for value in values:
                equity *= 1.0 + value / 100.0
                peak = max(peak, equity)
                drawdown = max(drawdown, (peak - equity) / max(peak, 0.0000001) * 100.0)
            report["trade_decile_summary"].append({
                "decile": int(decile),
                "trades": len(values),
                "profit_factor": round(positive / negative, 6) if negative else (999.0 if positive else 0.0),
                "net_profit_percent": round(equity - 100.0, 6),
                "max_drawdown_percent": round(drawdown, 6),
            })

    if "signal" in out.columns:
        signal = out["signal"].astype(str)
        original = out.get("pre_volume_signal", signal).astype(str)
        signal_summary: dict[int, dict[str, int]] = {}
        for index, decile in decile_by_index.items():
            if decile is None:
                continue
            row = signal_summary.setdefault(int(decile), {"opportunities": 0, "accepted": 0, "volume_vetoes": 0})
            if original.loc[index] in {"BUY", "SELL"}:
                row["opportunities"] += 1
            if signal.loc[index] in {"BUY", "SELL"}:
                row["accepted"] += 1
            rejection = str(out.loc[index].get("volume_policy_rejection", ""))
            if rejection.startswith(("breakout_volume", "transition_volume", "low_volume")):
                row["volume_vetoes"] += 1
        report["signal_deciles"] = [
            {
                "decile": decile,
                **values,
                "opportunity_recall": round(values["accepted"] / max(1, values["opportunities"]), 6),
            }
            for decile, values in sorted(signal_summary.items())
        ]
    return report
