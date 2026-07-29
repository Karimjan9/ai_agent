<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use Illuminate\Support\Collection;

class SpecialistPortfolioAllocator
{
    public function __construct(private EliteEcosystemService $eliteEcosystem) {}

    /**
     * Select the evidence-strongest independent specialist for the current
     * market state. This is a state decision, not a fixed-size champion list.
     */
    public function ownsRegime(ModelMarketPerformance $candidate, Collection $universe, string $regime, string $volatility): bool
    {
        $eligible = $universe->filter(function (ModelMarketPerformance $item) use ($candidate, $regime, $volatility): bool {
            if ($item->symbol !== $candidate->symbol || $item->timeframe !== $candidate->timeframe) return false;
            $claim = data_get($item->metrics, 'edge_claim', []);
            $atlasRequired = (int) data_get($item->modelVersion?->metadata, 'statistical_gate_version', 0) >= 3;
            return data_get($claim, 'falsification_report.status') !== 'falsified'
                && data_get($item->metrics, 'behavioral_diversity.status') !== 'near_duplicate'
                && in_array(data_get($claim, 'target_regime'), [$regime, 'unproven'], true)
                // New-protocol agents must own an evidence-backed niche. A
                // legacy record is not retroactively made ineligible merely
                // because its historical archive entry does not exist.
                && (! $atlasRequired || $this->eliteEcosystem->routerEligible($item, $regime, $volatility));
        });

        if ($eligible->isEmpty()) return false;
        $winner = $eligible->sortByDesc(fn (ModelMarketPerformance $item) => $this->stateScore($item, $regime, $volatility))->first();
        return $winner?->id === $candidate->id;
    }

    private function stateScore(ModelMarketPerformance $candidate, string $regime, string $volatility): float
    {
        $metrics = $candidate->metrics ?? [];
        $regimePf = (float) data_get($metrics, "pf_attribution.breakdown.by_regime.{$regime}.net_pf", 0);
        $volatilityPf = (float) data_get($metrics, "pf_attribution.breakdown.by_volatility.{$volatility}.net_pf", 0);
        return ($regimePf * 100) + ($volatilityPf * 25) + ((float) $candidate->forward_score)
            + ((float) data_get($metrics, 'negative_space_portfolio.diversification_score', 0) * .25)
            - ((float) data_get($metrics, 'negative_space_portfolio.loss_overlap', 0) * 40)
            - ((float) data_get($metrics, 'negative_space_portfolio.tail_loss_correlation', 0) * 30)
            - ((float) data_get($metrics, 'max_drawdown_percent', 100) * 2)
            - ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) * 3);
    }
}
