<?php

namespace App\Services;

use App\Models\CapabilitySkill;

/** Five-state organism router: capital is reserved until a confirmed conditional skill matches. */
class RegimeCapabilityRouter
{
    public const PROTOCOL = 'regime_capability_router_v1';

    public function __construct(private AntiSkillCemeteryService $cemetery) {}

    /** @return array<string,mixed> */
    public function route(string $symbol, string $timeframe, array $state, array $context = []): array
    {
        $stateKey = (string) ($state['state_key'] ?? implode('|', [$state['regime'] ?? 'unknown', $state['session'] ?? 'unknown', $state['volatility'] ?? 'unknown']));
        $strategy = $context['strategy_id'] ?? null;
        $tactic = $context['tactic_id'] ?? null;
        if ((bool) ($state['transition'] ?? false) || (bool) ($context['risk_veto'] ?? false)) {
            return $this->decision('WAIT', 'RISK_OR_TRANSITION_HAZARD', $stateKey);
        }
        if ($this->cemetery->blocks($symbol, $timeframe, $stateKey, $strategy, $tactic)) {
            return $this->decision('REPAIR', 'FORBIDDEN_ANTI_SKILL', $stateKey);
        }
        $skills = CapabilitySkill::query()->where(['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'state_key' => $stateKey, 'status' => 'active'])->get();
        if ($skills->isNotEmpty()) {
            return $this->decision('TRADE', 'CONFIRMED_SKILL_MATCH', $stateKey, $skills->first());
        }
        if ((bool) ($context['research_allowed'] ?? false)) {
            return $this->decision('EXPLORE', 'SHADOW_EXPERIMENT_ONLY', $stateKey);
        }

        return $this->decision('OBSERVE', 'NO_CONFIRMED_CAPABILITY', $stateKey);
    }

    /** @return array<string,mixed> */
    private function decision(string $state, string $reason, string $stateKey, ?CapabilitySkill $skill = null): array
    {
        return ['protocol' => self::PROTOCOL, 'organism_state' => $state, 'reason_code' => $reason, 'state_key' => $stateKey, 'skill_id' => $skill?->id, 'skill_key' => $skill?->skill_key, 'capital_authorized' => $state === 'TRADE', 'shadow_only' => in_array($state, ['OBSERVE', 'EXPLORE', 'REPAIR'], true), 'promotion_evidence' => false];
    }
}
