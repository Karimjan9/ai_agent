<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_discipline_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 96)->unique();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->foreignId('paper_signal_id')->nullable()->constrained('paper_signals')->cascadeOnDelete();
            $table->foreignId('paper_order_id')->nullable()->constrained('paper_orders')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('phase', 16);
            $table->string('state', 16);
            $table->string('decision', 16);
            $table->string('classification', 24)->nullable();
            $table->decimal('setup_quality_score', 6, 2)->nullable();
            $table->decimal('process_adherence_score', 6, 2);
            $table->decimal('risk_multiplier', 8, 6)->default(0);
            $table->json('reason_codes');
            $table->json('gate_results');
            $table->json('metrics');
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['symbol', 'timeframe', 'phase', 'decided_at'], 'smart_discipline_market_phase');
            $table->index(['decision', 'state', 'decided_at'], 'smart_discipline_decision_state');
            $table->index(['classification', 'decided_at'], 'smart_discipline_classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_discipline_decisions');
    }
};
