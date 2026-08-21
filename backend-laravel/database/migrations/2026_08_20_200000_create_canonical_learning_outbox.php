<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_learning_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 160)->unique();
            $table->string('kind', 48);
            $table->string('status', 32)->default('pending');
            $table->foreignId('pair_id')->nullable()->constrained('lab_learning_lane_pairs')->nullOnDelete();
            $table->string('evidence_run_id', 128)->nullable();
            $table->string('data_hash', 160)->nullable();
            $table->string('execution_hash', 160)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'kind'], 'canonical_learning_outbox_status_kind_idx');
            $table->index(['pair_id', 'kind'], 'canonical_learning_outbox_pair_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_learning_outbox');
    }
};
