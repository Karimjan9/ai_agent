<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_learning_memories')) {
            Schema::create('lab_learning_memories', function (Blueprint $table): void {
                $table->id();
                $table->string('memory_key', 128)->unique();
                $table->string('symbol', 32);
                $table->string('timeframe', 16);
                $table->string('family', 96)->nullable();
                $table->string('specialist_role', 96)->nullable();
                $table->string('target', 96)->nullable();
                $table->string('state_signature', 160)->nullable();
                $table->string('gene', 160)->nullable();
                $table->string('direction', 32)->nullable();
                $table->string('memory_type', 24);
                $table->string('status', 24)->default('active');
                $table->unsignedInteger('trial_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failure_count')->default(0);
                $table->decimal('score', 18, 8)->default(0);
                $table->decimal('confidence', 8, 5)->default(0);
                $table->timestamp('blocked_until')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('last_observed_at')->nullable();
                $table->timestamps();

                $table->index(['symbol', 'timeframe', 'family', 'target'], 'lab_learning_mem_scope_idx');
                $table->index(['memory_type', 'status'], 'lab_learning_mem_state_idx');
            });
        }

        if (! Schema::hasTable('lab_failure_dojo_runs')) {
            Schema::create('lab_failure_dojo_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('dojo_key', 128)->unique();
                $table->foreignId('pair_id')->nullable()->constrained('lab_learning_lane_pairs')->nullOnDelete();
                $table->foreignId('candidate_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
                $table->foreignId('repair_anchor_id')->nullable()->constrained('lab_failure_repair_anchors')->nullOnDelete();
                $table->string('symbol', 32);
                $table->string('timeframe', 16);
                $table->string('family', 96)->nullable();
                $table->string('target', 96)->nullable();
                $table->string('state_signature', 160)->nullable();
                $table->string('expected_action', 64)->nullable();
                $table->string('status', 32)->default('pending');
                $table->decimal('score', 18, 8)->nullable();
                $table->json('failure_signature')->nullable();
                $table->json('evidence')->nullable();
                $table->timestamp('evaluated_at')->nullable();
                $table->timestamps();

                $table->index(['symbol', 'timeframe', 'status'], 'lab_dojo_scope_idx');
                $table->index(['family', 'target', 'state_signature'], 'lab_dojo_target_idx');
            });
        }

        if (! Schema::hasTable('lab_council_disagreements')) {
            Schema::create('lab_council_disagreements', function (Blueprint $table): void {
                $table->id();
                $table->string('event_key', 128)->unique();
                $table->string('symbol', 32);
                $table->string('timeframe', 16);
                $table->string('family', 96)->nullable();
                $table->string('h1_context_hash', 160)->nullable();
                $table->timestamp('decision_at')->nullable();
                $table->string('regime', 64)->nullable();
                $table->json('specialist_votes')->nullable();
                $table->string('risk_decision', 32)->nullable();
                $table->string('council_decision', 64)->nullable();
                $table->json('disagreement')->nullable();
                $table->string('outcome_status', 32)->default('unresolved');
                $table->decimal('outcome_score', 18, 8)->nullable();
                $table->json('evidence')->nullable();
                $table->boolean('promotion_evidence')->default(false);
                $table->timestamps();

                $table->index(['symbol', 'timeframe', 'outcome_status'], 'lab_disagreement_scope_idx');
                $table->index(['regime', 'family'], 'lab_disagreement_regime_idx');
            });
        }

        if (! Schema::hasTable('lab_gene_interactions')) {
            Schema::create('lab_gene_interactions', function (Blueprint $table): void {
                $table->id();
                $table->string('interaction_key', 128)->unique();
                $table->string('symbol', 32);
                $table->string('timeframe', 16);
                $table->string('family', 96)->nullable();
                $table->string('specialist_role', 96)->nullable();
                $table->string('target', 96)->nullable();
                $table->json('genes');
                $table->json('mentor_ids')->nullable();
                $table->string('status', 32)->default('awaiting_mentors');
                $table->json('baseline_metrics')->nullable();
                $table->json('observed_metrics')->nullable();
                $table->decimal('target_delta', 18, 8)->nullable();
                $table->boolean('non_target_regression')->nullable();
                $table->json('evidence')->nullable();
                $table->boolean('promotion_evidence')->default(false);
                $table->timestamps();

                $table->index(['symbol', 'timeframe', 'status'], 'lab_interaction_scope_idx');
                $table->index(['family', 'specialist_role', 'target'], 'lab_interaction_target_idx');
            });
        }

        if (Schema::hasTable('lab_learning_lane_dispatches')) {
            Schema::table('lab_learning_lane_dispatches', function (Blueprint $table): void {
                if (! Schema::hasColumn('lab_learning_lane_dispatches', 'stage')) {
                    $table->string('stage', 32)->default('micro')->after('status');
                }
                if (! Schema::hasColumn('lab_learning_lane_dispatches', 'micro_status')) {
                    $table->string('micro_status', 32)->default('pending')->after('stage');
                }
                if (! Schema::hasColumn('lab_learning_lane_dispatches', 'micro_attempts')) {
                    $table->unsignedInteger('micro_attempts')->default(0)->after('micro_status');
                }
                if (! Schema::hasColumn('lab_learning_lane_dispatches', 'micro_completed_at')) {
                    $table->timestamp('micro_completed_at')->nullable()->after('micro_attempts');
                }
                if (! Schema::hasColumn('lab_learning_lane_dispatches', 'micro_metadata')) {
                    $table->json('micro_metadata')->nullable()->after('micro_completed_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lab_learning_lane_dispatches')) {
            Schema::table('lab_learning_lane_dispatches', function (Blueprint $table): void {
                foreach (['micro_metadata', 'micro_completed_at', 'micro_attempts', 'micro_status', 'stage'] as $column) {
                    if (Schema::hasColumn('lab_learning_lane_dispatches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('lab_gene_interactions');
        Schema::dropIfExists('lab_council_disagreements');
        Schema::dropIfExists('lab_failure_dojo_runs');
        Schema::dropIfExists('lab_learning_memories');
    }
};
