<?php

namespace App\Services;

/**
 * Pre-registers the memory-blinded arm of a causal learning experiment.
 *
 * This selector deliberately consumes no lesson, mutation memory, posterior,
 * diagnosis or bandit state. It receives the frozen executable baseline, the
 * declared failure target, a stable seed and the same non-credit feasibility
 * mask available to the guided arm. Thus memory/ranking stays blinded while
 * physically inert genes cannot consume a nine-fold replay.
 */
class CausalBlindedMutationSelectorService
{
    public const PROTOCOL = 'causal_blinded_single_gene_selector_v5';

    public const SEMANTIC_PROTOCOL = 'executable_parameter_semantic_distinctness_v1';

    /** @var array<string, array<int, string>> */
    private const TARGET_GENES = [
        'drawdown_risk' => [
            'high_volatility_risk_multiplier', 'max_loss_streak_before_wait',
            'loss_cooldown_candles', 'avoid_high_volatility',
            'atr_stop_multiplier', 'time_stop_candles', 'partial_take_profit_fraction',
        ],
        'stress_cost' => [
            'max_spread_atr_ratio', 'atr_stop_multiplier',
            'atr_target_multiplier', 'trailing_atr_multiplier',
        ],
        'profit_factor' => [
            'minimum_signal_confidence', 'atr_target_multiplier',
            'atr_stop_multiplier', 'trailing_atr_multiplier',
        ],
        'edge_quality' => [
            'minimum_signal_confidence', 'atr_target_multiplier',
            'atr_stop_multiplier', 'trailing_atr_multiplier',
        ],
        'selection_quality' => [
            'minimum_signal_confidence', 'confirmation_candles',
            'max_spread_atr_ratio', 'transition_wait_candles',
        ],
        'execution_quality' => [
            'max_spread_atr_ratio', 'atr_stop_multiplier',
            'time_stop_candles', 'entry_topology_variant',
        ],
        'management_quality' => [
            'partial_take_profit_fraction', 'time_stop_candles',
            'trailing_atr_multiplier', 'atr_target_multiplier',
        ],
        'trade_frequency' => [
            'minimum_signal_confidence', 'confirmation_candles',
            'lookback', 'loss_cooldown_candles',
        ],
        'temporal_stability' => [
            'transition_firewall_enabled', 'transition_wait_candles',
            'loss_cooldown_candles', 'weak_regime_wait_candles',
        ],
        'monthly_survival' => [
            'transition_firewall_enabled', 'session_filter_enabled',
            'loss_cooldown_candles', 'weak_regime_wait_candles',
        ],
        'regime_coverage' => [
            'trend_down_strength_min', 'trend_up_strength_min',
            'minimum_signal_confidence', 'lookback',
        ],
    ];

    /**
     * @return array{protocol:string,gene:string,old_value:mixed,value:mixed,selection_hash:string,memory_inputs:int,feasibility_inputs:int,feasibility_screen:array<string,mixed>,promotion_evidence:bool}|null
     */
    public function select(
        string $family,
        string $target,
        array $baseline,
        string $experimentSeed,
        ?string $guidedGene = null,
        mixed $guidedValue = null,
        array $activationManifest = [],
        ?array $allowedGenes = null,
    ): ?array {
        $schema = app(StrategyParameterSchemaService::class)->schema($family);
        $geneAllowed = static fn (string $gene): bool => $allowedGenes === null || in_array($gene, $allowedGenes, true);
        $preferred = array_values(array_filter(
            self::TARGET_GENES[$target] ?? [],
            fn (string $gene): bool => $geneAllowed($gene) && array_key_exists($gene, $schema) && array_key_exists($gene, $baseline),
        ));
        $fallback = array_values(array_filter(
            array_keys($schema),
            fn (string $gene): bool => $geneAllowed($gene) && array_key_exists($gene, $baseline),
        ));
        $keys = array_values(array_unique([...$preferred, ...$fallback]));
        if ($keys === []) {
            return null;
        }

        $seedHash = hash('sha256', self::PROTOCOL.'|'.$experimentSeed.'|'.$family.'|'.$target);
        $offset = (int) (hexdec(substr($seedHash, 0, 8)) % count($keys));
        $keys = [...array_slice($keys, $offset), ...array_slice($keys, 0, $offset)];
        $activations = app(CausalParameterActivationService::class);
        $manifestHash = $activations->hash($activationManifest);
        $skipped = [];

        foreach ($keys as $keyIndex => $gene) {
            $old = $baseline[$gene];
            foreach ($this->candidateValues((array) $schema[$gene], $old, $seedHash, $keyIndex) as $value) {
                if (! $this->different($old, $value)) {
                    continue;
                }
                if (! $this->semanticallyDifferent($family, $gene, $old, $value)) {
                    $skipped[] = [
                        'gene' => $gene,
                        'old_value' => $old,
                        'value' => $value,
                        'status' => 'semantic_alias',
                        'reason' => 'runtime_maps_both_values_to_the_same_executable_branch',
                    ];
                    continue;
                }
                if ($gene === $guidedGene && ! $this->different($value, $guidedValue)) {
                    continue;
                }
                $activation = $activations->status($gene, $old, $value, $activationManifest, $baseline);
                if ((string) $activation['status'] === 'unsupported') {
                    $skipped[] = $activation;
                    continue;
                }

                $selectionHash = hash('sha512', json_encode([
                    'protocol' => self::PROTOCOL,
                    'seed' => $experimentSeed,
                    'family' => $family,
                    'target' => $target,
                    'gene' => $gene,
                    'old_value' => $old,
                    'value' => $value,
                    'feasibility_manifest_hash' => $manifestHash,
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

                return [
                    'protocol' => self::PROTOCOL,
                    'gene' => $gene,
                    'old_value' => $old,
                    'value' => $value,
                    'selection_hash' => $selectionHash,
                    'memory_inputs' => 0,
                    'feasibility_inputs' => $manifestHash === null ? 0 : 1,
                    'feasibility_screen' => [
                        'protocol' => CausalParameterActivationService::MASK_PROTOCOL,
                        'status' => (string) $activation['status'],
                        'selected_gene' => $gene,
                        'selected_gene_evidence' => $activation,
                        'skipped_genes' => $skipped,
                        'manifest_hash' => $manifestHash,
                        'semantic_distinctness_protocol' => self::SEMANTIC_PROTOCOL,
                        'performance_credit' => false,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ];
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    private function candidateValues(array $definition, mixed $old, string $seedHash, int $keyIndex): array
    {
        [$type, $min, $max] = array_pad($definition, 3, null);
        if ($type === 'boolean') {
            return [! (bool) $old];
        }
        if ($type === 'string') {
            $choices = array_values(array_filter((array) $min, fn (mixed $value): bool => $this->different($old, $value)));
            if ($choices === []) {
                return [];
            }
            $offset = (int) (hexdec(substr($seedHash, 8, 8)) + $keyIndex) % count($choices);

            return [...array_slice($choices, $offset), ...array_slice($choices, 0, $offset)];
        }
        if (! in_array($type, ['integer', 'numeric'], true)
            || ! is_numeric($old) || ! is_numeric($min) || ! is_numeric($max)) {
            return [];
        }

        $span = (float) $max - (float) $min;
        $step = $type === 'integer' ? max(1, (int) round($span * .05)) : max(.0001, $span * .05);
        $firstDirection = ((hexdec(substr($seedHash, 16, 2)) + $keyIndex) % 2 === 0) ? 1 : -1;
        $values = [];
        foreach ([$firstDirection, -$firstDirection] as $direction) {
            foreach ([1, 2, 3] as $distance) {
                $value = max((float) $min, min((float) $max, (float) $old + ($direction * $distance * $step)));
                $values[] = $type === 'integer' ? (int) round($value) : round($value, 4);
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    private function different(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) > 0.000000001;
        }

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            !== json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function semanticallyDifferent(string $family, string $gene, mixed $old, mixed $new): bool
    {
        if ($gene !== 'range_signal_mode'
            || ! in_array(strtolower($family), ['hybrid', 'mean_reversion'], true)) {
            return true;
        }

        $class = static fn (mixed $value): string => in_array((string) $value, ['reentry', 'mean_reversion'], true)
            ? 'contrarian_extreme'
            : (string) $value;

        return $class($old) !== $class($new);
    }
}
