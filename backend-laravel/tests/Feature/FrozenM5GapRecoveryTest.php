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
    private array $rawEvidencePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $operation = base_path('scripts/recover-frozen-m5-gap.php');
        if (! is_file($operation)) $operation = dirname(base_path()).'/.runtime/frozen-m5-gap-repair-staging-2026-10-03/recover-frozen-m5-gap.php';
        if (! class_exists(\FrozenM5GapRecoveryOperation::class, false)) require_once $operation;
    }

    protected function tearDown(): void
    {
        try { foreach ($this->rawEvidencePaths as $path) if (is_file($path)) unlink($path); }
        finally { parent::tearDown(); }
    }

    public function rawTickProofFixture(string $target = '2025-12-17 23:00:00'): array
    {
        $at=\Carbon\CarbonImmutable::parse($target,'UTC'); $hour=$at->startOfHour();
        $raw=['timestamp'=>$hour->getTimestampMs(),'multiplier'=>0.001,'ask'=>4001.0,'bid'=>4000.0,
            'times'=>[],'asks'=>[],'bids'=>[],'askVolumes'=>[],'bidVolumes'=>[]];
        $m1=[];
        for ($minute=0;$minute<5;$minute++) {
            $raw['times'][]=$minute===0 ? ($at->getTimestampMs()-$hour->getTimestampMs()+100) : 1100;
            $raw['times'][]=58900;
            $raw['asks'][]=$minute===0?0:500; $raw['asks'][]=500;
            $raw['bids'][]=$minute===0?0:500; $raw['bids'][]=500;
            $raw['askVolumes'][]=150.0; $raw['askVolumes'][]=150.0;
            $raw['bidVolumes'][]=120.0; $raw['bidVolumes'][]=120.0;
            if (in_array($minute,[0,2,3],true)) $m1[]=['time'=>$at->addMinutes($minute)->format('Y-m-d H:i:s'),
                'open'=>4000.0+$minute,'high'=>4000.5+$minute,'low'=>4000.0+$minute,'close'=>4000.5+$minute,'volume'=>0.00024];
        }
        $m5=[['time'=>$target,'open'=>4000.0,'high'=>4003.5,'low'=>4000.0,'close'=>4003.5,'volume'=>0.00072]];
        $path=tempnam(sys_get_temp_dir(),'m5-raw-native-'); $this->rawEvidencePaths[]=$path;
        file_put_contents($path,json_encode($raw,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $evidence=['protocol'=>\FrozenM5GapRecoveryOperation::RAW_EVIDENCE_PROTOCOL,'hour'=>$hour->format('Y-m-d\TH:00:00.000\Z'),
            'source_url'=>'https://jetta.dukascopy.com/v1/ticks/XAU-USD/'.$hour->format('Y/n/j/G'),
            'raw_path'=>realpath($path),'raw_sha256'=>hash_file('sha256',$path)];
        return [$m1,$m5,$evidence];
    }

    public function rawSparseTickProofFixture(string $target = '2025-12-01 07:50:00'): array
    {
        $at=\Carbon\CarbonImmutable::parse($target,'UTC'); $hour=$at->startOfHour(); $offset=$at->getTimestampMs()-$hour->getTimestampMs();
        $bytes=pack('NNNGG',$offset+100,4001000,4000000,0.00015,0.00012).pack('NNNGG',$offset+59000,4001500,4000500,0.00015,0.00012);
        $process=new \Symfony\Component\Process\Process(['python','-c','import base64,lzma,sys;sys.stdout.buffer.write(lzma.compress(base64.b64decode(sys.argv[1]),format=lzma.FORMAT_ALONE))',base64_encode($bytes)]);
        $process->setTimeout(10); $process->mustRun();
        $path=tempnam(sys_get_temp_dir(),'m5-raw-sparse-'); $this->rawEvidencePaths[]=$path; file_put_contents($path,$process->getOutput());
        $m1=[['time'=>$target,'open'=>4000.0,'high'=>4000.5,'low'=>4000.0,'close'=>4000.5,'volume'=>0.00024]];
        $evidence=['protocol'=>\FrozenM5GapRecoveryOperation::BI5_EVIDENCE_PROTOCOL,'hour'=>$hour->format('Y-m-d\TH:00:00.000\Z'),
            'source_url'=>'https://www.dukascopy.com/datafeed/XAUUSD/'.$hour->format('Y').'/'.sprintf('%02d',(int)$hour->format('n')-1).'/'.$hour->format('d/H').'h_ticks.bi5',
            'raw_path'=>realpath($path),'raw_sha256'=>hash_file('sha256',$path)];
        return [$m1,$m1,$evidence];
    }

    public function test_raw_sparse_bi5_recovery_requires_matching_actual_native_minutes_and_preserves_unobserved_clock_minutes(): void
    {
        [$m1,$m5,$evidence]=$this->rawSparseTickProofFixture();
        $proof=\FrozenM5GapRecoveryOperation::rawTickProof('2025-12-01 07:50:00',$m1,$m5,$evidence);
        $this->assertSame(1,$proof['observed_m1_minutes']); $this->assertSame(1,$proof['tick_observed_clock_minutes']);
        $this->assertSame(2,$proof['actual_tick_count']); $this->assertCount(1,$proof['tick_minutes']);
        $this->assertFalse($proof['complete_minute_coverage']); $this->assertFalse($proof['complete_tick_history_proven']);
        $this->assertFalse($proof['unobserved_minute_filled']);
        $this->assertSame('local_aggregate_of_actual_native_m1_rows',$proof['native_m5_origin']);
        $this->assertSame('observed_native_m1_sparse_aggregate_validated',$proof['native_m5_role']);
        $this->assertEqualsWithDelta(0.00024,$proof['recovered_row']['volume'],1e-9);
        $source=[$this->row('2025-12-01 07:45:00'),$this->row('2025-12-01 07:55:00')];
        $fork=\FrozenM5GapRecoveryOperation::forkMany($source,[$proof],['2025-12-01T07:50:00+00:00']);
        $this->assertCount(3,$fork); $this->assertSame($source[0],$fork[0]); $this->assertSame($source[1],$fork[2]);
        $this->assertSame($proof['recovered_row'],$fork[1]);
    }

    public function test_raw_sparse_bi5_recovery_refuses_absent_extra_or_conflicting_native_minute_evidence(): void
    {
        foreach (['missing_m1','extra_m1','conflicting_price','missing_m5','conflicting_m5'] as $case) {
            [$m1,$m5,$evidence]=$this->rawSparseTickProofFixture();
            if ($case==='missing_m1') $m1=[];
            if ($case==='extra_m1') $m1[]=[...$m1[0],'time'=>'2025-12-01 07:51:00'];
            if ($case==='conflicting_price') $m1[0]['high']+=0.01;
            if ($case==='missing_m5') $m5=[];
            if ($case==='conflicting_m5') $m5[0]['high']+=0.01;
            try { \FrozenM5GapRecoveryOperation::rawTickProof('2025-12-01 07:50:00',$m1,$m5,$evidence); $this->fail('Invalid sparse '.$case.' accepted'); }
            catch (\RuntimeException $error) {
                $expected=match($case) {
                    'missing_m1','extra_m1'=>'RECOVERY_RAW_PARTIAL_MINUTE_MEMBERSHIP_MISMATCH',
                    'conflicting_price'=>'RECOVERY_RAW_NATIVE_M1_TICKS_DISAGREE:high',
                    'missing_m5'=>'RECOVERY_RAW_PARTIAL_NATIVE_BUCKET_REQUIRED',
                    'conflicting_m5'=>'RECOVERY_PROVIDER_M1_M5_DISAGREE:high',
                };
                $this->assertSame($expected,$error->getMessage());
            }
        }
    }

    public function test_raw_tick_recovery_uses_omitted_actual_minutes_and_treats_sparse_native_m5_as_diagnostic(): void
    {
        [$m1,$m5,$evidence]=$this->rawTickProofFixture();
        $proof=\FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',$m1,$m5,$evidence);
        $this->assertSame(\FrozenM5GapRecoveryOperation::RAW_TICK_PROTOCOL,$proof['protocol']);
        $this->assertSame(3,$proof['observed_m1_minutes']); $this->assertSame(10,$proof['actual_tick_count']);
        $this->assertSame(4004.5,$proof['recovered_row']['close']); $this->assertEqualsWithDelta(0.0012,$proof['recovered_row']['volume'],1e-12);
        $this->assertSame(4003.5,$proof['native_m5_row']['close']); $this->assertTrue($proof['complete_minute_coverage']);
        $this->assertFalse($proof['native_candle_complete_minute_coverage']); $this->assertFalse($proof['complete_tick_history_proven']);
        $this->assertFalse($proof['unobserved_minute_filled']); $this->assertSame(5,$proof['tick_observed_clock_minutes']);
        $source=[$this->row('2025-12-17 22:55:00'),$this->row('2025-12-17 23:05:00')];
        $rows=\FrozenM5GapRecoveryOperation::forkMany($source,[$proof],['2025-12-17T23:00:00+00:00']);
        $this->assertSame($source[0],$rows[0]); $this->assertSame($source[1],$rows[2]);
        $this->assertSame($proof['recovered_row'],$rows[1]);
        $proof['raw_tick_decoder_spec_sha256']=str_repeat('a',64);
        $this->expectExceptionMessage('RECOVERY_BATCH_PROOF_INVALID');
        \FrozenM5GapRecoveryOperation::forkMany($source,[$proof],['2025-12-17T23:00:00+00:00']);
    }

    public function test_raw_tick_recovery_refuses_native_volume_disagreement_and_missing_minute_attestation(): void
    {
        [$m1,$m5,$evidence]=$this->rawTickProofFixture(); $m1[0]['volume']+=0.0001;
        try { \FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',$m1,$m5,$evidence); $this->fail('Wrong volume accepted'); }
        catch (\RuntimeException $error) { $this->assertSame('RECOVERY_RAW_NATIVE_M1_TICKS_DISAGREE:volume',$error->getMessage()); }
        [$m1,$m5,$evidence]=$this->rawTickProofFixture();
        $raw=json_decode(file_get_contents($evidence['raw_path']),true,flags:JSON_THROW_ON_ERROR);
        foreach (['times','asks','bids','askVolumes','bidVolumes'] as $field) $raw[$field]=array_slice($raw[$field],0,8);
        file_put_contents($evidence['raw_path'],json_encode($raw,JSON_PRESERVE_ZERO_FRACTION)); $evidence['raw_sha256']=hash_file('sha256',$evidence['raw_path']);
        $this->expectExceptionMessage('RECOVERY_RAW_TICK_ALL_FIVE_MINUTES_REQUIRED');
        \FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',$m1,$m5,$evidence);
    }

    public function test_hash_bound_raw_tick_recovery_rejects_malformed_columns_crossed_quotes_and_source_drift(): void
    {
        foreach (['length','crossed','timestamp','unknown_column','hash'] as $case) {
            [$m1,$m5,$evidence]=$this->rawTickProofFixture(); $raw=json_decode(file_get_contents($evidence['raw_path']),true,flags:JSON_THROW_ON_ERROR);
            if ($case==='length') array_pop($raw['asks']);
            if ($case==='crossed') $raw['ask']=3999.0;
            if ($case==='timestamp') $raw['times'][0]=-1;
            if ($case==='unknown_column') $raw['other_instrument']='EURUSD';
            if ($case==='hash') $raw['bidVolumes'][0]=999.0;
            file_put_contents($evidence['raw_path'],json_encode($raw,JSON_PRESERVE_ZERO_FRACTION));
            if ($case!=='hash') $evidence['raw_sha256']=hash_file('sha256',$evidence['raw_path']);
            try { \FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',$m1,$m5,$evidence); $this->fail('Malformed '.$case.' accepted'); }
            catch (\RuntimeException $error) { $this->assertStringStartsWith('RECOVERY_RAW_TICK_',$error->getMessage()); }
        }
    }

    public function test_bi5_ticks_supply_all_actual_minutes_when_native_candle_endpoint_is_empty(): void
    {
        [$m1,$m5,$jetta]=$this->rawTickProofFixture();
        $bytes='';
        for ($minute=0;$minute<5;$minute++) foreach ([100,59000] as $index=>$offset) {
            $bid=4000000+$minute*1000+$index*500;
            $bytes.=pack('NNNGG',$minute*60000+$offset,$bid+1000,$bid,0.00015,0.00012);
        }
        $process=new \Symfony\Component\Process\Process(['python','-c','import base64,lzma,sys;sys.stdout.buffer.write(lzma.compress(base64.b64decode(sys.argv[1]),format=lzma.FORMAT_ALONE))',base64_encode($bytes)]);
        $process->setTimeout(10); $process->mustRun();
        $path=tempnam(sys_get_temp_dir(),'m5-raw-bi5-'); $this->rawEvidencePaths[]=$path; file_put_contents($path,$process->getOutput());
        $evidence=['protocol'=>\FrozenM5GapRecoveryOperation::BI5_EVIDENCE_PROTOCOL,'hour'=>$jetta['hour'],
            'source_url'=>'https://www.dukascopy.com/datafeed/XAUUSD/2025/11/17/23h_ticks.bi5',
            'raw_path'=>realpath($path),'raw_sha256'=>hash_file('sha256',$path)];
        $proof=\FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',[],[],$evidence);
        $this->assertSame(0,$proof['observed_m1_minutes']); $this->assertSame(10,$proof['actual_tick_count']);
        $this->assertSame(4004.5,$proof['recovered_row']['close']); $this->assertEqualsWithDelta(0.0012,$proof['recovered_row']['volume'],1e-9);
        $this->assertFalse($proof['complete_tick_history_proven']); $this->assertNull($proof['native_m5_row']);
        $this->assertSame(hash_file('sha256',base_path('scripts/decode-dukascopy-tick-hour.py')),$proof['raw_tick_decoder_source_sha256']);
        $this->assertSame($proof,\FrozenM5GapRecoveryOperation::revalidateProof($proof));
        // Same BI5 bytes still have to agree with every actual native M1 observation.
        $m1[0]['close']-=0.001;
        $this->expectExceptionMessage('RECOVERY_RAW_NATIVE_M1_TICKS_DISAGREE:close');
        \FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',$m1,[],$evidence);
    }

    public function test_raw_supplement_manifest_requires_source_and_byte_hashes_and_bounded_unique_targets(): void
    {
        [$m1,$m5,$evidence]=$this->rawTickProofFixture(); $sourceHash=str_repeat('c',64);
        $path=tempnam(sys_get_temp_dir(),'m5-raw-manifest-'); $this->rawEvidencePaths[]=$path;
        $entry=['target_utc'=>'2025-12-17T23:00:00+00:00','native_m1_rows'=>$m1,'native_m5_rows'=>$m5,'evidence'=>$evidence];
        $manifest=['protocol'=>\FrozenM5GapRecoveryOperation::RAW_MANIFEST_PROTOCOL,'source_csv_sha256'=>$sourceHash,'targets'=>[$entry]];
        file_put_contents($path,json_encode($manifest,JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame([$entry],\FrozenM5GapRecoveryOperation::rawTickManifest($path,hash_file('sha256',$path),$sourceHash));
        try { \FrozenM5GapRecoveryOperation::rawTickManifest($path,str_repeat('d',64),$sourceHash); $this->fail('Wrong manifest SHA accepted'); }
        catch (\RuntimeException $error) { $this->assertSame('RECOVERY_RAW_MANIFEST_SHA_INVALID',$error->getMessage()); }
        $manifest['targets'][]=$entry; file_put_contents($path,json_encode($manifest));
        $this->expectExceptionMessage('RECOVERY_RAW_MANIFEST_TARGET_INVALID');
        \FrozenM5GapRecoveryOperation::rawTickManifest($path,hash_file('sha256',$path),$sourceHash);
    }

    public function test_raw_decoder_failure_remains_a_typed_dependency_in_a_verifiable_partial_fork(): void
    {
        [$m1, $m5, $evidence] = $this->rawTickProofFixture();
        $badPath = tempnam(sys_get_temp_dir(), 'm5-invalid-bi5-');
        $this->rawEvidencePaths[] = $badPath;
        File::put($badPath, 'not an LZMA tick stream');
        $badEvidence = ['protocol' => \FrozenM5GapRecoveryOperation::BI5_EVIDENCE_PROTOCOL,
            'hour' => '2025-12-01T14:00:00.000Z',
            'source_url' => 'https://www.dukascopy.com/datafeed/XAUUSD/2025/11/01/14h_ticks.bi5',
            'raw_path' => realpath($badPath), 'raw_sha256' => hash_file('sha256', $badPath)];
        try {
            \FrozenM5GapRecoveryOperation::rawTickProof('2025-12-01 14:05:00', [], [], $badEvidence);
            $this->fail('Invalid BI5 evidence was accepted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('RECOVERY_RAW_TICK_DECODER_FAILED', $error->getMessage());
            $this->assertInstanceOf(\Symfony\Component\Process\Exception\ProcessFailedException::class, $error->getPrevious());
        }

        $source = []; $start = \Carbon\CarbonImmutable::parse('2025-12-01 14:00:00', 'UTC');
        for ($index = 0; $index < 6001; $index++) {
            $time = $start->addMinutes($index * 5)->format('Y-m-d H:i:s');
            if (! in_array($time, ['2025-12-01 14:05:00', '2025-12-17 23:00:00'], true)) $source[] = $this->row($time);
        }
        $sourceDirectory = storage_path('app/lab-datasets/mtf/test-decoder-dependency-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($sourceDirectory);
        $sourcePath = $sourceDirectory.'/m5.csv';
        File::put($sourcePath, \FrozenM5GapRecoveryOperation::csvBytes($source));
        $sourceHash = hash_file('sha256', $sourcePath); $priceDirectory = null;
        try {
            $entries = [
                ['target_utc' => '2025-12-01T14:05:00+00:00', 'native_m1_rows' => [], 'native_m5_rows' => [], 'evidence' => $badEvidence],
                ['target_utc' => '2025-12-17T23:00:00+00:00', 'native_m1_rows' => $m1, 'native_m5_rows' => $m5, 'evidence' => $evidence],
            ];
            $batch = \FrozenM5GapRecoveryOperation::collectRawTickBatch($sourcePath, $sourceHash, $source, [], $entries);
            $this->assertSame([['target_utc' => '2025-12-01T14:05:00+00:00', 'reason' => 'RECOVERY_RAW_TICK_DECODER_FAILED']], $batch['unresolved_targets']);
            $this->assertCount(1, $batch['target_proofs']);
            $this->assertSame(1, $batch['calendar_scope']['full_source_unexpected_after']);
            $this->assertSame(0, $batch['calendar_scope']['screening_unexpected_after']);
            $this->assertFalse($batch['calendar_scope']['whole_archive_continuity_proven']);
            $this->assertSame(0, $batch['provider_days_requested']);
            $this->assertSame(0, $batch['tick_hours_requested']);
            $csv = \FrozenM5GapRecoveryOperation::csvBytes($batch['rows']);
            $identity = ['protocol' => \FrozenM5GapRecoveryOperation::RAW_BATCH_PROTOCOL,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'provider' => 'dukascopy',
                'source_csv_path' => realpath($sourcePath), 'source_csv_sha256' => $sourceHash,
                'source_rows' => count($source), 'new_rows' => count($batch['rows']),
                'source_first_at' => $source[0]['time'], 'source_last_at' => $source[array_key_last($source)]['time'],
                'new_price_csv_sha256' => hash('sha256', $csv), 'economic_row_hash_protocol' => 'training_decimal_6_rows_v1',
                'new_economic_rows_sha256' => \FrozenM5GapRecoveryOperation::economicRowsHash($batch['rows']),
                'target_proofs' => $batch['target_proofs'], 'canonical_missing_utc' => $batch['canonical_missing_utc'],
                'unresolved_targets' => $batch['unresolved_targets'], 'calendar_scope' => $batch['calendar_scope'],
                'provider_days_requested' => 0, 'tick_hours_requested' => 0, 'collection_ceiling_seconds' => 1800,
                'independent_evidence' => false, 'runtime_trade_authority' => false,
                'promotion_evidence' => false, 'quote_liquidity_inherited' => false];
            $hash = app(ExecutionContractService::class)->hashParameters($identity);
            $dataset = 'foundation_intraday_gapfix_'.substr($hash, 0, 16);
            $priceDirectory = storage_path('app/lab-datasets/training/recovery/'.$hash);
            File::ensureDirectoryExists($priceDirectory); $pricePath = $priceDirectory.'/m5.csv'; File::put($pricePath, $csv);
            $training = app(MarketTrainingDataService::class);
            $archive = $training->ensureArchive($dataset, 'dukascopy', 'XAUUSD', 'M5', $start,
                \Carbon\CarbonImmutable::parse($source[array_key_last($source)]['time'], 'UTC')->addMinutes(5));
            $training->upsertCandles($dataset, 'dukascopy', 'XAUUSD', 'M5', $batch['rows']); $training->refreshCoverage($archive);
            $archive->update(['status' => 'complete', 'metrics' => [
                'frozen_m5_gap_recovery_receipt' => [...$identity, 'repair_hash' => $hash, 'dataset_key' => $dataset],
                'frozen_m5_gap_recovery_price_path' => $pricePath]]);
            $owner = app(MultiTimeframeSnapshotService::class);
            $verified = $owner->verifiedNativeM5Repair($dataset);
            $this->assertNotNull($verified, 'A decoder dependency hid the actually verified recovered bucket.');
            $this->assertSame(\FrozenM5GapRecoveryOperation::RAW_BATCH_PROTOCOL, $verified['protocol']);
            $this->assertFalse($verified['independent_evidence']);
            $this->assertFalse($verified['calendar_scope']['whole_archive_continuity_proven']);
            config()->set('services.xauusd_organism.research_m5_dataset', $dataset);
            $this->assertSame('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED', $owner->agentValidationReadiness('XAUUSD')['reason']);
            $this->assertSame($sourceHash, hash_file('sha256', $sourcePath));
        } finally {
            if ($priceDirectory !== null) File::deleteDirectory($priceDirectory);
            File::deleteDirectory($sourceDirectory);
        }
    }

    public function test_v3_raw_resume_reopens_exact_evidence_and_v2_cannot_accept_relabelled_raw_proof(): void
    {
        [$m1,$m5,$evidence]=$this->rawTickProofFixture();
        $proof=\FrozenM5GapRecoveryOperation::rawTickProof('2025-12-17 23:00:00',$m1,$m5,$evidence);
        $source=[$this->row('2025-12-17 22:55:00'),$this->row('2025-12-17 23:05:00')]; $inventory=['2025-12-17T23:00:00+00:00'];
        $rows=\FrozenM5GapRecoveryOperation::forkMany($source,[$proof],$inventory);
        $sourcePath=tempnam(sys_get_temp_dir(),'m5-raw-source-'); $pricePath=tempnam(sys_get_temp_dir(),'m5-raw-price-');
        $this->rawEvidencePaths[]=$sourcePath; $this->rawEvidencePaths[]=$pricePath;
        file_put_contents($sourcePath,\FrozenM5GapRecoveryOperation::csvBytes($source)); file_put_contents($pricePath,\FrozenM5GapRecoveryOperation::csvBytes($rows));
        $sourceSha=hash_file('sha256',$sourcePath);
        $identity=['protocol'=>\FrozenM5GapRecoveryOperation::RAW_BATCH_PROTOCOL,'source_csv_path'=>realpath($sourcePath),'source_csv_sha256'=>$sourceSha,
            'source_rows'=>count($source),'new_rows'=>count($rows),'target_proofs'=>[$proof],'canonical_missing_utc'=>$inventory,
            'new_price_csv_sha256'=>hash_file('sha256',$pricePath),'new_economic_rows_sha256'=>\FrozenM5GapRecoveryOperation::economicRowsHash($rows),
            'independent_evidence'=>false,'promotion_evidence'=>false,'quote_liquidity_inherited'=>false];
        $seal=static function(array $identity):array {
            $hash=app(ExecutionContractService::class)->hashParameters($identity);
            return [...$identity,'repair_hash'=>$hash,'dataset_key'=>'foundation_intraday_gapfix_'.substr($hash,0,16)];
        };
        $receipt=$seal($identity);
        $this->assertSame([$proof],\FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,$sourceSha,$source,$receipt,$pricePath));
        $legacy=$seal([...$identity,'protocol'=>\FrozenM5GapRecoveryOperation::BATCH_PROTOCOL]);
        try { \FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,$sourceSha,$source,$legacy,$pricePath); $this->fail('Legacy relabel accepted'); }
        catch (\RuntimeException $error) { $this->assertSame('RECOVERY_RESUME_LEGACY_PROOF_PROTOCOL_INVALID',$error->getMessage()); }
        file_put_contents($evidence['raw_path'],file_get_contents($evidence['raw_path'])."\n");
        $this->expectExceptionMessage('RECOVERY_RAW_TICK_SOURCE_INVALID');
        \FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,$sourceSha,$source,$receipt,$pricePath);
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

    public function test_bounded_resume_accepts_only_exact_original_successful_proofs_and_unchanged_fork_bytes(): void
    {
        [$m1,$m5,$tick]=$this->batchProofFixture();
        $proof=\FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00',$m1,$m5,$tick);
        $source=[$this->row('2025-12-17 21:55:00'),$this->row('2025-12-17 23:05:00')];
        $inventory=['2025-12-17T23:00:00+00:00'];
        $rows=\FrozenM5GapRecoveryOperation::forkMany($source,[$proof],$inventory);
        $sourcePath=tempnam(sys_get_temp_dir(),'m5-resume-source-');
        $pricePath=tempnam(sys_get_temp_dir(),'m5-resume-price-');
        try {
            File::put($sourcePath,\FrozenM5GapRecoveryOperation::csvBytes($source));
            File::put($pricePath,\FrozenM5GapRecoveryOperation::csvBytes($rows));
            $sourceSha=hash_file('sha256',$sourcePath);
            $identity=['protocol'=>\FrozenM5GapRecoveryOperation::BATCH_PROTOCOL,
                'source_csv_path'=>realpath($sourcePath),'source_csv_sha256'=>$sourceSha,
                'source_rows'=>count($source),'new_rows'=>count($rows),'target_proofs'=>[$proof],
                'canonical_missing_utc'=>$inventory,'new_price_csv_sha256'=>hash_file('sha256',$pricePath),
                'new_economic_rows_sha256'=>\FrozenM5GapRecoveryOperation::economicRowsHash($rows),
                'independent_evidence'=>false,'promotion_evidence'=>false,'quote_liquidity_inherited'=>false];
            $hash=app(ExecutionContractService::class)->hashParameters($identity);
            $receipt=[...$identity,'repair_hash'=>$hash,'dataset_key'=>'foundation_intraday_gapfix_'.substr($hash,0,16)];
            $this->assertSame([$proof],\FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,$sourceSha,$source,$receipt,$pricePath));
            $forged=$receipt; $forged['target_proofs'][0]['actual_tick_count']++;
            try { \FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,$sourceSha,$source,$forged,$pricePath); $this->fail('Tampered resume accepted'); }
            catch (\RuntimeException $error) { $this->assertSame('RECOVERY_RESUME_RECEIPT_INVALID',$error->getMessage()); }
            try { \FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,str_repeat('b',64),$source,$receipt,$pricePath); $this->fail('Different original accepted'); }
            catch (\RuntimeException $error) { $this->assertSame('RECOVERY_RESUME_RECEIPT_INVALID',$error->getMessage()); }
            File::put($pricePath,\FrozenM5GapRecoveryOperation::csvBytes($rows)."\n");
            $this->expectExceptionMessage('RECOVERY_RESUME_PROOF_OR_BYTES_INVALID');
            \FrozenM5GapRecoveryOperation::resumeProofs($sourcePath,$sourceSha,$source,$receipt,$pricePath);
        } finally { File::delete([$sourcePath,$pricePath]); }
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
