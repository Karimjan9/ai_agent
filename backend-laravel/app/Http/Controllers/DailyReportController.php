<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use Illuminate\Contracts\View\View;

class DailyReportController extends Controller
{
    public function index(): View
    {
        $reports = DailyReport::query()
            ->latest('report_date')
            ->paginate(20);

        return view('daily-reports.index', compact('reports'));
    }

    public function show(DailyReport $dailyReport): View
    {
        return view('daily-reports.show', compact('dailyReport'));
    }
}
