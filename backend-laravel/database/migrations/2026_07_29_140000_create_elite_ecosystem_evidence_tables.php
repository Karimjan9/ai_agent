<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_skill_atlas_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 8);
            $table->string('niche_key', 255); $table->string('regime', 32)->nullable();
            $table->string('volatility', 32)->nullable(); $table->string('direction', 8)->nullable();
            $table->string('role', 32)->default('challenger');
            $table->decimal('quality_score', 10, 3)->default(0); $table->json('capabilities');
            $table->json('evidence')->nullable(); $table->timestamp('validated_at')->nullable(); $table->timestamps();
            $table->unique(['model_market_performance_id', 'niche_key'], 'atlas_performance_niche_unique');
            // MySQL limits identifiers to 64 characters.
            $table->index(['symbol', 'timeframe', 'niche_key', 'quality_score'], 'atlas_niche_quality_idx');
        });
        Schema::create('agent_failure_cases', function (Blueprint $table): void {
            $table->id(); $table->string('failure_case_key', 64)->unique();
            $table->string('market_slice_hash', 64); $table->string('symbol', 16); $table->string('timeframe', 8);
            $table->string('regime', 32)->nullable(); $table->string('failure_type', 64);
            $table->string('expected_safe_behavior', 64); $table->string('discovered_by', 64);
            $table->foreignId('source_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('fixed_by_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('regression_status', 32)->default('open'); $table->json('evidence')->nullable();
            $table->timestamps(); $table->index(['symbol', 'timeframe', 'regression_status']);
        });
        Schema::create('adversarial_validator_epochs', function (Blueprint $table): void {
            $table->id(); $table->string('epoch_key', 64)->unique(); $table->string('status', 32)->default('frozen');
            $table->json('validators'); $table->string('commitment_hash', 64); $table->timestamp('frozen_at'); $table->timestamp('retired_at')->nullable(); $table->timestamps();
        });
        Schema::create('adversarial_validator_findings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('adversarial_validator_epoch_id');
            $table->foreign('adversarial_validator_epoch_id', 'avf_epoch_fk')->references('id')->on('adversarial_validator_epochs')->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('validator', 64); $table->string('verdict', 32); $table->json('evidence')->nullable(); $table->timestamps();
            $table->index(['model_version_id', 'validator', 'verdict'], 'avf_model_validator_idx');
        });
        Schema::create('paper_sequential_evidences', function (Blueprint $table): void {
            $table->id(); $table->foreignId('model_market_performance_id')->constrained('model_market_performance')->cascadeOnDelete();
            $table->unsignedInteger('sample_count'); $table->decimal('e_value', 14, 6)->default(0);
            $table->decimal('likelihood_ratio', 14, 6)->default(0); $table->decimal('confidence_sequence', 8, 4)->default(0);
            $table->string('status', 32); $table->json('metrics'); $table->timestamps();
            $table->unique(['model_market_performance_id', 'sample_count'], 'pse_performance_sample_uq');
        });
    }
    public function down(): void { Schema::dropIfExists('paper_sequential_evidences'); Schema::dropIfExists('adversarial_validator_findings'); Schema::dropIfExists('adversarial_validator_epochs'); Schema::dropIfExists('agent_failure_cases'); Schema::dropIfExists('agent_skill_atlas_entries'); }
};
