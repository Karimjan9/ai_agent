<?php

use App\Http\Controllers\BacktestController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AiScientistController;
use App\Http\Controllers\AiCivilizationController;
use App\Http\Controllers\AgentHealthController;
use App\Http\Controllers\AgentMindController;
use App\Http\Controllers\CausalIntelligenceController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvolutionProposalController;
use App\Http\Controllers\EvolutionLabController;
use App\Http\Controllers\FutureIntelligenceController;
use App\Http\Controllers\KnowledgeCenterController;
use App\Http\Controllers\MarketDataController;
use App\Http\Controllers\MarketIntelligenceController;
use App\Http\Controllers\MarketProfilesController;
use App\Http\Controllers\MetaIntelligenceController;
use App\Http\Controllers\ModelVersionController;
use App\Http\Controllers\QuantLawsController;
use App\Http\Controllers\RealityCenterController;
use App\Http\Controllers\StrategyLabController;
use App\Http\Controllers\TheoryLabController;
use App\Http\Controllers\TrainingLogController;
use App\Http\Controllers\TrainingSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('web.auth')->group(function (): void {
Route::get('/', [DashboardController::class, 'dashboard'])->name('dashboard');
Route::get('/market-data', [MarketDataController::class, 'index'])->name('market-data');
Route::post('/market-data/update', [MarketDataController::class, 'update'])->middleware('role:operator,admin')->name('market-data.update');
Route::get('/market-profiles', [MarketProfilesController::class, 'index'])->name('market-profiles.index');
Route::post('/market-profiles/refresh', [MarketProfilesController::class, 'refresh'])->middleware('role:operator,admin')->name('market-profiles.refresh');
Route::get('/market-intelligence', [MarketIntelligenceController::class, 'index'])->name('market-intelligence.index');
Route::get('/knowledge-center', [KnowledgeCenterController::class, 'index'])->name('knowledge-center.index');
Route::get('/future-intelligence', [FutureIntelligenceController::class, 'index'])->name('future-intelligence.index');
Route::post('/future-intelligence/simulate', [FutureIntelligenceController::class, 'simulate'])->middleware(['role:admin', 'secondary.enabled'])->name('future-intelligence.simulate');
Route::get('/meta-intelligence', [MetaIntelligenceController::class, 'index'])->name('meta-intelligence.index');
Route::post('/meta-intelligence/audit', [MetaIntelligenceController::class, 'audit'])->middleware(['role:admin', 'secondary.enabled'])->name('meta-intelligence.audit');
Route::get('/ai-civilization', [AiCivilizationController::class, 'index'])->name('ai-civilization.index');
Route::post('/ai-civilization/sync', [AiCivilizationController::class, 'sync'])->middleware(['role:admin', 'secondary.enabled'])->name('ai-civilization.sync');
Route::get('/quant-laws', [QuantLawsController::class, 'index'])->name('quant-laws.index');
Route::post('/quant-laws/discover', [QuantLawsController::class, 'discover'])->middleware(['role:admin', 'secondary.enabled'])->name('quant-laws.discover');
Route::get('/causal-intelligence', [CausalIntelligenceController::class, 'index'])->name('causal-intelligence.index');
Route::post('/causal-intelligence/discover', [CausalIntelligenceController::class, 'discover'])->middleware(['role:admin', 'secondary.enabled'])->name('causal-intelligence.discover');
Route::get('/theory-lab', [TheoryLabController::class, 'index'])->name('theory-lab.index');
Route::post('/theory-lab/generate', [TheoryLabController::class, 'generate'])->middleware(['role:admin', 'secondary.enabled'])->name('theory-lab.generate');
Route::get('/reality-center', [RealityCenterController::class, 'index'])->name('reality-center.index');
Route::post('/reality-center/verify', [RealityCenterController::class, 'verify'])->middleware(['role:admin', 'secondary.enabled'])->name('reality-center.verify');
Route::get('/agent-health', [AgentHealthController::class, 'index'])->name('agent-health.index');
Route::post('/agent-health/check', [AgentHealthController::class, 'check'])->middleware('role:operator,admin')->name('agent-health.check');
Route::get('/ai-scientist', [AiScientistController::class, 'index'])->name('ai-scientist.index');
Route::get('/agent-mind', [AgentMindController::class, 'index'])->name('agent-mind.index');
Route::get('/evolution-lab', [EvolutionLabController::class, 'index'])->name('evolution-lab.index');
Route::get('/ai-laboratory/{symbol?}', [EvolutionLabController::class, 'laboratory'])->name('ai-laboratory.show');
Route::get('/strategy-lab', [StrategyLabController::class, 'index'])->name('strategy-lab.index');
Route::get('/strategy-lab/dna-laboratory', [StrategyLabController::class, 'dnaLaboratory'])->name('strategy-lab.dna-laboratory');
Route::post('/strategy-lab/run-all', [StrategyLabController::class, 'runAll'])->middleware('role:operator,admin')->name('strategy-lab.run-all');
Route::get('/training-sessions', [TrainingSessionController::class, 'index'])->name('training-sessions.index');
Route::get('/training-sessions/{trainingSession}', [TrainingSessionController::class, 'show'])->name('training-sessions.show');
Route::get('/training-logs', [TrainingLogController::class, 'index'])->name('training-logs.index');
Route::get('/training-logs/{trainingLog}', [TrainingLogController::class, 'show'])->name('training-logs.show');
Route::get('/model-versions', [ModelVersionController::class, 'index'])->name('model-versions.index');
Route::get('/evolution-proposals', [EvolutionProposalController::class, 'index'])->name('evolution-proposals.index');
Route::get('/evolution-proposals/{evolutionProposal}', [EvolutionProposalController::class, 'show'])->name('evolution-proposals.show');
Route::post('/evolution-proposals/{evolutionProposal}/approve', [EvolutionProposalController::class, 'approve'])->middleware('role:admin')->name('evolution-proposals.approve');
Route::post('/evolution-proposals/{evolutionProposal}/apply', [EvolutionProposalController::class, 'apply'])->middleware('role:admin')->name('evolution-proposals.apply');
Route::post('/evolution-proposals/{evolutionProposal}/reject', [EvolutionProposalController::class, 'reject'])->middleware('role:admin')->name('evolution-proposals.reject');
Route::get('/backtest-results', [DashboardController::class, 'backtestResults'])->name('backtest-results');
Route::get('/mistake-journal', [DashboardController::class, 'mistakeJournal'])->name('mistake-journal');
Route::get('/ai-daily-report', [DashboardController::class, 'dailyReport'])->name('ai-daily-report');

Route::get('/backtests', [BacktestController::class, 'index'])->name('backtests.index');
Route::post('/backtests/run', [BacktestController::class, 'run'])->middleware('role:operator,admin')->name('backtests.run');

Route::get('/daily-reports', [DailyReportController::class, 'index'])->name('daily-reports.index');
Route::get('/daily-reports/{dailyReport}', [DailyReportController::class, 'show'])->name('daily-reports.show');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
