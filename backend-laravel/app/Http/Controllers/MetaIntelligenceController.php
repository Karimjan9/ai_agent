<?php

namespace App\Http\Controllers;

use App\Models\BeliefDecayEvent;
use App\Models\BlindSpot;
use App\Models\KnowledgeAudit;
use App\Models\KnowledgeContradiction;
use App\Models\KnowledgeHealthScore;
use App\Models\MetaAuditRun;
use App\Models\SelfCritique;
use App\Models\UnknownZone;
use App\Services\MetaIntelligenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MetaIntelligenceController extends Controller
{
    public function index(): View
    {
        $latestRun = MetaAuditRun::query()
            ->with(['healthScore', 'selfCritiques'])
            ->latest()
            ->first();

        $metrics = [
            'runs' => MetaAuditRun::count(),
            'health' => $latestRun?->knowledge_health_score ?? 0,
            'audits' => KnowledgeAudit::count(),
            'decays' => BeliefDecayEvent::count(),
            'contradictions' => KnowledgeContradiction::where('status', 'open')->count(),
            'unknown_zones' => UnknownZone::where('status', 'open')->count(),
            'blind_spots' => BlindSpot::where('status', 'open')->count(),
        ];

        $knowledgeAudits = KnowledgeAudit::query()
            ->with('knowledgeClaim')
            ->when($latestRun, fn ($query) => $query->where('meta_audit_run_id', $latestRun->id))
            ->orderBy('audited_confidence')
            ->take(12)
            ->get();

        $beliefDecays = BeliefDecayEvent::query()
            ->when($latestRun, fn ($query) => $query->where('meta_audit_run_id', $latestRun->id))
            ->orderByDesc('decay_amount')
            ->take(12)
            ->get();

        $contradictions = KnowledgeContradiction::query()
            ->with(['claimA', 'claimB'])
            ->when($latestRun, fn ($query) => $query->where('meta_audit_run_id', $latestRun->id))
            ->orderByDesc('severity_score')
            ->take(12)
            ->get();

        $unknownZones = UnknownZone::query()
            ->when($latestRun, fn ($query) => $query->where('meta_audit_run_id', $latestRun->id))
            ->orderByDesc('uncertainty_score')
            ->take(12)
            ->get();

        $blindSpots = BlindSpot::query()
            ->when($latestRun, fn ($query) => $query->where('meta_audit_run_id', $latestRun->id))
            ->orderByDesc('priority_score')
            ->take(12)
            ->get();

        $healthScores = KnowledgeHealthScore::query()
            ->latest()
            ->take(8)
            ->get();

        $selfCritiques = SelfCritique::query()
            ->when($latestRun, fn ($query) => $query->where('meta_audit_run_id', $latestRun->id))
            ->orderByDesc('severity_score')
            ->take(8)
            ->get();

        return view('meta-intelligence.index', compact(
            'latestRun',
            'metrics',
            'knowledgeAudits',
            'beliefDecays',
            'contradictions',
            'unknownZones',
            'blindSpots',
            'healthScores',
            'selfCritiques',
        ));
    }

    public function audit(MetaIntelligenceService $metaIntelligence): RedirectResponse
    {
        $run = $metaIntelligence->runAudit();

        if (! $run) {
            return back()->with('error', 'Meta Intelligence migrationlari hali ishlamagan.');
        }

        return redirect()
            ->route('meta-intelligence.index')
            ->with('success', 'Meta Intelligence audit yakunlandi.');
    }
}
