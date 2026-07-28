<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The first migration may have run on MySQL with an earlier generated
        // name; add the explicit, short FK only when it is absent.
        if (Schema::hasTable('paper_confidence_calibrations') && Schema::hasTable('model_market_performance')) {
            Schema::table('paper_confidence_calibrations', function (Blueprint $table): void {
                $table->foreign('model_market_performance_id', 'pcc_performance_fk')
                    ->references('id')->on('model_market_performance')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('paper_confidence_calibrations', function (Blueprint $table): void {
            $table->dropForeign('pcc_performance_fk');
        });
    }
};
