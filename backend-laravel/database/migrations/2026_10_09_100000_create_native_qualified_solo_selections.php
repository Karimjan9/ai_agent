<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_qualified_solo_selections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('specialist_council_version_id')->unique('native_solo_selection_original_unique');
            $table->string('panel_kind', 30)->index('native_solo_selection_kind_index');
            $table->char('plan_hash', 64);
            $table->char('selection_hash', 64)->unique('native_solo_selection_hash_unique');
            $table->json('receipt');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_qualified_solo_selections');
    }
};
