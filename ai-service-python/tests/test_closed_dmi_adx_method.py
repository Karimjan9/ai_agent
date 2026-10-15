"""Scalar-reference closed DMI/ADX correctness, never economic skill proof."""

import math
import unittest

import pandas as pd

from app.services.instrument_method_features import (
    ClosedInstrumentMethodFeatures,
    _adx_strength,
    _directional_movement14,
    _wilder_adx14,
)


DMI_FIELDS = {
    "method_dmi_available", "method_dmi_aligned", "method_adx_strong", "method_adx_weak",
    "method_adx14", "method_di_plus14", "method_di_minus14",
}
NUMBERS = {"method_adx14", "method_di_plus14", "method_di_minus14"}


def frame(count=200, *, direction=0):
    closes = [100.0 + direction * index * .1 + .7 * math.sin(index * .41)
        + .3 * math.cos(index * .17) for index in range(count)]
    return pd.DataFrame({
        "time": pd.date_range("2025-01-06T08:00:00Z", periods=count, freq="5min"),
        "open": closes,
        "high": [value + .4 + .1 * math.sin(index * .29) ** 2 for index, value in enumerate(closes)],
        "low": [value - .3 - .1 * math.cos(index * .23) ** 2 for index, value in enumerate(closes)],
        "close": closes,
    })


def values(owner, data, index, direction="BUY", *, row=None):
    row = data.iloc[index].to_dict() if row is None else row
    result = owner.inputs(row, direction=direction,
        observed_at=(row["time"] + pd.Timedelta(minutes=5)).isoformat())
    return {key: value for key, value in result.items() if key in DMI_FIELDS}


def scalar_reference(data):
    """Independent sum-form Wilder reference, not the production average helper."""
    result = []
    initial = []
    plus_sum = minus_sum = range_sum = None
    dx_seed = []
    adx = None
    for index, row in enumerate(data.to_dict("records")):
        positive = negative = strength = None
        if index:
            previous = data.iloc[index - 1]
            up, down = row["high"] - previous["high"], previous["low"] - row["low"]
            positive_move = up if up > 0 and up > down else 0.0
            negative_move = down if down > 0 and down > up else 0.0
            true_range = max(row["high"] - row["low"],
                abs(row["high"] - previous["close"]), abs(row["low"] - previous["close"]))
            if index <= 14:
                initial.append((positive_move, negative_move, true_range))
                if index == 14:
                    plus_sum, minus_sum, range_sum = (
                        sum(item[column] for item in initial) for column in range(3))
            else:
                plus_sum = plus_sum - plus_sum / 14.0 + positive_move
                minus_sum = minus_sum - minus_sum / 14.0 + negative_move
                range_sum = range_sum - range_sum / 14.0 + true_range
            if range_sum is not None and range_sum > 0:
                positive = 100.0 * plus_sum / range_sum
                negative = 100.0 * minus_sum / range_sum
                total = positive + negative
                dx = 100.0 * abs(positive - negative) / total if total > 0 else 0.0
                if adx is None:
                    dx_seed.append(dx)
                    if len(dx_seed) == 14:
                        adx = sum(dx_seed) / 14.0
                else:
                    adx = (adx * 13.0 + dx) / 14.0
                strength = adx
            else:
                dx_seed, adx = [], None
        result.append((positive, negative, strength))
    return result


class ClosedDmiAdxMethodTest(unittest.TestCase):
    def test_independent_scalar_wilder_seed_recursion_and_actual_public_values(self):
        data = frame()
        expected = scalar_reference(data)
        ranges = pd.concat([data.high - data.low,
            (data.high - data.close.shift()).abs(), (data.low - data.close.shift()).abs()], axis=1).max(axis=1)
        actual = _directional_movement14(data.high, data.low, ranges)
        for index in range(len(data)):
            for series, number in zip(actual, expected[index]):
                if number is None:
                    self.assertTrue(pd.isna(series.iloc[index]))
                else:
                    self.assertAlmostEqual(series.iloc[index], number, places=11)
        owner = ClosedInstrumentMethodFeatures(data)
        for index in (149, 160, 199):
            result = values(owner, data, index)
            self.assertTrue(result["method_dmi_available"])
            for key, number in zip(("method_di_plus14", "method_di_minus14", "method_adx14"), expected[index]):
                self.assertAlmostEqual(result[key], number, places=11)

    def test_first_directional_range_is_index_one_not_the_initial_candle_range(self):
        data = frame(170)
        data.loc[0, ["open", "close", "high", "low"]] = [100., 100., 500., 1.]
        expected = scalar_reference(data)
        result = values(ClosedInstrumentMethodFeatures(data), data, 149)
        self.assertAlmostEqual(result["method_di_plus14"], expected[149][0], places=11)
        self.assertAlmostEqual(result["method_di_minus14"], expected[149][1], places=11)
        self.assertAlmostEqual(result["method_adx14"], expected[149][2], places=11)

    def test_mature_dx_seeds_adx_at_index_27_but_public_requires_150_observed_bars(self):
        data = frame(150)
        owner = ClosedInstrumentMethodFeatures(data)
        immature = values(owner, data, 148)
        self.assertFalse(immature["method_dmi_available"])
        self.assertTrue(NUMBERS.isdisjoint(immature))
        self.assertTrue(values(owner, data, 149)["method_dmi_available"])
        short = frame(149)
        self.assertFalse(values(ClosedInstrumentMethodFeatures(short), short, 148)["method_dmi_available"])
        ranges = pd.concat([data.high - data.low,
            (data.high - data.close.shift()).abs(), (data.low - data.close.shift()).abs()], axis=1).max(axis=1)
        positive, negative, strength = _directional_movement14(data.high, data.low, ranges)
        self.assertTrue(pd.isna(positive.iloc[13]))
        self.assertTrue(math.isfinite(positive.iloc[14]))
        self.assertTrue(math.isfinite(negative.iloc[14]))
        self.assertTrue(pd.isna(strength.iloc[26]))
        self.assertTrue(math.isfinite(strength.iloc[27]))

    def test_inside_and_tied_outside_movement_supply_no_guessed_direction(self):
        data = frame(160)
        # Alternating symmetric outside expansion and inside contraction:
        # positive and negative movement tie outside, and both are negative inside.
        for index in range(len(data)):
            width = 1.0 if index % 2 else 2.0
            data.loc[index, ["open", "close", "high", "low"]] = [100., 100., 100. + width, 100. - width]
        for direction in ("BUY", "SELL", "WAIT"):
            result = values(ClosedInstrumentMethodFeatures(data), data, 159, direction)
            self.assertTrue(result["method_dmi_available"])
            self.assertEqual(result["method_di_plus14"], 0.0)
            self.assertEqual(result["method_di_minus14"], 0.0)
            self.assertEqual(result["method_adx14"], 0.0)
            self.assertTrue(result["method_adx_weak"])
            self.assertFalse(result["method_dmi_aligned"])
            self.assertFalse(result["method_adx_strong"])

    def test_one_sided_trends_compare_di_for_buy_sell_not_adx_direction(self):
        for sign, aligned in ((1, "BUY"), (-1, "SELL")):
            data = frame(160)
            closes = [100. + sign * index * .1 for index in range(len(data))]
            data.loc[:, "open"] = closes
            data.loc[:, "close"] = closes
            data.loc[:, "high"] = [value + .2 for value in closes]
            data.loc[:, "low"] = [value - .2 for value in closes]
            owner = ClosedInstrumentMethodFeatures(data)
            for direction in ("BUY", "SELL", "WAIT"):
                result = values(owner, data, 159, direction)
                self.assertAlmostEqual(result["method_adx14"], 100.0)
                self.assertTrue(result["method_adx_strong"])
                self.assertFalse(result["method_adx_weak"])
                self.assertEqual(result["method_dmi_aligned"], direction == aligned)

    def test_flat_zero_true_range_is_unavailable_not_fabricated_dx_or_probability(self):
        data = frame(170)
        data.loc[:, ["open", "close", "high", "low"]] = 100.
        result = values(ClosedInstrumentMethodFeatures(data), data, 169)
        self.assertTrue(NUMBERS.isdisjoint(result))
        self.assertTrue(all(value is False for value in result.values()))

    def test_constant_positive_range_without_dm_is_defined_weak_zero_adx(self):
        data = frame(170)
        data.loc[:, ["open", "close"]] = 100.
        data.loc[:, "high"] = 101.
        data.loc[:, "low"] = 99.
        result = values(ClosedInstrumentMethodFeatures(data), data, 169)
        self.assertTrue(result["method_dmi_available"])
        self.assertEqual(result["method_adx14"], 0.0)
        self.assertTrue(result["method_adx_weak"])
        self.assertFalse(result["method_dmi_aligned"])

    def test_flat_initial_history_recovers_only_after_fourteen_real_mature_dx(self):
        data = frame(200)
        data.loc[:169, ["open", "close", "high", "low"]] = 100.
        for index in range(170, len(data)):
            close = 100. + (index - 169) * .1
            data.loc[index, ["open", "close", "high", "low"]] = [close, close, close + .05, close - .05]
        owner = ClosedInstrumentMethodFeatures(data)
        self.assertFalse(values(owner, data, 169)["method_dmi_available"])
        self.assertFalse(values(owner, data, 182)["method_dmi_available"])
        result = values(owner, data, 183)
        self.assertTrue(result["method_dmi_available"])
        self.assertAlmostEqual(result["method_adx14"], scalar_reference(data)[183][2])

    def test_unusable_dx_resets_existing_adx_and_requires_a_complete_new_seed(self):
        for invalid in (float("nan"), float("inf"), float("-inf"), -1., 101.):
            series = pd.Series([10.] * 15 + [invalid] + [30.] * 15, dtype=float)
            result = _wilder_adx14(series)
            self.assertAlmostEqual(result.iloc[13], 10.)
            self.assertTrue(pd.isna(result.iloc[15]))
            self.assertTrue(result.iloc[16:29].isna().all())
            self.assertAlmostEqual(result.iloc[29], 30.)
            self.assertAlmostEqual(result.iloc[30], 30.)

    def test_current_missing_bar_blocks_only_current_pair_old_gap_is_not_faked_continuity(self):
        data = frame(160)
        data.loc[149:, "time"] += pd.Timedelta(minutes=5)
        owner = ClosedInstrumentMethodFeatures(data)
        missing = values(owner, data, 149)
        self.assertFalse(missing["method_dmi_available"])
        self.assertTrue(NUMBERS.isdisjoint(missing))
        self.assertTrue(values(owner, data, 150)["method_dmi_available"])
        old_gap = frame(160)
        old_gap.loc[10:, "time"] += pd.Timedelta(days=2)
        self.assertTrue(values(ClosedInstrumentMethodFeatures(old_gap), old_gap, 149)["method_dmi_available"])

    def test_prefix_invariance_and_future_price_changes_never_revise_closed_observation(self):
        data = frame(200)
        prefix = data.iloc[:150].copy()
        future = data.copy()
        future.loc[150:, ["open", "close", "high", "low"]] *= 1000.
        original = values(ClosedInstrumentMethodFeatures(data), data, 149)
        self.assertEqual(original, values(ClosedInstrumentMethodFeatures(prefix), prefix, 149))
        self.assertEqual(original, values(ClosedInstrumentMethodFeatures(future), future, 149))

    def test_supplied_adx_di_and_method_columns_never_override_closed_feature_owner(self):
        data = frame(160)
        expected = values(ClosedInstrumentMethodFeatures(data), data, 159)
        spoofed = data.copy()
        for key in DMI_FIELDS:
            spoofed[key] = False if key not in NUMBERS else 999999.
        spoofed["ADX"] = 100.
        spoofed["DI_plus"] = 0.
        owner = ClosedInstrumentMethodFeatures(spoofed)
        row = spoofed.iloc[159].to_dict()
        row.update({"method_adx14": -1., "method_dmi_aligned": True})
        self.assertEqual(expected, values(owner, spoofed, 159, row=row))

    def test_strict_strength_thresholds_have_neutral_equality_and_intermediate_zone(self):
        for number, answer in ((0., (False, True)), (19.999999, (False, True)),
            (20., (False, False)), (22., (False, False)), (25., (False, False)),
            (25.000001, (True, False)), (100., (True, False))):
            self.assertEqual(_adx_strength(number), answer)
        for number in (None, True, float("nan"), float("inf"), float("-inf"), -1., 101.):
            self.assertEqual(_adx_strength(number), (False, False))

    def test_invalid_nonfinite_prices_fail_closed_and_open_candle_cannot_supply_inputs(self):
        for number in (float("nan"), float("inf"), float("-inf")):
            data = frame(160)
            data.loc[100, "high"] = number
            with self.assertRaisesRegex(ValueError, "OHLC_INVALID"):
                ClosedInstrumentMethodFeatures(data)
        data = frame(160)
        owner = ClosedInstrumentMethodFeatures(data)
        row = data.iloc[159].to_dict()
        with self.assertRaisesRegex(ValueError, "ORIGINAL_CLOSED_ROW_REQUIRED"):
            owner.inputs(row, direction="BUY", observed_at=row["time"].isoformat())

    def test_positive_affine_price_scaling_preserves_dimensionless_directional_values(self):
        data = frame(160)
        scaled = data.copy()
        scaled.loc[:, ["open", "close", "high", "low"]] *= 5.
        left = values(ClosedInstrumentMethodFeatures(data), data, 159)
        right = values(ClosedInstrumentMethodFeatures(scaled), scaled, 159)
        for key in DMI_FIELDS - NUMBERS:
            self.assertEqual(left[key], right[key])
        for key in NUMBERS:
            self.assertAlmostEqual(left[key], right[key], places=10)


if __name__ == "__main__":
    unittest.main()
