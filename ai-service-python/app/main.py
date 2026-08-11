import hmac
import os
import math
import hashlib
import json
import subprocess
import sys
import tempfile
import time
from threading import Lock
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
import pandas as pd

from app.routers.backtests import router as backtests_router
from app.schemas import SimpleBacktestRequest, SimpleBacktestResponse
from app.services.backtester import (
    _advance_trailing_stop, _apply_execution_regime, _entry_price, _exit_distances, _exit_price, _intrabar_exit, _load_regime_source,
    _load_simple_candles, _position_size_multiple, _resolve_dataset_path, _volatility_risk_multiplier, _volume_risk_multiplier,
    run_simple_ema_rsi_backtest, run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.parameter_schema import validate_strategy_parameters
from app.services.walk_forward import WalkForwardService
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.services.execution_contract import enforce_policy_boundary, execution_contract_metadata
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

app = FastAPI(
    title="NeuroTrader Lab AI Service",
    description="Strategy and backtest service for the NeuroTrader Lab MVP.",
    version="0.1.0",
)

_replay_state_lock = Lock()
_replay_lane_lock = Lock()
_active_replay_count = 0
_last_replay_started_at: str | None = None
_last_replay_finished_at: str | None = None
_last_replay_termination: str | None = None


def _load_foundation_candles(payload: SimpleBacktestRequest) -> pd.DataFrame | None:
    """Load the separately sealed foundation archive for full evidence."""
    if not payload.foundation_dataset_path:
        return None
    return pd.read_csv(_resolve_dataset_path(payload.foundation_dataset_path))


def _candidate_cache_payload(
    cohort_payload: SimpleBacktestRequest,
    strategy_payload: SimpleBacktestRequest,
    strategy_name: str,
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
        candidate_contract = repair_contracts.get(strategy_name)
        candidate_policy["repair_contracts"] = (
            {strategy_name: candidate_contract}
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
            _last_replay_started_at = pd.Timestamp.utcnow().isoformat()
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
def health() -> dict[str, str]:
    return {"status": "ok", "service": "neurotrader-ai-service"}


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
    """Expose the single evaluator lane's liveness to safe mutex recovery."""
    with _replay_state_lock:
        return {
            "active_requests": _active_replay_count,
            "last_replay_started_at": _last_replay_started_at,
            "last_replay_finished_at": _last_replay_finished_at,
            "last_replay_termination": _last_replay_termination,
            "service_pid": os.getpid(),
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

    try:
        # Do not load the large sealed archives until a candidate-cache miss
        # proves that core execution is actually needed. A fully cached
        # cohort can rebuild its current statistical envelope without
        # allocating the replay dataset or foundation archive.
        source_df = None
        foundation_df = None
        walk_forward = WalkForwardService()

        for config in strategy_configs:
            if hasattr(config, "model_dump"):
                config = config.model_dump()

            strategy_name = config["strategy"]
            is_portfolio_config = bool(payload.portfolio_members) and (
                strategy_name == "portfolio_v1" or config.get("base_strategy") == "portfolio"
            )
            parameters = dict(config.get("parameters") or {}) if is_portfolio_config else validate_strategy_parameters(
                strategy_name,
                config.get("parameters") or {},
                config.get("base_strategy"),
            )
            strategy_payload = payload.model_copy(update={
                "strategy": strategy_name,
                "base_strategy": config.get("base_strategy"),
                "version": config.get("version"),
                "parameters": parameters,
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
            candidate_payload = _candidate_cache_payload(payload, strategy_payload, strategy_name)
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
                continue
            if source_df is None:
                source_df = _load_simple_candles(payload)
                foundation_df = _load_foundation_candles(payload) if payload.evaluation_mode == "replay" else None
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
                opportunity_payload = strategy_payload
                if len(ordered) >= 5000 and "differential_router" in str(
                    strategy_payload.base_strategy or strategy_payload.strategy
                ).lower():
                    opportunity_payload = strategy_payload.model_copy(update={
                        "parameters": {
                            **strategy_payload.parameters,
                            "differential_pair_replay_enabled": False,
                        },
                    })
                opportunity_result = run_simple_ema_rsi_backtest_on_dataframe(
                    opportunity_payload,
                    opportunity_df,
                    include_differential_pair=False,
                    lightweight=True,
                ).model_dump()
                # Tier 2 is the only screen allowed to make a survival claim.
                # An archive shorter than 5k is an evidence gap, not failure.
                if len(ordered) >= 5000:
                    survival_df = ordered.tail(5000).reset_index(drop=True)
                    # Screening is a routing/falsification stage.  Keep the
                    # same core execution, costs and full-ledger attribution,
                    # but defer Monte Carlo/DNA/promotion-only diagnostics to
                    # full validation.  This cannot create promotion or
                    # paper evidence and prevents one pathological specialist
                    # from monopolizing the screening queue.
                    incremental_result = run_simple_ema_rsi_backtest_on_dataframe(
                        strategy_payload, survival_df, lightweight=True
                    ).model_dump()
                    admission = _screening_robustness_admission(incremental_result)
                    survival = (
                        MarketAdaptiveReplayService().screening_survival_profile(
                            strategy_payload, survival_df, incremental_result, calculate_strategy_score
                        )
                        if admission["passed"]
                        else _screening_insufficient_robustness_profile(incremental_result, admission)
                    )
                    if "cost_profile" in survival:
                        incremental_result["pf_attribution"] = survival["cost_profile"]
                else:
                    incremental_result = opportunity_result
                    survival = {
                        "protocol": "screening_survival_v2", "status": "insufficient_evidence",
                        "required_candles": 5000, "available_candles": len(ordered), "reason_codes": ["INSUFFICIENT_SCREENING_EVIDENCE"],
                        "promotion_evidence": False,
                        "rule": "Short opportunity screening may route research, but cannot make a survival or harmful mutation claim.",
                    }
                incremental_result["screening_opportunity"] = {
                    "protocol": "screening_opportunity_v1", "candles": len(opportunity_df),
                    "total_trades": opportunity_result.get("total_trades", 0),
                    "entry_funnel": opportunity_result.get("entry_funnel", {}),
                    "opportunity_metrics": opportunity_result.get("opportunity_metrics", {}),
                    "promotion_evidence": False,
                }
                incremental_result["screening_survival"] = survival
                incremental_result["evaluation_mode"] = "incremental_two_tier"
                incremental_score = calculate_strategy_score(incremental_result)
                analysis = {
                    "train_score": incremental_score, "validation_score": incremental_score,
                    "forward_score": incremental_score, "forward_window_scores": [],
                    "rolling_windows_count": 0, "robustness_score": 0, "is_overfit": False,
                    "result": {**incremental_result, "evaluation_mode": "incremental"},
                }
            elif payload.evaluation_mode == "replay":
                analysis = MarketAdaptiveReplayService().run(
                    strategy_payload, source_df, calculate_strategy_score, foundation_df
                )
            else:
                analysis = walk_forward.run(strategy_payload, source_df, calculate_strategy_score)
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
            _store_immutable_replay_cache(candidate_cache_key, {
                "protocol": "candidate_replay_cache_v1",
                "strategy": strategy_name,
                "item": candidate_item,
            })
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

    leaderboard = sorted(leaderboard, key=lambda item: item["score"], reverse=True)

    return {
        "symbol": payload.symbol,
        "timeframe": payload.timeframe,
        "leaderboard": leaderboard,
        "statistical_validation": cscv,
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
    elif payload.evaluation_mode == "incremental" and is_differential:
        env_name, default, ceiling = "AI_REPLAY_DIFFERENTIAL_SCREEN_HARD_TIMEOUT_SECONDS", 780, 840
    elif payload.evaluation_mode == "incremental":
        # Screening computes the full 5k-candle survival profile, including
        # chronological month attribution. Keep a strict wall-clock bound,
        # but leave enough room for a normal Windows worker under load.
        env_name, default, ceiling = "AI_REPLAY_SCREEN_HARD_TIMEOUT_SECONDS", 330, 330
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
    """Execute one heavy replay in a killable, single-lane worker.

    ``multiprocessing`` spawn is not a reliable containment boundary on
    Windows when the request contains thousands of inline candle objects: the
    parent can remain inside process startup/serialization before it can
    observe a child failure. A standalone Python subprocess with a JSON pipe
    gives us a hard parent-controlled deadline and a deterministic kill path.
    """
    global _last_replay_finished_at, _last_replay_termination

    # The replay compiler is intentionally content addressed.  It is safe to
    # reuse only an *identical* payload under the same evaluator code digest;
    # no stochastic seed, candle, execution assumption or specialist contract
    # is omitted from the key.  This removes duplicate timeout work without
    # changing a single gate result.
    cache_key = _replay_cache_key(operation, payload)
    cached = _load_immutable_replay_cache(cache_key)
    if cached is not None:
        _last_replay_finished_at = pd.Timestamp.utcnow().isoformat()
        _last_replay_termination = "cache_hit"
        return _with_replay_compiler_metadata(cached, cache_key, "immutable_cache_hit")

    # Laravel's WithoutOverlapping is the primary queue mutex. This second
    # guard protects direct/API callers and makes contention fail fast rather
    # than queueing an HTTP request that will consume a client timeout.
    if not _replay_lane_lock.acquire(blocking=False):
        raise HTTPException(status_code=429, detail="AI replay lane is busy; retry after the current bounded replay.")

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
        _last_replay_finished_at = pd.Timestamp.utcnow().isoformat()
        _replay_lane_lock.release()


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
    app_root = Path(__file__).resolve().parent
    files: dict[str, str] = {}
    for path in sorted(app_root.rglob("*.py")):
        if "__pycache__" in path.parts:
            continue
        files[path.relative_to(app_root).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()

    for name in ("requirements.txt", "pyproject.toml", "poetry.lock"):
        path = app_root.parent / name
        if path.is_file():
            files[name] = hashlib.sha256(path.read_bytes()).hexdigest()

    return {
        "protocol": "runtime_dependency_manifest_v2",
        "python": sys.version,
        "files": files,
    }


def _dataset_dependency_manifest(payload: SimpleBacktestRequest) -> dict[str, object]:
    paths = [
        ("dataset", payload.dataset_path),
        ("regime_dataset", payload.regime_dataset_path),
        # Full replay deliberately uses a separate pre-2026 foundation
        # archive. Its content hash must participate in the immutable cache
        # identity or a changed training archive could reuse old evidence.
        ("foundation_dataset", payload.foundation_dataset_path),
    ]
    manifest: dict[str, object] = {}
    for key, raw_path in paths:
        if not raw_path:
            continue
        path = Path(raw_path)
        if not path.is_absolute():
            path = (Path(__file__).resolve().parent.parent / path).resolve()
        item: dict[str, object] = {"requested_path": raw_path, "resolved_path": str(path)}
        manifest_path = Path(str(path) + ".manifest.json")
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
    return manifest


def _replay_cache_path(cache_key: str) -> Path:
    root = Path(os.getenv("AI_REPLAY_IMMUTABLE_CACHE_DIR", str(Path(__file__).resolve().parent.parent / ".runtime" / "replay-cache")))
    return root / f"{cache_key}.json"


def _load_immutable_replay_cache(cache_key: str) -> dict[str, object] | None:
    path = _replay_cache_path(cache_key)
    try:
        document = json.loads(path.read_text(encoding="utf-8"))
        value = document.get("value")
        return value if isinstance(value, dict) and document.get("key") == cache_key else None
    except (OSError, json.JSONDecodeError):
        return None


def _store_immutable_replay_cache(cache_key: str, value: dict[str, object]) -> None:
    path = _replay_cache_path(cache_key)
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        # Atomic replace prevents a timeout/restart from ever exposing a
        # partial diagnostic as if it were a completed immutable replay.
        temporary = path.with_suffix(".tmp")
        temporary.write_text(json.dumps({
            "protocol": "immutable_replay_cache_v2",
            "key": cache_key,
            "created_at": pd.Timestamp.utcnow().isoformat(),
            "value": value,
        }, ensure_ascii=False, separators=(",", ":"), default=str), encoding="utf-8")
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
            for path in root.glob("*.json"):
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


@app.post("/api/paper/signal")
def paper_signal(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        payload = _prepare_paper_payload(payload)
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"])
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df.columns:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        if "volume_available" not in df.columns:
            df["volume_available"] = False
        df["volume_available"] = df["volume_available"].fillna(False).astype(bool)
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
        row = prepared.iloc[-1]
        signal = str(row.get("signal", "WAIT"))
        price = float(row["close"])
        meta = _abstention_meta_decision(row, prepared.iloc[-2] if len(prepared) > 1 else None, signal, price, payload)
        router_wait_reason = str(row.get("portfolio_wait_reason", "") or "")
        if signal == "WAIT" and router_wait_reason:
            meta = {**meta, "decision": "WAIT", "reason": router_wait_reason, "router_wait": True}
        return {
            "signal": meta["decision"], "agent_signal": signal, "signal_time": pd.Timestamp(row["time"]).isoformat(), "price": price,
            "market_regime": str(row.get("market_regime", "unknown")),
            "volatility_regime": str(row.get("volatility_regime", "normal_volatility")),
            "confidence": float(row.get("signal_confidence", 1.0) or 0),
            "volume_quality": dict(prepared.attrs.get("volume_quality") or {}),
            "volume_context": {
                "feature_available": bool(row.get("volume_feature_available", False)),
                "ratio": float(row.get("volume_ratio", 0) or 0) if pd.notna(row.get("volume_ratio")) else None,
                "regime": str(row.get("volume_regime", "unavailable")),
                "policy_rejection": str(row.get("volume_policy_rejection", "")),
            },
            "meta_agent": meta,
            "router_wait_reason": router_wait_reason or None,
            "execution_contract_preview": _execution_contract(payload, row, signal, price, meta),
        }
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
        df["time"] = pd.to_datetime(df["time"])
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        if "volume_available" not in df.columns:
            df["volume_available"] = False
        df["volume_available"] = df["volume_available"].fillna(False).astype(bool)
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
            expected = pd.Timestamp(requested_time)
            if expected.tzinfo is not None:
                expected = expected.tz_localize(None)
            matches = prepared[pd.to_datetime(prepared["time"]).eq(expected)]
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
        return _execution_contract(payload, row, signal, market_price, meta)
    except (KeyError, TypeError, ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.post("/api/paper/advance-contract")
def advance_paper_contract(body: dict[str, object]) -> dict[str, object]:
    """Reconcile a paper order through the same trailing/exit code as replay."""
    try:
        payload = SimpleBacktestRequest.model_validate(body.get("request", {}))
        contract = dict(body["contract"])
        entry_time = pd.Timestamp(str(body["entry_time"]))
        df = _load_simple_candles(payload).copy()
        df["time"] = pd.to_datetime(df["time"])
        for column in ["open", "high", "low", "close", "volume"]:
            if column not in df:
                df[column] = 0
            df[column] = pd.to_numeric(df[column], errors="coerce")
        df = df.dropna(subset=["time", "open", "high", "low", "close"]).sort_values("time").reset_index(drop=True)
        previous_close = df["close"].shift(1)
        df["_management_atr"] = pd.concat([
            df["high"] - df["low"], (df["high"] - previous_close).abs(), (df["low"] - previous_close).abs(),
        ], axis=1).max(axis=1).rolling(14, min_periods=1).mean()
        matches = df[pd.to_datetime(df["time"]).eq(entry_time.tz_localize(None) if entry_time.tzinfo else entry_time)]
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
            holding_days = max((pd.Timestamp(candle["time"]) - entry_time).total_seconds() / 86400, 0)
            profit = gross * float(position["position_size_multiple"]) - (payload.execution.commission_percent + payload.execution.swap_per_day_percent * holding_days) * float(position["position_size_multiple"])
            return {"closed": True, "exit_price": round(float(exit_price), 8), "profit_percent": round(profit, 5), "exit_reason": reason,
                "stop_loss": round(float(position["stop_loss"]), 8), "contract_version": contract.get("contract_version")}
        return {"closed": False, "stop_loss": round(float(position["stop_loss"]), 8), "contract_version": contract.get("contract_version")}
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
    timestamp = pd.Timestamp(row.get("time"))
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


def _execution_contract(payload: SimpleBacktestRequest, row: pd.Series, signal: str, market_price: float, meta: dict[str, object]) -> dict[str, object]:
    execution_metadata = execution_contract_metadata(payload)
    if meta.get("decision") not in {"BUY", "SELL"}:
        return {
            "decision": "WAIT",
            "meta_agent": meta,
            "contract_version": "reality_parity_execution_v1",
            "execution_contract": execution_metadata,
            "execution_hash": execution_metadata["execution_hash"],
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
    }


@app.post("/api/holdout/run")
def run_sealed_holdout(payload: SimpleBacktestRequest) -> dict[str, object]:
    try:
        if payload.portfolio_members:
            if len(payload.portfolio_members) < 2:
                raise ValueError("A portfolio holdout requires at least two sealed members.")
            members = [
                member.model_copy(update={
                    "parameters": validate_strategy_parameters(
                        member.strategy, member.parameters, member.base_strategy,
                    ),
                })
                for member in payload.portfolio_members
            ]
            payload = payload.model_copy(update={
                "strategy": "portfolio_v1",
                "base_strategy": "portfolio",
                "parameters": dict(payload.parameters or {}),
                "portfolio_members": members,
            })
        else:
            parameters = validate_strategy_parameters(payload.strategy, payload.parameters, payload.base_strategy)
            payload = payload.model_copy(update={"parameters": parameters})
        df = _load_simple_candles(payload)
        foundation_df = _load_foundation_candles(payload)
        result, period = MarketAdaptiveReplayService().sealed_holdout(payload, df, foundation_df)
        return {"score": calculate_strategy_score(result), "result": result, "rows": period["rows"], "period": period,
                "protocol": "market_adaptive_replay_sealed_holdout"}
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


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


def _fitness_quality(result: dict) -> dict[str, object]:
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


def build_fitness_breakdown(result: dict, fitness_score: int | None = None) -> dict[str, object]:
    quality = _fitness_quality(result)
    return {
        "protocol": "fitness_quality_v2",
        "score": int(fitness_score if fitness_score is not None else calculate_strategy_score(result)),
        "components": quality,
        "weights": {
            "profit_and_pf": "base_score",
            "monthly_stability": "worst_month_pf + positive_month_ratio",
            "regime_coverage": "worst_regime_pf + observed_regime_ratio",
            "execution_cost": "stress_cost_pf + cost_to_gross_profit_percent",
            "sample_size": "trade_confidence",
        },
        "promotion_evidence": False,
        "rule": "Ranking only; forward, paper, portfolio and live gates remain unchanged.",
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
    quality = _fitness_quality(result)

    score = 0

    if profit > 0:
        score += min(profit, 30) * 0.8
    else:
        score += profit * 1.2

    score += winrate * 0.2

    if profit_factor >= 2:
        score += 25
    elif profit_factor >= 1.7:
        score += 20
    elif profit_factor >= 1.4:
        score += 15
    elif profit_factor >= 1.1:
        score += 8
    elif profit_factor < 1:
        score -= 15

    if max_drawdown > 25:
        score -= 35
    elif max_drawdown > 20:
        score -= 25
    elif max_drawdown > 15:
        score -= 18
    elif max_drawdown > 10:
        score -= 10
    elif max_drawdown <= 5:
        score += 8

    if max_consecutive_losses >= 10:
        score -= 20
    elif max_consecutive_losses >= 7:
        score -= 12
    elif max_consecutive_losses >= 5:
        score -= 6

    score += stability_score * 0.2

    if total_trades < 20:
        score -= 20
    elif total_trades >= 100:
        score += 5

    profitable_regimes = 0
    for data in regime_performance.values():
        if data.get("trades", 0) >= 10 and data.get("profit_percent", 0) > 0:
            profitable_regimes += 1

    if profitable_regimes >= 3:
        score += 8
    elif profitable_regimes == 2:
        score += 5
    elif profitable_regimes == 0:
        score -= 8

    # Reward consistency and cost survival explicitly. These are intentionally
    # bounded ranking adjustments; a high score cannot bypass a failed gate.
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


def calculate_final_walk_forward_score(
    forward_score: int,
    robustness_score: int,
    is_overfit: bool,
    fitness_score: int | None = None,
) -> int:
    if fitness_score is None:
        score = (forward_score * 0.70) + (robustness_score * 0.30)
    else:
        score = (forward_score * 0.55) + (robustness_score * 0.25) + (fitness_score * 0.20)

    if is_overfit:
        score -= 20

    return round(max(min(score, 100), 0))


app.include_router(backtests_router)
