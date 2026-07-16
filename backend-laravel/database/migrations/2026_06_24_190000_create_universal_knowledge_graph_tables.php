<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_graph_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('node_type');
            $table->string('node_key')->unique();
            $table->string('label');
            $table->text('summary')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['node_type', 'confidence_score']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('knowledge_graph_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_node_id')->constrained('knowledge_graph_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('knowledge_graph_nodes')->cascadeOnDelete();
            $table->string('relation_type');
            $table->decimal('weight', 8, 4)->default(1);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('polarity')->default('positive');
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['source_node_id', 'target_node_id', 'relation_type'], 'knowledge_graph_edge_unique');
            $table->index(['relation_type', 'confidence_score']);
        });

        Schema::create('knowledge_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('primary_node_id')->nullable()->constrained('knowledge_graph_nodes')->nullOnDelete();
            $table->string('title')->unique();
            $table->text('claim');
            $table->string('claim_type');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('status')->default('provisional');
            $table->json('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['claim_type', 'confidence_score']);
        });

        Schema::create('knowledge_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_claim_id')->nullable()->constrained('knowledge_claims')->cascadeOnDelete();
            $table->foreignId('knowledge_graph_node_id')->nullable()->constrained('knowledge_graph_nodes')->cascadeOnDelete();
            $table->foreignId('knowledge_graph_edge_id')->nullable()->constrained('knowledge_graph_edges')->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('evidence_type');
            $table->text('summary');
            $table->decimal('weight', 8, 4)->default(1);
            $table->timestamp('observed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('evidence_type');
        });

        Schema::create('knowledge_queries', function (Blueprint $table): void {
            $table->id();
            $table->text('question');
            $table->text('answer');
            $table->json('matched_node_ids')->nullable();
            $table->json('matched_edge_ids')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->json('reasoning')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_mining_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('nodes_created')->default(0);
            $table->unsignedInteger('edges_created')->default(0);
            $table->unsignedInteger('claims_created')->default(0);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_mining_runs');
        Schema::dropIfExists('knowledge_queries');
        Schema::dropIfExists('knowledge_evidence');
        Schema::dropIfExists('knowledge_claims');
        Schema::dropIfExists('knowledge_graph_edges');
        Schema::dropIfExists('knowledge_graph_nodes');
    }
};
