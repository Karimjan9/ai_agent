<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->decimal('knowledge_health_score', 6, 2)->default(50);
            $table->unsignedInteger('audited_claims')->default(0);
            $table->unsignedInteger('decayed_beliefs')->default(0);
            $table->unsignedInteger('contradictions_found')->default(0);
            $table->unsignedInteger('unknown_zones_found')->default(0);
            $table->unsignedInteger('blind_spots_found')->default(0);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audit_type')->default('confidence_review');
            $table->decimal('original_confidence', 6, 2)->default(50);
            $table->decimal('audited_confidence', 6, 2)->default(50);
            $table->decimal('decay_amount', 6, 2)->default(0);
            $table->string('verdict')->default('stable');
            $table->string('recommended_action')->default('monitor');
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['verdict', 'audited_confidence']);
        });

        Schema::create('belief_decay_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_belief_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->string('belief_key');
            $table->decimal('original_score', 6, 2)->default(50);
            $table->decimal('decayed_score', 6, 2)->default(50);
            $table->decimal('decay_amount', 6, 2)->default(0);
            $table->string('reason_code')->default('aging');
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_contradictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_a_id')->nullable()->constrained('knowledge_claims')->nullOnDelete();
            $table->foreignId('claim_b_id')->nullable()->constrained('knowledge_claims')->nullOnDelete();
            $table->string('contradiction_type')->default('directional_conflict');
            $table->decimal('severity_score', 6, 2)->default(50);
            $table->string('status')->default('open');
            $table->text('summary');
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity_score']);
        });

        Schema::create('unknown_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->string('symbol')->nullable();
            $table->string('timeframe')->nullable();
            $table->string('market_state')->nullable();
            $table->string('market_species')->nullable();
            $table->decimal('similarity_score', 6, 2)->default(0);
            $table->decimal('uncertainty_score', 6, 2)->default(50);
            $table->string('status')->default('open');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('blind_spots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->string('spot_key');
            $table->string('label');
            $table->decimal('priority_score', 6, 2)->default(50);
            $table->string('status')->default('open');
            $table->text('reason');
            $table->json('coverage')->nullable();
            $table->json('suggested_research')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority_score']);
        });

        Schema::create('knowledge_health_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->decimal('overall_score', 6, 2)->default(50);
            $table->decimal('fresh_discoveries_score', 6, 2)->default(50);
            $table->decimal('aging_discoveries_score', 6, 2)->default(0);
            $table->decimal('contradiction_score', 6, 2)->default(0);
            $table->decimal('unknown_zone_score', 6, 2)->default(0);
            $table->decimal('blind_spot_score', 6, 2)->default(0);
            $table->json('components')->nullable();
            $table->timestamps();
        });

        Schema::create('self_critiques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_audit_run_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('critique');
            $table->text('evidence_summary')->nullable();
            $table->text('recommended_action')->nullable();
            $table->decimal('severity_score', 6, 2)->default(50);
            $table->string('status')->default('open');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_critiques');
        Schema::dropIfExists('knowledge_health_scores');
        Schema::dropIfExists('blind_spots');
        Schema::dropIfExists('unknown_zones');
        Schema::dropIfExists('knowledge_contradictions');
        Schema::dropIfExists('belief_decay_events');
        Schema::dropIfExists('knowledge_audits');
        Schema::dropIfExists('meta_audit_runs');
    }
};
