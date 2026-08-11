<?php

namespace App\Services;

class TradingDeploymentSafetyService
{
    public function __construct(private PaperEvidenceReadinessService $paperEvidence) {}

    public function status(): array
    {
        $hardStop = (bool) config('services.live_trading.hard_stop', true);
        $gates = [
            'paper_evidence_ready' => $this->paperEvidence->ready(),
            'simulated_paper_only' => true,
            'live_disabled_by_default' => $hardStop || ! (bool) config('services.live_trading.enabled', false),
            'kill_switch_engaged' => (bool) config('services.live_trading.kill_switch_engaged', true),
            'live_hard_stop_engaged' => $hardStop,
            'human_approval_configured' => (string) config('services.live_trading.human_approval_sha256') !== '',
            'small_capital_limit' => (float) config('services.live_trading.max_capital', 0) > 0,
        ];
        return [
            'stage' => $hardStop
                ? 'research_frozen_live_disabled'
                : ($gates['paper_evidence_ready'] ? 'simulated_paper_validated' : 'paper_observation'),
            'gates' => $gates,
        ];
    }
}
