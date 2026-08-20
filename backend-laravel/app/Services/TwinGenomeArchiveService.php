<?php

namespace App\Services;

use App\Models\DualTrackGeneCemetery;
use App\Models\DualTrackGenomeArchive;
use App\Models\DualTrackGenomeArchiveEvent;
use App\Models\DualTrackOutcome;
use App\Models\ModelVersion;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/** Persistent quality-diversity archive and recoverable gene cemetery. */
class TwinGenomeArchiveService
{
    public const PROTOCOL = 'twin_map_elites_genome_archive_v1';

    /** @return array<string, mixed> */
    public function record(ModelMarketPerformance $candidate, DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_genome_archives')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $model = $candidate->modelVersion;
        $lane = $outcome->lane;
        $regime = (string) ($outcome->run?->market_regime ?: 'unknown');
        $volatility = (string) ($outcome->run?->volatility_regime ?: 'unknown');
        $behaviorCell = $regime.'|'.$volatility.'|'.($outcome->decision ?: 'WAIT');
        $genes = (array) ($model?->parameters ?? []);
        $genomeHash = hash('sha256', json_encode([
            'strategy' => $model?->strategy ?: $candidate->strategy_family,
            'version' => $model?->version,
            'parameters' => $genes,
            'lane' => $lane,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        // Keep archive settlement O(1) in application memory. Candidate
        // outcomes are immutable rows; MySQL can aggregate the JSON identity
        // without hydrating the whole cell history on every close.
        $candidateOutcomes = DualTrackOutcome::query()->where('symbol', $outcome->symbol)->where('timeframe', $outcome->timeframe)
            ->where('cell_key', $outcome->cell_key)->where('lane', $lane)->where('outcome_status', 'settled')
            ->where('metadata->candidate_id', (int) $candidate->id);
        $fitness = (float) ($candidateOutcomes->avg('reward') ?? ($outcome->reward ?? 0));
        $evidenceCount = max(1, (int) $candidateOutcomes->count());
        $archiveKey = hash('sha256', self::PROTOCOL.'|'.$lane.'|'.$outcome->cell_key.'|'.$behaviorCell);
        $current = DualTrackGenomeArchive::query()->where('archive_key', $archiveKey)->first();
        $novelty = $this->novelty($genes, $current?->genes);
        $admitted = ! $current || $fitness > (float) $current->fitness_score || $novelty > (float) $current->novelty_score;
        $eventKey = hash('sha256', self::PROTOCOL.'|event|'.$outcome->id.'|'.$lane.'|'.$genomeHash);
        $event = Schema::hasTable('dual_track_genome_archive_events')
            ? DualTrackGenomeArchiveEvent::query()->firstOrCreate(
                ['event_key' => $eventKey],
                ['archive_key' => $archiveKey, 'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe,
                    'lane' => $lane, 'cell_key' => $outcome->cell_key, 'behavior_cell' => $behaviorCell,
                    'genome_hash' => $genomeHash, 'fitness_score' => $fitness, 'novelty_score' => $novelty,
                    'event_type' => $admitted ? 'elite_admitted' : 'observed', 'genes' => $genes,
                    'evidence' => ['outcome_id' => $outcome->id, 'candidate_id' => $candidate->id, 'promotion_evidence' => false],
                    'promotion_evidence' => false],
            ) : null;
        $archive = DualTrackGenomeArchive::query()->updateOrCreate(
            ['archive_key' => $archiveKey],
            [
                'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'lane' => $lane,
                'cell_key' => $outcome->cell_key, 'behavior_cell' => $behaviorCell, 'genome_hash' => $genomeHash,
                'model_version_id' => $model?->id, 'genes' => $genes, 'phenotype' => [
                    'strategy' => $model?->strategy, 'family' => $candidate->strategy_family,
                    'regime' => $regime, 'volatility' => $volatility,
                ], 'fitness_score' => $admitted ? $fitness : $current?->fitness_score,
                'novelty_score' => max($novelty, (float) ($current?->novelty_score ?? 0)),
                'evidence_count' => max((int) ($current?->evidence_count ?? 0) + ($event ? 1 : 0), $evidenceCount),
                'status' => $admitted ? 'elite' : 'frontier',
                'evidence' => ['protocol' => self::PROTOCOL, 'outcome_id' => $outcome->id, 'event_id' => $event?->id, 'candidate_id' => $candidate->id, 'fitness_basis' => 'settled_candidate_mean_reward', 'admitted' => $admitted, 'promotion_evidence' => false],
            ],
        );

        $cemetery = null;
        if ($outcome->correct === false || in_array($outcome->actual_outcome, ['loss', 'missed_opportunity'], true)) {
            $parentId = data_get($model?->metadata, 'progressive_inheritance.parent_model_version_id');
            $cemetery = $this->bury($outcome, $genomeHash, $regime, $this->parentGenomeHash($parentId));
        }

        return ['status' => 'recorded', 'archive_id' => $archive->id, 'behavior_cell' => $behaviorCell, 'genome_hash' => $genomeHash, 'admitted' => $admitted, 'cemetery_id' => $cemetery?->id, 'promotion_evidence' => false];
    }

    /** @return array<int, array<string, mixed>> */
    public function resurrectionCandidates(string $symbol, string $timeframe, string $lane, string $regime): array
    {
        if (! Schema::hasTable('dual_track_gene_cemeteries')) return [];
        return DualTrackGeneCemetery::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('lane', $lane)->where('failure_regime', $regime)->where('status', 'buried')->limit(20)->get()
            ->map(fn (DualTrackGeneCemetery $row): array => ['id' => $row->id, 'genome_hash' => $row->genome_hash, 'reason_code' => $row->reason_code, 'status' => 'resurrection_candidate'])->all();
    }

    /** Resurrection is an explicit new experiment, never an automatic revive. */
    public function resurrect(int $cemeteryId, array $evidence): array
    {
        if (! Schema::hasTable('dual_track_gene_cemeteries')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $entry = DualTrackGeneCemetery::query()->find($cemeteryId);
        if (! $entry) return ['status' => 'missing', 'promotion_evidence' => false];
        $allowed = ($evidence['independent_holdout_passed'] ?? false) === true
            && ($evidence['regime_match'] ?? false) === true
            && ($evidence['risk_gate_passed'] ?? false) === true
            && trim((string) ($evidence['experiment_key'] ?? '')) !== ''
            && trim((string) ($evidence['fresh_snapshot_hash'] ?? '')) !== '';
        $entry->update([
            'status' => $allowed ? 'resurrected' : 'resurrection_candidate',
            'resurrection_eligible_at' => now(), 'resurrected_at' => $allowed ? now() : null,
            'death_evidence' => [...((array) $entry->death_evidence), 'resurrection_attempt' => $evidence, 'resurrection_protocol' => 'new_experiment_with_fresh_snapshot_v1', 'promotion_evidence' => false],
        ]);
        return ['status' => $entry->status, 'genome_hash' => $entry->genome_hash, 'allowed' => $allowed, 'promotion_evidence' => false];
    }

    private function bury(DualTrackOutcome $outcome, string $genomeHash, string $regime, mixed $parent): ?DualTrackGeneCemetery
    {
        if (! Schema::hasTable('dual_track_gene_cemeteries')) return null;
        $key = hash('sha256', self::PROTOCOL.'|cemetery|'.$outcome->cell_key.'|'.$genomeHash);
        return DualTrackGeneCemetery::query()->updateOrCreate(
            ['cemetery_key' => $key],
            [
                'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'lane' => $outcome->lane,
                'cell_key' => $outcome->cell_key, 'genome_hash' => $genomeHash, 'parent_genome_hash' => $parent ? (string) $parent : null,
                'failure_regime' => $regime, 'reason_code' => (string) ($outcome->actual_outcome ?: 'verified_failure'),
                'death_evidence' => ['outcome_id' => $outcome->id, 'reward' => $outcome->reward, 'promotion_evidence' => false],
                'status' => 'buried',
            ],
        );
    }

    private function novelty(array $genes, ?array $previous): float
    {
        if ($previous === null) return 1.0;
        $keys = array_values(array_unique(array_merge(array_keys($genes), array_keys($previous))));
        if ($keys === []) return 0.0;
        $different = count(array_filter($keys, fn (string|int $key): bool => json_encode($genes[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION) !== json_encode($previous[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)));
        return round($different / count($keys), 6);
    }

    private function parentGenomeHash(mixed $parentId): ?string
    {
        if (! is_numeric($parentId) || (int) $parentId < 1) return null;
        $parent = ModelVersion::query()->find((int) $parentId);
        if (! $parent) return null;
        return hash('sha256', json_encode(['strategy' => $parent->strategy, 'version' => $parent->version, 'parameters' => $parent->parameters], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
