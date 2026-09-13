<?php

namespace App\Services;

/** Builds the exact, local identity of one temporary specialist organism. */
class ContextualSpecialistIdentityService
{
    public const PROTOCOL = 'contextual_specialist_identity_v1';

    /** @return array<string, mixed> */
    public function build(
        array $cell,
        array $niche = [],
        string $strategy = '',
        array $tactic = [],
        array $instrumentPolicy = [],
        array $composition = [],
    ): array {
        $instrumentBundle = array_values(array_unique(array_filter(array_map('strval', [
            ...(array) data_get($niche, 'instrument_pair.instrument_ids', []),
            ...(array) data_get($niche, 'instrument_bundle.instrument_keys', []),
            ...(array) data_get($niche, 'cooperative_evolution_capsule.components.toolbox_instrument', []),
            ...(array) data_get($instrumentPolicy, 'preferred_genes', []),
        ]))));
        sort($instrumentBundle);
        $identity = [
            'regime' => data_get($cell, 'regime'),
            'venue_phase' => data_get($cell, 'venue_phase'),
            'session' => data_get($cell, 'session'),
            'session_instance_id' => data_get($cell, 'session_ownership.session_instance_id'),
            'volatility' => data_get($cell, 'volatility'),
            'spread_liquidity' => data_get($cell, 'spread_liquidity_state'),
            'transition_state' => data_get($cell, 'transition_state', data_get($niche, 'transition_state')),
            'direction' => data_get($cell, 'direction'),
            'trait' => data_get($cell, 'trait_gene'),
            'instrument_bundle' => $instrumentBundle,
            'strategy' => $strategy !== '' ? $strategy : data_get($niche, 'strategy_library_id'),
            'tactic' => data_get($tactic, 'key', data_get($tactic, 'tactic_id', data_get($niche, 'tactic_library_key'))),
            'risk' => data_get($composition, 'risk_library_id', data_get($niche, 'risk_library_id')),
            'management' => data_get($composition, 'management_id', data_get($niche, 'management_id')),
            'calendar_version' => data_get($cell, 'session_ownership.calendar_version'),
        ];
        $runtimeAxes = ['spread_liquidity', 'transition_state'];
        $missing = collect($identity)->filter(fn ($value): bool => $value === null || $value === '' || $value === [])->keys()->values()->all();
        $pendingRuntime = array_values(array_intersect($missing, $runtimeAxes));

        return [
            'protocol' => self::PROTOCOL,
            'status' => $missing === [] ? 'sealed' : ($pendingRuntime !== [] ? 'pre_registered_pending_runtime_axes' : 'incomplete'),
            'identity' => $identity,
            'identity_hash' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'missing_axes' => $missing,
            'runtime_axes' => $runtimeAxes,
            'activation_outside_identity' => 'WAIT',
            'local_authority_only' => true,
            'promotion_evidence' => false,
        ];
    }
}
