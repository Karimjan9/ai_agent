<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'training_sessions',
        'strategy_scores',
        'model_versions',
        'model_market_performance',
        'paper_trading_evaluations',
        'paper_orders',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('evidence_status', 24)->default('valid')->index();
                $blueprint->timestamp('invalidated_at')->nullable();
                $blueprint->string('invalidation_reason')->nullable();
            });
        }

        $cutover = now();
        foreach ($this->tables as $table) {
            DB::table($table)->where('evidence_status', 'valid')->update([
                'evidence_status' => 'legacy_invalid',
                'invalidated_at' => $cutover,
                'invalidation_reason' => 'pre_p0_dataset_and_execution_simulator',
            ]);
        }

        // A pre-cutover open simulation must not leak into the new paper
        // observation window. It remains queryable and is never deleted.
        DB::table('paper_orders')->where('status', 'open')->update([
            'status' => 'invalidated',
            'closed_at' => $cutover,
        ]);
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['evidence_status']);
                $blueprint->dropColumn(['evidence_status', 'invalidated_at', 'invalidation_reason']);
            });
        }
    }
};
