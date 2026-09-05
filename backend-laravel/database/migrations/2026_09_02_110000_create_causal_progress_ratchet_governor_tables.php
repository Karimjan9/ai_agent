<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('causal_progress_ratchets', function (Blueprint $table): void {
            $table->id();
            $table->string('ratchet_key', 160)->unique();
            $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('composition_key', 160); $table->unsignedBigInteger('causal_baseline_id')->nullable();
            $table->string('baseline_epoch_hash', 128); $table->string('data_hash', 128); $table->string('execution_hash', 128);
            $table->string('deepest_stage', 48)->default('none'); $table->unsignedTinyInteger('stage_depth')->default(0);
            $table->string('authority', 64)->default('none'); $table->string('status', 64)->default('active');
            $table->json('evidence'); $table->timestamps();
            $table->unique(['composition_key', 'baseline_epoch_hash', 'data_hash', 'execution_hash'], 'causal_ratchet_identity_uq');
            $table->index(['symbol', 'timeframe', 'stage_depth'], 'causal_ratchet_scope_depth_idx');
        });

        Schema::create('causal_progress_states', function (Blueprint $table): void {
            $table->id(); $table->string('progress_key', 160)->unique();
            $table->foreignId('causal_progress_ratchet_id')->nullable()->constrained('causal_progress_ratchets')->nullOnDelete();
            $table->string('phase', 64); $table->string('status', 64); $table->string('intervention_hash', 128);
            $table->string('window_plan_hash', 128); $table->json('evidence'); $table->timestamp('settled_at')->nullable(); $table->timestamps();
            $table->index(['causal_progress_ratchet_id', 'phase', 'status'], 'causal_progress_state_idx');
        });

        Schema::create('causal_axis_retirements', function (Blueprint $table): void {
            $table->id(); $table->string('retirement_key', 160)->unique();
            $table->foreignId('causal_progress_ratchet_id')->nullable()->constrained('causal_progress_ratchets')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('composition_key', 160);
            $table->string('axis', 96); $table->string('classification', 64); $table->unsignedInteger('observations')->default(1);
            $table->boolean('retired')->default(false); $table->json('evidence'); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'axis', 'classification'], 'causal_axis_scope_idx');
        });

        Schema::create('causal_governor_debt_ledgers', function (Blueprint $table): void {
            $table->id(); $table->string('ledger_key', 160)->unique(); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->unsignedInteger('debt_score')->default(0); $table->string('mode', 64); $table->json('debt'); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'created_at'], 'causal_debt_scope_time_idx');
        });

        Schema::create('causal_governor_allocations', function (Blueprint $table): void {
            $table->id(); $table->string('allocation_key', 160)->unique(); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('mode', 64); $table->json('allocation'); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'created_at'], 'causal_allocation_scope_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('causal_governor_allocations');
        Schema::dropIfExists('causal_governor_debt_ledgers');
        Schema::dropIfExists('causal_axis_retirements');
        Schema::dropIfExists('causal_progress_states');
        Schema::dropIfExists('causal_progress_ratchets');
    }
};
