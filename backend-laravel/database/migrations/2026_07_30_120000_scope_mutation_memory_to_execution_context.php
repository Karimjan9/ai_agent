<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->string('architecture', 96)->nullable()->after('strategy_family');
            $table->string('direction', 8)->nullable()->after('market_regime');
            $table->string('volatility_regime', 48)->nullable()->after('direction');
            $table->string('execution_contract_hash', 64)->nullable()->after('behavioral_effect');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'architecture', 'market_regime'], 'mutation_memory_scope_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->dropIndex('mutation_memory_scope_lookup');
            $table->dropColumn(['architecture', 'direction', 'volatility_regime', 'execution_contract_hash']);
        });
    }
};
