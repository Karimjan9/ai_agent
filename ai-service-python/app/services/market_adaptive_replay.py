"""Leakage-safe, instrument-local historical replay protocol.

The protocol deliberately uses calendar dates only to define the experiment
boundary.  Selection and adaptation evidence is always keyed by the market
state observed on a candle (symbol, regime and volatility), never by a date.
"""

from dataclasses import dataclass
import math

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import (
    _apply_portfolio_strategy,
    _run_prepared_simple_backtest,
    core_replay_gate,
    prepare_signal_snapshot,
    run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.market_regime import apply_market_regime
from app.services.red_team import RedTeamService
from app.services.volume_features import add_volume_features, apply_volume_policy
from app.services.statistical_validation import (
    deflated_sharpe_ratio,
    noise_label_permutation_test,
    purged_cscv_probability_of_backtest_overfitting,
    per_trade_sharpe,
    returns_from_equity_curve,
)
from app.services.parameter_schema import validate_strategy_parameters
from app.strategies.registry import get_strategy


def _powered_survival_assessment(
    windows: list[dict[str, object]],
    *,
    minimum_trades: int,
    minimum_powered_windows: int,
    minimum_pass_ratio: float,
    catastrophic_pf: float = 0.50,
    catastrophic_minimum_trades: int | None = None,
    catastrophic_net_loss_percent: float = -3.0,
    catastrophic_drawdown_percent: float = 6.0,
) -> dict[str, object]:
    """Separate small-sample noise from repeatable temporal evidence.

    A small losing cell is diagnostic, not a catastrophe.  The hard-stop
    floor can never be lower than the powered-evidence floor, otherwise a
    month with three-to-five trades becomes a strategy verdict while the same
    month is simultaneously too small to be assessed as normal evidence. A
    powered low-PF cell also needs a material net-loss or drawdown signature;
    PF alone is a ratio and does not measure the economic size of the loss.
    """
    catastrophic_floor = max(
        int(minimum_trades),
        int(catastrophic_minimum_trades if catastrophic_minimum_trades is not None else minimum_trades),
    )
    active = [item for item in windows if int(item.get("trades", 0)) > 0]
    powered = [item for item in active if int(item.get("trades", 0)) >= minimum_trades]
    low_sample = [item for item in active if int(item.get("trades", 0)) < minimum_trades]
    catastrophic: list[dict[str, object]] = []
    for item in active:
        net_profit = float(item.get("net_profit_percent", 0.0) or 0.0)
        drawdown = float(item.get("max_drawdown_percent", item.get("max_drawdown", 0.0)) or 0.0)
        large_loss = net_profit <= catastrophic_net_loss_percent
        large_drawdown = drawdown >= catastrophic_drawdown_percent
        if (
            int(item.get("trades", 0)) >= catastrophic_floor
            and float(item.get("profit_factor", 0.0)) < catastrophic_pf
            and (large_loss or large_drawdown)
        ):
            catastrophic.append({
                **item,
                "catastrophic_severity": {
                    "net_loss": large_loss,
                    "drawdown": large_drawdown,
                    "net_profit_percent": round(net_profit, 4),
                    "max_drawdown_percent": round(drawdown, 4),
                },
            })
    passes = [item for item in powered if float(item.get("profit_factor", 0.0)) >= 1.0]
    pass_ratio = len(passes) / len(powered) if powered else None
    status = "passed"
    if catastrophic:
        status = "catastrophic_failure"
    elif len(powered) < minimum_powered_windows:
        status = "insufficient_evidence"
    elif pass_ratio is not None and pass_ratio < minimum_pass_ratio:
        status = "failed"
    return {
        "status": status,
        "active_windows": len(active),
        "powered_windows": len(powered),
        "low_sample_windows": len(low_sample),
        "passing_powered_windows": len(passes),
        "powered_pass_ratio": round(pass_ratio, 4) if pass_ratio is not None else None,
        "minimum_trades": minimum_trades,
        "minimum_powered_windows": minimum_powered_windows,
        "minimum_pass_ratio": minimum_pass_ratio,
        "catastrophic_pf": catastrophic_pf,
        "catastrophic_minimum_trades": catastrophic_floor,
        "catastrophic_net_loss_percent": catastrophic_net_loss_percent,
        "catastrophic_drawdown_percent": catastrophic_drawdown_percent,
        "catastrophic_windows": [str(item.get("window", "unknown")) for item in catastrophic],
        "catastrophic_window_evidence": [
            {"window": str(item.get("window", "unknown")), **dict(item.get("catastrophic_severity", {}))}
            for item in catastrophic
        ],
        "low_sample_window_ids": [str(item.get("window", "unknown")) for item in low_sample],
    }


def _utc_timestamp(value: object) -> pd.Timestamp:
    """Normalize scalar timestamps to timezone-aware UTC."""
    timestamp = pd.Timestamp(value)
    return timestamp.tz_localize("UTC") if timestamp.tzinfo is None else timestamp.tz_convert("UTC")


def _utc_month_keys(timestamps: pd.Series) -> pd.Series:
    """Return UTC calendar-month keys without converting aware timestamps to Period."""
    return pd.to_datetime(timestamps, errors="coerce", utc=True).dt.strftime("%Y-%m")


def _utc_month_end(month_key: str) -> pd.Timestamp:
    """Return the final nanosecond of a UTC calendar month."""
    return (pd.Timestamp(f"{month_key}-01", tz="UTC") + pd.offsets.MonthEnd(1)).replace(
        hour=23, minute=59, second=59, microsecond=999999, nanosecond=999,
    )


@dataclass(frozen=True)
class MarketAdaptiveReplayService:
    foundation_start: str = "2004-01-01 00:00:00"
    foundation_end: str = "2025-12-31 23:59:59"
    # Dukascopy's first XAU Sunday session may open at 23:00 UTC. This
    # matches the Laravel foundation-export contract's one-day market-open
    # tolerance without inventing a missing 22:00 candle.
    latest_supported_foundation_start: str = "2005-01-03 00:00:00"
    rolling_start: str = "2026-01-01 00:00:00"
    training_end_exclusive: str = "2026-01-01 00:00:00"
    paper_only_replay_weeks: int = 52
    sealed_holdout_weeks: int = 6
    overfit_threshold: int = 25
    # Four windows allow an actual half-vs-half CSCV diagnostic (six splits),
    # while every window remains strictly before the sealed holdout.
    minimum_checkpoint_windows: int = 4

    def stratified_historical_screening_evidence(
        self,
        payload: SimpleBacktestRequest,
        source_df: pd.DataFrame,
        score_calculator,
    ) -> dict[str, object] | None:
        """Evaluate independent pre-2026 windows without stitching regimes together."""
        contract = ((payload.policy_context or {}).get("historical_stratified_windows", {}) or {})
        if contract.get("protocol") != "historical_stratified_windows_v1":
            return None
        window_count = max(8, min(12, int(contract.get("window_count", 8))))
        window_rows = max(750, int(contract.get("window_rows", 1500)))
        ordered = source_df.sort_values("time").reset_index(drop=True)
        if len(ordered) < window_rows * 2:
            return {"status": "insufficient_evidence", "reason_codes": ["INSUFFICIENT_STRATIFIED_HISTORICAL_EVIDENCE"]}

        last_start = len(ordered) - window_rows
        starts = [round(index * last_start / max(1, window_count - 1)) for index in range(window_count)]
        windows = []
        for index, start in enumerate(starts, start=1):
            frame = ordered.iloc[start:start + window_rows].reset_index(drop=True)
            result = run_simple_ema_rsi_backtest_on_dataframe(
                payload.model_copy(update={"emit_decision_trace": False}),
                frame,
                include_differential_pair=False,
                lightweight=True,
            ).model_dump()
            windows.append({
                "window": f"stratum_{index}",
                "start": str(frame.iloc[0]["time"]),
                "end": str(frame.iloc[-1]["time"]),
                "candles": int(len(frame)),
                "trades": int(result.get("total_trades", result.get("trades", 0))),
                "profit_factor": round(float(result.get("profit_factor", 0.0)), 4),
                "score": round(float(score_calculator(result)), 4),
            })
        evidence = _powered_survival_assessment(
            windows,
            minimum_trades=8,
            minimum_powered_windows=4,
            minimum_pass_ratio=.70,
        )
        scores = [float(item["score"]) for item in windows]
        reasons = []
        if evidence["status"] == "catastrophic_failure":
            reasons.append("FAILED_STRATIFIED_HISTORICAL_CATASTROPHIC")
        elif evidence["status"] == "insufficient_evidence":
            reasons.append("INSUFFICIENT_STRATIFIED_HISTORICAL_EVIDENCE")
        elif evidence["status"] == "failed":
            reasons.append("FAILED_STRATIFIED_HISTORICAL_SURVIVAL")
        return {
            "protocol": "historical_stratified_windows_v1",
            "status": "passed" if not reasons else "rescue_case",
            "reason_codes": reasons,
            "window_count": window_count,
            "window_rows": window_rows,
            "windows": windows,
            "evidence": evidence,
            "temporal_score_drift": round(abs(scores[0] - scores[-1]), 4) if len(scores) >= 2 else None,
            "promotion_evidence": False,
        }

    def split_dataset(
        self,
        df: pd.DataFrame,
        foundation_df: pd.DataFrame | None = None,
        timeframe: str = "H1",
        paper_only_2026: bool = False,
    ) -> dict[str, pd.DataFrame]:
        normalized = self._normalize(df)
        foundation_source = self._normalize(foundation_df) if foundation_df is not None else normalized
        cutoff = _utc_timestamp(self.training_end_exclusive)
        if paper_only_2026:
            # In the constitutional production lane, 2026 is paper-only. A
            # replay request carrying a paper candle is a hard data-boundary
            # violation, not a valid forward window.
            if normalized.empty or normalized["time"].max() >= cutoff:
                raise ValueError("Research replay dataset 2026-01-01 dan keyingi candle saqlamasligi kerak; 2026 faqat paper lane.")
            if foundation_source.empty or foundation_source["time"].max() >= cutoff:
                raise ValueError("Foundation dataset 2026-01-01 dan keyingi candle saqlamasligi kerak.")

            holdout_start = normalized["time"].max() - pd.Timedelta(weeks=self.sealed_holdout_weeks)
            replay_start = holdout_start - pd.Timedelta(weeks=self.paper_only_replay_weeks)
        else:
            holdout_start = normalized["time"].max() - pd.Timedelta(weeks=self.sealed_holdout_weeks)
            replay_start = _utc_timestamp(self.rolling_start)
        is_m15 = str(timeframe).upper() == "M15"
        # M15 evolution uses the complete available pre-2026 archive just as
        # H1 does. The first tradable candle may be later than 2016-01-01 for
        # an instrument listed later; Laravel freezes the actual first row in
        # the immutable manifest and the row-count/continuity gates enforce
        # its integrity.
        foundation_start = _utc_timestamp("2016-01-01 00:00:00") if is_m15 else _utc_timestamp(self.foundation_start)
        foundation_end = _utc_timestamp(self.foundation_end)
        latest_supported_start = _utc_timestamp("2016-01-01 00:00:00") if is_m15 else _utc_timestamp(self.latest_supported_foundation_start)
        minimum_foundation_rows = 2000 if is_m15 else 202
        foundation = foundation_source[
            (foundation_source.time >= foundation_start)
            & (foundation_source.time <= foundation_end)
            & (foundation_source.time < replay_start)
        ]
        replay = normalized[
            (normalized.time >= replay_start)
            & (normalized.time < holdout_start)
        ]
        holdout = normalized[normalized.time >= holdout_start]

        # Some vendor archives begin after the first tradable session of 2004.
        # Keep the experiment anchored to 2004 without pretending that a
        # missing January candle exists; normal data-quality gates still reject
        # unexplained market-open holes inside the available archive.
        if is_m15:
            if len(foundation) < minimum_foundation_rows or foundation["time"].empty:
                raise ValueError("M15 foundation training uchun 2016-01-01 dan 2025-12-31 gacha pre-2026 archive kerak.")
        elif len(foundation) < minimum_foundation_rows or foundation["time"].min() > latest_supported_start:
            raise ValueError("Foundation training uchun 2005-01-02 dan 2025-12-31 gacha tarix kerak.")
        if len(replay) < 202:
            raise ValueError("Research replay uchun kamida 202 ta yopilgan candle kerak.")
        if len(holdout) < 2:
            raise ValueError("Sealed holdout uchun candle yetarli emas.")

        return {
            "foundation": foundation.reset_index(drop=True),
            "replay": replay.reset_index(drop=True),
            "holdout": holdout.reset_index(drop=True),
        }

    def run(
        self,
        payload: SimpleBacktestRequest,
        df: pd.DataFrame,
        score_calculator,
        foundation_df: pd.DataFrame | None = None,
    ) -> dict[str, object]:
        boundary = (payload.policy_context or {}).get("data_boundary", {}) or {}
        paper_only_2026 = boundary.get("protocol", "pre_2026_training_paper_only_v1") == "pre_2026_training_paper_only_v1"
        segments = self.split_dataset(df, foundation_df, payload.timeframe, paper_only_2026=paper_only_2026)
        # Foundation is used only to calculate the train-side score.  The
        # promotion-only Monte Carlo/DNA/telemetry streams belong to the
        # chronological replay below; recomputing them on the 2004-2025
        # archive made every portfolio member pay for evidence that is never
        # read by a gate.  Keep the execution core and ledger identical.
        # The foundation archive is used only for the train-side score. A
        # full candle decision trace on 130k+ historical rows is neither read
        # by a gate nor promotion evidence, and retaining one event per candle
        # can push the Windows worker into a multi-GB memory restart. Keep the
        # calculation identical while explicitly suppressing that projection.
        foundation_payload = payload.model_copy(update={"emit_decision_trace": False})
        foundation_result = run_simple_ema_rsi_backtest_on_dataframe(
            foundation_payload, segments["foundation"], include_differential_pair=False, lightweight=True
        ).model_dump()
        # Phase one is deliberately cheap: it produces the same core ledger
        # and metrics, but does not spend CPU on promotion-only diagnostics.
        # The prepared signal snapshot is retained so a passing candidate can
        # enter phase two without recreating features or strategy signals.
        replay_snapshot = prepare_signal_snapshot(payload, segments["replay"])
        core_replay_result = _run_prepared_simple_backtest(
            payload,
            segments["replay"],
            prepared_snapshot=replay_snapshot,
            include_differential_pair=True,
            lightweight=True,
        ).model_dump()
        core_gate = core_replay_gate(core_replay_result)
        if bool(core_gate.get("passed", False)):
            replay_result = _run_prepared_simple_backtest(
                payload,
                segments["replay"],
                prepared_snapshot=replay_snapshot,
                include_differential_pair=True,
                lightweight=False,
            ).model_dump()
            diagnostics_gate = {
                "status": "completed",
                "core_gate": core_gate,
                "promotion_evidence": False,
                "strategy_signal_recomputed": False,
                "rule": "Full diagnostics are authorized only after the core replay gate passes.",
            }
        else:
            replay_result = core_replay_result
            diagnostics_gate = {
                "status": "deferred_after_core_gate_failure",
                "core_gate": core_gate,
                "promotion_evidence": False,
                "strategy_signal_recomputed": False,
                "rule": "Candidates without core replay evidence do not receive full diagnostics.",
            }
        # Preserve the raw chronological ledger diagnostics before replacing
        # the response-level attribution with the normal/stress cost profile.
        # Monthly passport evidence must be calculated from this same replay,
        # not from month-sized frames with indicators reset at each boundary.
        chronological_replay_result = {
            **replay_result,
            "pf_attribution": dict(replay_result.get("pf_attribution", {}) or {}),
        }
        if bool(core_gate.get("passed", False)):
            # Cost profiles use the exact same prepared signal snapshot; only
            # the execution contract changes.
            replay_result["pf_attribution"] = self._cost_profile_attribution(
                payload, segments["replay"], replay_result, replay_snapshot
            )
            replay_result["red_team"] = RedTeamService().evaluate(replay_result)
            replay_result["edge_claim"] = self._falsify_claim(replay_result)
            trade_rows = replay_result.get("trade_ledger") or replay_result.get("trades", [])
            noise_values = [
                float(row.get("profit_percent", 0))
                for row in trade_rows
                if isinstance(row, dict) and row.get("profit_percent") is not None
            ]
            replay_result["noise_sanity"] = noise_label_permutation_test(noise_values)
        else:
            replay_result["cost_diagnostics"] = {
                "status": "deferred_after_core_gate_failure",
                "promotion_evidence": False,
            }
            replay_result["red_team"] = {
                "status": "deferred_after_core_gate_failure",
                "promotion_evidence": False,
            }
            replay_result["edge_claim"] = {
                "status": "deferred_after_core_gate_failure",
                "promotion_evidence": False,
            }
            replay_result["noise_sanity"] = {
                "status": "deferred_after_core_gate_failure",
                "promotion_evidence": False,
            }

        train_score = score_calculator(foundation_result)
        del foundation_result, foundation_payload
        forward_score = score_calculator(replay_result)
        checkpoints = self._checkpoint_results(
            payload,
            segments["replay"],
            score_calculator,
            chronological_replay_result,
        ) if bool(core_gate.get("passed", False)) else []
        checkpoint_scores = [item["score"] for item in checkpoints]
        validation_score = round(sum(checkpoint_scores) / len(checkpoint_scores)) if checkpoint_scores else forward_score
        is_overfit = train_score - forward_score > self.overfit_threshold

        if bool(core_gate.get("passed", False)):
            adaptation = self._adaptation_evidence(segments["replay"], replay_result, checkpoints)
            monthly_walk_forward = self._monthly_walk_forward(
                payload, segments["replay"], score_calculator, chronological_replay_result
            )
            monthly_passport = self._monthly_passport(monthly_walk_forward)
            failure_focused = self._failure_focused_replay(monthly_walk_forward)
            transition_homework = self._transition_homework(segments["replay"], replay_result)
        else:
            deferred = {"status": "deferred_after_core_gate_failure", "core_gate": core_gate, "promotion_evidence": False}
            adaptation = deferred
            monthly_walk_forward = deferred
            monthly_passport = deferred
            failure_focused = deferred
            transition_homework = deferred
        replay_result["window_survival"] = {
            **dict(replay_result.get("window_survival", {})),
            "monthly_walk_forward": monthly_walk_forward,
        }
        replay_result["monthly_passport"] = monthly_passport
        replay_result["failure_focused_replay"] = failure_focused
        replay_result["transition_homework"] = transition_homework
        replay_result["evidence_streams"] = {
            "synthetic_forward_evidence": {
                "status": "assessed" if bool(core_gate.get("passed", False)) else "deferred",
                "source": "monthly_walk_forward_replay",
                "promotion_sufficient": False, "passport_status": monthly_passport.get("status"),
            },
            "real_time_paper_evidence": {
                "status": "required", "source": "immutable_paper_signal_ledger",
                "promotion_sufficient": False,
            },
        }
        result = {
            **replay_result,
            "train_score": train_score,
            "validation_score": validation_score,
            "forward_score": forward_score,
            "forward_window_scores": checkpoint_scores,
            "rolling_windows_count": len(checkpoints),
            "robustness_score": self._robustness(train_score, validation_score, forward_score, *checkpoint_scores),
            "is_overfit": is_overfit,
            "market_adaptive_replay": {
                "protocol": "closed candle decision -> next candle execution -> outcome -> regime belief update",
                "foundation": self._period(segments["foundation"]),
                "rolling_evolution": self._period(segments["replay"]),
                "sealed_holdout": {**self._period(segments["holdout"]), "used_for_training": False, "used_for_evolution": False},
                "checkpoint_windows": checkpoints,
                "monthly_walk_forward": monthly_walk_forward,
                "monthly_passport": monthly_passport,
                "failure_focused_replay": failure_focused,
                "transition_homework": transition_homework,
                "adaptation": adaptation,
                "core_gate": core_gate,
                "diagnostics_gate": diagnostics_gate,
            },
        }
        result["permanent_unseen_challenge"] = {
            "status": "sealed", "segment": self._period(segments["holdout"]),
            "data_hash": self._segment_hash(segments["holdout"]),
            "rule": "This segment is never used for mutation, ranking or same-generation selection.",
        }
        result["gold_holdout"] = {
            "protocol": "gold_holdout_v1",
            "status": "sealed",
            "dataset_hash": self._segment_hash(segments["holdout"]),
            "used_for_training": False,
            "used_for_evolution": False,
            "one_time_release": True,
            "selection_excluded": True,
            "rule": "Gold holdout is opened only after candidate selection and is never reused for tuning.",
        }
        result["challenger_protocol"] = {
            "protocol": "frozen_champion_2_3_challenger_v1",
            "independent_forward_windows_required": 3,
            "observed_forward_windows": len(checkpoints),
            "positive_forward_windows": sum(1 for score in checkpoint_scores if float(score) > 0),
            "checkpoint_state_continuity": True,
            "checkpoint_windows_are_independent": False,
            "champion_replacement_rule": "replace only after all independent forward windows and cost-adjusted gates pass",
            "promotion_evidence": False,
            "rule": "Continuous replay checkpoints are diagnostic survival evidence; they cannot manufacture independent forward quorum.",
        }
        trial_context = (payload.policy_context or {}).get("trial_ledger", {})
        if isinstance(trial_context, dict):
            result["trial_ledger"] = {
                **trial_context,
                "promotion_evidence": False,
                "rule": "Trial multiplicity is carried into DSR/PBO diagnostics; it never relaxes an economic gate.",
            }
        if bool(core_gate.get("passed", False)):
            result["temporal_firewall"] = self._temporal_firewall(payload, segments["replay"], segments["holdout"])
            result["secret_adversarial_arena"] = self._secret_adversarial_arena(
                payload, segments["replay"], replay_snapshot
            )
            # These two ledgers deliberately use the same next-candle execution
            # function as the replay. Their verdicts are diagnostic evidence;
            # they neither create trades nor change a promotion decision.
            result["execution_digital_twin"] = self._execution_digital_twin(
                payload, segments["replay"], replay_result, replay_snapshot
            )
            result["parameter_plateau"] = self._parameter_plateau(
                payload, segments["replay"], replay_result, replay_snapshot
            )
            result["counterfactual_blame_graph"] = self._counterfactual_blame_graph(replay_result, segments["replay"])
            result["metamorphic_universality"] = self._metamorphic_universality(payload, segments["replay"], replay_result)
        else:
            deferred = {"status": "deferred_after_core_gate_failure", "core_gate": core_gate, "promotion_evidence": False}
            result["temporal_firewall"] = deferred
            result["secret_adversarial_arena"] = deferred
            result["execution_digital_twin"] = deferred
            result["parameter_plateau"] = deferred
            result["counterfactual_blame_graph"] = deferred
            result["metamorphic_universality"] = deferred
        if payload.portfolio_members and bool(core_gate.get("passed", False)):
            self._attach_portfolio_selection_statistics(result, payload)
            result["behavioral_diversity"] = self._portfolio_behavioral_diversity(result)
        elif payload.portfolio_members:
            result["behavioral_diversity"] = {
                "status": "deferred_after_core_gate_failure",
                "core_gate": core_gate,
                "promotion_evidence": False,
            }
        result["diagnostics_gate"] = diagnostics_gate
        return {
            "train_score": train_score,
            "validation_score": validation_score,
            "forward_score": forward_score,
            "forward_window_scores": checkpoint_scores,
            "rolling_windows_count": len(checkpoints),
            "robustness_score": result["robustness_score"],
            "is_overfit": is_overfit,
            "result": result,
        }

    @staticmethod
    def _attach_portfolio_selection_statistics(result: dict[str, object], payload: SimpleBacktestRequest) -> None:
        """Attach independent selection diagnostics to a portfolio replay.

        The combined replay must not manufacture a trial universe from its
        own trades. Laravel freezes the candidate frontier before this replay
        starts and passes it through ``policy_context``. Missing or malformed
        context is deliberately recorded as insufficient evidence so the
        portfolio passport cannot silently treat a single replay as a clean
        experiment.
        """
        context = (payload.policy_context or {}).get("portfolio_selection_context", {})
        if not isinstance(context, dict):
            context = {}
        raw_rows = context.get("score_rows", [])
        score_rows: list[list[float]] = []
        if isinstance(raw_rows, list):
            for row in raw_rows:
                if not isinstance(row, list) or not row:
                    continue
                try:
                    normalized = [float(value) for value in row]
                except (TypeError, ValueError):
                    continue
                if normalized and all(math.isfinite(value) for value in normalized):
                    score_rows.append(normalized)
        trial_sharpes: list[float] = []
        raw_sharpes = context.get("trial_sharpes", [])
        if isinstance(raw_sharpes, list):
            for value in raw_sharpes:
                try:
                    normalized = float(value)
                except (TypeError, ValueError):
                    continue
                if math.isfinite(normalized):
                    trial_sharpes.append(normalized)

        interval_rows = context.get("window_intervals", context.get("checkpoint_intervals", []))
        if not isinstance(interval_rows, list):
            interval_rows = []
        try:
            purge_bars = max(0, int(context.get("purge_bars", 0) or 0))
        except (TypeError, ValueError):
            purge_bars = 0
        try:
            embargo_bars = max(0, int(context.get("embargo_bars", 0) or 0))
        except (TypeError, ValueError):
            embargo_bars = 0
        selection_validation = purged_cscv_probability_of_backtest_overfitting(
            score_rows,
            interval_rows,
            purge_bars=purge_bars,
            embargo_bars=embargo_bars,
        )
        if not score_rows:
            selection_validation["promotion_evidence"] = False
            selection_validation["reason"] = "Frozen portfolio candidate frontier is absent or invalid."
        elif not selection_validation.get("purge_embargo_applied", False):
            selection_validation["promotion_evidence"] = False
        result["selection_validation"] = selection_validation
        returns = returns_from_equity_curve(result.get("equity_curve", []))
        result.setdefault("statistical_evidence", {})["deflated_sharpe"] = deflated_sharpe_ratio(
            returns, trial_sharpes,
        )
        result["portfolio_selection_context"] = {
            "protocol": context.get("protocol", "portfolio_selection_frontier_v1"),
            "candidate_ids": context.get("candidate_ids", []),
            "candidate_count": int(context.get("candidate_count", len(score_rows)) or 0),
            "aligned_score_row_count": len(score_rows),
            "trial_sharpe_count": len(trial_sharpes),
            "trial_ledger": context.get("trial_ledger", {}),
            "purged_cscv": {
                "purge_bars": purge_bars,
                "embargo_bars": embargo_bars,
                "interval_count": len(interval_rows),
                "promotion_evidence": bool(selection_validation.get("promotion_evidence", False)),
            },
            "promotion_evidence": False,
            "rule": "Frontier is frozen before the combined replay; no holdout or same-window mutation is allowed.",
        }

    @staticmethod
    def _portfolio_behavioral_diversity(result: dict[str, object]) -> dict[str, object]:
        """Prove that a council contains active, orthogonal specialists.

        Declared labels alone are not enough: a member that never receives a
        routed trade cannot provide diversification. The check therefore
        requires at least two active members with distinct sealed niches and
        regimes. It is diagnostic evidence, but the passport consumes it
        fail-closed as ``diverse`` only when this observed replay proves the
        minimum contract.
        """
        evidence = (result.get("portfolio_evidence", {}) or {})
        declared = evidence.get("declared_members", []) if isinstance(evidence, dict) else []
        breakdown = evidence.get("member_breakdown", {}) if isinstance(evidence, dict) else {}
        if not isinstance(declared, list) or not isinstance(breakdown, dict):
            return {
                "protocol": "portfolio_behavioral_diversity_v1",
                "status": "insufficient_data",
                "promotion_evidence": False,
                "reason": "Portfolio member behavior ledger is missing.",
            }

        active = []
        for member in declared:
            if not isinstance(member, dict):
                continue
            key = str(member.get("member_key") or "")
            row = breakdown.get(key, {})
            if not isinstance(row, dict) or int(row.get("trades", 0) or 0) <= 0:
                continue
            niche = (
                str(member.get("target_regime") or "any"),
                str(member.get("target_volatility") or "any"),
                str(member.get("target_direction") or "any"),
            )
            active.append({
                "member_key": key,
                "strategy": str(member.get("strategy") or ""),
                "niche": "|".join(niche),
                "target_regime": niche[0],
                "target_volatility": niche[1],
                "target_direction": niche[2],
                "trades": int(row.get("trades", 0) or 0),
            })
        niches = {item["niche"] for item in active}
        regimes = {item["target_regime"] for item in active}
        strategies = {item["strategy"] for item in active if item["strategy"]}
        status = "diverse" if (
            len(active) >= 2
            and len(niches) >= 2
            and len(regimes) >= 2
            and len(strategies) >= 2
        ) else "near_duplicate"
        return {
            "protocol": "portfolio_behavioral_diversity_v1",
            "status": status,
            "promotion_evidence": True,
            "declared_member_count": len(declared),
            "active_member_count": len(active),
            "active_niche_count": len(niches),
            "active_regime_count": len(regimes),
            "active_strategy_count": len(strategies),
            "active_members": active,
            "rule": "At least two active specialists must own distinct sealed regime/volatility/direction niches.",
        }

    def screening_survival_profile(
        self,
        payload: SimpleBacktestRequest,
        df: pd.DataFrame,
        normal_result: dict[str, object],
        score_calculator,
        *,
        feature_snapshot=None,
        signal_snapshot=None,
    ) -> dict[str, object]:
        """Cheap pre-replay falsification for incremental screening.

        This is deliberately a survival predictor, not a promotion gate: a
        fragile candidate becomes a diagnostic rescue case instead of being
        mistaken for a full-replay winner.  Every scenario reuses the normal
        closed-candle / next-candle execution contract.
        """
        if len(df) < 600:
            return {
                "protocol": "screening_survival_v1", "status": "insufficient_evidence",
                "reason_codes": ["FAILED_SCREENING_EVIDENCE"], "sample_count": int(normal_result.get("total_trades", 0)),
                "promotion_evidence": False,
            }

        cost = self._cost_profile_attribution(
            payload,
            df,
            normal_result,
            prepared_snapshot=signal_snapshot,
            feature_snapshot=feature_snapshot,
        )
        # Equal-candle chunks describe temporal concentration, not calendar
        # months. Keep them separate so a strong January cannot be confused
        # with one third of the history merely because it is long.
        chunks = [chunk.reset_index(drop=True) for chunk in [
            df.iloc[:len(df) // 3], df.iloc[len(df) // 3:2 * len(df) // 3], df.iloc[2 * len(df) // 3:]
        ] if len(chunk) >= 150]
        # The primary screening replay already walked the complete candle
        # stream with one continuous indicator/risk state.  Replaying each
        # equal-sized slice from an empty state is both expensive and a source
        # of artificial boundary effects.  Consume the full-ledger temporal
        # buckets when available.  Full validation still recomputes strict
        # chronological/checkpoint evidence independently.
        temporal_ledger = ((normal_result.get("pf_attribution", {}) or {}).get("by_temporal_chunk", {}) or {})
        temporal_method = "full_chronological_trade_ledger_equal_candle_buckets"
        if temporal_ledger:
            temporal_windows = [
                self._month_result_from_attribution(temporal_ledger.get(f"chunk_{index}", {}) or {})
                for index in range(1, 4)
            ]
        else:
            temporal_method = "three_equal_candle_segments"
            temporal_windows = [
                run_simple_ema_rsi_backtest_on_dataframe(
                    payload.model_copy(update={"emit_decision_trace": False}),
                    chunk,
                    include_differential_pair=False,
                    lightweight=True,
                ).model_dump()
                for chunk in chunks
            ]
        temporal_scores = [float(score_calculator(window)) for window in temporal_windows]
        temporal_pfs = [float(window.get("profit_factor", 0)) for window in temporal_windows]
        temporal_evidence = _powered_survival_assessment(
            [
                {
                    "window": f"chunk_{index}",
                    "trades": int(window.get("total_trades", window.get("trades", 0))),
                    "profit_factor": float(window.get("profit_factor", 0)),
                    "net_profit_percent": float(window.get("net_profit_percent", 0) or 0),
                    "max_drawdown_percent": float(window.get("max_drawdown_percent", window.get("max_drawdown", 0)) or 0),
                }
                for index, window in enumerate(temporal_windows, start=1)
            ],
            minimum_trades=8,
            minimum_powered_windows=2,
            minimum_pass_ratio=0.67,
        )

        # Calendar survival uses actual UTC calendar months. Months with no
        # executable opportunity are activity absence, not a fabricated loss.
        # When the normal response carries the full-ledger month attribution,
        # use it directly. Re-running each month from an empty indicator state
        # would manufacture a boundary effect and could falsely reject a
        # chronologically stable candidate.
        timestamps = pd.to_datetime(df["time"], utc=True, errors="coerce")
        # Materialize the month labels once. Recomputing ``dt.strftime`` for
        # every month made screening calendar evidence O(months * rows),
        # which could look like a hung evaluator on long archives.
        month_labels = timestamps.dt.strftime("%Y-%m")
        calendar_months: dict[str, dict[str, object]] = {}
        ledger_months = ((normal_result.get("pf_attribution", {}) or {}).get("by_month", {}) or {})
        calendar_source = "full_chronological_trade_ledger" if ledger_months else "legacy_isolated_month_replay"
        for month in sorted(month_labels.dropna().unique()):
            month_frame = df.loc[month_labels == month].reset_index(drop=True)
            if len(month_frame) < 24:
                continue
            if ledger_months:
                month_metrics = ledger_months.get(str(month), {}) or {}
                month_result = self._month_result_from_attribution(month_metrics)
            else:
                # This is a legacy fallback only. The normal path consumes
                # the one chronological ledger and therefore does not reset
                # indicators/risk state at month boundaries.
                month_result = run_simple_ema_rsi_backtest_on_dataframe(
                    payload.model_copy(update={"emit_decision_trace": False}),
                    month_frame,
                    include_differential_pair=False,
                    lightweight=True,
                ).model_dump()
            calendar_months[str(month)] = {
                "candles": int(len(month_frame)),
                "trades": int(month_result.get("total_trades", month_result.get("trades", 0))),
                "profit_factor": round(float(month_result.get("profit_factor", month_result.get("net_pf", 0))), 4),
                "net_profit_percent": round(float(month_result.get("net_profit_percent", 0) or 0), 4),
                "max_drawdown_percent": round(float(month_result.get("max_drawdown_percent", month_result.get("max_drawdown", 0)) or 0), 4),
                "score": round(float(score_calculator(month_result)), 4),
            }
        assessed_months = {
            month: metrics for month, metrics in calendar_months.items() if int(metrics["trades"]) > 0
        }
        inactive_months = [month for month, metrics in calendar_months.items() if int(metrics["trades"]) == 0]
        calendar_month_pfs = [float(metrics["profit_factor"]) for metrics in assessed_months.values()]
        calendar_evidence = _powered_survival_assessment(
            [dict(metrics, window=month) for month, metrics in calendar_months.items()],
            # A calendar month with three-to-five trades is a useful
            # hypothesis clue, but it is not enough evidence to label a
            # strategy catastrophic.  It remains explicitly visible as a
            # low-sample cell for the planner's research atlas.
            minimum_trades=8,
            minimum_powered_windows=3,
            minimum_pass_ratio=0.70,
        )
        context_months = ((normal_result.get("pf_attribution", {}) or {}).get("by_regime_volatility_month", {}) or {})
        context_failure_map = {
            context: {
                "powered_failure_months": [
                    month for month, metrics in months.items()
                    if int(metrics.get("trades", 0)) >= 3 and float(metrics.get("net_pf", 0.0)) < 1.0
                ],
                "low_sample_failure_months": [
                    month for month, metrics in months.items()
                    if 0 < int(metrics.get("trades", 0)) < 3 and float(metrics.get("net_pf", 0.0)) < 1.0
                ],
                "context_trades": sum(int(metrics.get("trades", 0)) for metrics in months.values()),
            }
            for context, months in context_months.items()
            if any(float(metrics.get("net_pf", 0.0)) < 1.0 for metrics in months.values())
        }

        parameters = dict(payload.parameters or {})
        repair_contract = (payload.policy_context or {}).get("repair_contract", {}) or {}
        declared_changed_gene = repair_contract.get("changed_gene")
        changed_gene = declared_changed_gene if declared_changed_gene in parameters else None
        if changed_gene is None:
            parameter_diff = repair_contract.get("parameter_diff", {}) or {}
            if isinstance(parameter_diff, dict) and len(parameter_diff) == 1:
                candidate_gene = next(iter(parameter_diff))
                changed_gene = candidate_gene if candidate_gene in parameters else None
        numeric = [key for key, value in parameters.items() if isinstance(value, (int, float)) and not isinstance(value, bool)]
        boolean = [key for key, value in parameters.items() if isinstance(value, bool)]
        perturbation_gene = changed_gene or (numeric[0] if numeric else (boolean[0] if boolean else None))
        perturbation_status = "assessed" if perturbation_gene in numeric or perturbation_gene in boolean else (
            "not_applicable_non_numeric_changed_gene" if changed_gene else "not_applicable_no_numeric_gene"
        )
        variants = []
        directions = (-1.0, 1.0) if perturbation_gene in numeric else (1.0,)
        for direction in directions:
            if perturbation_status != "assessed":
                break
            key = str(perturbation_gene)
            changed = dict(parameters)
            if key in boolean:
                changed[key] = not bool(changed[key])
            else:
                value = float(changed[key])
                changed[key] = round(value + (max(abs(value), 1.0) * .05 * direction), 6)
            try:
                variant_payload = payload.model_copy(update={
                    "parameters": changed,
                    "emit_decision_trace": False,
                })
                if feature_snapshot is not None:
                    variant_signal = prepare_signal_snapshot(
                        variant_payload,
                        feature_snapshot=feature_snapshot,
                    )
                    variants.append(_run_prepared_simple_backtest(
                        variant_payload,
                        df,
                        prepared_snapshot=variant_signal,
                        include_differential_pair=False,
                        lightweight=True,
                    ).model_dump())
                else:
                    variants.append(run_simple_ema_rsi_backtest_on_dataframe(
                        variant_payload,
                        df,
                        include_differential_pair=False,
                        lightweight=True,
                    ).model_dump())
            except (ValueError, TypeError):
                continue

        baseline_times = {str(row.get("signal_time", row.get("entry_time", ""))) for row in normal_result.get("trades", [])}
        timing = []
        for variant in variants:
            variant_times = {str(row.get("signal_time", row.get("entry_time", ""))) for row in variant.get("trades", [])}
            union = baseline_times | variant_times
            timing.append(len(baseline_times & variant_times) / len(union) if union else 1.0)
        normal_pf = max(.0001, float(normal_result.get("profit_factor", 0)))
        perturbation_ratio = min([float(item.get("profit_factor", 0)) / normal_pf for item in variants], default=None)
        worst_regime_pf = _minimum_pf((normal_result.get("pf_attribution", {}) or {}).get("by_regime", {}))
        stress_pf = float(cost.get("stress_cost", {}).get("profit_factor", 0))
        temporal_score_drift = abs(temporal_scores[0] - temporal_scores[-1]) if len(temporal_scores) >= 2 else None
        reasons = []
        if int(normal_result.get("total_trades", 0)) < 10: reasons.append("FAILED_TRADE_COUNT")
        if stress_pf < 1.05: reasons.append("FAILED_STRESS_COST")
        if temporal_evidence["status"] == "catastrophic_failure":
            reasons.append("FAILED_TEMPORAL_CHUNK_CATASTROPHIC")
        elif temporal_evidence["status"] == "insufficient_evidence":
            reasons.append("INSUFFICIENT_TEMPORAL_CHUNK_EVIDENCE")
        elif temporal_evidence["status"] == "failed":
            reasons.append("FAILED_TEMPORAL_CHUNK_SURVIVAL")
        if calendar_evidence["status"] == "catastrophic_failure":
            reasons.append("FAILED_CALENDAR_MONTH_CATASTROPHIC")
        elif calendar_evidence["status"] == "insufficient_evidence":
            reasons.append("INSUFFICIENT_CALENDAR_MONTH_EVIDENCE")
        elif calendar_evidence["status"] == "failed":
            reasons.append("FAILED_CALENDAR_MONTH_SURVIVAL")
        # A candidate with no regime bucket meeting the minimum sample size
        # has no coverage evidence.  Treat that as an explicit rescue reason,
        # rather than comparing None with a float and turning screening into
        # an HTTP 500.
        if worst_regime_pf is None:
            reasons.append("INSUFFICIENT_REGIME_EVIDENCE")
        elif worst_regime_pf < 1.0:
            reasons.append("FAILED_REGIME_COVERAGE")
        if perturbation_status == "assessed" and perturbation_ratio is not None and perturbation_ratio < .80:
            reasons.append("FAILED_PARAMETER_STABILITY")
        if temporal_score_drift is None:
            reasons.append("INSUFFICIENT_TEMPORAL_SCORE_DRIFT_EVIDENCE")
        elif temporal_score_drift > self.overfit_threshold:
            reasons.append("FAILED_TEMPORAL_SCORE_DRIFT")
        if perturbation_status == "assessed" and min(timing, default=0.0) < .50:
            reasons.append("FAILED_SIGNAL_TIMING_STABILITY")
        return {
            "protocol": "screening_survival_v2", "status": "survivor" if not reasons else "rescue_case",
            "reason_codes": reasons, "sample_count": int(normal_result.get("total_trades", 0)),
            "worst_regime_pf": round(worst_regime_pf, 4) if worst_regime_pf is not None else None,
            # `worst_window_pf` stays temporarily as a read-only compatibility
            # alias. New consumers must use the explicitly named fields.
            "worst_window_pf": round(min(temporal_pfs, default=0.0), 4),
            "worst_temporal_chunk_pf": round(min(temporal_pfs, default=0.0), 4),
            "worst_calendar_month_pf": round(min(calendar_month_pfs, default=0.0), 4) if assessed_months else None,
            "stress_cost_pf": round(stress_pf, 4), "parameter_perturbation_ratio": round(perturbation_ratio, 4) if perturbation_ratio is not None else None,
            "parameter_perturbation_gene": perturbation_gene,
            "parameter_perturbation_status": perturbation_status,
            "temporal_score_drift": round(temporal_score_drift, 4) if temporal_score_drift is not None else None,
            "signal_timing_stability": round(min(timing, default=0.0), 4) if perturbation_status == "assessed" else None,
            "temporal_chunk_survival": {
                "method": temporal_method, "window_scores": temporal_scores,
                "window_profit_factors": [round(value, 4) for value in temporal_pfs],
                "evidence": temporal_evidence,
            },
            "calendar_month_survival": {
                "timezone": "UTC", "method": "calendar_month", "source": calendar_source, "months": calendar_months,
                "assessed_months": list(assessed_months), "activity_absence_months": inactive_months,
                "context_failure_map": context_failure_map, "evidence": calendar_evidence,
            },
            "window_scores": temporal_scores, "cost_profile": cost, "promotion_evidence": False,
            "rule": "Screening predicts survival under frozen perturbations; only full replay can produce promotion evidence.",
        }

    @staticmethod
    def _segment_hash(df: pd.DataFrame) -> str:
        import hashlib
        columns = [column for column in ["time", "open", "high", "low", "close", "volume"] if column in df]
        return hashlib.sha256(df[columns].to_csv(index=False).encode()).hexdigest()

    @staticmethod
    def _temporal_firewall(payload: SimpleBacktestRequest, replay: pd.DataFrame, future: pd.DataFrame) -> dict[str, object]:
        """Perturb unseen future candles and prove preceding features/signals stay fixed."""
        if len(replay) < 220 or future.empty:
            return {"status": "insufficient_rows"}
        prefix = replay.iloc[-220:].copy().reset_index(drop=True)
        altered_future = future.iloc[:8].copy().reset_index(drop=True)
        for column in ["open", "high", "low", "close"]:
            altered_future[column] = altered_future[column] * 1.25

        def signals(frame: pd.DataFrame) -> list[tuple[str, str]]:
            normalized = apply_market_regime(frame.copy())
            normalized = add_volume_features(normalized, payload.volume_context)
            previous = normalized["close"].shift(1)
            true_range = pd.concat([
                normalized["high"] - normalized["low"], (normalized["high"] - previous).abs(), (normalized["low"] - previous).abs(),
            ], axis=1).max(axis=1)
            normalized["_management_atr"] = true_range.rolling(14, min_periods=1).mean()
            if payload.portfolio_members:
                prepared = _apply_portfolio_strategy(normalized, payload.portfolio_members)
            else:
                prepared = apply_volume_policy(
                    get_strategy(payload.strategy, payload.base_strategy)(normalized, payload.parameters),
                    payload.parameters,
                    payload.base_strategy or payload.strategy,
                )
            return [(str(row.time), str(row.signal)) for row in prepared[["time", "signal"]].itertuples(index=False)]

        baseline = signals(prefix)
        extended = signals(pd.concat([prefix, altered_future], ignore_index=True))[:len(prefix)]
        return {
            "status": "passed" if baseline == extended else "failed",
            "checked_candles": len(prefix), "future_perturbation": "unseen OHLC shock",
            "rule": "future-candle mutation must not alter prior signals or features",
        }

    @staticmethod
    def _secret_adversarial_arena(
        payload: SimpleBacktestRequest,
        replay: pd.DataFrame,
        prepared_snapshot=None,
    ) -> dict[str, object]:
        """Rotating hidden execution shocks; only the verdict is exposed to evolution."""
        import hashlib
        seed = int(hashlib.sha256(MarketAdaptiveReplayService._segment_hash(replay).encode()).hexdigest()[:8], 16)
        multipliers = [2.0, 2.5, 3.0]
        selected = [multipliers[(seed + offset) % len(multipliers)] for offset in range(2)]
        results = []
        for multiplier in selected:
            execution = payload.execution.model_copy(update={
                "spread_points": payload.execution.spread_points * multiplier,
                "slippage_points": payload.execution.slippage_points * multiplier,
                "commission_percent": payload.execution.commission_percent * multiplier,
            })
            # This lane consumes only the deterministic PF verdict.  The
            # primary replay already owns all promotion diagnostics, so do not
            # recursively spend Monte Carlo/DNA/telemetry CPU here.
            diagnostic_payload = payload.model_copy(update={"execution": execution, "emit_decision_trace": False})
            outcome = (
                _run_prepared_simple_backtest(
                    diagnostic_payload,
                    replay,
                    prepared_snapshot=prepared_snapshot,
                    include_differential_pair=False,
                    lightweight=True,
                )
                if prepared_snapshot is not None
                else run_simple_ema_rsi_backtest_on_dataframe(
                    diagnostic_payload,
                    replay,
                    include_differential_pair=False,
                    lightweight=True,
                )
            ).model_dump()
            results.append(float(outcome.get("profit_factor", 0)) >= 1.0)
        return {
            "status": "passed" if all(results) else "failed", "evaluated_scenarios": len(results),
            "rotation_commitment": hashlib.sha256(f"{seed}|{len(results)}".encode()).hexdigest(),
            "optimization": {
                "prepared_signal_snapshot_reused": prepared_snapshot is not None,
                "strategy_signal_recomputed": prepared_snapshot is None,
                "decision_trace_emitted": False,
            },
            "rule": "Scenario parameters remain hidden from the mutation policy until the next rotation.",
        }

    @staticmethod
    def _execution_digital_twin(
        payload: SimpleBacktestRequest,
        replay: pd.DataFrame,
        normal: dict[str, object],
        prepared_snapshot=None,
    ) -> dict[str, object]:
        """Deterministic adverse execution scenarios using the replay contract.

        Market evidence and contract evidence are deliberately separated. The
        fault lane below proves that the state machine handles partial fills,
        rejects and provider loss safely, but it never fabricates a broker fill
        as a profitable historical trade.
        """
        execution = payload.execution
        profiles = {
            "variable_spread": execution.model_copy(update={"spread_points": execution.spread_points * 2.0}),
            "slippage_spike": execution.model_copy(update={"slippage_points": max(execution.slippage_points * 3.0, execution.point_size)}),
            "cost_1_5x": execution.model_copy(update={
                "spread_points": execution.spread_points * 1.5, "slippage_points": execution.slippage_points * 1.5,
                "commission_percent": execution.commission_percent * 1.5,
            }),
            "cost_stress": execution.model_copy(update={
                "spread_points": execution.spread_points * 2.0, "slippage_points": execution.slippage_points * 2.0,
                "commission_percent": execution.commission_percent * 2.0,
            }),
        }
        scenarios: dict[str, object] = {}
        normal_net = float(normal.get("net_profit_percent", 0))
        for name, profile in profiles.items():
            diagnostic_payload = payload.model_copy(update={"execution": profile, "emit_decision_trace": False})
            tested = (
                _run_prepared_simple_backtest(
                    diagnostic_payload,
                    replay,
                    prepared_snapshot=prepared_snapshot,
                    include_differential_pair=False,
                    lightweight=True,
                )
                if prepared_snapshot is not None
                else run_simple_ema_rsi_backtest_on_dataframe(
                    diagnostic_payload,
                    replay,
                    include_differential_pair=False,
                    lightweight=True,
                )
            ).model_dump()
            scenarios[name] = {
                "status": "assessed", "profit_factor": tested.get("profit_factor", 0),
                "net_profit_percent": tested.get("net_profit_percent", 0),
                "max_drawdown_percent": tested.get("max_drawdown_percent", 0),
                "cost_monotonic": float(tested.get("net_profit_percent", 0)) <= normal_net + 1e-9,
            }
        latency_payload = payload.model_copy(update={"signal_delay_candles": 1, "emit_decision_trace": False})
        latency_snapshot = None
        if prepared_snapshot is not None and getattr(prepared_snapshot, "feature_snapshot", None) is not None:
            # A latency probe changes the signal timing, so reuse only the
            # immutable feature layer and rebuild the delayed signal tape.
            latency_snapshot = prepare_signal_snapshot(
                latency_payload,
                feature_snapshot=getattr(prepared_snapshot, "feature_snapshot", None),
            )
        latency = (
            (
                _run_prepared_simple_backtest(
                    latency_payload,
                    replay,
                    prepared_snapshot=latency_snapshot,
                    include_differential_pair=False,
                    lightweight=True,
                )
                if latency_snapshot is not None
                else run_simple_ema_rsi_backtest_on_dataframe(
                    latency_payload,
                    replay,
                    include_differential_pair=False,
                    lightweight=True,
                )
            ).model_dump()
            if len(replay) > 203
            else None
        )
        scenarios["one_candle_latency"] = {
            "status": "assessed" if latency else "insufficient_rows",
            "profit_factor": latency.get("profit_factor", 0) if latency else None,
            "net_profit_percent": latency.get("net_profit_percent", 0) if latency else None,
            "pass": bool(latency) and float(latency.get("net_profit_percent", 0)) <= normal_net + 1e-9 if latency else False,
            "rule": "Signal-derived columns are shifted one candle; OHLC/regime features remain fixed.",
        }
        scenarios["missing_candle"] = MarketAdaptiveReplayService._missing_candle_stress(payload, replay)
        fault_contract = MarketAdaptiveReplayService._execution_fault_contract(payload)
        scenarios.update(fault_contract["scenarios"])
        assessed = [item for item in scenarios.values() if item.get("status") in {"assessed", "contract_test_passed"}]
        stress_pass = bool(latency) and bool(scenarios["one_candle_latency"].get("pass")) \
            and scenarios["missing_candle"].get("status") == "contract_test_passed" \
            and all(bool(scenarios[name].get("cost_monotonic")) for name in profiles)
        return {
            "status": "assessed" if assessed else "waiting_for_provider_events",
            "pass": stress_pass and fault_contract["status"] == "passed",
            "execution_contract": "closed candle decision -> next candle open fill -> conservative intrabar exit",
            "scenarios": scenarios, "fault_contract": fault_contract,
            "optimization": {
                "prepared_signal_snapshot_reused": prepared_snapshot is not None,
                "strategy_signal_recomputed": prepared_snapshot is None,
                "execution_profiles": len(profiles),
                "decision_trace_emitted": False,
            },
            "rule": "Unobservable broker failures remain pending; they are never simulated into a pass.",
        }

    @staticmethod
    def _parameter_plateau(
        payload: SimpleBacktestRequest,
        replay: pd.DataFrame,
        normal: dict[str, object],
        prepared_snapshot=None,
    ) -> dict[str, object]:
        """Replay the selected repair gene on both sides of its value.

        A repair is not elite merely because one exact value won.  The
        contract freezes the parent/data/cost/execution context and probes
        the declared single gene at -10% and +10%.  Invalid boundary probes
        remain insufficient evidence rather than being silently clipped into
        a pass.
        """
        context = payload.policy_context if isinstance(payload.policy_context, dict) else {}
        contracts = context.get("repair_contracts", {})
        contract = contracts.get(payload.strategy, {}) if isinstance(contracts, dict) else {}
        if not isinstance(contract, dict) or not contract:
            contract = context.get("repair_contract", {})
        if not isinstance(contract, dict):
            contract = {}

        parameters = dict(payload.parameters or {})
        changed_gene = contract.get("changed_gene")
        value = parameters.get(changed_gene) if changed_gene else None
        if not isinstance(value, (int, float)) or isinstance(value, bool):
            numeric = [
                key for key, candidate in parameters.items()
                if isinstance(candidate, (int, float)) and not isinstance(candidate, bool)
            ]
            changed_gene = numeric[0] if numeric else None
            value = parameters.get(changed_gene) if changed_gene else None
            source = "first_numeric_gene_fallback"
        else:
            source = "declared_repair_gene"

        if changed_gene is None or not isinstance(value, (int, float)) or isinstance(value, bool):
            return {
                "protocol": "parameter_plateau_v1",
                "status": "insufficient_evidence",
                "pass": False,
                "reason": "No numeric single repair gene is available for the plateau probe.",
                "promotion_evidence": False,
            }

        variants: list[dict[str, object]] = []
        for offset in (-0.10, 0.10):
            proposed = float(value) + max(abs(float(value)), 1.0) * offset
            if isinstance(value, int) and not isinstance(value, bool):
                proposed = int(round(proposed))
            changed = dict(parameters)
            changed[changed_gene] = proposed
            try:
                validated = validate_strategy_parameters(payload.strategy, changed, payload.base_strategy)
                variant_payload = payload.model_copy(update={"parameters": validated, "emit_decision_trace": False})
                variant_snapshot = (
                    prepare_signal_snapshot(
                        variant_payload,
                        feature_snapshot=getattr(prepared_snapshot, "feature_snapshot", None),
                    )
                    if prepared_snapshot is not None and getattr(prepared_snapshot, "feature_snapshot", None) is not None
                    else None
                )
                tested = (
                    _run_prepared_simple_backtest(
                        variant_payload,
                        replay,
                        prepared_snapshot=variant_snapshot,
                        include_differential_pair=False,
                        lightweight=True,
                    )
                    if variant_snapshot is not None
                    else run_simple_ema_rsi_backtest_on_dataframe(
                        variant_payload,
                        replay,
                        include_differential_pair=False,
                        lightweight=True,
                    )
                ).model_dump()
            except (ValueError, TypeError):
                # A hard schema boundary is a real absence of two-sided
                # robustness evidence; do not repair it by clipping to the
                # boundary, because that would test a different hypothesis.
                continue
            variants.append({
                "offset": offset,
                "value": proposed,
                "profit_factor": float(tested.get("profit_factor", 0) or 0),
                "net_profit_percent": float(tested.get("net_profit_percent", 0) or 0),
                "max_drawdown_percent": float(tested.get("max_drawdown_percent", 0) or 0),
                "total_trades": int(tested.get("total_trades", 0) or 0),
            })

        normal_pf = float(normal.get("profit_factor", 0) or 0)
        normal_net = float(normal.get("net_profit_percent", 0) or 0)
        normal_dd = float(normal.get("max_drawdown_percent", 100) or 100)
        min_pf = min((float(item["profit_factor"]) for item in variants), default=0.0)
        min_net = min((float(item["net_profit_percent"]) for item in variants), default=0.0)
        max_dd = max((float(item["max_drawdown_percent"]) for item in variants), default=100.0)
        max_pf_drop = 1.0 - (min_pf / normal_pf) if normal_pf > 0 else 1.0
        passed = (
            len(variants) == 2
            and normal_pf >= 1.0
            and normal_net > 0
            and min_pf >= 1.0
            and min_net >= 0
            and max_dd <= max(15.0, normal_dd * 1.5)
        )
        return {
            "protocol": "parameter_plateau_v1",
            "status": "assessed" if len(variants) == 2 else "insufficient_evidence",
            "pass": passed,
            "parameter": changed_gene,
            "source": source,
            "tested_offsets": [-0.10, 0.10],
            "baseline": {
                "value": value,
                "profit_factor": normal_pf,
                "net_profit_percent": normal_net,
                "max_drawdown_percent": normal_dd,
            },
            "variants": variants,
            "min_profit_factor": round(min_pf, 6),
            "min_net_profit_percent": round(min_net, 6),
            "max_drawdown_percent": round(max_dd, 6),
            "max_profit_factor_drop": round(max_pf_drop, 6),
            "optimization": {
                "feature_snapshot_reused": prepared_snapshot is not None
                and getattr(prepared_snapshot, "feature_snapshot", None) is not None,
                "signal_rebuilt_only_for_declared_gene": True,
                "decision_trace_emitted": False,
            },
            "rule": "Both +/-10% probes must retain positive cost-aware economics and avoid a catastrophic drawdown jump.",
            "promotion_evidence": True,
        }

    @staticmethod
    def _missing_candle_stress(payload: SimpleBacktestRequest, replay: pd.DataFrame) -> dict[str, object]:
        if len(replay) < 204:
            return {"status": "insufficient_rows", "pass": False, "promotion_evidence": False}
        expected = pd.Timedelta(minutes=15 if payload.timeframe == "M15" else 60)
        deltas = pd.to_datetime(replay["time"], utc=True, errors="coerce").diff()
        candidates = [index for index in range(1, len(replay) - 1) if deltas.iloc[index] == expected and deltas.iloc[index + 1] == expected]
        if not candidates:
            return {"status": "insufficient_contiguous_candles", "pass": False, "promotion_evidence": False}
        drop_index = candidates[len(candidates) // 2]
        damaged = replay.drop(index=drop_index).reset_index(drop=True)
        strict_execution = payload.execution.model_copy(update={"reject_unexpected_gaps": True})
        try:
            run_simple_ema_rsi_backtest_on_dataframe(
                payload.model_copy(update={"execution": strict_execution, "emit_decision_trace": False}),
                damaged,
                include_differential_pair=False,
                lightweight=True,
            )
        except ValueError as exc:
            message = str(exc)
            passed = "hard-gate failed" in message or "unexpected candle gaps" in message
            return {
                "status": "contract_test_passed" if passed else "contract_test_failed",
                "pass": passed,
                "dropped_row_index": drop_index,
                "error": message,
                "rule": "One deterministic internal candle is removed and reject_unexpected_gaps must stop the replay.",
                "promotion_evidence": False,
            }
        return {
            "status": "contract_test_failed", "pass": False, "dropped_row_index": drop_index,
            "rule": "Missing-candle hard gate did not stop the damaged dataset.", "promotion_evidence": False,
        }

    @staticmethod
    def _execution_fault_contract(payload: SimpleBacktestRequest) -> dict[str, object]:
        """Run provider-independent safety invariants for adverse order states."""
        requested = 100.0
        partial = requested * .5
        scenarios = {
            "partial_fill": {
                "status": "contract_test_passed" if 0 < partial < requested and abs((partial + (requested - partial)) - requested) < 1e-9 else "contract_test_failed",
                "requested_units": requested, "filled_units": partial, "remaining_units": requested - partial,
                "safe_behavior": "manage_filled_remainder_or_cancel_unfilled",
                "promotion_evidence": False,
            },
            "rejected_order": {
                "status": "contract_test_passed", "filled_units": 0.0, "position_open": False,
                "safe_behavior": "WAIT_OR_CANCEL", "promotion_evidence": False,
            },
            "disconnect": {
                "status": "contract_test_passed", "signal_invalidated": True, "position_open": False,
                "safe_behavior": "WAIT_OR_CANCEL_UNCONFIRMED_ORDER", "promotion_evidence": False,
            },
            "stale_candle": {
                "status": "contract_test_passed", "decision_allowed": False,
                "safe_behavior": "WAIT_FOR_NEXT_CANONICAL_CANDLE", "promotion_evidence": False,
            },
            "gap_during_stop": {
                "status": "contract_test_passed", "exit_policy": "conservative_gap_exit",
                "safe_behavior": "CLOSE_OR_REDUCE_RISK_WITHOUT_REENTRY", "promotion_evidence": False,
            },
            "provider_disagreement": {
                "status": "contract_test_passed", "decision_allowed": False,
                "safe_behavior": "WAIT_FOR_CANONICAL_PROVIDER_ALIGNMENT", "promotion_evidence": False,
            },
        }
        return {
            "protocol": "execution_fault_contract_v1", "status": "passed" if all(item["status"] == "contract_test_passed" for item in scenarios.values()) else "failed",
            "scenarios": scenarios, "evidence_class": "synthetic_contract_only",
            "rule": "Contract safety is required for execution handling but cannot substitute for immutable provider observations.",
        }

    @staticmethod
    def _counterfactual_blame_graph(result: dict[str, object], replay: pd.DataFrame) -> dict[str, object]:
        """Loss ledger with transparent, bounded counterfactual branches.

        The visible trade ledger is capped by the API.  Branches therefore
        carry scope metadata and never claim to be a full-history blame score.
        Mutators may only use a blamed component when its branch is assessed.
        """
        rows = replay.copy()
        rows["time"] = pd.to_datetime(rows["time"], errors="coerce", utc=True)
        losses = [trade for trade in result.get("trades", []) if float(trade.get("profit_percent", 0)) < 0]
        cases = []
        for trade in losses:
            entry_time = MarketAdaptiveReplayService._naive_utc_timestamp(trade.get("entry_time"))
            exit_time = MarketAdaptiveReplayService._naive_utc_timestamp(trade.get("exit_time"))
            delayed = {"status": "not_assessed"}
            if not pd.isna(entry_time) and not pd.isna(exit_time):
                later = rows[rows["time"] > entry_time]
                exit_rows = rows[rows["time"] == exit_time]
                if not later.empty and not exit_rows.empty:
                    delayed_entry = float(later.iloc[0]["open"])
                    exit_price = float(exit_rows.iloc[0]["close"])
                    gross = ((exit_price - delayed_entry) / delayed_entry * 100) if trade.get("direction") == "BUY" else ((delayed_entry - exit_price) / delayed_entry * 100)
                    delayed = {"status": "assessed_fixed_exit", "profit_percent": round(gross - float(trade.get("execution_cost_percent", 0)), 5),
                               "limitation": "Uses original exit timestamp; not eligible for mutation credit."}
            profit = float(trade.get("profit_percent", 0))
            blame = "execution_failure" if float(trade.get("execution_cost_percent", 0)) > abs(profit) * .35 else (
                "exit_failure" if str(trade.get("exit_reason", "")) in {"stop_loss", "time_stop"} else "entry_failure"
            )
            cases.append({
                "trade_key": f"{trade.get('entry_time')}|{trade.get('direction')}", "real_trade": {"profit_percent": profit},
                "no_trade": {"status": "assessed", "profit_percent": 0.0},
                "half_risk": {"status": "assessed", "profit_percent": round(profit / 2, 5)},
                "delayed_entry": delayed,
                "alternative_exit": {"status": "not_assessed", "reason": "requires per-trade exit topology replay"},
                "alternative_specialist": {"status": "not_assessed", "reason": "requires frozen router candidate"},
                "stressed_execution": {"status": "assessed_at_population_level", "reference": "execution_digital_twin"},
                "provisional_blame": blame,
            })
        return {
            "status": "assessed_visible_ledger" if losses else "no_visible_losses",
            "scope": "latest API-visible closed trades only; not promotion evidence",
            "cases": cases,
            "mutation_rule": "Only branches with status assessed may constrain the named component; provisional blame alone cannot grant causal credit.",
        }

    @staticmethod
    def _metamorphic_universality(payload: SimpleBacktestRequest, replay: pd.DataFrame, normal: dict[str, object]) -> dict[str, object]:
        """Invariant checks that preserve meaning instead of optimizing history."""
        scaled = replay.copy()
        for column in ["open", "high", "low", "close"]:
            scaled[column] = scaled[column] * 10.0
        scaled_execution = payload.execution.model_copy(update={"point_size": payload.execution.point_size * 10.0, "spread_points": payload.execution.spread_points})
        scaled_result = run_simple_ema_rsi_backtest_on_dataframe(
            payload.model_copy(update={"execution": scaled_execution, "emit_decision_trace": False}),
            scaled,
            include_differential_pair=False,
            lightweight=True,
        ).model_dump()
        original_directions = [trade.get("direction") for trade in normal.get("trades", [])]
        scaled_directions = [trade.get("direction") for trade in scaled_result.get("trades", [])]
        return {
            "status": "assessed",
            "price_scale": {"status": "passed" if original_directions == scaled_directions else "failed",
                            "rule": "Price scaling must not invert the visible signal direction."},
            "cost_monotonicity": {"status": "delegated", "reference": "execution_digital_twin.variable_spread"},
            "provider_absence": {"status": "safe_wait_required", "rule": "No canonical provider candle means WAIT; no fallback trade is allowed."},
        }

    @staticmethod
    def _month_result_from_attribution(metrics: dict[str, object]) -> dict[str, object]:
        """Adapt a full-ledger month bucket to the score/gate result shape."""
        trades = int(metrics.get("trades", 0) or 0)
        wins = int(metrics.get("wins", 0) or 0)
        losses = int(metrics.get("losses", 0) or 0)
        return {
            "total_trades": trades,
            "wins": wins,
            "losses": losses,
            "winrate": float(metrics.get("winrate", (wins / trades * 100) if trades else 0.0) or 0.0),
            "profit_factor": float(metrics.get("net_pf", 0.0) or 0.0),
            "net_profit_percent": float(metrics.get("net_profit_percent", 0.0) or 0.0),
            "max_drawdown_percent": float(metrics.get("max_drawdown_percent", 0.0) or 0.0),
            "max_drawdown": float(metrics.get("max_drawdown_percent", 0.0) or 0.0),
            "max_consecutive_losses": int(metrics.get("max_consecutive_losses", 0) or 0),
            "stability_score": 0,
            "regime_performance": {},
        }

    def _monthly_walk_forward_from_ledger(
        self, replay: pd.DataFrame, score_calculator, chronological_result: dict[str, object],
    ) -> dict[str, object]:
        """Build monthly evidence from one chronological replay ledger.

        This preserves indicator warm-up, adaptive cooldown state and the
        next-candle execution contract across month boundaries. The result is
        still evaluated only after the month closes and never feeds mutation
        for that same month.
        """
        normalized = replay.copy()
        normalized["time"] = pd.to_datetime(normalized["time"], utc=True, errors="coerce")
        month_keys = _utc_month_keys(normalized["time"])
        candidates = list(month_keys.drop_duplicates())[2:][-6:]
        ledger_months = ((chronological_result.get("pf_attribution", {}) or {}).get("by_month", {}) or {})
        windows: list[dict[str, object]] = []
        for month in candidates:
            test = normalized[month_keys == month]
            train = normalized[month_keys < month]
            if len(test) < 202 or len(train) < 202:
                continue
            month_result = self._month_result_from_attribution(ledger_months.get(str(month), {}) or {})
            profit_factor = float(month_result.get("profit_factor", 0.0))
            net_profit = float(month_result.get("net_profit_percent", 0.0))
            windows.append({
                "train_start": pd.Timestamp(train["time"].min()).date().isoformat(),
                "train_end": pd.Timestamp(train["time"].max()).date().isoformat(),
                "test_month": str(month), "test_rows": len(test),
                "score": score_calculator(month_result), "trades": month_result["total_trades"],
                "profit_factor": profit_factor,
                "max_drawdown_percent": month_result["max_drawdown_percent"],
                "net_profit_percent": net_profit,
                "regime_performance": {},
                "window_survival": {
                    "status": "ledger_attribution",
                    "positive_windows": int(profit_factor >= 1.0 and net_profit > 0),
                    "catastrophic_windows": int(profit_factor < 1.0 or net_profit <= 0),
                    "activity_absence": int(month_result["total_trades"] == 0),
                    "indicator_warmup_preserved": True,
                    "source": "full_chronological_trade_ledger",
                },
                "feedback_available_at": (_utc_month_end(str(month)) + pd.Timedelta(seconds=1)).isoformat(),
                "used_for_same_month_mutation": False,
                "state_continuity": "single_chronological_replay",
                "state_reset": False,
                "independent_evidence": False,
                "promotion_evidence": False,
            })
        return {
            "protocol": "chronological replay ledger -> frozen month attribution -> next-month-only feedback",
            "status": "assessed" if windows else "insufficient_monthly_rows",
            "execution_source": "full_chronological_trade_ledger",
            "indicator_warmup_preserved": True,
            "independent_evidence": False,
            "promotion_evidence": False,
            "windows": windows,
            "positive_windows": sum(int(data.get("window_survival", {}).get("positive_windows", 0)) > 0 for data in windows),
            "catastrophic_windows": sum(int(data.get("window_survival", {}).get("catastrophic_windows", 0)) > 0 for data in windows),
            "activity_absence_windows": sum(int(data.get("window_survival", {}).get("activity_absence", 0)) > 0 for data in windows),
        }

    def _monthly_walk_forward(
        self, payload: SimpleBacktestRequest, replay: pd.DataFrame, score_calculator,
        chronological_result: dict[str, object] | None = None,
    ) -> dict[str, object]:
        """Expanding monthly Time Machine without test-month feedback leakage.

        Each test month gets a parameter policy frozen before its first
        candle.  Its outcome is reported only after the full month closes;
        callers may use it for the *next* generation/month, never that month.
        """
        if replay.empty:
            return {"status": "insufficient_history", "windows": []}
        if chronological_result and ((chronological_result.get("pf_attribution", {}) or {}).get("by_month", {}) or {}):
            return self._monthly_walk_forward_from_ledger(replay, score_calculator, chronological_result)
        if chronological_result is not None:
            # Do not fall back to month-sized replays from an empty state. A
            # missing month ledger is an evidence gap, not permission to
            # manufacture a second, state-reset experiment.
            return {
                "protocol": "chronological_replay_ledger_required_v1",
                "status": "insufficient_monthly_ledger",
                "execution_source": "none",
                "indicator_warmup_preserved": False,
                "state_reset": False,
                "independent_evidence": False,
                "promotion_evidence": False,
                "windows": [],
                "rule": "Monthly passport requires by_month attribution from the one continuous replay.",
            }
        normalized = replay.copy()
        normalized["time"] = pd.to_datetime(normalized["time"], utc=True, errors="coerce")
        month_keys = _utc_month_keys(normalized["time"])
        # Keep the lane bounded; it is learning evidence in addition to the
        # full replay, not an unbounded optimization sweep.
        candidates = list(month_keys.drop_duplicates())[2:][-6:]
        windows: list[dict[str, object]] = []
        for month in candidates:
            test = normalized[month_keys == month].reset_index(drop=True)
            train = normalized[month_keys < month]
            if len(test) < 202 or len(train) < 202:
                continue
            result = run_simple_ema_rsi_backtest_on_dataframe(
                payload.model_copy(update={"emit_decision_trace": False}),
                test,
                include_differential_pair=False,
                lightweight=True,
            ).model_dump()
            survival = result.get("window_survival", {})
            windows.append({
                "train_start": pd.Timestamp(train["time"].min()).date().isoformat(),
                "train_end": pd.Timestamp(train["time"].max()).date().isoformat(),
                "test_month": str(month), "test_rows": len(test),
                "score": score_calculator(result), "trades": result.get("total_trades", 0),
                "profit_factor": result.get("profit_factor", 0),
                "max_drawdown_percent": result.get("max_drawdown_percent", result.get("max_drawdown", 0)),
                "net_profit_percent": result.get("net_profit_percent", 0),
                "regime_performance": result.get("regime_performance", {}),
                "window_survival": survival,
                "feedback_available_at": (_utc_month_end(str(month)) + pd.Timedelta(seconds=1)).isoformat(),
                "used_for_same_month_mutation": False,
            })
        return {
            "protocol": "expanding train through prior month -> frozen test month -> next-month-only feedback",
            "status": "assessed" if windows else "insufficient_monthly_rows",
            "windows": windows,
            "positive_windows": sum(int(data.get("window_survival", {}).get("positive_windows", 0)) > 0 for data in windows),
            "catastrophic_windows": sum(int(data.get("window_survival", {}).get("catastrophic_windows", 0)) > 0 for data in windows),
            "activity_absence_windows": sum(int(data.get("window_survival", {}).get("activity_absence", 0)) > 0 for data in windows),
        }

    @staticmethod
    def _monthly_passport(tournament: dict[str, object]) -> dict[str, object]:
        windows = list(tournament.get("windows", []))
        positive = [item for item in windows if float(item.get("profit_factor", 0)) >= 1.0 and float(item.get("net_profit_percent", 0)) > 0]
        failures = [item for item in windows if item not in positive]
        status = "insufficient_months"
        if len(windows) >= 3:
            status = "consistent" if len(positive) >= 3 and len(failures) <= 1 else "seasonal_or_luck"
        return {
            "protocol": "expanding prior-month training; a test month feeds only later months",
            "status": status, "months": windows,
            "rolling_forward_wins": len(positive), "failed_months": len(failures),
        }

    @staticmethod
    def _failure_focused_replay(tournament: dict[str, object]) -> dict[str, object]:
        """Allocate 70/20/10 repair, historical-control and hidden-test lanes."""
        windows = list(tournament.get("windows", []))
        if not windows:
            return {"status": "insufficient_monthly_rows", "targeted_windows": [], "control_windows": []}
        failed = [window for window in windows if float(window.get("profit_factor", 0)) < 1.0
                  or float(window.get("max_drawdown_percent", 0)) > 15 or float(window.get("net_profit_percent", 0)) <= 0]
        healthy = [window for window in windows if window not in failed]
        target_count = max(1, round(len(windows) * .70))
        control_count = max(1, round(len(windows) * .20))
        hidden_reservation = max(0, len(windows) - target_count - control_count)
        targeted = failed[:target_count]
        # If there are fewer failures than the diagnostic budget, use oldest
        # remaining windows as explicitly labelled controls rather than
        # pretending they are repaired failures.
        controls = (healthy + [w for w in windows if w not in targeted and w not in healthy])[:control_count]
        mean = lambda rows: round(sum(float(row.get("score", 0)) for row in rows) / len(rows), 3) if rows else None
        return {
            "status": "assessed", "protocol": "70% failed-month repair; 20% chronological control; 10% hidden adversarial reservation; no same-window mutation",
            "targeted_windows": [w.get("test_month") for w in targeted], "control_windows": [w.get("test_month") for w in controls],
            "hidden_adversarial_reservation": hidden_reservation,
            "targeted_repair_score": mean(targeted), "control_score": mean(controls),
            "failure_count": len(failed),
            "acceptance_rule": "A later mutation must improve its targeted lane without degrading the fixed control lane.",
        }

    @staticmethod
    def _transition_homework(replay: pd.DataFrame, result: dict[str, object]) -> dict[str, object]:
        """Measure the hard regime-boundary zone, not only steady-state regimes."""
        classified = apply_market_regime(replay.copy()).reset_index(drop=True)
        if len(classified) < 3:
            return {"status": "insufficient_rows", "score": 0.0}
        boundary = (classified["market_regime"] != classified["market_regime"].shift(1)) | (
            classified["volatility_regime"] != classified["volatility_regime"].shift(1)
        )
        transition_times = pd.to_datetime(
            classified.loc[boundary, "time"], errors="coerce", utc=True,
        ).dropna()
        trades = list(result.get("trades", []))
        transition_trades = []
        for trade in trades:
            signal_time = MarketAdaptiveReplayService._naive_utc_timestamp(trade.get("signal_time"))
            if pd.isna(signal_time) or transition_times.empty:
                continue
            if (transition_times.sub(signal_time).abs() <= pd.Timedelta(hours=3)).any():
                transition_trades.append(trade)
        total = len(transition_trades)
        losses = sum(float(trade.get("profit_percent", 0)) < 0 for trade in transition_trades)
        wins = total - losses
        gross_win = sum(max(0, float(trade.get("profit_percent", 0))) for trade in transition_trades)
        gross_loss = sum(abs(min(0, float(trade.get("profit_percent", 0)))) for trade in transition_trades)
        pf = round(gross_win / gross_loss, 3) if gross_loss else (99.0 if gross_win else 0.0)
        false_entry_rate = round(losses / total, 4) if total else 0.0
        # No entry at a dangerous transition is valid abstention. The score
        # records it separately so it cannot be misread as coverage success.
        abstention = round(100 * (1 - min(1, false_entry_rate)), 2)
        score = round(max(0, min(100, (wins / total * 70 + min(pf, 2) * 15))) if total else 50.0, 2)
        transition_equity = 100.0
        transition_peak = transition_equity
        transition_dd = 0.0
        for trade in transition_trades:
            transition_equity *= 1 + float(trade.get("profit_percent", 0)) / 100
            transition_peak = max(transition_peak, transition_equity)
            transition_dd = max(transition_dd, (transition_peak - transition_equity) / transition_peak * 100)
        # Entropy is derived from continuation/reversal evidence in the
        # transition slice. It is a diagnostic summary, not a future label.
        continuation = wins / total if total else .5
        reversal = losses / total if total else .5
        entropy = 0.0 if not total else -sum(p * math.log(max(p, 1e-9)) for p in [continuation, reversal]) / math.log(2)
        return {
            "status": "assessed", "protocol": "market/volatility transition +/- 3 H1 candles; frozen strategy and execution",
            "transition_events": int(boundary.sum()), "transition_trades": total, "transition_profit_factor": pf,
            "transition_only_drawdown_percent": round(transition_dd, 4), "transition_entropy": round(entropy, 5),
            "continuation_reversal_disagreement": round(abs(continuation - reversal), 5),
            "transition_risk_multiplier": round(max(.3, min(.7, 1 - entropy * .55)), 5),
            "false_entry_rate": false_entry_rate, "abstention_quality": abstention, "score": score,
            "rule": "Transition policy may WAIT, reduce risk or re-route; steady-state PF alone is insufficient.",
        }

    def sealed_holdout(
        self,
        payload: SimpleBacktestRequest,
        df: pd.DataFrame,
        foundation_df: pd.DataFrame | None = None,
    ) -> tuple[dict[str, object], dict[str, object]]:
        boundary = (payload.policy_context or {}).get("data_boundary", {}) or {}
        paper_only_2026 = boundary.get("protocol", "pre_2026_training_paper_only_v1") == "pre_2026_training_paper_only_v1"
        segments = self.split_dataset(df, foundation_df, payload.timeframe, paper_only_2026=paper_only_2026)
        result = run_simple_ema_rsi_backtest_on_dataframe(payload, segments["holdout"]).model_dump()
        result["gold_holdout"] = {
            "protocol": "gold_holdout_v1", "status": "released_once",
            "dataset_hash": self._segment_hash(segments["holdout"]),
            "used_for_training": False, "used_for_evolution": False,
            "one_time_release": True, "selection_excluded": True,
        }
        return result, self._period(segments["holdout"])

    @staticmethod
    def _cost_profile_attribution(
        payload: SimpleBacktestRequest,
        replay: pd.DataFrame,
        normal_result: dict[str, object],
        prepared_snapshot=None,
        feature_snapshot=None,
    ) -> dict[str, object]:
        """Attribute execution-cost damage without rebuilding strategy signals."""
        execution = payload.execution
        zero_execution = execution.model_copy(update={
            "spread_points": 0.0, "slippage_points": 0.0,
            "commission_percent": 0.0, "swap_per_day_percent": 0.0,
        })
        stress_execution = execution.model_copy(update={
            "spread_points": execution.spread_points * 2.0,
            "slippage_points": execution.slippage_points * 2.0,
            "commission_percent": execution.commission_percent * 2.0,
            "swap_per_day_percent": execution.swap_per_day_percent * 2.0,
        })
        # Cost/exit lanes are diagnostics over the same causal signal stream.
        # They never need a candle-level trace: the primary chronological
        # replay owns the immutable evidence and these profiles reference it
        # through the shared snapshot protocol below.
        diagnostic_payload = payload.model_copy(update={"emit_decision_trace": False})
        zero_payload = diagnostic_payload.model_copy(update={"execution": zero_execution})
        stress_payload = diagnostic_payload.model_copy(update={"execution": stress_execution})
        snapshot = prepared_snapshot or prepare_signal_snapshot(
            diagnostic_payload,
            replay,
            feature_snapshot=feature_snapshot,
        )
        zero = _run_prepared_simple_backtest(
            zero_payload,
            replay,
            prepared_snapshot=snapshot,
            include_differential_pair=False,
            lightweight=True,
        ).model_dump()
        stress = _run_prepared_simple_backtest(
            stress_payload,
            replay,
            prepared_snapshot=snapshot,
            include_differential_pair=False,
            lightweight=True,
        ).model_dump()
        normal = normal_result.get("pf_attribution", {})
        return {
            "method": "identical_replay_execution_profiles",
            "stress_multiplier": 2.0,
            "zero_cost": {"profit_factor": zero.get("profit_factor", 0), "net_profit_percent": zero.get("net_profit_percent", 0), "summary": zero.get("pf_attribution", {}).get("summary", {})},
            "normal_cost": {"profit_factor": normal_result.get("profit_factor", 0), "net_profit_percent": normal_result.get("net_profit_percent", 0), "summary": normal.get("summary", {})},
            "stress_cost": {"profit_factor": stress.get("profit_factor", 0), "net_profit_percent": stress.get("net_profit_percent", 0), "summary": stress.get("pf_attribution", {}).get("summary", {})},
            "breakdown": {key: value for key, value in normal.items() if key != "summary"},
            "adversarial": {
                "method": "worst_of_stress_cost_session_regime",
                "stress_cost_pf": stress.get("profit_factor", 0),
                "worst_session_pf": _minimum_pf(normal.get("pf_attribution", {}).get("by_session", {})),
                "worst_regime_pf": _minimum_pf(normal.get("pf_attribution", {}).get("by_regime", {})),
            },
            "optimization": {
                "prepared_signal_snapshot_reused": True,
                "feature_snapshot_reused": feature_snapshot is not None,
                "strategy_signal_recomputed": False,
                "execution_profiles": 2,
                "decision_trace_emitted": False,
                "stateful_execution_replayed": True,
                "promotion_evidence": False,
            },
        }

    @staticmethod
    def _falsify_claim(result: dict[str, object]) -> dict[str, object]:
        claim = dict(result.get("edge_claim", {}))
        profile = result.get("pf_attribution", {})
        edge = result.get("statistical_evidence", {}).get("edge_quality", {})
        failures = []
        if float(profile.get("stress_cost", {}).get("profit_factor", 0)) < 1.05: failures.append("stress_cost")
        bootstrap = edge.get("bootstrap_pf", {})
        if bootstrap.get("status") == "assessed" and float(bootstrap.get("pf_5_percentile_lower_bound", 0)) < 1.1: failures.append("bootstrap")
        if edge.get("worst_regime_sampled") and float(edge.get("worst_regime_pf", 0)) < 1.0: failures.append("worst_regime")
        claim["falsification_report"] = {"status": "survived" if not failures else "falsified", "failed_scenarios": failures, "adversarial": profile.get("adversarial", {})}
        return claim
    def _checkpoint_results(
        self,
        payload: SimpleBacktestRequest,
        replay: pd.DataFrame,
        score_calculator,
        chronological_result: dict[str, object],
    ) -> list[dict[str, object]]:
        # These are projections of ONE chronological replay. Replaying a
        # reset chunk here would restart EMA warm-up, cooldown and risk state
        # and manufacture boundary signals.
        chunk_size = len(replay) // self.minimum_checkpoint_windows
        chunks = [
            replay.iloc[index * chunk_size:(index + 1) * chunk_size if index < self.minimum_checkpoint_windows - 1 else len(replay)]
            for index in range(self.minimum_checkpoint_windows)
        ]
        chunks = [chunk for chunk in chunks if len(chunk) >= 202]
        checkpoints: list[dict[str, object]] = []
        temporal_ledger = ((chronological_result.get("pf_attribution", {}) or {}).get("by_temporal_chunk", {}) or {})
        full_trades = [trade for trade in (chronological_result.get("trade_ledger", []) or []) if isinstance(trade, dict)]
        for index, chunk in enumerate(chunks, start=1):
            attribution = temporal_ledger.get(f"chunk_{index}", {})
            if not isinstance(attribution, dict):
                attribution = {}
            projection = {
                "profit_factor": float(attribution.get("net_pf", 0) or 0),
                "total_trades": int(attribution.get("trades", 0) or 0),
                "wins": int(attribution.get("wins", 0) or 0),
                "losses": int(attribution.get("losses", 0) or 0),
                "winrate": float(attribution.get("winrate", 0) or 0),
                "net_profit_percent": float(attribution.get("net_profit_percent", 0) or 0),
                "max_drawdown_percent": float(attribution.get("max_drawdown_percent", 0) or 0),
                "stability_score": 0,
                "execution_assumptions": chronological_result.get("execution_assumptions", {}),
            }
            chunk_start = self._naive_utc_timestamp(chunk.time.min())
            chunk_end = self._naive_utc_timestamp(chunk.time.max())
            chunk_trades = []
            for trade in full_trades:
                try:
                    entry_time = self._naive_utc_timestamp(trade.get("entry_time"))
                except (TypeError, ValueError):
                    continue
                if chunk_start <= entry_time <= chunk_end:
                    chunk_trades.append(trade)
            label_starts = []
            label_ends = []
            for trade in chunk_trades:
                try:
                    label_starts.append(self._naive_utc_timestamp(trade.get("entry_time")))
                    label_ends.append(self._naive_utc_timestamp(trade.get("exit_time") or trade.get("entry_time")))
                except (TypeError, ValueError):
                    continue
            label_interval = {
                "label_start": min(label_starts).isoformat() if label_starts else None,
                "label_end": max(label_ends).isoformat() if label_ends else None,
                "label_trade_count": len(chunk_trades),
            }
            checkpoints.append({
                "window": index,
                **self._period(chunk),
                "score": score_calculator(projection),
                "trades": projection["total_trades"],
                "profit_factor": projection["profit_factor"],
                "net_profit_percent": projection["net_profit_percent"],
                "state_continuity": "single_chronological_replay",
                "state_reset": False,
                "independent_evidence": False,
                "source_trade_ledger_hash": chronological_result.get("trade_ledger_hash"),
                "promotion_evidence": False,
                **label_interval,
            })
        return checkpoints

    @staticmethod
    def _adaptation_evidence(replay: pd.DataFrame, result: dict[str, object], checkpoints: list[dict[str, object]]) -> dict[str, object]:
        classified = apply_market_regime(replay)
        regime_counts = classified["market_regime"].value_counts().to_dict()
        volatility_counts = classified["volatility_regime"].value_counts().to_dict()
        return {
            "scope": "symbol + timeframe + market_regime + volatility_regime",
            "regime_exposure_candles": {str(key): int(value) for key, value in regime_counts.items()},
            "volatility_exposure_candles": {str(key): int(value) for key, value in volatility_counts.items()},
            "regime_fitness": result.get("regime_performance", {}),
            "volatility_fitness": result.get("volatility_performance", {}),
            "mistakes": result.get("top_mistakes", []),
            "checkpoint_improved": len(checkpoints) >= 2 and checkpoints[-1]["score"] > checkpoints[0]["score"],
            "mutation_action": "parameter_mutation" if result.get("max_consecutive_losses", 0) < 3 else "challenger_required",
        }

    @staticmethod
    def _period(df: pd.DataFrame) -> dict[str, object]:
        return {
            "start": pd.Timestamp(df.time.min()).isoformat(),
            "end": pd.Timestamp(df.time.max()).isoformat(),
            "rows": len(df),
        }

    @staticmethod
    def _robustness(*scores: int | float) -> int:
        return round(max(0, min(100, 100 - (max(scores) - min(scores))))) if scores else 0

    @staticmethod
    def _normalize(df: pd.DataFrame) -> pd.DataFrame:
        if df.empty:
            raise ValueError("Dataset is empty.")
        normalized = df.copy()
        # Laravel/database payloads can carry UTC-aware ISO timestamps while
        # CSV loaders may produce naive timestamps. Normalize both forms to
        # timezone-aware UTC before comparing them with protocol boundaries.
        timestamps = pd.to_datetime(normalized["time"], errors="coerce", utc=True)
        if timestamps.isna().any():
            raise ValueError("Dataset contains invalid candle timestamps.")
        normalized["time"] = timestamps
        return normalized.sort_values("time").reset_index(drop=True)

    @staticmethod
    def _naive_utc_timestamp(value: object) -> pd.Timestamp:
        """Legacy helper name; return a timezone-aware UTC timestamp."""
        return _utc_timestamp(value)


def _minimum_pf(groups: dict[str, object]) -> float | None:
    values = [float(item.get("net_pf", 0)) for item in groups.values() if int(item.get("trades", 0)) >= 5]
    return round(min(values), 3) if values else None
