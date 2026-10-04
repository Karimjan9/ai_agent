<?php

namespace Tests\Feature;

use App\Models\MarketTrainingArchive;
use App\Models\MarketTrainingCandle;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\MarketData\SecondaryM5ResearchRecoveryService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ProspectiveRepairProbeWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SecondaryM5ResearchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) if (is_dir($directory)) File::deleteDirectory($directory);
        parent::tearDown();
    }

    public function test_actual_secondary_observations_close_only_the_separate_archive_and_preserve_typed_research_budget(): void
    {
        [$fixture, $evidence] = $this->fixture(5, false);
        $owner = app(MultiTimeframeSnapshotService::class);
        $service = app(SecondaryM5ResearchRecoveryService::class);
        $parent = $owner->verifiedNativeM5Repair($fixture['dataset']);
        $before = hash_file('sha256', $parent['prospective_m5_source_path']);
        $result = $service->build($fixture['dataset'], [$evidence], $fixture['bundle']['bundle_hash'], true);
        $this->directories[] = dirname($result['price_path']);
        $receipt = $result['receipt'];
        $this->assertSame(0, $receipt['calendar_scope']['full_source_unexpected_after']);
        $this->assertSame(5, $receipt['target_proofs'][0]['observed_m1_minutes']);
        $this->assertTrue($receipt['target_proofs'][0]['complete_observed_m1_membership']);
        $this->assertFalse($receipt['native_equivalence_proven']);
        $this->assertSame($before, hash_file('sha256', $parent['prospective_m5_source_path']));
        $archive = MarketTrainingArchive::where('dataset_key', $receipt['dataset_key'])->sole();
        $this->assertSame('mixed', $archive->provider);
        $this->assertNotNull($service->verify($archive));
        config()->set('services.xauusd_organism.research_m5_dataset', $receipt['dataset_key']);
        $this->assertSame('PROSPECTIVE_M5_REPAIR_PROVENANCE_INVALID', $owner->agentValidationReadiness('XAUUSD')['reason']);
        $bundle = $owner->forProspectiveCleanDiscovery('XAUUSD', $receipt['dataset_key']);
        $this->directories[] = dirname($bundle['manifest_path']);
        $this->assertSame('mixed', $bundle['manifest']['provider']);
        $this->assertSame(15512, $bundle['manifest']['streams']['M5']['row_count']);
        $this->assertFalse($bundle['manifest']['full_validation_eligible']);
        $this->assertFalse($bundle['manifest']['independent_evidence']);
        $this->assertSame(0, $bundle['manifest']['volume_provenance']['streams']['M5']['available_rows']);
        $this->assertSame(0, $bundle['manifest']['quote_spread_provenance']['available_rows']);
        $this->assertTrue($owner->discoveryBundleReadiness($bundle['manifest'])['allowed']);
        $header = str_getcsv(strtok(File::get($bundle['entry_dataset_path']), "\n"));
        $this->assertContains('source_provider', $header);
        $this->assertContains('price_basis', $header);
        $this->assertContains('source_response_sha256', $header);
        $this->assertNotContains('spread_available', $header);
        try { $owner->restoreAgentOwnedConfirmationValidationBundle($bundle['manifest']); $this->fail('Mixed discovery entered full validation.'); }
        catch (RuntimeException $error) { $this->assertSame('DISCOVERY_BUNDLE_CANNOT_SATISFY_FULL_VALIDATION', $error->getMessage()); }
        $method = new \ReflectionMethod(AcademyExperimentMaterializerService::class, 'dependencyBudgetScope');
        $materializer = app(AcademyExperimentMaterializerService::class);
        $base = ['foundation_sha256' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64)];
        $old = [...$base, 'discovery_scope' => $fixture['bundle']['manifest']['discovery_scope']];
        $new = [...$base, 'discovery_scope' => $bundle['manifest']['discovery_scope'], 'discovery_bundle_manifest' => $bundle['manifest']];
        $this->assertSame($method->invoke($materializer, 'XAUUSD', 'H1', $old), $method->invoke($materializer, 'XAUUSD', 'H1', $new));
        $bad = $new; unset($bad['discovery_bundle_manifest']);
        try { $method->invoke($materializer, 'XAUUSD', 'H1', $bad); $this->fail('An unsealed budget alias was accepted.'); }
        catch (RuntimeException $error) { $this->assertSame('ACADEMY_SECONDARY_DISCOVERY_BUDGET_ANCHOR_INVALID', $error->getMessage()); }
        $this->assertDatabaseCount('edge_academy_trials', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertPythonSourceAndPricePreparation($bundle);

        $forged = $archive->metrics; $forged['secondary_m5_research_receipt']['provider'] = 'dukascopy';
        $archive->metrics = $forged;
        $this->assertNull($service->verify($archive));
        $archive->refresh();
        $row = MarketTrainingCandle::where('dataset_key', $receipt['dataset_key'])->orderBy('time')->firstOrFail();
        $originalClose = $row->close;
        $row->update(['close' => 1099]);
        $this->assertNull($service->verify($archive), 'A replaced native economic row was accepted in the mixed SQL archive.');
        $row->update(['close' => $originalClose]);
        File::append($result['price_path'], "\n");
        $this->assertNull($service->verify($archive), 'Changed derivative CSV bytes were accepted.');
    }

    public function test_partial_minutes_require_the_actual_matching_provider_m5_and_never_become_complete_minutes(): void
    {
        [$fixture, $evidence, $response] = $this->fixture(2, true);
        $service = app(SecondaryM5ResearchRecoveryService::class);
        $result = $service->build($fixture['dataset'], [$evidence], $fixture['bundle']['bundle_hash']);
        $proof = $result['receipt']['target_proofs'][0];
        $this->assertSame(2, $proof['observed_m1_minutes']);
        $this->assertFalse($proof['complete_observed_m1_membership']);
        $this->assertFalse($proof['missing_m1_filled']);
        $this->assertTrue($proof['actual_provider_m5_present']);
        $this->assertFalse($result['applied']);
        $this->assertDatabaseMissing('market_training_archives', ['provider' => 'mixed']);
        $partial = json_decode(File::get($evidence['path']), true);
        $partial['results'] = [$partial['results'][0]];
        $partialPath = dirname($evidence['path']).'/partial-without-m5.json'; File::put($partialPath, json_encode($partial));
        try {
            $service->build($fixture['dataset'], [['path' => $partialPath, 'sha256' => hash_file('sha256', $partialPath)]], $fixture['bundle']['bundle_hash']);
            $this->fail('Partial minutes were accepted without an actual provider M5.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('SECONDARY_PARTIAL_M1_REQUIRES_ACTUAL_M5', $error->getMessage());
        }
        File::append($response, "\n");
        $this->expectExceptionMessage('SECONDARY_EVIDENCE_HASH_INVALID');
        $service->build($fixture['dataset'], [$evidence], $fixture['bundle']['bundle_hash']);
    }

    public function test_rehashed_foreign_provider_timezone_bad_request_bounds_and_misaligned_bars_are_refused(): void
    {
        $directory = storage_path('app/lab-datasets/training/secondary-recovery/test-invalid-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory); $this->directories[] = $directory;
        $value = ['datetime' => '2025-10-01 10:00:00', 'open' => '1000', 'high' => '1001', 'low' => '999', 'close' => '1000'];
        $base = ['status' => 'ok', 'meta' => ['symbol' => 'XAU/USD', 'interval' => '5min'], 'values' => [$value]];
        $query = ['symbol' => 'XAU/USD', 'timezone' => 'UTC', 'interval' => '5min', 'start_date' => '2025-10-01 10:00:00', 'end_date' => '2025-10-01 10:05:00'];
        $cases = [
            [[...$base, 'meta' => [...$base['meta'], 'symbol' => 'EUR/USD']], $query, 'SECONDARY_PROVIDER_RESPONSE_IDENTITY_INVALID'],
            [[...$base, 'meta' => [...$base['meta'], 'timezone' => 'Asia/Tashkent']], $query, 'SECONDARY_PROVIDER_RESPONSE_IDENTITY_INVALID'],
            [[...$base, 'values' => [[...$value, 'datetime' => '2025-10-01 10:01:00']]], $query, 'SECONDARY_PROVIDER_M5_ALIGNMENT_INVALID'],
            [$base, [...$query, 'start_date' => ''], 'SECONDARY_PROVIDER_REQUEST_BOUNDS_INVALID'],
        ];
        $method = new \ReflectionMethod(SecondaryM5ResearchRecoveryService::class, 'observations');
        foreach ($cases as $index => [$payload, $parameters, $reason]) {
            $response = $directory.'/response-'.$index.'.json'; File::put($response, json_encode($payload));
            $path = $directory.'/receipt-'.$index.'.json';
            File::put($path, json_encode(['results' => [['provider' => 'twelve_data', 'endpoint_without_query' => 'https://api.twelvedata.com/time_series',
                'http_status' => 200, 'requested_public_parameters' => $parameters, 'sanitized_response_path' => $response,
                'sanitized_response_sha256' => hash_file('sha256', $response)]]]));
            try {
                $method->invoke(app(SecondaryM5ResearchRecoveryService::class), [['path' => $path, 'sha256' => hash_file('sha256', $path)]]);
                $this->fail('Rehashing made invalid source evidence admissible.');
            } catch (RuntimeException $error) { $this->assertSame($reason, $error->getMessage()); }
        }
        $this->assertDatabaseMissing('market_training_archives', ['provider' => 'mixed']);
    }

    private function fixture(int $minuteCount, bool $withM5): array
    {
        $fixture = (new ProspectiveCleanDiscoverySnapshotTest('test_clean_scope_freezes_real_selected_rows_but_full_validation_stays_blocked'))->cleanBundleFixture();
        $this->directories = [...$this->directories, ...$fixture['directories']];
        $directory = storage_path('app/lab-datasets/training/secondary-recovery/test-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory); $this->directories[] = $directory;
        $minutes = [];
        foreach (range(0, $minuteCount - 1) as $offset) $minutes[] = ['datetime' => '2025-10-01 10:0'.$offset.':00', 'open' => '1000', 'high' => '1001', 'low' => '999', 'close' => '1000'];
        $response = $directory.'/m1.json';
        File::put($response, json_encode(['status' => 'ok', 'meta' => ['symbol' => 'XAU/USD', 'interval' => '1min'], 'values' => $minutes]));
        $query = ['symbol' => 'XAU/USD', 'timezone' => 'UTC', 'interval' => '1min', 'start_date' => '2025-10-01 10:00:00', 'end_date' => '2025-10-01 10:05:00'];
        $results = [['provider' => 'twelve_data', 'endpoint_without_query' => 'https://api.twelvedata.com/time_series',
            'http_status' => 200, 'requested_public_parameters' => $query, 'sanitized_response_path' => $response,
            'sanitized_response_sha256' => hash_file('sha256', $response)]];
        if ($withM5) {
            $path = $directory.'/m5.json';
            File::put($path, json_encode(['status' => 'ok', 'meta' => ['symbol' => 'XAU/USD', 'interval' => '5min'], 'values' => [$minutes[0]]]));
            $results[] = [...$results[0], 'requested_public_parameters' => [...$query, 'interval' => '5min'],
                'sanitized_response_path' => $path, 'sanitized_response_sha256' => hash_file('sha256', $path)];
        }
        $path = $directory.'/request-receipt.json'; File::put($path, json_encode(['results' => $results]));
        return [$fixture, ['path' => $path, 'sha256' => hash_file('sha256', $path)], $response];
    }

    private function assertPythonSourceAndPricePreparation(array $bundle): void
    {
        $handle = fopen($bundle['entry_dataset_path'], 'rb'); $header = fgetcsv($handle); $rows = [];
        while (($values = fgetcsv($handle)) !== false) $rows[] = ['time' => $values[array_search('time', $header, true)]];
        fclose($handle);
        $execution = str_repeat('e', 64);
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $bundle['bundle_hash'], $execution,
            'secondary_recovery_focused_test', 15000, 512);
        $requestPath = dirname($bundle['manifest_path']).'/test-request.json';
        File::put($requestPath, json_encode(['timeframe' => 'M5', 'evaluation_mode' => 'incremental',
            'dataset_path' => $bundle['entry_dataset_path'], 'replay_dataset_hash' => $bundle['bundle_hash'],
            'mtf_snapshot_manifest' => $bundle['manifest'], 'execution_contract' => ['execution_hash' => $execution],
            'policy_context' => ['prospective_probe_window' => $probe, 'prospective_clean_discovery_scope' => $bundle['manifest']['discovery_scope']]]));
        $script = <<<'PY'
import json, sys
from app.schemas import SimpleBacktestRequest
from app.services.backtester import _load_verified_dataset_csv, _prepare_simple_dataframe, _consumed_dataset_attestation
from app.services.prospective_probe_window import assert_clean_discovery_boundary, select_probe_window
p = SimpleBacktestRequest.model_validate(json.load(open(sys.argv[1], encoding='utf-8')))
assert_clean_discovery_boundary(p)
f = _load_verified_dataset_csv(p, p.dataset_path, 'M5')
a = _consumed_dataset_attestation(p,f)
g = _prepare_simple_dataframe(p,f)
evaluated, receipt = select_probe_window(g,p.policy_context['prospective_probe_window'],p.replay_dataset_hash,p.execution_contract['execution_hash'])
try:
    assert_clean_discovery_boundary(p.model_copy(update={'evaluation_mode': 'replay'}))
    full_rejected = False
except ValueError:
    full_rejected = True
print(json.dumps({'rows': len(g), 'evaluated_rows':len(evaluated), 'provider_column': 'source_provider' in a['source_columns'], 'volume': int(g['volume_available'].sum()), 'spread': 'spread_available' in g.columns,'full_rejected':full_rejected}))
PY;
        $process = new Process(['python', '-B', '-c', $script, $requestPath], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(120); $process->mustRun(); $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(15512, $actual['rows']); $this->assertTrue($actual['provider_column']);
        $this->assertSame(0, $actual['volume']); $this->assertFalse($actual['spread']);
        $this->assertSame(15000, $actual['evaluated_rows']); $this->assertTrue($actual['full_rejected']);
    }
}
