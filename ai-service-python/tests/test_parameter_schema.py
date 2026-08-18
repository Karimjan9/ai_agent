import unittest
from unittest.mock import patch

import pandas as pd

from app.services.parameter_schema import strategy_family, validate_strategy_parameters
from app.strategies.laboratory import apply_differential_router_strategy, apply_hybrid_strategy, apply_regime_ensemble_strategy
from app.strategies.registry import get_strategy


class ParameterSchemaTest(unittest.TestCase):
    def test_breakout_accepts_only_runtime_parameters_in_range(self):
        values = validate_strategy_parameters(
            "breakout_v7",
            {"lookback": 50, "atr_multiplier": 1.2, "confirmation_candles": 3},
        )
        self.assertEqual(values["lookback"], 50)

    def test_shared_execution_gene_is_accepted(self):
        values = validate_strategy_parameters("breakout_v2", {"avoid_high_volatility": True, "atr_stop_multiplier": 1.5})
        self.assertTrue(values["avoid_high_volatility"])

    def test_unknown_evolution_parameter_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "noma'lum parametr"):
            validate_strategy_parameters("breakout_v2", {"invented_parameter": True})

    def test_temporal_survival_genes_are_explicit_and_bounded(self):
        values = validate_strategy_parameters("breakout_v2", {
            "temporal_survival_enabled": True,
            "adaptive_signal_expiry_enabled": True,
            "signal_decay_half_life_candles": 3,
            "temporal_followthrough_min_rate": .4,
            "temporal_drift_lookback_candles": 48,
        })
        self.assertTrue(values["temporal_survival_enabled"])
        self.assertEqual(values["signal_decay_half_life_candles"], 3)

    def test_out_of_range_parameter_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "10..100"):
            validate_strategy_parameters("breakout_v2", {"lookback": 101})

    def test_hybrid_range_adapter_genes_are_runtime_validated(self):
        values = validate_strategy_parameters(
            "hybrid_v8",
            {"range_signal_mode": "inverse_extreme", "range_reentry_required": False},
        )
        self.assertEqual(values["range_signal_mode"], "inverse_extreme")
        self.assertFalse(values["range_reentry_required"])

    def test_specialized_runtime_identity_overrides_stale_parent_base(self):
        strategy = "xauusd_differential_router_g103_a01"
        values = validate_strategy_parameters(
            strategy,
            {"differential_target_regime": "trend_down", "differential_router_version": "v2"},
            base_strategy="breakout_v1",
        )

        self.assertEqual(strategy_family(strategy, "breakout_v1"), "differential_router")
        self.assertEqual(values["differential_router_version"], "v2")
        self.assertIs(get_strategy(strategy, "breakout_v1"), apply_differential_router_strategy)

    def test_regime_ensemble_identity_overrides_stale_parent_base(self):
        strategy = "eurusd_regime_ensemble_g4_a02"
        values = validate_strategy_parameters(strategy, {"adx_max": 20.0}, base_strategy="breakout_v1")

        self.assertEqual(strategy_family(strategy, "breakout_v1"), "regime_ensemble")
        self.assertEqual(values["adx_max"], 20.0)
        self.assertIs(get_strategy(strategy, "breakout_v1"), apply_regime_ensemble_strategy)

    def test_structural_entry_topology_changes_signal_surface(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=4, freq="h", tz="UTC"),
            "open": [100.0] * 4,
            "high": [101.0] * 4,
            "low": [99.0] * 4,
            "close": [100.0] * 4,
            "market_regime": ["trend_up"] * 4,
            "volatility_regime": ["normal_volatility"] * 4,
        })

        def trend(df, _parameters):
            out = df.copy()
            out["signal"] = "BUY"
            out["signal_confidence"] = 1.0
            return out

        def wait(df, _parameters):
            out = df.copy()
            out["signal"] = "WAIT"
            out["signal_confidence"] = 0.0
            return out

        with patch("app.strategies.laboratory.apply_momentum_strategy", side_effect=trend), \
             patch("app.strategies.laboratory.apply_volatility_strategy", side_effect=wait), \
             patch("app.strategies.laboratory.apply_mean_reversion_strategy", side_effect=wait):
            frozen = apply_hybrid_strategy(frame, {"entry_topology_variant": "frozen"})
            consensus = apply_hybrid_strategy(frame, {"entry_topology_variant": "regime_consensus_v1"})
            transition = apply_hybrid_strategy(frame, {"entry_topology_variant": "transition_hazard_v1"})

        self.assertGreater(int((frozen["signal"] == "BUY").sum()), 0)
        self.assertEqual(int((consensus["signal"] == "BUY").sum()), 0)
        self.assertLess(int((transition["signal"] == "BUY").sum()), int((frozen["signal"] == "BUY").sum()))


if __name__ == "__main__":
    unittest.main()
