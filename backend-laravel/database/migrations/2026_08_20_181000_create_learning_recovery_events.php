<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 180)->unique();
            $table->string('source_type', 96);
            $table->string('source_key', 180);
            $table->string('symbol', 32)->nullable();
            $table->string('timeframe', 16)->nullable();
            $table->string('status', 32)->default('inspected');
            $table->string('action', 64);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'status'], 'learning_recovery_source_status_idx');
            $table->index(['symbol', 'timeframe', 'status'], 'learning_recovery_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_recovery_events');
    }
};
