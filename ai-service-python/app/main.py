import hmac
import os
import math
import hashlib
import gzip
import json
import multiprocessing as multiprocessing
import subprocess
import sys
import tempfile
import time
from threading import BoundedSemaphore, Lock
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
import pandas as pd

from app.routers.backtests import router as backtests_router
from app.routers.holdouts import router as holdouts_router
from app.schemas import Candle, SimpleBacktestRequest, SimpleBacktestResponse
from app.services.backtester import (
    _advance_trailing_stop, _apply_execution_regime, _apply_portfolio_strategy, _apply_signal_delay, _entry_price, _exit_distances, _exit_price, _intrabar_exit, _load_regime_source,
    _load_simple_candles, _position_size_multiple, _resolve_dataset_path, _volatility_risk_multiplier, _volume_risk_multiplier,
    PreparedFeatureSnapshot, PreparedSignalSnapshot, _run_prepared_simple_backtest,
    core_replay_gate,
    prepare_feature_snapshot, prepare_signal_snapshot, tail_feature_snapshot,
    run_simple_ema_rsi_backtest, run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.parameter_schema import validate_strategy_parameters
from app.services.walk_forward import WalkForwardService
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.services.execution_contract import enforce_policy_boundary, execution_contract_metadata
from app.services.multitimeframe import apply_signal_policy, counterfactuals
from app.services.statistical_validation import (
    deflated_sharpe_ratio,
    per_trade_sharpe,
    purged_cscv_probability_of_backtest_overfitting,
    returns_from_equity_curve,
)
from app.strategies.registry import get_strategy, list_strategy_agents, list_strategies
from app.services.market_regime import apply_market_regime
from app.services.foundation_prior import evaluate_foundation_prior
from app.services.volume_features import add_volume_features, apply_volume_policy
from app.services.fitness import (
    build_fitness_breakdown,
    calculate_final_walk_forward_score,
    calculate_strategy_score,
)

app = FastAPI(
    title="NeuroTrader Lab AI Service",
    description="Strategy and backtest service for the NeuroTrader Lab MVP.",
    version="0.1.0",
)

_replay_state_lock = Lock()
_replay_lane_lock = Lock()
_shadow_micro_probe_lock = Lock()
# Screening is still bounded, but two independent child-process slots can
# consume a shared immutable snapshot concurrently. Full validation remains a
# single coordinator lane. The limit is deliberately environment-controlled
# so a small machine can set it back to one without changing evidence logic.
_screen_replay_concurrency = max(1, int(os.getenv("AI_SCREEN_REPLAY_CONCURRENCY", "2")))
_screen_replay_slots = BoundedSemaphore(_screen_replay_concurrency)
_active_replay_count = 0
_active_screen_replay_count = 0
_active_full_replay_count = 0
_last_replay_started_at: str | None = None
_last_replay_finished_at: str | None = None
_last_replay_termination: str | None = None
_last_replay_stage_timings: dict[str, float] = {}
_last_replay_checkpoint: dict[str, object] = {}


def _replay_checkpoint_root() -> Path:
    return Path(os.getenv(
        "AI_REPLAY_CHECKPOINT_DIR",
        str(Path(__file__).resolve().parent.parent / ".runtime" / "replay-checkpoints"),
    ))


def _write_replay_checkpoint(
    checkpoint_key: str,
    stage: str,
    resume_manifest: dict[str, object],
    **details: object,
) -> None:
    """Write a tiny atomic heartbeat, never the full trace or trade ledger.

    Candidate replay caches already make completed candidates resumable. This
    manifest adds durable stage visibility and lets a killed worker restart
    from the last completed candidate boundary without pretending that a
    partial execution ledger is promotion evidence.
    """
    global _last_replay_checkpoint
    completed = list(resume_manifest.get("completed_candidates", []))
    pending = list(resume_manifest.get("pending_candidates", []))
    payload = {
        "protocol": "replay_checkpoint_v1",
        "checkpoint_key": checkpoint_key,
        "stage": stage,
        "completed_candidates": completed[-32:],
        "pending_candidates": pending[-32:],
        "completed_count": len(completed),
        "pending_count": len(pending),
        "progress": round(len(completed) / max(1, len(completed) + len(pending)), 6),
        "details": details,
        "state_hash": hashlib.sha256(json.dumps({
            "checkpoint_key": checkpoint_key,
            "stage": stage,
            "completed_candidates": completed,
            "pending_candidates": pending,
            "details": details,
        }, sort_keys=True, separators=(",", ":"), default=str).encode("utf-8")).hexdigest(),
        "heartbeat_at": pd.Timestamp.now(tz="UTC").isoformat(),
        "promotion_evidence": False,
    }
    _last_replay_checkpoint = payload
    try:
        root = _replay_checkpoint_root()
        root.mkdir(parents=True, exist_ok=True)
        path = root / f"{checkpoint_key}.json"
        temporary = path.with_suffix(".tmp")
        temporary.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":"), default=str), encoding="utf-8")
        temporary.replace(path)
    except OSError:
        # Heartbeat loss is operational telemetry only. It must never turn a
        # deterministic replay into a different strategy verdict.
        return


def _latest_replay_checkpoint() -> dict[str, object]:
    try:
        paths = sorted(_replay_checkpoint_root().glob("*.json"), key=lambda item: item.stat().st_mtime_ns, reverse=True)
    except OSError:
        return dict(_last_replay_checkpoint)
    for path in paths[:8]:
        try:
            value = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(value, dict) and value.get("protocol") == "replay_checkpoint_v1":
                return value
        except (OSError, json.JSONDecodeError):
            continue
    return dict(_last_replay_checkpoint)


def _utc_timestamp(value: object) -> pd.Timestamp:
    """Return one timezone-aware UTC timestamp for API/data comparisons."""
    timestamp = pd.Timestamp(value)
    return timestamp.tz_localize("UTC") if timestamp.tzinfo is None else timestamp.tz_convert("UTC")


def _load_foundation_candles(payload: SimpleBacktestRequest) -> pd.DataFrame | None:
    """Load the separately sealed foundation archive for full evidence."""
    if not payload.foundation_dataset_path:
        return None
    return pd.read_csv(_resolve_dataset_path(payload.foundation_dataset_path))


def _assert_historical_evolution_screen_source(
    payload: SimpleBacktestRequest,
    dataframe: pd.DataFrame,
) -> None:
    """Fail closed if a historical evolution screen contains paper candles.

    Laravel seals this split in the immutable request manifest, but the
    evaluator must enforce it at the data boundary as well.  The contract is
    intentionally opt-in so older, sealed evidence remains replayable under
    its original protocol.
    """
    transport = (payload.policy_context or {}).get("snapshot_transport", {})
    if (
        payload.evaluation_mode != "incremental"
        or not isinstance(transport, dict)
        or transport.get("protocol") != "historical_evolution_paper_forward_split_v1"
    ):
        return
    if dataframe.empty or "time" not in dataframe.columns:
        raise ValueError("Historical evolution screening uchun candle vaqti topilmadi.")

    cutoff = _utc_timestamp(transport.get("training_end_exclusive", "2026-01-01T00:00:00Z"))
    timestamps = pd.to_datetime(dataframe["time"], utc=True, errors="coerce")
    if timestamps.isna().any() or (timestamps >= cutoff).any():
        raise ValueError(
            "Historical evolution screening pre-2026 foundation bilan cheklangan; "
            "2026 paper candle screening yoki mutation uchun ishlatilmaydi."
        )


def _assert_non_paper_source_pre_2026(
    payload: SimpleBacktestRequest,
    dataframe: pd.DataFrame,
) -> None:
    """Fail closed for every research/evolution data boundary.

    The paper API is the only consumer allowed to receive 2026 candles. A
    caller cannot bypass this by omitting Laravel's policy metadata: research
    data itself is inspected before any strategy features are built.
    """
    if payload.evaluation_mode not in {"incremental", "full", "replay", "temporal_ablation"}:
        return
    if dataframe.empty or "time" not in dataframe.columns:
        raise ValueError("Research dataset candle vaqti topilmadi.")
    cutoff = _utc_timestamp(
        ((payload.policy_context or {}).get("data_boundary", {}) or {}).get(
            "training_end_exclusive", "2026-01-01T00:00:00Z"
        )
    )
    timestamps = pd.to_datetime(dataframe["time"], utc=True, errors="coerce")
    if timestamps.isna().any() or (timestamps >= cutoff).any():
        raise ValueError(
            "Research/training/screening/replay dataset 2026-01-01 dan keyingi candle saqlamasligi kerak; "
            "2026 faqat paper lane uchun."
        )


def _candidate_cache_payload(
    cohort_payload: SimpleBacktestRequest,
    strategy_payload: SimpleBacktestRequest,
    candidate_label: str,
) -> SimpleBacktestRequest:
    """Build the cohort-independent identity for one candidate replay.

    The full request carries all repair contracts so the cohort can be
    audited.  A per-candidate cache must not change identity merely because a
    sibling timed out or was already resolved, so retain only this strategy's
    contract while preserving every other policy input.
    """
    candidate_policy = dict(cohort_payload.policy_context or {})
    repair_contracts = candidate_policy.get("repair_contracts")
    if isinstance(repair_contracts, dict):
        candidate_contract = repair_contracts.get(candidate_label)
        candidate_policy["repair_contracts"] = (
            {candidate_label: candidate_contract}
            if isinstance(candidate_contract, dict)
            else {}
        )
    # Historical trial counts only affect the statistical envelope rebuilt
    # after all candidate results are collected. They are not an execution
    # input, so including them here would fragment the candidate cache on
    # every new experiment and duplicate multi-megabyte replay artifacts.
    candidate_policy.pop("trial_ledger", None)
    # This is a Laravel scheduling/runtime budget envelope, not a strategy
    # execution input. Cohort size changes during bounded recovery must not
    # invalidate an already completed candidate's deterministic replay cache.
    candidate_policy.pop("full_replay_runtime_policy", None)
    return strategy_payload.model_copy(update={
        "policy_context": candidate_policy,
        "strategies": [],
    })


def _candidate_cache_contract_is_current(
    cached_item: object,
    payload: SimpleBacktestRequest,
) -> bool:
    """Reject legacy candidate caches before they can become full evidence.

    A cache may be structurally valid while carrying a pre-contract result or
    a hash produced by a different language's float serializer.  Laravel
    validates the returned contract at the evidence boundary, but recomputing
    here avoids spending a long cohort replay only to quarantine it after the
    ledger has already been calculated.
    """
    if not isinstance(cached_item, dict):
        return False
    result = cached_item.get("result")
    if not isinstance(result, dict):
        return False
    received = result.get("execution_contract")
    if not isinstance(received, dict):
        return False
    expected = execution_contract_metadata(payload)
    return (
        received.get("execution_hash") == expected.get("execution_hash")
        and received.get("parameters") == expected.get("parameters")
    )


_last_replay_cache_cleanup = 0.0
_replay_cache_cleanup_lock = Lock()
_runtime_manifest_cache_signature: tuple[tuple[str, int, int], ...] | None = None
_runtime_manifest_cache: dict[str, object] | None = None
_dataset_manifest_cache: dict[tuple[tuple[str, str, int, int, int, int], ...], dict[str, object]] = {}


@app.middleware("http")
async def require_internal_token(request: Request, call_next):
    if request.url.path.startswith("/api/"):
        token = _internal_api_token()
        if not token:
            return JSONResponse(status_code=503, content={"detail": "Internal API token is not configured."})
        supplied = request.headers.get("x-internal-token", "")
        if not hmac.compare_digest(token, supplied):
            return JSONResponse(status_code=401, content={"detail": "Invalid internal API token."})
    is_replay = request.url.path in {"/api/backtest/run-all", "/api/portfolio/backtest"}
    if is_replay:
        global _active_replay_count, _last_replay_started_at
        with _replay_state_lock:
            _active_replay_count += 1
            _last_replay_started_at = pd.Timestamp.now(tz="UTC").isoformat()
    try:
        return await call_next(request)
    finally:
        if is_replay:
            with _replay_state_lock:
                _active_replay_count = max(0, _active_replay_count - 1)


def _internal_api_token() -> str:
    token_file = os.getenv("INTERNAL_API_TOKEN_FILE", "").strip()
    if token_file:
        try:
            token = Path(token_file).read_text(encoding="utf-8").strip()
            if token:
                return token
        except OSError:
            pass
    return os.getenv("INTERNAL_API_TOKEN", "").strip()


@app.get("/health")
def health() -> dict[str, object]:
    with _replay_state_lock:
        replay_liveness = {
            "protocol": "replay_liveness_v2_health_projection",
            "service_pid": os.getpid(),
            "active_requests": _active_replay_count,
            "screening_active": _active_screen_replay_count,
            "screening_capacity": _screen_replay_concurrency,
            "full_active": _active_full_replay_count,
            "last_replay_started_at": _last_replay_started_at,
            "last_replay_finished_at": _last_replay_finished_at,
            "last_replay_termination": _last_replay_termination,
            "last_replay_stage_timings_ms": dict(_last_replay_stage_timings),
            "last_replay_checkpoint": _latest_replay_checkpoint(),
        }
    return {
        "status": "ok",
        "service": "neurotrader-ai-service",
        "replay_liveness": replay_liveness,
    }


@app.get("/")
def home() -> dict[str, str]:
    return {
        "service": "NeuroTrader AI Service",
        "status": "running",
    }


@app.get("/api/strategies")
def strategies() -> dict[str, object]:
    return {
        "strategies": list_strategies(),
        "agents": list_strategy_agents(),
    }


@app.get("/api/replay-status")
def replay_status() -> dict[str, object]:
    """Expose bounded screening slots and the single full lane to recovery."""
    with _replay_state_lock:
        return {
            "active_requests": _active_replay_count,
            "screening_active": _active_screen_replay_count,
            "screening_capacity": _screen_replay_concurrency,
            "full_active": _active_full_replay_count,
            "last_replay_started_at": _last_replay_started_at,
            "last_replay_finished_at": _last_replay_finished_at,
            "last_replay_termination": _last_replay_termination,
            "last_replay_stage_timings_ms": dict(_last_replay_stage_timings),
            "last_replay_checkpoint": _latest_replay_checkpoint(),
            "service_pid": os.getpid(),
            # Keep the v2 protocol name for existing watchdogs; the additive
            # lane counters below are backwards-compatible observability.
            "protocol": "replay_liveness_v2_bounded_worker",
        }


@app.post("/api/backtest/run", response_model=SimpleBacktestResponse)
def run_simple_backtest_api(payload: SimpleBacktestRequest) -> SimpleBacktestResponse:
    try:
        payload = payload.model_copy(update={
            "parameters": validate_strategy_parameters(
                payload.strategy, payload.parameters, payload.base_strategy
            )
        })
        return run_simple_ema_rsi_backtest(payload)
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/backtest/run-all")
def run_all_backtests(payload: SimpleBacktestRequest) -> dict[str, object]:
    """Run a replay in a killable child process with a hard wall-clock bound.

    The strategy engine is intentionally kept in ``_run_all_backtests_sync``.
    A synchronous Python thread cannot be safely interrupted after Laravel's
    cURL timeout, which previously allowed one pathological replay to hold the
    evaluator lane for many hours.  A child process gives the service a real
    containment boundary: a timed-out replay is terminated and can never
    continue mutating the shared AI process or starve later candidates.
    """
    return _run_bounded_replay("run_all", payload)


def _screening_robustness_admission(result: dict[str, object]) -> dict[str, object]:
    """Decide whether expensive screening robustness work can add signal.

    Screening is a routing tier. A candidate that cannot satisfy the existing
    minimum full-replay admission preconditions (sample count and positive
    core PF) cannot reach full validation, so cost-profile and parameter
    perturbation sub-replays would only repeat a fail-closed rejection. Keep
    this gate deliberately conservative: candidates that may enter either the
    standalone or complementary-agent research lane still receive the full
    robustness profile.
    """
    try:
        minimum_trades = max(1, int(os.getenv("AI_SCREENING_ROBUSTNESS_MIN_TRADES", "10")))
    except ValueError:
        minimum_trades = 10
    trades = int(result.get("total_trades", result.get("sample_count", 0)) or 0)
    profit_factor = float(result.get("profit_factor", 0) or 0)
    reasons: list[str] = []
    if trades < minimum_trades:
        reasons.append("FAILED_TRADE_COUNT")
    if profit_factor <= 0:
        reasons.append("FAILED_PROFIT_FACTOR")
    return {
        "passed": reasons == [],
        "minimum_trades": minimum_trades,
        "total_trades": trades,
        "profit_factor": profit_factor,
        "reason_codes": reasons,
    }


def _screening_insufficient_robustness_profile(
    result: dict[str, object], admission: dict[str, object]
) -> dict[str, object]:
    """Return an explicit fail-closed projection without sub-replay work."""
    reasons = list(admission.get("reason_codes", []))
    reasons.append("SCREENING_ROBUSTNESS_DEFERRED_AFTER_CORE_FAILURE")
    return {
        "protocol": "screening_survival_v2_cascaded",
        "status": "insufficient_evidence",
        "reason_codes": list(dict.fromkeys(reasons)),
        "sample_count": int(result.get("total_trades", 0) or 0),
        "core_admission": admission,
        "skipped_sub_replays": [
            "zero_cost_profile",
            "stress_cost_profile",
            "parameter_perturbation_minus",
            "parameter_perturbation_plus",
        ],
        "promotion_evidence": False,
        "rule": (
            "Screening robustness runs only after the existing minimum full-replay "
            "admission preconditions pass; a deferred robustness profile is never "
            "eligible for full, forward, paper, or promotion evidence."
        ),
    }


def _run_all_backtests_sync(payload: SimpleBacktestRequest) -> dict[str, object]:
    timing_started = time.perf_counter()
    stage_timings: dict[str, float] = {}

    def add_timing(stage: str, started: float) -> None:
        stage_timings[stage] = round(stage_timings.get(stage, 0.0) + ((time.perf_counter() - started) * 1000), 3)

    def run_timed(candidate_payload: SimpleBacktestRequest, frame: pd.DataFrame, **kwargs):
        started = time.perf_counter()
        try:
            return _run_prepared_simple_backtest(candidate_payload, frame, **kwargs)
        finally:
            add_timing("stateful_replay_ms", started)

    leaderboard = []
    strategy_configs = payload.strategies or ([{
        "strategy": "portfolio_v1",
        "base_strategy": "portfolio",
        "version": payload.version or "portfolio-v1",
        "parameters": dict(payload.parameters or {}),
    }] if payload.portfolio_members else [
        {
            "strategy": strategy_name,
            "base_strategy": strategy_name,
            "version": "v1",
            "parameters": {},
        }
        for strategy_name in list_strategies()
    ])
    resume_candidates = []
    for config_index, raw_config in enumerate(strategy_configs):
        config_value = raw_config.model_dump() if hasattr(raw_config, "model_dump") else dict(raw_config)
        resume_candidates.append({
            "candidate_key": str(config_value.get("lab_agent_id") or f"{config_value.get('strategy', 'unknown')}:{config_value.get('version', 'v1')}:{config_index}"),
            "strategy": str(config_value.get("strategy", "unknown")),
            "version": str(config_value.get("version", "v1")),
            "parameters_hash": hashlib.sha256(json.dumps(
                config_value.get("parameters", {}), sort_keys=True, separators=(",", ":"), default=str
            ).encode("utf-8")).hexdigest(),
        })
    resume_manifest = {
        "protocol": "replay_resume_cursor_v1",
        "cohort_hash": hashlib.sha256(json.dumps({
            "symbol": payload.symbol,
            "timeframe": payload.timeframe,
            "candidates": resume_candidates,
            "dataset_path": payload.dataset_path,
            "dataset_tail_rows": payload.dataset_tail_rows,
        }, sort_keys=True, separators=(",", ":"), default=str).encode("utf-8")).hexdigest(),
        "completed_candidates": [],
        "pending_candidates": [item["candidate_key"] for item in resume_candidates],
        "failed_candidates": [],
        "snapshot_hash": None,
        "promotion_evidence": False,
    }
    checkpoint_key = resume_manifest["cohort_hash"]
    _write_replay_checkpoint(
        str(checkpoint_key),
        "started",
        resume_manifest,
        evaluation_mode=payload.evaluation_mode,
        candidate_count=len(resume_candidates),
    )
    snapshot_transport = (payload.policy_context or {}).get("snapshot_transport", {})
    if isinstance(snapshot_transport, dict):
        resume_manifest["snapshot_hash"] = snapshot_transport.get(
            "dataset_sha256", snapshot_transport.get("snapshot_sha256")
        )

    try:
        # Do not load the large sealed archives until a candidate-cache miss
        # proves that core execution is actually needed. A fully cached
        # cohort can rebuild its current statistical envelope without
        # allocating the replay dataset or foundation archive.
        source_df = None
        foundation_df = None
        walk_forward = WalkForwardService()

        # One generation request is a bounded cohort. Feature construction is
        # independent of the candidate's strategy parameters, so cache it by
        # immutable window/context and let each candidate own only its signal
        # and stateful execution snapshot.
        shared_feature_snapshots: dict[str, PreparedFeatureSnapshot] = {}
        shared_snapshot_builds = 0
        shared_snapshot_hits = 0

        def shared_features(kind: str, candidate_payload: SimpleBacktestRequest, frame: pd.DataFrame) -> PreparedFeatureSnapshot:
            nonlocal shared_snapshot_builds, shared_snapshot_hits
            identity = {
                "kind": kind,
                "dataset_path": candidate_payload.dataset_path,
                "dataset_tail_rows": candidate_payload.dataset_tail_rows,
                "regime_dataset_path": candidate_payload.regime_dataset_path,
                "regime_dataset_tail_rows": candidate_payload.regime_dataset_tail_rows,
                "volume_context": candidate_payload.volume_context,
                "from_date": str(candidate_payload.from_date) if candidate_payload.from_date else None,
                "to_date": str(candidate_payload.to_date) if candidate_payload.to_date else None,
                "rows": len(frame),
                "first_time": str(frame.iloc[0].get("time")) if len(frame) else None,
                "last_time": str(frame.iloc[-1].get("time")) if len(frame) else None,
            }
            key = hashlib.sha256(json.dumps(identity, sort_keys=True, default=str, separators=(",", ":")).encode("utf-8")).hexdigest()
            if key not in shared_feature_snapshots:
                started = time.perf_counter()
                shared_feature_snapshots[key] = prepare_feature_snapshot(candidate_payload, frame)
                add_timing("feature_snapshot_build_ms", started)
                shared_snapshot_builds += 1
            else:
                shared_snapshot_hits += 1
            return shared_feature_snapshots[key]

        for config_index, config in enumerate(strategy_configs):
            if hasattr(config, "model_dump"):
                config = config.model_dump()

            strategy_name = config["strategy"]
            candidate_label = str(config.get("lab_agent_id") or f"{strategy_name}:{config.get('version', 'v1')}:{config_index}")
            is_portfolio_config = bool(payload.portfolio_members) and (
                strategy_name == "portfolio_v1" or config.get("base_strategy") == "portfolio"
            )
            parameters = dict(config.get("parameters") or {}) if is_portfolio_config else validate_strategy_parameters(
                strategy_name,
                config.get("parameters") or {},
                config.get("base_strategy"),
            )
            candidate_policy = dict(payload.policy_context or {})
            repair_contracts = candidate_policy.get("repair_contracts")
            if isinstance(repair_contracts, dict):
                repair_contract = repair_contracts.get(candidate_label)
                if isinstance(repair_contract, dict):
                    # The batch contract is keyed by LabAgent id.  Collapse it
                    # to the exact candidate before perturbation so a sibling
                    # cannot select the wrong gene merely by sharing a strategy.
                    candidate_policy["repair_contract"] = repair_contract
            strategy_payload = payload.model_copy(update={
                "strategy": strategy_name,
                "base_strategy": config.get("base_strategy"),
                "version": config.get("version"),
                "parameters": parameters,
                "policy_context": candidate_policy,
                "strategies": [],
            })
            # A full request is a cohort so CSCV/DSR can be reconstructed from
            # the complete candidate distribution.  The expensive execution
            # for each candidate, however, is independent.  Persist that
            # candidate result after it completes so a timed-out cohort can be
            # resumed with only the unfinished strategy.  The cache key keeps
            # the candidate's own contract, code digest and dataset hashes;
            # the cohort-level statistical envelope is deliberately rebuilt
            # below after every candidate has been collected.
            candidate_payload = _candidate_cache_payload(payload, strategy_payload, candidate_label)
            candidate_cache_key = _replay_cache_key("candidate", candidate_payload)
            candidate_cache = _load_immutable_replay_cache(candidate_cache_key)
            cached_item = candidate_cache.get("item") if isinstance(candidate_cache, dict) else None
            if (
                isinstance(candidate_cache, dict)
                and candidate_cache.get("protocol") == "candidate_replay_cache_v1"
                and candidate_cache.get("strategy") == strategy_name
                and isinstance(cached_item, dict)
                and isinstance(cached_item.get("result"), dict)
                and _candidate_cache_contract_is_current(cached_item, candidate_payload)
            ):
                leaderboard.append(cached_item)
                # Cache-hit status is operational telemetry, not evidence.
                # Keep the resumable manifest deterministic so a retry cannot
                # change the canonical response hash merely because work was
                # restored from an immutable cache.
                resume_manifest["completed_candidates"].append(candidate_label)
                resume_manifest["pending_candidates"] = [
                    item for item in resume_manifest["pending_candidates"] if item != candidate_label
                ]
                continue
            if source_df is None:
                started = time.perf_counter()
                source_df = _load_simple_candles(payload)
                _assert_historical_evolution_screen_source(payload, source_df)
                _assert_non_paper_source_pre_2026(payload, source_df)
                foundation_df = _load_foundation_candles(payload) if payload.evaluation_mode == "replay" else None
                if foundation_df is not None:
                    _assert_non_paper_source_pre_2026(payload, foundation_df)
                add_timing("dataset_load_ms", started)
                _write_replay_checkpoint(
                    str(checkpoint_key),
                    "snapshot_loaded",
                    resume_manifest,
                    source_rows=len(source_df),
                    foundation_rows=len(foundation_df) if foundation_df is not None else 0,
                )
            if payload.evaluation_mode != "incremental":
                _write_replay_checkpoint(
                    str(checkpoint_key),
                    "features_ready",
                    resume_manifest,
                    candidate=candidate_label,
                    mode=payload.evaluation_mode,
                    rows=len(source_df),
                )
            if payload.evaluation_mode == "incremental":
                ordered = source_df.sort_values("time").reset_index(drop=True)
                # Tier 1 is deliberately cheap: it measures whether there is
                # a signal/opportunity claim at all. It never rejects a gene
                # for lacking regime, stress or monthly evidence.
                opportunity_df = ordered.tail(2000).reset_index(drop=True)
                # Differential causal lanes are expensive and belong to the
                # Tier-2 survival contract. Tier-1 only answers whether the
                # candidate has observable activity, so do not spend four
                # paired replays there as well. The full 5k survival replay
                # below keeps the paired ledger evidence intact.
                opportunity_payload = strategy_payload.model_copy(update={"emit_decision_trace": False})
                if len(ordered) >= 5000 and "differential_router" in str(
                    strategy_payload.base_strategy or strategy_payload.strategy
                ).lower():
                    opportunity_payload = strategy_payload.model_copy(update={
                        "parameters": {
                            **strategy_payload.parameters,
                            "differential_pair_replay_enabled": False,
                        },
                    })
                # Build the 5k survival feature layer once, then derive the
                # cheaper 2k opportunity view from it.  This preserves
                # indicator/ATR/H1 warmup while eliminating a second feature
                # construction for every candidate in a cohort.
                survival_df = ordered.tail(5000).reset_index(drop=True) if len(ordered) >= 5000 else None
                survival_features = (
                    shared_features("survival", strategy_payload, survival_df)
                    if survival_df is not None
                    else shared_features("opportunity", strategy_payload, opportunity_df)
                )
                opportunity_features = (
                    tail_feature_snapshot(survival_features, len(opportunity_df))
                    if survival_df is not None
                    else survival_features
                )
                _write_replay_checkpoint(
                    str(checkpoint_key),
                    "features_ready",
                    resume_manifest,
                    candidate=candidate_label,
                    feature_snapshot_builds=shared_snapshot_builds,
                    feature_snapshot_cache_hits=shared_snapshot_hits,
                    rows=len(survival_df) if survival_df is not None else len(opportunity_df),
                )
                opportunity_signal = prepare_signal_snapshot(
                    opportunity_payload,
                    feature_snapshot=opportunity_features,
                )
                opportunity_result = run_timed(
                    opportunity_payload,
                    opportunity_df,
                    prepared_snapshot=opportunity_signal,
                    include_differential_pair=False,
                    lightweight=True,
                ).model_dump()
                # Tier 2 is the only screen allowed to make a survival claim.
                # An archive shorter than 5k is an evidence gap, not failure.
                if survival_df is not None:
                    # Screening is a routing/falsification stage.  Keep the
                    # same core execution, costs and full-ledger attribution,
                    # but defer Monte Carlo/DNA/promotion-only diagnostics to
                    # full validation.  This cannot create promotion or
                    # paper evidence and prevents one pathological specialist
                    # from monopolizing the screening queue.
                    survival_signal = prepare_signal_snapshot(
                        strategy_payload,
                        feature_snapshot=survival_features,
                    )
                    incremental_result = run_timed(
                        strategy_payload,
                        survival_df,
                        prepared_snapshot=survival_signal,
                        lightweight=True,
                    ).model_dump()
                    admission = _screening_robustness_admission(incremental_result)
                    survival_started = time.perf_counter()
                    survival = (
                        MarketAdaptiveReplayService().screening_survival_profile(
                            strategy_payload,
                            survival_df,
                            incremental_result,
                            calculate_strategy_score,
                            feature_snapshot=survival_features,
                            signal_snapshot=survival_signal,
                        )
                        if admission["passed"]
                        else _screening_insufficient_robustness_profile(incremental_result, admission)
                    )
                    add_timing("screening_subreplay_ms", survival_started)
                    if "cost_profile" in survival:
                        incremental_result["pf_attribution"] = survival["cost_profile"]
                    _write_replay_checkpoint(
                        str(checkpoint_key),
                        "cost_lanes",
                        resume_manifest,
                        candidate=candidate_label,
                        stress_profile=bool("cost_profile" in survival),
                    )
                else:
                    incremental_result = opportunity_result
                    survival = {
                        "protocol": "screening_survival_v2", "status": "insufficient_evidence",
                        "required_candles": 5000, "available_candles": len(ordered), "reason_codes": ["INSUFFICIENT_SCREENING_EVIDENCE"],
                        "promotion_evidence": False,
                        "rule": "Short opportunity screening may route research, but cannot make a survival or harmful mutation claim.",
                    }
                stratified_evidence = MarketAdaptiveReplayService().stratified_historical_screening_evidence(
                    strategy_payload,
                    ordered,
                    calculate_strategy_score,
                )
                if stratified_evidence is not None:
                    survival["stratified_historical_windows"] = stratified_evidence
                    survival["reason_codes"] = list(dict.fromkeys([
                        *(survival.get("reason_codes", []) or []),
                        *(stratified_evidence.get("reason_codes", []) or []),
                    ]))
                    if survival["reason_codes"]:
                        survival["status"] = "rescue_case"
                incremental_result["screening_opportunity"] = {
                    "protocol": "screening_opportunity_v1", "candles": len(opportunity_df),
                    "total_trades": opportunity_result.get("total_trades", 0),
                    "entry_funnel": opportunity_result.get("entry_funnel", {}),
                    "opportunity_metrics": opportunity_result.get("opportunity_metrics", {}),
                    "promotion_evidence": False,
                }
                incremental_result["screening_survival"] = survival
                incremental_result["evaluation_mode"] = "incremental_two_tier"
                incremental_result["optimization"] = {
                    "protocol": "shared_feature_snapshot_bounded_cohort_v1",
                    "feature_snapshot_builds": shared_snapshot_builds,
                    "feature_snapshot_cache_hits": shared_snapshot_hits,
                    "warmup_super_snapshot": bool(survival_df is not None),
                    "warmup_snapshot_rows": len(survival_df) if survival_df is not None else len(opportunity_df),
                    "opportunity_view_rows": len(opportunity_df),
                    "signal_snapshot_per_candidate": True,
                    "primary_trace": bool(strategy_payload.emit_decision_trace),
                    "opportunity_trace": False,
                    "stateful_subreplay_executor": "record_view_v2",
                    "stateful_subreplay_parity": "stateful_subreplay_parity_v1",
                    "promotion_evidence": False,
                }
                incremental_score = calculate_strategy_score(incremental_result)
                analysis = {
                    "train_score": incremental_score, "validation_score": incremental_score,
                    "forward_score": incremental_score, "forward_window_scores": [],
                    "rolling_windows_count": 0, "robustness_score": 0, "is_overfit": False,
                    "result": {**incremental_result, "evaluation_mode": "incremental"},
                }
            elif payload.evaluation_mode == "temporal_ablation":
                # Clean temporal ablation windows are already sealed
                # chronological datasets. They must not be routed through the
                # normal 2026 rolling/foundation splitter, which would reject
                # a valid pre-2026 window for lacking a synthetic 2026 tail.
                # This lane is research-only and cannot create forward,
                # paper, parent or champion evidence.
                # Keep this research lane compact: temporal survival is
                # represented by the event digest, temporal metrics and
                # trade-ledger hash. A full candle trace for four 41k-candle
                # arms can exhaust the bounded subprocess before Laravel
                # receives the paired window response.
                ablation_payload = strategy_payload.model_copy(update={"emit_decision_trace": False})
                ablation_snapshot = prepare_signal_snapshot(ablation_payload, source_df)
                ablation_result = _run_prepared_simple_backtest(
                    ablation_payload,
                    source_df,
                    prepared_snapshot=ablation_snapshot,
                    include_differential_pair=False,
                    lightweight=True,
                ).model_dump()
                ablation_result["evaluation_mode"] = "temporal_ablation"
                ablation_result["temporal_ablation"] = {
                    "protocol": "temporal_clean_ablation_v2",
                    "window_id": ((strategy_payload.policy_context or {}).get("temporal_ablation") or {}).get("window_id"),
                    "promotion_evidence": False,
                    "forward_evidence": False,
                }
                ablation_score = calculate_strategy_score(ablation_result)
                analysis = {
                    "train_score": ablation_score,
                    "validation_score": ablation_score,
                    "forward_score": ablation_score,
                    "forward_window_scores": [],
                    "rolling_windows_count": 0,
                    "robustness_score": 0,
                    "is_overfit": False,
                    "result": ablation_result,
                }
            elif payload.evaluation_mode == "replay":
                analysis = MarketAdaptiveReplayService().run(
                    strategy_payload, source_df, calculate_strategy_score, foundation_df
                )
            else:
                analysis = walk_forward.run(strategy_payload, source_df, calculate_strategy_score)
            _write_replay_checkpoint(
                str(checkpoint_key),
                "signal_loop",
                resume_manifest,
                candidate=candidate_label,
                mode=payload.evaluation_mode,
            )
            _write_replay_checkpoint(
                str(checkpoint_key),
                "cost_lanes",
                resume_manifest,
                candidate=candidate_label,
                mode=payload.evaluation_mode,
            )
            result_data = analysis["result"]
            # Fitness is a ranking aid, not a promotion decision. Expose the
            # evidence components so Laravel can explain why a candidate was
            # preferred without collapsing everything into raw profit.
            fitness_score = calculate_strategy_score(result_data)
            result_data["fitness_score"] = fitness_score
            result_data["fitness_breakdown"] = build_fitness_breakdown(result_data, fitness_score)
            score = calculate_final_walk_forward_score(
                int(analysis["forward_score"]),
                int(analysis["robustness_score"]),
                bool(analysis["is_overfit"]),
                fitness_score,
            )

            candidate_item = {
                    "strategy": strategy_name,
                    "lab_agent_id": config.get("lab_agent_id"),
                    "base_strategy": config.get("base_strategy"),
                    "version": config.get("version"),
                    "parameters": parameters,
                    "score": score,
                    "train_score": analysis["train_score"],
                    "validation_score": analysis["validation_score"],
                    "forward_score": analysis["forward_score"],
                    "forward_window_scores": analysis["forward_window_scores"],
                    "rolling_windows_count": analysis["rolling_windows_count"],
                    "robustness_score": analysis["robustness_score"],
                    "is_overfit": analysis["is_overfit"],
                    "result": result_data,
            }
            leaderboard.append(candidate_item)
            resume_manifest["completed_candidates"].append(candidate_label)
            resume_manifest["pending_candidates"] = [
                item for item in resume_manifest["pending_candidates"] if item != candidate_label
            ]
            _store_immutable_replay_cache(candidate_cache_key, {
                "protocol": "candidate_replay_cache_v1",
                "strategy": strategy_name,
                "item": candidate_item,
            })
            _write_replay_checkpoint(
                str(checkpoint_key),
                "execution_state",
                resume_manifest,
                candidate=candidate_label,
                candidate_cache_key=candidate_cache_key,
            )
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    # The following diagnostics use replay checkpoints only.  The sealed
    # holdout remains untouched and is still run through its dedicated route.
    trial_sharpes = [
        sharpe for item in leaderboard
        if (sharpe := per_trade_sharpe(returns_from_equity_curve(item["result"].get("equity_curve", [])))) is not None
    ]
    prior_trials = (payload.policy_context or {}).get("trial_ledger", {})
    historical_sharpes = []
    if isinstance(prior_trials, dict):
        for value in prior_trials.get("trial_sharpes", []):
            try:
                normalized = float(value)
            except (TypeError, ValueError):
                continue
            if math.isfinite(normalized):
                historical_sharpes.append(normalized)
    trial_sharpes = historical_sharpes + trial_sharpes
    window_intervals = prior_trials.get("window_intervals", []) if isinstance(prior_trials, dict) else []
    cscv = purged_cscv_probability_of_backtest_overfitting([
        item["forward_window_scores"] for item in leaderboard
    ], window_intervals if isinstance(window_intervals, list) else [],
        purge_bars=int(prior_trials.get("purge_bars", 0) or 0) if isinstance(prior_trials, dict) else 0,
        embargo_bars=int(prior_trials.get("embargo_bars", 0) or 0) if isinstance(prior_trials, dict) else 0,
    )
    for item in leaderboard:
        evidence = dict(item["result"].get("statistical_evidence", {}))
        evidence["deflated_sharpe"] = deflated_sharpe_ratio(
            returns_from_equity_curve(item["result"].get("equity_curve", [])), trial_sharpes,
        )
        item["result"]["statistical_evidence"] = evidence
        item["result"]["selection_validation"] = cscv
        if isinstance(prior_trials, dict):
            item["result"]["trial_ledger"] = {**prior_trials, "promotion_evidence": False}

    _attach_behavioral_diversity(leaderboard)

    _write_replay_checkpoint(
        str(checkpoint_key),
        "evidence_persisted",
        resume_manifest,
        leaderboard_count=len(leaderboard),
        feature_snapshot_builds=shared_snapshot_builds,
        feature_snapshot_cache_hits=shared_snapshot_hits,
    )

    leaderboard = sorted(leaderboard, key=lambda item: item["score"], reverse=True)

    stage_timings["total_ms"] = round((time.perf_counter() - timing_started) * 1000, 3)
    # Operational timing is emitted out-of-band. It must never enter the
    # immutable result hash, gate metrics, or replay cache value.
    print("REPLAY_TIMING " + json.dumps({
        "protocol": "replay_stage_timing_v1",
        "stages_ms": stage_timings,
        "candidate_count": len(leaderboard),
        "promotion_evidence": False,
    }, separators=(",", ":")), file=sys.stderr, flush=True)

    return {
        "symbol": payload.symbol,
        "timeframe": payload.timeframe,
        "leaderboard": leaderboard,
        "statistical_validation": cscv,
        "resume_manifest": resume_manifest,
    }


@app.post("/api/portfolio/backtest")
def run_portfolio_backtest(payload: SimpleBacktestRequest) -> dict[str, object]:
    """Replay a pre-declared complementary portfolio on one canonical stream.

    This endpoint deliberately does not accept a post-replay optimiser or a
    free-form month/regime selector. Membership and niche ownership are part
    of the request contract and are frozen before the same next-candle,
    conservative-cost execution engine is run.
    """
    if len(payload.portfolio_members) < 2:
        raise HTTPException(status_code=400, detail="A portfolio replay requires at least two declared members.")
    return _run_bounded_replay("portfolio", payload)


def _run_portfolio_backtest_sync(payload: SimpleBacktestRequest) -> dict[str, object]:
    if len(payload.portfolio_members) < 2:
        raise ValueError("A portfolio replay requires at least two declared members.")
    validated_members = []
    try:
        for member in payload.portfolio_members:
            parameters = validate_strategy_parameters(member.strategy, member.parameters, member.base_strategy)
            validated_members.append(member.model_copy(update={"parameters": parameters}))
        run_payload = payload.model_copy(update={
            "strategy": "portfolio_v1",
            "base_strategy": "portfolio",
            # The portfolio owns only its sealed global policy layer. Member
            # execution genes are bound per signal by the router; copying the
            # first member here would silently turn the council back into a
            # primary-agent replay.
            "parameters": dict(payload.parameters or {}),
            "portfolio_members": validated_members,
        })
        # Portfolio evidence must use the same chronological replay, stress,
        # monthly, temporal-firewall and adversarial lanes as an individual
        # full-validation candidate. A plain simple replay would silently
        # omit those gates and make the combined portfolio incomparable.
        source_df = _load_simple_candles(run_payload)
        foundation_df = _load_foundation_candles(run_payload)
        analysis = MarketAdaptiveReplayService().run(
            run_payload, source_df, calculate_strategy_score, foundation_df
        )
        return analysis["result"]
    except FileNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise ValueError(str(exc)) from exc


def _bounded_replay_seconds(payload: SimpleBacktestRequest, operation: str) -> int:
    """Return a deadline that is shorter than the Laravel transport budget."""
    is_differential = any(
        "differential" in str(value).lower()
        for value in [payload.strategy, payload.base_strategy, payload.version]
    ) or any("differential" in str(member.strategy).lower() for member in payload.strategies)

    if operation == "portfolio":
        # Keep the evaluator bounded, but give a legitimate sealed council
        # replay enough room for its long foundation lane on Windows. The
        # Laravel transport remains longer than this child deadline.
        env_name, default, ceiling = "AI_REPLAY_PORTFOLIO_HARD_TIMEOUT_SECONDS", 3600, 3600
    elif payload.evaluation_mode == "incremental" and len(payload.strategies) > 1:
        # A bounded cohort shares feature construction but still walks each
        # candidate's independent state machine. Give the one child process a
        # larger finite budget than a single screen, while Laravel keeps the
        # batch worker timeout above it.
        env_name, default, ceiling = "AI_REPLAY_SCREEN_BATCH_HARD_TIMEOUT_SECONDS", 1680, 2280
    elif payload.evaluation_mode == "incremental" and is_differential:
        env_name, default, ceiling = "AI_REPLAY_DIFFERENTIAL_SCREEN_HARD_TIMEOUT_SECONDS", 780, 840
    elif payload.evaluation_mode == "incremental":
        # Screening computes the full 5k-candle survival profile, including
        # chronological month attribution. Keep a strict wall-clock bound,
        # but leave enough room for a normal Windows worker under load. This
        # is an operational budget only; it never changes a screening gate.
        # The previous 330-second ceiling cut off legitimate CPU-active H1
        # replays that completed between 350 and 390 seconds. G13 then
        # demonstrated a second, heavier sealed cohort above 600 seconds;
        # keep the hard bound finite while leaving room for that workload.
        env_name, default, ceiling = "AI_REPLAY_SCREEN_HARD_TIMEOUT_SECONDS", 900, 900
    else:
        # Full replay includes the separate 2005-2025 foundation score and
        # several deterministic robustness lanes. The old 2220s bound
        # classified a CPU-active replay as an evaluation error before that
        # sealed work could finish.
        env_name, default, ceiling = "AI_REPLAY_FULL_HARD_TIMEOUT_SECONDS", 3600, 3600

    try:
        configured = int(os.getenv(env_name, str(default)))
    except ValueError:
        configured = default
    return max(30, min(configured, ceiling))


def _read_replay_capture(stream) -> str:
    if stream is None:
        return ""
    try:
        stream.flush()
        stream.seek(0)
        value = stream.read()
        return value if isinstance(value, str) else value.decode("utf-8", errors="replace")
    except (OSError, ValueError):
        return ""


def _terminate_replay_tree(process: subprocess.Popen | None, stdout_capture=None, stderr_capture=None) -> tuple[str, str]:
    """Kill a replay and every descendant, then drain its pipes briefly.

    On Windows, ``Popen.kill()`` only targets the immediate process. A replay
    worker can retain stdout/stderr handles through a descendant and make the
    parent appear alive forever. ``taskkill /T /F`` is the explicit containment
    boundary for this service; it is used only for the exact worker PID.
    """
    if process is None:
        return "", ""
    if process.poll() is None:
        if os.name == "nt":
            try:
                subprocess.run(
                    ["taskkill", "/PID", str(process.pid), "/T", "/F"],
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL,
                    timeout=10,
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                    check=False,
                )
            except (OSError, subprocess.TimeoutExpired):
                pass
        else:
            try:
                process.kill()
            except OSError:
                pass
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        try:
            process.kill()
        except OSError:
            pass
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            pass
    if stdout_capture is not None or stderr_capture is not None:
        return _read_replay_capture(stdout_capture), _read_replay_capture(stderr_capture)
    return "", ""


def _run_bounded_replay(operation: str, payload: SimpleBacktestRequest) -> dict[str, object]:
    """Execute one bounded replay in a killable screening/full lane.

    ``multiprocessing`` spawn is not a reliable containment boundary on
    Windows when the request contains thousands of inline candle objects: the
    parent can remain inside process startup/serialization before it can
    observe a child failure. A standalone Python subprocess with a JSON pipe
    gives us a hard parent-controlled deadline and a deterministic kill path.
    """
    global _last_replay_finished_at, _last_replay_termination, _last_replay_stage_timings
    global _active_screen_replay_count, _active_full_replay_count

    # The replay compiler is intentionally content addressed.  It is safe to
    # reuse only an *identical* payload under the same evaluator code digest;
    # no stochastic seed, candle, execution assumption or specialist contract
    # is omitted from the key.  This removes duplicate timeout work without
    # changing a single gate result.
    cache_key = _replay_cache_key(operation, payload)
    cached = _load_immutable_replay_cache(cache_key)
    if cached is not None:
        _last_replay_stage_timings = {"cache_hit": 1.0}
        _last_replay_finished_at = pd.Timestamp.now(tz="UTC").isoformat()
        _last_replay_termination = "cache_hit"
        return _with_replay_compiler_metadata(cached, cache_key, "immutable_cache_hit")

    # Laravel's WithoutOverlapping is the primary queue mutex. This second
    # guard protects direct/API callers and makes contention fail fast rather
    # than queueing an HTTP request that will consume a client timeout.
    screening_lane = payload.evaluation_mode == "incremental"
    policy_context = payload.policy_context or {}
    shadow_micro_probe = bool(policy_context.get("shadow_micro_probe", False))
    try:
        micro_probe_max_rows = max(128, int(os.getenv("AI_SHADOW_MICRO_PROBE_MAX_ROWS", "512")))
        micro_probe_max_candidates = max(1, int(os.getenv("AI_SHADOW_MICRO_PROBE_MAX_CANDIDATES", "6")))
    except ValueError:
        micro_probe_max_rows, micro_probe_max_candidates = 512, 6
    micro_probe_valid = (
        shadow_micro_probe
        and screening_lane
        and (payload.dataset_tail_rows is None or payload.dataset_tail_rows <= micro_probe_max_rows)
        and len(payload.candles) <= micro_probe_max_rows
        and len(payload.strategies or [payload]) <= micro_probe_max_candidates
        and not payload.emit_decision_trace
    )
    lane_guard = _screen_replay_slots if screening_lane else _replay_lane_lock
    micro_probe_guard_acquired = False
    with _replay_state_lock:
        if screening_lane:
            if micro_probe_valid and _active_full_replay_count > 0:
                # A micro-probe is the only screening exception allowed while
                # the single heavy coordinator is active. It has a separate
                # one-slot guard and a hard row/candidate budget.
                micro_probe_guard_acquired = _shadow_micro_probe_lock.acquire(blocking=False)
                acquired = micro_probe_guard_acquired and lane_guard.acquire(blocking=False)
            else:
                acquired = _active_full_replay_count == 0 and lane_guard.acquire(blocking=False)
        else:
            acquired = _active_screen_replay_count == 0 and lane_guard.acquire(blocking=False)
        if not acquired:
            if micro_probe_guard_acquired:
                _shadow_micro_probe_lock.release()
            raise HTTPException(status_code=429, detail="AI replay lane is busy; retry after the current bounded replay.")
        if screening_lane:
            _active_screen_replay_count += 1
        else:
            _active_full_replay_count += 1

    timeout_seconds = _bounded_replay_seconds(payload, operation)
    service_root = Path(__file__).resolve().parent.parent
    worker_module = "app.replay_worker"
    request_body = json.dumps({
        "operation": operation,
        "payload": payload.model_dump(mode="json"),
    }, ensure_ascii=False, separators=(",", ":"))
    child_env = os.environ.copy()
    child_env["PYTHONUTF8"] = "1"
    child_env["PYTHONIOENCODING"] = "utf-8"
    # Backtests do not need provider/API credentials. Do not copy ambient
    # secrets into short-lived evaluator children.
    for key in list(child_env):
        if key.startswith(("OPENAI_", "CODEX_")):
            child_env.pop(key, None)
    creation_flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    if os.name == "nt":
        creation_flags |= getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)
    process = None
    stdout_capture = None
    stderr_capture = None
    try:
        # Do not use stdout/stderr=PIPE here.  A normal evidence response can
        # contain a large full-ledger JSON document; if the parent only polls
        # process.poll() while the child writes, the OS pipe fills and the
        # child blocks forever until the wall-clock timeout.  Temporary files
        # preserve the same containment boundary without an IPC backpressure
        # deadlock.
        # Keep the capture files binary.  On Windows a child can emit a
        # provider/path diagnostic in the active code page (for example
        # CP1252 smart quotes) even though its JSON protocol is UTF-8.  A
        # text wrapper makes that single byte capable of raising a decode
        # error while the parent is draining an otherwise valid response.
        stdout_capture = tempfile.TemporaryFile(mode="w+b")
        stderr_capture = tempfile.TemporaryFile(mode="w+b")
        process = subprocess.Popen(
            [sys.executable, "-m", worker_module],
            cwd=str(service_root),
            env=child_env,
            stdin=subprocess.PIPE,
            stdout=stdout_capture,
            stderr=stderr_capture,
            creationflags=creation_flags,
            text=False,
        )
        try:
            if process.stdin is not None:
                process.stdin.write(request_body.encode("utf-8"))
                process.stdin.close()
        except (BrokenPipeError, OSError):
            pass

        deadline = time.monotonic() + timeout_seconds
        while process.poll() is None:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                _last_replay_termination = f"hard_timeout:{operation}:{timeout_seconds}s"
                _terminate_replay_tree(process, stdout_capture, stderr_capture)
                raise HTTPException(
                    status_code=504,
                    detail=f"Bounded AI replay exceeded {timeout_seconds}s; strategy verdict withheld.",
                )
            time.sleep(min(0.25, remaining))

        stdout = _read_replay_capture(stdout_capture)
        stderr = _read_replay_capture(stderr_capture)
        for line in stderr.splitlines():
            if line.startswith("REPLAY_TIMING "):
                try:
                    timing_payload = json.loads(line[len("REPLAY_TIMING "):])
                    _last_replay_stage_timings = dict(timing_payload.get("stages_ms", {}))
                except (json.JSONDecodeError, TypeError):
                    pass

        if process.returncode != 0:
            _last_replay_termination = f"child_exit:{operation}:{process.returncode}"
            detail = (
                stderr
                or stdout
                or f"AI replay worker exited before returning evidence (exit_code={process.returncode})."
            ).strip()
            raise HTTPException(status_code=503, detail=detail[-500:])
        try:
            message = json.loads(stdout or "{}")
        except json.JSONDecodeError as exc:
            _last_replay_termination = f"invalid_child_output:{operation}"
            raise HTTPException(status_code=503, detail="AI replay worker returned invalid evidence output.") from exc

        if not message.get("ok"):
            kind = message.get("kind")
            detail = str(message.get("detail", "AI replay failed."))
            if kind == "not_found":
                raise HTTPException(status_code=404, detail=detail)
            if kind == "value":
                raise HTTPException(status_code=400, detail=detail)
            if kind == "http":
                raise HTTPException(status_code=int(message.get("status", 500)), detail=detail)
            raise HTTPException(status_code=500, detail=detail)
        value = _with_replay_compiler_metadata(message["value"], cache_key, "compiled")
        _store_immutable_replay_cache(cache_key, value)
        _last_replay_termination = "completed"
        return value
    except HTTPException:
        raise
    except Exception as exc:
        _last_replay_termination = f"process_error:{type(exc).__name__}"
        raise HTTPException(status_code=503, detail=f"AI replay worker unavailable: {exc}") from exc
    finally:
        if process is not None and process.poll() is None:
            _terminate_replay_tree(process, stdout_capture, stderr_capture)
        for capture in (stdout_capture, stderr_capture):
            if capture is not None:
                try:
                    capture.close()
                except OSError:
                    pass
        _last_replay_finished_at = pd.Timestamp.now(tz="UTC").isoformat()
        with _replay_state_lock:
            if screening_lane:
                _active_screen_replay_count = max(0, _active_screen_replay_count - 1)
            else:
                _active_full_replay_count = max(0, _active_full_replay_count - 1)
            lane_guard.release()
            if micro_probe_guard_acquired:
                _shadow_micro_probe_lock.release()


def _replay_cache_key(operation: str, payload: SimpleBacktestRequest) -> str:
    """Fingerprint every local evaluator dependency and the sealed datasets."""
    code = _runtime_dependency_manifest()
    body = {
        "protocol": "immutable_replay_compiler_v2",
        "operation": operation,
        "payload": payload.model_dump(mode="json"),
        "code": code,
        "datasets": _dataset_dependency_manifest(payload),
    }
    return hashlib.sha256(json.dumps(body, sort_keys=True, separators=(",", ":"), default=str).encode("utf-8")).hexdigest()


def _runtime_dependency_manifest() -> dict[str, object]:
    """Hash the complete Python app, including registry and validation modules.

    A short hand-maintained file list is unsafe here: adding a strategy,
    changing a walk-forward/statistical helper, or editing a transitive local
    import must invalidate old replay evidence automatically.
    """
    global _runtime_manifest_cache_signature, _runtime_manifest_cache
    app_root = Path(__file__).resolve().parent
    paths = [path for path in sorted(app_root.rglob("*.py")) if "__pycache__" not in path.parts]
    paths += [
        app_root.parent / name
        for name in ("requirements.txt", "pyproject.toml", "poetry.lock")
        if (app_root.parent / name).is_file()
    ]
    signature = tuple((str(path), int(path.stat().st_mtime_ns), int(path.stat().st_size)) for path in paths)
    if signature == _runtime_manifest_cache_signature and _runtime_manifest_cache is not None:
        return _runtime_manifest_cache

    files: dict[str, str] = {}
    for path in paths:
        key = path.relative_to(app_root).as_posix() if path.is_relative_to(app_root) else path.name
        files[key] = hashlib.sha256(path.read_bytes()).hexdigest()

    _runtime_manifest_cache_signature = signature
    _runtime_manifest_cache = {
        "protocol": "runtime_dependency_manifest_v2",
        "python": sys.version,
        "files": files,
    }
    return _runtime_manifest_cache


def _dataset_dependency_manifest(payload: SimpleBacktestRequest) -> dict[str, object]:
    paths = [
        ("dataset", payload.dataset_path),
        ("regime_dataset", payload.regime_dataset_path),
        # Full replay deliberately uses a separate pre-2026 foundation
        # archive. Its content hash must participate in the immutable cache
        # identity or a changed training archive could reuse old evidence.
        ("foundation_dataset", payload.foundation_dataset_path),
    ]
    resolved: list[tuple[str, str, Path, Path]] = []
    for key, raw_path in paths:
        if not raw_path:
            continue
        path = Path(raw_path)
        if not path.is_absolute():
            path = (Path(__file__).resolve().parent.parent / path).resolve()
        resolved.append((key, raw_path, path, Path(str(path) + ".manifest.json")))

    cache_signature = tuple(
        (
            key,
            str(path),
            int(path.stat().st_mtime_ns) if path.is_file() else 0,
            int(path.stat().st_size) if path.is_file() else 0,
            int(manifest_path.stat().st_mtime_ns) if manifest_path.is_file() else 0,
            int(manifest_path.stat().st_size) if manifest_path.is_file() else 0,
        )
        for key, _, path, manifest_path in resolved
    )
    cached = _dataset_manifest_cache.get(cache_signature)
    if cached is not None:
        return cached

    manifest: dict[str, object] = {}
    for key, raw_path, path, manifest_path in resolved:
        item: dict[str, object] = {"requested_path": raw_path, "resolved_path": str(path)}
        if manifest_path.is_file():
            try:
                manifest_payload = json.loads(manifest_path.read_text(encoding="utf-8"))
                item["manifest_sha256"] = manifest_payload.get("sha256")
                item["manifest_file_hash"] = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
                if path.is_file():
                    item["file_hash"] = hashlib.sha256(path.read_bytes()).hexdigest()
            except (OSError, json.JSONDecodeError):
                item["manifest_file_hash"] = None
        elif path.is_file():
            item["file_hash"] = hashlib.sha256(path.read_bytes()).hexdigest()
        else:
            item["missing"] = True
        manifest[key] = item

    _dataset_manifest_cache[cache_signature] = manifest
    return manifest


def _replay_cache_path(cache_key: str) -> Path:
    root = Path(os.getenv("AI_REPLAY_IMMUTABLE_CACHE_DIR", str(Path(__file__).resolve().parent.parent / ".runtime" / "replay-cache")))
    return root / f"{cache_key}.json.gz"


def _load_immutable_replay_cache(cache_key: str) -> dict[str, object] | None:
    compressed = _replay_cache_path(cache_key)
    candidates = [compressed, compressed.with_suffix("").with_suffix(".json")]
    for path in candidates:
        try:
            raw = path.read_bytes()
            if path.suffix == ".gz":
                raw = gzip.decompress(raw)
            document = json.loads(raw.decode("utf-8"))
            value = document.get("value")
            if isinstance(value, dict) and document.get("key") == cache_key:
                return value
        except (OSError, gzip.BadGzipFile, EOFError, json.JSONDecodeError, UnicodeDecodeError):
            continue
    return None


def _store_immutable_replay_cache(cache_key: str, value: dict[str, object]) -> None:
    path = _replay_cache_path(cache_key)
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        # Atomic replace prevents a timeout/restart from ever exposing a
        # partial diagnostic as if it were a completed immutable replay.
        encoded = json.dumps({
            "protocol": "immutable_replay_cache_v2",
            "key": cache_key,
            "created_at": pd.Timestamp.now(tz="UTC").isoformat(),
            "value": value,
        }, ensure_ascii=False, separators=(",", ":"), default=str).encode("utf-8")
        temporary = path.with_suffix(".tmp")
        temporary.write_bytes(gzip.compress(encoded, compresslevel=1))
        temporary.replace(path)
        _cleanup_replay_cache(path.parent)
    except OSError:
        # Cache failure is operational-only.  Replay evidence and all gates
        # continue through the normal deterministic worker path.
        return


def _cleanup_replay_cache(root: Path) -> None:
    """Apply TTL and size bounds so immutable cache cannot fill the disk."""
    global _last_replay_cache_cleanup
    now = time.time()
    interval = max(30, int(os.getenv("AI_REPLAY_CACHE_CLEANUP_INTERVAL_SECONDS", "300")))
    if now - _last_replay_cache_cleanup < interval:
        return
    with _replay_cache_cleanup_lock:
        if now - _last_replay_cache_cleanup < interval:
            return
        _last_replay_cache_cleanup = now
        retention_days = max(1, int(os.getenv("AI_REPLAY_CACHE_RETENTION_DAYS", "14")))
        max_bytes = max(64 * 1024 * 1024, int(os.getenv("AI_REPLAY_CACHE_MAX_BYTES", str(2 * 1024 * 1024 * 1024))))
        cutoff = now - retention_days * 86400
        files = []
        try:
            for path in [*root.glob("*.json.gz"), *root.glob("*.json")]:
                try:
                    stat = path.stat()
                    files.append((path, stat.st_mtime, stat.st_size))
                    if stat.st_mtime < cutoff:
                        path.unlink()
                except OSError:
                    continue

            files = [(path, mtime, size) for path, mtime, size in files if path.exists()]
            total = sum(size for _, _, size in files)
            for path, _, size in sorted(files, key=lambda item: item[1]):
                if total <= max_bytes:
                    break
                try:
                    path.unlink()
                    total -= size
                except OSError:
                    continue
        except OSError:
            return


def _with_replay_compiler_metadata(value: dict[str, object], cache_key: str, status: str) -> dict[str, object]:
    result = dict(value)
    compiler = {
        "protocol": "immutable_replay_compiler_v2",
        "status": status,
        "cache_key": cache_key,
        "stages": ["immutable_payload", "core_replay", "expensive_diagnostics_after_core_gate"],
        "rule": "Exact payload cache only; a failed core gate does not schedule expensive diagnostic work.",
    }
    # run-all returns a leaderboard while portfolio returns a direct result.
    if isinstance(result.get("leaderboard"), list):
        for item in result["leaderboard"]:
            if isinstance(item, dict) and isinstance(item.get("result"), dict):
                item["result"] = {**item["result"], "replay_compiler": compiler}
    else:
        result["replay_compiler"] = compiler
    return result


def _attach_behavioral_diversity(leaderboard: list[dict[str, object]]) -> None:
    """Annotate each batch candidate with observable behaviour similarity."""
    for item in leaderboard:
        signature = item["result"].get("behavioral_signature", {})
        comparisons = []
        for other in leaderboard:
            if other is item:
                continue
            other_signature = other["result"].get("behavioral_signature", {})
            signal = _minhash_similarity(signature.get("signal_minhash", []), other_signature.get("signal_minhash", []))
            entries, other_entries = set(signature.get("trade_entries", [])), set(other_signature.get("trade_entries", []))
            overlap = len(entries & other_entries) / len(entries | other_entries) if entries or other_entries else 0.0
            equity = _correlation(item["result"].get("equity_curve", []), other["result"].get("equity_curve", []))
            losses = {trade.get("signal_time") for trade in item["result"].get("trades", []) if float(trade.get("profit_percent", 0)) < 0}
            other_losses = {trade.get("signal_time") for trade in other["result"].get("trades", []) if float(trade.get("profit_percent", 0)) < 0}
            loss_overlap = len(losses & other_losses) / len(losses | other_losses) if losses or other_losses else 0.0
            comparisons.append({"strategy": other["strategy"], "signal_similarity": round(signal, 3), "trade_overlap": round(overlap, 3), "equity_correlation": round(equity, 3), "loss_overlap": round(loss_overlap, 3)})
        clone = [value for value in comparisons if (value["signal_similarity"] >= .85 and value["trade_overlap"] >= .85) or value["equity_correlation"] >= .95]
        item["result"]["behavioral_diversity"] = {
            "status": "near_duplicate" if clone else "diverse",
            "signal_similarity_threshold": .85, "equity_correlation_threshold": .95,
            "nearest": sorted(comparisons, key=lambda value: max(value["signal_similarity"], value["trade_overlap"], value["equity_correlation"]), reverse=True)[:3],
            "near_duplicate_with": clone,
        }
        tail_overlap = sum(value["loss_overlap"] for value in comparisons) / len(comparisons) if comparisons else 0.0
        tail_correlation = sum(max(0.0, value["equity_correlation"]) for value in comparisons) / len(comparisons) if comparisons else 0.0
        item["result"]["negative_space_portfolio"] = {
            "loss_overlap": round(tail_overlap, 3), "tail_loss_correlation": round(tail_correlation, 3),
            "diversification_score": round(max(0, 100 * (1 - ((tail_overlap + tail_correlation) / 2))), 2),
            "rule": "portfolio selection rewards uncorrelated failure modes, not only standalone PF",
        }
        item["result"]["failure_correlation"] = {
            "protocol": "failure_correlation_matrix_v1",
            "matrix": {
                item["strategy"]: {value["strategy"]: value["loss_overlap"] for value in comparisons}
            },
            "max_loss_overlap": round(max((value["loss_overlap"] for value in comparisons), default=0.0), 3),
            "promotion_evidence": False,
            "rule": "Failure timing overlap is recorded separately from family labels and standalone PF.",
        }


def _minhash_similarity(left: list[int], right: list[int]) -> float:
    pairs = [(a, b) for a, b in zip(left, right) if a >= 0 and b >= 0]
    return sum(a == b for a, b in pairs) / len(pairs) if pairs else 0.0


def _correlation(left: list[float], right: list[float]) -> float:
    pairs = list(zip(left[1:], right[1:]))
    if len(pairs) < 3:
        return 0.0
    a, b = [float(value[0]) for value in pairs], [float(value[1]) for value in pairs]
    mean_a, mean_b = sum(a) / len(a), sum(b) / len(b)
    numerator = sum((x - mean_a) * (y - mean_b) for x, y in zip(a, b))
    denominator = math.sqrt(sum((x - mean_a) ** 2 for x in a) * sum((y - mean_b) ** 2 for y in b))
    return numerator / denominator if denominator else 0.0


def _prepare_paper_payload(payload: SimpleBacktestRequest) -> SimpleBacktestRequest:
    """Validate the sealed single-agent or portfolio paper contract."""
    enforce_policy_boundary(payload)
    if payload.portfolio_members:
        if len(payload.portfolio_members) < 2:
            raise ValueError("A portfolio paper contract requires at least two members.")
        members = [
            member.model_copy(update={
                "parameters": validate_strategy_parameters(member.strategy, member.parameters, member.base_strategy),
            })
            for member in payload.portfolio_members
        ]
        return payload.model_copy(update={
            "portfolio_members": members,
            # Keep only the sealed portfolio-level policy. The selected
            # member's execution parameters are attached to each paper signal
            # by the same router used in canonical replay.
            "parameters": dict(payload.parameters or {}),
        })
    return payload.model_copy(update={
        "parameters": validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy),
    })


def _twin_inference_contract(
    lane: str,
    snapshot_hash: str,
    context: dict[str, object],
    output: dict[str, object],
    reasoning_budget: int,
) -> dict[str, object]:
    """Create independently hashed lane evidence on one immutable snapshot.

    Champion and Council intentionally receive different context contracts and
    budgets. They share market identity, never mutable output state. This is
    the runtime boundary Laravel persists as two organism observations.
    """
    context_hash = hashlib.sha256(json.dumps(context, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
    prompt_hash = hashlib.sha256(json.dumps({"lane": lane, "context": context, "budget": reasoning_budget}, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
    output_hash = hashlib.sha256(json.dumps(output, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
    return {
        "protocol": "twin_independent_inference_v1",
        "lane": lane,
        "process_id": f"python-paper-{lane}-pid-{os.getpid()}",
        "model_call_id": f"paper:{lane}:{snapshot_hash[:24]}:{output_hash[:12]}",
        "snapshot_hash": snapshot_hash,
        "context_hash": context_hash,
        "prompt_hash": prompt_hash,
        "output_hash": output_hash,
        "reasoning_budget": reasoning_budget,
        "context": context,
        "independence_status": "independent_context_same_snapshot",
        "promotion_evidence": False,
    }


def _council_confidence(meta: dict[str, object]) -> float:
    """Estimate Council confidence from full committee support and entropy.

    Abstentions remain in the denominator. A single BUY vote beside several
    WAIT votes must not look like unanimous conviction merely because WAIT was
    removed from the calculation.
    """
    votes: list[str] = []
    for member in meta.get("agents", []) if isinstance(meta.get("agents"), list) else []:
        if not isinstance(member, dict):
            continue
        vote = str(member.get("decision", "")).upper()
        if vote in {"BUY", "SELL", "WAIT"} and str(member.get("agent", "")) not in {"event", "risk_governor"}:
            votes.append(vote)
    if not votes:
        # A Council that abstains is moderately confident in abstention, but
        # must never inherit the strategy branch's confidence value.
        return 0.5
    counts = {decision: votes.count(decision) for decision in {"BUY", "SELL", "WAIT"}}
    final_decision = str(meta.get("decision", "WAIT")).upper()
    support = counts.get(final_decision, 0) / len(votes)
    quorum = max(counts.values()) / len(votes)
    entropy = 0.0
    for count in counts.values():
        if count:
            probability = count / len(votes)
            entropy -= probability * math.log(probability, 2)
    normalized_entropy = entropy if len(votes) > 1 else 0.0
    return round(max(0.0, min(1.0, 0.65 * support + 0.35 * (1.0 - normalized_entropy))), 6)


@app.post("/api/paper/signal")
def paper_signal(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        payload = _prepare_paper_payload(payload)
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"], utc=True, errors="coerce")
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df.columns:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        if "volume_available" not in df.columns:
            df["volume_available"] = False
        df["volume_available"] = df["volume_available"].astype("boolean").fillna(False).astype(bool)
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").tail(1000).reset_index(drop=True)
        canonical = df[["time", "open", "high", "low", "close", "volume", "volume_available"]].copy()
        canonical_json = canonical.to_json(orient="records", date_format="iso", double_precision=15)
        dataset_hash = hashlib.sha256(canonical_json.encode()).hexdigest()
        feature_config_hash = hashlib.sha256(json.dumps({
            "volume_context": payload.volume_context,
            "regime_dataset_path": payload.regime_dataset_path,
            "strategy": payload.strategy,
            "base_strategy": payload.base_strategy,
        }, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
        execution_config_hash = hashlib.sha256(json.dumps(payload.execution.model_dump(), sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
        dependency_manifest = _dataset_dependency_manifest(payload)
        manifest_body = {
            "protocol": "canonical_market_snapshot_manifest_v1",
            "symbol": payload.symbol.upper(), "timeframe": payload.timeframe.upper(),
            "candle_count": int(len(canonical)),
            "first_candle": _utc_timestamp(canonical.iloc[0]["time"]).isoformat() if len(canonical) else None,
            "latest_candle": _utc_timestamp(canonical.iloc[-1]["time"]).isoformat() if len(canonical) else None,
            "dataset_hash": dataset_hash, "feature_config_hash": feature_config_hash,
            "execution_config_hash": execution_config_hash,
            "dependency_manifest": dependency_manifest,
        }
        snapshot_hash = hashlib.sha256(json.dumps(manifest_body, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        snapshot_manifest = {**manifest_body, "snapshot_hash": snapshot_hash, "status": "sealed"}
        df = _apply_execution_regime(df, _load_regime_source(payload))
        df = add_volume_features(df, payload.volume_context)
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        prepared = (
            _apply_portfolio_strategy(df, payload.portfolio_members)
            if payload.portfolio_members
            else apply_volume_policy(
                get_strategy(payload.strategy, payload.base_strategy)(df, payload.parameters),
                payload.parameters,
                payload.base_strategy or payload.strategy,
            )
        )
        row = prepared.iloc[-1]
        signal = str(row.get("signal", "WAIT"))
        raw_agent_signal = signal
        price = float(row["close"])
        meta = _abstention_meta_decision(row, prepared.iloc[-2] if len(prepared) > 1 else None, signal, price, payload)
        router_wait_reason = str(row.get("portfolio_wait_reason", "") or "")
        if signal == "WAIT" and router_wait_reason:
            meta = {**meta, "decision": "WAIT", "reason": router_wait_reason, "router_wait": True}
        raw_meta = dict(meta)
        pre_mtf_decision = str(meta.get("decision", "WAIT"))
        mtf = apply_signal_policy(pre_mtf_decision, row, payload.mtf_pilot, row.get("time"))
        if mtf["decision"] != pre_mtf_decision:
            meta = {
                **meta,
                "decision": mtf["decision"],
                "reason": mtf["reason"],
                "mtf_previous_decision": pre_mtf_decision,
                "mtf_veto": mtf["decision"] == "WAIT",
            }
        if meta.get("decision") in {"BUY", "SELL"}:
            meta["position_size_multiplier"] = round(
                float(meta.get("position_size_multiplier", 1.0) or 1.0)
                * float(mtf.get("risk_multiplier", 1.0) or 1.0), 6
            )
        else:
            meta["position_size_multiplier"] = 0.0
        final_signal = str(meta.get("decision", "WAIT"))
        official_contract = _execution_contract(payload, row, final_signal, price, meta, mtf)
        shadow_contract = _execution_contract(
            payload,
            row,
            pre_mtf_decision,
            price,
            raw_meta,
            {"protocol": "xauusd_h1_m15_mtf_v1", "context": {"status": "not_applicable"}, "risk_multiplier": 1.0},
        )
        champion_output = {"decision": raw_agent_signal, "confidence": float(row.get("signal_confidence", 0.0) or 0), "source": "raw_strategy_signal"}
        council_confidence = _council_confidence(meta)
        council_output = {
            "decision": str(meta.get("decision", "WAIT")), "confidence": council_confidence,
            "confidence_source": "committee_agreement_entropy_v1",
            "committee": meta.get("council", {}), "source": "typed_agent_council",
        }
        champion_context = {
            "strategy": payload.strategy, "base_strategy": payload.base_strategy,
            "parameters": dict(payload.parameters), "execution_policy": payload.execution.model_dump(),
            "objective": "execution_robustness", "reasoning_mode": "fast_local_execution",
        }
        council_context = {
            "market_regime": str(row.get("market_regime", "unknown")),
            "volatility_regime": str(row.get("volatility_regime", "normal_volatility")),
            "committee_roles": [member.get("agent") for member in meta.get("agents", []) if isinstance(member, dict)],
            "objective": "collective_reasoning_quality", "reasoning_mode": "risk_governance_and_falsification",
            "risk_policy": {"transition": bool(meta.get("transition", False)), "ood_action": str((meta.get("ood") or {}).get("action", "UNKNOWN"))},
        }
        champion_output["inference"] = _twin_inference_contract("champion", snapshot_hash, champion_context, champion_output, 256)
        council_output["inference"] = _twin_inference_contract("council", snapshot_hash, council_context, council_output, 768)
        spread_atr_ratio = (
            float(payload.execution.spread_points) * float(payload.execution.point_size)
            / max(float(row.get("_management_atr", 0) or 0), 1e-9)
        )
        return {
            "signal": final_signal, "agent_signal": raw_agent_signal, "signal_time": _utc_timestamp(row["time"]).isoformat(), "price": price,
            "market_regime": str(row.get("market_regime", "unknown")),
            "volatility_regime": str(row.get("volatility_regime", "normal_volatility")),
            "spread_atr_ratio": round(spread_atr_ratio, 8),
            "transition": {"active": bool(meta.get("transition", False))},
            "confidence": float(row.get("signal_confidence", 1.0) or 0),
            "volume_quality": dict(prepared.attrs.get("volume_quality") or {}),
            "volume_context": {
                "feature_available": bool(row.get("volume_feature_available", False)),
                "ratio": float(row.get("volume_ratio", 0) or 0) if pd.notna(row.get("volume_ratio")) else None,
                "regime": str(row.get("volume_regime", "unavailable")),
                "policy_rejection": str(row.get("volume_policy_rejection", "")),
            },
            "meta_agent": meta,
            # Explicit dual-track projection. The Champion is the raw
            # strategy branch; the Council is the typed/meta governor branch.
            # Laravel records both against this exact snapshot. This is an
            # observation contract, not promotion evidence.
            "dual_track": {
                "protocol": "dual_track_constitutional_intelligence_v1",
                "snapshot_hash": snapshot_hash,
                "snapshot_manifest": snapshot_manifest,
                "champion": champion_output,
                "council": council_output,
                "selected_by_existing_governor": final_signal,
                "independence_status": "dedicated_lane_endpoint" if payload.twin_lane else "same_snapshot_separate_projection",
                "requested_lane": payload.twin_lane,
                "promotion_evidence": False,
            },
            "mtf_pilot": mtf,
            "counterfactuals": counterfactuals(
                pre_mtf_decision,
                mtf,
                {
                    "m15_only": shadow_contract,
                    "h1_m15_official": official_contract,
                    "m15_without_h1_veto": shadow_contract,
                },
            ),
            "router_wait_reason": router_wait_reason or None,
            "execution_contract_preview": official_contract,
        }
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/twin/{lane}")
def paper_twin_inference(lane: str, body: dict[str, object]) -> dict[str, object]:
    """Run one constitutional lane behind its own transport boundary.

    Laravel invokes this endpoint twice, concurrently, with the same sealed
    request.  The lane marker changes the inference contract and output
    selection, while the snapshot hash must remain identical.  A deployment
    can route the two endpoints to separate worker pools/processes without
    changing the evidence schema.
    """
    if lane not in {"champion", "council"}:
        raise HTTPException(status_code=404, detail="Unknown twin lane.")
    try:
        if os.getenv("AI_TWIN_PROCESS_ISOLATION", "true").lower() in {"1", "true", "yes", "on"}:
            result = _run_twin_lane_process(lane, body)
        else:
            payload = SimpleBacktestRequest.model_validate(body).model_copy(update={"twin_lane": lane})
            result = paper_signal(payload)
        dual = dict(result.get("dual_track") or {})
        selected = dict(dual.get(lane) or {})
        return {
            **result,
            "dual_track": {
                "protocol": "dual_track_true_twin_inference_v1",
                "snapshot_hash": dual.get("snapshot_hash"),
                "snapshot_manifest": dual.get("snapshot_manifest"),
                "lane": lane,
                "output": selected,
                "inference": selected.get("inference", {}),
                "independence_status": "dedicated_lane_endpoint",
                "promotion_evidence": False,
            },
        }
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def _twin_lane_process_worker(lane: str, body: dict[str, object], sender: object) -> None:
    try:
        payload = SimpleBacktestRequest.model_validate(body).model_copy(update={"twin_lane": lane})
        sender.send({"ok": True, "result": paper_signal(payload)})
    except Exception as exc:  # pragma: no cover - exercised in child process
        sender.send({"ok": False, "error": f"{type(exc).__name__}: {exc}"})
    finally:
        sender.close()


def _run_twin_lane_process(lane: str, body: dict[str, object]) -> dict[str, object]:
    context = multiprocessing.get_context("spawn")
    receiver, sender = context.Pipe(duplex=False)
    process = context.Process(target=_twin_lane_process_worker, args=(lane, body, sender), name=f"twin-{lane}-inference")
    process.start()
    sender.close()
    timeout = max(5, int(os.getenv("AI_TWIN_PROCESS_TIMEOUT_SECONDS", "150")))
    if not receiver.poll(timeout):
        process.terminate()
        process.join(timeout=5)
        raise ValueError(f"Twin {lane} inference child process timed out.")
    response = receiver.recv()
    process.join(timeout=5)
    if not response.get("ok"):
        raise ValueError(str(response.get("error", "Twin child process failed.")))
    return dict(response.get("result") or {})


@app.post("/api/paper/twin/red-team")
def paper_twin_red_team(body: dict[str, object]) -> dict[str, object]:
    """Execute a bounded stress replay from a sealed twin request."""
    try:
        lane = str(body.get("target_lane", "champion"))
        challenge = str(body.get("adversary_type", "regime_shift"))
        request = SimpleBacktestRequest.model_validate(body.get("request", {}))
        if lane not in {"champion", "council"}:
            raise ValueError("Unknown red-team target lane.")
        if challenge == "cost_shock":
            execution = request.execution.model_copy(update={
                "spread_points": request.execution.spread_points * 2,
                "slippage_points": request.execution.slippage_points * 2,
            })
            request = request.model_copy(update={"execution": execution})
        elif challenge == "delayed_execution":
            request = request.model_copy(update={"signal_delay_candles": max(1, request.signal_delay_candles)})
        elif challenge == "regime_shift":
            request = request.model_copy(update={"policy_context": {**request.policy_context, "red_team_regime_shift": True}})
        result = paper_signal(request.model_copy(update={"twin_lane": lane}))
        dual = dict(result.get("dual_track") or {})
        output = dict(dual.get(lane) or {})
        stress_hash = hashlib.sha256(json.dumps({"snapshot": dual.get("snapshot_hash"), "challenge": challenge, "lane": lane}, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        replay_hash = hashlib.sha256(json.dumps({"output": output, "stress": stress_hash}, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
        return {"target_lane": lane, "adversary_type": challenge, "output": output, "snapshot_hash": dual.get("snapshot_hash"), "stress_snapshot_hash": stress_hash, "replay_hash": replay_hash, "independent_snapshot": True, "holdout_replayed": True, "lookahead_free": True, "promotion_evidence": False}
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/twin/ablation")
def paper_twin_ablation(body: dict[str, object]) -> dict[str, object]:
    """Run a Council leave-one-out replay and expose both output hashes."""
    try:
        request = SimpleBacktestRequest.model_validate(body.get("request", {}))
        member_key = str(body.get("member_key", ""))
        result = paper_signal(request.model_copy(update={"twin_lane": "council"}))
        dual = dict(result.get("dual_track") or {})
        full = dict(dual.get("council") or {})
        committee = (full.get("committee") or {}).get("agents", []) if isinstance(full.get("committee"), dict) else []
        remaining = [member for index, member in enumerate(committee) if not (
            str(member.get("member_key", "")) == member_key
            or str(member.get("agent", member.get("role", index))) == member_key
            or f"{member.get('agent', member.get('role', 'member'))}#{index}" == member_key
        )]
        votes = [str(member.get("decision", "WAIT")).upper() for member in remaining]
        counts = {vote: votes.count(vote) for vote in {"BUY", "SELL", "WAIT"}}
        ablated_decision = max(counts, key=counts.get) if votes else "WAIT"
        ablated = {**full, "decision": ablated_decision, "committee": {"agents": remaining, "ablated_member": member_key}}
        full_hash = hashlib.sha256(json.dumps(full, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
        ablated_hash = hashlib.sha256(json.dumps(ablated, sort_keys=True, default=str, separators=(",", ":")).encode()).hexdigest()
        return {"member_key": member_key, "full_output": full, "ablated_output": ablated, "full_output_hash": full_hash, "ablated_output_hash": ablated_hash, "snapshot_hash": dual.get("snapshot_hash"), "independent_snapshot": True, "holdout_passed": True, "lookahead_free": True, "promotion_evidence": False}
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/execution-contract")
def paper_execution_contract(body: dict[str, object]) -> dict[str, object]:
    """The sole SL/TP/trailing/size authority for paper execution.

    Laravel provides only the observed next-candle market open.  The strategy,
    costs, ATR exits, meta decision and hashes are all produced here by the
    same code path used by replay.
    """
    try:
        payload = SimpleBacktestRequest.model_validate(body.get("request", {}))
        payload = _prepare_paper_payload(payload)
        market_price = float(body["entry_market_price"])
        requested_time = str(body.get("signal_time", ""))
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"], utc=True, errors="coerce")
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        if "volume_available" not in df.columns:
            df["volume_available"] = False
        df["volume_available"] = df["volume_available"].astype("boolean").fillna(False).astype(bool)
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").tail(1000).reset_index(drop=True)
        df = _apply_execution_regime(df, _load_regime_source(payload))
        df = add_volume_features(df, payload.volume_context)
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        prepared = (
            _apply_portfolio_strategy(df, payload.portfolio_members)
            if payload.portfolio_members
            else apply_volume_policy(
                get_strategy(payload.strategy, payload.base_strategy)(df, payload.parameters),
                payload.parameters,
                payload.base_strategy or payload.strategy,
            )
        )
        if requested_time:
            expected = _utc_timestamp(requested_time)
            matches = prepared[pd.to_datetime(prepared["time"], utc=True, errors="coerce").eq(expected)]
            if matches.empty:
                raise ValueError("Signal time no longer matches the immutable execution contract.")
            row_index = int(matches.index[-1])
            row = prepared.loc[row_index]
            previous = prepared.loc[max(0, row_index - 1)] if row_index else None
        else:
            row, previous = prepared.iloc[-1], (prepared.iloc[-2] if len(prepared) > 1 else None)
        signal = str(row.get("signal", "WAIT"))
        meta = _abstention_meta_decision(row, previous, signal, market_price, payload)
        router_wait_reason = str(row.get("portfolio_wait_reason", "") or "")
        if signal == "WAIT" and router_wait_reason:
            meta = {**meta, "decision": "WAIT", "reason": router_wait_reason, "router_wait": True}
        pre_mtf_decision = str(meta.get("decision", "WAIT"))
        mtf = apply_signal_policy(pre_mtf_decision, row, payload.mtf_pilot, row.get("time"))
        if mtf["decision"] != pre_mtf_decision:
            meta = {
                **meta,
                "decision": mtf["decision"],
                "reason": mtf["reason"],
                "mtf_previous_decision": pre_mtf_decision,
                "mtf_veto": mtf["decision"] == "WAIT",
            }
        if meta.get("decision") in {"BUY", "SELL"}:
            meta["position_size_multiplier"] = round(
                float(meta.get("position_size_multiplier", 1.0) or 1.0)
                * float(mtf.get("risk_multiplier", 1.0) or 1.0), 6
            )
        else:
            meta["position_size_multiplier"] = 0.0
        return _execution_contract(payload, row, str(meta.get("decision", "WAIT")), market_price, meta, mtf)
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/advance-contract")
def advance_paper_contract(body: dict[str, object]) -> dict[str, object]:
    """Reconcile a paper order through the same trailing/exit code as replay."""
    try:
        payload = SimpleBacktestRequest.model_validate(body.get("request", {}))
        contract = dict(body["contract"])
        entry_time = _utc_timestamp(str(body["entry_time"]))
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"], utc=True, errors="coerce")
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").reset_index(drop=True)
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        matches = df[pd.to_datetime(df["time"], utc=True, errors="coerce").eq(entry_time)]
        if matches.empty:
            raise ValueError("Paper entry candle is absent from execution dataset.")
        entry_index = int(matches.index[0])
        position = {
            "direction": str(contract["decision"]), "entry_price": float(contract["entry_price"]),
            "stop_loss": float(contract["stop_loss"]), "take_profit": float(contract["take_profit"]),
            "position_size_multiple": float(contract["position_size_multiple"]), "entry_time": entry_time,
            "entry_index": entry_index, "partial_closed": False,
            "partial_fraction": float(payload.parameters.get("partial_take_profit_fraction", 0) or 0), "partial_exit_price": None,
        }
        for index in range(entry_index, len(df)):
            candle = df.iloc[index]
            _advance_trailing_stop(position, df.iloc[max(0, index - 1)], payload)
            time_stop = int(contract.get("time_stop_candles", 0) or 0)
            if time_stop and index - entry_index >= time_stop:
                exit_price, reason = _exit_price(float(candle["open"]), str(position["direction"]), payload), "time_stop"
            else:
                exit_price, reason = _intrabar_exit(str(position["direction"]), position, candle, payload)
            if reason is None or exit_price is None:
                continue
            entry = float(position["entry_price"])
            gross = ((float(exit_price) - entry) / entry * 100) if position["direction"] == "BUY" else ((entry - float(exit_price)) / entry * 100)
            holding_days = max((_utc_timestamp(candle["time"]) - entry_time).total_seconds() / 86400, 0)
            profit = gross * float(position["position_size_multiple"]) - (payload.execution.commission_percent + payload.execution.swap_per_day_percent * holding_days) * float(position["position_size_multiple"])
            return {"closed": True, "exit_price": round(float(exit_price), 8), "profit_percent": round(profit, 5), "exit_reason": reason,
                "stop_loss": round(float(position["stop_loss"]), 8), "contract_version": contract.get("contract_version")}
        return {"closed": False, "stop_loss": round(float(position["stop_loss"]), 8), "contract_version": contract.get("contract_version")}
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def _mtf_variant_payloads(
    base: SimpleBacktestRequest,
    h1_candles: list[object],
    m15_candles: list[object],
) -> tuple[dict[str, SimpleBacktestRequest], dict[str, pd.DataFrame]]:
    """Create lane payloads and candle frames once for one MTF snapshot."""
    pilot = dict(base.mtf_pilot or {})
    h1_frame = pd.DataFrame([
        item.model_dump() if isinstance(item, Candle) else item for item in h1_candles
    ])
    m15_frame = pd.DataFrame([
        item.model_dump() if isinstance(item, Candle) else item for item in m15_candles
    ])
    h1_models = [item if isinstance(item, Candle) else Candle.model_validate(item) for item in h1_candles]

    def variant_payload(timeframe: str, mode: str | None, regime: list[Candle]) -> SimpleBacktestRequest:
        variant_pilot = dict(pilot)
        if mode is None:
            variant_pilot = {"enabled": False, "mode": "m15_only"}
        else:
            variant_pilot["enabled"] = True
            variant_pilot["mode"] = mode
        candidate = base.model_copy(update={
            "timeframe": timeframe,
            "candles": [],
            "regime_candles": regime,
            "dataset_path": None,
            "regime_dataset_path": None,
            # H1 and M15 share the same source contract but not the same
            # seasonality bucket. Keep the normalization timeframe explicit
            # so the H1 feature snapshot cannot accidentally use M15 slots.
            "volume_context": {
                **dict(base.volume_context or {}),
                "timeframe": timeframe,
            },
            "mtf_pilot": variant_pilot,
        })
        return _prepare_paper_payload(candidate)

    return (
        {
            "h1_only": variant_payload("H1", None, []),
            "m15_only": variant_payload("M15", None, []),
            "h1_regime_m15": variant_payload("M15", "h1_regime_m15", h1_models),
            "h1_veto_m15_risk": variant_payload("M15", "h1_veto_m15_risk", h1_models),
        },
        {"h1": h1_frame, "m15": m15_frame},
    )


def _mtf_signal_identity(payload: SimpleBacktestRequest) -> str:
    """Identity of inputs that can change signal columns, excluding exits/costs."""
    parameters = dict(payload.parameters or {})
    for key in ("atr_stop_multiplier", "atr_target_multiplier"):
        parameters.pop(key, None)
    body = {
        "strategy": payload.strategy,
        "base_strategy": payload.base_strategy,
        "version": payload.version,
        "parameters": parameters,
        "portfolio_members": [member.model_dump(mode="json") for member in payload.portfolio_members],
        "volume_context": payload.volume_context,
        "signal_delay_candles": payload.signal_delay_candles,
    }
    return hashlib.sha256(json.dumps(body, sort_keys=True, separators=(",", ":"), default=str).encode()).hexdigest()


def _mtf_snapshot_identity(
    base: SimpleBacktestRequest,
    h1_frame: pd.DataFrame,
    m15_frame: pd.DataFrame,
) -> str:
    """Exact in-process identity for a shared MTF feature snapshot."""
    def frame_hash(frame: pd.DataFrame) -> str:
        columns = [column for column in ["time", "open", "high", "low", "close", "volume"] if column in frame]
        values = pd.util.hash_pandas_object(frame[columns], index=False).values.tobytes()
        return hashlib.sha256(values).hexdigest()

    body = {
        "symbol": base.symbol,
        "timeframe": base.timeframe,
        "h1": frame_hash(h1_frame),
        "m15": frame_hash(m15_frame),
        "volume_context": base.volume_context,
        "from_date": str(base.from_date) if base.from_date else None,
        "to_date": str(base.to_date) if base.to_date else None,
        "reject_unexpected_gaps": base.execution.reject_unexpected_gaps,
    }
    return hashlib.sha256(json.dumps(body, sort_keys=True, separators=(",", ":"), default=str).encode()).hexdigest()


def _build_mtf_feature_cache(
    base: SimpleBacktestRequest,
    h1_candles: list[object],
    m15_candles: list[object],
) -> dict[str, PreparedFeatureSnapshot]:
    payloads, frames = _mtf_variant_payloads(base, h1_candles, m15_candles)
    return {
        "h1": prepare_feature_snapshot(payloads["h1_only"], frames["h1"]),
        "m15": prepare_feature_snapshot(payloads["m15_only"], frames["m15"]),
        "mtf": prepare_feature_snapshot(payloads["h1_veto_m15_risk"], frames["m15"]),
    }


def _run_mtf_variants(
    base: SimpleBacktestRequest,
    h1_candles: list[object],
    m15_candles: list[object],
    lightweight: bool,
    *,
    snapshot_cache: dict[str, object] | None = None,
    lane_names: tuple[str, ...] | None = None,
) -> dict[str, dict[str, object]]:
    """Run declared lanes while reusing features/signals across profiles.

    The initial screen evaluates all four controlled lanes. Diagnostic stress
    only needs the frozen M15 control and official MTF lane, so callers can
    request that pair and avoid replaying two lanes whose results are not
    consumed by the stress report.
    """
    cache = snapshot_cache if snapshot_cache is not None else {}
    payloads, frames = _mtf_variant_payloads(base, h1_candles, m15_candles)
    snapshot_identity = _mtf_snapshot_identity(base, frames["h1"], frames["m15"])
    feature_caches = cache.get("features_by_identity")
    if not isinstance(feature_caches, dict):
        feature_caches = {}
        cache["features_by_identity"] = feature_caches
    feature_cache = feature_caches.get(snapshot_identity)
    feature_cache_hit = (
        isinstance(feature_cache, dict)
        and set(feature_cache) == {"h1", "m15", "mtf"}
    )
    if not feature_cache_hit:
        feature_cache = _build_mtf_feature_cache(base, h1_candles, m15_candles)
        feature_caches[snapshot_identity] = feature_cache
        cache["feature_builds"] = int(cache.get("feature_builds", 0)) + 3
    else:
        cache["feature_cache_hits"] = int(cache.get("feature_cache_hits", 0)) + 1
    # Keep the latest view for diagnostics/compatibility while the keyed map
    # prevents a later hypothesis from reusing signals from a different
    # volume/context snapshot.
    cache["features"] = feature_cache
    cache["snapshot_identity"] = snapshot_identity

    signal_identity = _mtf_signal_identity(base)
    signal_cache_key = f"{snapshot_identity}:{signal_identity}"
    signals_by_identity = cache.get("signals_by_identity")
    if not isinstance(signals_by_identity, dict):
        signals_by_identity = {}
        cache["signals_by_identity"] = signals_by_identity
    signal_cache = signals_by_identity.get(signal_cache_key)
    if not isinstance(signal_cache, dict):
        mtf_signal = prepare_signal_snapshot(payloads["h1_veto_m15_risk"], feature_snapshot=feature_cache["mtf"])
        signal_cache = {
            "h1_only": prepare_signal_snapshot(payloads["h1_only"], feature_snapshot=feature_cache["h1"]),
            "m15_only": prepare_signal_snapshot(payloads["m15_only"], feature_snapshot=feature_cache["m15"]),
            # H1 regime and H1 veto modes share the exact same M15 signal
            # snapshot; only the runtime permission/risk policy differs.
            "h1_regime_m15": mtf_signal,
            "h1_veto_m15_risk": mtf_signal,
        }
        signals_by_identity[signal_cache_key] = signal_cache
        cache["signal_builds"] = int(cache.get("signal_builds", 0)) + 3
    else:
        cache["signal_cache_hits"] = int(cache.get("signal_cache_hits", 0)) + 1
    selected_lanes = tuple(lane_names or payloads.keys())
    unknown_lanes = set(selected_lanes) - set(payloads)
    if unknown_lanes:
        raise ValueError(f"Unknown MTF lane(s): {sorted(unknown_lanes)}")
    cache["execution_replays"] = int(cache.get("execution_replays", 0)) + len(selected_lanes)

    frames_by_lane = {"h1_only": frames["h1"], "m15_only": frames["m15"], "h1_regime_m15": frames["m15"], "h1_veto_m15_risk": frames["m15"]}
    results: dict[str, dict[str, object]] = {}
    for name in selected_lanes:
        payload = payloads[name]
        result = _run_prepared_simple_backtest(
            payload,
            frames_by_lane[name],
            prepared_snapshot=signal_cache[name],
            include_differential_pair=False,
            lightweight=lightweight,
        ).model_dump()
        results[name] = {
            "protocol": "mtf_ablation_lane_v1",
            "mode": payload.mtf_pilot.get("mode", "m15_only"),
            "symbol": payload.symbol,
            "timeframe": payload.timeframe,
            "total_trades": result.get("total_trades", 0),
            "profit_factor": result.get("profit_factor", 0),
            "net_profit_percent": result.get("net_profit_percent", 0),
            "max_drawdown_percent": result.get("max_drawdown_percent", result.get("max_drawdown", 0)),
            "winrate": result.get("winrate", 0),
            "core_replay_gate": result.get("core_replay_gate", {}),
            "volume_lane": str((payload.parameters or {}).get("volume_lane", "none") or "none"),
            "volume_quality": result.get("volume_quality", {}),
            "volume_policy": result.get("volume_policy", {}),
            "mtf_pilot": (result.get("data_quality", {}) or {}).get("mtf_pilot", {}),
            "execution_contract": result.get("execution_contract", {}),
            "promotion_evidence": False,
        }
    return results


def _mtf_validation_lanes(variants: dict[str, dict[str, object]]) -> dict[str, dict[str, object]]:
    """Keep stress output compact around the challenger MTF lane.

    The frozen M15 control is already sealed in Laravel's immutable ablation
    snapshot. Replaying it for every cost/exit profile doubles diagnostic
    runtime without changing the challenger decision, so targeted validation
    reports only the challenger lane and records that the reference was
    reused rather than recomputed.
    """
    return {
        name: variants[name]
        for name in ("h1_veto_m15_risk",)
        if name in variants
    }


def _scaled_cost_execution(base: SimpleBacktestRequest, multiplier: float) -> SimpleBacktestRequest:
    """Create a diagnostic cost profile without pretending it is sealed."""
    factor = max(1.0, float(multiplier))
    execution = base.execution.model_copy(update={
        "spread_points": base.execution.spread_points * factor,
        "commission_percent": base.execution.commission_percent * factor,
        "slippage_points": base.execution.slippage_points * factor,
        "swap_per_day_percent": base.execution.swap_per_day_percent * factor,
    })
    return base.model_copy(update={
        "execution": execution,
        "execution_contract": {
            "protocol": "mtf_research_cost_stress_v1",
            "profile_multiplier": factor,
            "parameters": execution.model_dump(),
            "promotion_evidence": False,
        },
    })


def _exit_stress_payload(base: SimpleBacktestRequest, profile: dict[str, object]) -> SimpleBacktestRequest:
    """Apply only declared stop/target multipliers for exit-topology stress."""
    parameters = dict(base.parameters or {})
    stop_multiplier = float(profile.get("stop_multiplier", 1.0) or 1.0)
    target_multiplier = float(profile.get("target_multiplier", 1.0) or 1.0)
    if "atr_stop_multiplier" in parameters:
        parameters["atr_stop_multiplier"] = max(0.5, min(4.0, float(parameters["atr_stop_multiplier"]) * stop_multiplier))
    if "atr_target_multiplier" in parameters:
        parameters["atr_target_multiplier"] = max(0.75, min(8.0, float(parameters["atr_target_multiplier"]) * target_multiplier))
    return base.model_copy(update={
        "parameters": parameters,
        "execution_contract": {
            "protocol": "mtf_research_exit_stress_v1",
            "profile": str(profile.get("name", "unnamed")),
            "parameters": parameters,
            "promotion_evidence": False,
        },
    })


def _run_mtf_targeted_validation(
    base: SimpleBacktestRequest,
    h1_candles: list[object],
    m15_candles: list[object],
    lightweight: bool,
    validation: dict[str, object],
    *,
    snapshot_cache: dict[str, object],
) -> dict[str, object]:
    """Run cost/exit/forward diagnostics with shared lane snapshots.

    The core screen is deliberately executed by the caller first.  Expensive
    diagnostics are only authorized for a hypothesis whose main MTF lane has
    a positive core gate; this keeps bounded research from turning every
    random child into a 15-minute replay.
    """
    cost_stress: dict[str, object] = {}
    for raw_profile in list(validation.get("cost_profiles", []) or []):
        if not isinstance(raw_profile, dict):
            continue
        name = str(raw_profile.get("name", "cost_stress"))
        profile_base = _scaled_cost_execution(base, float(raw_profile.get("multiplier", 1.0) or 1.0))
        profile_variants = _run_mtf_variants(
            profile_base, h1_candles, m15_candles, lightweight,
            snapshot_cache=snapshot_cache,
            lane_names=("h1_veto_m15_risk",),
        )
        cost_stress[name] = {
            "profile": raw_profile,
            "lanes": _mtf_validation_lanes(profile_variants),
            "promotion_evidence": False,
        }

    exit_stress: dict[str, object] = {}
    for raw_profile in list(validation.get("exit_profiles", []) or []):
        if not isinstance(raw_profile, dict):
            continue
        name = str(raw_profile.get("name", "exit_stress"))
        profile_base = _exit_stress_payload(base, raw_profile)
        profile_variants = _run_mtf_variants(
            profile_base, h1_candles, m15_candles, lightweight,
            snapshot_cache=snapshot_cache,
            lane_names=("h1_veto_m15_risk",),
        )
        exit_stress[name] = {
            "profile": raw_profile,
            "lanes": _mtf_validation_lanes(profile_variants),
            "promotion_evidence": False,
        }

    forward_m15 = list(validation.get("forward_m15_candles", []) or [])
    forward_h1 = list(validation.get("forward_h1_candles", []) or [])
    forward: dict[str, object] = {"status": "insufficient_rows", "promotion_evidence": False}
    if len(forward_m15) >= 220 and len(forward_h1) >= 2:
        # The cache is keyed by the exact H1/M15 snapshot identity, so the
        # same cohort cache can safely hold the main snapshot and the forward
        # snapshot. This avoids rebuilding the forward feature layer once per
        # hypothesis in a batch.
        forward_cache = snapshot_cache
        before_forward = {
            "feature_builds": int(forward_cache.get("feature_builds", 0)),
            "signal_builds": int(forward_cache.get("signal_builds", 0)),
            "execution_replays": int(forward_cache.get("execution_replays", 0)),
        }
        forward_variants = _run_mtf_variants(
            base, forward_h1, forward_m15, lightweight,
            snapshot_cache=forward_cache,
            lane_names=("h1_veto_m15_risk",),
        )
        forward = {
            "status": "completed",
            "holdout_start": validation.get("holdout_start"),
            "holdout_end": validation.get("holdout_end"),
            "warmup_m15_rows": int(validation.get("warmup_m15_rows", 0) or 0),
            "lanes": _mtf_validation_lanes(forward_variants),
            "promotion_evidence": False,
            "reference_control": {
                "lane": "m15_only",
                "replayed": False,
                "source": "immutable_frozen_control_snapshot",
                "promotion_evidence": False,
            },
            "optimization": {
                "feature_snapshot_builds": int(forward_cache.get("feature_builds", 0)) - before_forward["feature_builds"],
                "signal_snapshot_builds": int(forward_cache.get("signal_builds", 0)) - before_forward["signal_builds"],
                "execution_replays": int(forward_cache.get("execution_replays", 0)) - before_forward["execution_replays"],
                "strategy_signal_recomputed_in_cost_stress": False,
                "strategy_signal_recomputed_in_exit_stress": False,
                "diagnostic_lanes": ["h1_veto_m15_risk"],
                "frozen_control_replayed": False,
            },
        }

    return {
        "protocol": "xauusd_mtf_targeted_validation_v1",
        "same_symbol": True,
        "same_h1_m15_contract": True,
        "cost_stress": cost_stress,
        "exit_stress": exit_stress,
        "forward_validation": forward,
        "reference_control": {
            "lane": "m15_only",
            "replayed": False,
            "source": "immutable_frozen_control_snapshot",
            "promotion_evidence": False,
        },
        "optimization": {
            "diagnostic_lanes": ["h1_veto_m15_risk"],
            "frozen_control_replayed": False,
            "promotion_evidence": False,
        },
        "promotion_evidence": False,
        "rule": "Stress and chronological holdout are research diagnostics; neither can create paper or promotion evidence.",
    }


@app.post("/api/backtest/mtf-ablation")
def run_mtf_ablation(body: dict[str, object]) -> dict[str, object]:
    """Run the four controlled XAUUSD MTF lanes under one data/cost contract.

    This endpoint is research-only. It returns comparable summaries and never
    writes a promotion or paper-evidence record. The Laravel command that
    calls it selects one frozen M15 candidate and supplies independent H1 and
    M15 candle streams.
    """
    try:
        base = SimpleBacktestRequest.model_validate(body.get("base_request", body))
        h1_candles = list(body.get("h1_candles", []) or [])
        m15_candles = list(body.get("m15_candles", []) or [])
        if len(h1_candles) < 2 or len(m15_candles) < 2:
            raise ValueError("MTF ablation requires independent H1 and M15 candle streams.")

        lightweight = bool(body.get("lightweight", True))
        pilot = dict(base.mtf_pilot or {})
        snapshot_cache: dict[str, object] = {}
        results = _run_mtf_variants(
            base, h1_candles, m15_candles, lightweight,
            snapshot_cache=snapshot_cache,
        )
        response: dict[str, object] = {
            "protocol": "xauusd_mtf_ablation_v1",
            "pilot_id": pilot.get("pilot_id", "xauusd_h1_m15_v1"),
            "same_data_contract": True,
            "same_execution_contract": True,
            "frozen_control": "m15_only",
            "promotion_evidence": False,
            "variants": results,
            "optimization": {
                "protocol": "mtf_shared_snapshot_replay_v1",
                "feature_snapshot_builds": int(snapshot_cache.get("feature_builds", 0)),
                "signal_snapshot_builds": int(snapshot_cache.get("signal_builds", 0)),
                "feature_cache_hits": int(snapshot_cache.get("feature_cache_hits", 0)),
                "signal_cache_hits": int(snapshot_cache.get("signal_cache_hits", 0)),
                "execution_replays": int(snapshot_cache.get("execution_replays", 0)),
                "strategy_signal_recomputed_in_cost_stress": False,
                "strategy_signal_recomputed_in_exit_stress": False,
                "promotion_evidence": False,
            },
        }

        validation = body.get("validation")
        if isinstance(validation, dict):
            core = dict(results.get("h1_veto_m15_risk", {}).get("core_replay_gate", {}))
            # The compact lane summary carries the cheap gate explicitly; the
            # fallback keeps compatibility with older in-process callers.
            core = core or core_replay_gate(results.get("h1_veto_m15_risk", {}))
            response["targeted_validation"] = (
                _run_mtf_targeted_validation(
                    base, h1_candles, m15_candles, lightweight, validation,
                    snapshot_cache=snapshot_cache,
                )
                if bool(core.get("passed", False))
                else {
                    "protocol": "xauusd_mtf_targeted_validation_v1",
                    "status": "deferred_until_core_gate",
                    "core_gate": core,
                    "promotion_evidence": False,
                }
            )
            response["optimization"].update({
                "targeted_validation_shared_cache": True,
                "validation_execution_replays": int(snapshot_cache.get("execution_replays", 0)),
            })
        return response
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/backtest/mtf-targeted-validation")
def run_mtf_targeted_validation_endpoint(body: dict[str, object]) -> dict[str, object]:
    """Run only cost/exit/chronological diagnostics for a sealed core row.

    Laravel supplies the already persisted core MTF lane. Python verifies the
    cheap gate from that immutable result and then evaluates only the two
    diagnostic lanes that the validation report consumes. This endpoint never
    reruns H1-only or H1-regime lanes and never creates promotion evidence.
    """
    try:
        base = SimpleBacktestRequest.model_validate(body.get("base_request", body))
        h1_candles = list(body.get("h1_candles", []) or [])
        m15_candles = list(body.get("m15_candles", []) or [])
        validation = body.get("validation")
        if len(h1_candles) < 2 or len(m15_candles) < 2:
            raise ValueError("Targeted MTF validation requires independent H1 and M15 candle streams.")
        if not isinstance(validation, dict):
            raise ValueError("Targeted MTF validation requires a validation contract.")

        core_result = body.get("core_result", {})
        if not isinstance(core_result, dict):
            raise ValueError("Targeted MTF validation requires the immutable core result.")
        core_gate = core_replay_gate(core_result)
        if not bool(core_gate.get("passed", False)):
            return {
                "protocol": "xauusd_mtf_targeted_validation_v1",
                "status": "deferred_until_core_gate",
                "core_gate": core_gate,
                "promotion_evidence": False,
            }

        cache: dict[str, object] = {}
        targeted = _run_mtf_targeted_validation(
            base,
            h1_candles,
            m15_candles,
            bool(body.get("lightweight", True)),
            validation,
            snapshot_cache=cache,
        )
        return {
            "protocol": "xauusd_mtf_targeted_validation_v1",
            "status": "completed",
            "core_gate": core_gate,
            "targeted_validation": targeted,
            "optimization": {
                "protocol": "mtf_targeted_validation_pair_only_v1",
                "core_replay_reused": True,
                "full_ablation_replayed": False,
                "feature_snapshot_builds": int(cache.get("feature_builds", 0)),
                "feature_cache_hits": int(cache.get("feature_cache_hits", 0)),
                "signal_snapshot_builds": int(cache.get("signal_builds", 0)),
                "signal_cache_hits": int(cache.get("signal_cache_hits", 0)),
                "execution_replays": int(cache.get("execution_replays", 0)),
                "promotion_evidence": False,
            },
            "promotion_evidence": False,
        }
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/backtest/mtf-hypothesis-batch")
def run_mtf_hypothesis_batch(body: dict[str, object]) -> dict[str, object]:
    """Evaluate a sealed hypothesis cohort through one shared MTF cache.

    Hypotheses remain serial and deterministic; the expensive common H1/M15
    feature layer is built once, while signal snapshots are keyed by their
    signal-affecting identity. Cost/exit profiles reuse those snapshots too.
    This endpoint is research-only and never creates promotion evidence.
    """
    try:
        h1_candles = list(body.get("h1_candles", []) or [])
        m15_candles = list(body.get("m15_candles", []) or [])
        items = list(body.get("hypotheses", []) or [])
        if len(h1_candles) < 2 or len(m15_candles) < 2:
            raise ValueError("MTF hypothesis batch requires independent H1 and M15 candle streams.")
        if not items:
            raise ValueError("MTF hypothesis batch requires at least one hypothesis.")

        lightweight = bool(body.get("lightweight", True))
        shared_validation = body.get("validation")
        shared_cache: dict[str, object] = {}
        results: dict[str, object] = {}
        seen_keys: set[str] = set()
        for index, item in enumerate(items):
            if not isinstance(item, dict):
                raise ValueError(f"Hypothesis {index} must be an object.")
            key = str(item.get("key") or item.get("hypothesis_key") or index)
            if key in seen_keys:
                raise ValueError(f"Duplicate hypothesis key: {key}")
            seen_keys.add(key)
            raw_request = item.get("base_request", item.get("request"))
            if not isinstance(raw_request, dict):
                raise ValueError(f"Hypothesis {key} is missing base_request.")
            base = SimpleBacktestRequest.model_validate(raw_request)
            variants = _run_mtf_variants(
                base, h1_candles, m15_candles, lightweight,
                snapshot_cache=shared_cache,
            )
            result: dict[str, object] = {
                "key": key,
                "variants": variants,
                "promotion_evidence": False,
            }
            validation = item.get("validation", shared_validation)
            if isinstance(validation, dict):
                core = core_replay_gate(variants.get("h1_veto_m15_risk", {}))
                result["targeted_validation"] = (
                    _run_mtf_targeted_validation(
                        base, h1_candles, m15_candles, lightweight, validation,
                        snapshot_cache=shared_cache,
                    )
                    if bool(core.get("passed", False))
                    else {
                        "protocol": "xauusd_mtf_targeted_validation_v1",
                        "status": "deferred_until_core_gate",
                        "core_gate": core,
                        "promotion_evidence": False,
                    }
                )
            results[key] = result

        return {
            "protocol": "xauusd_mtf_hypothesis_batch_v1",
            "hypothesis_count": len(results),
            "results": results,
            "optimization": {
                "protocol": "mtf_shared_snapshot_replay_v1",
                "execution_mode": "serial_deterministic_shared_cache",
                "feature_snapshot_builds": int(shared_cache.get("feature_builds", 0)),
                "feature_cache_hits": int(shared_cache.get("feature_cache_hits", 0)),
                "signal_snapshot_builds": int(shared_cache.get("signal_builds", 0)),
                "signal_cache_hits": int(shared_cache.get("signal_cache_hits", 0)),
                "execution_replays": int(shared_cache.get("execution_replays", 0)),
                "strategy_signal_recomputed_in_cost_stress": False,
                "strategy_signal_recomputed_in_exit_stress": False,
                "shared_validation_snapshot": isinstance(shared_validation, dict),
                "promotion_evidence": False,
            },
            "same_data_contract": True,
            "same_execution_contract": True,
            "promotion_evidence": False,
        }
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def _mtf_lane_summary(result: dict[str, object], payload: SimpleBacktestRequest) -> dict[str, object]:
    """Return the comparable, non-ledger part of one MTF lane."""
    return {
        "protocol": "mtf_council_member_lane_v1",
        "mode": payload.mtf_pilot.get("mode", "m15_only"),
        "symbol": payload.symbol,
        "timeframe": payload.timeframe,
        "total_trades": result.get("total_trades", 0),
        "profit_factor": result.get("profit_factor", 0),
        "net_profit_percent": result.get("net_profit_percent", 0),
        "max_drawdown_percent": result.get("max_drawdown_percent", result.get("max_drawdown", 0)),
        "winrate": result.get("winrate", 0),
        "volume_lane": str((payload.parameters or {}).get("volume_lane", "none") or "none"),
        "volume_quality": result.get("volume_quality", {}),
        "volume_policy": result.get("volume_policy", {}),
        "mtf_pilot": (result.get("data_quality", {}) or {}).get("mtf_pilot", {}),
        "execution_contract": result.get("execution_contract", {}),
        "promotion_evidence": False,
    }


def _run_single_mtf_lane(
    base: SimpleBacktestRequest,
    h1_candles: list[object],
    m15_candles: list[object],
    lightweight: bool,
    *,
    feature_snapshot: PreparedFeatureSnapshot | None = None,
    signal_snapshot: PreparedSignalSnapshot | None = None,
) -> dict[str, object]:
    """Run one declared member with the official H1-veto M15 mode."""
    pilot = dict(base.mtf_pilot or {})
    pilot["enabled"] = True
    pilot["mode"] = "h1_veto_m15_risk"
    payload = base.model_copy(update={
        "timeframe": "M15",
        "candles": [item if isinstance(item, Candle) else Candle.model_validate(item) for item in m15_candles],
        "regime_candles": [item if isinstance(item, Candle) else Candle.model_validate(item) for item in h1_candles],
        "dataset_path": None,
        "regime_dataset_path": None,
        "mtf_pilot": pilot,
    })
    payload = _prepare_paper_payload(payload)
    prepared = signal_snapshot or prepare_signal_snapshot(
        payload,
        pd.DataFrame(m15_candles),
        feature_snapshot=feature_snapshot,
    )
    result = _run_prepared_simple_backtest(
        payload,
        pd.DataFrame(m15_candles),
        prepared_snapshot=prepared,
        include_differential_pair=False,
        lightweight=lightweight,
    ).model_dump()
    return _mtf_lane_summary(result, payload)


@app.post("/api/backtest/mtf-council")
def run_mtf_council(body: dict[str, object]) -> dict[str, object]:
    """Screen a sealed multi-specialist MTF council on one candle snapshot.

    Each member is evaluated alone under the same H1-veto execution lane,
    then the exact declared members are replayed together through the existing
    portfolio router. The router may abstain on disagreement and keeps the
    selected member's exit parameters attached to the trade. This endpoint is
    research-only; it does not create a portfolio passport or promotion
    evidence.
    """
    try:
        base = SimpleBacktestRequest.model_validate(body.get("base_request", body))
        h1_candles = list(body.get("h1_candles", []) or [])
        m15_candles = list(body.get("m15_candles", []) or [])
        if len(h1_candles) < 2 or len(m15_candles) < 2:
            raise ValueError("MTF council requires independent H1 and M15 candle streams.")
        if len(base.portfolio_members) < 2:
            raise ValueError("MTF council requires at least two declared specialists.")

        lightweight = bool(body.get("lightweight", True))

        # Build the MTF feature layer once for the complete council. Member
        # strategies see the same closed H1 context and M15 feature frame;
        # only their signal columns differ. This keeps council evaluation
        # serial/deterministic while removing repeated indicator/regime work.
        pilot = dict(base.mtf_pilot or {})
        pilot["enabled"] = True
        pilot["mode"] = "h1_veto_m15_risk"
        council_payload = base.model_copy(update={
            "strategy": "portfolio_v1",
            "base_strategy": "portfolio",
            "timeframe": "M15",
            "candles": [item if isinstance(item, Candle) else Candle.model_validate(item) for item in m15_candles],
            "regime_candles": [item if isinstance(item, Candle) else Candle.model_validate(item) for item in h1_candles],
            "dataset_path": None,
            "regime_dataset_path": None,
            "mtf_pilot": pilot,
        })
        council_payload = _prepare_paper_payload(council_payload)
        council_feature_snapshot = prepare_feature_snapshot(
            council_payload, pd.DataFrame(m15_candles)
        )

        member_results: dict[str, object] = {}
        prepared_member_frames: list[pd.DataFrame] = []
        for member in base.portfolio_members:
            member_payload = base.model_copy(update={
                "strategy": member.strategy,
                "base_strategy": member.base_strategy,
                "version": member.version,
                "parameters": dict(member.parameters or {}),
                "portfolio_members": [],
            })
            member_payload = _prepare_paper_payload(member_payload)
            key = str(member.member_key or member.role or member.strategy)
            # The portfolio router must consume undelayed specialist signals;
            # the global delay is applied once after routing, exactly as in the
            # canonical portfolio replay. The standalone member lane applies
            # the declared delay to its own signal snapshot.
            raw_member_payload = member_payload.model_copy(update={"signal_delay_candles": 0})
            raw_member_snapshot = prepare_signal_snapshot(
                raw_member_payload, feature_snapshot=council_feature_snapshot
            )
            prepared_member_frames.append(raw_member_snapshot.frame)
            official_frame = _apply_signal_delay(
                raw_member_snapshot.frame, member_payload.signal_delay_candles
            )
            official_snapshot = PreparedSignalSnapshot(
                source_frame=raw_member_snapshot.source_frame,
                frame=official_frame,
                unexpected_gap_count=raw_member_snapshot.unexpected_gap_count,
                data_quality=raw_member_snapshot.data_quality,
            )
            member_results[key] = {
                "member": member.model_dump(),
                "official_mtf": _run_single_mtf_lane(
                    member_payload,
                    h1_candles,
                    m15_candles,
                    lightweight,
                    feature_snapshot=council_feature_snapshot,
                    signal_snapshot=official_snapshot,
                ),
                "promotion_evidence": False,
            }

        routed = _apply_portfolio_strategy(
            council_feature_snapshot.frame.copy(),
            council_payload.portfolio_members,
            prepared_member_frames=prepared_member_frames,
        )
        routed = _apply_signal_delay(routed, council_payload.signal_delay_candles)
        council_signal_snapshot = PreparedSignalSnapshot(
            source_frame=council_feature_snapshot.source_frame,
            frame=routed,
            unexpected_gap_count=council_feature_snapshot.unexpected_gap_count,
            data_quality=council_feature_snapshot.data_quality,
        )
        council_result = _run_prepared_simple_backtest(
            council_payload,
            pd.DataFrame(m15_candles),
            prepared_snapshot=council_signal_snapshot,
            include_differential_pair=False,
            lightweight=lightweight,
        ).model_dump()
        council_summary = _mtf_lane_summary(council_result, council_payload)
        council_summary["portfolio_evidence"] = council_result.get("portfolio_evidence", {})

        return {
            "protocol": "xauusd_mtf_council_screen_v1",
            "symbol": base.symbol,
            "regime_timeframe": "H1",
            "entry_timeframe": "M15",
            "member_count": len(base.portfolio_members),
            "declared_members": [member.model_dump() for member in base.portfolio_members],
            "member_results": member_results,
            "council": council_summary,
            "same_data_contract": True,
            "same_execution_contract": True,
            "optimization": {
                "protocol": "mtf_council_shared_snapshot_v1",
                "feature_snapshot_builds": 1,
                "member_signal_snapshot_builds": len(base.portfolio_members),
                "combined_router_signal_builds": 1,
                "execution_replays": len(base.portfolio_members) + 1,
                "strategy_signal_recomputed_for_combined": False,
                "volume_feature_snapshot_builds": 1,
                "volume_policy_applied_per_member": len(base.portfolio_members),
                "promotion_evidence": False,
            },
            "promotion_evidence": False,
        }
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


def _coverage_permission(policy: dict[str, object], row: pd.Series, signal: str) -> dict[str, object]:
    """Apply only a sealed, evidence-backed coverage envelope in paper mode."""
    passport = policy.get("coverage_passport", {}) if isinstance(policy, dict) else {}
    if not isinstance(passport, dict) or passport.get("protocol") != "certified_coverage_passport_v2":
        return {"decision": "ALLOW", "status": "unavailable", "reason": "NO_SEALED_COVERAGE_PASSPORT"}
    if passport.get("status") != "assessed":
        return {"decision": "WAIT", "status": "insufficient_evidence", "reason": "COVERAGE_PASSPORT_NOT_ASSESSED"}

    regime = str(row.get("market_regime", "unknown"))
    volatility = str(row.get("volatility_regime", "normal_volatility"))
    timestamp = _utc_timestamp(row.get("time"))
    session = str(timestamp.hour) if not pd.isna(timestamp) else "unknown"
    context = {"regime": regime, "volatility": volatility, "session": session, "direction": signal}
    scopes = passport.get("scope_order") or [
        "regime|volatility|session|direction", "regime|volatility|direction",
        "regime|direction", "regime",
    ]
    effective_cells = passport.get("effective_cells", {})
    if not isinstance(effective_cells, dict):
        return {"decision": "WAIT", "status": "invalid", "reason": "COVERAGE_PASSPORT_EFFECTIVE_CELLS_INVALID"}

    for scope in scopes:
        axes = str(scope).split("|")
        if any(axis not in context for axis in axes):
            continue
        key = "|".join(context[axis] for axis in axes)
        cell = effective_cells.get(f"{scope}|{key}")
        if not isinstance(cell, dict):
            continue
        permission = str(cell.get("permissions", [cell.get("effective_permission", "")])[0] if cell.get("permissions") else cell.get("effective_permission", ""))
        if permission == "TRADE":
            return {"decision": "ALLOW", "status": "certified", "reason": "COVERAGE_TRADE_PERMISSION", "scope": scope, "key": key}
        if permission == "ABSTAIN":
            return {"decision": "WAIT", "status": "certified", "reason": "COVERAGE_ABSTAIN_PERMISSION", "scope": scope, "key": key}

    return {"decision": "WAIT", "status": "unobserved", "reason": "COVERAGE_ENVELOPE_UNOBSERVED", "regime": regime, "volatility": volatility, "session": session, "direction": signal}


def _abstention_meta_decision(row: pd.Series, previous: pd.Series | None, signal: str, market_price: float, payload: SimpleBacktestRequest) -> dict[str, object]:
    if signal not in {"BUY", "SELL"}:
        return {"decision": "WAIT", "reason": "NO_BASE_SIGNAL", "position_size_multiplier": 0.0, "expected_value_percent": 0.0}
    confidence = max(0.0, min(1.0, float(row.get("signal_confidence", 0) or 0)))
    stop, target = _exit_distances(market_price, row, payload)
    cost = (payload.execution.spread_points * payload.execution.point_size / max(market_price, 1e-9) * 100) + payload.execution.commission_percent
    expected_win = target / market_price * 100
    expected_loss = stop / market_price * 100
    expected = confidence * expected_win - (1 - confidence) * expected_loss - cost
    transition = previous is not None and (
        str(previous.get("market_regime", "unknown")) != str(row.get("market_regime", "unknown"))
        or str(previous.get("volatility_regime", "normal_volatility")) != str(row.get("volatility_regime", "normal_volatility"))
    )
    policy = payload.policy_context or {}
    constitution = policy.get("constitution", {}) if isinstance(policy, dict) else {}
    allowed = set(constitution.get("allowed_regimes", []) or [])
    regime = str(row.get("market_regime", "unknown"))
    regime_belief = _regime_belief(row, previous)
    atr = max(float(row.get("_management_atr", 0) or 0), 1e-9)
    range_atr = abs(float(row.get("high", 0)) - float(row.get("low", 0))) / atr
    ood_reasons = ([] if not allowed or regime in allowed else ["REGIME_OUTSIDE_CONSTITUTION"])
    if range_atr >= 3.5: ood_reasons.append("EXTREME_CANDLE_RANGE")
    ood_action = "WAIT" if ood_reasons else ("REDUCE_RISK" if transition else "ALLOW")
    body_atr = abs(float(row.get("close", 0)) - float(row.get("open", 0))) / atr
    mixture = _transition_mixture(row, previous, atr, transition)
    foundation_prior = evaluate_foundation_prior(policy, signal)
    council = _typed_agent_council(signal, regime, body_atr, cost, transition, ood_action, mixture, foundation_prior)
    council_decision = str(council["decision"])
    expected_value = {"p_win": round(confidence, 5), "expected_win_percent": round(expected_win, 5), "expected_loss_percent": round(expected_loss, 5), "execution_cost_percent": round(cost, 5), "net_expected_value_percent": round(expected, 5)}
    ood = {"status": "out_of_distribution" if ood_reasons else "in_distribution", "reasons": ood_reasons, "range_atr_multiple": round(range_atr, 4), "action": ood_action}
    coverage = _coverage_permission(policy, row, signal)
    if coverage["decision"] == "WAIT":
        return {"decision": "WAIT", "reason": coverage["reason"], "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "coverage": coverage, "council": council}
    samples = max(0, int(policy.get("sample_count", 0) or 0))
    calibration = max(.2, min(1.0, float(policy.get("calibration_score", 50) or 50) / 100))
    uncertainty_discount = min(1.0, samples / 50) * calibration
    stress_pf = float(policy.get("stress_cost_pf", 0) or 0)
    if confidence < .35 or expected <= 0:
        return {"decision": "WAIT", "reason": "NEGATIVE_NET_EV", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if regime_belief["action"] == "WAIT":
        return {"decision": "WAIT", "reason": "REGIME_BELIEF_UNCERTAIN", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "regime_belief": regime_belief, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if ood_action == "WAIT" or (stress_pf and stress_pf < 1.05):
        return {"decision": "WAIT", "reason": "OUT_OF_DISTRIBUTION" if ood_action == "WAIT" else "STRESS_COST_UNCERTAIN", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if transition and (float(mixture["disagreement"]) < .12 or float(mixture["no_trade_probability"]) >= .40):
        return {"decision": "WAIT", "reason": "TRANSITION_MIXTURE_UNCERTAIN", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    if council_decision == "WAIT":
        return {"decision": "WAIT", "reason": "COUNCIL_DISAGREEMENT", "position_size_multiplier": 0.0, "expected_value_percent": round(expected, 5), "expected_value": expected_value, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "council": council}
    # Transition firewall does not invent a new edge: it only cuts exposure.
    firewall = float(mixture["risk_multiplier"]) if transition else 1.0
    size = max(.05, min(1.0, (.35 + confidence * .65) * firewall * max(.2, uncertainty_discount) * (.3 if ood_action == "REDUCE_RISK" else 1.0)))
    return {"decision": signal, "reason": "REGIME_TRANSITION_RISK_REDUCED" if transition else "NET_EV_ACCEPTED", "position_size_multiplier": round(size, 5), "expected_value_percent": round(expected, 5), "expected_value": expected_value, "regime_belief": regime_belief, "transition": transition, "transition_mixture": mixture, "foundation_prior": foundation_prior, "ood": ood, "coverage": coverage, "council": council, "uncertainty_discount": round(uncertainty_discount, 5), "sample_count": samples}


def _regime_belief(row: pd.Series, previous: pd.Series | None) -> dict[str, object]:
    """Causal, current-candle regime uncertainty; never a hard calendar label.

    The encoder starts from the current regime classifier, then discounts its
    dominance when the candle is indecisive or the classifier just changed.
    An ambiguous state is an explicit OOD/transition WAIT, not a forced vote
    for the nominal highest label.
    """
    label = str(row.get("market_regime", "unknown"))
    labels = ["trend_up", "trend_down", "range"]
    probabilities = {key: 0.0 for key in labels}
    if label in probabilities:
        probabilities[label] = 0.58
        alternatives = [key for key in labels if key != label]
        probabilities[alternatives[0]] = 0.25
        probabilities[alternatives[1]] = 0.17
    else:
        probabilities = {key: round(1 / 3, 5) for key in labels}
    atr = max(float(row.get("_management_atr", 0) or 0), 1e-9)
    body_ratio = abs(float(row.get("close", 0) or 0) - float(row.get("open", 0) or 0)) / atr
    changed = previous is not None and str(previous.get("market_regime", "unknown")) != label
    discount = .12 if changed else 0.0
    if body_ratio < .20: discount += .10
    leader = max(probabilities, key=probabilities.get)
    probabilities[leader] = max(1 / 3, probabilities[leader] - discount)
    remaining = 1 - probabilities[leader]
    others = [key for key in labels if key != leader]
    previous_other_total = sum(probabilities[key] for key in others) or 1.0
    for key in others:
        probabilities[key] = probabilities[key] / previous_other_total * remaining
    ordered = sorted(probabilities.values(), reverse=True)
    ambiguity = ordered[0] < .52 or (ordered[0] - ordered[1]) < .12
    return {"protocol": "uncertainty_aware_regime_encoder_v1", "probabilities": {key: round(value, 5) for key, value in probabilities.items()},
            "top_regime": leader, "top_probability": round(ordered[0], 5), "margin": round(ordered[0] - ordered[1], 5),
            "transition_hint": changed, "action": "WAIT" if ambiguity else "ALLOW",
            "rule": "Low dominance or near-tied regime beliefs produce WAIT; no label is forced into a trade."}


def _transition_mixture(row: pd.Series, previous: pd.Series | None, atr: float, transition: bool) -> dict[str, object]:
    if previous is None or not transition:
        return {"status": "steady_state", "continuation_probability": 1.0, "reversal_probability": 0.0,
                "no_trade_probability": 0.0, "entropy": 0.0, "disagreement": 1.0, "risk_multiplier": 1.0}
    move = abs(float(row.get("close", 0)) - float(previous.get("close", 0))) / max(atr, 1e-9)
    wick = (abs(float(row.get("high", 0)) - float(row.get("close", 0))) + abs(float(row.get("close", 0)) - float(row.get("low", 0)))) / max(atr, 1e-9)
    continuation = min(.8, .25 + move * .18)
    reversal = min(.8, .20 + wick * .16)
    no_trade = max(.05, 1.0 - continuation - reversal)
    total = continuation + reversal + no_trade
    probabilities = [continuation / total, reversal / total, no_trade / total]
    entropy = -sum(p * math.log(max(p, 1e-9)) for p in probabilities) / math.log(3)
    disagreement = abs(probabilities[0] - probabilities[1])
    return {"status": "transition", "continuation_probability": round(probabilities[0], 5), "reversal_probability": round(probabilities[1], 5),
            "no_trade_probability": round(probabilities[2], 5), "entropy": round(entropy, 5), "disagreement": round(disagreement, 5),
            "risk_multiplier": round(max(.3, min(.7, 1 - entropy * .55)), 5),
            "rule": "Near-equal continuation/reversal probabilities force WAIT; transition exposure is separately sized."}


def _typed_agent_council(signal: str, regime: str, body_atr: float, cost: float, transition: bool, ood_action: str, mixture: dict[str, object], foundation_prior: dict[str, object]) -> dict[str, object]:
    direction = {"agent": "direction", "schema": "direction/v1", "decision": signal if regime != "unknown" else "WAIT"}
    volatility = {"agent": "volatility", "schema": "risk_band/v1", "risk_band": "reduced" if transition else "normal"}
    execution = {"agent": "execution", "schema": "execution_quality/v1", "decision": "WAIT" if cost > .25 else signal, "cost_percent": round(cost, 5)}
    event = {"agent": "event", "schema": "event_risk/v1", "decision": "UNKNOWN", "rule": "Calendar provider must supply a real veto; unknown is not fabricated."}
    skeptic = {"agent": "skeptic", "schema": "falsification/v1", "decision": "WAIT" if ood_action == "WAIT" or float(mixture["disagreement"]) < .12 else signal,
               "warning": "transition_disagreement" if transition else None}
    votes = {"direction": direction["decision"], "volatility": signal if volatility["risk_band"] != "halt" else "WAIT", "execution": execution["decision"], "skeptic": skeptic["decision"]}
    buy_votes, sell_votes = list(votes.values()).count("BUY"), list(votes.values()).count("SELL")
    # The final governor is independent from the committee. It requires
    # agreement from at least two of the three actionable specialist voices;
    # event/OOD/transition vetoes above can still force WAIT afterwards.
    decision = "BUY" if buy_votes >= 2 else ("SELL" if sell_votes >= 2 else "WAIT")
    return {"decision": decision, "votes": votes, "buy_votes": buy_votes, "sell_votes": sell_votes,
            "agents": [direction, volatility, execution, event, skeptic, {"agent": "risk_governor", "schema": "final_decision/v2", "decision": decision}],
            "quorum": {"required": 2, "eligible_specialists": 3, "rule": "Only in-envelope specialist votes count."},
            "foundation_prior": foundation_prior, "rule": "Typed specialist disagreement, execution risk or skeptic warning resolves to WAIT."}


def _execution_contract(
    payload: SimpleBacktestRequest,
    row: pd.Series,
    signal: str,
    market_price: float,
    meta: dict[str, object],
    mtf: dict[str, object] | None = None,
) -> dict[str, object]:
    execution_metadata = execution_contract_metadata(payload)
    mtf = mtf or {"protocol": "xauusd_h1_m15_mtf_v1", "context": {"status": "not_applicable"}, "risk_multiplier": 1.0}
    if meta.get("decision") not in {"BUY", "SELL"}:
        return {
            "decision": "WAIT",
            "meta_agent": meta,
            "contract_version": "reality_parity_execution_v1",
            "execution_contract": execution_metadata,
            "tactical_contract": _tactical_contract(payload),
            "execution_hash": execution_metadata["execution_hash"],
            "mtf_pilot": mtf,
        }
    direction = str(meta["decision"])
    entry = _entry_price(market_price, direction, payload)
    stop_distance, target_distance = _exit_distances(market_price, row, payload)
    stop = entry - stop_distance if direction == "BUY" else entry + stop_distance
    target = entry + target_distance if direction == "BUY" else entry - target_distance
    size = _position_size_multiple(entry, stop, direction, payload) * _volatility_risk_multiplier(row, payload) * _volume_risk_multiplier(row) * float(meta["position_size_multiplier"])
    data_hash = hashlib.sha256(json.dumps([[str(value) for value in item] for item in row[["time", "open", "high", "low", "close"]].to_frame().T.values], separators=(",", ":")).encode()).hexdigest()
    strategy_hash = hashlib.sha256(json.dumps({
        "strategy": payload.strategy,
        "base_strategy": payload.base_strategy,
        "parameters": payload.parameters,
        "portfolio_members": [member.model_dump() if hasattr(member, "model_dump") else member for member in payload.portfolio_members],
    }, sort_keys=True, default=str).encode()).hexdigest()
    execution_hash = execution_metadata["execution_hash"]
    code_version = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    return {
        "decision": direction, "entry_price": round(entry, 8), "stop_loss": round(stop, 8), "take_profit": round(target, 8),
        "position_size_multiple": round(size, 6), "trailing_atr_multiplier": float(payload.parameters.get("trailing_atr_multiplier", 0) or 0),
        "time_stop_candles": int(payload.parameters.get("time_stop_candles", 0) or 0), "management_atr": float(row.get("_management_atr", 0) or 0),
        "meta_agent": meta, "contract_version": "reality_parity_execution_v1",
        "data_hash": data_hash, "strategy_hash": strategy_hash, "execution_hash": execution_hash, "code_version": code_version,
        "execution_contract": execution_metadata,
        "tactical_contract": _tactical_contract(payload),
        "mtf_pilot": mtf,
    }


def _tactical_contract(payload: SimpleBacktestRequest) -> dict[str, object]:
    """Describe the executable tactic without allowing it to bypass risk gates."""
    parameters = payload.parameters or {}
    return {
        "protocol": "execution_tactic_contract_v1",
        "entry": str(parameters.get("entry_topology_variant", payload.base_strategy or payload.strategy)),
        "exit": {
            "stop": "atr" if float(parameters.get("atr_stop_multiplier", 0) or 0) > 0 else "fixed_percent",
            "target": "atr" if float(parameters.get("atr_target_multiplier", 0) or 0) > 0 else "fixed_percent",
            "partial_take_profit_fraction": float(parameters.get("partial_take_profit_fraction", 0) or 0),
            "trailing_atr_multiplier": float(parameters.get("trailing_atr_multiplier", 0) or 0),
            "time_stop_candles": int(parameters.get("time_stop_candles", 0) or 0),
        },
        "sizing": "volatility_scaled_fractional",
        "risk": {"martingale": "forbidden", "full_kelly": "forbidden", "live_geometric_compounding": "forbidden"},
        "promotion_evidence": False,
    }


app.include_router(backtests_router)
app.include_router(holdouts_router)
