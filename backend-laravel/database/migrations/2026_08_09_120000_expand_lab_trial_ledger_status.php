<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_trial_ledger')) {
            return;
        }

        Schema::table('lab_trial_ledger', function (Blueprint $table): void {
            // Evidence statuses include explicit contract/quarantine states;
            // varchar(24) truncated `invalid_execution_contract` and caused
            // the integrity repair itself to fail.
            $table->string('status', 64)->default('recorded')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lab_trial_ledger')) {
            return;
        }

        Schema::table('lab_trial_ledger', function (Blueprint $table): void {
            $table->string('status', 24)->default('recorded')->change();
        });
    }
};
