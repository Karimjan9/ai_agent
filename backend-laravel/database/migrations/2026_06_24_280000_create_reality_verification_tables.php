<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reality_verification_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_scored')->default(0);
            $table->unsignedInteger('certified_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('cemetery_count')->default(0);
            $table->unsignedInteger('skeptic_reports_count')->default(0);
            $table->text('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('reality_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reality_verification_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_layer')->default('knowledge');
            $table->string('source_title');
            $table->decimal('original_confidence', 6, 2)->default(0);
            $table->decimal('reality_score', 6, 2)->default(0);
            $table->decimal('evidence_score', 6, 2)->default(0);
            $table->decimal('drift_score', 6, 2)->default(0);
            $table->decimal('false_discovery_risk', 6, 2)->default(0);
            $table->string('validation_status')->default('draft');
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('live_sample_count')->default(0);
            $table->unsignedInteger('paper_sample_count')->default(0);
            $table->unsignedInteger('backtest_sample_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->text('rationale')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'reality_scores_source_unique');
            $table->index(['validation_status', 'reality_score'], 'reality_scores_status_score_idx');
        });

        Schema::create('reality_validation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reality_score_id')->constrained()->cascadeOnDelete();
            $table->string('event_type')->default('verified');
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->decimal('previous_reality_score', 6, 2)->nullable();
            $table->decimal('new_reality_score', 6, 2)->nullable();
            $table->text('evidence_summary');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('reality_experiments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reality_score_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('experiment_key', 220)->unique();
            $table->string('title');
            $table->string('mode')->default('paper_trading');
            $table->string('status')->default('observing');
            $table->unsignedInteger('planned_samples')->default(100);
            $table->unsignedInteger('observed_samples')->default(0);
            $table->decimal('success_rate', 6, 2)->default(0);
            $table->decimal('confidence_score', 6, 2)->default(0);
            $table->text('hypothesis');
            $table->json('success_criteria')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_cemetery_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reality_score_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('title');
            $table->string('failure_reason')->default('reality_failed');
            $table->decimal('original_confidence', 6, 2)->default(0);
            $table->decimal('final_reality_score', 6, 2)->default(0);
            $table->string('status')->default('archived');
            $table->timestamp('failed_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'knowledge_cemetery_source_unique');
        });

        Schema::create('skeptic_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reality_score_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('report_key', 220)->unique();
            $table->string('verdict')->default('needs_validation');
            $table->decimal('false_discovery_risk', 6, 2)->default(0);
            $table->text('objections');
            $table->text('suggested_tests');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('certified_knowledge_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reality_score_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('certificate_key', 220)->unique();
            $table->string('title');
            $table->string('grade')->default('validated');
            $table->decimal('reality_score', 6, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('evidence_summary');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certified_knowledge_items');
        Schema::dropIfExists('skeptic_reports');
        Schema::dropIfExists('knowledge_cemetery_entries');
        Schema::dropIfExists('reality_experiments');
        Schema::dropIfExists('reality_validation_events');
        Schema::dropIfExists('reality_scores');
        Schema::dropIfExists('reality_verification_runs');
    }
};
