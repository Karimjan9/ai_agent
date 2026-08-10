<?php

namespace App\Http\Controllers;

use App\Services\LabPopulationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Compatibility facade for the retired StrategyScore/TrainingSession UI.
 *
 * The public URL remains available for bookmarks, but every read is routed to
 * the canonical AI Laboratory and every run is dispatched as LabAgent
 * evidence.  No legacy projection is created here.
 */
class StrategyLabController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('ai-laboratory.show', ['symbol' => 'XAUUSD']);
    }

    public function dnaLaboratory(): RedirectResponse
    {
        return redirect()->route('ai-laboratory.show', ['symbol' => 'XAUUSD']);
    }

    public function runAll(Request $request, LabPopulationService $populations): RedirectResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'regex:/^(XAU|EUR|GBP)(?:[_\/]?USD)$/i'],
            'timeframe' => ['required', 'string', 'in:M15,H1'],
            'initial_balance' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'risk_per_trade' => ['nullable', 'numeric', 'gt:0', 'max:5'],
        ]);
        $symbol = strtoupper(str_replace(['_', '/'], '', (string) $validated['symbol']));
        $timeframe = strtoupper((string) $validated['timeframe']);

        try {
            $populations->ensureLaboratories();
            $dispatchCode = Artisan::call('trading:dispatch-lab', [
                $symbol,
                '--timeframe' => $timeframe,
                '--force-generation' => true,
            ]);
            $output = Artisan::output();

            if ($dispatchCode !== 0) {
                return back()->with('error', trim($output) ?: 'Canonical Lab dispatch failed.');
            }

            return redirect()
                ->route('ai-laboratory.show', ['symbol' => $symbol])
                ->with('success', "{$symbol} {$timeframe}: canonical Lab replay queue'ga yuborildi.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Canonical Lab dispatch failed: '.$exception->getMessage());
        }
    }
}
