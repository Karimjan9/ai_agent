<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            if (! Schema::hasColumn('trades', 'market_regime')) {
                $table->string('market_regime')->nullable()->after('result');
            }

            if (! Schema::hasColumn('trades', 'volatility_regime')) {
                $table->string('volatility_regime')->nullable()->after('market_regime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            if (Schema::hasColumn('trades', 'volatility_regime')) {
                $table->dropColumn('volatility_regime');
            }

            if (Schema::hasColumn('trades', 'market_regime')) {
                $table->dropColumn('market_regime');
            }
        });
    }
};
