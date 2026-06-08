<?php

namespace Database\Seeders;

use App\Models\MarketSymbol;
use App\Models\Symbol;
use Illuminate\Database\Seeder;

class MarketSymbolSeeder extends Seeder
{
    public function run(): void
    {
        $symbols = [
            [
                'symbol' => 'XAUUSD',
                'provider_symbol' => 'XAU_USD',
                'name' => 'Gold / US Dollar',
                'market_type' => 'forex',
                'is_active' => true,
            ],
            [
                'symbol' => 'EURUSD',
                'provider_symbol' => 'EUR_USD',
                'name' => 'Euro / US Dollar',
                'market_type' => 'forex',
                'is_active' => true,
            ],
            [
                'symbol' => 'GBPUSD',
                'provider_symbol' => 'GBP_USD',
                'name' => 'British Pound / US Dollar',
                'market_type' => 'forex',
                'is_active' => true,
            ],
        ];

        foreach ($symbols as $symbol) {
            MarketSymbol::updateOrCreate(['symbol' => $symbol['symbol']], $symbol);

            Symbol::updateOrCreate(
                ['code' => $symbol['symbol']],
                [
                    'display_name' => $symbol['name'] ?? $symbol['symbol'],
                    'asset_class' => $symbol['market_type'],
                    'is_active' => $symbol['is_active'],
                ],
            );
        }
    }
}
