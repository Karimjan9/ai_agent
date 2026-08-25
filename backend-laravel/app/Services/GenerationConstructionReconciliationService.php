<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\SystemEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanently closes a partial population once screening has contaminated it.
 * Immutable runs are preserved, but no result from that cohort may become
 * strategy, learning, inheritance, or promotion evidence.
 */
class GenerationConstructionReconciliationService
{
    public function __construct(
        private readonly GenerationConstructionAdmissionService $admission,
    ) {}

    /** @return array<string, mixed> */
    public function reconcileLatest(string $symbol, string $timeframe): array
    {
        $lab = AiLaboratory::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->first();
        $generation = $lab?->generations()->latest('generation')->first();
        if (! $generation) {
            return ['status' => 'no_generation', 'closed' => false];
        }

        return $this->reconcile($generation);
    }

    /** @return array<string, mixed> */
    public function reconcile(LabGeneration $generation): array
    {
        // The first reconciliation has already terminalized this cohort. A
        // later scheduler tick must treat it as immutable history; returning
        // `closed=true` forever would block every successor generation.
        if ((string) $generation->status === 'abandoned'
            && data_get($generation->trigger_context, 'constructor_contamination.protocol') === 'generation_construction_contamination_v1') {
            return [
                'protocol' => 'generation_construction_reconciliation_v1',
                'status' => 'already_abandoned_diagnostic_only',
                'closed' => false,
                'generation_id' => (int) $generation->id,
                'promotion_evidence' => false,
            ];
        }
        $inspection = $this->admission->inspect($generation);
        $planned = (int) data_get($inspection, 'planned_slots', 0);
        $actual = (int) data_get($inspection, 'actual_agents', 0);
        $incomplete = $planned > 0 && $actual < $planned;
        $runCount = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'screening')
            ->count();

        if (! $incomplete || $runCount === 0) {
            return [
                'protocol' => 'generation_construction_reconciliation_v1',
                'status' => $incomplete ? 'resumable_clean_construction' : 'not_applicable',
                'closed' => false,
                'generation_id' => (int) $generation->id,
                'construction_admission' => $inspection,
                'screening_run_count' => $runCount,
                'promotion_evidence' => false,
            ];
        }

        return DB::transaction(function () use ($generation, $inspection, $runCount, $planned, $actual): array {
            $locked = LabGeneration::query()->lockForUpdate()->findOrFail($generation->id);
            $context = (array) ($locked->trigger_context ?? []);
            $context['constructor_contamination'] = [
                'protocol' => 'generation_construction_contamination_v1',
                'reason_code' => 'SCREENING_BEFORE_CONSTRUCTION_ADMISSION',
                'planned_slots' => $planned,
                'actual_agents' => $actual,
                'screening_run_count' => $runCount,
                'immutable_runs_preserved' => true,
                'strategy_verdict' => 'withheld',
                'learning_evidence' => false,
                'inheritance_credit' => false,
                'promotion_evidence' => false,
                'closed_at' => now()->utc()->toIso8601String(),
            ];
            $locked->update([
                'status' => 'abandoned',
                'completed_at' => now(),
                'trigger_context' => $context,
            ]);

            // Only nonterminal rows are closed. Completed/failed immutable
            // attempts keep their original envelope; an orphan `started` row
            // would otherwise advertise work that can never finish after the
            // contaminated generation has been abandoned.
            foreach (LabEvaluationRun::query()
                ->where('lab_generation_id', $locked->id)
                ->where('phase', 'screening')
                ->whereNull('finished_at')
                ->where('status', 'started')
                ->get() as $run) {
                $run->update([
                    'status' => 'technical_error',
                    'finished_at' => now(),
                    'duration_ms' => $run->started_at
                        ? (int) $run->started_at->diffInMilliseconds(now())
                        : null,
                    'error_class' => 'GenerationConstructionContamination',
                    'error_message' => 'Run terminalized because screening started before the generation construction contract was admitted.',
                    'metadata' => [
                        ...((array) $run->metadata),
                        'reason_code' => 'SCREENING_BEFORE_CONSTRUCTION_ADMISSION',
                        'strategy_verdict' => 'withheld',
                        'promotion_evidence' => false,
                    ],
                ]);
            }

            foreach ($locked->agents()->with('modelVersion')->get() as $agent) {
                $agent->update([
                    'lifecycle_status' => 'technical_quarantine',
                    'decision_reason' => 'Incomplete cohort received screening before constructor admission; immutable run preserved, strategy verdict withheld.',
                ]);
            }

            if (Schema::hasTable('lab_learning_lane_pairs')) {
                foreach (LabLearningLanePair::query()->where('lab_generation_id', $locked->id)->get() as $pair) {
                    $pair->update([
                        'status' => 'diagnostic_only',
                        'pair_integrity_status' => 'invalid_generation_construction',
                        'metadata' => [
                            ...((array) $pair->metadata),
                            'diagnostic_reason' => 'SCREENING_BEFORE_CONSTRUCTION_ADMISSION',
                            'promotion_evidence' => false,
                        ],
                    ]);
                }
            }
            if (Schema::hasTable('lab_learning_lane_dispatches')) {
                LabLearningLaneDispatch::query()
                    ->where('lab_generation_id', $locked->id)
                    ->whereIn('status', ['selected', 'retry_ready', 'queued', 'running'])
                    ->update(['status' => 'diagnostic_only', 'completed_at' => null]);
            }

            SystemEvent::query()->firstOrCreate(
                ['event_key' => 'generation-construction-contamination:'.$locked->id],
                [
                    'event_type' => 'generation_construction_contamination_closed',
                    'source_type' => LabGeneration::class,
                    'source_id' => (int) $locked->id,
                    'agent' => 'lifecycle_orchestrator',
                    'symbol' => (string) $locked->laboratory?->symbol,
                    'timeframe' => (string) $locked->laboratory?->timeframe,
                    'severity' => 'critical',
                    'summary' => 'Partial generation screening was closed as diagnostic-only evidence.',
                    'payload' => [
                        'protocol' => 'generation_construction_contamination_v1',
                        'planned_slots' => $planned,
                        'actual_agents' => $actual,
                        'screening_run_count' => $runCount,
                        'construction_admission' => $inspection,
                        'promotion_evidence' => false,
                    ],
                    'occurred_at' => now()->utc(),
                ],
            );

            return [
                'protocol' => 'generation_construction_reconciliation_v1',
                'status' => 'abandoned_diagnostic_only',
                'closed' => true,
                'generation_id' => (int) $locked->id,
                'planned_slots' => $planned,
                'actual_agents' => $actual,
                'screening_run_count' => $runCount,
                'promotion_evidence' => false,
            ];
        });
    }
}
