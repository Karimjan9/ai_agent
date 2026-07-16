<?php

namespace App\Http\Controllers;

use App\Models\QuantTheory;
use App\Models\TheoryBattle;
use App\Models\TheoryComponent;
use App\Models\TheoryEvolutionEvent;
use App\Models\TheoryGenerationRun;
use App\Models\TheoryPrediction;
use App\Models\UnifiedQuantModel;
use App\Services\AutonomousTheoryGenerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class TheoryLabController extends Controller
{
    public function index(): View
    {
        $latestRun = TheoryGenerationRun::query()->latest()->first();
        $metrics = [
            'runs' => TheoryGenerationRun::count(),
            'theories' => QuantTheory::count(),
            'dominant' => QuantTheory::where('status', 'dominant')->count(),
            'accepted' => QuantTheory::where('status', 'accepted')->count(),
            'components' => TheoryComponent::count(),
            'battles' => TheoryBattle::count(),
            'predictions' => TheoryPrediction::count(),
            'unified_models' => UnifiedQuantModel::count(),
        ];

        $theories = QuantTheory::query()
            ->withCount('components')
            ->orderByDesc('confidence_score')
            ->take(12)
            ->get();
        $battles = TheoryBattle::query()->with(['theoryA', 'theoryB', 'winner'])->latest()->take(8)->get();
        $predictions = TheoryPrediction::query()->with('theory')->latest()->take(10)->get();
        $events = TheoryEvolutionEvent::query()->with('theory')->latest()->take(10)->get();
        $models = UnifiedQuantModel::query()->orderByDesc('confidence_score')->take(6)->get();
        $components = TheoryComponent::query()->with('theory')->orderByDesc('contribution_score')->take(12)->get();

        return view('theory-lab.index', compact(
            'latestRun',
            'metrics',
            'theories',
            'battles',
            'predictions',
            'events',
            'models',
            'components',
        ));
    }

    public function generate(AutonomousTheoryGenerationService $theoryGeneration): RedirectResponse
    {
        $run = $theoryGeneration->generate();

        if (! $run) {
            return back()->with('error', 'Theory Generation migrationlari hali ishlamagan.');
        }

        return redirect()->route('theory-lab.index')->with('success', 'Theory generation yakunlandi.');
    }
}
