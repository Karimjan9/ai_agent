<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_parent_candidate_preparations', function (Blueprint $table): void {
            $table->id();
            $table->string('preparation_key', 128)->unique();
            $table->foreignId('model_version_id')->constrained('model_versions')->cascadeOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 96);
            $table->string('council_role', 128)->nullable();
            $table->string('status', 32)->default('planned');
            $table->string('idea_type', 64);
            $table->json('idea');
            $table->json('required_evidence')->nullable();
            $table->json('source_metrics')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'status'], 'lab_parent_prep_scope_status_idx');
            $table->index(['model_version_id', 'idea_type'], 'lab_parent_prep_model_idea_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_parent_candidate_preparations');
    }
};
