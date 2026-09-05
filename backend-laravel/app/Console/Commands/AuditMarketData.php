<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\MarketDataAuditService;
use Illuminate\Console\Command;

class AuditMarketData extends Command
{
    protected $signature = 'market-data:audit {symbol?} {--timeframe=H1}';
    protected $description = 'Audit market candle continuity, provider coverage and cross-provider discrepancy';

    public function handle(MarketDataAuditService $audit): int
    {
        $symbols = $this->argument('symbol') ? MarketSymbol::where('symbol', strtoupper($this->argument('symbol')))->get() : MarketSymbol::where('is_active', true)->get();
        foreach ($symbols as $symbol) {
            $timeframe = strtoupper((string) $this->option('timeframe'));
            // The 2026 XAU intraday plane is intentionally built from a
            // primary feed plus audited, non-overwriting repair bars.  Its
            // audit identity therefore represents the frozen composite, not
            // the application's H1/M15 default price provider.
            $canonical = $symbol->symbol === 'XAUUSD' && $timeframe === 'M1'
                ? 'xauusd_intraday_shadow_composite'
                : (string) config('services.market_data.canonical_provider', config('services.market_data.provider', 'dukascopy'));
            if ($symbol->symbol === 'XAUUSD' && in_array($timeframe, ['M5', 'M30'], true)) {
                $canonical = 'dukascopy_m1_derived';
            }
            $metrics = $audit->audit($canonical, $symbol->symbol, $timeframe);
            $this->line("{$symbol->symbol}: {$metrics['audit_status']}, canonical={$canonical}, gaps={$metrics['unexpected_gaps']}, providers=".collect($metrics['providers'])->keys()->implode(','));
        }

        return self::SUCCESS;
    }
}
