<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('candidate_gate_decisions', 'attribution_status')) {
            Schema::table('candidate_gate_decisions', function (Blueprint $table): void {
                $table->string('attribution_status', 32)->default('unassessed')->after('metrics');
                $table->timestamp('quarantined_at')->nullable()->after('attribution_status');
                $table->string('quarantine_reason', 128)->nullable()->after('quarantined_at');
                $table->index(['stage', 'attribution_status'], 'gate_attribution_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('candidate_gate_decisions', 'attribution_status')) {
            Schema::table('candidate_gate_decisions', function (Blueprint $table): void {
                $table->dropIndex('gate_attribution_status_idx');
                $table->dropColumn(['attribution_status', 'quarantined_at', 'quarantine_reason']);
            });
        }
    }
};
