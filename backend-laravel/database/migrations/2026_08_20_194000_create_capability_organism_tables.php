<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_causal_attributions', function (Blueprint $table): void {
            $table->id();
            $table->string('attribution_key', 160)->unique();
            $table->foreignId('paper_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paper_signal_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->string('primary_cause', 48);
            $table->json('contributions');
            $table->json('evidence');
            $table->timestamp('attributed_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'primary_cause'], 'capability_attribution_scope_idx');
        });

        Schema::create('capability_experiment_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 160)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lane', 24);
            $table->string('action', 64);
            $table->string('target_key', 160)->nullable();
            $table->string('changed_axis', 96)->nullable();
            $table->decimal('research_budget_percent', 10, 6)->default(0);
            $table->decimal('priority_score', 12, 6)->default(0);
            $table->string('status', 32)->default('shadow_only');
            $table->json('contract');
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['lane', 'status', 'decided_at'], 'capability_experiment_lane_idx');
        });

        Schema::create('capability_skills', function (Blueprint $table): void {
            $table->id();
            $table->string('skill_key', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('state_key', 160);
            $table->string('strategy_id', 128)->nullable();
            $table->string('tactic_id', 128)->nullable();
            $table->string('status', 32)->default('provisional');
            $table->string('data_hash', 128)->nullable();
            $table->string('execution_hash', 128)->nullable();
            $table->unsignedInteger('independent_windows')->default(0);
            $table->unsignedInteger('positive_windows')->default(0);
            $table->boolean('non_target_regression')->default(false);
            $table->boolean('independently_confirmed')->default(false);
            $table->json('contract');
            $table->json('evidence');
            $table->timestamp('compiled_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'state_key', 'status'], 'capability_skill_scope_idx');
        });

        Schema::create('capability_anti_skill_cemetery', function (Blueprint $table): void {
            $table->id();
            $table->string('cemetery_key', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('state_key', 160);
            $table->string('strategy_id', 128)->nullable();
            $table->string('tactic_id', 128)->nullable();
            $table->string('failure_mode', 96);
            $table->string('status', 32)->default('retry_with_new_hypothesis');
            $table->unsignedInteger('failures')->default(1);
            $table->json('evidence');
            $table->timestamp('buried_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'state_key', 'status'], 'capability_cemetery_scope_idx');
        });

        Schema::create('capability_progress_scoreboards', function (Blueprint $table): void {
            $table->id();
            $table->string('score_key', 160)->unique();
            $table->string('symbol', 16)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->decimal('progress_score', 12, 6)->default(0);
            $table->json('metrics');
            $table->timestamp('measured_at');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'measured_at'], 'capability_score_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_progress_scoreboards');
        Schema::dropIfExists('capability_anti_skill_cemetery');
        Schema::dropIfExists('capability_skills');
        Schema::dropIfExists('capability_experiment_decisions');
        Schema::dropIfExists('capability_causal_attributions');
    }
};
