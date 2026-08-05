import unittest

from app.schemas import SimpleTrade
from app.services.backtester import _certified_coverage_passport


class CoveragePassportTest(unittest.TestCase):
    def _trade(self, hour: int, profit: float) -> SimpleTrade:
        return SimpleTrade(
            direction="BUY",
            entry_time=f"2026-01-01 {hour:02d}:00:00",
            exit_time=f"2026-01-01 {hour:02d}:30:00",
            entry_price=100.0,
            exit_price=100.0 + profit,
            result="WIN" if profit > 0 else "LOSS",
            profit_percent=profit,
            balance=10000.0,
            market_regime="trend_up",
            volatility_regime="normal_volatility",
        )

    def test_sparse_fine_cells_backoff_to_an_evidence_backed_regime(self):
        passport = _certified_coverage_passport(
            [self._trade(1, 1.0), self._trade(2, -0.2), self._trade(3, 1.0)],
            [],
        )

        self.assertEqual(passport["protocol"], "certified_coverage_passport_v2")
        self.assertEqual(passport["certified_cells"], 3)
        self.assertEqual(passport["uncertified_cells"], 0)
        self.assertTrue(all(row["backoff_used"] for row in passport["cells"].values()))
        self.assertTrue(all(row["effective_scope"] != "regime|volatility|session|direction" for row in passport["cells"].values()))
        self.assertEqual({row["effective_scope"] for row in passport["cells"].values()}, {"regime|volatility|direction"})
        self.assertEqual(len(passport["effective_cells"]), 1)

    def test_under_sampled_envelopes_do_not_pass(self):
        passport = _certified_coverage_passport(
            [self._trade(1, 1.0), self._trade(2, -0.2)],
            [],
        )

        self.assertEqual(passport["certified_cells"], 0)
        self.assertEqual(passport["uncertified_cells"], 2)


if __name__ == "__main__":
    unittest.main()
