<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidate_handoff_events', function (Blueprint $table): void {
            $table->id(); $table->foreignId('lab_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 40); $table->string('status', 40); $table->string('terminal_reason', 96)->nullable();
            $table->json('payload')->nullable(); $table->timestamp('recorded_at'); $table->timestamps();
            $table->unique(['lab_generation_id', 'lab_agent_id', 'stage'], 'handoff_generation_agent_stage_uq');
            $table->index(['stage', 'status'], 'handoff_stage_status_idx');
        });
        Schema::create('decision_counterfactual_edges', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('model_market_performance_id')->nullable();
            $table->foreign('model_market_performance_id', 'dce_performance_fk')->references('id')->on('model_market_performance')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete(); $table->string('edge_key', 128);
            $table->string('source_node', 64); $table->string('target_node', 64); $table->string('regime', 32)->nullable();
            $table->string('cost_scenario', 48)->nullable(); $table->decimal('baseline_value', 12, 6)->nullable();
            $table->decimal('intervention_value', 12, 6)->nullable(); $table->decimal('delta_value', 12, 6)->nullable();
            $table->decimal('lower_confidence_bound', 12, 6)->nullable(); $table->decimal('upper_confidence_bound', 12, 6)->nullable();
            $table->unsignedInteger('sample_count')->default(0); $table->string('evidence_status', 48); $table->json('metadata')->nullable(); $table->timestamps();
            $table->unique(['model_market_performance_id', 'lab_agent_id', 'edge_key'], 'blame_performance_agent_edge_uq');
        });
        Schema::create('paper_execution_events', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('model_market_performance_id');
            $table->foreign('model_market_performance_id', 'pee_performance_fk')->references('id')->on('model_market_performance')->cascadeOnDelete();
            $table->foreignId('paper_signal_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('paper_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40); $table->string('provider', 48)->nullable(); $table->string('idempotency_key', 128);
            $table->timestamp('occurred_at'); $table->decimal('requested_price', 16, 8)->nullable(); $table->decimal('filled_price', 16, 8)->nullable();
            $table->decimal('requested_units', 16, 6)->nullable(); $table->decimal('filled_units', 16, 6)->nullable();
            $table->unsignedInteger('latency_ms')->nullable(); $table->string('reason', 128)->nullable(); $table->unsignedInteger('retry_count')->default(0);
            $table->json('payload')->nullable(); $table->timestamps(); $table->unique('idempotency_key', 'paper_exec_idempotency_uq');
            $table->index(['paper_order_id', 'event_type'], 'paper_exec_order_event_idx');
        });
        Schema::table('agent_failure_cases', function (Blueprint $table): void {
            $table->string('severity', 24)->default('P2_RESEARCH')->after('failure_type');
            $table->string('expected_action', 64)->nullable()->after('expected_safe_behavior');
            $table->timestamp('discovered_at')->nullable()->after('regression_status'); $table->timestamp('fixed_at')->nullable()->after('discovered_at');
        });
    }
    public function down(): void
    {
        Schema::table('agent_failure_cases', function (Blueprint $table): void { $table->dropColumn(['severity','expected_action','discovered_at','fixed_at']); });
        Schema::dropIfExists('paper_execution_events'); Schema::dropIfExists('decision_counterfactual_edges'); Schema::dropIfExists('candidate_handoff_events');
    }
};
