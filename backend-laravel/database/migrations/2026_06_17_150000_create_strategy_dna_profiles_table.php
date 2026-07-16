<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('strategy_dna_profiles')) {
            return;
        }

        Schema::create('strategy_dna_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_score_id')->constrained('strategy_scores')->cascadeOnDelete();
            $table->decimal('aggression_score', 8, 2)->nullable();
            $table->decimal('trend_dependency', 8, 2)->nullable();
            $table->decimal('range_dependency', 8, 2)->nullable();
            $table->decimal('volatility_sensitivity', 8, 2)->nullable();
            $table->decimal('adaptability_score', 8, 2)->nullable();
            $table->decimal('recovery_score', 8, 2)->nullable();
            $table->decimal('survival_score', 8, 2)->nullable();
            $table->text('dna_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_dna_profiles');
    }
};
