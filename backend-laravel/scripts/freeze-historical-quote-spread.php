<?php

/**
 * Freeze a bounded, research-only observed spread sidecar from paired Jetta
 * BID/ASK minute closes. It never edits a generation's existing M5 snapshot.
 */

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MarketData\DukascopyMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

$options = getopt('', ['m5:', 'from:', 'to:', 'freeze']);
$source = realpath((string) ($options['m5'] ?? ''));
$allowedRoot = realpath(storage_path('app/lab-datasets/mtf'));
$sourceNormalized = $source ? str_replace('\\', '/', strtolower($source)) : '';
$rootNormalized = $allowedRoot ? rtrim(str_replace('\\', '/', strtolower($allowedRoot)), '/').'/' : '';
if (! $source || ! $allowedRoot || ! str_starts_with($sourceNormalized, $rootNormalized)
    || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'csv') {
    fwrite(STDERR, "--m5 must name one existing frozen MTF CSV.\n");
    exit(2);
}
try {
    $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($options['from'] ?? ''), 'UTC');
    $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($options['to'] ?? ''), 'UTC');
} catch (Throwable) {
    fwrite(STDERR, "--from and --to must be UTC YYYY-MM-DD dates.\n");
    exit(2);
}
if (! $from || ! $to || $from->format('Y-m-d') !== ($options['from'] ?? null)
    || $to->format('Y-m-d') !== ($options['to'] ?? null)
    || $to->lessThanOrEqualTo($from) || $from->diffInDays($to) > 31
    || $to->greaterThan(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'))) {
    fwrite(STDERR, "Choose at most 31 complete pre-2026 UTC days; 2026 remains paper-only.\n");
    exit(2);
}

$handle = fopen($source, 'rb');
if (! $handle) throw new RuntimeException('Frozen M5 CSV cannot be opened.');
$header = fgetcsv($handle);
$timeColumn = array_search('time', $header ?: [], true);
$closeColumn = array_search('close', $header ?: [], true);
if ($timeColumn === false || $closeColumn === false) throw new RuntimeException('M5 time/close columns are required.');
$m5 = [];
while (($row = fgetcsv($handle)) !== false) {
    $time = (string) ($row[$timeColumn] ?? '');
    if ($time >= $from->format('Y-m-d H:i:s') && $time < $to->format('Y-m-d H:i:s')) {
        if (! is_numeric($row[$closeColumn] ?? null)) throw new RuntimeException('Non-numeric M5 close at '.$time);
        $m5[$time] = (float) $row[$closeColumn];
    }
}
fclose($handle);
if ($m5 === []) throw new RuntimeException('No M5 rows in the bounded interval.');

$decoder = new ReflectionMethod(app(DukascopyMarketDataProvider::class), 'decodeJettaMinutePayload');
$provider = app(DukascopyMarketDataProvider::class);
$minutes = ['BID' => [], 'ASK' => []];
$sourceDays = [];
$neededDays = array_values(array_unique(array_map(static fn (string $time): string => substr($time, 0, 10), array_keys($m5))));
foreach ($neededDays as $date) {
    $day = CarbonImmutable::parse($date.' 00:00:00', 'UTC');
    foreach (['BID', 'ASK'] as $side) {
        $url = sprintf('%s/v1/candles/minute/XAU-USD/%s/%d/%d/%d',
            rtrim((string) config('services.dukascopy.jetta_base_url', 'https://jetta.dukascopy.com'), '/'),
            $side, $day->year, $day->month, $day->day);
        $response = Http::timeout(20)->retry(2, 500)->get($url)->throw();
        $payload = $response->json();
        if (! is_array($payload)) throw new RuntimeException('Non-JSON Jetta response: '.$day->format('Y-m-d').' '.$side);
        $sourceDays[$day->format('Y-m-d')][$side] = hash('sha256', $response->body());
        foreach ($decoder->invoke($provider, $payload, $day, $day->addDay()) as $row) {
            $minutes[$side][$row['time']] = (float) $row['close'];
        }
    }
}

$csv = "time,spread,spread_available,bid_close,ask_close,observation_status\n";
$counts = ['paired' => 0, 'missing_minute' => 0, 'bid_mismatch' => 0, 'negative_spread' => 0];
foreach ($m5 as $time => $close) {
    $minute = CarbonImmutable::parse($time, 'UTC')->addMinutes(4)->format('Y-m-d H:i:s');
    $bid = $minutes['BID'][$minute] ?? null;
    $ask = $minutes['ASK'][$minute] ?? null;
    $status = $bid === null || $ask === null ? 'missing_minute'
        : (abs($bid - $close) > 0.000001 ? 'bid_mismatch'
            : ($ask < $bid ? 'negative_spread' : 'paired'));
    $counts[$status]++;
    $csv .= implode(',', [
        $time,
        $status === 'paired' ? number_format($ask - $bid, 6, '.', '') : '',
        $status === 'paired' ? '1' : '0',
        $bid === null ? '' : number_format($bid, 6, '.', ''),
        $ask === null ? '' : number_format($ask, 6, '.', ''),
        $status,
    ])."\n";
}
$identity = [
    'protocol' => 'research_quote_spread_sidecar_v1',
    'symbol' => 'XAUUSD', 'timeframe' => 'M5',
    'from_utc' => $from->format('Y-m-d'), 'to_utc_exclusive' => $to->format('Y-m-d'),
    'm5_sha256' => hash_file('sha256', $source),
    'decoder_sha256' => hash_file('sha256', (new ReflectionClass(DukascopyMarketDataProvider::class))->getFileName()),
    'provider' => 'dukascopy_jetta_bid_ask_minute_v1',
    'observation' => 'last_closed_m1_bid_ask_close_for_each_m5',
    'source_day_body_sha256' => $sourceDays,
    'sidecar_sha256' => hash('sha256', $csv), 'counts' => $counts,
    'coverage' => round($counts['paired'] / count($m5), 8),
    'paper_2026_included' => false, 'promotion_evidence' => false,
    'automatic_replay_authority' => false,
];
if ($counts['paired'] === 0) throw new RuntimeException('No verified BID/ASK observations matched the frozen M5 close.');
$digest = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
$result = ['identity_hash' => $digest, 'identity' => $identity, 'frozen' => false];
if (isset($options['freeze'])) {
    $base = storage_path('app/lab-datasets/quote-spread');
    if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
        throw new RuntimeException('Quote-spread artifact directory cannot be created.');
    }
    $target = $base.'/'.$digest;
    if (is_dir($target)) {
        $existing = json_decode((string) file_get_contents($target.'/manifest.json'), true);
        if (($existing['identity_hash'] ?? null) !== $digest
            || ! hash_equals((string) ($existing['identity']['sidecar_sha256'] ?? ''), hash_file('sha256', $target.'/m5-spread.csv'))) {
            throw new RuntimeException('Existing quote-spread artifact differs; refusing overwrite.');
        }
    } else {
        $stage = $base.'/.staging-'.bin2hex(random_bytes(8));
        if (! mkdir($stage)) throw new RuntimeException('Cannot create quote-spread staging directory.');
        file_put_contents($stage.'/m5-spread.csv', $csv);
        file_put_contents($stage.'/manifest.json', json_encode(['identity_hash' => $digest, 'identity' => $identity], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (! rename($stage, $target)) throw new RuntimeException('Cannot seal quote-spread artifact.');
    }
    $result['frozen'] = true;
    $result['artifact_path'] = $target;
}
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
