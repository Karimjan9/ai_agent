<?php

namespace App\Http\Controllers;

use App\Models\ExtinctionEvent;
use App\Models\FitnessEvaluation;
use App\Models\GenomeCrossover;
use App\Models\GenomeDiscovery;
use App\Models\GenomeMutation;
use App\Models\StrategyGenome;
use App\Models\AiLaboratory;
use App\Models\ModelMarketPerformance;
use App\Models\MutationMemory;
use App\Services\LabPopulationService;
use Illuminate\Contracts\View\View;

class EvolutionLabController extends Controller
{
    public function laboratory(string $symbol = 'XAUUSD', LabPopulationService $populations): View
    {
        $populations->ensureLaboratories();
        $lab = AiLaboratory::where('symbol', strtoupper($symbol))->firstOrFail();
        $generation = $lab->generations()->with(['agents.modelVersion', 'agents.parentA', 'agents.parentB'])->latest('generation')->first();
        $champions = ModelMarketPerformance::with('modelVersion')->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->where('status', 'champion')->orderBy('strategy_family')->get();
        $challengers = ModelMarketPerformance::with('modelVersion')->where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->whereIn('status', ['challenger', 'forward_validated', 'paper'])->orderByDesc('forward_score')->get();
        $memories = MutationMemory::where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->latest()->take(20)->get();
        $generationPerformance = $lab->generations()->with('agents')->orderBy('generation')->get()->map(fn ($item) => [
            'generation' => $item->generation,
            'forward' => round((float) $item->agents->whereNotNull('forward_score')->avg('forward_score'), 2),
            'best' => round((float) $item->agents->max('forward_score'), 2),
        ]);
        $labs = AiLaboratory::orderBy('symbol')->get();
        return view('ai-laboratory.show', compact('lab', 'labs', 'generation', 'champions', 'challengers', 'memories', 'generationPerformance'));
    }

    public function index(): View
    {
        $metrics = [
            'genomes' => StrategyGenome::count(),
            'alive' => StrategyGenome::query()->where('status', 'alive')->count(),
            'archived' => StrategyGenome::query()->where('status', 'archived')->count(),
            'mutations' => GenomeMutation::count(),
            'crossovers' => GenomeCrossover::count(),
            'discoveries' => GenomeDiscovery::count(),
        ];

        $genomes = StrategyGenome::query()
            ->with(['parentLineages.parentGenome', 'childLineages.childGenome'])
            ->orderBy('family')
            ->orderBy('generation')
            ->latest()
            ->take(30)
            ->get();

        $mutations = GenomeMutation::query()
            ->with(['parentGenome', 'childGenome'])
            ->latest()
            ->take(12)
            ->get();

        $crossovers = GenomeCrossover::query()
            ->with(['parentA', 'parentB', 'childGenome'])
            ->latest()
            ->take(12)
            ->get();

        $extinctions = ExtinctionEvent::query()
            ->with('strategyGenome')
            ->latest()
            ->take(12)
            ->get();

        $discoveries = GenomeDiscovery::query()
            ->orderByDesc('confidence_score')
            ->latest()
            ->take(12)
            ->get();

        $fitnessEvaluations = FitnessEvaluation::query()
            ->with('strategyGenome')
            ->orderByDesc('fitness_score')
            ->take(12)
            ->get();

        $geneHeatmap = StrategyGenome::query()
            ->where('fitness_score', '>', 0)
            ->get()
            ->flatMap(fn (StrategyGenome $genome): array => collect($genome->genes ?? [])
                ->filter(fn ($value): bool => is_numeric($value))
                ->map(fn ($value, string $key): array => [
                    'gene' => $key,
                    'value' => (float) $value,
                    'fitness' => (float) $genome->fitness_score,
                ])
                ->values()
                ->all())
            ->groupBy('gene')
            ->map(fn ($items) => [
                'count' => $items->count(),
                'min' => round((float) $items->min('value'), 4),
                'max' => round((float) $items->max('value'), 4),
                'avg_fitness' => round((float) $items->avg('fitness'), 2),
            ]);

        return view('evolution-lab.index', compact(
            'metrics',
            'genomes',
            'mutations',
            'crossovers',
            'extinctions',
            'discoveries',
            'fitnessEvaluations',
            'geneHeatmap',
        ));
    }
}
