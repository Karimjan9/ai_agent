<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_lifecycle_cycles', function (Blueprint $table): void {
            $table->id();
            $table->string('cycle_id', 96)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('status', 32);
            $table->string('stage', 48);
            $table->string('summary', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status', 'heartbeat_at'], 'lab_lifecycle_cycle_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_lifecycle_cycles');
    }
};
