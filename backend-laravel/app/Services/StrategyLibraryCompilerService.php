<?php

namespace App\Services;

/** One canonical strategy spec produces contracts for every runtime layer. */
class StrategyLibraryCompilerService
{
    public const PROTOCOL = 'composable_strategy_library_v1';

    /** @return array<int,array<string,mixed>> */
    public function library(): array
    {
        return [
            $this->spec('str_001_ema_adx_pullback', 'trend_following', 'trend', ['ema_alignment', 'ema_slope'], ['ema_pullback'], ['closed_candle_rejection'], ['rsi_zone', 'adx_strength'], ['ema_fast', 'ema_slow', 'adx_min', 'atr_multiplier']),
            $this->spec('str_003_donchian_breakout', 'breakout', 'breakout_compression', ['ema_alignment'], ['donchian_previous_break'], ['closed_candle_break'], ['adx_strength', 'atr_expansion'], ['lookback', 'atr_multiplier']),
            $this->spec('str_010_bollinger_squeeze', 'breakout', 'breakout_compression', ['ema_slope'], ['bb_compression'], ['closed_candle_break'], ['adx_strength', 'atr_expansion'], ['bb_period', 'bb_width_percentile']),
            $this->spec('str_020_bb_rsi_reversion', 'mean_reversion', 'range', ['ema_slope_flat'], ['bollinger_extreme'], ['reentry_close'], ['rsi_zone', 'adx_weak'], ['rsi_period', 'adx_max']),
            $this->spec('str_022_zscore_reversion', 'mean_reversion', 'range', ['ema_slope_flat'], ['zscore_extreme'], ['reentry_close'], ['adx_weak'], ['zscore_threshold']),
            $this->spec('str_031_bos_retest', 'market_structure', 'trend', ['structure_direction'], ['bos_event'], ['retest_hold'], ['displacement', 'volume_confirmation'], ['swing_lookback', 'retest_atr_fraction']),
            $this->spec('str_032_choch_reversal', 'market_structure', 'transition', ['structure_direction'], ['choch_event'], ['liquidity_sweep'], ['transition_confidence'], ['swing_lookback', 'transition_confidence_min']),
            $this->spec('str_037_fvg_retest', 'liquidity_smc', 'trend', ['structure_direction'], ['fvg_retest'], ['closed_candle_rejection'], ['liquidity_sweep'], ['fvg_mitigation_fraction']),
            $this->spec('str_040_asia_london_breakout', 'session', 'breakout_compression', ['session_bias'], ['asia_range'], ['london_break'], ['spread_normal'], ['session_start', 'session_end']),
            // This is intentionally a research-only contract until the data
            // plane can seal closed H4/H1/M15/M5 streams for one decision.
            // The OHLCV observations below are structural/liquidity proxies,
            // not a claim that unseen institutional order flow was observed.
            $this->spec(
                'str_041_liquidity_trap_mtf',
                'liquidity_smc',
                'trend',
                ['h4_direction', 'h1_external_structure', 'h1_poi_location'],
                ['m15_liquidity_trap'],
                ['m5_mss_or_choch', 'm5_displacement'],
                ['m5_fvg_or_order_block_retest', 'session_eligibility'],
                ['h4_swing_lookback', 'h1_swing_lookback', 'm15_sweep_lookback', 'm5_trigger_lookback'],
                'shadow_only',
                ['H4', 'H1', 'M15', 'M5'],
                [
                    'bias' => 'H4_closed_direction',
                    'location' => 'H1_closed_external_structure_and_poi',
                    'setup' => 'M15_closed_liquidity_trap',
                    'trigger' => 'M5_closed_mss_or_choch_with_displacement',
                    'execution' => 'next_M5_open_after_retest',
                    'invalidation' => 'M15_trap_extreme_plus_cost_buffer',
                    'target' => 'H1_internal_then_external_liquidity_proxy',
                ],
                ['h4_h1_conflict', 'poi_missing', 'trap_failure', 'trigger_missing', 'high_spread', 'late_entry'],
            ),
            $this->spec('mix_001_trend_beast', 'hybrid', 'trend', ['ema_alignment', 'ema_slope'], ['ema_pullback'], ['closed_candle_rejection'], ['rsi_zone', 'adx_strength'], ['ema_fast', 'ema_slow', 'adx_min', 'atr_multiplier']),
            $this->spec('mix_002_breakout_beast', 'hybrid', 'breakout_compression', ['ema_alignment'], ['bb_compression', 'donchian_previous_break'], ['closed_candle_break'], ['adx_strength', 'atr_expansion'], ['lookback', 'bb_width_percentile']),
            $this->spec('mix_003_smc_trend_pullback', 'hybrid', 'trend', ['structure_direction', 'discount_zone'], ['liquidity_sweep', 'fvg_retest'], ['choch_event'], ['displacement'], ['swing_lookback', 'equal_level_atr_fraction']),
            $this->spec('mix_006_range_killer', 'hybrid', 'range', ['ema_slope_flat'], ['bollinger_extreme', 'zscore_extreme'], ['reentry_close'], ['rsi_zone', 'adx_weak'], ['zscore_threshold', 'adx_max']),
            // These two specialist runtimes already exist in the laboratory
            // and Python replay registry. They must also have first-class
            // composition identities: mapping either family to the generic
            // hybrid fallback rewrites a frozen causal control before replay.
            $this->spec('mix_010_regime_ensemble', 'regime_ensemble', 'any', ['regime_classifier'], ['specialist_ownership'], ['closed_candle_router'], ['regime_confidence'], ['minimum_confidence', 'high_volatility_wait']),
            $this->spec('mix_011_differential_router', 'differential_router', 'any', ['frozen_parent_router'], ['target_regime_specialist'], ['closed_candle_router'], ['non_target_parent_freeze'], ['differential_target_min_signal_confidence', 'trend_up_strength_min', 'trend_down_strength_min', 'differential_router_version', 'range_deviation']),
            $this->spec('str_050_macro_bias', 'macro_fundamental', 'any', ['macro_bias'], ['technical_setup'], ['closed_candle_confirmation'], ['news_safe'], [], 'shadow_only'),
            $this->spec('str_060_cot_filter', 'positioning', 'any', ['cot_bias'], ['technical_setup'], ['closed_candle_confirmation'], ['cot_available_at'], [], 'shadow_only'),
        ];
    }

    /** @return array<string,mixed> */
    public function compile(string $id): array
    {
        $spec = collect($this->library())->firstWhere('id', $id);
        if (! $spec) {
            throw new \InvalidArgumentException("Unknown strategy library id: {$id}");
        }

        return ['protocol' => self::PROTOCOL, 'strategy_spec' => $spec, 'feature_contract' => ['required_values' => $spec['required_values'], 'lookahead_safe' => true, 'external_values_require_available_at' => in_array($spec['family'], ['macro_fundamental', 'positioning'], true)], 'temporal_role_contract' => ['required_roles' => $spec['temporal_roles'], 'timeframe_hardcode_forbidden' => true, 'execution_and_invalidation_may_differ' => true], 'tactic_contract' => ['regime_lens' => $spec['regime'], 'bias' => $spec['bias'], 'setup' => $spec['setup'], 'trigger' => $spec['trigger'], 'confirmation' => $spec['confirmation'], 'risk_owner' => 'risk_sentinel', 'exit' => ['partial' => '1R', 'target' => '2R', 'trailing' => 'atr']], 'mutation_contract' => ['allowed' => $spec['allowed_mutations'], 'forbidden' => ['risk_owner', 'data_source', 'execution_contract'], 'one_axis_only' => true], 'lifecycle' => $spec['status'] === 'shadow_only' ? ['state' => 'SHADOW', 'routable' => false] : ['state' => 'EXECUTABLE_RESEARCH', 'routable' => false], 'promotion_evidence' => false];
    }

    /**
     * Map an executable research spec to a runtime family/topology already
     * implemented by the replay engine. Shadow-only macro/positioning specs
     * deliberately return null: external data availability cannot be faked.
     *
     * @return array{family:string, architecture:string}|null
     */
    public function runtime(string $id): ?array
    {
        $spec = collect($this->library())->firstWhere('id', $id);
        if (! $spec || (string) $spec['status'] === 'shadow_only') return null;

        return match ($id) {
            'str_001_ema_adx_pullback' => ['family' => 'trend', 'architecture' => 'trend_pullback'],
            'str_003_donchian_breakout' => ['family' => 'breakout', 'architecture' => 'breakout_retest'],
            'str_010_bollinger_squeeze' => ['family' => 'volatility', 'architecture' => 'volatility_compression_expansion'],
            'str_020_bb_rsi_reversion' => ['family' => 'mean_reversion', 'architecture' => 'range_mean_reversion'],
            'str_022_zscore_reversion' => ['family' => 'mean_reversion', 'architecture' => 'range_rsi_reversion'],
            'str_031_bos_retest', 'str_037_fvg_retest' => ['family' => 'trend', 'architecture' => 'trend_breakout_retest'],
            'str_032_choch_reversal' => ['family' => 'hybrid', 'architecture' => 'regime_consensus'],
            'str_040_asia_london_breakout' => ['family' => 'session', 'architecture' => 'session_breakout'],
            'mix_001_trend_beast', 'mix_002_breakout_beast', 'mix_003_smc_trend_pullback' => ['family' => 'hybrid', 'architecture' => 'regime_router'],
            'mix_006_range_killer' => ['family' => 'hybrid', 'architecture' => 'regime_consensus'],
            'mix_010_regime_ensemble' => ['family' => 'regime_ensemble', 'architecture' => 'frozen_regime_specialist_ensemble'],
            'mix_011_differential_router' => ['family' => 'differential_router', 'architecture' => 'frozen_parent_differential_router'],
            default => null,
        };
    }

    /**
     * Declare the closed-regime envelope the selected Python strategy runtime
     * can emit into. This is capability metadata only; it does not make a
     * signal more permissive or claim that any historical candle activated.
     * An unknown runtime has no provable scope and must not be composed.
     *
     * @return array{protocol:string,runtime:string,regimes:array<int,string>}
     */
    public function signalScope(string $baseStrategy): array
    {
        $runtime = strtolower(trim($baseStrategy));
        $regimes = match ($runtime) {
            'trend_v1', 'trend_pullback_v1', 'trend_retest_v1', 'trend_breakout_retest_v1',
            'momentum_v1', 'momentum_pullback_v1' => ['trend_up', 'trend_down'],
            'breakout_v1', 'breakout_continuation_v1', 'volatility_v1',
            'volatility_breakout_v1', 'session_v1' => ['trend_up', 'trend_down', 'high_volatility'],
            'mean_reversion_v1', 'range_rsi_reversion_v1', 'session_mean_reversion_v1' => ['range', 'low_volatility'],
            'hybrid_v1', 'regime_consensus_v1' => ['trend_up', 'trend_down', 'range', 'unknown', 'transition', 'high_volatility'],
            'differential_router_v1' => ['trend_up', 'trend_down', 'range'],
            'regime_ensemble_v1' => ['trend_up', 'trend_down', 'range', 'high_volatility'],
            default => [],
        };

        return [
            'protocol' => 'strategy_signal_scope_v1',
            'runtime' => $baseStrategy,
            'regimes' => $regimes,
        ];
    }

    /** @return string|null The concrete registry key owned by this library strategy. */
    public function runtimeBaseStrategy(string $id): ?string
    {
        return match ($id) {
            'str_001_ema_adx_pullback' => 'trend_v1',
            'str_031_bos_retest', 'str_037_fvg_retest' => 'trend_retest_v1',
            'str_003_donchian_breakout' => 'breakout_v1',
            'str_010_bollinger_squeeze' => 'volatility_v1',
            'str_020_bb_rsi_reversion' => 'mean_reversion_v1',
            'str_022_zscore_reversion' => 'range_rsi_reversion_v1',
            'str_032_choch_reversal', 'mix_006_range_killer' => 'regime_consensus_v1',
            'str_040_asia_london_breakout' => 'session_v1',
            'mix_001_trend_beast', 'mix_002_breakout_beast', 'mix_003_smc_trend_pullback' => 'hybrid_v1',
            'mix_010_regime_ensemble' => 'regime_ensemble_v1',
            'mix_011_differential_router' => 'differential_router_v1',
            default => null,
        };
    }

    private function spec(
        string $id,
        string $family,
        string $regime,
        array $bias,
        array $setup,
        array $trigger,
        array $confirmation,
        array $mutations,
        string $status = 'research',
        array $timeframes = ['H1', 'M15'],
        ?array $temporalRoles = null,
        ?array $failureModes = null,
    ): array
    {
        return [
            'id' => $id,
            'family' => $family,
            'status' => $status,
            'timeframes' => $timeframes,
            'regime' => ['allowed' => $regime === 'any' ? [] : [$regime], 'confidence_min' => .65],
            'bias' => $bias,
            'setup' => $setup,
            'trigger' => $trigger,
            'confirmation' => $confirmation,
            'required_values' => array_values(array_unique([...$bias, ...$setup, ...$confirmation, 'atr', 'spread_atr_ratio'])),
            'allowed_mutations' => $mutations,
            'temporal_roles' => $temporalRoles ?? [
                'bias' => 'regime_direction',
                'setup' => 'location_or_compression',
                'trigger' => 'closed_candle_confirmation',
                'execution' => 'cost_aware_entry',
                'invalidation' => 'trade_idea_failure',
            ],
            'failure_modes' => $failureModes ?? ['range_false_signal', 'late_entry', 'high_spread', 'transition'],
        ];
    }
}
