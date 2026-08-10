<?php

namespace App\Console\Commands;

use App\Models\LabCandleDecisionEvent;
use App\Models\LabEvidenceArtifact;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompressLabEvidence extends Command
{
    protected $signature = 'trading:lab-compress-evidence
                            {--apply : Write compressed files and clear inline JSON payloads}
                            {--limit=1000 : Maximum artifacts to process in this invocation}
                            {--decision-run-limit=10 : Maximum legacy decision runs to archive in this invocation}
                            {--after-id=0 : Resume decision compaction after this candle event id}
                            {--archive-only : Archive legacy decision runs without compacting rows}
                            {--compact-decision-events : Clear legacy per-candle JSON after artifacts are externalized}';

    protected $description = 'Externalize immutable lab evidence into compressed artifacts and compact MySQL JSON';

    public function handle(LabImmutableEvidenceService $evidence): int
    {
        $limit = max(1, min(50000, (int) $this->option('limit')));
        $afterId = max(0, (int) $this->option('after-id'));
        $artifactQuery = LabEvidenceArtifact::query()->whereNull('storage_path')->whereNotNull('payload');
        $artifactSample = (clone $artifactQuery)->orderBy('id')->limit($limit)->pluck('id');
        $decisionQuery = DB::table('lab_candle_decision_events')
            ->where('id', '>', $afterId)
            ->where(function ($query): void {
                $query->whereNotNull('features')->orWhereNotNull('state')->orWhereNotNull('payload');
            });
        $decisionSample = (clone $decisionQuery)->orderBy('id')->limit($limit)->pluck('id');
        $artifactCount = $artifactSample->count();
        $decisionCount = $decisionSample->count();
        $artifactLabel = $artifactCount === $limit ? $artifactCount.'+' : (string) $artifactCount;
        $decisionLabel = $decisionCount === $limit ? $decisionCount.'+' : (string) $decisionCount;

        $this->info(sprintf(
            'Inline artifacts (sample): %s; candle JSON rows (sample): %s; mode: %s',
            $artifactLabel,
            $decisionLabel,
            $this->option('apply') ? 'APPLY' : 'DRY-RUN',
        ));

        if (! $this->option('apply')) {
            $this->line('Hech narsa o\'zgartirilmadi. Apply uchun --apply bering.');

            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;
        $decisionRunLimit = max(0, min(100, (int) $this->option('decision-run-limit')));
        $artifactQuery->orderBy('id')->limit($limit)->get()->each(function (LabEvidenceArtifact $artifact) use ($evidence, &$converted, &$failed): void {
            try {
                if ($evidence->externalizeLegacyArtifact($artifact)) {
                    $converted++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->error($artifact->artifact_id.': '.$exception->getMessage());
            }
        });

        $compacted = 0;
        $lastDecisionId = $afterId;
        $archivedRuns = 0;
        if ($this->option('archive-only')) {
            if ($decisionRunLimit > 0) {
                $archivedRuns = $this->archiveLegacyDecisionRuns($evidence, $decisionQuery, $decisionRunLimit);
            }
            $this->info("Externalized: {$converted}; archived decision runs: {$archivedRuns}; compacted candle rows: 0; failed: {$failed}.");

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('compact-decision-events')) {
            if ($artifactQuery->exists()) {
                $this->error('Avval barcha legacy artifact payload’larini externalize qiling; compact bosqichi bloklandi.');

                return self::FAILURE;
            }

            if ($decisionRunLimit > 0) {
                $archivedRuns = $this->archiveLegacyDecisionRuns($evidence, $decisionQuery, $decisionRunLimit);
            }

            $compactableDecisionQuery = (clone $decisionQuery)->whereExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('lab_evidence_artifacts as trace_artifact')
                    ->whereColumn('trace_artifact.run_id', 'lab_candle_decision_events.run_id')
                    ->whereIn('trace_artifact.artifact_type', ['decision_trace', 'legacy_decision_trace'])
                    ->whereNotNull('trace_artifact.storage_path');
            });
            $ids = $compactableDecisionQuery
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
            if ($ids->isNotEmpty()) {
                $compacted = DB::table('lab_candle_decision_events')->whereIn('id', $ids)->update([
                    'features' => null,
                    'state' => null,
                    'payload' => null,
                ]);
                $lastDecisionId = (int) $ids->max();
            }
        }

        $this->info("Externalized: {$converted}; archived decision runs: {$archivedRuns}; compacted candle rows: {$compacted}; last decision id: {$lastDecisionId}; failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function archiveLegacyDecisionRuns(
        LabImmutableEvidenceService $evidence,
        Builder $decisionQuery,
        int $runLimit,
    ): int {
        $runIds = (clone $decisionQuery)
            ->whereNotNull('run_id')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('lab_evidence_artifacts as existing_trace')
                    ->whereColumn('existing_trace.run_id', 'lab_candle_decision_events.run_id')
                    ->whereIn('existing_trace.artifact_type', ['decision_trace', 'legacy_decision_trace'])
                    ->whereNotNull('existing_trace.storage_path');
            })
            ->select('run_id')
            ->distinct()
            ->orderBy('run_id')
            ->limit($runLimit)
            ->pluck('run_id');

        $archived = 0;
        foreach ($runIds as $runId) {
            $alreadyArchived = DB::table('lab_evidence_artifacts')
                ->where('run_id', $runId)
                ->whereIn('artifact_type', ['decision_trace', 'legacy_decision_trace'])
                ->whereNotNull('storage_path')
                ->exists();
            if ($alreadyArchived) {
                continue;
            }

            $events = LabCandleDecisionEvent::query()
                ->where('run_id', $runId)
                ->where(function ($query): void {
                    $query->whereNotNull('features')->orWhereNotNull('state')->orWhereNotNull('payload');
                })
                ->orderBy('id')
                ->get();
            if ($events->isEmpty()) {
                continue;
            }

            $trace = $events->map(static function (LabCandleDecisionEvent $event): array {
                return [
                    'decision_id' => $event->decision_id,
                    'candle_time' => $event->candle_time,
                    'candle_index' => $event->candle_index,
                    'event_type' => $event->event_type,
                    'action' => $event->action,
                    'accepted' => $event->accepted,
                    'rejection_code' => $event->rejection_code,
                    'market_regime' => $event->market_regime,
                    'volatility_regime' => $event->volatility_regime,
                    'confidence' => $event->confidence,
                    'price' => $event->price,
                    'features' => $event->features,
                    'state' => $event->state,
                    'payload_hash' => $event->payload_hash,
                    'payload' => $event->payload,
                    'recorded_at' => optional($event->recorded_at)->toIso8601String(),
                ];
            })->all();

            $evidence->recordArtifact(null, 'legacy_decision_trace', $trace, [
                'legacy' => true,
                'source_table' => 'lab_candle_decision_events',
                'event_count' => count($trace),
                'compaction_protocol' => 'legacy_decision_trace_v1',
            ], null, (string) $runId);
            $archived++;
        }

        return $archived;
    }
}
