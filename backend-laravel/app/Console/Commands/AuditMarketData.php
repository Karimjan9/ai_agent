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
            $metrics = $audit->audit((string) config('services.market_data.provider', 'dukascopy'), $symbol->symbol, (string) $this->option('timeframe'));
            $this->line("{$symbol->symbol}: {$metrics['audit_status']}, gaps={$metrics['unexpected_gaps']}, providers=".collect($metrics['providers'])->keys()->implode(','));
        }

        return self::SUCCESS;
    }
}
