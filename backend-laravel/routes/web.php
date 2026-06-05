<?php

use App\Http\Controllers\BacktestController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModelVersionController;
use App\Http\Controllers\StrategyLabController;
use App\Http\Controllers\TrainingSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'dashboard'])->name('dashboard');
Route::get('/market-data', [DashboardController::class, 'marketData'])->name('market-data');
Route::get('/strategy-lab', [StrategyLabController::class, 'index'])->name('strategy-lab.index');
Route::post('/strategy-lab/run-all', [StrategyLabController::class, 'runAll'])->name('strategy-lab.run-all');
Route::get('/training-sessions', [TrainingSessionController::class, 'index'])->name('training-sessions.index');
Route::get('/training-sessions/{trainingSession}', [TrainingSessionController::class, 'show'])->name('training-sessions.show');
Route::get('/model-versions', [ModelVersionController::class, 'index'])->name('model-versions.index');
Route::get('/backtest-results', [DashboardController::class, 'backtestResults'])->name('backtest-results');
Route::get('/mistake-journal', [DashboardController::class, 'mistakeJournal'])->name('mistake-journal');
Route::get('/ai-daily-report', [DashboardController::class, 'dailyReport'])->name('ai-daily-report');

Route::get('/backtests', [BacktestController::class, 'index'])->name('backtests.index');
Route::post('/backtests/run', [BacktestController::class, 'run'])->name('backtests.run');

Route::get('/daily-reports', [DailyReportController::class, 'index'])->name('daily-reports.index');
Route::get('/daily-reports/{dailyReport}', [DailyReportController::class, 'show'])->name('daily-reports.show');
