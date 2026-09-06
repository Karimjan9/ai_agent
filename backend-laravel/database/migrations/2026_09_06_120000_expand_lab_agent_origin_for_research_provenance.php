<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct causal-replay cohorts retain their full provenance origin, for
     * example `skill_cartridge_transplant`. The old VARCHAR(24) truncates
     * that auditable identity and causes the scheduler job to fail.
     */
    public function up(): void
    {
        Schema::table('lab_agents', function (Blueprint $table): void {
            $table->string('origin', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('lab_agents', function (Blueprint $table): void {
            $table->string('origin', 24)->change();
        });
    }
};
