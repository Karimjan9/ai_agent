<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void { Schema::table('mutation_memories', fn (Blueprint $table) => $table->json('behavioral_effect')->nullable()->after('gate_transition')); }
    public function down(): void { Schema::table('mutation_memories', fn (Blueprint $table) => $table->dropColumn('behavioral_effect')); }
};
