<?php

namespace App\Services;

use App\Models\LabParentContextScore;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\Schema;

/**
 * Contextual, decaying trust for a parent skill.
 *
 * A parent is never trusted globally. The score is keyed by the exact
 * specialist context so a useful trend-up skill can be down-ranked in a
 * range/low-volume context without deleting the parent lineage.
 */
class ParentContextTrustService
{
    public const PROTOCOL = 'parent_context_trust_matrix_v1';

    /** @return array<string, mixed> */
    public function context(array $context = []): array
    {
        $regime = $this->nullableString($context['regime'] ?? $context['market_regime'] ?? null);
        $session = $context['session_utc_hour'] ?? $context['session'] ?? null;
        $session = $session === null || $session === '' ? null : (string) $session;
        $volume = $this->nullableString($context['volume_state'] ?? $context['volume_quality'] ?? null);
        $cost = $this->nullableString($context['cost_stress'] ?? $context['cost_stress_level'] ?? 'normal');
        $age = is_numeric($context['snapshot_age_days'] ?? null)
            ? max(0.0, (float) $context['snapshot_age_days'])
            : null;
        $ageBucket = $this->ageBucket($age);

        $identity = [
            'regime' => $regime,
            'session_utc_hour' => $session,
            'volume_state' => $volume,
            'cost_stress' => $cost,
            'snapshot_age_bucket' => $ageBucket,
        ];

        return [
            ...$identity,
            'snapshot_age_days' => $age,
            'context_key' => $this->contextKey($identity),
            'protocol' => self::PROTOCOL,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function score(
        ModelVersion $parent,
        string $symbol,
        string $timeframe,
        string $family,
        string $skillKey,
        array $context = [],
    ): array {
        $normalized = $this->context($context);
        $default = [
            'protocol' => self::PROTOCOL,
            'status' => 'no_context_evidence',
            'trust_score' => .50,
            'incremental_value' => 0.0,
            'success_count' => 0,
            'failure_count' => 0,
            'uncertainty_count' => 0,
            'context' => $normalized,
            'parent_model_version_id' => (int) $parent->id,
            'skill_key' => $skillKey,
            'promotion_evidence' => false,
        ];
        if (! $this->available()) return $default;

        $row = LabParentContextScore::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('parent_model_version_id', $parent->id)
            ->where('skill_key', $skillKey)
            ->where('context_key', $normalized['context_key'])
            ->first();
        if (! $row) return $default;

        $decayed = $this->decayedTrust((float) $row->trust_score, $row->last_evidence_at?->diffInDays(now()));
        return [
            'protocol' => self::PROTOCOL,
            'status' => $row->status,
            'trust_score' => round($decayed, 6),
            'incremental_value' => (float) $row->incremental_value,
            'success_count' => (int) $row->success_count,
            'failure_count' => (int) $row->failure_count,
            'uncertainty_count' => (int) $row->uncertainty_count,
            'score_id' => (int) $row->id,
            'context' => $normalized,
            'parent_model_version_id' => (int) $parent->id,
            'skill_key' => $skillKey,
            'last_evidence_at' => $row->last_evidence_at?->toIso8601String(),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function record(
        ModelVersion $parent,
        string $symbol,
        string $timeframe,
        string $family,
        string $skillKey,
        array $context,
        string $outcome,
        float $incrementalValue = 0.0,
        array $evidence = [],
    ): array {
        $normalized = $this->context($context);
        $outcome = in_array($outcome, ['positive', 'negative', 'uncertainty'], true) ? $outcome : 'uncertainty';
        if (! $this->available()) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'migration_pending',
                'outcome' => $outcome,
                'trust_score' => .50,
                'context' => $normalized,
                'promotion_evidence' => false,
            ];
        }

        $row = LabParentContextScore::query()->firstOrNew([
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'strategy_family' => $family,
            'parent_model_version_id' => $parent->id,
            'skill_key' => $skillKey,
            'context_key' => $normalized['context_key'],
        ]);
        $observations = (int) $row->success_count + (int) $row->failure_count + (int) $row->uncertainty_count;
        $row->symbol = strtoupper($symbol);
        $row->timeframe = strtoupper($timeframe);
        $row->strategy_family = $family;
        $row->skill_key = $skillKey;
        $row->regime = $normalized['regime'];
        $row->session_utc_hour = $normalized['session_utc_hour'];
        $row->volume_state = $normalized['volume_state'];
        $row->cost_stress = $normalized['cost_stress'];
        if ($outcome === 'positive') $row->success_count = (int) $row->success_count + 1;
        if ($outcome === 'negative') $row->failure_count = (int) $row->failure_count + 1;
        if ($outcome === 'uncertainty') $row->uncertainty_count = (int) $row->uncertainty_count + 1;
        $row->incremental_value = $observations === 0
            ? $incrementalValue
            : (((float) $row->incremental_value * $observations) + $incrementalValue) / ($observations + 1);
        $row->trust_score = $this->trustFromCounts(
            (int) $row->success_count,
            (int) $row->failure_count,
            (int) $row->uncertainty_count,
        );
        $row->status = $this->statusFor($row->trust_score, (int) $row->success_count, (int) $row->failure_count);
        $row->last_evidence_at = now()->utc();
        $row->metadata = [
            ...((array) $row->metadata),
            'last_outcome' => $outcome,
            'last_incremental_value' => $incrementalValue,
            'last_evidence' => $evidence,
            'context' => $normalized,
            'promotion_evidence' => false,
        ];
        $row->save();

        return [
            'protocol' => self::PROTOCOL,
            'status' => $row->status,
            'score_id' => (int) $row->id,
            'outcome' => $outcome,
            'trust_score' => round((float) $row->trust_score, 6),
            'incremental_value' => round((float) $row->incremental_value, 6),
            'success_count' => (int) $row->success_count,
            'failure_count' => (int) $row->failure_count,
            'uncertainty_count' => (int) $row->uncertainty_count,
            'context' => $normalized,
            'promotion_evidence' => false,
        ];
    }

    public function available(): bool
    {
        try {
            return Schema::hasTable('lab_parent_context_scores');
        } catch (\Throwable) {
            return false;
        }
    }

    public function contextKey(array $identity): string
    {
        ksort($identity);
        return hash('sha256', json_encode($identity, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    private function decayedTrust(float $trust, ?int $ageDays): float
    {
        if ($ageDays === null || $ageDays <= 0) return $this->clamp($trust);
        $decayDays = max(1, (int) config('services.lab_selection.parent_trust_decay_days', 30));
        $decay = pow(.95, $ageDays / $decayDays);
        $towardPrior = .50 + (($trust - .50) * $decay);
        return $this->clamp($towardPrior);
    }

    private function trustFromCounts(int $success, int $failure, int $uncertainty): float
    {
        $observations = max(1, $success + $failure + $uncertainty);
        $signal = ($success - $failure) / $observations;
        return $this->clamp(.50 + (.35 * $signal));
    }

    private function statusFor(float $trust, int $success, int $failure): string
    {
        if ($success >= 2 && $trust >= .60) return 'context_confirmed';
        if ($failure >= 2 && $trust <= .35) return 'context_downranked';
        return 'probation';
    }

    private function clamp(float $value): float
    {
        return max(
            (float) config('services.lab_selection.parent_trust_floor', .15),
            min((float) config('services.lab_selection.parent_trust_ceiling', .85), $value),
        );
    }

    private function ageBucket(?float $days): string
    {
        if ($days === null) return 'unknown';
        return $days <= 7 ? 'fresh' : ($days <= 30 ? 'aged_30d' : ($days <= 90 ? 'aged_90d' : 'stale'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
