<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_state_snapshots', function (Blueprint $table): void {
            // Prevent MySQL from automatically replacing an historical candle
            // time when Eloquent updates the snapshot's analytical fields.
            $table->dateTime('time')->change();
        });
    }

    public function down(): void
    {
        Schema::table('market_state_snapshots', function (Blueprint $table): void {
            $table->timestamp('time')->change();
        });
    }
};
