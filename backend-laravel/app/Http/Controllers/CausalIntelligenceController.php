<?php

namespace App\Http\Controllers;

use App\Models\CausalCounterfactual;
use App\Models\CausalDiscoveryRun;
use App\Models\CausalEdge;
use App\Models\CausalEffectEstimate;
use App\Models\CausalExperiment;
use App\Models\CausalIntervention;
use App\Models\CausalNode;
use App\Models\CausalRootCause;
use App\Models\DiscoveryQualityScore;
use App\Services\CausalIntelligenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CausalIntelligenceController extends Controller
{
    public function index(): View
    {
        $latestRun = CausalDiscoveryRun::query()->latest()->first();
        $metrics = [
            'runs' => CausalDiscoveryRun::count(),
            'nodes' => CausalNode::count(),
            'edges' => CausalEdge::count(),
            'effects' => CausalEffectEstimate::count(),
            'counterfactuals' => CausalCounterfactual::count(),
            'interventions' => CausalIntervention::count(),
            'experiments' => CausalExperiment::count(),
            'root_causes' => CausalRootCause::count(),
        ];

        $edges = CausalEdge::query()->with(['sourceNode', 'targetNode', 'quantLaw'])->orderByDesc('causality_score')->take(12)->get();
        $rootCauses = CausalRootCause::query()->with('edge.sourceNode')->orderBy('rank')->take(10)->get();
        $counterfactuals = CausalCounterfactual::query()->with('edge.sourceNode')->latest()->take(10)->get();
        $interventions = CausalIntervention::query()->with('edge.sourceNode')->orderByDesc('expected_impact_score')->take(10)->get();
        $experiments = CausalExperiment::query()->with('edge.sourceNode')->orderByDesc('expected_information_gain')->take(10)->get();
        $qualityScores = DiscoveryQualityScore::query()->orderByDesc('quality_score')->take(10)->get();

        return view('causal-intelligence.index', compact(
            'latestRun',
            'metrics',
            'edges',
            'rootCauses',
            'counterfactuals',
            'interventions',
            'experiments',
            'qualityScores',
        ));
    }

    public function discover(CausalIntelligenceService $causal): RedirectResponse
    {
        $run = $causal->discover();

        if (! $run) {
            return back()->with('error', 'Causal Intelligence migrationlari hali ishlamagan.');
        }

        return redirect()->route('causal-intelligence.index')->with('success', 'Causal discovery yakunlandi.');
    }
}
