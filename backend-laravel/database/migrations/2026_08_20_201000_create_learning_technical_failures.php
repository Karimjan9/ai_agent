<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_technical_failures', function (Blueprint $table): void {
            $table->id(); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('fingerprint', 64); $table->string('error_class', 255);
            $table->unsignedInteger('occurrences')->default(1); $table->string('status', 32)->default('observed');
            $table->json('context')->nullable(); $table->timestamp('last_seen_at'); $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'fingerprint'], 'learning_technical_failure_uq');
        });
    }
    public function down(): void { Schema::dropIfExists('learning_technical_failures'); }
};
