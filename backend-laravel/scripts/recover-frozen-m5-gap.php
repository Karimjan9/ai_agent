<?php

/** Explicit offline operation. Dry-run is default; it never changes a sealed source. */
final class FrozenM5GapRecoveryOperation
{
    public const PROTOCOL = 'frozen_m5_gap_recovery_v1';
    public const BATCH_PROTOCOL = 'frozen_m5_gap_recovery_v2';
    public const MAX_BATCH_TARGETS = 300;
    public const TARGET = '2025-12-17 23:00:00';
    public const EXPECTED_TICK_HOUR_SHA256 = 'ecccee8e84d4bcd2ebb56ff0cf3f4db5cd397bbe6dc41547bc91503b7cef327e';

    /** All source price/volume strings survive unchanged. Extras remain separate quote evidence. */
    public static function source(string $path, string $expectedHash, bool $batch = false): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash) || ! is_file($path) || filesize($path) > 134217728
            || ! hash_equals($expectedHash, (string) hash_file('sha256', $path))) throw new RuntimeException('RECOVERY_FROZEN_SOURCE_SHA_MISMATCH');
        $handle = fopen($path, 'rb'); $rows = []; $prior = null;
        try {
            $headers = fgetcsv($handle, escape: '');
            foreach (['time', 'open', 'high', 'low', 'close', 'volume'] as $key) {
                if (! is_array($headers) || ! in_array($key, $headers, true)) throw new RuntimeException('RECOVERY_SOURCE_COLUMN_MISSING');
            }
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if (count($values) !== count($headers)) throw new RuntimeException('RECOVERY_SOURCE_ROW_MALFORMED');
                $row = array_combine($headers, $values);
                $time = self::time((string) $row['time']);
                if ($time >= '2026-01-01 00:00:00' || ($prior !== null && $time <= $prior) || (! $batch && $time === self::TARGET)) throw new RuntimeException('RECOVERY_SOURCE_CHRONOLOGY_OR_TARGET_INVALID');
                $row = array_intersect_key($row, array_flip(['time', 'open', 'high', 'low', 'close', 'volume']));
                $row['time'] = $time;
                self::price($row); $rows[] = $row; $prior = $time;
            }
        } finally { fclose($handle); }
        if (count($rows) < 2 || count($rows) > 350000 || (! $batch && ($rows[0]['time'] >= self::TARGET || $rows[array_key_last($rows)]['time'] <= self::TARGET))) throw new RuntimeException('RECOVERY_SOURCE_TARGET_NOT_BOUNDED');
        if (! hash_equals($expectedHash, (string) hash_file('sha256', $path))) throw new RuntimeException('RECOVERY_SOURCE_CHANGED_DURING_READ');
        return $rows;
    }

    /** This one known sparse reopen is not generalized into a relaxed minute-coverage policy. */
    public static function proof(array $m1, array $m5, array $tick): array
    {
        if (($tick['source_hour_sha256'] ?? null) !== self::EXPECTED_TICK_HOUR_SHA256
            || ($tick['first_bucket_count'] ?? null) !== 137 || ($tick['whole_hour_count'] ?? null) !== 4572
            || ($tick['minute_counts'] ?? []) !== ['2025-12-17T23:01' => 6, '2025-12-17T23:02' => 64, '2025-12-17T23:03' => 45, '2025-12-17T23:04' => 22]) throw new RuntimeException('RECOVERY_RAW_TICK_EVIDENCE_MISMATCH');
        $minuteRows = []; $recovered = null;
        foreach ($m1 as $row) {
            $time = self::time((string) ($row['time'] ?? ''));
            if ($time < self::TARGET || $time >= '2025-12-17 23:05:00') continue;
            if (isset($minuteRows[$time])) throw new RuntimeException('RECOVERY_DUPLICATE_PROVIDER_MINUTE');
            self::price($row); $minuteRows[$time] = $row;
        }
        if (array_keys($minuteRows) !== ['2025-12-17 23:01:00', '2025-12-17 23:02:00', '2025-12-17 23:03:00', '2025-12-17 23:04:00']) throw new RuntimeException('RECOVERY_SPARSE_REOPEN_NOT_EXACT');
        foreach ($m5 as $row) {
            if (self::time((string) ($row['time'] ?? '')) === self::TARGET) {
                if ($recovered !== null) throw new RuntimeException('RECOVERY_DUPLICATE_PROVIDER_BUCKET');
                self::price($row); $recovered = $row;
            }
        }
        if ($recovered === null) throw new RuntimeException('RECOVERY_PROVIDER_BUCKET_MISSING');
        $mins = array_values($minuteRows);
        $actual = ['open' => (float) $mins[0]['open'], 'high' => max(array_column($mins, 'high')), 'low' => min(array_column($mins, 'low')),
            'close' => (float) $mins[array_key_last($mins)]['close'], 'volume' => array_sum(array_column($mins, 'volume'))];
        foreach ($actual as $key => $value) {
            if (abs((float) $recovered[$key] - $value) > 0.00000001) throw new RuntimeException('RECOVERY_PROVIDER_M1_M5_DISAGREE:'.$key);
        }
        foreach (['open', 'high', 'low', 'close'] as $key) {
            if (! is_numeric($tick['first_bucket_ohlc'][$key] ?? null)
                || abs((float) $tick['first_bucket_ohlc'][$key] - $actual[$key]) > 0.00000001) throw new RuntimeException('RECOVERY_PROVIDER_TICKS_DISAGREE:'.$key);
        }
        return ['recovered_row' => ['time' => self::TARGET, ...$actual], 'observed_m1_minutes' => 4, 'expected_clock_minutes' => 5,
            'complete_minute_coverage' => false, 'unobserved_minute_filled' => false, 'actual_tick_count' => 137,
            'source_tick_hour_sha256' => self::EXPECTED_TICK_HOUR_SHA256, 'first_tick_utc' => $tick['first_tick_utc'],
            'last_tick_utc' => $tick['last_tick_utc'], 'provider_m1_sha256' => hash('sha256', json_encode($mins, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))];
    }

    public static function fork(array $source, array $proof): array
    {
        $row = $proof['recovered_row'] ?? []; self::price($row);
        if (($row['time'] ?? null) !== self::TARGET || ($proof['complete_minute_coverage'] ?? null) !== false
            || ($proof['unobserved_minute_filled'] ?? null) !== false) throw new RuntimeException('RECOVERY_PROOF_INVALID');
        $result = []; $added = false;
        foreach ($source as $original) {
            if (($original['time'] ?? '') === self::TARGET) throw new RuntimeException('RECOVERY_TARGET_ALREADY_PRESENT');
            if (! $added && $original['time'] > self::TARGET) { $result[] = $row; $added = true; }
            $result[] = $original;
        }
        if (! $added || count($result) !== count($source) + 1) throw new RuntimeException('RECOVERY_FORK_INCOMPLETE');
        return $result;
    }

    /** Native observed minutes and actual BID ticks, never a synthetic clock-minute fill. */
    public static function batchProof(string $target, array $m1, array $m5, array $tick): array
    {
        $target = self::time($target);
        $start = new DateTimeImmutable($target, new DateTimeZone('UTC'));
        if ($target >= '2026-01-01 00:00:00' || (int) $start->format('i') % 5 !== 0) throw new RuntimeException('RECOVERY_BATCH_TARGET_INVALID');
        $end = $start->modify('+5 minutes')->format('Y-m-d H:i:s');
        $hour = $start->format('Y-m-d\TH:00:00.000\Z');
        $iso = $start->format('Y-m-d\TH:i:00.000\Z');
        if (($tick['protocol'] ?? null) !== 'actual_provider_tick_hour_v1' || ($tick['hour'] ?? null) !== $hour
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($tick['source_hour_sha256'] ?? ''))
            || ! is_int($tick['whole_hour_count'] ?? null) || $tick['whole_hour_count'] < 1) throw new RuntimeException('RECOVERY_BATCH_RAW_HOUR_INVALID');
        $bucket = (array) ($tick['buckets'][$iso] ?? []);
        if (! is_int($bucket['count'] ?? null) || $bucket['count'] < 1 || $bucket['count'] > $tick['whole_hour_count']) throw new RuntimeException('RECOVERY_BATCH_NO_OBSERVED_TICKS');
        $minuteRows = [];
        foreach ($m1 as $row) {
            $time = self::time((string) ($row['time'] ?? ''));
            if ($time < $target || $time >= $end) continue;
            if (isset($minuteRows[$time])) throw new RuntimeException('RECOVERY_DUPLICATE_PROVIDER_MINUTE');
            self::price($row); $minuteRows[$time] = array_intersect_key($row, array_flip(['time','open','high','low','close','volume']));
        }
        ksort($minuteRows); $mins = array_values($minuteRows);
        if (count($mins) < 1 || count($mins) > 5) throw new RuntimeException('RECOVERY_BATCH_NO_OBSERVED_MINUTES');
        $native = null;
        foreach ($m5 as $row) if (self::time((string) ($row['time'] ?? '')) === $target) {
            if ($native !== null) throw new RuntimeException('RECOVERY_DUPLICATE_PROVIDER_BUCKET');
            self::price($row); $native = array_intersect_key($row, array_flip(['time','open','high','low','close','volume']));
        }
        if ($native === null) throw new RuntimeException('RECOVERY_PROVIDER_BUCKET_MISSING');
        $actual = ['open'=>(float)$mins[0]['open'], 'high'=>max(array_column($mins,'high')), 'low'=>min(array_column($mins,'low')),
            'close'=>(float)$mins[array_key_last($mins)]['close'], 'volume'=>array_sum(array_column($mins,'volume'))];
        foreach ($actual as $key=>$value) if (abs((float)$native[$key]-$value)>0.00000001) throw new RuntimeException('RECOVERY_PROVIDER_M1_M5_DISAGREE:'.$key);
        $tickMinutes = []; $tickCount = 0;
        foreach ((array) ($tick['minutes'] ?? []) as $minuteIso=>$summary) {
            $minute = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', (string)$minuteIso, new DateTimeZone('UTC'));
            if ($minute === false || $minute->format('Y-m-d\TH:i:s.v\Z') !== $minuteIso) throw new RuntimeException('RECOVERY_BATCH_TICK_MINUTE_INVALID');
            $time = $minute->format('Y-m-d H:i:s');
            if ($time < $target || $time >= $end) continue;
            if (! isset($minuteRows[$time]) || ! is_int($summary['count'] ?? null) || $summary['count'] < 1) throw new RuntimeException('RECOVERY_BATCH_MINUTE_TICK_MEMBERSHIP_MISMATCH');
            self::tickOhlc($summary, $minuteRows[$time], $minute, $minute->modify('+1 minute'));
            $tickMinutes[$minuteIso] = $summary; $tickCount += $summary['count'];
        }
        ksort($tickMinutes);
        if (count($tickMinutes) !== count($mins) || $tickCount !== $bucket['count']) throw new RuntimeException('RECOVERY_BATCH_MINUTE_TICK_MEMBERSHIP_MISMATCH');
        self::tickOhlc($bucket, $actual, $start, $start->modify('+5 minutes'));
        return ['protocol'=>'actual_observed_m5_bucket_recovery_v1', 'recovered_row'=>['time'=>$target,...$actual],
            'observed_m1_minutes'=>count($mins), 'expected_clock_minutes'=>5, 'complete_minute_coverage'=>count($mins)===5,
            'unobserved_minute_filled'=>false, 'actual_tick_count'=>$bucket['count'], 'source_tick_hour_sha256'=>$tick['source_hour_sha256'],
            'tick_hour_utc'=>$hour, 'tick_hour_count'=>$tick['whole_hour_count'], 'native_m1_rows'=>$mins, 'native_m5_row'=>$native,
            'tick_bucket'=>$bucket, 'tick_minutes'=>$tickMinutes];
    }

    private static function tickOhlc(array $tick, array $price, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        foreach (['open','high','low','close'] as $key) if (! is_numeric($tick[$key] ?? null) || ! is_finite((float)$tick[$key])
            || abs((float)$tick[$key]-(float)$price[$key])>0.00000001) throw new RuntimeException('RECOVERY_PROVIDER_TICKS_DISAGREE:'.$key);
        foreach (['first_tick_utc','last_tick_utc'] as $key) {
            if (! is_string($tick[$key] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$tick[$key])) throw new RuntimeException('RECOVERY_BATCH_TICK_BOUNDS_INVALID');
            $value = new DateTimeImmutable($tick[$key]);
            if ($value < $start || $value >= $end) throw new RuntimeException('RECOVERY_BATCH_TICK_BOUNDS_INVALID');
        }
        if ($tick['first_tick_utc'] > $tick['last_tick_utc']) throw new RuntimeException('RECOVERY_BATCH_TICK_BOUNDS_INVALID');
    }

    /** Revalidated bounded proof inventory; untouched rows retain their original six cells. */
    public static function forkMany(array $source, array $proofs, array $canonicalMissingUtc): array
    {
        if (count($proofs)<1 || count($proofs)>self::MAX_BATCH_TARGETS || count($canonicalMissingUtc)<1
            || count($canonicalMissingUtc)>self::MAX_BATCH_TARGETS || count(array_unique($canonicalMissingUtc))!==count($canonicalMissingUtc)) throw new RuntimeException('RECOVERY_BATCH_BOUND_EXCEEDED');
        $missing = [];
        foreach ($canonicalMissingUtc as $iso) {
            if (! is_string($iso) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00\+00:00$/D',$iso)) throw new RuntimeException('RECOVERY_BATCH_INVENTORY_INVALID');
            $time=self::time(substr($iso,0,10).' '.substr($iso,11,8));
            if ($time <= $source[0]['time'] || $time >= $source[array_key_last($source)]['time']) throw new RuntimeException('RECOVERY_BATCH_TARGET_NOT_BOUNDED');
            $missing[$time]=true;
        }
        $insertions=[];
        foreach ($proofs as $proof) {
            $row=(array)($proof['recovered_row']??[]); $time=(string)($row['time']??'');
            $iso=(new DateTimeImmutable($time,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:00.000\Z');
            $recomputed=self::batchProof($time,(array)($proof['native_m1_rows']??[]),[(array)($proof['native_m5_row']??[])],
                ['protocol'=>'actual_provider_tick_hour_v1','hour'=>$proof['tick_hour_utc']??null,'whole_hour_count'=>$proof['tick_hour_count']??null,
                    'source_hour_sha256'=>$proof['source_tick_hour_sha256']??null,'buckets'=>[$iso=>$proof['tick_bucket']??[]],'minutes'=>$proof['tick_minutes']??[]]);
            if (app(App\Services\ExecutionContractService::class)->hashParameters($proof)!==app(App\Services\ExecutionContractService::class)->hashParameters($recomputed)
                || ! isset($missing[$time]) || isset($insertions[$time])) throw new RuntimeException('RECOVERY_BATCH_PROOF_INVALID');
            $insertions[$time]=$row;
        }
        ksort($insertions); $result=[];
        foreach ($source as $original) {
            if (isset($insertions[$original['time']])) throw new RuntimeException('RECOVERY_TARGET_ALREADY_PRESENT');
            while ($insertions && array_key_first($insertions)<$original['time']) { $result[]=array_shift($insertions); }
            $result[]=$original;
        }
        if ($insertions || count($result)!==count($source)+count($proofs)) throw new RuntimeException('RECOVERY_FORK_INCOMPLETE');
        return $result;
    }

    public static function csvBytes(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        try {
            fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume'], escape: '');
            foreach ($rows as $row) fputcsv($handle, array_map(fn ($key) => $row[$key], ['time', 'open', 'high', 'low', 'close', 'volume']), escape: '');
            rewind($handle); return (string) stream_get_contents($handle);
        } finally { fclose($handle); }
    }

    public static function economicRowsHash(iterable $rows): string
    {
        $digest = hash_init('sha256');
        foreach ($rows as $row) {
            // The existing training table owns six-decimal storage precision.
            hash_update($digest, json_encode([(string) $row['time'], number_format((float) $row['open'], 6, '.', ''),
                number_format((float) $row['high'], 6, '.', ''), number_format((float) $row['low'], 6, '.', ''),
                number_format((float) $row['close'], 6, '.', ''), number_format((float) $row['volume'], 6, '.', '')])."\n");
        }
        return hash_final($digest);
    }

    /** Reuse only original, fully revalidated successful proofs, never prior missing-data verdicts. */
    public static function resumeProofs(string $sourcePath, string $sourceHash, array $source, array $receipt, string $pricePath): array
    {
        $hash = (string) ($receipt['repair_hash'] ?? '');
        $identity = array_diff_key($receipt, array_flip(['repair_hash', 'dataset_key']));
        if (($receipt['protocol'] ?? null) !== self::BATCH_PROTOCOL
            || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || ! hash_equals($hash, app(App\Services\ExecutionContractService::class)->hashParameters($identity))
            || ($receipt['dataset_key'] ?? null) !== 'foundation_intraday_gapfix_'.substr($hash, 0, 16)
            || ($receipt['source_csv_sha256'] ?? null) !== $sourceHash
            || realpath((string) ($receipt['source_csv_path'] ?? '')) !== realpath($sourcePath)
            || ($receipt['source_rows'] ?? null) !== count($source)
            || ($receipt['independent_evidence'] ?? null) !== false
            || ($receipt['promotion_evidence'] ?? null) !== false
            || ($receipt['quote_liquidity_inherited'] ?? null) !== false
            || ! is_file($pricePath)) throw new RuntimeException('RECOVERY_RESUME_RECEIPT_INVALID');
        $proofs = (array) ($receipt['target_proofs'] ?? []);
        $rows = self::forkMany($source, $proofs, (array) ($receipt['canonical_missing_utc'] ?? []));
        if (($receipt['new_rows'] ?? null) !== count($rows)
            || ! hash_equals((string) ($receipt['new_price_csv_sha256'] ?? ''), hash('sha256', self::csvBytes($rows)))
            || ! hash_equals((string) ($receipt['new_price_csv_sha256'] ?? ''), (string) hash_file('sha256', $pricePath))
            || ! hash_equals((string) ($receipt['new_economic_rows_sha256'] ?? ''), self::economicRowsHash($rows))) {
            throw new RuntimeException('RECOVERY_RESUME_PROOF_OR_BYTES_INVALID');
        }
        return $proofs;
    }

    /** One bounded provider/tick collection; failures remain unresolved source buckets. */
    public static function collectBatch(string $sourcePath, string $sourceHash, array $source, object $provider, array $resumedProofs = []): array
    {
        $audit = static function (?array $recovered) use ($sourcePath): array {
            $arguments=['python', __DIR__.'/audit-frozen-m5-gap-source.py', $sourcePath,
                $recovered===null?'--inventory':'--recovered='.implode(',',$recovered)];
            $process=new Symfony\Component\Process\Process($arguments); $process->setTimeout(180); $process->mustRun();
            return json_decode($process->getOutput(),true,flags:JSON_THROW_ON_ERROR);
        };
        $inventory=$audit(null); $targets=(array)($inventory['canonical_missing_utc']??[]);
        if (($inventory['source_csv_sha256']??null)!==$sourceHash || ($inventory['source_rows']??null)!==count($source)
            || count($targets)<1 || count($targets)>self::MAX_BATCH_TARGETS || count(array_unique($targets))!==count($targets)) throw new RuntimeException('RECOVERY_BATCH_INVENTORY_OR_BOUND_INVALID');
        if ($resumedProofs !== []) self::forkMany($source, $resumedProofs, $targets);
        $completed = array_fill_keys(array_map(static fn ($proof) => str_replace(' ', 'T', $proof['recovered_row']['time']).'+00:00', $resumedProofs), true);
        $days=[]; $hours=[]; $proofs=$resumedProofs; $unresolved=[]; $deadline=microtime(true)+1800;
        self::configureOfflineTransport();
        // Selected screening is diagnosed first; this ordering creates no false full-archive certificate.
        $ordered=array_values(array_filter($targets, static fn ($target) => ! isset($completed[$target]))); rsort($ordered);
        if ($resumedProofs !== []) fwrite(STDERR, 'RECOVERY_BATCH_REUSED_VERIFIED_PROOFS '.count($resumedProofs).' pending='.count($ordered).PHP_EOL);
        foreach ($ordered as $index=>$iso) {
            $at=Carbon\CarbonImmutable::parse($iso,'UTC'); $day=$at->startOfDay(); $dayKey=$day->toDateString(); $hour=$at->startOfHour(); $hourKey=$hour->format('Y-m-d\TH:i:s\Z');
            try {
                if (microtime(true)>=$deadline) throw new RuntimeException('RECOVERY_BATCH_PROVIDER_BUDGET_EXHAUSTED');
                if (! array_key_exists($dayKey,$days)) {
                    try { $days[$dayKey]=['m1'=>$provider->fetchCandles('XAUUSD','XAUUSD','M1',1440,$day,$day->addDay()),
                        'm5'=>$provider->fetchCandles('XAUUSD','XAUUSD','M5',288,$day,$day->addDay())]; }
                    catch (Throwable $e) { $days[$dayKey]=['failure'=>'RECOVERY_PROVIDER_DAY_UNAVAILABLE']; }
                }
                if (isset($days[$dayKey]['failure'])) throw new RuntimeException($days[$dayKey]['failure']);
                if (! array_key_exists($hourKey,$hours)) {
                    try {
                        $process=new Symfony\Component\Process\Process([(string)config('services.dukascopy.node_binary','node'),__DIR__.'/verify-sparse-reopen-ticks.cjs','--hour='.$hourKey]);
                        $process->setTimeout(min(20,max(1,$deadline-microtime(true)))); $process->mustRun(); $hours[$hourKey]=json_decode($process->getOutput(),true,flags:JSON_THROW_ON_ERROR);
                    } catch (Throwable $e) { $hours[$hourKey]=['failure'=>'RECOVERY_RAW_TICK_HOUR_UNAVAILABLE']; }
                }
                if (isset($hours[$hourKey]['failure'])) throw new RuntimeException($hours[$hourKey]['failure']);
                $proofs[]=self::batchProof($at->format('Y-m-d H:i:s'),$days[$dayKey]['m1'],$days[$dayKey]['m5'],$hours[$hourKey]);
            } catch (Throwable $e) { $unresolved[]=['target_utc'=>$iso,'reason'=>$e->getMessage()]; }
            if (($index+1)%10===0 || $index+1===count($ordered)) fwrite(STDERR,'RECOVERY_BATCH_PROGRESS '.($index+1).'/'.count($ordered).' verified='.count($proofs).' unresolved='.count($unresolved).PHP_EOL);
        }
        if (! $proofs) throw new RuntimeException('RECOVERY_BATCH_NO_VERIFIED_ACTUAL_BARS');
        usort($proofs,static fn($a,$b)=>strcmp($a['recovered_row']['time'],$b['recovered_row']['time']));
        usort($unresolved,static fn($a,$b)=>strcmp($a['target_utc'],$b['target_utc']));
        $rows=self::forkMany($source,$proofs,$targets);
        $recovered=array_map(static fn($proof)=>str_replace(' ','T',$proof['recovered_row']['time']).'+00:00',$proofs);
        $calendar=$audit($recovered);
        if (($calendar['source_csv_sha256']??null)!==$sourceHash || ($calendar['source_rows']??null)!==count($source)
            || ($calendar['new_rows']??null)!==count($rows) || ($calendar['screening_unexpected_after']??null)!==0) throw new RuntimeException('RECOVERY_SELECTED_SCREENING_SCOPE_STILL_GAPPED');
        return ['rows'=>$rows,'target_proofs'=>$proofs,'canonical_missing_utc'=>$targets,'unresolved_targets'=>$unresolved,
            'calendar_scope'=>$calendar,'provider_days_requested'=>count($days),'tick_hours_requested'=>count($hours), 'collection_ceiling_seconds'=>1800];
    }

    private static function configureOfflineTransport(): void
    {
        // This CLI collects a bounded historical day, not live-feed recovery.
        // Inheriting live_chunk_hours=1 refetches the same daily Jetta resource
        // up to 24 times and can consume the whole batch ceiling prematurely.
        // Values are process-local only; neither .env nor live owners change.
        config()->set('services.dukascopy.live_chunk_hours', 0);
        config()->set('services.dukascopy.timeout_seconds', 20);
        config()->set('services.dukascopy.http_timeout_seconds', 5);
        config()->set('services.dukascopy.http_retry_attempts', 1);
    }

    private static function time(string $time): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $time)) throw new RuntimeException('RECOVERY_TIMESTAMP_INVALID');
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone('UTC'));
        if ($value === false || $value->format('Y-m-d H:i:s') !== $time) throw new RuntimeException('RECOVERY_TIMESTAMP_INVALID');
        return $time;
    }

    private static function price(array $row): void
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            if (! is_numeric($row[$key] ?? null) || ! is_finite((float) $row[$key]) || (float) $row[$key] < 0) throw new RuntimeException('RECOVERY_OHLCV_INVALID');
        }
        if ((float) $row['low'] <= 0 || (float) $row['high'] < max((float) $row['open'], (float) $row['close'])
            || (float) $row['low'] > min((float) $row['open'], (float) $row['close'])) throw new RuntimeException('RECOVERY_OHLCV_INVALID');
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    try {
        $options = getopt('', ['source:', 'source-sha256:', 'batch', 'resume-dataset:', 'apply']);
        $sourcePath = realpath((string) ($options['source'] ?? ''));
        $root = realpath(storage_path('app/lab-datasets/mtf'));
        if ($sourcePath === false || $root === false || ! str_starts_with(str_replace('\\', '/', $sourcePath), str_replace('\\', '/', $root).'/')
            || basename($sourcePath) !== 'm5.csv') throw new RuntimeException('RECOVERY_SOURCE_OUTSIDE_FROZEN_MTF');
        $sourceHash = (string) ($options['source-sha256'] ?? '');
        $batchMode=array_key_exists('batch',$options);
        if (isset($options['resume-dataset']) && ! $batchMode) throw new RuntimeException('RECOVERY_RESUME_REQUIRES_BATCH');
        $source = FrozenM5GapRecoveryOperation::source($sourcePath, $sourceHash, $batchMode);
        $provider = app(App\Services\MarketData\DukascopyMarketDataProvider::class);
        if (config('services.dukascopy.transport', 'jetta') !== 'jetta') throw new RuntimeException('RECOVERY_JETTA_PROVIDER_REQUIRED');
        if ($batchMode) {
            $resumedProofs = [];
            if (isset($options['resume-dataset'])) {
                $priorArchive = App\Models\MarketTrainingArchive::query()->where('dataset_key', $options['resume-dataset'])
                    ->where('provider', 'dukascopy')->where('symbol', 'XAUUSD')->where('timeframe', 'M5')->where('status', 'complete')->first();
                if (! $priorArchive) throw new RuntimeException('RECOVERY_RESUME_ARCHIVE_NOT_FOUND');
                $priorReceipt = (array) data_get($priorArchive->metrics, 'frozen_m5_gap_recovery_receipt', []);
                $resumedProofs = FrozenM5GapRecoveryOperation::resumeProofs($sourcePath, $sourceHash, $source, $priorReceipt,
                    (string) data_get($priorArchive->metrics, 'frozen_m5_gap_recovery_price_path', ''));
                $priorRows = app(App\Services\MarketData\MarketTrainingDataService::class)->query($priorArchive->dataset_key, 'dukascopy', 'XAUUSD', 'M5')
                    ->toBase()->orderBy('time')->cursor()->map(static fn ($row) => ['time'=>(string)$row->time,
                        'open'=>$row->open,'high'=>$row->high,'low'=>$row->low,'close'=>$row->close,'volume'=>$row->volume]);
                if ((int) $priorArchive->row_count !== (int) $priorReceipt['new_rows']
                    || FrozenM5GapRecoveryOperation::economicRowsHash($priorRows) !== $priorReceipt['new_economic_rows_sha256']) {
                    throw new RuntimeException('RECOVERY_RESUME_SQL_CONTENT_INVALID');
                }
            }
            $batch=FrozenM5GapRecoveryOperation::collectBatch($sourcePath,$sourceHash,$source,$provider,$resumedProofs);
            $rows=$batch['rows']; $calendar=$batch['calendar_scope'];
        } else {
        $start = Carbon\CarbonImmutable::parse(FrozenM5GapRecoveryOperation::TARGET, 'UTC'); $end = $start->addMinutes(10);
        $m1 = $provider->fetchCandles('XAUUSD', 'XAUUSD', 'M1', 10, $start, $end);
        $m5 = $provider->fetchCandles('XAUUSD', 'XAUUSD', 'M5', 2, $start, $end);
        $process = new Symfony\Component\Process\Process([(string) config('services.dukascopy.node_binary', 'node'), __DIR__.'/verify-sparse-reopen-ticks.cjs']);
        $process->setTimeout(65); $process->mustRun();
        $ticks = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $proof = FrozenM5GapRecoveryOperation::proof($m1, $m5, $ticks);
        $calendarProcess = new Symfony\Component\Process\Process(['python', __DIR__.'/audit-frozen-m5-gap-source.py', $sourcePath]);
        $calendarProcess->setTimeout(180); $calendarProcess->mustRun();
        $calendar = json_decode($calendarProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (($calendar['source_csv_sha256'] ?? null) !== $sourceHash || ($calendar['source_rows'] ?? null) !== count($source)
            || ($calendar['screening_unexpected_after'] ?? null) !== 0) throw new RuntimeException('RECOVERY_SELECTED_SCREENING_SCOPE_STILL_GAPPED');
        $rows = FrozenM5GapRecoveryOperation::fork($source, $proof);
        }
        $csv = FrozenM5GapRecoveryOperation::csvBytes($rows);
        $identity = ['protocol' => $batchMode?FrozenM5GapRecoveryOperation::BATCH_PROTOCOL:FrozenM5GapRecoveryOperation::PROTOCOL, 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'provider' => 'dukascopy', 'source_csv_path' => $sourcePath, 'source_csv_sha256' => $sourceHash, 'source_rows' => count($source),
            'new_rows' => count($rows), 'source_first_at' => $source[0]['time'], 'source_last_at' => $source[array_key_last($source)]['time'],
            'new_price_csv_sha256' => hash('sha256', $csv), 'economic_row_hash_protocol' => 'training_decimal_6_rows_v1',
            'new_economic_rows_sha256' => FrozenM5GapRecoveryOperation::economicRowsHash($rows),
            ...($batchMode?['target_proofs'=>$batch['target_proofs'],'canonical_missing_utc'=>$batch['canonical_missing_utc'],
                'unresolved_targets'=>$batch['unresolved_targets'],'provider_days_requested'=>$batch['provider_days_requested'],
                'tick_hours_requested'=>$batch['tick_hours_requested'],'collection_ceiling_seconds'=>$batch['collection_ceiling_seconds']]:['proof'=>$proof]),
            'calendar_scope' => $calendar, 'independent_evidence' => false,
            'runtime_trade_authority' => false, 'promotion_evidence' => false, 'quote_liquidity_inherited' => false];
        $repairHash = app(App\Services\ExecutionContractService::class)->hashParameters($identity);
        $dataset = 'foundation_intraday_gapfix_'.substr($repairHash, 0, 16);
        $receipt = [...$identity, 'repair_hash' => $repairHash, 'dataset_key' => $dataset];
        $target = storage_path('app/lab-datasets/training/recovery/'.$repairHash.'/m5.csv');
        if (array_key_exists('apply', $options)) {
            if (! hash_equals($sourceHash, (string) hash_file('sha256', $sourcePath))) throw new RuntimeException('RECOVERY_SOURCE_DRIFT_BEFORE_APPLY');
            $training = app(App\Services\MarketData\MarketTrainingDataService::class);
            Illuminate\Support\Facades\DB::transaction(function () use ($training, $receipt, $dataset, $source, $rows, $csv, $target): void {
                $existing = App\Models\MarketTrainingArchive::query()->where('dataset_key', $dataset)->where('provider', 'dukascopy')->where('symbol', 'XAUUSD')->where('timeframe', 'M5')->lockForUpdate()->first();
                if ($existing) {
                    if (data_get($existing->metrics, 'frozen_m5_gap_recovery_receipt') !== $receipt || ! is_file($target)
                        || hash_file('sha256', $target) !== $receipt['new_price_csv_sha256'] || (int) $existing->row_count !== count($rows)) throw new RuntimeException('RECOVERY_EXISTING_NEW_ARCHIVE_CONFLICT');
                    $current = $training->query($dataset, 'dukascopy', 'XAUUSD', 'M5')->toBase()->orderBy('time')->cursor()->map(fn ($row) => [
                        'time' => (string) $row->time, 'open' => $row->open,
                        'high' => $row->high, 'low' => $row->low, 'close' => $row->close, 'volume' => $row->volume]);
                    if (FrozenM5GapRecoveryOperation::economicRowsHash($current) !== $receipt['new_economic_rows_sha256']) throw new RuntimeException('RECOVERY_EXISTING_NEW_ARCHIVE_CONTENT_CHANGED');
                    return;
                }
                $directory = dirname($target);
                if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) throw new RuntimeException('RECOVERY_ARTIFACT_DIRECTORY_FAILED');
                if (is_file($target)) {
                    if (hash_file('sha256', $target) !== $receipt['new_price_csv_sha256']) throw new RuntimeException('RECOVERY_NEW_ARTIFACT_CONFLICT');
                } else {
                    $handle = fopen($target, 'xb'); if ($handle === false) throw new RuntimeException('RECOVERY_ARTIFACT_CREATE_FAILED');
                    try { if (fwrite($handle, $csv) !== strlen($csv)) throw new RuntimeException('RECOVERY_ARTIFACT_WRITE_FAILED'); } finally { fclose($handle); }
                }
                $from = Carbon\CarbonImmutable::parse($source[0]['time'], 'UTC');
                $to = Carbon\CarbonImmutable::parse($source[array_key_last($source)]['time'], 'UTC')->addMinutes(5);
                $archive = $training->ensureArchive($dataset, 'dukascopy', 'XAUUSD', 'M5', $from, $to);
                $result = $training->importCsv($archive, $target, $from, $to);
                if ($result['imported'] !== count($rows) || $result['skipped'] !== 0 || (int) data_get($result, 'coverage.row_count') !== count($rows)) throw new RuntimeException('RECOVERY_NEW_ARCHIVE_IMPORT_INCOMPLETE');
                $archive->update(['status' => 'complete', 'backfill_cursor_at' => $to, 'metrics' => [...(array) $archive->metrics,
                    'frozen_m5_gap_recovery_receipt' => $receipt, 'frozen_m5_gap_recovery_price_path' => $target]]);
            });
        }
        echo json_encode(['mode' => array_key_exists('apply', $options) ? 'applied_new_archive' : 'dry_run_no_writes',
            'dataset_key' => $dataset, 'prospective_price_path' => $target, 'receipt' => $receipt,
            'quote_dependency' => 'NEW_BASE_SHA_REQUIRES_EXISTING_BOUNDED_QUOTE_SIDECAR_FREEZE',
            'old_frozen_source_changed' => false, 'old_trial_retry_authorized' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
}
