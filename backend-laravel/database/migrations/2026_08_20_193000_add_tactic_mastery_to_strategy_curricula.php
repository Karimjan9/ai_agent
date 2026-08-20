<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_curriculum_contracts', function (Blueprint $table): void {
            $table->string('tactic_id', 96)->nullable()->after('strategy_version');
            $table->string('tactic_mastery_stage', 32)->default('tactic_seed')->after('training_stage');
            $table->json('entry_contract')->nullable()->after('target_sessions');
            $table->json('exit_contract')->nullable()->after('entry_contract');
            $table->json('sizing_contract')->nullable()->after('exit_contract');
            $table->json('risk_contract')->nullable()->after('sizing_contract');
            $table->string('control_pair_key', 160)->nullable()->after('control_contract');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_curriculum_contracts', function (Blueprint $table): void {
            $table->dropColumn(['tactic_id', 'tactic_mastery_stage', 'entry_contract', 'exit_contract', 'sizing_contract', 'risk_contract', 'control_pair_key']);
        });
    }
};
