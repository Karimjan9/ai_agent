<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_logs')) {
            return;
        }

        Schema::create('training_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('auto_training');
            $table->string('status')->default('pending');
            $table->foreignId('training_session_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->longText('message')->nullable();
            $table->longText('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_logs');
    }
};
