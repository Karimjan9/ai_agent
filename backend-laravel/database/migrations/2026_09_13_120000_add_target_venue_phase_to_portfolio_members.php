<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->string('target_venue_phase', 48)->nullable()->after('target_session');
            $table->index(
                ['elite_agent_portfolio_id', 'target_venue_phase'],
                'elite_portfolio_member_venue_phase_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('elite_agent_portfolio_members', function (Blueprint $table): void {
            $table->dropIndex('elite_portfolio_member_venue_phase_idx');
            $table->dropColumn('target_venue_phase');
        });
    }
};
