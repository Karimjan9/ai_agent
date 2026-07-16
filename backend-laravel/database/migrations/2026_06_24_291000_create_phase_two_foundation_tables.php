<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type');
            $table->string('event_key', 220)->nullable()->unique();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('agent')->nullable();
            $table->string('symbol')->nullable();
            $table->string('timeframe')->nullable();
            $table->foreignId('market_state_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity')->default('info');
            $table->text('summary');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['agent', 'event_type']);
            $table->index(['symbol', 'timeframe', 'occurred_at']);
        });

        Schema::create('service_health_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('service_key');
            $table->string('service_label');
            $table->string('status')->default('unknown');
            $table->decimal('health_score', 6, 2)->default(50);
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('stale_after_seconds')->default(300);
            $table->text('message')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique('service_key');
            $table->index(['status', 'health_score']);
        });

        Schema::create('signal_market_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('signal_type')->default('paper_candidate');
            $table->string('signal_key', 220)->nullable()->unique();
            $table->string('strategy');
            $table->string('symbol');
            $table->string('timeframe');
            $table->string('signal')->default('WAIT');
            $table->decimal('confidence', 6, 2)->default(50);
            $table->foreignId('market_state_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('market_species')->nullable();
            $table->decimal('trend_score', 6, 2)->default(0);
            $table->decimal('volatility_score', 6, 2)->default(0);
            $table->decimal('liquidity_score', 6, 2)->default(0);
            $table->decimal('momentum_score', 6, 2)->default(0);
            $table->decimal('memory_match_score', 6, 2)->default(0);
            $table->json('snapshot')->nullable();
            $table->text('hypothesis')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'symbol', 'timeframe']);
            $table->index(['signal', 'confidence']);
        });

        Schema::create('agent_memory_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_memory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_market_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy');
            $table->string('symbol')->nullable();
            $table->string('timeframe')->nullable();
            $table->decimal('similarity_score', 6, 2)->default(0);
            $table->text('lesson')->nullable();
            $table->json('match_context')->nullable();
            $table->timestamps();

            $table->index(['strategy', 'similarity_score']);
        });

        if (Schema::hasTable('agent_memories')) {
            Schema::table('agent_memories', function (Blueprint $table): void {
                if (! Schema::hasColumn('agent_memories', 'market_species')) {
                    $table->string('market_species')->nullable()->after('volatility_regime');
                }
                if (! Schema::hasColumn('agent_memories', 'outcome')) {
                    $table->string('outcome')->nullable()->after('market_species');
                }
                if (! Schema::hasColumn('agent_memories', 'confidence_score')) {
                    $table->decimal('confidence_score', 6, 2)->default(50)->after('strength');
                }
                if (! Schema::hasColumn('agent_memories', 'last_matched_at')) {
                    $table->timestamp('last_matched_at')->nullable()->after('confidence_score');
                }
                if (! Schema::hasColumn('agent_memories', 'occurrences')) {
                    $table->unsignedInteger('occurrences')->default(1)->after('last_matched_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_memories')) {
            Schema::table('agent_memories', function (Blueprint $table): void {
                foreach (['occurrences', 'last_matched_at', 'confidence_score', 'outcome', 'market_species'] as $column) {
                    if (Schema::hasColumn('agent_memories', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('agent_memory_matches');
        Schema::dropIfExists('signal_market_snapshots');
        Schema::dropIfExists('service_health_checks');
        Schema::dropIfExists('system_events');
    }
};
