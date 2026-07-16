<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_laboratories', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16)->unique();
            $table->string('name', 64);
            $table->string('timeframe', 16)->default('H1');
            $table->json('strategy_families');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lab_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_laboratory_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->string('trigger_type')->default('new_data');
            $table->json('trigger_context')->nullable();
            $table->string('data_fingerprint')->nullable();
            $table->unsignedInteger('population_size')->default(20);
            $table->string('status')->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['ai_laboratory_id', 'generation']);
        });

        Schema::create('lab_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_a_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('parent_b_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('origin', 24);
            $table->string('lifecycle_status')->default('draft');
            $table->json('parameter_diff')->nullable();
            $table->decimal('train_score', 8, 2)->nullable();
            $table->decimal('validation_score', 8, 2)->nullable();
            $table->decimal('forward_score', 8, 2)->nullable();
            $table->decimal('champion_improvement', 8, 2)->nullable();
            $table->unsignedInteger('rolling_wins')->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('profit_factor', 8, 2)->nullable();
            $table->decimal('max_drawdown', 8, 2)->nullable();
            $table->decimal('risk_of_ruin', 8, 2)->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->unique(['lab_generation_id', 'model_version_id']);
            $table->index(['symbol', 'timeframe', 'strategy_family', 'lifecycle_status'], 'lab_agents_market_lifecycle');
        });

        Schema::create('mutation_memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('parameter_key', 64);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->decimal('forward_delta', 8, 2);
            $table->string('market_regime')->nullable();
            $table->string('outcome', 16);
            $table->decimal('confidence', 5, 2)->default(50);
            $table->text('decision')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'strategy_family', 'parameter_key', 'outcome'], 'mutation_memory_lookup');
        });

        Schema::create('paper_trading_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('sample_count')->default(0);
            $table->decimal('profit_factor', 8, 2)->default(0);
            $table->decimal('max_drawdown', 8, 2)->default(0);
            $table->decimal('net_profit_percent', 8, 2)->default(0);
            $table->json('metrics')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('model_market_performance', function (Blueprint $table): void {
            $table->string('paper_status', 16)->default('pending')->after('status');
            $table->unsignedInteger('paper_sample_count')->default(0)->after('sample_count');
            $table->decimal('paper_profit_factor', 8, 2)->default(0)->after('paper_sample_count');
            $table->decimal('paper_max_drawdown', 8, 2)->default(0)->after('paper_profit_factor');
        });
    }

    public function down(): void
    {
        Schema::table('model_market_performance', function (Blueprint $table): void {
            $table->dropColumn(['paper_status', 'paper_sample_count', 'paper_profit_factor', 'paper_max_drawdown']);
        });
        Schema::dropIfExists('paper_trading_evaluations');
        Schema::dropIfExists('mutation_memories');
        Schema::dropIfExists('lab_agents');
        Schema::dropIfExists('lab_generations');
        Schema::dropIfExists('ai_laboratories');
    }
};
