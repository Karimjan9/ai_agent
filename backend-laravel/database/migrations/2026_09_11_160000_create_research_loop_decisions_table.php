<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('research_loop_decisions')) {
            return;
        }

        Schema::create('research_loop_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 128)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('action', 64);
            $table->string('status', 24)->default('selected');
            $table->unsignedTinyInteger('priority')->default(1);
            $table->string('evidence_hash', 128);
            $table->string('command')->nullable();
            $table->string('queue', 64)->nullable();
            $table->json('arguments')->nullable();
            $table->json('reason_codes');
            $table->json('evidence_snapshot');
            $table->json('contract');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'created_at'], 'research_loop_scope_created_idx');
            $table->index(['status', 'priority', 'created_at'], 'research_loop_status_priority_idx');
            $table->index(['action', 'evidence_hash'], 'research_loop_action_evidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_loop_decisions');
    }
};
