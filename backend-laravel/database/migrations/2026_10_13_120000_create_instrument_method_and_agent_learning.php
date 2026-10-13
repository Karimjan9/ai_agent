<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_method_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 64)->unique();
            $table->string('kind', 32);
            $table->text('reference')->nullable();
            $table->string('content_hash', 64);
            $table->longText('content');
            $table->json('metadata');
            $table->timestamp('captured_at');
            $table->timestamps();
        });
        Schema::create('instrument_method_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('method_hash', 64)->unique();
            $table->string('instrument_key', 100);
            $table->string('method_key', 100);
            $table->unsignedInteger('revision');
            $table->json('contract');
            $table->json('source_keys');
            $table->timestamps();
            $table->unique(['instrument_key', 'method_key', 'revision'], 'instrument_method_revision_unique');
        });
        Schema::create('instrument_recipe_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('recipe_hash', 64)->unique();
            $table->string('recipe_key', 100);
            $table->unsignedInteger('revision');
            $table->json('contract');
            $table->timestamps();
            $table->unique(['recipe_key', 'revision'], 'instrument_recipe_revision_unique');
        });
        Schema::create('agent_method_policy_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('policy_hash', 64)->unique();
            $table->string('agent_key', 150);
            $table->unsignedInteger('revision');
            $table->string('library_hash', 64);
            $table->json('contract');
            $table->timestamps();
            $table->unique(['agent_key', 'revision'], 'agent_method_policy_revision_unique');
        });
        Schema::create('agent_method_experiences', function (Blueprint $table) {
            $table->id();
            $table->string('experience_key', 64)->unique();
            $table->string('policy_hash', 64)->index();
            $table->string('run_id', 64)->index();
            $table->string('recipe_hash', 64)->nullable();
            $table->string('context_key', 64);
            $table->json('facts');
            $table->timestamps();
        });
        Schema::create('instrument_method_studies', function (Blueprint $table) {
            $table->id();
            $table->string('study_hash', 64)->unique();
            $table->string('study_key', 150)->unique();
            $table->json('design');
            $table->json('assessment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_method_studies');
        Schema::dropIfExists('agent_method_experiences');
        Schema::dropIfExists('agent_method_policy_revisions');
        Schema::dropIfExists('instrument_recipe_revisions');
        Schema::dropIfExists('instrument_method_revisions');
        Schema::dropIfExists('instrument_method_sources');
    }
};
