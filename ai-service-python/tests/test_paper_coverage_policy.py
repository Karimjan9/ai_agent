import unittest

import pandas as pd

from app.main import _coverage_permission


class PaperCoveragePolicyTest(unittest.TestCase):
    def _row(self, hour: int = 1) -> pd.Series:
        return pd.Series({
            "time": f"2026-01-01 {hour:02d}:00:00",
            "market_regime": "trend_up",
            "volatility_regime": "normal_volatility",
        })

    def _policy(self, permission: str) -> dict[str, object]:
        return {
            "coverage_passport": {
                "protocol": "certified_coverage_passport_v2",
                "status": "assessed",
                "scope_order": ["regime"],
                "effective_cells": {
                    "regime|trend_up": {"permissions": [permission], "effective_permission": permission},
                },
            },
        }

    def test_certified_trade_scope_allows_signal(self):
        result = _coverage_permission(self._policy("TRADE"), self._row(), "BUY")

        self.assertEqual(result["decision"], "ALLOW")
        self.assertEqual(result["reason"], "COVERAGE_TRADE_PERMISSION")

    def test_aggregate_scope_and_abstain_permission_are_respected(self):
        result = _coverage_permission(self._policy("TRADE"), self._row(2), "BUY")

        self.assertEqual(result["decision"], "ALLOW")

        unseen = self._row()
        unseen["market_regime"] = "range"
        result = _coverage_permission(self._policy("TRADE"), unseen, "BUY")
        self.assertEqual(result["decision"], "WAIT")
        self.assertEqual(result["reason"], "COVERAGE_ENVELOPE_UNOBSERVED")

        result = _coverage_permission(self._policy("ABSTAIN"), self._row(), "BUY")
        self.assertEqual(result["decision"], "WAIT")
        self.assertEqual(result["reason"], "COVERAGE_ABSTAIN_PERMISSION")


if __name__ == "__main__":
    unittest.main()
