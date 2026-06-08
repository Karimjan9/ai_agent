<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_sessions')) {
            return;
        }

        Schema::table('training_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('training_sessions', 'average_drawdown')) {
                $table->decimal('average_drawdown', 8, 2)->default(0)->after('average_profit');
            }
            if (! Schema::hasColumn('training_sessions', 'average_profit_factor')) {
                $table->decimal('average_profit_factor', 8, 2)->default(0)->after('average_drawdown');
            }
            if (! Schema::hasColumn('training_sessions', 'average_stability_score')) {
                $table->integer('average_stability_score')->default(0)->after('average_profit_factor');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('training_sessions')) {
            return;
        }

        Schema::table('training_sessions', function (Blueprint $table) {
            foreach (['average_drawdown', 'average_profit_factor', 'average_stability_score'] as $column) {
                if (Schema::hasColumn('training_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
