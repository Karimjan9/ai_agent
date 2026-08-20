<?php

namespace App\Services;

use App\Models\InstrumentEvidence;
use App\Models\InstrumentValuePosterior;
use App\Models\MarketStateSnapshot;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\RouterDecision;
use App\Models\TradingInstrument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Routes an observed market state to an executable playbook, including a
 * deliberate abstention.  This is a research router: it records decisions
 * and evidence but never promotes an instrument into live execution.
 */
class TradingInstrumentOperatingSystemService
{
    public const PROTOCOL = 'trading_instrument_operating_system_v1';

    public function seedDefaults(): array
    {
        return DB::transaction(function (): array {
            $instruments = collect($this->instrumentDefinitions())->mapWithKeys(function (array $definition, string $key): array {
                $instrument = TradingInstrument::updateOrCreate(['instrument_key' => $key], Arr::only($definition, ['label', 'role', 'tactic_id', 'promotion_state', 'is_abstention', 'definition']));
                $instrument->contract()->updateOrCreate([], $definition['contract']);

                return [$key => $instrument];
            });

            foreach ($this->playbookDefinitions() as $playbook) {
                PlaybookComposition::updateOrCreate(['playbook_key' => $playbook['playbook_key']], $playbook);
            }

            return ['instruments' => $instruments->values(), 'playbooks' => PlaybookComposition::query()->orderBy('playbook_key')->get()];
        });
    }

    public function supports(string $symbol, string $timeframe): bool
    {
        $this->seedDefaults();

        return PlaybookComposition::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->exists();
    }

    /** @return array<string,mixed> */
    public function fingerprint(string $symbol, string $timeframe, array $context = []): array
    {
        $state = MarketStateSnapshot::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('time')->first();
        $regime = (string) ($context['regime'] ?? $context['h1_regime'] ?? $state?->market_state ?? 'unknown');
        $session = (string) ($context['session'] ?? $this->sessionFor((int) now()->format('H')));
        $volatility = (string) ($context['volatility'] ?? $this->volatilityFor($state));
        $spread = array_key_exists('spread_atr_ratio', $context) && is_numeric($context['spread_atr_ratio'])
            ? (float) $context['spread_atr_ratio'] : null;
        $transition = (bool) ($context['transition'] ?? $regime === 'transition');
        $lossStreak = max(0, (int) ($context['loss_streak'] ?? 0));
        $liquidity = (string) ($context['liquidity'] ?? $state?->liquidity_state ?? 'unknown');

        $fingerprint = [
            'regime' => $regime, 'm15_regime' => (string) ($context['m15_regime'] ?? $regime), 'session' => $session,
            'volatility' => $volatility, 'spread_atr_ratio' => $spread, 'spread_state' => $spread === null ? 'unknown' : ($spread > .25 ? 'high' : 'normal'),
            'liquidity' => $liquidity, 'transition' => $transition, 'loss_streak' => $lossStreak,
            'volume' => $context['volume'] ?? null, 'news_risk' => (bool) ($context['news_risk'] ?? false),
        ];
        $fingerprint['state_key'] = implode('|', [$regime, $session, $volatility, $fingerprint['spread_state'], $transition ? 'transition' : 'stable', min(9, $lossStreak)]);

        return $fingerprint;
    }

    /** @return array<string,mixed> */
    public function route(string $symbol, string $timeframe, array $context = []): array
    {
        $this->seedDefaults();
        $state = $this->fingerprint($symbol, $timeframe, $context);
        $playbooks = PlaybookComposition::query()->where(fn ($query) => $query->whereNull('symbol')->orWhere('symbol', $symbol))
            ->where(fn ($query) => $query->whereNull('timeframe')->orWhere('timeframe', $timeframe))->get();
        $instruments = TradingInstrument::query()->with('contract')->whereIn('instrument_key', $playbooks->flatMap(fn (PlaybookComposition $p) => $p->instrument_keys)->unique())->get()->keyBy('instrument_key');
        $candidates = $playbooks->filter(fn (PlaybookComposition $playbook) => $this->eligible($playbook, $instruments, $state))
            ->map(fn (PlaybookComposition $playbook) => $this->candidate($playbook, $instruments, $symbol, $timeframe, $state))->sortByDesc('score')->values();
        $emergency = $candidates->first(fn (array $candidate) => $candidate['abstention']);
        $selected = $emergency ?: $candidates->first();
        $decision = $selected && ! $selected['abstention'] ? 'TRADE' : 'ABSTAIN';
        $reason = $selected['reason_code'] ?? 'NO_ELIGIBLE_PLAYBOOK';
        $decisionKey = (string) ($context['decision_key'] ?? Str::uuid());
        $row = RouterDecision::updateOrCreate(['decision_key' => $decisionKey], [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key'],
            'playbook_composition_id' => $selected['playbook']->id ?? null, 'decision' => $decision, 'reason_code' => $reason,
            'state_fingerprint' => $state, 'candidates' => $candidates->map(fn (array $candidate) => Arr::except($candidate, 'playbook'))->all(),
            'metadata' => ['protocol' => self::PROTOCOL], 'decided_at' => now(),
        ]);

        return [
            'decision' => $decision,
            'reason_code' => $reason,
            'state' => $state,
            'playbook' => $selected['playbook'] ?? null,
            'candidate' => $selected,
            'candidates' => $candidates->map(fn (array $candidate): array => Arr::except($candidate, 'playbook'))->all(),
            'router_decision' => $row,
        ];
    }

    /** Persist paired-control outcome and update the conditional posterior. */
    public function recordEvidence(string $instrumentKey, string $symbol, string $timeframe, array $context, array $outcome): InstrumentValuePosterior
    {
        $this->assertPairedControl($outcome);
        $this->seedDefaults();
        $instrument = TradingInstrument::query()->where('instrument_key', $instrumentKey)->firstOrFail();
        $state = $this->fingerprint($symbol, $timeframe, $context);
        $metrics = (array) ($outcome['metrics'] ?? $outcome);
        $vector = $this->valueVector($metrics, $instrument->is_abstention);

        return DB::transaction(function () use ($instrument, $symbol, $timeframe, $state, $outcome, $metrics, $vector): InstrumentValuePosterior {
            $evidence = InstrumentEvidence::firstOrCreate(['evidence_key' => (string) ($outcome['evidence_key'] ?? Str::uuid())], [
                'trading_instrument_id' => $instrument->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key'],
                'outcome_state' => (string) ($outcome['outcome_state'] ?? 'observed'), 'source_type' => $outcome['source_type'] ?? null,
                'source_key' => $outcome['source_key'] ?? null, 'metrics' => $metrics, 'control_metrics' => $outcome['control_metrics'] ?? null,
                'metadata' => ['protocol' => self::PROTOCOL, 'state' => $state], 'observed_at' => $outcome['observed_at'] ?? now(),
            ]);
            $scope = ['trading_instrument_id' => $instrument->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key']];
            if (! $evidence->wasRecentlyCreated) {
                return InstrumentValuePosterior::firstOrCreate($scope, ['value_vector' => []]);
            }
            $posterior = InstrumentValuePosterior::firstOrNew($scope);
            $oldN = (int) ($posterior->observations ?? 0);
            $n = $oldN + 1;
            $net = (($oldN * (float) ($posterior->net_value ?? 0)) + $vector['conditional_net_utility']) / $n;
            $uncertainty = max(.05, 1 / sqrt($n));
            $posterior->fill(['observations' => $n, 'net_value' => $net, 'uncertainty' => $uncertainty, 'decay_state' => $this->decayState($n, $net, $vector), 'value_vector' => $vector, 'last_observed_at' => now()])->save();

            return $posterior;
        });
    }

    public function recordPlaybookEvidence(string $playbookKey, string $symbol, string $timeframe, array $context, array $outcome): PlaybookValuePosterior
    {
        $this->assertPairedControl($outcome);
        $playbook = PlaybookComposition::query()->where('playbook_key', $playbookKey)->firstOrFail();
        $state = $this->fingerprint($symbol, $timeframe, $context);
        $vector = $this->valueVector((array) ($outcome['metrics'] ?? $outcome), (bool) data_get($playbook->metadata, 'abstention', false));

        return DB::transaction(function () use ($playbook, $symbol, $timeframe, $state, $vector): PlaybookValuePosterior {
            $scope = ['playbook_composition_id' => $playbook->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key']];
            $posterior = PlaybookValuePosterior::firstOrNew($scope);
            $oldN = (int) ($posterior->observations ?? 0);
            $n = $oldN + 1;
            $net = (($oldN * (float) ($posterior->net_value ?? 0)) + $vector['conditional_net_utility']) / $n;
            $posterior->fill(['observations' => $n, 'net_value' => $net, 'uncertainty' => max(.05, 1 / sqrt($n)), 'decay_state' => $this->decayState($n, $net, $vector), 'value_vector' => $vector, 'last_observed_at' => now()])->save();

            return $posterior;
        });
    }

    private function eligible(PlaybookComposition $playbook, $instruments, array $state): bool
    {
        if (! (bool) data_get($playbook->metadata, 'abstention', false) && $state['spread_atr_ratio'] === null) {
            return false;
        }
        foreach ((array) $playbook->instrument_keys as $key) {
            $instrument = $instruments->get($key);
            if (! $instrument || ! $this->contractMatches($instrument, $state)) {
                return false;
            }
        }
        foreach ((array) ($playbook->preconditions ?? []) as $key => $expected) {
            if (! in_array($state[$key] ?? null, (array) $expected, true)) {
                return false;
            }
        }

        return true;
    }

    private function contractMatches(TradingInstrument $instrument, array $state): bool
    {
        $contract = $instrument->contract;
        if (! $contract) {
            return true;
        }
        foreach ((array) $contract->required_inputs as $input) {
            if (! array_key_exists($input, $state) || $state[$input] === null) {
                return false;
            }
        }
        if (in_array($state['regime'], (array) $contract->forbidden_regimes, true)) {
            return false;
        }
        $compatible = (array) $contract->compatible_regimes;

        return ! $compatible || in_array($state['regime'], $compatible, true);
    }

    private function candidate(PlaybookComposition $playbook, $instruments, string $symbol, string $timeframe, array $state): array
    {
        $lead = $instruments->get($playbook->instrument_keys[0]);
        $posterior = PlaybookValuePosterior::query()->where('playbook_composition_id', $playbook->id)->where('symbol', $symbol)->where('timeframe', $timeframe)->where('state_key', $state['state_key'])->first();
        $posterior ??= InstrumentValuePosterior::query()->where('trading_instrument_id', $lead->id)->where('symbol', $symbol)->where('timeframe', $timeframe)->where('state_key', $state['state_key'])->first();
        $abstention = (bool) ($playbook->metadata['abstention'] ?? false);
        $score = $posterior ? (float) $posterior->net_value - ((float) $posterior->uncertainty * .2) : ($abstention ? .1 : .05);
        if ($abstention) {
            $score += 10;
        } // safety instruments always dominate when their precondition matches

        return ['playbook' => $playbook, 'playbook_key' => $playbook->playbook_key, 'instrument_keys' => $playbook->instrument_keys, 'score' => round($score, 6), 'observations' => $posterior?->observations ?? 0, 'abstention' => $abstention, 'reason_code' => $abstention ? ($playbook->metadata['reason_code'] ?? 'RISK_ABSTENTION') : 'BEST_CONDITIONAL_NET_VALUE'];
    }

    private function valueVector(array $metrics, bool $abstention): array
    {
        $edge = (float) ($metrics['net_edge'] ?? $metrics['cost_adjusted_return'] ?? 0);
        $cost = max(0, (float) ($metrics['cost_penalty'] ?? 0));
        $drawdown = max(0, (float) ($metrics['drawdown_penalty'] ?? $metrics['drawdown_percent'] ?? 0) / 100);
        $uncertainty = max(0, (float) ($metrics['uncertainty'] ?? 0));
        $survival = (float) ($metrics['survival_value'] ?? $metrics['temporal_survival'] ?? 0);
        $coverage = (float) ($metrics['regime_coverage_value'] ?? 0);
        $abstentionValue = $abstention ? (float) ($metrics['abstention_quality'] ?? 0) : 0;
        $lift = (float) ($metrics['incremental_lift'] ?? 0);

        return ['net_edge_after_cost' => $edge, 'profit_factor' => (float) ($metrics['profit_factor'] ?? 0), 'drawdown_penalty' => $drawdown, 'adverse_excursion' => (float) ($metrics['adverse_excursion'] ?? 0), 'trade_frequency' => (float) ($metrics['trade_frequency'] ?? 0), 'regime_coverage_value' => $coverage, 'survival_value' => $survival, 'temporal_decay' => (float) ($metrics['temporal_decay'] ?? 0), 'spread_sensitivity' => (float) ($metrics['spread_sensitivity'] ?? 0), 'non_target_regression' => (float) ($metrics['non_target_regression'] ?? 0), 'abstention_value' => $abstentionValue, 'uncertainty' => $uncertainty, 'incremental_lift' => $lift, 'conditional_net_utility' => $edge - $cost - $drawdown - $uncertainty + $survival + $coverage + $abstentionValue + $lift];
    }

    private function assertPairedControl(array $outcome): void
    {
        $control = (array) ($outcome['control_metrics'] ?? []);
        if ($control === [] || ! (bool) data_get($outcome, 'control_contract.paired_isolated', false)) {
            throw new InvalidArgumentException('Instrument evidence faqat paired-isolated control metrics bilan yoziladi.');
        }
    }

    private function decayState(int $observations, float $net, array $vector): string
    {
        if ((float) $vector['temporal_decay'] >= .5) {
            return 'decaying';
        }
        if ($observations >= 5 && $net < -.1) {
            return 'forbidden';
        }

        return $observations >= 5 && $net >= .1 ? 'confirmed' : 'provisional';
    }

    private function volatilityFor(?MarketStateSnapshot $state): string
    {
        if (! $state) {
            return 'unknown';
        }
        if (max((float) $state->expansion_score, (float) $state->panic_score) >= 70) {
            return 'high';
        }

        return (float) $state->compression_score >= 70 ? 'low' : 'normal';
    }

    private function sessionFor(int $hour): string
    {
        return $hour >= 12 && $hour <= 16 ? 'london_new_york_overlap' : ($hour >= 7 && $hour < 12 ? 'london' : ($hour > 16 && $hour <= 21 ? 'new_york' : 'asian'));
    }

    private function instrumentDefinitions(): array
    {
        $execution = ['compatible_regimes' => [], 'forbidden_regimes' => [], 'required_inputs' => [], 'allowed_genes' => [], 'cost_model' => ['spread_aware' => true], 'risk_model' => [], 'control_contract' => ['mode' => 'paired_isolated'], 'contract' => ['protocol' => self::PROTOCOL]];

        return [
            'trend_pullback' => ['label' => 'Trend Pullback', 'role' => 'tactic', 'tactic_id' => 'trend_following_pullback', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'H1 trend with M15 pullback.'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime', 'session', 'volatility', 'spread_atr_ratio'], 'allowed_genes' => ['ema_fast', 'ema_slow', 'pullback_atr_fraction']]],
            'breakout_retest' => ['label' => 'Breakout Retest', 'role' => 'tactic', 'tactic_id' => 'donchian_atr_breakout_retest', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Range break, measured retest and cost gate.'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down', 'high_volatility'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime', 'spread_atr_ratio'], 'allowed_genes' => ['lookback', 'atr_multiplier', 'retest_required']]],
            'compression_expansion' => ['label' => 'Compression Expansion', 'role' => 'tactic', 'tactic_id' => 'atr_squeeze_expansion', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Low-volatility compression followed by measured expansion.'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['volatility'], 'allowed_genes' => ['compression_ratio', 'expansion_multiplier']]],
            'session_breakout' => ['label' => 'London/NY Session Breakout', 'role' => 'tactic', 'tactic_id' => 'session_range_breakout', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Liquid-session range break.'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['session', 'spread_atr_ratio'], 'allowed_genes' => ['session_start', 'session_end', 'lookback']]],
            'range_reentry' => ['label' => 'Range Re-entry', 'role' => 'tactic', 'tactic_id' => 'bollinger_zscore_reentry', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Band/z-score re-entry in a low ADX range.'], 'contract' => [...$execution, 'compatible_regimes' => ['range', 'low_volatility'], 'forbidden_regimes' => ['transition', 'high_volatility'], 'required_inputs' => ['regime', 'volatility'], 'allowed_genes' => ['lookback', 'deviation', 'adx_max']]],
            'dynamic_fibonacci_zone' => ['label' => 'Dynamic Fibonacci Zone', 'role' => 'market_lens', 'tactic_id' => 'fibonacci_structure_pullback', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['zone_width', 'distance_atr', 'swing_range']], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'equal_level_atr_fraction']]],
            'confirmed_swing' => ['label' => 'Confirmed Swing', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['swing_high', 'swing_low', 'distance_atr']], 'contract' => $execution],
            'bos_event' => ['label' => 'Break of Structure', 'role' => 'market_lens', 'tactic_id' => 'bos_retest_continuation', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['break_displacement', 'retest_quality', 'false_break_probability']], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'minimum_displacement_atr', 'retest_atr_fraction']]],
            'choch_event' => ['label' => 'Change of Character', 'role' => 'market_lens', 'tactic_id' => 'choch_reversal', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['transition_confidence', 'break_displacement']], 'contract' => [...$execution, 'compatible_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'transition_confidence_min']]],
            'support_resistance_zone' => ['label' => 'Support/Resistance Zone', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['strength', 'zone_width', 'touch_count', 'zone_age']], 'contract' => [...$execution, 'compatible_regimes' => ['range'], 'required_inputs' => ['regime']]],
            'liquidity_pool' => ['label' => 'Liquidity Pool Proxy', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['equal_high_low', 'clustered_swing', 'liquidity_score']], 'contract' => $execution],
            'liquidity_sweep' => ['label' => 'Liquidity Sweep Proxy', 'role' => 'market_lens', 'tactic_id' => 'liquidity_sweep_reversion', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['liquidity_score', 'failure_probability']], 'contract' => [...$execution, 'compatible_regimes' => ['range', 'trend_up', 'trend_down', 'transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'equal_level_atr_fraction', 'zone_strength_min']]],
            'session_range' => ['label' => 'Session Range', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['range_width', 'break_displacement', 'session']], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['session']]],
            'volume_confirmation' => ['label' => 'Volume Confirmation', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['relative_volume', 'confirmation']], 'contract' => $execution],
            'atr_risk_envelope' => ['label' => 'ATR Risk Envelope', 'role' => 'execution', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['stop' => 'ATR', 'target' => 'ATR', 'partial_take_profit' => true], 'contract' => $execution],
            'cost_firewall' => ['label' => 'Cost Firewall', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait_when_spread_abnormal'], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['spread_atr_ratio']]],
            'high_volatility_firewall' => ['label' => 'High-Volatility Firewall', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait_or_reduce_risk'], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['volatility']]],
            'transition_protection' => ['label' => 'Transition Protection', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait'], 'contract' => [...$execution, 'compatible_regimes' => ['transition'], 'required_inputs' => ['regime']]],
            'loss_streak_cooldown' => ['label' => 'Loss-Streak Cooldown', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait_after_loss_streak'], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['loss_streak']]],
            'cost_aware_exit' => ['label' => 'Cost-Aware Exit', 'role' => 'execution', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['target_stop_time_stop' => 'spread_and_volatility_aware'], 'contract' => $execution],
        ];
    }

    private function playbookDefinitions(): array
    {
        $trade = ['symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'provisional', 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => false]];

        return [
            [...$trade, 'playbook_key' => 'xauusd_trend_pullback_v1', 'label' => 'XAUUSD Trend Pullback', 'instrument_keys' => ['trend_pullback', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_breakout_retest_v1', 'label' => 'XAUUSD Breakout Retest', 'instrument_keys' => ['breakout_retest', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_compression_expansion_v1', 'label' => 'XAUUSD Compression Expansion', 'instrument_keys' => ['compression_expansion', 'atr_risk_envelope'], 'preconditions' => ['volatility' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_london_ny_breakout_v1', 'label' => 'XAUUSD London/NY Breakout', 'instrument_keys' => ['session_breakout', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['session' => ['london', 'london_new_york_overlap'], 'spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_range_reentry_v1', 'label' => 'XAUUSD Range Re-entry', 'instrument_keys' => ['range_reentry', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['volatility' => ['low', 'normal']]],
            [...$trade, 'playbook_key' => 'xauusd_fibonacci_structure_pullback_v1', 'label' => 'XAUUSD Fibonacci Structure Pullback', 'instrument_keys' => ['dynamic_fibonacci_zone', 'confirmed_swing', 'liquidity_sweep', 'atr_risk_envelope'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_bos_retest_continuation_v1', 'label' => 'XAUUSD BOS Retest Continuation', 'instrument_keys' => ['bos_event', 'confirmed_swing', 'volume_confirmation', 'atr_risk_envelope'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_choch_reversal_v1', 'label' => 'XAUUSD CHOCH Reversal', 'instrument_keys' => ['choch_event', 'liquidity_sweep', 'atr_risk_envelope'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_liquidity_sweep_reversion_v1', 'label' => 'XAUUSD Liquidity Sweep Reversion', 'instrument_keys' => ['support_resistance_zone', 'liquidity_pool', 'liquidity_sweep', 'cost_aware_exit'], 'preconditions' => ['spread_state' => ['normal']]],
            ['playbook_key' => 'xauusd_high_volatility_wait_v1', 'label' => 'XAUUSD High-Volatility Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['high_volatility_firewall'], 'preconditions' => ['volatility' => ['high']], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'HIGH_VOLATILITY_FIREWALL']],
            ['playbook_key' => 'xauusd_transition_wait_v1', 'label' => 'XAUUSD Transition Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['transition_protection'], 'preconditions' => ['regime' => ['transition']], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'TRANSITION_FIREWALL']],
            ['playbook_key' => 'xauusd_cost_wait_v1', 'label' => 'XAUUSD Cost Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['cost_firewall'], 'preconditions' => ['spread_state' => ['high']], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'COST_FIREWALL']],
            ['playbook_key' => 'xauusd_loss_streak_wait_v1', 'label' => 'XAUUSD Loss-Streak Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['loss_streak_cooldown'], 'preconditions' => ['loss_streak' => [4, 5, 6, 7, 8, 9]], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'LOSS_STREAK_COOLDOWN']],
        ];
    }
}
