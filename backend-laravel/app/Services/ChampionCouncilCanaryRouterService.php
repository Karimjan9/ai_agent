<?php

namespace App\Services;

/** Deterministic order-level canary assignment with incumbent fail-closed fallback. */
class ChampionCouncilCanaryRouterService
{
    public const PROTOCOL = 'champion_council_deterministic_canary_v1';

    /** @return array<string, mixed> */
    public function decide(array $transition, string $eventKey): array
    {
        $decision = (string) data_get($transition, 'decision', 'KEEP_INCUMBENT');
        $share = max(0.0, min(1.0, (float) data_get($transition, 'council_canary_share', 0)));
        $eligible = in_array($decision, ['HYBRID_CANARY', 'COUNCIL_CANARY', 'PROMOTE_COUNCIL'], true);
        $bucket = hexdec(substr(hash('sha256', self::PROTOCOL.'|'.$eventKey), 0, 12)) / 0xFFFFFFFFFFFF;
        $useCouncil = $eligible && $bucket < $share;
        return [
            'protocol' => self::PROTOCOL,
            'event_key_hash' => hash('sha256', $eventKey),
            'decision' => $decision,
            'share' => $share,
            'bucket' => $bucket,
            'route' => $useCouncil ? 'council' : 'incumbent',
            'fallback' => 'incumbent',
            'promotion_evidence' => false,
        ];
    }
}
