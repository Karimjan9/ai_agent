<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dual_track_snapshot_manifests', function (Blueprint $table): void {
            $table->id();
            $table->string('snapshot_hash', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->unsignedInteger('candle_count')->default(0);
            $table->timestamp('first_candle_at')->nullable();
            $table->timestamp('latest_candle_at')->nullable();
            $table->string('dataset_hash', 160);
            $table->string('feature_config_hash', 160)->nullable();
            $table->string('execution_config_hash', 160)->nullable();
            $table->json('manifest')->nullable();
            $table->string('status', 32)->default('sealed');
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'latest_candle_at'], 'twin_snapshot_scope_idx');
        });

        Schema::create('dual_track_promotion_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('decision_key', 180)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('cell_key', 160);
            $table->string('requested_lane', 24)->default('incumbent');
            $table->string('status', 32)->default('blocked');
            $table->boolean('allowed')->default(false);
            $table->json('reasons')->nullable();
            $table->json('evidence')->nullable();
            $table->string('evidence_hash', 160);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['cell_key', 'status', 'expires_at'], 'twin_promotion_cell_status_idx');
        });

        Schema::create('dual_track_settlement_states', function (Blueprint $table): void {
            $table->id();
            $table->string('state_key', 180)->unique();
            $table->foreignId('dual_track_run_id')->nullable()->constrained('dual_track_runs')->nullOnDelete();
            $table->foreignId('paper_signal_outcome_id')->nullable()->constrained('paper_signal_outcomes')->nullOnDelete();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('stage', 32)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('completed_stages')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['stage', 'last_attempted_at'], 'twin_settlement_stage_idx');
        });

        Schema::create('dual_track_genome_archive_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 180)->unique();
            $table->string('archive_key', 180);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('lane', 24);
            $table->string('cell_key', 160);
            $table->string('behavior_cell', 160);
            $table->string('genome_hash', 160);
            $table->decimal('fitness_score', 14, 6)->default(0);
            $table->decimal('novelty_score', 14, 6)->default(0);
            $table->string('event_type', 32)->default('observed');
            $table->json('genes')->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();
            $table->index(['archive_key', 'created_at'], 'twin_archive_event_lookup_idx');
        });

        Schema::table('dual_track_exchange_packets', function (Blueprint $table): void {
            $table->string('delivery_status', 32)->default('observed')->after('status');
            $table->string('revalidation_hash', 160)->nullable()->after('delivery_status');
            $table->timestamp('revalidated_at')->nullable()->after('revalidation_hash');
            $table->timestamp('expires_at')->nullable()->after('revalidated_at');
        });
    }

    public function down(): void
    {
        Schema::table('dual_track_exchange_packets', function (Blueprint $table): void {
            $table->dropColumn(['delivery_status', 'revalidation_hash', 'revalidated_at', 'expires_at']);
        });
        Schema::dropIfExists('dual_track_genome_archive_events');
        Schema::dropIfExists('dual_track_settlement_states');
        Schema::dropIfExists('dual_track_promotion_decisions');
        Schema::dropIfExists('dual_track_snapshot_manifests');
    }
};
