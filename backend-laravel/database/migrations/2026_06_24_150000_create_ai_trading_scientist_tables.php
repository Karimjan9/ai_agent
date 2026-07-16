<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_hypotheses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('strategy');
            $table->string('symbol')->default('XAUUSD');
            $table->string('timeframe')->default('H1');
            $table->string('decision')->default('BUY');
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->string('market_regime')->nullable();
            $table->string('volatility_regime')->nullable();
            $table->text('hypothesis');
            $table->json('measurable_target')->nullable();
            $table->unsignedSmallInteger('horizon_candles')->default(20);
            $table->decimal('expected_move_atr', 8, 2)->default(1.50);
            $table->json('actual_outcome')->nullable();
            $table->string('status')->default('pending');
            $table->text('evaluation_summary')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'status']);
            $table->index(['training_session_id', 'strategy']);
        });

        Schema::create('agent_beliefs', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy');
            $table->string('belief_key');
            $table->string('belief_label');
            $table->decimal('score', 6, 2)->default(50);
            $table->unsignedInteger('sample_size')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('confidence_interval_low', 6, 2)->nullable();
            $table->decimal('confidence_interval_high', 6, 2)->nullable();
            $table->string('regime')->nullable();
            $table->timestamp('last_evidence_at')->nullable();
            $table->text('evidence_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['strategy', 'belief_key', 'regime']);
            $table->index(['belief_key', 'score']);
        });

        Schema::create('scientist_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->json('observations')->nullable();
            $table->string('most_failed_hypothesis')->nullable();
            $table->text('conclusion');
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique('training_session_id');
        });

        Schema::create('knowledge_facts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('fact');
            $table->json('scope')->nullable();
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->unsignedInteger('evidence_count')->default(1);
            $table->string('status')->default('provisional');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'confidence_score']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('counterfactual_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_hypothesis_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scenario_name');
            $table->json('intervention');
            $table->json('baseline_result');
            $table->json('alternative_result');
            $table->decimal('delta_percent', 10, 2)->default(0);
            $table->string('verdict')->default('neutral');
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index(['scenario_name', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterfactual_runs');
        Schema::dropIfExists('knowledge_facts');
        Schema::dropIfExists('scientist_journals');
        Schema::dropIfExists('agent_beliefs');
        Schema::dropIfExists('agent_hypotheses');
    }
};
