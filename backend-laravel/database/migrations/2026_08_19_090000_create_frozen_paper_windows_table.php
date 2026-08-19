<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frozen_paper_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_key', 64);
            $table->string('provider', 32);
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            // All range ends are exclusive. The training lane may use only
            // data before training_ends_at; paper data is the sealed range.
            $table->dateTime('training_starts_at');
            $table->dateTime('training_ends_at');
            $table->dateTime('paper_starts_at');
            $table->dateTime('paper_ends_at');
            $table->unsignedInteger('months')->default(6);
            $table->string('snapshot_path');
            $table->string('snapshot_sha256', 64);
            $table->unsignedBigInteger('row_count');
            $table->dateTime('frozen_at');
            $table->timestamps();

            $table->unique(
                ['dataset_key', 'provider', 'symbol', 'timeframe'],
                'frozen_paper_window_identity',
            );
            $table->index(['symbol', 'timeframe', 'training_ends_at'], 'frozen_paper_training_cutoff');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frozen_paper_windows');
    }
};
