"""Synthetic no-volume compatibility checks; no market/probability evidence."""

import copy
import math
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import ExecutionConfig, SimpleBacktestRequest
from app.services.backtester import (
    _effective_lane_signal,
    _entry_eligibility,
    run_simple_ema_rsi_backtest_on_dataframe,
)
from app.services.execution_contract import execution_contract_metadata
from app.services.volume_features import VOLUME_LANES, apply_volume_policy


def frame(rows=360):
    closes = [100.0 + index * .03 + .5 * math.sin(index * .3) for index in range(rows)]
    return pd.DataFrame({"time": pd.date_range("2025-01-06T08:00:00Z", periods=rows, freq="5min"),
        "open": closes, "high": [value + .3 for value in closes],
        "low": [value - .3 for value in closes], "close": closes,
        "volume": 0.0, "volume_available": False, "signal": "BUY", "_management_atr": .6})


def request():
    return SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5",
        initial_balance=10000.0, risk_per_trade=1.0,
        parameters={"ema_fast": 50, "ema_slow": 200, "rsi_period": 14,
            "volume_lane": "none", "minimum_signal_confidence": .35,
            "atr_stop_multiplier": 1.5, "atr_target_multiplier": 2.5,
            "confidence_calibration_enabled": True, "confidence_calibration_min_samples": 15},
        execution=ExecutionConfig(spread_points=0.0, slippage_points=0.0, commission_percent=0.0))


class VolumeNoneConfidenceCompatibilityTest(unittest.TestCase):
    def test_missing_confidence_stays_absent_with_unchanged_existing_gate_fallback(self):
        data = frame(2)
        payload = request()
        parameters = copy.deepcopy(payload.parameters)
        execution = execution_contract_metadata(payload)
        out = apply_volume_policy(data, payload.parameters, payload.strategy)
        self.assertNotIn("signal_confidence", out)
        self.assertNotIn("pre_volume_signal_confidence", out)
        self.assertNotIn("signal_confidence", data)
        pd.testing.assert_series_equal(out["signal"], data["signal"])
        self.assertEqual(out.attrs["volume_quality"]["status"], "not_requested")
        self.assertFalse(out.attrs["volume_quality"]["blocking"])
        self.assertFalse(out.attrs["volume_quality"]["source_observed"])
        self.assertEqual(_effective_lane_signal(data.iloc[0], None)[1], 1.0)
        self.assertEqual(_effective_lane_signal(out.iloc[0], None)[1], 1.0)
        before = _entry_eligibility(data.iloc[1], payload, data.iloc[0])
        after = _entry_eligibility(out.iloc[1], payload, out.iloc[0])
        self.assertEqual(before, (True, None))
        self.assertEqual(after, before)
        self.assertEqual(payload.parameters, parameters)
        self.assertEqual(payload.parameters["minimum_signal_confidence"], .35)
        self.assertEqual(execution_contract_metadata(payload), execution)

    def test_explicit_zero_low_nan_and_malformed_values_are_never_replaced_by_fallback(self):
        payload = request()
        for value in (0.0, .2, .35, float("nan"), None, "malformed"):
            with self.subTest(value=value):
                data = frame(2)
                data["signal_confidence"] = pd.Series([value, value], dtype=object)
                out = apply_volume_policy(data, {"volume_lane": "none"}, "ema_rsi_v1")
                pd.testing.assert_series_equal(out["signal_confidence"], data["signal_confidence"])
                self.assertIn("pre_volume_signal_confidence", out)
                if value == "malformed":
                    for row in (data.iloc[0], out.iloc[0]):
                        with self.assertRaises(ValueError):
                            _entry_eligibility(out.iloc[1], payload, row)
                elif value is None or (isinstance(value, float) and math.isnan(value)):
                    self.assertTrue(pd.isna(out.iloc[0]["signal_confidence"]))
                    self.assertEqual(out.iloc[0]["pre_volume_signal_confidence"], 0.0)
                    if value is None:
                        self.assertEqual(_entry_eligibility(out.iloc[1], payload, out.iloc[0]),
                            (False, "minimum_confidence"))
                    else:
                        self.assertTrue(math.isnan(_effective_lane_signal(out.iloc[0], None)[1]))
                else:
                    expected = (False, "minimum_confidence") if value < .35 else (True, None)
                    self.assertEqual(_entry_eligibility(out.iloc[1], payload, out.iloc[0]), expected)
                self.assertEqual(payload.parameters["minimum_signal_confidence"], .35)

    def test_active_volume_lanes_keep_the_existing_missing_confidence_zero_behavior(self):
        for lane in VOLUME_LANES - {"none"}:
            with self.subTest(lane=lane):
                data = frame(2)
                out = apply_volume_policy(data, {"volume_lane": lane}, "ema_rsi_v1")
                self.assertIn("signal_confidence", out)
                self.assertTrue(out.signal_confidence.eq(0.0).all())
                self.assertTrue(out.pre_volume_signal_confidence.eq(0.0).all())
                self.assertTrue(out.volume_risk_multiplier.eq(1.0).all())
                self.assertFalse(out.volume_feature_available.any())

    def test_synthetic_ema_replay_matches_native_no_volume_control_with_threshold_and_costs_unchanged(self):
        # A software-only no-op wrapper is the unchanged native column contract,
        # not a replay or repaired version of any archived original experiment.
        data = frame().drop(columns=["signal", "_management_atr"])
        payload = request()
        parameters = copy.deepcopy(payload.parameters)
        execution = execution_contract_metadata(payload)
        wrapped = run_simple_ema_rsi_backtest_on_dataframe(payload, data,
            include_differential_pair=False, lightweight=True).model_dump(mode="json")
        with patch("app.services.backtester.apply_volume_policy", side_effect=lambda source, *_: source.copy()):
            native = run_simple_ema_rsi_backtest_on_dataframe(payload, data,
                include_differential_pair=False, lightweight=True).model_dump(mode="json")
        self.assertGreater(wrapped["total_trades"], 0)
        self.assertEqual(wrapped["trade_ledger"], native["trade_ledger"])
        self.assertEqual(wrapped["trade_ledger_hash"], native["trade_ledger_hash"])
        self.assertEqual(wrapped["final_balance"], native["final_balance"])
        self.assertEqual(wrapped["execution_assumptions"], native["execution_assumptions"])
        self.assertEqual(wrapped["data_quality"]["replay_executed_clock"],
            native["data_quality"]["replay_executed_clock"])
        self.assertEqual(payload.parameters, parameters)
        self.assertEqual(payload.parameters["minimum_signal_confidence"], .35)
        self.assertEqual(execution_contract_metadata(payload), execution)


if __name__ == "__main__":
    unittest.main()
