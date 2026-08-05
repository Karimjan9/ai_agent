<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable conclusions derived from the evidence plane. These rows are
     * deliberately separate from mutable agent/model projections: a new
     * evidence window creates a new insight instead of rewriting an old one.
     */
    public function up(): void
    {
        Schema::create('lab_learning_insights', function (Blueprint $table): void {
            $table->id();
            $table->uuid('insight_id')->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('scope_key', 128)->default('global');
            $table->string('insight_type', 64);
            $table->string('evidence_quality', 32);
            $table->boolean('causal_prior_allowed')->default(false);
            $table->decimal('confidence', 6, 3)->default(0);
            $table->string('source_hash', 128)->unique();
            $table->json('source_generation_ids')->nullable();
            $table->json('source_agent_ids')->nullable();
            $table->json('source_run_ids')->nullable();
            $table->json('source_event_ids')->nullable();
            $table->json('failure_signature')->nullable();
            $table->json('metrics')->nullable();
            $table->json('recommended_mutations')->nullable();
            $table->json('blocked_mutations')->nullable();
            $table->text('conclusion')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family', 'generated_at'], 'lab_learning_scope_time_idx');
            $table->index(['evidence_quality', 'causal_prior_allowed'], 'lab_learning_quality_idx');
        });

        Schema::create('lab_learning_consumption_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('lab_learning_insight_id')->nullable()->constrained('lab_learning_insights')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('role', 64);
            $table->string('target', 64)->nullable();
            $table->string('evidence_quality', 32)->nullable();
            $table->boolean('causal_prior_allowed')->default(false);
            $table->json('selected_keys')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['lab_generation_id', 'strategy_family'], 'lab_learning_consumption_generation_family_idx');
            $table->index(['symbol', 'timeframe', 'recorded_at'], 'lab_learning_consumption_scope_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_learning_consumption_events');
        Schema::dropIfExists('lab_learning_insights');
    }
};
