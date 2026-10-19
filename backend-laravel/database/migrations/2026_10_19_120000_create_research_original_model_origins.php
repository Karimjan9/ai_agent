<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_original_model_origins', function (Blueprint $table): void {
            $table->id();
            $table->string('origin_key', 64)->unique();
            $table->foreignId('lab_generation_id')->unique()->constrained('lab_generations')->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->string('server_seal', 64);
            $table->json('payload');
            $table->dateTime('recorded_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_original_model_origins');
    }
};
