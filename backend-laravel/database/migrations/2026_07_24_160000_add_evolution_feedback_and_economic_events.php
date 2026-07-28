<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('economic_events')) Schema::create('economic_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 48);
            $table->string('external_id', 128);
            $table->string('title');
            $table->string('country', 64)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('impact', 16)->default('unknown');
            $table->dateTime('scheduled_at');
            $table->string('actual', 64)->nullable();
            $table->string('forecast', 64)->nullable();
            $table->string('previous', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index(['scheduled_at', 'impact']);
            $table->index(['currency', 'scheduled_at']);
        });

        if (! Schema::hasTable('paper_confidence_calibrations')) Schema::create('paper_confidence_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 160)->unique();
            $table->unsignedBigInteger('model_market_performance_id')->nullable();
            $table->foreign('model_market_performance_id', 'pcc_performance_fk')->references('id')->on('model_market_performance')->nullOnDelete();
            $table->string('symbol', 16)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->string('strategy_family', 64)->nullable();
            $table->string('market_regime', 32)->nullable();
            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('brier_score', 10, 6)->nullable();
            $table->decimal('reliability_error', 10, 6)->nullable();
            $table->json('bins');
            $table->timestamp('calibrated_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_confidence_calibrations');
        Schema::dropIfExists('economic_events');
    }
};
