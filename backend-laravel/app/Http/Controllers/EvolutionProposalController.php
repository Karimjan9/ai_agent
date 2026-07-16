<?php

namespace App\Http\Controllers;

use App\Models\EvolutionProposal;
use App\Services\EvolutionProposalApplicationService;
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

    public function apply(EvolutionProposal $evolutionProposal, EvolutionProposalApplicationService $applicationService): RedirectResponse
    {
        $modelVersion = $applicationService->apply($evolutionProposal);

        if (! $modelVersion) {
            return back()->with('error', 'Bu proposalni apply qilib bolmaydi.');
        }

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
            'open_status' => null,
        ]);

        return back()->with('success', 'Evolution proposal rejected qilindi.');
    }

}
