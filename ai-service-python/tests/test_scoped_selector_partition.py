"""Conditional software partition checks; these do not authorize future data."""
import unittest
import pandas as pd

from app.services.walk_forward import WalkForwardService, _original_maturity_totals
from app.services.scoped_original_replay import original_scoped_trade_context
from app.services.backtester import _trade_ledger_hash


class ScopedSelectorPartitionTest(unittest.TestCase):
    def test_actual_empty_outcome_context_does_not_invent_instrument_assignment(self):
        original = {"total_trades": 0, "trade_ledger": [], "trade_ledger_hash": _trade_ledger_hash([])}
        trace = original_scoped_trade_context(original)
        self.assertEqual(trace["trade_ledger_count"], 0)
        self.assertEqual(trace["exact_context_slices"], [])
        self.assertFalse(trace["instrument_assignment_or_activation_claimed"])
        self.assertNotIn("selected", trace)
        original["trade_ledger_hash"] = "a" * 64
        with self.assertRaisesRegex(ValueError, "LEDGER_DRIFT"):
            original_scoped_trade_context(original)

    def frame(self, rows=9000):
        return pd.DataFrame({"time": pd.date_range("2027-01-01", periods=rows, freq="5min", tz="UTC"),
            "open": 100.0, "high": 101.0, "low": 99.0, "close": 100.0, "volume": 1.0})

    def test_one_authorized_month_has_disjoint_bounded_strata_without_a_fictitious_two_year_holdout(self):
        frame = self.frame()
        service = WalkForwardService()
        strata = [service._causal_forward_folds(frame, frame.iloc[0:0], fold_count=1, fold_offset=index,
            fold_universe_count=6, max_rows_per_fold=1024, purge_bars=48, embargo_bars=1,
            research_end_exclusive=pd.Timestamp("2027-02-01", tz="UTC"))[0]["forward"] for index in range(6)]
        self.assertTrue(all(201 <= len(part) <= 1024 for part in strata))
        self.assertTrue(all(part.time.max() < pd.Timestamp("2027-02-01", tz="UTC") for part in strata))
        self.assertTrue(all(strata[index - 1].time.max() < strata[index].time.min() for index in range(1, 6)))
        self.assertEqual(sum(len(part) for part in strata), 6144)
        self.assertEqual(frame.time.min(), pd.Timestamp("2027-01-01", tz="UTC"))

    def test_a_short_window_is_typed_underpowered_not_replaced_by_a_different_archive(self):
        frame = self.frame(900)
        with self.assertRaisesRegex(ValueError, "required"):
            WalkForwardService()._causal_forward_folds(frame, frame.iloc[0:0], fold_count=1,
                fold_universe_count=6, max_rows_per_fold=1024, purge_bars=48, embargo_bars=1,
                research_end_exclusive=pd.Timestamp("2027-02-01", tz="UTC"))

    def receipt(self, closed=2, opened=0):
        return {"total_trades": closed, "statistical_evidence": {"original_position_maturity": {
            "protocol": "original_position_maturity_v1", "closed_trade_count": closed,
            "open_position_count": opened, "censored_trade_count": 0, "unknown_maturity_count": 0,
            "forced_terminal_close_applied": False}}}

    def test_aggregate_retains_an_open_position_from_any_original_fold(self):
        observed = _original_maturity_totals([self.receipt(2, 1), self.receipt(3, 0)])
        self.assertEqual(observed["closed_trade_count"], 5)
        self.assertEqual(observed["open_position_count"], 1)
        self.assertEqual(observed["original_product_count"], 2)
        self.assertFalse(observed["forced_terminal_close_applied"])

    def test_missing_or_inconsistent_original_state_cannot_be_filled_with_zeroes(self):
        self.assertIsNone(_original_maturity_totals([self.receipt(), {"total_trades": 2}]))
        corrupt = self.receipt(); corrupt["total_trades"] = 3
        with self.assertRaisesRegex(ValueError, "AGGREGATE_MISMATCH"):
            _original_maturity_totals([corrupt])


if __name__ == "__main__":
    unittest.main()
