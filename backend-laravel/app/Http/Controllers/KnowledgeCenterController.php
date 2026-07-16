<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeClaim;
use App\Models\KnowledgeGraphEdge;
use App\Models\KnowledgeGraphNode;
use App\Models\KnowledgeMiningRun;
use App\Models\KnowledgeQuery;
use App\Services\UniversalKnowledgeGraphService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class KnowledgeCenterController extends Controller
{
    public function index(Request $request, UniversalKnowledgeGraphService $knowledgeGraph): View
    {
        $answer = null;
        $question = trim((string) $request->query('q', ''));

        if ($question !== '') {
            $answer = $knowledgeGraph->answer($question);
        }

        $metrics = [
            'nodes' => KnowledgeGraphNode::count(),
            'edges' => KnowledgeGraphEdge::count(),
            'claims' => KnowledgeClaim::count(),
            'queries' => KnowledgeQuery::count(),
            'runs' => KnowledgeMiningRun::count(),
        ];

        $nodes = KnowledgeGraphNode::query()
            ->withCount(['outgoingEdges', 'incomingEdges'])
            ->orderByDesc('confidence_score')
            ->latest('last_seen_at')
            ->take(16)
            ->get();

        $edges = KnowledgeGraphEdge::query()
            ->with(['sourceNode', 'targetNode'])
            ->orderByDesc('confidence_score')
            ->latest('last_seen_at')
            ->take(16)
            ->get();

        $claims = KnowledgeClaim::query()
            ->with('primaryNode')
            ->orderByDesc('confidence_score')
            ->latest('last_seen_at')
            ->take(14)
            ->get();

        $failureClaims = KnowledgeClaim::query()
            ->where('claim_type', 'failure_cause')
            ->orderByDesc('confidence_score')
            ->take(8)
            ->get();

        $patternClaims = KnowledgeClaim::query()
            ->whereIn('claim_type', ['strategy_species_performance', 'genome_pattern', 'market_pattern', 'agent_belief'])
            ->orderByDesc('confidence_score')
            ->take(8)
            ->get();

        $queries = KnowledgeQuery::query()
            ->latest()
            ->take(8)
            ->get();

        $runs = KnowledgeMiningRun::query()
            ->latest()
            ->take(8)
            ->get();

        return view('knowledge-center.index', compact(
            'answer',
            'question',
            'metrics',
            'nodes',
            'edges',
            'claims',
            'failureClaims',
            'patternClaims',
            'queries',
            'runs',
        ));
    }
}
