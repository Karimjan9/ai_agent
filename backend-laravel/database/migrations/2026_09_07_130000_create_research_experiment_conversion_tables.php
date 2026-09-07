<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional.  Keep this migration resumable if
        // a deploy is interrupted after the first CREATE TABLE.
        if (! Schema::hasTable('research_experiment_receipts')) {
            Schema::create('research_experiment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_key', 160)->unique();
            $table->string('source_type', 160);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('canonical_learning_outbox_id')->nullable()->unique();
            $table->string('symbol', 16);
            $table->string('laboratory_timeframe', 16);
            $table->string('execution_timeframe', 16);
            $table->string('contract_version', 64);
            $table->string('rule_version', 64);
            $table->string('contract_hash', 128);
            $table->string('evidence_hash', 128);
            $table->string('classification', 64);
            $table->unsignedInteger('subject_revision')->default(1);
            $table->unsignedInteger('evidence_revision')->default(1);
            $table->json('payload');
            $table->json('terminal_reason')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'laboratory_timeframe', 'classification'], 'research_receipt_scope_class_idx');
            $table->index(['source_type', 'source_id'], 'research_receipt_source_idx');
            });
        }

        if (! Schema::hasTable('research_experiment_work_items')) {
            Schema::create('research_experiment_work_items', function (Blueprint $table): void {
            $table->id();
            $table->string('work_key', 160)->unique();
            $table->unsignedBigInteger('research_experiment_receipt_id');
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('work_type', 64);
            $table->string('status', 24)->default('ready');
            $table->unsignedTinyInteger('priority')->default(5);
            $table->string('dependency_key', 160)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->unsignedBigInteger('fence_version')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->json('payload');
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority', 'lease_expires_at'], 'research_work_claim_idx');
            $table->index(['symbol', 'timeframe', 'work_type', 'status'], 'research_work_scope_idx');
            });
        }
        // Laravel's inferred foreign-key name exceeds MySQL's 64-character
        // limit for this table/column pair.  The explicit short name is also
        // needed to resume the partial DDL produced by an older deployment.
        Schema::table('research_experiment_work_items', function (Blueprint $table): void {
            $table->foreign('research_experiment_receipt_id', 'research_work_receipt_fk')
                ->references('id')->on('research_experiment_receipts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_experiment_work_items');
        Schema::dropIfExists('research_experiment_receipts');
    }
};
