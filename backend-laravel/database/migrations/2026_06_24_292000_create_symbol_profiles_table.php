<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_symbols')) {
            Schema::table('market_symbols', function (Blueprint $table): void {
                if (! Schema::hasColumn('market_symbols', 'category')) {
                    $table->string('category')->default('forex')->after('market_type');
                }
                if (! Schema::hasColumn('market_symbols', 'priority')) {
                    $table->unsignedInteger('priority')->default(100)->after('category');
                }
                if (! Schema::hasColumn('market_symbols', 'settings')) {
                    $table->json('settings')->nullable()->after('priority');
                }
            });
        }

        Schema::create('symbol_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_symbol_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('timeframe')->nullable();
            $table->string('category')->default('forex');
            $table->string('best_session')->nullable();
            $table->string('worst_session')->nullable();
            $table->string('best_strategy')->nullable();
            $table->string('worst_strategy')->nullable();
            $table->string('current_regime')->nullable();
            $table->decimal('news_sensitivity_score', 6, 2)->default(50);
            $table->decimal('volatility_profile_score', 6, 2)->default(50);
            $table->decimal('trend_cleanliness_score', 6, 2)->default(50);
            $table->decimal('winrate', 6, 2)->default(0);
            $table->decimal('profit_factor', 8, 2)->default(0);
            $table->unsignedInteger('signals_count')->default(0);
            $table->unsignedInteger('paper_trades_count')->default(0);
            $table->unsignedInteger('observations_count')->default(0);
            $table->decimal('confidence_score', 6, 2)->default(50);
            $table->text('summary')->nullable();
            $table->json('session_stats')->nullable();
            $table->json('strategy_stats')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'timeframe']);
            $table->index(['category', 'confidence_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbol_profiles');

        if (Schema::hasTable('market_symbols')) {
            Schema::table('market_symbols', function (Blueprint $table): void {
                foreach (['settings', 'priority', 'category'] as $column) {
                    if (Schema::hasColumn('market_symbols', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
