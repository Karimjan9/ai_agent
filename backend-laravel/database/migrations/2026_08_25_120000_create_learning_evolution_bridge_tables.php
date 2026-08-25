<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evolution_learning_receipts', function (Blueprint $table): void {
            $table->id(); $table->string('receipt_key', 160)->unique(); $table->string('claim_key', 160)->index();
            $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete();
            $table->string('source_type', 128); $table->string('source_key', 160); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('component', 64); $table->string('action', 32); $table->string('status', 24); $table->string('claim', 512);
            $table->decimal('causal_uplift_r', 14, 6)->nullable(); $table->decimal('confidence', 8, 6)->default(0); $table->unsignedInteger('support')->default(0);
            $table->json('scope'); $table->json('source_experiments'); $table->json('evidence'); $table->dateTime('expires_at', 6)->nullable(); $table->dateTime('compiled_at', 6); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status', 'component'], 'evolution_receipt_scope_idx');
            $table->unique(['source_type', 'source_key'], 'evolution_receipt_source_unique');
        });
        Schema::create('agent_inheritance_manifests', function (Blueprint $table): void {
            $table->id(); $table->string('manifest_key', 160)->unique(); $table->foreignId('lab_agent_id')->nullable()->constrained('lab_agents')->nullOnDelete();
            $table->foreignId('lab_generation_id')->nullable()->constrained('lab_generations')->nullOnDelete(); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('experiment_role', 32); $table->string('status', 32); $table->json('manifest'); $table->json('validation'); $table->dateTime('sealed_at', 6); $table->timestamps();
            $table->index(['lab_generation_id', 'experiment_role', 'status'], 'inheritance_manifest_generation_idx');
        });
        Schema::create('generation_closed_loop_audits', function (Blueprint $table): void {
            $table->id(); $table->string('audit_key', 160)->unique(); $table->foreignId('lab_generation_id')->constrained('lab_generations')->cascadeOnDelete();
            $table->string('status', 32); $table->decimal('replay_coverage', 8, 6)->default(0); $table->decimal('settlement_coverage', 8, 6)->default(0); $table->decimal('attribution_coverage', 8, 6)->default(0); $table->decimal('learning_decision_coverage', 8, 6)->default(0); $table->decimal('inheritance_manifest_coverage', 8, 6)->default(0); $table->json('report'); $table->dateTime('audited_at', 6); $table->timestamps();
            $table->unique('lab_generation_id', 'generation_closed_loop_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_closed_loop_audits'); Schema::dropIfExists('agent_inheritance_manifests'); Schema::dropIfExists('evolution_learning_receipts');
    }
};
