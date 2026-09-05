<?php

namespace App\Services;

/**
 * Separates executable-context feasibility from learning-memory selection.
 *
 * Guided and memory-blinded selectors may rank genes differently, but neither
 * may spend a nine-fold replay on a parameter whose required market/runtime
 * context was absent across the complete frozen-control folds.
 */
class CausalParameterActivationService
{
    public const MANIFEST_PROTOCOL = 'causal_parameter_activation_manifest_v1';

    public const MASK_PROTOCOL = 'causal_feasibility_mask_v2';

    /** @var array<string, array<string, mixed>> */
    private const CONFIG_DEPENDENCIES = [
        'meta_label_min_history' => ['meta_label_enabled' => true],
        'meta_label_min_pf' => ['meta_label_enabled' => true],
        'meta_label_risk_multiplier' => ['meta_label_enabled' => true],
        'confidence_calibration_min_samples' => ['confidence_calibration_enabled' => true],
        'confidence_ev_lower_bound_enabled' => ['confidence_calibration_enabled' => true],
        'cooldown_shadow_min_samples' => ['dynamic_cooldown_enabled' => true],
        'cooldown_shadow_edge_pf' => ['dynamic_cooldown_enabled' => true],
        'signal_max_age_candles' => [
            'temporal_survival_enabled' => true,
            'adaptive_signal_expiry_enabled' => true,
        ],
        'signal_decay_half_life_candles' => [
            'temporal_survival_enabled' => true,
            'adaptive_signal_expiry_enabled' => true,
        ],
        'temporal_drift_zscore_max' => [
            'temporal_survival_enabled' => true,
            'drift_abstention_enabled' => true,
        ],
        'temporal_drift_lookback_candles' => [
            'temporal_survival_enabled' => true,
            'drift_abstention_enabled' => true,
        ],
        'partial_target_atr_multiplier' => ['partial_take_profit_fraction' => '__positive__'],
    ];

    /** @return array<string, mixed> */
    public function status(string $gene, mixed $old, mixed $new, array $manifest, array $baseline = []): array
    {
        $configuration = $this->configurationStatus($gene, $baseline);
        if ((string) $configuration['status'] === 'unsupported') {
            return $configuration;
        }

        $facts = (array) data_get($manifest, 'facts', []);
        if (! $this->complete($manifest)) {
            return [
                'gene' => $gene,
                'status' => 'unknown_allowed',
                'reason' => 'complete_frozen_control_activation_manifest_unavailable',
            ];
        }

        $supported = match ($gene) {
            'high_volatility_risk_multiplier', 'avoid_high_volatility' =>
                (int) data_get($facts, 'high_volatility_trades', 0) > 0,
            'max_loss_streak_before_wait' =>
                (int) data_get($facts, 'maximum_consecutive_losses', 0)
                    >= min((int) $old, (int) $new),
            'loss_cooldown_candles' => (int) data_get($facts, 'total_losses', 0) > 0,
            'time_stop_candles', 'partial_take_profit_fraction' =>
                (int) data_get($facts, 'total_trades', 0) > 0,
            default => null,
        };

        return [
            'gene' => $gene,
            'status' => $supported === false ? 'unsupported' : ($supported === true ? 'supported' : 'unknown_allowed'),
            'reason' => $supported === false
                ? 'required_context_absent_across_complete_frozen_control_folds'
                : ($supported === true ? 'required_context_observed_in_frozen_control' : 'gene_has_no_safe_activation_predicate'),
            'facts' => $facts,
        ];
    }

    /** @return array<string, mixed> */
    public function configurationStatus(string $gene, array $baseline): array
    {
        $dependencies = self::CONFIG_DEPENDENCIES[$gene] ?? [];
        if ($dependencies === []) {
            return [
                'gene' => $gene,
                'status' => 'unknown_allowed',
                'reason' => 'gene_has_no_configuration_dependency',
                'configuration_dependencies' => [],
            ];
        }

        $missing = [];
        foreach ($dependencies as $dependency => $required) {
            $actual = $baseline[$dependency] ?? null;
            $satisfied = $required === '__positive__'
                ? is_numeric($actual) && (float) $actual > 0
                : $actual === $required;
            if (! $satisfied) {
                $missing[] = [
                    'gene' => $dependency,
                    'required' => $required,
                    'actual' => $actual,
                ];
            }
        }

        return [
            'gene' => $gene,
            'status' => $missing === [] ? 'supported' : 'unsupported',
            'reason' => $missing === []
                ? 'executable_configuration_dependencies_satisfied'
                : 'executable_configuration_dependency_inactive',
            'configuration_dependencies' => $dependencies,
            'inactive_dependencies' => $missing,
        ];
    }

    public function complete(array $manifest): bool
    {
        return (string) data_get($manifest, 'protocol') === self::MANIFEST_PROTOCOL
            && (string) data_get($manifest, 'status') === 'complete'
            && (int) data_get($manifest, 'observed_folds', 0)
                >= (int) config('services.learning_lane.causal_minimum_powered_windows', 6);
    }

    public function hash(array $manifest): ?string
    {
        if (! $this->complete($manifest)) {
            return null;
        }

        return hash('sha256', json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }
}
