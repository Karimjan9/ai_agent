<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_competency_tensor_cells', function (Blueprint $table): void {
            $table->id(); $table->foreignId('lab_agent_id')->constrained('lab_agents')->cascadeOnDelete();
            $table->string('skill', 96); $table->string('context_key', 160); $table->string('stage', 96);
            $table->unsignedInteger('exposed_events')->default(0); $table->unsignedInteger('successful_transitions')->default(0);
            $table->unsignedInteger('false_positive_events')->default(0); $table->unsignedInteger('independent_windows')->default(0);
            $table->decimal('mastery_estimate', 10, 6)->default(0); $table->decimal('uncertainty', 10, 6)->default(1);
            $table->decimal('calibration', 10, 6)->nullable(); $table->string('knowledge_status', 32); $table->string('authority_status', 32);
            $table->string('drift_status', 32)->default('stable'); $table->string('freshness_status', 32)->default('active');
            $table->string('last_verified_epoch', 128)->nullable(); $table->json('evidence'); $table->timestamps();
            $table->unique(['lab_agent_id','skill','context_key','stage'], 'agent_competency_cell_uq');
            $table->index(['skill','context_key','knowledge_status'], 'agent_competency_lookup_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('agent_competency_tensor_cells'); }
};
