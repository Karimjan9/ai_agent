<?php
namespace Tests\Feature;

use App\Models\MarketTrainingArchive;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\MarketData\MarketTrainingDataService;
use App\Services\MultiTimeframeSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrozenM5GapRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $operation = base_path('scripts/recover-frozen-m5-gap.php');
        if (! is_file($operation)) $operation = dirname(base_path()).'/.runtime/frozen-m5-gap-repair-staging-2026-10-03/recover-frozen-m5-gap.php';
        if (! class_exists(\FrozenM5GapRecoveryOperation::class, false)) require_once $operation;
    }

    private function proof(): array
    {
        $m1 = [
            ['time'=>'2025-12-17 23:01:00','open'=>4339.53,'high'=>4339.953,'low'=>4336.795,'close'=>4339.953,'volume'=>0.00106],
            ['time'=>'2025-12-17 23:02:00','open'=>4336.725,'high'=>4340.248,'low'=>4336.725,'close'=>4339.498,'volume'=>0.01042],
            ['time'=>'2025-12-17 23:03:00','open'=>4339.498,'high'=>4339.898,'low'=>4337.385,'close'=>4339.398,'volume'=>0.0061],
            ['time'=>'2025-12-17 23:04:00','open'=>4339.298,'high'=>4339.298,'low'=>4338.548,'close'=>4338.648,'volume'=>0.00271],
        ];
        $m5 = [['time'=>'2025-12-17 23:00:00','open'=>4339.53,'high'=>4340.248,'low'=>4336.725,'close'=>4338.648,'volume'=>0.02029]];
        $ticks = ['source_hour_sha256'=>\FrozenM5GapRecoveryOperation::EXPECTED_TICK_HOUR_SHA256,'first_bucket_count'=>137,'whole_hour_count'=>4572,
            'minute_counts'=>['2025-12-17T23:01'=>6,'2025-12-17T23:02'=>64,'2025-12-17T23:03'=>45,'2025-12-17T23:04'=>22],
            'first_bucket_ohlc'=>['open'=>4339.53,'high'=>4340.248,'low'=>4336.725,'close'=>4338.648],
            'first_tick_utc'=>'2025-12-17T23:01:27.345Z','last_tick_utc'=>'2025-12-17T23:04:59.303Z'];
        return [$m1,$m5,$ticks];
    }

    public function test_observed_sparse_reopen_is_explicit_not_complete_and_preserves_original_rows(): void
    {
        [$m1,$m5,$ticks] = $this->proof();
        $proof = \FrozenM5GapRecoveryOperation::proof($m1,$m5,$ticks);
        $source = [$this->row('2025-12-17 21:55:00'),$this->row('2025-12-17 23:05:00')];
        $fork = \FrozenM5GapRecoveryOperation::fork($source,$proof);
        $this->assertCount(3,$fork); $this->assertSame($source[0],$fork[0]); $this->assertSame($source[1],$fork[2]);
        $this->assertSame(4,$proof['observed_m1_minutes']); $this->assertFalse($proof['complete_minute_coverage']);
        $this->assertFalse($proof['unobserved_minute_filled']); $this->assertSame(137,$proof['actual_tick_count']);
        $this->assertSame('2025-12-17 23:00:00',$fork[1]['time']);
    }

    public function test_tick_or_provider_mismatch_cannot_make_a_recovery(): void
    {
        [$m1,$m5,$ticks]=$this->proof(); $ticks['source_hour_sha256']=str_repeat('a',64);
        try { \FrozenM5GapRecoveryOperation::proof($m1,$m5,$ticks); $this->fail('Tamperedtick accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('RECOVERY_RAW_TICK_EVIDENCE_MISMATCH',$e->getMessage()); }
        [$m1,$m5,$ticks]=$this->proof(); $m5[0]['close']+=1;
        $this->expectExceptionMessage('RECOVERY_PROVIDER_M1_M5_DISAGREE:close'); \FrozenM5GapRecoveryOperation::proof($m1,$m5,$ticks);
    }

    public function test_source_sha_target_or_future_chronology_is_refused(): void
    {
        $path=tempnam(sys_get_temp_dir(),'m5repair-');
        try {
            file_put_contents($path,\FrozenM5GapRecoveryOperation::csvBytes([$this->row('2025-12-17 21:55:00'),$this->row('2025-12-17 23:05:00')]));
            $source=\FrozenM5GapRecoveryOperation::source($path,hash_file('sha256',$path)); $this->assertCount(2,$source);
            try { \FrozenM5GapRecoveryOperation::source($path,str_repeat('a',64)); $this->fail('Wrongsha accepted'); }
            catch (\RuntimeException $e) { $this->assertSame('RECOVERY_FROZEN_SOURCE_SHA_MISMATCH',$e->getMessage()); }
            file_put_contents($path,\FrozenM5GapRecoveryOperation::csvBytes([$this->row('2025-12-17 21:55:00'),$this->row('2026-01-01 00:00:00')]));
            $this->expectExceptionMessage('RECOVERY_SOURCE_CHRONOLOGY_OR_TARGET_INVALID'); \FrozenM5GapRecoveryOperation::source($path,hash_file('sha256',$path));
        } finally { unlink($path); }
    }

    public function test_selected_fork_readiness_verifies_old_new_bytes_and_actual_database_content(): void
    {
        [$m1,$m5,$ticks]=$this->proof(); $proof=\FrozenM5GapRecoveryOperation::proof($m1,$m5,$ticks);
        $source=[$this->row('2025-12-17 21:55:00'),$this->row('2025-12-17 23:05:00')]; $rows=\FrozenM5GapRecoveryOperation::fork($source,$proof);
        $sourceDir=storage_path('app/lab-datasets/mtf/test-gaprepair-'.bin2hex(random_bytes(5))); File::ensureDirectoryExists($sourceDir);
        $sourcePath=$sourceDir.'/m5.csv'; File::put($sourcePath,\FrozenM5GapRecoveryOperation::csvBytes($source)); $priceCsv=\FrozenM5GapRecoveryOperation::csvBytes($rows);
        $identity=['protocol'=>'frozen_m5_gap_recovery_v1','symbol'=>'XAUUSD','timeframe'=>'M5','provider'=>'dukascopy','source_csv_path'=>realpath($sourcePath),
            'source_csv_sha256'=>hash_file('sha256',$sourcePath),'source_rows'=>2,'new_rows'=>3,'source_first_at'=>$source[0]['time'],'source_last_at'=>$source[1]['time'],
            'new_price_csv_sha256'=>hash('sha256',$priceCsv),'economic_row_hash_protocol'=>'training_decimal_6_rows_v1',
            'new_economic_rows_sha256'=>\FrozenM5GapRecoveryOperation::economicRowsHash($rows),'proof'=>$proof,
            'calendar_scope'=>['protocol'=>'frozen_source_calendar_scope_audit_v1','source_csv_sha256'=>hash_file('sha256',$sourcePath),'screening_unexpected_after'=>0,
                'full_source_unexpected_after'=>1,'whole_archive_continuity_proven'=>false],
            'independent_evidence'=>false,'runtime_trade_authority'=>false,'promotion_evidence'=>false,'quote_liquidity_inherited'=>false];
        $hash=app(ExecutionContractService::class)->hashParameters($identity); $dataset='foundation_intraday_gapfix_'.substr($hash,0,16);
        $priceDir=storage_path('app/lab-datasets/training/recovery/'.$hash); File::ensureDirectoryExists($priceDir); $pricePath=$priceDir.'/m5.csv'; File::put($pricePath,$priceCsv);
        try {
            $receipt=[...$identity,'repair_hash'=>$hash,'dataset_key'=>$dataset]; $training=app(MarketTrainingDataService::class);
            $archive=$training->ensureArchive($dataset,'dukascopy','XAUUSD','M5',\Carbon\CarbonImmutable::parse($source[0]['time'],'UTC'),\Carbon\CarbonImmutable::parse($source[1]['time'],'UTC')->addMinutes(5));
            $training->upsertCandles($dataset,'dukascopy','XAUUSD','M5',$rows); $training->refreshCoverage($archive);
            $archive->update(['status'=>'complete','metrics'=>['frozen_m5_gap_recovery_receipt'=>$receipt,'frozen_m5_gap_recovery_price_path'=>$pricePath]]);
            $service=app(MultiTimeframeSnapshotService::class); $method=new \ReflectionMethod($service,'verifiedProspectiveM5Repair');
            $verified=$method->invoke($service,$archive); $this->assertTrue($verified['verified']);
            $this->assertSame($identity['source_csv_sha256'],$verified['original_bad_m5_sha256']);
            $this->assertSame(hash_file('sha256',$pricePath),$verified['prospective_m5_source_sha256']); $this->assertFalse($verified['independent_evidence']);
            config()->set('services.xauusd_organism.research_m5_dataset',$dataset);
            $readiness=$service->agentValidationReadiness('XAUUSD');
            $this->assertFalse($readiness['ready']); $this->assertSame('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED',$readiness['reason']);
            $this->assertSame(0,$readiness['selected_screening_unexpected_gaps']); $this->assertSame(1,$readiness['full_source_unexpected_gaps']);
            $training->query($dataset,'dukascopy','XAUUSD','M5')->where('time','2025-12-17 23:00:00')->update(['close'=>4338.649]);
            $this->assertNull($method->invoke($service,$archive));
            File::put($pricePath,$priceCsv.'\n'); $this->assertNull($method->invoke($service,$archive));
        } finally { File::deleteDirectory($sourceDir); File::deleteDirectory($priceDir); }
    }

    public function test_new_dataset_label_without_native_receipt_cannot_reopen_readiness(): void
    {
        config()->set('services.xauusd_organism.research_m5_dataset','foundation_intraday_gapfix_unverified');
        $result=app(MultiTimeframeSnapshotService::class)->agentValidationReadiness('XAUUSD');
        $this->assertFalse($result['ready']); $this->assertSame('PROSPECTIVE_M5_REPAIR_PROVENANCE_INVALID',$result['reason']);
    }

    public function test_offline_collection_does_not_inherit_hourly_live_chunking_or_unbounded_transport(): void
    {
        config()->set('services.dukascopy.live_chunk_hours', 1);
        config()->set('services.dukascopy.timeout_seconds', 180);
        $providerBefore = config('services.market_data.canonical_provider');
        (new \ReflectionMethod(\FrozenM5GapRecoveryOperation::class, 'configureOfflineTransport'))->invoke(null);
        $this->assertSame(0, config('services.dukascopy.live_chunk_hours'));
        $this->assertSame(20, config('services.dukascopy.timeout_seconds'));
        $this->assertSame(5, config('services.dukascopy.http_timeout_seconds'));
        $this->assertSame(1, config('services.dukascopy.http_retry_attempts'));
        $this->assertSame($providerBefore, config('services.market_data.canonical_provider'));
    }

    public function batchProofFixture(): array
    {
        [$m1,$m5,$legacy] = $this->proof(); $minutes=[];
        foreach ($m1 as $row) {
            $minute=str_replace(' ','T',$row['time']); $key=$minute.'.000Z';
            $minutes[$key]=['count'=>$legacy['minute_counts'][substr($minute,0,16)],
                ...array_intersect_key($row,array_flip(['open','high','low','close'])),
                'first_tick_utc'=>substr($minute,0,17).'01.000Z','last_tick_utc'=>substr($minute,0,17).'59.000Z'];
        }
        $tick=['protocol'=>'actual_provider_tick_hour_v1','hour'=>'2025-12-17T23:00:00.000Z',
            'source_hour_sha256'=>$legacy['source_hour_sha256'],'whole_hour_count'=>4572,
            'buckets'=>['2025-12-17T23:00:00.000Z'=>['count'=>137,...$legacy['first_bucket_ohlc'],
                'first_tick_utc'=>$legacy['first_tick_utc'],'last_tick_utc'=>$legacy['last_tick_utc']]],'minutes'=>$minutes];
        return [$m1,$m5,$tick];
    }

    public function test_batch_recovery_revalidates_each_actual_bucket_and_preserves_all_untouched_rows(): void
    {
        [$m1,$m5,$tick]=$this->batchProofFixture();
        $proof=\FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00',$m1,$m5,$tick);
        $source=[$this->row('2025-12-17 21:55:00'),$this->row('2025-12-17 23:05:00')];
        $fork=\FrozenM5GapRecoveryOperation::forkMany($source,[$proof],['2025-12-17T23:00:00+00:00']);
        $this->assertCount(3,$fork); $this->assertSame($source[0],$fork[0]); $this->assertSame($source[1],$fork[2]);
        $this->assertSame(4,$proof['observed_m1_minutes']); $this->assertSame(137,$proof['actual_tick_count']);
        $this->assertFalse($proof['complete_minute_coverage']); $this->assertFalse($proof['unobserved_minute_filled']);
        $proof['actual_tick_count']=138;
        $this->expectExceptionMessage('RECOVERY_BATCH_PROOF_INVALID');
        \FrozenM5GapRecoveryOperation::forkMany($source,[$proof],['2025-12-17T23:00:00+00:00']);
    }

    public function test_batch_zero_ticks_or_tick_minute_mismatch_cannot_create_a_bar(): void
    {
        [$m1,$m5,$tick]=$this->batchProofFixture(); $tick['buckets']=[];
        try { \FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00',$m1,$m5,$tick); $this->fail('No ticks accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('RECOVERY_BATCH_NO_OBSERVED_TICKS',$e->getMessage()); }
        [$m1,$m5,$tick]=$this->batchProofFixture(); $tick['minutes']['2025-12-17T23:01:00.000Z']['close']+=1;
        $this->expectExceptionMessage('RECOVERY_PROVIDER_TICKS_DISAGREE:close');
        \FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00',$m1,$m5,$tick);
    }

    public function test_batch_duplicate_out_of_inventory_future_or_unbounded_targets_are_refused(): void
    {
        [$m1,$m5,$tick]=$this->batchProofFixture(); $proof=\FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00',$m1,$m5,$tick);
        $source=[$this->row('2025-12-17 21:55:00'),$this->row('2025-12-17 23:05:00')];
        foreach ([[[$proof,$proof],['2025-12-17T23:00:00+00:00']], [[$proof],['2025-12-17T23:01:00+00:00']],
            [array_fill(0,301,$proof),['2025-12-17T23:00:00+00:00']]] as [$proofs,$inventory]) {
            try { \FrozenM5GapRecoveryOperation::forkMany($source,$proofs,$inventory); $this->fail('Unsafe batch accepted'); }
            catch (\RuntimeException $e) { $this->assertStringStartsWith('RECOVERY_BATCH_',$e->getMessage()); }
        }
        $this->expectExceptionMessage('RECOVERY_BATCH_TARGET_INVALID');
        \FrozenM5GapRecoveryOperation::batchProof('2026-01-01 00:00:00',$m1,$m5,$tick);
    }

    public function test_native_v2_readiness_binds_proof_source_bytes_sql_and_partial_calendar_scope(): void
    {
        [$m1,$m5,$ticks]=$this->batchProofFixture(); $proof=\FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00',$m1,$m5,$ticks);
        $source=[]; $start=\Carbon\CarbonImmutable::parse('2025-12-01 14:00:00','UTC');
        for ($index=0;$index<6001;$index++) {
            $time=$start->addMinutes($index*5)->format('Y-m-d H:i:s');
            if (! in_array($time,['2025-12-01 14:05:00','2025-12-17 23:00:00'],true)) $source[]=$this->row($time);
        }
        $missing=['2025-12-01T14:05:00+00:00','2025-12-17T23:00:00+00:00']; $recovered=['2025-12-17T23:00:00+00:00'];
        $remaining=['2025-12-01T14:05:00+00:00']; $rows=\FrozenM5GapRecoveryOperation::forkMany($source,[$proof],$missing);
        $sourceDir=storage_path('app/lab-datasets/mtf/test-gaprepair-v2-'.bin2hex(random_bytes(5))); File::ensureDirectoryExists($sourceDir);
        $sourcePath=$sourceDir.'/m5.csv'; File::put($sourcePath,\FrozenM5GapRecoveryOperation::csvBytes($source)); $priceCsv=\FrozenM5GapRecoveryOperation::csvBytes($rows);
        $identity=['protocol'=>'frozen_m5_gap_recovery_v2','symbol'=>'XAUUSD','timeframe'=>'M5','provider'=>'dukascopy','source_csv_path'=>realpath($sourcePath),
            'source_csv_sha256'=>hash_file('sha256',$sourcePath),'source_rows'=>count($source),'new_rows'=>count($rows),'source_first_at'=>$source[0]['time'],'source_last_at'=>$source[array_key_last($source)]['time'],
            'new_price_csv_sha256'=>hash('sha256',$priceCsv),'economic_row_hash_protocol'=>'training_decimal_6_rows_v1',
            'new_economic_rows_sha256'=>\FrozenM5GapRecoveryOperation::economicRowsHash($rows),'target_proofs'=>[$proof], 'canonical_missing_utc'=>$missing,
            'unresolved_targets'=>[['target_utc'=>$remaining[0],'reason'=>'RECOVERY_BATCH_NO_OBSERVED_TICKS']],
            'provider_days_requested'=>2,'tick_hours_requested'=>2,'collection_ceiling_seconds'=>1800,
            'calendar_scope'=>['protocol'=>'frozen_source_calendar_scope_audit_v2','source_csv_sha256'=>hash_file('sha256',$sourcePath),'source_rows'=>count($source),'new_rows'=>count($rows),
                'canonical_missing_utc'=>$missing,'recovered_targets_utc'=>$recovered,'remaining_missing_utc'=>$remaining,
                'full_source_unexpected_before'=>2,'full_source_unexpected_after'=>1,'whole_archive_continuity_proven'=>false,'screening_unexpected_after'=>0],
            'independent_evidence'=>false,'runtime_trade_authority'=>false,'promotion_evidence'=>false,'quote_liquidity_inherited'=>false];
        $hash=app(ExecutionContractService::class)->hashParameters($identity); $dataset='foundation_intraday_gapfix_'.substr($hash,0,16);
        $priceDir=storage_path('app/lab-datasets/training/recovery/'.$hash); File::ensureDirectoryExists($priceDir); $pricePath=$priceDir.'/m5.csv'; File::put($pricePath,$priceCsv);
        try {
            $receipt=[...$identity,'repair_hash'=>$hash,'dataset_key'=>$dataset]; $training=app(MarketTrainingDataService::class);
            $archive=$training->ensureArchive($dataset,'dukascopy','XAUUSD','M5',\Carbon\CarbonImmutable::parse($source[0]['time'],'UTC'),\Carbon\CarbonImmutable::parse($source[array_key_last($source)]['time'],'UTC')->addMinutes(5));
            $training->upsertCandles($dataset,'dukascopy','XAUUSD','M5',$rows); $training->refreshCoverage($archive);
            $archive->update(['status'=>'complete','metrics'=>['frozen_m5_gap_recovery_receipt'=>$receipt,'frozen_m5_gap_recovery_price_path'=>$pricePath]]);
            $service=app(MultiTimeframeSnapshotService::class); $method=new \ReflectionMethod($service,'verifiedProspectiveM5Repair');
            $verified=$method->invoke($service,$archive); $this->assertTrue($verified['verified']); $this->assertSame('frozen_m5_gap_recovery_v2',$verified['protocol']);
            $this->assertSame($identity['source_csv_sha256'],$verified['original_bad_m5_sha256']); $this->assertFalse($verified['quote_liquidity_inherited']);
            config()->set('services.xauusd_organism.research_m5_dataset',$dataset);
            $readiness=$service->agentValidationReadiness('XAUUSD');
            $this->assertFalse($readiness['ready']); $this->assertSame('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED',$readiness['reason']);
            $this->assertSame(0,$readiness['selected_screening_unexpected_gaps']); $this->assertSame(1,$readiness['full_source_unexpected_gaps']);
            // Byte/hash drift is never made valid by copying the old dataset label.
            File::put($sourcePath,\FrozenM5GapRecoveryOperation::csvBytes([$this->row('2025-12-17 21:50:00'),$source[1]]));
            $this->assertNull($method->invoke($service,$archive));
            File::put($sourcePath,\FrozenM5GapRecoveryOperation::csvBytes($source));
            $tampered=$receipt; $tampered['target_proofs'][0]['native_m5_row']['close']+=1;
            $archive->update(['metrics'=>['frozen_m5_gap_recovery_receipt'=>$tampered,'frozen_m5_gap_recovery_price_path'=>$pricePath]]);
            $this->assertNull($method->invoke($service,$archive));
            $archive->update(['metrics'=>['frozen_m5_gap_recovery_receipt'=>$receipt,'frozen_m5_gap_recovery_price_path'=>$pricePath]]);
            $training->query($dataset,'dukascopy','XAUUSD','M5')->where('time','2025-12-17 23:00:00')->update(['close'=>4338.649]);
            $this->assertNull($method->invoke($service,$archive));
        } finally { File::deleteDirectory($sourceDir); File::deleteDirectory($priceDir); }
    }

    private function row(string $time): array
    {
        return ['time'=>$time,'open'=>'4338.748000','high'=>'4339.148000','low'=>'4336.898000','close'=>'4337.198000','volume'=>'0.034080'];
    }
}
