<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Structured JSONL error writer for the lifecycle orchestrator.
 *
 * Writes to storage/logs/neurotrader/lifecycle-errors/YYYY-MM-DD.jsonl
 * One JSON object per line, redacted of secrets/tokens/env/command-line/SQL.
 */
class LabLifecycleErrorLogger
{
    public function record(
        string $cycleId,
        string $symbol,
        string $timeframe,
        string $stage,
        Throwable $error,
        ?int $generationId = null,
        ?int $agentId = null,
        ?string $jobId = null,
    ): void {
        $date = (string) Date::now('Asia/Tashkent')->format('Y-m-d');
        $path = $this->errorPath($date);

        $record = [
            'timestamp_utc' => Carbon::now('UTC')->toIso8601String(),
            'timestamp_tashkent' => Carbon::now('Asia/Tashkent')->toIso8601String(),
            'cycle_id' => $cycleId,
            'symbol' => strtoupper($symbol),
            'timeframe' => $timeframe,
            'stage' => $stage,
            'severity' => $this->severity($error),
            'error_code' => $this->errorCode($error),
            'error_class' => $error::class,
            'safe_message' => $this->safeMessage($error),
            'retryable' => $this->retryable($error),
            'affected' => [
                'generation_id' => $generationId,
                'agent_id' => $agentId,
                'job_id' => $jobId,
            ],
            'stack_trace' => $this->isLocal() ? $error->getTraceAsString() : null,
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) return;

        try {
            @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Never let the error logger itself throw.
        }
    }

    private function errorPath(string $date): string
    {
        $dir = storage_path('logs/neurotrader/lifecycle-errors');
        if (! is_dir($dir)) mkdir($dir, 0o755, true);
        return $dir.'/'.$date.'.jsonl';
    }

    private function severity(Throwable $error): string
    {
        if ($error instanceof \ErrorException || $error instanceof \PDOException) return 'critical';
        if ($error instanceof \TypeError) return 'error';
        return 'warning';
    }

    private function errorCode(Throwable $error): string
    {
        return str_replace('\\', '_', $error::class);
    }

    /**
     * Safe, non-revealing message. Avoids leaking query text, credentials,
     * env values, tokens, or command-line payloads.
     */
    private function safeMessage(Throwable $error): string
    {
        $msg = $error->getMessage();
        $msg = preg_replace('/[?&](?:apiKey|apikey|key|token|access_token|password|secret|credential)=[^&]+/i', '$1=[REDACTED]', $msg);
        $msg = preg_replace('/[\w.-]+:\/\/[^:]+:[^@]+@/', '[REDACTED]://', $msg);
        $msg = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '[IP]', $msg);
        $msg = preg_replace('/SQLSTATE\[[^"]+\]: [^;]+; (?:[A-Za-z ]+? )?\"([^\"]{0,120})/', '$1', $msg);

        return $msg;
    }

    private function retryable(Throwable $error): bool
    {
        if ($error instanceof \ErrorException && ($error->getSeverity() & E_ERROR)) return false;
        if ($error instanceof \TypeError) return false;
        if ($error->getCode() === 0) return true; // default to retryable only for explicit non-zero codes that indicate otherwise
        return true;
    }

    private function isLocal(): bool
    {
        return (string) config('app.env') === 'local';
    }
}
