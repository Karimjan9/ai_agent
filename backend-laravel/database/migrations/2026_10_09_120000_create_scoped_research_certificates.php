<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoped_research_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('certificate_key', 64)->unique();
            $table->string('protocol', 64);
            $table->string('scope', 32);
            $table->string('record_type', 32);
            $table->foreignId('parent_certificate_id')->nullable()
                ->constrained('scoped_research_certificates')->restrictOnDelete();
            // Source deletion/drift invalidates inspection; it must not delete the receipt.
            $table->string('source_type', 160);
            $table->unsignedBigInteger('source_id');
            $table->string('source_hash', 64);
            $table->string('design_hash', 64);
            $table->string('payload_hash', 64);
            $table->string('server_seal', 64);
            $table->json('payload');
            $table->dateTime('preregistered_at', 6);
            $table->dateTime('recorded_at', 6);
            $table->index(['source_type', 'source_id', 'scope'], 'scoped_certificate_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoped_research_certificates');
    }
};
