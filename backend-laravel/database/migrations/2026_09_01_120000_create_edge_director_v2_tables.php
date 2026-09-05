<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_hypothesis_packets', function (Blueprint $table): void {
            $table->id();
            $table->string('hypothesis_key', 128)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->unsignedBigInteger('source_generation_id')->nullable();
            $table->unsignedBigInteger('materialized_generation_id')->nullable();
            $table->string('architecture_revision', 96);
            $table->string('diagnosis', 96);
            $table->string('structural_axis', 96);
            $table->string('packet_definition_hash', 128);
            $table->string('status', 48)->default('registered');
            $table->json('definition');
            $table->json('evidence');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'edge_hypothesis_scope_status_idx');
            $table->unique(['symbol', 'timeframe', 'packet_definition_hash'], 'edge_hypothesis_definition_unique');
            $table->foreign('source_generation_id', 'edge_hyp_source_generation_fk')
                ->references('id')->on('lab_generations')->nullOnDelete();
            $table->foreign('materialized_generation_id', 'edge_hyp_materialized_generation_fk')
                ->references('id')->on('lab_generations')->nullOnDelete();
        });

        Schema::create('edge_genesis_cohorts', function (Blueprint $table): void {
            $table->id();
            $table->string('cohort_key', 128)->unique();
            $table->unsignedBigInteger('lab_generation_id')->nullable()->unique();
            $table->unsignedBigInteger('edge_hypothesis_packet_id')->nullable();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('architecture_revision', 96);
            $table->string('data_hash', 128);
            $table->string('mtf_bundle_hash', 128);
            $table->string('execution_hash', 128);
            $table->string('packet_definition_hash', 128);
            $table->string('frozen_window_plan_hash', 128);
            $table->string('status', 48)->default('registered');
            $table->json('admission_snapshot');
            $table->json('evidence');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'edge_cohort_scope_status_idx');
            $table->foreign('lab_generation_id', 'edge_cohort_generation_fk')
                ->references('id')->on('lab_generations')->nullOnDelete();
            $table->foreign('edge_hypothesis_packet_id', 'edge_cohort_hypothesis_fk')
                ->references('id')->on('edge_hypothesis_packets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_genesis_cohorts');
        Schema::dropIfExists('edge_hypothesis_packets');
    }
};
