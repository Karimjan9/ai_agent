import unittest

import pandas as pd
from unittest.mock import patch

from app.schemas import SimpleBacktestRequest
from app.services.walk_forward import (
    WalkForwardService,
    calculate_robustness_score,
    detect_overfit,
)


class WalkForwardSplitTest(unittest.TestCase):
    def test_split_dataset_uses_70_15_15_ratios(self):
        df = pd.DataFrame({
            "time": pd.date_range("2024-01-01", periods=100, freq="h"),
            "open": range(100),
            "high": range(100),
            "low": range(100),
            "close": range(100),
            "volume": [0] * 100,
        })

        splits = WalkForwardService().split_dataset(df)

        self.assertEqual(len(splits["train"]), 70)
        self.assertEqual(len(splits["validation"]), 15)
        self.assertEqual(len(splits["forward"]), 15)

    def test_rolling_windows_reserve_final_two_years(self):
        df = pd.DataFrame({
            "time": pd.date_range("2004-01-01", "2026-01-01", freq="30D", tz="UTC"),
            "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1,
        })

        windows, holdout = WalkForwardService().rolling_windows(df)

        self.assertGreaterEqual(len(windows), 3)
        self.assertEqual(windows[0]["train"]["time"].min().year, 2004)
        self.assertEqual(windows[0]["validation"]["time"].min().year, 2012)
        self.assertEqual(windows[0]["forward"]["time"].min().year, 2014)
        self.assertGreaterEqual(holdout["time"].min(), df["time"].max() - pd.DateOffset(years=2))

    def test_sparse_history_uses_three_chronological_row_windows_before_holdout(self):
        dates = pd.concat([
            pd.Series(pd.date_range("2004-01-01", "2013-01-01", freq="30D")),
            pd.Series(pd.date_range("2016-01-01", "2026-01-01", freq="30D")),
        ]).reset_index(drop=True)
        df = pd.DataFrame({
            "time": dates,
            "open": 100, "high": 101, "low": 99, "close": 100, "volume": 1,
        })

        windows, holdout = WalkForwardService().rolling_windows(df)

        self.assertEqual(3, len(windows))
        self.assertLess(windows[-1]["forward"]["time"].max(), holdout["time"].min())

    def test_confirmation_protocol_requires_and_reports_bounded_horizon_purge(self):
        evaluations = [
            {"periods": {"forward": "2014-01-01 - 2015-12-31"}, "scores": {"forward": 10}},
            {"periods": {"forward": "2016-01-01 - 2017-12-31"}, "scores": {"forward": 8}},
            {"periods": {"forward": "2018-01-01 - 2019-12-31"}, "scores": {"forward": -2}},
        ]

        blocked = WalkForwardService._forward_window_protocol(evaluations)
        confirmed = WalkForwardService._forward_window_protocol(evaluations, purge_bars=12, embargo_bars=1)

        self.assertFalse(blocked["purge_embargo_applied"])
        self.assertTrue(confirmed["purge_embargo_applied"])
        self.assertTrue(confirmed["label_holding_period_purged"])
        self.assertEqual(2, confirmed["positive_windows"])

    def test_confirmation_run_applies_same_bounded_execution_overlay_to_unbounded_genome(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2000-01-01", "2025-12-31", freq="D", tz="UTC"),
            "open": 1.0,
            "high": 1.1,
            "low": 0.9,
            "close": 1.0,
            "volume": 1.0,
        })
        payload = SimpleBacktestRequest(parameters={"time_stop_candles": 0})

        def segment_result(effective_payload, _segment, _name, **_kwargs):
            return {
                "profit_factor": 1.1,
                "max_drawdown": 1.0,
                "max_drawdown_percent": 1.0,
                "monte_carlo": {"risk_of_ruin_percent": 0.0},
                "total_trades": 10,
                "trades": [],
                "effective_time_stop": effective_payload.parameters["time_stop_candles"],
            }

        with patch.object(WalkForwardService, "_run_segment", side_effect=segment_result):
            outcome = WalkForwardService().run(
                payload,
                frame,
                lambda result: 10 if result["effective_time_stop"] == 240 else -10,
                maximum_holding_bars=240,
                purge_bars=240,
                embargo_bars=1,
            )

        protocol = outcome["result"]["walk_forward"]["forward_window_protocol"]
        self.assertTrue(protocol["execution_horizon_overlay_applied"])
        self.assertEqual(0, protocol["declared_time_stop_candles"])
        self.assertEqual(240, protocol["effective_time_stop_candles"])
        self.assertEqual(240, protocol["purge_bars"])

    def test_edge_context_authority_aggregates_all_folds_and_rejects_trade_telemetry_contradiction(self):
        report = {
            "protocol": "edge_context_authority_firewall_v1",
            "enforced": True,
            "admission_axes": ["regime", "direction"],
            "outside_scope_action": "WAIT",
            "closed_signal_state_only": True,
            "calendar_identity_forbidden": True,
        }
        valid = WalkForwardService._aggregate_edge_context_enforcement([
            {"total_trades": 2, "edge_context_enforcement": {
                **report, "observed_signals": 5, "matched_signals": 3,
                "rejected_signals": 2, "rejection_reasons": {"outside": 2},
            }},
            {"total_trades": 1, "edge_context_enforcement": {
                **report, "observed_signals": 4, "matched_signals": 2,
                "rejected_signals": 2, "rejection_reasons": {"outside": 2},
            }},
        ])
        self.assertEqual(9, valid["observed_signals"])
        self.assertEqual(5, valid["matched_signals"])
        self.assertEqual(4, valid["rejected_signals"])
        self.assertTrue(valid["fold_telemetry_complete"])
        self.assertTrue(valid["fold_contract_identity_consistent"])
        self.assertTrue(valid["trade_admission_consistent"])

        contradictory = WalkForwardService._aggregate_edge_context_enforcement([
            {"total_trades": 2, "edge_context_enforcement": {
                **report, "observed_signals": 2, "matched_signals": 0,
                "rejected_signals": 2, "rejection_reasons": {"outside": 2},
            }},
        ])
        self.assertFalse(contradictory["trade_admission_consistent"])

    def test_edge_two_three_nine_stages_use_disjoint_frozen_universe_partitions(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2000-01-01", "2025-12-31", freq="D", tz="UTC"),
            "open": 1.0, "high": 1.1, "low": 0.9, "close": 1.0, "volume": 1.0,
        })
        service = WalkForwardService()
        _, holdout = service.rolling_windows(frame, purge_bars=1, embargo_bars=1)

        def identities(count, offset):
            folds = service._causal_forward_folds(
                frame, holdout, fold_count=count, fold_offset=offset,
                fold_universe_count=14, max_rows_per_fold=4096,
                purge_bars=1, embargo_bars=1,
            )
            return {
                (fold["forward"].time.min().isoformat(), fold["forward"].time.max().isoformat())
                for fold in folds
            }

        discovery = identities(2, 0)
        replication = identities(3, 2)
        authority = identities(9, 5)

        self.assertEqual(2, len(discovery))
        self.assertEqual(3, len(replication))
        self.assertEqual(9, len(authority))
        self.assertTrue(discovery.isdisjoint(replication))
        self.assertTrue(discovery.isdisjoint(authority))
        self.assertTrue(replication.isdisjoint(authority))

    def test_causal_confirmation_executes_nine_bounded_folds_plus_audit_trace(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2000-01-01", "2025-12-31", freq="D", tz="UTC"),
            "open": 1.0,
            "high": 1.1,
            "low": 0.9,
            "close": 1.0,
            "volume": 1.0,
        })
        payload = SimpleBacktestRequest(parameters={"time_stop_candles": 0}, emit_decision_trace=True)
        trace_flags = []
        ledger_flags = []

        fast_flags = []
        lightweight_flags = []
        differential_flags = []

        def segment_result(effective_payload, segment, name, **kwargs):
            trace_flags.append(effective_payload.emit_decision_trace)
            ledger_flags.append(effective_payload.emit_trade_ledger)
            fast_flags.append(kwargs.get("fast_stateful"))
            lightweight_flags.append(kwargs.get("lightweight"))
            differential_flags.append(kwargs.get("include_differential_pair"))
            trade_ledger = [{
                "direction": "BUY",
                "entry_time": f"2020-01-{index + 1:02d}T00:00:00Z",
                "exit_time": f"2020-01-{index + 1:02d}T01:00:00Z",
                "entry_price": 100.0,
                "exit_price": 101.0,
                "result": "win",
                "profit_percent": 1.0,
                "balance": 10001.0 + index,
                "market_regime": "trend_up",
            } for index in range(10)] if effective_payload.emit_trade_ledger else []
            return {
                "profit_factor": 1.1,
                "net_profit_percent": 2.0,
                "max_drawdown": 1.0,
                "max_drawdown_percent": 1.0,
                "monte_carlo": {"risk_of_ruin_percent": 0.0},
                "total_trades": 10,
                "losses": 4,
                "max_consecutive_losses": 3,
                "volatility_performance": {
                    "normal_volatility": {"trades": 8},
                    "high_volatility": {"trades": 2},
                },
                "trades": [],
                "trade_ledger": trade_ledger,
                "equity_curve": list(range(1000)),
                "segment": name,
                "rows": len(segment),
                "effective_time_stop": effective_payload.parameters["time_stop_candles"],
            }

        progress = []
        with patch.object(WalkForwardService, "_run_segment", side_effect=segment_result) as replay:
            outcome = WalkForwardService().run_causal_confirmation(
                payload,
                frame,
                lambda result: 10 if result["effective_time_stop"] == 240 else -10,
                maximum_holding_bars=240,
                purge_bars=240,
                embargo_bars=1,
                total_budget_seconds=600,
                per_fold_budget_seconds=180,
                progress_callback=lambda stage, details: progress.append((stage, details)),
            )

        protocol = outcome["result"]["walk_forward"]["forward_window_protocol"]
        self.assertEqual(10, replay.call_count)
        self.assertEqual(([False] * 9) + [True], trace_flags)
        self.assertEqual(([True] * 9) + [False], ledger_flags)
        self.assertEqual([True] * 10, fast_flags)
        self.assertEqual([True] * 10, lightweight_flags)
        self.assertEqual([False] * 10, differential_flags)
        self.assertEqual(90, outcome["result"]["total_trades"])
        self.assertEqual(90, len(outcome["result"]["trade_ledger"]))
        self.assertEqual(90, outcome["result"]["wins"])
        self.assertEqual(99.0, outcome["result"]["profit_factor"])
        self.assertEqual("assessed", outcome["result"]["statistical_evidence"]["edge_quality"]["bootstrap_pf"]["status"])
        self.assertEqual(9, outcome["result"]["statistical_evidence"]["edge_quality"]["fold_count"])
        self.assertTrue(outcome["result"]["trade_ledger_hash"])
        self.assertTrue(all(
            "equity_curve" not in window["results"]["forward"]
            for window in outcome["result"]["walk_forward"]["windows"]
        ))
        self.assertEqual("causal_window_evidence_projection_v1", outcome["result"]["walk_forward"]["windows"][0]["results"]["forward"]["protocol"])
        self.assertEqual("causal_forward_only", outcome["result"]["walk_forward"]["mode"])
        self.assertEqual(9, protocol["observed_windows"])
        self.assertEqual(9, len(protocol["windows"]))
        self.assertEqual(9, len({row["id"] for row in protocol["windows"]}))
        self.assertEqual(9, protocol["powered_windows"])
        self.assertEqual(6, protocol["minimum_powered_windows"])
        self.assertTrue(protocol["power_quorum_passed"])
        self.assertTrue(protocol["independence_verified"])
        self.assertTrue(protocol["purge_embargo_applied"])
        self.assertFalse(outcome["result"]["promotion_evidence"])
        self.assertEqual(
            (["causal_fold_started", "causal_fold_completed"] * 9)
            + ["causal_audit_trace_started", "causal_audit_trace_completed"],
            [event[0] for event in progress],
        )
        self.assertEqual(9, progress[-3][1]["fold"])
        replay_budget = outcome["result"]["causal_confirmation_replay"]
        self.assertEqual(600, replay_budget["total_budget_seconds"])
        self.assertEqual(180, replay_budget["per_fold_budget_seconds"])
        self.assertEqual("bounded_audit_slice", replay_budget["canonical_trace_fold"])
        self.assertEqual(9, replay_budget["metric_only_fast_folds"])
        self.assertEqual("deferred_research_lane", replay_budget["promotion_diagnostics"])
        self.assertEqual(0, replay_budget["differential_pair_replays"])
        self.assertLessEqual(replay_budget["audit_trace_rows"], 512)
        activation = outcome["result"]["parameter_activation_manifest"]
        self.assertEqual("causal_parameter_activation_manifest_v1", activation["protocol"])
        self.assertEqual(9, activation["observed_folds"])
        self.assertEqual(90, activation["facts"]["total_trades"])
        self.assertEqual(36, activation["facts"]["total_losses"])
        self.assertEqual(18, activation["facts"]["high_volatility_trades"])
        self.assertEqual(3, activation["facts"]["maximum_consecutive_losses"])
        self.assertTrue(activation["gene_support"]["high_volatility_risk_multiplier"])
        self.assertFalse(activation["performance_credit"])

        # Genesis discovery is intentionally two folds. The shared causal
        # runner must not silently clamp that registered budget back to three.
        with patch.object(WalkForwardService, "_run_segment", side_effect=segment_result) as discovery_replay:
            discovery = WalkForwardService().run_causal_confirmation(
                payload,
                frame,
                lambda result: 10,
                maximum_holding_bars=240,
                purge_bars=240,
                embargo_bars=1,
                fold_count=2,
                total_budget_seconds=600,
                per_fold_budget_seconds=180,
            )
        discovery_protocol = discovery["result"]["walk_forward"]["forward_window_protocol"]
        self.assertEqual(3, discovery_replay.call_count)
        self.assertEqual(2, discovery_protocol["observed_windows"])
        self.assertEqual(2, discovery["result"]["statistical_evidence"]["edge_quality"]["fold_count"])


class OverfitDetectionTest(unittest.TestCase):
    def test_train_forward_gap_over_threshold_is_overfit(self):
        self.assertTrue(detect_overfit(95, 40))


class RobustnessScoreTest(unittest.TestCase):
    def test_robustness_score_uses_score_range(self):
        self.assertEqual(calculate_robustness_score(91, 88, 84), 93)


if __name__ == "__main__":
    unittest.main()
