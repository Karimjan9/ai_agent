<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_instrument_abstractions', function (Blueprint $table): void {
            $table->id();
            $table->string('macro_key', 64)->unique();
            $table->string('scope_key', 64)->index();
            $table->string('status', 40);
            $table->string('result_type', 16);
            $table->json('definition');
            $table->json('source_evidence');
            $table->json('validation_evidence')->nullable();
            $table->unsignedInteger('definition_nodes');
            $table->integer('net_savings');
            $table->timestamps();
        });
        Schema::create('research_behavior_archive', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_key', 64)->unique();
            $table->string('run_id', 64)->unique();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('scope_key', 64);
            $table->string('descriptor_cell', 64);
            $table->string('status', 40);
            $table->json('descriptors');
            $table->json('research_value');
            $table->json('confirmed_value')->nullable();
            $table->json('evidence');
            $table->timestamps();
            $table->index(['scope_key', 'descriptor_cell'], 'research_behavior_scope_cell_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_behavior_archive');
        Schema::dropIfExists('research_instrument_abstractions');
    }
};
