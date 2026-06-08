<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evolution_proposals')) {
            return;
        }

        Schema::create('evolution_proposals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('training_session_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('model_version_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('strategy');
            $table->string('current_version')->default('v1');
            $table->string('proposed_version')->default('v2');
            $table->integer('current_score')->default(0);
            $table->string('main_problem')->nullable();
            $table->longText('reason')->nullable();
            $table->longText('proposal')->nullable();
            $table->json('old_parameters')->nullable();
            $table->json('new_parameters')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolution_proposals');
    }
};
