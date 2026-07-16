<?php

namespace App\Services;

class TradingDeploymentSafetyService
{
    public function __construct(private PaperEvidenceReadinessService $paperEvidence) {}

    public function status(): array
    {
        $practiceUrl = str_contains((string) config('services.oanda.base_url'), 'fxpractice');
        $gates = [
            'paper_evidence_ready' => $this->paperEvidence->ready(),
            'practice_environment' => config('services.oanda.environment', 'practice') === 'practice' && $practiceUrl,
            'live_disabled_by_default' => ! (bool) config('services.live_trading.enabled', false),
            'kill_switch_engaged' => (bool) config('services.live_trading.kill_switch_engaged', true),
            'human_approval_configured' => (string) config('services.live_trading.human_approval_sha256') !== '',
            'small_capital_limit' => (float) config('services.live_trading.max_capital', 0) > 0,
        ];
        return ['stage' => $gates['paper_evidence_ready'] ? 'oanda_practice_eligible' : 'paper_observation', 'gates' => $gates];
    }

    public function assertPracticeOnly(): void
    {
        if (config('services.oanda.environment', 'practice') !== 'practice'
            || ! str_contains((string) config('services.oanda.base_url'), 'fxpractice')) {
            throw new \RuntimeException('Paper execution is restricted to OANDA practice. Live trading is not implemented.');
        }
    }
}
