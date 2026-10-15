"""Per-replay calendar memo parity; neither changed market rules nor market proof."""

import unittest
from unittest.mock import patch

import pandas as pd

from app.services import backtester
from app.services.research_program_tasks import canonical_hash
from test_instrument_method_learning import learning_contract
from test_source_grounded_method_runtime import owned_recipe, software_frame, software_request


def coordinates(value):
    return (backtester._edge_market_session(value), backtester._edge_market_venue_phase(value),
        backtester._edge_market_session_instance(value), backtester._edge_market_session_offset(value))


class ReplayMarketCoordinateCacheTest(unittest.TestCase):
    def test_actual_calendar_dst_and_session_boundary_outputs_match_without_cache(self):
        # Real canonical-calendar outputs across London/US DST mismatch, fixes,
        # core overlap, maintenance, Asia and weekend boundaries.
        stamps = ["2025-10-24T06:59:59Z", "2025-10-24T07:00:00Z", "2025-10-24T09:30:00Z",
            "2025-10-24T12:19:59Z", "2025-10-24T12:20:00Z", "2025-10-24T14:02:00Z",
            "2025-10-27T07:59:59Z", "2025-10-27T08:00:00Z", "2025-10-27T10:30:00Z",
            "2025-10-27T12:20:00Z", "2025-10-27T15:02:00Z", "2025-10-27T21:00:00Z",
            "2025-11-03T13:19:59Z", "2025-11-03T13:20:00Z", "2025-11-03T22:00:00Z",
            "2025-12-04T02:00:00Z", "2025-12-06T12:00:00Z"]
        self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())
        original = backtester.session_membership
        for stamp in stamps:
            expected = coordinates(stamp)
            expected_row = original(pd.Series([stamp])).iloc[0]
            with backtester._replay_market_coordinate_scope():
                self.assertEqual(coordinates(stamp), expected)
                pd.testing.assert_series_equal(backtester._edge_market_coordinates(stamp), expected_row)
        self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())

    def test_four_coordinate_getters_share_one_original_calendar_call_only_inside_replay_scope(self):
        stamp = pd.Timestamp("2025-10-27T13:00:00Z")
        original = backtester.session_membership
        with patch.object(backtester, "session_membership", wraps=original) as owner:
            expected = coordinates(stamp)
            self.assertEqual(owner.call_count, 4)
            owner.reset_mock()
            with backtester._replay_market_coordinate_scope() as cache:
                self.assertEqual(coordinates(stamp), expected)
                self.assertEqual(owner.call_count, 1)
                self.assertEqual(len(cache), 1)
            self.assertEqual(len(cache), 0)
            self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())
            coordinates(stamp)
            self.assertEqual(owner.call_count, 5)

    def test_cache_key_is_normalized_utc_nanoseconds_not_input_spelling(self):
        original = backtester.session_membership
        values = ["2025-10-27T13:00:00Z", "2025-10-27T08:00:00-05:00",
            pd.Timestamp("2025-10-27T13:00:00Z"), pd.Timestamp("2025-10-27T13:00:00")]
        with patch.object(backtester, "session_membership", wraps=original) as owner:
            with backtester._replay_market_coordinate_scope() as cache:
                expected = coordinates(values[0])
                for value in values[1:]:
                    self.assertEqual(coordinates(value), expected)
                self.assertEqual(owner.call_count, 1)
                self.assertEqual(list(cache), [pd.Timestamp(values[0]).value])

    def test_bounded_lru_evicts_at_2048_without_persistent_cross_scope_reuse(self):
        base = pd.Timestamp("2025-01-06T00:00:00Z")

        def original(times):
            return pd.DataFrame([{"session": str(times.iloc[0])}])

        with patch.object(backtester, "session_membership", side_effect=original) as owner:
            with backtester._replay_market_coordinate_scope() as cache:
                for offset in range(2048):
                    backtester._edge_market_coordinates(base + pd.Timedelta(minutes=5 * offset))
                self.assertEqual(len(cache), 2048)
                backtester._edge_market_coordinates(base)
                self.assertEqual(owner.call_count, 2048)
                backtester._edge_market_coordinates(base + pd.Timedelta(minutes=5 * 2048))
                self.assertEqual(len(cache), 2048)
                self.assertIn(base.value, cache)
                self.assertNotIn((base + pd.Timedelta(minutes=5)).value, cache)
                backtester._edge_market_coordinates(base + pd.Timedelta(minutes=5))
                self.assertEqual(owner.call_count, 2050)
                self.assertEqual(len(cache), 2048)
            self.assertEqual(cache, {})
            with backtester._replay_market_coordinate_scope():
                backtester._edge_market_coordinates(base)
                self.assertEqual(owner.call_count, 2051)

    def test_nested_invocations_have_independent_cache_and_restore_the_outer_cache(self):
        stamp = "2025-10-27T13:00:00Z"
        original = backtester.session_membership

        @backtester._with_replay_market_coordinates
        def child():
            child_cache = backtester._EDGE_MARKET_COORDINATES.get()
            coordinates(stamp)
            self.assertEqual(len(child_cache), 1)
            return child_cache

        with patch.object(backtester, "session_membership", wraps=original) as owner:
            with backtester._replay_market_coordinate_scope() as parent_cache:
                coordinates(stamp)
                child_cache = child()
                self.assertIsNot(child_cache, parent_cache)
                self.assertEqual(child_cache, {})
                self.assertIs(backtester._EDGE_MARKET_COORDINATES.get(), parent_cache)
                coordinates(stamp)
                self.assertEqual(owner.call_count, 2)
            child()
            self.assertEqual(owner.call_count, 3)
        self.assertEqual(child.__name__, "child")
        self.assertTrue(callable(child.__wrapped__))
        self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())

    def test_exception_during_actual_replay_cleans_scope_and_does_not_cache_invalid_inputs(self):
        captured = []

        def fail(_payload):
            cache = backtester._EDGE_MARKET_COORDINATES.get()
            self.assertIsNotNone(cache)
            coordinates("2025-10-27T13:00:00Z")
            self.assertEqual(len(cache), 1)
            captured.append(cache)
            raise RuntimeError("synthetic replay stop")

        with patch.object(backtester, "_scoped_maturity_entry_end", side_effect=fail):
            with self.assertRaisesRegex(RuntimeError, "synthetic replay stop"):
                backtester._run_prepared_simple_backtest(software_request(), software_frame())
        self.assertEqual(captured, [{}])
        self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())

    def test_invalid_nat_and_owner_errors_keep_original_behavior_and_are_not_memoized(self):
        original = backtester.session_membership
        for value in (None, pd.NaT, "not-a-timestamp", float("nan"), True, {}, []):
            try:
                expected = coordinates(value)
                error = None
            except Exception as failure:
                expected, error = None, (type(failure), str(failure))
            with patch.object(backtester, "session_membership", wraps=original) as owner:
                with backtester._replay_market_coordinate_scope() as cache:
                    for _ in range(2):
                        if error is None:
                            self.assertEqual(coordinates(value), expected)
                        else:
                            with self.assertRaises(error[0]) as failure:
                                coordinates(value)
                            self.assertEqual(str(failure.exception), error[1])
                    self.assertEqual(len(cache), 0)
                    self.assertEqual(owner.call_count, 8 if error is None else 2)
        self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())

    def test_actual_full_replay_cached_and_original_unwrapped_have_exact_response_trace_clock_and_ledger_parity(self):
        data = software_frame()
        request = software_request()
        recipe = owned_recipe(request, "calendar-parity-half-risk", "risk_multiplier",
            {"op": "CONST", "type": "number", "value": .5})
        contract = learning_contract(request, data, [recipe])
        request.policy_context["instrument_method_learning"] = contract
        snapshot = backtester.prepare_signal_snapshot(request, data)
        original = backtester.session_membership
        with patch.object(backtester, "session_membership", wraps=original) as calendar:
            cached = backtester._run_prepared_simple_backtest(request.model_copy(deep=True), data.copy(deep=True),
                prepared_snapshot=snapshot, include_differential_pair=False, lightweight=False)
            cached_calls = calendar.call_count
            self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())
            calendar.reset_mock()
            uncached = backtester._run_prepared_simple_backtest.__wrapped__(request.model_copy(deep=True), data.copy(deep=True),
                prepared_snapshot=snapshot, include_differential_pair=False, lightweight=False)
            uncached_calls = calendar.call_count
        left, right = cached.model_dump(mode="json"), uncached.model_dump(mode="json")
        self.assertGreater(cached.total_trades, 0)
        self.assertGreater(len(cached.decision_trace), 0)
        self.assertGreater(cached.data_quality["instrument_method_learning_receipt"]["used_count"], 0)
        self.assertGreater(len(cached.trade_ledger), 0)
        self.assertLess(cached_calls, uncached_calls)
        self.assertEqual(left, right)
        self.assertEqual(canonical_hash(left), canonical_hash(right))
        self.assertIsNone(backtester._EDGE_MARKET_COORDINATES.get())


if __name__ == "__main__":
    unittest.main()
