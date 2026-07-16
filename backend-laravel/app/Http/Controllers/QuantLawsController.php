<?php

namespace App\Http\Controllers;

use App\Models\QuantLaw;
use App\Models\QuantLawCandidate;
use App\Models\QuantLawConflict;
use App\Models\QuantLawDiscoveryRun;
use App\Models\QuantLawEvidence;
use App\Models\QuantLawGraphEdge;
use App\Models\UniversalDriverRanking;
use App\Services\UniversalQuantLawsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class QuantLawsController extends Controller
{
    public function index(): View
    {
        $latestRun = QuantLawDiscoveryRun::query()->latest()->first();

        $metrics = [
            'runs' => QuantLawDiscoveryRun::count(),
            'laws' => QuantLaw::count(),
            'active_laws' => QuantLaw::where('status', 'active')->count(),
            'candidates' => QuantLawCandidate::count(),
            'conflicts' => QuantLawConflict::where('status', 'open')->count(),
            'drivers' => UniversalDriverRanking::count(),
        ];

        $laws = QuantLaw::query()
            ->orderByDesc('confidence_score')
            ->orderByDesc('universality_score')
            ->take(12)
            ->get();

        $candidates = QuantLawCandidate::query()
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        $conflicts = QuantLawConflict::query()
            ->with(['lawA', 'lawB'])
            ->orderByDesc('severity_score')
            ->take(10)
            ->get();

        $graphEdges = QuantLawGraphEdge::query()
            ->with('law')
            ->orderByDesc('confidence_score')
            ->take(12)
            ->get();

        $evidences = QuantLawEvidence::query()
            ->with(['law', 'candidate'])
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        $drivers = UniversalDriverRanking::query()
            ->when($latestRun, fn ($query) => $query->where('quant_law_discovery_run_id', $latestRun->id))
            ->orderBy('rank')
            ->take(10)
            ->get();

        return view('quant-laws.index', compact(
            'latestRun',
            'metrics',
            'laws',
            'candidates',
            'conflicts',
            'graphEdges',
            'evidences',
            'drivers',
        ));
    }

    public function discover(UniversalQuantLawsService $laws): RedirectResponse
    {
        $run = $laws->discover();

        if (! $run) {
            return back()->with('error', 'Quant Laws migrationlari hali ishlamagan.');
        }

        return redirect()
            ->route('quant-laws.index')
            ->with('success', 'Quant Laws discovery yakunlandi.');
    }
}
