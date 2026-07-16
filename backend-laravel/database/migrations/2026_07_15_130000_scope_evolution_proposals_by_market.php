<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('evolution_proposals', 'symbol')) {
            Schema::table('evolution_proposals', function (Blueprint $table): void {
                $table->string('symbol', 16)->nullable()->after('strategy');
                $table->string('timeframe', 16)->nullable()->after('symbol');
                $table->string('strategy_family', 64)->nullable()->after('timeframe');
            });
        }

        // The original compound unique index was also the only index MySQL
        // could use for parent_model_version_id's foreign key. Add a dedicated
        // index before replacing it with the market-scoped unique key.
        Schema::table('evolution_proposals', function (Blueprint $table): void {
            $table->index('parent_model_version_id', 'evolution_proposals_parent_model_idx');
        });

        Schema::table('evolution_proposals', function (Blueprint $table): void {
            $table->dropUnique('evolution_proposals_one_open_unique');
            $table->unique(['parent_model_version_id', 'symbol', 'timeframe', 'proposed_version', 'open_status'], 'evolution_proposals_market_open_unique');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'status'], 'evolution_proposals_market_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table): void {
            $table->dropIndex('evolution_proposals_market_status_idx');
            $table->dropUnique('evolution_proposals_market_open_unique');
            $table->unique(['parent_model_version_id', 'proposed_version', 'open_status'], 'evolution_proposals_one_open_unique');
            $table->dropColumn(['symbol', 'timeframe', 'strategy_family']);
        });
    }
};
