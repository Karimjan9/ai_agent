<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->string('broker')->default('simulated');
            $table->string('external_order_id')->nullable();
            $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('direction', 8); $table->decimal('units', 14, 4)->default(1);
            $table->decimal('entry_price', 18, 6); $table->decimal('stop_loss', 18, 6);
            $table->decimal('take_profit', 18, 6); $table->decimal('exit_price', 18, 6)->nullable();
            $table->decimal('profit_percent', 10, 4)->nullable();
            $table->string('status')->default('open');
            $table->timestamp('opened_at'); $table->timestamp('closed_at')->nullable();
            $table->json('signal_context')->nullable(); $table->json('broker_payload')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status']);
        });

        Schema::create('paper_fills', function (Blueprint $table): void {
            $table->id(); $table->foreignId('paper_order_id')->constrained()->cascadeOnDelete();
            $table->string('fill_type'); $table->decimal('price', 18, 6);
            $table->decimal('cost_percent', 10, 5)->default(0); $table->timestamp('filled_at');
            $table->json('payload')->nullable(); $table->timestamps();
        });

        Schema::create('agent_diagnoses', function (Blueprint $table): void {
            $table->id(); $table->foreignId('lab_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->string('primary_failure'); $table->json('evidence');
            $table->json('recommended_mutations')->nullable(); $table->json('blocked_mutations')->nullable();
            $table->text('explanation'); $table->decimal('confidence', 5, 2)->default(50); $table->timestamps();
        });

        Schema::create('market_drift_snapshots', function (Blueprint $table): void {
            $table->id(); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->decimal('psi_score', 8, 4); $table->decimal('volatility_ratio', 10, 4);
            $table->decimal('mean_return_shift', 10, 6); $table->string('status');
            $table->json('metrics'); $table->timestamp('detected_at'); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status']);
        });

        Schema::create('sealed_holdout_releases', function (Blueprint $table): void {
            $table->id(); $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->string('dataset_hash'); $table->string('status')->default('running');
            $table->decimal('score', 8, 2)->nullable(); $table->json('result')->nullable();
            $table->timestamp('opened_at'); $table->timestamp('completed_at')->nullable(); $table->timestamps();
            $table->unique('model_market_performance_id');
        });

        Schema::table('model_market_performance', function (Blueprint $table): void {
            $table->string('holdout_status', 16)->default('sealed')->after('paper_status');
            $table->decimal('holdout_score', 8, 2)->nullable()->after('forward_score');
        });
    }

    public function down(): void
    {
        Schema::table('model_market_performance', fn (Blueprint $table) => $table->dropColumn(['holdout_status', 'holdout_score']));
        Schema::dropIfExists('sealed_holdout_releases'); Schema::dropIfExists('market_drift_snapshots');
        Schema::dropIfExists('agent_diagnoses'); Schema::dropIfExists('paper_fills'); Schema::dropIfExists('paper_orders');
    }
};
