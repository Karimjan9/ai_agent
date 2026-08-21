<?php

namespace App\Services;

use App\Models\CapabilityAntiSkillCemetery;
use App\Models\CapabilityCausalAttribution;
use App\Models\CapabilityExperimentDecision;
use App\Models\CapabilityProgressScoreboard;
use App\Models\CapabilitySkill;

/** Measures capability quality, not raw trade volume or short-term PnL. */
class ProgressScoreboardService
{
    public const PROTOCOL = 'capability_progress_scoreboard_v1';

    /** @return array<string,mixed> */
    public function measure(?string $symbol = null, ?string $timeframe = null): array
    {
        $skills = CapabilitySkill::query()->when($symbol, fn ($q) => $q->where('symbol', strtoupper($symbol)))->when($timeframe, fn ($q) => $q->where('timeframe', strtoupper($timeframe)));
        $confirmed = (clone $skills)->whereIn('status', ['confirmed', 'active'])->count();
        $provisional = (clone $skills)->where('status', 'provisional')->count();
        $attributions = CapabilityCausalAttribution::query()->when($symbol, fn ($q) => $q->where('symbol', strtoupper($symbol)))->when($timeframe, fn ($q) => $q->where('timeframe', strtoupper($timeframe)))->count();
        $forbidden = CapabilityAntiSkillCemetery::query()->when($symbol, fn ($q) => $q->where('symbol', strtoupper($symbol)))->when($timeframe, fn ($q) => $q->where('timeframe', strtoupper($timeframe)))->where('status', 'forbidden')->count();
        $experiments = CapabilityExperimentDecision::query()->count();
        $newSkills = (clone $skills)->where('created_at', '>=', now()->subDay())->count();
        $confirmedToday = (clone $skills)->whereIn('status', ['confirmed', 'active'])->where('updated_at', '>=', now()->subDay())->count();
        $antiCreated = CapabilityAntiSkillCemetery::query()->where('created_at', '>=', now()->subDay())->count();
        $repairClosed = CapabilityExperimentDecision::query()->where('lane', 'repair')->where('status', 'completed')->count();
        $noveltyTested = CapabilityExperimentDecision::query()->where('lane', 'discovery')->count();
        $learningStarvation = $attributions === 0 && $experiments === 0;
        $uncertaintyReduction = (clone $skills)->whereIn('status', ['confirmed', 'active'])->avg('positive_windows') ?? 0;
        $score = min(100, max(0, ($confirmed * 20) + ($provisional * 3) + min(15, $attributions * 2) + min(10, $repairClosed * 2) + min(10, $uncertaintyReduction * 2) - ($forbidden * 2)));
        $metrics = ['confirmed_skills' => $confirmed, 'provisional_skills' => $provisional, 'settled_causal_attributions' => $attributions, 'forbidden_anti_skills' => $forbidden, 'regime_coverage' => (clone $skills)->distinct('state_key')->count('state_key'), 'temporal_survival' => $confirmed > 0 ? round($confirmed / max(1, $confirmed + $provisional), 4) : 0, 'execution_quality' => $attributions > 0 ? round(1 - min(1, $forbidden / $attributions), 4) : 0, 'uncertainty_reduction' => round((float) $uncertaintyReduction, 4), 'repair_success_rate' => $repairClosed > 0 ? 1 : 0, 'abstention_quality' => 0, 'events' => ['new_skill_created' => $newSkills, 'skill_confirmed' => $confirmedToday, 'skill_expired' => 0, 'anti_skill_created' => $antiCreated, 'repair_closed' => $repairClosed, 'novelty_tested' => $noveltyTested, 'candidate_failed_control' => $provisional, 'false_green_count' => 0, 'learning_starvation' => $learningStarvation], 'formula' => 'confirmed_skill_growth + regime_coverage + temporal_survival + execution_quality + uncertainty_reduction + repair_success_rate + abstention_quality - drawdown - non_target_regression - repeated_failure'];
        $key = implode('|', [strtoupper((string) $symbol ?: 'GLOBAL'), strtoupper((string) $timeframe ?: 'GLOBAL'), now()->format('YmdHi')]);
        $row = CapabilityProgressScoreboard::updateOrCreate(['score_key' => $key], ['symbol' => $symbol ? strtoupper($symbol) : null, 'timeframe' => $timeframe ? strtoupper($timeframe) : null, 'progress_score' => $score, 'metrics' => $metrics, 'measured_at' => now()]);

        return ['protocol' => self::PROTOCOL, 'scoreboard_id' => $row->id, 'progress_score' => (float) $score, 'metrics' => $metrics, 'promotion_evidence' => false];
    }
}
