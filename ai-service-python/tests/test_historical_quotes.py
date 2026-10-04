import pandas as pd
import pytest

from app.schemas import SimpleBacktestRequest


def _payload():
    return SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5", strategy="trend_v1", parameters={},
        mtf_pilot={"enabled": True, "activation_status": "execution_stream_bound"},
        mtf_snapshot_manifest={"quote_spread_provenance": {
            "protocol": "historical_quote_spread_snapshot_v1",
            "provider": "dukascopy_historical_synchronized_tick_v1", "maximum_quote_age_ms": 60000,
            "paper_2026_included": False, "promotion_evidence": False,
            "sources": [{"identity_hash": "a" * 64}], "source_m5_csv_sha256": "b" * 64,
        }})


def _frame():
    return pd.DataFrame([{
        "time": pd.Timestamp("2025-12-22T13:05:00Z"), "close": 100.0,
        "spread_available": 1, "spread": 0.5, "bid_close": 100.0, "ask_close": 100.5,
        "quote_time_utc": "2025-12-22T13:09:59.000Z",
        "quote_available_after_utc": "2025-12-22T13:10:00.000Z", "quote_age_ms": 1000,
    }])


def test_synchronized_quote_is_observed_but_never_promotion_evidence():
    from app.services.historical_quotes import validate_historical_quotes
    from app.services.backtester import _spread_quality

    payload = _payload()
    frame = validate_historical_quotes(_frame(), payload)
    assert frame.loc[0, "spread"] == 0.5
    quality = _spread_quality(frame, payload)
    assert quality["provider_observed"] is True
    assert quality["available_rows"] == 1
    assert quality["execution_cost_model_changed"] is False
    assert quality["promotion_evidence"] is False


def test_quality_counts_the_consumed_slice_not_its_parent_snapshot():
    from app.services.historical_quotes import validate_historical_quotes
    from app.services.backtester import _spread_quality

    payload = _payload()
    parent = pd.concat([_frame(), _frame().assign(spread_available=0)], ignore_index=True)
    frame = validate_historical_quotes(parent, payload)
    assert frame.attrs["quote_spread_quality"]["available_rows"] == 1
    # DataFrame attrs survive slicing; a stale aggregate must not claim that
    # a fold with only unavailable observations consumed a measured quote.
    quality = _spread_quality(frame.iloc[1:].copy(), payload)
    assert quality["available_rows"] == 0
    assert quality["rows"] == 1
    assert quality["coverage"] == 0
    assert quality["provider_observed"] is False
    assert quality["status"] == "unavailable"


@pytest.mark.parametrize("closed_mtf", [False, True], ids=["h1-asof", "closed-h4-h1-m15"])
def test_quote_attestation_survives_real_feature_joins_and_signal_tail(monkeypatch, closed_mtf):
    from app.services import backtester

    def candles(start, periods, frequency):
        prices = pd.Series([100.0 + i * 0.02 + (i % 7) * 0.1 for i in range(periods)])
        return pd.DataFrame({
            "time": pd.date_range(start, periods=periods, freq=frequency),
            "open": prices, "high": prices + 1, "low": prices - 1,
            "close": prices + 0.1, "volume": 1.0,
        })

    payload = _payload()
    # In-memory feature input is not a queue-admitted autonomous snapshot.
    # Bundle-file admission has separate coverage; do not mock that guard.
    payload.mtf_pilot = {}
    source = candles("2025-12-22T13:05:00Z", 32, "5min")
    source["volume_available"] = False
    source["spread_available"] = 1
    source["bid_close"] = source["close"]
    source["ask_close"] = source["close"] + 0.5
    source["spread"] = 0.5
    source["quote_time_utc"] = source["time"] + pd.Timedelta(minutes=4, seconds=59)
    source["quote_available_after_utc"] = source["time"] + pd.Timedelta(minutes=5)
    source["quote_age_ms"] = 1000
    source.loc[31, "spread_available"] = 0
    higher = candles("2025-12-17T00:00:00Z", 140, "h")
    streams = {
        "H4": candles("2025-12-01T00:00:00Z", 140, "4h"),
        "H1": higher,
        "M15": candles("2025-12-17T00:00:00Z", 560, "15min"),
    }
    # Replace only data transport; execute the actual H1 and closed-MTF joins.
    monkeypatch.setattr(backtester, "_load_regime_source", lambda _: higher)
    monkeypatch.setattr(backtester, "_load_mtf_streams", lambda _: streams if closed_mtf else {})
    features = backtester.prepare_feature_snapshot(payload, source)
    quality = backtester._spread_quality(features.frame, payload)
    assert quality["available_rows"] == 31
    assert quality["rows"] == 32
    assert quality["coverage"] == 31 / 32
    assert quality["status"] == "partial"
    assert quality["promotion_evidence"] is False
    assert quality["execution_cost_model_changed"] is False

    # The quote receipt must also survive shared-feature tail and real strategy
    # preparation; the consumed tail must not inherit the parent's 31/32 count.
    tail = backtester.tail_feature_snapshot(features, 16)
    signals = backtester.prepare_signal_snapshot(payload, feature_snapshot=tail)
    tail_quality = backtester._spread_quality(signals.frame, payload)
    assert tail_quality["available_rows"] == 15
    assert tail_quality["rows"] == 16
    assert tail_quality["coverage"] == 15 / 16
    assert tail_quality["status"] == "partial"
    assert tail_quality["promotion_evidence"] is False

    response = backtester._run_prepared_simple_backtest(
        payload, tail.source_frame, prepared_snapshot=signals,
        include_differential_pair=False, lightweight=True,
    ).model_dump()
    returned_quality = response["data_quality"]["spread_quality"]
    assert returned_quality["available_rows"] == 15
    assert returned_quality["rows"] == 16
    assert returned_quality["status"] == "partial"
    assert returned_quality["promotion_evidence"] is False


def test_frozen_csv_with_sparse_quote_times_loads_without_chunk_dtype_warning(tmp_path):
    import warnings
    from app.services.backtester import _load_simple_candles

    path = tmp_path / "sparse-quotes.csv"
    headers = ["time", "open", "high", "low", "close", "volume", "volume_available",
               "spread_available", "spread", "bid_close", "ask_close", "quote_time_utc", "quote_available_after_utc", "quote_age_ms"]
    empty = "2025-12-22 13:05:00,100,101,99,100,1,1,0,,,,,,\n"
    observed = "2025-12-22 13:05:00,100,101,99,100,1,1,1,0.5,100,100.5,2025-12-22T13:09:59.000Z,2025-12-22T13:10:00.000Z,1000\n"
    # Force more than one parser chunk, with nullable text only at the tail.
    path.write_text(",".join(headers) + "\n" + empty * 160000 + observed, encoding="utf-8")
    payload = SimpleBacktestRequest(dataset_path=str(path), dataset_tail_rows=2, timeframe="M5")
    with warnings.catch_warnings():
        warnings.simplefilter("error", pd.errors.DtypeWarning)
        frame = _load_simple_candles(payload)
    assert len(frame) == 2
    assert frame.iloc[-1]["quote_time_utc"] == "2025-12-22T13:09:59.000Z"
    assert pd.isna(frame.iloc[0]["quote_time_utc"])


def test_unavailable_quote_cannot_be_liquid_even_if_placeholder_is_zero():
    from app.services.historical_quotes import validate_historical_quotes
    from app.services.market_sessions import apply_specialist_scope

    frame = _frame().assign(spread_available=0, spread=0.0, atr=2.0, signal="BUY")
    validated = validate_historical_quotes(frame, _payload())
    assert pd.isna(validated.loc[0, "spread"])
    scoped = apply_specialist_scope(validated, {"spread_liquidity_state": "liquid"})
    assert scoped.loc[0, "signal"] == "WAIT"
    assert scoped.loc[0, "specialist_scope_first_veto"] == "liquidity_observation_missing"


@pytest.mark.parametrize("updates", [
    {"bid_close": 100.1}, {"ask_close": 99.0}, {"spread": 0.1},
    {"quote_time_utc": "2025-12-22T13:10:00Z", "quote_age_ms": 0},
    {"quote_time_utc": "2025-12-22T13:08:00Z", "quote_age_ms": 120000},
    {"quote_age_ms": 1}, {"quote_available_after_utc": "2025-12-22T13:15:00Z"},
])
def test_quote_tampering_and_future_or_stale_observation_fail_closed(updates):
    from app.services.historical_quotes import validate_historical_quotes

    with pytest.raises(ValueError, match="HISTORICAL_QUOTE_ALIGNMENT_INVALID"):
        validate_historical_quotes(_frame().assign(**updates), _payload())


def test_autonomous_quote_requires_sealed_provenance_and_paper_boundary():
    from app.services.historical_quotes import validate_historical_quotes

    payload = _payload().model_copy(update={"mtf_snapshot_manifest": {}})
    with pytest.raises(ValueError, match="HISTORICAL_QUOTE_PROVENANCE_INVALID"):
        validate_historical_quotes(_frame(), payload)
    payload = _payload()
    payload.mtf_snapshot_manifest["quote_spread_provenance"]["paper_2026_included"] = True
    with pytest.raises(ValueError, match="HISTORICAL_QUOTE_PROVENANCE_INVALID"):
        validate_historical_quotes(_frame(), payload)
