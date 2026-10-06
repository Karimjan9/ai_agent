import copy
import hashlib
import json
import unittest
from unittest.mock import patch

import pandas as pd

from app.schemas import Candle, ExecutionConfig, SimpleBacktestRequest, StrategyRuntimeConfig
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
from app.services.execution_contract import execution_contract_metadata
from app.services.research_program_tasks import BoundedDecisionProgram, canonical_hash
from app.services.specialist_council import run_specialist_council, seal_contract


def member(role, *, holding=20, risk=0.2, direction="BUY"):
    return {"specialist_id": role, "role": role,
        "strategy": "ema_rsi_v1", "base_strategy": "ema_rsi", "version": "v1",
        "strategy_version": "strategy-v1", "tactic_version": "tactic-v1", "management_version": "management-v1",
        "parameters": {"ema_fast": {"scalp": 2, "hour": 3, "day": 4, "swing": 5}[role], "ema_slow": 10},
        "horizon": {"decision_interval_bars": 1, "reevaluation_interval_bars": 1, "max_holding_bars": holding},
        "capital_weight": 0.25, "risk_per_trade_percent": risk, "test_direction": direction}


def fixtures(members=None, *, execution=None, policy=None, upgrades=None):
    frame = pd.DataFrame({"time": pd.date_range("2025-01-06", periods=22, freq="h", tz="UTC"),
        "open": 100.0, "high": 100.05, "low": 99.95, "close": 100.0,
        "volume": 100.0, "volume_available": True})
    request = SimpleBacktestRequest(strategy="portfolio_v1", base_strategy="portfolio",
        timeframe="H1", replay_dataset_hash="dataset-test",
        execution=execution or ExecutionConfig(stop_loss_percent=1, take_profit_percent=2, max_leverage=5))
    limits = {"broker_position_mode": "hedging", "opposite_position_policy": "reject", "max_open_positions": 4,
        "max_reserved_capital_percent": 100.0, "max_gross_exposure_percent": 100.0,
        "max_total_risk_percent": 5.0, "max_drawdown_percent": 20.0,
        "max_daily_loss_percent": 5.0, "max_expected_cost_percent": 5.0, **(policy or {})}
    body = {"protocol": "specialist_council_runtime_v1", "council_id": "test-council", "council_version": "council-v1",
        "execution_timeframe": "H1", "replay_dataset_hash": request.replay_dataset_hash,
        "execution_hash": execution_contract_metadata(request)["execution_hash"],
        "members": members or [member("swing"), member("scalp", holding=2), member("hour", holding=3), member("day", holding=4)],
        "policy": limits, "upgrades": upgrades or []}
    request.specialist_council_contract = seal_contract(body)
    return request, frame


def deterministic_strategy(frame, parameters):
    frame = frame.copy()
    frame["signal"] = "WAIT"
    # All four have the same first intent; short members continue while swing
    # remains open. Strategy differences are parameters, never future results.
    frame.loc[2:18, "signal"] = "BUY"
    frame["signal_confidence"] = 1.0
    return frame


def operator(*, target="confirmation", ast=None, bindings=None, budget=None):
    ast = ast or {"op": "BOOL", "available_at": "2025-01-01T00:00:00Z", "input_key": "veto"}
    return seal_contract({"protocol": "typed_operator_decision_v1", "source_task_key": "learned-operator-1",
        "scope_key": "hour-confirmation", "target": target, "ast": ast,
        "ast_hash": canonical_hash(ast), "abstractions": {},
        "input_bindings": bindings or {"veto": "risk_veto"},
        "budget": {"cpu_seconds": 1.0, "max_calls": 1000, "max_node_evaluations": 48000, **(budget or {})}})


def probe_contract(request, frame, warmup=8):
    stamp = lambda value: value.strftime("%Y-%m-%dT%H:%M:%SZ")
    body = {"protocol": "prospective_repair_probe_window_v1", "evaluator_version": "incremental_probe_window_v2",
        "experiment_key": "council-same-window", "dataset_hash": request.replay_dataset_hash,
        "execution_hash": request.execution_contract.get("execution_hash"),
        "independent_validation": False, "paper_2026_eligible": False,
        "loaded_rows": len(frame), "warmup_rows": warmup, "evaluated_rows": len(frame) - warmup,
        "loaded_start": stamp(frame.iloc[0]["time"]), "loaded_end": stamp(frame.iloc[-1]["time"]),
        "evaluated_start": stamp(frame.iloc[warmup]["time"]), "evaluated_end": stamp(frame.iloc[-1]["time"]),
        "evaluated_month_counts": {key: int(value) for key, value in
            frame.iloc[warmup:]['time'].dt.strftime('%Y-%m').value_counts().sort_index().items()}}
    return {**body, "contract_hash": hashlib.sha256(json.dumps(body, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()}


class SpecialistCouncilTest(unittest.TestCase):
    def replay(self, request, frame, strategy=deterministic_strategy):
        with patch("app.services.backtester.get_strategy", return_value=strategy):
            return run_simple_ema_rsi_backtest_on_dataframe(request, frame)

    def test_actual_native_trace_covers_decisions_and_publishes_real_trade_ledger(self):
        request, frame = fixtures(execution=ExecutionConfig(stop_loss_percent=2, take_profit_percent=5,
            max_leverage=5, commission_percent=0.1, swap_per_day_percent=0.2))
        request.emit_decision_trace = True
        result = self.replay(request, frame)
        trace = result.decision_trace
        receipt = result.specialist_council_receipt
        self.assertEqual([row['candle_index'] for row in trace], list(range(1, len(frame))))
        self.assertEqual(len(result.trade_ledger), result.total_trades)
        self.assertGreater(result.total_trades, 0)
        self.assertGreater(receipt['account']['fees'], 0)
        self.assertEqual(receipt['decision_trace_identity']['trace_hash'], canonical_hash(trace))
        fill_ids = {member['position_id'] for row in trace for member in row['member_decisions'] if member['accepted']}
        self.assertEqual(fill_ids, {row['position_id'] for row in receipt['position_ledger']})
        self.assertTrue(any(row['state']['owned_positions_at_open'] for row in trace))
        for row in trace:
            self.assertEqual(row['decision_id'], canonical_hash(row['source_clock']))
            self.assertLessEqual(len(row['features']), 6)
            self.assertEqual(len(row['closed_source_inputs_hash']), 64)
            self.assertTrue(all(member['closed_inputs']['time'] == row['signal_time'] for member in row['member_decisions']))
            self.assertTrue(all(len(member['closed_inputs']) <= 8 and len(member['closed_inputs_hash']) == 64
                and member['closed_input_columns'] > len(member['closed_inputs']) for member in row['member_decisions']))

    def test_bounded_native_inspection_keeps_omitted_closed_inputs_hash_bound(self):
        from app.services import backtester
        from app.services.specialist_council import _closed_trace_input, MEMBER_TRACE_FIELDS
        original = {'time': pd.Timestamp('2025-01-06T00:00:00Z'), 'close': 100.0, 'unshown_input': 1.0}
        changed = {**original, 'unshown_input': 2.0}
        before, before_hash, columns = _closed_trace_input(original, MEMBER_TRACE_FIELDS, backtester)
        after, after_hash, after_columns = _closed_trace_input(changed, MEMBER_TRACE_FIELDS, backtester)
        self.assertEqual(before, after)
        self.assertEqual(columns, after_columns)
        self.assertNotEqual(before_hash, after_hash)

    def test_native_15000_row_probe_trace_uses_actual_512_warmup_clock(self):
        from app.services.execution_contract import PROTOCOL as EXECUTION_PROTOCOL
        request, small = fixtures([member('hour')])
        request.timeframe = 'M5'
        request.evaluation_mode = 'incremental'
        request.emit_decision_trace = True
        request.execution_contract = execution_contract_metadata(request)
        request.execution_contract['protocol'] = EXECUTION_PROTOCOL
        request.execution_contract = execution_contract_metadata(request)
        body = {key: value for key, value in request.specialist_council_contract.items() if key != 'contract_hash'}
        body.update(execution_timeframe='M5', execution_hash=request.execution_contract['execution_hash'])
        request.specialist_council_contract = seal_contract(body)
        frame = pd.concat([small.iloc[[0]]] * 15512, ignore_index=True)
        frame['time'] = pd.date_range('2025-01-06', periods=15512, freq='5min', tz='UTC')
        request.policy_context['prospective_probe_window'] = probe_contract(request, frame, warmup=512)
        def wait(source, parameters):
            output = source.copy()
            output['signal'] = 'WAIT'
            output['signal_confidence'] = 0.0
            return output
        result = self.replay(request, frame, wait)
        scope = result.specialist_council_receipt['evaluated_scope']
        self.assertEqual(scope['rows'], 15000)
        self.assertEqual(scope['decision_rows'], 14999)
        self.assertEqual([row['candle_index'] for row in result.decision_trace], list(range(513, 15512)))
        self.assertEqual(result.decision_trace[0]['signal_time'], frame.iloc[512]['time'].isoformat())
        self.assertEqual(result.decision_trace[-1]['execution_time'], frame.iloc[-1]['time'].isoformat())
        self.assertTrue(result.data_quality['decision_trace']['complete'])
        self.assertEqual(result.data_quality['decision_trace']['input_candle_count'], 15512)
        self.assertEqual(result.total_trades, 0)

    def test_swing_stays_open_while_short_members_trade_on_shared_account(self):
        request, frame = fixtures()
        result = self.replay(request, frame)
        receipt = result.specialist_council_receipt
        self.assertEqual(receipt["status"], "computed")
        positions = receipt["position_ledger"]
        swing = next(item for item in positions if item["role"] == "swing")
        for role in ["scalp", "hour", "day"]:
            items = [item for item in positions if item["role"] == role]
            self.assertGreater(len(items), 1)
            self.assertLess(items[0]["exit_time"], swing["exit_time"])
        self.assertTrue(any(set(item["owners"]) == {"scalp", "hour", "day", "swing"} for item in receipt["account_ledger"]))
        self.assertEqual(receipt["account"]["reserved_capital"], 0)
        self.assertAlmostEqual(receipt["account"]["reconciliation_error"], 0)
        self.assertEqual(receipt, result.data_quality["specialist_council_receipt"])
        self.assertFalse(receipt["promotion_evidence"])

    def test_simultaneous_requests_cannot_spend_capital_twice(self):
        members = [member("swing", risk=1), member("scalp", risk=1)]
        request, frame = fixtures(members, policy={"max_reserved_capital_percent": 20.0})
        result = self.replay(request, frame)
        stages = result.specialist_council_receipt["intent_execution_ledger"]
        first_fills = [item for item in stages if item["reason"] == "filled" and item["time"] == "2025-01-06T03:00:00+00:00"]
        self.assertEqual(len(first_fills), 1)
        self.assertTrue(any(item["reason"] == "capital_reservation_limit" for item in stages))
        self.assertLessEqual(result.specialist_council_receipt["account"]["max_reserved_capital"], 2000)
        ids = [item["position_id"] for item in first_fills]
        self.assertEqual(len(ids), len(set(ids)))

    def test_hard_risk_veto_has_role_reason(self):
        request, frame = fixtures([member("swing", risk=0.5), member("hour", risk=0.5)], policy={"max_total_risk_percent": 0.5})
        result = self.replay(request, frame)
        self.assertGreater(result.specialist_council_receipt["role_stage_reasons"]["hour"].get("risk:account_hard_risk_limit", 0), 0)
        self.assertEqual({item["role"] for item in result.specialist_council_receipt["position_ledger"]}, {"swing"})

    def test_fees_carry_and_embedded_costs_reconcile(self):
        execution = ExecutionConfig(stop_loss_percent=2, take_profit_percent=5, max_leverage=5,
            spread_points=2, point_size=0.01, slippage_points=1, commission_percent=0.1, swap_per_day_percent=0.2)
        request, frame = fixtures([member("swing")], execution=execution)
        result = self.replay(request, frame)
        receipt = result.specialist_council_receipt
        closed = receipt["position_ledger"][0]
        holding = (pd.Timestamp(closed["exit_time"]) - pd.Timestamp(closed["entry_time"])).total_seconds() / 86400
        self.assertAlmostEqual(closed["fees"], closed["notional"] * 0.1 / 100)
        self.assertAlmostEqual(closed["carry"], closed["notional"] * 0.2 / 100 * holding)
        self.assertGreater(closed["spread_slippage"], 0)
        self.assertAlmostEqual(result.final_balance - result.initial_balance, closed["net_pnl"])
        self.assertAlmostEqual(closed["net_pnl"], closed["gross_pnl_after_embedded_cost"] - closed["fees"] - closed["carry"])

    def test_member_and_management_version_pinned_after_upgrade(self):
        swing = member("swing")
        upgraded = copy.deepcopy(swing)
        upgraded["management_version"] = "management-v2"
        upgraded["horizon"]["max_holding_bars"] = 1
        request, frame = fixtures([swing], upgrades=[{"effective_at": "2025-01-06T05:00:00Z", "council_version": "council-v2", "members": [upgraded]}])
        result = self.replay(request, frame)
        receipt = result.specialist_council_receipt
        original = receipt["position_ledger"][0]
        self.assertEqual(original["council_version"], "council-v1")
        self.assertEqual(original["management_version"], "management-v1")
        self.assertEqual(original["exit_reason"], "end_of_data")
        self.assertEqual(receipt["final_council_version"], "council-v2")

    def test_declared_identity_and_upgrades_tamper_fail_closed(self):
        request, frame = fixtures()
        request.specialist_council_contract["members"][0]["management_version"] = "tampered"
        with self.assertRaisesRegex(ValueError, "CONTRACT_HASH_MISMATCH"):
            self.replay(request, frame)

    def test_second_scalp_is_dependency_with_no_fake_trade(self):
        scalp = member("scalp")
        scalp["execution_requirements"] = {"second_scalp": True, "market_depth": True}
        request, frame = fixtures([scalp])
        result = self.replay(request, frame)
        self.assertEqual(result.total_trades, 0)
        self.assertEqual(result.specialist_council_receipt["status"], "dependency")
        self.assertIn("SECOND_SCALP_REQUIRES_TICK_ORDER_FILL_AND_LATENCY", result.specialist_council_receipt["dependency_reasons"])

    def test_opposite_policy_hedges_only_when_declared_and_netting_never_fakes_hedging(self):
        def different_directions(frame, parameters):
            frame = deterministic_strategy(frame, parameters)
            if parameters["ema_fast"] == 2:
                frame.loc[2:18, "signal"] = "SELL"
            return frame
        members = [member("swing"), member("scalp")]
        request, frame = fixtures(members)
        result = self.replay(request, frame, different_directions)
        self.assertTrue(any(item["reason"] == "opposite_position_rejected" for item in result.specialist_council_receipt["intent_execution_ledger"]))
        request, frame = fixtures(members, policy={"opposite_position_policy": "hedge"})
        result = self.replay(request, frame, different_directions)
        self.assertEqual({item["direction"] for item in result.specialist_council_receipt["position_ledger"]}, {"BUY", "SELL"})
        request, frame = fixtures(members, policy={"broker_position_mode": "netting", "opposite_position_policy": "hedge"})
        result = self.replay(request, frame, different_directions)
        self.assertEqual(result.total_trades, 0)
        self.assertIn("BROKER_NETTING_OPPOSITE_OWNERSHIP_UNSUPPORTED", result.specialist_council_receipt["dependency_reasons"])

    def test_learned_operator_changes_actual_entry_and_ablation_restores_control(self):
        hour = member("hour")
        # A learned confirmation operator from the typed library asks whether
        # the actual prior close exceeds 100.5, so the flat stream abstains.
        ast = {"op": "GREATER_THAN", "args": [
            {"op": "PRICE_CLOSE", "available_at": "2025-01-01T00:00:00Z", "input_key": "price"},
            {"op": "CONST", "type": "price", "value": 100.5}]}
        hour["operator_contract"] = operator(ast=ast, bindings={"price": "close"})
        request, frame = fixtures([hour])
        modified = self.replay(request, frame)
        self.assertEqual(modified.total_trades, 0)
        program = modified.specialist_council_receipt["members"][0]["operator_receipt"]
        self.assertGreater(program["behavior_delta_decisions"], 0)
        hour.pop("operator_contract")
        request, frame = fixtures([hour])
        control = self.replay(request, frame)
        self.assertGreater(control.total_trades, 0)
        self.assertNotEqual(modified.event_ledger_hash, control.event_ledger_hash)

    def test_operator_reduces_sizing_and_cannot_increase_external_risk(self):
        hour = member("hour")
        request, frame = fixtures([hour])
        control = self.replay(request, frame)
        ast = {"op": "CONST", "type": "number", "value": 0.5}
        hour["operator_contract"] = operator(target="risk_multiplier", ast=ast, bindings={})
        request, frame = fixtures([hour])
        modified = self.replay(request, frame)
        self.assertAlmostEqual(modified.specialist_council_receipt["position_ledger"][0]["notional"],
            control.specialist_council_receipt["position_ledger"][0]["notional"] * 0.5)
        ast = {"op": "CONST", "type": "number", "value": 1.5}
        program = BoundedDecisionProgram(operator(target="risk_multiplier", ast=ast))
        with self.assertRaisesRegex(ValueError, "RISK_INCREASE_FORBIDDEN"):
            program.evaluate({}, decision_at="2025-01-06T00:00:00Z", observed_at="2025-01-06T00:00:00Z")

    def test_operator_asof_whitelist_and_resource_bound(self):
        ast = {"op": "BOOL", "available_at": "2025-01-07T00:00:00Z", "input_key": "veto"}
        program = BoundedDecisionProgram(operator(ast=ast))
        with self.assertRaisesRegex(ValueError, "FUTURE_INPUT_FORBIDDEN"):
            program.evaluate({"risk_veto": False}, decision_at="2025-01-06T00:00:00Z", observed_at="2025-01-06T00:00:00Z")
        with self.assertRaisesRegex(ValueError, "INPUT_BINDING_INVALID"):
            BoundedDecisionProgram(operator(bindings={"veto": "future_return"}))
        program = BoundedDecisionProgram(operator(budget={"max_node_evaluations": 1}))
        program.evaluate({"risk_veto": False}, decision_at="2025-01-06T00:00:00Z", observed_at="2025-01-06T00:00:00Z")
        with self.assertRaisesRegex(ValueError, "COMPUTE_BUDGET_EXCEEDED"):
            program.evaluate({"risk_veto": False}, decision_at="2025-01-06T01:00:00Z", observed_at="2025-01-06T01:00:00Z")

    def test_float_preserving_contract_transport_and_copy_drift(self):
        request, frame = fixtures([member("hour")])
        contract = request.specialist_council_contract
        body = {key: value for key, value in contract.items() if key != "contract_hash"}
        preserved = json.dumps(body, separators=(",", ":"))
        transported = json.loads(preserved.replace("100.0", "100").replace("500.0", "500").replace("1.0", "1"))
        request.specialist_council_contract = {**transported, "contract_hash": contract["contract_hash"], "contract_json": preserved}
        self.assertGreater(self.replay(request, frame).total_trades, 0)
        request.specialist_council_contract["policy"]["max_open_positions"] = 2
        with self.assertRaisesRegex(ValueError, "JSON_COPY_MISMATCH"):
            self.replay(request, frame)

    def test_partial_fill_releases_capital_and_carry_is_proportional(self):
        hour = member("hour")
        hour["parameters"].update({"partial_take_profit_fraction": 0.5, "partial_target_atr_multiplier": 0.4})
        request, frame = fixtures([hour], execution=ExecutionConfig(stop_loss_percent=1,
            take_profit_percent=2, commission_percent=0.1, swap_per_day_percent=0.2))
        result = self.replay(request, frame)
        ledger = result.specialist_council_receipt["position_ledger"][0]
        partial = ledger["partial"][0]
        self.assertEqual(partial["fraction"], 0.5)
        self.assertGreater(partial["reserved_capital_released"], 0)
        initial = pd.Timestamp(ledger["entry_time"])
        partial_days = (pd.Timestamp(partial["exit_time"]) - initial).total_seconds() / 86400
        full_days = (pd.Timestamp(ledger["exit_time"]) - initial).total_seconds() / 86400
        self.assertAlmostEqual(ledger["carry"], ledger["notional"] * 0.2 / 100 * (0.5 * partial_days + 0.5 * full_days))
        self.assertAlmostEqual(ledger["fees"], ledger["notional"] * 0.1 / 100)
        self.assertAlmostEqual(result.final_balance - result.initial_balance, ledger["net_pnl"])

    def test_seconds_holding_deadline_reports_unavoidable_weekend_overrun(self):
        swing = member("swing")
        swing["horizon"]["max_holding_seconds"] = 2 * 3600
        request, frame = fixtures([swing])
        frame.loc[4:, "time"] = frame.loc[4:, "time"] + pd.Timedelta(days=3)
        result = self.replay(request, frame)
        first = result.specialist_council_receipt["position_ledger"][0]
        self.assertEqual(first["exit_reason"], "max_holding_deadline")
        self.assertGreater(first["gap_overrun_seconds"], 0)
        self.assertEqual(first["management_deadline"], "2025-01-06T05:00:00+00:00")

    def test_operator_receipt_is_deterministic_and_float_copy_protected(self):
        ast = {"op": "CONST", "type": "number", "value": 0.5}
        binding = operator(target="risk_multiplier", ast=ast)
        body = {key: value for key, value in binding.items() if key != "contract_hash"}
        preserved = json.dumps(body, separators=(",", ":"))
        transported = json.loads(preserved.replace("1.0", "1"))
        hour = member("hour")
        hour["operator_contract"] = {**transported, "contract_hash": binding["contract_hash"], "contract_json": preserved}
        request, frame = fixtures([hour])
        first, second = self.replay(request, frame), self.replay(request, frame)
        self.assertEqual(first.specialist_council_receipt["receipt_hash"], second.specialist_council_receipt["receipt_hash"])
        broken = copy.deepcopy(hour["operator_contract"])
        broken["budget"]["max_calls"] = 2
        with self.assertRaisesRegex(ValueError, "JSON_COPY_MISMATCH"):
            BoundedDecisionProgram(broken)

    def test_request_cannot_relax_external_envelope_and_naive_upgrade_rejected(self):
        request, frame = fixtures(policy={"max_total_risk_percent": 6.0})
        with self.assertRaisesRegex(ValueError, "MAX_TOTAL_RISK_PERCENT_INVALID"):
            self.replay(request, frame)
        request, frame = fixtures(upgrades=[{"effective_at": "2025-01-06T05:00:00", "council_version": "council-v2", "members": [member("hour")]}])
        with self.assertRaisesRegex(ValueError, "EXPLICIT_UTC_REQUIRED"):
            self.replay(request, frame)

    def test_passport_scope_and_permissions_abstain_without_erasing_opportunities(self):
        hour = member("hour")
        hour["scope"] = {"symbols": ["EURUSD"], "contexts": ["any"]}
        request, frame = fixtures([hour])
        with self.assertRaisesRegex(ValueError, "MEMBER_SYMBOL_SCOPE_MISMATCH"):
            self.replay(request, frame)
        hour["scope"] = {"symbols": ["XAUUSD"], "contexts": ["trend"]}
        request, frame = fixtures([hour])
        wrong_context = self.replay(request, frame)
        self.assertEqual(wrong_context.total_trades, 0)
        self.assertGreater(wrong_context.entry_funnel["strategy_signals"], 0)
        self.assertIn("passport_context_outside_scope", wrong_context.entry_funnel["rejection_reasons"])
        hour["scope"]["contexts"] = ["any"]
        hour["allowed_actions"] = ["wait"]
        request, frame = fixtures([hour])
        denied = self.replay(request, frame)
        self.assertEqual(denied.total_trades, 0)
        self.assertIn("passport_trade_permission_missing", denied.entry_funnel["rejection_reasons"])

    def test_tick_precision_is_dependency_even_with_hourly_decisions(self):
        hour = member("hour")
        hour["horizon"]["execution_precision"] = "tick"
        hour["horizon"]["decision_interval_seconds"] = 3600
        request, frame = fixtures([hour])
        result = self.replay(request, frame)
        self.assertEqual(result.specialist_council_receipt["status"], "dependency")
        self.assertIn("TICK_EXECUTION_REQUIRES_ORDER_FILL_AND_LATENCY", result.specialist_council_receipt["dependency_reasons"])
        self.assertEqual(len(result.specialist_council_receipt["members"]), 1)
        self.assertEqual(result.specialist_council_receipt["members"][0]["stages"]["decision:observed"], 0)

    def test_run_all_carries_native_per_candidate_and_cache_refuses_missing_receipt(self):
        from app import main
        request, frame = fixtures([member("hour")])
        request.strategies = [StrategyRuntimeConfig(strategy="portfolio_v1", base_strategy="portfolio", version="v1",
            specialist_council_contract=request.specialist_council_contract)]
        request.specialist_council_contract = {}
        request.candles = [Candle.model_validate(item) for item in frame.to_dict("records")]
        request.evaluation_mode = "incremental"
        probe = probe_contract(request, frame)
        request.policy_context["prospective_probe_window"] = probe
        with patch("app.services.backtester.get_strategy", return_value=deterministic_strategy), \
             patch("app.main._write_replay_checkpoint"), \
             patch("app.main._load_immutable_replay_cache", return_value=None), \
             patch("app.main._store_immutable_replay_cache"), \
             patch("app.main._internal_api_token", return_value="fixture"):
            response = main._run_all_backtests_sync(request)
        item = response["leaderboard"][0]
        self.assertEqual(len(response["leaderboard"]), 1)
        self.assertEqual(item["result"]["specialist_council_receipt"]["protocol"], "specialist_council_receipt_v1")
        self.assertTrue(item["result"]["prospective_probe_window_receipt"]["complete"])
        self.assertEqual(item["result"]["specialist_council_receipt"]["evaluated_scope"]["rows"], 14)
        self.assertEqual(item["result"]["specialist_council_receipt"]["members"][0]["stages"]["decision:observed"], 13)
        candidate = request.model_copy(update={"strategies": [], "specialist_council_contract": request.strategies[0].specialist_council_contract})
        self.assertTrue(main._candidate_cache_contract_is_current(item, candidate))
        bad = copy.deepcopy(item)
        bad["result"].pop("specialist_council_receipt")
        self.assertFalse(main._candidate_cache_contract_is_current(bad, candidate))
        bad = copy.deepcopy(item)
        bad["result"].pop("prospective_probe_window_receipt")
        self.assertFalse(main._candidate_cache_contract_is_current(bad, candidate))
        bad = copy.deepcopy(item)
        bad["result"]["specialist_council_receipt"]["account"]["net_pnl"] += 1
        self.assertFalse(main._candidate_cache_contract_is_current(bad, candidate))

    def test_native_probe_keeps_feature_warmup_without_any_warmup_account_activity(self):
        request, frame = fixtures([member("hour")])
        request.evaluation_mode = "incremental"
        request.policy_context["prospective_probe_window"] = probe_contract(request, frame)
        observed_lengths = []
        def warmup_only(source, parameters):
            observed_lengths.append(len(source))
            output = deterministic_strategy(source, parameters)
            output.loc[8:, "signal"] = "WAIT"
            return output
        result = self.replay(request, frame, warmup_only)
        self.assertEqual(observed_lengths, [len(frame)])
        self.assertEqual(result.total_trades, 0)
        self.assertEqual(result.final_balance, request.initial_balance)
        scope = result.specialist_council_receipt["evaluated_scope"]
        self.assertEqual(scope["warmup_rows"], 8)
        self.assertEqual(scope["rows"], 14)
        self.assertEqual(scope["decision_rows"], 13)
        self.assertEqual(scope["policy_hash"], canonical_hash(request.policy_context["prospective_probe_window"]))
        self.assertEqual(scope["start_inclusive"], frame.iloc[8]["time"].isoformat())
        self.assertEqual(scope["end_exclusive"], (frame.iloc[-1]["time"] + pd.Timedelta(hours=1)).isoformat())
        self.assertTrue(result.prospective_probe_window_receipt["complete"])
        self.assertEqual(result.data_quality["replay_evaluation_scope"], scope)
        evaluated = self.replay(request, frame)
        self.assertTrue(evaluated.trades)
        self.assertTrue(all(item["entry_time"] >= frame.iloc[9]["time"].isoformat()
            for item in evaluated.specialist_council_receipt["position_ledger"]))

    def test_ordinary_native_incremental_uses_canonical_tail_not_the_full_source(self):
        for source_rows, evaluated_rows in [(2200, 2000), (5010, 5000)]:
            with self.subTest(source_rows=source_rows):
                request, small = fixtures([member("hour")])
                request.evaluation_mode = "incremental"
                frame = pd.concat([small.iloc[[0]]] * source_rows, ignore_index=True)
                frame["time"] = pd.date_range("2025-01-06", periods=source_rows, freq="h", tz="UTC")
                warmup = source_rows - evaluated_rows
                lengths = []
                def warmup_only(source, parameters):
                    lengths.append(len(source))
                    output = deterministic_strategy(source, parameters)
                    output.loc[warmup:, "signal"] = "WAIT"
                    return output
                result = self.replay(request, frame, warmup_only)
                self.assertEqual(lengths, [source_rows])
                self.assertEqual(result.total_trades, 0)
                scope = result.specialist_council_receipt["evaluated_scope"]
                self.assertEqual(scope["rows"], evaluated_rows)
                self.assertEqual(scope["decision_rows"], evaluated_rows - 1)
                self.assertEqual(scope["warmup_rows"], warmup)
                self.assertEqual(scope["start_inclusive"], frame.iloc[warmup]["time"].isoformat())
                self.assertEqual(result.specialist_council_receipt["members"][0]["stages"]["decision:observed"], evaluated_rows - 1)


if __name__ == "__main__":
    unittest.main()
