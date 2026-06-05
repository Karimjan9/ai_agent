<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_sessions')) {
            Schema::table('training_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('training_sessions', 'title')) {
                    $table->string('title')->nullable()->after('id');
                }
                if (! Schema::hasColumn('training_sessions', 'symbol')) {
                    $table->string('symbol')->nullable()->after('title');
                }
                if (! Schema::hasColumn('training_sessions', 'timeframe')) {
                    $table->string('timeframe')->nullable()->after('symbol');
                }
                if (! Schema::hasColumn('training_sessions', 'agents_count')) {
                    $table->integer('agents_count')->default(0)->after('timeframe');
                }
                if (! Schema::hasColumn('training_sessions', 'best_strategy')) {
                    $table->string('best_strategy')->nullable()->after('agents_count');
                }
                if (! Schema::hasColumn('training_sessions', 'best_score')) {
                    $table->integer('best_score')->default(0)->after('best_strategy');
                }
                if (! Schema::hasColumn('training_sessions', 'worst_strategy')) {
                    $table->string('worst_strategy')->nullable()->after('best_score');
                }
                if (! Schema::hasColumn('training_sessions', 'worst_score')) {
                    $table->integer('worst_score')->default(0)->after('worst_strategy');
                }
                if (! Schema::hasColumn('training_sessions', 'total_trades')) {
                    $table->integer('total_trades')->default(0)->after('worst_score');
                }
                if (! Schema::hasColumn('training_sessions', 'average_winrate')) {
                    $table->decimal('average_winrate', 8, 2)->default(0)->after('total_trades');
                }
                if (! Schema::hasColumn('training_sessions', 'average_profit')) {
                    $table->decimal('average_profit', 8, 2)->default(0)->after('average_winrate');
                }
                if (! Schema::hasColumn('training_sessions', 'ai_conclusion')) {
                    $table->longText('ai_conclusion')->nullable()->after('average_profit');
                }
                if (! Schema::hasColumn('training_sessions', 'next_training_plan')) {
                    $table->longText('next_training_plan')->nullable()->after('ai_conclusion');
                }
                if (! Schema::hasColumn('training_sessions', 'raw_leaderboard')) {
                    $table->json('raw_leaderboard')->nullable()->after('next_training_plan');
                }
            });
        }

        if (Schema::hasTable('model_versions')) {
            Schema::table('model_versions', function (Blueprint $table) {
                if (! Schema::hasColumn('model_versions', 'strategy')) {
                    $table->string('strategy')->nullable()->after('id');
                }
                if (! Schema::hasColumn('model_versions', 'version')) {
                    $table->string('version')->nullable()->after('strategy');
                }
                if (! Schema::hasColumn('model_versions', 'generation')) {
                    $table->integer('generation')->default(1)->after('version');
                }
                if (! Schema::hasColumn('model_versions', 'best_score')) {
                    $table->decimal('best_score', 8, 2)->default(0)->after('status');
                }
                if (! Schema::hasColumn('model_versions', 'best_winrate')) {
                    $table->decimal('best_winrate', 8, 2)->default(0)->after('best_score');
                }
                if (! Schema::hasColumn('model_versions', 'best_profit')) {
                    $table->decimal('best_profit', 8, 2)->default(0)->after('best_winrate');
                }
                if (! Schema::hasColumn('model_versions', 'best_drawdown')) {
                    $table->decimal('best_drawdown', 8, 2)->nullable()->after('best_profit');
                }
                if (! Schema::hasColumn('model_versions', 'description')) {
                    $table->longText('description')->nullable()->after('best_drawdown');
                }
                if (! Schema::hasColumn('model_versions', 'change_log')) {
                    $table->longText('change_log')->nullable()->after('description');
                }
                if (! Schema::hasColumn('model_versions', 'parameters')) {
                    $table->json('parameters')->nullable()->after('change_log');
                }
                if (! Schema::hasColumn('model_versions', 'promoted_at')) {
                    $table->timestamp('promoted_at')->nullable()->after('parameters');
                }
            });
        }

        if (Schema::hasTable('strategy_scores') && ! Schema::hasColumn('strategy_scores', 'training_session_id')) {
            Schema::table('strategy_scores', function (Blueprint $table) {
                $table->foreignId('training_session_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        //
    }
};
