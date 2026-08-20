<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frozen_paper_windows', function (Blueprint $table): void {
            $table->string('window_key', 64)->default('rolling_6m_v1')->after('timeframe');
        });

        Schema::table('frozen_paper_windows', function (Blueprint $table): void {
            $table->dropUnique('frozen_paper_window_identity');
            $table->unique(
                ['dataset_key', 'provider', 'symbol', 'timeframe', 'window_key'],
                'frozen_paper_window_identity',
            );
        });
    }

    public function down(): void
    {
        Schema::table('frozen_paper_windows', function (Blueprint $table): void {
            $table->dropUnique('frozen_paper_window_identity');
            $table->dropColumn('window_key');
            $table->unique(
                ['dataset_key', 'provider', 'symbol', 'timeframe'],
                'frozen_paper_window_identity',
            );
        });
    }
};
