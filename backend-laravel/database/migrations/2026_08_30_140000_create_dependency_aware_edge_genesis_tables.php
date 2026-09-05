<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_genesis_passports', function (Blueprint $table): void {
            $table->id(); $table->string('genesis_key', 160)->unique();
            $table->unsignedBigInteger('lab_generation_id')->nullable(); $table->unsignedBigInteger('baseline_model_version_id')->nullable();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('strategy_family', 64);
            $table->string('phase', 48); $table->string('status', 48); $table->string('data_hash', 128); $table->string('execution_hash', 128);
            $table->json('context'); $table->json('evidence'); $table->dateTime('phase_changed_at', 6)->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'phase', 'status'], 'edge_genesis_phase_scope_idx');
            $table->foreign('lab_generation_id', 'edge_gen_passport_generation_fk')->references('id')->on('lab_generations')->nullOnDelete();
            $table->foreign('baseline_model_version_id', 'edge_gen_passport_baseline_fk')->references('id')->on('model_versions')->nullOnDelete();
        });
        Schema::create('edge_genesis_trials', function (Blueprint $table): void {
            $table->id(); $table->string('trial_key', 180)->unique(); $table->unsignedBigInteger('edge_genesis_passport_id');
            $table->unsignedBigInteger('lab_agent_id')->nullable(); $table->unsignedBigInteger('model_version_id')->nullable();
            $table->string('packet_key', 128); $table->string('emitter', 64); $table->string('arm', 64); $table->string('stage', 32); $table->string('status', 48);
            $table->json('evidence'); $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->index(['edge_genesis_passport_id', 'packet_key', 'arm'], 'edge_gen_trial_packet_arm_idx');
            $table->foreign('edge_genesis_passport_id', 'edge_gen_trial_passport_fk')->references('id')->on('edge_genesis_passports')->cascadeOnDelete();
            $table->foreign('lab_agent_id', 'edge_gen_trial_agent_fk')->references('id')->on('lab_agents')->nullOnDelete();
            $table->foreign('model_version_id', 'edge_gen_trial_model_fk')->references('id')->on('model_versions')->nullOnDelete();
        });
        Schema::create('edge_genesis_component_attributions', function (Blueprint $table): void {
            $table->id(); $table->string('attribution_key', 180)->unique(); $table->unsignedBigInteger('edge_genesis_passport_id');
            $table->string('packet_key', 128); $table->string('component_axis', 64); $table->string('status', 48); $table->decimal('after_cost_delta', 14, 6)->nullable();
            $table->json('evidence'); $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->foreign('edge_genesis_passport_id', 'edge_gen_attr_passport_fk')->references('id')->on('edge_genesis_passports')->cascadeOnDelete();
        });
        Schema::create('edge_genesis_compute_ledgers', function (Blueprint $table): void {
            $table->id(); $table->string('ledger_key', 180)->unique(); $table->unsignedBigInteger('edge_genesis_passport_id')->nullable();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('emitter', 64); $table->string('phase', 48); $table->decimal('budget_share', 8, 4); $table->decimal('priority', 12, 6);
            $table->string('status', 48); $table->json('evidence'); $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->foreign('edge_genesis_passport_id', 'edge_gen_compute_passport_fk')->references('id')->on('edge_genesis_passports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_genesis_compute_ledgers'); Schema::dropIfExists('edge_genesis_component_attributions');
        Schema::dropIfExists('edge_genesis_trials'); Schema::dropIfExists('edge_genesis_passports');
    }
};
