<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Schema;

class SystemLogService
{
    public function write(string $type, string $message, array $context = [], string $level = 'info', ?string $component = null, ?string $action = null, ?string $status = null, ?string $sourceType = null, ?int $sourceId = null): ?SystemLog
    {
        if (! Schema::hasTable('system_logs')) {
            return null;
        }

        return SystemLog::create([
            'log_type' => $type,
            'level' => $level,
            'component' => $component,
            'action' => $action,
            'status' => $status,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
