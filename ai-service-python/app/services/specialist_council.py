"""Native chronological specialist replay using the canonical execution kernels.

Each specialist owns its position and management program. A single account
serializes reservations and fills; independent backtest P&Ls are never merged.
This research runtime creates no broker orders and grants no trading authority.
"""

from __future__ import annotations

import copy
import json
import math
from collections import Counter, defaultdict
from dataclasses import dataclass, field

import pandas as pd

from app.schemas import SimpleBacktestRequest, SimpleBacktestResponse, SimpleTrade
from app.services.execution_contract import enforce_policy_boundary, execution_contract_metadata
from app.services.research_program_tasks import BoundedDecisionProgram, canonical_hash, canonical_json, preserved_contract_body, _same_ast_copy
from app.services.prospective_probe_window import assert_clean_discovery_boundary, select_probe_window

PROTOCOL = "specialist_council_runtime_v1"
RECEIPT_PROTOCOL = "specialist_council_receipt_v1"
MAX_SOURCE_ROWS = 250000
MAX_MEMBER_CANDLE_EVALUATIONS = 2000000
TRADING_ROLES = {"scalp", "hour", "day", "swing"}
POLICY_LIMITS = {
    "max_reserved_capital_percent": (0, 100),
    "max_gross_exposure_percent": (0, 100),
    "max_total_risk_percent": (0, 5),
    "max_drawdown_percent": (0, 20),
    "max_daily_loss_percent": (0, 5),
    "max_expected_cost_percent": (0, 5),
}
PRIMARY_TRACE_FIELDS = ('time', 'open', 'high', 'low', 'close', 'volume')
MEMBER_TRACE_FIELDS = ('time', 'close', 'signal_confidence', 'market_regime',
    'volatility_regime', 'mtf_stack_context_hash', 'composition_decision_id', 'entry_contract_status')


def _closed_trace_input(row, fields, kernel):
    # Every actual closed input remains hash-bound, while only the bounded
    # inspection snapshot is duplicated across the account/member clocks.
    full = {key: kernel._semantic_event_value(value) for key, value in row.items()}
    return {key: full[key] for key in fields if key in full}, canonical_hash(full), len(full)


def seal_contract(body: dict) -> dict:
    sealed = copy.deepcopy(body)
    sealed.pop("contract_hash", None)
    return {**sealed, "contract_hash": canonical_hash(sealed)}


def _receipt_json_value(value: object) -> object:
    """Seal the representation that PHP's associative JSON decoder receives.

    An empty JSON object becomes an empty PHP array. Normalize only receipts,
    never executable contracts or typed ASTs, before both emission and hashing.
    """
    if isinstance(value, dict):
        return {key: _receipt_json_value(item) for key, item in value.items()} if value else []
    if isinstance(value, list):
        return [_receipt_json_value(item) for item in value]
    return value


def receipt_hash_is_current(receipt: dict) -> bool:
    body = {key: value for key, value in receipt.items() if key not in {"receipt_hash", "receipt_json"}}
    try:
        preserved = receipt.get("receipt_json")
        if preserved is not None:
            if not isinstance(preserved, str):
                return False
            parsed = json.loads(preserved)
            if not _same_ast_copy(body, parsed) or canonical_json(parsed) != preserved:
                return False
            body = parsed
        return receipt.get("receipt_hash") == canonical_hash(body)
    except (ValueError, TypeError, RecursionError):
        return False


def _utc(value: object) -> pd.Timestamp:
    stamp = pd.Timestamp(value)
    if pd.isna(stamp):
        raise ValueError("SPECIALIST_COUNCIL_TIMESTAMP_INVALID")
    if stamp.tzinfo is None or stamp.utcoffset().total_seconds() != 0:
        raise ValueError("SPECIALIST_COUNCIL_EXPLICIT_UTC_REQUIRED")
    return stamp.tz_convert("UTC")


def _stamp(value: object) -> str:
    return _utc(value).isoformat()


def _positive_number(value: object, maximum: float) -> bool:
    return (isinstance(value, (float, int)) and not isinstance(value, bool)
            and math.isfinite(value) and 0 < value <= maximum)


def _members(members: object) -> list[dict]:
    if not isinstance(members, list) or not 1 <= len(members) <= 32:
        raise ValueError("SPECIALIST_COUNCIL_MEMBER_BUDGET_INVALID")
    seen = set()
    total_weight = 0.0
    for member in members:
        if not isinstance(member, dict):
            raise ValueError("SPECIALIST_COUNCIL_MEMBER_INVALID")
        identity = member.get("specialist_id")
        if not isinstance(identity, str) or not identity or identity in seen:
            raise ValueError("SPECIALIST_COUNCIL_MEMBER_ID_INVALID")
        seen.add(identity)
        if member.get("role") not in TRADING_ROLES:
            raise ValueError("SPECIALIST_COUNCIL_TRADING_ROLE_REQUIRED")
        for key in ("strategy", "version", "strategy_version", "tactic_version", "management_version"):
            if not isinstance(member.get(key), str) or not member[key]:
                raise ValueError(f"SPECIALIST_COUNCIL_MEMBER_{key.upper()}_REQUIRED")
        if not isinstance(member.get("parameters", {}), dict):
            raise ValueError("SPECIALIST_COUNCIL_PARAMETERS_INVALID")
        if "scope" in member:
            scope = member["scope"]
            if not isinstance(scope, dict) or any(not isinstance(scope.get(key), list) or not scope[key]
                or any(not isinstance(value, str) or not value for value in scope[key]) for key in ("symbols", "contexts")):
                raise ValueError("SPECIALIST_COUNCIL_PASSPORT_SCOPE_INVALID")
        if "allowed_actions" in member:
            actions = member["allowed_actions"]
            if (not isinstance(actions, list) or "wait" not in actions
                or any(action not in {"propose_trade", "manage_owned_position", "wait"} for action in actions)):
                raise ValueError("SPECIALIST_COUNCIL_TRADER_PERMISSION_INVALID")
        horizon = member.get("horizon")
        if not isinstance(horizon, dict):
            raise ValueError("SPECIALIST_COUNCIL_HORIZON_REQUIRED")
        for key in ("decision_interval_bars", "reevaluation_interval_bars", "max_holding_bars"):
            value = horizon.get(key)
            if not isinstance(value, int) or isinstance(value, bool) or not 1 <= value <= 100000:
                raise ValueError("SPECIALIST_COUNCIL_HORIZON_INVALID")
        for key in ("decision_interval_seconds", "reevaluation_interval_seconds", "max_holding_seconds"):
            if key in horizon and (not isinstance(horizon[key], int) or isinstance(horizon[key], bool) or not 1 <= horizon[key] <= 31536000):
                raise ValueError("SPECIALIST_COUNCIL_CLOCK_HORIZON_INVALID")
        if not _positive_number(member.get("capital_weight"), 1):
            raise ValueError("SPECIALIST_COUNCIL_CAPITAL_WEIGHT_INVALID")
        total_weight += float(member["capital_weight"])
        if not _positive_number(member.get("risk_per_trade_percent"), 2):
            raise ValueError("SPECIALIST_COUNCIL_RISK_BUDGET_INVALID")
        if member.get("operator_contract"):
            BoundedDecisionProgram(member["operator_contract"])
    if total_weight > 1 + 1e-12:
        raise ValueError("SPECIALIST_COUNCIL_ALLOCATION_WEIGHT_EXCEEDED")
    return copy.deepcopy(members)


def validate_contract(payload: SimpleBacktestRequest) -> dict:
    contract = payload.specialist_council_contract
    if not isinstance(contract, dict) or contract.get("protocol") != PROTOCOL:
        raise ValueError("SPECIALIST_COUNCIL_PROTOCOL_REQUIRED")
    body = preserved_contract_body(contract, "SPECIALIST_COUNCIL")
    if canonical_hash(body) != contract.get("contract_hash"):
        raise ValueError("SPECIALIST_COUNCIL_CONTRACT_HASH_MISMATCH")
    contract = {**body, "contract_hash": contract["contract_hash"]}
    for key in ("council_id", "council_version"):
        if not isinstance(contract.get(key), str) or not contract[key]:
            raise ValueError("SPECIALIST_COUNCIL_IDENTITY_REQUIRED")
    execution = execution_contract_metadata(payload)
    if execution["status"] == "mismatch":
        raise ValueError("SPECIALIST_COUNCIL_EXECUTION_CONTRACT_MISMATCH")
    if (contract.get("execution_timeframe") != payload.timeframe
        or contract.get("replay_dataset_hash") != payload.replay_dataset_hash
        or not payload.replay_dataset_hash
        or contract.get("execution_hash") != execution["execution_hash"]):
        raise ValueError("SPECIALIST_COUNCIL_AUTHORITY_IDENTITY_MISMATCH")
    policy = contract.get("policy")
    if not isinstance(policy, dict):
        raise ValueError("SPECIALIST_COUNCIL_POLICY_REQUIRED")
    for key, (_minimum, maximum) in POLICY_LIMITS.items():
        if not _positive_number(policy.get(key), maximum):
            raise ValueError(f"SPECIALIST_COUNCIL_POLICY_{key.upper()}_INVALID")
    cap = policy.get("max_open_positions")
    if not isinstance(cap, int) or isinstance(cap, bool) or not 1 <= cap <= 32:
        raise ValueError("SPECIALIST_COUNCIL_POSITION_LIMIT_INVALID")
    if policy.get("broker_position_mode") not in {"hedging", "netting"}:
        raise ValueError("SPECIALIST_COUNCIL_BROKER_MODE_REQUIRED")
    if policy.get("opposite_position_policy") not in {"reject", "hedge"}:
        raise ValueError("SPECIALIST_COUNCIL_OPPOSITE_POSITION_POLICY_REQUIRED")
    carry = policy.get("carry_per_day_percent_by_direction", {}) or {}
    if not isinstance(carry, dict) or any(
        direction not in {"BUY", "SELL"} or not isinstance(rate, (int, float))
        or isinstance(rate, bool) or not math.isfinite(rate) or rate < 0
        for direction, rate in carry.items()
    ):
        raise ValueError("SPECIALIST_COUNCIL_CARRY_RATE_INVALID")
    members = _members(contract.get("members"))
    def validate_member_symbols(declarations: list[dict]) -> None:
        execution_symbol = payload.symbol.upper().replace("/", "")
        for member in declarations:
            native_symbol = member.get("symbol")
            if native_symbol is not None and (not isinstance(native_symbol, str) or native_symbol.upper().replace("/", "") != execution_symbol):
                raise ValueError("SPECIALIST_COUNCIL_MEMBER_SYMBOL_IDENTITY_MISMATCH")
            scope = member.get("scope")
            if scope:
                symbols = {value.upper().replace("/", "") for value in scope["symbols"]}
                if execution_symbol not in symbols and not symbols.intersection({"*", "ANY"}):
                    raise ValueError("SPECIALIST_COUNCIL_MEMBER_SYMBOL_SCOPE_MISMATCH")
    validate_member_symbols(members)
    upgrades = contract.get("upgrades", [])
    if not isinstance(upgrades, list) or len(upgrades) > 16:
        raise ValueError("SPECIALIST_COUNCIL_UPGRADE_BUDGET_INVALID")
    previous_time = None
    versions = {contract["council_version"]}
    for upgrade in upgrades:
        if not isinstance(upgrade, dict) or not upgrade.get("council_version"):
            raise ValueError("SPECIALIST_COUNCIL_UPGRADE_IDENTITY_REQUIRED")
        effective = upgrade.get("effective_at")
        if not isinstance(effective, str) or not effective.endswith(("Z", "+00:00")):
            raise ValueError("SPECIALIST_COUNCIL_UPGRADE_EXPLICIT_UTC_REQUIRED")
        effective = _utc(effective)
        if (previous_time is not None and effective <= previous_time) or upgrade["council_version"] in versions:
            raise ValueError("SPECIALIST_COUNCIL_UPGRADE_ORDER_INVALID")
        previous_time = effective
        versions.add(upgrade["council_version"])
        validate_member_symbols(_members(upgrade.get("members")))
    return {**copy.deepcopy(contract), "members": members}


@dataclass
class MemberRuntime:
    declaration: dict
    council_version: str
    payload: SimpleBacktestRequest
    rows: list[dict]
    data_quality: dict
    operator: BoundedDecisionProgram | None = None
    stages: Counter = field(default_factory=Counter)
    closed_returns: dict = field(default_factory=lambda: defaultdict(list))
    cooldown_until: int = -1
    loss_streak: int = 0
    meta_returns: dict = field(default_factory=lambda: defaultdict(list))
    confidence_history: dict = field(default_factory=lambda: defaultdict(list))
    temporal_state: dict = field(default_factory=dict)
    instrument_state: dict = field(default_factory=dict)
    loss_wait_until: int = -1
    last_decision_at: pd.Timestamp | None = None
    _version_identity_hash: str = ""

    @property
    def identity(self) -> str:
        return self.declaration["specialist_id"]

    @property
    def version_hash(self) -> str:
        if not self._version_identity_hash:
            self._version_identity_hash = canonical_hash({"council_version": self.council_version, "member": self.declaration})
        return self._version_identity_hash


def _compile_member(payload: SimpleBacktestRequest, frame: pd.DataFrame, member: dict, version: str) -> MemberRuntime:
    from app.services import backtester as kernel
    from app.services.composition_runtime import validate_composition_runtime_contract
    from app.services.parameter_schema import validate_strategy_parameters

    parameters = validate_strategy_parameters(member["strategy"], member.get("parameters", {}), member.get("base_strategy"))
    child = payload.model_copy(update={
        "strategy": member["strategy"], "base_strategy": member.get("base_strategy"),
        "version": member["version"], "parameters": parameters,
        "portfolio_members": [], "strategies": [], "specialist_council_contract": {},
        "risk_per_trade": member["risk_per_trade_percent"],
        "specialist_context_contract": member.get("specialist_context_contract", {}) or {},
        "composition_runtime_contract": member.get("composition_runtime_contract", {}) or {},
        "instrument_research_assignment": member.get("instrument_research_assignment", {}) or {},
        "mtf_pilot": member.get("mtf_pilot", payload.mtf_pilot) or {},
    })
    enforce_policy_boundary(child)
    validate_composition_runtime_contract(
        child.composition_runtime_contract, base_strategy=child.base_strategy,
        parameters=child.parameters, execution_timeframe=child.timeframe,
        runtime_authority=kernel._composition_runtime_authority(child),
    )
    snapshot = kernel.prepare_signal_snapshot(child, frame)
    if len(snapshot.frame) != len(frame) or not snapshot.frame["time"].reset_index(drop=True).equals(frame["time"].reset_index(drop=True)):
        raise ValueError("SPECIALIST_COUNCIL_MEMBER_CANDLE_DOMAIN_MISMATCH")
    runtime = MemberRuntime(member, version, child, snapshot.frame.to_dict("records"), snapshot.data_quality,
        BoundedDecisionProgram(member["operator_contract"]) if member.get("operator_contract") else None)
    runtime.temporal_state = kernel._temporal_survival_state()
    runtime.instrument_state = kernel._instrument_runtime_state(child.instrument_research_assignment)
    return runtime


def _passport_scope_reason(member: dict, payload: SimpleBacktestRequest, prior: dict) -> str | None:
    scope = member.get("scope")
    if not scope:
        return None
    symbols = {value.upper().replace("/", "") for value in scope["symbols"]}
    if payload.symbol.upper().replace("/", "") not in symbols and not symbols.intersection({"*", "ANY"}):
        return "passport_symbol_outside_scope"
    contexts = {value.lower() for value in scope["contexts"]}
    regime = str(prior.get("market_regime", "unknown")).lower()
    if not (regime in contexts or contexts.intersection({"*", "any"}) or ("trend" in contexts and regime.startswith("trend_"))):
        return "passport_context_outside_scope"
    return None


def run_specialist_council(payload: SimpleBacktestRequest, frame: pd.DataFrame) -> SimpleBacktestResponse:
    from app.services import backtester as kernel

    contract = validate_contract(payload)
    policy = contract["policy"]
    policy_boundary = enforce_policy_boundary(payload)
    frame = kernel._prepare_simple_dataframe(payload, frame)
    assert_clean_discovery_boundary(payload)
    probe = (payload.policy_context or {}).get("prospective_probe_window")
    probe_receipt = None
    warmup_rows = 0
    selector_policy = {"protocol": "native_evaluated_window_selector_v1", "mode": payload.evaluation_mode,
        "selection": "full_prepared_source", "indicator_warmup_preserved": True}
    if isinstance(probe, dict):
        evaluated, probe_receipt = select_probe_window(frame, probe,
            payload.replay_dataset_hash, (payload.execution_contract or {}).get("execution_hash"))
        warmup_rows = len(frame) - len(evaluated)
        selector_policy["selection"] = "sealed_prospective_probe"
    elif payload.evaluation_mode == "incremental":
        limit = 5000 if len(frame) >= 5000 else 2000
        warmup_rows = max(0, len(frame) - limit)
        selector_policy.update({"selection": "canonical_survival_tail" if limit == 5000 else "canonical_opportunity_tail",
            "maximum_evaluated_rows": limit})
    # Compile every member over the full immutable stream so indicators retain
    # warmup. Warmup has no account, intents or P&L, and its last signal cannot
    # enter at the first evaluated open (the legacy next-open clock is N-1).
    evaluation_start_index = warmup_rows
    selection_policy = probe if probe_receipt is not None else (payload.policy_context or {}).get("full_replay_runtime_policy")
    if selection_policy is None and payload.evaluation_mode == "incremental":
        selection_policy = selector_policy
    evaluated_scope = {"start_inclusive": _stamp(frame.iloc[evaluation_start_index]["time"]),
        "end_exclusive": _stamp(_utc(frame.iloc[-1]["time"]) + pd.Timedelta(minutes=kernel._timeframe_duration_minutes(payload.timeframe))),
        "rows": len(frame) - warmup_rows, "decision_rows": len(frame) - warmup_rows - 1,
        "warmup_rows": warmup_rows,
        "policy_hash": canonical_hash(selection_policy) if isinstance(selection_policy, dict) else None,
        "selector_policy": selector_policy}
    # Validate the actual immutable source and release before compiling any member.
    attestation = kernel._consumed_dataset_attestation(payload, frame)
    release_receipt = kernel.attest_research_release(payload.research_release,
        dataset_hash=payload.replay_dataset_hash,
        execution_hash=execution_contract_metadata(payload)["execution_hash"])
    duration = pd.Timedelta(minutes=kernel._timeframe_duration_minutes(payload.timeframe))
    versions = [(contract["council_version"], contract["members"])] + [
        (item["council_version"], item["members"]) for item in contract.get("upgrades", [])
    ]
    dependencies = []
    if len(frame) > MAX_SOURCE_ROWS or len(frame) * sum(len(members) for _, members in versions) > MAX_MEMBER_CANDLE_EVALUATIONS:
        dependencies.append("SPECIALIST_COUNCIL_REPLAY_COMPUTE_BUDGET_EXCEEDED")
    if policy["broker_position_mode"] == "netting" and policy["opposite_position_policy"] == "hedge":
        dependencies.append("BROKER_NETTING_OPPOSITE_OWNERSHIP_UNSUPPORTED")
    for version, members in versions:
        for member in members:
            requirements = member.get("execution_requirements", {}) or {}
            precision = member.get("execution_precision", member["horizon"].get("execution_precision", "candle"))
            if requirements.get("second_scalp"):
                dependencies.append("SECOND_SCALP_REQUIRES_TICK_ORDER_FILL_AND_LATENCY")
            if requirements.get("tick_execution") or precision in {"tick", "second", "seconds"}:
                dependencies.append("TICK_EXECUTION_REQUIRES_ORDER_FILL_AND_LATENCY")
            for clock_field in ("decision_interval_seconds", "reevaluation_interval_seconds", "max_holding_seconds"):
                if member["horizon"].get(clock_field, int(duration.total_seconds())) < duration.total_seconds():
                    dependencies.append("SPECIALIST_HORIZON_REQUIRES_FINER_EXECUTION_CANDLES")
            if requirements.get("market_depth"):
                dependencies.append("MARKET_DEPTH_EXECUTION_UNAVAILABLE")
            if requirements.get("partial_order_fills"):
                dependencies.append("PARTIAL_ORDER_FILL_REPLAY_UNAVAILABLE")
    runtimes = {
        version: [_compile_member(payload, frame, member, version) for member in members]
        for version, members in versions
    } if not dependencies else {}
    active_version = contract["council_version"]
    active = runtimes.get(active_version, [])
    rows = frame.to_dict("records")
    positions: dict[str, dict] = {}
    receipts: list[dict] = []
    decision_trace: list[dict] = []
    decision_count = 0
    trace_clock = None
    trace_members = {}
    emit_trace = bool(payload.emit_decision_trace)
    account_ledger: list[dict] = []
    position_ledger: list[dict] = []
    trades: list[SimpleTrade] = []
    filled_intents = set()
    cash = float(payload.initial_balance)
    reserved = 0.0
    peak_equity = cash
    max_drawdown = 0.0
    max_daily_loss = 0.0
    gross_profit = gross_loss = 0.0
    fees = carry_total = embedded_costs = 0.0
    equity_curve = [cash]
    day = None
    day_equity = cash
    halted_reason = "external_emergency_stop" if policy.get("emergency_stop") is True else None
    upgrade_index = 0
    worst_gross_exposure = worst_total_risk = max_reserved = 0.0

    def marked_equity(price: float, timestamp: object) -> float:
        value = cash
        for pos in positions.values():
            direction = 1 if pos["direction"] == "BUY" else -1
            exit_price = kernel._exit_price(price, pos["direction"], pos["runtime"].payload)
            value += pos["units"] * (exit_price - pos["entry_price"]) * direction
            value -= pos["notional"] * pos["runtime"].payload.execution.commission_percent / 200
            holding = max(0.0, (_utc(timestamp) - _utc(pos["entry_time"])).total_seconds() / 86400)
            value -= pos["notional"] * pos["carry_rate"] / 100 * holding
        return value

    def event(runtime: MemberRuntime | None, timestamp: object, stage: str, reason: str, **detail) -> None:
        item = {"time": _stamp(timestamp), "stage": stage, "reason": reason, **detail}
        if runtime:
            runtime.stages[f"{stage}:{reason}"] += 1
            item.update({"specialist_id": runtime.identity, "role": runtime.declaration["role"],
                         "council_version": runtime.council_version,
                         "member_version_hash": runtime.version_hash})
        receipts.append(item)
        # Observe the actual pre-entry branch. Intrabar exits and outcomes
        # retain their separate execution ledger and cannot leak into inputs.
        if trace_clock is not None and runtime is not None:
            observed = trace_members.get(runtime.version_hash)
            if observed is not None and (stage in {'intent', 'risk', 'operator'}
                    or (stage == 'execution' and reason in {'filled', 'duplicate_intent'})):
                observed['stage_receipts'].append({'stage': stage, 'reason': reason,
                    'detail': {key: kernel._semantic_event_value(value) for key, value in detail.items()}})
                if stage == 'risk' or reason in {'decision_cadence_wait', 'expired', 'duplicate_intent'}:
                    observed.update({'action': 'WAIT', 'accepted': False, 'rejection_code': reason})
                elif stage == 'execution' and reason == 'filled':
                    observed.update({'action': observed['raw_signal'], 'accepted': True,
                        'rejection_code': None, 'position_id': detail['position_id']})
                    trace_clock['action'] = (observed['raw_signal'] if trace_clock['action'] == 'WAIT'
                        else trace_clock['action'] if trace_clock['action'] == observed['raw_signal'] else 'MIXED')
                    trace_clock.update({'accepted': True, 'rejection_code': None})

    def close_position(pos: dict, market_exit: float, execution_exit: float, reason: str, timestamp: object, index: int) -> None:
        nonlocal cash, reserved, fees, carry_total, embedded_costs, gross_profit, gross_loss
        runtime = pos["runtime"]
        direction = 1 if pos["direction"] == "BUY" else -1
        fraction = float(pos.get("partial_fraction", 0)) if pos.get("partial_closed") else 0.0
        market_pnl = pos["realized_partial_gross"] + pos["units"] * (execution_exit - pos["entry_price"]) * direction
        holding = max(0.0, (_utc(timestamp) - _utc(pos["entry_time"])).total_seconds() / 86400)
        carry = pos["notional"] * pos["carry_rate"] / 100 * holding
        exit_fee = pos["notional"] * runtime.payload.execution.commission_percent / 200
        total_fees = pos["entry_fee"] + pos["partial_exit_fees"] + exit_fee
        total_carry = pos["partial_carry"] + carry
        net_pnl = market_pnl - total_fees - total_carry
        cash += market_pnl - pos["realized_partial_gross"] - exit_fee - carry
        fees += exit_fee
        carry_total += carry
        reserved -= pos["reserved_capital"]
        if abs(reserved) < 1e-9:
            reserved = 0.0
        del positions[pos["position_id"]]
        actual_embedded = (pos["initial_units"] * abs(pos["entry_price"] - pos["market_entry_price"])
            + pos["partial_embedded_cost"] + pos["units"] * abs(execution_exit - market_exit))
        embedded_costs += actual_embedded
        percent = net_pnl / pos["entry_equity"] * 100
        if net_pnl > 0:
            gross_profit += net_pnl
            runtime.loss_streak = 0
        else:
            gross_loss += abs(net_pnl)
            runtime.loss_streak += 1
            runtime.cooldown_until = index + int(runtime.payload.parameters.get("loss_cooldown_candles", 0) or 0)
            if runtime.loss_streak >= int(runtime.payload.parameters.get("max_loss_streak_before_wait", 99) or 99):
                runtime.loss_wait_until = index + kernel._loss_streak_wait_duration(runtime.payload)
        runtime.closed_returns[str(pos["signal_row"].get("market_regime", "unknown"))].append(percent)
        runtime.meta_returns[kernel._risk_context(pos["signal_row"], pos["direction"])].append(percent)
        kernel._record_confidence_observation(runtime.confidence_history, pos["signal_row"], pos["direction"], percent)
        trade = SimpleTrade(
            direction=pos["direction"], entry_time=_stamp(pos["entry_time"]), exit_time=_stamp(timestamp),
            entry_price=pos["entry_price"], exit_price=execution_exit,
            stop_loss=pos["initial_stop_loss"], take_profit=pos["take_profit"],
            result="WIN" if net_pnl > 0 else "LOSS", profit_percent=percent,
            gross_profit_percent=market_pnl / pos["entry_equity"] * 100,
            execution_cost_percent=(total_fees + total_carry + actual_embedded) / pos["entry_equity"] * 100,
            market_profit_percent=market_pnl / max(pos["initial_notional"], 1e-12) * 100,
            position_size_multiple=pos["initial_notional"] / pos["entry_equity"],
            risk_budget_percent=runtime.declaration["risk_per_trade_percent"],
            signal_time=_stamp(pos["signal_row"]["time"]),
            signal_confidence=float(pos["signal_row"].get("signal_confidence", 1.0) or 0.0),
            exit_reason=("partial_target+" if fraction else "") + reason,
            balance=cash, market_regime=str(pos["signal_row"].get("market_regime", "unknown")),
            volatility_regime=str(pos["signal_row"].get("volatility_regime", "normal_volatility")),
            portfolio_member=runtime.identity, initial_risk_distance=pos["initial_risk_distance"],
            initial_risk_percent=pos["initial_risk_amount"] / pos["entry_equity"] * 100,
        )
        trades.append(trade)
        ledger = {
            "position_id": pos["position_id"], "specialist_id": runtime.identity,
            "role": runtime.declaration["role"], "council_version": runtime.council_version,
            "member_version_hash": runtime.version_hash,
            "management_owner": runtime.identity,
            "management_version": runtime.declaration["management_version"],
            "strategy_version": runtime.declaration["strategy_version"],
            "tactic_version": runtime.declaration["tactic_version"],
            "model_version_id": runtime.declaration.get("model_version_id"),
            "passport_hash": runtime.declaration.get("passport_hash"),
            "entry_time": _stamp(pos["entry_time"]), "exit_time": _stamp(timestamp),
            "direction": pos["direction"], "units": pos["initial_units"], "notional": pos["initial_notional"],
            "gross_pnl_after_embedded_cost": market_pnl, "net_pnl": net_pnl,
            "fees": total_fees, "carry": total_carry,
            "spread_slippage": actual_embedded, "reserved_capital_released": pos["initial_reserved_capital"],
            "partial": pos["partial_events"],
            "exit_reason": reason,
            "outcome_matured": reason != "end_of_data",
            "holding_seconds": max(0.0, (_utc(timestamp) - _utc(pos["entry_time"])).total_seconds()),
            "management_deadline": _stamp(pos["management_deadline"]) if pos["management_deadline"] is not None else None,
            "gap_overrun_seconds": max(0.0, (_utc(timestamp) - pos["management_deadline"]).total_seconds()) if pos["management_deadline"] is not None else 0.0,
        }
        position_ledger.append(ledger)
        event(runtime, timestamp, "execution", "closed", position_id=pos["position_id"], exit_reason=reason, net_pnl=net_pnl)

    for index in range(evaluation_start_index + 1, len(rows)):
        if dependencies:
            break
        candle = rows[index]
        timestamp = candle["time"]
        # Releases activate for decisions observed after their sealed boundary.
        signal_at = _utc(rows[index - 1]["time"]) + duration
        while upgrade_index < len(contract.get("upgrades", [])):
            upgrade = contract["upgrades"][upgrade_index]
            if _utc(upgrade["effective_at"]) > signal_at:
                break
            active_version = upgrade["council_version"]
            active = runtimes[active_version]
            upgrade_index += 1
            event(None, timestamp, "release", "activated", council_version=active_version,
                  effective_at=upgrade["effective_at"], pinned_open_positions=list(positions))
        open_price = float(candle["open"])
        opening_equity = marked_equity(open_price, timestamp)
        decision_count += 1
        trace_clock, trace_members = None, {}
        if emit_trace:
            closed_source, source_input_hash, source_columns = _closed_trace_input(rows[index - 1], PRIMARY_TRACE_FIELDS, kernel)
            source_clock = {'contract_hash': contract['contract_hash'], 'dataset_hash': payload.replay_dataset_hash,
                'source_sha256': attestation.get('actual_source_sha256', ''), 'candle_index': index,
                'signal_time': _stamp(rows[index - 1]['time']), 'decision_at': _stamp(signal_at),
                'execution_time': _stamp(timestamp)}
            trace_clock = {'candle_index': index, 'candle_time': _stamp(timestamp),
                'signal_time': source_clock['signal_time'], 'decision_at': source_clock['decision_at'],
                'execution_time': source_clock['execution_time'], 'decision_id': canonical_hash(source_clock),
                'source_clock': source_clock, 'event_type': 'signal_evaluation', 'action': 'WAIT',
                'accepted': False, 'rejection_code': 'no_native_member_fill', 'price': open_price,
                'features': closed_source, 'closed_source_inputs_hash': source_input_hash,
                'closed_source_input_columns': source_columns,
                'state': {'cash_at_open': cash, 'equity_at_open': opening_equity,
                    'reserved_capital_at_open': reserved, 'owned_positions_at_open': list(positions),
                    'council_version': active_version}, 'member_decisions': []}
            decision_trace.append(trace_clock)
        if day != _utc(timestamp).date():
            day, day_equity = _utc(timestamp).date(), opening_equity
        peak_equity = max(peak_equity, opening_equity)
        drawdown = (peak_equity - opening_equity) / max(peak_equity, 1e-12) * 100
        daily_loss = (day_equity - opening_equity) / max(day_equity, 1e-12) * 100
        max_daily_loss = max(max_daily_loss, daily_loss)
        hard_reason = None
        if drawdown >= policy["max_drawdown_percent"]:
            hard_reason = "hard_drawdown"
        elif daily_loss >= policy["max_daily_loss_percent"]:
            hard_reason = "hard_daily_loss"
        elif positions and sum(pos["units"] * open_price for pos in positions.values()) > opening_equity * min(policy["max_gross_exposure_percent"], payload.execution.max_leverage * 100) / 100 + 1e-8:
            hard_reason = "hard_gross_exposure"
        elif positions and sum(pos["risk_amount"] for pos in positions.values()) > opening_equity * policy["max_total_risk_percent"] / 100 + 1e-8:
            hard_reason = "hard_account_risk"
        if hard_reason:
            halted_reason = hard_reason
            for pos in list(positions.values()):
                close_position(pos, open_price, kernel._exit_price(open_price, pos["direction"], pos["runtime"].payload),
                               halted_reason, timestamp, index)
            event(None, timestamp, "risk", halted_reason)

        # Only information available at this open may release capital for new
        # entries. Intrabar exits settle after simultaneous reservations below.
        for pos in list(positions.values()):
            runtime = pos["runtime"]
            held = index - pos["entry_index"]
            prior = runtime.rows[index - 1]
            horizon = runtime.declaration["horizon"]
            reason = None
            management = kernel.effective_management_parameters(runtime.payload.composition_runtime_contract, runtime.payload.parameters)
            time_stop = int(management.get("time_stop_candles", 0) or 0)
            reevaluation_seconds = horizon.get("reevaluation_interval_seconds")
            reevaluation_due = (_utc(timestamp) >= pos["last_reevaluation_at"] + pd.Timedelta(seconds=reevaluation_seconds)
                if reevaluation_seconds else held and held % horizon["reevaluation_interval_bars"] == 0)
            if pos["management_deadline"] is not None and _utc(timestamp) >= pos["management_deadline"]:
                reason = "max_holding_deadline"
            elif held >= min(horizon["max_holding_bars"], time_stop or horizon["max_holding_bars"]):
                reason = "max_holding"
            elif reevaluation_due and "manage_owned_position" in runtime.declaration.get("allowed_actions", ["manage_owned_position"]):
                pos["last_reevaluation_at"] = _utc(timestamp)
                event(runtime, timestamp, "management", "reevaluated", position_id=pos["position_id"])
                kernel._advance_trailing_stop(pos, prior, runtime.payload)
                if runtime.operator and runtime.operator.target == "exit":
                    decision = runtime.operator.evaluate(prior, decision_at=_stamp(signal_at), observed_at=_stamp(signal_at))
                    if decision:
                        runtime.operator.changed_decisions += 1
                        reason = "operator_exit"
                if bool(runtime.declaration.get("exit_on_opposite_signal", False)) and prior.get("signal") in {"BUY", "SELL"} and prior["signal"] != pos["direction"]:
                    reason = "opposite_signal"
            # Gaps are known at the open; canonical kernel must prefer them
            # before an operator/time exit can improve their execution price.
            gap_price, gap_reason = kernel._intrabar_exit(pos["direction"], pos,
                {**candle, "high": open_price, "low": open_price}, runtime.payload)
            if gap_reason:
                close_position(pos, open_price, gap_price, gap_reason, timestamp, index)
            elif reason:
                close_position(pos, open_price, kernel._exit_price(open_price, pos["direction"], runtime.payload), reason, timestamp, index)

        for runtime in active:
            member = runtime.declaration
            prior = runtime.rows[index - 1]
            if trace_clock is not None:
                closed_inputs, input_hash, input_columns = _closed_trace_input(prior, MEMBER_TRACE_FIELDS, kernel)
                observed = {'decision_id': canonical_hash({'account_decision_id': trace_clock['decision_id'],
                        'member_version_hash': runtime.version_hash}),
                    'specialist_id': runtime.identity, 'council_version': runtime.council_version,
                    'member_version_hash': runtime.version_hash, 'raw_signal': str(prior.get('signal', 'WAIT')),
                    'action': 'WAIT', 'accepted': False, 'rejection_code': 'no_signal',
                    'closed_inputs': closed_inputs, 'closed_inputs_hash': input_hash,
                    'closed_input_columns': input_columns,
                    'stage_receipts': []}
                trace_members[runtime.version_hash] = observed
                trace_clock['member_decisions'].append(observed)
            kernel._temporal_update_pending(runtime.temporal_state, prior, index - 1, runtime.payload)
            if runtime.loss_wait_until >= 0 and index >= runtime.loss_wait_until:
                runtime.loss_streak, runtime.loss_wait_until = 0, -1
            signal = str(prior.get("signal", "WAIT"))
            decision_seconds = member["horizon"].get("decision_interval_seconds")
            cadence_wait = (runtime.last_decision_at is not None and signal_at < runtime.last_decision_at + pd.Timedelta(seconds=decision_seconds)
                if decision_seconds else bool((index - 1) % member["horizon"]["decision_interval_bars"]))
            if cadence_wait:
                if signal in {"BUY", "SELL"}:
                    event(runtime, timestamp, "intent", "decision_cadence_wait")
                continue
            runtime.last_decision_at = signal_at
            runtime.stages["decision:observed"] += 1
            if signal not in {"BUY", "SELL"}:
                reasons = (kernel._instrument_observed_value(prior.get(key)) for key in (
                    'volume_policy_rejection', 'specialist_scope_first_veto', 'composition_decision_reason', 'entry_contract_status'))
                no_signal_reason = next((str(value) for value in reasons if value), 'no_signal')
                runtime.stages[f"intent:{no_signal_reason}"] += 1
                if trace_clock is not None:
                    trace_members[runtime.version_hash]['rejection_code'] = no_signal_reason
                continue
            intent_id = canonical_hash({"council_version": runtime.council_version,
                "member_version_hash": runtime.version_hash, "signal_at": _stamp(signal_at),
                "direction": signal, "symbol": payload.symbol})
            if intent_id in filled_intents:
                event(runtime, timestamp, "execution", "duplicate_intent")
                continue
            event(runtime, timestamp, "intent", "created", intent_id=intent_id,
                  direction=signal, observed_at=_stamp(signal_at),
                  expires_at=_stamp(signal_at + duration),
                  capital_weight=member["capital_weight"], management_owner=runtime.identity,
                  management_version=member["management_version"], max_holding_bars=member["horizon"]["max_holding_bars"])
            if _utc(timestamp) > signal_at + duration:
                event(runtime, timestamp, "intent", "expired")
                continue
            reason = None
            if halted_reason:
                reason = halted_reason
            elif "propose_trade" not in member.get("allowed_actions", ["propose_trade"]):
                reason = "passport_trade_permission_missing"
            elif _passport_scope_reason(member, runtime.payload, prior):
                reason = _passport_scope_reason(member, runtime.payload, prior)
            elif any(pos["runtime"].identity == runtime.identity for pos in positions.values()):
                reason = "owner_position_open"
            elif len(positions) >= policy["max_open_positions"]:
                reason = "max_open_positions"
            elif any(pos["direction"] != signal for pos in positions.values()) and policy["opposite_position_policy"] == "reject":
                reason = "opposite_position_rejected"
            if reason:
                event(runtime, timestamp, "risk", reason)
                continue
            scope_allowed, _owners = kernel._instrument_owner_scope_allows(runtime.instrument_state, prior, signal, runtime.identity)
            if not scope_allowed:
                event(runtime, timestamp, "risk", "instrument_context_outside_scope")
                continue
            mtf_policy = kernel.apply_signal_policy(signal, prior, runtime.payload.mtf_pilot,
                prior.get("decision_at", prior.get("time")))
            if mtf_policy.get("decision") != signal:
                event(runtime, timestamp, "risk", "mtf_" + str(mtf_policy.get("reason", "permission_veto")))
                continue
            edge_contracts = runtime.payload.policy_context.get("edge_genesis_contracts", {}) or {}
            edge = edge_contracts.get(runtime.payload.strategy, {}) if isinstance(edge_contracts, dict) else {}
            edge_allowed, edge_reason, _context = kernel._edge_context_admission(prior, edge, direction=signal)
            if not edge_allowed:
                event(runtime, timestamp, "risk", str(edge_reason))
                continue
            temporal_metrics = kernel._temporal_context_metrics(prior, {**candle, "high": open_price, "low": open_price},
                runtime.temporal_state, index, runtime.payload)
            temporal = kernel._temporal_survival_assessment(prior, temporal_metrics, runtime.payload,
                runtime.loss_streak, runtime.temporal_state)
            kernel._temporal_register_signal(runtime.temporal_state, prior, signal, index - 1, runtime.payload)
            kernel._temporal_commit_features(runtime.temporal_state, temporal_metrics, prior)
            confidence = kernel._confidence_assessment(prior, signal, runtime.confidence_history, runtime.payload, candle)
            eligible, veto = kernel._entry_eligibility(candle, runtime.payload, prior,
                runtime.loss_streak, index < runtime.cooldown_until, runtime.closed_returns, runtime.meta_returns,
                loss_streak_wait_active=index < runtime.loss_wait_until,
                confidence_assessment=confidence, temporal_assessment=temporal)
            if not eligible:
                event(runtime, timestamp, "risk", str(veto or "member_veto"))
                continue
            multiplier = 1.0
            if runtime.operator and runtime.operator.target in {"confirmation", "risk_multiplier"}:
                output = runtime.operator.evaluate(prior, decision_at=_stamp(signal_at), observed_at=_stamp(signal_at))
                event(runtime, timestamp, "operator", "evaluated", target=runtime.operator.target, output=output)
                if runtime.operator.target == "confirmation" and not output:
                    runtime.operator.changed_decisions += 1
                    event(runtime, timestamp, "risk", "operator_confirmation_veto")
                    continue
                if runtime.operator.target == "risk_multiplier":
                    multiplier = float(output)
                    if multiplier != 1.0:
                        runtime.operator.changed_decisions += 1
                    if not multiplier:
                        event(runtime, timestamp, "risk", "operator_zero_risk")
                        continue
            entry_price = kernel._entry_price(open_price, signal, runtime.payload)
            stop_distance, target_distance = kernel._exit_distances(open_price, prior, runtime.payload)
            stop = open_price - stop_distance if signal == "BUY" else open_price + stop_distance
            target = open_price + target_distance if signal == "BUY" else open_price - target_distance
            equity = marked_equity(open_price, timestamp)
            multiple = (kernel._position_size_multiple(entry_price, stop, signal, runtime.payload)
                * kernel._volatility_risk_multiplier(prior, runtime.payload)
                * kernel._regime_specific_risk_multiplier(prior, runtime.payload)
                * kernel._volume_risk_multiplier(prior)
                * kernel._meta_risk_multiplier(prior, signal, runtime.payload, runtime.meta_returns)
                * float(mtf_policy.get("risk_multiplier", 1.0) or 1.0) * multiplier)
            # Specialist allocation caps margin; learned sizing cannot enlarge
            # the immutable per-member budget or the account leverage boundary.
            multiple = min(multiple, member["capital_weight"] * payload.execution.max_leverage,
                payload.execution.max_leverage)
            notional = max(0.0, equity * multiple)
            margin = notional / payload.execution.max_leverage
            entry_fee = notional * payload.execution.commission_percent / 200
            risk = equity * kernel._initial_executable_risk_percent(entry_price, stop, signal, runtime.payload, multiple) / 100
            total_risk = sum(pos["risk_amount"] for pos in positions.values()) + risk
            gross = sum(pos["units"] * open_price for pos in positions.values()) + notional / entry_price * open_price
            rates = policy.get("carry_per_day_percent_by_direction", {}) or {}
            carry_rate = float(rates.get(signal, payload.execution.swap_per_day_percent))
            cost_percent = ((payload.execution.spread_points * payload.execution.point_size
                + 2 * payload.execution.slippage_points * payload.execution.point_size) / max(open_price, 1e-12) * 100
                + payload.execution.commission_percent
                + carry_rate * member["horizon"]["max_holding_bars"] * duration.total_seconds() / 86400)
            reason = None
            if equity <= 0 or notional <= 0:
                reason = "capital_unavailable"
            elif risk > equity * member["risk_per_trade_percent"] / 100 + 1e-8:
                reason = "member_hard_risk_limit"
            elif reserved + margin + entry_fee > equity * policy["max_reserved_capital_percent"] / 100 + 1e-8:
                reason = "capital_reservation_limit"
            elif gross > equity * min(policy["max_gross_exposure_percent"], payload.execution.max_leverage * 100) / 100 + 1e-8:
                reason = "gross_exposure_limit"
            elif total_risk > equity * policy["max_total_risk_percent"] / 100 + 1e-8:
                reason = "account_hard_risk_limit"
            elif cost_percent > policy["max_expected_cost_percent"]:
                reason = "cost_limit"
            if reason:
                event(runtime, timestamp, "risk", reason)
                continue
            # Reservation and fill share one serialized account operation.
            # Later specialists see the already-reserved margin and paid fee.
            reserved += margin
            cash -= entry_fee
            fees += entry_fee
            filled_intents.add(intent_id)
            management = kernel.effective_management_parameters(runtime.payload.composition_runtime_contract, runtime.payload.parameters)
            pos = {
                "position_id": intent_id, "runtime": runtime, "direction": signal,
                "signal_row": prior, "entry_time": timestamp, "entry_index": index,
                "last_reevaluation_at": _utc(timestamp),
                "management_deadline": (_utc(timestamp) + pd.Timedelta(seconds=member["horizon"]["max_holding_seconds"])) if member["horizon"].get("max_holding_seconds") else None,
                "entry_price": entry_price, "market_entry_price": open_price,
                "stop_loss": stop, "initial_stop_loss": stop, "take_profit": target,
                "entry_equity": equity, "notional": notional, "units": notional / entry_price,
                "initial_notional": notional, "initial_units": notional / entry_price,
                "reserved_capital": margin, "entry_fee": entry_fee,
                "initial_reserved_capital": margin,
                "risk_amount": risk, "initial_risk_amount": risk, "carry_rate": carry_rate,
                "realized_partial_gross": 0.0, "partial_exit_fees": 0.0,
                "partial_carry": 0.0, "partial_embedded_cost": 0.0, "partial_events": [],
                "position_size_multiple": multiple, "initial_risk_distance": abs(entry_price - stop),
                "partial_fraction": float(management.get("partial_take_profit_fraction", 0) or 0),
                "partial_closed": False, "partial_exit_price": None,
                "maximum_favorable_excursion": 0.0, "maximum_adverse_excursion": 0.0,
            }
            positions[intent_id] = pos
            max_reserved = max(max_reserved, reserved)
            worst_gross_exposure = max(worst_gross_exposure, gross)
            worst_total_risk = max(worst_total_risk, total_risk)
            event(runtime, timestamp, "execution", "filled", intent_id=intent_id,
                position_id=intent_id, entry_price=entry_price, reserved_capital=margin,
                risk_amount=risk, units=pos["units"], expected_cost_percent=cost_percent,
                initial_stop_loss=stop, initial_take_profit=target)

        for pos in list(positions.values()):
            runtime = pos["runtime"]
            kernel._update_position_excursions(pos, candle)
            exit_price, reason = kernel._intrabar_exit(pos["direction"], pos, candle, runtime.payload)
            if reason:
                # Use the known market stop/target for embedded cost reporting.
                market_exit = pos["stop_loss"] if "stop" in reason else pos["take_profit"]
                close_position(pos, market_exit, exit_price, reason, timestamp, index)
            elif kernel._take_partial_profit(pos, {**candle, "_management_atr": runtime.rows[index - 1].get("_management_atr", 0)}, runtime.payload):
                fraction = float(pos["partial_fraction"])
                closed_units = pos["units"] * fraction
                closed_notional = pos["notional"] * fraction
                partial_exit = float(pos["partial_exit_price"])
                direction = 1 if pos["direction"] == "BUY" else -1
                partial_gross = closed_units * (partial_exit - pos["entry_price"]) * direction
                partial_fee = closed_notional * runtime.payload.execution.commission_percent / 200
                holding = max(0.0, (_utc(timestamp) + duration - _utc(pos["entry_time"])).total_seconds() / 86400)
                partial_carry = closed_notional * pos["carry_rate"] / 100 * holding
                cash += partial_gross - partial_fee - partial_carry
                fees += partial_fee
                carry_total += partial_carry
                released = pos["reserved_capital"] * fraction
                reserved -= released
                pos["reserved_capital"] -= released
                pos["units"] -= closed_units
                pos["notional"] -= closed_notional
                pos["risk_amount"] *= 1 - fraction
                pos["realized_partial_gross"] += partial_gross
                pos["partial_exit_fees"] += partial_fee
                pos["partial_carry"] += partial_carry
                embed_per_unit = runtime.payload.execution.spread_points * runtime.payload.execution.point_size / 2 + runtime.payload.execution.slippage_points * runtime.payload.execution.point_size
                pos["partial_embedded_cost"] += closed_units * embed_per_unit
                pos["partial_events"].append({"fraction": fraction, "exit_price": partial_exit,
                    "exit_time": _stamp(_utc(timestamp) + duration), "units": closed_units,
                    "gross_pnl": partial_gross, "exit_fee": partial_fee, "carry": partial_carry,
                    "reserved_capital_released": released})
                event(runtime, timestamp, "management", "partial_profit_recorded", position_id=pos["position_id"],
                      fraction=fraction, units=closed_units, reserved_capital_released=released)

        equity = marked_equity(float(candle["close"]), _utc(timestamp) + duration)
        peak_equity = max(peak_equity, equity)
        max_drawdown = max(max_drawdown, (peak_equity - equity) / max(peak_equity, 1e-12) * 100)
        max_daily_loss = max(max_daily_loss, (day_equity - equity) / max(day_equity, 1e-12) * 100)
        max_reserved = max(max_reserved, reserved)
        exposure = sum(pos["units"] * float(candle["close"]) for pos in positions.values())
        total_risk = sum(pos["risk_amount"] for pos in positions.values())
        worst_gross_exposure = max(worst_gross_exposure, exposure)
        worst_total_risk = max(worst_total_risk, total_risk)
        signed_units = sum(pos["units"] * (1 if pos["direction"] == "BUY" else -1) for pos in positions.values())
        account_ledger.append({"time": _stamp(_utc(timestamp) + duration), "cash": cash,
            "equity": equity, "reserved_capital": reserved, "free_capital": equity - reserved,
            "gross_exposure": exposure, "net_units": signed_units,
            "risk_amount": total_risk, "open_positions": len(positions),
            "council_version": active_version,
            "owners": [pos["runtime"].identity for pos in positions.values()],
            "broker_position_mode": policy["broker_position_mode"]})
        equity_curve.append(equity)

    # Replay-end settlement uses the last observed close, never a later candle.
    last = rows[-1]
    ending = _utc(last["time"]) + duration
    force_close_count = len(positions)
    for pos in list(positions.values()):
        market_exit = float(last["close"])
        close_position(pos, market_exit, kernel._exit_price(market_exit, pos["direction"], pos["runtime"].payload),
                       "end_of_data", ending, len(rows) - 1)
    equity_curve[-1] = cash
    if account_ledger:
        account_ledger[-1].update({"cash": cash, "equity": cash, "free_capital": cash,
            "reserved_capital": reserved, "gross_exposure": 0.0, "net_units": 0.0,
            "risk_amount": 0.0, "open_positions": 0, "owners": [],
            "settlement": "replay_end", "force_close_count": force_close_count})
    by_role = defaultdict(Counter)
    member_receipts = []
    for version_members in runtimes.values():
        for runtime in version_members:
            by_role[runtime.declaration["role"]].update(runtime.stages)
            member_receipts.append({"specialist_id": runtime.identity, "role": runtime.declaration["role"],
                "council_version": runtime.council_version, "member_version_hash": runtime.version_hash,
                "strategy_version": runtime.declaration["strategy_version"], "tactic_version": runtime.declaration["tactic_version"],
                "management_version": runtime.declaration["management_version"],
                "model_version_id": runtime.declaration.get("model_version_id"),
                "symbol": runtime.payload.symbol,
                "passport_hash": runtime.declaration.get("passport_hash"),
                "execution_precision": runtime.declaration.get("execution_precision", runtime.declaration["horizon"].get("execution_precision", "candle")),
                "known_limits": runtime.declaration.get("known_limits", []),
                "data_requirements": runtime.declaration.get("data_requirements", []),
                "scope": runtime.declaration.get("scope", {}),
                "allowed_actions": runtime.declaration.get("allowed_actions", ["propose_trade", "manage_owned_position", "wait"]),
                "resources": runtime.declaration.get("resources", {}),
                "specialist_scope_receipt": runtime.data_quality.get("specialist_signal_scope", {}),
                "horizon": runtime.declaration["horizon"], "stages": dict(runtime.stages),
                "effective_parameters_hash": canonical_hash(runtime.payload.parameters),
                "source_attestation": runtime.data_quality.get("dataset_attestation", {}),
                "context_source_attestations": runtime.data_quality.get("context_source_attestations", {}),
                "operator_receipt": runtime.operator.receipt() if runtime.operator else {"status": "not_bound"}})
    if dependencies:
        for version, declarations in versions:
            for declaration in declarations:
                stages = {"decision:observed": 0, "dependency:unavailable": 1}
                by_role[declaration["role"]].update(stages)
                member_receipts.append({"specialist_id": declaration["specialist_id"], "role": declaration["role"],
                    "council_version": version,
                    "member_version_hash": canonical_hash({"council_version": version, "member": declaration}),
                    "strategy_version": declaration["strategy_version"], "tactic_version": declaration["tactic_version"],
                    "management_version": declaration["management_version"], "horizon": declaration["horizon"],
                    "stages": stages, "status": "dependency", "dependency_reasons": sorted(set(dependencies)),
                    "source_attestation": attestation, "context_source_attestations": {},
                    "operator_receipt": {"status": "not_executed"}})
    net_ledger = sum(item["net_pnl"] for item in position_ledger)
    reconciliation_error = cash - payload.initial_balance - net_ledger
    if abs(reconciliation_error) > 1e-7 * max(1.0, payload.initial_balance):
        raise ValueError("SPECIALIST_COUNCIL_ACCOUNT_RECONCILIATION_FAILED")
    decision_trace = _receipt_json_value(decision_trace)
    trace_identity = {'protocol': 'native_council_decision_trace_v1', 'contract_hash': contract['contract_hash'],
        'trace_hash': canonical_hash(decision_trace), 'source_rows': len(rows), 'decision_rows': decision_count,
        'warmup_rows': warmup_rows, 'first_candle_index': evaluation_start_index + 1,
        'last_candle_index': len(rows) - 1 if decision_count else None, 'scope_policy_hash': evaluated_scope['policy_hash']}
    receipt_body = {
        "protocol": RECEIPT_PROTOCOL, "contract_hash": contract["contract_hash"],
        "council_id": contract["council_id"], "council_version": contract["council_version"],
        "final_council_version": active_version, "dataset_hash": payload.replay_dataset_hash,
        "execution_hash": execution_contract_metadata(payload)["execution_hash"],
        "execution_timeframe": payload.timeframe, "replay_start": evaluated_scope["start_inclusive"],
        "replay_end": _stamp(ending), "source_rows": len(rows),
        "source_attestation": attestation, "evaluated_scope": evaluated_scope,
        "decision_trace_identity": trace_identity,
        "asof_policy": "previous_closed_candle_next_open",
        "status": "dependency" if dependencies else "computed",
        "dependency_reasons": sorted(set(dependencies)), "members": member_receipts,
        "role_stage_reasons": {role: dict(counts) for role, counts in sorted(by_role.items())},
        "intent_execution_ledger": receipts, "position_ledger": position_ledger,
        "account_ledger": account_ledger,
        "account": {"initial_balance": payload.initial_balance, "final_balance": cash,
            "reserved_capital": reserved, "open_positions": len(positions),
            "fees": fees, "carry": carry_total, "spread_slippage": embedded_costs,
            "net_pnl": net_ledger, "reconciliation_error": reconciliation_error,
            "max_reserved_capital": max_reserved, "max_gross_exposure": worst_gross_exposure,
            "max_total_risk": worst_total_risk, "halted_reason": halted_reason,
            "same_open_allocation_order": "sealed_member_order",
            "broker_position_mode": policy["broker_position_mode"],
            "opposite_position_policy": policy["opposite_position_policy"],
            "broker_reconciliation": "internal_replay_only_no_broker_fills"},
        "external_risk_envelope": {key: maximum for key, (_minimum, maximum) in POLICY_LIMITS.items()},
        "clock_semantics": "deadline_exit_at_first_executable_candle_gap_overrun_recorded",
        "execution_capabilities": {"candle_execution": True, "tick_execution": False,
            "latency_evidence": False, "market_depth": False, "partial_order_fills": False,
            "broker_rollover_calendar": False,
            "carry_model": "sealed_directional_rate_elapsed_utc_days",
            "time_precision": "candle_open_labels_intrabar_order_unknown"},
        "metrics": {"initial_capital": payload.initial_balance, "net_profit": net_ledger,
            "total_costs": fees + carry_total + embedded_costs,
            "total_trades": len(trades),
            "matured_trades": sum(item["outcome_matured"] for item in position_ledger),
            "censored_trades": sum(not item["outcome_matured"] for item in position_ledger),
            "max_drawdown_percent": max_drawdown, "max_daily_loss_percent": max_daily_loss,
            "max_gross_exposure_percent": worst_gross_exposure / payload.initial_balance * 100,
            "max_total_risk_percent": worst_total_risk / payload.initial_balance * 100},
        "scientific_evidence": False, "research_only": True, "promotion_evidence": False,
        "independent_evaluation": "required",
    }
    receipt_body = _receipt_json_value(receipt_body)
    receipt = {**receipt_body, "receipt_hash": canonical_hash(receipt_body), "receipt_json": canonical_json(receipt_body)}
    wins = sum(trade.result == "WIN" for trade in trades)
    stages = Counter()
    for item in receipts:
        stages[f"{item['stage']}:{item['reason']}"] += 1
    data_quality = dict(frame.attrs.get("data_quality") or {})
    data_quality.update({"dataset_attestation": attestation, "research_release_receipt": release_receipt,
        "specialist_council_receipt": receipt, "replay_evaluation_scope": evaluated_scope})
    data_quality['decision_trace'] = {'protocol': 'candle_decision_trace_v1', 'requested': emit_trace,
        'complete': emit_trace and not dependencies and decision_count == evaluated_scope['decision_rows'],
        'event_count': len(decision_trace), 'evaluated_candle_count': decision_count,
        'input_candle_count': len(rows), 'warmup_rows': warmup_rows,
        'first_candle_index': evaluation_start_index + 1, 'evaluated_scope': evaluated_scope,
        'scope_owner': 'native_specialist_council_v1', 'trace_hash': trace_identity['trace_hash'],
        'promotion_evidence': False}
    if probe_receipt is not None:
        data_quality["prospective_probe_window_receipt"] = probe_receipt
    response = SimpleBacktestResponse(
        strategy=payload.strategy, parameters=payload.parameters, instrument=payload.symbol,
        timeframe=payload.timeframe, period=f"{evaluated_scope['start_inclusive']} / {_stamp(ending)}",
        initial_balance=payload.initial_balance, final_balance=cash,
        net_profit_percent=(cash / payload.initial_balance - 1) * 100,
        total_trades=len(trades), wins=wins, losses=len(trades) - wins,
        winrate=wins / len(trades) * 100 if trades else 0.0,
        profit_factor=gross_profit / gross_loss if gross_loss else (999.0 if gross_profit else 0.0),
        max_drawdown=max_drawdown, max_drawdown_percent=max_drawdown,
        equity_curve=equity_curve, trades=trades,
        top_mistakes=[], conclusion="Specialist council research replay; independent evaluation is required.",
        execution_assumptions=payload.execution.model_dump(),
        execution_contract=execution_contract_metadata(payload), policy_boundary=policy_boundary,
        specialist_council_receipt=receipt, data_quality=data_quality,
        decision_trace=decision_trace, trade_ledger=trades, displayed_trade_count=len(trades),
        prospective_probe_window_receipt=probe_receipt or {},
        entry_funnel={"strategy_signals": stages["intent:created"],
            "entries_accepted": stages["execution:filled"],
            "risk_governor_rejections": sum(value for key, value in stages.items() if key.startswith("risk:")),
            "rejection_reasons": {key[5:]: value for key, value in stages.items() if key.startswith("risk:")}},
        portfolio_evidence={"protocol": PROTOCOL, "member_count": len(contract["members"]),
                            "shared_account": True, "independent_positions": True,
                            "promotion_evidence": False},
        benchmark={"specialist_council": {"status": receipt["status"],
            "independent_evaluation": "required", "promotion_evidence": False}},
        risk_governor_compliant=not dependencies,
        trade_ledger_hash=canonical_hash(position_ledger),
        event_ledger_hash=canonical_hash(receipts), event_ledger_count=len(receipts),
    )
    response.core_replay_gate = {"passed": not dependencies and bool(trades),
        "status": "dependency" if dependencies else ("computed" if trades else "no_trade"),
        "promotion_evidence": False, "reason_codes": receipt["dependency_reasons"]}
    return response
