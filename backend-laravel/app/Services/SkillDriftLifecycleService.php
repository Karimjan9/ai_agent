<?php

namespace App\Services;

use App\Models\CapabilitySkill;

class SkillDriftLifecycleService
{
    /** @return array<string,mixed> */
    public function assess(CapabilitySkill $skill, array $current): array
    {
        $feature = abs((float) data_get($current, 'feature_distance', 0));
        $regime = abs((float) data_get($current, 'regime_distance', 0));
        $outcome = max(0, (float) data_get($current, 'performance_decay', 0));
        $drift = min(1, ($feature * .35) + ($regime * .35) + ($outcome * .30));
        $status = $drift >= .75 || ($skill->expires_at && $skill->expires_at->isPast()) ? 'expired' : ($drift >= .5 ? 'shadow_only' : ($drift >= .3 ? 'watch' : ($skill->status === 'confirmed' ? 'active' : $skill->status)));
        $skill->update(['status' => $status, 'drift_score' => $drift, 'performance_decay' => $outcome, 'current_state_distribution' => (array) ($current['state_distribution'] ?? []), 'revalidation_required' => in_array($status, ['watch', 'shadow_only', 'expired'], true), 'last_success_at' => data_get($current, 'success') ? now() : $skill->last_success_at]);

        return ['status' => $status, 'drift_score' => $drift, 'routing_eligible' => $status === 'active', 'promotion_evidence' => false];
    }
}
