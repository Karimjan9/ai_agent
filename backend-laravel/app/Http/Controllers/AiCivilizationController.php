<?php

namespace App\Http\Controllers;

use App\Models\CivilizationAgent;
use App\Models\CivilizationCreditEvent;
use App\Models\CivilizationGoal;
use App\Models\CivilizationMemory;
use App\Models\CouncilDecision;
use App\Models\CouncilVote;
use App\Models\InstitutionalKnowledge;
use App\Services\QuantCivilizationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AiCivilizationController extends Controller
{
    public function index(): View
    {
        $latestDecision = CouncilDecision::query()
            ->with(['votes.agent', 'proposer'])
            ->latest()
            ->first();

        $metrics = [
            'agents' => CivilizationAgent::count(),
            'credits' => round((float) CivilizationAgent::sum('credits_balance'), 2),
            'avg_reputation' => round((float) CivilizationAgent::avg('reputation_score'), 2),
            'decisions' => CouncilDecision::count(),
            'memories' => CivilizationMemory::count(),
            'knowledge' => InstitutionalKnowledge::count(),
        ];

        $agents = CivilizationAgent::query()
            ->orderByDesc('reputation_score')
            ->orderByDesc('credits_balance')
            ->take(16)
            ->get();

        $creditEvents = CivilizationCreditEvent::query()
            ->with('agent')
            ->latest()
            ->take(12)
            ->get();

        $decisions = CouncilDecision::query()
            ->withCount('votes')
            ->latest()
            ->take(10)
            ->get();

        $votes = CouncilVote::query()
            ->with(['agent', 'decision'])
            ->when($latestDecision, fn ($query) => $query->where('council_decision_id', $latestDecision->id))
            ->orderByDesc('weight')
            ->get();

        $goals = CivilizationGoal::query()
            ->with('owner')
            ->orderByDesc('priority_score')
            ->get();

        $memories = CivilizationMemory::query()
            ->orderByDesc('impact_score')
            ->latest()
            ->take(10)
            ->get();

        $knowledge = InstitutionalKnowledge::query()
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        return view('ai-civilization.index', compact(
            'latestDecision',
            'metrics',
            'agents',
            'creditEvents',
            'decisions',
            'votes',
            'goals',
            'memories',
            'knowledge',
        ));
    }

    public function sync(QuantCivilizationService $civilization): RedirectResponse
    {
        $decision = $civilization->synchronize();

        if (! $decision) {
            return back()->with('error', 'AI Civilization migrationlari hali ishlamagan.');
        }

        return redirect()
            ->route('ai-civilization.index')
            ->with('success', 'AI Civilization sync yakunlandi.');
    }
}
