<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_academy_passports', function (Blueprint $table): void {
            $table->id();
            $table->string('passport_key', 160)->unique();
            $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('composition_key', 160); $table->string('strategy_key', 96);
            $table->string('context_key', 160); $table->string('temporal_binding_hash', 128);
            $table->string('deepest_stage', 64)->default('market_cartographer');
            $table->unsignedTinyInteger('stage_depth')->default(0);
            $table->string('status', 64)->default('apprentice');
            $table->json('frozen_upstream_contract');
            $table->json('curriculum'); $table->json('evidence');
            $table->timestamp('assessed_at')->nullable(); $table->timestamps();
            $table->unique(['composition_key', 'context_key', 'temporal_binding_hash'], 'academy_passport_identity_uq');
            $table->index(['symbol', 'timeframe', 'stage_depth'], 'academy_passport_scope_depth_idx');
        });

        Schema::create('edge_academy_oracle_gaps', function (Blueprint $table): void {
            $table->id(); $table->string('diagnostic_key', 160)->unique();
            $table->foreignId('edge_academy_passport_id')->constrained('edge_academy_passports')->cascadeOnDelete();
            $table->string('data_hash', 128); $table->string('execution_hash', 128);
            $table->string('status', 64); $table->string('dominant_leak', 96)->nullable();
            $table->json('edge_gap'); $table->json('oracle_contract'); $table->json('action');
            $table->timestamp('assessed_at'); $table->timestamps();
            $table->index(['edge_academy_passport_id', 'status'], 'academy_oracle_passport_status_idx');
        });

        Schema::create('edge_academy_beams', function (Blueprint $table): void {
            $table->id(); $table->string('beam_key', 160)->unique();
            $table->foreignId('edge_academy_passport_id')->nullable()->constrained('edge_academy_passports')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('beam_stage', 64);
            $table->string('composition_key', 160); $table->string('quality_key', 160);
            $table->decimal('score', 12, 6)->default(0); $table->unsignedTinyInteger('rank')->nullable();
            $table->string('status', 64)->default('candidate'); $table->json('evidence'); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'beam_stage', 'rank'], 'academy_beam_scope_rank_idx');
        });

        Schema::create('edge_academy_trials', function (Blueprint $table): void {
            $table->id(); $table->string('trial_key', 160)->unique();
            $table->foreignId('edge_academy_passport_id')->constrained('edge_academy_passports')->cascadeOnDelete();
            $table->string('trial_type', 80); $table->string('status', 64)->default('planned');
            $table->json('frozen_contract'); $table->json('arms'); $table->json('density_contract');
            $table->json('outcome')->nullable(); $table->timestamp('settled_at')->nullable(); $table->timestamps();
            $table->index(['edge_academy_passport_id', 'trial_type', 'status'], 'academy_trial_passport_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_academy_trials');
        Schema::dropIfExists('edge_academy_beams');
        Schema::dropIfExists('edge_academy_oracle_gaps');
        Schema::dropIfExists('edge_academy_passports');
    }
};
