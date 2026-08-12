<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lighthouse_vertical_loop_monitor_runs')) {
            return;
        }

        Schema::create('lighthouse_vertical_loop_monitor_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lab_generation_id')->nullable();
            $table->string('symbol', 16);
            $table->string('timeframe', 16);
            $table->unsignedInteger('generation')->nullable();
            $table->string('stage', 40);
            $table->string('status', 24);
            $table->decimal('health_score', 6, 2)->default(0);
            $table->json('report');
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('lab_generation_id', 'lighthouse_loop_generation_fk')
                ->references('id')->on('lab_generations')->nullOnDelete();
            $table->index(['symbol', 'timeframe', 'checked_at'], 'lighthouse_loop_scope_time_idx');
            $table->index(['stage', 'status', 'checked_at'], 'lighthouse_loop_stage_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lighthouse_vertical_loop_monitor_runs');
    }
};
