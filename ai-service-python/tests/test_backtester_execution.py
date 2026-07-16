import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe


def golden_strategy(frame: pd.DataFrame, _parameters: dict | None) -> pd.DataFrame:
    frame = frame.copy()
    frame["signal"] = "WAIT"
    frame.loc[199, "signal"] = "BUY"
    return frame


class BacktesterExecutionRegressionTest(unittest.TestCase):
    def candles(self) -> pd.DataFrame:
        rows = 205
        prices = [100.0 + ((index % 10) * 0.01) for index in range(rows)]
        frame = pd.DataFrame({
            "time": pd.date_range("2026-01-05 00:00:00", periods=rows, freq="h"),
            "open": prices,
            "high": [price + 0.4 for price in prices],
            "low": [price - 0.4 for price in prices],
            "close": prices,
            "volume": [1000.0] * rows,
        })
        return frame

    def payload(self, reject_gaps: bool = True) -> SimpleBacktestRequest:
        return SimpleBacktestRequest(
            symbol="XAUUSD",
            timeframe="H1",
            strategy="golden_v1",
            risk_per_trade=1,
            execution=ExecutionConfig(
                stop_loss_percent=0.5,
                take_profit_percent=1.0,
                max_leverage=5,
                reject_unexpected_gaps=reject_gaps,
            ),
        )

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_signal_close_enters_next_open_and_checks_first_candle(self, _strategy):
        frame = self.candles()
        frame.loc[200, "low"] = 99.4

        result = run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)

        self.assertEqual(result.total_trades, 1)
        trade = result.trades[0]
        self.assertEqual(pd.Timestamp(trade.signal_time), frame.loc[199, "time"])
        self.assertEqual(pd.Timestamp(trade.entry_time), frame.loc[200, "time"])
        self.assertEqual(pd.Timestamp(trade.exit_time), frame.loc[200, "time"])
        self.assertEqual(trade.exit_reason, "intrabar_stop")
        self.assertEqual(trade.position_size_multiple, 2.0)
        self.assertEqual(trade.profit_percent, -1.0)
        self.assertEqual(result.final_balance, 9900.0)

    @patch("app.services.backtester.get_strategy", return_value=golden_strategy)
    def test_post_entry_gap_fills_at_open_not_stop_price(self, _strategy):
        frame = self.candles()
        frame.loc[201, ["open", "high", "low", "close"]] = [99.0, 99.2, 98.8, 99.0]

        result = run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)

        trade = result.trades[0]
        self.assertEqual(pd.Timestamp(trade.exit_time), frame.loc[201, "time"])
        self.assertEqual(trade.exit_reason, "gap_stop")
        self.assertEqual(trade.exit_price, 99.0)
        self.assertEqual(trade.profit_percent, -2.0)

    def test_unexpected_weekday_gap_hard_blocks_backtest(self):
        frame = self.candles()
        frame.loc[100:, "time"] = frame.loc[100:, "time"] + pd.Timedelta(hours=1)

        with self.assertRaisesRegex(ValueError, "hard-gate failed: 1"):
            run_simple_ema_rsi_backtest_on_dataframe(self.payload(), frame)


if __name__ == "__main__":
    unittest.main()
