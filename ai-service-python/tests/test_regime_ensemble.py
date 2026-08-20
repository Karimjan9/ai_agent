import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import SimpleBacktestRequest, SimpleTrade, StrategyRuntimeConfig
from app.strategies.laboratory import apply_differential_router_strategy, apply_differential_trend_down_router_strategy, apply_hybrid_strategy, apply_mean_reversion_strategy, apply_regime_ensemble_strategy
from app.services.backtester import _apply_portfolio_strategy, _pf_attribution, _portfolio_evidence, _portfolio_payload_for_signal, _router_evidence


def _portfolio_high_confidence_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    result = frame.copy()
    result["signal"] = "BUY"
    result["signal_confidence"] = 0.9
    return result


def _portfolio_low_confidence_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    result = frame.copy()
    result["signal"] = "BUY"
    result["signal_confidence"] = 0.6
    return result


def _portfolio_sell_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    result = frame.copy()
    result["signal"] = "SELL"
    result["signal_confidence"] = 0.9
    return result


def _portfolio_unsafe_confidence_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    result = frame.copy()
    result["signal"] = "BUY"
    result["signal_confidence"] = 0.2
    return result


class RegimeEnsembleTest(unittest.TestCase):
    def test_differential_v2_trend_down_roc_threshold_changes_target_lane_signal(self):
        closes = [100.0 - index * .025 for index in range(80)]
        frame = pd.DataFrame({
            "time": pd.date_range("2025-01-01", periods=len(closes), freq="h"),
            "open": closes, "high": [value + .1 for value in closes],
            "low": [value - .1 for value in closes], "close": closes,
            "market_regime": ["trend_down"] * len(closes),
            "volatility_regime": ["normal_volatility"] * len(closes),
            "adx": [30.0] * len(closes), "atr_regime": [1.0] * len(closes),
        })

        permissive = apply_differential_router_strategy(frame, {
            "differential_target_regime": "trend_down", "differential_router_version": "v2",
            "trend_down_roc_threshold": .20,
        })
        selective = apply_differential_router_strategy(frame, {
            "differential_target_regime": "trend_down", "differential_router_version": "v2",
            "trend_down_roc_threshold": .40,
        })

        self.assertGreater((permissive["signal"] == "SELL").sum(), (selective["signal"] == "SELL").sum())

    def test_mean_reversion_inverse_extreme_is_an_executable_one_gene_topology(self):
        closes = [100.0 + (index % 2) * .1 for index in range(24)] + [110.0]
        frame = pd.DataFrame({
            "time": pd.date_range("2025-01-01", periods=len(closes), freq="h"),
            "open": closes, "high": [value + .2 for value in closes],
            "low": [value - .2 for value in closes], "close": closes,
            "adx": [10.0] * len(closes),
            "volatility_regime": ["low_volatility"] * len(closes),
        })

        control = apply_mean_reversion_strategy(frame, {"range_signal_mode": "reentry"})
        candidate = apply_mean_reversion_strategy(frame, {"range_signal_mode": "inverse_extreme"})

        self.assertEqual("SELL", control.iloc[-1]["signal"])
        self.assertEqual("BUY", candidate.iloc[-1]["signal"])

    def test_portfolio_evidence_preserves_member_context_and_month_intersection(self):
        request = SimpleBacktestRequest(
            symbol="XAUUSD",
            timeframe="H1",
            strategy="portfolio_v1",
            candles=[{"time": "2026-04-01T00:00:00", "open": 100, "high": 101, "low": 99, "close": 100}],
            portfolio_members=[StrategyRuntimeConfig(
                strategy="hybrid_v1",
                member_key="performance:239",
                role="range",
                target_regime="range",
                target_volatility="low_volatility",
                target_direction="BUY",
            )],
        )
        trades = [
            SimpleTrade(
                direction="BUY", entry_time="2026-04-07 02:00:00", exit_time="2026-04-07 03:00:00",
                entry_price=100, exit_price=101, result="WIN", profit_percent=1.0, balance=10100,
                market_regime="range", volatility_regime="low_volatility", portfolio_member="performance:239",
            ),
            SimpleTrade(
                direction="SELL", entry_time="2026-04-09 02:00:00", exit_time="2026-04-09 03:00:00",
                entry_price=100, exit_price=99, result="WIN", profit_percent=1.0, balance=10200,
                market_regime="range", volatility_regime="low_volatility", portfolio_member="performance:239",
            ),
            SimpleTrade(
                direction="BUY", entry_time="2026-04-14 02:00:00", exit_time="2026-04-14 03:00:00",
                entry_price=100, exit_price=99, result="LOSS", profit_percent=-1.0, balance=10100,
                market_regime="range", volatility_regime="low_volatility", portfolio_member="performance:239",
            ),
        ]

        evidence = _portfolio_evidence(pd.DataFrame({"portfolio_disagreement": [False]}), trades, request)
        context = evidence["member_breakdown"]["performance:239"]["context_breakdown"]["range|low_volatility"]

        self.assertEqual(context["trades"], 3)
        self.assertEqual(context["monthly"]["2026-04"]["trades"], 3)
        self.assertAlmostEqual(context["monthly"]["2026-04"]["profit_factor"], 2.0)
        self.assertEqual(evidence["member_breakdown"]["performance:239"]["target_direction"], "BUY")

    def test_router_has_one_predeclared_specialist_per_candle(self):
        rows = 80
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=rows, freq="h"),
            "open": [100 + index * .1 for index in range(rows)],
            "high": [101 + index * .1 for index in range(rows)],
            "low": [99 + index * .1 for index in range(rows)],
            "close": [100.2 + index * .1 for index in range(rows)],
            "market_regime": ["trend_up", "range", "unknown", "trend_down"] * 20,
            "volatility_regime": ["normal_volatility", "normal_volatility", "normal_volatility", "high_volatility"] * 20,
            "adx": [25.0] * rows,
            "atr_regime": [1.0] * rows,
        })
        frame.loc[4, "market_regime"] = "trend_down"
        frame.loc[4, "volatility_regime"] = "normal_volatility"

        result = apply_regime_ensemble_strategy(frame)

        self.assertTrue(result["selected_specialist"].isin(["trend_up", "trend_down", "breakout", "range", "unknown_wait"]).all())
        self.assertTrue(result["signal"].isin(["BUY", "SELL", "WAIT"]).all())
        self.assertEqual(result.loc[3, "selected_specialist"], "breakout")
        self.assertEqual(result.loc[1, "selected_specialist"], "range")
        self.assertEqual(result.loc[2, "selected_specialist"], "unknown_wait")
        self.assertEqual(result.loc[2, "signal"], "WAIT")
        self.assertEqual(result.loc[4, "selected_specialist"], "trend_down")

    def test_router_is_fail_closed_for_unseen_regime_even_when_session_has_signal(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=4, freq="h"),
            "open": [100.0] * 4,
            "high": [101.0] * 4,
            "low": [99.0] * 4,
            "close": [100.8] * 4,
            "market_regime": ["new_regime"] * 4,
            "volatility_regime": ["normal_volatility"] * 4,
            "adx": [25.0] * 4,
            "atr_regime": [1.0] * 4,
        })

        result = apply_regime_ensemble_strategy(frame)

        self.assertTrue((result["signal"] == "WAIT").all())
        self.assertTrue((result["signal_confidence"] == 0.0).all())
        self.assertTrue((result["selected_specialist"] == "unknown_wait").all())

    def test_differential_router_keeps_parent_signal_and_confidence_outside_trend_down(self):
        rows = 120
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=rows, freq="h"),
            "open": [100 + index * .02 for index in range(rows)], "high": [100.5 + index * .02 for index in range(rows)],
            "low": [99.5 + index * .02 for index in range(rows)], "close": [100.1 + index * .02 for index in range(rows)],
            "market_regime": ["trend_up", "range", "trend_down"] * 40,
            "volatility_regime": ["normal_volatility"] * rows, "adx": [28.0] * rows, "atr_regime": [1.0] * rows,
        })
        parent = apply_hybrid_strategy(frame)
        child = apply_differential_trend_down_router_strategy(frame)
        non_target = frame["market_regime"] != "trend_down"

        self.assertTrue((child.loc[non_target, "signal"] == parent.loc[non_target, "signal"]).all())
        self.assertTrue((child.loc[non_target, "signal_confidence"] == parent.loc[non_target, "signal_confidence"]).all())
        self.assertTrue((child.loc[~non_target, "selected_specialist"] == "trend_down_child").all())

    def test_differential_router_v2_reuses_parent_momentum_topology(self):
        rows = 120
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=rows, freq="h"),
            "open": [100 + index * .02 for index in range(rows)], "high": [100.5 + index * .02 for index in range(rows)],
            "low": [99.5 + index * .02 for index in range(rows)], "close": [100.1 + index * .02 for index in range(rows)],
            "market_regime": ["trend_up", "range", "trend_down"] * 40,
            "volatility_regime": ["normal_volatility"] * rows, "adx": [28.0] * rows, "atr_regime": [1.0] * rows,
        })
        parameters = {
            "differential_target_regime": "trend_up", "differential_router_version": "v2",
            "trend_roc_period": 12, "trend_roc_threshold": .2, "trend_ema_period": 50,
            "trend_up_roc_period": 12, "trend_up_roc_threshold": .2, "trend_up_ema_period": 50,
        }
        parent = apply_hybrid_strategy(frame, parameters)
        child = apply_differential_router_strategy(frame, parameters)
        non_target = frame["market_regime"] != "trend_up"

        self.assertTrue((child.loc[non_target, "signal"] == parent.loc[non_target, "signal"]).all())
        self.assertTrue((child.loc[non_target, "signal_confidence"] == parent.loc[non_target, "signal_confidence"]).all())
        self.assertTrue((child.loc[frame["market_regime"] == "trend_up", "signal"] == parent.loc[frame["market_regime"] == "trend_up", "signal"]).all())
        self.assertTrue((child.loc[frame["market_regime"] == "trend_up", "selected_specialist"] == "trend_up_child").all())

    def test_differential_router_can_target_the_observed_range_lane(self):
        rows = 120
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=rows, freq="h"),
            "open": [100 + index * .02 for index in range(rows)], "high": [100.5 + index * .02 for index in range(rows)],
            "low": [99.5 + index * .02 for index in range(rows)], "close": [100.1 + index * .02 for index in range(rows)],
            "market_regime": ["trend_up", "range", "trend_down"] * 40,
            "volatility_regime": ["normal_volatility"] * rows, "adx": [12.0] * rows, "atr_regime": [1.0] * rows,
        })

        result = apply_differential_router_strategy(frame, {
            "differential_target_regime": "range", "range_reentry_required": True,
            "range_low_volatility_only": False, "range_deviation": 2.0, "range_adx_max": 20.0,
        })

        self.assertEqual(result["differential_target_regime"].unique().tolist(), ["range"])
        self.assertTrue((result.loc[result["market_regime"] == "range", "selected_specialist"] == "range_child").all())
        non_target = result["market_regime"] != "range"
        self.assertTrue((result.loc[non_target, "signal"] == result.loc[non_target, "parent_signal"]).all())

    def test_range_inverse_extreme_is_explicitly_scoped_to_the_range_lane(self):
        rows = 120
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=rows, freq="h"),
            "open": [100 + index * .02 for index in range(rows)], "high": [100.5 + index * .02 for index in range(rows)],
            "low": [99.5 + index * .02 for index in range(rows)], "close": [100.1 + index * .02 for index in range(rows)],
            "market_regime": ["trend_up", "range", "trend_down"] * 40,
            "volatility_regime": ["normal_volatility"] * rows, "adx": [12.0] * rows, "atr_regime": [1.0] * rows,
        })
        parent = apply_hybrid_strategy(frame)
        child = apply_differential_router_strategy(frame, {
            "differential_target_regime": "range", "range_signal_mode": "inverse_extreme",
            "range_low_volatility_only": False, "range_deviation": 2.0, "range_adx_max": 20.0,
        })

        non_target = frame["market_regime"] != "range"
        self.assertTrue((child.loc[non_target, "signal"] == parent.loc[non_target, "signal"]).all())
        self.assertTrue((child.loc[frame["market_regime"] == "range", "selected_specialist"] == "range_child").all())

    def test_portfolio_binds_selected_member_execution_and_keeps_global_policy(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=80, freq="h"),
            "open": [100 + index * .1 for index in range(80)],
            "high": [101 + index * .1 for index in range(80)],
            "low": [99 + index * .1 for index in range(80)],
            "close": [100.2 + index * .1 for index in range(80)],
            "market_regime": ["trend_up"] * 80,
            "volatility_regime": ["normal_volatility"] * 80,
            "adx": [28.0] * 80,
            "atr_regime": [1.0] * 80,
        })
        members = [{
            "strategy": "hybrid_v1", "base_strategy": "hybrid_v1", "parameters": {
                "atr_stop_multiplier": 2.75, "atr_target_multiplier": 4.25,
            }, "role": "trend_up", "target_regime": "trend_up",
            "target_volatility": "normal_volatility",
        }]
        prepared = _apply_portfolio_strategy(frame, members)
        row = prepared.iloc[20]
        payload = SimpleBacktestRequest(
            portfolio_members=members,
            parameters={"portfolio_policy_version": "test", "transition_firewall_enabled": False},
        )
        bound = _portfolio_payload_for_signal(payload, row)

        self.assertEqual(float(bound.parameters["atr_stop_multiplier"]), 2.75)
        self.assertEqual(float(bound.parameters["atr_target_multiplier"]), 4.25)
        self.assertFalse(bound.parameters["transition_firewall_enabled"])

    def test_portfolio_attribution_keeps_same_role_members_distinct(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=80, freq="h"),
            "open": [100 + index * .1 for index in range(80)],
            "high": [101 + index * .1 for index in range(80)],
            "low": [99 + index * .1 for index in range(80)],
            "close": [100.2 + index * .1 for index in range(80)],
            "market_regime": ["trend_up"] * 80,
            "volatility_regime": ["normal_volatility"] * 80,
            "adx": [28.0] * 80,
            "atr_regime": [1.0] * 80,
        })
        members = [
            {"strategy": "hybrid_v1", "member_key": "performance:101", "role": "trend_up", "target_regime": "trend_up"},
            {"strategy": "hybrid_v1", "member_key": "performance:202", "role": "trend_up", "target_regime": "trend_up"},
        ]
        prepared = _apply_portfolio_strategy(frame, members)

        selected = prepared.loc[prepared["selected_specialist"] != "portfolio_wait", "selected_specialist"]
        self.assertTrue(len(selected) > 0)
        self.assertTrue((selected == "performance:101").all())
        self.assertNotEqual("performance:101", "performance:202")

    def test_portfolio_generic_member_cannot_trade_unknown_regime(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=4, freq="h"),
            "open": [100.0] * 4, "high": [101.0] * 4, "low": [99.0] * 4,
            "close": [100.8] * 4, "market_regime": ["unknown"] * 4,
            "volatility_regime": ["normal_volatility"] * 4,
        })
        members = [{"strategy": "hybrid_v1", "member_key": "performance:unknown"}]

        with patch("app.services.backtester.get_strategy", return_value=_portfolio_high_confidence_strategy):
            prepared = _apply_portfolio_strategy(frame, members)

        self.assertTrue((prepared["signal"] == "WAIT").all())
        self.assertTrue((prepared["selected_specialist"] == "portfolio_wait").all())
        self.assertTrue((prepared["portfolio_wait_reason"] == "unknown_state_wait").all())

    def test_portfolio_same_niche_binds_highest_current_confidence_execution(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=4, freq="h"),
            "open": [100.0] * 4, "high": [101.0] * 4, "low": [99.0] * 4,
            "close": [100.0] * 4, "market_regime": ["trend_up"] * 4,
            "volatility_regime": ["normal_volatility"] * 4,
        })
        members = [
            {"strategy": "low_v1", "member_key": "performance:low", "target_regime": "trend_up", "target_volatility": "normal_volatility"},
            {"strategy": "high_v1", "member_key": "performance:high", "target_regime": "trend_up", "target_volatility": "normal_volatility"},
        ]

        def strategy_factory(strategy: str, _base: str | None = None):
            return _portfolio_high_confidence_strategy if strategy == "high_v1" else _portfolio_low_confidence_strategy

        with patch("app.services.backtester.get_strategy", side_effect=strategy_factory):
            prepared = _apply_portfolio_strategy(frame, members)

        self.assertTrue((prepared["selected_specialist"] == "performance:high").all())
        self.assertTrue((prepared["portfolio_member_count"] == 2).all())

    def test_council_opposite_specialists_force_wait_and_record_reason(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=4, freq="h"),
            "open": [100.0] * 4, "high": [101.0] * 4, "low": [99.0] * 4,
            "close": [100.0] * 4, "market_regime": ["trend_up"] * 4,
            "volatility_regime": ["normal_volatility"] * 4,
        })
        members = [
            {"strategy": "buy_v1", "member_key": "performance:buy", "target_regime": "trend_up", "target_direction": "BUY"},
            {"strategy": "sell_v1", "member_key": "performance:sell", "target_regime": "trend_up", "target_direction": "SELL"},
        ]

        def strategy_factory(strategy: str, _base: str | None = None):
            return _portfolio_sell_strategy if strategy == "sell_v1" else _portfolio_high_confidence_strategy

        with patch("app.services.backtester.get_strategy", side_effect=strategy_factory):
            prepared = _apply_portfolio_strategy(frame, members)

        self.assertTrue((prepared["signal"] == "WAIT").all())
        self.assertTrue(prepared["portfolio_disagreement"].all())
        self.assertTrue((prepared["portfolio_wait_reason"] == "council_disagreement").all())

    def test_council_low_confidence_consensus_forces_wait(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=4, freq="h"),
            "open": [100.0] * 4, "high": [101.0] * 4, "low": [99.0] * 4,
            "close": [100.0] * 4, "market_regime": ["trend_up"] * 4,
            "volatility_regime": ["normal_volatility"] * 4,
        })
        members = [
            {"strategy": "low_v1", "member_key": "performance:low1", "target_regime": "trend_up"},
            {"strategy": "low_v1", "member_key": "performance:low2", "target_regime": "trend_up"},
        ]

        with patch("app.services.backtester.get_strategy", return_value=lambda frame, _parameters: _portfolio_unsafe_confidence_strategy(frame, _parameters)):
            prepared = _apply_portfolio_strategy(frame, members)

        self.assertTrue((prepared["signal"] == "WAIT").all())
        self.assertTrue((prepared["portfolio_wait_reason"] == "calibrated_confidence_below_minimum").all())

    def test_router_objective_excludes_profit_factor(self):
        result = _router_evidence(
            pd.DataFrame({"signal": ["WAIT"], "portfolio_disagreement": [True], "portfolio_wait_reason": ["council_disagreement"]}),
            SimpleBacktestRequest(), {"status": "observed"},
            {"abstention_precision": .80, "opportunities": 20},
            {"edge_quality": {"confidence_calibration": {"score": 80, "sample_count": 20}}},
        )
        self.assertEqual(result["status"], "assessed")
        self.assertFalse(result["profit_factor_used_for_training"])
        self.assertEqual(result["training_objective"], "calibrated_confidence_plus_abstention_precision")

    def test_pf_attribution_persists_regime_volatility_intersection(self):
        trades = [
            SimpleTrade(
                entry_time="2026-01-01 01:00:00", exit_time="2026-01-01 02:00:00",
                direction="BUY", entry_price=100, exit_price=102, result="WIN",
                profit_percent=2, gross_profit_percent=2, execution_cost_percent=0,
                balance=10000, market_regime="trend_up", volatility_regime="normal_volatility",
            ),
            SimpleTrade(
                entry_time="2026-01-01 03:00:00", exit_time="2026-01-01 04:00:00",
                direction="BUY", entry_price=100, exit_price=99, result="LOSS",
                profit_percent=-1, gross_profit_percent=-1, execution_cost_percent=0,
                balance=10000, market_regime="trend_up", volatility_regime="low_volatility",
            ),
        ]
        result = _pf_attribution(trades)

        self.assertEqual(result["by_regime_volatility"]["trend_up|normal_volatility"]["trades"], 1)
        self.assertEqual(result["by_regime_volatility"]["trend_up|low_volatility"]["net_pf"], 0.0)
        self.assertEqual(
            result["by_regime_volatility_direction"]["trend_up|normal_volatility"]["BUY"]["trades"],
            1,
        )
        self.assertIn("trend_up|low_volatility", result["by_regime_volatility_session"])


if __name__ == "__main__":
    unittest.main()
