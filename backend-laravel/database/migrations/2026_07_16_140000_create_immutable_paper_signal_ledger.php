<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_market_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->dateTime('candle_time');
            $table->string('decision', 8);
            $table->decimal('price', 18, 6);
            $table->decimal('stop_loss', 18, 6)->nullable();
            $table->decimal('take_profit', 18, 6)->nullable();
            $table->decimal('confidence', 8, 2)->default(0);
            $table->string('market_regime')->nullable();
            $table->string('volatility_regime')->nullable();
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['model_market_performance_id', 'symbol', 'timeframe', 'candle_time'], 'paper_signal_identity');
            $table->index(['symbol', 'timeframe', 'candle_time']);
            $table->index('payload_hash');
        });

        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->foreignId('paper_signal_id')->nullable()->after('model_market_performance_id')
                ->unique()->constrained('paper_signals')->nullOnDelete();
        });

        Schema::create('paper_signal_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_signal_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('paper_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('outcome', 16);
            $table->decimal('exit_price', 18, 6);
            $table->decimal('profit_percent', 10, 4);
            $table->string('exit_reason', 32)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('market_health_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('status', 16);
            $table->unsignedInteger('age_seconds');
            $table->dateTime('candle_time')->nullable();
            $table->timestamp('sampled_at')->useCurrent();
            $table->index(['symbol', 'timeframe', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_health_samples');
        Schema::dropIfExists('paper_signal_outcomes');
        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paper_signal_id');
        });
        Schema::dropIfExists('paper_signals');
    }
};
