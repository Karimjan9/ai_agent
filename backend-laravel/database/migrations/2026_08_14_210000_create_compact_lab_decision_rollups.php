<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_candle_decision_rollups', function (Blueprint $table): void {
            $table->id();
            $table->string('rollup_key', 64)->unique();
            $table->string('run_id', 36)->nullable();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->date('bucket_date')->nullable();
            $table->string('event_type', 40)->default('signal_evaluation');
            $table->string('action', 16)->nullable();
            $table->boolean('accepted')->nullable();
            $table->string('rejection_code', 96)->nullable();
            $table->string('market_regime', 48)->nullable();
            $table->string('volatility_regime', 48)->nullable();
            $table->unsignedBigInteger('event_count')->default(0);
            $table->unsignedBigInteger('accepted_count')->default(0);
            $table->string('first_candle_time', 64)->nullable();
            $table->string('last_candle_time', 64)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['lab_agent_id', 'bucket_date'], 'lab_rollup_agent_date_idx');
            $table->index(['rejection_code', 'market_regime'], 'lab_rollup_rejection_regime_idx');
            $table->index(['run_id', 'event_type'], 'lab_rollup_run_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_candle_decision_rollups');
    }
};
