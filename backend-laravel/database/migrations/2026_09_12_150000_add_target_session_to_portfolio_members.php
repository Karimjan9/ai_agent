<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->string('target_session', 32)->nullable()->after('target_direction');
            $table->index(
                ['elite_agent_portfolio_id', 'target_session'],
                'elite_portfolio_member_session_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->dropIndex('elite_portfolio_member_session_idx');
            $table->dropColumn('target_session');
        });
    }
};
