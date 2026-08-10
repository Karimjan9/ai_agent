<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_progress_cards', function (Blueprint $table): void {
            $table->string('tactic_id', 96)->nullable()->after('strategy_family');
            $table->json('tactic_contract')->nullable()->after('tactic_id');
            $table->index(['symbol', 'timeframe', 'tactic_id'], 'agent_progress_tactic_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agent_progress_cards', function (Blueprint $table): void {
            $table->dropIndex('agent_progress_tactic_idx');
            $table->dropColumn(['tactic_id', 'tactic_contract']);
        });
    }
};
