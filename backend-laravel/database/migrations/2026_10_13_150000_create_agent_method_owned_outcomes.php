<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_method_owned_outcomes', function (Blueprint $table) {
            $table->id();
            $table->string('outcome_key', 64)->unique();
            $table->string('policy_hash', 64)->index();
            $table->string('physical_episode_hash', 64);
            $table->string('context_key', 64);
            $table->string('bundle_hash', 64);
            $table->string('source_proof_hash', 64);
            $table->string('run_id', 64)->index();
            $table->json('facts');
            $table->timestamp('created_at');
            $table->unique(['policy_hash', 'physical_episode_hash'], 'agent_method_owned_episode_unique');
            $table->unique(['source_proof_hash', 'physical_episode_hash'], 'agent_method_owned_source_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_method_owned_outcomes') && DB::table('agent_method_owned_outcomes')->exists()) {
            throw new \LogicException('OWNED_METHOD_APPEND_ONLY_HISTORY_PREVENTS_ROLLBACK');
        }
        Schema::dropIfExists('agent_method_owned_outcomes');
    }
};
