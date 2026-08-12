<?php

namespace App\Services;

use App\Models\MtfAblationRun;
use App\Models\MtfStrategyResearchRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Turns immutable MTF experiments into decisions about the next experiment.
 * It is deliberately diagnostic: a report can recommend a forward review,
 * but it cannot promote a model or change a gate.
 */
class MtfStrategyResearchReportService
{
    public const PROTOCOL = 'xauusd_mtf_research_report_v2';

    public function __construct(private MtfStrategyResearchService $catalog) {}

    /** @return array<string, mixed> */
    public function report(string $symbol = 'XAUUSD', int $lookbackHours = 720): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $now = CarbonImmutable::now('UTC');
        $from = $now->subHours(max(1, $lookbackHours));
        $runs = Schema::hasTable('mtf_strategy_research_runs')
            ? MtfStrategyResearchRun::query()
                ->where('symbol', $symbol)
                ->where(function ($query) use ($from): void {
                    $query->where('completed_at', '>=', $from)->orWhereNull('completed_at');
                })
                ->latest('completed_at')
                ->latest('id')
                ->get()
            : collect();
        $analyses = $runs->map(fn (MtfStrategyResearchRun $run): array => $this->analyseRun($run))->values()->all();
        $latestAblation = $this->latestAblation($symbol);
        $currentDataHash = $latestAblation['data_hash'] ?? null;
        $currentRuns = $runs->filter(fn (MtfStrategyResearchRun $run): bool =>
            $currentDataHash !== null
            && $run->data_hash === $currentDataHash
            && $run->status === 'completed'
            && (array) data_get($run->result, 'frozen_control.m15_only', []) !== []
        );
        $families = $this->familyBudget($currentRuns);

        return [
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            'generated_at' => $now->toIso8601String(),
            'lookback_hours' => max(1, $lookbackHours),
            'catalog' => array_map(fn (array $item): array => [
                'key' => $item['key'],
                'strategy' => $item['strategy'],
                'family' => $item['family'],
                'mutation_class' => $item['mutation_class'],
                'target_gate' => $item['target_gate'],
                'evidence_basis' => $item['evidence_basis'] ?? [],
            ], $this->catalog->catalog()),
            'run_count' => count($analyses),
            'current_cohort_data_hash' => $currentDataHash,
            'current_cohort_run_count' => $currentRuns->count(),
            'runs' => $analyses,
            'family_budget' => $families,
            'latest_controlled_ablation' => $latestAblation,
            'next_research_actions' => $this->nextActions($currentRuns->map(fn (MtfStrategyResearchRun $run): array => $this->analyseRun($run))->values()->all(), $families),
            'promotion_evidence' => false,
            'rule' => 'Only a forward-valid candidate can start official paper; this report is learning evidence, not promotion evidence.',
        ];
    }

    /** @return array<string, mixed> */
    private function analyseRun(MtfStrategyResearchRun $run): array
    {
        $variants = (array) data_get($run->result, 'variants', []);
        $mtf = (array) ($variants['h1_veto_m15_risk'] ?? []);
        $m15 = (array) ($variants['m15_only'] ?? []);
        $h1Regime = (array) ($variants['h1_regime_m15'] ?? []);
        $h1 = (array) ($variants['h1_only'] ?? []);
        $frozenControl = $this->frozenControl($run);
        $frozenM15 = (array) ($frozenControl['m15_only'] ?? []);
        $referenceMtf = (array) ($frozenControl['official_mtf'] ?? []);
        $mtfPf = (float) ($mtf['profit_factor'] ?? 0);
        $targetM15Pf = (float) ($m15['profit_factor'] ?? 0);
        $m15Pf = (float) ($frozenM15['profit_factor'] ?? 0);
        $mtfNet = (float) ($mtf['net_profit_percent'] ?? 0);
        $targetM15Net = (float) ($m15['net_profit_percent'] ?? 0);
        $m15Net = (float) ($frozenM15['net_profit_percent'] ?? 0);
        $mtfDd = (float) ($mtf['max_drawdown_percent'] ?? 0);
        $targetM15Dd = (float) ($m15['max_drawdown_percent'] ?? 0);
        $m15Dd = (float) ($frozenM15['max_drawdown_percent'] ?? 0);
        $vetoCount = (int) data_get($mtf, 'mtf_pilot.veto_count', 0);
        $contextTotal = array_sum(array_map('intval', (array) data_get($mtf, 'mtf_pilot.context_counts', [])));
        $vetoRate = $contextTotal > 0 ? round($vetoCount / $contextTotal, 4) : null;
        $trades = (int) ($mtf['total_trades'] ?? 0);
        $sampleStatus = $trades < 30 ? 'low_sample' : 'usable_screening_sample';
        $mutationEffective = $this->mutationEffective($mtf, $referenceMtf);
        $targetGate = (string) data_get($run->research_contract, 'target_gate', 'unknown');
        $targetGateImproved = $mutationEffective && $this->targetGateImproved($targetGate, $mtf, $referenceMtf);
        $classification = $frozenM15 === []
            ? 'frozen_control_missing'
            : ($run->status !== 'completed'
                ? 'evidence_recovery_required'
                : ($sampleStatus === 'low_sample'
                    ? 'low_sample'
                    : (! $mutationEffective
                        ? 'mutation_no_observable_effect'
                        : $this->classify($run->status, $mtfPf, $m15Pf, $mtfNet, $m15Net, $mtfDd, $m15Dd, $trades))));
        $highVeto = $vetoRate !== null && $vetoRate > 0.75;

        return [
            'research_run_id' => $run->id,
            'candidate_id' => $run->model_market_performance_id,
            'hypothesis_key' => $run->hypothesis_key,
            'strategy_identity' => $run->strategy_identity,
            'strategy_family' => $run->strategy_family,
            'mutation_class' => data_get($run->research_contract, 'mutation_class'),
            'target_gate' => data_get($run->research_contract, 'target_gate'),
            'status' => $run->status,
            'failure_class' => $run->failure_class,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'data_hash' => $run->data_hash,
            'parameter_hash' => $run->parameter_hash,
            'execution_hash' => $run->execution_hash,
            'sample_status' => $sampleStatus,
            'classification' => $classification,
            'mutation_effective' => $mutationEffective,
            'target_gate_improved' => $targetGateImproved,
            'h1_only' => $this->metrics($h1),
            'm15_only' => $this->metrics($m15),
            'frozen_m15_control' => $this->metrics($frozenM15),
            'reference_official_mtf' => $this->metrics($referenceMtf),
            'h1_regime_m15' => $this->metrics($h1Regime),
            'h1_veto_m15_risk' => [
                ...$this->metrics($mtf),
                'veto_count' => $vetoCount,
                'context_count' => $contextTotal,
                'veto_rate' => $vetoRate,
            ],
            'delta_vs_m15' => [
                'profit_factor' => round($mtfPf - $m15Pf, 4),
                'net_profit_percent' => round($mtfNet - $m15Net, 4),
                'max_drawdown_percent' => round($mtfDd - $m15Dd, 4),
                'trades' => $trades - (int) ($frozenM15['total_trades'] ?? 0),
            ],
            'delta_vs_target_strategy_m15_only' => [
                'profit_factor' => round($mtfPf - $targetM15Pf, 4),
                'net_profit_percent' => round($mtfNet - $targetM15Net, 4),
                'max_drawdown_percent' => round($mtfDd - $targetM15Dd, 4),
                'trades' => $trades - (int) ($m15['total_trades'] ?? 0),
            ],
            'mutation_delta_vs_official_mtf' => [
                'profit_factor' => round($mtfPf - (float) ($referenceMtf['profit_factor'] ?? 0), 4),
                'net_profit_percent' => round($mtfNet - (float) ($referenceMtf['net_profit_percent'] ?? 0), 4),
                'max_drawdown_percent' => round($mtfDd - (float) ($referenceMtf['max_drawdown_percent'] ?? 0), 4),
                'trades' => $trades - (int) ($referenceMtf['total_trades'] ?? 0),
            ],
            'frozen_control_run_id' => $frozenControl['run_id'] ?? null,
            'high_veto_pressure' => $highVeto,
            'next_action' => $this->nextAction($classification, $highVeto, $sampleStatus),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function metrics(array $variant): array
    {
        return [
            'total_trades' => (int) ($variant['total_trades'] ?? 0),
            'profit_factor' => (float) ($variant['profit_factor'] ?? 0),
            'net_profit_percent' => (float) ($variant['net_profit_percent'] ?? 0),
            'max_drawdown_percent' => (float) ($variant['max_drawdown_percent'] ?? 0),
            'winrate' => (float) ($variant['winrate'] ?? 0),
        ];
    }

    private function classify(string $status, float $mtfPf, float $m15Pf, float $mtfNet, float $m15Net, float $mtfDd, float $m15Dd, int $trades): string
    {
        if ($status !== 'completed') return 'evidence_recovery_required';
        if ($trades < 30) return 'low_sample';
        if ($mtfPf >= $m15Pf && $mtfNet >= $m15Net && $mtfDd <= $m15Dd) return 'alpha_and_risk_candidate';
        if ($mtfDd <= $m15Dd - 0.50 && $mtfPf >= $m15Pf * 0.90) return 'risk_stabilizer';
        if ($mtfPf > $m15Pf && $mtfDd > $m15Dd * 1.10) return 'alpha_with_drawdown_cost';
        if ($mtfPf < $m15Pf * 0.90 && $mtfDd >= $m15Dd * 0.95) return 'mtf_entry_starvation';
        return 'inconclusive';
    }

    private function nextAction(string $classification, bool $highVeto, string $sampleStatus): string
    {
        if ($classification === 'frozen_control_missing') return 'Run the four-lane ablation for this exact candidate/data hash before interpreting the hypothesis.';
        if ($classification === 'evidence_recovery_required') return 'Technical recovery only; do not convert this row into learning.';
        if ($sampleStatus === 'low_sample') return 'Keep research-only; collect a longer forward/shadow sample before tuning.';
        if ($classification === 'mutation_no_observable_effect') return 'Mutation produced no observable topology change; freeze this parent and redesign or retire the hypothesis instead of adding parameters.';
        if ($classification === 'alpha_and_risk_candidate') return 'Freeze parameters and request a separate forward-validation review; do not promote automatically.';
        if ($classification === 'risk_stabilizer') return 'Preserve H1 veto/risk scaling and mutate M15 entry or exit topology.';
        if ($classification === 'alpha_with_drawdown_cost') return 'Stress-test cost/exit topology before valuing the apparent alpha.';
        if ($classification === 'mtf_entry_starvation') return 'Keep H1 veto; target entry recall, directional specialist, or transition handling. Never relax the veto from this result.';
        if ($highVeto) return 'Inspect regime/entry mismatch and transition specialist; high veto is a failure signal, not a reason to lower gates.';
        return 'Run the next declared hypothesis or collect forward evidence; no random mutation.';
    }

    /** @param Collection<int, MtfStrategyResearchRun> $runs @return array<string, mixed> */
    private function familyBudget(Collection $runs): array
    {
        // A retry with the same hypothesis/parameter/data identity is one
        // experiment, not a new evidence-budget credit. Keep only the newest
        // completed row for each identity before counting the family.
        $runs = $runs
            ->sortByDesc(fn (MtfStrategyResearchRun $run): int => $run->id)
            ->unique(fn (MtfStrategyResearchRun $run): string => implode('|', [
                $run->strategy_family,
                $run->hypothesis_key,
                $run->parameter_hash,
                $run->data_hash,
            ]));
        $result = [];
        foreach ($runs->groupBy('strategy_family') as $family => $familyRuns) {
            $analyses = $familyRuns
                ->sortByDesc(fn (MtfStrategyResearchRun $run): int => $run->id)
                ->map(fn (MtfStrategyResearchRun $run): array => $this->analyseRun($run))
                ->values();
            $consecutiveWithoutImprovement = 0;
            foreach ($analyses as $analysis) {
                if ($this->gateImproved($analysis)) break;
                $consecutiveWithoutImprovement++;
            }
            $result[$family ?: 'unknown'] = [
                'run_count' => $analyses->count(),
                'latest_hypotheses' => $analyses->pluck('hypothesis_key')->take(3)->all(),
                'consecutive_without_gate_improvement' => $consecutiveWithoutImprovement,
                'status' => $consecutiveWithoutImprovement >= 3 ? 'pause_research_family' : 'continue_bounded_research',
                'rule' => 'Three consecutive bounded attempts without a gate improvement pauses the family in the report only.',
                'promotion_evidence' => false,
            ];
        }
        return $result;
    }

    /** @param array<string, mixed> $analysis */
    private function gateImproved(array $analysis): bool
    {
        return ! in_array($analysis['classification'] ?? null, ['low_sample', 'frozen_control_missing', 'evidence_recovery_required'], true)
            && (bool) ($analysis['target_gate_improved'] ?? false);
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $reference */
    private function mutationEffective(array $candidate, array $reference): bool
    {
        if ($reference === []) return false;
        return (int) ($candidate['total_trades'] ?? 0) !== (int) ($reference['total_trades'] ?? 0)
            || abs((float) ($candidate['profit_factor'] ?? 0) - (float) ($reference['profit_factor'] ?? 0)) > 0.0001
            || abs((float) ($candidate['net_profit_percent'] ?? 0) - (float) ($reference['net_profit_percent'] ?? 0)) > 0.0001
            || abs((float) ($candidate['max_drawdown_percent'] ?? 0) - (float) ($reference['max_drawdown_percent'] ?? 0)) > 0.0001;
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $reference */
    private function targetGateImproved(string $gate, array $candidate, array $reference): bool
    {
        if ($reference === []) return false;
        $pf = (float) ($candidate['profit_factor'] ?? 0);
        $refPf = (float) ($reference['profit_factor'] ?? 0);
        $dd = (float) ($candidate['max_drawdown_percent'] ?? 0);
        $refDd = (float) ($reference['max_drawdown_percent'] ?? 0);

        return match ($gate) {
            'forward_profit_factor', 'range_profit_factor' => $pf > $refPf + 0.05,
            'stress_drawdown', 'false_positive_control' => $dd < $refDd - 0.25 && $pf >= $refPf * 0.90,
            'trend_up_stability', 'regime_coverage_and_drawdown' => $pf >= $refPf && $dd <= $refDd,
            default => $pf >= $refPf && $dd <= $refDd,
        };
    }

    /** @return array<string, mixed> */
    private function latestAblation(string $symbol): array
    {
        if (! Schema::hasTable('mtf_ablation_runs')) {
            return ['status' => 'missing', 'promotion_evidence' => false];
        }
        $run = MtfAblationRun::query()->where('symbol', $symbol)->latest('completed_at')->first();
        if (! $run) return ['status' => 'missing', 'promotion_evidence' => false];
        return [
            'status' => $run->status,
            'run_id' => $run->id,
            'candidate_id' => $run->model_market_performance_id,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'data_hash' => $run->data_hash,
            'execution_hash' => $run->execution_hash,
            'variants' => array_map(fn (array $variant): array => $this->metrics($variant), (array) $run->variants),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function frozenControl(MtfStrategyResearchRun $run): array
    {
        $embedded = (array) data_get($run->result, 'frozen_control', []);
        if ((array) ($embedded['m15_only'] ?? []) !== [] && (array) ($embedded['official_mtf'] ?? []) !== []) return $embedded;
        if (! Schema::hasTable('mtf_ablation_runs')) return [];

        $ablation = ($embedded['run_id'] ?? null)
            ? MtfAblationRun::query()->find((int) $embedded['run_id'])
            : MtfAblationRun::query()
                ->where('model_market_performance_id', $run->model_market_performance_id)
                ->where('data_hash', $run->data_hash)
                ->where('execution_hash', $run->execution_hash)
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first();
        if (! $ablation) return [];

        return [
            'protocol' => 'frozen_m15_control_v1',
            'run_id' => $ablation->id,
            'candidate_id' => $ablation->model_market_performance_id,
            'data_hash' => $ablation->data_hash,
            'execution_hash' => $ablation->execution_hash,
            'm15_only' => (array) data_get($ablation->variants, 'm15_only', []),
            'official_mtf' => (array) data_get($ablation->variants, 'h1_veto_m15_risk', []),
            'promotion_evidence' => false,
        ];
    }

    /** @param list<array<string, mixed>> $analyses @param array<string, mixed> $families @return list<string> */
    private function nextActions(array $analyses, array $families): array
    {
        if ($analyses === []) return ['Run a bounded MTF hypothesis set before interpreting progress.'];
        $actions = [];
        foreach (array_slice($analyses, 0, 5) as $analysis) {
            $action = (string) ($analysis['next_action'] ?? '');
            if ($action !== '' && ! in_array($action, $actions, true)) $actions[] = $action;
        }
        foreach ($families as $family => $budget) {
            if (($budget['status'] ?? '') === 'pause_research_family') {
                $actions[] = "Pause {$family} experiments and redesign the architecture; do not add more parameters.";
            }
        }
        return array_values($actions);
    }
}
