<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Research-only pairing and dispatch ledgers.  These tables deliberately
     * sit beside, rather than inside, the promotion/evidence tables: a
     * learning observation may guide a future mutation, but it can never
     * grant paper, forward or parent eligibility by itself.
     */
    public function up(): void
    {
        Schema::create('lab_learning_lane_pairs', function (Blueprint $table): void {
            $table->id();
            $table->string('pair_key', 128)->unique();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('candidate_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('control_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('candidate_response_map_id')->nullable()->constrained('lab_mutation_response_maps')->nullOnDelete();
            $table->foreignId('control_response_map_id')->nullable()->constrained('lab_mutation_response_maps')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('target', 64)->nullable();
            $table->string('specialist_role', 96)->nullable();
            $table->string('baseline_source', 32)->nullable(); // control; parent; anchor; missing
            $table->string('status', 32)->default('missing_control');
            $table->string('candidate_evidence_run_id', 128)->nullable();
            $table->string('control_evidence_run_id', 128)->nullable();
            $table->string('independent_window_key', 128)->nullable();
            $table->json('candidate_metrics')->nullable();
            $table->json('control_metrics')->nullable();
            $table->json('target_delta')->nullable();
            $table->json('non_target_regression')->nullable();
            $table->json('failure_signature')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['symbol', 'timeframe', 'strategy_family', 'target', 'status'],
                'lab_learning_pair_scope_status_idx',
            );
            $table->index(['candidate_agent_id', 'status'], 'lab_learning_pair_candidate_status_idx');
        });

        Schema::create('lab_learning_lane_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('dispatch_key', 128)->unique();
            $table->foreignId('pair_id')->nullable()->constrained('lab_learning_lane_pairs')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('target', 64)->nullable();
            $table->string('specialist_role', 96)->nullable();
            $table->string('status', 32)->default('selected');
            $table->string('queue_batch_id', 128)->nullable();
            $table->decimal('selection_score', 12, 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['symbol', 'timeframe', 'strategy_family', 'status'],
                'lab_learning_dispatch_scope_status_idx',
            );
            $table->index(['lab_agent_id', 'status'], 'lab_learning_dispatch_agent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_learning_lane_dispatches');
        Schema::dropIfExists('lab_learning_lane_pairs');
    }
};
