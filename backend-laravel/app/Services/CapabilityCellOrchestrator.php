<?php

namespace App\Services;

class CapabilityCellOrchestrator
{
    public function __construct(private MarketStateEstimatorService $states, private CapabilityCellService $cells, private RegimeCapabilityRouter $router) {}

    /** @return array<string,mixed> */
    public function decide(string $symbol, string $timeframe, array $execution = [], array $news = [], array $context = []): array
    {
        $state = $this->states->estimate($symbol, $timeframe, $execution, $news);
        $state['regime'] = $state['state'];
        $state['session'] = $context['session'] ?? 'unknown';
        $state['posterior'] = [$state['state'] => $state['regime_probability']];
        $cell = $this->cells->resolve($symbol, $timeframe, $state, $context);
        $route = $this->router->route($symbol, $timeframe, [...$state, 'state_key' => $cell['cell_key']], $context);

        return ['state' => $state, 'cell' => ['id' => $cell['cell']->id, 'key' => $cell['cell_key']], 'route' => $route, 'promotion_evidence' => false];
    }
}
