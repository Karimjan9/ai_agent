<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Collection;

/**
 * Produces the durable, human-readable result packet for every lab phase.
 *
 * Reports are stored in lab_generations.trigger_context so this contract can
 * be deployed without a destructive schema change.  A phase is idempotent:
 * retrying a technical recovery replaces that phase's report rather than
 * creating fake progress.
 */
class LabGenerationReportService
{
    public const PROTOCOL = 'lab_generation_report_v1';

    public function record(LabGeneration $generation, string $phase): array
    {
        $generation = $generation->fresh(['laboratory', 'agents.modelVersion']);
        if (! $generation) return [];

        $agents = $generation->agents;
        $agentIds = $agents->pluck('id')->all();
        $modelIds = $agents->pluck('model_version_id')->all();
        $performances = ModelMarketPerformance::query()
            ->whereIn('model_version_id', $modelIds ?: [0])
            ->where('symbol', $generation->laboratory?->symbol)
            ->where('timeframe', $generation->laboratory?->timeframe)
            ->get()->keyBy('model_version_id');
        $decisions = CandidateGateDecision::query()->whereIn('lab_agent_id', $agentIds ?: [0])->get();
        $handoffs = CandidateHandoffEvent::query()->where('lab_generation_id', $generation->id)->get();

        $cleanTerminalStatuses = ['screened', 'challenger', 'overfit', 'rejected', 'stagnated', 'forward_validated', 'paper', 'champion', 'archived'];
        $cleanTerminal = $agents->whereIn('lifecycle_status', $cleanTerminalStatuses)->count();
        $technicalErrors = $agents->where('lifecycle_status', 'evaluation_error')->map(fn (LabAgent $agent): array => [
            'agent_id' => $agent->id,
            'reason' => (string) $agent->decision_reason,
            'recovery_attempts' => (int) data_get($agent->modelVersion?->metadata, 'evaluator_recovery_attempts', 0),
        ])->values()->all();

        $screenDecisions = $decisions->where('stage', 'screening');
        $screenPassed = $screenDecisions->where('decision', 'passed')->count();
        $failedReasons = $this->reasonCounts($decisions);
        $best = $this->bestAgent($agents, $performances);
        $bestPerformance = $best ? $performances->get($best->model_version_id) : null;
        $bestResult = (array) ($bestPerformance?->metrics
            ?? data_get($best?->modelVersion?->metadata, 'last_result', data_get($best?->modelVersion?->metadata, 'last_screen_result', [])));
        $parent = $this->parentPerformance($best, $generation);
        $parentMetrics = $parent ? $this->metrics((array) $parent->metrics, (int) $parent->sample_count) : null;
        $bestMetrics = $this->metrics($bestResult, (int) ($best?->sample_count ?? 0));
        $parentDelta = $parentMetrics ? collect($bestMetrics)->mapWithKeys(function ($value, string $key) use ($parentMetrics): array {
            return [$key => $value === null || $parentMetrics[$key] === null ? null : round((float) $value - (float) $parentMetrics[$key], 6)];
        })->all() : null;

        $selected = $handoffs->where('stage', 'selection_passed')->filter(fn (CandidateHandoffEvent $event): bool =>
            $event->status === 'completed' && data_get($event->payload, 'selection_lane', 'none') !== 'none'
        )->count();
        $forwardValidated = $performances->whereIn('status', ['forward_validated', 'paper', 'champion'])->count();
        $paperTransition = $forwardValidated > 0
            ? $performances->whereIn('status', ['forward_validated', 'paper', 'champion'])->map(fn (ModelMarketPerformance $performance): ?int =>
                $performance->created_at && $performance->updated_at ? $performance->created_at->diffInSeconds($performance->updated_at) : null
            )->filter()->min()
            : null;

        $targets = collect((array) data_get($generation->trigger_context, 'generation_plan', []))
            ->pluck('target')->merge($agents->map(fn (LabAgent $agent) => data_get($agent->modelVersion?->metadata, 'generation_target')))
            ->filter()->unique()->values()->all();
        $targetedAttempts = $generation->laboratory
            ? $generation->laboratory->generations()->where('generation', '<', $generation->generation)->where('trigger_type', 'candidate_handoff')->count()
            : 0;
        $coverageKpis = $this->coverageKpis($agents, $performances);

        $report = [
            'protocol' => self::PROTOCOL,
            'phase' => $phase,
            'recorded_at' => now()->utc()->toIso8601String(),
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'symbol' => $generation->laboratory?->symbol,
            'timeframe' => $generation->laboratory?->timeframe,
            'status' => $generation->status,
            'best_agent' => $best ? [
                'id' => $best->id,
                'lifecycle_status' => $best->lifecycle_status,
                'strategy_family' => $best->strategy_family,
                'sample_count' => $best->sample_count,
                'profit_factor' => $best->profit_factor,
                'performance_status' => $bestPerformance?->status,
            ] : null,
            'parent_delta' => $parentDelta,
            'gate_improvements' => $parentDelta
                ? collect($parentDelta)->filter(fn ($delta): bool => $delta !== null && (float) $delta > 0)->keys()->values()->all()
                : [],
            'gate_failures' => $failedReasons,
            'technical_errors' => $technicalErrors,
            'mutation_targets' => $targets,
            'targeted_rescue_attempts_before_generation' => $targetedAttempts,
            'kpis' => [
                'technical_completion_rate' => $agents->count() > 0 ? round($cleanTerminal / $agents->count() * 100, 2) : 0,
                'screening_pass_rate' => $screenDecisions->count() > 0 ? round($screenPassed / $screenDecisions->count() * 100, 2) : 0,
                'full_validation_completion_rate' => $selected > 0 ? round($performances->count() / $selected * 100, 2) : 0,
                'forward_valid_agents' => $forwardValidated,
                'paper_transition_time_seconds' => $paperTransition,
                ...$coverageKpis,
            ],
            'next_action' => $this->nextAction($generation, $technicalErrors, $screenPassed, $screenDecisions->count(), $selected, $forwardValidated, $targetedAttempts),
            'promotion_evidence' => false,
            'rule' => 'A generation report describes evidence; it never changes a gate decision or creates paper eligibility.',
        ];

        $context = (array) $generation->trigger_context;
        $reports = collect((array) data_get($context, 'generation_reports', []))
            ->reject(fn ($item): bool => data_get($item, 'phase') === $phase)
            ->push($report)->values()->all();
        $context['generation_reports'] = $reports;
        $context['latest_generation_report'] = $report;
        $generation->update(['trigger_context' => $context]);

        return $report;
    }

    /** Coverage is reported separately so sparse specialist evidence cannot
     * hide behind aggregate PF or a portfolio result. */
    private function coverageKpis(Collection $agents, Collection $performances): array
    {
        $cells = [];
        $profitable = $abstentions = $missed = $routerContribution = 0;
        foreach ($agents as $agent) {
            $result = (array) ($performances->get($agent->model_version_id)?->metrics
                ?? data_get($agent->modelVersion?->metadata, 'last_result', data_get($agent->modelVersion?->metadata, 'last_screen_result', [])));
            foreach ((array) data_get($result, 'certified_coverage_passport.cells', []) as $key => $cell) {
                $cells[$key] = $cell;
                if ((float) data_get($cell, 'trade_pf', 0) > 1 && (int) data_get($cell, 'trade_count', 0) > 0) $profitable++;
                $abstentions += (int) data_get($cell, 'abstain_shadow_count', 0);
                $missed += (int) data_get($cell, 'missed_profitable_opportunities', 0);
            }
            if ($agent->strategy_family === 'differential_router') {
                $routerContribution += (int) data_get($result, 'total_trades', 0);
            }
        }
        $certified = collect($cells)->filter(fn ($cell): bool => data_get($cell, 'trade_permission') === 'CERTIFIED' || data_get($cell, 'abstain_permission') === 'CERTIFIED')->count();
        $recalls = $agents->map(function (LabAgent $agent) use ($performances): mixed {
            $result = (array) ($performances->get($agent->model_version_id)?->metrics ?? data_get($agent->modelVersion?->metadata, 'last_result', []));
            return data_get($result, 'opportunity_recall.opportunity_recall');
        })->filter(fn ($value) => is_numeric($value));
        return [
            'certified_cells' => $certified, 'uncertified_cells' => max(0, count($cells) - $certified),
            'profitable_trade_cells' => $profitable, 'abstention_cells' => $abstentions,
            'missed_profitable_opportunity_cells' => $missed,
            'coverage_recall' => $recalls->isEmpty() ? null : round((float) $recalls->avg(), 6),
            'router_contribution' => $routerContribution,
        ];
    }

    /** Return the current KPI packet for every active laboratory. */
    public function currentKpis(?string $symbol = null, ?string $timeframe = null): array
    {
        $labs = AiLaboratory::query()->where('is_active', true)
            ->when($symbol, fn ($query) => $query->where('symbol', strtoupper($symbol)))
            ->when($timeframe, fn ($query) => $query->where('timeframe', strtoupper($timeframe)))
            ->orderBy('symbol')->get();

        return $labs->map(function (AiLaboratory $lab): array {
            $generation = $lab->generations()->with('agents.modelVersion')->latest('generation')->first();
            $report = (array) data_get($generation?->trigger_context, 'latest_generation_report', []);
            return [
                'symbol' => $lab->symbol,
                'timeframe' => $lab->timeframe,
                'generation' => $generation?->generation,
                'status' => $generation?->status,
                'kpis' => (array) ($report['kpis'] ?? []),
                'next_action' => $report['next_action'] ?? 'generation_report_pending',
                'technical_errors' => count((array) ($report['technical_errors'] ?? [])),
                'paper_eligible' => ModelMarketPerformance::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->whereIn('status', ['forward_validated', 'paper', 'champion'])->count(),
            ];
        })->values()->all();
    }

    private function bestAgent(Collection $agents, Collection $performances): ?LabAgent
    {
        $eligible = $agents->reject(fn (LabAgent $agent): bool => $agent->lifecycle_status === 'evaluation_error');

        // Once full-validation evidence exists, the report must describe the
        // best evaluated candidate—not a small-sample screening outlier that
        // was never replayed under the sealed contract.
        $evaluated = $eligible->filter(fn (LabAgent $agent): bool => $performances->has($agent->model_version_id));
        if ($evaluated->isNotEmpty()) $eligible = $evaluated;

        return $eligible
            ->sortByDesc(function (LabAgent $agent) use ($performances): float {
                $metrics = (array) ($performances->get($agent->model_version_id)?->metrics ?? []);
                return ((float) data_get($metrics, 'profit_factor', $agent->profit_factor ?? 0) * 1000)
                    + (int) ($agent->sample_count ?? 0);
            })->first();
    }

    private function parentPerformance(?LabAgent $agent, LabGeneration $generation): ?ModelMarketPerformance
    {
        $parentId = $agent?->parent_a_model_version_id ?: $agent?->parent_b_model_version_id;
        if (! $parentId) return null;
        return ModelMarketPerformance::query()->where('model_version_id', $parentId)
            ->where('symbol', $generation->laboratory?->symbol)->where('timeframe', $generation->laboratory?->timeframe)
            ->where('evidence_status', 'valid')->latest('id')->first();
    }

    private function metrics(array $result, int $sampleCount): array
    {
        return [
            'profit_factor' => $this->number(data_get($result, 'profit_factor'), 0),
            'stress_cost_pf' => $this->number(data_get($result, 'pf_attribution.stress_cost.profit_factor', data_get($result, 'stress_profit_factor')), 0),
            'worst_regime_pf' => $this->number(data_get($result, 'screening_survival.worst_regime_pf', data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf')), 0),
            'worst_temporal_pf' => $this->number(data_get($result, 'screening_survival.worst_temporal_chunk_pf', data_get($result, 'screening_survival.worst_window_pf')), 0),
            'worst_calendar_pf' => $this->number(data_get($result, 'screening_survival.worst_calendar_month_pf'), 0),
            'sample_count' => $this->number(data_get($result, 'total_trades', data_get($result, 'sample_count', $sampleCount)), $sampleCount),
        ];
    }

    private function reasonCounts(Collection $decisions): array
    {
        $counts = [];
        foreach ($decisions as $decision) {
            foreach (array_values(array_unique((array) $decision->reason_codes)) as $reason) {
                // Selection outcomes and waiting states describe routing, not
                // a failed quality gate.  Keep the report focused on actual
                // falsifiers so the next mutation is not aimed at a queue
                // status such as FULL_REPLAY_ELIGIBLE.
                if (! preg_match('/^(FAILED_|INSUFFICIENT_|DOMINATED_|OVERFIT|REJECTED)/', (string) $reason)) continue;
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    private function number(mixed $value, float|int $fallback): float|int
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }

    private function nextAction(LabGeneration $generation, array $technicalErrors, int $screenPassed, int $screenDecisions, int $selected, int $forwardValidated, int $targetedAttempts): string
    {
        if ($technicalErrors !== []) return 'recover_technical_errors_before_quality_interpretation';
        if ($forwardValidated > 0) return 'paper_admission_handshake';
        if ($generation->status === 'screened' && $selected === 0) {
            return $targetedAttempts >= 2 ? 'data_edge_audit_required' : 'targeted_rescue_for_dominant_gate_failure';
        }
        if ($generation->status === 'screened' && $screenDecisions > 0 && $screenPassed === 0) {
            return $targetedAttempts >= 2 ? 'data_edge_audit_required' : 'targeted_rescue_for_dominant_gate_failure';
        }
        if ($generation->status === 'completed') {
            return $targetedAttempts >= 2
                ? 'data_edge_audit_required'
                : 'targeted_rescue_for_dominant_gate_failure';
        }
        if ($generation->status === 'full_validation' || $selected > 0) return 'complete_full_validation_before_new_generation';
        return 'finish_current_generation_phase';
    }
}
