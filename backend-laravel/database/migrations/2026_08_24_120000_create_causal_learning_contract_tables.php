<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_learning_mutation_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('intent_id')->unique();
            $table->string('intent_key', 128)->unique();
            $table->string('packet_id', 64)->nullable()->index();
            $table->foreignId('lab_generation_id')->constrained('lab_generations')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('target', 96)->nullable();
            $table->string('selected_gene', 128)->nullable();
            $table->string('influence_type', 48)->default('independent_exploration');
            $table->string('status', 32)->default('sealed');
            $table->json('retrieved_lesson_ids')->nullable();
            $table->json('selected_lesson_ids')->nullable();
            $table->json('causally_applied_lesson_ids')->nullable();
            $table->json('rejected_lesson_ids')->nullable();
            $table->json('causally_applied_retrieval_ids')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('baseline_hash', 128);
            $table->string('parameter_hash', 128);
            $table->string('mutation_hash', 128);
            // DATETIME(6) is portable to the production MariaDB profile,
            // where a required TIMESTAMP without an implicit default is
            // rejected even though Laravel emits valid modern MySQL SQL.
            $table->dateTime('retrieved_at', 6)->nullable();
            $table->dateTime('sealed_at', 6);
            $table->dateTime('bound_at', 6)->nullable();
            $table->dateTime('invalidated_at', 6)->nullable();
            $table->string('invalid_reason', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(
                ['symbol', 'timeframe', 'strategy_family', 'influence_type', 'status'],
                'learning_mutation_intent_scope_idx',
            );
        });

        Schema::create('agent_learning_causal_experiments', function (Blueprint $table): void {
            $table->id();
            $table->string('experiment_key', 128)->unique();
            $table->foreignId('lab_generation_id')->constrained('lab_generations')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('target', 96)->nullable();
            $table->string('gene_key', 128);
            $table->foreignId('source_lesson_id')->nullable()->constrained('agent_learning_lessons')->nullOnDelete();
            $table->foreignId('guided_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('blinded_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('control_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('status', 48)->default('awaiting_counterfactuals');
            $table->unsignedSmallInteger('independent_window_count')->default(0);
            $table->boolean('guided_beats_blinded')->default(false);
            $table->boolean('guided_beats_control')->default(false);
            $table->json('evidence')->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family', 'status'], 'causal_experiment_scope_idx');
        });

        Schema::create('generation_admission_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 128)->unique();
            $table->foreignId('ai_laboratory_id')->constrained('ai_laboratories')->cascadeOnDelete();
            $table->foreignId('latest_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->string('decision', 48);
            $table->boolean('allowed')->default(false);
            $table->json('reason_codes')->nullable();
            $table->json('context')->nullable();
            $table->dateTime('decided_at', 6);
            $table->timestamps();
            $table->index(['ai_laboratory_id', 'decision', 'allowed'], 'generation_admission_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_admission_decisions');
        Schema::dropIfExists('agent_learning_causal_experiments');
        Schema::dropIfExists('agent_learning_mutation_intents');
    }
};
