<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing causal, paper and descendant status codes are longer than
        // the original 32-character column. MySQL strict mode would reject
        // those otherwise-valid immutable credit receipts.
        Schema::table('lab_evolution_credit_events', function (Blueprint $table): void {
            $table->string('status', 64)->default('observed')->change();
        });
    }

    public function down(): void
    {
        // Do not truncate already-issued immutable credit receipts on rollback.
    }
};
