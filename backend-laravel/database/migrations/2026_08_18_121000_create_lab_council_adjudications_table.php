<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_council_adjudications', function (Blueprint $table): void {
            $table->id();
            $table->string('adjudication_key', 128)->unique();
            $table->foreignId('disagreement_id')->constrained('lab_council_disagreements')->cascadeOnDelete();
            $table->string('decision', 16);
            $table->string('evidence_run_id', 128);
            $table->string('replay_hash', 160);
            $table->json('window_keys');
            $table->json('role_votes')->nullable();
            $table->json('evidence')->nullable();
            $table->string('approved_by', 128);
            $table->text('approval_reason');
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['disagreement_id', 'decision'], 'lab_adjudication_disagreement_decision_idx');
            $table->index(['evidence_run_id'], 'lab_adjudication_evidence_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_council_adjudications');
    }
};
