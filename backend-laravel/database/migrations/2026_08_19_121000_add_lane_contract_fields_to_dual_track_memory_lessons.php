<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dual_track_memory_lessons', function (Blueprint $table): void {
            $table->string('memory_namespace', 96)->nullable()->after('lane');
            $table->string('learning_objective', 120)->nullable()->after('memory_namespace');
            $table->string('failure_class', 96)->nullable()->after('failure_signature');
            $table->json('reward_components')->nullable()->after('confidence');
            $table->json('transfer_policy')->nullable()->after('evidence');
            $table->json('promotion_policy')->nullable()->after('transfer_policy');
        });
    }

    public function down(): void
    {
        Schema::table('dual_track_memory_lessons', function (Blueprint $table): void {
            $table->dropColumn([
                'memory_namespace', 'learning_objective', 'failure_class', 'reward_components',
                'transfer_policy', 'promotion_policy',
            ]);
        });
    }
};
