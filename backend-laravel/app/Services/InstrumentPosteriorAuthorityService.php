<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/** Re-derive exact-delta validation authority; legacy aggregates stay research-only. */
class InstrumentPosteriorAuthorityService
{
    public function validationEpochs(Model $posterior): array
    {
        $epochs = [];
        foreach ((array) data_get($posterior->value_vector, 'validation_epochs', []) as $key => $epoch) {
            if (! is_array($epoch) || (string) ($epoch['epoch_key'] ?? '') !== (string) $key) {
                continue;
            }
            $assessment = app(InstrumentValidationEvidenceService::class)->assessEpoch($epoch, (string) $posterior->state_key);
            if ($posterior->decay_state === 'decaying' || (float) data_get($posterior->value_vector, 'temporal_decay', 0) >= .5) {
                $assessment['canonical_state'] = 'decaying';
                $assessment['verified_confirmed'] = $assessment['verified_forbidden'] = false;
            }
            $epochs[] = $assessment;
        }

        return $epochs;
    }

    public function assess(Model $posterior): array
    {
        $label = (string) data_get($posterior, 'decay_state', 'provisional');
        $epochs = $this->validationEpochs($posterior);
        // A different delta's incomplete proof cannot erase a completed epoch.
        $eligible = array_values(array_filter($epochs, static fn (array $epoch): bool => in_array($epoch['canonical_state'], ['confirmed', 'forbidden'], true)));
        $selected = $eligible !== [] ? $eligible[count($eligible) - 1] : ($epochs !== [] ? $epochs[count($epochs) - 1] : null);
        if ($selected !== null) {
            if ($selected['canonical_state'] === 'provisional' && in_array($label, ['confirmed', 'forbidden'], true)) {
                $selected['canonical_state'] = 'status_only_quarantined';
            }
            if ((float) data_get($posterior->value_vector, 'temporal_decay', 0) >= .5 || $label === 'decaying') {
                $selected['canonical_state'] = 'decaying';
                $selected['verified_confirmed'] = $selected['verified_forbidden'] = false;
            }

            return [...$selected, 'declared_state' => $label,
                'discovery_observations' => (int) $posterior->observations,
                'authority_protocol' => InstrumentValidationEvidenceService::PROTOCOL];
        }

        return ['canonical_state' => in_array($label, ['confirmed', 'forbidden'], true) ? 'status_only_quarantined' : $label,
            'verified_confirmed' => false, 'verified_forbidden' => false, 'declared_state' => $label,
            'strategy_family' => data_get($posterior->value_vector, 'strategy_family'),
            'independent_windows' => 0, 'positive_independent_windows' => 0, 'negative_independent_windows' => 0,
            'evidence_keys' => 0, 'observations' => 0, 'positive_observations' => 0, 'negative_observations' => 0,
            'non_target_regressions' => 0, 'tested_intervention' => [],
            'checks' => ['sealed_window_evidence_complete' => false, 'exact_tested_delta_sealed' => false],
            'discovery_observations' => (int) $posterior->observations,
            'authority_protocol' => InstrumentValidationEvidenceService::PROTOCOL, 'promotion_evidence' => false];
    }
}
