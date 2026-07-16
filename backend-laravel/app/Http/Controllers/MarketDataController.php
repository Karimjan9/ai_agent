<?php

namespace App\Http\Controllers;

use App\Models\Candle;
use App\Models\MarketSymbol;
use App\Models\MarketDataSyncState;
use App\Models\Symbol;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class MarketDataController extends Controller
{
    public function index(): View
    {
        $provider = (string) config('services.market_data.provider', 'csv');
        $symbols = MarketSymbol::query()
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get()
            ->map(function (MarketSymbol $marketSymbol) use ($provider): array {
                $symbol = Symbol::query()
                    ->where('code', $marketSymbol->symbol)
                    ->first();

                $query = Candle::query()
                    ->when($symbol, fn ($query) => $query->where('symbol_id', $symbol->id));

                return [
                    'symbol' => $marketSymbol,
                    'count' => $symbol ? (clone $query)->count() : 0,
                    'last_candle' => $symbol ? (clone $query)->latest('time')->first() : null,
                    'sync_state' => MarketDataSyncState::query()
                        ->where('provider', $provider)
                        ->where('symbol', $marketSymbol->symbol)
                        ->where('timeframe', 'H1')
                        ->first(),
                ];
            });

        return view('market-data.index', compact('symbols', 'provider'));
    }

    public function update(Request $request): RedirectResponse
    {
        $code = Artisan::call('market-data:update', [
            '--symbol' => $request->input('symbol', 'XAUUSD'),
            '--timeframe' => $request->input('timeframe', 'H1'),
            '--limit' => (int) $request->input('limit', 1000),
        ]);

        if ($code !== 0) {
            return back()->with('error', trim(Artisan::output()) ?: 'Market data update xatolik berdi.');
        }

        return back()->with('success', trim(Artisan::output()) ?: 'Market data update qilindi.');
    }
}
