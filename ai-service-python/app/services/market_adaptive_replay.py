"""Leakage-safe, instrument-local historical replay protocol.

The protocol deliberately uses calendar dates only to define the experiment
boundary.  Selection and adaptation evidence is always keyed by the market
state observed on a candle (symbol, regime and volatility), never by a date.
"""

from dataclasses import dataclass
import math

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.market_regime import apply_market_regime
from app.services.red_team import RedTeamService
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
        foundation_result = run_simple_ema_rsi_backtest_on_dataframe(payload, segments["foundation"]).model_dump()
        replay_result = run_simple_ema_rsi_backtest_on_dataframe(payload, segments["replay"]).model_dump()
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
        monthly_walk_forward = self._monthly_walk_forward(payload, segments["replay"], score_calculator)
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
            outcome = run_simple_ema_rsi_backtest_on_dataframe(payload.model_copy(update={"execution": execution}), replay).model_dump()
            results.append(float(outcome.get("profit_factor", 0)) >= 1.0)
        return {
            "status": "passed" if all(results) else "failed", "evaluated_scenarios": len(results),
            "rotation_commitment": hashlib.sha256(f"{seed}|{len(results)}".encode()).hexdigest(),
            "rule": "Scenario parameters remain hidden from the mutation policy until the next rotation.",
        }

    @staticmethod
    def _execution_digital_twin(payload: SimpleBacktestRequest, replay: pd.DataFrame, normal: dict[str, object]) -> dict[str, object]:
        """Deterministic adverse execution scenarios using the replay contract.

        Partial-fill, broker-rejection and disconnect behaviours are explicitly
        marked unavailable until the provider exposes such immutable events;
        no imagined fill result is presented as market evidence.
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
            tested = run_simple_ema_rsi_backtest_on_dataframe(payload.model_copy(update={"execution": profile}), replay).model_dump()
            scenarios[name] = {
                "status": "assessed", "profit_factor": tested.get("profit_factor", 0),
                "net_profit_percent": tested.get("net_profit_percent", 0),
                "max_drawdown_percent": tested.get("max_drawdown_percent", 0),
                "cost_monotonic": float(tested.get("net_profit_percent", 0)) <= normal_net + 1e-9,
            }
        latency = run_simple_ema_rsi_backtest_on_dataframe(payload, replay.iloc[1:].reset_index(drop=True)).model_dump() if len(replay) > 203 else None
        scenarios["one_candle_latency"] = {
            "status": "assessed" if latency else "insufficient_rows",
            "profit_factor": latency.get("profit_factor", 0) if latency else None,
            "net_profit_percent": latency.get("net_profit_percent", 0) if latency else None,
            "rule": "Delayed dataset start is a conservative availability check, not a substitute for per-order latency replay.",
        }
        for unavailable in ["partial_fill", "rejected_order", "stale_candle", "disconnect", "gap_during_stop", "provider_disagreement"]:
            scenarios[unavailable] = {"status": "waiting_for_immutable_provider_event", "safe_behavior": "WAIT_OR_CANCEL"}
        assessed = [item for item in scenarios.values() if item.get("status") == "assessed"]
        return {
            "status": "assessed" if assessed else "waiting_for_provider_events",
            "execution_contract": "closed candle decision -> next candle open fill -> conservative intrabar exit",
            "scenarios": scenarios,
            "rule": "Unobservable broker failures remain pending; they are never simulated into a pass.",
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
        scaled_result = run_simple_ema_rsi_backtest_on_dataframe(payload.model_copy(update={"execution": scaled_execution}), scaled).model_dump()
        original_directions = [trade.get("direction") for trade in normal.get("trades", [])]
        scaled_directions = [trade.get("direction") for trade in scaled_result.get("trades", [])]
        return {
            "status": "assessed",
            "price_scale": {"status": "passed" if original_directions == scaled_directions else "failed",
                            "rule": "Price scaling must not invert the visible signal direction."},
            "cost_monotonicity": {"status": "delegated", "reference": "execution_digital_twin.variable_spread"},
            "provider_absence": {"status": "safe_wait_required", "rule": "No canonical provider candle means WAIT; no fallback trade is allowed."},
        }

    def _monthly_walk_forward(
        self, payload: SimpleBacktestRequest, replay: pd.DataFrame, score_calculator,
    ) -> dict[str, object]:
        """Expanding monthly Time Machine without test-month feedback leakage.

        Each test month gets a parameter policy frozen before its first
        candle.  Its outcome is reported only after the full month closes;
        callers may use it for the *next* generation/month, never that month.
        """
        if replay.empty:
            return {"status": "insufficient_history", "windows": []}
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
            payload.model_copy(update={"execution": zero_execution}), replay
        ).model_dump()
        stress = run_simple_ema_rsi_backtest_on_dataframe(
            payload.model_copy(update={"execution": stress_execution}), replay
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
            result = run_simple_ema_rsi_backtest_on_dataframe(payload, chunk.reset_index(drop=True)).model_dump()
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
