<?php

namespace App\Http\Controllers;

use App\Models\AgentBelief;
use App\Models\AgentHypothesis;
use App\Models\CounterfactualRun;
use App\Models\KnowledgeFact;
use App\Models\ScientistJournal;
use Illuminate\Contracts\View\View;

class AiScientistController extends Controller
{
    public function index(): View
    {
        $metrics = [
            'hypotheses' => AgentHypothesis::count(),
            'confirmed' => AgentHypothesis::query()->where('status', 'confirmed')->count(),
            'failed' => AgentHypothesis::query()->where('status', 'failed')->count(),
            'beliefs' => AgentBelief::count(),
            'journals' => ScientistJournal::count(),
            'knowledge_facts' => KnowledgeFact::count(),
            'counterfactuals' => CounterfactualRun::count(),
        ];

        $hypotheses = AgentHypothesis::query()
            ->latest()
            ->take(12)
            ->get();

        $beliefs = AgentBelief::query()
            ->orderByDesc('score')
            ->latest('last_evidence_at')
            ->take(12)
            ->get();

        $journals = ScientistJournal::query()
            ->with('trainingSession')
            ->latest()
            ->take(6)
            ->get();

        $knowledgeFacts = KnowledgeFact::query()
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(10)
            ->get();

        $counterfactualRuns = CounterfactualRun::query()
            ->with('agentHypothesis')
            ->latest()
            ->take(10)
            ->get();

        return view('ai-scientist.index', compact(
            'metrics',
            'hypotheses',
            'beliefs',
            'journals',
            'knowledgeFacts',
            'counterfactualRuns',
        ));
    }
}
