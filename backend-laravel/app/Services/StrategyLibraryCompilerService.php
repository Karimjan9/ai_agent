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
            $this->spec('mix_001_trend_beast', 'hybrid', 'trend', ['ema_alignment', 'ema_slope'], ['ema_pullback'], ['closed_candle_rejection'], ['rsi_zone', 'adx_strength'], ['ema_fast', 'ema_slow', 'adx_min', 'atr_multiplier']),
            $this->spec('mix_002_breakout_beast', 'hybrid', 'breakout_compression', ['ema_alignment'], ['bb_compression', 'donchian_previous_break'], ['closed_candle_break'], ['adx_strength', 'atr_expansion'], ['lookback', 'bb_width_percentile']),
            $this->spec('mix_003_smc_trend_pullback', 'hybrid', 'trend', ['structure_direction', 'discount_zone'], ['liquidity_sweep', 'fvg_retest'], ['choch_event'], ['displacement'], ['swing_lookback', 'equal_level_atr_fraction']),
            $this->spec('mix_006_range_killer', 'hybrid', 'range', ['ema_slope_flat'], ['bollinger_extreme', 'zscore_extreme'], ['reentry_close'], ['rsi_zone', 'adx_weak'], ['zscore_threshold', 'adx_max']),
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

        return ['protocol' => self::PROTOCOL, 'strategy_spec' => $spec, 'feature_contract' => ['required_values' => $spec['required_values'], 'lookahead_safe' => true, 'external_values_require_available_at' => in_array($spec['family'], ['macro_fundamental', 'positioning'], true)], 'tactic_contract' => ['regime_lens' => $spec['regime'], 'bias' => $spec['bias'], 'setup' => $spec['setup'], 'trigger' => $spec['trigger'], 'confirmation' => $spec['confirmation'], 'risk_owner' => 'risk_sentinel', 'exit' => ['partial' => '1R', 'target' => '2R', 'trailing' => 'atr']], 'mutation_contract' => ['allowed' => $spec['allowed_mutations'], 'forbidden' => ['risk_owner', 'data_source', 'execution_contract'], 'one_axis_only' => true], 'lifecycle' => $spec['status'] === 'shadow_only' ? ['state' => 'SHADOW', 'routable' => false] : ['state' => 'EXECUTABLE_RESEARCH', 'routable' => false], 'promotion_evidence' => false];
    }

    private function spec(string $id, string $family, string $regime, array $bias, array $setup, array $trigger, array $confirmation, array $mutations, string $status = 'research'): array
    {
        return ['id' => $id, 'family' => $family, 'status' => $status, 'timeframes' => ['H1', 'M15'], 'regime' => ['allowed' => $regime === 'any' ? [] : [$regime], 'confidence_min' => .65], 'bias' => $bias, 'setup' => $setup, 'trigger' => $trigger, 'confirmation' => $confirmation, 'required_values' => array_values(array_unique([...$bias, ...$setup, ...$confirmation, 'atr', 'spread_atr_ratio'])), 'allowed_mutations' => $mutations, 'failure_modes' => ['range_false_signal', 'late_entry', 'high_spread', 'transition']];
    }
}
