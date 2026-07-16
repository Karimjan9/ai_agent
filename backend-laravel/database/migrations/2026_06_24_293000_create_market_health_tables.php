<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_provider_health', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('mt5');
            $table->string('symbol');
            $table->string('timeframe');
            $table->timestamp('last_candle_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('unknown');
            $table->unsignedInteger('age_seconds')->default(0);
            $table->unsignedInteger('stale_after_seconds')->default(1200);
            $table->unsignedInteger('lost_after_seconds')->default(1800);
            $table->boolean('alert_sent')->default(false);
            $table->timestamp('alert_sent_at')->nullable();
            $table->boolean('auto_recovery_attempted')->default(false);
            $table->timestamp('auto_recovery_attempted_at')->nullable();
            $table->text('message')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'symbol', 'timeframe'], 'market_provider_health_unique');
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('system_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('log_type');
            $table->string('level')->default('info');
            $table->string('component')->nullable();
            $table->string('action')->nullable();
            $table->string('status')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['log_type', 'occurred_at']);
            $table->index(['component', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
        Schema::dropIfExists('market_provider_health');
    }
};
