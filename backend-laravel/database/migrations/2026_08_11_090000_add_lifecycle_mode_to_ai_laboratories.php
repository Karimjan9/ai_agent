<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_laboratories')) return;

        Schema::table('ai_laboratories', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_laboratories', 'lifecycle_mode')) {
                $table->string('lifecycle_mode', 24)->default('shadow')->after('is_active');
            }
        });

        DB::table('ai_laboratories')->update(['lifecycle_mode' => 'shadow']);
        DB::table('ai_laboratories')
            ->where('symbol', 'XAUUSD')
            ->where('timeframe', 'H1')
            ->update(['lifecycle_mode' => 'lighthouse']);
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_laboratories') && Schema::hasColumn('ai_laboratories', 'lifecycle_mode')) {
            Schema::table('ai_laboratories', fn (Blueprint $table): mixed => $table->dropColumn('lifecycle_mode'));
        }
    }
};
