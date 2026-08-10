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
        $parentModelVersionIds = $best
            ? app(ParentContributionGraphService::class)->ids($best)
            : [];
        $parentPerformances = $this->parentPerformances($best, $generation, $parentModelVersionIds);
        $parent = $parentPerformances->first();
        $parentMetrics = $parent ? $this->metrics((array) $parent->metrics, (int) $parent->sample_count) : null;
        $bestMetrics = $this->metrics($bestResult, (int) ($best?->sample_count ?? 0));
        $parentDeltaFor = function (?ModelMarketPerformance $candidate) use ($bestMetrics): ?array {
            if (! $candidate) return null;
            $metrics = $this->metrics((array) $candidate->metrics, (int) $candidate->sample_count);
            return collect($bestMetrics)->mapWithKeys(function ($value, string $key) use ($metrics): array {
                return [$key => $value === null || $metrics[$key] === null ? null : round((float) $value - (float) $metrics[$key], 6)];
            })->all();
        };
        $parentDeltasByParent = $parentPerformances->mapWithKeys(fn (ModelMarketPerformance $candidate): array => [
            (string) $candidate->model_version_id => $parentDeltaFor($candidate),
        ])->all();
        $parentDelta = $parentMetrics ? collect($bestMetrics)->mapWithKeys(function ($value, string $key) use ($parentMetrics): array {
            return [$key => $value === null || $parentMetrics[$key] === null ? null : round((float) $value - (float) $parentMetrics[$key], 6)];
        })->all() : null;
        $gateImprovements = collect($parentDeltasByParent)
            ->flatMap(fn ($delta): array => collect((array) $delta)
                ->filter(fn ($value): bool => $value !== null && (float) $value > 0)
                ->keys()->all())
            ->unique()->values()->all();

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
        $populationGroupCheckpoints = $this->populationGroupCheckpoints($agents, $performances);

        $report = [
            'protocol' => self::PROTOCOL,
            'phase' => $phase,
            'recorded_at' => now()->utc()->toIso8601String(),
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'symbol' => $generation->laboratory?->symbol,
            'timeframe' => $generation->laboratory?->timeframe,
            'status' => $generation->status,
            'parent_model_version_ids' => $parentModelVersionIds,
            'parent_deltas_by_parent' => $parentDeltasByParent,
            // Keep the legacy key for dashboard compatibility, but make its
            // scope explicit: this is only a diagnostic representative. The
            // evolutionary/runtime object is the specialist council below,
            // never one global champion.
            'best_agent' => $best ? [
                'id' => $best->id,
                'lifecycle_status' => $best->lifecycle_status,
                'strategy_family' => $best->strategy_family,
                'sample_count' => $best->sample_count,
                'profit_factor' => $best->profit_factor,
                'performance_status' => $bestPerformance?->status,
                'scope' => 'diagnostic_representative_only',
                'global_champion' => false,
            ] : null,
            'council' => [
                'protocol' => 'specialist_council_v1',
                'global_champion_forbidden' => true,
                'member_model' => 'complementary_specialists_by_research_group_and_semantic_cell',
                'selection_rule' => 'retain a same-cell frontier of parameter specialists; do not collapse the council to headline PF or one global winner',
                'group_frontiers' => collect($populationGroupCheckpoints)->map(fn (array $checkpoint): array => [
                    'key' => $checkpoint['key'],
                    'member_agent_ids' => data_get($checkpoint, 'checkpoint.member_agent_ids', []),
                    'member_model_version_ids' => data_get($checkpoint, 'checkpoint.member_model_version_ids', []),
                    'status' => data_get($checkpoint, 'checkpoint.status'),
                ])->values()->all(),
                'combined_runtime_activation' => 'only_after_individual_member_passports_and_council_quorum',
                'promotion_evidence' => false,
            ],
            'parent_delta' => $parentDelta,
            'gate_improvements' => $gateImprovements,
            'gate_failures' => $failedReasons,
            'technical_errors' => $technicalErrors,
            'mutation_targets' => $targets,
            'population_group_checkpoints' => $populationGroupCheckpoints,
            'targeted_rescue_attempts_before_generation' => $targetedAttempts,
            'kpis' => [
                'technical_completion_rate' => $agents->count() > 0 ? round($cleanTerminal / $agents->count() * 100, 2) : 0,
                'screening_pass_rate' => $screenDecisions->count() > 0 ? round($screenPassed / $screenDecisions->count() * 100, 2) : 0,
                'full_validation_completion_rate' => $selected > 0 ? round($performances->count() / $selected * 100, 2) : 0,
                'forward_valid_agents' => $forwardValidated,
                'paper_transition_time_seconds' => $paperTransition,
                'population_groups' => collect($populationGroupCheckpoints)->map(fn (array $checkpoint): array => [
                    'key' => $checkpoint['key'],
                    'agent_count' => $checkpoint['agent_count'],
                    'checkpoint_status' => data_get($checkpoint, 'checkpoint.status'),
                ])->values()->all(),
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

    /**
     * Produce one durable checkpoint packet per research group. This is a
     * progress ledger, not a promotion shortcut: only a valid evaluated model
     * can become the next group's checkpoint; screening/quarantine remains
     * explanatory evidence only.
     *
     * @param Collection<int, LabAgent> $agents
     * @param Collection<int, ModelMarketPerformance> $performances
     * @return array<string, array<string, mixed>>
     */
    private function populationGroupCheckpoints(Collection $agents, Collection $performances): array
    {
        $groups = $agents->groupBy(function (LabAgent $agent): string {
            return (string) data_get(
                $agent->modelVersion?->metadata,
                'population_group.key',
                data_get($agent->modelVersion?->metadata, 'generation_target', 'unassigned'),
            );
        });

        $terminalStatuses = ['screened', 'challenger', 'overfit', 'rejected', 'stagnated', 'forward_validated', 'paper', 'champion', 'archived'];
        $checkpointStatuses = ['challenger', 'forward_validated', 'paper', 'champion'];
        $result = [];

        foreach ($groups as $key => $members) {
            $ranked = $members->sortByDesc(function (LabAgent $agent) use ($performances): array {
                $performance = $performances->get($agent->model_version_id);
                return [
                    $performance?->status === 'champion' ? 1 : 0,
                    (float) ($performance?->forward_score ?? $agent->forward_score ?? 0),
                    (float) ($performance?->profit_factor ?? $agent->profit_factor ?? 0),
                    (int) ($performance?->sample_count ?? $agent->sample_count ?? 0),
                ];
            })->values();
            $best = $ranked->first();
            $bestPerformance = $best ? $performances->get($best->model_version_id) : null;
            $checkpointEligible = function (LabAgent $agent) use ($performances, $checkpointStatuses, $terminalStatuses): bool {
                $performance = $performances->get($agent->model_version_id);
                return $performance
                    && $performance->evidence_status === 'valid'
                    && in_array((string) $performance->status, $checkpointStatuses, true)
                    && in_array((string) $agent->lifecycle_status, $terminalStatuses, true);
            };
            $frontier = $members->filter($checkpointEligible)->values();
            $representative = $frontier->first() ?: $best;
            $representativePerformance = $representative ? $performances->get($representative->model_version_id) : null;

            $result[(string) $key] = [
                'protocol' => 'population_group_checkpoint_v1',
                'key' => (string) $key,
                'agent_count' => $members->count(),
                'terminal_agent_count' => $members->whereIn('lifecycle_status', $terminalStatuses)->count(),
                'depth_agents' => $members->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'population_group.search_mode') === 'depth')->pluck('id')->values()->all(),
                'breadth_agents' => $members->filter(fn (LabAgent $agent): bool => str_starts_with((string) data_get($agent->modelVersion?->metadata, 'population_group.search_mode'), 'breadth'))->pluck('id')->values()->all(),
                'frontier_member_count' => $frontier->count(),
                'frontier_members' => $members->map(function (LabAgent $agent) use ($performances, $checkpointEligible): array {
                    $performance = $performances->get($agent->model_version_id);
                    return [
                        'agent_id' => $agent->id,
                        'model_version_id' => $agent->model_version_id,
                        'strategy_family' => $agent->strategy_family,
                        'search_role' => data_get($agent->modelVersion?->metadata, 'population_group.search_role'),
                        'parameter_specialties' => array_keys((array) $agent->parameter_diff),
                        'lifecycle_status' => $agent->lifecycle_status,
                        'performance_status' => $performance?->status,
                        'evidence_status' => $performance?->evidence_status,
                        'council_frontier_eligible' => $checkpointEligible($agent),
                        'promotion_evidence' => false,
                    ];
                })->values()->all(),
                'diagnostic_representative' => $representative ? [
                    'agent_id' => $representative->id,
                    'model_version_id' => $representative->model_version_id,
                    'performance_status' => $representativePerformance?->status,
                    'scope' => 'group_diagnostic_representative_only',
                    'global_champion' => false,
                ] : null,
                'checkpoint' => [
                    'status' => $frontier->isNotEmpty() ? 'eligible_for_exact_parent_frontier' : ($best ? 'diagnostic_only' : 'no_result'),
                    'member_agent_ids' => $frontier->pluck('id')->values()->all(),
                    'member_model_version_ids' => $frontier->pluck('model_version_id')->values()->all(),
                    'singleton_forbidden' => true,
                    'rule' => 'Only valid challenger/forward/paper/champion evidence may advance this group frontier; the group retains complementary specialists and never borrows a foreign semantic parent.',
                    'promotion_evidence' => false,
                ],
                'progress_rule' => 'Compare the next four-seat cohort with this group checkpoint; carry confirmed beneficial traits only after independent replay credit.',
                'promotion_evidence' => false,
            ];
        }

        return $result;
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

    /** @return Collection<int, ModelMarketPerformance> */
    private function parentPerformances(?LabAgent $agent, LabGeneration $generation, array $parentIds = []): Collection
    {
        if ($parentIds === [] && $agent) $parentIds = app(ParentContributionGraphService::class)->ids($agent);
        if ($parentIds === []) return collect();
        return ModelMarketPerformance::query()->whereIn('model_version_id', $parentIds)
            ->where('symbol', $generation->laboratory?->symbol)->where('timeframe', $generation->laboratory?->timeframe)
            ->where('evidence_status', 'valid')->latest('id')->get()
            ->unique('model_version_id')
            ->sortBy(fn (ModelMarketPerformance $performance): int => (int) array_search(
                (int) $performance->model_version_id,
                $parentIds,
                true,
            ))
            ->values();
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
