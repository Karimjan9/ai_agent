<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lab_learning_lane_pairs')) {
            Schema::table('lab_learning_lane_pairs', function (Blueprint $table): void {
                $table->index(['symbol', 'timeframe', 'control_agent_id'], 'lab_learning_pair_control_scope_idx');
                $table->index(['lab_generation_id', 'status'], 'lab_learning_pair_generation_status_idx');
            });
        }
        if (Schema::hasTable('lab_failure_dojo_runs')) {
            Schema::table('lab_failure_dojo_runs', function (Blueprint $table): void {
                $table->index(['symbol', 'timeframe', 'pair_id', 'status'], 'lab_dojo_pair_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lab_learning_lane_pairs')) {
            Schema::table('lab_learning_lane_pairs', function (Blueprint $table): void {
                $table->dropIndex('lab_learning_pair_control_scope_idx');
                $table->dropIndex('lab_learning_pair_generation_status_idx');
            });
        }
        if (Schema::hasTable('lab_failure_dojo_runs')) {
            Schema::table('lab_failure_dojo_runs', function (Blueprint $table): void {
                $table->dropIndex('lab_dojo_pair_status_idx');
            });
        }
    }
};
