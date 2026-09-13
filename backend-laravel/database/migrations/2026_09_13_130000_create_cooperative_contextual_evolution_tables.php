<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_idea_inbox_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('idea_key', 128)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('source_type', 32);
            $table->string('source_reference', 255)->nullable();
            $table->string('title', 255);
            $table->text('hypothesis');
            $table->json('compatibility_contract');
            $table->json('executable_contract');
            $table->json('bounded_genes');
            $table->string('status', 40)->default('ready_for_experiment');
            $table->string('assigned_block_key', 128)->nullable();
            $table->json('evidence_receipt')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'status'], 'research_idea_inbox_scope_status_idx');
        });

        Schema::create('cooperative_module_species_members', function (Blueprint $table): void {
            $table->id();
            $table->string('member_key', 128)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('species', 48);
            $table->string('component_key', 128);
            $table->string('context_cell_key', 128)->nullable();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->json('genome');
            $table->json('evidence')->nullable();
            $table->string('authority_level', 32)->default('hypothesis');
            $table->string('status', 24)->default('research');
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'species', 'status'], 'cooperative_species_scope_idx');
            $table->index(['context_cell_key', 'species'], 'cooperative_species_context_idx');
        });

        Schema::create('contextual_specialist_capsules', function (Blueprint $table): void {
            $table->id();
            $table->string('capsule_key', 128)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('context_cell_key', 128);
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->json('identity');
            $table->json('components');
            $table->json('activation_contract');
            $table->json('pareto_vector');
            $table->json('evidence')->nullable();
            $table->string('authority_level', 40)->default('research_only');
            $table->string('status', 32)->default('challenger');
            $table->unsignedInteger('outside_scope_activation_count')->default(0);
            $table->foreignId('replaces_capsule_id')->nullable()->constrained('contextual_specialist_capsules')->nullOnDelete();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'context_cell_key', 'status'], 'contextual_capsule_cell_status_idx');
            $table->index(['authority_level', 'status'], 'contextual_capsule_authority_idx');
        });

        Schema::create('cooperative_experiment_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('settlement_key', 128)->unique();
            $table->string('block_key', 128);
            $table->foreignId('lab_generation_id')->constrained('lab_generations')->cascadeOnDelete();
            $table->string('block_type', 48);
            $table->string('context_cell_key', 128)->nullable();
            $table->json('arm_results');
            $table->json('component_effects');
            $table->json('pareto_vectors');
            $table->string('outcome_status', 48);
            $table->boolean('evidence_complete')->default(false);
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['lab_generation_id', 'block_key'], 'cooperative_settlement_generation_block_idx');
            $table->index(['block_type', 'outcome_status'], 'cooperative_settlement_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_experiment_settlements');
        Schema::dropIfExists('contextual_specialist_capsules');
        Schema::dropIfExists('cooperative_module_species_members');
        Schema::dropIfExists('research_idea_inbox_entries');
    }
};
