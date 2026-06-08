<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candles', function (Blueprint $table): void {
            if (! Schema::hasColumn('candles', 'provider')) {
                $table->string('provider')->nullable()->after('volume');
            }
        });
    }

    public function down(): void
    {
        Schema::table('candles', function (Blueprint $table): void {
            if (Schema::hasColumn('candles', 'provider')) {
                $table->dropColumn('provider');
            }
        });
    }
};
