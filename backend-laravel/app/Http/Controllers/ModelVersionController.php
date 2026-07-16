<?php

namespace App\Http\Controllers;

use App\Models\ModelVersion;
use Illuminate\Contracts\View\View;

class ModelVersionController extends Controller
{
    public function index(): View
    {
        $statusCounts = ModelVersion::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $versions = ModelVersion::query()
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'testing' THEN 2 WHEN 'overfit' THEN 3 WHEN 'rejected' THEN 4 WHEN 'archived' THEN 5 ELSE 6 END")
            ->orderByDesc('best_score')
            ->paginate(20);

        return view('model-versions.index', compact('versions', 'statusCounts'));
    }
}
