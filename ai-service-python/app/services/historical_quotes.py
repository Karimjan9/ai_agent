"""Validate synchronized close-time quote observations in a prospectively sealed M5 stream."""

import pandas as pd


def validate_historical_quotes(frame: pd.DataFrame, payload) -> pd.DataFrame:
    if "spread_available" not in frame.columns:
        return frame
    pilot = dict(payload.mtf_pilot or {})
    autonomous = bool(pilot.get("enabled")) and pilot.get("activation_status") == "execution_stream_bound"
    provenance = dict((payload.mtf_snapshot_manifest or {}).get("quote_spread_provenance") or {})
    if autonomous and (
        provenance.get("protocol") != "historical_quote_spread_snapshot_v1"
        or provenance.get("provider") != "dukascopy_historical_synchronized_tick_v1"
        or provenance.get("maximum_quote_age_ms") != 60000
        or provenance.get("paper_2026_included") is not False
        or provenance.get("promotion_evidence") is not False
        or not provenance.get("sources")
        or len(str(provenance.get("source_m5_csv_sha256") or "")) != 64
    ):
        raise ValueError("HISTORICAL_QUOTE_PROVENANCE_INVALID")
    if str(payload.timeframe).upper() != "M5":
        raise ValueError("HISTORICAL_QUOTE_TIMEFRAME_MISMATCH")
    columns = {"spread", "bid_close", "ask_close", "quote_time_utc", "quote_available_after_utc", "quote_age_ms"}
    if not columns.issubset(frame.columns):
        raise ValueError("HISTORICAL_QUOTE_COLUMNS_MISSING")
    marker = pd.to_numeric(frame["spread_available"], errors="coerce")
    if not marker.isin([0, 1]).all():
        raise ValueError("HISTORICAL_QUOTE_AVAILABILITY_INVALID")
    available = marker.eq(1)
    for column in ("spread", "bid_close", "ask_close", "quote_age_ms"):
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    quote_time = pd.to_datetime(frame["quote_time_utc"], utc=True, errors="coerce")
    available_after = pd.to_datetime(frame["quote_available_after_utc"], utc=True, errors="coerce")
    close_time = frame["time"] + pd.Timedelta(minutes=5)
    age = (close_time - quote_time).dt.total_seconds() * 1000
    valid = (
        frame["time"].lt(pd.Timestamp("2026-01-01", tz="UTC"))
        & quote_time.ge(frame["time"]) & quote_time.lt(close_time)
        & available_after.eq(close_time) & age.gt(0) & age.le(60000)
        & age.sub(frame["quote_age_ms"]).abs().le(0.001)
        & frame["bid_close"].gt(0) & frame["bid_close"].lt(float("inf"))
        & frame["ask_close"].ge(frame["bid_close"]) & frame["ask_close"].lt(float("inf"))
        & frame["bid_close"].sub(frame["close"]).abs().le(0.000001)
        & frame["spread"].sub(frame["ask_close"] - frame["bid_close"]).abs().le(0.000001)
    )
    if (available & ~valid).any():
        raise ValueError("HISTORICAL_QUOTE_ALIGNMENT_INVALID")
    frame["spread_available"] = available
    frame.loc[~available, "spread"] = float("nan")
    count = int(available.sum())
    frame.attrs["quote_spread_quality"] = {
        "protocol": "historical_quote_spread_quality_v1",
        "status": "observed" if count == len(frame) and count > 0 else ("partial" if count else "unavailable"),
        "available_rows": count, "rows": len(frame), "coverage": count / len(frame) if len(frame) else 0,
        "provider_observed": count > 0, "source": "synchronized_bid_ask_tick",
        "maximum_quote_age_ms": 60000, "execution_cost_model_changed": False,
        "promotion_evidence": False,
    }
    return frame
