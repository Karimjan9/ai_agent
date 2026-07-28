<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_veto_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('veto_reason', 64);
            $table->string('market_regime', 32)->nullable();
            $table->string('volatility_regime', 32)->nullable();
            $table->string('direction', 8)->nullable();
            $table->timestamp('signal_time')->nullable();
            $table->timestamp('entry_time')->nullable();
            $table->timestamp('exit_time')->nullable();
            $table->decimal('shadow_profit', 12, 5)->default(0);
            $table->decimal('shadow_loss', 12, 5)->default(0);
            $table->decimal('shadow_profit_percent', 12, 5)->default(0);
            $table->string('outcome', 8);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['lab_agent_id', 'stage', 'veto_reason', 'signal_time'], 'shadow_veto_signal_unique');
            $table->index(['veto_reason', 'market_regime', 'volatility_regime'], 'shadow_veto_reason_regime_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_veto_observations');
    }
};
