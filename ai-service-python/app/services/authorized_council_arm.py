"""One original full-window arm; transport admission is not qualification.

Only a verified server HMAC containing the persisted PHP arm binding selects
this route. Ordinary full/walk-forward and historical discovery are unchanged.
"""
import json
import math

import pandas as pd

from app.schemas import StrategyRuntimeConfig
from app.services.research_program_tasks import canonical_hash, _same_ast_copy

PROTOCOL = "authorized_original_council_arm_v1"
RECEIPT_PROTOCOL = "authorized_original_council_arm_receipt_v1"
MAX_ROWS = 250000
SECONDS = {"M1": 60, "M5": 300, "M15": 900, "M30": 1800, "H1": 3600, "H4": 14400, "D1": 86400}


def finite_trace_json(value):
    """Unavailable diagnostic values stay null in the original JSON ledger."""
    if isinstance(value, dict):
        return {key: finite_trace_json(item) for key, item in value.items()}
    if isinstance(value, list):
        return [finite_trace_json(item) for item in value]
    if isinstance(value, float) and not math.isfinite(value):
        return None
    return value


def original_arm_identity(payload, verified_transport: dict | None) -> dict | None:
    declared = (payload.policy_context or {}).get("specialist_council_authorized_arm")
    signed = (verified_transport or {}).get("original_council_arm")
    if declared is None and signed is None:
        if (payload.mtf_snapshot_manifest or {}).get('validation_bundle_protocol') == 'authorized_original_council_window_bundle_v1':
            raise ValueError('COUNCIL_ARM_ORIGINAL_SIGNED_TRANSPORT_REQUIRED')
        return None
    if not isinstance(declared, dict) or not isinstance(signed, dict):
        raise ValueError("COUNCIL_ARM_ORIGINAL_SIGNED_TRANSPORT_REQUIRED")
    if (signed.get("protocol") != PROTOCOL or signed.get("purpose") != "independent"
            or signed.get("independent_evidence") is not False or signed.get("promotion_evidence") is not False
            or payload.evaluation_mode != "full" or len(payload.strategies) != 1
            or signed.get("generation_id") != verified_transport.get("generation_id")
            or signed.get("window_key") != verified_transport.get("window", {}).get("window_key")):
        raise ValueError("COUNCIL_ARM_ORIGINAL_SCOPE_INVALID")
    for key in ("generation_id", "work_item_id", "model_version_id", "version_id"):
        if type(signed.get(key)) is not int or signed[key] <= 0:
            raise ValueError("COUNCIL_ARM_ORIGINAL_IDENTITY_INVALID")
    try:
        raw = json.loads(signed["strategy_payload_json"])
        policy = json.loads(signed["runtime_policy_json"])
        scope = json.loads(signed["evaluation_scope_json"])
        shared = json.loads(signed["shared_runtime_json"])
    except (KeyError, TypeError, ValueError) as error:
        raise ValueError("COUNCIL_ARM_ORIGINAL_JSON_REQUIRED") from error
    if not all(isinstance(value, dict) for value in (raw, policy, scope, shared)):
        raise ValueError("COUNCIL_ARM_ORIGINAL_JSON_REQUIRED")
    original = StrategyRuntimeConfig.model_validate(raw).model_dump(mode="json")
    actual = payload.strategies[0].model_dump(mode="json")
    binding = original.get("specialist_council_evaluation") or {}
    if (not _same_ast_copy(original, actual)
            or binding.get("protocol") != "specialist_council_evaluation_plan_v1"
            or any(binding.get(key) != signed.get(key) for key in ("version_id", "manifest_hash", "plan_hash", "arm_key"))):
        raise ValueError("COUNCIL_ARM_ORIGINAL_STRATEGY_OR_BINDING_DRIFT")
    for key, value in shared.items():
        actual_value = getattr(payload, key, None)
        if hasattr(actual_value, "model_dump"):
            # ExecutionConfig adds defaults; compare the original executable
            # declaration through the same validator, not a lossy float hash.
            expected = type(actual_value).model_validate(value).model_dump(mode="json")
            actual_value = actual_value.model_dump(mode="json")
        else:
            expected = value
        if not _same_ast_copy(expected, actual_value):
            raise ValueError("COUNCIL_ARM_ORIGINAL_SHARED_RUNTIME_DRIFT:" + key)
    if (not _same_ast_copy(policy, (payload.policy_context or {}).get("full_replay_runtime_policy"))
            or scope.get("policy_hash") != canonical_hash(policy)
            or not _same_ast_copy(scope, declared.get("evaluation_scope"))
            or any(declared.get(key) != signed.get(key) for key in (
                "generation_id", "work_item_id", "reservation_hash", "arm_key", "window_key", "plan_hash", "model_hash"))):
        raise ValueError("COUNCIL_ARM_ORIGINAL_POLICY_DRIFT")
    if (policy.get("protocol") != "specialist_council_original_full_source_v1"
            or policy.get("evaluation_mode") != "full" or policy.get("selection") != "entire_authorized_source"
            or type(policy.get("maximum_source_rows")) is not int or not 2 <= policy["maximum_source_rows"] <= MAX_ROWS
            or type(policy.get("maximum_runtime_seconds")) is not int or not 30 <= policy["maximum_runtime_seconds"] <= 600
            or policy.get("warmup_rows") != 0 or policy.get("no_walk_forward_selection") is not True
            or policy.get("promotion_evidence") is not False
            or not {"M5", "M15", "H1", "H4"}.issubset(verified_transport.get("files") or {})):
        raise ValueError("COUNCIL_ARM_ORIGINAL_FULL_POLICY_UNSUPPORTED")
    primary = (verified_transport.get("files") or {}).get(payload.timeframe)
    if not isinstance(primary, dict):
        raise ValueError("COUNCIL_ARM_ORIGINAL_PRIMARY_REQUIRED")
    measured = measured_scope(payload, primary, policy)
    if measured['rows'] > policy['maximum_source_rows']:
        raise ValueError("COUNCIL_ARM_FULL_ROW_BUDGET_INVALID")
    if not same_scope(scope, measured):
        raise ValueError("COUNCIL_ARM_ORIGINAL_FULL_SCOPE_MISMATCH")
    return {**signed, "original_scope": scope, "original_policy": policy, "primary": primary,
            "transport_files": verified_transport['files']}


def measured_scope(payload, primary: dict, policy: dict) -> dict:
    rows = primary.get("rows")
    if type(rows) is not int or not 2 <= rows <= MAX_ROWS or payload.timeframe not in SECONDS:
        raise ValueError("COUNCIL_ARM_FULL_ROW_BUDGET_INVALID")
    start = pd.Timestamp(primary["start_inclusive"])
    last = pd.Timestamp(primary["last_candle_at"])
    if start.tzinfo is None or last.tzinfo is None:
        raise ValueError("COUNCIL_ARM_FULL_UTC_REQUIRED")
    return {"start_inclusive": start.tz_convert("UTC").isoformat(),
            "end_exclusive": (last.tz_convert("UTC") + pd.Timedelta(seconds=SECONDS[payload.timeframe])).isoformat(),
            "rows": rows, "decision_rows": rows - 1, "warmup_rows": 0,
            "policy_hash": canonical_hash(policy)}


def same_scope(left: dict, right: dict) -> bool:
    try:
        return (all(type(left.get(key)) is int and left[key] == right[key] for key in ("rows", "decision_rows", "warmup_rows"))
                and left.get("policy_hash") == right["policy_hash"]
                and all(pd.Timestamp(left[key]) == pd.Timestamp(right[key]) for key in ("start_inclusive", "end_exclusive")))
    except (KeyError, TypeError, ValueError):
        return False


def result_scope_current(result: dict, identity: dict) -> bool:
    scope = (result.get("data_quality") or {}).get("replay_evaluation_scope")
    receipt = (result.get("data_quality") or {}).get("authorized_original_council_arm")
    return (isinstance(scope, dict) and same_scope(identity["original_scope"], scope)
            and isinstance(receipt, dict) and receipt.get("protocol") == RECEIPT_PROTOCOL
            and all(receipt.get(key) == identity[key] for key in ("plan_hash", "arm_key", "window_key", "model_hash"))
            and receipt.get("independent_evidence") is False and receipt.get("promotion_evidence") is False
            and (bool(result.get('specialist_council_receipt'))
                 or solo_account_current((result.get('data_quality') or {}).get('original_solo_account'), identity)))


def solo_account_current(account: object, identity: dict) -> bool:
    """A missing or changed measured ledger is never replaced by totals."""
    if not isinstance(account, dict):
        return False
    try:
        metrics, ledger, clock = account['metrics'], account['position_ledger'], account['decision_clock']
        required = ('initial_capital', 'net_profit', 'total_costs', 'commission', 'carry', 'spread', 'slippage',
                    'max_drawdown_percent', 'max_daily_loss_percent', 'max_gross_exposure_percent', 'max_total_risk_percent')
        counts = ('total_trades', 'matured_trades', 'censored_trades')
        if (account.get('protocol') != 'original_solo_account_v1' or account.get('status') != 'computed'
                or account.get('plan_hash') != identity['plan_hash'] or account.get('arm_key') != identity['arm_key']
                or account.get('independent_evidence') is not False or account.get('promotion_evidence') is not False
                or type(account.get('decision_rows')) is not int
                or account['decision_rows'] != identity['original_scope']['decision_rows']
                or not isinstance(ledger, list) or not isinstance(account['account_ledger'], list)
                or not account['account_ledger'] or not isinstance(metrics, dict) or not isinstance(clock, dict)
                or clock.get('rows') != account['decision_rows']
                or pd.Timestamp(clock['first_signal_at']) != pd.Timestamp(identity['original_scope']['start_inclusive'])
                or pd.Timestamp(clock['last_execution_at']) != pd.Timestamp(identity['primary']['last_candle_at'])
                or account.get('receipt_hash') != canonical_hash({key: value for key, value in account.items() if key != 'receipt_hash'})):
            return False
        for key in required:
            OriginalSoloAccountObserver.finite(metrics[key])
        if (metrics['initial_capital'] <= 0 or any(type(metrics[key]) is not int or metrics[key] < 0 for key in counts)
                or metrics['total_trades'] != len(ledger)
                or metrics['matured_trades'] + metrics['censored_trades'] != len(ledger)):
            return False
        for row in ledger:
            for key in ('entry_cash', 'cash_before_exit', 'cash_after_exit', 'units', 'entry_price', 'exit_price',
                        'net_pnl', 'total_costs', 'commission', 'carry', 'spread', 'slippage', 'gross_market_pnl'):
                OriginalSoloAccountObserver.finite(row[key])
            if (type(row['outcome_matured']) is not bool
                    or abs(row['cash_after_exit'] - row['cash_before_exit'] - row['net_pnl']) > 1e-7 * max(1, abs(row['net_pnl']))
                    or abs(row['gross_market_pnl'] - row['total_costs'] - row['net_pnl']) > 1e-7 * max(1, abs(row['net_pnl']))):
                return False
        if (metrics['matured_trades'] != sum(row['outcome_matured'] for row in ledger)
                or abs(metrics['net_profit'] - sum(row['net_pnl'] for row in ledger)) > 1e-7 * max(1, abs(metrics['net_profit']))
                or abs(metrics['total_costs'] - sum(row['total_costs'] for row in ledger)) > 1e-7 * max(1, abs(metrics['total_costs']))):
            return False
        return True
    except (KeyError, TypeError, ValueError, OverflowError):
        return False


def run_original_full_arm(payload, frame, identity: dict, run_replay) -> dict:
    # The verified bytes are the actual consumed stream. No tail, split,
    # foundation substitution, extra warmup or walk-forward arm is permitted.
    times = pd.to_datetime(frame["time"], utc=True, errors="coerce")
    actual = {"rows": len(frame), "start_inclusive": times.iloc[0].isoformat(),
              "last_candle_at": times.iloc[-1].isoformat()}
    if (times.isna().any() or not times.is_unique or not times.is_monotonic_increasing
            or not same_scope(identity["original_scope"], measured_scope(payload, actual, identity["original_policy"]))):
        raise ValueError("COUNCIL_ARM_CONSUMED_FULL_SCOPE_MISMATCH")
    from app.services.historical_quotes import bind_original_full_quote_calendar
    frame = bind_original_full_quote_calendar(frame, payload, identity)
    response = run_replay(payload, frame, include_differential_pair=False, lightweight=True, original_full_arm=identity)
    result = response.model_dump() if hasattr(response, "model_dump") else dict(response)
    quality = dict(result.get("data_quality") or {})
    native = result.get("specialist_council_receipt")
    if native:
        if not same_scope(identity["original_scope"], native.get("evaluated_scope") or {}):
            raise ValueError("COUNCIL_ARM_NATIVE_PRODUCER_SCOPE_MISMATCH")
    elif quality.get("rows_after_cleaning") != len(frame):
        raise ValueError("COUNCIL_ARM_NORMALIZED_FULL_ROWS_CHANGED")
    else:
        account = quality.get('original_solo_account')
        if not solo_account_current(account, identity) or account['metrics']['initial_capital'] != float(payload.initial_balance):
            raise ValueError('COUNCIL_ARM_ORIGINAL_SOLO_ACCOUNT_METRICS_REQUIRED')
        result['metrics'] = account['metrics']
    quality["replay_evaluation_scope"] = measured_scope(payload, actual, identity["original_policy"])
    quality["authorized_original_council_arm"] = {
        "protocol": RECEIPT_PROTOCOL, "plan_hash": identity["plan_hash"], "arm_key": identity["arm_key"],
        "window_key": identity["window_key"], "model_hash": identity["model_hash"],
        "independent_evidence": False, "promotion_evidence": False,
    }
    result["data_quality"] = quality
    # These are an original comparison's raw account results, not walk-forward
    # training scores or a release/paper qualification certificate.
    return {"result": result, "train_score": 0, "validation_score": 0, "forward_score": 0,
            "forward_window_scores": [], "rolling_windows_count": 0, "robustness_score": 0,
            "is_overfit": False, "evaluation_status": "original_research_arm_only"}


class OriginalSoloAccountObserver:
    """Measure the existing single-position kernel's actual economic events.

    No order, signal, model parameter or legacy replay is changed by this
    observer. Terminal marks are explicitly censored, never matured trades.
    """
    def __init__(self, payload, proof):
        self.payload = payload
        self.proof = proof
        self.initial = float(payload.initial_balance)
        self.peak = self.initial
        self.day = None
        self.day_equity = self.initial
        self.max_drawdown = self.max_daily_loss = self.max_gross = self.max_risk = 0.0
        self.cash = self.initial
        self.active = None
        self.positions = []
        self.samples = []
        self.decisions = 0
        self.first_decision = self.last_decision = None
        self.first_execution = self.last_execution = None

    @staticmethod
    def finite(value):
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
            raise ValueError('COUNCIL_ARM_SOLO_UNMEASURABLE_ECONOMICS')
        return float(value)

    def fill(self, position, cash, execution_payload, risk_percent):
        if self.active is not None:
            raise ValueError('COUNCIL_ARM_SOLO_POSITION_OWNERSHIP_COLLISION')
        entry = self.finite(position['entry_price'])
        multiple = self.finite(position['position_size_multiple'])
        if entry <= 0 or multiple < 0:
            raise ValueError('COUNCIL_ARM_SOLO_UNMEASURABLE_ECONOMICS')
        self.active = {'entry_cash': self.finite(cash), 'units': cash * multiple / entry,
                       'risk_amount': cash * self.finite(risk_percent) / 100,
                       'entry_time': pd.Timestamp(position['entry_time']).isoformat(),
                       'entry_price': entry, 'market_entry_price': self.finite(position['market_entry_price']),
                       'execution': execution_payload}

    def decision(self, signal_time, execution_time):
        signal_stamp, execution_stamp = pd.Timestamp(signal_time).isoformat(), pd.Timestamp(execution_time).isoformat()
        if self.first_decision is None:
            self.first_decision, self.first_execution = signal_stamp, execution_stamp
        self.last_decision, self.last_execution = signal_stamp, execution_stamp
        self.decisions += 1

    def outcome(self, position, price, timestamp):
        from app.services import backtester as kernel
        execution_payload = kernel._payload_for_position(self.payload, position)
        direction = position['direction']
        entry = self.finite(position['entry_price'])
        executable = kernel._exit_price(self.finite(price), direction, execution_payload)
        market_return = ((executable - entry) / entry * 100 if direction == 'BUY'
                         else (entry - executable) / entry * 100)
        fraction = float(position.get('partial_fraction', 0) or 0) if position.get('partial_closed') else 0.0
        if fraction:
            partial = self.finite(position['partial_exit_price'])
            partial_return = ((partial - entry) / entry * 100 if direction == 'BUY' else (entry - partial) / entry * 100)
            market_return = market_return * (1 - fraction) + partial_return * fraction
        holding = max(0, (pd.Timestamp(timestamp) - pd.Timestamp(position['entry_time'])).total_seconds() / 86400)
        explicit = (execution_payload.execution.commission_percent + execution_payload.execution.swap_per_day_percent * holding)
        multiple = self.finite(position['position_size_multiple'])
        return market_return * multiple - explicit * multiple, explicit * multiple

    def sample(self, price, timestamp, cash, position):
        cash = self.finite(cash)
        equity = cash
        exposure = risk = 0.0
        if position is not None:
            if self.active is None:
                raise ValueError('COUNCIL_ARM_SOLO_ORIGINAL_FILL_MISSING')
            profit, _ = self.outcome(position, price, timestamp)
            equity += self.active['entry_cash'] * profit / 100
            fraction = float(position.get('partial_fraction', 0) or 0) if position.get('partial_closed') else 0.0
            exposure = self.active['units'] * self.finite(price) * (1 - fraction)
            risk = self.active['risk_amount'] * (1 - fraction)
        stamp = pd.Timestamp(timestamp)
        if self.day != stamp.date():
            self.day, self.day_equity = stamp.date(), equity
        self.peak = max(self.peak, equity)
        self.max_drawdown = max(self.max_drawdown, (self.peak - equity) / max(self.peak, 1e-12) * 100)
        self.max_daily_loss = max(self.max_daily_loss, (self.day_equity - equity) / max(self.day_equity, 1e-12) * 100)
        self.max_gross = max(self.max_gross, exposure)
        self.max_risk = max(self.max_risk, risk)
        self.samples.append({'time': stamp.isoformat(), 'cash': cash, 'equity': equity,
                             'gross_exposure': exposure, 'risk_amount': risk})

    def close(self, position, timestamp, cash_before, profit_percent, explicit_cost_percent,
              exit_price, exit_reason, matured=True):
        if self.active is None or abs(self.active['entry_cash'] - cash_before) > 1e-7 * max(1, abs(cash_before)):
            raise ValueError('COUNCIL_ARM_SOLO_ORIGINAL_CASH_RECONCILIATION_FAILED')
        actual = self.active
        payload = actual['execution']
        # These fixed-cost fills are the real existing _entry/_exit_price
        # kernels, not aggregate net PnL interpreted as execution cost.
        entry_offset = abs(self.finite(position['entry_price']) - self.finite(position['market_entry_price']))
        spread_offset = self.finite(payload.execution.spread_points) * self.finite(payload.execution.point_size) / 2
        slippage_offset = self.finite(payload.execution.slippage_points) * self.finite(payload.execution.point_size)
        exit_offset = spread_offset + slippage_offset
        if abs(entry_offset - exit_offset) > 1e-9 * max(1, entry_offset):
            raise ValueError('COUNCIL_ARM_SOLO_ORIGINAL_FILL_COST_MISMATCH')
        explicit = cash_before * self.finite(explicit_cost_percent) / 100
        embedded = actual['units'] * (entry_offset + exit_offset)
        net = cash_before * self.finite(profit_percent) / 100
        multiple = self.finite(position['position_size_multiple'])
        holding_days = max(0, (pd.Timestamp(timestamp) - pd.Timestamp(position['entry_time'])).total_seconds() / 86400)
        commission = cash_before * multiple * self.finite(payload.execution.commission_percent) / 100
        carry = cash_before * multiple * self.finite(payload.execution.swap_per_day_percent) * holding_days / 100
        fraction = self.finite(position.get('partial_fraction', 0) or 0) if position.get('partial_closed') else 0.0
        weighted_exit = self.finite(exit_price) * (1 - fraction)
        if fraction:
            weighted_exit += self.finite(position['partial_exit_price']) * fraction
        direction = 1 if position['direction'] == 'BUY' else -1
        gross_fill_pnl = actual['units'] * direction * (weighted_exit - actual['entry_price'])
        if (abs(explicit - commission - carry) > 1e-7 * max(1, abs(explicit))
                or abs(net - gross_fill_pnl + explicit) > 1e-7 * max(1, abs(net))):
            raise ValueError('COUNCIL_ARM_SOLO_ORIGINAL_COST_RECONCILIATION_FAILED')
        self.positions.append({'entry_time': actual['entry_time'], 'exit_time': pd.Timestamp(timestamp).isoformat(),
                               'direction': position['direction'], 'entry_cash': actual['entry_cash'],
                               'cash_before_exit': cash_before, 'cash_after_exit': cash_before + net,
                               'units': actual['units'], 'entry_price': actual['entry_price'],
                               'market_entry_price': actual['market_entry_price'], 'exit_price': self.finite(exit_price),
                               'weighted_exit_price': weighted_exit, 'partial_fraction': fraction,
                               'exit_reason': exit_reason, 'holding_days': holding_days,
                               'commission': commission, 'carry': carry,
                               'spread': actual['units'] * spread_offset * 2,
                               'slippage': actual['units'] * slippage_offset * 2,
                               'gross_market_pnl': gross_fill_pnl + embedded,
                               'net_pnl': net, 'explicit_cost': explicit, 'spread_slippage': embedded,
                               'total_costs': explicit + embedded, 'outcome_matured': bool(matured),
                               'censor_reason': None if matured else 'authorized_window_end'})
        self.cash = cash_before + net
        self.active = None

    def finish(self, position, candle, cash, duration):
        timestamp = pd.Timestamp(candle['time']) + duration
        self.sample(candle['close'], timestamp, cash, position)
        closed_cash = self.initial + sum(row['net_pnl'] for row in self.positions)
        if abs(closed_cash - cash) > 1e-7 * max(1, abs(cash)):
            raise ValueError('COUNCIL_ARM_SOLO_ORIGINAL_CASH_RECONCILIATION_FAILED')
        if position is not None:
            from app.services import backtester as kernel
            profit, explicit = self.outcome(position, candle['close'], timestamp)
            self.close(position, timestamp, cash, profit, explicit,
                kernel._exit_price(self.finite(candle['close']), position['direction'],
                    kernel._payload_for_position(self.payload, position)), 'authorized_window_end', matured=False)
        else:
            self.cash = cash
        metrics = {'initial_capital': self.initial, 'net_profit': self.cash - self.initial,
                   'total_costs': sum(row['total_costs'] for row in self.positions),
                   'commission': sum(row['commission'] for row in self.positions),
                   'carry': sum(row['carry'] for row in self.positions),
                   'spread': sum(row['spread'] for row in self.positions),
                   'slippage': sum(row['slippage'] for row in self.positions),
                   'total_trades': len(self.positions), 'matured_trades': sum(row['outcome_matured'] for row in self.positions),
                   'censored_trades': sum(not row['outcome_matured'] for row in self.positions),
                   'max_drawdown_percent': self.max_drawdown, 'max_daily_loss_percent': self.max_daily_loss,
                   'max_gross_exposure_percent': self.max_gross / self.initial * 100,
                   'max_total_risk_percent': self.max_risk / self.initial * 100}
        body = {'protocol': 'original_solo_account_v1', 'status': 'computed', 'plan_hash': self.proof['plan_hash'],
                'arm_key': self.proof['arm_key'], 'decision_rows': self.decisions, 'metrics': metrics,
                'decision_clock': {'first_signal_at': self.first_decision, 'last_signal_at': self.last_decision,
                    'first_execution_at': self.first_execution, 'last_execution_at': self.last_execution,
                    'rows': self.decisions},
                'position_ledger': self.positions, 'account_ledger': self.samples,
                'realized_cash': cash, 'marked_terminal_cash': self.cash,
                'reconciliation_error': self.cash - self.initial - sum(row['net_pnl'] for row in self.positions),
                'independent_evidence': False, 'promotion_evidence': False}
        return {**body, 'receipt_hash': canonical_hash(body)}
