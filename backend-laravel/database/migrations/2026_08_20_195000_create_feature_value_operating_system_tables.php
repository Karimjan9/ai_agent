<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_value_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_key', 96)->unique();
            $table->string('layer', 32);
            $table->string('unit', 32);
            $table->string('formula_version', 48);
            $table->json('definition');
            $table->json('eligible_lanes');
            $table->boolean('lookahead_safe')->default(true);
            $table->timestamps();
        });
        Schema::create('feature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('snapshot_key', 160)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->dateTime('as_of');
            $table->dateTime('available_at');
            $table->string('data_hash', 128);
            $table->json('values');
            $table->json('provenance');
            $table->timestamps();
            $table->index(['symbol', 'timeframe', 'as_of'], 'feature_snapshot_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_snapshots');
        Schema::dropIfExists('feature_value_catalog');
    }
};
