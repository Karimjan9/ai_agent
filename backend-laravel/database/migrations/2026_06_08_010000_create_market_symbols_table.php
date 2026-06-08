<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_symbols')) {
            return;
        }

        Schema::create('market_symbols', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('provider_symbol')->nullable();
            $table->string('name')->nullable();
            $table->string('market_type')->default('forex');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_symbols');
    }
};
