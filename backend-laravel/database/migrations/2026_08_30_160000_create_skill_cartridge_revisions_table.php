<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_cartridge_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_skill_zoo_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('revision_key', 160)->unique();
            $table->json('payload');
            $table->timestamp('sealed_at');
            $table->timestamps();
            $table->unique(['lab_skill_zoo_entry_id', 'revision'], 'skill_cartridge_revision_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_cartridge_revisions');
    }
};
