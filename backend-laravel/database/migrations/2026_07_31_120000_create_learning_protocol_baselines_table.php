<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_protocol_baselines', function (Blueprint $table): void {
            $table->id();
            $table->string('protocol_version', 96);
            $table->foreignId('lab_generation_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot_hash', 64);
            $table->json('snapshot');
            $table->timestamp('frozen_at');
            $table->timestamps();

            // A frozen generation is append-only evidence.  Re-running the
            // command must never rewrite an old baseline with newer facts.
            $table->unique(['protocol_version', 'lab_generation_id'], 'protocol_baseline_generation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_protocol_baselines');
    }
};
