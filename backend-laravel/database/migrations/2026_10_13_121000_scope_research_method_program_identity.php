<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exact program_key remains unique. The legacy semantic lookup remains
        // in its compiler; bounded research needs fresh data/baseline exports.
        Schema::table('research_instrument_programs', function (Blueprint $table) {
            $table->dropUnique('research_instrument_ast_scope_uq');
            $table->index(['symbol', 'timeframe', 'ast_hash'], 'research_instrument_ast_scope_lookup');
        });
    }

    public function down(): void
    {
        if (DB::table('research_instrument_programs')->select('symbol', 'timeframe', 'ast_hash')
            ->groupBy('symbol', 'timeframe', 'ast_hash')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Research scope rollback would discard distinct frozen programs.');
        }
        Schema::table('research_instrument_programs', function (Blueprint $table) {
            $table->dropIndex('research_instrument_ast_scope_lookup');
            $table->unique(['symbol', 'timeframe', 'ast_hash'], 'research_instrument_ast_scope_uq');
        });
    }
};
