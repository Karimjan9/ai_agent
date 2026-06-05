<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use Illuminate\Contracts\View\View;

class TrainingSessionController extends Controller
{
    public function index(): View
    {
        $sessions = TrainingSession::query()
            ->latest()
            ->paginate(20);

        return view('training-sessions.index', compact('sessions'));
    }

    public function show(TrainingSession $trainingSession): View
    {
        $trainingSession->load(['strategyScores' => fn ($query) => $query->orderByDesc('score')]);

        return view('training-sessions.show', compact('trainingSession'));
    }
}
