<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_data_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->timestamp('last_confirmed_candle_at')->nullable();
            $table->timestamp('pending_from_at')->nullable();
            $table->timestamp('pending_to_at')->nullable();
            $table->string('status', 24)->default('healthy');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'symbol', 'timeframe'], 'market_data_sync_state_unique');
            $table->index(['status', 'pending_from_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_data_sync_states');
    }
};
