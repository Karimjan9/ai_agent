<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_laboratories', function (Blueprint $table): void {
            $table->dropUnique('ai_laboratories_symbol_unique');
            $table->unique(['symbol', 'timeframe'], 'ai_laboratories_symbol_timeframe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_laboratories', function (Blueprint $table): void {
            $table->dropUnique('ai_laboratories_symbol_timeframe_unique');
            $table->unique('symbol', 'ai_laboratories_symbol_unique');
        });
    }
};
