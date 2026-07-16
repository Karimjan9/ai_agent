<?php

namespace App\Http\Controllers;

use App\Models\CertifiedKnowledgeItem;
use App\Models\KnowledgeCemeteryEntry;
use App\Models\RealityExperiment;
use App\Models\RealityScore;
use App\Models\RealityValidationEvent;
use App\Models\RealityVerificationRun;
use App\Models\SkepticReport;
use App\Services\RealityVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RealityCenterController extends Controller
{
    public function index(): View
    {
        $latestRun = RealityVerificationRun::query()->latest()->first();
        $metrics = [
            'runs' => RealityVerificationRun::count(),
            'scores' => RealityScore::count(),
            'certified' => CertifiedKnowledgeItem::count(),
            'failed' => RealityScore::where('validation_status', 'reality_failed')->count(),
            'cemetery' => KnowledgeCemeteryEntry::count(),
            'experiments' => RealityExperiment::count(),
            'skeptic_reports' => SkepticReport::count(),
            'avg_reality_score' => round((float) RealityScore::avg('reality_score'), 2),
        ];

        $scores = RealityScore::query()->orderByDesc('reality_score')->take(12)->get();
        $certified = CertifiedKnowledgeItem::query()->orderByDesc('reality_score')->take(10)->get();
        $cemetery = KnowledgeCemeteryEntry::query()->latest('failed_at')->take(10)->get();
        $experiments = RealityExperiment::query()->orderByDesc('observed_samples')->take(10)->get();
        $skepticReports = SkepticReport::query()->orderByDesc('false_discovery_risk')->take(10)->get();
        $events = RealityValidationEvent::query()->with('score')->latest()->take(10)->get();

        return view('reality-center.index', compact(
            'latestRun',
            'metrics',
            'scores',
            'certified',
            'cemetery',
            'experiments',
            'skepticReports',
            'events',
        ));
    }

    public function verify(RealityVerificationService $reality): RedirectResponse
    {
        $run = $reality->verify();

        if (! $run) {
            return back()->with('error', 'Reality Verification migrationlari hali ishlamagan.');
        }

        return redirect()->route('reality-center.index')->with('success', 'Reality verification yakunlandi.');
    }
}
