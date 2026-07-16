<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quant_law_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('candidates_created')->default(0);
            $table->unsignedInteger('laws_promoted')->default(0);
            $table->unsignedInteger('conflicts_found')->default(0);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('quant_law_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_law_discovery_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('candidate_key', 180)->unique();
            $table->string('title');
            $table->text('observation');
            $table->string('law_type');
            $table->string('status')->default('emerging');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->decimal('universality_score', 6, 2)->default(0);
            $table->decimal('effect_size', 8, 3)->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('strategy_count')->default(0);
            $table->unsignedInteger('species_count')->default(0);
            $table->unsignedInteger('session_count')->default(0);
            $table->unsignedInteger('trade_count')->default(0);
            $table->json('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'confidence_score'], 'ql_candidates_status_conf_idx');
            $table->index(['law_type', 'universality_score'], 'ql_candidates_type_univ_idx');
        });

        Schema::create('quant_laws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_law_candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('law_key', 180)->unique();
            $table->string('title');
            $table->text('statement');
            $table->string('law_type');
            $table->string('status')->default('emerging');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->decimal('universality_score', 6, 2)->default(0);
            $table->decimal('effect_size', 8, 3)->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('strategy_count')->default(0);
            $table->unsignedInteger('species_count')->default(0);
            $table->unsignedInteger('session_count')->default(0);
            $table->unsignedInteger('trade_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->json('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'confidence_score'], 'quant_laws_status_conf_idx');
            $table->index(['law_type', 'universality_score'], 'quant_laws_type_univ_idx');
        });

        Schema::create('quant_law_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_law_candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quant_law_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('strategy')->nullable();
            $table->string('market_species')->nullable();
            $table->string('evidence_type');
            $table->string('effect_direction')->default('negative');
            $table->decimal('effect_size', 8, 3)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('sample_size')->default(1);
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['quant_law_candidate_id', 'evidence_type'], 'ql_ev_candidate_type_idx');
            $table->index(['quant_law_id', 'evidence_type'], 'ql_ev_law_type_idx');
            $table->index(['source_type', 'source_id'], 'ql_ev_source_idx');
        });

        Schema::create('quant_law_graph_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_law_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_label');
            $table->string('target_label');
            $table->string('relation_type');
            $table->string('polarity')->default('negative');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_label', 'target_label'], 'ql_graph_source_target_idx');
        });

        Schema::create('quant_law_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('law_a_id')->nullable()->constrained('quant_laws')->nullOnDelete();
            $table->foreignId('law_b_id')->nullable()->constrained('quant_laws')->nullOnDelete();
            $table->string('conflict_type')->default('opposite_direction');
            $table->decimal('severity_score', 6, 2)->default(50);
            $table->string('status')->default('open');
            $table->text('summary');
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity_score'], 'ql_conflicts_status_sev_idx');
        });

        Schema::create('quant_law_evolution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_law_id')->constrained()->cascadeOnDelete();
            $table->string('event_type')->default('validated');
            $table->decimal('previous_confidence', 6, 2)->nullable();
            $table->decimal('new_confidence', 6, 2)->default(50);
            $table->decimal('delta', 8, 3)->default(0);
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('universal_driver_rankings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quant_law_discovery_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver_key');
            $table->string('driver_label');
            $table->unsignedInteger('rank')->default(0);
            $table->decimal('impact_score', 6, 2)->default(50);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['rank', 'impact_score'], 'driver_rank_impact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universal_driver_rankings');
        Schema::dropIfExists('quant_law_evolution_events');
        Schema::dropIfExists('quant_law_conflicts');
        Schema::dropIfExists('quant_law_graph_edges');
        Schema::dropIfExists('quant_law_evidences');
        Schema::dropIfExists('quant_laws');
        Schema::dropIfExists('quant_law_candidates');
        Schema::dropIfExists('quant_law_discovery_runs');
    }
};
