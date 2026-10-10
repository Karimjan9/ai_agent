import unittest
from types import SimpleNamespace
import pandas as pd

from app.schemas import SimpleBacktestRequest
from app.services.backtester import _original_position_maturity, _scoped_maturity_entry_end, _entry_eligibility
from app.services.walk_forward import _scoped_forward_payload


class ScopedPositionMaturityTest(unittest.TestCase):
    def request(self, purpose="independent_scoped_component_research"):
        return SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5", policy_context={
            "scoped_research_certificate": {"purpose": purpose},
            "scoped_position_maturity_fence": {"protocol": "scoped_original_maturity_fence_v1",
                "entry_end_exclusive": "2027-01-31T23:00:00Z", "end_exclusive": "2027-02-01T00:00:00Z",
                "holding_fence_seconds": 3600},
        })

    def test_declared_entry_tail_is_common_and_does_not_close_positions(self):
        payload = self.request()
        self.assertEqual(_scoped_maturity_entry_end(payload), pd.Timestamp("2027-01-31T23:00:00Z"))
        allowed, reason = _entry_eligibility(pd.Series({"time": "2027-01-31T23:00:00Z"}), payload)
        self.assertFalse(allowed)
        self.assertEqual(reason, "scoped_validation_maturity_tail")
        self.assertEqual(payload.parameters, {})

    def test_absent_new_purpose_leaves_the_ordinary_path_unchanged(self):
        self.assertIsNone(_scoped_maturity_entry_end(SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5")))
        with self.assertRaisesRegex(ValueError, "ORIGINAL_PURPOSE_REQUIRED"):
            _scoped_maturity_entry_end(self.request("ordinary_discovery"))

    def test_a_shorter_or_changed_tail_cannot_be_accepted_as_the_original_fence(self):
        payload = self.request()
        payload.policy_context["scoped_position_maturity_fence"]["holding_fence_seconds"] = 600
        with self.assertRaisesRegex(ValueError, "CONTRACT_INVALID"):
            _scoped_maturity_entry_end(payload)

    def test_wire_claim_alone_cannot_narrow_an_original_forward_fence(self):
        payload = self.request("independent_scoped_selector_research")
        before = payload.model_dump()
        frame = pd.DataFrame({"time": pd.date_range("2027-01-10T10:00:00Z", periods=300, freq="5min")})
        with self.assertRaisesRegex(ValueError, "PRIVATE_WITNESS_REQUIRED"):
            _scoped_forward_payload(payload, frame)
        self.assertEqual(payload.model_dump(), before)

    def test_ordinary_forward_payload_is_not_rebound_or_given_a_new_filter(self):
        payload = SimpleBacktestRequest(symbol="XAUUSD", timeframe="M5")
        self.assertIs(_scoped_forward_payload(payload, pd.DataFrame()), payload)

    def trade(self, reason="target", exit_at="2027-01-01T11:00:00Z"):
        return SimpleNamespace(entry_time="2027-01-01T10:00:00Z", exit_time=exit_at, exit_reason=reason)

    def test_closed_mature_originals_do_not_force_a_terminal_close(self):
        receipt = _original_position_maturity([self.trade()], None)
        self.assertEqual(receipt["closed_trade_count"], 1)
        self.assertEqual(receipt["open_position_count"], 0)
        self.assertEqual(receipt["censored_trade_count"], 0)
        self.assertEqual(receipt["unknown_maturity_count"], 0)
        self.assertFalse(receipt["forced_terminal_close_applied"])

    def test_open_position_is_observed_even_with_an_empty_closed_ledger(self):
        position = {"direction": "BUY", "entry_price": 100}
        original = dict(position)
        receipt = _original_position_maturity([], position)
        self.assertEqual(receipt["open_position_count"], 1)
        self.assertEqual(receipt["closed_trade_count"], 0)
        self.assertEqual(position, original)

    def test_censoring_and_unknown_maturity_are_not_silently_discarded(self):
        receipt = _original_position_maturity([self.trade("end_of_data"), self.trade(exit_at=None)], None)
        self.assertEqual(receipt["censored_trade_count"], 1)
        self.assertEqual(receipt["unknown_maturity_count"], 1)


if __name__ == "__main__":
    unittest.main()
