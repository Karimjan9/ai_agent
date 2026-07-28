<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->json('gate_transition')->nullable()->after('decision');
        });
    }

    public function down(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->dropColumn('gate_transition');
        });
    }
};
