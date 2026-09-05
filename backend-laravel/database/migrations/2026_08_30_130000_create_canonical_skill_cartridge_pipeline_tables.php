<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL applies DDL before a later FK-name error can be raised. This
        // migration is not recorded on such a failure, so clean only its own
        // incomplete first table before retrying.
        Schema::dropIfExists('skill_cartridge_observations');
        Schema::dropIfExists('skill_cartridge_transplant_trials');
        if (! Schema::hasColumn('lab_skill_zoo_entries', 'cartridge_key')) {
            Schema::table('lab_skill_zoo_entries', function (Blueprint $table): void {
                $table->string('cartridge_key', 128)->nullable()->after('skill_key');
                $table->unsignedInteger('revision')->default(1)->after('cartridge_key');
                $table->string('component_status', 48)->default('observed')->after('status');
                $table->string('organism_viability', 48)->default('unknown')->after('component_status');
                $table->unsignedBigInteger('causal_baseline_agent_id')->nullable()->after('lab_agent_id');
                $table->unsignedBigInteger('genetic_parent_model_version_id')->nullable()->after('model_version_id');
            });
        }
        if (! Schema::hasIndex('lab_skill_zoo_entries', 'lab_skill_zoo_cartridge_key_uq')) {
            Schema::table('lab_skill_zoo_entries', fn (Blueprint $table) => $table->unique('cartridge_key', 'lab_skill_zoo_cartridge_key_uq'));
        }
        Schema::create('skill_cartridge_observations', function (Blueprint $table): void {
            $table->id(); $table->string('observation_key', 160)->unique();
            $table->foreignId('lab_skill_zoo_entry_id')->constrained('lab_skill_zoo_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_learning_settlement_id')->nullable();
            $table->unsignedBigInteger('lab_learning_lane_pair_id')->nullable();
            $table->unsignedBigInteger('lab_mutation_response_map_id')->nullable();
            $table->string('outcome', 32); $table->decimal('target_delta', 14, 6)->nullable(); $table->json('evidence');
            $table->dateTime('observed_at', 6); $table->timestamps();
            $table->index(['lab_skill_zoo_entry_id', 'outcome'], 'skill_cartridge_observation_entry_outcome_idx');
            $table->foreign('agent_learning_settlement_id', 'skill_cart_obs_settlement_fk')->references('id')->on('agent_learning_settlements')->nullOnDelete();
            $table->foreign('lab_learning_lane_pair_id', 'skill_cart_obs_pair_fk')->references('id')->on('lab_learning_lane_pairs')->nullOnDelete();
            $table->foreign('lab_mutation_response_map_id', 'skill_cart_obs_response_fk')->references('id')->on('lab_mutation_response_maps')->nullOnDelete();
        });
        Schema::create('skill_cartridge_transplant_trials', function (Blueprint $table): void {
            $table->id(); $table->string('trial_key', 160)->unique();
            $table->foreignId('lab_skill_zoo_entry_id')->constrained('lab_skill_zoo_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('baseline_model_version_id')->nullable();
            $table->unsignedBigInteger('child_model_version_id')->nullable();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('mode', 32); $table->string('status', 48);
            $table->json('context'); $table->json('evidence'); $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->foreign('baseline_model_version_id', 'skill_cart_transplant_base_fk')->references('id')->on('model_versions')->nullOnDelete();
            $table->foreign('child_model_version_id', 'skill_cart_transplant_child_fk')->references('id')->on('model_versions')->nullOnDelete();
        });
        Schema::create('skill_cartridge_interactions', function (Blueprint $table): void {
            $table->id(); $table->string('interaction_key', 160)->unique();
            $table->foreignId('skill_a_id')->constrained('lab_skill_zoo_entries')->cascadeOnDelete();
            $table->foreignId('skill_b_id')->constrained('lab_skill_zoo_entries')->cascadeOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('status', 48); $table->json('evidence');
            $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('skill_cartridge_interactions'); Schema::dropIfExists('skill_cartridge_transplant_trials'); Schema::dropIfExists('skill_cartridge_observations');
        Schema::table('lab_skill_zoo_entries', function (Blueprint $table): void { $table->dropUnique('lab_skill_zoo_cartridge_key_uq'); $table->dropColumn(['cartridge_key','revision','component_status','organism_viability','causal_baseline_agent_id','genetic_parent_model_version_id']); });
    }
};
