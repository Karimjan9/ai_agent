"""Closed source-formula software fixtures, never market/profit evidence."""

import math
import unittest

import pandas as pd

from app.services.instrument_method_features import BOOL_FIELDS, ClosedInstrumentMethodFeatures


FIELDS = {"method_macd_available", "method_macd_cross", "method_rsi_available", "method_rsi_recovery",
    "method_chandelier_available", "method_chandelier_exit"}


def frame(closes):
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=len(closes), freq="5min"),
        "open": closes, "high": [value + .25 for value in closes],
        "low": [value - .25 for value in closes], "close": closes,
        "volume": 0.0, "volume_available": False})


def values(owner, data, index, direction="BUY", seconds=300):
    row = data.iloc[index].to_dict()
    return owner.inputs(row, direction=direction,
        observed_at=(row["time"] + pd.Timedelta(seconds=seconds)).isoformat())


def recursive_ema(items, period):
    result = [float(items[0])]
    for item in items[1:]:
        result.append(result[-1] + 2.0 / (period + 1) * (item - result[-1]))
    return result


class SourceGroundedMethodFeaturesTest(unittest.TestCase):
    def test_macd_recursive_formula_signal_seed_and_two_mature_closed_values(self):
        closes = [100 + .05 * index + math.sin(index / 4) for index in range(60)]
        data = frame(closes)
        owner = ClosedInstrumentMethodFeatures(data)
        fast, slow = recursive_ema(closes, 12), recursive_ema(closes, 26)
        macd = [fast[index] - slow[index] for index in range(25, len(closes))]
        signal = recursive_ema(macd, 9)
        self.assertIsNone(owner.features[24]["_macd"])
        self.assertIsNone(owner.features[32]["_macd_signal"])
        self.assertAlmostEqual(owner.features[33]["_macd_signal"], signal[8], places=11)
        self.assertFalse(values(owner, data, 33)["method_macd_available"])
        self.assertTrue(values(owner, data, 34)["method_macd_available"])
        for index in range(34, len(closes)):
            self.assertAlmostEqual(owner.features[index]["_macd"], macd[index - 25], places=11)
            self.assertAlmostEqual(owner.features[index]["_macd_signal"], signal[index - 25], places=11)

    def test_macd_strict_current_cross_previous_equality_direction_and_current_equality_wait(self):
        for direction, last in [("BUY", 101.0), ("SELL", 99.0)]:
            with self.subTest(direction=direction):
                data = frame([100.0] * 40 + [last, last])
                owner = ClosedInstrumentMethodFeatures(data)
                self.assertFalse(values(owner, data, 39, direction)["method_macd_cross"])
                self.assertTrue(values(owner, data, 40, direction)["method_macd_cross"])
                self.assertFalse(values(owner, data, 40, "SELL" if direction == "BUY" else "BUY")["method_macd_cross"])
                self.assertFalse(values(owner, data, 41, direction)["method_macd_cross"])
                self.assertFalse(values(owner, data, 41, "WAIT")["method_macd_cross"])

    def test_rsi_first_fourteen_deltas_seed_and_wilder_not_ewm_or_rolling_update(self):
        deltas = [2.0, -1.0] * 7 + [3.0, -2.0]
        closes = [100.0]
        for delta in deltas:
            closes.append(closes[-1] + delta)
        data = frame(closes)
        owner = ClosedInstrumentMethodFeatures(data)
        gain, loss = 1.0, .5
        self.assertIsNone(owner.features[13]["_wilder_rsi14"])
        self.assertAlmostEqual(owner.features[14]["_wilder_rsi14"], 100 - 100 / (1 + gain / loss))
        self.assertFalse(values(owner, data, 14)["method_rsi_available"])
        for index, delta in enumerate(deltas[14:], 15):
            gain = (gain * 13 + max(delta, 0)) / 14
            loss = (loss * 13 + max(-delta, 0)) / 14
            self.assertAlmostEqual(owner.features[index]["_wilder_rsi14"], 100 - 100 / (1 + gain / loss))
            self.assertTrue(values(owner, data, index)["method_rsi_available"])

    def test_rsi_flat_zero_loss_zero_gain_bounds_and_directional_recovery(self):
        for closes, expected in [([100.0] * 17, 50.0), ([100.0 + i for i in range(17)], 100.0),
                ([100.0 - i for i in range(17)], 0.0)]:
            data = frame(closes)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertEqual(owner.features[14]["_wilder_rsi14"], expected)
            self.assertFalse(values(owner, data, 15)["method_rsi_recovery"])
        for direction, closes in [("BUY", [100.0 - i for i in range(15)] + [100.0]),
                ("SELL", [100.0 + i for i in range(15)] + [100.0])]:
            data = frame(closes)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertTrue(values(owner, data, 15, direction)["method_rsi_recovery"])
            self.assertFalse(values(owner, data, 15, "SELL" if direction == "BUY" else "BUY")["method_rsi_recovery"])

    def test_rsi_threshold_equality_on_either_closed_bar_waits(self):
        for direction, closes, threshold in [("BUY", [198.0 - 7 * i for i in range(15)] + [139.0, 140.0], 30.0),
                ("SELL", [102.0 + 7 * i for i in range(15)] + [161.0, 160.0], 70.0)]:
            data = frame(closes)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertEqual(owner.features[15]["_wilder_rsi14"], threshold)
            self.assertFalse(values(owner, data, 15, direction)["method_rsi_recovery"])
            self.assertFalse(values(owner, data, 16, direction)["method_rsi_recovery"])

    def test_chandelier_rolling_extremes_and_arithmetic_seed_then_wilder_atr(self):
        data = frame([100.0] * 24)
        data["high"] = [100.0 + i / 10 for i in range(24)]
        data["low"] = 99.0
        data.loc[22, "high"] = 110.0
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(values(owner, data, 20)["method_chandelier_available"])
        self.assertTrue(values(owner, data, 21)["method_chandelier_available"])
        seed = sum(1.0 + i / 10 for i in range(22)) / 22
        current = (seed * 21 + 11.0) / 22
        self.assertAlmostEqual(owner.features[21]["_wilder_atr22"], seed)
        self.assertAlmostEqual(owner.features[22]["_wilder_atr22"], current)
        self.assertAlmostEqual(owner.features[22]["_chandelier_long"], 110.0 - 3 * current)
        self.assertAlmostEqual(owner.features[22]["_chandelier_short"], 99.0 + 3 * current)
        self.assertNotAlmostEqual(owner.features[22]["_wilder_atr22"], owner.features[22]["_atr"])

    def test_chandelier_close_only_directional_owned_exit_and_equality_wait(self):
        for direction, closes in [("BUY", [110.0 - i * .5 for i in range(22)]),
                ("SELL", [90.0 + i * .5 for i in range(22)])]:
            data = frame(closes)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertTrue(values(owner, data, 21, direction)["method_chandelier_exit"])
            self.assertFalse(values(owner, data, 21, "SELL" if direction == "BUY" else "BUY")["method_chandelier_exit"])
            self.assertFalse(values(owner, data, 21, "WAIT")["method_chandelier_exit"])
        flat = frame([100.0] * 22)
        flat.loc[:, ["high", "low"]] = 100.0
        owner = ClosedInstrumentMethodFeatures(flat)
        self.assertFalse(values(owner, flat, 21)["method_chandelier_available"])
        self.assertEqual(owner.features[21]["_wilder_atr22"], 0.0)
        for direction in ("BUY", "SELL"):
            self.assertFalse(values(owner, flat, 21, direction)["method_chandelier_exit"])
        # Equality must wait even with a mature strictly positive ATR, not
        # merely because the all-flat zero-ATR dependency is unavailable.
        atr = (21.0 + 4.5) / 22
        for direction, high, low, close, last_high, last_low, boundary in [
                ("BUY", 110.0, 109.0, 109.5, 108.0, 105.0, 110.0 - 3 * atr),
                ("SELL", 111.0, 110.0, 110.5, 115.0, 112.0, 110.0 + 3 * atr)]:
            equal = frame([close] * 21 + [boundary])
            equal["high"], equal["low"] = high, low
            equal.loc[21, ["high", "low"]] = [last_high, last_low]
            owner = ClosedInstrumentMethodFeatures(equal)
            self.assertTrue(values(owner, equal, 21, direction)["method_chandelier_available"])
            self.assertEqual(owner.features[21]["_chandelier_long" if direction == "BUY" else "_chandelier_short"], boundary)
            self.assertFalse(values(owner, equal, 21, direction)["method_chandelier_exit"])
        wick = frame([100.0] * 22)
        wick.loc[21, "low"] = 90.0
        owner = ClosedInstrumentMethodFeatures(wick)
        self.assertLess(wick.iloc[21]["low"], owner.features[21]["_chandelier_long"])
        self.assertFalse(values(owner, wick, 21)["method_chandelier_exit"])

    def test_gap_cannot_confirm_macd_or_rsi_and_configured_duration_is_used(self):
        for data, index, field in [(frame([100.0] * 40 + [101.0, 101.0]), 40, "method_macd"),
                (frame([100.0 - i for i in range(15)] + [100.0, 100.0]), 15, "method_rsi")]:
            self.assertTrue(values(ClosedInstrumentMethodFeatures(data), data, index)[field + ("_cross" if field == "method_macd" else "_recovery")])
            data.loc[index:, "time"] += pd.Timedelta(minutes=5)
            owner = ClosedInstrumentMethodFeatures(data)
            self.assertFalse(values(owner, data, index)[field + "_available"])
            self.assertFalse(values(owner, data, index)[field + ("_cross" if field == "method_macd" else "_recovery")])
            self.assertTrue(values(owner, data, index + 1)[field + "_available"])
        data = frame([100.0] * 40 + [101.0])
        data["time"] = pd.date_range("2025-01-06T08:00:00Z", periods=len(data), freq="15min")
        self.assertFalse(values(ClosedInstrumentMethodFeatures(data), data, 40)["method_macd_available"])
        self.assertTrue(values(ClosedInstrumentMethodFeatures(data, bar_duration_seconds=900), data, 40,
            seconds=900)["method_macd_cross"])

    def test_future_suffix_cannot_rewrite_closed_public_or_private_formula_prefixes(self):
        data = frame([100 + math.sin(i / 4) * 4 + i * .01 for i in range(120)])
        prefix = data.iloc[:80].copy()
        changed = data.copy()
        changed.loc[80:, ["open", "high", "low", "close"]] *= 100
        left, right = ClosedInstrumentMethodFeatures(prefix), ClosedInstrumentMethodFeatures(changed)
        private = ["_macd", "_macd_signal", "_wilder_rsi14", "_wilder_atr22", "_chandelier_long", "_chandelier_short"]
        for index in range(80):
            for field in private:
                self.assertEqual(left.features[index][field], right.features[index][field])
            for direction in ("BUY", "SELL"):
                a, b = values(left, prefix, index, direction), values(right, changed, index, direction)
                self.assertEqual({key: a[key] for key in FIELDS}, {key: b[key] for key in FIELDS})

    def test_supplied_derived_columns_and_input_spoofs_are_ignored_while_ohlc_drift_refuses(self):
        data = frame([100.0] * 40 + [101.0])
        spoofed = data.copy()
        for field in FIELDS | {"macd", "macd_signal", "rsi", "atr", "chandelier_long", "chandelier_short"}:
            spoofed[field] = True if field in FIELDS else 999999.0
        left, right = ClosedInstrumentMethodFeatures(data), ClosedInstrumentMethodFeatures(spoofed)
        expected, actual = values(left, data, 39), values(right, spoofed, 39)
        self.assertEqual({key: expected[key] for key in FIELDS}, {key: actual[key] for key in FIELDS})
        row = data.iloc[39].to_dict()
        row.update({key: True for key in FIELDS})
        observed = (row["time"] + pd.Timedelta(minutes=5)).isoformat()
        actual = ClosedInstrumentMethodFeatures(data).inputs(row, direction="BUY", observed_at=observed)
        self.assertFalse(actual["method_macd_cross"])
        self.assertFalse(actual["method_rsi_recovery"])
        row["close"] += .01
        with self.assertRaisesRegex(ValueError, "ORIGINAL_ROW_DRIFT"):
            ClosedInstrumentMethodFeatures(data).inputs(row, direction="BUY", observed_at=observed)

    def test_current_candle_is_unavailable_before_close_and_all_new_fields_are_typed(self):
        data = frame([100.0] * 40 + [101.0])
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertTrue(FIELDS.issubset(BOOL_FIELDS))
        for field in FIELDS:
            self.assertIsInstance(values(owner, data, 40)[field], bool)
        with self.assertRaisesRegex(ValueError, "ORIGINAL_CLOSED_ROW_REQUIRED"):
            values(ClosedInstrumentMethodFeatures(data), data, 40, seconds=299)

    def test_nonfinite_source_or_overflowed_derived_seed_cannot_supply_chandelier_levels(self):
        data = frame([100.0] * 22)
        data.loc[0, "close"] = float("inf")
        with self.assertRaisesRegex(ValueError, "OHLC_INVALID"):
            ClosedInstrumentMethodFeatures(data)
        data = frame([1e308] * 22)
        data["high"], data["low"] = 1.5e308, .5e308
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(values(owner, data, 21)["method_chandelier_available"])
        self.assertFalse(values(owner, data, 21)["method_chandelier_exit"])


if __name__ == "__main__":
    unittest.main()
