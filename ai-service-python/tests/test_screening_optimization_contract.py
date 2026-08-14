from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

import pandas as pd

from app.main import _run_all_backtests_sync
from app.schemas import SimpleBacktestRequest
from app.services.backtester import (
    _load_simple_candles,
    prepare_feature_snapshot,
    prepare_signal_snapshot,
    run_simple_ema_rsi_backtest_on_dataframe,
    tail_feature_snapshot,
)
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.services.replay_parity import compare_stateful_replay


def _candles(count: int = 620) -> list[dict[str, object]]:
    start = datetime(2025, 1, 1, tzinfo=timezone.utc)
    rows = []
    for index in range(count):
        close = 100.0 + ((index % 37) * 0.08) + (index * 0.01)
        rows.append({
            "time": (start + timedelta(hours=index)).isoformat(),
            "open": close - 0.03,
            "high": close + 0.12,
            "low": close - 0.12,
            "close": close,
            "volume": 1000 + index,
            "volume_available": True,
        })
    return rows


def test_snapshot_path_tail_is_bounded_before_replay(tmp_path: Path):
    frame = pd.DataFrame(_candles(8))
    path = tmp_path / "snapshot.csv"
    frame.to_csv(path, index=False)

    payload = SimpleBacktestRequest(
        dataset_path=str(path),
        dataset_tail_rows=3,
    )

    loaded = _load_simple_candles(payload)

    assert len(loaded) == 3
    assert loaded.iloc[0]["time"] == frame.iloc[-3]["time"]


def test_bounded_cohort_builds_features_once_and_keeps_primary_trace():
    payload = SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="H1",
        evaluation_mode="incremental",
        candles=_candles(),
        strategies=[
            {"strategy": "ema_rsi_v1", "base_strategy": "ema_rsi_v1", "version": "a", "parameters": {}},
            {"strategy": "ema_rsi_v1", "base_strategy": "ema_rsi_v1", "version": "b", "parameters": {"ema_fast": 51}},
        ],
        emit_decision_trace=True,
    )

    with patch("app.main._load_immutable_replay_cache", return_value=None), \
         patch("app.main._store_immutable_replay_cache"), \
         patch("app.main.prepare_feature_snapshot", wraps=__import__("app.main", fromlist=["prepare_feature_snapshot"]).prepare_feature_snapshot) as feature_builder:
        result = _run_all_backtests_sync(payload)

    assert len(result["leaderboard"]) == 2
    assert result["resume_manifest"]["protocol"] == "replay_resume_cursor_v1"
    assert result["resume_manifest"]["pending_candidates"] == []
    assert len(result["resume_manifest"]["completed_candidates"]) == 2
    for item in result["leaderboard"]:
        optimization = item["result"]["optimization"]
        assert optimization["protocol"] == "shared_feature_snapshot_bounded_cohort_v1"
        assert optimization["feature_snapshot_builds"] == 1
        assert optimization["primary_trace"] is True
        assert optimization["opportunity_trace"] is False
    assert feature_builder.call_count == 1


def test_cost_profiles_disable_trace_but_replay_stateful_execution():
    candles = _candles()
    payload = SimpleBacktestRequest(candles=candles, emit_decision_trace=True)
    frame = pd.DataFrame(candles)
    features = prepare_feature_snapshot(payload, frame)
    signal = prepare_signal_snapshot(payload, feature_snapshot=features)
    normal = run_simple_ema_rsi_backtest_on_dataframe(
        payload,
        frame,
        lightweight=True,
    ).model_dump()
    emitted: list[bool] = []

    from app.services import market_adaptive_replay as replay_module

    original = replay_module._run_prepared_simple_backtest

    def observed(*args, **kwargs):
        emitted.append(bool(args[0].emit_decision_trace))
        return original(*args, **kwargs)

    with patch.object(replay_module, "_run_prepared_simple_backtest", side_effect=observed):
        cost = MarketAdaptiveReplayService._cost_profile_attribution(
            payload,
            frame,
            normal,
            prepared_snapshot=signal,
            feature_snapshot=features,
        )

    assert emitted and all(value is False for value in emitted)
    assert cost["optimization"]["decision_trace_emitted"] is False
    assert cost["optimization"]["stateful_execution_replayed"] is True
    assert cost["optimization"]["strategy_signal_recomputed"] is False


def test_warmup_super_snapshot_slices_2k_view_without_rebuilding_features():
    candles = _candles(5200)
    payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="H1", candles=candles)
    frame = pd.DataFrame(candles)

    prepared = prepare_feature_snapshot(payload, frame)
    view = tail_feature_snapshot(prepared, 2000)

    assert len(prepared.frame) == 5200
    assert len(view.frame) == 2000
    assert view.frame.iloc[0]["time"] == prepared.frame.iloc[-2000]["time"]
    assert view.frame.iloc[0]["_management_atr"] == prepared.frame.iloc[-2000]["_management_atr"]
    assert view.frame.attrs["warmup_source_rows"] == 5200


def test_fast_stateful_subreplay_matches_reference_ledger_and_gates():
    candles = _candles(720)
    payload = SimpleBacktestRequest(
        symbol="XAUUSD",
        timeframe="H1",
        candles=candles,
        emit_decision_trace=False,
    )
    frame = pd.DataFrame(candles)
    features = prepare_feature_snapshot(payload, frame)
    signal = prepare_signal_snapshot(payload, feature_snapshot=features)

    parity = compare_stateful_replay(
        payload,
        frame,
        prepared_snapshot=signal,
        lightweight=True,
    )

    assert parity["passed"], parity["mismatches"]
