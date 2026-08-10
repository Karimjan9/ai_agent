<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Services\CanonicalLabResultService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

class DailyReportController extends Controller
{
    public function index(): View
    {
        $query = DailyReport::query();
        if (Schema::hasColumn('daily_reports', 'source')) {
            $query->where('source', CanonicalLabResultService::SOURCE);
        }
        $reports = $query->latest('report_date')->paginate(20);

        return view('daily-reports.index', compact('reports'));
    }

    public function show(DailyReport $dailyReport): View
    {
        if (Schema::hasColumn('daily_reports', 'source')
            && $dailyReport->source !== CanonicalLabResultService::SOURCE) {
            abort(404);
        }

        return view('daily-reports.show', compact('dailyReport'));
    }
}
