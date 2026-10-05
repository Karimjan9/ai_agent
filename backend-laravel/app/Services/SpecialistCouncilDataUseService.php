<?php

namespace App\Services;

use App\Models\PaperOrder;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use InvalidArgumentException;

/** Market-event exposure, independent of provider, file/hash, timeframe and candidate labels. */
class SpecialistCouncilDataUseService
{
    public const PROTOCOL = 'specialist_council_event_use_v1';
    public const USES = ['training', 'selection', 'evaluation', 'paper'];

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    /** Trusted ingress supplies event chronology; availability and maturity are checked before every use. */
    public function recordUse(SpecialistCouncilVersion $version, array $observations, string $use,
        string $consumerId, string $asOf, ?string $runId = null): array
    {
        if (! in_array($use, self::USES, true) || ! preg_match('/^[A-Za-z0-9_.:-]{1,150}$/', $consumerId)
            || $observations === [] || count($observations) > 50000) throw new InvalidArgumentException('Unknown, unattributed or unbounded data use.');
        $time = $this->time($asOf);
        if ($time->greaterThan(now()->utc())) throw new LogicException('DATA_USE_AS_OF_IS_IN_THE_FUTURE');
        $policy = ['protocol' => self::PROTOCOL, 'epoch_contract' => $this->epochs->contract(),
            'provider_or_hash_relabeling_independent' => false, 'event_overlap_independent' => false,
            'mature_feedback_required' => true];
        $policyHash = $this->epochs->parameterHash($policy);
        return DB::transaction(function () use ($version, $observations, $use, $consumerId, $time, $runId, $policyHash): array {
            // Serialize exposure decisions across the whole lineage, including simultaneous successor exams.
            $lineage = SpecialistCouncilVersion::where('council_id', $version->council_id)->orderBy('id')->lockForUpdate()->get();
            $current = $lineage->firstWhere('id', $version->id);
            if (! $current || ! app(SpecialistCouncilContractService::class)->manifestValid($current->manifest)) throw new LogicException('DATA_USE_VERSION_IDENTITY_INVALID');
            $symbols = array_values(array_unique(array_map(fn (array $event): string => strtoupper((string) ($event['symbol'] ?? '')), $observations)));
            sort($symbols);
            foreach ($symbols as $symbol) {
                DB::table('specialist_council_data_locks')->insertOrIgnore(['symbol' => $symbol]);
                DB::table('specialist_council_data_locks')->where('symbol', $symbol)->lockForUpdate()->first();
            }
            $count = 0;
            foreach ($observations as $observation) {
                $symbol = strtoupper((string) ($observation['symbol'] ?? ''));
                if (! preg_match('/^[A-Z0-9_.:-]{1,30}$/', $symbol)) throw new InvalidArgumentException('Market event instrument is missing.');
                $start = $this->time($observation['event_start'] ?? null); $end = $this->time($observation['event_end'] ?? null);
                $available = $this->time($observation['available_at'] ?? null);
                $matured = isset($observation['matured_at']) ? $this->time($observation['matured_at']) : null;
                if (! $end->greaterThan($start) || $available->lessThan($end) || $available->greaterThan($time)
                    || ($matured !== null && $matured->lessThan($available))) throw new LogicException('DATA_NOT_AVAILABLE_AT_DECISION_TIME');
                if ($use !== 'paper' && ! $this->epochs->researchIntervalDisjointFromPaper($start->toIso8601String(), $end->toIso8601String())) {
                    throw new LogicException('2026_PAPER_OR_AUTHORIZED_PAPER_EVENTS_ARE_NOT_RESEARCH_DATA');
                }
                if ($use !== 'paper' && ($matured === null || $matured->greaterThan($time))) throw new LogicException('FEEDBACK_OR_EVALUATION_OUTCOME_NOT_MATURE');
                if ($use === 'paper') {
                    $contract = (array) ($observation['paper_epoch_contract'] ?? $this->epochs->contract());
                    $freeze = $current->sealed_at->toIso8601String();
                    if (! $this->epochs->paperWindowValid([$start->toIso8601String(), $end->subMicrosecond()->toIso8601String()],
                        data_get($contract, 'paper_epoch.window_key'), $contract, $freeze)) throw new LogicException('PAPER_EVENT_OUTSIDE_AUTHORIZED_EPOCH');
                }
                if ($use === 'evaluation' && $this->intervalExposed($current, $symbol, $start->toIso8601String(), $end->toIso8601String())) {
                    throw new LogicException('EVALUATION_EVENTS_ALREADY_USED_FOR_TRAINING_OR_SELECTION');
                }
                $identity = ['symbol' => $symbol, 'event_start' => $start->toIso8601String(), 'event_end' => $end->toIso8601String()];
                $key = $this->epochs->parameterHash($identity);
                $event = DB::table('specialist_council_data_events')->where('event_key', $key)->first();
                if ($event) {
                    if (! $available->equalTo(CarbonImmutable::parse($event->available_at, 'UTC'))
                        || (($matured === null) !== ($event->matured_at === null))
                        || ($matured !== null && ! $matured->equalTo(CarbonImmutable::parse($event->matured_at, 'UTC')))) {
                        throw new LogicException('ORIGINAL_EVENT_AVAILABILITY_OR_MATURITY_CANNOT_BE_RELABELED');
                    }
                    $eventId = $event->id;
                } else {
                    $eventId = DB::table('specialist_council_data_events')->insertGetId(['event_key' => $key, 'symbol' => $symbol,
                        'market' => 'canonical_market', 'event_start' => $start, 'event_end' => $end,
                        'available_at' => $available, 'matured_at' => $matured,
                        'provenance' => json_encode((array) ($observation['provenance'] ?? []), JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now()]);
                }
                $usageKey = $this->epochs->parameterHash(['version_id' => $current->id, 'event_key' => $key,
                    'use' => $use, 'consumer_id' => $consumerId, 'run_id' => $runId]);
                $count += DB::table('specialist_council_data_uses')->insertOrIgnore(['usage_key' => $usageKey,
                    'specialist_council_version_id' => $current->id, 'council_id' => $current->council_id,
                    'event_id' => $eventId, 'use' => $use, 'consumer_id' => $consumerId, 'run_id' => $runId,
                    'as_of' => $time, 'policy_hash' => $policyHash, 'created_at' => now(), 'updated_at' => now()]);
            }
            return ['protocol' => self::PROTOCOL, 'use' => $use, 'recorded' => $count,
                'observations' => count($observations), 'version_id' => $current->id,
                'policy_hash' => $policyHash, 'promotion_evidence' => false];
        });
    }

    /** Shared lab selection cannot recover independence by renaming the council or provider. */
    public function intervalExposed(SpecialistCouncilVersion $version, string $symbol, string $start, string $end): bool
    {
        $from = $this->time($start); $until = $this->time($end);
        return DB::table('specialist_council_data_uses as uses')
            ->join('specialist_council_data_events as events', 'events.id', '=', 'uses.event_id')
            ->whereIn('uses.use', ['training', 'selection'])
            ->where('events.symbol', strtoupper($symbol))->where('events.event_start', '<', $until)
            ->where('events.event_end', '>', $from)->exists();
    }

    /** Derive event identities from the original input rows, never from a provider's independence label. */
    public function recordReplayUse(SpecialistCouncilVersion $version, array $request, string $runId, ?array $receipt = null): array
    {
        if ($receipt !== null) {
            if (($receipt['protocol'] ?? '') !== 'specialist_council_receipt_v1'
                || ($receipt['dataset_hash'] ?? '') !== ($request['replay_dataset_hash'] ?? '')) throw new LogicException('REPLAY_USE_CONSUMPTION_RECEIPT_INVALID');
            app(SpecialistCouncilLifecycleService::class)->assertReceiptSeal($receipt);
        }
        $binding = (array) ($request['specialist_council_evaluation'] ?? []);
        $use = 'training';
        if ($binding !== []) {
            $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
            $plan = $row ? json_decode($row->plan, true) : null;
            if (! is_array($plan) || ($binding['plan_hash'] ?? '') !== $row->plan_hash
                || ! isset($plan['arms'][$binding['arm_key'] ?? ''])) throw new LogicException('REPLAY_USE_PREREGISTRATION_MISSING');
            $use = $plan['purpose'] === 'independent' ? 'evaluation' : 'selection';
        }
        $timeframe = (string) ($request['timeframe'] ?? 'H1');
        $seconds = app(SpecialistCouncilContractService::class)->timeframeSeconds($timeframe);
        $holding = max(array_map(fn (array $member): int => (int) data_get($member, 'horizon.max_holding_seconds', 0), $version->manifest['members']));
        $symbol = strtoupper((string) ($request['symbol'] ?? ''));
        $observations = [];
        $batch = [];
        $flush = function () use (&$batch, &$observations): void {
            if ($batch === []) return;
            // Every original event participates in this unsampled digest. Intervals deliberately
            // include gaps, conservatively fencing the whole source range rather than undercounting warmup.
            $identities = array_map(fn (array $event): array => ['symbol' => $event['symbol'],
                'event_start' => $event['event_start'], 'event_end' => $event['event_end']], $batch);
            $last = $batch[array_key_last($batch)];
            $observations[] = [...$batch[0], 'event_end' => $last['event_end'],
                'available_at' => $last['available_at'], 'matured_at' => $last['matured_at'],
                'provenance' => [...$batch[0]['provenance'], 'event_count' => count($batch),
                    'event_identities_hash' => $this->epochs->parameterHash($identities),
                    'exposure_policy' => 'conservative_whole_source_including_warmup', 'sampled' => false]];
            $batch = [];
        };
        if (! empty($request['candles'])) {
            foreach ($request['candles'] as $candle) {
                $batch[] = $this->candleEvent($symbol, $candle['time'] ?? $candle['timestamp'] ?? null,
                    $seconds, $holding, ['source' => 'original_inline_request', 'run_id' => $runId]);
                if (count($batch) === 1000) $flush();
            }
            $flush();
        } else {
            $paths = [$timeframe => $request['dataset_path'] ?? null];
            foreach ((array) ($request['mtf_dataset_paths'] ?? []) as $stream => $path) $paths[$stream] = $path;
            if (! empty($request['regime_dataset_path'])) $paths['REGIME_H1'] = $request['regime_dataset_path'];
            foreach ($paths as $stream => $path) {
                if (! is_string($path) || $path === '') throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_MISSING');
                $resolved = realpath($path) ?: realpath(dirname(base_path()).DIRECTORY_SEPARATOR.$path);
                if ($resolved === false || ! is_file($resolved) || is_link($resolved)) throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_INVALID');
                $rootAllowed = false;
                foreach ([storage_path('app/lab-datasets'), dirname(base_path()).DIRECTORY_SEPARATOR.'datasets'] as $root) {
                    $actualRoot = realpath($root);
                    if ($actualRoot !== false && str_starts_with(strtolower($resolved), strtolower($actualRoot).DIRECTORY_SEPARATOR)) $rootAllowed = true;
                }
                if (! $rootAllowed) throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_OUTSIDE_CANONICAL_ROOT');
                $sourceTimeframe = $stream === 'REGIME_H1' ? 'H1' : strtoupper($stream);
                $expectedHash = data_get($request, 'mtf_snapshot_manifest.streams.'.$sourceTimeframe.'.sha256');
                if ($stream === 'REGIME_H1') $expectedHash ??= data_get($request, 'policy_context.snapshot_transport.regime_dataset_sha256');
                if ($expectedHash === null && strtoupper($stream) === strtoupper($timeframe)) {
                    $expectedHash = data_get($receipt, 'source_attestation.actual_source_sha256', $request['replay_dataset_hash'] ?? null);
                }
                if (! is_string($expectedHash)) throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_HASH_MISSING');
                [$handle, $actualHash] = $this->verifiedCsv($resolved, $expectedHash);
                try {
                    if ($receipt !== null && strtoupper($stream) === strtoupper($timeframe)
                        && data_get($receipt, 'source_attestation.actual_source_sha256') !== $actualHash) throw new LogicException('REPLAY_USE_PRIMARY_SOURCE_RECEIPT_MISMATCH');
                    $header = fgetcsv($handle, escape: '');
                    $columns = array_map(fn ($field) => strtolower(trim((string) $field)), $header ?: []);
                    $timeIndex = array_search('time', $columns, true);
                    if ($timeIndex === false) $timeIndex = array_search('timestamp', $columns, true);
                    if ($timeIndex === false) throw new LogicException('ORIGINAL_REPLAY_DATA_TIME_COLUMN_MISSING');
                    $streamSeconds = app(SpecialistCouncilContractService::class)->timeframeSeconds($sourceTimeframe);
                    $last = null; $rows = 0;
                    while (($row = fgetcsv($handle, escape: '')) !== false) {
                        $event = $this->candleEvent($symbol, $row[$timeIndex] ?? null, $streamSeconds, $holding,
                            ['source_sha256' => $actualHash, 'stream' => $stream, 'run_id' => $runId]);
                        if ($last !== null && $event['event_start'] <= $last) throw new LogicException('ORIGINAL_REPLAY_DATA_CHRONOLOGY_INVALID');
                        $last = $event['event_start']; $batch[] = $event;
                        if (count($batch) === 1000) $flush();
                        if (++$rows > 2000000 || count($observations) > 50000) throw new LogicException('EVENT_USE_SOURCE_REQUIRES_BOUNDED_REPLAY_SLICE');
                    }
                    $flush();
                    if ($receipt !== null && strtoupper($stream) === strtoupper($timeframe)
                        && (int) data_get($receipt, 'source_attestation.source_rows', 0) !== $rows) throw new LogicException('REPLAY_USE_LOADED_SOURCE_ROW_COUNT_MISMATCH');
                } finally { fclose($handle); }
            }
        }
        return $this->recordUse($version, $observations, $use, 'replay:'.$runId, now()->utc()->toIso8601String(), $runId);
    }

    /** Hash exactly the bytes subsequently parsed; source replacement cannot relabel the event ledger. */
    private function verifiedCsv(string $path, string $expectedHash): array
    {
        $source = fopen($path, 'rb');
        $snapshot = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($source === false || $snapshot === false) throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_UNREADABLE');
        $context = hash_init('sha256'); $bytes = 0;
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1048576);
                if ($chunk === false) throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_UNREADABLE');
                $bytes += strlen($chunk);
                if ($bytes > 536870912) throw new LogicException('EVENT_USE_SOURCE_BYTE_BUDGET_EXCEEDED');
                hash_update($context, $chunk);
                if (fwrite($snapshot, $chunk) !== strlen($chunk)) throw new LogicException('ORIGINAL_REPLAY_DATA_SNAPSHOT_WRITE_FAILED');
            }
            $actualHash = hash_final($context);
            if (! hash_equals($expectedHash, $actualHash)) throw new LogicException('ORIGINAL_REPLAY_DATA_SOURCE_HASH_MISMATCH');
            rewind($snapshot);
            return [$snapshot, $actualHash];
        } catch (\Throwable $error) {
            fclose($snapshot); throw $error;
        } finally { fclose($source); }
    }

    /** An open swing outcome never becomes mature economic feedback, and 2026 remains paper-only. */
    public function recordMaturePaperFeedback(PaperOrder $order, SpecialistCouncilVersion $version, string $consumerId, string $asOf): array
    {
        $order = $order->fresh() ?? $order;
        $time = $this->time($asOf);
        if ($order->status !== 'closed' || ! $order->closed_at || ! $order->opened_at
            || $order->closed_at->greaterThan($time)) return ['allowed' => false, 'reason_code' => 'POSITION_FEEDBACK_NOT_MATURE', 'promotion_evidence' => false];
        if (! $this->epochs->researchIntervalDisjointFromPaper($order->opened_at->toIso8601String(), $order->closed_at->toIso8601String())) {
            return ['allowed' => false, 'reason_code' => 'PAPER_EPOCH_FEEDBACK_REMAINS_OBSERVATION_ONLY', 'promotion_evidence' => false];
        }
        $binding = (array) data_get($order->signal_context, 'specialist_council_binding', []);
        if (($binding['council_id'] ?? null) !== $version->council_id || ($binding['council_version'] ?? null) !== $version->version) {
            return ['allowed' => false, 'reason_code' => 'PAPER_FEEDBACK_OWNER_MISMATCH', 'promotion_evidence' => false];
        }
        return ['allowed' => true, ...$this->recordUse($version, [[
            'symbol' => $order->symbol, 'event_start' => $order->opened_at->toIso8601String(),
            'event_end' => $order->closed_at->toIso8601String(), 'available_at' => $order->closed_at->toIso8601String(),
            'matured_at' => $order->closed_at->toIso8601String(), 'provenance' => ['paper_order_id' => $order->id],
        ]], 'training', $consumerId, $asOf)];
    }

    private function candleEvent(string $symbol, mixed $timestamp, int $seconds, int $holding, array $provenance): array
    {
        $start = $this->time($timestamp, true); $end = $start->addSeconds($seconds);
        return ['symbol' => $symbol, 'event_start' => $start->toIso8601String(), 'event_end' => $end->toIso8601String(),
            'available_at' => $end->toIso8601String(), 'matured_at' => $end->toIso8601String(), 'provenance' => $provenance];
    }

    private function time(mixed $value, bool $csvUtc = false): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match($csvUtc
            ? '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/'
            : '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new InvalidArgumentException('Market-event chronology requires an explicit timestamp.');
        }
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
