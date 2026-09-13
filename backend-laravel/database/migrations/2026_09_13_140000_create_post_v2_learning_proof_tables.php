<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_protocol_epoch_links', function (Blueprint $table): void {
            $table->id();
            $table->string('link_key', 128)->unique();
            $table->string('protocol_epoch', 64);
            $table->string('entity_type', 160);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('data_hash', 128)->nullable();
            $table->string('execution_hash', 128)->nullable();
            $table->string('source_link_hash', 128);
            $table->string('linkage_status', 48)->default('unverified');
            $table->boolean('eligible_for_v2_denominator')->default(false);
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['protocol_epoch', 'entity_type', 'entity_id'], 'learning_epoch_entity_unique');
            $table->index(['symbol', 'timeframe', 'protocol_epoch', 'eligible_for_v2_denominator'], 'learning_epoch_scope_idx');
        });

        Schema::create('causal_capability_escrows', function (Blueprint $table): void {
            $table->id();
            $table->string('escrow_key', 128)->unique();
            $table->unsignedBigInteger('agent_learning_causal_experiment_id');
            $table->unsignedBigInteger('lab_learning_lane_pair_id')->nullable();
            $table->unsignedBigInteger('agent_learning_settlement_id')->nullable();
            $table->string('protocol_epoch', 64);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64)->nullable();
            $table->string('target', 128);
            $table->string('gene_key', 128);
            $table->string('context_hash', 128);
            $table->string('lattice_state', 64)->default('research_inbox');
            $table->boolean('component_confirmed')->default(false);
            $table->boolean('composition_eligible')->default(false);
            $table->boolean('organism_viable')->default(false);
            $table->boolean('reproductive_authority')->default(false);
            $table->json('evidence');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['agent_learning_causal_experiment_id', 'protocol_epoch'], 'causal_escrow_experiment_epoch_unique');
            $table->index(['symbol', 'timeframe', 'lattice_state'], 'causal_escrow_scope_state_idx');
            $table->foreign('agent_learning_causal_experiment_id', 'causal_escrow_experiment_fk')
                ->references('id')->on('agent_learning_causal_experiments')->cascadeOnDelete();
            $table->foreign('lab_learning_lane_pair_id', 'causal_escrow_pair_fk')
                ->references('id')->on('lab_learning_lane_pairs')->nullOnDelete();
            $table->foreign('agent_learning_settlement_id', 'causal_escrow_settlement_fk')
                ->references('id')->on('agent_learning_settlements')->nullOnDelete();
        });

        Schema::create('contextual_instrument_bundle_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('effect_key', 128)->unique();
            $table->unsignedBigInteger('cooperative_experiment_settlement_id')->nullable();
            $table->unsignedBigInteger('lab_generation_id')->nullable();
            $table->string('protocol_epoch', 64);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('context_cell_key', 128);
            $table->string('instrument_a', 128)->nullable();
            $table->string('instrument_b', 128)->nullable();
            $table->string('bundle_hash', 128);
            $table->string('effect_type', 48);
            $table->decimal('marginal_effect', 18, 8)->nullable();
            $table->decimal('interaction_effect', 18, 8)->nullable();
            $table->decimal('leave_one_out_effect', 18, 8)->nullable();
            $table->unsignedInteger('observations')->default(1);
            $table->string('authority_level', 32)->default('research_only');
            $table->boolean('contraindicated')->default(false);
            $table->json('evidence');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'context_cell_key', 'effect_type'], 'instrument_bundle_effect_scope_idx');
            $table->index(['instrument_a', 'instrument_b', 'contraindicated'], 'instrument_bundle_effect_pair_idx');
            $table->foreign('cooperative_experiment_settlement_id', 'instrument_bundle_settlement_fk')
                ->references('id')->on('cooperative_experiment_settlements')->nullOnDelete();
            $table->foreign('lab_generation_id', 'instrument_bundle_generation_fk')
                ->references('id')->on('lab_generations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contextual_instrument_bundle_effects');
        Schema::dropIfExists('causal_capability_escrows');
        Schema::dropIfExists('learning_protocol_epoch_links');
    }
};
