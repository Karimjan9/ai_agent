<?php

namespace Tests\Feature;

use App\Models\InstrumentValuePosterior;
use App\Models\PlaybookComposition;
use App\Services\InstrumentPolicyConsumptionService;
use App\Services\InstrumentPosteriorAuthorityService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabInstrumentResearchService;
use App\Services\TradingInstrumentOperatingSystemService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\InstrumentValidationFixture;

class InstrumentResearchWindowAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use InstrumentValidationFixture;

    public function test_three_sealed_windows_flow_from_paired_evidence_to_exact_successor_consumption(): void
    {
        $windows = $this->windows();
        $operating = app(TradingInstrumentOperatingSystemService::class);
        $operating->seedDefaults();
        $playbook = PlaybookComposition::create([
            'playbook_key' => 'window-proof-bundle', 'label' => 'Window proof bundle',
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'research_only',
            'instrument_keys' => ['volume_confirmation', 'atr_risk_envelope', 'cost_aware_exit'],
            'preconditions' => [], 'metadata' => [
                'protocol' => 'exact_instrument_research_bundle_v1',
                'primary_instrument_key' => 'volume_confirmation',
            ],
        ]);
        $context = [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
            'venue_phase' => 'london_am_fix', 'direction' => 'BUY',
            'strategy_family' => 'hybrid', 'spread_atr_ratio' => .1,
        ];
        foreach ($windows as $index => $window) {
            $outcome = [
                ...$this->exactValidationFacts($context, $window, 'sealed-pair-'.$index),
                'evidence_key' => 'sealed-pair-'.$index,
                'source_type' => 'synthetic_exact_paired_research',
                'source_key' => 'synthetic-pair-'.$index,
                'instrument_research_window_receipt' => $window,
                'control_contract' => ['paired_isolated' => true,
                    'data_hash' => $window['dataset_sha256']],
                'metrics' => ['net_edge' => .03, 'cost_penalty' => .001,
                    'drawdown_penalty' => .2, 'profit_factor' => 1.2],
                'control_metrics' => ['net_edge' => 0, 'profit_factor' => 1.0],
            ];
            $instrument = $operating->recordEvidence('volume_confirmation', 'XAUUSD', 'M15', $context, $outcome);
            $bundle = $operating->recordPlaybookEvidence($playbook->playbook_key, 'XAUUSD', 'M15',
                $context, [...$outcome, 'evidence_key' => 'sealed-bundle-'.$index]);
        }
        $this->assertSame('confirmed', $instrument->decay_state);
        $this->assertSame('confirmed', $bundle->decay_state);
        $this->assertSame(3, app(InstrumentPosteriorAuthorityService::class)->assess($instrument)['independent_windows']);
        $requested = ['regime' => 'trend_up', 'session' => 'london',
            'volatility' => 'normal', 'venue_phase' => 'london_am_fix', 'direction' => 'buy',
            'spread_liquidity_state' => 'normal', 'transition_state' => 'stable'];
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $requested);
        $this->assertContains('volume_lane', $policy['preferred_genes']);
        $receipt = app(InstrumentPolicyConsumptionService::class)->receipt($policy, [
            'volume_lane' => ['old' => 'none', 'new' => 'confirmed'],
        ], ['volume_lane' => 'confirmed']);
        $this->assertSame('policy_aligned_mutation_observed', $receipt['status']);
        $this->assertSame([], app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal',
        ])['preferred_genes']);
    }

    public function test_only_server_authorized_post_paper_dataset_can_seal_a_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        $service = app(InstrumentResearchWindowService::class);
        $this->assertNull($service->seal('arbitrary-key', str_repeat('a', 64)));
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'paper-leak', 'research_epoch_id' => 'research',
            'start_inclusive' => '2026-05-01T00:00:00Z',
            'end_exclusive' => '2026-06-01T00:00:00Z',
            'dataset_sha256' => str_repeat('a', 64),
            'purpose' => 'instrument_independent_validation',
        ]]);
        $this->assertNull($service->seal('paper-leak', str_repeat('a', 64)));
    }

    public function test_pre_2026_screening_manifest_cannot_borrow_a_future_window(): void
    {
        $windows = $this->windows();
        $manifest = [
            'data_partition' => ['screening_source' => 'pre_2026_foundation_training'],
            'instrument_research_window' => ['authorization_id' => 'window-1',
                'research_epoch_id' => 'test-post-paper-research'],
            'first_candle_at' => '2005-01-01T00:00:00Z',
            'last_candle_at' => '2025-12-31T23:00:00Z',
        ];
        $this->assertNull(app(InstrumentResearchWindowService::class)->sealForDataset(
            $windows[0]['dataset_sha256'], $manifest,
        ));
        $manifest['data_partition']['screening_source'] = 'authorized_post_paper_research_validation';
        $this->assertNull(app(InstrumentResearchWindowService::class)->sealForDataset(
            $windows[0]['dataset_sha256'], $manifest,
        ));
    }

    public function test_two_positive_observations_in_one_window_are_not_independent_replication(): void
    {
        $windows = $this->windows();
        $observations = [
            $this->observation($windows[0], 'e1', 'positive'),
            $this->observation($windows[0], 'e2', 'positive'),
            $this->observation($windows[1], 'e3', 'negative'),
            $this->observation($windows[2], 'e4', 'negative'),
        ];
        $authority = app(InstrumentPosteriorAuthorityService::class)->assess(
            $this->posterior($observations, 2, 2),
        );
        $this->assertSame('status_only_quarantined', $authority['canonical_state']);
        $this->assertSame(3, $authority['independent_windows']);
        $this->assertSame(1, $authority['positive_independent_windows']);

        $observations[2]['outcome'] = 'positive';
        $observations[3]['outcome'] = 'neutral';
        $authority = app(InstrumentPosteriorAuthorityService::class)->assess(
            $this->posterior($observations, 3, 0),
        );
        $this->assertSame('confirmed', $authority['canonical_state']);
        $this->assertSame(2, $authority['positive_independent_windows']);
    }

    public function test_overlapping_or_tampered_window_receipts_cannot_confirm(): void
    {
        $windows = $this->windows(true);
        $observations = [
            $this->observation($windows[0], 'e1', 'positive'),
            $this->observation($windows[1], 'e2', 'positive'),
            $this->observation($windows[2], 'e3', 'positive'),
        ];
        $authority = app(InstrumentPosteriorAuthorityService::class)->assess(
            $this->posterior($observations, 3, 0),
        );
        $this->assertSame('status_only_quarantined', $authority['canonical_state']);
        $this->assertFalse($authority['checks']['sealed_window_evidence_complete']);

        $windows = $this->windows();
        $observations[1]['window'] = $windows[1];
        $observations[2]['window'] = $windows[2];
        $observations[0]['window'] = $windows[0];
        $observations[0]['window']['dataset_sha256'] = str_repeat('f', 64);
        $this->assertSame('status_only_quarantined', app(InstrumentPosteriorAuthorityService::class)
            ->assess($this->posterior($observations, 3, 0))['canonical_state']);
    }

    public function test_replaying_one_dataset_under_different_window_names_is_not_replication(): void
    {
        $this->windows();
        $manifests = (array) config('services.instrument_policy.authorized_research_windows');
        $manifests[1]['dataset_sha256'] = $manifests[0]['dataset_sha256'];
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $service = app(InstrumentResearchWindowService::class);
        $windows = array_map(fn (array $manifest): array => $service->seal(
            $manifest['authorization_id'], $manifest['dataset_sha256'],
        ), $manifests);
        $observations = [
            $this->observation($windows[0], 'e1', 'positive'),
            $this->observation($windows[1], 'e2', 'positive'),
            $this->observation($windows[2], 'e3', 'positive'),
        ];
        $this->assertSame('status_only_quarantined', app(InstrumentPosteriorAuthorityService::class)
            ->assess($this->posterior($observations, 3, 0))['canonical_state']);
    }

    public function test_json_key_reordering_does_not_break_an_unchanged_receipt(): void
    {
        $windows = $this->windows();
        $reordered = array_reverse($windows[0], true);
        $service = app(InstrumentResearchWindowService::class);
        $this->assertTrue($service->authorized($reordered, $reordered['dataset_sha256']));
        $observations = [
            $this->observation($reordered, 'e1', 'positive'),
            $this->observation($windows[1], 'e2', 'positive'),
            $this->observation($windows[2], 'e3', 'positive'),
        ];
        $this->assertTrue($service->analyze($observations)['valid']);
    }

    /** @return list<array<string,string>> */
    private function windows(bool $overlap = false): array
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        $manifests = [];
        foreach ([1, 2, 3] as $month) {
            $manifests[] = [
                'authorization_id' => 'window-'.$month,
                'research_epoch_id' => 'test-post-paper-research',
                'start_inclusive' => sprintf('2027-%02d-%02dT00:00:00Z', $month, $overlap && $month === 2 ? 15 : 1),
                'end_exclusive' => sprintf('2027-%02d-01T00:00:00Z', $month + 1),
                'dataset_sha256' => hash('sha256', 'dataset-'.$month),
                'purpose' => 'instrument_independent_validation',
            ];
        }
        if ($overlap) {
            $manifests[0]['end_exclusive'] = '2027-02-20T00:00:00Z';
        }
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);

        return array_map(fn (array $manifest): array => app(InstrumentResearchWindowService::class)
            ->seal($manifest['authorization_id'], $manifest['dataset_sha256']), $manifests);
    }

    /** @param array<string,string> $window */
    private function observation(array $window, string $key, string $outcome): array
    {
        return ['window' => $window, 'evidence_key' => $key, 'outcome' => $outcome];
    }

    private function posterior(array $observations, int $positive, int $negative): InstrumentValuePosterior
    {
        $stateKey = 'trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix';

        return new InstrumentValuePosterior([
            'state_key' => $stateKey, 'observations' => count($observations),
            'net_value' => .2, 'decay_state' => 'confirmed',
            'value_vector' => $this->exactValidationVector($stateKey, $observations),
        ]);
    }
}
