<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_training_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key', 64);
            $table->string('provider', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            // The end boundary is exclusive for every backfill request.
            $table->dateTime('target_from');
            $table->dateTime('target_to');
            $table->dateTime('backfill_cursor_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedInteger('completed_chunks')->default(0);
            $table->unsignedInteger('failed_chunks')->default(0);
            $table->dateTime('first_candle_at')->nullable();
            $table->dateTime('last_candle_at')->nullable();
            $table->dateTime('last_chunk_from')->nullable();
            $table->dateTime('last_chunk_to')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(
                ['dataset_key', 'provider', 'symbol', 'timeframe'],
                'market_training_archive_identity',
            );
            $table->index(['symbol', 'timeframe', 'status'], 'market_training_archive_status');
        });

        Schema::create('market_training_candles', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key', 64);
            $table->string('provider', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->dateTime('time');
            $table->decimal('open', 18, 6);
            $table->decimal('high', 18, 6);
            $table->decimal('low', 18, 6);
            $table->decimal('close', 18, 6);
            $table->decimal('volume', 20, 6)->default(0);
            $table->timestamps();

            $table->unique(
                ['dataset_key', 'provider', 'symbol', 'timeframe', 'time'],
                'market_training_candle_identity',
            );
            $table->index(
                ['dataset_key', 'symbol', 'timeframe', 'time'],
                'market_training_candle_timeline',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_training_candles');
        Schema::dropIfExists('market_training_archives');
    }
};
