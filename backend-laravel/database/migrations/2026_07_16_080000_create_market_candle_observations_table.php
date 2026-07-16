<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_candle_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->dateTime('time');
            $table->decimal('open', 18, 6);
            $table->decimal('high', 18, 6);
            $table->decimal('low', 18, 6);
            $table->decimal('close', 18, 6);
            $table->decimal('volume', 20, 6)->default(0);
            $table->timestamps();
            $table->unique(['provider', 'symbol', 'timeframe', 'time'], 'market_observation_provider_time_unique');
            $table->index(['symbol', 'timeframe', 'time'], 'market_observation_market_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_candle_observations');
    }
};
