<?php

use App\Http\Controllers\Api\BacktestController;
use Illuminate\Support\Facades\Route;

Route::get('/backtest/strategies', [BacktestController::class, 'strategies'])->middleware(['internal.token', 'throttle:30,1']);
Route::post('/backtest/run', [BacktestController::class, 'run'])->middleware(['internal.token', 'throttle:10,1']);
Route::get('/backtest/runs/{backtestRun}', [BacktestController::class, 'status'])
    ->middleware(['internal.token', 'throttle:60,1'])
    ->name('api.backtest.status');
