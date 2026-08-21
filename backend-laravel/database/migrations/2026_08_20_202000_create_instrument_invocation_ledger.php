<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_invocation_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('invocation_key', 160)->unique();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->foreignId('paper_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paper_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trading_instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->string('instrument_key', 96); $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('state_key', 160)->nullable();
            $table->string('input_hash', 128); $table->string('output_hash', 128)->nullable();
            $table->boolean('used_in_decision')->default(false); $table->boolean('used_in_execution')->default(false);
            $table->string('verdict', 32)->default('invoked'); $table->decimal('causal_contribution', 12, 6)->nullable();
            $table->json('control_delta')->nullable(); $table->json('metadata')->nullable();
            $table->timestamp('invoked_at'); $table->timestamp('settled_at')->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'instrument_key', 'verdict'], 'instrument_invocation_scope_verdict_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('instrument_invocation_ledger'); }
};
