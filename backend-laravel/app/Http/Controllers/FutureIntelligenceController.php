<?php

namespace App\Http\Controllers;

use App\Models\FutureDiscovery;
use App\Models\FutureProbabilityNode;
use App\Models\FutureScenario;
use App\Models\FutureSimulationRun;
use App\Models\FutureStressTest;
use App\Models\FutureTimelineForecast;
use App\Models\StrategySurvivalForecast;
use App\Services\FutureSimulationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FutureIntelligenceController extends Controller
{
    public function index(): View
    {
        $latestRun = FutureSimulationRun::query()
            ->with(['marketSpecies', 'snapshot'])
            ->latest()
            ->first();

        $metrics = [
            'runs' => FutureSimulationRun::count(),
            'scenarios' => FutureScenario::count(),
            'survival_forecasts' => StrategySurvivalForecast::count(),
            'stress_tests' => FutureStressTest::count(),
            'discoveries' => FutureDiscovery::count(),
        ];

        $scenarios = FutureScenario::query()
            ->when($latestRun, fn ($query) => $query->where('future_simulation_run_id', $latestRun->id))
            ->orderByDesc('probability')
            ->take(12)
            ->get();

        $probabilityTree = FutureProbabilityNode::query()
            ->when($latestRun, fn ($query) => $query->where('future_simulation_run_id', $latestRun->id))
            ->with('parent')
            ->orderBy('parent_id')
            ->orderByDesc('probability')
            ->get();

        $timeline = FutureTimelineForecast::query()
            ->when($latestRun, fn ($query) => $query->where('future_simulation_run_id', $latestRun->id))
            ->orderBy('horizon_candles')
            ->get();

        $survivalForecasts = StrategySurvivalForecast::query()
            ->when($latestRun, fn ($query) => $query->where('future_simulation_run_id', $latestRun->id))
            ->orderByDesc('survival_probability')
            ->take(12)
            ->get();

        $stressTests = FutureStressTest::query()
            ->when($latestRun, fn ($query) => $query->where('future_simulation_run_id', $latestRun->id))
            ->orderByDesc('impact_score')
            ->take(12)
            ->get();

        $discoveries = FutureDiscovery::query()
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        return view('future-intelligence.index', compact(
            'latestRun',
            'metrics',
            'scenarios',
            'probabilityTree',
            'timeline',
            'survivalForecasts',
            'stressTests',
            'discoveries',
        ));
    }

    public function simulate(Request $request, FutureSimulationService $futureSimulation): RedirectResponse
    {
        $run = $futureSimulation->simulate(
            (string) $request->input('symbol', 'XAUUSD'),
            (string) $request->input('timeframe', 'H1'),
            (int) $request->input('scenario_count', 1000),
        );

        if (! $run) {
            return back()->with('error', 'Future simulation uchun latest Market Genome topilmadi.');
        }

        return redirect()
            ->route('future-intelligence.index')
            ->with('success', 'Future simulation run yaratildi.');
    }
}
