<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_funnel_entries', function (Blueprint $table): void {
            $table->id(); $table->string('opportunity_key', 128)->unique();
            $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('composition_id', 96)->nullable();
            $table->string('stage', 48); $table->string('decision', 16); $table->string('rejected_reason', 128)->nullable();
            $table->json('funnel'); $table->json('evidence_snapshot')->nullable();
            $table->decimal('expected_value_before_filter', 14, 6)->nullable(); $table->decimal('expected_value_after_filter', 14, 6)->nullable();
            $table->json('shadow_outcome')->nullable(); $table->dateTime('available_at', 6); $table->dateTime('decided_at', 6); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'decision', 'stage'], 'opportunity_funnel_scope_idx');
        });
        Schema::create('location_atlas_entries', function (Blueprint $table): void {
            $table->id(); $table->string('atlas_key', 128)->unique(); $table->string('symbol', 16); $table->string('timeframe', 16);
            $table->string('location_type', 64); $table->string('definition_version', 32); $table->string('state', 32);
            $table->dateTime('formed_at', 6); $table->dateTime('available_at', 6); $table->dateTime('expires_at', 6)->nullable(); $table->dateTime('invalidated_at', 6)->nullable();
            $table->decimal('strength', 8, 4)->default(0); $table->unsignedInteger('touch_count')->default(0); $table->decimal('freshness', 8, 4)->default(0); $table->decimal('distance_in_atr', 12, 6)->nullable(); $table->json('contract'); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'location_type', 'state'], 'location_atlas_scope_idx');
        });
        Schema::create('trade_path_laboratory_runs', function (Blueprint $table): void {
            $table->id(); $table->string('run_key', 128)->unique(); $table->foreignId('model_version_id')->nullable()->constrained('model_versions')->nullOnDelete();
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('composition_id', 96)->nullable(); $table->string('entry_hash', 128); $table->string('status', 32); $table->json('paths'); $table->json('attribution')->nullable(); $table->json('evidence')->nullable(); $table->dateTime('settled_at', 6)->nullable(); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'status'], 'trade_path_lab_scope_idx');
        });
        Schema::create('risk_hysteresis_states', function (Blueprint $table): void {
            $table->id(); $table->string('state_key', 96)->unique(); $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('state', 16); $table->decimal('risk_multiplier', 8, 4); $table->string('transition_reason', 128); $table->json('metrics'); $table->dateTime('changed_at', 6); $table->timestamps();
            $table->index(['symbol', 'timeframe', 'state'], 'risk_hysteresis_scope_idx');
        });
        Schema::create('winner_only_pyramiding_ledger_entries', function (Blueprint $table): void {
            $table->id(); $table->string('ledger_key', 128)->unique(); $table->string('position_key', 128);
            $table->string('symbol', 16); $table->string('timeframe', 16); $table->string('state', 32);
            $table->unsignedInteger('add_number')->default(0); $table->decimal('open_risk_before', 14, 6);
            $table->decimal('open_risk_after', 14, 6); $table->decimal('initial_risk_limit', 14, 6);
            $table->decimal('unrealized_r', 14, 6); $table->json('contract'); $table->dateTime('decided_at', 6); $table->timestamps();
            $table->index(['position_key', 'state'], 'pyramiding_position_state_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('winner_only_pyramiding_ledger_entries'); Schema::dropIfExists('risk_hysteresis_states'); Schema::dropIfExists('trade_path_laboratory_runs'); Schema::dropIfExists('location_atlas_entries'); Schema::dropIfExists('opportunity_funnel_entries'); }
};
