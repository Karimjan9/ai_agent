<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volume_shadow_experiments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lab_agent_id')->nullable();
            $table->unsignedBigInteger('model_version_id')->nullable();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->string('status', 32);
            $table->string('protocol', 96);
            $table->string('source_contract', 128);
            $table->string('data_hash', 128)->nullable();
            $table->json('metrics')->nullable();
            $table->boolean('promotion_evidence')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'status'], 'volume_shadow_market_status');
            $table->index(['model_version_id', 'created_at'], 'volume_shadow_model_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volume_shadow_experiments');
    }
};
