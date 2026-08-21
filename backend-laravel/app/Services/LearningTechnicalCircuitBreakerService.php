<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Quarantines repeatable infrastructure faults before they multiply cohorts. */
class LearningTechnicalCircuitBreakerService
{
    public const THRESHOLD = 3;

    public function record(string $symbol, string $timeframe, string $error, array $context = []): bool
    {
        if (! Schema::hasTable('learning_technical_failures')) return false;
        $fingerprint = hash('sha256', $this->normalize($error));
        $row = DB::table('learning_technical_failures')->where(compact('symbol', 'timeframe', 'fingerprint'))->first();
        $count = ((int) ($row->occurrences ?? 0)) + 1;
        DB::table('learning_technical_failures')->updateOrInsert(compact('symbol', 'timeframe', 'fingerprint'), [
            'error_class' => substr($this->normalize($error), 0, 255), 'occurrences' => $count,
            'status' => $count >= self::THRESHOLD ? 'technical_quarantine' : 'observed',
            'context' => json_encode($context + ['promotion_evidence' => false]), 'last_seen_at' => now(), 'updated_at' => now(), 'created_at' => $row?->created_at ?? now(),
        ]);
        return $count >= self::THRESHOLD;
    }

    public function blocked(string $symbol, string $timeframe): bool
    {
        return Schema::hasTable('learning_technical_failures') && DB::table('learning_technical_failures')
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'technical_quarantine')->where('occurrences', '>=', self::THRESHOLD)->exists();
    }

    private function normalize(string $error): string
    {
        return preg_replace('/\b\d+\b/', '#', trim(preg_replace('/\s+/', ' ', $error))) ?: 'unknown_technical_error';
    }
}
