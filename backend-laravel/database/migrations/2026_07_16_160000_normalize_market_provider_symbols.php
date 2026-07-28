<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['XAUUSD' => 'XAU/USD', 'EURUSD' => 'EUR/USD', 'GBPUSD' => 'GBP/USD'] as $symbol => $providerSymbol) {
            DB::table('market_symbols')->where('symbol', $symbol)->update(['provider_symbol' => $providerSymbol]);
        }
    }

    public function down(): void
    {
        foreach (['XAUUSD' => 'XAU_USD', 'EURUSD' => 'EUR_USD', 'GBPUSD' => 'GBP_USD'] as $symbol => $providerSymbol) {
            DB::table('market_symbols')->where('symbol', $symbol)->update(['provider_symbol' => $providerSymbol]);
        }
    }
};
