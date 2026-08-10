import unittest

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import _apply_signal_delay
from app.services.market_adaptive_replay import MarketAdaptiveReplayService
from app.services.statistical_validation import noise_label_permutation_test


class RobustnessProtocolTest(unittest.TestCase):
    def test_noise_null_is_assessed_only_after_thirty_trades(self):
        insufficient = noise_label_permutation_test([0.01, -0.005] * 10)
        self.assertEqual(insufficient["status"], "insufficient_data")
        self.assertFalse(insufficient["pass"])

        values = [0.01 if index % 3 else -0.003 for index in range(60)]
        assessed = noise_label_permutation_test(values, simulations=100)
        self.assertEqual(assessed["status"], "assessed")
        self.assertTrue(assessed["pass"])

    def test_signal_delay_moves_only_signal_columns(self):
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=3, freq="h"),
            "close": [100.0, 101.0, 102.0],
            "signal": ["WAIT", "BUY", "SELL"],
            "signal_confidence": [0.0, 0.8, 0.9],
            "market_regime": ["range", "trend_up", "trend_up"],
        })

        delayed = _apply_signal_delay(frame, 1)

        self.assertEqual(delayed["signal"].tolist(), ["WAIT", "WAIT", "BUY"])
        self.assertEqual(delayed["signal_confidence"].tolist(), [0.0, 0.0, 0.8])
        self.assertEqual(delayed["market_regime"].tolist(), frame["market_regime"].tolist())
        self.assertEqual(delayed["close"].tolist(), frame["close"].tolist())

    def test_missing_internal_candle_is_hard_blocked(self):
        close = [100.0 + (index % 37) for index in range(230)]
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-01", periods=len(close), freq="h"),
            "open": close,
            "high": [value + 1 for value in close],
            "low": [value - 1 for value in close],
            "close": close,
            "volume": [1.0] * len(close),
        })
        payload = SimpleBacktestRequest(
            strategy="trend_v1",
            execution=ExecutionConfig(reject_unexpected_gaps=True),
        )

        evidence = MarketAdaptiveReplayService._missing_candle_stress(payload, frame)

        self.assertEqual(evidence["status"], "contract_test_passed")
        self.assertTrue(evidence["pass"])


if __name__ == "__main__":
    unittest.main()
