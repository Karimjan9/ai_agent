<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shadow_veto_observations', function (Blueprint $table): void {
            $table->string('spread_context', 32)->nullable()->after('volatility_regime');
            $table->decimal('p_allow', 8, 6)->nullable()->after('shadow_profit_percent');
            $table->decimal('p_veto', 8, 6)->nullable()->after('p_allow');
            $table->boolean('exploration_assigned')->default(false)->after('p_veto');
        });
        Schema::create('veto_policy_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->constrained()->cascadeOnDelete();
            $table->string('veto_reason', 64); $table->string('context_key', 160);
            $table->unsignedInteger('sample_count')->default(0); $table->unsignedInteger('calendar_windows')->default(0);
            $table->decimal('doubly_robust_value', 12, 6)->nullable(); $table->decimal('lower_confidence_bound', 12, 6)->nullable();
            $table->string('status', 48); $table->string('recommended_action', 64); $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['lab_agent_id', 'veto_reason', 'context_key'], 'vpe_agent_veto_context_uq');
            $table->index(['veto_reason', 'status'], 'vpe_veto_status_idx');
        });
        Schema::create('regime_reservoir_entries', function (Blueprint $table): void {
            $table->id(); $table->string('symbol', 16); $table->string('timeframe', 8);
            $table->string('regime', 32); $table->string('volatility_regime', 32)->nullable();
            $table->string('state_signature', 64); $table->foreignId('adapter_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->json('performance_posterior'); $table->json('known_failures')->nullable();
            $table->decimal('recovery_quality', 8, 3)->default(0); $table->timestamp('last_seen_at')->nullable(); $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'regime', 'volatility_regime', 'state_signature'], 'reservoir_state_uq');
            $table->index(['symbol', 'timeframe', 'regime'], 'reservoir_lookup_idx');
        });
        Schema::create('evaluator_reputations', function (Blueprint $table): void {
            $table->id(); $table->string('validator', 64)->unique(); $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedInteger('forward_confirmed_count')->default(0); $table->unsignedInteger('false_positive_count')->default(0);
            $table->decimal('reputation_score', 8, 3)->default(0); $table->string('passport_status', 48);
            $table->json('evidence')->nullable(); $table->timestamps();
        });
        Schema::create('transfer_matrix_entries', function (Blueprint $table): void {
            $table->id(); $table->foreignId('model_version_id')->constrained()->cascadeOnDelete();
            $table->string('train_markets', 64); $table->string('test_market', 16); $table->string('test_scope', 32);
            $table->decimal('from_scratch_score', 10, 3)->nullable(); $table->decimal('transferred_score', 10, 3)->nullable();
            $table->decimal('transfer_gain', 10, 3)->nullable(); $table->unsignedInteger('adaptation_steps')->nullable();
            $table->decimal('source_regression', 10, 3)->nullable(); $table->string('status', 48); $table->json('evidence')->nullable(); $table->timestamps();
            $table->unique(['model_version_id', 'train_markets', 'test_market', 'test_scope'], 'transfer_matrix_case_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_matrix_entries'); Schema::dropIfExists('evaluator_reputations');
        Schema::dropIfExists('regime_reservoir_entries'); Schema::dropIfExists('veto_policy_evaluations');
        Schema::table('shadow_veto_observations', function (Blueprint $table): void {
            $table->dropColumn(['spread_context', 'p_allow', 'p_veto', 'exploration_assigned']);
        });
    }
};
