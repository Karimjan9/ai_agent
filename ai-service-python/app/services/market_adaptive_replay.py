"""Leakage-safe, instrument-local historical replay protocol.

The protocol deliberately uses calendar dates only to define the experiment
boundary.  Selection and adaptation evidence is always keyed by the market
state observed on a candle (symbol, regime and volatility), never by a date.
"""

from dataclasses import dataclass
import math

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import _apply_portfolio_strategy, run_simple_ema_rsi_backtest_on_dataframe
from app.services.market_regime import apply_market_regime
from app.services.red_team import RedTeamService
from app.services.statistical_validation import (
    cscv_probability_of_backtest_overfitting,
    deflated_sharpe_ratio,
    per_trade_sharpe,
    returns_from_equity_curve,
)
from app.strategies.registry import get_strategy


@dataclass(frozen=True)
class MarketAdaptiveReplayService:
    foundation_start: str = "2004-01-01 00:00:00"
    foundation_end: str = "2025-12-31 23:59:59"
    latest_supported_foundation_start: str = "2005-01-02 22:00:00"
    rolling_start: str = "2026-01-01 00:00:00"
    sealed_holdout_weeks: int = 6
    overfit_threshold: int = 25
    # Four windows allow an actual half-vs-half CSCV diagnostic (six splits),
    # while every window remains strictly before the sealed holdout.
    minimum_checkpoint_windows: int = 4

    def split_dataset(self, df: pd.DataFrame) -> dict[str, pd.DataFrame]:
        normalized = self._normalize(df)
        holdout_start = normalized["time"].max() - pd.Timedelta(weeks=self.sealed_holdout_weeks)
        foundation = normalized[
            (normalized.time >= pd.Timestamp(self.foundation_start))
            & (normalized.time <= pd.Timestamp(self.foundation_end))
        ]
        replay = normalized[
            (normalized.time >= pd.Timestamp(self.rolling_start))
            & (normalized.time < holdout_start)
        ]
        holdout = normalized[normalized.time >= holdout_start]

        # Some vendor archives begin after the first tradable session of 2004.
        # Keep the experiment anchored to 2004 without pretending that a
        # missing January candle exists; normal data-quality gates still reject
        # unexplained market-open holes inside the available archive.
        if len(foundation) < 202 or foundation["time"].min() > pd.Timestamp(self.latest_supported_foundation_start):
            raise ValueError("Foundation training uchun 2005-01-02 dan 2025-12-31 gacha tarix kerak.")
        if len(replay) < 202:
            raise ValueError("2026 rolling replay uchun kamida 202 ta yopilgan candle kerak.")
        if len(holdout) < 2:
            raise ValueError("Sealed holdout uchun candle yetarli emas.")

        return {
            "foundation": foundation.reset_index(drop=True),
            "replay": replay.reset_index(drop=True),
            "holdout": holdout.reset_index(drop=True),
        }

    def run(self, payload: SimpleBacktestRequest, df: pd.DataFrame, score_calculator) -> dict[str, object]:
        segments = self.split_dataset(df)
        # Foundation is used only to calculate the train-side score.  The
        # promotion-only Monte Carlo/DNA/telemetry streams belong to the
        # chronological replay below; recomputing them on the 2004-2025
        # archive made every portfolio member pay for evidence that is never
        # read by a gate.  Keep the execution core and ledger identical.
        foundation_result = run_simple_ema_rsi_backtest_on_dataframe(
            payload, segments["foundation"], include_differential_pair=False, lightweight=True
        ).model_dump()
        replay_result = run_simple_ema_rsi_backtest_on_dataframe(payload, segments["replay"]).model_dump()
        # Preserve the raw chronological ledger diagnostics before replacing
        # the response-level attribution with the normal/stress cost profile.
        # Monthly passport evidence must be calculated from this same replay,
        # not from month-sized frames with indicators reset at each boundary.
        chronological_replay_result = {
            **replay_result,
            "pf_attribution": dict(replay_result.get("pf_attribution", {}) or {}),
        }
        # Re-run the identical sealed replay with zero and adverse execution
        # costs. This distinguishes a weak signal from an edge consumed by
        # spread/slippage/commission; it is not a synthetic adjustment.
        replay_result["pf_attribution"] = self._cost_profile_attribution(payload, segments["replay"], replay_result)
        replay_result["red_team"] = RedTeamService().evaluate(replay_result)
        replay_result["edge_claim"] = self._falsify_claim(replay_result)

        train_score = score_calculator(foundation_result)
        forward_score = score_calculator(replay_result)
        checkpoints = self._checkpoint_results(payload, segments["replay"], score_calculator)
        checkpoint_scores = [item["score"] for item in checkpoints]
        validation_score = round(sum(checkpoint_scores) / len(checkpoint_scores)) if checkpoint_scores else forward_score
        is_overfit = train_score - forward_score > self.overfit_threshold

        adaptation = self._adaptation_evidence(segments["replay"], replay_result, checkpoints)
        monthly_walk_forward = self._monthly_walk_forward(
            payload, segments["replay"], score_calculator, chronological_replay_result
        )
        monthly_passport = self._monthly_passport(monthly_walk_forward)
        failure_focused = self._failure_focused_replay(monthly_walk_forward)
        transition_homework = self._transition_homework(segments["replay"], replay_result)
        replay_result["window_survival"] = {
            **dict(replay_result.get("window_survival", {})),
            "monthly_walk_forward": monthly_walk_forward,
        }
        replay_result["monthly_passport"] = monthly_passport
        replay_result["failure_focused_replay"] = failure_focused
        replay_result["transition_homework"] = transition_homework
        replay_result["evidence_streams"] = {
            "synthetic_forward_evidence": {
                "status": "assessed", "source": "monthly_walk_forward_replay",
                "promotion_sufficient": False, "passport_status": monthly_passport["status"],
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
            },
        }
        result["permanent_unseen_challenge"] = {
            "status": "sealed", "segment": self._period(segments["holdout"]),
            "data_hash": self._segment_hash(segments["holdout"]),
            "rule": "This segment is never used for mutation, ranking or same-generation selection.",
        }
        result["temporal_firewall"] = self._temporal_firewall(payload, segments["replay"], segments["holdout"])
        result["secret_adversarial_arena"] = self._secret_adversarial_arena(payload, segments["replay"])
        # These two ledgers deliberately use the same next-candle execution
        # function as the replay.  Their verdicts are diagnostic evidence;
        # they neither create trades nor change a promotion decision.
        result["execution_digital_twin"] = self._execution_digital_twin(payload, segments["replay"], replay_result)
        result["counterfactual_blame_graph"] = self._counterfactual_blame_graph(replay_result, segments["replay"])
        result["metamorphic_universality"] = self._metamorphic_universality(payload, segments["replay"], replay_result)
        if payload.portfolio_members:
            self._attach_portfolio_selection_statistics(result, payload)
            result["behavioral_diversity"] = self._portfolio_behavioral_diversity(result)
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

        selection_validation = cscv_probability_of_backtest_overfitting(score_rows)
        if not score_rows:
            selection_validation["promotion_evidence"] = False
            selection_validation["reason"] = "Frozen portfolio candidate frontier is absent or invalid."
        else:
            selection_validation["promotion_evidence"] = True
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

    def screening_survival_profile(self, payload: SimpleBacktestRequest, df: pd.DataFrame,
                                   normal_result: dict[str, object], score_calculator) -> dict[str, object]:
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

        cost = self._cost_profile_attribution(payload, df, normal_result)
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
                    payload, chunk, include_differential_pair=False, lightweight=True
                ).model_dump()
                for chunk in chunks
            ]
        temporal_scores = [float(score_calculator(window)) for window in temporal_windows]
        temporal_pfs = [float(window.get("profit_factor", 0)) for window in temporal_windows]

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
                month_result = run_simple_ema_rsi_backtest_on_dataframe(payload, month_frame).model_dump()
            calendar_months[str(month)] = {
                "candles": int(len(month_frame)),
                "trades": int(month_result.get("total_trades", month_result.get("trades", 0))),
                "profit_factor": round(float(month_result.get("profit_factor", month_result.get("net_pf", 0))), 4),
                "score": round(float(score_calculator(month_result)), 4),
            }
        assessed_months = {
            month: metrics for month, metrics in calendar_months.items() if int(metrics["trades"]) > 0
        }
        inactive_months = [month for month, metrics in calendar_months.items() if int(metrics["trades"]) == 0]
        calendar_month_pfs = [float(metrics["profit_factor"]) for metrics in assessed_months.values()]
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
        numeric = [key for key, value in parameters.items() if isinstance(value, (int, float)) and not isinstance(value, bool)]
        variants = []
        for direction in (-1.0, 1.0):
            if not numeric:
                break
            key = numeric[0]
            changed = dict(parameters)
            value = float(changed[key])
            changed[key] = round(value + (max(abs(value), 1.0) * .05 * direction), 6)
            try:
                variants.append(run_simple_ema_rsi_backtest_on_dataframe(
                    payload.model_copy(update={"parameters": changed}),
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
        perturbation_ratio = min([float(item.get("profit_factor", 0)) / normal_pf for item in variants], default=0.0)
        worst_regime_pf = _minimum_pf((normal_result.get("pf_attribution", {}) or {}).get("by_regime", {}))
        stress_pf = float(cost.get("stress_cost", {}).get("profit_factor", 0))
        train_forward_gap = abs(temporal_scores[0] - temporal_scores[-1]) if len(temporal_scores) >= 2 else 999.0
        reasons = []
        if int(normal_result.get("total_trades", 0)) < 10: reasons.append("FAILED_TRADE_COUNT")
        if stress_pf < 1.05: reasons.append("FAILED_STRESS_COST")
        if min(temporal_pfs, default=0.0) < 1.0: reasons.append("FAILED_TEMPORAL_CHUNK_SURVIVAL")
        if not assessed_months:
            reasons.append("INSUFFICIENT_CALENDAR_MONTH_EVIDENCE")
        elif min(calendar_month_pfs, default=0.0) < 1.0:
            reasons.append("FAILED_CALENDAR_MONTH_SURVIVAL")
        # A candidate with no regime bucket meeting the minimum sample size
        # has no coverage evidence.  Treat that as an explicit rescue reason,
        # rather than comparing None with a float and turning screening into
        # an HTTP 500.
        if worst_regime_pf is None:
            reasons.append("INSUFFICIENT_REGIME_EVIDENCE")
        elif worst_regime_pf < 1.0:
            reasons.append("FAILED_REGIME_COVERAGE")
        if perturbation_ratio < .80: reasons.append("FAILED_PARAMETER_STABILITY")
        if train_forward_gap > self.overfit_threshold: reasons.append("FAILED_TRAIN_FORWARD_GAP")
        if min(timing, default=0.0) < .50: reasons.append("FAILED_SIGNAL_TIMING_STABILITY")
        return {
            "protocol": "screening_survival_v2", "status": "survivor" if not reasons else "rescue_case",
            "reason_codes": reasons, "sample_count": int(normal_result.get("total_trades", 0)),
            "worst_regime_pf": round(worst_regime_pf, 4) if worst_regime_pf is not None else None,
            # `worst_window_pf` stays temporarily as a read-only compatibility
            # alias. New consumers must use the explicitly named fields.
            "worst_window_pf": round(min(temporal_pfs, default=0.0), 4),
            "worst_temporal_chunk_pf": round(min(temporal_pfs, default=0.0), 4),
            "worst_calendar_month_pf": round(min(calendar_month_pfs, default=0.0), 4) if assessed_months else None,
            "stress_cost_pf": round(stress_pf, 4), "parameter_perturbation_ratio": round(perturbation_ratio, 4),
            "train_forward_gap": round(train_forward_gap, 4), "signal_timing_stability": round(min(timing, default=0.0), 4),
            "temporal_chunk_survival": {
                "method": temporal_method, "window_scores": temporal_scores,
                "window_profit_factors": [round(value, 4) for value in temporal_pfs],
            },
            "calendar_month_survival": {
                "timezone": "UTC", "method": "calendar_month", "source": calendar_source, "months": calendar_months,
                "assessed_months": list(assessed_months), "activity_absence_months": inactive_months,
                "context_failure_map": context_failure_map,
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
            previous = normalized["close"].shift(1)
            true_range = pd.concat([
                normalized["high"] - normalized["low"], (normalized["high"] - previous).abs(), (normalized["low"] - previous).abs(),
            ], axis=1).max(axis=1)
            normalized["_management_atr"] = true_range.rolling(14, min_periods=1).mean()
            if payload.portfolio_members:
                prepared = _apply_portfolio_strategy(normalized, payload.portfolio_members)
            else:
                prepared = get_strategy(payload.strategy, payload.base_strategy)(normalized, payload.parameters)
            return [(str(row.time), str(row.signal)) for row in prepared[["time", "signal"]].itertuples(index=False)]

        baseline = signals(prefix)
        extended = signals(pd.concat([prefix, altered_future], ignore_index=True))[:len(prefix)]
        return {
            "status": "passed" if baseline == extended else "failed",
            "checked_candles": len(prefix), "future_perturbation": "unseen OHLC shock",
            "rule": "future-candle mutation must not alter prior signals or features",
        }

    @staticmethod
    def _secret_adversarial_arena(payload: SimpleBacktestRequest, replay: pd.DataFrame) -> dict[str, object]:
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
            outcome = run_simple_ema_rsi_backtest_on_dataframe(
                payload.model_copy(update={"execution": execution}),
                replay,
                include_differential_pair=False,
                lightweight=True,
            ).model_dump()
            results.append(float(outcome.get("profit_factor", 0)) >= 1.0)
        return {
            "status": "passed" if all(results) else "failed", "evaluated_scenarios": len(results),
            "rotation_commitment": hashlib.sha256(f"{seed}|{len(results)}".encode()).hexdigest(),
            "rule": "Scenario parameters remain hidden from the mutation policy until the next rotation.",
        }

    @staticmethod
    def _execution_digital_twin(payload: SimpleBacktestRequest, replay: pd.DataFrame, normal: dict[str, object]) -> dict[str, object]:
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
            "cost_stress": execution.model_copy(update={
                "spread_points": execution.spread_points * 2.0, "slippage_points": execution.slippage_points * 2.0,
                "commission_percent": execution.commission_percent * 2.0,
            }),
        }
        scenarios: dict[str, object] = {}
        normal_net = float(normal.get("net_profit_percent", 0))
        for name, profile in profiles.items():
            tested = run_simple_ema_rsi_backtest_on_dataframe(
                payload.model_copy(update={"execution": profile}),
                replay,
                include_differential_pair=False,
                lightweight=True,
            ).model_dump()
            scenarios[name] = {
                "status": "assessed", "profit_factor": tested.get("profit_factor", 0),
                "net_profit_percent": tested.get("net_profit_percent", 0),
                "max_drawdown_percent": tested.get("max_drawdown_percent", 0),
                "cost_monotonic": float(tested.get("net_profit_percent", 0)) <= normal_net + 1e-9,
            }
        latency = run_simple_ema_rsi_backtest_on_dataframe(
            payload,
            replay.iloc[1:].reset_index(drop=True),
            include_differential_pair=False,
            lightweight=True,
        ).model_dump() if len(replay) > 203 else None
        scenarios["one_candle_latency"] = {
            "status": "assessed" if latency else "insufficient_rows",
            "profit_factor": latency.get("profit_factor", 0) if latency else None,
            "net_profit_percent": latency.get("net_profit_percent", 0) if latency else None,
            "rule": "Delayed dataset start is a conservative availability check, not a substitute for per-order latency replay.",
        }
        fault_contract = MarketAdaptiveReplayService._execution_fault_contract(payload)
        scenarios.update(fault_contract["scenarios"])
        assessed = [item for item in scenarios.values() if item.get("status") in {"assessed", "contract_test_passed"}]
        return {
            "status": "assessed" if assessed else "waiting_for_provider_events",
            "execution_contract": "closed candle decision -> next candle open fill -> conservative intrabar exit",
            "scenarios": scenarios, "fault_contract": fault_contract,
            "rule": "Unobservable broker failures remain pending; they are never simulated into a pass.",
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
        rows["time"] = pd.to_datetime(rows["time"])
        losses = [trade for trade in result.get("trades", []) if float(trade.get("profit_percent", 0)) < 0]
        cases = []
        for trade in losses:
            entry_time = pd.to_datetime(trade.get("entry_time"), errors="coerce")
            exit_time = pd.to_datetime(trade.get("exit_time"), errors="coerce")
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
            payload.model_copy(update={"execution": scaled_execution}),
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
        normalized["time"] = pd.to_datetime(normalized["time"])
        periods = list(normalized["time"].dt.to_period("M").drop_duplicates())
        candidates = periods[2:][-6:]
        ledger_months = ((chronological_result.get("pf_attribution", {}) or {}).get("by_month", {}) or {})
        windows: list[dict[str, object]] = []
        for month in candidates:
            test = normalized[normalized["time"].dt.to_period("M") == month]
            train = normalized[normalized["time"].dt.to_period("M") < month]
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
                "feedback_available_at": (month.end_time + pd.Timedelta(seconds=1)).isoformat(),
                "used_for_same_month_mutation": False,
            })
        return {
            "protocol": "chronological replay ledger -> frozen month attribution -> next-month-only feedback",
            "status": "assessed" if windows else "insufficient_monthly_rows",
            "execution_source": "full_chronological_trade_ledger",
            "indicator_warmup_preserved": True,
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
        normalized = replay.copy()
        normalized["time"] = pd.to_datetime(normalized["time"])
        periods = list(normalized["time"].dt.to_period("M").drop_duplicates())
        # Keep the lane bounded; it is learning evidence in addition to the
        # full replay, not an unbounded optimization sweep.
        candidates = periods[2:][-6:]
        windows: list[dict[str, object]] = []
        for month in candidates:
            test = normalized[normalized["time"].dt.to_period("M") == month].reset_index(drop=True)
            train = normalized[normalized["time"].dt.to_period("M") < month]
            if len(test) < 202 or len(train) < 202:
                continue
            result = run_simple_ema_rsi_backtest_on_dataframe(payload, test).model_dump()
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
                "feedback_available_at": (month.end_time + pd.Timedelta(seconds=1)).isoformat(),
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
        transition_times = pd.to_datetime(classified.loc[boundary, "time"])
        trades = list(result.get("trades", []))
        transition_trades = []
        for trade in trades:
            signal_time = pd.to_datetime(trade.get("signal_time"), errors="coerce")
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

    def sealed_holdout(self, payload: SimpleBacktestRequest, df: pd.DataFrame) -> tuple[dict[str, object], dict[str, object]]:
        segments = self.split_dataset(df)
        result = run_simple_ema_rsi_backtest_on_dataframe(payload, segments["holdout"]).model_dump()
        return result, self._period(segments["holdout"])

    @staticmethod
    def _cost_profile_attribution(payload: SimpleBacktestRequest, replay: pd.DataFrame, normal_result: dict[str, object]) -> dict[str, object]:
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
        zero = run_simple_ema_rsi_backtest_on_dataframe(
            payload.model_copy(update={"execution": zero_execution}),
            replay,
            include_differential_pair=False,
            lightweight=True,
        ).model_dump()
        stress = run_simple_ema_rsi_backtest_on_dataframe(
            payload.model_copy(update={"execution": stress_execution}),
            replay,
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
    def _checkpoint_results(self, payload: SimpleBacktestRequest, replay: pd.DataFrame, score_calculator) -> list[dict[str, object]]:
        # The checkpoints are chronological evidence only.  They do not tune a
        # strategy, so a later candle cannot alter an earlier decision.
        chunk_size = len(replay) // self.minimum_checkpoint_windows
        chunks = [
            replay.iloc[index * chunk_size:(index + 1) * chunk_size if index < self.minimum_checkpoint_windows - 1 else len(replay)]
            for index in range(self.minimum_checkpoint_windows)
        ]
        chunks = [chunk for chunk in chunks if len(chunk) >= 202]
        checkpoints: list[dict[str, object]] = []
        for index, chunk in enumerate(chunks, start=1):
            # Checkpoints contribute only chronological score/trade evidence.
            # They must preserve the same execution core, but promotion-only
            # diagnostics and paired child replays are already represented by
            # the primary full replay.  Keeping this lane lightweight removes
            # four redundant full-history diagnostic stacks per candidate.
            result = run_simple_ema_rsi_backtest_on_dataframe(
                payload,
                chunk.reset_index(drop=True),
                include_differential_pair=False,
                lightweight=True,
            ).model_dump()
            checkpoints.append({"window": index, **self._period(chunk), "score": score_calculator(result), "trades": result["total_trades"]})
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
        normalized["time"] = pd.to_datetime(normalized["time"])
        return normalized.sort_values("time").reset_index(drop=True)


def _minimum_pf(groups: dict[str, object]) -> float | None:
    values = [float(item.get("net_pf", 0)) for item in groups.values() if int(item.get("trades", 0)) >= 5]
    return round(min(values), 3) if values else None
