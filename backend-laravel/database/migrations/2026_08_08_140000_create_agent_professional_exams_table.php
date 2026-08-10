<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only professional exams keep learning evidence separate from
     * the immutable forward/paper promotion ledger.  An exam may teach or
     * quarantine an agent, but it can never grant promotion by itself.
     */
    public function up(): void
    {
        Schema::create('agent_professional_exams', function (Blueprint $table): void {
            $table->id();
            $table->uuid('exam_id')->unique();
            $table->string('exam_hash', 128)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('parent_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('exam_type', 64);
            $table->string('status', 32)->default('unassessed');
            $table->string('challenge_version', 64)->nullable();
            $table->string('state_cluster_id', 128)->nullable();
            $table->string('challenge_digest', 128)->nullable();
            $table->json('metrics')->nullable();
            $table->json('evidence')->nullable();
            $table->json('source_run_ids')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('observed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'strategy_family', 'exam_type', 'status'], 'agent_exam_scope_type_status_idx');
            $table->index(['state_cluster_id', 'exam_type', 'status'], 'agent_exam_cluster_type_status_idx');
            $table->index(['expires_at', 'status'], 'agent_exam_expiry_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_professional_exams');
    }
};
