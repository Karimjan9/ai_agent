<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_reports')) {
            return;
        }

        Schema::table('daily_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('daily_reports', 'source')) {
                $table->string('source', 64)->default('lab_evaluation_runs')->after('report_date');
            }
            if (! Schema::hasColumn('daily_reports', 'evidence_run_ids')) {
                $table->json('evidence_run_ids')->nullable()->after('source');
            }
            $table->index(['report_date', 'source'], 'daily_reports_date_source_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('daily_reports')) {
            return;
        }

        Schema::table('daily_reports', function (Blueprint $table): void {
            $table->dropIndex('daily_reports_date_source_idx');
            if (Schema::hasColumn('daily_reports', 'evidence_run_ids')) {
                $table->dropColumn('evidence_run_ids');
            }
            if (Schema::hasColumn('daily_reports', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
