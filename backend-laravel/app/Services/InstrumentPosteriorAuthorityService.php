<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/** Re-derive instrument/bundle authority; a mutable status label is never enough. */
class InstrumentPosteriorAuthorityService
{
    /** @return array<string,mixed> */
    public function assess(Model $posterior): array
    {
        $vector = (array) data_get($posterior, 'value_vector', []);
        $context = (array) data_get($vector, 'context', []);
        $contextContract = app(ContextContractV2Service::class)->project($context);
        $family = (string) data_get($vector, 'strategy_family', data_get($context, 'strategy_family', ''));
        $windows = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($vector, 'independent_window_keys', []),
        ))));
        $evidenceKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($vector, 'evidence_keys', []),
        ))));
        $observations = (int) data_get($posterior, 'observations', 0);
        $positive = (int) data_get($vector, 'positive_observations', 0);
        $negative = (int) data_get($vector, 'negative_observations', 0);
        $regressions = (int) data_get($vector, 'non_target_regression_count', 0);
        $net = (float) data_get($posterior, 'net_value', 0);
        $minimumObservations = max(3, (int) config('services.instrument_policy.minimum_posterior_observations', 3));
        $minimumWindows = max(3, (int) config('services.instrument_policy.minimum_independent_windows', 3));
        $positiveThreshold = max(.00001, (float) config('services.instrument_policy.minimum_confirmed_net_utility', .001));
        $negativeThreshold = min(-.00001, (float) config('services.instrument_policy.minimum_forbidden_net_utility', -.001));
        $identityValid = (string) data_get($context, 'state_key', '') !== ''
            && hash_equals((string) data_get($posterior, 'state_key', ''), (string) data_get($context, 'state_key'));
        $common = [
            'valid_context' => data_get($contextContract, 'status') === 'valid',
            'family_sealed' => $family !== '' && $family !== 'unscoped',
            'state_identity_valid' => $identityValid,
            'observation_count_valid' => $observations >= $minimumObservations,
            'evidence_identity_coverage' => count($evidenceKeys) >= $observations,
            'non_target_safe' => $regressions === 0,
        ];
        $confirmed = ! in_array(false, $common, true)
            && count($windows) >= $minimumWindows
            && $positive >= 2
            && $net >= $positiveThreshold;
        $forbidden = $common['valid_context'] && $common['family_sealed'] && $common['state_identity_valid']
            && $common['observation_count_valid'] && $common['evidence_identity_coverage']
            && count($windows) >= 2 && $negative >= 2 && $net <= $negativeThreshold;
        $label = (string) data_get($posterior, 'decay_state', 'provisional');
        $canonicalState = match (true) {
            $label === 'confirmed' && $confirmed => 'confirmed',
            $label === 'forbidden' && $forbidden => 'forbidden',
            in_array($label, ['confirmed', 'forbidden'], true) => 'status_only_quarantined',
            default => $label,
        };

        return [
            'canonical_state' => $canonicalState,
            'verified_confirmed' => $canonicalState === 'confirmed',
            'verified_forbidden' => $canonicalState === 'forbidden',
            'declared_state' => $label,
            'strategy_family' => $family ?: null,
            'independent_windows' => count($windows),
            'evidence_keys' => count($evidenceKeys),
            'positive_observations' => $positive,
            'negative_observations' => $negative,
            'non_target_regressions' => $regressions,
            'checks' => $common,
            'promotion_evidence' => false,
        ];
    }
}
