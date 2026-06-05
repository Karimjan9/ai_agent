<?php

namespace App\Http\Controllers;

use App\Models\ModelVersion;
use Illuminate\Contracts\View\View;

class ModelVersionController extends Controller
{
    public function index(): View
    {
        $versions = ModelVersion::query()
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'testing' THEN 2 WHEN 'rejected' THEN 3 WHEN 'archived' THEN 4 ELSE 5 END")
            ->orderByDesc('best_score')
            ->paginate(20);

        return view('model-versions.index', compact('versions'));
    }
}
