import unittest

import pandas as pd

from app.strategies.laboratory import apply_regime_ensemble_strategy


class RegimeEnsembleTest(unittest.TestCase):
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

        result = apply_regime_ensemble_strategy(frame)

        self.assertTrue(result["selected_specialist"].isin(["trend", "breakout", "range", "session"]).all())
        self.assertTrue(result["signal"].isin(["BUY", "SELL", "WAIT"]).all())
        self.assertEqual(result.loc[3, "selected_specialist"], "breakout")
        self.assertEqual(result.loc[1, "selected_specialist"], "range")


if __name__ == "__main__":
    unittest.main()
