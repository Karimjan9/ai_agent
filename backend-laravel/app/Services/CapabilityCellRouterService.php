<?php

namespace App\Services;

/** Cell-level router; shadow mode is deliberately incumbent-owned. */
class CapabilityCellRouterService
{
    public const PROTOCOL = 'dual_track_capability_cell_router_v1';

    public function __construct(
        private ChampionCouncilCanaryRouterService $canary,
        private DualTrackCellPolicyService $policies,
    ) {}

    /** @return array<string, mixed> */
    public function decide(array $context, array $transition = [], ?string $eventKey = null): array
    {
        $mode = (string) $this->setting('services.dual_track.mode', 'shadow');
        $cell = DualTrackDecisionService::cellKey($context);
        $configured = $this->setting('services.dual_track.cell_routes.'.$cell, null);
        $policy = $this->policies->route($context);
        $requested = is_string($configured)
            ? $configured
            : (string) ($policy['recommended_lane'] ?? $this->setting('services.dual_track.default_lane', 'incumbent'));
        $requested = in_array($requested, ['champion', 'council', 'hybrid', 'incumbent'], true) ? $requested : 'incumbent';

        if (! (bool) $this->setting('services.dual_track.enabled', true)) {
            $requested = 'incumbent';
        }

        if ($mode !== 'active') {
            return [
                'protocol' => self::PROTOCOL,
                'mode' => $mode,
                'cell_key' => $cell,
                'requested_lane' => $requested,
                'route' => 'incumbent',
                'observation_only' => true,
                'policy' => $policy,
                'fallback' => 'incumbent',
                'promotion_evidence' => false,
            ];
        }

        if ($requested === 'council' && $eventKey !== null) {
            $canary = $this->canary->decide($transition, $eventKey.'|'.$cell);
            $route = $canary['route'] === 'council' ? 'council' : 'incumbent';
        } else {
            $route = $requested === 'champion' ? 'champion' : ($requested === 'hybrid' ? 'hybrid' : 'incumbent');
            $canary = null;
        }

        return [
            'protocol' => self::PROTOCOL,
            'mode' => $mode,
            'cell_key' => $cell,
            'requested_lane' => $requested,
            'route' => $route,
            'observation_only' => false,
            'canary' => $canary,
            'policy' => $policy,
            'fallback' => 'incumbent',
            'promotion_evidence' => false,
        ];
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return function_exists('app') && app()->bound('config') ? config($key, $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
