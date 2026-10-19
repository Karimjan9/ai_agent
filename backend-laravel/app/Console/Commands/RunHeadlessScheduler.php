<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class RunHeadlessScheduler extends Command
{
    protected $signature = 'schedule:headless-work';

    protected $description = 'Run scheduled callbacks in this PHP process without spawning Windows console windows.';

    public function handle(): int
    {
        // PM2 reloads on Windows can leave the previous PHP child alive for a
        // short time.  Laravel's per-command overlap locks do not protect the
        // scheduler loop itself, so two loops could both execute the same due
        // callbacks.  Keep one renewable Redis-backed process lease around the
        // entire loop.  The lease is operational coordination only; it never
        // changes a trading gate or evidence decision.
        $leaseKey = (string) config('services.scheduler.lease_key', 'trading:headless-scheduler:v1');
        $leaseSeconds = max(30, (int) config('services.scheduler.lease_seconds', 90));
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows runs the schedule inside this long-lived PHP process to
            // avoid cmd.exe console flashes. Keep the lease alive across a
            // legitimate longer callback; a dead local owner is still
            // recovered by recoverStaleLocalLease().
            $leaseSeconds = max(7200, $leaseSeconds);
        }
        $heartbeatSeconds = max(5, min($leaseSeconds - 5, (int) config('services.scheduler.heartbeat_seconds', 20)));
        $duplicateWaitSeconds = max(1, (int) config('services.scheduler.duplicate_wait_seconds', 5));
        $lease = Cache::lock($leaseKey, $leaseSeconds);
        $lastDuplicateLog = 0.0;
        while (! $lease->get()) {
            if ($this->recoverStaleLocalLease($lease, $leaseKey, $heartbeatSeconds)) {
                continue;
            }

            // A duplicate/manual launch must stay completely passive rather
            // than exiting into a PM2 restart storm. It waits for the owner
            // or for a stale TTL to expire, but never runs schedule:run.
            $now = microtime(true);
            if (($now - $lastDuplicateLog) >= 30.0) {
                Log::warning('Headless scheduler lease is already owned; duplicate process is waiting passively.', [
                    'lease_key' => $leaseKey,
                    'pid' => getmypid(),
                ]);
                $lastDuplicateLog = $now;
            }
            sleep($duplicateWaitSeconds);
        }

        $lastMinute = null;
        $executedTicks = 0;
        // Zero is intentional: keep one long-lived scheduler process. A
        // positive value is an explicit bounded-rotation override. Treating
        // zero as one causes PM2 to restart PHP after every minute tick,
        // which can materialize a visible console window on Windows.
        $maxTicksPerProcess = max(0, (int) config('services.scheduler.max_ticks_per_process', 0));
        $lastLeaseRefresh = microtime(true);
        // A long-lived scheduler must not recycle after every heavy callback:
        // on Windows each PM2 recycle can briefly materialize a conhost. Set
        // a positive value only when an operator explicitly wants bounded
        // memory rotation; zero leaves lifecycle control to PM2/monitoring.
        $memoryLimitMb = max(0, (int) config('services.scheduler.memory_limit_mb', 768));
        $memoryLimitBytes = $memoryLimitMb > 0 ? $memoryLimitMb * 1024 * 1024 : 0;
        try {
            Cache::put('system:scheduler-lease', [
                'protocol' => 'headless_scheduler_singleton_v1',
                'pid' => getmypid(),
                'hostname' => $this->hostname(),
                'lease_key' => $leaseKey,
                'started_at' => now()->toIso8601String(),
                'heartbeat_at' => now()->toIso8601String(),
                'lease_seconds' => $leaseSeconds,
            ], now()->addSeconds($leaseSeconds));
            // Publish liveness as soon as the singleton lease is acquired.
            // Previously this key was written only after a successful
            // schedule:run call, so a healthy owner looked dead whenever a
            // single scheduled callback returned non-zero. Callback success
            // remains separately observable in the scheduler logs; this key
            // is strictly process/lease liveness and never evidence.
            Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));

            while (true) {
                $now = microtime(true);
                if (($now - $lastLeaseRefresh) >= $heartbeatSeconds) {
                    if (! $this->refreshLease($lease, $leaseKey, $leaseSeconds)) {
                        return self::FAILURE;
                    }
                    $lastLeaseRefresh = $now;
                }

                $minute = CarbonImmutable::now()->format('Y-m-d H:i');

                if ($minute !== $lastMinute) {
                    $lastMinute = $minute;
                    // Persist the minute claim so a bounded-memory restart
                    // cannot immediately execute the same schedule minute a
                    // second time. The in-process marker above only protects
                    // one PHP lifetime; the cache key spans the PM2 restart.
                    if ($this->claimMinute($minute)) {
                        try {
                            if (PHP_OS_FAMILY === 'Windows') {
                                // Schedule::call entries in routes/console.php
                                // invoke their Artisan commands in this same
                                // process. That avoids Symfony Process, whose
                                // Windows implementation creates a visible
                                // cmd.exe/conhost for every scheduler tick.
                                $exitCode = $this->runScheduleTickInProcess();
                            } else {
                                // Other platforms retain an isolated tick so
                                // a heavy scheduled callback cannot grow the
                                // singleton scheduler process indefinitely.
                                $process = $this->scheduleProcess();
                                $process->start();
                                while ($process->isRunning()) {
                                    $now = microtime(true);
                                    if (($now - $lastLeaseRefresh) >= $heartbeatSeconds) {
                                        if (! $this->refreshLease($lease, $leaseKey, $leaseSeconds)) {
                                            $process->stop(5);

                                            return self::FAILURE;
                                        }
                                        $lastLeaseRefresh = $now;
                                    }
                                    usleep(250_000);
                                }
                                $exitCode = $process->getExitCode() ?? self::FAILURE;
                            }
                            if ($exitCode !== 0) {
                                Log::warning('Headless scheduler tick returned a non-zero exit code.', [
                                    'minute' => $minute,
                                    'exit_code' => $exitCode,
                                ]);
                            } else {
                                Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));
                            }
                        } catch (Throwable $exception) {
                            // A transient MySQL deadlock must not kill the only
                            // scheduler process. Mark this minute consumed so
                            // the loop does not hammer the same lock; the next
                            // minute retries the normal schedule tick.
                            Log::warning('Headless scheduler tick failed; retrying on the next minute.', [
                                'minute' => $minute,
                                'exception' => $exception,
                            ]);
                        }

                        $executedTicks++;
                        gc_collect_cycles();

                        if ($maxTicksPerProcess > 0 && $executedTicks >= $maxTicksPerProcess) {
                            Log::info('Headless scheduler process completed isolated tick.', [
                                'executed_ticks' => $executedTicks,
                                'max_ticks_per_process' => $maxTicksPerProcess,
                            ]);

                            return self::SUCCESS;
                        }

                        $memoryBytes = memory_get_usage(true);
                        if ($memoryLimitBytes > 0 && $memoryBytes >= $memoryLimitBytes) {
                            Log::warning('Headless scheduler reached its bounded memory limit; exiting for a clean supervisor restart.', [
                                'minute' => $minute,
                                'memory_bytes' => $memoryBytes,
                                'memory_limit_mb' => $memoryLimitMb,
                            ]);

                            return self::SUCCESS;
                        }
                    }
                }

                usleep(250_000);
            }
        } finally {
            try {
                // Never erase a replacement owner's heartbeat after this
                // process has lost/expired its lease.
                if ($lease->isOwnedByCurrentProcess()) {
                    Cache::forget('system:scheduler-lease');
                    Cache::forget('system:scheduler-heartbeat');
                }
                $lease->release();
            } catch (Throwable $exception) {
                Log::warning('Headless scheduler lease release failed during shutdown.', [
                    'lease_key' => $leaseKey,
                    'pid' => getmypid(),
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function refreshLease(mixed $lease, string $leaseKey, int $leaseSeconds): bool
    {
        try {
            if (! $lease->refresh($leaseSeconds)) {
                Log::critical('Headless scheduler lease was lost; exiting for a clean supervisor restart.', [
                    'lease_key' => $leaseKey,
                    'pid' => getmypid(),
                ]);

                return false;
            }

            Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));
            $currentMetadata = Cache::get('system:scheduler-lease');
            Cache::put('system:scheduler-lease', [
                'protocol' => 'headless_scheduler_singleton_v1',
                'pid' => getmypid(),
                'hostname' => $this->hostname(),
                'lease_key' => $leaseKey,
                'started_at' => is_array($currentMetadata) && filled($currentMetadata['started_at'] ?? null)
                    ? $currentMetadata['started_at']
                    : now()->toIso8601String(),
                'heartbeat_at' => now()->toIso8601String(),
                'lease_seconds' => $leaseSeconds,
            ], now()->addSeconds($leaseSeconds));

            return true;
        } catch (Throwable $exception) {
            // Continuing after a failed refresh could create two active
            // schedulers once the old TTL expires. Fail closed and let PM2
            // restart one clean owner.
            Log::critical('Headless scheduler lease refresh failed; exiting.', [
                'lease_key' => $leaseKey,
                'pid' => getmypid(),
                'exception' => $exception,
            ]);

            return false;
        }
    }

    private function scheduleProcess(): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:run', '--whisper'], base_path());
        $process->setTimeout(null);
        $process->disableOutput();

        return $process;
    }

    private function runScheduleTickInProcess(): int
    {
        return Artisan::call('schedule:run', ['--whisper' => true]);
    }

    /**
     * Recover only a provably stale lease from a dead/reused process on this host.
     * A live process may be inside a long callback and is never pre-empted.
     */
    private function recoverStaleLocalLease(mixed $lease, string $leaseKey, int $heartbeatSeconds): bool
    {
        $metadata = Cache::get('system:scheduler-lease');
        if (! is_array($metadata) || ($metadata['lease_key'] ?? null) !== $leaseKey) {
            return false;
        }

        $ownerHost = trim((string) ($metadata['hostname'] ?? ''));
        $ownerPid = (int) ($metadata['pid'] ?? 0);
        $heartbeatAt = $metadata['heartbeat_at'] ?? $metadata['started_at'] ?? null;
        if ($ownerHost === '' || ! hash_equals($this->hostname(), $ownerHost) || $ownerPid <= 0 || ! $heartbeatAt) {
            return false;
        }

        try {
            $ageSeconds = max(0, (int) floor(CarbonImmutable::parse((string) $heartbeatAt)->diffInSeconds(now())));
        } catch (Throwable) {
            return false;
        }

        if ($ageSeconds < max(60, $heartbeatSeconds * 3)) {
            return false;
        }
        // Serialize competing recoverers, then release only the captured lock
        // token. A delayed probe can never force-release a replacement owner.
        $recovery = Cache::lock($leaseKey.':stale-recovery', 15);
        if (! $recovery->get()) return false;
        try {
            $token = $this->currentLeaseToken($leaseKey);
            if (! is_string($token) || $token === '' || Cache::get('system:scheduler-lease') !== $metadata
                || $this->localProcessIsRunning($ownerPid, $metadata) !== false
                || Cache::get('system:scheduler-lease') !== $metadata
                || ! $lease->isOwnedBy($token)
                || ! Cache::restoreLock($leaseKey, $token)->release()) return false;
            // Do not erase read-model keys after releasing the lock: another
            // genuine owner may already have published its new heartbeat.
            Log::warning('Recovered a stale headless scheduler lease from a dead or reused local process.', [
                'lease_key' => $leaseKey, 'stale_pid' => $ownerPid,
                'stale_age_seconds' => $ageSeconds, 'replacement_pid' => getmypid(),
            ]);
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $recovery->release();
        }
    }

    private function hostname(): string
    {
        return (string) (gethostname() ?: php_uname('n'));
    }

    /**
     * Null means that the platform could not prove either state.
     */
    private function localProcessIsRunning(int $pid, array $metadata = []): ?bool
    {
        $identity = $this->localProcessIdentity($pid);
        if (($identity['running'] ?? null) === false) return false;
        if (($identity['running'] ?? null) !== true || ($identity['pid'] ?? null) !== $pid) return null;
        $command = strtolower(str_replace('\\', '/', (string) ($identity['command_line'] ?? '')));
        $artisan = strtolower(str_replace('\\', '/', base_path('artisan')));
        $binary = strtolower(basename(str_replace('\\', '/', (string) ($identity['executable_path'] ?? ''))));
        if (! in_array($binary, ['php.exe', 'php-win.exe'], true)
            || ! preg_match('~(?:^|\s)"?'.preg_quote($artisan, '~').'"?\s+"?([a-z][a-z0-9_-]*:[a-z0-9_-]+)"?(?:\s|$)~', $command, $match)) return null;
        // Even a stale heartbeat can belong to a legitimate long callback.
        if ($match[1] === 'schedule:headless-work') return true;
        try {
            if (! is_string($metadata['started_at'] ?? null) || ! is_string($identity['created_at'] ?? null)) return null;
            $ownerStart = CarbonImmutable::parse($metadata['started_at']);
            $processStart = CarbonImmutable::parse($identity['created_at']);
            // Both a different canonical Artisan role and a later OS process
            // birth are required; absent/ambiguous identity is never death.
            return $processStart->greaterThan($ownerStart->addSecond()) ? false : null;
        } catch (Throwable) { return null; }
    }

    private function currentLeaseToken(string $leaseKey): ?string
    {
        $store = Cache::store()->getStore();
        $owner = $store instanceof RedisStore ? $store->lockConnection()->get($store->getPrefix().$leaseKey)
            : ($store instanceof ArrayStore ? ($store->locks[$leaseKey]['owner'] ?? null) : null);
        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /** Null is an unknown identity, not permission to steal a scheduler lease. */
    protected function localProcessIdentity(int $pid): ?array
    {
        if ($pid <= 0) return null;
        if (PHP_OS_FAMILY !== 'Windows') {
            if (function_exists('posix_kill') && ! @posix_kill($pid, 0)
                && function_exists('posix_get_last_error') && posix_get_last_error() === 3) return ['running' => false];
            return null;
        }
        try {
            // Only the already-built GUI shell is permitted. A bare console
            // PowerShell/proc_open launch can flash before -WindowStyle acts.
            $directory = dirname(PHP_BINARY);
            $shell = $directory.DIRECTORY_SEPARATOR.'cmd.exe';
            $buildBytes = (string) @file_get_contents($directory.DIRECTORY_SEPARATOR.'build.json');
            $build = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $buildBytes), true);
            $source = base_path('scripts/windows-hidden-shell.cs');
            if (! is_array($build) || ! is_file($shell) || ! is_file($source)
                || strtolower((string) ($build['shell_sha256'] ?? '')) !== hash_file('sha256', $shell)
                || strtolower((string) ($build['shell_source_sha256'] ?? '')) !== hash_file('sha256', $source)) return null;
            $powershell = rtrim((string) getenv('SystemRoot'), '\\/').'/System32/WindowsPowerShell/v1.0/powershell.exe';
            if (! is_file($powershell) || ! function_exists('iconv')) return null;
            $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $this->windowsIdentityScript($pid)));
            $stdout = tmpfile(); $stderr = tmpfile();
            if ($stdout === false || $stderr === false) return null;
            $process = null;
            try {
                $pipes = [];
                $process = @proc_open([$shell, '/D', '/C', $powershell, '-NoLogo', '-NoProfile', '-NonInteractive',
                    '-WindowStyle', 'Hidden', '-EncodedCommand', $encoded],
                    [0 => ['file', 'NUL', 'r'], 1 => $stdout, 2 => $stderr], $pipes, base_path(), null,
                    ['bypass_shell' => true, 'create_new_console' => false, 'create_process_group' => false]);
                if (! is_resource($process)) return null;
                $deadline = microtime(true) + 5;
                do {
                    $status = proc_get_status($process);
                    if (! $status['running']) break;
                    if (microtime(true) >= $deadline) { proc_terminate($process); return null; }
                    usleep(20_000);
                } while (true);
                if (($status['exitcode'] ?? -1) !== 0 || (int) (fstat($stdout)['size'] ?? 0) > 16384) return null;
                rewind($stdout);
                $decoded = json_decode((string) stream_get_contents($stdout), true, 16, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : null;
            } finally {
                if (is_resource($process)) proc_close($process);
                fclose($stdout); fclose($stderr);
            }
        } catch (Throwable) { return null; }
    }

    protected function windowsIdentityScript(int $pid): string
    {
        return '$ErrorActionPreference="Stop"; $p=Get-CimInstance Win32_Process -Filter "ProcessId = '.(int) $pid.'"; '
            .'if($null -eq $p){[ordered]@{running=$false}|ConvertTo-Json -Compress}else{'
            .'[ordered]@{running=$true;pid=[int]$p.ProcessId;created_at=$p.CreationDate.ToUniversalTime().ToString("o");'
            .'executable_path=[string]$p.ExecutablePath;command_line=[string]$p.CommandLine}|ConvertTo-Json -Compress}';
    }

    private function claimMinute(string $minute): bool
    {
        return Cache::add(
            'system:scheduler-tick:'.$minute,
            [
                'protocol' => 'headless_scheduler_tick_claim_v1',
                'minute' => $minute,
                'pid' => getmypid(),
            ],
            now()->addMinutes(10),
        );
    }
}
