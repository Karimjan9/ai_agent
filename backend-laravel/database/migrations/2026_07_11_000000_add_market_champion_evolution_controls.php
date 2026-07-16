<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_market_performance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_version_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('timeframe', 16);
            $table->string('strategy_family', 64);
            $table->string('status', 32)->default('challenger');
            $table->string('champion_slot', 16)->nullable();
            $table->decimal('fitness', 8, 2)->default(0);
            $table->decimal('forward_score', 8, 2)->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('rolling_windows_count')->default(0);
            $table->unsignedInteger('rolling_forward_wins')->default(0);
            $table->unsignedInteger('consecutive_no_improvement')->default(0);
            $table->json('metrics')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['model_version_id', 'symbol', 'timeframe'], 'model_market_performance_model_market_unique');
            $table->unique(['symbol', 'timeframe', 'strategy_family', 'champion_slot'], 'model_market_performance_one_champion_unique');
            $table->index(['symbol', 'timeframe', 'strategy_family', 'status'], 'model_market_performance_champion_lookup');
        });

        Schema::table('evolution_proposals', function (Blueprint $table): void {
            $table->foreignId('parent_model_version_id')->nullable()->after('model_version_id')->constrained('model_versions')->nullOnDelete();
            $table->foreignId('applied_model_version_id')->nullable()->after('parent_model_version_id')->constrained('model_versions')->nullOnDelete();
            $table->string('open_status', 16)->nullable()->after('status');
            $table->unique(['parent_model_version_id', 'proposed_version', 'open_status'], 'evolution_proposals_one_open_unique');
        });
    }

    public function down(): void
    {
        Schema::table('evolution_proposals', function (Blueprint $table): void {
            $table->dropUnique('evolution_proposals_one_open_unique');
            $table->dropConstrainedForeignId('applied_model_version_id');
            $table->dropConstrainedForeignId('parent_model_version_id');
            $table->dropColumn('open_status');
        });
        Schema::dropIfExists('model_market_performance');
    }
};
