<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('full_stack_playbook_passports', function (Blueprint $table): void {
            $table->id();
            $table->string('passport_key', 180)->unique();
            $table->unsignedBigInteger('lab_agent_id')->nullable()->unique();
            $table->unsignedBigInteger('model_version_id')->unique();
            $table->unsignedBigInteger('edge_genesis_passport_id')->nullable();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('packet_key', 128);
            $table->string('arm', 64);
            $table->string('mastery_stage', 48)->default('imitation');
            $table->string('status', 48)->default('enrolled');
            $table->string('data_hash', 128);
            $table->string('execution_hash', 128);
            $table->json('playbook');
            $table->json('evidence');
            $table->decimal('procedural_score', 8, 6)->nullable();
            $table->decimal('economic_score', 14, 6)->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'mastery_stage', 'status'], 'full_stack_mastery_scope_idx');
            $table->foreign('lab_agent_id', 'full_stack_passport_agent_fk')->references('id')->on('lab_agents')->nullOnDelete();
            $table->foreign('model_version_id', 'full_stack_passport_model_fk')->references('id')->on('model_versions')->cascadeOnDelete();
            $table->foreign('edge_genesis_passport_id', 'full_stack_passport_genesis_fk')->references('id')->on('edge_genesis_passports')->nullOnDelete();
        });

        Schema::create('playbook_mastery_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_key', 180)->unique();
            $table->unsignedBigInteger('full_stack_playbook_passport_id');
            $table->string('stage', 48);
            $table->string('status', 48);
            $table->unsignedTinyInteger('fold_count')->default(0);
            $table->json('procedural_dimensions');
            $table->json('economic_evidence');
            $table->json('evidence');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['full_stack_playbook_passport_id', 'stage', 'status'], 'playbook_mastery_ledger_lookup_idx');
            $table->foreign('full_stack_playbook_passport_id', 'playbook_mastery_ledger_passport_fk')->references('id')->on('full_stack_playbook_passports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playbook_mastery_ledger_entries');
        Schema::dropIfExists('full_stack_playbook_passports');
    }
};
