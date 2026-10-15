"""Cheap deterministic mechanics, not market performance or broker fills."""

import copy
import unittest

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import _advance_trailing_stop, _intrabar_exit
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures


class CuratorManagementMechanicsTest(unittest.TestCase):
    def request(self, multiplier=2):
        return SimpleBacktestRequest(parameters={"trailing_atr_multiplier": multiplier},
            execution=ExecutionConfig(spread_points=0, slippage_points=0,
                commission_percent=.01, swap_per_day_percent=.002,
                intrabar_policy="conservative"))

    def test_buy_and_sell_stops_ratchet_without_mutating_risk_or_target(self):
        for direction, stop, close, expected in [("BUY", 95., 105., 101.),
                                                   ("SELL", 105., 95., 99.)]:
            with self.subTest(direction=direction):
                position = {"direction": direction, "stop_loss": stop, "take_profit": 120.,
                            "position_size_multiple": .8, "initial_stop_loss": stop}
                original = copy.deepcopy(position)
                _advance_trailing_stop(position, {"close": close, "_management_atr": 2.}, self.request())
                self.assertEqual(position["stop_loss"], expected)
                for field in ("take_profit", "position_size_multiple", "initial_stop_loss"):
                    self.assertEqual(position[field], original[field])
                # A later adverse close or larger ATR cannot loosen protection.
                adverse = close - 10 if direction == "BUY" else close + 10
                _advance_trailing_stop(position, {"close": adverse, "_management_atr": 5.}, self.request())
                self.assertEqual(position["stop_loss"], expected)

    def test_disabled_or_unavailable_atr_preserves_native_stop(self):
        for multiplier, row in [(0, {"close": 105., "_management_atr": 2.}),
                                (2, {"close": 105., "_management_atr": 0.}),
                                (2, {"close": 105.})]:
            position = {"direction": "BUY", "stop_loss": 95.}
            _advance_trailing_stop(position, row, self.request(multiplier))
            self.assertEqual(position["stop_loss"], 95.)

    def test_prior_closed_ratchet_can_gap_at_next_open_not_fill_at_old_line(self):
        position = {"direction": "BUY", "stop_loss": 95., "take_profit": 115.}
        _advance_trailing_stop(position, {"close": 105., "_management_atr": 2.}, self.request())
        self.assertEqual(position["stop_loss"], 101.)
        price, reason = _intrabar_exit("BUY", position,
            {"open": 100., "high": 110., "low": 99.}, self.request())
        self.assertEqual((price, reason), (100., "gap_stop"))

    def test_existing_conservative_same_bar_stop_target_precedence_is_retained(self):
        position = {"direction": "BUY", "stop_loss": 99., "take_profit": 103.}
        self.assertEqual(_intrabar_exit("BUY", position,
            {"open": 100., "high": 104., "low": 98.}, self.request()), (99., "intrabar_stop"))

    def test_chandelier_line_is_not_a_monotonic_ratchet(self):
        frame = pd.DataFrame({"time": pd.date_range("2025-10-13T00:00:00Z", periods=60, freq="5min"),
            "open": 99.5, "high": 100., "low": 99., "close": 99.5})
        frame.loc[21, "high"] = 110.
        owner = ClosedInstrumentMethodFeatures(frame)
        # The spike falls outside rolling HH22. Unlike a broker ratchet, the
        # computed long line may move down; it is a closed-cross exit signal.
        self.assertLess(owner.features[43]["_chandelier_long"], owner.features[42]["_chandelier_long"])


if __name__ == "__main__":
    unittest.main()
