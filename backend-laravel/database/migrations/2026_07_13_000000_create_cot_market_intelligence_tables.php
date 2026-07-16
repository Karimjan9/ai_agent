<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cot_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
            $table->string('source')->default('cftc_disaggregated_futures_only');
            $table->string('source_record_id')->unique();
            $table->string('market_name');
            $table->date('report_date');
            $table->dateTime('available_at');
            $table->boolean('release_time_estimated')->default(true);
            $table->unsignedBigInteger('open_interest')->nullable();
            $table->integer('managed_money_long')->nullable();
            $table->integer('managed_money_short')->nullable();
            $table->integer('managed_money_spread')->nullable();
            $table->integer('managed_money_net')->nullable();
            $table->integer('commercial_long')->nullable();
            $table->integer('commercial_short')->nullable();
            $table->integer('commercial_net')->nullable();
            $table->json('raw_payload');
            $table->dateTime('ingested_at');
            $table->timestamps();

            $table->unique(['symbol', 'report_date']);
            $table->index(['symbol', 'report_date']);
            $table->index('available_at');
        });

        Schema::create('cot_feature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cot_report_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->string('feature_version')->default('cot_v1');
            $table->date('report_date');
            $table->dateTime('available_at');
            $table->integer('managed_money_net');
            $table->integer('managed_money_delta_1w')->nullable();
            $table->integer('managed_money_delta_4w')->nullable();
            $table->decimal('managed_money_average_12w', 14, 2)->nullable();
            $table->decimal('managed_money_percentile_3y', 6, 2)->nullable();
            $table->decimal('commercial_percentile_3y', 6, 2)->nullable();
            $table->decimal('crowding_index', 6, 2)->nullable();
            $table->string('positioning_state');
            $table->string('weekly_bias');
            $table->decimal('confidence_score', 6, 2);
            $table->json('features');
            $table->timestamps();

            $table->unique(['cot_report_id', 'feature_version']);
            $table->index(['symbol', 'report_date']);
            $table->index(['symbol', 'weekly_bias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cot_feature_snapshots');
        Schema::dropIfExists('cot_reports');
    }
};
