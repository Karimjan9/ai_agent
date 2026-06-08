<?php

namespace App\Http\Controllers;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class EvolutionProposalController extends Controller
{
    public function index(): View
    {
        $proposals = EvolutionProposal::query()
            ->with(['trainingSession', 'modelVersion'])
            ->latest()
            ->paginate(20);

        return view('evolution-proposals.index', compact('proposals'));
    }

    public function show(EvolutionProposal $evolutionProposal): View
    {
        $evolutionProposal->load(['trainingSession', 'modelVersion']);

        return view('evolution-proposals.show', compact('evolutionProposal'));
    }

    public function approve(EvolutionProposal $evolutionProposal): RedirectResponse
    {
        if ($evolutionProposal->status !== 'pending') {
            return back()->with('error', 'Bu proposal pending holatda emas.');
        }

        $evolutionProposal->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Evolution proposal approved qilindi.');
    }

    public function apply(EvolutionProposal $evolutionProposal): RedirectResponse
    {
        if (! in_array($evolutionProposal->status, ['pending', 'approved'], true)) {
            return back()->with('error', 'Bu proposalni apply qilib bolmaydi.');
        }

        $strategyName = $this->nextStrategyName(
            $evolutionProposal->strategy,
            $evolutionProposal->proposed_version,
        );

        ModelVersion::create([
            'name' => $strategyName,
            'strategy' => $strategyName,
            'version' => $evolutionProposal->proposed_version,
            'generation' => $this->nextGeneration($evolutionProposal),
            'status' => 'testing',
            'best_score' => 0,
            'best_winrate' => 0,
            'best_profit' => 0,
            'best_drawdown' => 0,
            'description' => $evolutionProposal->proposal,
            'change_log' => $evolutionProposal->reason,
            'parameters' => $evolutionProposal->new_parameters,
            'metadata' => [
                'source_proposal_id' => $evolutionProposal->id,
                'parent_strategy' => $evolutionProposal->strategy,
                'parent_version' => $evolutionProposal->current_version,
            ],
        ]);

        $evolutionProposal->update([
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        return redirect()
            ->route('model-versions.index')
            ->with('success', 'Yangi model version yaratildi.');
    }

    public function reject(EvolutionProposal $evolutionProposal): RedirectResponse
    {
        if ($evolutionProposal->status === 'applied') {
            return back()->with('error', 'Applied qilingan proposalni reject qilib bolmaydi.');
        }

        $evolutionProposal->update([
            'status' => 'rejected',
        ]);

        return back()->with('success', 'Evolution proposal rejected qilindi.');
    }

    private function nextStrategyName(string $strategy, string $proposedVersion): string
    {
        $next = preg_replace('/_v\d+$/', '_'.$proposedVersion, $strategy);

        return $next ?: $strategy.'_'.$proposedVersion;
    }

    private function nextGeneration(EvolutionProposal $proposal): int
    {
        $model = $proposal->modelVersion;

        if (! $model) {
            return 1;
        }

        return (int) $model->generation + 1;
    }
}
