<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategy_scores')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table): void {
            if (! Schema::hasColumn('strategy_scores', 'train_score')) {
                $table->decimal('train_score', 8, 2)->nullable()->after('score');
            }

            if (! Schema::hasColumn('strategy_scores', 'validation_score')) {
                $table->decimal('validation_score', 8, 2)->nullable()->after('train_score');
            }

            if (! Schema::hasColumn('strategy_scores', 'forward_score')) {
                $table->decimal('forward_score', 8, 2)->nullable()->after('validation_score');
            }

            if (! Schema::hasColumn('strategy_scores', 'robustness_score')) {
                $table->decimal('robustness_score', 8, 2)->nullable()->after('forward_score');
            }

            if (! Schema::hasColumn('strategy_scores', 'is_overfit')) {
                $table->boolean('is_overfit')->default(false)->after('robustness_score');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('strategy_scores')) {
            return;
        }

        Schema::table('strategy_scores', function (Blueprint $table): void {
            foreach ([
                'is_overfit',
                'robustness_score',
                'forward_score',
                'validation_score',
                'train_score',
            ] as $column) {
                if (Schema::hasColumn('strategy_scores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
