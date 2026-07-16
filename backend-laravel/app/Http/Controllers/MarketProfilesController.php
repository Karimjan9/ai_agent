<?php

namespace App\Http\Controllers;

use App\Models\MarketSymbol;
use App\Models\SymbolProfile;
use App\Services\InstrumentIntelligenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MarketProfilesController extends Controller
{
    public function index(): View
    {
        $profiles = SymbolProfile::query()->orderBy('symbol')->orderBy('timeframe')->get();
        $symbols = MarketSymbol::query()->where('is_active', true)->orderBy('priority')->get();
        $metrics = [
            'symbols' => $symbols->count(),
            'profiles' => $profiles->count(),
            'xauusd_profiles' => $profiles->where('symbol', 'XAUUSD')->count(),
            'eurusd_profiles' => $profiles->where('symbol', 'EURUSD')->count(),
            'avg_confidence' => round((float) $profiles->avg('confidence_score'), 2),
        ];

        return view('market-profiles.index', compact('profiles', 'symbols', 'metrics'));
    }

    public function refresh(InstrumentIntelligenceService $profiles): RedirectResponse
    {
        $profiles->refresh(['XAUUSD', 'EURUSD'], ['M15', 'H1']);

        return redirect()->route('market-profiles.index')->with('success', 'Market profiles refreshed.');
    }
}
