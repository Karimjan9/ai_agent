<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cooperative_module_species_members')) {
            return;
        }

        Schema::table('cooperative_module_species_members', function (Blueprint $table): void {
            // Settlement uses explicit local-observation states such as
            // `beneficial_local_observation`; VARCHAR(24) truncated those
            // truthful outcomes after replay had already completed.
            $table->string('status', 48)->default('research')->change();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive. Once a 25+ character evidence state
        // exists, shrinking this column would either fail or corrupt history.
    }
};
