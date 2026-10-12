import unittest

import pandas as pd
from ta.trend import ADXIndicator
from ta.volatility import AverageTrueRange

from app.services.market_regime import apply_market_regime


class MarketRegimeWarmupTest(unittest.TestCase):
    def candles(self, count):
        close = pd.Series([100.0 + index * 0.1 for index in range(count)], dtype=float)
        return pd.DataFrame({"open": close, "high": close + 0.5, "low": close - 0.5, "close": close})

    def test_short_closed_histories_remain_unknown_without_fabricated_adx(self):
        for count in [0, 1, 13, 14, 27]:
            with self.subTest(candles=count):
                result = apply_market_regime(self.candles(count))
                self.assertTrue(result["adx"].isna().all())
                self.assertTrue((result["market_regime"] == "unknown").all())
                if count < 14:
                    self.assertTrue(result["atr_regime"].isna().all())

    def test_mature_history_preserves_original_ta_indicator_values(self):
        source = self.candles(300)
        result = apply_market_regime(source)
        pd.testing.assert_series_equal(result["adx"], ADXIndicator(source.high, source.low, source.close, window=14).adx(), check_names=False)
        pd.testing.assert_series_equal(result["atr_regime"], AverageTrueRange(source.high, source.low, source.close, window=14).average_true_range(), check_names=False)

    def test_first_complete_adx_window_uses_only_its_closed_prefix(self):
        source = self.candles(80)
        prefix = apply_market_regime(source.iloc[:28])
        full = apply_market_regime(source)
        self.assertTrue(pd.notna(prefix.adx.iloc[-1]))
        self.assertEqual(prefix.adx.iloc[-1], full.adx.iloc[27])


if __name__ == "__main__":
    unittest.main()
