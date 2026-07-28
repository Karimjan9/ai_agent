"""Leakage-safe, instrument-local historical replay protocol.

The protocol deliberately uses calendar dates only to define the experiment
boundary.  Selection and adaptation evidence is always keyed by the market
state observed on a candle (symbol, regime and volatility), never by a date.
"""

from dataclasses import dataclass

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.market_regime import apply_market_regime
from app.services.red_team import RedTeamService


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
        replay_result["window_survival"] = {
            **dict(replay_result.get("window_survival", {})),
            "monthly_walk_forward": monthly_walk_forward,
        }
        replay_result["monthly_passport"] = monthly_passport
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
                "adaptation": adaptation,
            },
        }
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
