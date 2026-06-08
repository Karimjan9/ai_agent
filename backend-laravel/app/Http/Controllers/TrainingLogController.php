<?php

namespace App\Http\Controllers;

use App\Models\TrainingLog;
use Illuminate\Contracts\View\View;

class TrainingLogController extends Controller
{
    public function index(): View
    {
        $logs = TrainingLog::query()
            ->with('trainingSession')
            ->latest()
            ->paginate(30);

        return view('training-logs.index', compact('logs'));
    }

    public function show(TrainingLog $trainingLog): View
    {
        $trainingLog->load('trainingSession');

        return view('training-logs.show', compact('trainingLog'));
    }
}
