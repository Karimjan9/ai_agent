"""Synthetic projection fixtures only; no market backtest or skill evidence."""
import copy
import csv
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import execution_contract_metadata
from app.services.replay_executed_clock import ReplayExecutedClock

_SPEC = importlib.util.spec_from_file_location("curator_scalp_metrics",
    Path(__file__).resolve().parents[1] / "scripts/curator_scalp_metrics.py")
metrics = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(metrics)


class CuratorScalpMetricsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "synthetic.csv"
        self.request = SimpleBacktestRequest(strategy="ema_rsi_v1", base_strategy="ema_rsi", timeframe="M5",
            replay_dataset_hash="synthetic-projection-only", parameters={}).model_dump()
        self.request["execution"]["allowed_sessions_utc"] = ["1-22"]
        # Warmup Sunday; two actual observed execution dates Monday/Tuesday.
        self.times = list(pd.date_range("2025-01-05T14:20:00Z", periods=600, freq="5min"))
        self.trade_specs = []

    def fixture(self, *, open_position_count=0):
        with self.path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=["time", "open", "high", "low", "close"])
            writer.writeheader()
            for stamp in self.times:
                writer.writerow({"time": stamp.isoformat(), "open": 100, "high": 102, "low": 98, "close": 100})
        request_model = SimpleBacktestRequest.model_validate(self.request)
        clock = ReplayExecutedClock(owner="ordinary_single_position_v1", input_rows=len(self.times),
            evaluation_offset=0, timeframe="M5", duration_seconds=300,
            dataset_hash=self.request["replay_dataset_hash"],
            execution_hash=execution_contract_metadata(request_model)["execution_hash"], policy=None, probe=None)
        for index in range(200, len(self.times)):
            clock.observe(index, self.times[index - 1], self.times[index])
        body = {"data_quality": {"replay_executed_clock": clock.finish()},
            "entry_funnel": {"raw_strategy_signals": 12, "flat_signal_opportunities": 9,
                "accepted_entries": len(self.trade_specs) + open_position_count,
                "rejected": {"outside_session": 3, "minimum_confidence": 2}}}
        original = {key: copy.deepcopy(self.request[key]) for key in
            ("symbol", "timeframe", "initial_balance", "risk_per_trade", "parameters", "execution")}
        witness = {"request": {**original, "duration_seconds": 300},
            "scope_rows": [{"index": index, "time": self.times[index].isoformat()}
                for index in range(199, len(self.times) - 1)],
            "entry_indices": [], "entry_observations": {}, "public_trades": []}
        trades, balance, commission, carry = [], self.request["initial_balance"], 0, 0
        peak, drawdown = balance, 0
        for entry_index, exit_index, direction, market_return in self.trade_specs:
            entered, exited = self.times[entry_index], self.times[exit_index]
            size = 2.0
            commission_cash = balance * .001 * size / 100
            carry_cash = balance * .002 * ((exited - entered).total_seconds() / 86400) * size / 100
            cash = balance * market_return * size / 100 - commission_cash - carry_cash
            trades.append({"entry_time": entered.isoformat(), "exit_time": exited.isoformat(),
                "direction": direction, "holding_seconds": (exited - entered).total_seconds(),
                "exact_closed_pnl_cash": cash, "commission_cash": commission_cash, "carry_cash": carry_cash})
            balance += cash
            commission += commission_cash
            carry += carry_cash
            peak = max(peak, balance)
            drawdown = max(drawdown, (peak - balance) / peak * 100)
            witness["entry_indices"].append(entry_index - 1)
            witness["entry_observations"][str(entry_index - 1)] = {
                "signal_time": self.times[entry_index - 1].isoformat(), "execution_time": entered.isoformat()}
            witness["public_trades"].append({"market_regime": "raw_fixture_regime", "session": "raw_not_derived"})
        if open_position_count:
            index = len(self.times) - 2
            witness["entry_indices"].append(index)
            witness["entry_observations"][str(index)] = {"signal_time": self.times[index].isoformat(),
                "execution_time": self.times[index + 1].isoformat()}
        verified = {"per_trade": trades, "mature_trade_count": len(trades),
            "open_position_count": open_position_count,
            "unclosed_exposure": "censored_terminal_marked_value_unknown" if open_position_count else "none",
            "exact_net_cash": balance - self.request["initial_balance"],
            "commission_cash": commission, "carry_cash": carry,
            "explicit_commission_carry_cash": commission + carry, "closed_cash_drawdown_percent": drawdown}
        return body, witness, verified

    def summarize(self, *, open_position_count=0):
        body, witness, verified = self.fixture(open_position_count=open_position_count)
        with patch.object(metrics, "verify_management_witness", return_value=verified) as owner:
            result = metrics.summarize_scalp_metrics(self.request, body, witness, csv_path=self.path)
        owner.assert_called_once_with(body, witness)
        return result

    def registered_naive_fixture(self, *, semantics=None):
        """Synthetic registration; tests explicitly replace only the trust pin.

        No arbitrary manifest hash supplied by a production caller is trusted.
        """
        body, witness, verified = self.fixture()
        with self.path.open("r", encoding="utf-8", newline="") as handle:
            rows = list(csv.DictReader(handle))
        with self.path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=rows[0].keys(), quoting=csv.QUOTE_ALL)
            writer.writeheader()
            for row in rows:
                row["time"] = pd.Timestamp(row["time"]).strftime("%Y-%m-%d %H:%M:%S")
                writer.writerow(row)
        csv_hash = hashlib.sha256(self.path.read_bytes()).hexdigest()
        self.request["replay_dataset_hash"] = csv_hash
        clock = body["data_quality"]["replay_executed_clock"]
        clock["dataset_hash"] = csv_hash
        clock_body = {key: value for key, value in clock.items() if key not in ("receipt_hash", "receipt_json")}
        clock["receipt_hash"] = metrics.canonical_hash(clock_body)
        clock["receipt_json"] = metrics.canonical_json(clock_body)
        manifest = {"protocol": "curator_raw_context_window_preparation_v1",
            "timestamp_semantics": semantics if semantics is not None else metrics.REGISTERED_CSV_TIME_SEMANTICS,
            "windows": {"synthetic_registered": {"path": str(self.path.resolve()), "sha256": csv_hash,
                "loaded_rows": len(rows)}}}
        manifest_path = self.path.parent / "provenance-manifest.json"
        manifest_path.write_text(json.dumps(manifest, sort_keys=True), encoding="utf-8")
        manifest_sha = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
        provenance = {"manifest_path": str(manifest_path), "manifest_sha256": manifest_sha,
            "window_key": "synthetic_registered"}
        return body, witness, verified, provenance

    def test_trust_pin_and_reporting_version_are_not_caller_selectable(self):
        self.assertEqual(metrics.TRUSTED_CSV_MANIFEST_SHA256,
            "b91435027999a1468066611c5ff053020c52ba6373bd0bb69cef3a193efc8c92")
        self.assertEqual(metrics.PROTOCOL, "offline_curator_scalp_metrics_v2")
        body, witness, verified, provenance = self.registered_naive_fixture()
        with patch.object(metrics, "verify_management_witness", return_value=verified):
            with self.assertRaisesRegex(ValueError, "TRUSTED_CSV_MANIFEST"):
                metrics.summarize_scalp_metrics(self.request, body, witness,
                    csv_path=self.path, csv_provenance=provenance)

    def test_exact_explicit_registered_naive_csv_matches_original_utc_clock(self):
        body, witness, verified, provenance = self.registered_naive_fixture()
        with patch.object(metrics, "TRUSTED_CSV_MANIFEST_SHA256", provenance["manifest_sha256"]), \
                patch.object(metrics, "verify_management_witness", return_value=verified):
            result = metrics.summarize_scalp_metrics(self.request, body, witness,
                csv_path=self.path, csv_provenance=provenance)
        self.assertEqual(result["clock_receipt_hash"], body["data_quality"]["replay_executed_clock"]["receipt_hash"])
        self.assertEqual(result["csv_timestamp_registration"]["window_key"], "synthetic_registered")
        self.assertEqual(result["eligible_observed_utc_days"], 2)

    def test_only_exact_registered_adjacent_manifest_is_automatically_resolved(self):
        body, witness, verified, provenance = self.registered_naive_fixture()
        with patch.object(metrics, "TRUSTED_CSV_MANIFEST_SHA256", provenance["manifest_sha256"]), \
                patch.object(metrics, "verify_management_witness", return_value=verified):
            explicit = metrics.summarize_scalp_metrics(self.request, body, witness,
                csv_path=self.path, csv_provenance=provenance)
            automatic = metrics.summarize_scalp_metrics(self.request, body, witness, csv_path=self.path)
        self.assertEqual(automatic, explicit)

    def test_missing_changed_or_sideways_registration_refuses_naive_csv(self):
        for changed in ("missing", "changed_manifest", "sideways_csv", "changed_csv", "wrong_window", "request_hash"):
            body, witness, verified, provenance = self.registered_naive_fixture()
            path = self.path
            declared = None
            if changed == "missing":
                Path(provenance["manifest_path"]).unlink()
            elif changed == "changed_manifest":
                with Path(provenance["manifest_path"]).open("a", encoding="utf-8") as handle:
                    handle.write(" ")
            elif changed == "sideways_csv":
                path = self.path.with_name("unregistered-copy.csv")
                path.write_bytes(self.path.read_bytes())
            elif changed == "changed_csv":
                self.path.write_bytes(self.path.read_bytes().replace(b'"100"', b'"101"', 1))
            elif changed == "wrong_window":
                declared = {**provenance, "window_key": "another_window"}
            else:
                self.request["replay_dataset_hash"] = "f" * 64
            with self.subTest(changed=changed), \
                    patch.object(metrics, "TRUSTED_CSV_MANIFEST_SHA256", provenance["manifest_sha256"]), \
                    patch.object(metrics, "verify_management_witness", return_value=verified):
                with self.assertRaisesRegex(ValueError, "SCALP_"):
                    metrics.summarize_scalp_metrics(self.request, body, witness,
                        csv_path=path, csv_provenance=declared)

    def test_local_time_or_ambiguous_semantics_is_not_utc_authorization(self):
        body, witness, verified, provenance = self.registered_naive_fixture(semantics="provider_local_time_unknown_zone")
        with patch.object(metrics, "TRUSTED_CSV_MANIFEST_SHA256", provenance["manifest_sha256"]), \
                patch.object(metrics, "verify_management_witness", return_value=verified):
            with self.assertRaisesRegex(ValueError, "REGISTERED_CSV_UTC_SEMANTICS"):
                metrics.summarize_scalp_metrics(self.request, body, witness,
                    csv_path=self.path, csv_provenance=provenance)

    def test_registered_csv_never_authorizes_naive_witness_or_returned_times(self):
        self.trade_specs = [(200, 203, "BUY", .1)]
        for changed in ("witness_scope", "returned_trade"):
            body, witness, verified, provenance = self.registered_naive_fixture()
            if changed == "witness_scope":
                witness["scope_rows"][0]["time"] = self.times[199].tz_localize(None).isoformat()
            else:
                verified["per_trade"][0]["entry_time"] = self.times[200].tz_localize(None).isoformat()
            with self.subTest(changed=changed), \
                    patch.object(metrics, "TRUSTED_CSV_MANIFEST_SHA256", provenance["manifest_sha256"]), \
                    patch.object(metrics, "verify_management_witness", return_value=verified):
                with self.assertRaisesRegex(ValueError, "SCALP_UTC_TIMESTAMP_REQUIRED"):
                    metrics.summarize_scalp_metrics(self.request, body, witness,
                        csv_path=self.path, csv_provenance=provenance)

    def test_zero_trade_dates_remain_in_denominator_unknown_holdings_not_zero(self):
        result = self.summarize()
        self.assertEqual(result["eligible_observed_utc_days"], 2)
        self.assertEqual(result["zero_close_eligible_days"], 2)
        self.assertEqual(result["mature_closes_per_eligible_observed_day"], 0)
        self.assertIsNone(result["holding_minutes"]["median"])
        self.assertIsNone(result["holding_minutes"]["within_cap_fraction"])
        self.assertIsNone(result["wait_count"])
        self.assertIsNone(result["marked_drawdown_percent"])
        self.assertEqual(result["funnel_opportunities"]["flat_signal_opportunities"], 9)

    def test_buy_sell_daily_net_and_costs_reconcile_without_double_spread(self):
        self.trade_specs = [(200, 203, "BUY", .25), (490, 494, "SELL", -.2)]
        result = self.summarize()
        self.assertEqual(result["mature_full_closes"], 2)
        self.assertEqual(result["zero_close_eligible_days"], 0)
        self.assertEqual([row["direction"] for row in result["per_trade"]], ["BUY", "SELL"])
        self.assertAlmostEqual(sum(row["realized_net_cash"] for row in result["per_day"]), result["exact_realized_net_cash"])
        costs = sum(row["commission_cash"] + row["carry_cash"] for row in result["per_day"])
        self.assertAlmostEqual(costs, result["explicit_commission_carry_cash"])
        self.assertGreater(result["closed_cash_drawdown_percent"], 0)
        self.assertIsNone(result["terminal_marked_value"])
        self.assertIn("already_embedded", result["spread_slippage"])

    def test_original_verifier_technical_or_censor_refusal_is_not_zero_result(self):
        body, witness, _ = self.fixture()
        for reason in ("TECHNICAL_ERROR", "NATURAL_MATURE_FULL_CLOSE_REASON_REQUIRED"):
            with self.subTest(reason=reason), patch.object(metrics, "verify_management_witness", side_effect=ValueError(reason)):
                with self.assertRaisesRegex(ValueError, reason):
                    metrics.summarize_scalp_metrics(self.request, body, witness, csv_path=self.path)

    def test_eof_open_is_not_mature_zero_or_marked_cash(self):
        result = self.summarize(open_position_count=1)
        self.assertEqual(result["mature_full_closes"], 0)
        self.assertEqual(result["open_position_count"], 1)
        self.assertEqual(sum(row["entries"] for row in result["per_day"]), 1)
        self.assertEqual(result["unclosed_exposure"], "censored_terminal_marked_value_unknown")
        self.assertIsNone(result["terminal_marked_value"])

    def test_archive_gap_breach_uses_wall_time_not_just_bar_count(self):
        for index in range(203, len(self.times)):
            self.times[index] += pd.Timedelta(hours=1)
        self.trade_specs = [(200, 206, "BUY", .1)]
        result = self.summarize()
        trade = result["per_trade"][0]
        self.assertEqual(trade["holding_minutes"], 90)
        self.assertEqual(trade["observed_bar_open_steps"], 6)
        self.assertEqual(trade["archive_gap_excess_minutes_during_holding"], 60)
        self.assertTrue(trade["over_cap_explained_by_observed_gap"])
        self.assertEqual(result["holding_minutes"]["within_cap_fraction"], 0)

    def test_ordinary_over_cap_not_reclassified_as_gap_and_same_bar_unknown(self):
        self.trade_specs = [(200, 207, "BUY", .1), (208, 208, "SELL", -.1)]
        result = self.summarize()
        self.assertEqual(result["holding_minutes"]["median"], 17.5)
        self.assertEqual(result["holding_minutes"]["p90"], 31.5)
        self.assertEqual(result["holding_minutes"]["over_cap_count"], 1)
        self.assertEqual(result["holding_minutes"]["over_cap_gap_explained_count"], 0)
        self.assertEqual(result["holding_minutes"]["same_bar_intrabar_duration_unknown_count"], 1)

    def test_context_is_prior_closed_point_not_signal_open_or_exit_and_preserves_raw(self):
        self.trade_specs = [(200, 203, "BUY", .1)]
        result = self.summarize()
        trade = result["per_trade"][0]
        context = trade["entry_context"]
        self.assertEqual(context["signal_candle_open"], "2025-01-06T06:55:00+00:00")
        self.assertEqual(context["coordinate_timestamp"], "2025-01-06T07:00:00+00:00")
        self.assertEqual(context["membership_duration_minutes"], 0)
        self.assertEqual(trade["raw_public_trade_context"]["session"], "raw_not_derived")
        self.assertNotEqual(context["session"], "raw_not_derived")
        self.assertFalse(context["independent_session_qualification"])

    def test_exact_clock_scope_and_physical_request_tampering_refused(self):
        for changed in ("clock", "scope", "request"):
            body, witness, verified = self.fixture()
            if changed == "clock":
                body["data_quality"]["replay_executed_clock"]["decision_rows"] -= 1
            elif changed == "scope":
                witness["scope_rows"][0]["time"] = self.times[198].isoformat()
            else:
                witness["request"]["risk_per_trade"] *= .5
            with self.subTest(changed=changed), patch.object(metrics, "verify_management_witness", return_value=verified):
                with self.assertRaisesRegex(ValueError, "SCALP_"):
                    metrics.summarize_scalp_metrics(self.request, body, witness, csv_path=self.path)

    def test_daily_original_cash_mismatch_refused(self):
        body, witness, verified = self.fixture()
        verified["exact_net_cash"] = 1
        with patch.object(metrics, "verify_management_witness", return_value=verified):
            with self.assertRaisesRegex(ValueError, "DAILY_CASH_RECONCILIATION"):
                metrics.summarize_scalp_metrics(self.request, body, witness, csv_path=self.path)

    def test_pair_requires_exact_account_clock_and_cost_not_matched_trade_claim(self):
        control = self.summarize()
        candidate = copy.deepcopy(control)
        candidate["exact_realized_net_cash"] += 2
        compared = metrics.compare_scalp_metrics(control, candidate)
        self.assertEqual(compared["exact_realized_net_cash_delta"], 2)
        self.assertFalse(compared["same_entry_isolated_causal_contrast"])
        for field in ("csv_sha256", "account_identity", "clock_identity"):
            bad = copy.deepcopy(candidate)
            bad[field] = "tampered"
            with self.subTest(field=field), self.assertRaisesRegex(ValueError, "COMPARABLE_ACCOUNT_CLOCK"):
                metrics.compare_scalp_metrics(control, bad)

    def test_original_synthetic_owner_witness_and_clock_are_consumed_without_mock(self):
        # One existing deterministic software fixture exercises the real
        # original witness boundary, not archive research or economic proof.
        from app.services import backtester
        from test_source_grounded_method_runtime import software_frame, software_request
        request, frame = software_request(), software_frame()
        frame.to_csv(self.path, index=False)
        with metrics._OWNER.capture_management_witness() as observer:
            body = backtester.run_simple_ema_rsi_backtest_on_dataframe(request, frame,
                include_differential_pair=False, lightweight=True).model_dump()
        witness = observer.snapshot()
        result = metrics.summarize_scalp_metrics(request, body, witness, csv_path=self.path)
        self.assertGreater(result["mature_full_closes"], 0)
        self.assertAlmostEqual(result["exact_realized_net_cash"],
            sum(row["exact_closed_pnl_cash"] for row in result["per_trade"]))
        self.assertEqual(result["clock_receipt_hash"], body["data_quality"]["replay_executed_clock"]["receipt_hash"])
        self.assertFalse(result["economic_authority"])


if __name__ == "__main__":
    unittest.main()
