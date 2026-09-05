<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_technical_failures')) return;
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) return;

        // On older MariaDB versions the first TIMESTAMP column receives an
        // implicit ON UPDATE CURRENT_TIMESTAMP clause even though the Laravel
        // migration never requested it. State transitions must not impersonate
        // a new technical failure, so last_seen_at is write-only from record().
        // DEFAULT must be explicit on MariaDB; omitting it recreates the
        // legacy implicit DEFAULT + ON UPDATE behavior for the first
        // TIMESTAMP column.
        DB::statement('ALTER TABLE learning_technical_failures MODIFY last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        if (! Schema::hasTable('learning_technical_failures')) return;
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) return;

        DB::statement('ALTER TABLE learning_technical_failures MODIFY last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
