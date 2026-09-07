<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dual_track_evidence_work_items', function (Blueprint $table): void {
            $table->uuid('lease_token')->nullable()->after('leased_at');
            $table->unsignedBigInteger('fence_version')->default(0)->after('lease_token');
            $table->timestamp('lease_expires_at')->nullable()->after('fence_version');
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
            $table->index(['status', 'lease_expires_at'], 'twin_work_lease_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dual_track_evidence_work_items', function (Blueprint $table): void {
            $table->dropIndex('twin_work_lease_expiry_idx');
            $table->dropColumn(['lease_token', 'fence_version', 'lease_expires_at', 'heartbeat_at']);
        });
    }
};
