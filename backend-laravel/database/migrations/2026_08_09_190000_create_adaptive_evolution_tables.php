<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_evolution_islands', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('island_key', 128);
            $table->foreignId('local_champion_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->json('archive_counts')->nullable();
            $table->decimal('diversity_score', 8, 4)->default(0);
            $table->decimal('progress_score', 8, 4)->default(0);
            $table->unsignedInteger('stagnation_generations')->default(0);
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'strategy_family', 'island_key'], 'lab_evolution_island_scope_uq');
            $table->index(['symbol', 'timeframe', 'strategy_family'], 'lab_evolution_island_scope_idx');
        });

        Schema::create('lab_evolution_archive_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('island_key', 128);
            $table->string('archive_type', 32);
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->unsignedInteger('rank')->default(0);
            $table->decimal('novelty_score', 8, 4)->default(0);
            $table->string('behavior_signature', 128)->nullable();
            $table->json('fitness_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['archive_type', 'island_key', 'model_version_id'], 'lab_evolution_archive_entry_uq');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'archive_type'], 'lab_evolution_archive_scope_idx');
            $table->index(['island_key', 'status'], 'lab_evolution_archive_island_status_idx');
        });

        Schema::create('lab_parent_selection_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_generation_id')->constrained('lab_generations')->cascadeOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('origin', 32);
            $table->string('target', 64)->nullable();
            $table->string('island_key', 128);
            $table->string('mode', 48);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('selected_count')->default(0);
            $table->json('selected_parent_model_version_ids')->nullable();
            $table->json('candidate_scores')->nullable();
            $table->json('policy')->nullable();
            $table->decimal('diversity_score', 8, 4)->default(0);
            $table->decimal('progress_score', 8, 4)->default(0);
            $table->decimal('exploration_ratio', 8, 4)->default(0);
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['lab_generation_id', 'mode'], 'lab_parent_selection_generation_mode_idx');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'island_key'], 'lab_parent_selection_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_parent_selection_decisions');
        Schema::dropIfExists('lab_evolution_archive_entries');
        Schema::dropIfExists('lab_evolution_islands');
    }
};
