<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_volume_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_contract', 96);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->dateTime('time');
            $table->decimal('raw_volume', 28, 8);
            $table->string('semantic', 32);
            $table->string('unit', 32);
            $table->string('status', 24)->default('usable');
            $table->timestamps();

            $table->unique(
                ['source_contract', 'symbol', 'timeframe', 'time'],
                'market_volume_source_symbol_time_unique',
            );
            $table->index(['symbol', 'timeframe', 'time'], 'market_volume_market_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_volume_observations');
    }
};
