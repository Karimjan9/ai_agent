<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_genomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->string('family');
            $table->string('version')->default('v1');
            $table->unsignedInteger('generation')->default(1);
            $table->string('genome_hash')->unique();
            $table->json('genes');
            $table->json('phenotype')->nullable();
            $table->decimal('fitness_score', 8, 2)->default(0);
            $table->decimal('evolution_efficiency', 10, 2)->default(0);
            $table->string('status')->default('alive');
            $table->text('death_reason')->nullable();
            $table->timestamp('born_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['family', 'generation']);
            $table->index(['strategy', 'status']);
        });

        Schema::create('genome_genes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_genome_id')->constrained()->cascadeOnDelete();
            $table->string('gene_key');
            $table->json('gene_value');
            $table->string('value_type')->default('mixed');
            $table->decimal('observed_fitness', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['gene_key', 'value_type']);
        });

        Schema::create('genome_lineages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_genome_id')->nullable()->constrained('strategy_genomes')->nullOnDelete();
            $table->foreignId('child_genome_id')->constrained('strategy_genomes')->cascadeOnDelete();
            $table->string('lineage_type')->default('mutation');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['parent_genome_id', 'child_genome_id', 'lineage_type'], 'genome_lineages_unique');
        });

        Schema::create('genome_mutations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_genome_id')->nullable()->constrained('strategy_genomes')->nullOnDelete();
            $table->foreignId('child_genome_id')->constrained('strategy_genomes')->cascadeOnDelete();
            $table->foreignId('evolution_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mutation_type')->default('parameter_change');
            $table->json('mutation_diff');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('genome_crossovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_a_genome_id')->constrained('strategy_genomes')->cascadeOnDelete();
            $table->foreignId('parent_b_genome_id')->constrained('strategy_genomes')->cascadeOnDelete();
            $table->foreignId('child_genome_id')->nullable()->constrained('strategy_genomes')->nullOnDelete();
            $table->string('child_strategy');
            $table->json('combined_genes');
            $table->text('rationale');
            $table->string('status')->default('proposed');
            $table->timestamps();

            $table->index(['child_strategy', 'status']);
        });

        Schema::create('evolution_generations', function (Blueprint $table): void {
            $table->id();
            $table->string('family');
            $table->unsignedInteger('generation');
            $table->unsignedInteger('genomes_count')->default(0);
            $table->decimal('best_fitness', 8, 2)->default(0);
            $table->decimal('average_fitness', 8, 2)->default(0);
            $table->foreignId('best_genome_id')->nullable()->constrained('strategy_genomes')->nullOnDelete();
            $table->timestamps();

            $table->unique(['family', 'generation']);
        });

        Schema::create('fitness_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_genome_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('fitness_score', 8, 2);
            $table->json('components');
            $table->text('evaluation_summary')->nullable();
            $table->timestamps();

            $table->index('fitness_score');
        });

        Schema::create('selection_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('selection_type')->default('survival_of_fittest');
            $table->json('survivor_genome_ids');
            $table->json('archived_genome_ids');
            $table->json('criteria');
            $table->timestamps();
        });

        Schema::create('extinction_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_genome_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason_code');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestamp('extinct_at')->nullable();
            $table->timestamps();
        });

        Schema::create('genome_discoveries', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('discovery');
            $table->string('gene_key')->nullable();
            $table->json('scope')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('status')->default('provisional');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['gene_key', 'confidence_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genome_discoveries');
        Schema::dropIfExists('extinction_events');
        Schema::dropIfExists('selection_events');
        Schema::dropIfExists('fitness_evaluations');
        Schema::dropIfExists('evolution_generations');
        Schema::dropIfExists('genome_crossovers');
        Schema::dropIfExists('genome_mutations');
        Schema::dropIfExists('genome_lineages');
        Schema::dropIfExists('genome_genes');
        Schema::dropIfExists('strategy_genomes');
    }
};
