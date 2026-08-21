<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_cells', function (Blueprint $table): void {
            $table->id();
            $table->string('cell_key', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('regime', 64);
            $table->string('session', 32);
            $table->string('strategy_id', 128)->nullable();
            $table->string('tactic_id', 128)->nullable();
            $table->string('risk_regime', 32);
            $table->string('execution_environment', 32);
            $table->decimal('regime_probability', 10, 6);
            $table->decimal('transition_hazard', 10, 6);
            $table->decimal('state_confidence', 10, 6);
            $table->json('state_posterior');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'regime', 'session'], 'capability_cell_scope_idx');
        });
        Schema::table('capability_skills', function (Blueprint $table): void {
            $table->foreignId('capability_cell_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->decimal('drift_score', 10, 6)->default(0);
            $table->json('reference_state_distribution')->nullable();
            $table->json('current_state_distribution')->nullable();
            $table->decimal('performance_decay', 10, 6)->default(0);
            $table->boolean('revalidation_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('capability_skills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('capability_cell_id');
            $table->dropColumn(['last_validated_at', 'expires_at', 'last_success_at', 'drift_score', 'reference_state_distribution', 'current_state_distribution', 'performance_decay', 'revalidation_required']);
        });
        Schema::dropIfExists('capability_cells');
    }
};
