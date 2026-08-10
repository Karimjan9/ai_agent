<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backtest_runs')) {
            return;
        }

        Schema::table('backtest_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('backtest_runs', 'status')) {
                $table->string('status', 32)->default('queued')->after('strategy');
            }
            if (! Schema::hasColumn('backtest_runs', 'request_payload')) {
                $table->json('request_payload')->nullable()->after('status');
            }
            if (! Schema::hasColumn('backtest_runs', 'metrics')) {
                $table->json('metrics')->nullable()->after('raw_result');
            }
            if (! Schema::hasColumn('backtest_runs', 'started_at')) {
                $table->dateTime('started_at')->nullable()->after('metrics');
            }
            if (! Schema::hasColumn('backtest_runs', 'finished_at')) {
                $table->dateTime('finished_at')->nullable()->after('started_at');
            }
            $table->index(['status', 'created_at'], 'backtest_runs_status_created_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('backtest_runs')) {
            return;
        }

        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->dropIndex('backtest_runs_status_created_idx');
            foreach (['finished_at', 'started_at', 'metrics', 'request_payload', 'status'] as $column) {
                if (Schema::hasColumn('backtest_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
