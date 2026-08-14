<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mtf_ablation_runs') && ! Schema::hasColumn('mtf_ablation_runs', 'snapshot_reference')) {
            Schema::table('mtf_ablation_runs', function (Blueprint $table): void {
                $table->json('snapshot_reference')->nullable()->after('variants');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mtf_ablation_runs') && Schema::hasColumn('mtf_ablation_runs', 'snapshot_reference')) {
            Schema::table('mtf_ablation_runs', function (Blueprint $table): void {
                $table->dropColumn('snapshot_reference');
            });
        }
    }
};
