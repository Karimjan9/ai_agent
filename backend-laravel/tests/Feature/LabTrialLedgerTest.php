<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\LabTrialLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabTrialLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_run_is_counted_and_prior_sharpes_are_returned_without_holdout(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'trial_ledger_test', true);
        $agent = $generation->agents->first()->fresh(['modelVersion']);
        $model = $agent->modelVersion;
        $result = [
            'forward_score' => 72,
            'equity_curve' => [10000, 10100, 10050, 10200],
            'data_manifest' => ['sha256' => str_repeat('a', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'total_trades' => 3,
            'profit_factor' => 1.4,
        ];

        $service = app(LabTrialLedgerService::class);
        $first = $service->record($agent, $model, 'XAUUSD', 'H1', 'screening', $result, 'trial-ledger-1');
        $second = $service->record($agent, $model, 'XAUUSD', 'H1', 'full_replay', $result, 'trial-ledger-2');
        $context = $service->selectionContext('XAUUSD', 'H1');

        $this->assertSame(1, $first['trial_count']);
        $this->assertSame(2, $second['trial_count']);
        $this->assertSame(2, $context['trial_count']);
        $this->assertCount(2, $context['trial_sharpes']);
        $this->assertFalse($context['holdout_included']);
        $this->assertDatabaseCount('lab_trial_ledger', 2);
    }

    public function test_replayed_full_identity_is_idempotent_across_new_run_ids(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'trial_ledger_retry_test', true);
        $agent = $generation->agents->first()->fresh(['modelVersion']);
        $model = $agent->modelVersion;
        $result = [
            'forward_score' => 55,
            'equity_curve' => [10000, 10080, 10120, 10430],
            'data_manifest' => ['sha256' => str_repeat('c', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('d', 64)],
            'total_trades' => 30,
            'profit_factor' => 1.3,
        ];

        $service = app(LabTrialLedgerService::class);
        $first = $service->record($agent, $model, 'XAUUSD', 'M15', 'full_replay', $result, 'full-run-1');
        $retry = $service->record($agent, $model, 'XAUUSD', 'M15', 'full_replay', $result, 'full-run-2');

        $this->assertSame($first['trial_id'], $retry['trial_id']);
        $this->assertSame($first['trial_count'], $retry['trial_count']);
        $this->assertDatabaseCount('lab_trial_ledger', 1);
        $this->assertDatabaseHas('lab_trial_ledger', [
            'id' => $first['trial_id'],
            'run_id' => 'full-run-1',
        ]);
    }
}
