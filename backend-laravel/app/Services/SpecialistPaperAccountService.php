<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperFill;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Simulated paper ledger. Integer cents and 1/10000 units; no broker authority. */
class SpecialistPaperAccountService
{
    public const UNIT_SCALE = 10000;
    public const PRICE_SCALE = 1000000;

    public function __construct(private PaperAuthorityAdmissionService $authority, private SpecialistCouncilLifecycleService $councils,
        private PaperExecutionStateMachineService $executionState) {}

    public static function decimalUnits(float|string $units): int
    {
        if (is_float($units)) {
            if (! is_finite($units)) throw new LogicException('NON_FINITE_PAPER_AMOUNT');
            // Rounding up a reduced risk-authorized size would increase risk.
            return (int) floor($units * self::UNIT_SCALE + .000000001);
        }
        return self::scaled($units, 4);
    }

    public static function price(float|string $price): int
    {
        return self::scaled($price, 6);
    }

    private static function scaled(float|string $value, int $places): int
    {
        if (is_float($value)) {
            if (! is_finite($value)) throw new LogicException('NON_FINITE_PAPER_AMOUNT');
            $value = number_format($value, $places, '.', '');
        }
        if (! preg_match('/^(-?)(\d{1,9})(?:\.(\d*))?$/', $value, $parts)) throw new LogicException('INVALID_PAPER_AMOUNT');
        $fraction = $parts[3] ?? '';
        if (strlen($fraction) > $places && trim(substr($fraction, $places), '0') !== '') throw new LogicException('PAPER_PRECISION_UNSUPPORTED');
        return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 10 ** $places + (int) str_pad(substr($fraction, 0, $places), $places, '0'));
    }

    private static function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right > 0 && $left > intdiv(PHP_INT_MAX, $right))) throw new LogicException('PAPER_AMOUNT_OVERFLOW');
        return $left * $right;
    }

    private static function ceilRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator, $denominator) + ($numerator % $denominator === 0 ? 0 : 1);
    }

    public static function notionalCents(int $units, int $price): int
    {
        return self::ceilRatio(self::multiply($units, $price), 100000000);
    }

    public static function costCents(int $units, int $price, float|string $percent): int
    {
        return self::ceilRatio(self::multiply(self::notionalCents($units, $price), self::scaled($percent, 8)), 10000000000);
    }

    /** All reservation/admission/publication operations take the same account lock first. */
    public function reserve(ModelMarketPerformance $candidate, PaperSignal $signal, array $binding, int $units, int $entry, int $stop, int $costCents): array
    {
        return DB::transaction(function () use ($candidate, $signal, $binding, $units, $entry, $stop, $costCents): array {
            if (! (bool) config('services.paper.specialist_council_enabled', false)) return $this->blocked('SPECIALIST_PAPER_DISABLED');
            if (! str_ends_with(strtoupper(str_replace(['/', '_', '-'], '', $candidate->symbol)), 'USD')) return $this->blocked('PAPER_ACCOUNT_QUOTE_CONVERSION_UNSUPPORTED');
            if ((string) config('services.paper.specialist_broker_account_mode', 'hedging') !== 'hedging') return $this->blocked('BROKER_NETTING_RECONCILIATION_UNSUPPORTED');
            $key = (string) config('services.paper.specialist_account_key', 'specialist-paper');
            $initial = (int) config('services.paper.specialist_initial_balance_cents', 1000000);
            if ($initial <= 0) return $this->blocked('PAPER_ACCOUNT_CAPITAL_UNAVAILABLE');
            DB::table('paper_capital_accounts')->insertOrIgnore(['account_key' => $key, 'initial_balance_cents' => $initial,
                'balance_cents' => $initial, 'peak_equity_cents' => $initial, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('paper_capital_accounts')->where('account_key', $key)->lockForUpdate()->first();
            if (PaperOrder::whereNull('paper_capital_reservation_id')->whereIn('status', ['open', 'submitted'])->exists()) return $this->blocked('LEGACY_PAPER_ACCOUNT_RECONCILIATION_REQUIRED');
            $version = \App\Models\SpecialistCouncilVersion::where('council_id', $binding['council_id'] ?? '')
                ->where('version', $binding['council_version'] ?? '')->lockForUpdate()->first();
            ModelMarketPerformance::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            \App\Models\ModelVersion::query()->whereKey($candidate->model_version_id)->lockForUpdate()->firstOrFail();
            $signal = PaperSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            $candidate->refresh()->load('modelVersion');
            $identity = $this->authority->verifyFrozenCandidate($candidate->modelVersion, $candidate->symbol, $candidate->timeframe,
                $this->passport($candidate, $signal->timeframe),
                (int) data_get($signal->payload, 'paper_admission.admission_id', 0));
            if (! ($identity['allowed'] ?? false) || ! hash_equals((string) ($identity['identity_hash'] ?? ''), (string) data_get($signal->payload, 'paper_admission.identity_hash', ''))) return $this->blocked($identity['reason_code'] ?? 'PAPER_SIGNAL_FROZEN_IDENTITY_MISMATCH');
            $ready = $this->authority->observationReadiness($candidate->modelVersion, $candidate->symbol, $candidate->timeframe,
                (int) data_get($signal->payload, 'paper_admission.admission_id', 0));
            if (! ($ready['allowed'] ?? false)) return $this->blocked($ready['reason_code']);
            $member = $this->councils->paperBinding($candidate->modelVersion, $candidate->symbol, $candidate->timeframe, $binding);
            if (! ($member['allowed'] ?? false)) return $this->blocked($member['reason_code']);
            $horizon = (array) data_get($member, 'member.horizon', []);
            $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$signal->timeframe] ?? 0;
            if (($horizon['execution_precision'] ?? null) !== 'candle' || $seconds <= 0
                || ($horizon['decision_interval_seconds'] ?? 0) < $seconds || ($horizon['reevaluation_interval_seconds'] ?? 0) < $seconds
                || $horizon['decision_interval_seconds'] % $seconds !== 0 || $horizon['reevaluation_interval_seconds'] % $seconds !== 0) return $this->blocked('SPECIALIST_PAPER_EXECUTION_PRECISION_UNSUPPORTED');
            if ($signal->candle_time->timestamp % $horizon['decision_interval_seconds'] !== 0) return $this->blocked('SPECIALIST_DECISION_NOT_DUE');
            $proposal = (array) data_get($signal->payload, 'specialist_trade_intent', []);
            $expiry = $signal->candle_time->copy()->utc()->addSeconds(2 * $horizon['decision_interval_seconds']);
            if (($proposal['protocol'] ?? null) !== 'specialist_paper_trade_intent_v1' || ($proposal['owner_id'] ?? null) !== $member['owner_id']
                || ($proposal['symbol'] ?? null) !== $signal->symbol || ($proposal['direction'] ?? null) !== $signal->decision
                || ($proposal['expires_at'] ?? null) !== $expiry->toIso8601String()) return $this->blocked('SPECIALIST_PAPER_INTENT_MISSING_OR_CHANGED');
            if (now()->greaterThan($expiry)) {
                $held = DB::table('paper_capital_reservations')->where('paper_signal_id', $signal->id)->first();
                if ($held && $held->paper_order_id) return ['allowed' => true, 'idempotent' => true, 'reservation' => (array) $held];
                if ($held) $this->release((int) $held->id);
                $cancelled = PaperOrder::firstOrCreate(['paper_signal_id' => $signal->id], ['model_market_performance_id' => $candidate->id,
                    'broker' => 'simulated', 'symbol' => $signal->symbol, 'timeframe' => $signal->timeframe, 'direction' => $signal->decision,
                    'units' => 0, 'entry_price' => $signal->price, 'stop_loss' => $signal->stop_loss, 'take_profit' => $signal->take_profit,
                    'status' => 'cancelled', 'opened_at' => now(), 'closed_at' => now(), 'signal_context' => ['specialist_council_binding' => $binding, 'reason_code' => 'SPECIALIST_INTENT_EXPIRED']]);
                $this->executionState->record($candidate, 'cancelled', $signal, $cancelled, ['provider' => 'simulated', 'reason' => 'SPECIALIST_INTENT_EXPIRED']);
                return $this->blocked('SPECIALIST_INTENT_EXPIRED');
            }
            $policy = (array) data_get($version?->manifest, 'execution', []);
            if (($policy['broker_position_mode'] ?? null) !== 'hedging') return $this->blocked('BROKER_NETTING_RECONCILIATION_UNSUPPORTED');
            $pin = (array) data_get($signal->payload, 'specialist_council_binding', []);
            foreach (['council_id', 'council_version', 'specialist_id', 'management_version'] as $field) {
                if ((string) ($pin[$field] ?? '') !== (string) ($binding[$field] ?? '')) return $this->blocked('PAPER_SPECIALIST_SIGNAL_BINDING_MISMATCH');
            }
            $intent = hash('sha256', implode('|', [$account->id, $signal->id, $candidate->id, $signal->payload_hash]));
            $existing = DB::table('paper_capital_reservations')->where('intent_key', $intent)->first();
            if ($existing) return ['allowed' => true, 'idempotent' => true, 'reservation' => (array) $existing];
            if ($units <= 0 || $entry <= 0 || $stop <= 0 || $costCents < 0) return $this->blocked('INVALID_PAPER_RESERVATION');
            $valuation = $this->markedEquity($account);
            if (! $valuation['known']) return [...$this->blocked('PAPER_ACCOUNT_MARK_UNAVAILABLE'), 'mark_dependencies' => $valuation['dependencies']];
            $peak = $this->observePeak($account, $valuation['equity_cents']);
            if ($peak === null) return $this->blocked('PAPER_ACCOUNT_PEAK_EQUITY_HISTORY_UNAVAILABLE');
            // Floating gains do not fund entries; floating losses immediately
            // narrow the same locked account's capital and risk allowance.
            $budget = min((int) $account->balance_cents, $valuation['equity_cents']);
            if ($budget <= 0) return $this->blocked('PAPER_SHARED_CAPITAL_EXHAUSTED');
            $capital = self::notionalCents($units, $entry);
            $risk = self::notionalCents($units, abs($entry - $stop)) + $costCents;
            if ($costCents > self::costCents($units, $entry, (float) ($policy['max_expected_cost_percent'] ?? 0))) return $this->blocked('PAPER_EXPECTED_COST_LIMIT');
            $owner = (string) $member['owner_id'];
            $pending = DB::table('paper_capital_reservations')->where('paper_capital_account_id', $account->id)->whereIn('status', ['reserved', 'partial', 'filled']);
            if ((clone $pending)->where('owner_id', $owner)->exists()) return $this->blocked('PAPER_SPECIALIST_POSITION_ALREADY_OWNED');
            $open = PaperOrder::query()->whereIn('status', ['open', 'submitted'])->where('evidence_status', 'valid');
            $unpublished = (clone $pending)->whereNull('paper_order_id')->get();
            $maxPositions = min((int) config('services.risk.max_open_positions', 3), (int) ($policy['max_open_positions'] ?? 0));
            if ((clone $open)->count() + $unpublished->count() >= $maxPositions) return $this->blocked('NO_TRADE_MAX_OPEN_RISK');
            $group = str_starts_with(strtoupper($candidate->symbol), 'XAU') ? 'metal_usd' : 'usd_fx';
            $sameGroup = static fn ($row): bool => (str_starts_with(strtoupper($row->symbol), 'XAU') ? 'metal_usd' : 'usd_fx') === $group;
            if ($open->get()->filter($sameGroup)->count() + $unpublished->filter($sameGroup)->count() >= (int) config('services.risk.max_positions_per_group', 2)) return $this->blocked('NO_TRADE_CORRELATED_RISK');
            if ($capital > $budget - $account->reserved_cents - $account->allocated_cents - $costCents) return $this->blocked('PAPER_SHARED_CAPITAL_EXHAUSTED');
            $ownerCap = self::costCents(10000, self::price($budget / 100), (float) data_get($member, 'member.capital_weight', 0) * 100);
            if ($capital > $ownerCap) return $this->blocked('PAPER_MEMBER_CAPITAL_ALLOCATION_LIMIT');
            $reservedCap = self::costCents(10000, self::price($budget / 100), (float) ($policy['max_reserved_capital_percent'] ?? 0));
            if ($account->reserved_cents + $account->allocated_cents + $capital > $reservedCap) return $this->blocked('PAPER_SHARED_CAPITAL_ALLOCATION_LIMIT');
            $grossCap = min((int) config('services.paper.specialist_max_gross_exposure_cents', 1000000),
                self::costCents(10000, self::price($budget / 100), (float) ($policy['max_gross_exposure_percent'] ?? 0)),
                self::costCents(10000, self::price($budget / 100), (float) data_get(app(ExecutionContractService::class)->for($candidate->symbol, $signal->timeframe), 'parameters.max_leverage', 0) * 100));
            if ($account->gross_exposure_cents + $capital > $grossCap) return $this->blocked('PAPER_GROSS_EXPOSURE_LIMIT');
            $riskCap = min((int) config('services.paper.specialist_max_account_risk_cents', 10000),
                self::costCents(10000, self::price($budget / 100), (float) ($policy['max_total_risk_percent'] ?? 0)));
            if ($account->reserved_risk_cents + $account->allocated_risk_cents + $risk > $riskCap) return $this->blocked('PAPER_ACCOUNT_RISK_LIMIT');
            if (($policy['opposite_position_policy'] ?? 'reject') === 'reject'
                && (clone $pending)->where('symbol', $candidate->symbol)->where('direction', '!=', $signal->decision)->exists()) return $this->blocked('PAPER_OPPOSITE_POSITION_POLICY_REJECTED');
            $perTradeCap = self::costCents(10000, self::price($budget / 100), min((float) config('services.risk.max_risk_per_trade_percent', 1), (float) data_get($member, 'member.risk_per_trade_percent', 0)));
            if ($risk > $perTradeCap) return $this->blocked('NO_TRADE_PER_TRADE_RISK');
            $daily = (int) DB::table('paper_cost_ledger')->where('paper_capital_account_id', $account->id)->where('created_at', '>=', now()->startOfDay())->selectRaw('COALESCE(SUM(realized_cents - cost_cents), 0) as pnl')->value('pnl');
            // Without an original day-open mark, retain a conservative loss
            // floor instead of treating overnight unrealized loss as zero.
            $daily += min(0, $valuation['unrealized_pnl_cents']);
            $lossCap = self::costCents(10000, self::price($account->initial_balance_cents / 100), min(abs((float) config('services.risk.daily_loss_limit_percent', 2)), (float) ($policy['max_daily_loss_percent'] ?? 0)));
            if ($daily <= -$lossCap) return $this->blocked('NO_TRADE_DAILY_LOSS_LOCK');
            $drawdownCap = self::costCents(10000, self::price($peak / 100), (float) ($policy['max_drawdown_percent'] ?? 0));
            if ($peak - $valuation['equity_cents'] >= $drawdownCap) return $this->blocked('PAPER_ACCOUNT_DRAWDOWN_LIMIT');
            $id = DB::table('paper_capital_reservations')->insertGetId(['paper_capital_account_id' => $account->id, 'paper_signal_id' => $signal->id,
                'intent_key' => $intent, 'owner_id' => $owner, 'council_id' => $binding['council_id'], 'council_version' => $binding['council_version'],
                'management_version' => $binding['management_version'], 'symbol' => $candidate->symbol, 'direction' => $signal->decision,
                'requested_units_micros' => $units, 'capital_cents' => $capital, 'risk_cents' => $risk,
                'pending_capital_cents' => $capital, 'pending_risk_cents' => $risk, 'binding' => json_encode($binding),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('paper_capital_accounts')->where('id', $account->id)->update(['reserved_cents' => $account->reserved_cents + $capital,
                'reserved_risk_cents' => $account->reserved_risk_cents + $risk, 'gross_exposure_cents' => $account->gross_exposure_cents + $capital, 'updated_at' => now()]);
            return ['allowed' => true, 'idempotent' => false, 'reservation' => (array) DB::table('paper_capital_reservations')->find($id)];
        }, 3);
    }

    public function attach(int $reservationId, PaperOrder $order): void
    {
        $this->locked($reservationId, function ($account, $reservation) use ($order): void {
            if ($reservation->paper_order_id !== null && (int) $reservation->paper_order_id !== (int) $order->id) throw new LogicException('PAPER_INTENT_ORDER_MISMATCH');
            if ((int) $reservation->paper_signal_id !== (int) $order->paper_signal_id) throw new LogicException('PAPER_INTENT_SIGNAL_MISMATCH');
            DB::table('paper_capital_reservations')->where('id', $reservation->id)->update(['paper_order_id' => $order->id, 'updated_at' => now()]);
            $order->update(['paper_capital_reservation_id' => $reservation->id, 'owner_id' => $reservation->owner_id,
                'council_id' => $reservation->council_id, 'council_version' => $reservation->council_version,
                'management_version' => $reservation->management_version, 'filled_units_micros' => 0, 'remaining_units_micros' => 0]);
        });
    }

    public function fill(PaperOrder $order, string $fillKey, string $type, int $units, int $price, int $cost, array $payload = []): bool
    {
        return $this->locked((int) $order->paper_capital_reservation_id, function ($account, $reservation) use ($order, $fillKey, $type, $units, $price, $cost, $payload): bool {
            $order->refresh();
            if ((int) $reservation->paper_order_id !== (int) $order->id) throw new LogicException('PAPER_RESERVATION_ORDER_MISMATCH');
            $key = hash('sha256', $order->id.'|'.$type.'|'.$fillKey);
            if (DB::table('paper_cost_ledger')->where('ledger_key', $key)->exists()) return false;
            if ($units <= 0 || $price <= 0 || $cost < 0 || ! in_array($type, ['entry', 'exit'], true)) throw new LogicException('INVALID_PAPER_FILL');
            $entry = $type === 'entry';
            $available = $entry ? $reservation->requested_units_micros - $reservation->filled_units_micros : $reservation->remaining_units_micros;
            if ($units > $available || ! in_array($reservation->status, ['reserved', 'partial', 'filled'], true)) throw new LogicException('PAPER_FILL_EXCEEDS_RESERVED_UNITS');
            if ($entry) {
                \App\Models\SpecialistCouncilVersion::where('council_id', $reservation->council_id)
                    ->where('version', $reservation->council_version)->lockForUpdate()->firstOrFail();
                ModelMarketPerformance::whereKey($order->model_market_performance_id)->lockForUpdate()->firstOrFail();
                $candidate = $order->marketPerformance()->with('modelVersion')->firstOrFail();
                \App\Models\ModelVersion::whereKey($candidate->model_version_id)->lockForUpdate()->firstOrFail();
                PaperSignal::whereKey($order->paper_signal_id)->lockForUpdate()->firstOrFail();
                $candidate->refresh()->load('modelVersion');
                $order->unsetRelation('paperSignal');
                $binding = json_decode($reservation->binding, true);
                $member = $this->councils->paperBinding($candidate->modelVersion, $candidate->symbol, $candidate->timeframe, $binding);
                $identity = $this->authority->verifyFrozenCandidate($candidate->modelVersion, $candidate->symbol, $candidate->timeframe,
                    $this->passport($candidate, $order->timeframe),
                    (int) data_get($order->paperSignal?->payload, 'paper_admission.admission_id', 0));
                $ready = $this->authority->observationReadiness($candidate->modelVersion, $candidate->symbol, $candidate->timeframe,
                    (int) data_get($order->paperSignal?->payload, 'paper_admission.admission_id', 0));
                if (! ($member['allowed'] ?? false) || ! ($identity['allowed'] ?? false) || ! ($ready['allowed'] ?? false)
                    || ! hash_equals((string) ($identity['identity_hash'] ?? ''), (string) data_get($order->paperSignal?->payload, 'paper_admission.identity_hash', ''))) throw new LogicException('PAPER_ENTRY_AUTHORITY_DRIFT');
            }
            $capitalPool = $entry ? $reservation->pending_capital_cents : $reservation->allocated_capital_cents;
            $riskPool = $entry ? $reservation->pending_risk_cents : $reservation->allocated_risk_cents;
            $capital = $units === $available ? $capitalPool : intdiv(self::multiply($capitalPool, $units), $available);
            $risk = $units === $available ? $riskPool : intdiv(self::multiply($riskPool, $units), $available);
            $realized = 0;
            if (! $entry) {
                $delta = $price - self::price((string) $order->entry_price);
                $direction = $order->direction === 'BUY' ? 1 : -1;
                $realized = $direction * ($delta < 0 ? -1 : 1) * intdiv(self::multiply(abs($delta), $units), 100000000);
            }
            if ($entry && $price !== self::price((string) $order->entry_price)) throw new LogicException('PAPER_ENTRY_PRICE_DRIFT');
            if ($entry && $account->balance_cents - $cost < $account->reserved_cents + $account->allocated_cents) throw new LogicException('PAPER_COST_CAPITAL_EXHAUSTED');
            DB::table('paper_cost_ledger')->insert(['paper_capital_account_id' => $account->id, 'paper_capital_reservation_id' => $reservation->id,
                'paper_order_id' => $order->id, 'ledger_key' => $key, 'event_type' => $type, 'units_micros' => $units, 'price_micros' => $price,
                'cost_cents' => $cost, 'realized_cents' => $realized, 'payload' => json_encode(['broker' => 'simulated', 'cost_basis' => 'sealed_simulation_estimate', ...$payload]), 'created_at' => now(), 'updated_at' => now()]);
            $remaining = $reservation->remaining_units_micros + ($entry ? $units : -$units);
            $filled = $reservation->filled_units_micros + ($entry ? $units : 0);
            DB::table('paper_capital_reservations')->where('id', $reservation->id)->update([
                'filled_units_micros' => $filled, 'remaining_units_micros' => $remaining,
                'pending_capital_cents' => $reservation->pending_capital_cents - ($entry ? $capital : 0),
                'pending_risk_cents' => $reservation->pending_risk_cents - ($entry ? $risk : 0),
                'allocated_capital_cents' => $reservation->allocated_capital_cents + ($entry ? $capital : -$capital),
                'allocated_risk_cents' => $reservation->allocated_risk_cents + ($entry ? $risk : -$risk),
                'status' => $entry ? ($filled === $reservation->requested_units_micros ? 'filled' : 'partial') : ($remaining === 0 ? 'closed' : 'filled'), 'updated_at' => now()]);
            DB::table('paper_capital_accounts')->where('id', $account->id)->update([
                'balance_cents' => $account->balance_cents + $realized - $cost,
                'reserved_cents' => $account->reserved_cents - ($entry ? $capital : 0),
                'allocated_cents' => $account->allocated_cents + ($entry ? $capital : -$capital),
                'reserved_risk_cents' => $account->reserved_risk_cents - ($entry ? $risk : 0),
                'allocated_risk_cents' => $account->allocated_risk_cents + ($entry ? $risk : -$risk),
                'gross_exposure_cents' => $account->gross_exposure_cents - ($entry ? 0 : $capital), 'updated_at' => now()]);
            PaperFill::create(['paper_order_id' => $order->id, 'fill_key' => $key, 'fill_type' => $type, 'price' => $price / self::PRICE_SCALE,
                'units_micros' => $units, 'cost_cents' => $cost, 'realized_cents' => $realized, 'cost_percent' => $payload['cost_percent'] ?? 0,
                'filled_at' => $payload['filled_at'] ?? now(), 'payload' => $payload]);
            $order->update(['filled_units_micros' => $filled, 'remaining_units_micros' => $remaining,
                'units' => $filled / self::UNIT_SCALE, 'status' => $remaining > 0 ? 'open' : ($entry ? 'submitted' : 'closed')]);
            $account->balance_cents += $realized - $cost;
            $valuation = $this->markedEquity($account);
            if ($valuation['known']) $this->observePeak($account, $valuation['equity_cents']);
            $candidate = ModelMarketPerformance::findOrFail($order->model_market_performance_id);
            $this->executionState->record($candidate, $entry ? ($filled < $reservation->requested_units_micros ? 'partially_filled' : 'filled') : ($remaining > 0 ? 'partial_exit' : 'closed_fill'),
                $order->paperSignal, $order, ['provider' => 'simulated', 'idempotency_suffix' => $key,
                    'filled_price' => $price / self::PRICE_SCALE, 'filled_units' => $units / self::UNIT_SCALE,
                    'payload' => ['ledger_key' => $key, 'cost_cents' => $cost, 'realized_cents' => $realized]]);
            return true;
        });
    }

    /** Cancel/reject only releases the unfilled allocation; existing fills keep their owner. */
    public function release(int $reservationId, string $reason = 'cancelled'): void
    {
        $this->locked($reservationId, function ($account, $reservation) use ($reason): void {
            if ($reservation->pending_capital_cents === 0 && $reservation->pending_risk_cents === 0) return;
            DB::table('paper_capital_accounts')->where('id', $account->id)->update([
                'reserved_cents' => $account->reserved_cents - $reservation->pending_capital_cents,
                'reserved_risk_cents' => $account->reserved_risk_cents - $reservation->pending_risk_cents,
                'gross_exposure_cents' => $account->gross_exposure_cents - $reservation->pending_capital_cents, 'updated_at' => now()]);
            DB::table('paper_capital_reservations')->where('id', $reservation->id)->update([
                'requested_units_micros' => $reservation->filled_units_micros, 'pending_capital_cents' => 0, 'pending_risk_cents' => 0,
                'status' => $reservation->remaining_units_micros > 0 ? 'filled' : 'released', 'updated_at' => now()]);
            if ($reservation->paper_order_id && $reservation->remaining_units_micros === 0) PaperOrder::whereKey($reservation->paper_order_id)->update(['status' => $reason]);
            if ($reservation->paper_order_id) {
                $order = PaperOrder::findOrFail($reservation->paper_order_id);
                $candidate = ModelMarketPerformance::findOrFail($order->model_market_performance_id);
                $this->executionState->record($candidate, $reason === 'rejected' ? 'rejected' : 'cancelled_remaining', $order->paperSignal, $order,
                    ['provider' => 'simulated', 'reason' => $reason, 'idempotency_suffix' => 'reservation-'.$reservation->id,
                        'payload' => ['released_capital_cents' => $reservation->pending_capital_cents, 'remaining_position_units_micros' => $reservation->remaining_units_micros]]);
            }
        });
    }

    public function reconciliation(string $accountKey): array
    {
        return DB::transaction(function () use ($accountKey): array {
            $account = DB::table('paper_capital_accounts')->where('account_key', $accountKey)->lockForUpdate()->firstOrFail();
            $rows = DB::table('paper_capital_reservations')->where('paper_capital_account_id', $account->id)->get();
            $ledger = DB::table('paper_cost_ledger')->where('paper_capital_account_id', $account->id)->get();
            $expected = ['balance_cents' => $account->initial_balance_cents + $ledger->sum('realized_cents') - $ledger->sum('cost_cents'),
                'reserved_cents' => $rows->sum('pending_capital_cents'), 'allocated_cents' => $rows->sum('allocated_capital_cents'),
                'reserved_risk_cents' => $rows->sum('pending_risk_cents'), 'allocated_risk_cents' => $rows->sum('allocated_risk_cents'),
                'gross_exposure_cents' => $rows->sum('pending_capital_cents') + $rows->sum('allocated_capital_cents')];
            $differences = [];
            foreach ($expected as $field => $value) if ((int) $account->$field !== (int) $value) $differences[$field] = ['actual' => (int) $account->$field, 'expected' => (int) $value];
            foreach ($rows->whereNotNull('paper_order_id') as $row) {
                $fills = PaperFill::where('paper_order_id', $row->paper_order_id)->get();
                $entry = (int) $fills->where('fill_type', 'entry')->sum('units_micros');
                $exit = (int) $fills->where('fill_type', 'exit')->sum('units_micros');
                if ($entry !== (int) $row->filled_units_micros || $entry - $exit !== (int) $row->remaining_units_micros) $differences['units_'.$row->paper_order_id] = ['entry' => $entry, 'exit' => $exit];
                $cost = (int) $fills->sum('cost_cents');
                if ($cost !== (int) $ledger->where('paper_order_id', $row->paper_order_id)->sum('cost_cents')) $differences['cost_'.$row->paper_order_id] = ['fills' => $cost];
            }
            $exposure = [];
            foreach ($rows as $row) {
                $gross = (int) $row->pending_capital_cents + (int) $row->allocated_capital_cents;
                $exposure[$row->symbol] ??= ['gross_cents' => 0, 'net_cents' => 0];
                $exposure[$row->symbol]['gross_cents'] += $gross;
                $exposure[$row->symbol]['net_cents'] += $gross * ($row->direction === 'BUY' ? 1 : -1);
            }
            $valuation = $this->markedEquity($account, $rows);
            $peak = $valuation['known'] ? $this->observePeak($account, $valuation['equity_cents']) : $account->peak_equity_cents;
            return ['protocol' => 'specialist_paper_account_reconciliation_v1', 'reconciled' => $differences === [], 'differences' => $differences,
                'account' => (array) $account, 'currency' => 'USD', 'exposure_by_symbol' => $exposure,
                'unrealized_pnl_cents' => $valuation['unrealized_pnl_cents'],
                'equity_cents' => $valuation['equity_cents'], 'peak_equity_cents' => $peak,
                'mark_dependencies' => $valuation['dependencies'],
                'missing_mark_order_ids' => array_keys($valuation['dependencies']),
                'mark_basis' => 'latest_fresh_closed_candle_less_pinned_exit_spread_slippage_commission_and_accrued_carry',
                'broker' => 'simulated', 'broker_reconciled' => false, 'promotion_evidence' => false];
        });
    }

    /** Caller holds the account lock. The same valuation owns reporting and intake. */
    private function markedEquity(object $account, ?\Illuminate\Support\Collection $rows = null): array
    {
        $rows ??= DB::table('paper_capital_reservations')->where('paper_capital_account_id', $account->id)->get();
        $observedAt = \Carbon\CarbonImmutable::now('UTC');
        $unrealized = 0; $dependencies = [];
        foreach ($rows as $row) {
            if ((int) $row->remaining_units_micros <= 0) continue;
            if (! $row->paper_order_id) { $dependencies['reservation_'.$row->id] = 'owned_order_missing'; continue; }
            $order = PaperOrder::find($row->paper_order_id);
            if (! $order || ! $order->opened_at || $order->symbol !== $row->symbol || $order->direction !== $row->direction) {
                $dependencies[$row->paper_order_id] = 'owned_order_identity_invalid'; continue;
            }
            $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$order->timeframe] ?? 0;
            if ($seconds <= 0) { $dependencies[$order->id] = 'mark_timeframe_unsupported'; continue; }
            $mark = \App\Models\Candle::where('symbol_id', \App\Models\Symbol::where('code', $order->symbol)->value('id'))
                ->where('timeframe', $order->timeframe)->where('time', '<=', $observedAt->subSeconds($seconds))
                ->orderByDesc('time')->orderByDesc('id')->first();
            if (! $mark) { $dependencies[$order->id] = 'closed_mark_missing'; continue; }
            $closedAt = $mark->time->copy()->utc()->addSeconds($seconds);
            if ($closedAt->lessThan($observedAt->subSeconds($seconds)) || $closedAt->lessThan($order->opened_at)) {
                $dependencies[$order->id] = 'closed_mark_stale'; continue;
            }
            $policy = (array) data_get($order->signal_context, 'paper_cost_policy', []);
            foreach (['commission_percent', 'swap_per_day_percent', 'spread_points', 'slippage_points', 'point_size'] as $field) {
                if (! isset($policy[$field]) || ! is_numeric($policy[$field]) || ! is_finite((float) $policy[$field]) || $policy[$field] < 0) {
                    $dependencies[$order->id] = 'pinned_mark_cost_policy_unavailable'; continue 2;
                }
            }
            if ((float) $policy['point_size'] <= 0) { $dependencies[$order->id] = 'pinned_mark_cost_policy_unavailable'; continue; }
            try {
                if (! is_numeric($mark->close) || ! is_finite((float) $mark->close) || $mark->close <= 0 || empty($mark->provider)) {
                    throw new LogicException('INVALID_PAPER_MARK');
                }
                $entry = self::price((string) $order->entry_price);
                $offset = ((float) $policy['spread_points'] / 2 + (float) $policy['slippage_points']) * (float) $policy['point_size'];
                // Round hypothetical closing friction against equity; an
                // unavailable precision cannot silently remove an exit cost.
                if (! is_finite($offset) || $offset * self::PRICE_SCALE > PHP_INT_MAX) throw new LogicException('INVALID_PAPER_EXIT_COST');
                $exit = self::price((string) $mark->close) + ($order->direction === 'BUY' ? -1 : 1) * (int) ceil($offset * self::PRICE_SCALE);
                if ($exit <= 0) throw new LogicException('INVALID_PAPER_EXIT_MARK');
                $delta = $exit - $entry;
                $movement = self::multiply(abs($delta), (int) $row->remaining_units_micros);
                $loss = $order->direction === 'BUY' ? $delta < 0 : $delta > 0;
                $pnl = $loss ? -self::ceilRatio($movement, 100000000) : intdiv($movement, 100000000);
                $exitCommission = self::costCents((int) $row->remaining_units_micros, $entry, (float) $policy['commission_percent'] / 2);
                // Canonical paper carry remains payable on the original filled
                // notional even after a partial exit; the ledger owns paid cost.
                $days = max(0, $order->opened_at->diffInSeconds($observedAt)) / 86400;
                $carry = self::costCents((int) $row->filled_units_micros, $entry, (float) $policy['swap_per_day_percent'] * $days);
                $paidCarry = 0;
                foreach (DB::table('paper_cost_ledger')->where('paper_order_id', $order->id)->get(['payload', 'cost_cents']) as $costRow) {
                    $paid = data_get(json_decode((string) $costRow->payload, true), 'carry_cents', 0);
                    if (! is_int($paid) || $paid < 0 || $paid > (int) $costRow->cost_cents) throw new LogicException('PAPER_PAID_CARRY_UNATTESTED');
                    $paidCarry += $paid;
                }
                $unrealized += $pnl - $exitCommission - max(0, $carry - $paidCarry);
            } catch (LogicException $error) {
                $dependencies[$order->id] = 'closed_mark_or_cost_invalid';
            }
        }
        return ['known' => $dependencies === [], 'dependencies' => $dependencies,
            'unrealized_pnl_cents' => $dependencies === [] ? $unrealized : null,
            'equity_cents' => $dependencies === [] ? (int) $account->balance_cents + $unrealized : null];
    }

    private function observePeak(object $account, int $equity): ?int
    {
        if ($account->peak_equity_cents === null) {
            if ((int) $account->balance_cents !== (int) $account->initial_balance_cents
                || DB::table('paper_cost_ledger')->where('paper_capital_account_id', $account->id)->exists()
                || DB::table('paper_capital_reservations')->where('paper_capital_account_id', $account->id)
                    ->where(function ($query): void {
                        $query->where('filled_units_micros', '>', 0)
                            ->orWhereExists(function ($fills): void {
                                $fills->selectRaw('1')->from('paper_fills')
                                    ->whereColumn('paper_fills.paper_order_id', 'paper_capital_reservations.paper_order_id');
                            });
                    })->exists()) return null;
            $account->peak_equity_cents = (int) $account->initial_balance_cents;
        }
        $peak = max((int) $account->peak_equity_cents, $equity);
        DB::table('paper_capital_accounts')->where('id', $account->id)->update(['peak_equity_cents' => $peak]);
        $account->peak_equity_cents = $peak;
        return $peak;
    }

    private function locked(int $id, callable $action): mixed
    {
        return DB::transaction(function () use ($id, $action) {
            $reference = DB::table('paper_capital_reservations')->find($id);
            if (! $reference) throw new LogicException('PAPER_RESERVATION_MISSING');
            $account = DB::table('paper_capital_accounts')->where('id', $reference->paper_capital_account_id)->lockForUpdate()->firstOrFail();
            $reservation = DB::table('paper_capital_reservations')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($reservation->paper_order_id) PaperOrder::whereKey($reservation->paper_order_id)->lockForUpdate()->firstOrFail();
            return $action($account, $reservation);
        }, 3);
    }

    private function blocked(string $code): array
    {
        return ['allowed' => false, 'reason_code' => $code, 'dependency_status' => 'BLOCKED_DEPENDENCY', 'promotion_evidence' => false];
    }

    private function passport(ModelMarketPerformance $candidate, string $executionTimeframe): array
    {
        $model = $candidate->modelVersion;
        $window = data_get($model->metadata, 'paper_window_key', data_get($candidate->metrics, 'paper_window_key'));
        return [...($window !== null ? ['paper_window_key' => $window] : []),
            'passport_hash' => data_get($model->metadata, 'elite_agent_passport.passport_hash', data_get($candidate->metrics, 'elite_agent_passport.passport_hash')),
            'execution_hash' => data_get(app(ExecutionContractService::class)->for($candidate->symbol, $executionTimeframe), 'execution_hash'),
            'confirmation_entry_hash' => data_get($model->metadata, 'confirmation_entry.contract_hash', data_get($candidate->metrics, 'confirmation_entry.contract_hash')),
            'risk_governor_hash' => data_get($model->metadata, 'risk_governor.hash', data_get($candidate->metrics, 'risk_governor.hash')),
            'trade_management_hash' => data_get($model->metadata, 'trade_management.hash', data_get($candidate->metrics, 'trade_management.hash')),
            'training_pre_2026' => data_get($candidate->metrics, 'training_boundary.used_for_training') === false && data_get($candidate->metrics, 'gold_holdout.used_for_training') === false];
    }
}
