<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Classifies settlement lag without falsely treating every open episode as a failed job. */
class SettlementWatermarkService
{
    public const PROTOCOL = 'settlement_watermark_v1';
    public const TERMINAL = ['settled', 'technical_quarantine', 'irrecoverable_legacy'];

    public function __construct(private UncertaintyAbstentionSettlementService $abstentions) {}

    /** @return array<string,mixed> */
    public function reconcile(string $symbol, string $timeframe, ?LabGeneration $generation = null): array
    {
        if (! Schema::hasTable('settlement_watermarks')) return ['available' => false];
        $episodes = AgentLearningEpisode::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->when($generation, fn ($q) => $q->whereIn('lab_agent_id', $generation->agents()->pluck('id')))
            ->with(['settlement', 'labAgent.modelVersion'])->get();
        $counts = [];
        foreach ($episodes as $episode) {
            // Reconcile guards completed before the zero-credit settlement
            // contract existed (or interrupted between evidence close and
            // settlement). This is evidence-gated and idempotent.
            $abstentionReconciliation = $episode->labAgent
                ? $this->abstentions->settle($episode->labAgent, $episode)
                : ['status' => 'not_applicable', 'promotion_evidence' => false];
            $episode->refresh()->load(['settlement', 'labAgent.modelVersion']);
            $disposition = $this->classify($episode);
            $counts[$disposition] = ($counts[$disposition] ?? 0) + 1;
            DB::table('settlement_watermarks')->updateOrInsert(['watermark_key' => hash('sha256', self::PROTOCOL.'|'.$episode->id)], [
                'agent_learning_episode_id' => $episode->id, 'lab_generation_id' => $generation?->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe),
                'disposition' => $disposition, 'terminal' => in_array($disposition, self::TERMINAL, true), 'data_hash' => $episode->data_hash,
                'execution_hash' => $episode->execution_hash, 'evidence' => json_encode(['protocol' => self::PROTOCOL, 'episode_status' => $episode->status,
                    'agent_lifecycle_status' => $episode->labAgent?->lifecycle_status,
                    'has_settlement' => $episode->settlement !== null, 'control_hash' => data_get($episode->decision_context, 'control_hash'),
                    'composition_hash' => data_get($episode->decision_context, 'composition_hash'),
                    'abstention_reconciliation' => $abstentionReconciliation,
                    'promotion_evidence' => false]),
                'observed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        $total = $episodes->count(); $terminal = collect($counts)->only(self::TERMINAL)->sum();
        $lagSeconds = $episodes->filter(fn (AgentLearningEpisode $episode): bool => $episode->settlement === null)
            ->map(fn (AgentLearningEpisode $episode): int => (int) max(0, $episode->opened_at?->diffInSeconds(now()) ?? 0))->sort()->values();
        $p95 = $lagSeconds->isEmpty() ? 0 : (int) $lagSeconds[(int) floor(($lagSeconds->count() - 1) * .95)];
        return ['protocol' => self::PROTOCOL, 'available' => true, 'episodes' => $total, 'terminal' => $terminal,
            'terminal_coverage' => $total ? round($terminal / $total, 6) : 1.0, 'lag_taxonomy' => $counts,
            'p95_lag_seconds' => $p95, 'p95_lag_within_slo' => $p95 < 300,
            'orphan_reconciliation' => ['status' => 'automatic_classification_complete', 'retryable_projection_count' => (int) ($counts['retryable_projection'] ?? 0)],
            'generation_close_allowed' => $total === $terminal, 'promotion_evidence' => false];
    }

    private function classify(AgentLearningEpisode $episode): string
    {
        if ($episode->settlement) return 'settled';
        if ($episode->status === 'technical_quarantine') return 'technical_quarantine';
        if (in_array((string) $episode->labAgent?->lifecycle_status,
            ['technical_quarantine', 'quarantined'], true)) return 'technical_quarantine';
        if (data_get($episode->decision_context, 'legacy.irrecoverable') === true) return 'irrecoverable_legacy';
        if (in_array($episode->status, ['open', 'decision', 'running'], true)
            && $episode->opened_at?->greaterThan(now()->subMinutes(5))) return 'in_flight';
        if (! data_get($episode->decision_context, 'control_hash')) return 'awaiting_control';
        if (! data_get($episode->observations, 'full_outcomes_complete')) return 'awaiting_full_outcomes';
        return 'retryable_projection';
    }
}
