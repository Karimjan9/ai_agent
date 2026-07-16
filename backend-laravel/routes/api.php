<?php

use App\Http\Controllers\Api\BacktestController;
use Illuminate\Support\Facades\Route;

Route::post('/backtest/run', [BacktestController::class, 'run'])->middleware(['internal.token', 'throttle:60,1']);
