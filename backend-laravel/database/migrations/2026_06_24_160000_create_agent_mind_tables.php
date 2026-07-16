<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_psychology_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('strategy');
            $table->decimal('confidence', 6, 2)->default(50);
            $table->decimal('stress', 6, 2)->default(0);
            $table->decimal('trust', 6, 2)->default(50);
            $table->decimal('adaptation_pressure', 6, 2)->default(0);
            $table->decimal('stability', 6, 2)->default(50);
            $table->decimal('learning_rate', 8, 4)->default(0.0500);
            $table->string('state')->default('stable');
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'state']);
            $table->index(['training_session_id', 'strategy']);
        });

        Schema::create('agent_self_reflections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_psychology_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->text('reflection');
            $table->json('observations')->nullable();
            $table->text('suggested_action')->nullable();
            $table->decimal('stress', 6, 2)->default(0);
            $table->decimal('adaptation_pressure', 6, 2)->default(0);
            $table->timestamps();

            $table->index(['strategy', 'created_at']);
        });

        Schema::create('agent_memories', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy');
            $table->string('memory_type')->default('performance_event');
            $table->string('market_regime')->nullable();
            $table->string('volatility_regime')->nullable();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary');
            $table->text('lesson');
            $table->decimal('strength', 6, 2)->default(50);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'memory_type']);
            $table->index(['market_regime', 'volatility_regime']);
        });

        Schema::create('internal_debates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('symbol')->default('XAUUSD');
            $table->string('timeframe')->default('H1');
            $table->string('final_decision')->default('WAIT');
            $table->decimal('consensus_score', 6, 2)->default(50);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['training_session_id', 'final_decision']);
        });

        Schema::create('debate_arguments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_debate_id')->constrained()->cascadeOnDelete();
            $table->string('strategy');
            $table->string('stance');
            $table->decimal('confidence', 6, 2)->default(50);
            $table->text('argument');
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'stance']);
        });

        Schema::create('agent_reputations', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy')->unique();
            $table->decimal('reputation_score', 6, 2)->default(50);
            $table->decimal('stability_score', 6, 2)->default(50);
            $table->decimal('trust_score', 6, 2)->default(50);
            $table->decimal('calibration_score', 6, 2)->default(50);
            $table->decimal('survival_score', 6, 2)->default(50);
            $table->unsignedInteger('sessions_count')->default(0);
            $table->foreignId('last_training_session_id')->nullable()->constrained('training_sessions')->nullOnDelete();
            $table->json('reasons')->nullable();
            $table->timestamps();

            $table->index('reputation_score');
        });

        Schema::create('evolution_triggers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_score_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_psychology_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->string('trigger_type');
            $table->decimal('trigger_value', 6, 2);
            $table->decimal('threshold', 6, 2);
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'status']);
            $table->index(['trigger_type', 'trigger_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolution_triggers');
        Schema::dropIfExists('agent_reputations');
        Schema::dropIfExists('debate_arguments');
        Schema::dropIfExists('internal_debates');
        Schema::dropIfExists('agent_memories');
        Schema::dropIfExists('agent_self_reflections');
        Schema::dropIfExists('agent_psychology_snapshots');
    }
};
