<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->unsignedSmallInteger('independent_confirmation_count')->default(0)->after('execution_contract_hash');
            $table->string('non_target_regression_status', 32)->default('not_applicable')->after('independent_confirmation_count');
            $table->string('evidence_scope_status', 32)->default('historical_failure_memory')->after('non_target_regression_status');
        });
    }

    public function down(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->dropColumn(['independent_confirmation_count', 'non_target_regression_status', 'evidence_scope_status']);
        });
    }
};
