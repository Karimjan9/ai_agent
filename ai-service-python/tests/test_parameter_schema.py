import unittest

from app.services.parameter_schema import validate_strategy_parameters


class ParameterSchemaTest(unittest.TestCase):
    def test_breakout_accepts_only_runtime_parameters_in_range(self):
        values = validate_strategy_parameters(
            "breakout_v7",
            {"lookback": 50, "atr_multiplier": 1.2, "confirmation_candles": 3},
        )
        self.assertEqual(values["lookback"], 50)

    def test_unknown_evolution_parameter_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "noma'lum parametr"):
            validate_strategy_parameters("breakout_v2", {"avoid_high_volatility": True})

    def test_out_of_range_parameter_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "10..100"):
            validate_strategy_parameters("breakout_v2", {"lookback": 101})


if __name__ == "__main__":
    unittest.main()
