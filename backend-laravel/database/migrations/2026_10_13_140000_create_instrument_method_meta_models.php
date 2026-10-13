<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_method_meta_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('registration_hash', 64)->unique();
            $table->string('model_key', 150)->unique();
            $table->json('design');
            $table->json('assessment')->nullable();
            $table->timestamps();
        });
        Schema::create('instrument_method_meta_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_hash', 64)->unique();
            $table->string('registration_hash', 64)->unique();
            $table->longText('artifact_json');
            $table->string('issuer_signature', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_method_meta_models');
        Schema::dropIfExists('instrument_method_meta_registrations');
    }
};
