<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_exposure_capture_records', function (Blueprint $table): void {
            $table->id();
            $table->string('record_key', 64)->unique();
            $table->foreignId('certificate_id')->constrained('scoped_research_certificates')->restrictOnDelete();
            $table->string('record_type', 24);
            $table->unsignedBigInteger('evaluation_run_id')->nullable();
            $table->string('payload_hash', 64);
            $table->string('server_seal', 64);
            $table->json('payload');
            $table->dateTime('recorded_at', 6);
            $table->index(['certificate_id', 'record_type'], 'research_exposure_owner_idx');
            $table->index('evaluation_run_id', 'research_exposure_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_exposure_capture_records');
    }
};
