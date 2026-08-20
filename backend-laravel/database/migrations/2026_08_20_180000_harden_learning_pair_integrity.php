<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_learning_lane_pairs')) {
            return;
        }

        Schema::table('lab_learning_lane_pairs', function (Blueprint $table): void {
            if (! Schema::hasColumn('lab_learning_lane_pairs', 'candidate_data_hash')) {
                $table->string('candidate_data_hash', 160)->nullable()->after('control_evidence_run_id');
            }
            if (! Schema::hasColumn('lab_learning_lane_pairs', 'control_data_hash')) {
                $table->string('control_data_hash', 160)->nullable()->after('candidate_data_hash');
            }
            if (! Schema::hasColumn('lab_learning_lane_pairs', 'candidate_execution_hash')) {
                $table->string('candidate_execution_hash', 160)->nullable()->after('control_data_hash');
            }
            if (! Schema::hasColumn('lab_learning_lane_pairs', 'control_execution_hash')) {
                $table->string('control_execution_hash', 160)->nullable()->after('candidate_execution_hash');
            }
            if (! Schema::hasColumn('lab_learning_lane_pairs', 'pair_integrity_status')) {
                $table->string('pair_integrity_status', 32)->default('unverified')->after('control_execution_hash');
            }
            if (! Schema::hasColumn('lab_learning_lane_pairs', 'same_generation')) {
                $table->boolean('same_generation')->default(false)->after('pair_integrity_status');
            }

            $table->index(['lab_generation_id', 'pair_integrity_status'], 'lab_learning_pair_integrity_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lab_learning_lane_pairs')) {
            return;
        }

        Schema::table('lab_learning_lane_pairs', function (Blueprint $table): void {
            if (Schema::hasIndex('lab_learning_lane_pairs', 'lab_learning_pair_integrity_idx')) {
                $table->dropIndex('lab_learning_pair_integrity_idx');
            }
            foreach ([
                'same_generation', 'pair_integrity_status', 'control_execution_hash',
                'candidate_execution_hash', 'control_data_hash', 'candidate_data_hash',
            ] as $column) {
                if (Schema::hasColumn('lab_learning_lane_pairs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
