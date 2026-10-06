"""Validate synchronized close-time quote observations in a prospectively sealed M5 stream."""

import pandas as pd
from dataclasses import dataclass


_ORIGINAL_QUOTE_ISSUER = object()
_ORIGINAL_QUOTE_ATTR = '_authorized_original_quote_calendar'


@dataclass(frozen=True)
class _OriginalFullQuoteCalendar:
    """Private, immutable admission propagated from the signed full owner."""
    issuer: object
    dataset_hash: str
    plan_hash: str
    window_key: str
    sources: tuple

    def __post_init__(self):
        if self.issuer is not _ORIGINAL_QUOTE_ISSUER:
            raise TypeError('ORIGINAL_QUOTE_CALENDAR_ISSUER_REQUIRED')

    def __deepcopy__(self, memo):
        memo[id(self)] = self
        return self

    def calendar(self, frame, payload):
        from app.services import backtester as kernel
        stream = str(payload.timeframe).upper()
        times = pd.to_datetime(frame['time'], utc=True, errors='coerce')
        record = next((item for item in self.sources if item[0] == stream), None)
        attestation = kernel._consumed_dataset_attestation(payload, frame)
        if (record is None or self.dataset_hash != payload.replay_dataset_hash
                or attestation.get('status') != 'verified' or attestation.get('stream') != stream
                or attestation.get('actual_source_sha256') != record[2]
                or attestation.get('consumed_rows') != record[3]
                or pd.Timestamp(frame['time'].iloc[0]) != pd.Timestamp(record[4])
                or pd.Timestamp(frame['time'].iloc[-1]) != pd.Timestamp(record[5])
                or (payload.policy_context or {}).get('specialist_council_authorized_arm', {}).get('plan_hash') != self.plan_hash):
            raise ValueError('HISTORICAL_QUOTE_ORIGINAL_SOURCE_SCOPE_MISMATCH')
        start, last = pd.Timestamp(record[4]), pd.Timestamp(record[5])
        if start < pd.Timestamp('2027-01-01', tz='UTC'):
            raise ValueError('HISTORICAL_QUOTE_ORIGINAL_PAPER_SCOPE_FORBIDDEN')
        return times.ge(start) & times.le(last)


def bind_original_full_quote_calendar(frame, payload, identity):
    """Called only after original transport authentication and full-scope check.

    All signed sources retain their own bounds/hash. A source-column witness
    is still required at consumption; JSON flags or caller attrs are no proof.
    """
    sources = tuple((stream, record['path'], record['sha256'], record['rows'],
                     record['start_inclusive'], record['last_candle_at'])
                    for stream, record in sorted(identity['transport_files'].items()))
    proof = _OriginalFullQuoteCalendar(_ORIGINAL_QUOTE_ISSUER, payload.replay_dataset_hash,
        identity['plan_hash'], identity['window_key'], sources)
    copied = frame.copy()
    # Check the actual consumed columns against the original loader witness
    # before carrying this narrow calendar exception into any member kernel.
    proof.calendar(copied, payload)
    copied.attrs[_ORIGINAL_QUOTE_ATTR] = proof
    return copied


def original_full_mtf_bundle_current(frame, payload):
    """A new window bundle is admitted by the original owner, never its label."""
    proof = frame.attrs.get(_ORIGINAL_QUOTE_ATTR) if frame is not None else None
    if not isinstance(proof, _OriginalFullQuoteCalendar) or payload.evaluation_mode != 'full':
        return False
    proof.calendar(frame, payload)
    manifest = payload.mtf_snapshot_manifest or {}
    records = manifest.get('streams') or {}
    signed = {item[0]: item for item in proof.sources}
    from app.services import backtester as kernel
    return (manifest.get('bundle_hash') == proof.dataset_hash
            and all(stream in records and stream in signed
                and kernel._resolve_dataset_path(records[stream].get('path')).resolve()
                    == kernel._resolve_dataset_path(signed[stream][1]).resolve()
                and records[stream].get('sha256') == signed[stream][2]
                for stream in ('M5', 'M15', 'H1', 'H4')))


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
    calendar = frame['time'].lt(pd.Timestamp('2026-01-01', tz='UTC'))
    original = frame.attrs.get(_ORIGINAL_QUOTE_ATTR)
    if isinstance(original, _OriginalFullQuoteCalendar):
        calendar |= original.calendar(frame, payload)
    valid = (
        calendar
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
    if isinstance(original, _OriginalFullQuoteCalendar):
        frame.attrs['quote_spread_quality']['calendar_admission'] = {
            'protocol': 'authorized_original_council_quote_calendar_v1',
            'window_key': original.window_key, 'plan_hash': original.plan_hash,
            'stream': str(payload.timeframe).upper(), 'independent_evidence': False, 'promotion_evidence': False,
        }
    return frame
