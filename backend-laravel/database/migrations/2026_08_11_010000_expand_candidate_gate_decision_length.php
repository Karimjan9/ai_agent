<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gate decisions include explicit abstention states such as
     * insufficient_evidence; the original 16-character projection was only
     * large enough for passed/failed/waiting and turned a safe screening
     * abstention into a technical queue failure.
     */
    public function up(): void
    {
        if (Schema::hasTable('candidate_gate_decisions') && Schema::hasColumn('candidate_gate_decisions', 'decision')) {
            Schema::table('candidate_gate_decisions', function (Blueprint $table): void {
                $table->string('decision', 32)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('candidate_gate_decisions') && Schema::hasColumn('candidate_gate_decisions', 'decision')) {
            Schema::table('candidate_gate_decisions', function (Blueprint $table): void {
                $table->string('decision', 16)->change();
            });
        }
    }
};
