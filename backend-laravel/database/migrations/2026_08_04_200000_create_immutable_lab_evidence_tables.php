<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The existing laboratory tables intentionally keep a small mutable
     * projection for fast selection.  These tables are the durable evidence
     * plane: one row is created for every attempt/event and is never used as
     * an upsert target.
     */
    public function up(): void
    {
        Schema::create('lab_evaluation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('phase', 48);
            $table->string('mode', 32)->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('queue', 96)->nullable();
            $table->string('job_uuid', 128)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->string('status', 32)->default('started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('worker_name', 128)->nullable();
            $table->string('worker_pid', 32)->nullable();
            $table->string('request_hash', 128)->nullable();
            $table->string('response_hash', 128)->nullable();
            $table->string('data_hash', 128)->nullable();
            $table->string('code_hash', 128)->nullable();
            $table->string('parameter_hash', 128)->nullable();
            $table->string('trade_ledger_hash', 128)->nullable();
            $table->string('error_class', 192)->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('response_meta')->nullable();
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['lab_generation_id', 'phase', 'status'], 'lab_runs_generation_phase_status_idx');
            $table->index(['lab_agent_id', 'phase', 'attempt'], 'lab_runs_agent_phase_attempt_idx');
            $table->index(['request_id'], 'lab_runs_request_id_idx');
        });

        Schema::create('lab_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('run_id', 36)->nullable();
            $table->string('phase', 48)->nullable();
            $table->string('event_type', 64);
            $table->string('from_status', 48)->nullable();
            $table->string('to_status', 48)->nullable();
            $table->unsignedInteger('attempt')->nullable();
            $table->string('source', 96)->nullable();
            $table->string('reason_code', 128)->nullable();
            $table->string('error_class', 192)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['lab_agent_id', 'occurred_at'], 'lab_lifecycle_agent_time_idx');
            $table->index(['lab_generation_id', 'event_type'], 'lab_lifecycle_generation_event_idx');
            $table->index(['run_id', 'event_type'], 'lab_lifecycle_run_event_idx');
        });

        Schema::create('lab_gate_decision_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('current_decision_id')->nullable()->constrained('candidate_gate_decisions')->nullOnDelete();
            $table->foreignId('model_market_performance_id')->nullable()->constrained('model_market_performance')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('run_id', 36)->nullable();
            $table->string('stage', 48);
            $table->string('decision', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->string('attribution_status', 48)->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('metrics')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['lab_agent_id', 'stage', 'revision'], 'lab_gate_agent_stage_revision_idx');
            $table->index(['lab_generation_id', 'stage', 'decision'], 'lab_gate_generation_stage_decision_idx');
        });

        Schema::create('lab_mutation_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mutation_memory_id')->nullable()->constrained('mutation_memories')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('model_market_performance_id')->nullable()->constrained('model_market_performance')->nullOnDelete();
            $table->string('parameter_key', 96);
            $table->string('mutation_bundle_id', 128)->nullable();
            $table->string('outcome', 32);
            $table->decimal('forward_delta', 12, 6)->nullable();
            $table->foreignId('parent_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('control_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->json('evidence_run_ids')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['lab_agent_id', 'parameter_key', 'recorded_at'], 'lab_mutation_agent_parameter_time_idx');
            $table->index(['outcome', 'parameter_key'], 'lab_mutation_outcome_parameter_idx');
        });

        Schema::create('lab_evidence_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('artifact_id')->unique();
            $table->string('run_id', 36)->nullable();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('artifact_type', 64);
            $table->string('sha256', 128);
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('content_encoding', 24)->default('json');
            $table->string('storage_path', 512)->nullable();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['run_id', 'artifact_type'], 'lab_artifact_run_type_idx');
            $table->index(['sha256', 'artifact_type'], 'lab_artifact_hash_type_idx');
        });

        Schema::create('lab_candle_decision_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('decision_id')->unique();
            $table->string('run_id', 36)->nullable();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('candle_time', 64)->nullable();
            $table->unsignedInteger('candle_index')->nullable();
            $table->string('event_type', 40)->default('signal_evaluation');
            $table->string('action', 16)->nullable();
            $table->boolean('accepted')->nullable();
            $table->string('rejection_code', 96)->nullable();
            $table->string('market_regime', 48)->nullable();
            $table->string('volatility_regime', 48)->nullable();
            $table->decimal('confidence', 10, 6)->nullable();
            $table->decimal('price', 20, 10)->nullable();
            $table->json('features')->nullable();
            $table->json('state')->nullable();
            $table->string('payload_hash', 128)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['lab_agent_id', 'candle_time'], 'lab_candle_agent_time_idx');
            $table->index(['run_id', 'event_type'], 'lab_candle_run_type_idx');
            $table->index(['rejection_code', 'market_regime'], 'lab_candle_rejection_regime_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_candle_decision_events');
        Schema::dropIfExists('lab_evidence_artifacts');
        Schema::dropIfExists('lab_mutation_credit_events');
        Schema::dropIfExists('lab_gate_decision_events');
        Schema::dropIfExists('lab_lifecycle_events');
        Schema::dropIfExists('lab_evaluation_runs');
    }
};
