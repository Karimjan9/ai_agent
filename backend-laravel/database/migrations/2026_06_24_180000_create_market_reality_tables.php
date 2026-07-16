<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_species', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('dominant_state');
            $table->text('description')->nullable();
            $table->decimal('danger_score', 6, 2)->default(50);
            $table->decimal('opportunity_score', 6, 2)->default(50);
            $table->json('signature')->nullable();
            $table->timestamps();
        });

        Schema::create('market_species_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_species_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->json('signature');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('sample_size')->default(0);
            $table->timestamps();

            $table->unique(['market_species_id', 'version']);
        });

        Schema::create('market_state_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('symbol_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_species_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('timeframe');
            // `timestamp` can implicitly gain ON UPDATE CURRENT_TIMESTAMP on
            // older MySQL/MariaDB configurations. A snapshot's observation
            // time is immutable and participates in a unique key, so use a
            // datetime column instead.
            $table->dateTime('time');
            $table->string('market_state');
            $table->string('liquidity_state');
            $table->string('momentum_state');
            $table->string('structure_state');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->decimal('trend_score', 6, 2)->default(0);
            $table->decimal('panic_score', 6, 2)->default(0);
            $table->decimal('compression_score', 6, 2)->default(0);
            $table->decimal('expansion_score', 6, 2)->default(0);
            $table->decimal('momentum_score', 6, 2)->default(0);
            $table->decimal('liquidity_proxy_score', 6, 2)->default(0);
            $table->json('features')->nullable();
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'timeframe', 'time']);
            $table->index(['market_state', 'time']);
        });

        Schema::create('market_state_probabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_state_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('state');
            $table->decimal('probability', 6, 4);
            $table->timestamps();

            $table->index(['state', 'probability']);
        });

        Schema::create('market_genomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_state_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_species_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('timeframe');
            $table->timestamp('time');
            $table->string('genome_hash')->unique();
            $table->json('vector');
            $table->decimal('trend', 6, 2)->default(0);
            $table->decimal('panic', 6, 2)->default(0);
            $table->decimal('compression', 6, 2)->default(0);
            $table->decimal('momentum', 6, 2)->default(0);
            $table->decimal('liquidity_proxy', 6, 2)->default(0);
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'time']);
        });

        Schema::create('market_memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_species_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_state_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('timeframe');
            $table->string('memory_type')->default('market_event');
            $table->string('market_state');
            $table->text('summary');
            $table->text('lesson');
            $table->decimal('strength', 6, 2)->default(50);
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['market_state', 'memory_type']);
        });

        Schema::create('market_similarity_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('current_market_genome_id')->constrained('market_genomes')->cascadeOnDelete();
            $table->foreignId('matched_market_genome_id')->constrained('market_genomes')->cascadeOnDelete();
            $table->decimal('similarity_score', 6, 2);
            $table->text('lesson')->nullable();
            $table->timestamps();

            $table->unique(['current_market_genome_id', 'matched_market_genome_id'], 'market_similarity_unique');
            $table->index('similarity_score');
        });

        Schema::create('market_discoveries', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('discovery');
            $table->foreignId('market_species_id')->nullable()->constrained()->nullOnDelete();
            $table->string('market_state')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('status')->default('provisional');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['market_state', 'confidence_score']);
        });

        Schema::create('strategy_species_performance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_species_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->string('species_code')->nullable();
            $table->string('species_name')->nullable();
            $table->unsignedInteger('trades')->default(0);
            $table->decimal('winrate', 6, 2)->default(0);
            $table->decimal('profit_percent', 10, 2)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'species_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_species_performance');
        Schema::dropIfExists('market_discoveries');
        Schema::dropIfExists('market_similarity_matches');
        Schema::dropIfExists('market_memories');
        Schema::dropIfExists('market_genomes');
        Schema::dropIfExists('market_state_probabilities');
        Schema::dropIfExists('market_state_snapshots');
        Schema::dropIfExists('market_species_versions');
        Schema::dropIfExists('market_species');
    }
};
