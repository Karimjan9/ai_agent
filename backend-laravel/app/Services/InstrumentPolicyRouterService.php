<?php

namespace App\Services;

/** State-dependent sparse activation policy over the existing instrument OS. */
class InstrumentPolicyRouterService
{
    public const PROTOCOL = 'instrument_policy_router_v1';

    public function __construct(private TradingInstrumentOperatingSystemService $router) {}

    /** @return array<string,mixed> */
    public function route(string $symbol, string $timeframe, array $context = []): array
    {
        $route = $this->router->route($symbol, $timeframe, $context);
        $keys = array_values(array_unique((array) data_get($route, 'playbook.instrument_keys', [])));
        $minimum = (int) config('services.instrument_policy.minimum_active_instruments', 3);
        $maximum = (int) config('services.instrument_policy.maximum_active_instruments', 6);
        $trade = ($route['decision'] ?? 'ABSTAIN') === 'TRADE';
        $valid = ! $trade || (count($keys) >= $minimum && count($keys) <= $maximum);
        if (! $valid) {
            $route['decision'] = 'ABSTAIN';
            $route['reason_code'] = 'SPARSE_INSTRUMENT_BUNDLE_INVALID';
        }
        // The scheduler can pin a lane explicitly. Otherwise selection is
        // deterministic by decision key: replaying a decision never shifts
        // it from exploit to repair/discovery just because it was re-run.
        $lane = (string) ($context['learning_lane'] ?? $this->selectLearningLane((string) ($context['decision_key'] ?? data_get($route, 'router_decision.decision_key', ''))));
        if (! in_array($lane, ['exploit', 'repair', 'discovery'], true)) $lane = 'exploit';
        $route['instrument_bundle'] = [
            'protocol' => self::PROTOCOL, 'keys' => $keys, 'activation_count' => count($keys),
            'sparse_activation' => $valid, 'state_key' => data_get($route, 'state.state_key'),
            'allocation' => ['exploit' => .70, 'repair' => .20, 'discovery' => .10],
            'selected_lane' => $lane,
            'discovery_contract' => ['max_new_instruments' => 1, 'max_changed_axis' => 1, 'control_required' => true],
            'promotion_evidence' => false,
        ];
        return $route;
    }

    private function selectLearningLane(string $decisionKey): string
    {
        $bucket = (int) sprintf('%u', crc32($decisionKey)) % 100;

        return $bucket < 70 ? 'exploit' : ($bucket < 90 ? 'repair' : 'discovery');
    }
}
