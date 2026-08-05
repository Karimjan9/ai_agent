<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->string('target_direction', 8)->nullable()->after('target_volatility');
            $table->index(
                ['elite_agent_portfolio_id', 'target_regime', 'target_volatility', 'target_direction'],
                'elite_portfolio_member_direction_route_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->dropIndex('elite_portfolio_member_direction_route_idx');
            $table->dropColumn('target_direction');
        });
    }
};
