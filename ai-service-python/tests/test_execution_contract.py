import unittest

from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import execution_contract_metadata


class ExecutionContractCompatibilityTest(unittest.TestCase):
    def test_fx_point_size_hash_matches_laravel_canonical_json(self):
        payload = SimpleBacktestRequest(
            symbol="EURUSD",
            timeframe="H1",
            execution={
                "spread_points": 12,
                "point_size": 0.00001,
                "commission_percent": 0.01,
                "slippage_points": 2,
                "swap_per_day_percent": 0.002,
                "allowed_sessions_utc": ["1-22"],
                "intrabar_policy": "conservative",
                "max_gap_multiple": 96,
                "reject_unexpected_gaps": True,
                "stop_loss_percent": 0.5,
                "take_profit_percent": 1.0,
                "max_leverage": 5,
            },
            execution_contract={"protocol": "canonical_market_execution_v1"},
        )

        self.assertEqual(
            execution_contract_metadata(payload)["execution_hash"],
            "b1f45ac65c1ceb9e19f35dc924c158479674e3c2b6ef1e25f5d82c209b213950",
        )


if __name__ == "__main__":
    unittest.main()
