<?php

namespace App\Http\Controllers;

use App\Models\CotFeatureSnapshot;
use App\Models\MarketDiscovery;
use App\Models\MarketGenome;
use App\Models\MarketMemory;
use App\Models\MarketSimilarityMatch;
use App\Models\MarketSpecies;
use App\Models\MarketStateSnapshot;
use App\Models\StrategySpeciesPerformance;
use Illuminate\Contracts\View\View;

class MarketIntelligenceController extends Controller
{
    public function index(): View
    {
        $latestSnapshot = MarketStateSnapshot::query()
            ->with(['marketSpecies', 'genome.similarityMatches.matchedGenome.snapshot.marketSpecies'])
            ->latest('time')
            ->first();

        $metrics = [
            'snapshots' => MarketStateSnapshot::count(),
            'species' => MarketSpecies::count(),
            'memories' => MarketMemory::count(),
            'discoveries' => MarketDiscovery::count(),
            'similarities' => MarketSimilarityMatch::count(),
            'strategy_species' => StrategySpeciesPerformance::count(),
        ];

        $species = MarketSpecies::query()
            ->orderByDesc('opportunity_score')
            ->take(16)
            ->get();

        $memories = MarketMemory::query()
            ->with('marketSpecies')
            ->latest()
            ->take(12)
            ->get();

        $discoveries = MarketDiscovery::query()
            ->with('marketSpecies')
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        $similarities = MarketSimilarityMatch::query()
            ->with(['currentGenome.snapshot.marketSpecies', 'matchedGenome.snapshot.marketSpecies'])
            ->orderByDesc('similarity_score')
            ->latest()
            ->take(12)
            ->get();

        $strategySpecies = StrategySpeciesPerformance::query()
            ->with('marketSpecies')
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        $genomes = MarketGenome::query()
            ->with('marketSpecies')
            ->latest('time')
            ->take(20)
            ->get();

        $cotSnapshot = CotFeatureSnapshot::query()
            ->with('report')
            ->where('symbol', 'XAUUSD')
            ->latest('report_date')
            ->first();
        $cotHistory = CotFeatureSnapshot::query()
            ->where('symbol', 'XAUUSD')
            ->latest('report_date')
            ->take(12)
            ->get();

        return view('market-intelligence.index', compact(
            'latestSnapshot',
            'metrics',
            'species',
            'memories',
            'discoveries',
            'similarities',
            'strategySpecies',
            'genomes',
            'cotSnapshot',
            'cotHistory',
        ));
    }
}
