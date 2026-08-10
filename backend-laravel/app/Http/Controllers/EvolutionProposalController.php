<?php

namespace App\Http\Controllers;

use App\Models\EvolutionProposal;
use Illuminate\Contracts\View\View;

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

    public function approve(EvolutionProposal $evolutionProposal)
    {
        abort(410, 'Legacy EvolutionProposal oqimi o\'chirilgan; canonical Lab evidence ishlatiladi.');
    }

    public function apply(EvolutionProposal $evolutionProposal)
    {
        abort(410, 'Legacy EvolutionProposal apply o\'chirilgan; canonical Lab generation shu evidence asosida ishlaydi.');
    }

    public function reject(EvolutionProposal $evolutionProposal)
    {
        abort(410, 'Legacy EvolutionProposal mutation o\'chirilgan; canonical Lab evidence immutable.');
    }

}
