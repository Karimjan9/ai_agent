import unittest

from app.main import _strategy_contract_hash, advance_paper_contract
from app.schemas import SimpleBacktestRequest
from app.services.execution_contract import (
    execution_contract_metadata,
    management_contract_metadata,
    verify_management_contract,
)


class PaperManagementContractTest(unittest.TestCase):
    def payload(self) -> SimpleBacktestRequest:
        return SimpleBacktestRequest(
            symbol="XAUUSD",
            timeframe="H1",
            strategy="ema_rsi_v1",
            parameters={
                "partial_take_profit_fraction": 0.5,
                "partial_target_atr_multiplier": 1.0,
                "trailing_atr_multiplier": 0.0,
                "time_stop_candles": 0,
            },
            candles=[
                {"time": "2026-01-01T00:00:00Z", "open": 100, "high": 100.5, "low": 99.5, "close": 100},
                {"time": "2026-01-01T01:00:00Z", "open": 100, "high": 101.2, "low": 100, "close": 101},
                {"time": "2026-01-01T02:00:00Z", "open": 101, "high": 110.5, "low": 100.5, "close": 110},
            ],
            execution={
                "spread_points": 0,
                "slippage_points": 0,
                "commission_percent": 0,
                "swap_per_day_percent": 0,
                "stop_loss_percent": 1,
                "take_profit_percent": 10,
            },
        )

    def contract(self, payload: SimpleBacktestRequest) -> dict[str, object]:
        execution = execution_contract_metadata(payload)
        return {
            "decision": "BUY",
            "market_entry_price": 100.0,
            "entry_price": 100.0,
            "stop_loss": 99.0,
            "take_profit": 110.0,
            "position_size_multiple": 1.0,
            "contract_version": "reality_parity_execution_v1",
            "execution_hash": execution["execution_hash"],
            "strategy_hash": _strategy_contract_hash(payload),
            "management_contract": management_contract_metadata(payload),
        }

    def test_management_contract_detects_post_entry_parameter_drift(self) -> None:
        payload = self.payload()
        contract = self.contract(payload)

        _, attested = verify_management_contract(payload, contract)

        self.assertTrue(attested)
        changed = payload.model_copy(
            update={"parameters": {**payload.parameters, "partial_take_profit_fraction": 0.75}}
        )
        with self.assertRaisesRegex(ValueError, "drifted after entry"):
            verify_management_contract(changed, contract)

    def test_paper_advance_executes_partial_then_final_target_and_emits_r_audit(self) -> None:
        payload = self.payload()
        result = advance_paper_contract({
            "request": payload.model_dump(mode="json"),
            "contract": self.contract(payload),
            "entry_time": "2026-01-01T00:00:00Z",
        })

        self.assertTrue(result["closed"])
        self.assertEqual("partial_target+intrabar_target", result["exit_reason"])
        self.assertAlmostEqual(5.55, result["profit_percent"], places=5)
        audit = result["management_audit"]
        self.assertTrue(audit["management_attested"])
        self.assertTrue(audit["execution_attested"])
        self.assertTrue(audit["strategy_attested"])
        self.assertTrue(audit["contract_followed"])
        self.assertTrue(audit["partial_closed"])
        self.assertFalse(audit["stop_widened"])
        self.assertAlmostEqual(5.55, audit["realized_r_multiple"], places=5)
        self.assertAlmostEqual(10.5, audit["mfe_r"], places=5)
        self.assertAlmostEqual(0.5, audit["mae_r"], places=5)
        accounting = result["paper_accounting"]
        self.assertEqual(accounting["protocol"], "specialist_paper_accounting_v1")
        self.assertEqual(accounting["entry_price"], 100.0)
        self.assertEqual(accounting["exit_price"], 110.0)
        self.assertEqual(accounting["partial"]["fraction"], 0.5)
        self.assertEqual(accounting["partial"]["exit_time"], "2026-01-01T01:00:00+00:00")
        self.assertEqual(accounting["exit_time"], "2026-01-01T02:00:00+00:00")
        self.assertEqual(accounting["costs_embedded_in_prices"], {"spread": True, "slippage": True})

    def test_open_paper_partial_has_exact_accounting_without_future_exit(self) -> None:
        payload = self.payload()
        payload.candles = payload.candles[:2]
        result = advance_paper_contract({"request": payload.model_dump(mode="json"),
            "contract": self.contract(payload), "entry_time": "2026-01-01T00:00:00Z"})
        self.assertFalse(result["closed"])
        self.assertIsNone(result["paper_accounting"]["exit_price"])
        self.assertIsNone(result["paper_accounting"]["exit_time"])
        self.assertEqual(result["paper_accounting"]["partial"]["fraction"], 0.5)


if __name__ == "__main__":
    unittest.main()
