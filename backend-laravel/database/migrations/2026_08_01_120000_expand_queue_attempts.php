<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Queue releases count as attempts. A heavy shared replay can legitimately
     * be released more than 255 times while another market owns the AI lane;
     * Laravel's default unsignedTinyInteger then turns a healthy job into a
     * database error before its bounded retry window is reached.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempts')->default(0)->change();
        });
    }
};
