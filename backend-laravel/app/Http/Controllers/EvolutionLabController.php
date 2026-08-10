<?php

namespace App\Http\Controllers;

use App\Models\AiLaboratory;
use App\Models\ModelMarketPerformance;
use App\Models\MutationMemory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\PaperConfidenceCalibration;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Services\LabPopulationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class EvolutionLabController extends Controller
{
    public function laboratory(string $symbol = 'XAUUSD', LabPopulationService $populations): View
    {
        $populations->ensureLaboratories();
        $lab = AiLaboratory::where('symbol', strtoupper($symbol))->firstOrFail();
        $generation = $lab->generations()->with(['agents.modelVersion', 'agents.parentA', 'agents.parentB', 'agents.progressCard'])->latest('generation')->first();
        $generationReport = (array) data_get($generation?->trigger_context, 'latest_generation_report', []);
        $champions = ModelMarketPerformance::with('modelVersion')->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->where('status', 'champion')->orderBy('strategy_family')->get();
        $candidates = ModelMarketPerformance::with('modelVersion')->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->where('evidence_status', 'valid')->orderByDesc('forward_score')->get();
        $challengers = $candidates->whereIn('status', ['challenger', 'forward_validated', 'paper']);
        $gateDiagnostics = $candidates->map(fn (ModelMarketPerformance $candidate) => $this->forwardGateDiagnostic($candidate, $champions));
        $memories = MutationMemory::where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->latest()->take(20)->get();
        $gateDecisions = CandidateGateDecision::with(['performance.modelVersion', 'labAgent.modelVersion'])
            ->where(function ($query) use ($lab): void {
                $query->whereHas('performance', fn ($performance) => $performance->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe))
                    ->orWhereHas('labAgent', fn ($agent) => $agent->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe));
            })->latest('evaluated_at')->take(30)->get();
        $agents = LabAgent::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe);
        $performances = ModelMarketPerformance::query()->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('evidence_status', 'valid');
        $performanceIds = (clone $performances)->pluck('id');
        $funnel = [
            'generated' => (clone $agents)->count(),
            // Only a completed screening is screening evidence.  Rejected
            // strategies and evaluator/runtime errors must remain visible as
            // separate terminal outcomes instead of inflating this stage.
            'screened' => (clone $agents)->where('lifecycle_status', 'screened')->count(),
            'evaluation_errors' => (clone $agents)->where('lifecycle_status', 'evaluation_error')->count(),
            'diagnostic_replay' => (clone $gateDecisions)->where('stage', 'diagnostic_rescue_replay')->count(),
            'full_replay_eligible' => (clone $agents)->whereIn('lifecycle_status', ['full_queued', 'training', 'challenger', 'forward_validated', 'paper', 'champion'])->count(),
            'full_evaluated' => (clone $performances)->count(),
            'forward_validated' => (clone $performances)->whereIn('status', ['forward_validated', 'paper', 'champion'])->count(),
            'paper_eligible' => (clone $performances)->whereIn('status', ['forward_validated', 'paper', 'champion'])->count(),
            'paper_signals' => PaperSignal::whereIn('model_market_performance_id', $performanceIds)->count(),
            'closed_outcomes' => PaperSignalOutcome::whereHas('signal', fn ($signal) => $signal->whereIn('model_market_performance_id', $performanceIds))->count(),
            'calibrated' => PaperConfidenceCalibration::where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('sample_count', '>=', (int) config('services.paper_calibration.minimum_samples', 20))->count(),
            'holdout_passed' => (clone $performances)->where('holdout_status', 'passed')->count(),
            'champion' => (clone $performances)->where('status', 'champion')->count(),
        ];
        $paperReadiness = app(\App\Services\PaperEvidenceReadinessService::class)->inspect();
        $generationPerformance = $lab->generations()->with('agents')->orderBy('generation')->get()->map(fn ($item) => [
            'generation' => $item->generation,
            'forward' => round((float) $item->agents->whereNotNull('forward_score')->avg('forward_score'), 2),
            'best' => round((float) $item->agents->max('forward_score'), 2),
        ]);
        $labs = AiLaboratory::orderBy('symbol')->get();
        return view('ai-laboratory.show', compact('lab', 'labs', 'generation', 'generationReport', 'champions', 'challengers', 'gateDiagnostics', 'memories', 'gateDecisions', 'funnel', 'paperReadiness', 'generationPerformance'));
    }

    private function forwardGateDiagnostic(ModelMarketPerformance $candidate, $champions): array
    {
        $metrics = $candidate->metrics ?? [];
        $champion = $champions->firstWhere('strategy_family', $candidate->strategy_family);
        $forwardGain = (float) $candidate->forward_score - (float) ($champion?->forward_score ?? 0);
        $gates = [
            'PF >= 1.30' => [(float) data_get($metrics, 'profit_factor', 0) >= 1.3, number_format((float) data_get($metrics, 'profit_factor', 0), 2)],
            'Drawdown <= 15%' => [(float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15, number_format((float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)), 2).'%'],
            'Ruin <= 10%' => [(float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10, number_format((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100), 2).'%'],
            '30+ trades' => [(int) $candidate->sample_count >= 30, (string) $candidate->sample_count],
            '3 rolling wins' => [(int) $candidate->rolling_windows_count >= 3 && (int) $candidate->rolling_forward_wins >= 3, $candidate->rolling_forward_wins.'/'.$candidate->rolling_windows_count],
            'No overfit' => [! (bool) data_get($metrics, 'is_overfit', true), (bool) data_get($metrics, 'is_overfit', true) ? 'yes' : 'no'],
        ];
        $costProfile = data_get($metrics, 'pf_attribution');
        if (is_array($costProfile) && data_get($costProfile, 'method') === 'identical_replay_execution_profiles') {
            $stressPf = (float) data_get($costProfile, 'stress_cost.profit_factor', 0);
            $gates['Stress-cost PF >= 1.05'] = [$stressPf >= 1.05, number_format($stressPf, 2)];
        }
        $bootstrap = data_get($metrics, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        if (data_get($bootstrap, 'status') === 'assessed') {
            $lower = (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0);
            $gates['Bootstrap PF 5% >= 1.10'] = [$lower >= 1.1, number_format($lower, 2)];
        }
        if (data_get($metrics, 'statistical_evidence.edge_quality.worst_regime_sampled', false)) {
            $worst = (float) data_get($metrics, 'statistical_evidence.edge_quality.worst_regime_pf', 0);
            $gates['Worst-regime PF >= 1.00'] = [$worst >= 1.0, number_format($worst, 2)];
        }
        $diversity = data_get($metrics, 'behavioral_diversity.status');
        if ($diversity) $gates['Behavioural diversity'] = [$diversity !== 'near_duplicate', $diversity];
        $pboStatus = data_get($metrics, 'selection_validation.status');
        if ($pboStatus === 'assessed') {
            $pbo = (float) data_get($metrics, 'selection_validation.probability_of_backtest_overfitting', 1);
            $gates['CSCV PBO <= 50%'] = [$pbo <= 0.50, number_format($pbo * 100, 2).'%'];
        }
        $dsrStatus = data_get($metrics, 'statistical_evidence.deflated_sharpe.status');
        if ($dsrStatus === 'assessed') {
            $dsr = (float) data_get($metrics, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability', 0);
            $gates['Deflated Sharpe >= 95%'] = [$dsr >= 0.95, number_format($dsr * 100, 2).'%'];
        }
        if (data_get($metrics, 'parameter_plateau.status') !== null) {
            $plateau = data_get($metrics, 'parameter_plateau');
            $gates['Parameter plateau +/-10%'] = [
                data_get($plateau, 'status') === 'assessed' && (bool) data_get($plateau, 'pass', false),
                data_get($plateau, 'parameter', 'not assessed').' / '.data_get($plateau, 'status', 'unknown'),
            ];
        }
        if ($champion) {
            $gates['Champion delta >= 5'] = [$forwardGain >= 5, number_format($forwardGain, 2)];
        }

        return [
            'candidate' => $candidate,
            'gates' => $gates,
            'cost_profiles' => [
                'gross' => data_get($costProfile, 'normal_cost.summary.gross_pf'),
                'normal' => data_get($costProfile, 'normal_cost.profit_factor', data_get($metrics, 'profit_factor')),
                'stress' => data_get($costProfile, 'stress_cost.profit_factor'),
                'cost_ratio' => data_get($costProfile, 'normal_cost.summary.cost_to_gross_profit_percent'),
            ],
            'attribution' => data_get($costProfile, 'breakdown', data_get($metrics, 'pf_attribution', [])),
            'edge_claim' => data_get($metrics, 'edge_claim', []),
            'failed' => collect($gates)->filter(fn (array $gate) => ! $gate[0])->keys()->values(),
        ];
    }

    public function index(): RedirectResponse
    {
        return redirect()->route('ai-laboratory.show', ['symbol' => 'XAUUSD']);
    }
}
