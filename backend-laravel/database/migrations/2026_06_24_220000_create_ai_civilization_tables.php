<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('civilization_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_key', 120)->unique();
            $table->string('display_name');
            $table->string('role_key', 80);
            $table->string('role_label');
            $table->string('domain')->default('institutional');
            $table->string('status')->default('active');
            $table->decimal('credits_balance', 12, 2)->default(0);
            $table->decimal('reputation_score', 6, 2)->default(50);
            $table->decimal('contribution_score', 6, 2)->default(50);
            $table->decimal('trust_score', 6, 2)->default(50);
            $table->decimal('vote_weight', 6, 2)->default(1);
            $table->json('capabilities')->nullable();
            $table->json('objectives')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['role_key', 'status'], 'civ_agents_role_status_idx');
            $table->index('reputation_score', 'civ_agents_reputation_idx');
        });

        Schema::create('civilization_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('civilization_agent_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['civilization_agent_id', 'event_type'], 'civ_credit_agent_event_idx');
            $table->index(['source_type', 'source_id'], 'civ_credit_source_idx');
        });

        Schema::create('council_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('proposal_key', 160)->unique();
            $table->foreignId('proposed_by_agent_id')->nullable()->constrained('civilization_agents')->nullOnDelete();
            $table->string('title');
            $table->string('proposal_type')->default('research_allocation');
            $table->string('status')->default('decided');
            $table->string('final_decision')->default('pending');
            $table->decimal('expected_value_score', 6, 2)->default(50);
            $table->decimal('risk_score', 6, 2)->default(50);
            $table->decimal('knowledge_gap_score', 6, 2)->default(50);
            $table->decimal('quorum_score', 6, 2)->default(0);
            $table->decimal('consensus_score', 6, 2)->default(0);
            $table->text('rationale')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'final_decision'], 'council_decision_status_idx');
        });

        Schema::create('council_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('council_decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('civilization_agent_id')->constrained()->cascadeOnDelete();
            $table->string('vote');
            $table->decimal('weight', 6, 2)->default(1);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['council_decision_id', 'civilization_agent_id'], 'council_vote_agent_unique');
            $table->index('vote', 'council_vote_vote_idx');
        });

        Schema::create('civilization_memories', function (Blueprint $table): void {
            $table->id();
            $table->string('memory_key', 180)->unique();
            $table->string('memory_type');
            $table->string('title');
            $table->text('summary');
            $table->decimal('impact_score', 6, 2)->default(50);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('tags')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['memory_type', 'status'], 'civ_memory_type_status_idx');
            $table->index(['source_type', 'source_id'], 'civ_memory_source_idx');
        });

        Schema::create('institutional_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('knowledge_key', 180)->unique();
            $table->string('title');
            $table->string('knowledge_type');
            $table->text('summary');
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('preservation_status')->default('preserved');
            $table->string('status')->default('active');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['knowledge_type', 'status'], 'inst_knowledge_type_status_idx');
            $table->index(['source_type', 'source_id'], 'inst_knowledge_source_idx');
        });

        Schema::create('civilization_goals', function (Blueprint $table): void {
            $table->id();
            $table->string('goal_key', 120)->unique();
            $table->foreignId('owner_agent_id')->nullable()->constrained('civilization_agents')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('priority_score', 6, 2)->default(50);
            $table->decimal('progress_score', 6, 2)->default(0);
            $table->string('status')->default('active');
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority_score'], 'civ_goal_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('civilization_goals');
        Schema::dropIfExists('institutional_knowledge');
        Schema::dropIfExists('civilization_memories');
        Schema::dropIfExists('council_votes');
        Schema::dropIfExists('council_decisions');
        Schema::dropIfExists('civilization_credit_events');
        Schema::dropIfExists('civilization_agents');
    }
};
