<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_capital_accounts', function (Blueprint $table): void {
            // Existing observed accounts cannot reconstruct an unrecorded peak.
            $table->bigInteger('peak_equity_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paper_capital_accounts', function (Blueprint $table): void {
            $table->dropColumn('peak_equity_cents');
        });
    }
};
