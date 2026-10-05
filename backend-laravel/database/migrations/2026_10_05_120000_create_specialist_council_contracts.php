<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialist_council_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('council_id', 100);
            $table->string('version', 100);
            $table->string('creator_id', 150);
            $table->string('state', 30)->default('draft');
            $table->json('manifest');
            $table->char('manifest_hash', 64);
            $table->string('evaluator_id', 150)->nullable();
            $table->json('assessment')->nullable();
            $table->char('assessment_hash', 64)->nullable();
            $table->json('paper_authority')->nullable();
            $table->unsignedBigInteger('previous_version_id')->nullable();
            // Literal UTC instants are supplied by the immutable owner. Legacy MySQL
            // TIMESTAMP defaults can silently synthesize zero/current dates in strict mode.
            $table->dateTime('sealed_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['council_id', 'version'], 'sc_version_identity_unique');
            $table->index(['council_id', 'state', 'effective_at'], 'sc_version_boundary_index');
        });
        Schema::create('specialist_council_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('specialist_council_version_id')->index('sc_eval_version_index');
            $table->string('evaluator_id', 150);
            $table->json('original_run_ids');
            $table->json('assessment');
            $table->char('assessment_hash', 64);
            $table->timestamps();
        });
        Schema::create('specialist_council_evaluation_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('specialist_council_version_id')->unique('sc_eval_plan_version_unique');
            $table->string('evaluator_id', 150);
            $table->json('plan');
            $table->char('plan_hash', 64);
            $table->dateTime('sealed_at');
            $table->timestamps();
        });
        Schema::create('specialist_council_evaluation_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 100)->unique('sc_delivery_run_unique');
            $table->unsignedBigInteger('specialist_council_version_id')->index('sc_delivery_version_index');
            $table->string('status', 30)->default('pending')->index('sc_delivery_status_index');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('receipt')->nullable();
            $table->timestamps();
        });
        Schema::create('specialist_council_data_events', function (Blueprint $table): void {
            $table->id();
            $table->char('event_key', 64)->unique('sc_event_key_unique');
            $table->string('symbol', 30);
            $table->string('market', 50);
            $table->dateTime('event_start');
            $table->dateTime('event_end');
            $table->dateTime('available_at');
            $table->timestamp('matured_at')->nullable();
            $table->json('provenance');
            $table->timestamps();
            $table->index(['symbol', 'event_start', 'event_end'], 'specialist_events_overlap');
        });
        Schema::create('specialist_council_data_uses', function (Blueprint $table): void {
            $table->id();
            $table->char('usage_key', 64)->unique('sc_use_key_unique');
            $table->unsignedBigInteger('specialist_council_version_id')->index('sc_use_version_index');
            $table->string('council_id', 100)->index('sc_use_council_index');
            $table->unsignedBigInteger('event_id')->index('sc_use_event_index');
            $table->string('use', 30);
            $table->string('consumer_id', 150);
            $table->string('run_id', 100)->nullable()->index('sc_use_run_index');
            $table->dateTime('as_of');
            $table->char('policy_hash', 64);
            $table->timestamps();
        });
        Schema::create('specialist_council_data_locks', function (Blueprint $table): void {
            $table->string('symbol', 30)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_council_data_uses');
        Schema::dropIfExists('specialist_council_data_locks');
        Schema::dropIfExists('specialist_council_data_events');
        Schema::dropIfExists('specialist_council_evaluations');
        Schema::dropIfExists('specialist_council_evaluation_deliveries');
        Schema::dropIfExists('specialist_council_evaluation_plans');
        Schema::dropIfExists('specialist_council_versions');
    }
};
