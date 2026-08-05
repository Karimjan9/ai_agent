import unittest

from app.services.parameter_schema import strategy_family, validate_strategy_parameters
from app.strategies.laboratory import apply_differential_router_strategy, apply_regime_ensemble_strategy
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


if __name__ == "__main__":
    unittest.main()
