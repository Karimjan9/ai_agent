<?php

namespace App\Http\Controllers;

use App\Models\AgentMemory;
use App\Models\AgentMemoryMatch;
use App\Models\ModelMarketPerformance;
use App\Models\MarketDataSyncState;
use App\Models\PaperFill;
use App\Models\PaperOrder;
use App\Models\ServiceHealthCheck;
use App\Models\SignalMarketSnapshot;
use App\Models\SystemEvent;
use App\Services\PhaseTwoFoundationService;
use App\Services\MarketData\MarketReadinessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AgentHealthController extends Controller
{
    public function index(MarketReadinessService $marketReadiness): View
    {
        // Historical MT5 checks are retained for audit, but they must not
        // dilute the active provider's operational truth panel.
        $checks = ServiceHealthCheck::query()->where('service_key', 'not like', 'market_feed:mt5:%')->orderBy('service_label')->get();
        $feedStates = MarketDataSyncState::query()
            ->where('provider', (string) config('services.market_data.provider', 'dukascopy'))
            ->orderBy('symbol')->orderBy('timeframe')->get();
        $metrics = [
            'services' => $checks->count(),
            'critical' => $checks->where('status', 'critical')->count(),
            'warnings' => $checks->where('status', 'warning')->count(),
            'events' => SystemEvent::count(),
            'signal_snapshots' => SignalMarketSnapshot::count(),
            'memories' => AgentMemory::count(),
            'memory_matches' => AgentMemoryMatch::count(),
            // Use the same active-provider scope as the visible checks.
            // Retained MT5 audit rows must not lower the live dashboard's
            // aggregate while Dukascopy is the configured feed.
            'avg_health' => round((float) $checks->avg('health_score'), 2),
            'champions' => ModelMarketPerformance::where('status', 'champion')->count(),
            'forward_validated' => ModelMarketPerformance::where('status', 'forward_validated')->count(),
            'paper_running' => ModelMarketPerformance::where('paper_status', 'running')->count(),
            'paper_closed_orders' => PaperOrder::where('status', 'closed')->count(),
            'paper_pnl' => round((float) PaperOrder::where('status', 'closed')->sum('profit_percent'), 3),
            'paper_fill_cost' => round((float) PaperFill::avg('cost_percent'), 4),
            'promotion_ready' => $marketReadiness->promotionReady(),
            'promotion_blocked_markets' => $marketReadiness->blockedMarkets(),
        ];

        $events = SystemEvent::query()->latest('occurred_at')->take(12)->get();
        $signals = SignalMarketSnapshot::query()->latest()->take(10)->get();
        $memoryMatches = AgentMemoryMatch::query()->with('memory')->orderByDesc('similarity_score')->take(10)->get();
        $champions = ModelMarketPerformance::query()->with('modelVersion')
            ->whereIn('status', ['champion', 'forward_validated', 'paper'])
            ->orderByDesc('forward_score')->take(10)->get();

        return view('agent-health.index', compact('checks', 'feedStates', 'metrics', 'events', 'signals', 'memoryMatches', 'champions'));
    }

    public function check(PhaseTwoFoundationService $foundation): RedirectResponse
    {
        $foundation->runHealthCheck();

        return redirect()->route('agent-health.index')->with('success', 'System health check yakunlandi.');
    }
}
