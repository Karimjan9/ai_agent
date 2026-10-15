"""Synthetic closed BandWidth Squeeze contracts, never trading-skill evidence."""

import math
import unittest

import pandas as pd

from app.services.instrument_method_features import ClosedInstrumentMethodFeatures


SQUEEZE_FIELDS = {
    "method_bandwidth_squeeze_available",
    "method_bandwidth_squeeze_breakout",
    "method_bandwidth",
}


def frame(closes):
    return pd.DataFrame({
        "time": pd.date_range("2025-01-06T08:00:00Z", periods=len(closes), freq="5min"),
        "open": closes,
        "high": [value + .25 for value in closes],
        "low": [value - .25 for value in closes],
        "close": closes,
        "market_regime": "trend_up",
        "market_session": "london",
        "volatility_regime": "normal_volatility",
        "volume": 0.0,
        "volume_available": False,
    })


def values(owner, data, index, direction="BUY"):
    row = data.iloc[index].to_dict()
    result = owner.inputs(
        row,
        direction=direction,
        observed_at=(row["time"] + pd.Timedelta(minutes=5)).isoformat(),
    )
    return {key: result[key] for key in SQUEEZE_FIELDS if key in result}


class ClosedBandwidthSqueezeTest(unittest.TestCase):
    def test_crossing_from_opposite_outside_band_is_not_the_declared_inside_breakout(self):
        for previous, current, direction in ((99., 101., "BUY"), (101., 99., "SELL")):
            data = frame([100.] * 144 + [previous, current])
            owner = ClosedInstrumentMethodFeatures(data)
            result = values(owner, data, 145, direction)
            self.assertTrue(result["method_bandwidth_squeeze_available"])
            self.assertFalse(result["method_bandwidth_squeeze_breakout"])

    def test_nonpositive_current_middle_does_not_supply_normalized_availability(self):
        for level in (0., -100.):
            data = frame([level] * 145)
            result = values(ClosedInstrumentMethodFeatures(data), data, 144)
            self.assertFalse(result["method_bandwidth_squeeze_available"])
            self.assertFalse(result["method_bandwidth_squeeze_breakout"])
            self.assertNotIn("method_bandwidth", result)

    def test_prior_125_mature_widths_require_index_144_before_confirmation(self):
        data = frame([100.0] * 144 + [101.0])
        owner = ClosedInstrumentMethodFeatures(data)
        previous = values(owner, data, 143)
        current = values(owner, data, 144)
        self.assertFalse(previous["method_bandwidth_squeeze_available"])
        self.assertFalse(previous["method_bandwidth_squeeze_breakout"])
        self.assertEqual(previous["method_bandwidth"], 0.0)
        self.assertTrue(current["method_bandwidth_squeeze_available"])
        self.assertTrue(current["method_bandwidth_squeeze_breakout"])

    def test_buy_and_sell_fresh_crossings_use_population_std_normalized_width(self):
        for direction, close in (("BUY", 101.0), ("SELL", 99.0)):
            with self.subTest(direction=direction):
                data = frame([100.0] * 144 + [close])
                owner = ClosedInstrumentMethodFeatures(data)
                result = values(owner, data, 144, direction)
                mean = (19 * 100.0 + close) / 20
                deviation = math.sqrt(
                    (19 * (100.0 - mean) ** 2 + (close - mean) ** 2) / 20
                )
                self.assertTrue(result["method_bandwidth_squeeze_available"])
                self.assertTrue(result["method_bandwidth_squeeze_breakout"])
                self.assertAlmostEqual(result["method_bandwidth"], 4 * deviation / mean, places=12)
                opposite = "SELL" if direction == "BUY" else "BUY"
                self.assertFalse(values(owner, data, 144, opposite)["method_bandwidth_squeeze_breakout"])
                self.assertFalse(values(owner, data, 144, "WAIT")["method_bandwidth_squeeze_breakout"])

    def test_flat_equal_band_contact_does_not_break_out(self):
        data = frame([100.0] * 146)
        owner = ClosedInstrumentMethodFeatures(data)
        result = values(owner, data, 144)
        self.assertTrue(result["method_bandwidth_squeeze_available"])
        self.assertEqual(result["method_bandwidth"], 0.0)
        self.assertFalse(result["method_bandwidth_squeeze_breakout"])
        self.assertFalse(values(owner, data, 144, "SELL")["method_bandwidth_squeeze_breakout"])

    def test_repeated_or_continuing_outside_close_is_not_a_fresh_breakout(self):
        for direction, closes in (
            ("BUY", [101.0, 101.0, 102.0]),
            ("SELL", [99.0, 99.0, 98.0]),
        ):
            with self.subTest(direction=direction):
                data = frame([100.0] * 144 + closes)
                owner = ClosedInstrumentMethodFeatures(data)
                self.assertTrue(values(owner, data, 144, direction)["method_bandwidth_squeeze_breakout"])
                self.assertFalse(values(owner, data, 145, direction)["method_bandwidth_squeeze_breakout"])
                self.assertFalse(values(owner, data, 146, direction)["method_bandwidth_squeeze_breakout"])

    def test_squeeze_alert_can_be_used_on_fifth_later_bar_but_not_sixth(self):
        for direction, sign in (("BUY", 1.0), ("SELL", -1.0)):
            for delay, tail in (
                (5, [100.01, 99.99, 100.01, 100.0]),
                (6, [100.01, 99.99, 100.01, 99.99, 100.0]),
            ):
                with self.subTest(direction=direction, delay=delay):
                    data = frame([100.0] * 144 + tail + [100.0 + sign])
                    index = 143 + delay
                    owner = ClosedInstrumentMethodFeatures(data)
                    result = values(owner, data, index, direction)
                    self.assertTrue(result["method_bandwidth_squeeze_available"])
                    self.assertEqual(result["method_bandwidth_squeeze_breakout"], delay == 5)

    def test_each_of_last_five_timestamp_intervals_must_be_contiguous(self):
        for gap_at in range(140, 145):
            with self.subTest(gap_at=gap_at):
                data = frame([100.0] * 144 + [101.0])
                data.loc[gap_at:, "time"] += pd.Timedelta(minutes=5)
                result = values(ClosedInstrumentMethodFeatures(data), data, 144)
                self.assertFalse(result["method_bandwidth_squeeze_available"])
                self.assertFalse(result["method_bandwidth_squeeze_breakout"])
        # This contract requires the last six timestamps, not gap-free history.
        data = frame([100.0] * 144 + [101.0])
        data.loc[139:, "time"] += pd.Timedelta(minutes=5)
        result = values(ClosedInstrumentMethodFeatures(data), data, 144)
        self.assertTrue(result["method_bandwidth_squeeze_available"])
        self.assertTrue(result["method_bandwidth_squeeze_breakout"])

    def test_future_suffix_cannot_rewrite_any_closed_prefix_observation(self):
        data = frame([100.0] * 144 + [101.0, 101.0, 102.0, 90.0, 110.0])
        changed = data.copy()
        changed.loc[145:, ["open", "high", "low", "close"]] *= 1000.0
        prefix = ClosedInstrumentMethodFeatures(data.iloc[:145])
        full = ClosedInstrumentMethodFeatures(changed)
        for index in range(145):
            with self.subTest(index=index):
                self.assertEqual(values(prefix, data, index), values(full, changed, index))

    def test_positive_price_scaling_preserves_normalized_width_and_confirmation(self):
        data = frame([100.0] * 144 + [101.0, 101.0, 102.0, 100.0])
        for factor in (0.01, 7.5, 1000.0):
            with self.subTest(factor=factor):
                scaled = data.copy()
                scaled.loc[:, ["open", "high", "low", "close"]] *= factor
                original_owner = ClosedInstrumentMethodFeatures(data)
                scaled_owner = ClosedInstrumentMethodFeatures(scaled)
                for index in range(19, len(data)):
                    original = values(original_owner, data, index)
                    result = values(scaled_owner, scaled, index)
                    self.assertEqual(result["method_bandwidth_squeeze_available"],
                        original["method_bandwidth_squeeze_available"])
                    self.assertEqual(result["method_bandwidth_squeeze_breakout"],
                        original["method_bandwidth_squeeze_breakout"])
                    self.assertAlmostEqual(result["method_bandwidth"], original["method_bandwidth"], places=10)

    def test_original_closed_time_is_required_and_ohlc_spoof_is_rejected(self):
        data = frame([100.0] * 144 + [101.0])
        owner = ClosedInstrumentMethodFeatures(data)
        row = data.iloc[144].to_dict()
        with self.assertRaisesRegex(ValueError, "ORIGINAL_CLOSED_ROW"):
            owner.inputs(row, direction="BUY",
                observed_at=(row["time"] + pd.Timedelta(seconds=299)).isoformat())
        observed = (row["time"] + pd.Timedelta(minutes=5)).isoformat()
        for field in ("open", "high", "low", "close"):
            with self.subTest(field=field):
                forged = dict(row)
                forged[field] += 1.0
                with self.assertRaisesRegex(ValueError, "ORIGINAL_ROW_DRIFT"):
                    owner.inputs(forged, direction="BUY", observed_at=observed)
        self.assertTrue(values(owner, data, 144)["method_bandwidth_squeeze_breakout"])

    def test_frame_and_caller_method_flags_cannot_replace_owner_observations(self):
        flat = frame([100.0] * 145)
        spoofed = flat.copy()
        spoofed["method_bandwidth_squeeze_available"] = True
        spoofed["method_bandwidth_squeeze_breakout"] = True
        spoofed["method_bandwidth"] = 123.0
        expected = values(ClosedInstrumentMethodFeatures(flat), flat, 144)
        actual = values(ClosedInstrumentMethodFeatures(spoofed), spoofed, 144)
        self.assertEqual(actual, expected)
        self.assertFalse(actual["method_bandwidth_squeeze_breakout"])
        self.assertEqual(actual["method_bandwidth"], 0.0)

        data = frame([100.0] * 144 + [101.0])
        owner = ClosedInstrumentMethodFeatures(data)
        row = data.iloc[144].to_dict()
        row.update(method_bandwidth_squeeze_available=False,
            method_bandwidth_squeeze_breakout=False, method_bandwidth=0.0)
        result = owner.inputs(row, direction="BUY",
            observed_at=(row["time"] + pd.Timedelta(minutes=5)).isoformat())
        self.assertTrue(result["method_bandwidth_squeeze_available"])
        self.assertTrue(result["method_bandwidth_squeeze_breakout"])
        self.assertGreater(result["method_bandwidth"], 0.0)


if __name__ == "__main__":
    unittest.main()
