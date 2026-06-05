<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('strategy_scores')) {
            return;
        }

        Schema::create('strategy_scores', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('timeframe');
            $table->string('strategy');
            $table->integer('score')->default(0);
            $table->integer('total_trades')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->decimal('winrate', 8, 2)->default(0);
            $table->decimal('net_profit_percent', 8, 2)->default(0);
            $table->decimal('max_drawdown_percent', 8, 2)->nullable()->default(0);
            $table->decimal('profit_factor', 8, 2)->nullable()->default(0);
            $table->json('raw_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_scores');
    }
};
