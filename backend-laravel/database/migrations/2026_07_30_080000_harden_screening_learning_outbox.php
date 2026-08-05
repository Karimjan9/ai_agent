<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->string('outcome', 64)->change();
        });
        Schema::create('screening_learning_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained()->cascadeOnDelete();
            $table->json('screen_result');
            $table->decimal('forward_score', 10, 2)->default(0);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_learning_outbox');
        Schema::table('mutation_memories', function (Blueprint $table): void {
            $table->string('outcome', 16)->change();
        });
    }
};
