<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_failure_repair_anchors', function (Blueprint $table): void {
            $table->id();
            $table->string('anchor_key', 64)->unique();
            $table->foreignId('source_lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('source_model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->foreignId('source_lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('failure_class', 16); // strategy; technical failures never create anchors
            $table->string('failure_reason', 96);
            $table->string('failure_target', 64);
            $table->string('status', 16)->default('open');
            $table->json('parameter_snapshot');
            $table->string('parameter_fingerprint', 64);
            $table->json('parameter_diff')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(
                ['symbol', 'timeframe', 'strategy_family', 'failure_target', 'status'],
                'lab_repair_anchor_scope_lookup',
            );
            $table->index(['source_lab_agent_id', 'failure_reason'], 'lab_repair_anchor_source_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_failure_repair_anchors');
    }
};
