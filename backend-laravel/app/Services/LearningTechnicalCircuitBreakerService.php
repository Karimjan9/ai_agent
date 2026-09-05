<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Quarantines repeatable infrastructure faults before they multiply cohorts. */
class LearningTechnicalCircuitBreakerService
{
    public const THRESHOLD = 3;

    /** @var array<string, true> Scopes armed by this service instance. */
    private array $acquiredProbes = [];

    public function record(string $symbol, string $timeframe, string $error, array $context = []): bool
    {
        if (! Schema::hasTable('learning_technical_failures')) return false;
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $fingerprint = hash('sha256', $this->normalize($error));

        return DB::transaction(function () use ($symbol, $timeframe, $fingerprint, $error, $context): bool {
            // A half-open lease tests the evaluator scope, not one exact error
            // string. If the probe fails with a different exception (timeout
            // becomes connection-refused, for example), close the existing
            // lease instead of inserting a new observed fingerprint while the
            // old lease remains stranded in half_open.
            $halfOpenRows = DB::table('learning_technical_failures')
                ->where(compact('symbol', 'timeframe'))
                ->where('status', 'half_open')
                ->where('occurrences', '>=', self::THRESHOLD)
                ->lockForUpdate()
                ->get();
            if ($halfOpenRows->isNotEmpty()) {
                foreach ($halfOpenRows as $halfOpenRow) {
                    $stored = $this->decodeContext($halfOpenRow->context);
                    $count = ((int) $halfOpenRow->occurrences) + 1;
                    $stored = array_merge($stored, $context, [
                        'promotion_evidence' => false,
                        'previous_status' => 'half_open',
                        'failure_streak_occurrences' => $count,
                        'last_failure_at' => now()->utc()->toIso8601String(),
                        'probe_failed_at' => now()->utc()->toIso8601String(),
                        'probe_failure_fingerprint' => $fingerprint,
                        'probe_failure_error_class' => substr($this->normalize($error), 0, 255),
                        'probe_evaluator_called' => true,
                    ]);
                    DB::table('learning_technical_failures')->where('id', $halfOpenRow->id)->update([
                        'error_class' => substr($this->normalize($error), 0, 255),
                        'occurrences' => $count,
                        'status' => 'technical_quarantine',
                        'context' => json_encode($stored),
                        'last_seen_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                unset($this->acquiredProbes[$this->scopeKey($symbol, $timeframe)]);

                return true;
            }

            $row = DB::table('learning_technical_failures')
                ->where(compact('symbol', 'timeframe', 'fingerprint'))
                ->lockForUpdate()
                ->first();
            // A recovered incident starts a new failure streak. Lifetime
            // occurrences must not make the first post-recovery transport
            // error instantly quarantine an otherwise healthy evaluator.
            $count = $row && (string) $row->status !== 'recovered'
                ? ((int) $row->occurrences) + 1
                : 1;
            $status = $count >= self::THRESHOLD ? 'technical_quarantine' : 'observed';
            $priorContext = $this->decodeContext($row?->context ?? null);
            $payload = $context + [
                'promotion_evidence' => false,
                'previous_status' => $row?->status,
                'failure_streak_occurrences' => $count,
                'last_failure_at' => now()->utc()->toIso8601String(),
                'half_open_attempt' => (int) ($priorContext['half_open_attempt'] ?? 0),
                'recovery_history' => (array) ($priorContext['recovery_history'] ?? []),
            ];

            DB::table('learning_technical_failures')->updateOrInsert(compact('symbol', 'timeframe', 'fingerprint'), [
                'error_class' => substr($this->normalize($error), 0, 255),
                'occurrences' => $count,
                'status' => $status,
                'context' => json_encode($payload),
                'last_seen_at' => now(),
                'updated_at' => now(),
                'created_at' => $row?->created_at ?? now(),
            ]);

            return $status === 'technical_quarantine';
        });
    }

    public function blocked(string $symbol, string $timeframe): bool
    {
        if (! Schema::hasTable('learning_technical_failures')) return false;

        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $cooldownMinutes = max(1, (int) config('services.lab_queue.technical_breaker_cooldown_minutes', 30));
        $probeLeaseMinutes = max(1, (int) config('services.lab_queue.technical_breaker_probe_lease_minutes', 360));

        return DB::transaction(function () use ($symbol, $timeframe, $cooldownMinutes, $probeLeaseMinutes): bool {
            $rows = DB::table('learning_technical_failures')
                ->where(compact('symbol', 'timeframe'))
                ->whereIn('status', ['technical_quarantine', 'half_open'])
                ->where('occurrences', '>=', self::THRESHOLD)
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) return false;

            $cooldownCutoff = now()->subMinutes($cooldownMinutes);
            $probeCutoff = now()->subMinutes($probeLeaseMinutes);
            foreach ($rows as $row) {
                if ((string) $row->status === 'technical_quarantine'
                    && Carbon::parse($row->last_seen_at)->isAfter($cooldownCutoff)) {
                    return true;
                }
                if ((string) $row->status === 'half_open'
                    && Carbon::parse($row->updated_at)->isAfter($probeCutoff)) {
                    // Exactly one bounded probe owns this symbol/timeframe
                    // while its screening/full replay is in flight.
                    return true;
                }
            }

            // Every blocking incident has cooled down, or the previous probe
            // lease expired without a result. Arm one auditable half-open
            // probe; the transaction/row lock prevents concurrent dispatches.
            foreach ($rows as $row) {
                $stored = $this->decodeContext($row->context);
                $attempt = ((int) ($stored['half_open_attempt'] ?? 0)) + 1;
                $stored['half_open_attempt'] = $attempt;
                $stored['half_open_at'] = now()->utc()->toIso8601String();
                $stored['half_open_reason'] = (string) $row->status === 'half_open'
                    ? 'expired_probe_lease'
                    : 'cooldown_elapsed';
                $stored['cooldown_minutes'] = $cooldownMinutes;
                $stored['probe_lease_minutes'] = $probeLeaseMinutes;
                $stored['promotion_evidence'] = false;
                DB::table('learning_technical_failures')->where('id', $row->id)->update([
                    'status' => 'half_open',
                    'context' => json_encode($stored),
                    'last_seen_at' => $row->last_seen_at,
                    'updated_at' => now(),
                ]);
            }

            $this->acquiredProbes[$this->scopeKey($symbol, $timeframe)] = true;

            return false;
        });
    }

    /**
     * A breaker probe is intended to test evaluator health. If population
     * admission fails before a generation exists, release only the probe
     * acquired by this command instance; no runtime-health conclusion is
     * written and a concurrent probe cannot be stolen.
     */
    public function releaseAcquiredProbe(string $symbol, string $timeframe, string $reason): int
    {
        if (! Schema::hasTable('learning_technical_failures')) return 0;
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $scope = $this->scopeKey($symbol, $timeframe);
        if (! isset($this->acquiredProbes[$scope])) return 0;

        $updated = DB::transaction(function () use ($symbol, $timeframe, $reason): int {
            $rows = DB::table('learning_technical_failures')
                ->where(compact('symbol', 'timeframe'))
                ->where('status', 'half_open')
                ->lockForUpdate()
                ->get();
            foreach ($rows as $row) {
                $stored = $this->decodeContext($row->context);
                $stored['probe_released_at'] = now()->utc()->toIso8601String();
                $stored['probe_release_reason'] = $reason;
                $stored['probe_evaluator_called'] = false;
                $stored['promotion_evidence'] = false;
                DB::table('learning_technical_failures')->where('id', $row->id)->update([
                    'status' => 'technical_quarantine',
                    'context' => json_encode($stored),
                    // MariaDB historically gave this first TIMESTAMP column
                    // an implicit ON UPDATE clause. Set the old value even on
                    // corrected schemas so admission cannot become a failure.
                    'last_seen_at' => $row->last_seen_at,
                    'updated_at' => now(),
                ]);
            }

            return $rows->count();
        });
        unset($this->acquiredProbes[$scope]);

        return $updated;
    }

    /** Adopt the live lease only for its already-created confirmation draft. */
    public function adoptHalfOpenProbe(string $symbol, string $timeframe): bool
    {
        if (! Schema::hasTable('learning_technical_failures')) return false;
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $exists = DB::table('learning_technical_failures')
            ->where(compact('symbol', 'timeframe'))
            ->where('status', 'half_open')
            ->where('occurrences', '>=', self::THRESHOLD)
            ->exists();
        if ($exists) $this->acquiredProbes[$this->scopeKey($symbol, $timeframe)] = true;

        return $exists;
    }

    /**
     * Close a half-open breaker only after an authenticated evaluator run has
     * reached immutable `completed` evidence. Strategy quality is irrelevant:
     * this proves transport/runtime health, not trading edge.
     */
    public function recordSuccess(string $symbol, string $timeframe, array $context = []): int
    {
        if (! Schema::hasTable('learning_technical_failures')) return 0;

        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);

        return DB::transaction(function () use ($symbol, $timeframe, $context): int {
            $rows = DB::table('learning_technical_failures')
                ->where(compact('symbol', 'timeframe'))
                ->where('status', 'half_open')
                ->lockForUpdate()
                ->get();
            foreach ($rows as $row) {
                $stored = $this->decodeContext($row->context);
                $history = (array) ($stored['recovery_history'] ?? []);
                $history[] = $context + [
                    'recovered_at' => now()->utc()->toIso8601String(),
                    'protocol' => 'immutable_evaluator_success_v1',
                    'promotion_evidence' => false,
                ];
                $stored['recovered_at'] = now()->utc()->toIso8601String();
                $stored['recovery_protocol'] = 'immutable_evaluator_success_v1';
                $stored['recovery_history'] = array_slice($history, -10);
                $stored['promotion_evidence'] = false;
                DB::table('learning_technical_failures')->where('id', $row->id)->update([
                    'status' => 'recovered',
                    'context' => json_encode($stored),
                    'last_seen_at' => $row->last_seen_at,
                    'updated_at' => now(),
                ]);
            }

            return $rows->count();
        });
    }

    private function normalize(string $error): string
    {
        return preg_replace('/\b\d+\b/', '#', trim(preg_replace('/\s+/', ' ', $error))) ?: 'unknown_technical_error';
    }

    private function decodeContext(mixed $context): array
    {
        if (is_array($context)) return $context;
        if (! is_string($context) || $context === '') return [];
        $decoded = json_decode($context, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function scopeKey(string $symbol, string $timeframe): string
    {
        return strtoupper($symbol).'|'.strtoupper($timeframe);
    }
}
