<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategy_scores') || Schema::hasColumn('strategy_scores', 'parameters')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table) {
            $table->json('parameters')->nullable()->after('strategy');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('strategy_scores') || ! Schema::hasColumn('strategy_scores', 'parameters')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table) {
            $table->dropColumn('parameters');
        });
    }
};
