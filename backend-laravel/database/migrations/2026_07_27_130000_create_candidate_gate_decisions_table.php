<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_gate_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_market_performance_id')->nullable()->constrained('model_market_performance')->nullOnDelete();
            $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 32);
            $table->string('decision', 16); // passed, failed, waiting
            $table->json('reason_codes');
            $table->json('metrics')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->unique(['model_market_performance_id', 'lab_agent_id', 'stage'], 'candidate_gate_stage_unique');
            $table->index(['stage', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_gate_decisions');
    }
};
