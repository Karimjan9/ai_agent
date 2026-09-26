<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('causal_fold_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_key', 128)->unique();
            $table->foreignId('agent_learning_causal_experiment_id')
                ->constrained('agent_learning_causal_experiments')->cascadeOnDelete();
            $table->foreignId('lab_generation_id')->constrained('lab_generations')->cascadeOnDelete();
            $table->unsignedSmallInteger('fold_index');
            $table->unsignedSmallInteger('fold_count');
            $table->string('status', 32)->default('planned');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->string('dataset_hash', 64)->nullable();
            $table->string('execution_hash', 64)->nullable();
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('observed_at', 6);
            $table->timestamps();

            $table->unique(
                ['agent_learning_causal_experiment_id', 'fold_index'],
                'causal_fold_experiment_index_unique',
            );
            $table->index(
                ['lab_generation_id', 'status', 'fold_index'],
                'causal_fold_generation_status_idx',
            );
        });

        Schema::create('generation_autonomy_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_key', 128)->unique();
            $table->foreignId('lab_generation_id')->unique()->constrained('lab_generations')->cascadeOnDelete();
            $table->foreignId('arbiter_decision_id')->constrained('research_loop_decisions')->restrictOnDelete();
            $table->foreignId('successor_generation_id')->constrained('lab_generations')->restrictOnDelete();
            $table->string('state', 64);
            $table->string('receipt_hash', 64)->unique();
            $table->longText('payload');
            $table->dateTime('observed_at', 6);
            $table->timestamps();

            $table->index(['state', 'observed_at'], 'generation_autonomy_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_autonomy_receipts');
        Schema::dropIfExists('causal_fold_receipts');
    }
};
