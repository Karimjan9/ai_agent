<?php

namespace App\Http\Controllers;

use App\Models\AgentMemory;
use App\Models\AgentPsychologySnapshot;
use App\Models\AgentReputation;
use App\Models\AgentSelfReflection;
use App\Models\EvolutionTrigger;
use App\Models\InternalDebate;
use Illuminate\Contracts\View\View;

class AgentMindController extends Controller
{
    public function index(): View
    {
        $snapshots = AgentPsychologySnapshot::query()
            ->latest()
            ->take(20)
            ->get();

        $latestByStrategy = AgentPsychologySnapshot::query()
            ->latest()
            ->get()
            ->unique('strategy')
            ->values();

        $metrics = [
            'agents' => $latestByStrategy->count(),
            'stressed' => $latestByStrategy->whereIn('state', ['stressed', 'adaptation_required'])->count(),
            'avg_confidence' => round((float) $latestByStrategy->avg('confidence'), 2),
            'avg_stress' => round((float) $latestByStrategy->avg('stress'), 2),
            'avg_trust' => round((float) $latestByStrategy->avg('trust'), 2),
            'triggers' => EvolutionTrigger::query()->where('status', 'pending')->count(),
        ];

        $reputations = AgentReputation::query()
            ->orderByDesc('reputation_score')
            ->take(12)
            ->get();

        $reflections = AgentSelfReflection::query()
            ->latest()
            ->take(8)
            ->get();

        $memories = AgentMemory::query()
            ->latest()
            ->take(10)
            ->get();

        $debates = InternalDebate::query()
            ->with('arguments')
            ->latest()
            ->take(5)
            ->get();

        $triggers = EvolutionTrigger::query()
            ->latest()
            ->take(10)
            ->get();

        return view('agent-mind.index', compact(
            'metrics',
            'latestByStrategy',
            'snapshots',
            'reputations',
            'reflections',
            'memories',
            'debates',
            'triggers',
        ));
    }
}
