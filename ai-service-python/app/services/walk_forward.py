from dataclasses import dataclass
import hashlib
import json
import time
from typing import Callable

import pandas as pd

from app.schemas import SimpleBacktestRequest, SimpleTrade
from app.services.backtester import (
    PreparedReplayFeatureContext,
    PreparedSignalSnapshot,
    _assert_closed_mtf_runtime,
    _edge_quality_evidence,
    _management_evidence_report,
    _pf_attribution,
    _run_prepared_simple_backtest,
    _trade_ledger_hash,
    prepare_feature_snapshot,
    prepare_replay_feature_context,
    prepare_signal_snapshot,
    run_simple_ema_rsi_backtest_on_dataframe,
)


@dataclass(frozen=True)
class WalkForwardService:
    train_years: int = 8
    validation_years: int = 2
    forward_years: int = 2
    step_years: int = 2
    final_holdout_years: int = 2
    overfit_threshold: int = 25
    minimum_windows: int = 3

    @staticmethod
    def _causal_context_cache_key(payload: SimpleBacktestRequest) -> str:
        """Reuse only context compilation whose exact inputs are equivalent.

        The two excluded causal genes govern decision/execution after the
        higher-timeframe feature compiler. Every other parameter remains in
        the key, so a future context feature is fail-closed by default.
        """
        context_fields = {
            "symbol", "timeframe", "dataset_path", "replay_dataset_hash",
            "from_date", "to_date", "regime_candles", "regime_dataset_path",
            "regime_dataset_tail_rows", "mtf_streams", "mtf_dataset_paths",
            "mtf_dataset_tail_rows", "related_mtf_streams",
            "related_mtf_dataset_paths", "related_mtf_dataset_tail_rows",
            "mtf_snapshot_manifest", "mtf_pilot", "parameters",
        }
        identity = payload.model_dump(mode="json", include=context_fields)
        parameters = dict(identity.get("parameters") or {})
        parameters.pop("state_machine_variant", None)
        parameters.pop("minimum_signal_confidence", None)
        identity["parameters"] = parameters
        return hashlib.sha256(
            json.dumps(identity, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()

    def split_dataset(self, df: pd.DataFrame) -> dict[str, pd.DataFrame]:
        """Compatibility helper; production scoring uses rolling_windows()."""
        normalized = self._normalize(df)
        train_end = int(len(normalized) * 0.70)
        validation_end = train_end + int(len(normalized) * 0.15)
        if train_end == 0 or validation_end <= train_end or validation_end >= len(normalized):
            raise ValueError("Dataset is too small for walk-forward split.")
        return {
            "train": normalized.iloc[:train_end].reset_index(drop=True),
            "validation": normalized.iloc[train_end:validation_end].reset_index(drop=True),
            "forward": normalized.iloc[validation_end:].reset_index(drop=True),
        }

    def rolling_windows(
        self,
        df: pd.DataFrame,
        purge_bars: int = 0,
        embargo_bars: int = 1,
    ) -> tuple[list[dict[str, pd.DataFrame]], pd.DataFrame]:
        normalized = self._normalize(df)
        purge_bars = max(0, int(purge_bars))
        embargo_bars = max(1, int(embargo_bars))
        first = normalized["time"].min()
        holdout_start = normalized["time"].max() - pd.DateOffset(years=self.final_holdout_years)
        windows: list[dict[str, pd.DataFrame]] = []
        cursor = first

        while True:
            train_end = cursor + pd.DateOffset(years=self.train_years)
            validation_end = train_end + pd.DateOffset(years=self.validation_years)
            forward_end = validation_end + pd.DateOffset(years=self.forward_years)
            if forward_end > holdout_start:
                break

            window = self._purge_segments({
                "train": normalized[(normalized.time >= cursor) & (normalized.time < train_end)],
                "validation": normalized[(normalized.time >= train_end) & (normalized.time < validation_end)],
                "forward": normalized[(normalized.time >= validation_end) & (normalized.time < forward_end)],
            }, purge_bars, embargo_bars)
            if all(len(segment) >= 2 for segment in window.values()):
                windows.append({key: value.reset_index(drop=True) for key, value in window.items()})
            cursor += pd.DateOffset(years=self.step_years)

        holdout = normalized[normalized.time >= holdout_start].reset_index(drop=True)
        if len(windows) >= self.minimum_windows:
            return windows, holdout

        # Historical vendor archives can contain multi-month gaps. A strict
        # calendar split would reject otherwise valid data solely because one
        # calendar interval has no candles. Preserve chronology and the final
        # untouched two-year holdout, then build expanding rolling windows by
        # observed rows before that holdout.
        row_windows = self._row_rolling_windows(normalized, holdout_start, purge_bars, embargo_bars)
        if len(row_windows) < self.minimum_windows:
            raise ValueError(
                "Rolling walk-forward uchun kamida 3 ta oyna va 2 yillik final holdout kerak."
            )

        return row_windows, holdout

    def _row_rolling_windows(
        self,
        normalized: pd.DataFrame,
        holdout_start: pd.Timestamp,
        purge_bars: int = 0,
        embargo_bars: int = 1,
    ) -> list[dict[str, pd.DataFrame]]:
        selection = normalized[normalized.time < holdout_start].reset_index(drop=True)
        if len(selection) < 30:
            return []

        # Use one expanding training history and three *disjoint* validation /
        # forward blocks. The old 45/55/65% starts advanced by less than a
        # full block, so the reported forward windows overlapped and could not
        # be counted as independent evidence.
        initial_train_end = max(2, int(len(selection) * 0.45))
        remaining = len(selection) - initial_train_end
        # Three validation/forward pairs also need three one-row embargo
        # boundaries. Subtracting only two made the final forward end exceed
        # the archive by exactly one row for some dataset sizes, silently
        # reducing the fallback to two windows and rejecting a valid archive.
        segment_size = max(2, (remaining - 3) // 6)
        windows: list[dict[str, pd.DataFrame]] = []

        for index in range(3):
            validation_start = initial_train_end + index * (2 * segment_size + 1)
            validation_end = validation_start + segment_size
            # One observed-row embargo separates validation and forward. It
            # prevents a label that closes on the boundary from leaking into
            # the next forward block in sparse archives.
            forward_start = validation_end + 1
            forward_end = forward_start + segment_size
            if forward_end > len(selection):
                continue

            window = self._purge_segments({
                "train": selection.iloc[:validation_start].reset_index(drop=True),
                "validation": selection.iloc[validation_start:validation_end].reset_index(drop=True),
                "forward": selection.iloc[forward_start:forward_end].reset_index(drop=True),
            }, purge_bars, embargo_bars)
            if all(len(segment) >= 2 for segment in window.values()):
                windows.append(window)

        return windows

    def run(
        self,
        payload: SimpleBacktestRequest,
        df: pd.DataFrame,
        score_calculator,
        *,
        maximum_holding_bars: int | None = None,
        purge_bars: int | None = None,
        embargo_bars: int = 1,
    ) -> dict[str, object]:
        declared_horizon = max(0, int((payload.parameters or {}).get("time_stop_candles", 0) or 0))
        maximum_holding = max(1, int(maximum_holding_bars)) if maximum_holding_bars is not None else None
        effective_horizon = declared_horizon
        execution_payload = payload
        if maximum_holding is not None:
            effective_horizon = min(declared_horizon, maximum_holding) if declared_horizon > 0 else maximum_holding
            execution_payload = payload.model_copy(update={
                "parameters": {
                    **(payload.parameters or {}),
                    "time_stop_candles": effective_horizon,
                },
            })
        purge_bars = max(0, int(purge_bars)) if purge_bars is not None else effective_horizon
        embargo_bars = max(1, int(embargo_bars))
        windows, holdout = self.rolling_windows(df, purge_bars, embargo_bars)
        evaluations: list[dict[str, object]] = []

        for index, segments in enumerate(windows, start=1):
            results = {
                name: self._run_segment(execution_payload, segment, name)
                for name, segment in segments.items()
            }
            scores = {name: score_calculator(result) for name, result in results.items()}
            evaluations.append({
                "window": index,
                "periods": {
                    name: f"{segment.time.min().date()} - {segment.time.max().date()}"
                    for name, segment in segments.items()
                },
                "scores": scores,
                "results": results,
                "is_overfit": detect_overfit(scores["train"], scores["forward"], self.overfit_threshold),
            })

        train_score = round(sum(item["scores"]["train"] for item in evaluations) / len(evaluations))
        validation_score = round(sum(item["scores"]["validation"] for item in evaluations) / len(evaluations))
        forward_scores = [item["scores"]["forward"] for item in evaluations]
        forward_score = round(sum(forward_scores) / len(forward_scores))
        robustness_score = calculate_robustness_score(train_score, validation_score, *forward_scores)
        overfit_windows = sum(bool(item["is_overfit"]) for item in evaluations)
        is_overfit = overfit_windows > len(evaluations) / 2
        representative = dict(evaluations[-1]["results"]["forward"])
        forward_results = [item["results"]["forward"] for item in evaluations]
        representative["total_trades"] = sum(int(result.get("total_trades", 0)) for result in forward_results)
        representative["displayed_trade_count"] = len(representative.get("trades", []))
        representative["trade_ledger_scope"] = "latest forward window, last 20 trades; headline metrics aggregate all rolling forward windows"
        representative["profit_factor"] = min(float(result.get("profit_factor", 0)) for result in forward_results)
        representative["max_drawdown"] = max(float(result.get("max_drawdown", 0)) for result in forward_results)
        representative["max_drawdown_percent"] = max(
            float(result.get("max_drawdown_percent", result.get("max_drawdown", 0)))
            for result in forward_results
        )
        # Monte Carlo, DNA and promotion passports are deliberately absent
        # from these research-only folds. Publishing a synthetic 0/100 risk
        # value here would be worse than an explicit null: downstream causal
        # settlement consumes paired window economics, while the ordinary
        # champion gate is hard-withheld for all three registered arms.
        representative["monte_carlo"] = {
            "status": "deferred_causal_research_lane",
            "risk_of_ruin_percent": None,
            "promotion_evidence": False,
        }

        return {
            "train_score": train_score,
            "validation_score": validation_score,
            "forward_score": forward_score,
            "forward_window_scores": forward_scores,
            "rolling_windows_count": len(evaluations),
            "robustness_score": robustness_score,
            "is_overfit": is_overfit,
            "result": {
                **representative,
                "train_score": train_score,
                "validation_score": validation_score,
                "forward_score": forward_score,
                "forward_window_scores": forward_scores,
                "rolling_windows_count": len(evaluations),
                "robustness_score": robustness_score,
                "is_overfit": is_overfit,
                "walk_forward": {
                    "mode": "rolling",
                    "windows": evaluations,
                    "forward_window_protocol": self._forward_window_protocol(
                        evaluations,
                        purge_bars,
                        embargo_bars,
                        declared_horizon=declared_horizon,
                        effective_horizon=effective_horizon,
                        maximum_holding_bars=maximum_holding,
                    ),
                    "final_holdout": {
                        "period": f"{holdout.time.min().date()} - {holdout.time.max().date()}",
                        "rows": len(holdout),
                        "used_for_selection": False,
                    },
                },
            },
        }

    def run_causal_confirmation(
        self,
        payload: SimpleBacktestRequest,
        df: pd.DataFrame,
        score_calculator,
        *,
        maximum_holding_bars: int,
        purge_bars: int,
        embargo_bars: int = 1,
        total_budget_seconds: int = 720,
        per_fold_budget_seconds: int = 90,
        fold_count: int = 9,
        fold_offset: int = 0,
        fold_universe_count: int | None = None,
        max_rows_per_fold: int = 4096,
        audit_trace_rows: int = 512,
        minimum_trades_per_window: int = 8,
        progress_callback: Callable[[str, dict[str, object]], None] | None = None,
        context_cache: dict[str, PreparedReplayFeatureContext] | None = None,
    ) -> dict[str, object]:
        """Evaluate a bounded set of sealed chronological folds for causal credit.

        The genome is frozen and no parameter is fitted in this lane, so
        replaying every expanding train and validation segment provides no
        additional causal information.  Omitting those six redundant passes
        keeps the experiment inside its hard runtime boundary.  This result
        is research/settlement evidence only and explicitly withholds every
        promotion and overfit claim.
        """
        budget_started = time.monotonic()
        declared_horizon = max(0, int((payload.parameters or {}).get("time_stop_candles", 0) or 0))
        maximum_holding = max(1, int(maximum_holding_bars))
        effective_horizon = min(declared_horizon, maximum_holding) if declared_horizon > 0 else maximum_holding
        execution_payload = payload.model_copy(update={
            "parameters": {
                **(payload.parameters or {}),
                "time_stop_candles": effective_horizon,
            },
        })
        # Compile the immutable H4/H1/M15 context exactly once.  Fold M5
        # windows, signals and stateful execution remain independent; only
        # duplicate context feature construction is shared.
        context_started = time.monotonic()
        context_key = self._causal_context_cache_key(execution_payload)
        replay_feature_context = (
            context_cache.get(context_key) if context_cache is not None else None
        )
        if replay_feature_context is None:
            replay_feature_context = prepare_replay_feature_context(execution_payload)
            if context_cache is not None:
                context_cache[context_key] = replay_feature_context
        else:
            # Even on a compute-only cache hit, each arm's frozen manifest
            # must independently validate against the prepared context.
            _assert_closed_mtf_runtime(execution_payload, replay_feature_context.mtf_context)
        context_compiler_elapsed = time.monotonic() - context_started
        purge_bars = max(maximum_holding, int(purge_bars))
        embargo_bars = max(1, int(embargo_bars))
        _, holdout = self.rolling_windows(df, purge_bars, embargo_bars)
        # A normal in-process confirmation still requests the complete fold
        # set.  The durable Laravel coordinator may deliberately request one
        # frozen fold at a time, so one is now a valid lower bound.  This does
        # not weaken scientific settlement: the aggregate receipt remains
        # invalid until the registered fold universe is complete.
        fold_count = max(1, min(12, int(fold_count)))
        fold_offset = max(0, int(fold_offset))
        fold_universe_count = max(fold_count, int(fold_universe_count or fold_count))
        if fold_offset + fold_count > fold_universe_count:
            raise ValueError("Causal fold partition exceeds its frozen universe.")
        max_rows_per_fold = max(512, min(8192, int(max_rows_per_fold)))
        audit_trace_rows = max(128, min(1024, int(audit_trace_rows)))
        minimum_trades_per_window = max(1, int(minimum_trades_per_window))
        windows = self._causal_forward_folds(
            df,
            holdout,
            fold_count=fold_count,
            fold_offset=fold_offset,
            fold_universe_count=fold_universe_count,
            max_rows_per_fold=max_rows_per_fold,
            purge_bars=purge_bars,
            embargo_bars=embargo_bars,
        )
        evaluations: list[dict[str, object]] = []
        forward_results: list[dict[str, object]] = []
        economic_trade_ledger: list[dict[str, object]] = []
        total_budget_seconds = max(90, int(total_budget_seconds))
        per_fold_budget_seconds = max(30, min(int(per_fold_budget_seconds), total_budget_seconds))

        for index, segments in enumerate(windows, start=1):
            global_index = fold_offset + index
            elapsed_before = time.monotonic() - budget_started
            if elapsed_before >= total_budget_seconds:
                raise TimeoutError(
                    "Causal confirmation exhausted its total replay budget before the next fold; "
                    "partial folds are diagnostic only."
                )
            forward = segments["forward"]
            # All nine causal folds are metric-only. A separate small audit
            # slice below emits the immutable decision trace. Mixing a full
            # 12k-row trace into the final economic fold was the dominant
            # timeout source and prevented the triplet from ever settling.
            fold_payload = execution_payload.model_copy(update={
                "emit_decision_trace": False,
                "emit_trade_ledger": True,
            })
            if progress_callback is not None:
                progress_callback("causal_fold_started", {
                    "fold": global_index,
                    "folds_total": fold_universe_count,
                    "rows": len(forward),
                    "period": f"{forward.time.min().date()} - {forward.time.max().date()}",
                    "elapsed_seconds": round(elapsed_before, 3),
                    "total_budget_seconds": total_budget_seconds,
                    "per_fold_budget_seconds": per_fold_budget_seconds,
                })
            fold_started = time.monotonic()
            result = self._run_segment(
                fold_payload,
                forward,
                "forward",
                fast_stateful=True,
                lightweight=True,
                include_differential_pair=False,
                replay_context=replay_feature_context,
            )
            fold_trade_ledger = list(result.get("trade_ledger", []) or [])
            fold_trade_count = int(result.get("total_trades", 0) or 0)
            if len(fold_trade_ledger) != fold_trade_count:
                raise RuntimeError(
                    f"Causal confirmation fold {index} returned an incomplete trade ledger "
                    f"({len(fold_trade_ledger)}/{fold_trade_count}); no learning credit was emitted."
                )
            economic_trade_ledger.extend(fold_trade_ledger)
            # The top-level immutable ledger is authoritative. Window results
            # retain their hash/count but do not duplicate the full payload.
            result = dict(result)
            result.pop("trade_ledger", None)
            result["trade_ledger_count"] = fold_trade_count
            result["trade_ledger_scope"] = "fold ledger consolidated into top-level causal ledger"
            fold_elapsed = time.monotonic() - fold_started
            elapsed_total = time.monotonic() - budget_started
            if fold_elapsed > per_fold_budget_seconds:
                raise TimeoutError(
                    f"Causal confirmation fold {global_index} exceeded its {per_fold_budget_seconds}s budget; "
                    "remaining folds were not executed and no learning credit was emitted."
                )
            if elapsed_total > total_budget_seconds:
                raise TimeoutError(
                    f"Causal confirmation exceeded its {total_budget_seconds}s total budget after fold {global_index}; "
                    "partial evidence cannot settle learning."
                )
            score = score_calculator(result)
            forward_results.append(result)
            evaluations.append({
                "window": global_index,
                "periods": {
                    "forward": f"{forward.time.min().date()} - {forward.time.max().date()}",
                },
                "scores": {"forward": score},
                "results": {"forward": self._causal_window_projection(result)},
                "is_overfit": False,
            })
            if progress_callback is not None:
                progress_callback("causal_fold_completed", {
                    "fold": global_index,
                    "folds_total": fold_universe_count,
                    "rows": len(forward),
                    "elapsed_seconds": round(elapsed_total, 3),
                    "fold_elapsed_seconds": round(fold_elapsed, 3),
                    "score": score,
                    "profit_factor": float(result.get("profit_factor", 0) or 0),
                    "trades": int(result.get("total_trades", 0) or 0),
                    "promotion_evidence": False,
                })

        # The durable evidence plane still receives a complete decision trace,
        # but trace serialization is isolated from the nine economic folds.
        # The same deterministic tail slice is used by guided, blinded and
        # control arms and has no influence on their window scores.
        trace_source = windows[-1]["forward"]
        trace_segment = trace_source.iloc[-min(len(trace_source), audit_trace_rows):].reset_index(drop=True)
        if progress_callback is not None:
            progress_callback("causal_audit_trace_started", {
                "rows": len(trace_segment),
                "source_fold": len(windows),
                "elapsed_seconds": round(time.monotonic() - budget_started, 3),
                "promotion_evidence": False,
            })
        trace_started = time.monotonic()
        trace_payload = execution_payload.model_copy(update={"emit_decision_trace": True})
        trace_result = self._run_segment(
            trace_payload,
            trace_segment,
            "causal_audit_trace",
            fast_stateful=True,
            lightweight=True,
            include_differential_pair=False,
            replay_context=replay_feature_context,
        )
        trace_elapsed = time.monotonic() - trace_started
        elapsed_total = time.monotonic() - budget_started
        if elapsed_total > total_budget_seconds:
            raise TimeoutError(
                f"Causal confirmation exceeded its {total_budget_seconds}s total budget during the audit trace; "
                "economic folds remain diagnostic and cannot settle learning."
            )
        if progress_callback is not None:
            progress_callback("causal_audit_trace_completed", {
                "rows": len(trace_segment),
                "elapsed_seconds": round(elapsed_total, 3),
                "trace_elapsed_seconds": round(trace_elapsed, 3),
                "event_count": len(trace_result.get("decision_trace", []) or []),
                "promotion_evidence": False,
            })

        forward_scores = [item["scores"]["forward"] for item in evaluations]
        forward_score = round(sum(forward_scores) / len(forward_scores))
        representative = dict(forward_results[-1])
        expected_trade_count = sum(int(result.get("total_trades", 0) or 0) for result in forward_results)
        if len(economic_trade_ledger) != expected_trade_count:
            raise RuntimeError(
                "Causal confirmation aggregate trade ledger is incomplete; no learning credit was emitted."
            )
        validated_trade_ledger = [SimpleTrade.model_validate(trade) for trade in economic_trade_ledger]
        profit_values = [float(trade.profit_percent) for trade in validated_trade_ledger]
        realized_r = [
            float(trade.realized_r_multiple)
            if trade.realized_r_multiple is not None
            else float(trade.profit_percent) / max(float(trade.risk_budget_percent or payload.risk_per_trade or 1), 0.000001)
            for trade in validated_trade_ledger
        ]
        wins = sum(value > 0 for value in profit_values)
        losses = sum(value <= 0 for value in profit_values)
        gross_win = sum(value for value in profit_values if value > 0)
        gross_loss = abs(sum(value for value in profit_values if value <= 0))
        compounded = 1.0
        for value in profit_values:
            compounded *= 1.0 + (value / 100.0)
        aggregate_pf = gross_win / gross_loss if gross_loss else (99.0 if gross_win else 0.0)
        aggregate_net = (compounded - 1.0) * 100.0
        representative["trade_ledger"] = economic_trade_ledger
        representative["trade_ledger_hash"] = _trade_ledger_hash(validated_trade_ledger)
        representative["trades"] = economic_trade_ledger[-20:]
        representative["decision_trace"] = list(trace_result.get("decision_trace", []) or [])
        representative["data_quality"] = {
            **(representative.get("data_quality", {}) or {}),
            "decision_trace": {
                **((trace_result.get("data_quality", {}) or {}).get("decision_trace", {}) or {}),
                "requested": True,
                "complete": True,
                "audit_slice": True,
                "economic_score_input": False,
                "evaluated_candle_count": len(trace_segment),
                "promotion_evidence": False,
            },
        }
        representative["total_trades"] = expected_trade_count
        representative["wins"] = wins
        representative["losses"] = losses
        representative["winrate"] = round((wins / expected_trade_count) * 100, 2) if expected_trade_count else 0.0
        representative["profit_factor"] = round(aggregate_pf, 3)
        representative["net_profit_percent"] = round(aggregate_net, 4)
        representative["final_balance"] = round(float(payload.initial_balance) * compounded, 2)
        representative["average_win_percent"] = round(gross_win / wins, 4) if wins else 0.0
        representative["average_loss_percent"] = round(gross_loss / losses, 4) if losses else 0.0
        representative["risk_reward_ratio"] = round(
            representative["average_win_percent"] / representative["average_loss_percent"], 3
        ) if representative["average_loss_percent"] else 0.0
        representative["after_cost_expectancy_r"] = round(
            sum(realized_r) / len(realized_r), 6
        ) if realized_r else 0.0
        representative["displayed_trade_count"] = len(representative.get("trades", []))
        representative["trade_ledger_scope"] = "complete chronological ledger across all causal forward folds; UI trades are capped to latest 20"
        representative["statistical_evidence"] = {
            **(representative.get("statistical_evidence", {}) or {}),
            "trade_count": expected_trade_count,
            "edge_quality": {
                **_edge_quality_evidence(validated_trade_ledger),
                "worst_fold_profit_factor": round(
                    min(float(result.get("profit_factor", 0) or 0) for result in forward_results), 3
                ),
                "fold_count": len(forward_results),
                "promotion_evidence": False,
            },
        }
        representative["pf_attribution"] = _pf_attribution(validated_trade_ledger)
        representative["management_evidence"] = _management_evidence_report(validated_trade_ledger)
        representative["edge_observability"] = self._aggregate_edge_observability(
            forward_results, validated_trade_ledger,
        )
        representative["edge_context_enforcement"] = self._aggregate_edge_context_enforcement(
            forward_results,
        )
        representative["context_declared_before_replay"] = all(
            bool(result.get("context_declared_before_replay", False))
            for result in forward_results
        )
        setup_count = int((representative["edge_observability"].get("setup_location_valid") or {}).get("setup_count", 0) or 0)
        entry_count = int((representative["edge_observability"].get("entry") or {}).get("count", 0) or 0)
        representative["confirmation_entry_observed"] = all(
            (result.get("edge_observability", {}) or {}).get("protocol") == "edge_decision_outcome_observability_v1"
            for result in forward_results
        )
        representative["behavior_delta_observed"] = bool(setup_count > 0 and setup_count != entry_count)
        representative["context_occurrences"] = int(
            sum(int(result.get("context_occurrences", 0) or 0) for result in forward_results)
        )
        representative["max_drawdown"] = max(float(result.get("max_drawdown", 0)) for result in forward_results)
        representative["max_drawdown_percent"] = max(
            float(result.get("max_drawdown_percent", result.get("max_drawdown", 0)))
            for result in forward_results
        )
        representative["monte_carlo"] = {
            **(representative.get("monte_carlo", {}) or {}),
            "risk_of_ruin_percent": max(
                float((result.get("monte_carlo", {}) or {}).get("risk_of_ruin_percent", 100))
                for result in forward_results
            ),
        }
        representative["parameter_activation_manifest"] = self._parameter_activation_manifest(forward_results)
        representative["causal_confirmation_replay"] = {
            "protocol": "causal_forward_only_full_replay_v2",
            "frozen_parameters": True,
            "train_segments_executed": 0,
            "validation_segments_executed": 0,
            "forward_segments_executed": len(evaluations),
            "canonical_trace_fold": "bounded_audit_slice",
            "metric_only_fast_folds": len(evaluations),
            "promotion_diagnostics": "deferred_research_lane",
            "differential_pair_replays": 0,
            "context_compiler": "immutable_mtf_context_once_per_arm_v1",
            "context_compiler_builds": 1 if replay_feature_context.mtf_context is not None else 0,
            "context_compiler_reuses": (
                len(windows) + 1 if replay_feature_context.mtf_context is not None else 0
            ),
            "context_compiler_elapsed_seconds": round(context_compiler_elapsed, 3),
            "max_rows_per_fold": max_rows_per_fold,
            "audit_trace_rows": len(trace_segment),
            "audit_trace_elapsed_seconds": round(trace_elapsed, 3),
            "total_budget_seconds": total_budget_seconds,
            "per_fold_budget_seconds": per_fold_budget_seconds,
            "elapsed_seconds": round(time.monotonic() - budget_started, 3),
            "overfit_assessment": "withheld_not_a_selection_replay",
            "promotion_evidence": False,
        }

        return {
            "train_score": 0,
            "validation_score": 0,
            "forward_score": forward_score,
            "forward_window_scores": forward_scores,
            "rolling_windows_count": len(evaluations),
            "robustness_score": 0,
            "is_overfit": False,
            "result": {
                **representative,
                "train_score": 0,
                "validation_score": 0,
                "forward_score": forward_score,
                "forward_window_scores": forward_scores,
                "rolling_windows_count": len(evaluations),
                "robustness_score": 0,
                "is_overfit": False,
                "promotion_evidence": False,
                "walk_forward": {
                    "mode": "causal_forward_only",
                    "windows": evaluations,
                    "forward_window_protocol": self._forward_window_protocol(
                        evaluations,
                        purge_bars,
                        embargo_bars,
                        declared_horizon=declared_horizon,
                        effective_horizon=effective_horizon,
                        maximum_holding_bars=maximum_holding,
                        minimum_trades_per_window=minimum_trades_per_window,
                    ),
                    "final_holdout": {
                        "period": f"{holdout.time.min().date()} - {holdout.time.max().date()}",
                        "rows": len(holdout),
                        "used_for_selection": False,
                    },
                },
            },
        }

    @staticmethod
    def aggregate_causal_fold_items(
        items: list[dict[str, object]],
        *,
        expected_fold_count: int,
    ) -> dict[str, object]:
        """Combine already executed single-fold receipts without replaying data.

        Laravel owns retry/checkpoint durability.  Python remains the metric
        authority and therefore validates identity, fold coverage and ledger
        completeness before producing the one result that may cross the
        ordinary full-replay settlement boundary.
        """
        expected = max(1, min(12, int(expected_fold_count)))
        if len(items) != expected:
            raise ValueError(
                f"Causal fold aggregate requires {expected}/{expected} receipts; got {len(items)}."
            )

        def fold_number(item: dict[str, object]) -> int:
            result = item.get("result", {}) or {}
            contract = result.get("learning_confirmation", {}) or {}
            return int(contract.get("fold_offset", -1) or 0) + 1

        ordered = sorted(items, key=fold_number)
        observed = [fold_number(item) for item in ordered]
        if observed != list(range(1, expected + 1)):
            raise ValueError("Causal fold aggregate has missing or duplicate fold indexes.")

        identity = None
        fold_results: list[dict[str, object]] = []
        forward_scores: list[float] = []
        evaluations: list[dict[str, object]] = []
        protocol_rows: list[dict[str, object]] = []
        economic_trade_ledger: list[dict[str, object]] = []
        protocols: list[dict[str, object]] = []
        for item in ordered:
            result = item.get("result", {}) or {}
            if not isinstance(result, dict):
                raise ValueError("Causal fold result must be an object.")
            contract = result.get("learning_confirmation", {}) or {}
            current_identity = (
                item.get("lab_agent_id"),
                item.get("strategy"),
                item.get("base_strategy"),
                item.get("version"),
                (result.get("execution_contract", {}) or {}).get("execution_hash"),
                contract.get("protocol"),
                contract.get("fold_universe_count", expected),
            )
            if identity is None:
                identity = current_identity
            elif current_identity != identity:
                raise ValueError("Causal fold aggregate identity mismatch.")

            ledger = result.get("trade_ledger", []) or []
            if not isinstance(ledger, list) or len(ledger) != int(result.get("total_trades", 0) or 0):
                raise ValueError("Causal fold aggregate contains an incomplete trade ledger.")
            economic_trade_ledger.extend(ledger)
            fold_results.append(result)
            forward_scores.extend(float(value or 0) for value in (item.get("forward_window_scores", []) or []))
            walk_forward = result.get("walk_forward", {}) or {}
            evaluations.extend(list(walk_forward.get("windows", []) or []))
            protocol = walk_forward.get("forward_window_protocol", {}) or {}
            protocols.append(protocol)
            protocol_rows.extend(list(protocol.get("windows", []) or []))

        if len(forward_scores) != expected or len(protocol_rows) != expected:
            raise ValueError("Causal fold aggregate did not produce one independent window per receipt.")
        window_ids = [str(row.get("id", "")) for row in protocol_rows]
        if any(not value for value in window_ids) or len(set(window_ids)) != expected:
            raise ValueError("Causal fold aggregate window identity is incomplete or duplicated.")

        validated_trade_ledger = [SimpleTrade.model_validate(trade) for trade in economic_trade_ledger]
        profit_values = [float(trade.profit_percent) for trade in validated_trade_ledger]
        realized_r = [
            float(trade.realized_r_multiple)
            if trade.realized_r_multiple is not None
            else float(trade.profit_percent)
            for trade in validated_trade_ledger
        ]
        wins = sum(value > 0 for value in profit_values)
        losses = sum(value <= 0 for value in profit_values)
        gross_win = sum(value for value in profit_values if value > 0)
        gross_loss = abs(sum(value for value in profit_values if value <= 0))
        compounded = 1.0
        for value in profit_values:
            compounded *= 1.0 + value / 100.0
        total_trades = len(economic_trade_ledger)
        aggregate_pf = gross_win / gross_loss if gross_loss else (99.0 if gross_win else 0.0)
        aggregate_net = (compounded - 1.0) * 100.0

        representative = dict(fold_results[-1])
        representative["trade_ledger"] = economic_trade_ledger
        representative["trade_ledger_hash"] = _trade_ledger_hash(validated_trade_ledger)
        representative["trades"] = economic_trade_ledger[-20:]
        representative["total_trades"] = total_trades
        representative["wins"] = wins
        representative["losses"] = losses
        representative["winrate"] = round((wins / total_trades) * 100, 2) if total_trades else 0.0
        representative["profit_factor"] = round(aggregate_pf, 3)
        representative["net_profit_percent"] = round(aggregate_net, 4)
        representative["final_balance"] = round(10000.0 * compounded, 2)
        representative["average_win_percent"] = round(gross_win / wins, 4) if wins else 0.0
        representative["average_loss_percent"] = round(gross_loss / losses, 4) if losses else 0.0
        representative["risk_reward_ratio"] = round(
            representative["average_win_percent"] / representative["average_loss_percent"], 3
        ) if representative["average_loss_percent"] else 0.0
        representative["after_cost_expectancy_r"] = round(
            sum(realized_r) / len(realized_r), 6
        ) if realized_r else 0.0
        representative["displayed_trade_count"] = len(representative["trades"])
        representative["trade_ledger_scope"] = (
            "complete chronological ledger across immutable causal fold receipts; "
            "UI trades are capped to latest 20"
        )
        representative["max_drawdown"] = max(float(result.get("max_drawdown", 0) or 0) for result in fold_results)
        representative["max_drawdown_percent"] = max(
            float(result.get("max_drawdown_percent", result.get("max_drawdown", 0)) or 0)
            for result in fold_results
        )
        representative["monte_carlo"] = {
            **(representative.get("monte_carlo", {}) or {}),
            "risk_of_ruin_percent": max(
                float((result.get("monte_carlo", {}) or {}).get("risk_of_ruin_percent", 100) or 100)
                for result in fold_results
            ),
        }

        first_protocol = protocols[0]
        bounds = sorted(
            [(str(row.get("start", "")), str(row.get("end", ""))) for row in protocol_rows],
            key=lambda value: value[0],
        )
        overlap = any(bounds[index][0] <= bounds[index - 1][1] for index in range(1, len(bounds)))
        minimum_trades = max(1, int(first_protocol.get("minimum_trades_per_powered_window", 8) or 8))
        powered = sum(int(row.get("trades", 0) or 0) >= minimum_trades for row in protocol_rows)
        powered_required = min(expected, max(3, (expected * 2 + 2) // 3))
        merged_protocol = {
            **first_protocol,
            "observed_windows": expected,
            "windows": protocol_rows,
            "positive_windows": sum(float(value) > 0 for value in forward_scores),
            "powered_windows": powered,
            "minimum_powered_windows": powered_required,
            "power_quorum_passed": powered >= powered_required,
            "overlap_detected": overlap,
            "independence_verified": not overlap,
            "promotion_evidence": False,
        }
        forward_score = round(sum(forward_scores) / expected)
        representative["walk_forward"] = {
            **(representative.get("walk_forward", {}) or {}),
            "mode": "causal_fold_receipt_aggregate",
            "windows": evaluations,
            "forward_window_protocol": merged_protocol,
        }
        confirmation = dict(representative.get("learning_confirmation", {}) or {})
        confirmation.update({
            "execution_mode": "durable_single_fold_jobs",
            "fold_count": expected,
            "fold_offset": 0,
            "fold_universe_count": expected,
            "forward_window_protocol": merged_protocol,
            "status": "completed",
            "promotion_evidence": False,
        })
        representative["learning_confirmation"] = confirmation
        representative["statistical_evidence"] = {
            **(representative.get("statistical_evidence", {}) or {}),
            "trade_count": total_trades,
            "edge_quality": {
                **_edge_quality_evidence(validated_trade_ledger),
                "worst_fold_profit_factor": round(
                    min(float(result.get("profit_factor", 0) or 0) for result in fold_results), 3
                ),
                "fold_count": expected,
                "promotion_evidence": False,
            },
        }
        representative["pf_attribution"] = _pf_attribution(validated_trade_ledger)
        representative["management_evidence"] = _management_evidence_report(validated_trade_ledger)
        representative["edge_observability"] = WalkForwardService._aggregate_edge_observability(
            fold_results, validated_trade_ledger,
        )
        representative["edge_context_enforcement"] = WalkForwardService._aggregate_edge_context_enforcement(
            fold_results,
        )
        representative["parameter_activation_manifest"] = WalkForwardService._parameter_activation_manifest(
            fold_results,
        )
        representative["causal_confirmation_replay"] = {
            **(representative.get("causal_confirmation_replay", {}) or {}),
            "protocol": "causal_durable_fold_aggregate_v1",
            "metric_only_fast_folds": expected,
            "fold_receipts_complete": True,
            "fold_receipt_count": expected,
            "promotion_evidence": False,
        }
        representative["fold_aggregate_receipt"] = {
            "protocol": "causal_fold_aggregate_receipt_v1",
            "fold_count": expected,
            "fold_indexes": observed,
            "window_ids": window_ids,
            "trade_ledger_hash": representative["trade_ledger_hash"],
            "identity_verified": True,
            "promotion_evidence": False,
        }
        representative["train_score"] = 0
        representative["validation_score"] = 0
        representative["forward_score"] = forward_score
        representative["forward_window_scores"] = forward_scores
        representative["rolling_windows_count"] = expected
        representative["robustness_score"] = 0
        representative["is_overfit"] = False
        representative["promotion_evidence"] = False

        aggregate_item = dict(ordered[-1])
        aggregate_item.update({
            "score": round(sum(float(item.get("score", 0) or 0) for item in ordered) / expected),
            "train_score": 0,
            "validation_score": 0,
            "forward_score": forward_score,
            "forward_window_scores": forward_scores,
            "rolling_windows_count": expected,
            "robustness_score": 0,
            "is_overfit": False,
            "result": representative,
        })
        return aggregate_item

    def _causal_forward_folds(
        self,
        df: pd.DataFrame,
        holdout: pd.DataFrame,
        *,
        fold_count: int,
        fold_offset: int = 0,
        fold_universe_count: int | None = None,
        max_rows_per_fold: int,
        purge_bars: int,
        embargo_bars: int,
    ) -> list[dict[str, pd.DataFrame]]:
        """Build bounded, disjoint chronological confirmation strata.

        Frozen causal parameters need no expanding train/validation pass. The
        pre-holdout history is split into nine non-overlapping strata; each
        stratum contributes one centered contiguous sample with purge and
        embargo removed before row capping. This keeps roughly the same total
        candle budget as the former three two-year folds while tripling
        temporal observations.
        """
        normalized = self._normalize(df)
        holdout_start = holdout["time"].min()
        research = normalized[normalized["time"] < holdout_start].reset_index(drop=True)
        universe = max(fold_count, int(fold_universe_count or fold_count))
        offset = max(0, int(fold_offset))
        if offset + fold_count > universe:
            raise ValueError("Causal fold partition exceeds its frozen universe.")
        minimum_rows = universe * (purge_bars + embargo_bars + 128)
        if len(research) < minimum_rows:
            raise ValueError(
                f"Dataset has {len(research)} pre-holdout rows; {minimum_rows} are required for "
                f"{universe} purged causal fold partitions."
            )

        folds: list[dict[str, pd.DataFrame]] = []
        for index in range(offset, offset + fold_count):
            start = (len(research) * index) // universe
            end = (len(research) * (index + 1)) // universe
            block = research.iloc[start:end]
            usable_end = len(block) - purge_bars if purge_bars > 0 else len(block)
            usable = block.iloc[embargo_bars:usable_end]
            if len(usable) > max_rows_per_fold:
                sample_start = (len(usable) - max_rows_per_fold) // 2
                usable = usable.iloc[sample_start:sample_start + max_rows_per_fold]
            forward = usable.reset_index(drop=True)
            if len(forward) < 128:
                raise ValueError(f"Causal fold {index + 1} is underpowered after purge/embargo.")
            folds.append({"forward": forward})

        return folds

    @staticmethod
    def _causal_window_projection(result: dict[str, object]) -> dict[str, object]:
        """Keep causal window evidence without duplicating large replay bodies."""
        keys = [
            "profit_factor", "net_profit_percent", "total_trades", "wins", "losses", "winrate",
            "max_consecutive_losses",
            "max_drawdown", "max_drawdown_percent", "average_win_percent", "average_loss_percent",
            "stability_score", "trade_ledger_hash", "trade_ledger_count", "event_ledger_hash",
            "event_ledger_count", "event_ledger_categories", "signal_decision_hash",
            "signal_decision_count", "signal_decision_categories", "regime_performance",
            "volatility_performance", "walk_forward_segment", "edge_context_enforcement",
            "context_declared_before_replay", "context_occurrences",
        ]
        return {
            **{key: result[key] for key in keys if key in result},
            "protocol": "causal_window_evidence_projection_v1",
            "promotion_evidence": False,
        }

    @staticmethod
    def _aggregate_edge_observability(
        results: list[dict[str, object]],
        trades: list[SimpleTrade],
    ) -> dict[str, object]:
        """Aggregate causal ledger counts across every independent fold.

        The old projection copied only the last fold, so an entry in an early
        fold could disappear from the authority receipt. Telemetry presence
        and event occurrence intentionally remain separate facts.
        """
        ledgers = [
            result.get("edge_observability", {}) or {}
            for result in results
            if isinstance(result.get("edge_observability", {}), dict)
        ]

        def count(field: str, *keys: str) -> int:
            total = 0
            for ledger in ledgers:
                value = ledger.get(field, {}) or {}
                if not isinstance(value, dict):
                    continue
                for key in keys:
                    if key in value:
                        total += int(value.get(key, 0) or 0)
                        break
            return total

        complete = len(ledgers) == len(results) and all(
            ledger.get("protocol") == "edge_decision_outcome_observability_v1"
            for ledger in ledgers
        )
        ledger_hash = _trade_ledger_hash(trades)
        wins = sum(float(trade.profit_percent) > 0 for trade in trades)
        losses = len(trades) - wins
        return {
            "protocol": "edge_decision_outcome_observability_v1",
            "fold_telemetry_complete": complete,
            "opportunity_detected": {"observed": complete, "count": count("opportunity_detected", "count")},
            "setup_location_valid": {
                "observed": complete,
                "location_count": count("setup_location_valid", "location_count"),
                "setup_count": count("setup_location_valid", "setup_count"),
            },
            "context_bias_aligned": {"observed": complete, "count": count("context_bias_aligned", "count")},
            "confirmation": {"observed": complete, "count": count("confirmation", "count")},
            "entry": {"observed": complete, "count": count("entry", "count")},
            "execution_price": {
                "observed": complete, "closed_trade_count": len(trades), "trade_ledger_hash": ledger_hash,
            },
            "invalidation_price": {"observed": complete, "count": count("invalidation_price", "count")},
            "mfe_mae": {
                "observed": complete, "observed_trade_count": count("mfe_mae", "observed_trade_count"),
            },
            "exit_outcome": {
                "observed": complete, "closed_trade_count": len(trades), "wins": wins, "losses": losses,
            },
            "promotion_evidence": False,
        }

    @staticmethod
    def _aggregate_edge_context_enforcement(
        results: list[dict[str, object]],
    ) -> dict[str, object]:
        """Aggregate the authority firewall over every economic fold.

        Copying the last fold's telemetry next to an all-fold trade ledger
        created an internally contradictory receipt.  Authority now requires
        complete fold telemetry and proves that every executed trade had at
        least one matching pre-entry context decision in its own fold.
        """
        reports = [
            result.get("edge_context_enforcement", {}) or {}
            for result in results
            if isinstance(result.get("edge_context_enforcement", {}), dict)
        ]
        axes = [str(axis) for axis in (reports[0].get("admission_axes", []) if reports else [])]
        reasons: dict[str, int] = {}
        observed = matched = rejected = 0
        per_fold_consistent = len(reports) == len(results) and bool(results)
        identity_consistent = per_fold_consistent
        for result, report in zip(results, reports):
            fold_observed = int(report.get("observed_signals", 0) or 0)
            fold_matched = int(report.get("matched_signals", 0) or 0)
            fold_rejected = int(report.get("rejected_signals", 0) or 0)
            fold_trades = int(result.get("total_trades", 0) or 0)
            observed += fold_observed
            matched += fold_matched
            rejected += fold_rejected
            for reason, count in (report.get("rejection_reasons", {}) or {}).items():
                reasons[str(reason)] = reasons.get(str(reason), 0) + int(count or 0)
            per_fold_consistent = per_fold_consistent and (
                fold_observed == fold_matched + fold_rejected
                and fold_matched >= fold_trades
            )
            identity_consistent = identity_consistent and (
                report.get("protocol") == "edge_context_authority_firewall_v1"
                and [str(axis) for axis in (report.get("admission_axes", []) or [])] == axes
                and bool(report.get("enforced", False)) == bool(reports[0].get("enforced", False))
                and str(report.get("outside_scope_action", "")) == str(reports[0].get("outside_scope_action", ""))
            )
        enforced = bool(reports and reports[0].get("enforced", False) and axes)
        return {
            "protocol": "edge_context_authority_firewall_v1",
            "status": "enforced" if enforced else "telemetry_only_control",
            "enforced": enforced,
            "admission_axes": axes,
            "observed_signals": observed,
            "matched_signals": matched,
            "rejected_signals": rejected,
            "rejection_reasons": reasons,
            "outside_scope_action": reports[0].get("outside_scope_action") if reports else None,
            "closed_signal_state_only": all(bool(report.get("closed_signal_state_only", False)) for report in reports),
            "calendar_identity_forbidden": all(bool(report.get("calendar_identity_forbidden", False)) for report in reports),
            "fold_telemetry_complete": len(reports) == len(results) and bool(results),
            "fold_contract_identity_consistent": identity_consistent,
            "trade_admission_consistent": per_fold_consistent,
            "folds_observed": len(reports),
            "promotion_evidence": False,
        }

    @staticmethod
    def _parameter_activation_manifest(forward_results: list[dict[str, object]]) -> dict[str, object]:
        """Aggregate whether bounded repair genes could affect frozen history.

        This is scheduling evidence, not performance credit. A repair director
        may skip a gene only when the complete nine-fold control proves its
        required context never occurred; unknown genes remain admissible.
        """
        volatility_counts: dict[str, int] = {}
        for result in forward_results:
            for regime, metrics in (result.get("volatility_performance", {}) or {}).items():
                key = str(regime).strip().lower()
                volatility_counts[key] = volatility_counts.get(key, 0) + int(
                    (metrics or {}).get("trades", 0) or 0
                )
        high_volatility_trades = sum(
            count for regime, count in volatility_counts.items()
            if "high" in regime
        )
        total_trades = sum(int(result.get("total_trades", 0) or 0) for result in forward_results)
        total_losses = sum(int(result.get("losses", 0) or 0) for result in forward_results)
        maximum_loss_streak = max(
            (int(result.get("max_consecutive_losses", 0) or 0) for result in forward_results),
            default=0,
        )

        return {
            "protocol": "causal_parameter_activation_manifest_v1",
            "status": "complete" if forward_results else "unavailable",
            "observed_folds": len(forward_results),
            "facts": {
                "total_trades": total_trades,
                "total_losses": total_losses,
                "maximum_consecutive_losses": maximum_loss_streak,
                "volatility_trade_counts": volatility_counts,
                "high_volatility_trades": high_volatility_trades,
            },
            "gene_support": {
                "high_volatility_risk_multiplier": high_volatility_trades > 0,
                "avoid_high_volatility": high_volatility_trades > 0,
                "max_loss_streak_before_wait": maximum_loss_streak > 0,
                "loss_cooldown_candles": total_losses > 0,
                "time_stop_candles": total_trades > 0,
                "partial_take_profit_fraction": total_trades > 0,
            },
            "authority": "complete_frozen_control_folds_only",
            "performance_credit": False,
            "promotion_evidence": False,
        }

    @staticmethod
    def _forward_window_protocol(
        evaluations: list[dict[str, object]],
        purge_bars: int = 0,
        embargo_bars: int = 1,
        *,
        declared_horizon: int = 0,
        effective_horizon: int = 0,
        maximum_holding_bars: int | None = None,
        minimum_trades_per_window: int = 8,
    ) -> dict[str, object]:
        bounds: list[tuple[str, str]] = []
        window_rows: list[dict[str, object]] = []
        for evaluation in evaluations:
            period = (evaluation.get("periods", {}) or {}).get("forward", "")
            if not isinstance(period, str) or " - " not in period:
                continue
            start, end = period.split(" - ", 1)
            bounds.append((start, end))
            result = ((evaluation.get("results", {}) or {}).get("forward", {}) or {})
            window_rows.append({
                "id": f"{start}__{end}",
                "start": start,
                "end": end,
                "score": float(((evaluation.get("scores", {}) or {}).get("forward", 0)) or 0),
                "profit_factor": float(result.get("profit_factor", 0) or 0),
                "net_profit_percent": float(result.get("net_profit_percent", 0) or 0),
                "trades": int(result.get("total_trades", 0) or 0),
            })
        overlap = any(bounds[index][0] <= bounds[index - 1][1] for index in range(1, len(bounds)))
        positive = sum(
            float(((evaluation.get("scores", {}) or {}).get("forward", 0)) or 0) > 0
            for evaluation in evaluations
        )
        minimum_trades = max(1, int(minimum_trades_per_window))
        powered = sum(int(row.get("trades", 0) or 0) >= minimum_trades for row in window_rows)
        powered_required = min(len(window_rows), max(3, (len(window_rows) * 2 + 2) // 3))
        applied = purge_bars > 0 and embargo_bars > 0
        return {
            "protocol": "disjoint_forward_folds_v1",
            "source": "walk_forward_forward_segments",
            "observed_windows": len(bounds),
            "windows": window_rows,
            "positive_windows": positive,
            "minimum_trades_per_powered_window": minimum_trades,
            "powered_windows": powered,
            "minimum_powered_windows": powered_required,
            "power_quorum_passed": powered >= powered_required,
            "overlap_detected": overlap,
            "independence_verified": bool(bounds) and not overlap,
            "purge_bars": purge_bars,
            "embargo_bars": embargo_bars,
            "label_holding_period_purged": applied,
            "purge_embargo_applied": applied,
            "declared_time_stop_candles": declared_horizon,
            "effective_time_stop_candles": effective_horizon,
            "maximum_holding_bars": maximum_holding_bars,
            "execution_horizon_overlay_applied": maximum_holding_bars is not None,
            "promotion_evidence": False,
            "rule": "Every fold cold-starts; the declared maximum holding horizon is purged and one or more bars are embargoed before forward evaluation.",
        }

    @staticmethod
    def _purge_segments(
        segments: dict[str, pd.DataFrame],
        purge_bars: int,
        embargo_bars: int,
    ) -> dict[str, pd.DataFrame]:
        train = segments["train"]
        validation = segments["validation"]
        forward = segments["forward"]
        if purge_bars > 0:
            train = train.iloc[:-purge_bars] if len(train) > purge_bars else train.iloc[0:0]
            validation = validation.iloc[:-purge_bars] if len(validation) > purge_bars else validation.iloc[0:0]
        validation = validation.iloc[embargo_bars:]
        forward = forward.iloc[embargo_bars:]

        return {
            "train": train.reset_index(drop=True),
            "validation": validation.reset_index(drop=True),
            "forward": forward.reset_index(drop=True),
        }

    def _run_segment(
        self,
        payload: SimpleBacktestRequest,
        segment: pd.DataFrame,
        name: str,
        *,
        fast_stateful: bool | None = None,
        lightweight: bool = False,
        include_differential_pair: bool = True,
        prepared_snapshot: PreparedSignalSnapshot | None = None,
        replay_context: PreparedReplayFeatureContext | None = None,
    ) -> dict[str, object]:
        if prepared_snapshot is None and replay_context is not None:
            features = prepare_feature_snapshot(
                payload,
                segment,
                replay_context=replay_context,
            )
            prepared_snapshot = prepare_signal_snapshot(
                payload,
                feature_snapshot=features,
            )
        if prepared_snapshot is not None:
            data = _run_prepared_simple_backtest(
                payload,
                segment,
                prepared_snapshot=prepared_snapshot,
                fast_stateful=fast_stateful,
                lightweight=lightweight,
                include_differential_pair=include_differential_pair,
            ).model_dump()
        else:
            data = run_simple_ema_rsi_backtest_on_dataframe(
                payload,
                segment,
                fast_stateful=fast_stateful,
                lightweight=lightweight,
                include_differential_pair=include_differential_pair,
            ).model_dump()
        data["walk_forward_segment"] = name
        return data

    @staticmethod
    def _normalize(df: pd.DataFrame) -> pd.DataFrame:
        if df.empty:
            raise ValueError("Dataset is empty.")
        normalized = df.copy()
        normalized["time"] = pd.to_datetime(normalized["time"], utc=True, errors="coerce")
        return normalized.sort_values("time").reset_index(drop=True)


def calculate_robustness_score(*scores: int | float) -> int:
    if not scores:
        return 0
    return round(max(min(100 - (max(scores) - min(scores)), 100), 0))


def detect_overfit(train_score: int | float, forward_score: int | float, threshold: int = 25) -> bool:
    return train_score - forward_score > threshold
